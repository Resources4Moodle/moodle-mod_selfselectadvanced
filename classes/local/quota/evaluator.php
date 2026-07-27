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
     * repair it), and a seat-plan deficiency larger than the free
     * seats left below the effective maximum. The same greedy booking
     * that gates submission measures the deficiency, so this gate can
     * never admit a roster the submit gate would call unreachable.
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

        $maxexceeded = null;
        foreach ($rules as $rule) {
            if ($rule->rtype === 'distinct' || $rule->maxcount === null) {
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
            if ($current > (int) $rule->maxcount) {
                $maxexceeded = (object) [
                    'value' => $rule->value,
                    'max' => (int) $rule->maxcount,
                    'current' => $current,
                ];
                break;
            }
        }

        $slotresult = slots::evaluate_from_data($template, $memberids, $attrs);
        $missing = 0;
        foreach ($slotresult->slots as $entry) {
            $missing += (int) $entry->missing;
        }

        return (object) [
            'missing' => $missing,
            'seated' => count(array_unique($memberids)),
            'maxexceeded' => $maxexceeded,
        ];
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
     * slot booking logic (slots::evaluate_from_data(), including
     * matchtype value or distinct and allowoverlap) and the same
     * unknown-attribute handling, so their verdicts can never drift
     * apart.
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
