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
use mod_selfselectadvanced\local\authority;
use mod_selfselectadvanced\local\rules\gatekeeper;
use stdClass;

/**
 * Leadership transfer and leader step-out (spec section 6.4, decision A3).
 *
 * One active nomination per group, held on the group row. The nominee
 * confirms; the transfer executes atomically under the group lock with
 * the L3 slot re-checked, and for step-out the post-departure minimum
 * size (L1) - a replacement member must be confirmed first. The
 * outgoing leader's lead slot is released by the transfer itself
 * (leaderid moves on); after step-out the former leader may hold
 * pending invitations elsewhere (a held place) or remain groupless.
 *
 * AUTHORITY (1.20.1, audit F-1). Leadership can be ACQUIRED as well as
 * created, and this file is where it is acquired. Every verb here asks
 * \local\authority before it takes a lock, on the same split the rest
 * of the plugin uses:
 *
 * - nominate() and cancel() are the LEADER's verbs, so they ask :lead,
 *   the same authority as invitations::send(), withdraw() and
 *   confirm_leave().
 * - confirm() and decline() are the NOMINEE's answer, so both ask
 *   :respond. Confirm additionally requires :lead because it installs
 *   the nominee as leader; decline does not install anybody.
 *
 * Measured before the fix on both engines: with :creategroup and
 * :respond both PROHIBITed at the activity context a student was
 * nominated, confirmed, and became the team's leader. The gatekeeper
 * calls these methods still make answer ownership and lifecycle, which
 * is a different question and never was authority.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class succession {
    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param gatekeeper $gatekeeper the gatekeeper to consult
     */
    public function __construct(activity $activity, gatekeeper $gatekeeper) {
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;
    }

    /**
     * Nominate a confirmed member as successor (leader action).
     *
     * @param stdClass $group group row
     * @param int $nomineeid the proposed successor
     * @param string $type 'transfer' or 'stepout'
     * @param int $actorid the acting leader
     * @throws \moodle_exception when the gatekeeper refuses
     * @throws \required_capability_exception when the leader does not
     *         hold :lead
     */
    public function nominate(stdClass $group, int $nomineeid, string $type, int $actorid): void {
        global $DB;

        // BEFORE the lock, the write and the message. Naming a successor
        // is a leader's disposal of the team's leadership, so it is the
        // leader authority - the same one create, invite, withdraw and
        // confirm-leave ask for.
        authority::require_lead($this->activity, $actorid);

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_nominate($fresh, $nomineeid, $type, $actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $fresh->id,
                'successorid' => $nomineeid,
                'successortype' => $type,
                'timenominated' => time(),
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The can_nominate() gate is asked on the row read INSIDE the lock
            // and throws from inside the transaction; group.php catches
            // moodle_exception and redirects with a notification, so a
            // caught refusal never reaches Moodle's exception handler
            // and nothing else would roll this back.
            //
            // Unconditional, never gated on
            // $DB->is_transaction_started(): under PHPUnit that
            // predicate answers for advanced_testcase (true on m5pg,
            // false on m5my) rather than for this method, and the
            // nested arm it selects is wrong anyway - an undisposed
            // frame left on the stack makes the caller's own rollback()
            // rethrow without issuing the physical ROLLBACK. See
            // state::submit() and penalty\ledger::set_award().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        notifier::send(
            $this->activity,
            'nomination',
            $nomineeid,
            'msgnominationsubject',
            'msgnominationbody' . $type,
            (object) ['group' => format_string($group->name), 'pluginuid' => $group->pluginuid],
            $this->group_url((int) $group->id),
            format_string($group->name)
        );
    }

    /**
     * Confirm the active nomination (nominee action): leadership
     * transfers; for step-out the outgoing leader leaves the group.
     *
     * @param stdClass $group group row
     * @param int $userid the confirming nominee
     * @return string the executed type ('transfer' or 'stepout')
     * @throws \moodle_exception when the gatekeeper refuses
     * @throws \required_capability_exception when the nominee does not
     *         hold :respond
     */
    public function confirm(stdClass $group, int $userid): string {
        global $DB;

        // BEFORE the locks, the writes, the event and the message. The
        // nominee is answering a nomination, so :respond still applies.
        // The old code deliberately avoided :creategroup so pausing new
        // groups could not strand a handover. The split makes that
        // workaround unnecessary: :lead now asks only whether this
        // person may run an existing group, which is exactly what a
        // successful confirmation is about.
        authority::require_respond($this->activity, $userid);
        if (!authority::may_lead($this->activity, $userid)) {
            throw new workflow_refusal('refusalnomineecannotlead', 'mod_selfselectadvanced');
        }

        // L3 lead counts span groups: activity lock first (audit item 6).
        $activitylock = locks::acquire('activity:' . $this->activity->id());
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_confirm_succession($fresh, $userid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $oldleaderid = (int) $fresh->leaderid;
            $type = $fresh->successortype;

            // The successor becomes leader.
            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $fresh->id,
                'leaderid' => $userid,
                'successorid' => null,
                'successortype' => null,
                'timenominated' => null,
                'usermodified' => $userid,
                'timemodified' => $now,
            ]);
            $DB->set_field('selfselectadvanced_member', 'isleader', 1, [
                'groupid' => $fresh->id,
                'userid' => $userid,
            ]);
            $DB->set_field('selfselectadvanced_member', 'isleader', 0, [
                'groupid' => $fresh->id,
                'userid' => $oldleaderid,
            ]);

            if ($type === 'stepout') {
                // The former leader leaves the group entirely.
                $old = $DB->get_record('selfselectadvanced_member', [
                    'groupid' => $fresh->id,
                    'userid' => $oldleaderid,
                ], '*', MUST_EXIST);
                $old->status = groups::STATUS_REMOVED;
                $old->timeresponded = $now;
                $old->timemodified = $now;
                $DB->update_record('selfselectadvanced_member', $old);
            }

            \mod_selfselectadvanced\event\leadership_transferred::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'relateduserid' => $userid,
                'other' => [
                    'fromuserid' => $oldleaderid,
                    'pluginuid' => $fresh->pluginuid,
                    'type' => $type,
                ],
            ])->trigger();

            // A step-out removes the outgoing leader from the roster,
            // so the mirror has to follow. The gatekeeper limits this
            // path to FORMING today, where no mirror can exist and both
            // calls are no-ops - they are here so a later relaxation of
            // that gate cannot silently strand a mirror.
            freeze::request_sync($this->activity, $fresh);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The can_confirm_succession() gate and the MUST_EXIST read of the
            // outgoing leader's member row both throw from inside the
            // transaction. Unconditional - see nominate().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $activitylock->release();
        }

        freeze::sync_core_group($this->activity, (int) $fresh->id, $userid);

        notifier::send(
            $this->activity,
            'nominationresult',
            $oldleaderid,
            'msgnominationconfirmedsubject',
            'msgnominationconfirmedbody',
            (object) [
                'user' => fullname(\core_user::get_user($userid)),
                'group' => format_string($group->name),
            ],
            $this->group_url((int) $group->id),
            format_string($group->name)
        );

        return $type;
    }

    /**
     * STAFF FILL A LEADERSHIP VACANCY. This is a repair, not a transfer.
     *
     * Deletion, last unenrolment or privacy erasure can leave a group with
     * leaderid NULL and nobody able to act for it. Before 1.20.35 the flagged
     * report told staff to "designate one via a staged move", which does not
     * work: moves::stage() refuses a target who is already a confirmed member,
     * and the only lawful replacement IS a confirmed member. So the advice
     * named an operation the plugin could not perform.
     *
     * Deliberately NOT a general "replace the leader" verb. It refuses unless
     * the group is genuinely vacant, so it can never become a back door around
     * succession, which is the consensual route a live leader uses.
     *
     * @param stdClass $group the vacant group
     * @param int $newleaderid the confirmed member being appointed
     * @param int $actorid the staff member performing the repair
     * @throws \moodle_exception when the actor lacks authority or the candidate is ineligible
     */
    public function appoint_vacant_leader(stdClass $group, int $newleaderid, int $actorid): void {
        global $DB;

        // AUTHORITY. Full manage, or the narrow composition capability the
        // installed Group Coordinator role carries.
        $context = $this->activity->context();
        $ismanager = has_capability('mod/selfselectadvanced:manage', $context, $actorid);
        if (!$ismanager && !has_capability('mod/selfselectadvanced:managecomposition', $context, $actorid)) {
            throw new workflow_refusal('refusalleadervacantnoauthority', 'mod_selfselectadvanced');
        }
        if (!$ismanager) {
            // A narrow holder may not decide a group they are part of - the
            // same conflict rule the staged-move engine applies, asked through
            // the existing public policy rather than a second copy of it.
            tickets::require_uninvolved($this->activity, $group, $actorid);
        }

        // ACTIVITY LOCK BEFORE GROUP LOCK. L3 counts leadership across the
        // whole activity, so two repairs in different groups can race each
        // other over the same candidate's last free slot.
        $activitylock = locks::acquire('activity:' . $this->activity->id());
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($fresh->leaderid !== null) {
                // The losing side of two competing repairs lands here, and is
                // told the truth rather than silently overwriting the winner.
                throw new workflow_refusal('refusalleadervacantfilled', 'mod_selfselectadvanced');
            }

            // THE CANDIDATE. Enrolled, confirmed in THIS group, allowed to
            // lead, and with an L3 slot free - each asked separately so the
            // refusal names the actual obstacle.
            if (!is_enrolled($context, $newleaderid, 'mod/selfselectadvanced:respond', true)) {
                throw new workflow_refusal('errmovenotparticipant', 'mod_selfselectadvanced');
            }
            $membership = $DB->get_record('selfselectadvanced_member', [
                'groupid' => (int) $fresh->id,
                'userid' => $newleaderid,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            if (!$membership) {
                throw new workflow_refusal('refusalleadervacantnotmember', 'mod_selfselectadvanced');
            }
            if (!authority::may_lead($this->activity, $newleaderid)) {
                throw new workflow_refusal('refusalnomineecannotlead', 'mod_selfselectadvanced');
            }
            $max = $this->gatekeeper->resolver()->effective_maxlead($newleaderid);
            $leading = groups::count_leading($this->activity, $newleaderid);
            if ($leading >= $max->value) {
                throw new workflow_refusal('refusalleadervacantatcap', 'mod_selfselectadvanced', '', (object) [
                    'current' => $leading,
                    'max' => $max->value,
                ]);
            }

            // ONE LEADER FLAG. Cleared across the whole group first, because a
            // vacancy created by an older release can still carry a stale flag
            // on a removed row.
            $now = time();
            $DB->set_field('selfselectadvanced_member', 'isleader', 0, ['groupid' => (int) $fresh->id]);
            $DB->set_field('selfselectadvanced_member', 'isleader', 1, ['id' => (int) $membership->id]);
            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => (int) $fresh->id,
                'leaderid' => $newleaderid,
                // A nomination raised before the vacancy belonged to a leader
                // who no longer exists.
                'successorid' => null,
                'successortype' => null,
                'timenominated' => null,
                'usermodified' => $actorid,
                'timemodified' => $now,
            ]);

            $event = \mod_selfselectadvanced\event\leadership_transferred::create([
                'objectid' => (int) $fresh->id,
                'context' => $context,
                'relateduserid' => $newleaderid,
                'other' => [
                    // No outgoing leader: the event description says
                    // "assigned" rather than naming user id 0.
                    'fromuserid' => 0,
                    'pluginuid' => $fresh->pluginuid,
                    'type' => 'repair',
                ],
            ]);

            freeze::request_sync($this->activity, $fresh);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $activitylock->release();
        }

        // AFTER the commit and both locks, following this file's discipline:
        // the payload was built inside the critical section, but nothing
        // observable fires while a lock is held.
        $event->trigger();
    }

    /**
     * Decline the active nomination (nominee action).
     *
     * @param stdClass $group group row
     * @param int $userid the declining nominee
     * @throws \moodle_exception when the caller is not the nominee
     * @throws \required_capability_exception when the nominee does not
     *         hold :respond
     */
    public function decline(stdClass $group, int $userid): void {
        // Both halves of the response are gated, as on the invitation
        // path: declining writes a row and mails the leader, and the
        // capability string says "Accept OR DECLINE". Nothing is
        // stranded by refusing - the leader can still cancel().
        authority::require_respond($this->activity, $userid);
        $this->clear($group, $userid, true);
    }

    /**
     * Cancel the active nomination (leader action).
     *
     * @param stdClass $group group row
     * @param int $actorid the acting leader
     * @throws \moodle_exception when the caller is not the leader
     * @throws \required_capability_exception when the leader does not
     *         hold :lead
     */
    public function cancel(stdClass $group, int $actorid): void {
        // Authority first, then ownership: "you are the leader" is a
        // fact about the row and has never been a grant.
        authority::require_lead($this->activity, $actorid);
        if ((int) $group->leaderid !== $actorid) {
            throw new workflow_refusal('refusalnotleader', 'mod_selfselectadvanced');
        }
        $this->clear($group, $actorid, false);
    }

    /**
     * Clear the nomination fields and notify the other party.
     *
     * @param stdClass $group group row
     * @param int $actorid acting user (nominee when declining, leader when cancelling)
     * @param bool $declining true when the nominee declines
     */
    private function clear(stdClass $group, int $actorid, bool $declining): void {
        global $DB;

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if (empty($fresh->successorid)) {
                throw new workflow_refusal('refusalnotnominee', 'mod_selfselectadvanced');
            }
            if ($declining && (int) $fresh->successorid !== $actorid) {
                throw new workflow_refusal('refusalnotnominee', 'mod_selfselectadvanced');
            }
            $nomineeid = (int) $fresh->successorid;

            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $fresh->id,
                'successorid' => null,
                'successortype' => null,
                'timenominated' => null,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Both refusalnotnominee guards are judged on the row read
            // INSIDE the lock and throw from inside the transaction -
            // the nominee answering while the leader cancels is the
            // ordinary race. Unconditional - see nominate().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $recipient = $declining ? (int) $fresh->leaderid : $nomineeid;
        notifier::send(
            $this->activity,
            'nominationresult',
            $recipient,
            $declining ? 'msgnominationdeclinedsubject' : 'msgnominationcancelledsubject',
            $declining ? 'msgnominationdeclinedbody' : 'msgnominationcancelledbody',
            (object) ['group' => format_string($group->name)],
            $this->group_url((int) $group->id),
            format_string($group->name)
        );
    }

    /**
     * Deep link to a group page.
     *
     * @param int $groupid the group
     * @return \moodle_url
     */
    private function group_url(int $groupid): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $this->activity->cm()->id,
            'g' => $groupid,
        ]);
    }
}
