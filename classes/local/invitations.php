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
use mod_selfselectadvanced\local\rules\gatekeeper;
use stdClass;

/**
 * The invitation engine (spec section 6.2, limits 4A.2/4A.4).
 *
 * Reserved seats: a pending invitation occupies a seat, so
 * confirmed + pending never exceeds the effective max_size. Acceptance
 * re-validates the invitee's cap and the group's seats atomically under
 * the group lock; reaching the cap triggers the acceptance cascade -
 * every other pending invitation held by the user is auto-declined in
 * the same transaction with the affected leaders notified (4A.4, the
 * only automatic decline besides expiry).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invitations {
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
     * Send an invitation (leader action).
     *
     * A declined, expired or removed row for the same user is re-used
     * and returns to invited (decision A2); history lives in events.
     *
     * @param stdClass $group group row
     * @param int $inviteeid the invitee
     * @param int $actorid the inviting leader
     * @return stdClass the member row
     * @throws \moodle_exception when the gatekeeper refuses
     * @throws \required_capability_exception when the leader does not
     *         hold :lead
     */
    public function send(stdClass $group, int $inviteeid, int $actorid): stdClass {
        global $DB;

        // Authority before ownership: inviting is a leader action, and
        // leading an existing group is what :lead grants. Being named on
        // the row is not a grant.
        authority::require_lead($this->activity, $actorid);
        if ((int) $group->leaderid !== $actorid) {
            throw new workflow_refusal('refusalnotleader', 'mod_selfselectadvanced');
        }
        if ($refusal = $this->gatekeeper->can_invite($group, $inviteeid)) {
            throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
        }

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_invite($fresh, $inviteeid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $member = $DB->get_record('selfselectadvanced_member', [
                'groupid' => $fresh->id,
                'userid' => $inviteeid,
            ]);
            if ($member) {
                $member->status = groups::STATUS_INVITED;
                $member->invitedby = $actorid;
                $member->timeinvited = $now;
                $member->timeresponded = null;
                $member->timemodified = $now;
                $DB->update_record('selfselectadvanced_member', $member);
            } else {
                $member = (object) [
                    'groupid' => $fresh->id,
                    'userid' => $inviteeid,
                    'status' => groups::STATUS_INVITED,
                    'isleader' => 0,
                    'invitedby' => $actorid,
                    'timeinvited' => $now,
                    'timeresponded' => null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $member->id = $DB->insert_record('selfselectadvanced_member', $member);
            }

            \mod_selfselectadvanced\event\invitation_sent::create([
                'objectid' => $member->id,
                'context' => $this->activity->context(),
                'relateduserid' => $inviteeid,
                'other' => ['groupid' => (int) $fresh->id, 'pluginuid' => $fresh->pluginuid],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The can_invite() gate is re-asked on the row read INSIDE the lock,
            // so a rival invitation that filled the last seat between
            // the two asks is refused from inside the transaction -
            // and group.php catches that to redraw with the refusal.
            // Unconditional, never gated on
            // $DB->is_transaction_started(): see
            // state::submit() for why that predicate answers for the
            // test harness rather than for this method, and why the
            // nested arm it selected was the wrong one anyway.
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $expirydays = (int) $this->activity->settings()->inviteexpiry;
        $expirynote = '';
        if ($expirydays > 0) {
            $expiresat = userdate(time() + ($expirydays * DAYSECS));
            $expirynote = ' ' . get_string('msginvitationexpirynote', 'mod_selfselectadvanced', $expiresat);
        }
        notifier::send(
            $this->activity,
            'invitation',
            $inviteeid,
            'msginvitationsubject',
            'msginvitationbody',
            (object) [
                'group' => format_string($group->name),
                'pluginuid' => $group->pluginuid,
                'activity' => $this->activity->name(),
                'expirynote' => $expirynote,
            ],
            $this->group_url((int) $group->id),
            format_string($group->name)
        );

        return $member;
    }

    /**
     * Accept an invitation (invitee action).
     *
     * Atomic under the group lock: state, window, seats (L2) and the
     * invitee's cap (L4) re-checked inside the transaction; the
     * acceptance cascade fires when the cap is reached.
     *
     * @param stdClass $group group row
     * @param int $userid the invitee acting on their own invitation
     * @return stdClass the confirmed member row
     * @throws \moodle_exception when the gatekeeper refuses
     * @throws \required_capability_exception when the invitee does not
     *         hold :respond
     */
    public function accept(stdClass $group, int $userid): stdClass {
        global $DB;

        // BEFORE the lock, the write, the event and the message (A-03).
        // Holding an invitation row is not authority to answer it: an
        // invited user with :respond prohibited called this service and
        // became CONFIRMED, while the sibling path
        // joinrequests::request() had required the same capability
        // since it was written. Two paths that disagree about one
        // question are an oversight, not a policy.
        authority::require_respond($this->activity, $userid);

        // L4 counts across ALL groups; the group lock alone cannot
        // serialise two accepts into different groups (audit item 6).
        $activitylock = locks::acquire('activity:' . $this->activity->id());
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            $member = $DB->get_record('selfselectadvanced_member', [
                'groupid' => $fresh->id,
                'userid' => $userid,
            ], '*', MUST_EXIST);

            if ($refusal = $this->gatekeeper->can_accept($fresh, $member)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $member->status = groups::STATUS_CONFIRMED;
            $member->timeresponded = $now;
            $member->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $member);

            \mod_selfselectadvanced\event\invitation_accepted::create([
                'objectid' => $member->id,
                'context' => $this->activity->context(),
                'relateduserid' => $userid,
                'other' => ['groupid' => (int) $fresh->id, 'pluginuid' => $fresh->pluginuid],
            ])->trigger();

            // Acceptance cascade (4A.4): at the cap, every other pending
            // invitation is auto-declined in this same transaction.
            $cascaded = $this->cascade_at_cap($userid, (int) $fresh->id);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Two throws live inside this transaction: the MUST_EXIST
            // member read (the invitation can be withdrawn between the
            // page load and the click) and the can_accept() refusal.
            // Unconditional - see send().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $activitylock->release();
        }

        // Notifications after commit: inviter, and each cascaded leader.
        notifier::send(
            $this->activity,
            'invitationresult',
            (int) $fresh->leaderid,
            'msgacceptedsubject',
            'msgacceptedbody',
            (object) [
                'user' => fullname(\core_user::get_user($userid)),
                // The body's own vocabulary (seam audit H5): the
                // sentence promises {$a->member} and {$a->size}, so
                // the producer supplies them.
                'member' => fullname(\core_user::get_user($userid)),
                'size' => groups::count_confirmed((int) $fresh->id),
                'group' => format_string($fresh->name),
            ],
            $this->group_url((int) $fresh->id),
            format_string($fresh->name)
        );
        $this->notify_cascaded($cascaded, $userid);

        return $member;
    }

    /**
     * Decline an invitation (invitee action). Always allowed (spec 6.2);
     * releases the reserved seat and notifies the leader.
     *
     * @param stdClass $group group row
     * @param int $userid the invitee
     * @return stdClass the declined member row
     * @throws \required_capability_exception when the invitee does not
     *         hold :respond
     */
    public function decline(stdClass $group, int $userid): stdClass {
        global $DB;

        // Spec 6.2's "always allowed" is a RULE statement - no window,
        // no seat and no cap can stop a decline - not an authority one,
        // and the two were being read as one sentence. A decline still
        // writes a member row, fires an event and mails the leader, so
        // it is a response and :respond governs it - deliberately with
        // no cleanup exception, because an invitation the invitee may
        // no longer answer is still withdrawable by its leader and
        // still expirable by expire_due(), neither of which asks the
        // invitee for anything.
        authority::require_respond($this->activity, $userid);

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            $member = $DB->get_record('selfselectadvanced_member', [
                'groupid' => $fresh->id,
                'userid' => $userid,
            ], '*', MUST_EXIST);
            if ($member->status !== groups::STATUS_INVITED) {
                throw new workflow_refusal('refusalnotinvited', 'mod_selfselectadvanced');
            }

            $now = time();
            $member->status = groups::STATUS_DECLINED;
            $member->timeresponded = $now;
            $member->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $member);

            \mod_selfselectadvanced\event\invitation_declined::create([
                'objectid' => $member->id,
                'context' => $this->activity->context(),
                'relateduserid' => $userid,
                'other' => ['groupid' => (int) $fresh->id, 'pluginuid' => $fresh->pluginuid, 'reason' => 'declined'],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The MUST_EXIST member read and the refusalnotinvited
            // guard both throw from inside the transaction.
            // Unconditional - see send().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        notifier::send(
            $this->activity,
            'invitationresult',
            (int) $fresh->leaderid,
            'msgdeclinedsubject',
            'msgdeclinedbody',
            (object) [
                'user' => fullname(\core_user::get_user($userid)),
                'member' => fullname(\core_user::get_user($userid)),
                'group' => format_string($fresh->name),
            ],
            $this->group_url((int) $fresh->id),
            format_string($fresh->name)
        );

        return $member;
    }

    /**
     * Withdraw a pending invitation (leader action), freeing its seat.
     *
     * @param stdClass $group group row
     * @param int $memberid the member row id
     * @param int $actorid the acting leader
     * @return stdClass the member row
     * @throws \moodle_exception when the gatekeeper refuses
     * @throws \required_capability_exception when the leader does not
     *         hold :lead
     */
    public function withdraw(stdClass $group, int $memberid, int $actorid): stdClass {
        global $DB;

        // A leader action, so the leader authority (A-02).
        authority::require_lead($this->activity, $actorid);

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            $member = $DB->get_record('selfselectadvanced_member', [
                'id' => $memberid,
                'groupid' => $fresh->id,
            ], '*', MUST_EXIST);
            if ($refusal = $this->gatekeeper->can_withdraw($fresh, $member, $actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $member->status = groups::STATUS_REMOVED;
            $member->timeresponded = $now;
            $member->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $member);

            \mod_selfselectadvanced\event\invitation_withdrawn::create([
                'objectid' => $member->id,
                'context' => $this->activity->context(),
                'relateduserid' => (int) $member->userid,
                'other' => ['groupid' => (int) $fresh->id, 'pluginuid' => $fresh->pluginuid],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The MUST_EXIST member read and the can_withdraw()
            // refusal both throw from inside the transaction - the
            // invitee accepting first is the ordinary race.
            // Unconditional - see send().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        notifier::send(
            $this->activity,
            'invitationresult',
            (int) $member->userid,
            'msgwithdrawnsubject',
            'msgwithdrawnbody',
            (object) ['group' => format_string($fresh->name)],
            $this->activity_url(),
            $this->activity->name()
        );

        return $member;
    }

    /**
     * Expire due invitations for this activity (scheduled task path).
     *
     * Expiry is timeinvited plus the activity's inviteexpiry days;
     * expired invitations auto-decline and release their seat.
     *
     * @param int|null $now time of the run, defaults to now
     * @return int number of invitations expired
     */
    public function expire_due(?int $now = null): int {
        global $DB;

        $now = $now ?? time();
        $days = (int) $this->activity->settings()->inviteexpiry;
        if ($days < 1) {
            return 0;
        }
        $deadline = $now - ($days * DAYSECS);

        $sql = "SELECT m.*, g.pluginuid, g.name AS groupname, g.leaderid
                  FROM {selfselectadvanced_member} m
                  JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                 WHERE g.activityid = :activityid
                   AND m.status = :status
                   AND m.timeinvited IS NOT NULL
                   AND m.timeinvited < :deadline";
        $due = $DB->get_records_sql($sql, [
            'activityid' => $this->activity->id(),
            'status' => groups::STATUS_INVITED,
            'deadline' => $deadline,
        ]);

        $count = 0;
        foreach ($due as $row) {
            $lock = locks::acquire('group:' . $row->groupid);
            try {
                $transaction = $DB->start_delegated_transaction();

                $member = $DB->get_record('selfselectadvanced_member', ['id' => $row->id], '*', MUST_EXIST);
                if ($member->status !== groups::STATUS_INVITED) {
                    $transaction->allow_commit();
                    continue;
                }
                $member->status = groups::STATUS_EXPIRED;
                $member->timeresponded = $now;
                $member->timemodified = $now;
                $DB->update_record('selfselectadvanced_member', $member);

                \mod_selfselectadvanced\event\invitation_expired::create([
                    'objectid' => $member->id,
                    'context' => $this->activity->context(),
                    'relateduserid' => (int) $member->userid,
                    'other' => ['groupid' => (int) $row->groupid, 'pluginuid' => $row->pluginuid],
                ])->trigger();

                $transaction->allow_commit();
                $count++;
            } catch (\Throwable $e) {
                // The MUST_EXIST read inside the loop's transaction is
                // the reachable throw: the member row can be deleted
                // with its group between the batch query and the lock.
                // Without this the sweep walked out of the task leaving
                // one delegated transaction open per abandoned
                // iteration. Unconditional - see send().
                if (isset($transaction) && !$transaction->is_disposed()) {
                    $transaction->rollback($e);
                }
                throw $e;
            } finally {
                $lock->release();
            }

            $a = (object) ['group' => format_string($row->groupname)];
            notifier::send(
                $this->activity,
                'invitationresult',
                (int) $member->userid,
                'msgexpiredinviteesubject',
                'msgexpiredinviteebody',
                $a,
                $this->activity_url(),
                $this->activity->name()
            );
            notifier::send(
                $this->activity,
                'invitationresult',
                (int) $row->leaderid,
                'msgexpiredleadersubject',
                'msgexpiredleaderbody',
                (object) ['group' => format_string($row->groupname),
                    'user' => fullname(\core_user::get_user((int) $member->userid))],
                $this->group_url((int) $row->groupid),
                format_string($row->groupname)
            );
        }

        return $count;
    }

    /**
     * The cascade trigger check (4A.4), reusable by every path that can
     * consume a confirmed membership slot for $userid - not only
     * accept(), but also group creation and auto-placement, which
     * previously left rival invitations pending forever. Call inside
     * the caller's transaction while holding the activity or group
     * lock: it re-reads the user's current membership count and
     * effective cap, and only runs the decline sweep when the cap has
     * actually been reached.
     *
     * @param int $userid the user who may have just reached their cap
     * @param int $excludegroupid group to exempt from the decline sweep (e.g. the one just joined), 0 for none
     * @return stdClass[] cascaded rows (groupid, leaderid, name, memberid); empty when below cap
     */
    public function cascade_at_cap(int $userid, int $excludegroupid = 0): array {
        $cap = $this->gatekeeper->resolver()->effective_maxmembership($userid);
        if (groups::count_memberships($this->activity, $userid) < $cap->value) {
            return [];
        }

        return $this->cascade($userid, $excludegroupid);
    }

    /**
     * Post-commit notifications for a cascade_at_cap() result: each
     * affected leader is told their invitation to $userid was
     * auto-declined. Call once the caller's transaction has committed
     * and its lock released - never inside the transaction, since
     * message_send() is not transactional.
     *
     * @param stdClass[] $cascaded rows returned by cascade_at_cap()
     * @param int $userid the user whose cap triggered the cascade
     */
    public function notify_cascaded(array $cascaded, int $userid): void {
        notifier::send_all($this->activity, $this->cascade_intents($cascaded, $userid));
    }

    /**
     * The same cascade notifications as intents, so a caller still
     * holding a lock (the move engine, an auto-grouping run) can defer
     * them to after its release instead of sending under it.
     *
     * @param stdClass[] $cascaded rows returned by cascade_at_cap()
     * @param int $userid the user whose cap triggered the cascade
     * @return stdClass[] notifier::intent() records, in send order
     */
    public function cascade_intents(array $cascaded, int $userid): array {
        $intents = [];
        foreach ($cascaded as $row) {
            $intents[] = notifier::intent(
                'invitationresult',
                (int) $row->leaderid,
                'msgcascadesubject',
                'msgcascadebody',
                (object) ['user' => fullname(\core_user::get_user($userid)), 'group' => format_string($row->name)],
                $this->group_url((int) $row->groupid),
                format_string($row->name)
            );
        }

        return $intents;
    }

    /**
     * A member asks to leave a forming team (spec 6.3).
     *
     * @param stdClass $group group row (may be stale; re-read under the lock)
     * @param int $userid the member asking
     * @return stdClass the member row with leaverequested set
     * @throws \moodle_exception when the gatekeeper refuses
     */
    public function request_leave(stdClass $group, int $userid): stdClass {
        global $DB;

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            // The caller's copy can be minutes old; a submit can land
            // between the page load and the click (T-02 R2). Until this
            // moved out of group.php it was judged on the page-loaded
            // row and written with no lock, no transaction and no
            // re-read at all.
            $fresh = groups::get($this->activity, (int) $group->id);
            $member = $DB->get_record('selfselectadvanced_member', [
                'groupid' => $fresh->id,
                'userid' => $userid,
            ], '*', MUST_EXIST);
            if ($refusal = $this->gatekeeper->can_request_leave($fresh, $member, $userid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $member->leaverequested = $now;
            $member->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $member);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Unconditional since 1.20 wave 3D - see send().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        // Mail never travels under a lock.
        notifier::send(
            $this->activity,
            'leaverequest',
            (int) $fresh->leaderid,
            'msgleaverequestsubject',
            'msgleaverequestbody',
            (object) [
                'user' => fullname(\core_user::get_user($userid)),
                'group' => format_string($fresh->name),
            ],
            $this->group_url((int) $fresh->id),
            format_string($fresh->name)
        );

        return $member;
    }

    /**
     * The leader confirms a member's leave request (spec 6.3, L1-gated).
     *
     * @param stdClass $group group row (may be stale; re-read under the lock)
     * @param int $memberid the member row id
     * @param int $actorid the acting leader
     * @return stdClass the removed member row
     * @throws \moodle_exception when the gatekeeper refuses
     * @throws \required_capability_exception when the leader does not
     *         hold :lead
     */
    public function confirm_leave(stdClass $group, int $memberid, int $actorid): stdClass {
        global $DB;

        // A leader action, so the leader authority (A-02). The MEMBER's
        // own request_leave() is deliberately not gated: a student who
        // has lost every capability here must still be able to ask to
        // leave, and the leader's confirmation is the write that
        // actually changes the roster.
        authority::require_lead($this->activity, $actorid);

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Judged on the team as it is NOW: a leave confirmed from a
            // page loaded while the team was forming used to shrink a
            // team that had already been submitted (T-02 R2).
            $fresh = groups::get($this->activity, (int) $group->id);
            $member = $DB->get_record('selfselectadvanced_member', [
                'id' => $memberid,
                'groupid' => $fresh->id,
            ], '*', MUST_EXIST);
            if ($refusal = $this->gatekeeper->can_confirm_leave($fresh, $member, $actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $member->status = groups::STATUS_REMOVED;
            $member->leaverequested = null;
            $member->isleader = 0;
            $member->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $member);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Unconditional since 1.20 wave 3D - see send().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        // Mail never travels under a lock.
        notifier::send(
            $this->activity,
            'leaveresult',
            (int) $member->userid,
            'msgleaveconfirmedsubject',
            'msgleaveconfirmedbody',
            (object) ['group' => format_string($fresh->name)],
            $this->activity_url(),
            $this->activity->name()
        );

        return $member;
    }


    /**
     * A member leaves a team whose leader has asked it to wind up
     * (decision 63) - one click, no leader confirmation, because the
     * disband request IS the leader\'s standing consent.
     *
     * The write is confirm_leave()\'s write - REMOVED, same fields, same
     * lock discipline - behind a different gate: the member acts on
     * their OWN row, only while the request stands, only while the team
     * is forming. The deliberate no-minimum-floor rule is inherited:
     * a forming team must always be able to shrink.
     *
     * @param stdClass $group the team winding up
     * @param int $userid the member leaving
     * @return stdClass the removed member row
     * @throws \moodle_exception when refused
     */
    public function self_leave(stdClass $group, int $userid): stdClass {
        global $DB;

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($fresh->state !== state::FORMING) {
                throw new workflow_refusal('refusalwrongstate', 'mod_selfselectadvanced');
            }
            if (empty($fresh->timedisbandrequested)) {
                throw new workflow_refusal('refusaldisbandnone', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->leaderid === $userid) {
                // The leader does not leave their own wind-up; they
                // delete once alone, or cancel.
                throw new workflow_refusal('refusalnotleader', 'mod_selfselectadvanced');
            }
            $member = $DB->get_record('selfselectadvanced_member', [
                'groupid' => $fresh->id,
                'userid' => $userid,
                'status' => groups::STATUS_CONFIRMED,
            ], '*', MUST_EXIST);

            $now = time();
            $member->status = groups::STATUS_REMOVED;
            $member->leaverequested = null;
            $member->isleader = 0;
            $member->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $member);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        return $member;
    }

    /**
     * The acceptance cascade (4A.4): auto-decline every other pending
     * invitation of the user in this activity, inside the caller's
     * transaction. Returns the affected rows with group identity for
     * post-commit notifications. Only called via cascade_at_cap(),
     * which has already established the trigger condition.
     *
     * @param int $userid the user who reached their cap
     * @param int $excludegroupid the group just accepted
     * @return stdClass[] cascaded rows (groupid, leaderid, name, memberid)
     */
    private function cascade(int $userid, int $excludegroupid): array {
        global $DB;

        $sql = "SELECT m.id AS memberid, m.groupid, g.pluginuid, g.name, g.leaderid
                  FROM {selfselectadvanced_member} m
                  JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                 WHERE g.activityid = :activityid
                   AND m.userid = :userid
                   AND m.status = :status
                   AND m.groupid <> :excluded";
        $pending = $DB->get_records_sql($sql, [
            'activityid' => $this->activity->id(),
            'userid' => $userid,
            'status' => groups::STATUS_INVITED,
            'excluded' => $excludegroupid,
        ]);

        $now = time();
        foreach ($pending as $row) {
            $DB->update_record('selfselectadvanced_member', (object) [
                'id' => $row->memberid,
                'status' => groups::STATUS_DECLINED,
                'timeresponded' => $now,
                'timemodified' => $now,
            ]);
            \mod_selfselectadvanced\event\invitation_declined::create([
                'objectid' => $row->memberid,
                'context' => $this->activity->context(),
                'relateduserid' => $userid,
                'other' => [
                    'groupid' => (int) $row->groupid,
                    'pluginuid' => $row->pluginuid,
                    'reason' => 'membershipcap',
                ],
            ])->trigger();
        }

        return array_values($pending);
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

    /**
     * Deep link to the activity landing page.
     *
     * @return \moodle_url
     */
    private function activity_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $this->activity->cm()->id]);
    }
}
