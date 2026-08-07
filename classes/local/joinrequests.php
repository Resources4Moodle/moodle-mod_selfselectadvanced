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

    /** @var array<string,string> Invitation refusals a staff move override can repair. */
    private const OVERRIDEABLE_HARD_RULES = [
        'refusalnoseats' => 'L2',
        // The honest sibling sentences (seam audit B8) are the SAME
        // L2 fact and keep the same staff-override authority - the
        // full-suite sweep caught the first wording change silently
        // downgrading them to plain hard stops.
        'refusalnoseatsconfirmed' => 'L2',
        'refusalnoseatsheld' => 'L2',
        'refusalinviteecap' => 'L4',
    ];

    /** @var string[] Gatekeeper composition keys owned by fit::door_verdict() on this door. */
    private const COMPOSITION_KEYS = ['refusalcompositionmax', 'refusalcompositionunreachable'];

    /**
     * Whether accepting this request is live, blocked, or needs a confirmation.
     *
     * The answering surfaces consume this single object so their button
     * state cannot drift from the service path. Hard stops are the
     * cases acceptance cannot safely repair: no target seat, duplicate
     * target membership/invitation, an additional membership over the
     * student's cap, stale source membership, a source leader who
     * would need a successor the join-request workflow cannot name -
     * and, since decision 60, a composition maximum that CONFIRMED
     * members plus this student would violate, which only the staff
     * override may pass. An engine-refused reachability mismatch stays
     * a confirmable warning routed through the move engine's
     * move-scope bypass; a maximum that only pending invitations push
     * over is a consent note that bypasses nothing.
     *
     * @param activity $activity the activity
     * @param stdClass $request the join-request row
     * @param int $actorid the viewer deciding whether this accept control is live
     * @param stdClass|null $target the target group when the caller already has it
     * @return stdClass {canaccept: bool, hardreason: string, hardkey: string,
     *                  warnings: string[], confirmationrequired: bool, bypassrules: string[],
     *                  confirmacceptrequired: bool, consentnotes: string[]}
     */
    public static function accept_decision(
        activity $activity,
        stdClass $request,
        int $actorid,
        ?stdClass $target = null
    ): stdClass {
        global $DB;

        $target = $target ?? groups::get($activity, (int) $request->targetgroupid);
        $canoverride = has_capability('mod/selfselectadvanced:overriderules', $activity->context(), $actorid);
        $decision = (object) [
            'canaccept' => true,
            'hardreason' => '',
            'hardkey' => '',
            'warnings' => [],
            'confirmationrequired' => false,
            'bypassrules' => [],
            'confirmacceptrequired' => false,
            'consentnotes' => [],
        ];
        $hard = static function (string $reason, string $key) use ($decision): void {
            if ($decision->hardreason === '') {
                $decision->hardreason = $reason;
                $decision->hardkey = $key;
            }
            $decision->canaccept = false;
        };
        $overrideablehard = static function (
            string $reason,
            string $key,
            string $rule
        ) use (
            $decision,
            $hard,
            $canoverride
        ): void {
            if (!$canoverride) {
                $hard($reason, $key);

                return;
            }
            if ($decision->hardreason === '') {
                $decision->hardreason = $reason;
                $decision->hardkey = $key;
            }
            if (!in_array($reason, $decision->warnings, true)) {
                $decision->warnings[] = $reason;
            }
            if (!in_array($rule, $decision->bypassrules, true)) {
                $decision->bypassrules[] = $rule;
            }
        };
        // Consent, not bypass: the decider must confirm they read it,
        // but NO rule code travels with it - the engine will pass this
        // acceptance unaided, and until decision 60 this tier wrote a
        // QUOTA override row for a verdict that never refused, a record
        // claiming a bypass that bypassed nothing.
        $consent = static function (string $note) use ($decision): void {
            if ($note !== '' && !in_array($note, $decision->warnings, true)) {
                $decision->warnings[] = $note;
            }
            if ($note !== '' && !in_array($note, $decision->consentnotes, true)) {
                $decision->consentnotes[] = $note;
            }
        };

        $source = null;
        if ($request->sourcegroupid) {
            $source = groups::get($activity, (int) $request->sourcegroupid);
            $stillthere = $DB->record_exists('selfselectadvanced_member', [
                'groupid' => (int) $source->id,
                'userid' => (int) $request->userid,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            if (!$stillthere) {
                $hard(
                    get_string('refusaljoinsourcegone', 'mod_selfselectadvanced', format_string($source->name)),
                    'refusaljoinsourcegone'
                );
            } else if ((int) $source->leaderid === (int) $request->userid) {
                $others = $DB->record_exists_select(
                    'selfselectadvanced_member',
                    'groupid = ? AND userid <> ? AND status = ?',
                    [(int) $source->id, (int) $request->userid, groups::STATUS_CONFIRMED]
                );
                $key = $others ? 'errmovesuccessorrequired' : 'errmovesololeader';
                $hard(get_string($key, 'mod_selfselectadvanced'), $key);
            } else {
                $min = (new resolver($activity))->effective_minsize((int) $source->id)->value;
                $after = groups::count_confirmed((int) $source->id) - 1;
                if ($after < $min) {
                    // The SOURCE team's minimum is not the target
                    // leader's to waive (decision 64): until 1.20.17
                    // this was a confirmable warning, and the accepting
                    // leader's confirm click authored a move-scope L1
                    // override they had no authority to write.
                    $overrideablehard(get_string('moveruleL1', 'mod_selfselectadvanced', (object) [
                        'after' => $after,
                        'min' => $min,
                    ]), 'moveruleL1', 'L1');
                }
            }
        }

        if ($key = self::join_change_refusal($target, true)) {
            $hard(get_string($key, 'mod_selfselectadvanced'), $key);
        }
        if ($source !== null && ($key = self::join_change_refusal($source, false))) {
            $hard(get_string($key, 'mod_selfselectadvanced'), $key);
        }
        $alreadyconfirmed = $DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $target->id,
            'userid' => (int) $request->userid,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        if ($alreadyconfirmed) {
            $hard(
                get_string('refusaljointargetalready', 'mod_selfselectadvanced', format_string($target->name)),
                'refusaljointargetalready'
            );
        }

        foreach ((new api($activity))->gatekeeper()->can_invite_all($target, (int) $request->userid) as $refusal) {
            if ($refusal->stringkey === 'refusalwrongstate') {
                continue;
            }
            if ($refusal->stringkey === 'refusalinviteecap' && $source !== null) {
                continue;
            }
            if (in_array($refusal->stringkey, self::COMPOSITION_KEYS, true)) {
                // Composition is judged by fit::door_verdict() below -
                // one evaluator for every door (decision 60). The
                // gatekeeper's own composition answer is the INVITE
                // door's, measured over a projection this door must
                // not confuse with the present.
                continue;
            }
            if (isset(self::OVERRIDEABLE_HARD_RULES[$refusal->stringkey])) {
                $overrideablehard(
                    $refusal->get_message(),
                    $refusal->stringkey,
                    self::OVERRIDEABLE_HARD_RULES[$refusal->stringkey]
                );
                continue;
            }
            $hard($refusal->get_message(), $refusal->stringkey);
        }

        // Decisions 60 and 64, each from a live breach the maintainer
        // caught. Decision 60 (2026-08-06): a maximum that CONFIRMED
        // members plus this student would violate is a present
        // violation - a hard stop, staff-overridable only. Decision 64
        // (2026-08-07): an ENGINE-tier refusal gets the SAME
        // treatment, because a rule is the staff's to declare
        // breakable and never the accepting leader's - until 1.20.17
        // this tier was a confirmable warning whose confirm click
        // wrote a QUOTA override in the leader's name, and two SCE
        // members were admitted under SCOPE 2-2 + distinct>=4 on five
        // seats. Only the third tier remains the decider's own: a
        // maximum that PENDING INVITATIONS alone push over blocks
        // nothing and bypasses nothing - the decider proceeds informed
        // that those invitations can no longer be accepted.
        $door = fit::door_verdict(
            $activity,
            $target,
            (int) $request->userid,
            $source !== null ? (int) $source->id : null
        );
        if ($door->hardmax !== null) {
            $overrideablehard($door->hardmax, (string) $door->hardmaxkey, 'QUOTA');
        } else if ($door->engine !== null) {
            // Decision 64, from the maintainer's live breach of
            // 2026-08-07 (g=44: two SCE members admitted under
            // SCOPE 2-2 + distinct>=4 on five seats): an ENGINE-tier
            // quota refusal is a rule the activity set, and rules are
            // the STAFF'S to declare breakable, never the accepting
            // leader's. Until 1.20.17 this arm was a confirmable
            // warning whose confirm click wrote a QUOTA override in
            // the leader's name - "Should not allow it, but let us
            // see" allowed it. The netting rationale that once
            // justified the softer tier is vacuous here: a single
            // acceptance is a set of one, nothing else moves, so the
            // engine's refusal is final. Same treatment as hardmax:
            // hard stop for the ordinary decider, bypassable only
            // through :overriderules with a written reason.
            $overrideablehard($door->engine, (string) $door->enginekey, 'QUOTA');
        }
        foreach ($door->consent as $note) {
            $consent($note);
        }

        $decision->bypassrules = array_values(array_unique(array_intersect(
            moves::BYPASSABLE,
            $decision->bypassrules
        )));
        $decision->confirmationrequired = $decision->canaccept && $decision->bypassrules !== [];
        $decision->confirmacceptrequired = $decision->canaccept && $decision->consentnotes !== [];

        return $decision;
    }

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
        self::require_join_changeable($target, true);

        // A REQUEST TO A TEAM THAT ALREADY INVITED YOU IS AN ACCEPTANCE.
        // Maintainer ruling, 2026-08-05. An invitation and a request are the
        // same event - this person joining this team - differing only in who
        // spoke first, and the student clicking "ask to join" plainly means
        // yes. Creating a second pending row for one fact was what produced
        // the defect this rule closes: the advisory projection merged
        // confirmed + invited + the requester, and a person holding BOTH rows
        // was counted TWICE, so a team that met "between 2 and 2 with
        // Department SCOPE" exactly was told the maximum was exceeded by a
        // phantom that was really the requester's own invitation.
        //
        // Answered BEFORE the lock below because accept() takes its own
        // locks; taking joinrequest:user here first would nest them and
        // invert this codebase's lock order.
        $invited = $DB->record_exists('selfselectadvanced_member', [
            'groupid' => $targetgroupid,
            'userid' => $userid,
            'status' => groups::STATUS_INVITED,
        ]);
        if ($invited) {
            (new api($activity))->invitations()->accept($target, $userid);

            // The caller expects a request row back. There is none - the
            // membership is settled - so the shape that says so is the
            // accepted request this action is equivalent to.
            return (object) [
                'id' => 0,
                'activityid' => $activity->id(),
                'userid' => $userid,
                'targetgroupid' => $targetgroupid,
                'sourcegroupid' => null,
                // The terminal status an accepted request
                // reaches through the move engine; there is deliberately no
                // STATUS_ACCEPTED constant, and inventing one here would put
                // a value in this shape that no query in the codebase looks
                // for.
                'status' => 'committed',
                'reason' => $reason,
                'acceptedinvitation' => true,
            ];
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
            self::rollback($transaction ?? null, $e);
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

        self::require_join_changeable($source, false);

        return $source;
    }

    /**
     * Why this team cannot change through a join request, or null when it can.
     *
     * Forming teams are still open. Approved teams are open only after
     * their assigned guide has released them; pending-review and
     * unreleased approved teams are settled enough that the leader must
     * ask for a release first. Frozen keeps its older, more specific
     * refusal because it names the guide action that opens it.
     *
     * @param stdClass $group the team row
     * @param bool $target true when the student is joining this team, false when leaving it
     * @return string|null language string key, or null when the team may change
     */
    private static function join_change_refusal(stdClass $group, bool $target): ?string {
        if ($group->state === state::FROZEN) {
            return $target ? 'refusaljointargetfrozen' : 'refusaljoinsourcefrozen';
        }
        // Decision 63: a team its leader has asked to wind up recruits
        // nobody. A state-shaped early refusal at this ONE seam - ask
        // and accept both pass through it - and deliberately NOT inside
        // the composition machinery, so no bypass list, tier or key
        // built before it is touched. LEAVING the team stays open: the
        // wind-up is precisely people leaving.
        if ($target && !empty($group->timedisbandrequested)) {
            return 'refusaldisbanding';
        }
        if ($group->state === state::FORMING) {
            return null;
        }
        if ($group->state === state::FIRM && !empty($group->releasedbyguide)) {
            // Decision 58 (2026-08-05): a guide release makes the firm
            // roster mutable without touching timeapproved. Re-stamping
            // or forcing re-approval would increase the lateness penalty
            // for the whole team even though the guide sanctioned the
            // change.
            return null;
        }

        return $target ? 'refusaljointargetunreleased' : 'refusaljoinsourceunreleased';
    }

    /**
     * Assert that a team may change through a join request.
     *
     * @param stdClass $group the team row
     * @param bool $target true when the student is joining this team, false when leaving it
     * @throws \moodle_exception when the team may not change
     */
    private static function require_join_changeable(stdClass $group, bool $target): void {
        if ($key = self::join_change_refusal($group, $target)) {
            throw new \moodle_exception($key, 'mod_selfselectadvanced');
        }
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
     * @param bool $acceptconfirmed true when the decider confirmed the
     *        advisory rule mismatch named by accept_decision()
     * @return stdClass the decided request
     * @throws \moodle_exception when refused
     */
    public static function respond(
        activity $activity,
        int $requestid,
        bool $accept,
        string $note,
        int $actorid,
        array $bypass = [],
        bool $acceptconfirmed = false
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
                    $bypass,
                    $acceptconfirmed
                )
                : self::do_decline($activity, $request, $note, $actorid);
        } finally {
            $lock->release();
        }

        // Cleared blockers activate parked overrides at once (item 19).
        // AFTER the release, never inside it: recheck_pending() fires
        // an override_updated per row it activates, and until 1.20.1
        // this ran from inside do_accept() - outside the move engine's
        // locks but still inside the joinrequest:{id} lock above, so
        // those events travelled under a lock (audit O-3). Only
        // move_committed, leadership_transferred and join_decided are
        // grandfathered there. It also takes override: locks of its
        // own, which is a second lock nested under ours for no reason
        // now that it can simply run out here.
        //
        // RESTRICTED to what this acceptance moved - the requester and
        // the two teams involved - because an unrestricted sweep would
        // examine every pending row of the activity on every join
        // accept (T-08). Only on the accept path: a decline moves
        // nobody, so nothing it did can have cleared a blocker.
        // commit_set()'s own outermost call takes the same kind of
        // restriction, built from its committed move set.
        if ($accept) {
            \mod_selfselectadvanced\local\override\store::recheck_pending($activity, $actorid, [
                'user' => [(int) $request->userid],
                'group' => [(int) $target->id, (int) $request->sourcegroupid],
            ]);
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
        if ($accept) {
            self::notify_released_guide_change($activity, $target, $request);
        }

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
            self::rollback($transaction ?? null, $e);
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
     * @param bool $acceptconfirmed true when the decider confirmed the
     *        advisory rule mismatch named by accept_decision()
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
        array $bypass = [],
        bool $acceptconfirmed = false
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
        try {
            $transaction = $DB->start_delegated_transaction();

            // A settled team has to be released by its guide first
            // (strategy 1.19 B step 3) - judged on rows read INSIDE the
            // locks, or a submission, approval, freeze or release
            // landing between the page load and here is invisible.
            $target = groups::get($activity, (int) $target->id);
            self::require_join_changeable($target, true);
            $source = $sourceid ? groups::get($activity, $sourceid) : null;
            if ($source !== null) {
                self::require_join_changeable($source, false);
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
            // entirely - an invitation they accept, a staff move.
            //
            // The ENGINE now refuses that shape for every caller: it
            // scores such a move 0/0 and raises the non-bypassable TGT
            // verdict (moves::validate_set), where it used to score
            // gain=0 against loss=1, a net -1 that the L4 cap check
            // waved through while the SOURCE membership was removed for
            // nothing. This guard is no longer the only thing standing
            // between a stale request and a deleted membership - it is
            // the workflow's own words for it, with the same in-lock
            // read and the same contract as the guard above: refuse in
            // terms of the REQUEST and leave it OPEN, so the decider
            // can decline it with a note instead of meeting a set
            // refusal from the move engine.
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
            $staffbypass = $bypass;
            if ($staffbypass) {
                if (!has_capability('mod/selfselectadvanced:overriderules', $activity->context(), $actorid)) {
                    throw new \moodle_exception('refusaljoinbypasscap', 'mod_selfselectadvanced');
                }
                if (trim($note) === '') {
                    throw new \moodle_exception('errmoveoverridereasonrequired', 'mod_selfselectadvanced');
                }
            }

            $decision = self::accept_decision($activity, $request, $actorid, $target);
            $enginewillname = in_array($decision->hardkey, [
                'refusalnoseats',
                'refusalnoseatsconfirmed',
                'refusalnoseatsheld',
                'refusalinviteecap',
                'errmovesuccessorrequired',
                'errmovesololeader',
            ], true);
            if (!$decision->canaccept && !$staffbypass && !$enginewillname) {
                throw new \moodle_exception(
                    'refusaljoinrules',
                    'mod_selfselectadvanced',
                    '',
                    $decision->hardreason
                );
            }
            if ($decision->consentnotes !== [] && !$acceptconfirmed) {
                // Its own sentence (seam audit B8, 1.20.20): the old
                // wrapper said "Accepting would break the team's
                // composition" around notes whose whole point is that
                // NO rule is broken - the decider only has to read the
                // consequence before proceeding.
                throw new \moodle_exception(
                    'refusaljoinconsent',
                    'mod_selfselectadvanced',
                    '',
                    implode(' ', $decision->consentnotes)
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
            // Decision 64: NOTHING is merged into the bypass set here
            // any more. $bypass is exactly what the actor posted, the
            // :overriderules check above has already vetted it, and the
            // consent confirm carries no rule codes (decision 60) - so
            // a leader's confirm click can no longer author an
            // override row. The override reason is the staff note,
            // which the staff arm above requires to be non-empty.
            $overridereason = trim($note) !== ''
                ? $note
                : get_string('joinacceptconfirmedreason', 'mod_selfselectadvanced');
            if ($bypass) {
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
                    self::first_reason(
                        $verdicts,
                        (int) $staged->id,
                        $activity,
                        $target,
                        $source,
                        (int) $request->userid,
                        // Rule codes are staff vocabulary (seam audit
                        // B8): they name the override checkboxes, so
                        // an :overriderules holder keeps them; the
                        // ordinary decider reads the sentence alone.
                        has_capability('mod/selfselectadvanced:overriderules', $activity->context(), $actorid)
                    )
                );
            }
            $moves->commit_set(
                [(int) $staged->id],
                $actorid,
                true,
                $deferred,
                $deferredsync,
                $overridereason,
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
            self::rollback($transaction ?? null, $e);
        } finally {
            locks::release_all($locks);
        }

        // The parked-override sweep does NOT run here. It used to -
        // outside this method's transaction and locks, but still inside
        // respond()'s joinrequest:{id} lock, so every override_updated
        // event it fired travelled under a lock. Only three events in
        // this plugin are grandfathered inside one, and that is not one
        // of them, and recheck_pending() takes override: locks of its
        // own on top of ours for good measure. respond() runs it after
        // its release; see the call there (audit O-3).
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
            self::rollback($transaction ?? null, $e);
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
     * The same question require_decider() asks, as a refusal-or-null:
     * the one producer for every surface that must decide whether to
     * OFFER an answer control (seam audit B1, 1.20.20). Both the group
     * page's request panel and joinrequest.php's Answer tab call this
     * rather than keeping private copies of the door - which is how
     * the Answer tab drifted into drawing live controls for a
     * prohibited leader and, once decision 65 landed, for an involved
     * coordinator.
     *
     * @param activity $activity the activity
     * @param stdClass $target the target team
     * @param int $actorid the actor
     * @return string '' when they may decide, else the refusal sentence
     */
    public static function decide_refusal(activity $activity, stdClass $target, int $actorid): string {
        try {
            self::require_decider($activity, $target, $actorid);
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }

        return '';
    }

    /**
     * Who may answer a request for this team.
     *
     * The target team's leader while they still hold the authority to
     * act as a leader, and - the maintainer's escape hatch for an
     * absent leader or a contested case - any coordinator or manager
     * (an involved narrow-authority coordinator excepted, decision 65).
     *
     * @param activity $activity the activity
     * @param stdClass $target the target team
     * @param int $actorid the actor
     * @throws \moodle_exception when they may not
     */
    public static function require_decider(activity $activity, stdClass $target, int $actorid): void {
        if ((int) $target->leaderid === $actorid && authority::may_lead($activity, $actorid)) {
            return;
        }
        $context = $activity->context();
        if (
            has_capability('mod/selfselectadvanced:manage', $context, $actorid)
            || has_capability('mod/selfselectadvanced:coordinate', $context, $actorid)
        ) {
            // The maintainer's standing conflict rule ("Group
            // coordinators should not act on their own teams"), from
            // the single producer every sibling on-behalf seam already
            // asks - eoi::decide_refusal, freeze, return_group,
            // assign_guide, tickets, the override store. Until 1.20.19
            // this was the one staff-for-an-absent-leader door without
            // it (seam audit): an involved narrow-authority coordinator
            // could answer requests for their own team.
            // require_uninvolved() returns at once for a :manage
            // holder, so the trusted arm is unaffected.
            tickets::require_uninvolved($activity, $target, $actorid);

            return;
        }

        throw new \moodle_exception('refusaljoinnotleader', 'mod_selfselectadvanced');
    }

    /**
     * Roll back and rethrow, whoever opened the transaction.
     *
     * UNCONDITIONAL since 1.20 wave 3E. This helper used to take an
     * $outermost flag, read from $DB->is_transaction_started() before
     * the lock, and skip the rollback whenever a caller already held a
     * transaction. Both halves of that were wrong.
     *
     * The flag was not a fact about the method: advanced_testcase opens
     * a delegated transaction before every test on PostgreSQL and none
     * on MariaDB, so it was false on m5pg and true on m5my for the
     * whole suite - one arm per engine, neither engine exercising the
     * other.
     *
     * And skipping the rollback did not protect the caller, it broke
     * it. rollback_delegated_transaction() (lib/dml/moodle_database.php)
     * issues the physical ROLLBACK only for the frame on top of $DB's
     * stack. Abandoning our frame leaves it there undisposed, so the
     * caller's own rollback() fails that identity check, rethrows
     * without rolling anything back, and its writes survive a refusal
     * the caller believed it had unwound - after which
     * commit_delegated_transaction() throws for every later commit in
     * the request. do_accept() nests store::save() through
     * save_for_new_move() on exactly that path. Rolling our frame back
     * pops it, sets force_rollback, and lets the caller's rollback
     * reach the bottom, which is the cascade core documents.
     *
     * @param \moodle_transaction|null $transaction the transaction, if one was started
     * @param \Throwable $e what went wrong
     * @throws \Throwable always
     */
    private static function rollback(?\moodle_transaction $transaction, \Throwable $e): void {
        if ($transaction !== null && !$transaction->is_disposed()) {
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
     * THE QUOTA VERDICT IS RE-WORDED HERE (1.20.5). The engine's own
     * sentence is "Quota rules on both groups after the move", written
     * for a manager moving somebody BETWEEN two teams. A join request
     * that keeps every current team - the maintainer's extra-membership
     * shape - has no source group at all, so "both groups" named a team
     * the student had never mentioned, and the leader was left to guess
     * which rules on which roster had refused them. The teams involved
     * are known here, and fit::accept_composition_refusal() - the same
     * projection fit::for_person() put in front of the leader beside
     * this very request - says which side is at fault, in the sentence
     * they already read. The ENGINE still decides; this only decides
     * the words. Where the projection and the engine disagree the
     * engine's own sentence stands, and then only where it is true:
     * with a source group there really are two rosters.
     *
     * @param stdClass $verdicts what validate_set() returned
     * @param int $moveid the staged move
     * @param activity $activity the activity
     * @param stdClass $target the team being joined
     * @param stdClass|null $source the team being left, or null for an extra membership
     * @param int $userid the student the request is about
     * @param bool $includecodes prefix rule codes - staff vocabulary that names their bypass form
     * @return string a localised reason, or a general one
     */
    private static function first_reason(
        stdClass $verdicts,
        int $moveid,
        activity $activity,
        stdClass $target,
        ?stdClass $source,
        int $userid,
        bool $includecodes = false
    ): string {
        $prefix = static fn(string $rule): string => $includecodes ? $rule . ': ' : '';
        foreach ($verdicts->permove[$moveid] ?? [] as $rule => $verdict) {
            if (empty($verdict['ok']) && empty($verdict['bypassed']) && !empty($verdict['reason'])) {
                if ($rule === 'QUOTA') {
                    $named = fit::accept_composition_refusal(
                        $activity,
                        $target,
                        $userid,
                        $source !== null ? (int) $source->id : null
                    );
                    if ($named !== null) {
                        return $prefix($rule) . $named;
                    }
                    if ($source === null) {
                        return $prefix($rule) . get_string(
                            'refusaljoinquotatarget',
                            'mod_selfselectadvanced',
                            format_string($target->name)
                        );
                    }
                }

                return $prefix($rule) . $verdict['reason'];
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

    /**
     * Tell the guide that a team they released has a different roster.
     *
     * Maintainer decision 56 made a guide-released FIRM team mutable
     * again. The guide approved an earlier roster, so a later
     * leader-accepted join must be reported to that guide in the same
     * joinrequests provider as the rest of this workflow. The payload
     * names only the student and the teams involved. It deliberately
     * carries no email, phone or free-text note: a guide is a
     * non-editing teacher under the contact privacy rule, and the fact
     * they need is the roster delta, not a contact channel.
     *
     * @param activity $activity the activity
     * @param stdClass $target the target team as it was when accepted
     * @param stdClass $request the accepted join request
     */
    private static function notify_released_guide_change(
        activity $activity,
        stdClass $target,
        stdClass $request
    ): void {
        if ($target->state !== state::FIRM || empty($target->guideid)) {
            return;
        }

        $student = \core_user::get_user((int) $request->userid);
        $sourcechange = get_string('msgjoinguidechangednosource', 'mod_selfselectadvanced');
        if (!empty($request->sourcegroupid)) {
            $source = groups::get($activity, (int) $request->sourcegroupid);
            $sourcechange = get_string('msgjoinguidechangedsource', 'mod_selfselectadvanced', format_string($source->name));
        }

        notifier::send(
            $activity,
            'joinrequests',
            (int) $target->guideid,
            'msgjoinguidechangedsubject',
            'msgjoinguidechangedbody',
            (object) [
                'group' => format_string($target->name),
                'student' => $student ? fullname($student) : '',
                'sourcechange' => $sourcechange,
            ],
            new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $activity->cm()->id,
                'g' => $target->id,
            ]),
            format_string($target->name)
        );
    }
}
