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
     * would resurrect the deleted account. A deleted leader or guide
     * is surfaced by the existing flagged reports.
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
     * A removed leader or guide is NOT auto-reassigned: the existing
     * flagged reports surface leaderless and guideless groups, and a
     * removed guide simply stops being part of the expected mirror.
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
        if (!$rows) {
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
