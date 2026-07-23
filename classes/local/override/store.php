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

namespace mod_selfselectadvanced\local\override;

use mod_selfselectadvanced\activity;
use stdClass;

/**
 * Override persistence (spec 10, review item B5).
 *
 * The single write path for override rows: field sets are fixed per
 * scope (a user-scope minsize can never be written), one row exists
 * per (activity, scope, target) - saving again updates it - and every
 * create/update/delete fires its event with the actor, target, old and
 * new values. The resolver is the ONLY read path.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class store {
    /** @var string[][] Allowed value fields per scope (B5). */
    public const FIELDS = [
        'user' => ['timeopen', 'timedue', 'timecutoff', 'maxlead', 'maxmembership'],
        'group' => ['timeopen', 'timedue', 'timecutoff', 'minsize', 'maxsize', 'quotaexempt', 'penaltywaived'],
        'guide' => ['maxguided'],
        'move' => ['rulesbypassed'],
    ];

    /**
     * The override row for a scope and target, if any.
     *
     * @param activity $activity the activity
     * @param string $scope user, group, guide or move
     * @param int $targetid target user/group/move id
     * @return stdClass|null
     */
    public static function get(activity $activity, string $scope, int $targetid): ?stdClass {
        global $DB;

        return $DB->get_record('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => $scope,
        ] + self::target_field($scope, $targetid)) ?: null;
    }

    /**
     * All override rows of one scope for the activity.
     *
     * @param activity $activity the activity
     * @param string $scope user, group, guide or move
     * @return stdClass[]
     */
    public static function get_all(activity $activity, string $scope): array {
        global $DB;

        return array_values($DB->get_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => $scope,
        ], 'id ASC'));
    }

    /**
     * Create or update the override for a target (one row per target).
     *
     * Only the scope's B5 field set is written; anything else in
     * $values is rejected as a coding error. Null/empty values clear a
     * field (fall through to the next precedence level).
     *
     * @param activity $activity the activity
     * @param string $scope user, group, guide or move
     * @param int $targetid target user/group/move id
     * @param array $values field => value|null
     * @param int $actorid the acting user
     * @return stdClass the stored row
     */
    public static function save(activity $activity, string $scope, int $targetid, array $values, int $actorid): stdClass {
        global $DB;

        if (!isset(self::FIELDS[$scope])) {
            throw new \coding_exception('Unknown override scope: ' . $scope);
        }
        $allowed = self::FIELDS[$scope];
        foreach (array_keys($values) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new \coding_exception("Field $field is not valid for $scope-scope overrides (B5)");
            }
        }

        $transaction = $DB->start_delegated_transaction();

        $now = time();
        $existing = self::get($activity, $scope, $targetid);
        $record = $existing ?? (object) array_merge([
            'activityid' => $activity->id(),
            'scope' => $scope,
            'userid' => null,
            'groupid' => null,
            'moveid' => null,
            'timeopen' => null,
            'timedue' => null,
            'timecutoff' => null,
            'maxlead' => null,
            'maxmembership' => null,
            'maxguided' => null,
            'minsize' => null,
            'maxsize' => null,
            'quotaexempt' => null,
            'penaltywaived' => null,
            'rulesbypassed' => null,
            'timecreated' => $now,
        ], self::target_field($scope, $targetid));

        $old = [];
        $new = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if ($value === '' || $value === false) {
                $value = null;
            }
            if (($existing->$field ?? null) != $value) {
                $old[$field] = $existing->$field ?? null;
                $new[$field] = $value;
            }
            $record->$field = $value;
        }
        $record->usermodified = $actorid;
        $record->timemodified = $now;

        if ($existing) {
            $DB->update_record('selfselectadvanced_override', $record);
            $eventclass = \mod_selfselectadvanced\event\override_updated::class;
        } else {
            $record->id = $DB->insert_record('selfselectadvanced_override', $record);
            $eventclass = \mod_selfselectadvanced\event\override_created::class;
        }

        $eventclass::create([
            'objectid' => $record->id,
            'context' => $activity->context(),
            'relateduserid' => in_array($scope, ['user', 'guide'], true) ? $targetid : null,
            'other' => [
                'scope' => $scope,
                'targetid' => $targetid,
                'oldvalues' => $old,
                'newvalues' => $new,
            ],
        ])->trigger();

        $transaction->allow_commit();

        return $record;
    }

    /**
     * Delete an override row.
     *
     * @param activity $activity the activity
     * @param int $overrideid the row id
     * @param int $actorid the acting user
     */
    public static function delete(activity $activity, int $overrideid, int $actorid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $record = $DB->get_record('selfselectadvanced_override', [
            'id' => $overrideid,
            'activityid' => $activity->id(),
        ], '*', MUST_EXIST);
        $DB->delete_records('selfselectadvanced_override', ['id' => $record->id]);

        $old = [];
        foreach (self::FIELDS[$record->scope] ?? [] as $field) {
            if ($record->$field !== null) {
                $old[$field] = $record->$field;
            }
        }
        $targetid = (int) ($record->userid ?? 0) ?: ((int) ($record->groupid ?? 0) ?: (int) ($record->moveid ?? 0));
        \mod_selfselectadvanced\event\override_deleted::create([
            'objectid' => $record->id,
            'context' => $activity->context(),
            'relateduserid' => in_array($record->scope, ['user', 'guide'], true) ? $targetid : null,
            'other' => [
                'scope' => $record->scope,
                'targetid' => $targetid,
                'oldvalues' => $old,
                'newvalues' => [],
            ],
        ])->trigger();

        $transaction->allow_commit();
    }

    /**
     * The target column for a scope.
     *
     * @param string $scope the scope
     * @param int $targetid the target id
     * @return array column => id
     */
    private static function target_field(string $scope, int $targetid): array {
        return match ($scope) {
            'user', 'guide' => ['userid' => $targetid],
            'group' => ['groupid' => $targetid],
            'move' => ['moveid' => $targetid],
            default => throw new \coding_exception('Unknown override scope: ' . $scope),
        };
    }
}
