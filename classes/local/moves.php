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
 * Staged manager moves (spec 7, decisions A4/A6, review item B3).
 *
 * A removal is normally expressed as a move to somewhere. The one
 * exception is a staff PARK - a null targetgroupid, authorised by
 * :overriderules at the stage() seam - which removes a student with no
 * destination team (decision 6, D6-2); every target-side rule and write
 * is skipped for it. Moves sit in
 * `pending` with NO visible change until a manager commits a selected
 * SET; the set is validated jointly against the net post-state of
 * every touched group (a swap of two students commits as a set).
 * Rules L1-L4 and quota apply, each bypassable only by a move-scope
 * override attached to a specific move; member moves never change
 * guide assignments, so L5 is structurally unaffected (documented).
 * TGT, SUCC and LEADR are NOT in self::BYPASSABLE and their bypass
 * flag is a literal false: a move into a team the student already
 * belongs to, a stale successor and an unconsented demotion are
 * corruption rather than policy, and no override row reaches them.
 * Committing a move on a frozen group mirrors the core group and
 * refreshes the snapshot in the same transaction (A6).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moves {
    /**
     * The most moves one commit may carry.
     *
     * A commit holds the activity lock plus one lock per touched group
     * while it re-validates and applies every row, so an unbounded
     * selection is an unbounded lock hold against a 10s acquire budget
     * on a site with 1500 teams (decision 6, D6-8).
     *
     * @var int
     */
    public const MAX_COMMIT = 100;

    /** @var string[] The rule codes a move-scope override may bypass. */
    public const BYPASSABLE = ['L1', 'L2', 'L3', 'L4', 'QUOTA'];

    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param gatekeeper $gatekeeper the gatekeeper (resolver source)
     */
    public function __construct(activity $activity, gatekeeper $gatekeeper) {
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;
    }

    /**
     * Stage a move (no visible change occurs).
     *
     * A null target is a PARK (decision 6, D6-2): a staff removal with
     * no destination team. It is authorised by :overriderules at this
     * seam, never designates a leader, and must have a source to remove
     * the student from.
     *
     * Two refusals guard the destination, both AFTER the source
     * inference below, because the inference is what produces the
     * shapes they refuse: the source may not be the target, and the
     * student may not already be a confirmed member of the target.
     * Neither is the guarantee - validate_set()'s TGT verdict is, on
     * the roster the commit actually sees - but a manager should hear
     * it at the form and not days later.
     *
     * @param int $userid the student to move
     * @param int|null $sourcegroupid group to leave, null when placing a groupless student
     * @param int|null $targetgroupid group to join, null for a staff park (removal, no destination)
     * @param bool $makeleader designate the student leader of the target
     * @param int|null $successorid new leader for the source when moving its leader out
     * @param int $actorid the acting manager
     * @param bool $replaceleader explicit consent to demote the target group's current leader
     * @param bool $explicitnosource the caller means the null source literally - add a membership,
     *                               leave nothing - so no inference and no ambiguity refusal; the
     *                               L4 cap in validate_set() is what stops it
     * @return stdClass the pending move row with validation results
     */
    public function stage(
        int $userid,
        ?int $sourcegroupid,
        ?int $targetgroupid,
        bool $makeleader,
        ?int $successorid,
        int $actorid,
        bool $replaceleader = false,
        bool $explicitnosource = false
    ): stdClass {
        global $DB;

        // Server-side ownership of every id (IDOR). The row is kept:
        // the target-side refusals below name the team, and re-reading
        // it for the name would be a second query for the same row.
        $target = null;
        if ($targetgroupid !== null) {
            $target = groups::get($this->activity, $targetgroupid);
        }

        // Server-side ownership of the USER id too (IDOR): the picker
        // restricts to enrolled :respond holders
        // (external/search_participants.php) but the seam must not trust
        // the picker - the comment above claimed this check for two
        // releases while only the GROUPS were validated, so a groupless
        // id posted straight at this service skipped every membership
        // test and apply() inserted the member row unconditionally
        // (D6-10). A park (null target) is a pure removal and stays
        // possible for a suspended or unenrolled user; a placement must
        // land on a live participant.
        if (
            $targetgroupid !== null
            && !is_enrolled($this->activity->context(), $userid, 'mod/selfselectadvanced:respond', true)
        ) {
            throw new \moodle_exception('errmovenotparticipant', 'mod_selfselectadvanced');
        }

        // Park authorisation lives at the seam, so every caller - the
        // form, a dissolve, a future script - inherits it.
        if ($targetgroupid === null) {
            if (!has_capability('mod/selfselectadvanced:overriderules', $this->activity->context(), $actorid)) {
                throw new \moodle_exception('errmoveparkcapability', 'mod_selfselectadvanced');
            }
            if ($makeleader) {
                throw new \moodle_exception('errmoveparknolead', 'mod_selfselectadvanced');
            }
            if ($explicitnosource) {
                // Removes nothing and adds nothing: an incoherent ask,
                // not a no-op to be silently accepted.
                throw new \moodle_exception('errmoveparkandtarget', 'mod_selfselectadvanced');
            }
        }

        if ($sourcegroupid === null && !$explicitnosource) {
            // A blank source for a student who IS confirmed somewhere
            // would silently create a second membership on commit:
            // infer the source when it is unambiguous, refuse when the
            // manager must choose. A caller that MEANS the second
            // membership says so with $explicitnosource and is judged
            // by L4 instead.
            $memberships = $DB->get_records_sql(
                "SELECT g.id, g.name
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                  WHERE g.activityid = :activityid AND m.userid = :userid AND m.status = :status",
                [
                    'activityid' => $this->activity->id(),
                    'userid' => $userid,
                    'status' => groups::STATUS_CONFIRMED,
                ]
            );
            if (count($memberships) === 1) {
                $sourcegroupid = (int) reset($memberships)->id;
            } else if ($memberships) {
                $names = implode(', ', array_map(
                    static fn($group) => format_string($group->name),
                    $memberships
                ));
                throw new \moodle_exception('refusalmovesourcerequired', 'mod_selfselectadvanced', '', $names);
            }
        }

        // A move must go somewhere ELSE, and this is the seam that can
        // say so. classes/form/move_form.php compares only a source the
        // manager TYPED - its guard opens with !empty($data['source']) -
        // so it never fires on a blank one, and a blank source is
        // inferred just above, to the target itself whenever that is
        // the student's only team. Committing that shape removes the
        // membership and re-adds it, and when the student leads the
        // team the crown goes to the successor stage() forced the
        // manager to name: a silent demotion, no override involved and
        // every verdict green (measured, both engines).
        if ($sourcegroupid !== null && $targetgroupid !== null && $sourcegroupid === $targetgroupid) {
            throw new \moodle_exception('errmovesamegroup', 'mod_selfselectadvanced');
        }

        // Already there: the move gains the student nothing while its
        // source half still deletes a membership. validate_set()'s TGT
        // verdict is the engine-level guarantee - it is what covers the
        // set staged today and committed next week - and this is its
        // cheap, early half, so the manager hears it at the form
        // instead of at commit time.
        if (
            $targetgroupid !== null
            && $DB->record_exists('selfselectadvanced_member', [
                'groupid' => $targetgroupid,
                'userid' => $userid,
                'status' => groups::STATUS_CONFIRMED,
            ])
        ) {
            throw new \moodle_exception(
                'refusalmovetargetalready',
                'mod_selfselectadvanced',
                '',
                format_string($target->name)
            );
        }

        // A park removes somebody from somewhere; with no source there
        // is nothing to remove and nothing to add.
        if ($targetgroupid === null && $sourcegroupid === null) {
            throw new \moodle_exception('errmovenotmember', 'mod_selfselectadvanced');
        }
        if ($sourcegroupid !== null) {
            $source = groups::get($this->activity, $sourcegroupid);
            $ismember = $DB->record_exists('selfselectadvanced_member', [
                'groupid' => $sourcegroupid,
                'userid' => $userid,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            if (!$ismember) {
                throw new \moodle_exception('errmovenotmember', 'mod_selfselectadvanced');
            }
            if ((int) $source->leaderid === $userid && !$successorid) {
                // A one-member team can never name a successor, so
                // "name a successor" is a dead end there and used to be
                // the only thing this seam said (D6-3). Name the verb
                // that actually resolves it instead. The refusal stays
                // at stage time deliberately: a committed leader-out
                // with nobody left would strand leaderid (NOT NULL)
                // pointing at a removed member.
                $others = $DB->record_exists_select(
                    'selfselectadvanced_member',
                    'groupid = ? AND userid <> ? AND status = ?',
                    [$sourcegroupid, $userid, groups::STATUS_CONFIRMED]
                );
                throw new \moodle_exception(
                    $others ? 'errmovesuccessorrequired' : 'errmovesololeader',
                    'mod_selfselectadvanced'
                );
            }
        }
        // The successor is a roster id posted from a 5000-user
        // autocomplete: verify enrolment before membership so a foreign
        // id is named as such (D6-10).
        if (
            $successorid
            && !is_enrolled($this->activity->context(), $successorid, 'mod/selfselectadvanced:respond', true)
        ) {
            throw new \moodle_exception('errmovenotparticipant', 'mod_selfselectadvanced');
        }
        // A successor must be a confirmed member of the source group
        // other than the student being moved (5000-user autocomplete
        // means the value can be anyone; validate here, not in the UI).
        if ($successorid) {
            $issourcemember = $sourcegroupid !== null && $successorid !== $userid
                && $DB->record_exists('selfselectadvanced_member', [
                    'groupid' => $sourcegroupid,
                    'userid' => $successorid,
                    'status' => groups::STATUS_CONFIRMED,
                ]);
            if (!$issourcemember) {
                throw new \moodle_exception('errmovebadsuccessor', 'mod_selfselectadvanced');
            }
        }

        // Conflict of interest (1.16 D): an actor whose authority to be
        // here is the narrow :managecomposition capability may not
        // stage a move touching a team they are involved in. Probed on
        // BOTH sides, and null-safe: a park (D6-2) has no target, and a
        // placement of a groupless student has no source.
        $this->require_uninvolved_narrow($target, $actorid);
        if ($sourcegroupid !== null) {
            $this->require_uninvolved_narrow($source, $actorid);
        }

        $now = time();
        $move = (object) [
            'activityid' => $this->activity->id(),
            'userid' => $userid,
            'sourcegroupid' => $sourcegroupid,
            'targetgroupid' => $targetgroupid,
            'makeleader' => $makeleader ? 1 : 0,
            'replaceleader' => $replaceleader ? 1 : 0,
            'successorid' => $successorid,
            'status' => 'pending',
            'statusinfo' => null,
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $move->id = $DB->insert_record('selfselectadvanced_move', $move);

        // Store the initial per-rule verdicts for the manager UI.
        $verdicts = $this->validate_set([(int) $move->id]);
        $move->statusinfo = json_encode($verdicts->permove[(int) $move->id] ?? []);
        $DB->set_field('selfselectadvanced_move', 'statusinfo', $move->statusinfo, ['id' => $move->id]);

        \mod_selfselectadvanced\event\move_staged::create([
            'objectid' => $move->id,
            'context' => $this->activity->context(),
            'relateduserid' => $userid,
            'other' => [
                'sourcegroupid' => $sourcegroupid,
                'targetgroupid' => $targetgroupid,
            ],
        ])->trigger();

        return $move;
    }

    /**
     * Jointly validate a set of pending moves against the net
     * post-state of every touched group (A4).
     *
     * @param int[] $moveids the selected pending moves
     * @return stdClass {valid: bool, permove: [moveid => [rule => {ok, reason, bypassed}]]}
     */
    public function validate_set(array $moveids): stdClass {
        global $DB;

        $resolver = $this->gatekeeper->resolver();
        $moves = $this->load_pending($moveids);

        // Net membership deltas per group: a user both added and removed
        // in the same group cancels out; additions only count when the
        // user does not already hold that state in the group.
        //
        // Each list is a per-group SET of userids, keyed by userid: a
        // set is a statement about PEOPLE, and counting move ROWS was
        // this engine's central arithmetic error. Two staged moves out
        // of one source for one student are one departure, not two, so
        // building the lists with [] appended duplicates and then
        // array_diff/array_intersect (which preserve them) subtracted
        // that student twice from every figure below - refusing
        // compliant sets on the L1 minimum ("Source keeps 0 confirmed
        // members" of a group that would keep 2) and, on the delta
        // side, waving a set past the L4 cap. Measured on both engines.
        $removals = [];
        $additions = [];
        foreach ($moves as $move) {
            $uid = (int) $move->userid;
            if ($move->sourcegroupid) {
                $removals[(int) $move->sourcegroupid][$uid] = $uid;
            }
            if ($move->targetgroupid) {
                // A park has no target, so it contributes a removal and
                // nothing else.
                $additions[(int) $move->targetgroupid][$uid] = $uid;
            }
        }
        $confirmedin = [];
        $seatsin = [];
        foreach (array_unique(array_merge(array_keys($removals), array_keys($additions))) as $gid) {
            $rows = $DB->get_records_select(
                'selfselectadvanced_member',
                'groupid = ? AND status IN (?, ?)',
                [$gid, groups::STATUS_CONFIRMED, groups::STATUS_INVITED],
                '',
                'userid, status'
            );
            $confirmedin[$gid] = [];
            $seatsin[$gid] = [];
            foreach ($rows as $row) {
                $seatsin[$gid][] = (int) $row->userid;
                if ($row->status === groups::STATUS_CONFIRMED) {
                    $confirmedin[$gid][] = (int) $row->userid;
                }
            }
        }
        // Both closures count PEOPLE. array_diff and array_intersect
        // preserve duplicates of their first argument, so every count
        // below is only a roster figure while $add and $rem are sets;
        // array_unique states that here rather than trusting the shape
        // built above to stay that way.
        $confirmedafter = function (int $gid) use ($additions, $removals, $confirmedin): int {
            $add = array_unique(array_diff($additions[$gid] ?? [], $removals[$gid] ?? []));
            $rem = array_unique(array_diff($removals[$gid] ?? [], $additions[$gid] ?? []));

            return count($confirmedin[$gid] ?? [])
                + count(array_diff($add, $confirmedin[$gid] ?? []))
                - count(array_intersect($rem, $confirmedin[$gid] ?? []));
        };
        $seatsafterfn = function (int $gid) use ($additions, $removals, $seatsin): int {
            $add = array_unique(array_diff($additions[$gid] ?? [], $removals[$gid] ?? []));
            $rem = array_unique(array_diff($removals[$gid] ?? [], $additions[$gid] ?? []));

            return count($seatsin[$gid] ?? [])
                + count(array_diff($add, $seatsin[$gid] ?? []))
                - count(array_intersect($rem, $seatsin[$gid] ?? []));
        };

        // L4 must hold JOINTLY across the set: two moves adding the
        // same user to different groups each look fine alone, so the
        // verdict uses the user's net delta over the whole set (the
        // L3 lead verdict already aggregates the same way). A move
        // into a group where the user is already confirmed gains
        // nothing, and a removal is only credited when the user is
        // still confirmed in the source on the FRESH roster — a
        // staged move can outlive the membership it was staged
        // against, and a stale loss credit would let the commit
        // exceed the cap behind a green verdict.
        //
        // The delta is over the SET and counts TEAMS ENTERED and TEAMS
        // LEFT, not move rows. Summing per move subtracted one source
        // once per move out of it, so two moves out of Alpha scored
        // -2 against +2 and a cap of ONE membership committed TWO
        // (measured, both engines: "Would belong to 1 of 1 groups" on
        // both verdicts, count_memberships=2 afterwards). Three
        // targets scored -3.
        //
        // A move whose student is ALREADY confirmed in the target is
        // scored 0/0 and refused by TGT below. It gains nothing by
        // definition, and crediting its source removal was the net -1
        // that made a membership-destroying commit look compliant to
        // the cap check - the mechanism joinrequests::do_accept()
        // described in a comment while guarding only itself.
        $enters = [];
        $leaves = [];
        foreach ($moves as $move) {
            $uid = (int) $move->userid;
            $tid = $move->targetgroupid ? (int) $move->targetgroupid : null;
            if ($tid !== null && in_array($uid, $confirmedin[$tid] ?? [], true)) {
                continue;
            }
            if ($tid !== null) {
                $enters[$uid][$tid] = true;
            }
            // A park has no target and is pure loss. The source is
            // KEYED, so one student leaving one team counts once
            // however many rows name that team.
            if ($move->sourcegroupid && in_array($uid, $confirmedin[(int) $move->sourcegroupid] ?? [], true)) {
                $leaves[$uid][(int) $move->sourcegroupid] = true;
            }
        }
        $membershipdeltas = [];
        foreach ($moves as $move) {
            $uid = (int) $move->userid;
            $membershipdeltas[$uid] = count($enters[$uid] ?? []) - count($leaves[$uid] ?? []);
        }

        // Leadership evolves WITHIN a set (apply runs in id order), in
        // both directions: a leader's move out hands the crown to the
        // successor it names, who may have been staged out of that
        // same team while still a plain member, and an incoming
        // makeleader move TAKES the crown from an incumbent whose own
        // move out is in the same set. SUCC must judge the leader
        // apply() will actually see - it re-reads leaderid per move -
        // so track each group's leader as earlier moves change it.
        // (A makeleader move can no longer crown somebody already
        // inside the target: TGT refuses that move outright.)
        $liveleader = [];
        $leaderatapply = [];
        foreach ($moves as $move) {
            if ($move->sourcegroupid) {
                $sourceid = (int) $move->sourcegroupid;
                if (!array_key_exists($sourceid, $liveleader)) {
                    $liveleader[$sourceid] = (int) groups::get($this->activity, $sourceid)->leaderid;
                }
                $leaderatapply[(int) $move->id] = $liveleader[$sourceid];
                if ($liveleader[$sourceid] === (int) $move->userid) {
                    $liveleader[$sourceid] = (int) $move->successorid;
                }
            }
            if ($move->makeleader && $move->targetgroupid) {
                $targetid = (int) $move->targetgroupid;
                if (!array_key_exists($targetid, $liveleader)) {
                    $liveleader[$targetid] = (int) groups::get($this->activity, $targetid)->leaderid;
                }
                $liveleader[$targetid] = (int) $move->userid;
            }
        }

        $result = (object) ['valid' => true, 'permove' => []];
        foreach ($moves as $move) {
            $moveid = (int) $move->id;
            $bypasses = $resolver->move_bypasses($moveid);
            $verdicts = [];

            // The move's target, resolved ONCE per move: several
            // verdicts below read it, and leaving the assignment inside
            // one of their blocks would let a move that skipped that
            // block judge itself against the PREVIOUS move's target.
            $targetid = $move->targetgroupid ? (int) $move->targetgroupid : null;

            // TGT (NEVER bypassable, and first so a refused set names
            // it): the student is already a confirmed member of the
            // target, so the move gains them nothing while its source
            // half still deletes a membership - "moved" to a team they
            // are in, and mailed to say so. A membership deleted in
            // exchange for nothing is not a repair any staff member
            // can authorise, so the bypass flag below is a literal
            // false exactly as SUCC's and LEADR's are, and no override
            // row can reopen it whatever rule codes it carries.
            //
            // It belongs HERE, in the engine, and not at a caller's
            // seam: staging and committing are separate acts, this
            // queue is chronological and up to MAX_COMMIT rows wide,
            // and commit_set() re-runs this validation on the roster
            // it reads INSIDE its own locks. A set staged against a
            // correct roster and committed a week later - after the
            // student joined the target by an invitation, a join
            // request or another manager's move - is refused on the
            // roster that exists at commit, with no staff error
            // anywhere in the story.
            if ($targetid !== null) {
                $verdicts['TGT'] = $this->verdict(
                    !in_array((int) $move->userid, $confirmedin[$targetid] ?? [], true),
                    false,
                    get_string('moveruleTGT', 'mod_selfselectadvanced')
                );
            }

            // L1 on the source group's net post-state.
            if ($move->sourcegroupid) {
                $sourceid = (int) $move->sourcegroupid;
                $after = $confirmedafter($sourceid);
                $min = $resolver->effective_minsize($sourceid)->value;
                $verdicts['L1'] = $this->verdict(
                    $after >= $min,
                    in_array('L1', $bypasses, true),
                    get_string('moveruleL1', 'mod_selfselectadvanced', (object) ['after' => $after, 'min' => $min])
                );
            }

            // L2 on the target group's net post-state (confirmed +
            // pending seats). A park has no target, so the target-side
            // rules do not apply to it at all: its per-move verdicts are
            // exactly L1, (SUCC when it moves the leader), source QUOTA
            // and L4.
            if ($targetid !== null) {
                $seatsafter = $seatsafterfn($targetid);
                $max = $resolver->effective_maxsize($targetid)->value;
                $verdicts['L2'] = $this->verdict(
                    $seatsafter <= $max,
                    in_array('L2', $bypasses, true),
                    get_string('moveruleL2', 'mod_selfselectadvanced', (object) [
                        'after' => $seatsafter,
                        'max' => $max,
                    ])
                );
            }

            // L4 for the moved user's net memberships across the SET.
            $membershipsafter = groups::count_memberships($this->activity, (int) $move->userid)
                + $membershipdeltas[(int) $move->userid];
            $capvalue = $resolver->effective_maxmembership((int) $move->userid)->value;
            $verdicts['L4'] = $this->verdict(
                $membershipsafter <= $capvalue,
                in_array('L4', $bypasses, true),
                get_string('moveruleL4', 'mod_selfselectadvanced', (object) [
                    'after' => $membershipsafter,
                    'max' => $capvalue,
                ])
            );

            // L3 when the move designates a leader (target) or a
            // successor (source). stage() refuses makeleader on a park,
            // so $targetid is never null here - the guard states it
            // rather than trusting a row written by an older release.
            if ($move->makeleader && $targetid !== null) {
                $verdicts['L3'] = $this->leadverdict((int) $move->userid, $moves, $bypasses);

                // Deliberate leadership change (not code-bypassable):
                // replacing an incumbent needs explicit consent unless
                // that incumbent is leaving the target in this same
                // set (a leader swap resolves itself).
                $incumbent = (int) groups::get($this->activity, $targetid)->leaderid;
                $incumbentleaves = false;
                foreach ($moves as $other) {
                    if ((int) $other->sourcegroupid === $targetid && (int) $other->userid === $incumbent) {
                        $incumbentleaves = true;
                    }
                }
                $consentok = !$incumbent
                    || $incumbent === (int) $move->userid
                    || $incumbentleaves
                    || !empty($move->replaceleader);
                $verdicts['LEADR'] = $this->verdict(
                    $consentok,
                    false,
                    get_string('moveruleleadr', 'mod_selfselectadvanced', $incumbent
                        ? fullname(\core_user::get_user($incumbent)) : '')
                );
            }
            if ($move->successorid) {
                $verdicts['L3S'] = $this->leadverdict((int) $move->successorid, $moves, $bypasses);
            }

            // SUCC (never bypassable): promoting a stale or absent
            // successor corrupts the group. Whenever the moved user
            // will lead the source AT APPLY TIME (including a crown
            // gained from an earlier move in this same set), a
            // successor must exist, still be a confirmed source
            // member on the fresh roster, and not be removed from it
            // by this same set — staged moves can outlive the roster
            // and the leadership they were staged against.
            if ($move->sourcegroupid && $leaderatapply[(int) $move->id] === (int) $move->userid) {
                $sourceid = (int) $move->sourcegroupid;
                $succid = (int) $move->successorid;
                $succok = $succid
                    && in_array($succid, $confirmedin[$sourceid] ?? [], true)
                    && !in_array($succid, $removals[$sourceid] ?? [], true);
                $verdicts['SUCC'] = $this->verdict(
                    $succok,
                    false,
                    get_string('moveruleSUCC', 'mod_selfselectadvanced')
                );
            }

            // Quota on both groups' net post-state rosters. Exemption is
            // a PER-GROUP property (override resolver, gatekeeper.php
            // check_composition_feasibility()): each group passes when
            // it complies OR is exempt, and the set passes when both
            // do. Conjoining the two compliances first and only then
            // OR-ing one set-level exemption over the pair refused
            // legitimate moves whenever exactly one side was exempt.
            // Exemption is tested first, so an exempt group costs no
            // evaluation at all.
            // A park adds nobody to any team, so the target half of the
            // joint quota verdict is vacuously satisfied.
            $targetok = true;
            if ($targetid !== null) {
                $targetok = $resolver->is_quota_exempt($targetid)->enabled
                    || $this->quota_after($targetid, $additions[$targetid] ?? [], $removals[$targetid] ?? []);
            }
            $sourceok = true;
            if ($move->sourcegroupid) {
                $sourceid = (int) $move->sourcegroupid;
                $sourceok = $resolver->is_quota_exempt($sourceid)->enabled
                    || $this->quota_after($sourceid, $additions[$sourceid] ?? [], $removals[$sourceid] ?? []);
            }
            $verdicts['QUOTA'] = $this->verdict(
                $targetok && $sourceok,
                in_array('QUOTA', $bypasses, true),
                get_string('moveruleQUOTA', 'mod_selfselectadvanced')
            );

            $result->permove[$moveid] = $verdicts;
            foreach ($verdicts as $verdict) {
                if (!$verdict['ok'] && !$verdict['bypassed']) {
                    $result->valid = false;
                }
            }
        }

        return $result;
    }

    /**
     * The ordered lock resources a commit of these moves needs: the
     * activity lock (activity-wide L3/L4 counts and the move table)
     * followed by every touched group, ascending id (T-02 lock order).
     * Source and target group ids of a staged move are immutable once
     * staged - nothing in the plugin ever updates them - so computing
     * this from a pre-lock read is sound.
     *
     * Both ends are null-guarded. A placement of a groupless student
     * has no source, and a future park has no target; without the
     * target guard every such row would cast NULL to 0 and take a
     * site-wide group:0 lock shared by every activity on the site.
     *
     * @param int $activityid the activity
     * @param stdClass[] $moves pending move rows
     * @return string[] lock resources in acquisition order
     */
    public static function lock_resources_for(int $activityid, array $moves): array {
        $groupids = [];
        foreach ($moves as $move) {
            if ($move->sourcegroupid) {
                $groupids[] = (int) $move->sourcegroupid;
            }
            if ($move->targetgroupid) {
                $groupids[] = (int) $move->targetgroupid;
            }
        }
        $groupids = array_unique($groupids);
        sort($groupids, SORT_NUMERIC);

        $resources = ['activity:' . $activityid];
        foreach ($groupids as $gid) {
            $resources[] = 'group:' . $gid;
        }

        return $resources;
    }

    /**
     * Commit a selected set atomically: all moves apply, or none.
     *
     * @param int[] $moveids the selected pending moves
     * @param int $actorid the acting manager
     * @param bool $callerholdslocks true when the caller already holds
     *        this set's activity and group locks (the join-accept path)
     * @param array|null $deferrednotifications when supplied, the
     *        engine's notifications are appended to it instead of being
     *        sent, so the caller flushes them after ITS commit and
     *        release
     * @param array|null $deferredsyncgroupids when supplied, the plugin
     *        group ids whose mirrored course group needs converging are
     *        appended to it instead of being synced, so the caller runs
     *        the sync after ITS commit and release (T-16; the same
     *        hand-back pattern $deferrednotifications uses)
     * @param string $overridereason why the staff member is overriding
     *        the rules this set bypasses; required whenever any verdict
     *        commits bypassed (decision 6), ignored otherwise
     * @param array|null $deferredoverrideevents when supplied, the
     *        move_rules_overridden payloads are appended to it instead
     *        of being triggered, so the caller fires them after ITS
     *        commit and lock release (the join-accept path)
     * @return int number of committed moves
     * @throws \moodle_exception when the joint validation refuses, when
     *         more than self::MAX_COMMIT ids are selected, or when a
     *         bypassed set carries no reason
     */
    public function commit_set(
        array $moveids,
        int $actorid,
        bool $callerholdslocks = false,
        ?array &$deferrednotifications = null,
        ?array &$deferredsyncgroupids = null,
        string $overridereason = '',
        ?array &$deferredoverrideevents = null
    ): int {
        global $DB;

        // Bounded before anything is read: the commit holds the
        // activity lock plus one per touched group, so the size of the
        // selection is the size of the hold (D6-8).
        if (count($moveids) > self::MAX_COMMIT) {
            throw new \moodle_exception(
                'errmovetoomanyselected',
                'mod_selfselectadvanced',
                '',
                self::MAX_COMMIT
            );
        }

        // One flag, two uses: whether these notifications are ours to
        // send, and whether reactivating parked overrides is our job. A
        // caller that passes an array owns both, because its own
        // transaction and locks are still open when we return.
        $defersends = $deferrednotifications !== null;

        // The activity lock alone never excluded the leader/guide
        // paths, which serialise on group:{id} - so a manager commit
        // could overbook L2 against invitations::send, and a freeze
        // landing mid-commit left the plugin roster, the core group and
        // the snapshot disagreeing (T-02 R1). So the hold for a commit
        // is the activity lock plus one per touched group, activity
        // first and then groups in ascending id: the one global order.
        //
        // CORRECTED 2026-08-02 (audit O-5). This used to say "every
        // touched group is locked too" flatly, which is not what this
        // line does: on the $callerholdslocks path it takes NOTHING.
        // The hold is the same either way, but WHO takes it is not, and
        // stating it as an unconditional act of this method hid the
        // obligation the other path carries. Precisely:
        //
        // WITH $callerholdslocks FALSE this method acquires
        // lock_resources_for() over the pending rows of $moveids and
        // releases them at its finally.
        //
        // WITH $callerholdslocks TRUE the CALLER must already hold at
        // least that set, taken in the same order, and must still hold
        // it when this returns - because its own transaction is still
        // open around ours. joinrequests::do_accept() is the one
        // production caller: it takes activity + source + target for
        // the single move it is about to commit, which is exactly
        // lock_resources_for() over that move, and it takes them BEFORE
        // opening its transaction, so releasing at our finally would
        // have opened the very window T-02 R1c closes.
        //
        // The obligation is checked rather than trusted: a caller that
        // claims the locks while holding none is a defect that would
        // otherwise commit silently unserialised.
        if ($callerholdslocks && locks::held_count() === 0) {
            debugging(
                'commit_set() was told the caller holds the move locks, and no lock is held',
                DEBUG_DEVELOPER
            );
        }
        $locks = $callerholdslocks
            ? []
            : locks::acquire_all(self::lock_resources_for($this->activity->id(), $this->load_pending($moveids)));
        $notifications = [];
        $syncgroupids = [];
        $overriddenevents = [];
        try {
            $transaction = $DB->start_delegated_transaction();

            $verdicts = $this->validate_set($moveids);
            if (!$verdicts->valid) {
                // Name the first offending move and rule, so the
                // refusal tells the manager where to look.
                $failuser = '';
                $failrule = '';
                foreach ($verdicts->permove as $failmoveid => $moveverdicts) {
                    foreach ($moveverdicts as $rulecode => $verdict) {
                        if (!$verdict['ok'] && !$verdict['bypassed']) {
                            $failrow = $DB->get_record('selfselectadvanced_move', ['id' => $failmoveid]);
                            $failuser = $failrow ? fullname(\core_user::get_user((int) $failrow->userid)) : '?';
                            $failrule = $rulecode;
                            break 2;
                        }
                    }
                }
                throw new \moodle_exception('errmovesetinvalid', 'mod_selfselectadvanced', '', (object) [
                    'user' => $failuser,
                    'rule' => $failrule,
                ]);
            }
            // Decision 6: an override commits only with a typed reason,
            // enforced at the service seam so no caller can slip a
            // silent bypass through.
            $bypassedbymove = [];
            foreach ($verdicts->permove as $vmoveid => $moveverdicts) {
                $codes = array_keys(array_filter($moveverdicts, static fn($v) => !empty($v['bypassed'])));
                if ($codes) {
                    $bypassedbymove[(int) $vmoveid] = $codes;
                }
            }
            if ($bypassedbymove && trim($overridereason) === '') {
                throw new \moodle_exception('errmoveoverridereasonrequired', 'mod_selfselectadvanced');
            }

            $moves = $this->load_pending($moveids);

            // Conflict of interest again, against the rows re-read
            // INSIDE the lock: staging and committing are separate
            // acts, and the roster can change between them - the actor
            // may have joined a touched team since they staged. Both
            // sides are guarded and both are optional (a park carries a
            // null targetgroupid; a placement carries a null source),
            // which matters because groups::get() is MUST_EXIST and
            // (int) null would ask it for group 0. This only reads and
            // throws: nothing new is written, sent or fired inside the
            // lock (house rule 1).
            //
            // The actor's authority is constant for the whole call, so
            // it is asked ONCE, outside the loop. Reading the two group
            // rows first and deciding afterwards would cost up to
            // 2 x MAX_COMMIT extra queries on every commit a manager
            // makes and on every leader-accepted join request - a
            // per-row cost for a per-call answer (house rule 3).
            if ($this->coi_applies($actorid)) {
                foreach ($moves as $move) {
                    if ($move->targetgroupid) {
                        $this->require_uninvolved_narrow(
                            groups::get($this->activity, (int) $move->targetgroupid),
                            $actorid
                        );
                    }
                    if ($move->sourcegroupid) {
                        $this->require_uninvolved_narrow(
                            groups::get($this->activity, (int) $move->sourcegroupid),
                            $actorid
                        );
                    }
                }
            }

            $now = time();
            $leaderchanges = [];
            foreach ($moves as $move) {
                $moveid = (int) $move->id;
                $leaderchanges = array_merge(
                    $leaderchanges,
                    $this->apply($move, $actorid, $now, $notifications, $syncgroupids)
                );
                $update = (object) [
                    'id' => $move->id,
                    'status' => 'committed',
                    'statusinfo' => json_encode($verdicts->permove[$moveid]),
                    'usermodified' => $actorid,
                    'timemodified' => $now,
                    'timecommitted' => $now,
                ];
                if (isset($bypassedbymove[$moveid])) {
                    // Schema-neutral reason persistence: the column
                    // already carries "what the decider said" on the
                    // join-request path (db/install.xml).
                    $update->responsenote = trim($overridereason);
                }
                $DB->update_record('selfselectadvanced_move', $update);
                \mod_selfselectadvanced\event\move_committed::create([
                    'objectid' => $moveid,
                    'context' => $this->activity->context(),
                    'relateduserid' => (int) $move->userid,
                    'other' => [
                        'sourcegroupid' => $move->sourcegroupid ? (int) $move->sourcegroupid : null,
                        'targetgroupid' => $move->targetgroupid ? (int) $move->targetgroupid : null,
                        // Always present, so the event is
                        // self-describing: an empty array is a clean
                        // commit (D6-6a).
                        'bypassedrules' => $bypassedbymove[$moveid] ?? [],
                    ],
                ])->trigger();

                if (isset($bypassedbymove[$moveid])) {
                    // Collected, never triggered here: a new event must
                    // not fire inside a lock or an open transaction
                    // (requirement 2). The override row id is read now,
                    // while the transaction still sees it.
                    $overriderow = \mod_selfselectadvanced\local\override\store::get(
                        $this->activity,
                        'move',
                        $moveid
                    );
                    $overriddenevents[] = [
                        'objectid' => $moveid,
                        'relateduserid' => (int) $move->userid,
                        'other' => [
                            'sourcegroupid' => $move->sourcegroupid ? (int) $move->sourcegroupid : null,
                            'targetgroupid' => $move->targetgroupid ? (int) $move->targetgroupid : null,
                            'rules' => $bypassedbymove[$moveid],
                            'figures' => array_map(
                                static fn($code) => (string) $verdicts->permove[$moveid][$code]['reason'],
                                $bypassedbymove[$moveid]
                            ),
                            'reason' => trim($overridereason),
                            'overrideid' => $overriderow ? (int) $overriderow->id : null,
                            'kind' => $move->targetgroupid ? 'move' : 'park',
                        ],
                    ];
                }
            }

            // Leadership changed by a move must reach the same audit
            // trail as succession, or the manager path is invisible to
            // anyone asking who led a group when.
            foreach ($leaderchanges as $change) {
                \mod_selfselectadvanced\event\leadership_transferred::create([
                    'objectid' => $change->groupid,
                    'context' => $this->activity->context(),
                    'relateduserid' => $change->to,
                    'other' => [
                        'fromuserid' => $change->from,
                        'pluginuid' => groups::get($this->activity, $change->groupid)->pluginuid,
                        'type' => $change->type,
                    ],
                ])->trigger();
            }

            // A move can exhaust the moved user's membership capacity:
            // their rival pending invitations auto-decline here exactly
            // as they do on an invitation accept.
            $invitations = new invitations($this->activity, $this->gatekeeper);
            $cascaded = [];
            foreach (array_unique(array_map(static fn($m) => (int) $m->userid, $moves)) as $moveduser) {
                $rows = $invitations->cascade_at_cap($moveduser);
                if ($rows) {
                    $cascaded[$moveduser] = $rows;
                }
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Not tidying: validate_set's errmovesetinvalid throws from
            // INSIDE this transaction, so an invalid set used to leave
            // a dangling delegated transaction.
            //
            // UNCONDITIONAL since 1.20 wave 3D. It was gated on an
            // $outermost flag read from $DB->is_transaction_started(),
            // which was claimed to "keep this correct on the nested
            // join-accept path". It did the opposite, and this is the
            // one method in the plugin with a real nested production
            // caller, so the claim was checkable. joinrequests
            // ::do_accept() holds its own transaction T1 and calls us
            // with $callerholdslocks; ours is T2. Skipping OUR rollback
            // leaves T2 undisposed on top of $DB's stack, so when
            // do_accept catches and calls T1->rollback(),
            // rollback_delegated_transaction() finds
            // $transaction !== end($this->transactions), takes its
            // "better just rethrow" branch and NEVER issues the
            // physical ROLLBACK - the transaction stays open, and
            // joinrequests::respond() catches the exception, so nothing
            // aborts the request either. Rolling T2 back pops it and
            // sets force_rollback, T1 is then the top of the stack, and
            // do_accept's rollback really does roll the database back.
            // That is Moodle's documented cascade: "If any part of the
            // transaction rolls back then the whole thing is rolled
            // back."
            //
            // A $callerholdstransaction flag in the shape of
            // $callerholdslocks was considered and rejected: unlike the
            // locks, there is no different ACTION for the nested case -
            // both cases must roll their own frame back - so the flag
            // would encode a distinction that does not exist.
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            locks::release_all($locks);
        }

        // Post-commit: the promoted source successors (they gained a
        // group silently before this), then the cascade signals, then
        // the per-move notices - collected, in that order, so the whole
        // set travels outside the transaction AND outside the locks.
        foreach ($leaderchanges as $change) {
            if ($change->type !== 'movesuccession') {
                continue;
            }
            $sourcegroup = groups::get($this->activity, $change->groupid);
            $notifications[] = notifier::intent(
                'movecommitted',
                $change->to,
                'msgleaderpromotedsubject',
                'msgleaderpromotedbody',
                (object) [
                    'group' => format_string($sourcegroup->name),
                    'activity' => $this->activity->name(),
                ],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $this->activity->cm()->id,
                    'g' => $change->groupid,
                ]),
                format_string($sourcegroup->name)
            );
        }
        foreach ($cascaded as $moveduser => $rows) {
            $notifications = array_merge(
                $notifications,
                $invitations->cascade_intents($rows, (int) $moveduser)
            );
        }
        foreach ($moves as $move) {
            if (!$move->targetgroupid) {
                // A park has no destination to name, so the "you were
                // moved to X" pair would format an empty team. The
                // student is told what happened and where to look.
                $source = groups::get($this->activity, (int) $move->sourcegroupid);
                $notifications[] = notifier::intent(
                    'movecommitted',
                    (int) $move->userid,
                    'msgremovedsubject',
                    'msgremovedbody',
                    (object) [
                        'group' => format_string($source->name),
                        'activity' => $this->activity->name(),
                    ],
                    new \moodle_url('/mod/selfselectadvanced/view.php', [
                        'id' => $this->activity->cm()->id,
                    ]),
                    $this->activity->name()
                );
                continue;
            }
            $target = groups::get($this->activity, (int) $move->targetgroupid);
            $notifications[] = notifier::intent(
                'movecommitted',
                (int) $move->userid,
                'msgmovedsubject',
                'msgmovedbody',
                (object) ['group' => format_string($target->name)],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $this->activity->cm()->id,
                    'g' => $target->id,
                ]),
                format_string($target->name)
            );
        }

        // The override record travels with the notifications, and for
        // the same reason: after allow_commit() AND after the locks
        // released (requirement 2, strict rule for every NEW event).
        if ($deferredoverrideevents !== null) {
            // Nested inside a caller's locks/transaction (join accept):
            // the caller fires after ITS commit and lock release.
            $deferredoverrideevents = array_merge($deferredoverrideevents, $overriddenevents);
        } else {
            foreach ($overriddenevents as $overridden) {
                \mod_selfselectadvanced\event\move_rules_overridden::create(
                    $overridden + ['context' => $this->activity->context()]
                )->trigger();
            }
        }

        if ($defersends) {
            // Nested inside a caller's transaction
            // (joinrequests::do_accept): the caller sends after ITS
            // commit and lock release. The mirror sync travels with
            // them for the same reason - on that path our allow_commit()
            // is nested and locks::release_all([]) is a no-op, so the
            // caller's transaction and its activity:/group: locks are
            // still open here. Syncing would run core group API writes
            // inside a lock and a transaction (and in fact just defer,
            // leaving convergence to the queued adhoc).
            $deferrednotifications = array_merge($deferrednotifications, $notifications);
            if ($deferredsyncgroupids !== null) {
                $deferredsyncgroupids = array_merge($deferredsyncgroupids, array_unique($syncgroupids));
            }
        } else {
            notifier::send_all($this->activity, $notifications);
            foreach (array_unique($syncgroupids) as $gid) {
                freeze::sync_core_group($this->activity, (int) $gid, $actorid);
            }
        }

        if (!$defersends) {
            // Outermost owner only: recheck_pending fires one
            // override_updated event per activated row. On the nested
            // join-accept path the caller's transaction and its
            // activity:/group: locks are still open, so it is handed
            // back and run there instead (cleared blockers activate
            // parked overrides at once, item 19).
            //
            // RESTRICTED to what this set actually moved: a commit is a
            // hot path, and re-pricing every pending row of a
            // 10,000-student activity to learn that none of them
            // changed is work nobody asked for (T-08).
            \mod_selfselectadvanced\local\override\store::recheck_pending(
                $this->activity,
                $actorid,
                [
                    'user' => array_map(static fn($move) => (int) $move->userid, $moves),
                    'group' => array_merge(
                        array_map(static fn($move) => (int) ($move->sourcegroupid ?? 0), $moves),
                        array_map(static fn($move) => (int) ($move->targetgroupid ?? 0), $moves)
                    ),
                ]
            );
        }

        return count($moves);
    }

    /**
     * Cancel a pending move.
     *
     * @param int $moveid the move
     * @param int $actorid the acting manager
     */
    public function cancel(int $moveid, int $actorid): void {
        global $DB;

        // Under the SAME lock commit_set() takes, and re-read inside it.
        // update_record() matches on id alone, so a commit landing
        // between the read and the write was relabelled 'cancelled'
        // while its membership changes stood and its move_committed
        // event had already fired - leaving a committed move recorded
        // as cancelled and two contradictory events in the log.
        $lock = locks::acquire('activity:' . $this->activity->id());
        try {
            $move = $DB->get_record('selfselectadvanced_move', [
                'id' => $moveid,
                'activityid' => $this->activity->id(),
                'status' => 'pending',
            ], '*', MUST_EXIST);
            $DB->update_record('selfselectadvanced_move', (object) [
                'id' => $move->id,
                'status' => 'cancelled',
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);
            // Read here, deleted below. The move row is already
            // 'cancelled' when the lock drops, so no racing writer can
            // re-arm the bypass in between.
            $moveoverride = \mod_selfselectadvanced\local\override\store::get(
                $this->activity,
                'move',
                (int) $move->id
            );
        } finally {
            $lock->release();
        }

        // Outside the lock, deliberately, and this must not be
        // "simplified" back inside it (D6-6e): store::delete() acquires
        // override:{scope}:{targetid} - rank 5 in the one global order -
        // which ranks BEFORE activity: (rank 6), so calling it under
        // cancel()'s activity lock trips locks::check_order().
        //
        // What that trip is worth, stated accurately (this comment used
        // to claim "its debugging() is a PHPUnit failure", which is
        // false as written - see locks.php's own note): Moodle turns an
        // unconsumed debugging() into an E_USER_NOTICE, PHPUnit 11
        // reports it as a Notice rather than a Warning, and a run not
        // given --fail-on-notice still exits 0. That flag is in the
        // repository - .github/workflows/moodle-ci.yml passes
        // --fail-on-warning --fail-on-notice, as does the maintainer's
        // gate - so an inversion reddens a run anywhere the suite is
        // driven from this repo's configuration, not only on one
        // machine; in production debugging() is still a no-op below
        // DEBUG_DEVELOPER. The ordering constraint is real
        // regardless of what reports it, which is why the call stays
        // out here. store::delete() also opens its own transaction and
        // triggers override_deleted inside it, which requirement 2
        // forbids under a lock.
        if ($moveoverride) {
            \mod_selfselectadvanced\local\override\store::delete(
                $this->activity,
                (int) $moveoverride->id,
                $actorid
            );
        }

        \mod_selfselectadvanced\event\move_cancelled::create([
            'objectid' => (int) $move->id,
            'context' => $this->activity->context(),
            'relateduserid' => (int) $move->userid,
            'other' => [
                'sourcegroupid' => $move->sourcegroupid ? (int) $move->sourcegroupid : null,
                // Null, never 0: a cancelled park has no target, and
                // recording a phantom group 0 is a lie in the log.
                'targetgroupid' => $move->targetgroupid ? (int) $move->targetgroupid : null,
            ],
        ])->trigger();
    }

    /**
     * Apply one validated move inside the commit transaction.
     *
     * @param stdClass $move the move row
     * @param int $actorid the acting manager
     * @param int $now the commit time
     * @param array $notifications collected notifier intents, sent by
     *        the caller after the commit and the lock release
     * @param array $syncgroupids collects the plugin group ids whose
     *        mirrored course group needs converging, applied by the
     *        caller after the commit and the lock release
     * @return array leadership changes performed: {groupid, from, to, type}
     */
    private function apply(
        stdClass $move,
        int $actorid,
        int $now,
        array &$notifications,
        array &$syncgroupids
    ): array {
        global $DB;

        $changes = [];
        $userid = (int) $move->userid;

        if ($move->sourcegroupid) {
            $source = groups::get($this->activity, (int) $move->sourcegroupid);
            $row = $DB->get_record('selfselectadvanced_member', [
                'groupid' => $source->id,
                'userid' => $userid,
            ], '*', MUST_EXIST);
            $row->status = groups::STATUS_REMOVED;
            $row->isleader = 0;
            $row->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $row);

            // Leadership succession on the source, atomically (B3-checked).
            if ((int) $source->leaderid === $userid) {
                if (empty($move->successorid)) {
                    // SUCC validation makes this unreachable; a group
                    // must never be committed into leaderlessness.
                    throw new \coding_exception('Leader move applied without a successor.');
                }
                $DB->update_record('selfselectadvanced_group', (object) [
                    'id' => $source->id,
                    'leaderid' => (int) $move->successorid,
                    'usermodified' => $actorid,
                    'timemodified' => $now,
                ]);
                $DB->set_field('selfselectadvanced_member', 'isleader', 1, [
                    'groupid' => $source->id,
                    'userid' => (int) $move->successorid,
                ]);
                $changes[] = (object) [
                    'groupid' => (int) $source->id,
                    'from' => $userid,
                    'to' => (int) $move->successorid,
                    'type' => 'movesuccession',
                ];
            }
            $this->mirror_after_write((int) $source->id, $actorid, $syncgroupids);
        }

        // A park (null target) is the source half and nothing else: no
        // membership row is written anywhere, and the source-side hook
        // above has already appended the snapshot and requested the
        // mirror sync. Anchored on the row's own target, not on the
        // callee, so a later change of hook does not silently reopen it.
        if (!$move->targetgroupid) {
            return $changes;
        }

        $target = groups::get($this->activity, (int) $move->targetgroupid);
        $existing = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $target->id,
            'userid' => $userid,
        ]);
        if ($existing) {
            $existing->status = groups::STATUS_CONFIRMED;
            $existing->timeresponded = $now;
            $existing->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $existing);
        } else {
            $DB->insert_record('selfselectadvanced_member', (object) [
                'groupid' => $target->id,
                'userid' => $userid,
                'status' => groups::STATUS_CONFIRMED,
                'isleader' => 0,
                'invitedby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        if ($move->makeleader) {
            $demoted = (int) $target->leaderid;
            $DB->set_field('selfselectadvanced_member', 'isleader', 0, [
                'groupid' => $target->id,
                'userid' => $demoted,
            ]);
            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $target->id,
                'leaderid' => $userid,
                'usermodified' => $actorid,
                'timemodified' => $now,
            ]);
            $DB->set_field('selfselectadvanced_member', 'isleader', 1, [
                'groupid' => $target->id,
                'userid' => $userid,
            ]);
            if ($demoted !== $userid) {
                $changes[] = (object) [
                    'groupid' => (int) $target->id,
                    'from' => $demoted,
                    'to' => $userid,
                    'type' => 'movemakeleader',
                ];
            }
            if ($demoted && $demoted !== $userid) {
                // Collected, not sent: apply() runs inside the commit
                // transaction under the activity and group locks, and
                // core buffers a message to the outermost commit -
                // which is still inside the lock (T-02 R6).
                $notifications[] = notifier::intent(
                    'movecommitted',
                    $demoted,
                    'msgleaderreplacedsubject',
                    'msgleaderreplacedbody',
                    (object) [
                        'group' => format_string($target->name),
                        'pluginuid' => $target->pluginuid,
                        'activity' => $this->activity->name(),
                    ],
                    new \moodle_url('/mod/selfselectadvanced/group.php', [
                        'id' => $this->activity->cm()->id,
                        'g' => (int) $target->id,
                    ]),
                    format_string($target->name)
                );
            }
        }
        $this->mirror_after_write((int) $target->id, $actorid, $syncgroupids);

        return $changes;
    }

    /**
     * The in-transaction half of the mirror contract for one group
     * whose roster this commit just changed.
     *
     * The snapshot stays INSIDE the commit transaction - it is the
     * unfreeze restore point, and the part of the old single-delta hook
     * worth keeping. The core-group write does not: it is queued
     * (request_sync) and applied by the caller after the commit and the
     * lock release.
     *
     * @param int $groupid the plugin group just written
     * @param int $actorid the acting manager
     * @param array $syncgroupids collects group ids needing a post-release sync
     */
    private function mirror_after_write(int $groupid, int $actorid, array &$syncgroupids): void {
        $grouprow = groups::get($this->activity, $groupid);
        if ($grouprow->state === state::FROZEN) {
            freeze::append_snapshot($grouprow, $actorid);
        }
        freeze::request_sync($this->activity, $grouprow);
        if (!empty($grouprow->coregroupid)) {
            $syncgroupids[] = (int) $grouprow->id;
        }
    }

    /**
     * The conflict-of-interest guard, applied to the NARROW authority
     * this release introduces and to nothing that could reach this
     * engine before it.
     *
     * The rule (1.16 D) is that staff whose authority is a narrow
     * plugin capability may not act on a team they are part of, guide
     * or are the successor guide of. :managecomposition is what widened
     * these seams beyond :manage, so :managecomposition is what the
     * rule restrains - exactly the shape
     * tickets::require_uninvolved_override() uses for :coordinate, and
     * for the same stated reason: adding a role to a site must never
     * quietly take authority away from somebody who already had it.
     *
     * That scoping is LOAD-BEARING, not caution. This engine's other
     * caller is joinrequests::do_accept() (joinrequests.php:562 stages
     * and :617 commits), whose actor is whoever
     * joinrequests::require_decider() admitted - and that returns FIRST
     * for the TARGET TEAM'S OWN LEADER (joinrequests.php:772-775). A
     * leader is by definition a confirmed member of the team they are
     * admitting somebody to, and require_uninvolved() exempts only
     * :manage holders. An unconditional probe here would therefore
     * refuse every student-led join acceptance on the site with
     * "you cannot act on this team because you are a member of it".
     *
     * A holder of :manage needs no test of their own: the probe is
     * still made and require_uninvolved() returns immediately for them.
     *
     * @param stdClass|null $group the team to probe, or null when this
     *        side of the move has none (a park has no target; placing a
     *        groupless student has no source)
     * @param int $actorid the acting staff member
     * @throws \moodle_exception refusalcoiinvolved when involved
     */
    private function require_uninvolved_narrow(?stdClass $group, int $actorid): void {
        if ($group === null || !$this->coi_applies($actorid)) {
            return;
        }

        tickets::require_uninvolved($this->activity, $group, $actorid);
    }

    /**
     * Whether the conflict-of-interest rule restrains THIS actor here.
     *
     * One answer for a whole call, so callers can ask it once instead
     * of once per move: an actor's capabilities do not change between
     * two rows of the same commit. Exactly the two conditions
     * require_uninvolved_narrow() applied inline before - a :manage
     * holder is exempt (tickets::require_uninvolved() returns at once
     * for them anyway), and an actor without :managecomposition is not
     * restrained at all, which is what keeps a student leader's join
     * acceptance working.
     *
     * @param int $actorid the acting staff member
     * @return bool true when the actor's authority here is the narrow one
     */
    private function coi_applies(int $actorid): bool {
        $context = $this->activity->context();

        return !has_capability('mod/selfselectadvanced:manage', $context, $actorid)
            && has_capability('mod/selfselectadvanced:managecomposition', $context, $actorid);
    }

    /**
     * Load pending moves of this activity by id, refusing foreign rows.
     *
     * @param int[] $moveids the ids
     * @return stdClass[] move rows
     */
    private function load_pending(array $moveids): array {
        global $DB;

        if (!$moveids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $moveids));
        $params[] = $this->activity->id();

        return array_values($DB->get_records_select(
            'selfselectadvanced_move',
            "id $insql AND activityid = ? AND status = 'pending'",
            $params,
            'id ASC'
        ));
    }

    /**
     * An L3 verdict for a user who would become a leader.
     *
     * @param int $userid the prospective leader
     * @param stdClass[] $moves the selected set (leaderships gained count)
     * @param string[] $bypasses rule codes bypassed for this move
     * @return array verdict
     */
    private function leadverdict(int $userid, array $moves, array $bypasses): array {
        $resolver = $this->gatekeeper->resolver();
        $gained = 0;
        $released = 0;
        foreach ($moves as $other) {
            if ($other->makeleader && (int) $other->userid === $userid) {
                $gained++;
            }
            if ((int) ($other->successorid ?? 0) === $userid && $other->sourcegroupid) {
                $gained++;
            }
            // A leader moving out of their group releases that slot to
            // the designated successor within the same set.
            if ($other->sourcegroupid && (int) $other->userid === $userid) {
                $source = groups::get($this->activity, (int) $other->sourcegroupid);
                if ((int) $source->leaderid === $userid) {
                    $released++;
                }
            }
        }
        $after = groups::count_leading($this->activity, $userid) + $gained - $released;
        $max = $resolver->effective_maxlead($userid)->value;

        return $this->verdict(
            $after <= $max,
            in_array('L3', $bypasses, true),
            get_string('moveruleL3', 'mod_selfselectadvanced', (object) ['after' => $after, 'max' => $max])
        );
    }

    /**
     * Quota compliance of a group's net post-state roster.
     *
     * @param int $groupid the group
     * @param int[] $add userids joining
     * @param int[] $remove userids leaving
     * @return bool compliant
     */
    private function quota_after(int $groupid, array $add, array $remove): bool {
        global $DB;

        // The full evaluator — counting rules AND the seat plan — on
        // the virtual roster; a hand-rolled rules-only loop here once
        // let managers commit moves that broke slot compliance of
        // firm groups behind a green QUOTA verdict.
        $current = $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$groupid, groups::STATUS_CONFIRMED]
        );
        $virtual = array_diff(array_merge(array_map('intval', $current), $add), $remove);

        return \mod_selfselectadvanced\local\quota\evaluator::compliant_for_members($this->activity, $virtual);
    }

    /**
     * Build one rule verdict.
     *
     * @param bool $ok rule satisfied on the net post-state
     * @param bool $bypassed rule bypassed by a move-scope override
     * @param string $reason localised figures
     * @return array verdict
     */
    private function verdict(bool $ok, bool $bypassed, string $reason): array {
        return ['ok' => $ok, 'bypassed' => $bypassed && !$ok, 'reason' => $reason];
    }
}
