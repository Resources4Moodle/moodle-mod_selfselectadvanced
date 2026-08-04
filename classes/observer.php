<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_selfselectadvanced;

/**
 * Core event observers (review item M3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A user was deleted: remove their site-wide attribute record and
     * purge the distinct-value cache (M3), then clear their live
     * memberships so no roster keeps counting a ghost (RCA Q1): core
     * has already dropped their core-group rows itself, so each
     * affected FROZEN group gets a fresh snapshot recording the true
     * roster - otherwise a later unfreeze or re-freeze reconciliation
     * would resurrect the deleted account. A deleted leader is
     * surfaced by the existing flagged reports.
     *
     * A deleted GUIDE is handled here too (OBS-001): guides are not
     * members, so the member scan below never sees them, and until
     * 1.20.4 a group's guideid simply kept naming the dead account -
     * which the frozen mirror's expected set went on demanding, one
     * refused sync and one capaudit mail per run, for ever. See
     * guide_gone() for the policy.
     *
     * @param \core\event\user_deleted $event the core event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int) $event->objectid;
        \mod_selfselectadvanced\local\attributes\manager::delete_for_user($userid);
        $actorid = (int) ($event->userid ?: get_admin()->id);

        // Each group's flip + snapshot runs under the group lock in
        // one transaction, exactly like every other roster mutation
        // (A7) - otherwise a concurrent unfreeze can re-confirm the
        // ghost from the pre-deletion snapshot, and a crash between
        // the flip and the snapshot leaves a frozen mirror that would
        // resurrect it.
        $rows = $DB->get_records_sql(
            "SELECT m.id AS memberid, g.id AS groupid, g.activityid
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
              WHERE m.userid = :userid AND m.status IN (:confirmed, :invited)",
            [
                'userid' => $userid,
                'confirmed' => local\groups::STATUS_CONFIRMED,
                'invited' => local\groups::STATUS_INVITED,
            ]
        );
        $now = time();
        foreach ($rows as $row) {
            $activity = self::activity_or_null((int) $row->activityid);
            $lock = local\locks::acquire('group:' . (int) $row->groupid);
            try {
                $transaction = $DB->start_delegated_transaction();
                $member = $DB->get_record('selfselectadvanced_member', ['id' => (int) $row->memberid]);
                $live = [local\groups::STATUS_CONFIRMED, local\groups::STATUS_INVITED];
                if ($member && in_array($member->status, $live, true)) {
                    $member->status = local\groups::STATUS_REMOVED;
                    $member->timemodified = $now;
                    $DB->update_record('selfselectadvanced_member', $member);
                    $group = $DB->get_record('selfselectadvanced_group', ['id' => (int) $row->groupid]);
                    if ($group && $group->state === local\state::FROZEN) {
                        local\freeze::append_snapshot($group, $actorid);
                    }
                    if ($group && $activity !== null) {
                        local\freeze::request_sync($activity, $group);
                    }
                }
                $transaction->allow_commit();
            } finally {
                $lock->release();
            }
            if ($activity !== null) {
                // Verified rather than assumed: core's delete_user()
                // has already purged this account's course-group rows,
                // so the sync usually confirms a no-op - but if it ever
                // stops doing so, or if this observer is reached with a
                // transaction open, the request_sync() above has queued
                // the adhoc and convergence is still guaranteed. One
                // deleted account is a one-off, so the inline call is
                // affordable here in a way it is not in the bulk
                // unenrolment path below.
                local\freeze::sync_core_group($activity, (int) $row->groupid, $actorid);
            }
        }

        // The student's PENDING staged moves would re-insert the ghost
        // at commit; cancel them under each activity's lock - the lock
        // commit_set itself holds.
        $activityids = $DB->get_fieldset_sql(
            "SELECT DISTINCT activityid
               FROM {selfselectadvanced_move}
              WHERE userid = :userid AND status = :pending",
            ['userid' => $userid, 'pending' => 'pending']
        );
        foreach ($activityids as $activityid) {
            $lock = local\locks::acquire('activity:' . (int) $activityid);
            try {
                $transaction = $DB->start_delegated_transaction();
                $moveids = $DB->get_fieldset_select(
                    'selfselectadvanced_move',
                    'id',
                    'activityid = ? AND userid = ? AND status = ?',
                    [(int) $activityid, $userid, 'pending']
                );
                if ($moveids) {
                    [$insql, $inparams] = $DB->get_in_or_equal($moveids);
                    $DB->set_field_select('selfselectadvanced_move', 'status', 'cancelled', "id $insql", $inparams);
                    $DB->set_field_select('selfselectadvanced_move', 'timemodified', $now, "id $insql", $inparams);
                }
                $transaction->allow_commit();
            } finally {
                $lock->release();
            }
        }

        // Groups this account GUIDED, in any course - a different
        // involvement from the member rows above, and both can be true
        // of one group at once (a guide who was also confirmed as a
        // member): the member flip has already run, and this runs once
        // more for the guide half.
        self::guide_gone($userid, $actorid, 'deleted');
    }

    /**
     * A user lost an enrolment: when it was their LAST one in the
     * course, they cannot hold a seat any more, so every live
     * membership they hold in that course's activities is dropped -
     * loudly, following the user_deleted precedent rather than leaving
     * a ghost on rosters core has already emptied out of its groups.
     *
     * Core purges course-group memberships only on the last enrolment,
     * so a multi-instance unenrolment (a second manual or cohort
     * enrolment still standing) must eject nobody.
     *
     * A removed leader is NOT auto-reassigned: the existing flagged
     * reports surface leaderless groups.
     *
     * A removed GUIDE is handled by guide_gone() below (OBS-001):
     * guides are not members, so the member scan here never saw them,
     * and the old claim that "a removed guide simply stops being part
     * of the expected mirror" was false - freeze's expected set keeps
     * demanding guideid until something clears it, and nothing did.
     * A removed NOMINATED SUCCESSOR is handled there too, and reaching
     * it needed the early-out below to ask about nominations as well
     * (H-06).
     *
     * @param \core\event\user_enrolment_deleted $event the core event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if (!$userid || !$courseid) {
            return;
        }
        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return;
        }
        if (is_enrolled($coursecontext, $userid)) {
            // Another enrolment still stands; core keeps their group
            // memberships and so do we.
            return;
        }

        $actorid = (int) ($event->userid ?: get_admin()->id);
        $rows = $DB->get_records_sql(
            "SELECT m.id AS memberid, g.id AS groupid
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
               JOIN {selfselectadvanced} s ON s.id = g.activityid AND s.course = :courseid
              WHERE m.userid = :userid AND m.status IN (:confirmed, :invited)",
            [
                'courseid' => $courseid,
                'userid' => $userid,
                'confirmed' => local\groups::STATUS_CONFIRMED,
                'invited' => local\groups::STATUS_INVITED,
            ]
        );
        // BOTH staff involvements guide_gone() knows how to end, asked
        // as one question because the answer is used as one: the
        // ASSIGNED guide of a team, and the NOMINATED SUCCESSOR of a
        // pending handover.
        //
        // The successor arm is H-06, and it is a hole in this
        // plugin's OWN previous fix. Wave 2 taught guide_gone() to
        // lapse a handover whose nominee had gone, and the deletion
        // observer reached it - but this method's early-out asked
        // about memberships and guided teams only, so a user whose
        // ONLY involvement was being somebody's nominated successor
        // returned here and never reached the handling at all.
        // Deletion closed the case; unenrolment left the proposing
        // guide waiting for ever on an acceptance that could not
        // come. The wave-2 test covered the deletion door only, which
        // is how a fix and its test agreed with each other and both
        // missed the second door.
        $guideinvolvement = $DB->record_exists_sql(
            "SELECT 1
               FROM {selfselectadvanced_group} g
               JOIN {selfselectadvanced} s ON s.id = g.activityid AND s.course = :courseid
              WHERE g.guideid = :userid OR g.guidesuccessorid = :userid2",
            ['courseid' => $courseid, 'userid' => $userid, 'userid2' => $userid]
        );
        if (!$rows && !$guideinvolvement) {
            // No membership, no guided team AND no nomination: the
            // early-out that used to run on the member rows alone,
            // which is exactly how a guide-only involvement slipped
            // past this observer (OBS-001), and on guided teams
            // alone, which is how a successor-only one slipped past
            // it again (H-06).
            return;
        }

        $now = time();
        $activities = [];
        foreach ($rows as $row) {
            $lock = local\locks::acquire('group:' . (int) $row->groupid);
            try {
                $transaction = $DB->start_delegated_transaction();
                $member = $DB->get_record('selfselectadvanced_member', ['id' => (int) $row->memberid]);
                $live = [local\groups::STATUS_CONFIRMED, local\groups::STATUS_INVITED];
                if ($member && in_array($member->status, $live, true)) {
                    $member->status = local\groups::STATUS_REMOVED;
                    $member->timemodified = $now;
                    $DB->update_record('selfselectadvanced_member', $member);
                    $group = $DB->get_record('selfselectadvanced_group', ['id' => (int) $row->groupid]);
                    if ($group && $group->state === local\state::FROZEN) {
                        local\freeze::append_snapshot($group, $actorid);
                    }
                    if ($group) {
                        $activityid = (int) $group->activityid;
                        if (!array_key_exists($activityid, $activities)) {
                            $activities[$activityid] = self::activity_or_null($activityid);
                        }
                        if ($activities[$activityid] !== null) {
                            local\freeze::request_sync($activities[$activityid], $group);
                        }
                    }
                }
                $transaction->allow_commit();
            } finally {
                $lock->release();
            }
        }

        // NO inline sync here, deliberately. request_sync() has already
        // queued the deduped adhoc inside each transaction and that is
        // the convergence contract. This event fires in BULK - cohort
        // and LDAP enrolment sync and course reset remove hundreds of
        // users - and an inline sync per affected group per user would
        // add a lock pair, a membership read and core writes to each,
        // inside the enrolment task.

        if ($guideinvolvement) {
            // Guide OR successor: guide_gone() re-reads each row under
            // its lock and decides which of the two it is holding, so
            // the caller does not have to know.
            self::guide_gone($userid, $actorid, 'unenrolled', $courseid);
        }
    }

    /**
     * The OBS-001 policy for a guide who was deleted or lost their
     * last enrolment, decided by the fresh row's lifecycle state:
     *
     * - FORMING and PENDING_GUIDE teams lose the guide the way a
     *   return releases one: guideid cleared - and with it any pending
     *   handover, whose nomination belonged to the guide who just left
     *   (state::return_group()'s rule) - under the group lock on a
     *   re-read row, guide_removed fired after commit and release, the
     *   leader told through the notifier. A cleared PENDING_GUIDE team
     *   lands in the manager's assignment queue, which is the page
     *   built for it.
     * - FIRM and FROZEN teams keep their state AND their guideid: a
     *   frozen roster is never mutated behind the coordinators' backs.
     *   A guidegone ticket is filed instead, so a coordinator resolves
     *   the succession deliberately with the assign-guide tool. Until
     *   then the mirror's refused adds stay loud on purpose - they are
     *   the repeating alarm, and the ticket is the off switch.
     *
     * Idempotent across the double fire (core unenrols before it
     * deletes): a cleared guideid no longer matches, and a live ticket
     * is returned rather than duplicated.
     *
     * @param int $userid the gone guide
     * @param int $actorid whose act removed them
     * @param string $reason 'deleted' or 'unenrolled'
     * @param int $courseid restrict to one course's activities, 0 for all
     */
    private static function guide_gone(int $userid, int $actorid, string $reason, int $courseid = 0): void {
        global $DB;

        // BOTH roles the departed user can hold on a group row: the
        // ASSIGNED guide, and the NOMINATED successor of a pending
        // handover (wave-2 blind audit, the medium: a deleted successor
        // left the handover dangling - the proposing guide waited on an
        // acceptance that could never come). The loop below re-reads
        // under the lock and treats the two cases on their own terms.
        $sql = "SELECT g.id, g.activityid
                  FROM {selfselectadvanced_group} g
                  JOIN {selfselectadvanced} s ON s.id = g.activityid
                 WHERE g.guideid = :userid OR g.guidesuccessorid = :userid2";
        $params = ['userid' => $userid, 'userid2' => $userid];
        if ($courseid) {
            $sql .= " AND s.course = :courseid";
            $params['courseid'] = $courseid;
        }

        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $activity = self::activity_or_null((int) $row->activityid);

            $cleared = null;
            $ticketwanted = false;
            $handoverlapsed = null;
            $lock = local\locks::acquire('group:' . (int) $row->id);
            try {
                $transaction = $DB->start_delegated_transaction();
                $group = $DB->get_record('selfselectadvanced_group', ['id' => (int) $row->id]);
                if ($group && (int) ($group->guidesuccessorid ?? 0) === $userid) {
                    // The departed user is the NOMINATED successor: the
                    // pending handover can never be accepted, so it is
                    // cancelled - successor and nomination time cleared,
                    // the assigned guide (who proposed it and keeps the
                    // team either way) left in place. If the same user
                    // is somehow both guide and successor, the guide
                    // branch below still runs on this same re-read row.
                    $group->guidesuccessorid = null;
                    $group->timeguidenominated = null;
                    $group->usermodified = $actorid;
                    $group->timemodified = time();
                    $DB->update_record('selfselectadvanced_group', $group);
                    if ((int) $group->guideid > 0 && (int) $group->guideid !== $userid) {
                        // Tell the proposer AFTER the release, below:
                        // they were waiting on an acceptance that can
                        // never come now, and silence here is exactly
                        // the waiting-for-ever the audit named.
                        $handoverlapsed = $group;
                    }
                }
                if ($group && (int) $group->guideid === $userid) {
                    if (in_array($group->state, [local\state::FIRM, local\state::FROZEN], true)) {
                        // The row is deliberately untouched; the
                        // ticket is filed after the release, under the
                        // filing helper's own lock.
                        $ticketwanted = true;
                    } else {
                        $group->guideid = null;
                        $group->guidesuccessorid = null;
                        $group->timeguidenominated = null;
                        $group->usermodified = $actorid;
                        $group->timemodified = time();
                        $DB->update_record('selfselectadvanced_group', $group);
                        $cleared = $group;
                    }
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                if (isset($transaction) && !$transaction->is_disposed()) {
                    $transaction->rollback($e);
                }
                throw $e;
            } finally {
                $lock->release();
            }

            if ($activity === null) {
                // The instance or its course module has gone: the row
                // write above still ran, but there is no context to
                // fire against and nobody to tell.
                continue;
            }

            if ($handoverlapsed !== null) {
                // The proposing guide learns the truth: the nominee's
                // account is gone and the handover lapsed. Sent after
                // the release like every message here; the return is
                // consumed the MSG-001 way (send() records refusals
                // durably; nothing gates on delivery).
                local\notifier::send(
                    $activity,
                    'guidechanged',
                    (int) $handoverlapsed->guideid,
                    'msghandoverlapsedsubject',
                    'msghandoverlapsedbody',
                    (object) [
                        'group' => $handoverlapsed->name,
                        'pluginuid' => $handoverlapsed->pluginuid,
                        'activity' => $activity->name(),
                        'reason' => $reason,
                    ],
                    new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $activity->cm()->id]),
                    $handoverlapsed->name
                );
            }

            if ($cleared !== null) {
                // After the commit AND the release (the binding rule
                // for new code; store::save() is the worked example).
                \mod_selfselectadvanced\event\guide_removed::create([
                    'objectid' => (int) $cleared->id,
                    'context' => $activity->context(),
                    'userid' => $actorid,
                    'relateduserid' => $userid,
                    'other' => ['pluginuid' => $cleared->pluginuid, 'reason' => $reason],
                ])->trigger();

                // The return is consumed (MSG-001): nothing here gates
                // state on delivery, so a refusal needs no retry - but
                // it must not vanish into a discarded bool either.
                // send() has already recorded it durably
                // (notification_refused); the debugging() is the
                // development-run echo of that record.
                $submitted = local\notifier::send(
                    $activity,
                    'guidechanged',
                    (int) $cleared->leaderid,
                    'msgguideremovedsubject',
                    'msgguideremovedbody',
                    (object) [
                        'group' => format_string($cleared->name),
                        'activity' => $activity->name(),
                    ],
                    new \moodle_url('/mod/selfselectadvanced/group.php', [
                        'id' => $activity->cm()->id,
                        'g' => (int) $cleared->id,
                    ]),
                    format_string($cleared->name)
                );
                if (!$submitted) {
                    debugging(
                        'mod_selfselectadvanced: the guide-removed notice to leader '
                        . (int) $cleared->leaderid . ' was refused by messaging; the refusal is'
                        . ' recorded as a notification_refused event.',
                        DEBUG_DEVELOPER
                    );
                }
            } else if ($ticketwanted) {
                local\tickets::file_guidegone($activity, (int) $row->id, $userid, $reason, $actorid);
            }
        }
    }

    /**
     * The activity wrapper for an instance id, or null when the
     * instance or its course module has gone.
     *
     * @param int $activityid the instance id
     * @return \mod_selfselectadvanced\activity|null
     */
    private static function activity_or_null(int $activityid): ?\mod_selfselectadvanced\activity {
        try {
            return \mod_selfselectadvanced\activity::from_instance($activityid);
        } catch (\dml_missing_record_exception | \moodle_exception $e) {
            return null;
        }
    }
}
