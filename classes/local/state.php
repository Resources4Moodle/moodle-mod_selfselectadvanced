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
     * AUTHORITY (1.20.1, audit D2). This is the leader verb the two
     * previous authority waves both walked past: the old combined
     * :creategroup capability covered both creation and leader verbs.
     * The split now names this existing-group authority :lead, and
     * submitting, the verb that
     * moves a team out of FORMING, consumes a guide's declared capacity
     * and mails them, was gated by neither the page nor this service.
     * gatekeeper::can_submit() tests lifecycle state, the window, L1,
     * the proposal mandate, quota compliance and `leaderid === actorid`
     * - rule eligibility and record ownership, never authority - so a
     * student whose leader authority had been prohibited was correctly
     * refused Delete group and Invite members and could still submit.
     *
     * Asked HERE and asked FIRST: before the guide lock, before the
     * group lock and before the transaction (house rule - checks before
     * the lock), on the actor rather than on $USER, because a queued
     * task runs long after its actor's session is gone.
     *
     * @param stdClass $group group row
     * @param int|null $guideid chosen guide, null in manager-assigns mode
     * @param int $actorid the acting leader
     * @return stdClass the updated group row
     * @throws \required_capability_exception when the actor may not act as a leader here
     * @throws \moodle_exception when a gate refuses
     */
    public function submit(stdClass $group, ?int $guideid, int $actorid): stdClass {
        global $DB;

        authority::require_lead($this->activity, $actorid);

        $leaderselects = (int) $this->activity->settings()->guidemode === 0;
        // A guide accepted through an expression of interest is already
        // on the group row; that pre-assignment wins over the picker so
        // the group goes straight to the guide the leader chose.
        $preassigned = !empty($group->guideid) ? (int) $group->guideid : 0;
        if ($leaderselects && !$guideid && !$preassigned) {
            throw new workflow_refusal('refusalguiderequired', 'mod_selfselectadvanced');
        }

        // The guide's capacity gate below only holds under per-guide
        // serialisation: two groups submitting to the same guide from
        // under their own group locks would each read a free slot and
        // jointly exceed the cap. Same resource and same ordering as
        // the EOI paths: guide lock BEFORE group lock.
        $pretarget = $preassigned ?: (int) $guideid;
        // One acquire_all() takes the pair, never two bare acquires. When
        // the group lock times out the guide lock is already held, and the
        // bare pair leaked it: it blocked every other submit to that
        // guide until its own timeout expired, and it left
        // locks::held_count() non-zero for the rest of the process -
        // the question notifier::send() asks to decide whether it is
        // inside a lock. acquire_all() sorts into the same ascending
        // order the pair took by hand (eoiguide rank 7 before group
        // rank 8), so check_order() sees no change. do_approve() is the
        // worked example.
        $resources = ['group:' . (int) $group->id];
        if ($pretarget) {
            $resources[] = 'eoiguide:' . $pretarget;
        }
        $handles = locks::acquire_all($resources);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            $preassigned = !empty($fresh->guideid) ? (int) $fresh->guideid : 0;
            $target = $preassigned ?: (int) $guideid;
            if ($target !== $pretarget) {
                // An EOI decision changed the group's guide between the
                // pre-lock read and now: the lock held is the wrong
                // guide's, so the leader must review and resubmit.
                throw new workflow_refusal('refusalguidechanged', 'mod_selfselectadvanced');
            }
            if ($leaderselects && !$target) {
                throw new workflow_refusal('refusalguiderequired', 'mod_selfselectadvanced');
            }
            if ($refusal = $this->gatekeeper->can_submit($fresh, $actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
            if (
                ($leaderselects || $preassigned)
                && ($refusal = $this->gatekeeper->can_take_guide($target, (int) $fresh->id))
            ) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $fresh->state = self::PENDING_GUIDE;
            $fresh->guideid = $preassigned ?: ($leaderselects ? $guideid : null);
            $fresh->releasedbyguide = 0;
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
            //
            // UNCONDITIONAL since 1.20 wave 3D. It used to be gated on
            // an $outermost flag read from $DB->is_transaction_started()
            // before the locks, and that flag is not a fact about this
            // method: advanced_testcase wraps every test in a delegated
            // transaction on PostgreSQL and not on MariaDB, so the gate
            // was FALSE on m5pg and TRUE on m5my for the whole suite -
            // one branch per engine, neither engine exercising both.
            // Worse, the branch it selected on the nested path was the
            // wrong one: leaving our own frame undisposed on top of the
            // stack makes the CALLER's later rollback() miss the
            // identity check in rollback_delegated_transaction(), so it
            // rethrows without ever issuing the physical ROLLBACK and
            // the transaction stays open for the rest of the request.
            // Rolling our own frame back pops it, sets force_rollback
            // and lets the caller's rollback reach the bottom, which is
            // the cascade Moodle documents. Same idiom as
            // penalty\ledger::set_award().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            // Reverse acquisition order: the group (rank 8) first, then
            // the guide (rank 7), which is what release_all() does.
            locks::release_all($handles);
        }

        $url = $this->review_url((int) $fresh->id);
        $a = (object) [
            'group' => format_string($fresh->name),
            'pluginuid' => $fresh->pluginuid,
            'activity' => $this->activity->name(),
            // The body promises {$a->size} (seam audit H5).
            'size' => groups::count_confirmed((int) $fresh->id),
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
            // A5: notify everybody who can work the guide-assignment
            // queue that it has a new entry. Holders of the narrow
            // :assignguide capability are exactly the people this queue
            // is work for, so a manage-only enumeration left them
            // watching a page nobody told them to look at. Deduplicated
            // by the helper: somebody holding both is one recipient.
            // Outside the lock and the transaction, where it already
            // was (house rule 1).
            foreach (
                notifier::recipients($this->activity, [
                    'mod/selfselectadvanced:manage',
                    'mod/selfselectadvanced:assignguide',
                ]) as $manager
            ) {
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
     * AUTHORITY (1.20.1, wave 3C). ":assignguide" is named for this very
     * verb - "Assign or reassign a team's guide and decide expressions of
     * interest" - and until now this method asked no capability at all.
     * It carried the conflict-of-interest guard, which answers a
     * different question ("is this actor entangled with this team?") and
     * returns at once for a :manage holder, so a caller that reached the
     * service without the capability met nothing. The verb's OTHER half,
     * deciding an expression of interest, has asked
     * has_any_capability([:manage, :assignguide]) inside eoi::respond()
     * since 1.20.0; one capability answering its two verbs differently is
     * the shape wave 3C exists to remove.
     *
     * Asked here rather than only at manage.php because the service is
     * the authority and a page is only a caller: the gate has to hold for
     * a direct POST, for a future caller, and for anything queued before
     * an administrator revoked the capability. The pair is exactly what
     * manage.php already asks at its door, so no actor who could reach
     * this method before can be refused by it now - reassigning a guide
     * is a write that moves a student cohort's supervisor, releases one
     * guide's L5 slot and takes another's, and it is worth more than
     * ownership of a URL.
     *
     * Before the guide lock, the group lock and the transaction (house
     * rule: checks first, no lock held while refusing).
     *
     * @param stdClass $group group row
     * @param int $guideid the guide to assign
     * @param int $actorid the acting manager
     * @return stdClass the updated group row
     * @throws \required_capability_exception when the actor may not assign a guide here
     * @throws \moodle_exception when a gate refuses
     */
    public function assign_guide(stdClass $group, int $guideid, int $actorid): stdClass {
        global $DB;

        $context = $this->activity->context();
        if (
            !has_any_capability([
                'mod/selfselectadvanced:manage',
                'mod/selfselectadvanced:assignguide',
            ], $context, $actorid)
        ) {
            throw new \required_capability_exception(
                $context,
                'mod/selfselectadvanced:assignguide',
                'nopermissions',
                ''
            );
        }

        // Per-guide serialisation before the group lock (same resource
        // and ordering as the EOI paths), or two concurrent assigns
        // could jointly exceed the guide's cap.
        // Both through acquire_all(), because a timeout on the group
        // lock used to leave the guide lock held by a handle nobody
        // owned any more: every other assign or submit for that guide
        // waited out its full timeout, and held_count() - what
        // notifier::send() consults to know it is inside a lock - never
        // came back down. Order is unchanged, eoiguide (rank 7) then
        // group (rank 8), so check_order() is satisfied as before.
        $handles = locks::acquire_all([
            'eoiguide:' . $guideid,
            'group:' . (int) $group->id,
        ]);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if (!in_array($fresh->state, [self::PENDING_GUIDE, self::FIRM, self::FROZEN], true)) {
                throw new workflow_refusal('refusalreassignstate', 'mod_selfselectadvanced');
            }
            // Conflict of interest (1.16 D) on the RE-READ row, inside
            // the lock: a narrow-authority actor may not pick the guide
            // for a team they are involved in - including making
            // themselves its guide, which is the case this exists for.
            // require_uninvolved() returns at once for a :manage
            // holder, so every actor who could reach assign_guide()
            // before :assignguide existed is unaffected; this method's
            // only caller until now was manage.php behind :manage.
            //
            // This method accepts FROZEN groups, so a reassignment here
            // is a core-group guide-membership change: the sync is
            // requested below and runs outside every lock (decision 7).
            tickets::require_uninvolved($this->activity, $fresh, $actorid);
            $oldguide = (int) $fresh->guideid;
            // Re-assigning the guide the group already has is a no-op
            // cap-wise: their slot is held by this very group, so the
            // gate would falsely refuse a guide at capacity.
            if (
                $oldguide !== (int) $guideid
                && ($refusal = $this->gatekeeper->can_take_guide($guideid))
            ) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
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
            // the transaction (T-02 step 4.5). Unconditional since 1.20
            // wave 3D - see submit() for why the $outermost gate was
            // both engine-dependent and wrong when nested.
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            // Reverse acquisition order: group, then guide.
            locks::release_all($handles);
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
            format_string($fresh->name),
            // The newguide placeholder renders this same person, who is also
            // the recipient, so recipient indexing already covers them.
            // Stated anyway: it documents which payload field is about whom,
            // and it keeps the annotation correct if the recipient changes.
            [$guideid]
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
     * The comment's text FORMAT travels with the text (DATA-002): both
     * are written in this transaction and both ride the event payload,
     * so no caller is left writing a companion field after the commit.
     * review.php's editor passes FORMAT_HTML; guide.php's plain
     * queue-return textarea takes the default, which matches the
     * schema's own default for legacy rows (db/install.xml,
     * returncommentformat DEFAULT 2).
     *
     * @param stdClass $group group row
     * @param string $comment the mandatory return comment
     * @param int $actorid the acting guide
     * @param int $commentformat text format of the comment
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses or the comment is empty
     */
    public function return_group(
        stdClass $group,
        string $comment,
        int $actorid,
        int $commentformat = FORMAT_PLAIN
    ): stdClass {
        global $DB;

        if (trim($comment) === '') {
            throw new \moodle_exception('errcommentrequired', 'mod_selfselectadvanced');
        }

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($fresh->state === self::FIRM) {
                // Decision 62 (2026-08-06): the coordinator's half of
                // ruling 51-A2 ("a guide cannot un-approve; it needs a
                // coordinator"). The maintainer's relief flow: the guide
                // asks the coordinators to be relieved; granting it
                // returns the team to the state before a guide was
                // chosen. The actor is a queue worker - coordinator or
                // manager - and the standing conflict rule applies: a
                // coordinator never acts on a team they are involved
                // with. The team's own guide is still refused here,
                // which keeps 51-A2 itself intact.
                tickets::require_queue_authority($this->activity, $actorid);
                tickets::require_uninvolved($this->activity, $fresh, $actorid);
            } else if ($refusal = $this->gatekeeper->can_return($fresh, $actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
            $oldguideid = (int) ($fresh->guideid ?? 0);

            $now = time();
            $fresh->state = self::FORMING;
            $fresh->guideid = null;
            // Approval is undone with the guide who gave it: a forming
            // team with a timeapproved would restart the penalty clock
            // from a decision that no longer stands.
            $fresh->timeapproved = null;
            $fresh->releasedbyguide = 0;
            // A return dissolves any pending handover with it: the
            // nomination belonged to the guide who just released the
            // team, and must not survive into a future submission.
            $fresh->guidesuccessorid = null;
            $fresh->timeguidenominated = null;
            $fresh->returncomment = trim($comment);
            $fresh->returncommentformat = $commentformat;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);

            \mod_selfselectadvanced\event\group_returned::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'relateduserid' => (int) $fresh->leaderid,
                'other' => [
                    'pluginuid' => $fresh->pluginuid,
                    'comment' => trim($comment),
                    'commentformat' => $commentformat,
                ],
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
            // transaction (T-02 step 4.5). Unconditional since 1.20
            // wave 3D - see submit().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        // Decision 89: a return is a lifecycle event of the WHOLE GROUP, so
        // every confirmed member hears it - not the leader alone. Approval
        // (above) and freeze already fan out; a return is the one that changes
        // what everybody has to do next, and telling only the leader left the
        // rest of the group working on something that had been sent back.
        // The template label said "(to the members)" throughout, so this makes
        // the shipped label true rather than changing what it promises.
        //
        // Deliberately neutral and group-focused: the guide's comment is the
        // leader's to relay, so the body names the return and the coordination,
        // never the criticism. Detailed feedback stays where existing
        // permissions already show it.
        foreach (groups::get_roster((int) $fresh->id) as $member) {
            notifier::send(
                $this->activity,
                'groupreturned',
                (int) $member->userid,
                'msgreturnedsubject',
                (int) $member->userid === (int) $fresh->leaderid
                    ? 'msgreturnedbody'
                    : 'msgreturnedbodymember',
                (object) ['group' => format_string($fresh->name), 'comment' => trim($comment)],
                $this->group_url((int) $fresh->id),
                format_string($fresh->name)
            );
        }
        if ($oldguideid && $oldguideid !== $actorid) {
            // The relieved guide learns their relief was granted - the
            // whole point of the flow that reaches this arm.
            notifier::send(
                $this->activity,
                'groupreturned',
                $oldguideid,
                'msgguiderelievedsubject',
                'msgguiderelievedbody',
                (object) ['group' => format_string($fresh->name), 'comment' => trim($comment)],
                $this->group_url((int) $fresh->id),
                format_string($fresh->name)
            );
        }

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
                    throw new workflow_refusal(
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
                        //
                        // DEFENSIVE, not currently reachable, and worth
                        // saying so rather than letting a future reader
                        // assume it fires. The sweep writes GROUP-scoped
                        // relief, and override\guard::blockers() parks a
                        // group-scoped row for exactly one reason - the
                        // roster exceeding its maximum - which decision
                        // 80 now refuses earlier, in the plan. The branch
                        // stays because the invariant it defends is the
                        // real one: never approve on relief the resolver
                        // cannot see. It becomes reachable again the day
                        // blockers() grows a second group-scoped arm.
                        throw new workflow_refusal('refusalreliefpending', 'mod_selfselectadvanced');
                    }
                    // The resolver cached every override row of the
                    // activity before this write; nothing downstream may
                    // read through that stale cache.
                    $this->gatekeeper->resolver()->invalidate();
                }
            } else if ($refusal = $this->gatekeeper->can_approve($fresh, $actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $fresh->state = self::FIRM;
            $fresh->releasedbyguide = 0;
            // Decision 58 (2026-08-05): this stamp belongs to the
            // approval event. Later guide-released roster changes must
            // not re-stamp or invalidate it, because the lateness
            // penalty is computed from due date -> timeapproved; doing
            // so would punish the whole team for a change its guide
            // sanctioned.
            $fresh->timeapproved = $now;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);
            freeze::request_sync($this->activity, $fresh);

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
            // force-rolled the run back.
            //
            // The "only the outermost owner may roll back" rider that
            // used to stand here was wrong twice over, and is gone
            // (1.20 wave 3D). It was decided by
            // $DB->is_transaction_started(), which under PHPUnit
            // answers for the harness rather than for this method - so
            // the gate was false on m5pg and true on m5my and neither
            // engine tried the other branch. And poisoning the caller's
            // transaction is not a hazard to be avoided here, it is
            // Moodle's documented cascade: skipping the rollback leaves
            // our undisposed frame on top of the stack, which makes the
            // caller's own rollback() fail its identity check and
            // rethrow WITHOUT issuing the physical ROLLBACK. This
            // method's one nested-capable caller today is the
            // guide_autoapprove task, which opens no transaction of its
            // own; the unconditional form is what keeps a future one
            // safe. See submit() and penalty\ledger::set_award().
            if (isset($transaction) && !$transaction->is_disposed()) {
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
        // of once per approval (T-04 3c). The mirror sync lives in the
        // same outside-lock space: approval is now the first lifecycle
        // state Moodle activities can consume through a real group.
        try {
            $sync = freeze::sync_core_group($this->activity, (int) $fresh->id, $actorid);
        } catch (\Throwable $e) {
            // Approval is the authoritative roster/state write; the
            // mirror is best-effort inline and authoritative through
            // the queued adhoc task already committed with the group.
            debugging(
                'Inline core-group sync failed after approving plugin group ' . (int) $fresh->id . ': '
                    . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            $sync = (object) [
                'status' => 'failed',
                'coregroupid' => (int) ($fresh->coregroupid ?? 0),
                'added' => [],
                'removed' => [],
                'refused' => [],
                'extra' => [],
                'error' => $e->getMessage(),
            ];
        }
        $fresh = groups::get($this->activity, (int) $fresh->id);
        $fresh->sync = $sync;
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
        // FORMING was added 2026-08-06 (decision 62): the coordinator's
        // half of ruling 51-A2. A guide cannot un-approve; a
        // coordinator, resolving the guide's relief ticket, returns the
        // team to the state before a guide was chosen.
        self::FIRM => [self::FROZEN, self::FORMING],
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
}
