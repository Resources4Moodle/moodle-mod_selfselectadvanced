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
 * evaluator::feasibility_from_data(), so the advisory caution in the
 * picker can never contradict the refusal at the gate.
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
     * @param activity $activity the activity
     * @param stdClass $group the team
     * @param int $userid the person
     * @return stdClass {fits: bool, caution: string, seat: string|null, seatno: int|null}
     */
    public static function for_person(activity $activity, stdClass $group, int $userid): stdClass {
        $answer = (object) [
            'fits' => true,
            'caution' => '',
            'seat' => null,
            'seatno' => null,
        ];

        // The same gate an invitation goes through, so the caution a
        // student reads is the refusal they would actually meet.
        $refusal = (new api($activity))->gatekeeper()->can_invite($group, $userid);
        if ($refusal !== null) {
            $answer->fits = false;
            $answer->caution = $refusal->get_message();
        }

        $seat = self::seat_taken($activity, $group, $userid);
        if ($seat !== null) {
            $answer->seat = $seat->label;
            $answer->seatno = $seat->slotno;
        }

        return $answer;
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
                $with = array_merge($roster, [$userid]);
                $feasibility = evaluator::feasibility_from_data($rules, $template, $with, $attrs);
                $seating = $feasibility->seating;
                if ($feasibility->maxexceeded !== null) {
                    $answer->fits = false;
                    $answer->caution = get_string(
                        'refusalcompositionmax',
                        'mod_selfselectadvanced',
                        $feasibility->maxexceeded
                    );
                    continue;
                }
                $free = max(0, $maxsize - $feasibility->seated);
                if ($feasibility->missing > $free) {
                    $answer->fits = false;
                    $answer->caution = get_string(
                        'refusalcompositionunreachable',
                        'mod_selfselectadvanced',
                        (object) ['missing' => $feasibility->missing, 'free' => $free]
                    );
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
