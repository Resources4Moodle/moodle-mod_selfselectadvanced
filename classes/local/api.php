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

namespace mod_selfselectadvanced\local;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\rules\gatekeeper;
use stdClass;

/**
 * Application facade used by the pages: capability-checked callers pass
 * through here; the gatekeeper decides; the services mutate inside
 * transactions with named locks (decision A7).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     */
    public function __construct(activity $activity) {
        $this->activity = $activity;
        $this->gatekeeper = new gatekeeper($activity, new resolver($activity));
    }

    /**
     * The gatekeeper, for pages that render limit positions and reasons.
     *
     * @return gatekeeper
     */
    public function gatekeeper(): gatekeeper {
        return $this->gatekeeper;
    }

    /**
     * The activity.
     *
     * @return activity
     */
    public function activity(): activity {
        return $this->activity;
    }

    /**
     * The invitation engine.
     *
     * @return invitations
     */
    public function invitations(): invitations {
        return new invitations($this->activity, $this->gatekeeper);
    }

    /**
     * The guide handover engine (nominate, accept, decline, cancel).
     *
     * @return handover
     */
    public function handover(): handover {
        return new handover($this->activity, $this->gatekeeper);
    }

    /**
     * The succession engine (transfer and step-out).
     *
     * @return succession
     */
    public function succession(): succession {
        return new succession($this->activity, $this->gatekeeper);
    }

    /**
     * The lifecycle transition service (T2-T4 and A5 assignment).
     *
     * @return state
     */
    public function lifecycle(): state {
        return new state($this->activity, $this->gatekeeper);
    }

    /**
     * The staged-move engine.
     *
     * @return moves
     */
    public function moves(): moves {
        return new moves($this->activity, $this->gatekeeper);
    }

    /**
     * Create a group with the acting user as leader (transition T1).
     *
     * @param int $userid the leader-to-be
     * @param string $name group name, unique within the activity
     * @param string $title title of work
     * @param string $brief brief of work (HTML from the core editor)
     * @param int $briefformat text format of the brief
     * @param int|null $leaderid on the staff path, the student who
     *        leads the new team; null on the student path, where the
     *        actor leads their own team
     * @param bool $staff true when a :manage holder is creating a
     *        destination team for somebody else (decision 6, D6-4): the
     *        creation WINDOW and the actor's own L3/L4 are student-shaped
     *        constraints and do not apply, but the nominated leader's do
     * @return stdClass the created group row
     * @throws \moodle_exception when the gatekeeper refuses
     */
    public function create_group(
        int $userid,
        string $name,
        string $title,
        string $brief,
        int $briefformat,
        ?int $leaderid = null,
        bool $staff = false
    ): stdClass {
        global $DB;

        if ($staff) {
            // Authority is the manage capability, checked on the ACTOR;
            // the window and the actor's own caps are not consulted,
            // because a repair is precisely the case the window exists
            // to stop students doing.
            if (!has_capability('mod/selfselectadvanced:manage', $this->activity->context(), $userid)) {
                throw new \moodle_exception('errstaffcreatecap', 'mod_selfselectadvanced');
            }
            if ($leaderid === null) {
                throw new \coding_exception('A staff-created team must nominate a leader.');
            }
            if (!is_enrolled($this->activity->context(), $leaderid, 'mod/selfselectadvanced:respond', true)) {
                throw new \moodle_exception('errmovenotparticipant', 'mod_selfselectadvanced');
            }
            if ($refusal = $this->leader_capacity_refusal($leaderid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
        } else {
            $leaderid = $userid;
            if ($refusal = $this->gatekeeper->can_create_group($userid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
        }
        if (groups::name_taken($this->activity, $name)) {
            throw new \moodle_exception('errnametaken', 'mod_selfselectadvanced');
        }

        // Activity-scoped, deliberately. Since 1.16.0 a name must be
        // unique across every instance of this activity in the course,
        // which this lock does not span: two instances of the same
        // course could in principle mint the same name in the same
        // instant, and the loser is not refused. Widening the lock to
        // the course was tried and rejected - auto-grouping creates
        // groups under the activity lock too, so a wider lock here
        // would stop the two paths excluding each other and trade a
        // rare duplicate name for a real collision over seats. The
        // residue is a cosmetic duplicate, recorded rather than
        // papered over.
        $lock = locks::acquire('activity:' . $this->activity->id());
        try {
            $transaction = $DB->start_delegated_transaction();

            // Re-check under the lock: a parallel creation may have consumed the slot.
            if ($staff) {
                if ($refusal = $this->leader_capacity_refusal($leaderid)) {
                    throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
                }
            } else if ($refusal = $this->gatekeeper->can_create_group($userid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
            if (groups::name_taken($this->activity, $name)) {
                throw new \moodle_exception('errnametaken', 'mod_selfselectadvanced');
            }

            $now = time();
            $group = (object) [
                'activityid' => $this->activity->id(),
                'pluginuid' => '',
                'name' => trim($name),
                'title' => trim($title),
                'brief' => $brief,
                'briefformat' => $briefformat,
                'leaderid' => $leaderid,
                'guideid' => null,
                'state' => state::FORMING,
                'autoformed' => 0,
                'usermodified' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $group->id = $DB->insert_record('selfselectadvanced_group', $group);
            $group->pluginuid = groups::build_pluginuid($this->activity, (int) $group->id);
            $DB->set_field('selfselectadvanced_group', 'pluginuid', $group->pluginuid, ['id' => $group->id]);

            $DB->insert_record('selfselectadvanced_member', (object) [
                'groupid' => $group->id,
                'userid' => $leaderid,
                'status' => groups::STATUS_CONFIRMED,
                'isleader' => 1,
                'invitedby' => $staff ? $userid : null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            // Leading a new group consumes a membership slot too; when
            // it reaches the leader's cap, any other pending invitations
            // of theirs must cascade the same as an accept would (audit:
            // non-accept paths were leaving rivals pending forever).
            $cascaded = $this->invitations()->cascade_at_cap($leaderid);

            $other = ['pluginuid' => $group->pluginuid, 'name' => $group->name];
            if ($staff) {
                // The durable record that this team was made by staff,
                // possibly after the cutoff. Absent (not false) on the
                // student path: cheaper for old log consumers, and
                // group_created::validate_data() requires only pluginuid.
                $other['createdbystaff'] = true;
            }
            $event = \mod_selfselectadvanced\event\group_created::create([
                'objectid' => $group->id,
                'context' => $this->activity->context(),
                'other' => $other,
            ]);
            $event->trigger();

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        $this->invitations()->notify_cascaded($cascaded, $leaderid);

        return $group;
    }

    /**
     * The nominated leader's own L3/L4 position, judged with the same
     * reads the gatekeeper uses.
     *
     * Staff creation skips can_create_group() because the window and
     * the ACTOR's caps are student-shaped constraints - but the person
     * who will LEAD the team is still bound by the limits every other
     * leader is, and there is deliberately no bypass here: with the
     * default maxmembership of 1, parking the student first frees the
     * slot, so the two flows compose instead of stacking overrides.
     *
     * @param int $leaderid the nominated leader
     * @return \mod_selfselectadvanced\local\rules\refusal|null null when they have room
     */
    private function leader_capacity_refusal(int $leaderid): ?\mod_selfselectadvanced\local\rules\refusal {
        $resolver = $this->gatekeeper->resolver();

        $maxlead = $resolver->effective_maxlead($leaderid);
        $leading = groups::count_leading($this->activity, $leaderid);
        if ($leading >= $maxlead->value) {
            return new \mod_selfselectadvanced\local\rules\refusal('refusalleadcap', (object) [
                'current' => $leading,
                'max' => $maxlead->value,
            ]);
        }

        $maxmembership = $resolver->effective_maxmembership($leaderid);
        $memberships = groups::count_memberships($this->activity, $leaderid);
        if ($memberships >= $maxmembership->value) {
            return new \mod_selfselectadvanced\local\rules\refusal('refusalmembershipcap', (object) [
                'current' => $memberships,
                'max' => $maxmembership->value,
            ]);
        }

        return null;
    }

    /**
     * Delete a forming group (transition T7).
     *
     * Confirmed members are notified (provider 'groupdeleted'), the
     * acting user excepted; in the forming state before invitations
     * exist the leader is typically the only confirmed member.
     *
     * @param stdClass $group group row
     * @param int $userid the acting user (must be the leader)
     * @throws \moodle_exception when the gatekeeper refuses
     */
    public function delete_group(stdClass $group, int $userid): void {
        global $DB;

        if ($refusal = $this->gatekeeper->can_delete_group($group, $userid)) {
            throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
        }

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_delete_group($fresh, $userid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            // Confirmed roster captured before the rows disappear, for
            // the post-commit notification below.
            $confirmed = $DB->get_fieldset_select(
                'selfselectadvanced_member',
                'userid',
                'groupid = ? AND status = ?',
                [$fresh->id, groups::STATUS_CONFIRMED]
            );

            $DB->delete_records('selfselectadvanced_member', ['groupid' => $fresh->id]);
            $DB->delete_records('selfselectadvanced_group', ['id' => $fresh->id]);

            $event = \mod_selfselectadvanced\event\group_deleted::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'other' => ['pluginuid' => $fresh->pluginuid, 'name' => $fresh->name],
            ]);
            $event->trigger();

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        // The proposal attachments go with the group, and only once the
        // deletion has actually committed: file storage is not part of
        // the transaction, so removing them any earlier would destroy
        // the attachments of a group that a rollback then kept alive.
        get_file_storage()->delete_area_files(
            $this->activity->context()->id,
            'mod_selfselectadvanced',
            'proposal',
            (int) $fresh->id
        );

        $url = new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $this->activity->cm()->id]);
        foreach ($confirmed as $memberid) {
            if ((int) $memberid === $userid) {
                continue;
            }
            notifier::send(
                $this->activity,
                'groupdeleted',
                (int) $memberid,
                'msggroupdeletedsubject',
                'msggroupdeletedbody',
                (object) ['group' => format_string($fresh->name), 'activity' => $this->activity->name()],
                $url,
                $this->activity->name()
            );
        }
    }

    /**
     * Dissolve a team in ANY state: park every confirmed member and
     * close it (decision 6, D6-3).
     *
     * The verb exists because a solo-leader team could never be
     * repaired OR removed: moves::stage() refuses to move a leader out
     * without a successor, a one-member team can never name one, and
     * delete_group() is leader-and-forming-only. That is a dead end
     * with no exit at all, so this is the exit - authorised by
     * :manage AND :overriderules together, always with a typed reason,
     * always recorded per member.
     *
     * Two blockers are refused rather than overridden, because both are
     * resolvable through existing UI and both would destroy real data
     * silently: a gradebook award on the penalty ledger, and an open or
     * claimed ticket about this team.
     *
     * Every staged move and live join request naming the team is closed
     * in the same transaction: a pending move into or out of a team
     * that no longer exists can never commit, and validate_set() reads
     * both of its group ids with MUST_EXIST, so one orphan would take
     * the whole pending-moves page down and with it the only way to
     * cancel the orphan.
     *
     * @param stdClass $group the team to dissolve
     * @param string $reason why, from the staff member; required
     * @param int $actorid the acting staff member
     * @throws \moodle_exception when refused
     */
    public function dissolve_group(stdClass $group, string $reason, int $actorid): void {
        global $CFG, $DB;

        $context = $this->activity->context();
        if (
            !has_capability('mod/selfselectadvanced:manage', $context, $actorid)
            || !has_capability('mod/selfselectadvanced:overriderules', $context, $actorid)
        ) {
            throw new \moodle_exception('errdissolvecap', 'mod_selfselectadvanced');
        }
        if (trim($reason) === '') {
            throw new \moodle_exception('errdissolvereasonrequired', 'mod_selfselectadvanced');
        }

        // Captured BEFORE the locks (T-04's discipline): the refusals
        // below throw from INSIDE the transaction, so without this a
        // refused dissolve leaves a dangling delegated transaction.
        $outermost = !$DB->is_transaction_started();
        $locks = locks::acquire_all([
            'activity:' . $this->activity->id(),
            'group:' . $group->id,
        ]);
        $moverows = [];
        $orphanmoves = [];
        $oldcoregroupid = 0;
        try {
            $transaction = $DB->start_delegated_transaction();

            // Re-read INSIDE the lock; both blockers are judged on the
            // fresh rows, never on the copy the page loaded.
            $fresh = groups::get($this->activity, (int) $group->id);
            $awarded = $DB->record_exists_select(
                'selfselectadvanced_penalty',
                'activityid = ? AND groupid = ? AND award IS NOT NULL',
                [$this->activity->id(), (int) $fresh->id]
            );
            if ($awarded) {
                throw new \moodle_exception('errdissolveaward', 'mod_selfselectadvanced');
            }
            $liveticket = $DB->record_exists_select(
                'selfselectadvanced_ticket',
                'activityid = ? AND groupid = ? AND status IN (?, ?)',
                [
                    $this->activity->id(),
                    (int) $fresh->id,
                    tickets::STATUS_OPEN,
                    tickets::STATUS_CLAIMED,
                ]
            );
            if ($liveticket) {
                throw new \moodle_exception('errdissolveticket', 'mod_selfselectadvanced');
            }

            // The plugin-owned mirror is REMEMBERED here and deleted
            // after the commit and the release, so no core group API
            // call runs inside THIS lock and this transaction.
            //
            // Said as a local property and no longer as a universal
            // one. This comment used to read "no core group API call of
            // any kind runs inside this lock and transaction (T-16's
            // rule)", which is false for the plugin as a whole and was
            // measured false: freeze::sync_core_group() holds
            // 'group:{id}' across mint_core_group() ->
            // groups_create_group(), and \core\event\group_created was
            // observed dispatching at locks::held_count() === 1.
            // docs/architecture.md A7 names that one exception and what
            // it drags in with it. dissolve_group() is not that
            // exception, and the ordering below is what keeps it out.
            $oldcoregroupid = (int) ($fresh->coregroupid ?? 0);

            // Every staged move and every live join request that names
            // this team is closed HERE, before the group row goes. They
            // can never commit once the team is gone, and left pending
            // they are not merely dead: moves::validate_set() reads the
            // source and target of every pending row with
            // groups::get() (MUST_EXIST), so ONE orphan makes the whole
            // pending-moves page throw dml_missing_record_exception -
            // and the only way to cancel a move is from the page that
            // no longer loads. A live join request would strand its
            // asker the same way, holding the one-request-at-a-time
            // slot against a team that no longer exists.
            $orphanmoves = $DB->get_records_select(
                'selfselectadvanced_move',
                'activityid = :activityid AND status = :pending
                     AND (sourcegroupid = :sourceid OR targetgroupid = :targetid)',
                [
                    'activityid' => $this->activity->id(),
                    'pending' => 'pending',
                    'sourceid' => (int) $fresh->id,
                    'targetid' => (int) $fresh->id,
                ]
            );
            $orphanrequests = $DB->get_records('selfselectadvanced_move', [
                'activityid' => $this->activity->id(),
                'status' => joinrequests::STATUS_REQUESTED,
                'targetgroupid' => (int) $fresh->id,
            ]);

            $confirmed = array_map('intval', $DB->get_fieldset_select(
                'selfselectadvanced_member',
                'userid',
                'groupid = ? AND status = ?',
                [$fresh->id, groups::STATUS_CONFIRMED]
            ));
            // Leader last, so the record reads as the team draining and
            // then closing rather than losing its leader first.
            $leaderid = (int) $fresh->leaderid;
            $ordered = array_values(array_diff($confirmed, [$leaderid]));
            if (in_array($leaderid, $confirmed, true)) {
                $ordered[] = $leaderid;
            }

            // One committed park move row per confirmed member: the
            // per-member audit record requirement 11 demands of every
            // staff roster mutation. Inserted directly and NOT through
            // stage(), whose solo-leader refusal is the very wall this
            // verb exists to pass; these are records of an action, not
            // staged intents.
            $now = time();
            $statusinfo = json_encode(['DISSOLVE' => [
                'ok' => false,
                'bypassed' => true,
                'reason' => get_string('moveruledissolve', 'mod_selfselectadvanced'),
            ]]);
            foreach ($ordered as $memberid) {
                $moverow = (object) [
                    'activityid' => $this->activity->id(),
                    'userid' => $memberid,
                    'sourcegroupid' => (int) $fresh->id,
                    'targetgroupid' => null,
                    'makeleader' => 0,
                    'replaceleader' => 0,
                    'successorid' => null,
                    'status' => 'committed',
                    'statusinfo' => $statusinfo,
                    'responsenote' => trim($reason),
                    'usermodified' => $actorid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'timecommitted' => $now,
                ];
                $moverow->id = $DB->insert_record('selfselectadvanced_move', $moverow);
                $moverows[] = $moverow;
            }

            // Close them on the same rows the page would have offered a
            // cancel button for. Done inline rather than through
            // moves::cancel(), which re-acquires activity:{id} - not
            // re-entrant, so it would self-deadlock under the lock we
            // already hold. Their move-scope override rows and their
            // move_cancelled events are handled after the release, for
            // the reason cancel() itself records (override: is rank 5,
            // activity: is rank 6, and a lock never travels with an
            // event).
            foreach ($orphanmoves as $orphan) {
                $DB->update_record('selfselectadvanced_move', (object) [
                    'id' => $orphan->id,
                    'status' => 'cancelled',
                    'usermodified' => $actorid,
                    'timemodified' => $now,
                ]);
            }
            foreach ($orphanrequests as $orphan) {
                $DB->update_record('selfselectadvanced_move', (object) [
                    'id' => $orphan->id,
                    'status' => joinrequests::STATUS_DECLINED,
                    'responsenote' => trim($reason),
                    'usermodified' => $actorid,
                    'timemodified' => $now,
                ]);
            }

            $DB->delete_records('selfselectadvanced_member', ['groupid' => $fresh->id]);
            // Everything that is ONLY meaningful while the team exists
            // goes with it. delete_group() drops member and group rows
            // alone, which is enough for the FORMING team it is limited
            // to; this verb closes teams that have been firm or frozen,
            // so they carry a restore snapshot, a penalty row, guide
            // expressions of interest, approaches and possibly a
            // group-scope override. Left behind, those are rows no
            // report can reach and no page can explain.
            //
            // The MOVE rows are deliberately kept: they are the
            // per-member audit record this verb just wrote, and
            // requirement 11 is that no staff roster mutation goes
            // unrecorded. Resolved tickets are kept for the same
            // reason (open and claimed ones were refused above).
            $DB->delete_records('selfselectadvanced_snapshot', ['groupid' => $fresh->id]);
            $DB->delete_records('selfselectadvanced_penalty', [
                'activityid' => $this->activity->id(),
                'groupid' => $fresh->id,
            ]);
            $DB->delete_records('selfselectadvanced_eoi', ['groupid' => $fresh->id]);
            $DB->delete_records('selfselectadvanced_contact', [
                'activityid' => $this->activity->id(),
                'groupid' => $fresh->id,
            ]);
            $DB->delete_records('selfselectadvanced_override', [
                'activityid' => $this->activity->id(),
                'scope' => 'group',
                'groupid' => $fresh->id,
            ]);
            $DB->delete_records('selfselectadvanced_group', ['id' => $fresh->id]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            locks::release_all($locks);
        }

        // 1. The mirror dies with the team. Deliberately NOT through
        // freeze::discard_core_group(): that method REFUSES while the
        // team is frozen (exactly the case that has a mirror to
        // delete), re-acquires group:{id} - which is not re-entrant and
        // would self-deadlock to errlocktimeout - and would run the
        // core delete inside a still-open transaction. The guard is
        // "coregroupid is set", NOT "state === FROZEN": since T-16 a
        // FIRM team retains its mirror across unfreeze, and a
        // frozen-only delete would leave a permanently orphaned course
        // group no report can find.
        if ($oldcoregroupid) {
            require_once($CFG->dirroot . '/group/lib.php');
            if (groups_group_exists($oldcoregroupid)) {
                groups_delete_group($oldcoregroupid);
            }
        }

        // 2. Moved out of the transaction, unlike delete_group()'s.
        \mod_selfselectadvanced\event\group_deleted::create([
            'objectid' => (int) $fresh->id,
            'context' => $context,
            'other' => ['pluginuid' => $fresh->pluginuid, 'name' => $fresh->name],
        ])->trigger();

        // 3. The named override record, one per parked member.
        $figures = [get_string('moveruledissolve', 'mod_selfselectadvanced')];
        foreach ($moverows as $moverow) {
            \mod_selfselectadvanced\event\move_rules_overridden::create([
                'objectid' => (int) $moverow->id,
                'context' => $context,
                'relateduserid' => (int) $moverow->userid,
                'other' => [
                    'sourcegroupid' => (int) $fresh->id,
                    'targetgroupid' => null,
                    'rules' => ['DISSOLVE'],
                    'figures' => $figures,
                    'reason' => trim($reason),
                    'overrideid' => null,
                    'kind' => 'dissolve',
                ],
            ])->trigger();
        }

        // 3b. The staged moves this dissolve closed. The override rows
        // go OUTSIDE the lock for the reason moves::cancel() records -
        // store::delete() takes override:{scope}:{id}, rank 5, which
        // ranks before activity: (6) - and one batched read finds them
        // all rather than one per row.
        if ($orphanmoves) {
            $orphanoverrides = $DB->get_records_list(
                'selfselectadvanced_override',
                'moveid',
                array_map(static fn($orphan) => (int) $orphan->id, $orphanmoves),
                '',
                'id, moveid, activityid, scope'
            );
            foreach ($orphanoverrides as $orphanoverride) {
                if (
                    (int) $orphanoverride->activityid !== $this->activity->id()
                    || $orphanoverride->scope !== 'move'
                ) {
                    continue;
                }
                \mod_selfselectadvanced\local\override\store::delete(
                    $this->activity,
                    (int) $orphanoverride->id,
                    $actorid
                );
            }
            foreach ($orphanmoves as $orphan) {
                \mod_selfselectadvanced\event\move_cancelled::create([
                    'objectid' => (int) $orphan->id,
                    'context' => $context,
                    'relateduserid' => (int) $orphan->userid,
                    'other' => [
                        'sourcegroupid' => $orphan->sourcegroupid ? (int) $orphan->sourcegroupid : null,
                        'targetgroupid' => $orphan->targetgroupid ? (int) $orphan->targetgroupid : null,
                    ],
                ])->trigger();
            }
        }

        // 4. The ex-members hear about it, mirroring delete_group().
        $url = new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $this->activity->cm()->id]);
        foreach ($moverows as $moverow) {
            if ((int) $moverow->userid === $actorid) {
                continue;
            }
            notifier::send(
                $this->activity,
                'groupdeleted',
                (int) $moverow->userid,
                'msggroupdeletedsubject',
                'msggroupdeletedbody',
                (object) ['group' => format_string($fresh->name), 'activity' => $this->activity->name()],
                $url,
                $this->activity->name()
            );
        }

        // 5. File storage is not part of the transaction, so the
        // attachments go only once the deletion has actually committed.
        get_file_storage()->delete_area_files(
            $context->id,
            'mod_selfselectadvanced',
            'proposal',
            (int) $fresh->id
        );
    }
}
