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
use stdClass;

/**
 * Quota rule persistence (spec 8.2, review item S1).
 *
 * Priority uniqueness per activity is enforced HERE, not by a unique
 * index: reordering renumbers the full sequence 1..n inside a
 * transaction, so no two-phase temporary values are needed and both
 * databases behave identically.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class store {
    /**
     * All rules of the activity in priority order.
     *
     * @param activity $activity the activity
     * @return stdClass[] rule rows
     */
    public static function get_all(activity $activity): array {
        global $DB;

        return array_values($DB->get_records(
            'selfselectadvanced_quota',
            ['activityid' => $activity->id()],
            'priority ASC'
        ));
    }

    /**
     * Create or update a rule; new rules append at the lowest priority.
     *
     * @param activity $activity the activity
     * @param stdClass $data dimension, rtype, value, mincount, maxcount (+id to update)
     * @return stdClass the stored row
     */
    public static function save(activity $activity, stdClass $data): stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $now = time();
        if (!empty($data->id)) {
            $rule = $DB->get_record('selfselectadvanced_quota', [
                'id' => $data->id,
                'activityid' => $activity->id(),
            ], '*', MUST_EXIST);
        } else {
            $rule = (object) [
                'activityid' => $activity->id(),
                'priority' => 1 + (int) $DB->get_field_sql(
                    'SELECT COALESCE(MAX(priority), 0) FROM {selfselectadvanced_quota} WHERE activityid = ?',
                    [$activity->id()]
                ),
                'timecreated' => $now,
            ];
        }
        $rule->dimension = $data->dimension;
        $rule->rtype = $data->rtype;
        $rule->value = $data->rtype === 'distinct' ? null : $data->value;
        $rule->mincount = $data->mincount !== '' && $data->mincount !== null ? (int) $data->mincount : null;
        $rule->maxcount = $data->maxcount !== '' && $data->maxcount !== null ? (int) $data->maxcount : null;
        $rule->timemodified = $now;

        if (!empty($rule->id)) {
            $DB->update_record('selfselectadvanced_quota', $rule);
        } else {
            $rule->id = $DB->insert_record('selfselectadvanced_quota', $rule);
        }

        $transaction->allow_commit();

        return $rule;
    }

    /**
     * Delete a rule and close the priority gap.
     *
     * @param activity $activity the activity
     * @param int $ruleid the rule
     */
    public static function delete(activity $activity, int $ruleid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('selfselectadvanced_quota', ['id' => $ruleid, 'activityid' => $activity->id()]);
        self::renumber($activity);
        $transaction->allow_commit();
    }

    /**
     * Move a rule one step up or down in priority (S1: safe reorder by
     * full renumbering inside the transaction).
     *
     * @param activity $activity the activity
     * @param int $ruleid the rule to move
     * @param int $direction -1 = up (higher priority), 1 = down
     */
    public static function move(activity $activity, int $ruleid, int $direction): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $rules = self::get_all($activity);
        $index = null;
        foreach ($rules as $i => $rule) {
            if ((int) $rule->id === $ruleid) {
                $index = $i;
                break;
            }
        }
        $target = $index !== null ? $index + $direction : null;
        if ($index !== null && $target >= 0 && $target < count($rules)) {
            [$rules[$index], $rules[$target]] = [$rules[$target], $rules[$index]];
            foreach ($rules as $i => $rule) {
                if ((int) $rule->priority !== $i + 1) {
                    $DB->set_field('selfselectadvanced_quota', 'priority', $i + 1, ['id' => $rule->id]);
                }
            }
        }

        $transaction->allow_commit();
    }

    /**
     * Renumber priorities 1..n preserving order.
     *
     * @param activity $activity the activity
     */
    private static function renumber(activity $activity): void {
        global $DB;

        foreach (self::get_all($activity) as $i => $rule) {
            if ((int) $rule->priority !== $i + 1) {
                $DB->set_field('selfselectadvanced_quota', 'priority', $i + 1, ['id' => $rule->id]);
            }
        }
    }
}
