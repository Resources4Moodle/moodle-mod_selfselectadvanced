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
        $roster = groups::get_roster($groupid);
        $attrs = manager::get_for_users(array_map(static fn($m) => (int) $m->userid, $roster));

        // Attribute value multiset per dimension, lower-cased for
        // case-insensitive matching against rule values.
        $values = [];
        $unknown = 0;
        foreach ($roster as $member) {
            $record = $attrs[(int) $member->userid] ?? null;
            $missingany = false;
            foreach (manager::DIMENSIONS as $dimension) {
                $value = $record->$dimension ?? null;
                if ($value === null || $value === '') {
                    $missingany = true;
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

        return $report;
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
