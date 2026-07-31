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
 * The explicit group lifecycle state machine (spec section 5): the
 * single authority on state names, legal edges, and the guide-review
 * transitions T2 (submit), T3 (return) and T4 (approve). T5/T6
 * (freeze/unfreeze) live in the freeze service, which asserts its
 * edges here. Every gatekeeper method states its state precondition
 * (review item S2).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state {
    /**
     * Constructor for transition execution.
     *
     * @param activity $activity the activity
     * @param gatekeeper $gatekeeper the gatekeeper guarding every edge
     */
    public function __construct(
        /** @var activity The activity. */
        private readonly activity $activity,
        /** @var gatekeeper The gatekeeper guarding every edge. */
        private readonly gatekeeper $gatekeeper,
    ) {
    }

    /**
     * T2: the leader submits the group for guide review (spec 6.5).
     *
     * Leader-selects mode requires a guide with a free L5 slot;
     * manager-assigns mode (decision A5) submits without a guide and
     * the group enters the manager's assignment queue.
     *
     * @param stdClass $group group row
     * @param int|null $guideid chosen guide, null in manager-assigns mode
     * @param int $actorid the acting leader
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses
     */
    public function submit(stdClass $group, ?int $guideid, int $actorid): stdClass {
        global $DB;

        $leaderselects = (int) $this->activity->settings()->guidemode === 0;
        // A guide accepted through an expression of interest is already
        // on the group row; that pre-assignment wins over the picker so
        // the group goes straight to the guide the leader chose.
        $preassigned = !empty($group->guideid) ? (int) $group->guideid : 0;
        if ($leaderselects && !$guideid && !$preassigned) {
            throw new \moodle_exception('refusalguiderequired', 'mod_selfselectadvanced');
        }

        // The guide's capacity gate below only holds under per-guide
        // serialisation: two groups submitting to the same guide from
        // under their own group locks would each read a free slot and
        // jointly exceed the cap. Same resource and same ordering as
        // the EOI paths: guide lock BEFORE group lock.
        $pretarget = $preassigned ?: (int) $guideid;
        $outermost = !$DB->is_transaction_started();
        $guidelock = $pretarget ? locks::acquire('eoiguide:' . $pretarget) : null;
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            $preassigned = !empty($fresh->guideid) ? (int) $fresh->guideid : 0;
            $target = $preassigned ?: (int) $guideid;
            if ($target !== $pretarget) {
                // An EOI decision changed the group's guide between the
                // pre-lock read and now: the lock held is the wrong
                // guide's, so the leader must review and resubmit.
                throw new \moodle_exception('refusalguidechanged', 'mod_selfselectadvanced');
            }
            if ($leaderselects && !$target) {
                throw new \moodle_exception('refusalguiderequired', 'mod_selfselectadvanced');
            }
            if ($refusal = $this->gatekeeper->can_submit($fresh, $actorid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
            if (
                ($leaderselects || $preassigned)
                && ($refusal = $this->gatekeeper->can_take_guide($target, (int) $fresh->id))
            ) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $fresh->state = self::PENDING_GUIDE;
            $fresh->guideid = $preassigned ?: ($leaderselects ? $guideid : null);
            $fresh->timesubmitted = $now;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);

            \mod_selfselectadvanced\event\group_submitted::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'relateduserid' => $fresh->guideid,
                'other' => ['pluginuid' => $fresh->pluginuid],
            ])->trigger();

            // Reset via the preferences API, not raw deletes - a
            // direct table delete leaves each guide's preference
            // cache holding the stale marker (audit round 6).
            $marker = 'mod_selfselectadvanced_gremind_' . $fresh->id;
            foreach ($DB->get_fieldset_select('user_preferences', 'userid', 'name = ?', [$marker]) as $markeduser) {
                unset_user_preference($marker, (int) $markeduser);
            }
            // The mirror carries the guide (decision 7); reachable in
            // FIRM and FROZEN, where a mirror can exist.
            freeze::request_sync($this->activity, $fresh);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Every refusal above throws from INSIDE the transaction,
            // so without this a refused submit left a dangling
            // delegated transaction (T-02 step 4.5).
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            if ($guidelock) {
                $guidelock->release();
            }
        }

        $url = $this->review_url((int) $fresh->id);
        $a = (object) [
            'group' => format_string($fresh->name),
            'pluginuid' => $fresh->pluginuid,
            'activity' => $this->activity->name(),
        ];
        if ($fresh->guideid) {
            notifier::send(
                $this->activity,
                'guidequeue',
                (int) $fresh->guideid,
                'msgsubmittedsubject',
                'msgsubmittedbody',
                $a,
                $url,
                format_string($fresh->name)
            );
        } else {
            // A5: notify the managers that the queue has a new entry.
            foreach (get_users_by_capability($this->activity->context(), 'mod/selfselectadvanced:manage', 'u.id') as $manager) {
                notifier::send(
                    $this->activity,
                    'guidequeue',
                    (int) $manager->id,
                    'msgqueuedsubject',
                    'msgqueuedbody',
                    $a,
                    $url,
                    format_string($fresh->name)
                );
            }
        }

        return $fresh;
    }

    /**
     * A5: a manager assigns (or reassigns) the guide of a submitted group.
     *
     * @param stdClass $group group row
     * @param int $guideid the guide to assign
     * @param int $actorid the acting manager
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses
     */
    public function assign_guide(stdClass $group, int $guideid, int $actorid): stdClass {
        global $DB;

        // Per-guide serialisation before the group lock (same resource
        // and ordering as the EOI paths), or two concurrent assigns
        // could jointly exceed the guide's cap.
        $outermost = !$DB->is_transaction_started();
        $guidelock = locks::acquire('eoiguide:' . $guideid);
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if (!in_array($fresh->state, [self::PENDING_GUIDE, self::FIRM, self::FROZEN], true)) {
                throw new \moodle_exception('refusalreassignstate', 'mod_selfselectadvanced');
            }
            $oldguide = (int) $fresh->guideid;
            // Re-assigning the guide the group already has is a no-op
            // cap-wise: their slot is held by this very group, so the
            // gate would falsely refuse a guide at capacity.
            if (
                $oldguide !== (int) $guideid
                && ($refusal = $this->gatekeeper->can_take_guide($guideid))
            ) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $fresh->guideid = $guideid;
            // A manager reassignment supersedes any pending handover.
            $fresh->guidesuccessorid = null;
            $fresh->timeguidenominated = null;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = time();
            $DB->update_record('selfselectadvanced_group', $fresh);

            if ($oldguide && $oldguide !== (int) $guideid) {
                \mod_selfselectadvanced\event\guide_reassigned::create([
                    'objectid' => $fresh->id,
                    'context' => $this->activity->context(),
                    'relateduserid' => $guideid,
                    'other' => [
                        'pluginuid' => $fresh->pluginuid,
                        'fromguideid' => $oldguide,
                        'via' => 'reassign',
                    ],
                ])->trigger();
            }

            // Reset via the preferences API, not raw deletes - a
            // direct table delete leaves each guide's preference
            // cache holding the stale marker (audit round 6).
            $marker = 'mod_selfselectadvanced_gremind_' . $fresh->id;
            foreach ($DB->get_fieldset_select('user_preferences', 'userid', 'name = ?', [$marker]) as $markeduser) {
                unset_user_preference($marker, (int) $markeduser);
            }
            // The mirror carries the guide (decision 7); reachable in
            // FIRM and FROZEN, where a mirror can exist.
            freeze::request_sync($this->activity, $fresh);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The state and capacity refusals above throw from INSIDE
            // the transaction (T-02 step 4.5).
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $guidelock->release();
        }

        // Outside every lock and transaction (requirement 2): one sync
        // swaps the old guide out of the course group and the new one in.
        freeze::sync_core_group($this->activity, (int) $fresh->id, $actorid);

        $a = (object) [
            'group' => format_string($fresh->name),
            'pluginuid' => $fresh->pluginuid,
            'newguide' => fullname(\core_user::get_user($guideid)),
            'activity' => $this->activity->name(),
        ];
        // A queued group lands in the guide's review queue; a firm or
        // frozen one is simply theirs to guide now.
        $newguidebody = $fresh->state === self::PENDING_GUIDE ? 'msgsubmitted' : 'msgnowguiding';
        notifier::send(
            $this->activity,
            'guidequeue',
            $guideid,
            $newguidebody . 'subject',
            $newguidebody . 'body',
            $a,
            $this->review_url((int) $fresh->id),
            format_string($fresh->name)
        );
        if ($oldguide && $oldguide !== (int) $guideid) {
            $groupurl = new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $this->activity->cm()->id,
                'g' => (int) $fresh->id,
            ]);
            foreach ([$oldguide, (int) $fresh->leaderid] as $told) {
                notifier::send(
                    $this->activity,
                    'guidechanged',
                    $told,
                    'msgguidechangedsubject',
                    'msgguidechangedbody',
                    $a,
                    $groupurl,
                    format_string($fresh->name)
                );
            }
        }

        return $fresh;
    }

    /**
     * T3: the assigned guide returns the group with a mandatory
     * comment; the guide's L5 slot is released immediately (guideid
     * cleared, decision A11).
     *
     * @param stdClass $group group row
     * @param string $comment the mandatory return comment
     * @param int $actorid the acting guide
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses or the comment is empty
     */
    public function return_group(stdClass $group, string $comment, int $actorid): stdClass {
        global $DB;

        if (trim($comment) === '') {
            throw new \moodle_exception('errcommentrequired', 'mod_selfselectadvanced');
        }

        $outermost = !$DB->is_transaction_started();
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_return($fresh, $actorid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $fresh->state = self::FORMING;
            $fresh->guideid = null;
            // A return dissolves any pending handover with it: the
            // nomination belonged to the guide who just released the
            // team, and must not survive into a future submission.
            $fresh->guidesuccessorid = null;
            $fresh->timeguidenominated = null;
            $fresh->returncomment = trim($comment);
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);

            \mod_selfselectadvanced\event\group_returned::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'relateduserid' => (int) $fresh->leaderid,
                'other' => ['pluginuid' => $fresh->pluginuid, 'comment' => trim($comment)],
            ])->trigger();

            // Reset via the preferences API, not raw deletes - a
            // direct table delete leaves each guide's preference
            // cache holding the stale marker (audit round 6).
            $marker = 'mod_selfselectadvanced_gremind_' . $fresh->id;
            foreach ($DB->get_fieldset_select('user_preferences', 'userid', 'name = ?', [$marker]) as $markeduser) {
                unset_user_preference($marker, (int) $markeduser);
            }
            // The mirror carries the guide (decision 7); reachable in
            // FIRM and FROZEN, where a mirror can exist.
            freeze::request_sync($this->activity, $fresh);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The can_return() refusal throws from INSIDE the
            // transaction (T-02 step 4.5).
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        notifier::send(
            $this->activity,
            'groupreturned',
            (int) $fresh->leaderid,
            'msgreturnedsubject',
            'msgreturnedbody',
            (object) ['group' => format_string($fresh->name), 'comment' => trim($comment)],
            $this->group_url((int) $fresh->id),
            format_string($fresh->name)
        );

        return $fresh;
    }

    /**
     * T4: the assigned guide approves the group - irreversible (spec
     * 6.5). Sets timeapproved, which drives the penalty ledger.
     *
     * @param stdClass $group group row
     * @param int $actorid the acting guide
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses
     */
    public function approve(stdClass $group, int $actorid): stdClass {
        return $this->do_approve($group, $actorid, false);
    }

    /**
     * T4 forced by the guide decision window (the guide_autoapprove sweep).
     *
     * Every gate can_approve enforces is enforced here except the
     * assigned-guide identity check - that is what the lapsed window
     * stands in for (gatekeeper::autoapprove_plan). Any minimum-size or
     * quota shortfall is recorded as a group-scope override in THIS
     * transaction, so relief and approval commit or roll back together:
     * a relief row can never outlive an approval that failed (T-04 3d).
     *
     * The member grade push is deliberately NOT done here; the sweep
     * pushes once per activity after its batch, because push_grades()
     * republishes the whole activity on every call (T-04 3c).
     *
     * @param stdClass $group group row
     * @param int $actorid the acting user, the site admin in cron
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses
     */
    public function approve_auto(stdClass $group, int $actorid): stdClass {
        return $this->do_approve($group, $actorid, true);
    }

    /**
     * The one approval body behind approve() and approve_auto().
     *
     * @param stdClass $group group row
     * @param int $actorid the acting user
     * @param bool $auto true for the guide-window sweep
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses
     */
    private function do_approve(stdClass $group, int $actorid, bool $auto): stdClass {
        global $DB;

        $outermost = !$DB->is_transaction_started();
        // Ascending lock order (T-02 rank table): the override row
        // (rank 5) BEFORE the group (rank 8), because a forced approval
        // may have to write the group's relief row inside the group
        // lock and locks are not re-entrant. Manual approval writes no
        // override, so it takes the group lock alone.
        //
        // acquire_all() is what takes them, not two bare acquires: it
        // releases what it already holds when a LATER acquire times
        // out. Two bare acquires leak the override lock on that path,
        // and a leaked handle is worse than a held resource - it leaves
        // locks::held_count() permanently non-zero for the rest of the
        // process, which is the question notifier::send() asks to
        // decide whether it is inside a lock (T-02).
        $handles = locks::acquire_all(
            $auto
                ? ['override:group:' . (int) $group->id, 'group:' . (int) $group->id]
                : ['group:' . (int) $group->id]
        );
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($auto) {
                // Judged HERE, on the roster as it is at commit time -
                // not on the sweep's batch snapshot (T-04 3b).
                $plan = $this->gatekeeper->autoapprove_plan($fresh);
                if ($plan->refusal !== null) {
                    throw new \moodle_exception(
                        $plan->refusal->stringkey,
                        'mod_selfselectadvanced',
                        '',
                        $plan->refusal->a
                    );
                }
                if ($plan->relief !== []) {
                    $relief = override\store::save(
                        $this->activity,
                        'group',
                        (int) $fresh->id,
                        $plan->relief,
                        $actorid,
                        true
                    );
                    if ($relief->status !== 'active') {
                        // A pre-existing guarded reduction keeps the
                        // merged row pending; approving on relief the
                        // resolver cannot see would be unexplained.
                        throw new \moodle_exception('refusalreliefpending', 'mod_selfselectadvanced');
                    }
                    // The resolver cached every override row of the
                    // activity before this write; nothing downstream may
                    // read through that stale cache.
                    $this->gatekeeper->resolver()->invalidate();
                }
            } else if ($refusal = $this->gatekeeper->can_approve($fresh, $actorid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $fresh->state = self::FIRM;
            $fresh->timeapproved = $now;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);

            \mod_selfselectadvanced\event\group_approved::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'relateduserid' => (int) $fresh->leaderid,
                'other' => ['pluginuid' => $fresh->pluginuid, 'auto' => $auto ? 1 : 0],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Refusals are thrown from INSIDE the transaction by design.
            // Without this the transaction stayed open for the rest of
            // the request: in a cron sweep every LATER approval popped
            // its own frame without ever reaching an empty stack, so
            // nothing after the first refusal committed and dispose()
            // force-rolled the run back. Only the outermost owner may
            // roll back - a nested rollback poisons the caller's
            // transaction (tickets.php, joinrequests.php).
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            // Reverse acquisition order: group (rank 8) first, then the
            // override row (rank 5).
            locks::release_all($handles);
        }

        // Spec 11: the approval writes the group's ledger row (explicit
        // zero for on-time groups). upsert_for_group() takes the group
        // lock itself (T-02 R8), so it must stay outside the finally
        // above - locks are not re-entrant. The activity-wide grade push
        // is the caller's business on the sweep path: push_grades()
        // republishes every confirmed member of every firm or frozen
        // group, so the sweep does it once per activity per run instead
        // of once per approval (T-04 3c).
        penalty\ledger::upsert_for_group($this->activity, $fresh, $this->gatekeeper->resolver());
        if (!$auto) {
            penalty\ledger::push_grades($this->activity);
        }

        foreach (groups::get_roster((int) $fresh->id) as $member) {
            notifier::send(
                $this->activity,
                'groupapproved',
                (int) $member->userid,
                'msgapprovedsubject',
                'msgapprovedbody',
                (object) ['group' => format_string($fresh->name)],
                $this->group_url((int) $fresh->id),
                format_string($fresh->name)
            );
        }

        return $fresh;
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
     * Deep link to the guide review page.
     *
     * @param int $groupid the group
     * @return \moodle_url
     */
    private function review_url(int $groupid): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/review.php', [
            'id' => $this->activity->cm()->id,
            'g' => $groupid,
        ]);
    }
    /** @var string Leader edits, invites, transfers; members join and leave. */
    public const FORMING = 'forming';

    /** @var string Membership locked to students; guide approves or returns. */
    public const PENDING_GUIDE = 'pending_guide';

    /** @var string Approved; only manager staged moves alter membership. */
    public const FIRM = 'firm';

    /** @var string Mirrored into a core course group and locked. */
    public const FROZEN = 'frozen';

    /** @var string[][] Legal transitions: from-state to list of to-states. */
    private const EDGES = [
        self::FORMING => [self::PENDING_GUIDE],
        self::PENDING_GUIDE => [self::FORMING, self::FIRM],
        self::FIRM => [self::FROZEN],
        self::FROZEN => [self::FIRM],
    ];

    /**
     * All state names.
     *
     * @return string[]
     */
    public static function all(): array {
        return [self::FORMING, self::PENDING_GUIDE, self::FIRM, self::FROZEN];
    }

    /**
     * Whether a transition between two states is legal.
     *
     * @param string $from current state
     * @param string $to proposed state
     * @return bool
     */
    public static function is_legal(string $from, string $to): bool {
        return in_array($to, self::EDGES[$from] ?? [], true);
    }

    /**
     * Assert that a group row is in one of the expected states.
     *
     * Gatekeeper state preconditions (review item S2) funnel through
     * here so a stale POST can never act on a group whose state moved on.
     *
     * @param \stdClass $group group row
     * @param string[] $expected acceptable states
     * @throws \moodle_exception when the state does not match
     */
    public static function require_state(\stdClass $group, array $expected): void {
        if (!in_array($group->state, $expected, true)) {
            throw new \moodle_exception('errwrongstate', 'mod_selfselectadvanced', '', $group->state);
        }
    }
}
