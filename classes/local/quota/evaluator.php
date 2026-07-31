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

namespace mod_selfselectadvanced\local\quota;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use stdClass;

/**
 * Quota evaluation (spec 8.2): the bucket report behind the live
 * deficiency panel and the compliance gate used at submission,
 * approval and freeze.
 *
 * Rules evaluate over the CONFIRMED roster's plugin-local attributes,
 * strictly in their manager-set priority order. Members with missing
 * attributes count toward no value rule; they are surfaced as
 * "unknown" so staff flag rather than crash (spec 8.1).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evaluator {
    /**
     * Evaluate every rule of the activity against a group's confirmed
     * roster, in priority order.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @return stdClass report: rules[], compliant, unknowncount, hasrules
     */
    public static function evaluate(activity $activity, int $groupid): stdClass {
        global $DB;

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $template = slots::get_all($activity);
        $memberids = array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$groupid, groups::STATUS_CONFIRMED]
        ));
        $attrs = manager::get_for_users($memberids);

        return self::build_report($rules, $template, $memberids, $attrs);
    }

    /**
     * Whether the composition can still be completed if this roster
     * grows to the group's maximum size — the admission gate behind
     * inviting and accepting (a member who makes compliance
     * unreachable is refused before a seat is wasted on them).
     *
     * The basis roster is confirmed PLUS invited members (invited
     * members already hold reserved seats, L2), plus the candidate
     * when they are not seated yet. Two unreachability conditions:
     * an exceeded counting-rule MAXIMUM (adding members can never
     * repair it), and a demand for more further members than the free
     * seats left below the effective maximum could ever supply.
     *
     * That demand — `missing` — is a PROVEN LOWER BOUND on the number
     * of additional members any compliant completion of this roster
     * would need. It is measured by the same exact seat engine that
     * gates submission, plus counting-rule arithmetic that adds
     * demands which need DISJOINT people and only maxes demands one
     * person could serve at once (see feasibility_from_data()). A
     * lower bound is the only safe direction here: over-estimating
     * would refuse legitimate invitations.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @param int|null $candidateid prospective member, null when already seated
     * @return stdClass {missing, seated, maxexceeded: ?rule entry}
     */
    public static function feasibility(activity $activity, int $groupid, ?int $candidateid): stdClass {
        global $DB;

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $template = slots::get_all($activity);

        [$insql, $params] = $DB->get_in_or_equal(
            [groups::STATUS_CONFIRMED, groups::STATUS_INVITED],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['groupid'] = $groupid;
        $memberids = array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            "groupid = :groupid AND status $insql",
            $params
        ));
        if ($candidateid !== null && !in_array((int) $candidateid, $memberids, true)) {
            $memberids[] = (int) $candidateid;
        }
        $attrs = manager::get_for_users($memberids);

        return self::feasibility_from_data($rules, $template, $memberids, $attrs);
    }

    /**
     * The feasibility verdict over data already in hand — no queries.
     *
     * Split out of feasibility() in 1.19.1 so that a caller judging MANY
     * groups at once (the join picker, which annotates up to fifty teams
     * on one keystroke) pays for the activity's rules, its seat plan and
     * everyone's attributes ONCE rather than once per team. The
     * per-group gate keeps calling this through feasibility() above, so
     * there is exactly one implementation of the verdict and the picker
     * can never disagree with the gate that follows it.
     *
     * How `missing` is bounded (this arithmetic is the gate, so each
     * step below is a bound that can be PROVED, never an estimate):
     *
     *  - `slotbound`, the unfilled seats of the exact seat engine.
     *    Restricting any complete seating of a finished roster to the
     *    members present today is still a valid seating — dropping
     *    bookings only drops consumed values, which relaxes the
     *    no-overlap rule — so today's members can already fill at least
     *    as many seats as the engine says. The rest must come from new
     *    members, and a member fills at most one seat;
     *  - per dimension, the SUM over values of `max(seat demand, rule
     *    minimum) - members already holding that value`. Members
     *    holding different values of one dimension are necessarily
     *    different people, so demands on different values of the same
     *    dimension add;
     *  - per dimension, a distinct rule's own shortfall in values, as
     *    each new member adds at most one value. Combined with the sum
     *    above by max, never by +, because members brought in for the
     *    value demands may or may not also repair distinctness;
     *  - ACROSS dimensions, max: one new member holds a value in every
     *    dimension at once and can serve one demand per dimension.
     *
     * @param stdClass[] $rules the activity's quota rules, priority order
     * @param stdClass[] $template the activity's seat plan
     * @param int[] $memberids the roster to judge, candidate included
     * @param stdClass[] $attrs attributes keyed by user id
     * @return stdClass {missing, seated, maxexceeded: ?rule entry}
     */
    public static function feasibility_from_data(
        array $rules,
        array $template,
        array $memberids,
        array $attrs
    ): stdClass {
        // Counting rules: an exceeded MAXIMUM can never self-heal by
        // adding members and stops the scan where it always has; an
        // unmet MINIMUM records how many members of its value the
        // finished roster must hold.
        $maxexceeded = null;
        $rulemin = [];              // Dimension => value => required members.
        $distinctbound = [];        // Dimension => further values a distinct rule needs.
        foreach ($rules as $rule) {
            if ($rule->rtype === 'distinct') {
                $distinct = [];
                foreach ($memberids as $userid) {
                    $value = $attrs[$userid]->{$rule->dimension} ?? null;
                    if ($value !== null && $value !== '') {
                        $distinct[\core_text::strtolower($value)] = true;
                    }
                }
                $distinctbound[$rule->dimension] = max(
                    $distinctbound[$rule->dimension] ?? 0,
                    (int) $rule->mincount - count($distinct),
                    0
                );
                continue;
            }
            $target = \core_text::strtolower((string) $rule->value);
            $current = 0;
            foreach ($memberids as $userid) {
                $value = $attrs[$userid]->{$rule->dimension} ?? null;
                if ($value !== null && $value !== '' && \core_text::strtolower($value) === $target) {
                    $current++;
                }
            }
            if ($rule->maxcount !== null && $current > (int) $rule->maxcount) {
                $maxexceeded = (object) [
                    'value' => $rule->value,
                    'max' => (int) $rule->maxcount,
                    'current' => $current,
                ];
                break;
            }
            if ($rule->mincount !== null) {
                $rulemin[$rule->dimension][$target] = max(
                    $rulemin[$rule->dimension][$target] ?? 0,
                    (int) $rule->mincount
                );
            }
        }

        // Fixed-value seats demand DISTINCT members of their value, so
        // their mincounts add. Null-value and distinct seats demand no
        // particular value and are covered by the seat bound instead.
        $seatdemand = [];
        foreach ($template as $slot) {
            if ($slot->matchtype !== 'value' || $slot->value === null) {
                continue;
            }
            $value = \core_text::strtolower((string) $slot->value);
            $seatdemand[$slot->dimension][$value] = ($seatdemand[$slot->dimension][$value] ?? 0)
                + max(0, (int) $slot->mincount);
        }

        $present = self::value_supply($memberids, $attrs);
        $dimbound = 0;
        $dimensions = array_unique(array_merge(
            array_keys($seatdemand),
            array_keys($rulemin),
            array_keys($distinctbound)
        ));
        foreach ($dimensions as $dimension) {
            $sum = 0;
            $values = array_unique(array_merge(
                array_keys($seatdemand[$dimension] ?? []),
                array_keys($rulemin[$dimension] ?? [])
            ));
            foreach ($values as $value) {
                $required = max($seatdemand[$dimension][$value] ?? 0, $rulemin[$dimension][$value] ?? 0);
                $sum += max(0, $required - ($present[$dimension][$value] ?? 0));
            }
            $dimbound = max($dimbound, $sum, $distinctbound[$dimension] ?? 0);
        }

        $slotresult = slots::evaluate_from_data($template, $memberids, $attrs);
        $slotmissing = 0;
        foreach ($slotresult->slots as $entry) {
            $slotmissing += (int) $entry->missing;
        }

        return (object) [
            // Every term is a proven lower bound on the further members
            // a compliant completion needs, so the largest of them is
            // what the free seats must cover.
            'missing' => max($slotmissing, $dimbound, 0),
            'seated' => count(array_unique($memberids)),
            'maxexceeded' => $maxexceeded,
        ];
    }

    /**
     * How many members of a roster hold each value of each dimension.
     *
     * Member values are lower-cased AND trimmed, as the seat engine
     * matches them; configured rule and slot values are lower-cased
     * only, as the rule engine has always matched them. Attributes are
     * trimmed at write time (attributes\manager::set()), so on real
     * data the two agree; where a configured value is malformed, this
     * counts MORE members rather than fewer, which can only make the
     * bound looser and never turns it into an over-estimate.
     *
     * @param int[] $memberids the roster
     * @param stdClass[] $attrs attributes keyed by user id
     * @return array dimension => value => member count
     */
    private static function value_supply(array $memberids, array $attrs): array {
        $supply = [];
        foreach (array_unique(array_map('intval', $memberids)) as $userid) {
            $record = $attrs[$userid] ?? null;
            foreach (manager::DIMENSIONS as $dimension) {
                $value = \core_text::strtolower(trim((string) ($record->{$dimension} ?? '')));
                if ($value === '') {
                    continue;
                }
                $supply[$dimension][$value] = ($supply[$dimension][$value] ?? 0) + 1;
            }
        }

        return $supply;
    }

    /**
     * Whether a group currently satisfies every quota rule of its
     * activity (the gate consumed by submission, approval and freeze).
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @return bool true when compliant (vacuously true with no rules)
     */
    public static function is_compliant(activity $activity, int $groupid): bool {
        return self::evaluate($activity, $groupid)->compliant;
    }

    /**
     * Compliance of a hypothetical roster: the full report — counting
     * rules AND the seat plan — evaluated over an explicit member set,
     * for callers that reason about net post-states (the staged-move
     * QUOTA verdict). Funnels through the same build_report() as every
     * other gate, so verdicts can never drift apart.
     *
     * @param activity $activity the activity
     * @param int[] $memberids the hypothetical confirmed member ids
     * @return bool compliant (vacuously true with no rules and no seats)
     */
    public static function compliant_for_members(activity $activity, array $memberids): bool {
        global $DB;

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $template = slots::get_all($activity);
        $memberids = array_values(array_unique(array_map('intval', $memberids)));
        $attrs = manager::get_for_users($memberids);

        return self::build_report($rules, $template, $memberids, $attrs)->compliant;
    }

    /**
     * Compliance verdict for every listed group of an activity in one
     * pass (the flagged report's quota tab): the quota rules, the slot
     * template, every confirmed member of the requested groups and
     * their participant attributes are each loaded ONCE for the whole
     * set, then every group is evaluated in PHP from that shared data
     * through build_report(), the SAME private evaluator is_compliant()
     * consults per group, so the two paths can never drift apart.
     *
     * @param activity $activity the activity
     * @param int[] $groupids the groups to evaluate
     * @return bool[] compliant flag keyed by groupid; every requested
     *                groupid is present in the result
     */
    public static function compliance_for_activity(activity $activity, array $groupids): array {
        global $DB;

        $groupids = array_values(array_unique(array_map('intval', $groupids)));
        $verdicts = array_fill_keys($groupids, true);
        if (!$groupids) {
            return $verdicts;
        }

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $template = slots::get_all($activity);

        // Every requested group's confirmed roster, one query per 1000
        // ids so a huge activity cannot approach a bind-parameter limit.
        $memberidsbygroup = array_fill_keys($groupids, []);
        $useridset = [];
        foreach (array_chunk($groupids, 1000) as $chunk) {
            [$groupinsql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'gc');
            $params['status'] = groups::STATUS_CONFIRMED;
            $rows = $DB->get_records_sql(
                "SELECT m.id, m.groupid, m.userid
                   FROM {selfselectadvanced_member} m
                  WHERE m.groupid $groupinsql AND m.status = :status",
                $params
            );
            foreach ($rows as $row) {
                $memberidsbygroup[(int) $row->groupid][] = (int) $row->userid;
                $useridset[(int) $row->userid] = true;
            }
        }
        $attrs = manager::get_for_users(array_keys($useridset));

        foreach ($groupids as $groupid) {
            $entry = self::build_report($rules, $template, $memberidsbygroup[$groupid], $attrs);
            $verdicts[$groupid] = $entry->compliant;
        }

        return $verdicts;
    }

    /**
     * Build one group's quota report from data already loaded for the
     * whole activity, or just for its own group.
     *
     * The single evaluator behind evaluate() and
     * compliance_for_activity(): both the per-group path and the batch
     * path funnel through here, using the same rule logic, the same
     * exact seat evaluation (slots::evaluate_from_data(), including
     * matchtype value or distinct and allowoverlap) and the same
     * unknown-attribute handling, so their verdicts can never drift
     * apart. A seat plan is reported unsatisfied only when NO seating
     * of the roster satisfies it.
     *
     * @param stdClass[] $rules quota rule rows, priority order
     * @param stdClass[] $template slot rows in slot order
     * @param int[] $memberids the group's confirmed member ids
     * @param stdClass[] $attrs participant attribute records keyed by userid
     * @return stdClass report: rules[], compliant, unknowncount, hasrules
     */
    private static function build_report(array $rules, array $template, array $memberids, array $attrs): stdClass {
        // Attribute value multiset per dimension, lower-cased for
        // case-insensitive matching against rule values.
        $values = [];
        $unknown = 0;
        // A member is "unknown" when missing a dimension a RULE or
        // SLOT of this activity actually uses (programme joined the
        // vocabulary in 1.4.2 but must not retro-flag rosters in
        // activities that never reference it).
        $useddims = array_unique(array_merge(
            array_map(static fn($rule) => $rule->dimension, $rules),
            array_map(static fn($slot) => $slot->dimension, $template)
        )) ?: ['gender', 'department', 'subdepartment'];
        foreach ($memberids as $userid) {
            $record = $attrs[$userid] ?? null;
            $missingany = false;
            foreach (manager::DIMENSIONS as $dimension) {
                $value = $record->$dimension ?? null;
                if ($value === null || $value === '') {
                    if (in_array($dimension, $useddims, true)) {
                        $missingany = true;
                    }
                    continue;
                }
                $values[$dimension][] = \core_text::strtolower($value);
            }
            if (!$record || $missingany) {
                $unknown++;
            }
        }

        $report = (object) [
            'rules' => [],
            'compliant' => true,
            'unknowncount' => $unknown,
            'hasrules' => !empty($rules),
        ];

        foreach ($rules as $rule) {
            $entry = self::evaluate_rule($rule, $values[$rule->dimension] ?? []);
            $report->rules[] = $entry;
            if (!$entry->satisfied) {
                $report->compliant = false;
            }
        }

        // Slot-based composition template (1.3.0): booked-member
        // evaluation; entries share the panel's rule shape.
        $slotresult = slots::evaluate_from_data($template, $memberids, $attrs);
        foreach ($slotresult->slots as $slotentry) {
            $report->rules[] = (object) [
                'id' => 'slot' . $slotentry->slot->slotno,
                'priority' => 1000 + (int) $slotentry->slot->slotno,
                'satisfied' => $slotentry->missing === 0,
                'label' => $slotentry->label,
                'current' => $slotentry->filled,
                'deficiency' => $slotentry->deficiency,
            ];
        }
        $report->hasrules = $report->hasrules || !empty($slotresult->slots);
        if (!$slotresult->ok) {
            $report->compliant = false;
        }

        return $report;
    }

    /**
     * Evaluate one rule against a dimension's value multiset.
     *
     * @param stdClass $rule quota rule row
     * @param string[] $dimensionvalues lower-cased values present
     * @return stdClass entry: rule fields + current, satisfied, label, deficiency
     */
    private static function evaluate_rule(stdClass $rule, array $dimensionvalues): stdClass {
        $dimensionname = get_string('attr' . $rule->dimension, 'mod_selfselectadvanced');

        if ($rule->rtype === 'distinct') {
            $current = count(array_unique($dimensionvalues));
            $required = (int) $rule->mincount;
            $satisfied = $current >= $required;
            $label = get_string('quotaruledistinct', 'mod_selfselectadvanced', (object) [
                'k' => $required,
                'dimension' => $dimensionname,
            ]);
            $deficiency = $satisfied ? '' : get_string('quotadeficiencydistinct', 'mod_selfselectadvanced', (object) [
                'more' => $required - $current,
                'dimension' => $dimensionname,
            ]);
        } else {
            $current = count(array_filter(
                $dimensionvalues,
                static fn($value) => $value === \core_text::strtolower((string) $rule->value)
            ));
            $min = $rule->mincount !== null ? (int) $rule->mincount : null;
            $max = $rule->maxcount !== null ? (int) $rule->maxcount : null;
            $satisfied = ($min === null || $current >= $min) && ($max === null || $current <= $max);
            $a = (object) [
                'value' => $rule->value,
                'dimension' => $dimensionname,
                'min' => $min,
                'max' => $max,
            ];
            if ($min !== null && $max !== null) {
                $label = get_string('quotarulebetween', 'mod_selfselectadvanced', $a);
            } else if ($min !== null) {
                $label = get_string('quotarulemin', 'mod_selfselectadvanced', $a);
            } else {
                $label = get_string('quotarulemax', 'mod_selfselectadvanced', $a);
            }
            if ($satisfied) {
                $deficiency = '';
            } else if ($min !== null && $current < $min) {
                $deficiency = get_string('quotadeficiencymin', 'mod_selfselectadvanced', (object) [
                    'more' => $min - $current,
                    'dimension' => $dimensionname,
                    'value' => $rule->value,
                ]);
            } else {
                $deficiency = get_string('quotadeficiencymax', 'mod_selfselectadvanced', (object) [
                    'excess' => $current - $max,
                    'dimension' => $dimensionname,
                    'value' => $rule->value,
                ]);
            }
        }

        return (object) [
            'id' => (int) $rule->id,
            'priority' => (int) $rule->priority,
            'dimension' => $rule->dimension,
            'rtype' => $rule->rtype,
            'value' => $rule->value,
            'mincount' => $rule->mincount !== null ? (int) $rule->mincount : null,
            'maxcount' => $rule->maxcount !== null ? (int) $rule->maxcount : null,
            'current' => $current,
            'satisfied' => $satisfied,
            'label' => $label,
            'deficiency' => $deficiency,
        ];
    }
}
