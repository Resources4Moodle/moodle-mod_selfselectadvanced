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
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\quota\evaluator;
use mod_selfselectadvanced\local\quota\slots;
use stdClass;

/**
 * Whether one person fits one team, and which seat they would take
 * (maintainer's selection rules, 1.19.1).
 *
 * The rules this answers, in the maintainer's words:
 *
 *  - "student who does not fit the logic of group formation should be
 *     listed with caution that the student will not fit the
 *     requirements" - so a misfit is SHOWN, with the reason, never
 *     silently dropped. A team may still have a good reason to want
 *     somebody the rules would refuse today, and hiding them denies the
 *     leader the choice as well as the explanation. (Contrast the guide
 *     rule R1, where a FULL guide is genuinely removed from the list:
 *     a full guide is not a judgement about the student, and there is
 *     nothing the student could say that would create capacity.)
 *  - "students who are trying to join a group that has the particular
 *     seat that the student will fit if filled" - so where a seat plan
 *     exists, say WHICH seat the person would take.
 *
 * The seat answer is worked out by evaluating the plan twice: once
 * against the team as it stands, once with the candidate added. If the
 * total number of filled seats rises, the seat they would take is the
 * one the canonical assignment gives them. That reuses the seat engine
 * the compliance report already uses, rather than a second, subtly
 * different one - which is the whole point of doing it this way.
 *
 * Two entry points, because the two callers have opposite shapes:
 *
 *  - {@see self::for_person()} judges ONE person against ONE team
 *    through the real admission gate, for the leader's answer table and
 *    the student's own request page. It is authoritative;
 *  - {@see self::for_groups()} judges one person against up to fifty
 *    teams for the join picker, on every keystroke. It prefetches the
 *    rules, the seat plan, every roster and everyone's attributes in
 *    four queries and then judges each team with none.
 *
 * Both funnel the composition verdict through
 * self::composition_verdict(), which funnels through
 * evaluator::feasibility_from_data(), so the advisory caution in the
 * picker can never contradict the refusal at the gate.
 *
 * WHAT A PENDING INVITATION MAY AND MAY NOT DO (maintainer decision
 * 53: "Invitation should not hard-block. When a leader decides that
 * another member will do the job, why not remove the user"). The
 * admission gate counts confirmed PLUS invited members, because an
 * invitation reserves its seat (L2). For a counting-rule MAXIMUM that
 * basis used to produce a hard refusal, which told a leader that a
 * request was impossible when one click - withdraw the invitation -
 * would have made it possible. Since 1.20.5 the two questions are
 * asked separately over the same engine:
 *
 *  - only CONFIRMED members (plus the candidate) can put a maximum
 *    over in a way nothing the leader does today could repair, so only
 *    they produce a HARD refusal;
 *  - a maximum that is over only once PENDING invitations are counted
 *    is a WARNING, carried in {@see $answer->warnings}, naming what is
 *    confirmed, what is pending and what the request would make.
 *
 * WHAT ACCEPTING WOULD DO, ASKED HERE (maintainer decision 53, the
 * photographed contradiction). The Fit column said "Meets this team's
 * requirements" and the very same row's Accept button then failed with
 * the move engine's QUOTA verdict. The two were different questions:
 * this class asked the ADMISSION gate, the engine asked whether the
 * post-move rosters COMPLY. {@see self::accept_composition_refusal()}
 * is the one projection of that engine verdict, and it is consulted
 * BOTH here - so a request that cannot be accepted is never labelled a
 * fit - and by joinrequests::first_reason(), which words the engine's
 * own refusal with it. The engine keeps the last word; what this
 * removes is the possibility of the two SAYING different things.
 *
 * WHAT for_groups() COSTS, AND WHO PAYS IT (corrected 2026-07-31; this
 * docblock used to say the picker "costs about what the unannotated
 * picker cost", which stopped being true when the exact composition
 * engine landed). The four queries are still four queries. The CPU is
 * the point now: for_groups() runs an exact allocator solve per team,
 * up to fifty of them per keystroke, and the search is exponential in
 * the seat plan.
 *
 * The payers are search_groups.php (limit 50, called per keystroke by
 * amd/src/groupselector.js) and joinrequest.php - NOT flagged.php,
 * which allocator.php's own commentary used to name.
 *
 * Measured on this box, PHP 8.4, on a 12-member team against a
 * six-row / two-seat plan - an ordinary shape, not a pathological one:
 * one seat-plan evaluation costs about 2.9 ms on benign random
 * templates and up to 73 ms on adversarial ones. Until 1.20 for_groups
 * ran THREE such solves per team where two answer the question, and
 * the third took input identical element-for-element to the first;
 * the three-solve total measured 2.7-3.1x one solve, so deleting the
 * duplicate takes a third of the composition CPU off every keystroke.
 * The wave-1 audit measured the whole call at 4571 ms per keystroke on
 * that shape, against 50 ms before the exact engine.
 *
 * Two solves per team is still the dominant cost of this page and it
 * is still unpaged at fifty. T-12 owns the budget; this note exists so
 * T-12 scopes it against the real number.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fit {
    /**
     * How one person stands against one team, through the real gate.
     *
     * When a live join request from this person to this team exists it
     * is the SUBJECT of the verdict, and the answer is what accepting
     * that request would do - including the team the request would take
     * them out of. The row is looked up when the caller does not hand
     * it over, so the leader panel on group.php and the "Asked of my
     * team" tab reach the identical verdict without either of them
     * having to know that the other exists.
     *
     * @param activity $activity the activity
     * @param stdClass $group the team
     * @param int $userid the person
     * @param stdClass|null $request the live join request when the caller already holds it;
     *        null to look one up
     * @return stdClass {fits: bool, caution: string, warnings: string[],
     *                  seat: string|null, seatno: int|null}
     */
    public static function for_person(
        activity $activity,
        stdClass $group,
        int $userid,
        ?stdClass $request = null
    ): stdClass {
        global $DB;

        $answer = (object) [
            'fits' => true,
            'caution' => '',
            'warnings' => [],
            'seat' => null,
            'seatno' => null,
        ];

        if ($request === null) {
            // At most one live request per person exists (the duplicate
            // guard in joinrequests::request()), so IGNORE_MULTIPLE is
            // a statement about a corrupt table, not about a choice.
            $request = $DB->get_record_select(
                'selfselectadvanced_move',
                'activityid = :activityid AND userid = :userid'
                    . ' AND targetgroupid = :targetgroupid AND status = :status',
                [
                    'activityid' => $activity->id(),
                    'userid' => $userid,
                    'targetgroupid' => (int) $group->id,
                    'status' => joinrequests::STATUS_REQUESTED,
                ],
                '*',
                IGNORE_MULTIPLE
            ) ?: null;
        }
        $sourcegroupid = $request !== null && $request->sourcegroupid ? (int) $request->sourcegroupid : null;

        // The same gate an invitation goes through, so the caution a
        // student reads is the refusal they would actually meet.
        $refusal = (new api($activity))->gatekeeper()->can_invite($group, $userid);
        $fits = $refusal === null;
        $caution = $refusal !== null ? $refusal->get_message() : '';

        if ($refusal !== null && $refusal->stringkey === 'refusalcompositionmax') {
            // Decision 53: re-ask the composition question with the
            // confirmed/pending split, through the shared verdict the
            // picker also uses. A maximum that only PENDING invitations
            // put over is a warning, not a wall.
            $verdict = self::composition_verdict_for_group($activity, $group, $userid);
            $fits = $verdict->fits;
            $caution = $verdict->caution;
            if ($verdict->warning !== '') {
                $answer->warnings[] = $verdict->warning;
            }
        }

        if (!$fits && $refusal !== null && $refusal->stringkey === 'refusalinviteecap' && $sourcegroupid !== null) {
            // A request that LEAVES a team costs the student no net
            // membership, and the move engine judges it on exactly that
            // net (moves::validate_set, verdict L4). The invitation
            // gate has no source to net against and so refuses a
            // student at their cap; carrying that refusal into a
            // request whose whole point is the swap made the Fit column
            // disagree with the Accept button in the other direction.
            //
            // BUT THE CAP IS NOT THE ONLY QUESTION. can_invite() returns
            // at its FIRST refusal, so a cap refusal means the seat and
            // composition questions were never asked at all. Declaring
            // fits=true here would answer them by assumption - the
            // vacuity defect this project refuses. So the cap refusal is
            // only SET ASIDE, and the questions it pre-empted are asked
            // now, through the same shared verdict everything else uses.
            //
            // THIS BLOCK ONCE ASSERTED THAT AND DID NOT DO IT. Until
            // 2026-08-05 it re-asked only the composition question while
            // this comment claimed the seat question too, so a student at
            // their cap requesting a FULL team was shown a green "fits"
            // and an Accept that could only throw. can_invite_all() now
            // returns every refusal, so the set-aside is a filter over
            // answers that were actually computed rather than a promise
            // that they were.
            // Re-evaluated here rather than at the top of the method:
            // this branch needs a cap refusal AND a source team, which is
            // rare, while for_person() itself runs once per row of the
            // join inbox. Asking every question for every row would pay a
            // seat scan and a composition solve the common path does not
            // need.
            $others = array_values(array_filter(
                (new api($activity))->gatekeeper()->can_invite_all($group, $userid),
                static fn(rules\refusal $r): bool => $r->stringkey !== 'refusalinviteecap'
            ));
            if ($others !== []) {
                $fits = false;
                $caution = $others[0]->get_message();
            } else {
                $verdict = self::composition_verdict_for_group($activity, $group, $userid);
                $fits = $verdict->fits;
                $caution = $verdict->caution;
                if ($verdict->warning !== '') {
                    $answer->warnings[] = $verdict->warning;
                }
            }
        }

        if ($fits && $request !== null) {
            // What ACCEPTING would do, asked here so the column and the
            // button cannot disagree.
            $blocked = self::accept_composition_refusal($activity, $group, $userid, $sourcegroupid);
            if ($blocked !== null) {
                $fits = false;
                $caution = $blocked;
            }
        }

        $answer->fits = $fits;
        $answer->caution = $caution;

        $seat = self::seat_taken($activity, $group, $userid);
        if ($seat !== null) {
            $answer->seat = $seat->label;
            $answer->seatno = $seat->slotno;
        }

        return $answer;
    }

    /**
     * Whether accepting this student into this team would leave either
     * team's composition in a state the move engine's QUOTA verdict
     * refuses.
     *
     * This is deliberately the same question, asked the same way, as
     * moves::quota_after(): the CONFIRMED roster each team would have,
     * judged by quota_ok_after(), with a per-group quota exemption
     * skipping the team it is set on. FORMING teams ask whether
     * compliance is still reachable; approved teams ask whether it is
     * already present. It is a projection and not the engine itself
     * because the engine can only answer for a move row that has been
     * STAGED, and the leader needs the answer while deciding whether to
     * stage one at all.
     *
     * Nothing here is advisory: whatever this refuses, the engine
     * refuses. joinrequests::first_reason() asks it when the engine's
     * QUOTA verdict is the one that failed, so the refusal a leader
     * meets is worded in terms of the TEAMS involved instead of "Quota
     * rules on both groups after the move" - a sentence that named two
     * groups where an extra-membership request has only ever had one.
     *
     * @param activity $activity the activity
     * @param stdClass $target the team being joined
     * @param int $userid the student
     * @param int|null $sourcegroupid the team the request would take them out of, or null
     * @return string|null null when both rosters would comply, else the reason
     */
    public static function accept_composition_refusal(
        activity $activity,
        stdClass $target,
        int $userid,
        ?int $sourcegroupid
    ): ?string {
        $resolver = new resolver($activity);

        if (!$resolver->is_quota_exempt((int) $target->id)->enabled) {
            if (!self::quota_ok_after($activity, $target, [$userid], [], $resolver)) {
                return get_string(
                    'refusaljoinquotatarget',
                    'mod_selfselectadvanced',
                    format_string($target->name)
                );
            }
        }

        if ($sourcegroupid !== null && !$resolver->is_quota_exempt($sourcegroupid)->enabled) {
            $source = groups::get($activity, $sourcegroupid);
            if (!self::quota_ok_after($activity, $source, [], [$userid], $resolver)) {
                return get_string(
                    'refusaljoinquotasource',
                    'mod_selfselectadvanced',
                    format_string($source->name)
                );
            }
        }

        return null;
    }

    /**
     * A team's confirmed roster with a move applied to it, exactly as
     * moves::quota_after() builds the virtual roster it judges.
     *
     * @param int $groupid the team
     * @param int[] $add user ids the move would put in
     * @param int[] $remove user ids the move would take out
     * @return int[] the resulting confirmed member ids
     */
    private static function confirmed_after(int $groupid, array $add, array $remove): array {
        global $DB;

        $current = array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$groupid, groups::STATUS_CONFIRMED]
        ));

        return array_values(array_diff(array_merge($current, $add), $remove));
    }

    /**
     * State-dependent quota predicate for accepting or committing a move.
     *
     * FORMING teams are still being assembled, so their post-move
     * roster must be reachable: no maximum is exceeded, and the proven
     * lower bound of further members needed for compliance fits in the
     * remaining seats. Approved teams are no longer being built toward
     * compliance; their post-move roster must already comply.
     *
     * @param activity $activity the activity
     * @param stdClass $group the team being judged
     * @param int[] $add user ids the move would put in
     * @param int[] $remove user ids the move would take out
     * @param resolver|null $resolver override resolver already in use, when available
     * @return bool true when the team's post-move composition is allowed
     */
    public static function quota_ok_after(
        activity $activity,
        stdClass $group,
        array $add,
        array $remove,
        ?resolver $resolver = null
    ): bool {
        global $DB;

        $after = self::confirmed_after((int) $group->id, $add, $remove);
        if ($group->state !== state::FORMING) {
            return evaluator::compliant_for_members($activity, $after);
        }

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $template = slots::get_all($activity);
        $attrs = manager::get_for_users($after);
        $feasibility = evaluator::feasibility_from_data($rules, $template, $after, $attrs);
        if ($feasibility->maxexceeded !== null) {
            return false;
        }
        $resolver = $resolver ?? new resolver($activity);
        $free = max(0, $resolver->effective_maxsize((int) $group->id)->value - $feasibility->seated);

        return $feasibility->missing <= $free;
    }

    /**
     * The composition verdict for one team, loading the four things it
     * needs. The per-team entry point; the picker holds the same four
     * for the whole page and calls composition_verdict() directly.
     *
     * @param activity $activity the activity
     * @param stdClass $group the team
     * @param int $userid the candidate
     * @return stdClass {fits, caution, warning}
     */
    private static function composition_verdict_for_group(
        activity $activity,
        stdClass $group,
        int $userid
    ): stdClass {
        global $DB;

        $confirmed = [];
        $invited = [];
        [$insql, $params] = $DB->get_in_or_equal(
            [groups::STATUS_CONFIRMED, groups::STATUS_INVITED],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['groupid'] = (int) $group->id;
        $rows = $DB->get_records_select(
            'selfselectadvanced_member',
            "groupid = :groupid AND status $insql",
            $params,
            '',
            'id, userid, status'
        );
        foreach ($rows as $row) {
            if ($row->status === groups::STATUS_CONFIRMED) {
                $confirmed[] = (int) $row->userid;
            } else {
                $invited[] = (int) $row->userid;
            }
        }

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $template = slots::get_all($activity);
        $attrs = manager::get_for_users(array_merge($confirmed, $invited, [$userid]));
        $maxsize = (new resolver($activity))->effective_maxsize((int) $group->id)->value;

        return self::composition_verdict($rules, $template, $confirmed, $invited, $userid, $attrs, $maxsize);
    }

    /**
     * Whether the composition admits this candidate, with the ruling of
     * maintainer decision 53 applied: a counting-rule MAXIMUM is a hard
     * refusal only when CONFIRMED members put it over, and a warning
     * when it takes pending invitations to do so.
     *
     * The reachability half is unchanged in the ordinary case - the
     * same feasibility bound, over the same confirmed-plus-invited
     * basis, that the gate has always used. On the WARNING path the
     * bound is taken over the confirmed basis instead, with the pending
     * invitations subtracted from the free seats they reserve: the
     * confirmed-plus-invited run stopped its rule scan at the exceeded
     * maximum, so its `missing` is a partial figure, and refusing a
     * student on a partial figure would be exactly the vacuous check
     * this plugin does not ship.
     *
     * @param stdClass[] $rules the activity's quota rules, priority order
     * @param stdClass[] $template the activity's seat plan
     * @param int[] $confirmedids confirmed members, candidate excluded
     * @param int[] $invitedids members holding a pending invitation
     * @param int $userid the candidate
     * @param stdClass[] $attrs attributes keyed by user id
     * @param int $maxsize the team's effective maximum size
     * @return stdClass {fits: bool, caution: string, warning: string, seating: stdClass}
     */
    private static function composition_verdict(
        array $rules,
        array $template,
        array $confirmedids,
        array $invitedids,
        int $userid,
        array $attrs,
        int $maxsize
    ): stdClass {
        // DEDUPED, and this is a correctness fix rather than tidiness. The
        // seat engine does NOT collapse a repeated userid - measured: two
        // people with one id listed twice reports current=3 against a max of
        // 2 - so a person who holds BOTH a pending invitation and a pending
        // join request to the same team was counted as two people. A leader
        // whose team met "between 2 and 2 with Department SCOPE" exactly was
        // told the maximum was exceeded, by a phantom that was really the
        // requester's own invitation (maintainer's live test, 2026-08-05).
        //
        // The product rule now resolves that pair at source - a request to a
        // team that already invited you accepts the invitation - but the
        // dedupe stays, because the engine's behaviour is a sharp edge and
        // this is not the only caller that could hand it a repeat.
        $projected = array_values(array_unique(
            array_merge($confirmedids, $invitedids, [$userid])
        ));
        $full = evaluator::feasibility_from_data($rules, $template, $projected, $attrs);
        $verdict = (object) [
            'fits' => true,
            'caution' => '',
            'warning' => '',
            'seating' => $full->seating,
        ];

        if ($full->maxexceeded === null) {
            $free = max(0, $maxsize - $full->seated);
            if ($full->missing > $free) {
                $verdict->fits = false;
                $verdict->caution = get_string(
                    'refusalcompositionunreachable',
                    'mod_selfselectadvanced',
                    (object) ['missing' => $full->missing, 'free' => $free]
                );
            }

            return $verdict;
        }

        // A maximum is over on the projected roster. Whose maximum is
        // it? Re-asked over CONFIRMED members plus the candidate, which
        // is the only roster nothing the leader can do today would
        // shrink.
        $hardbasis = array_merge($confirmedids, [$userid]);
        $hard = evaluator::feasibility_from_data($rules, $template, $hardbasis, $attrs);
        if ($hard->maxexceeded !== null) {
            $verdict->fits = false;
            $verdict->caution = self::max_message(
                $rules,
                $confirmedids,
                $invitedids,
                $userid,
                $attrs,
                $hard->maxexceeded,
                true
            );

            return $verdict;
        }

        $verdict->warning = self::max_message(
            $rules,
            $confirmedids,
            $invitedids,
            $userid,
            $attrs,
            $full->maxexceeded,
            false
        );
        $free = max(0, $maxsize - $hard->seated - count(array_unique($invitedids)));
        if ($hard->missing > $free) {
            $verdict->fits = false;
            $verdict->caution = get_string(
                'refusalcompositionunreachable',
                'mod_selfselectadvanced',
                (object) ['missing' => $hard->missing, 'free' => $free]
            );
        }

        return $verdict;
    }

    /**
     * The sentence for an exceeded counting maximum, with the counts
     * broken out: what is CONFIRMED, what is merely PENDING, and what
     * this request would make.
     *
     * The VERDICT is never decided here - it is decided by the
     * evaluator, twice, over two rosters. All this does is attach
     * figures to it, by locating the rule the evaluator's own entry
     * came from and counting the two rosters against that rule's
     * dimension exactly as the evaluator counts them. When the rule
     * cannot be located with certainty the wording falls back to the
     * figure-free sentence rather than guessing a number: a wrong
     * number in a refusal is worse than a vague one.
     *
     * @param stdClass[] $rules the activity's quota rules, priority order
     * @param int[] $confirmedids confirmed members, candidate excluded
     * @param int[] $invitedids members holding a pending invitation
     * @param int $userid the candidate
     * @param stdClass[] $attrs attributes keyed by user id
     * @param stdClass $entry the evaluator's maxexceeded entry {value, max, current}
     * @param bool $hard true for the refusal, false for the advisory warning
     * @return string the localised sentence
     */
    private static function max_message(
        array $rules,
        array $confirmedids,
        array $invitedids,
        int $userid,
        array $attrs,
        stdClass $entry,
        bool $hard
    ): string {
        $rule = self::rule_behind($rules, $entry);
        if ($rule === null) {
            return $hard
                ? get_string('refusalcompositionmax', 'mod_selfselectadvanced', $entry)
                : get_string('cautioncompositionmaxpendingplain', 'mod_selfselectadvanced', $entry);
        }

        $confirmed = self::holders($confirmedids, $attrs, $rule->dimension, (string) $rule->value);
        $pending = self::holders($invitedids, $attrs, $rule->dimension, (string) $rule->value);
        $candidate = self::holders([$userid], $attrs, $rule->dimension, (string) $rule->value);
        $a = (object) [
            'value' => $rule->value,
            'max' => (int) $rule->maxcount,
            'confirmed' => $confirmed,
            'pending' => $pending,
            'candidate' => $candidate,
            'wouldbe' => $hard ? $confirmed + $candidate : $confirmed + $pending + $candidate,
        ];

        return $hard
            ? get_string('refusalcompositionmaxconfirmed', 'mod_selfselectadvanced', $a)
            : get_string('cautioncompositionmaxpending', 'mod_selfselectadvanced', $a);
    }

    /**
     * The rule an evaluator maxexceeded entry came from.
     *
     * The entry carries the configured value and the maximum but not
     * the dimension, and the dimension is what a count needs. Matched
     * on both of the fields the entry does carry, case-insensitively on
     * the value as the evaluator matches it; an ambiguous or absent
     * match returns null and the caller words itself without figures.
     *
     * @param stdClass[] $rules the activity's quota rules
     * @param stdClass $entry the evaluator's maxexceeded entry
     * @return stdClass|null the one rule that matches, or null
     */
    private static function rule_behind(array $rules, stdClass $entry): ?stdClass {
        $found = null;
        foreach ($rules as $rule) {
            if ($rule->rtype === 'distinct' || $rule->maxcount === null) {
                continue;
            }
            if ((int) $rule->maxcount !== (int) $entry->max) {
                continue;
            }
            if (\core_text::strtolower((string) $rule->value) !== \core_text::strtolower((string) $entry->value)) {
                continue;
            }
            if ($found !== null && $found->dimension !== $rule->dimension) {
                // Two dimensions carry the same value at the same
                // maximum: which one is over cannot be told from the
                // entry, so no figures are claimed.
                return null;
            }
            $found = $found ?? $rule;
        }

        return $found;
    }

    /**
     * How many of these people hold one value of one dimension.
     *
     * Compared exactly as evaluator::feasibility_from_data() compares a
     * counting rule: lower-cased on both sides, empty treated as
     * missing rather than as a value.
     *
     * @param int[] $userids the people
     * @param stdClass[] $attrs attributes keyed by user id
     * @param string $dimension the dimension
     * @param string $value the configured value
     * @return int how many hold it
     */
    private static function holders(array $userids, array $attrs, string $dimension, string $value): int {
        $target = \core_text::strtolower($value);
        $held = 0;
        foreach (array_unique(array_map('intval', $userids)) as $uid) {
            $mine = $attrs[$uid]->{$dimension} ?? null;
            if ($mine !== null && $mine !== '' && \core_text::strtolower($mine) === $target) {
                $held++;
            }
        }

        return $held;
    }

    /**
     * The seat this person would fill in this team, if the plan has one
     * waiting for them.
     *
     * @param activity $activity the activity
     * @param stdClass $group the team
     * @param int $userid the person
     * @return stdClass|null {slotno, label}, or null when no seat changes
     */
    public static function seat_taken(activity $activity, stdClass $group, int $userid): ?stdClass {
        global $DB;

        $template = slots::get_all($activity);
        if (!$template) {
            // No seat plan: there is no seat to name, which is not the
            // same as not fitting.
            return null;
        }

        [$insql, $params] = $DB->get_in_or_equal(
            [groups::STATUS_CONFIRMED, groups::STATUS_INVITED],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['groupid'] = $group->id;
        $memberids = array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            "groupid = :groupid AND status $insql",
            $params
        ));
        if (in_array($userid, $memberids, true)) {
            return null;
        }

        $attrs = manager::get_for_users(array_merge($memberids, [$userid]));

        return self::seat_from_data($template, $memberids, $userid, $attrs);
    }

    /**
     * How one person stands against MANY teams, for the join picker.
     *
     * The composition verdict and the seat come from the same routines
     * the gate uses. What this deliberately does NOT re-check per team
     * is the formation window, because it is resolved per leader and
     * would cost a query a team; the authoritative gate still applies
     * when the request is actually made. Everything it does report -
     * state, free seats, the membership cap, the counting rules and the
     * seat plan - it reports exactly as the gate would.
     *
     * @param activity $activity the activity
     * @param stdClass[] $groups team rows carrying at least id and state
     * @param int $userid the person
     * @return stdClass[] {fits, caution, seat, seatno, member} keyed by group id
     */
    public static function for_groups(activity $activity, array $groups, int $userid): array {
        global $DB;

        $answers = [];
        if (!$groups) {
            return $answers;
        }

        $groupids = [];
        foreach ($groups as $group) {
            $groupids[] = (int) $group->id;
        }

        // Query 1: every roster at once. Invited members are included
        // because an invitation already reserves its seat (L2).
        [$gsql, $gparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'g');
        [$ssql, $sparams] = $DB->get_in_or_equal(
            [groups::STATUS_CONFIRMED, groups::STATUS_INVITED],
            SQL_PARAMS_NAMED,
            'st'
        );
        $rows = $DB->get_recordset_select(
            'selfselectadvanced_member',
            "groupid $gsql AND status $ssql",
            array_merge($gparams, $sparams),
            '',
            'id, groupid, userid, status'
        );
        $rosters = array_fill_keys($groupids, []);
        $confirmed = array_fill_keys($groupids, []);
        $everyone = [$userid];
        foreach ($rows as $row) {
            $gid = (int) $row->groupid;
            $uid = (int) $row->userid;
            $rosters[$gid][] = $uid;
            if ($row->status === groups::STATUS_CONFIRMED) {
                $confirmed[$gid][] = $uid;
            }
            $everyone[] = $uid;
        }
        $rows->close();

        // Queries 2-4: the rules, the seat plan and everyone's
        // attributes, once for the whole page.
        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $template = slots::get_all($activity);
        $attrs = manager::get_for_users(array_values(array_unique($everyone)));

        // The person's own membership cap does not vary by team, so it
        // is resolved once. At the cap, no team fits, and saying so on
        // every row is the honest answer.
        $resolver = new resolver($activity);
        $cap = $resolver->effective_maxmembership($userid);
        $memberships = groups::count_memberships($activity, $userid);
        $atcap = $memberships >= $cap->value;

        foreach ($groups as $group) {
            $gid = (int) $group->id;
            $answer = (object) [
                'fits' => true,
                'caution' => '',
                'warnings' => [],
                'seat' => null,
                'seatno' => null,
                'member' => in_array($userid, $confirmed[$gid], true),
            ];
            $answers[$gid] = $answer;

            if ($answer->member) {
                // Their own team: no caution, and no seat to take.
                continue;
            }

            if ($group->state !== state::FORMING) {
                $answer->fits = false;
                $answer->caution = get_string('refusalwrongstate', 'mod_selfselectadvanced');
                continue;
            }

            if ($atcap) {
                $answer->fits = false;
                $answer->caution = get_string('refusalinviteecap', 'mod_selfselectadvanced', (object) [
                    'current' => $memberships,
                    'max' => $cap->value,
                ]);
                continue;
            }

            $roster = $rosters[$gid];
            $maxsize = $resolver->effective_maxsize($gid)->value;
            if (count(array_unique($roster)) >= $maxsize) {
                $answer->fits = false;
                $answer->caution = get_string('refusalnoseats', 'mod_selfselectadvanced');
                continue;
            }

            // Quota-exempt teams skip the composition gate exactly as
            // the gate itself does, so an exempt team is never cautioned
            // about rules that are not applied to it.
            //
            // $seating carries the roster-plus-candidate seating out of
            // the gate below so the seat naming can reuse it. Null on
            // the exempt path, where no gate ran and seat_from_data
            // solves it itself.
            $seating = null;
            if (!$resolver->is_quota_exempt($gid)->enabled) {
                // The SAME verdict the gate re-asks in for_person(), so
                // the caution this row carries and the refusal at the
                // gate are one sentence written once - including the
                // confirmed/pending split of decision 53.
                $invited = array_values(array_diff($roster, $confirmed[$gid]));
                $verdict = self::composition_verdict(
                    $rules,
                    $template,
                    $confirmed[$gid],
                    $invited,
                    $userid,
                    $attrs,
                    $maxsize
                );
                $seating = $verdict->seating;
                if ($verdict->warning !== '') {
                    $answer->warnings[] = $verdict->warning;
                }
                if (!$verdict->fits) {
                    $answer->fits = false;
                    $answer->caution = $verdict->caution;
                    continue;
                }
            }

            if ($template) {
                $seat = self::seat_from_data($template, $roster, $userid, $attrs, $seating);
                if ($seat !== null) {
                    $answer->seat = $seat->label;
                    $answer->seatno = $seat->slotno;
                }
            }
        }

        return $answers;
    }

    /**
     * Which seat a candidate takes, over data already in hand.
     *
     * The plan is evaluated twice — as the team stands, and with the
     * candidate added. If the total number of filled seats does not
     * rise, no seat was waiting for this person. If it does rise, the
     * seat they take is simply the one the canonical assignment puts
     * them in.
     *
     * That the assignment always names them is a proof, not a hope: if
     * a maximum-fill seating of the LARGER roster left the candidate
     * unassigned, that same seating would be a valid seating of the
     * roster without them, with the same fill — so the total could not
     * have risen. Whenever the guard above passes, the candidate is
     * seated. The defensive null below therefore never fires; it is
     * there so a future change to the engine's shape degrades to "no
     * seat named" rather than to a warning.
     *
     * Which seat that is follows the engine's least-restrictive
     * placement rule, so a candidate who could complete either of two
     * seats is shown in the roomier one.
     *
     * The roster-plus-candidate half can be handed in: the composition
     * gate that runs immediately before this in for_groups() has
     * already solved that exact roster, and re-solving it here was a
     * literal duplicate - a third full allocator solve per team on the
     * join picker's per-keystroke path. When $after is supplied it MUST
     * be the evaluation of $memberids plus $userid; anything else is a
     * different question with the same shape.
     *
     * @param stdClass[] $template the seat plan
     * @param int[] $memberids the roster without the candidate
     * @param int $userid the candidate
     * @param stdClass[] $attrs attributes keyed by user id
     * @param stdClass|null $after the roster-plus-candidate seating if
     *        the caller already has it, or null to solve it here
     * @return stdClass|null {slotno, label}, or null when no seat changes
     */
    private static function seat_from_data(
        array $template,
        array $memberids,
        int $userid,
        array $attrs,
        ?stdClass $after = null
    ): ?stdClass {
        $before = slots::evaluate_from_data($template, $memberids, $attrs);
        $after = $after ?? slots::evaluate_from_data($template, array_merge($memberids, [$userid]), $attrs);

        if ($after->totalfilled <= $before->totalfilled) {
            return null;
        }
        $index = $after->assignment[$userid] ?? null;
        if ($index === null || !isset($after->slots[$index])) {
            return null;
        }
        $entry = $after->slots[$index];

        return (object) ['slotno' => (int) $entry->slot->slotno, 'label' => $entry->label];
    }
}
