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
use stdClass;

/**
 * A student asking to join another team (strategy 1.19 B).
 *
 * The maintainer's rule, kept whole: self-service until the leader
 * accepts; the target team's LEADER approves while the team is still
 * forming; once the team is settled its GUIDE releases it first and the
 * leader still approves; the guide stays with the re-composed team; and
 * a coordinator may approve any of them at any point.
 *
 * A request is a row in {selfselectadvanced_move} carrying the new
 * status 'requested', so accepting one runs the move engine already in
 * place - the composition rules, the seat plan, the locks and the audit
 * trail are all the ones a coordinator's move goes through. Nothing
 * about committing a move is duplicated here.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class joinrequests {
    /** @var string Waiting for the target team's leader (or a coordinator). */
    public const STATUS_REQUESTED = 'requested';

    /** @var string Turned down, with the reason. */
    public const STATUS_DECLINED = 'declined';

    /**
     * Sentinel source: the student deliberately keeps every team they
     * are in and asks for the target as an ADDITIONAL membership.
     *
     * Not a group id and never stored: it resolves to a NULL
     * sourcegroupid, which on a 'requested' row means exactly "add a
     * membership, leave nothing". Zero is not used, because an unset
     * or placeholder select posts zero through PARAM_INT and that must
     * stay distinguishable from a deliberate choice.
     */
    public const SOURCE_ADDITIONAL = -1;

    /**
     * Ask to join a team.
     *
     * @param activity $activity the activity
     * @param int $targetgroupid the team the student wants to join
     * @param string $reason why, from the student
     * @param int $userid the student asking
     * @param int|null $sourcegroupid the team to leave; null to infer when unambiguous,
     *        self::SOURCE_ADDITIONAL to keep every current team
     * @return stdClass the request row
     * @throws \moodle_exception when a gate refuses, when the student is in more than one
     *         team and named none, when the named team is not theirs or is frozen, or when
     *         an additional membership has no room under the effective cap
     */
    public static function request(
        activity $activity,
        int $targetgroupid,
        string $reason,
        int $userid,
        ?int $sourcegroupid = null
    ): stdClass {
        global $DB;

        require_capability('mod/selfselectadvanced:respond', $activity->context(), $userid);
        if (trim($reason) === '') {
            throw new \moodle_exception('refusaljoinreason', 'mod_selfselectadvanced');
        }

        $target = groups::get($activity, $targetgroupid);

        // Leadership first: a leader is also a confirmed member of their
        // own team, so the general "already in it" answer would fire
        // and tell them less than the truth.
        if ((int) $target->leaderid === $userid) {
            throw new \moodle_exception('refusaljoinownteam', 'mod_selfselectadvanced');
        }
        // A frozen team cannot take anybody until it is released, and a
        // student cannot leave one either. Saying so here is kinder
        // than accepting a request that acceptance would refuse.
        if ($target->state === state::FROZEN) {
            throw new \moodle_exception('refusaljointargetfrozen', 'mod_selfselectadvanced');
        }

        // Read once outside the lock so an obviously impossible ask is
        // answered without opening a transaction, and once more INSIDE
        // it below - the authoritative read (house rule A7). Both reads
        // are one indexed query bounded by the membership cap.
        $source = self::resolve_source(
            $activity,
            groups::get_groups_of_user($activity, $userid),
            $targetgroupid,
            $userid,
            $sourcegroupid
        );

        // Serialised on the asker, so two clicks - or two tabs - cannot
        // both pass the duplicate check and insert (audit HIGH-TX-002).
        // The lock is on the person, because that is what the rule is
        // about: one live request each.
        $lock = locks::acquire('joinrequest:user:' . $userid);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            // One live request at a time, as with every other queue here.
            $live = $DB->get_record_select(
                'selfselectadvanced_move',
                'activityid = :activityid AND userid = :userid AND status = :requested',
                [
                    'activityid' => $activity->id(),
                    'userid' => $userid,
                    'requested' => self::STATUS_REQUESTED,
                ]
            );
            if ($live) {
                throw new \moodle_exception('refusaljoinduplicate', 'mod_selfselectadvanced', '', (int) $live->id);
            }

            // The choice is re-judged on the roster as it is NOW: the
            // form was rendered minutes ago and the student may have
            // left, or joined, a team since. This read is the one that
            // decides what gets stored.
            $source = self::resolve_source(
                $activity,
                groups::get_groups_of_user($activity, $userid),
                $targetgroupid,
                $userid,
                $sourcegroupid
            );

            $now = time();
            $request = (object) [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'sourcegroupid' => $source !== null ? (int) $source->id : null,
                'targetgroupid' => $targetgroupid,
                'makeleader' => 0,
                'replaceleader' => 0,
                'successorid' => null,
                'status' => self::STATUS_REQUESTED,
                'statusinfo' => null,
                'reason' => $reason,
                'responsenote' => null,
                'usermodified' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $request->id = $DB->insert_record('selfselectadvanced_move', $request);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
        } finally {
            $lock->release();
        }

        \mod_selfselectadvanced\event\join_requested::create([
            'objectid' => $request->id,
            'context' => $activity->context(),
            'relateduserid' => $userid,
            'other' => ['targetgroupid' => $targetgroupid],
        ])->trigger();

        // The leader hears about it; sends never happen under a lock.
        self::notify(
            $activity,
            (int) $target->leaderid,
            'msgjoinrequestedsubject',
            'msgjoinrequestedbody',
            $target,
            $userid,
            $reason
        );

        return $request;
    }

    /**
     * Which team the student leaves, decided rather than guessed.
     *
     * Multi-membership is a supported configuration (L4,
     * mod_form.php maxmembership), so "the team a student is in" is a
     * SET. This turns the student's stated intent into exactly one of:
     * a group row they are confirmed in, or null for a deliberate
     * additional membership. It never picks for them.
     *
     * @param activity $activity the activity
     * @param stdClass[] $currents confirmed group rows, keyed by group id (timecreated ASC)
     * @param int $targetgroupid the team being asked for
     * @param int $userid the asker
     * @param int|null $sourcegroupid the stated choice: a group id, self::SOURCE_ADDITIONAL, or null
     * @return stdClass|null the group row to leave, or null for an additional membership
     * @throws \moodle_exception when the choice is missing, not theirs, frozen or over cap
     */
    private static function resolve_source(
        activity $activity,
        array $currents,
        int $targetgroupid,
        int $userid,
        ?int $sourcegroupid
    ): ?stdClass {
        // Judged across EVERY confirmed membership, not one arbitrary
        // row: a student confirmed in the target has nothing to ask
        // for, whichever of their teams the database happens to list
        // first.
        if (isset($currents[$targetgroupid])) {
            throw new \moodle_exception('refusaljoinalready', 'mod_selfselectadvanced');
        }

        if ($sourcegroupid === self::SOURCE_ADDITIONAL) {
            self::require_headroom($activity, $currents, $userid);

            return null;
        }

        if ($sourcegroupid === null) {
            if (count($currents) > 1) {
                // The same rigour the manager flow already gets from
                // moves::stage() (refusalmovesourcerequired): name the
                // teams and refuse to choose for them.
                throw new \moodle_exception(
                    'refusaljoinsourcerequired',
                    'mod_selfselectadvanced',
                    '',
                    implode(', ', array_map(
                        static fn(stdClass $group): string => format_string($group->name),
                        $currents
                    ))
                );
            }
            if (!$currents) {
                self::require_headroom($activity, $currents, $userid);

                return null;
            }
            $source = reset($currents);
        } else {
            // Server-side ownership of the posted id (IDOR): a source
            // is only ever a team this student is confirmed in.
            if (!isset($currents[$sourcegroupid])) {
                throw new \moodle_exception('refusaljoinsourcenotyours', 'mod_selfselectadvanced');
            }
            $source = $currents[$sourcegroupid];
        }

        // A frozen team cannot let anybody go until it is released -
        // judged on the SELECTED team, not on whichever one came back
        // first. Saying so here is kinder than accepting a request
        // that acceptance would refuse.
        if ($source->state === state::FROZEN) {
            throw new \moodle_exception('refusaljoinsourcefrozen', 'mod_selfselectadvanced');
        }

        return $source;
    }

    /**
     * Refuse an additional membership the student's cap has no room for.
     *
     * @param activity $activity the activity
     * @param stdClass[] $currents confirmed group rows
     * @param int $userid the asker
     * @throws \moodle_exception when the cap is already reached
     */
    private static function require_headroom(activity $activity, array $currents, int $userid): void {
        $cap = (new resolver($activity))->effective_maxmembership($userid)->value;
        if (count($currents) >= $cap) {
            throw new \moodle_exception('refusaljoinnoheadroom', 'mod_selfselectadvanced', '', (object) [
                'current' => count($currents),
                'max' => $cap,
            ]);
        }
    }

    /**
     * Accept or decline a request.
     *
     * Accepting runs the move engine: the same validation, the same
     * locks, the same commit a coordinator's move goes through. A
     * request that would break the target team is refused HERE, with
     * the rule that refused it, and the request stays open.
     *
     * @param activity $activity the activity
     * @param int $requestid the request
     * @param bool $accept true to admit them
     * @param string $note what the decider said
     * @param int $actorid the leader, coordinator or manager deciding
     * @param string[] $bypass composition rule codes the decider is
     *        overriding (decision 6); refused unless the ACTOR holds
     *        :overriderules, so a crafted student POST cannot use it
     * @return stdClass the decided request
     * @throws \moodle_exception when refused
     */
    public static function respond(
        activity $activity,
        int $requestid,
        bool $accept,
        string $note,
        int $actorid,
        array $bypass = []
    ): stdClass {
        global $DB;

        // Serialised on the request, and the row is re-read INSIDE the
        // lock (house rule A7): the copy the page loaded can be minutes
        // old, and two leaders answering at once must not both act on a
        // request that was open when each of them looked (audit
        // HIGH-TX-001).
        //
        // The lock is taken on the request, never on a group. The move
        // engine takes the activity lock itself, so the order is always
        // joinrequest -> activity and cannot deadlock against it.
        $deferred = [];
        $deferredsync = [];
        $deferredoverrides = [];
        $lock = locks::acquire('joinrequest:' . $requestid);
        try {
            $request = self::get($activity, $requestid);
            if ($request->status !== self::STATUS_REQUESTED) {
                throw new \moodle_exception('refusaljoinnotopen', 'mod_selfselectadvanced');
            }
            $target = groups::get($activity, (int) $request->targetgroupid);
            self::require_decider($activity, $target, $actorid);

            $fresh = $accept
                ? self::do_accept(
                    $activity,
                    $request,
                    $target,
                    $note,
                    $actorid,
                    $deferred,
                    $deferredsync,
                    $deferredoverrides,
                    $bypass
                )
                : self::do_decline($activity, $request, $note, $actorid);
        } finally {
            $lock->release();
        }

        // A new event never travels under a lock or an open transaction
        // (requirement 2): the move engine collected these while our
        // joinrequest:{id} lock was still held, and they fire here.
        foreach ($deferredoverrides as $overridden) {
            \mod_selfselectadvanced\event\move_rules_overridden::create(
                $overridden + ['context' => $activity->context()]
            )->trigger();
        }

        // Mail never travels under a lock - not even the per-request
        // one. The move engine's own messages, deferred by commit_set()
        // through do_accept() because it ran under activity:/group: AND
        // inside this joinrequest:{id} lock (T-02 R6).
        notifier::send_all($activity, $deferred);

        // Same hand-back, same reason: core's groups API fires events
        // and writes group conversations per member, so the mirror is
        // converged here - after this lock released and the accept
        // committed - and never inside either (T-16).
        foreach (array_unique($deferredsync) as $syncgroupid) {
            freeze::sync_core_group($activity, (int) $syncgroupid, $actorid);
        }

        // Mail never travels under a lock (the 1.15 lesson).
        self::notify(
            $activity,
            (int) $request->userid,
            $accept ? 'msgjoinacceptedsubject' : 'msgjoindeclinedsubject',
            $accept ? 'msgjoinacceptedbody' : 'msgjoindeclinedbody',
            $target,
            (int) $request->userid,
            $note
        );

        return $fresh;
    }

    /**
     * Turn a request down, inside the caller's lock.
     *
     * @param activity $activity the activity
     * @param stdClass $request the request row, read under the lock
     * @param string $note what the decider said
     * @param int $actorid who decided
     * @return stdClass the decided request
     */
    private static function do_decline(
        activity $activity,
        stdClass $request,
        string $note,
        int $actorid
    ): stdClass {
        global $DB;

        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();
            $DB->update_record('selfselectadvanced_move', (object) [
                'id' => $request->id,
                'status' => self::STATUS_DECLINED,
                'responsenote' => $note,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);
            \mod_selfselectadvanced\event\join_decided::create([
                'objectid' => (int) $request->id,
                'context' => $activity->context(),
                'relateduserid' => (int) $request->userid,
                'other' => ['accepted' => false],
            ])->trigger();
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
        }

        return self::get($activity, (int) $request->id);
    }

    /**
     * Admit the student, inside the caller's lock.
     *
     * The move and the closing of the request are ONE transaction. Before
     * this they were two writes with the engine's own commit between
     * them, so a failure after the move left the student transferred and
     * the request still open for somebody to accept a second time.
     *
     * @param activity $activity the activity
     * @param stdClass $request the request row, read under the lock
     * @param stdClass $target the target team
     * @param string $note what the decider said
     * @param int $actorid who decided
     * @param array $deferred collects the move engine's notifications,
     *        flushed by respond() after ITS lock release
     * @param array $deferredsync collects the plugin group ids whose
     *        mirrored course group needs converging, applied by
     *        respond() after ITS lock release
     * @param array $deferredoverrides collects the move engine's
     *        move_rules_overridden payloads, fired by respond() after
     *        ITS lock release
     * @param string[] $bypass composition rule codes the decider is
     *        overriding (decision 6)
     * @return stdClass the decided request
     * @throws \moodle_exception when the composition rules refuse it
     */
    private static function do_accept(
        activity $activity,
        stdClass $request,
        stdClass $target,
        string $note,
        int $actorid,
        array &$deferred,
        array &$deferredsync,
        array &$deferredoverrides,
        array $bypass = []
    ): stdClass {
        global $DB;

        $api = new api($activity);
        $moves = $api->moves();

        // The move engine's own locks, taken HERE so they cover the
        // outer transaction too: commit_set releases at its finally,
        // which on this path is still inside our transaction, and a
        // writer slipping into that window sees uncommitted state
        // (T-02 R1c/R6).
        $sourceid = $request->sourcegroupid ? (int) $request->sourcegroupid : null;
        $resources = ['activity:' . $activity->id()];
        foreach (array_unique(array_filter([$sourceid, (int) $target->id])) as $gid) {
            $resources[] = 'group:' . $gid;
        }
        $locks = locks::acquire_all($resources);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            // A settled team has to be released by its guide first
            // (strategy 1.19 B step 3) - judged on rows read INSIDE the
            // locks, or a freeze landing between the page load and here
            // is invisible.
            $target = groups::get($activity, (int) $target->id);
            if ($target->state === state::FROZEN) {
                throw new \moodle_exception('refusaljointargetfrozen', 'mod_selfselectadvanced');
            }
            $source = $sourceid ? groups::get($activity, $sourceid) : null;
            if ($source !== null && $source->state === state::FROZEN) {
                throw new \moodle_exception('refusaljoinsourcefrozen', 'mod_selfselectadvanced');
            }
            // The student chose this team at ask time; the roster may have
            // moved since. Removing somebody from a team they already left
            // is not a move the engine can make - stage() would raise
            // errmovenotmember, an engine error the leader cannot read or
            // act on. Refuse in the workflow's own vocabulary instead, and
            // leave the request OPEN so the leader can decline it with a
            // note and the student can withdraw and re-file (the same
            // contract refusaljoinrules already has).
            $stillthere = $source === null || $DB->record_exists('selfselectadvanced_member', [
                'groupid' => (int) $source->id,
                'userid' => (int) $request->userid,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            if (!$stillthere) {
                throw new \moodle_exception(
                    'refusaljoinsourcegone',
                    'mod_selfselectadvanced',
                    '',
                    format_string($source->name)
                );
            }
            // The mirror image of the same staleness, and the one that
            // used to destroy a membership in silence. resolve_source()
            // refuses at ASK time when the student is already confirmed
            // in the target (refusaljoinalready); between asking and
            // answering they can be admitted to it by another route
            // entirely - an invitation they accept, a staff move. The
            // move engine then scores gain=0 (already there) against
            // loss=1, a NET -1, so the L4 cap check waves it through,
            // the SOURCE membership is removed for nothing, and
            // respond() mails the student that they succeeded. Same
            // in-lock read and the same contract as the guard above:
            // refuse in the workflow's own words and leave the request
            // OPEN, so the decider can decline it with a note.
            $alreadyin = $DB->record_exists('selfselectadvanced_member', [
                'groupid' => (int) $target->id,
                'userid' => (int) $request->userid,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            if ($alreadyin) {
                throw new \moodle_exception(
                    'refusaljointargetalready',
                    'mod_selfselectadvanced',
                    '',
                    format_string($target->name)
                );
            }

            $staged = $moves->stage(
                (int) $request->userid,
                $source !== null ? (int) $source->id : null,
                (int) $target->id,
                false,
                null,
                $actorid,
                false,
                $source === null
            );

            // Decision 6: the staff override reaches the join-request
            // path too - the same move-scope mechanism, never a second
            // one. The capability is checked on the ACTOR server-side,
            // so the target team's own student leader posting a crafted
            // bypass[] is refused whatever the form rendered; and
            // save_for_new_move() runs the conflict-of-interest guard,
            // so a conflicted coordinator is refused at the same seam.
            $bypass = array_values(array_intersect(
                array_map(static fn($code) => clean_param($code, PARAM_ALPHANUM), $bypass),
                moves::BYPASSABLE
            ));
            if ($bypass) {
                if (!has_capability('mod/selfselectadvanced:overriderules', $activity->context(), $actorid)) {
                    throw new \moodle_exception('refusaljoinbypasscap', 'mod_selfselectadvanced');
                }
                if (trim($note) === '') {
                    throw new \moodle_exception('errmoveoverridereasonrequired', 'mod_selfselectadvanced');
                }
                \mod_selfselectadvanced\local\override\store::save_for_new_move(
                    $activity,
                    (int) $staged->id,
                    implode(',', $bypass),
                    $actorid
                );
                // The resolver caches every override row on its first
                // read, and stage() has already resolved this move's
                // (empty) bypass set. Without this the validate_set()
                // below - and commit_set()'s own re-validation, which
                // shares this gatekeeper - would judge the move against
                // the cache taken before the row existed (T-04).
                $api->gatekeeper()->resolver()->invalidate();
            }

            $verdicts = $moves->validate_set([(int) $staged->id]);
            if (empty($verdicts->valid)) {
                // Refusing here rolls the staging back with everything
                // else, so nothing survives a refusal.
                throw new \moodle_exception(
                    'refusaljoinrules',
                    'mod_selfselectadvanced',
                    '',
                    self::first_reason($verdicts, (int) $staged->id)
                );
            }
            $moves->commit_set(
                [(int) $staged->id],
                $actorid,
                true,
                $deferred,
                $deferredsync,
                $note,
                $deferredoverrides
            );

            $DB->update_record('selfselectadvanced_move', (object) [
                'id' => $request->id,
                'status' => 'committed',
                'responsenote' => $note,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);
            \mod_selfselectadvanced\event\join_decided::create([
                'objectid' => (int) $request->id,
                'context' => $activity->context(),
                'relateduserid' => (int) $request->userid,
                'other' => ['accepted' => true],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
        } finally {
            locks::release_all($locks);
        }

        // Outside our transaction and outside the activity/group locks,
        // but still inside respond()'s joinrequest:{id} lock - so DB
        // work and events only. No message: $deferred is handed back to
        // respond(), which flushes it after ITS release. Cleared
        // blockers activate parked overrides at once (item 19); on the
        // outermost path commit_set() does this itself.
        \mod_selfselectadvanced\local\override\store::recheck_pending($activity, $actorid);

        return self::get($activity, (int) $request->id);
    }

    /**
     * Take back one's own request while nobody has answered it.
     *
     * @param activity $activity the activity
     * @param int $requestid the request
     * @param int $userid the student who made it
     * @return stdClass the withdrawn request
     * @throws \moodle_exception when refused
     */
    public static function withdraw(activity $activity, int $requestid, int $userid): stdClass {
        global $DB;

        // Under the same lock the answer takes, so a withdrawal and an
        // acceptance racing each other resolve one way or the other and
        // never both (audit HIGH-TX-002).
        $lock = locks::acquire('joinrequest:' . $requestid);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            $request = self::get($activity, $requestid);
            if ((int) $request->userid !== $userid) {
                throw new \moodle_exception('refusaljoinnotyours', 'mod_selfselectadvanced');
            }
            if ($request->status !== self::STATUS_REQUESTED) {
                throw new \moodle_exception('refusaljoinnotopen', 'mod_selfselectadvanced');
            }
            $DB->update_record('selfselectadvanced_move', (object) [
                'id' => $requestid,
                'status' => 'cancelled',
                'usermodified' => $userid,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
        } finally {
            $lock->release();
        }

        return self::get($activity, $requestid);
    }

    /**
     * The requests waiting for one team's leader to answer.
     *
     * @param activity $activity the activity
     * @param int $groupid the target team
     * @return stdClass[] request rows, oldest first
     */
    public static function waiting_for_group(activity $activity, int $groupid): array {
        global $DB;

        return $DB->get_records(
            'selfselectadvanced_move',
            [
                'activityid' => $activity->id(),
                'targetgroupid' => $groupid,
                'status' => self::STATUS_REQUESTED,
            ],
            'timecreated ASC'
        );
    }

    /**
     * One student's own requests, newest first.
     *
     * @param activity $activity the activity
     * @param int $userid the student
     * @return stdClass[] request rows
     */
    public static function mine(activity $activity, int $userid): array {
        global $DB;

        return $DB->get_records_select(
            'selfselectadvanced_move',
            'activityid = :activityid AND userid = :userid AND reason IS NOT NULL',
            ['activityid' => $activity->id(), 'userid' => $userid],
            'timecreated DESC'
        );
    }

    /**
     * One request, asserted to belong to the activity.
     *
     * @param activity $activity the activity
     * @param int $requestid the request
     * @return stdClass the row
     */
    public static function get(activity $activity, int $requestid): stdClass {
        global $DB;

        $request = $DB->get_record('selfselectadvanced_move', ['id' => $requestid], '*', MUST_EXIST);
        if ((int) $request->activityid !== $activity->id()) {
            throw new \moodle_exception('errmovenotfound', 'mod_selfselectadvanced');
        }

        return $request;
    }

    /**
     * Who may answer a request for this team.
     *
     * The target team's leader, and - the maintainer's escape hatch for
     * an absent leader or a contested case - any coordinator or
     * manager.
     *
     * @param activity $activity the activity
     * @param stdClass $target the target team
     * @param int $actorid the actor
     * @throws \moodle_exception when they may not
     */
    public static function require_decider(activity $activity, stdClass $target, int $actorid): void {
        if ((int) $target->leaderid === $actorid) {
            return;
        }
        $context = $activity->context();
        if (
            has_capability('mod/selfselectadvanced:manage', $context, $actorid)
            || has_capability('mod/selfselectadvanced:coordinate', $context, $actorid)
        ) {
            return;
        }

        throw new \moodle_exception('refusaljoinnotleader', 'mod_selfselectadvanced');
    }

    /**
     * Roll back and rethrow, but only when we opened the transaction.
     *
     * Moodle wraps every PHPUnit test in a transaction of its own, and
     * a nested rollback sets force_rollback and poisons the caller's.
     * The same rule the ticket queue follows.
     *
     * @param \moodle_transaction|null $transaction the transaction, if one was started
     * @param bool $outermost whether this call opened it
     * @param \Throwable $e what went wrong
     * @throws \Throwable always
     */
    private static function rollback(?\moodle_transaction $transaction, bool $outermost, \Throwable $e): void {
        if ($transaction !== null && $outermost) {
            $transaction->rollback($e);
        }

        throw $e;
    }

    /**
     * The teams a student is confirmed in, oldest first.
     *
     * Plural, deliberately: multi-membership is a supported
     * configuration (L4), so a single-row fetch over this relation
     * returns an arbitrary row from an unordered result and the two
     * supported databases may disagree about which. The ordered,
     * plural query already existed - this is the join workflow finally
     * using it.
     *
     * @param activity $activity the activity
     * @param int $userid the student
     * @return stdClass[] group rows keyed by group id, timecreated ASC
     */
    public static function current_groups(activity $activity, int $userid): array {
        return groups::get_groups_of_user($activity, $userid);
    }

    /**
     * The first rule that refused a staged move, for the message.
     *
     * moves::validate_set() returns each verdict as an ARRAY; this read
     * it with object syntax, so every branch was empty and the refusal
     * always fell through to the general string - the leader was told
     * "the rules refused it" and never which rule or by how much
     * (D6-5). It now names the rule and carries its figures.
     *
     * @param stdClass $verdicts what validate_set() returned
     * @param int $moveid the staged move
     * @return string a localised reason, or a general one
     */
    private static function first_reason(stdClass $verdicts, int $moveid): string {
        foreach ($verdicts->permove[$moveid] ?? [] as $rule => $verdict) {
            if (empty($verdict['ok']) && empty($verdict['bypassed']) && !empty($verdict['reason'])) {
                return $rule . ': ' . $verdict['reason'];
            }
        }

        return get_string('refusaljoinrulesgeneral', 'mod_selfselectadvanced');
    }

    /**
     * Tell somebody what happened, outside every lock.
     *
     * @param activity $activity the activity
     * @param int $touserid recipient
     * @param string $subjectkey subject string key
     * @param string $bodykey body string key
     * @param stdClass $target the team in question
     * @param int $studentid the student who asked
     * @param string $note the reason or the answer
     */
    private static function notify(
        activity $activity,
        int $touserid,
        string $subjectkey,
        string $bodykey,
        stdClass $target,
        int $studentid,
        string $note
    ): void {
        $student = \core_user::get_user($studentid);
        notifier::send(
            $activity,
            'joinrequests',
            $touserid,
            $subjectkey,
            $bodykey,
            (object) [
                'group' => format_string($target->name),
                'student' => $student ? fullname($student) : '',
                'note' => trim($note),
            ],
            new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $activity->cm()->id,
                'g' => $target->id,
            ]),
            format_string($target->name)
        );
    }
}
