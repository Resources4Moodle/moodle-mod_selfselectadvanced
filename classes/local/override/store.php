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
use mod_selfselectadvanced\local\locks;
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
        'guide' => ['maxguided', 'guidehidden'],
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

        $rows = $DB->get_records(
            'selfselectadvanced_override',
            [
                'activityid' => $activity->id(),
                'scope' => $scope,
            ] + self::target_field($scope, $targetid),
            'id ASC'
        );
        if (count($rows) > 1) {
            // Pre-1.19.2 duplicates (T-02 R5). The upgrade step merges
            // them away; until it has run, this read must pick the same
            // twin the resolver reads or an edit lands on a row nobody
            // governs by.
            debugging('Duplicate override rows for ' . $scope . ':' . $targetid, DEBUG_DEVELOPER);
        }

        // Precedence P14, and it is the RESOLVER's rule copied exactly:
        // resolver::load_overrides() selects status='active' only and
        // keeps the first in id ASC, so the row actually in force is
        // the OLDEST ACTIVE one - not simply the oldest. Returning the
        // oldest regardless of status let a coordinator read the active
        // twin's value on screen and then have save() update the parked
        // twin instead: the edit visibly did nothing while the active
        // row kept governing. The plain-oldest fallback covers the case
        // where no twin is active, so a parked row is still editable.
        foreach ($rows as $row) {
            if ($row->status === 'active') {
                return $row;
            }
        }

        return $rows ? reset($rows) : null;
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
     * @param bool $callerholdslock the caller already serialises this
     *        override row (state::approve_auto takes
     *        override:group:{id} before the group lock, because the
     *        relief write must happen inside the transition's
     *        transaction and locks are not re-entrant)
     * @return stdClass the stored row
     */
    public static function save(
        activity $activity,
        string $scope,
        int $targetid,
        array $values,
        int $actorid,
        bool $callerholdslock = false
    ): stdClass {
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
        // Enforced at the seam rather than the page, so no route to
        // granting an exception can forget it (strategy 1.17 B1).
        \mod_selfselectadvanced\local\tickets::require_uninvolved_override($activity, $scope, $targetid, $actorid);

        // One row per (activity, scope, target) is a class contract
        // with nothing behind it: read-then-insert, and a transaction
        // does not serialise two concurrent NULL reads. Two
        // coordinators, a double-submitted form, or grant_guidecap
        // racing this page each inserted a twin, after which get()
        // returned an arbitrary one and delete left the other alive
        // (T-02 R5). A unique index cannot express the invariant - the
        // four scopes use four nullable target columns, and NULLs are
        // distinct in a unique index on both supported engines - so the
        // lock is the enforcement. A caller that already holds this
        // exact resource says so: locks are not re-entrant, and a
        // second acquire of the same resource in the same process is
        // granted (the factory's static token map counts it up) and
        // then released once, leaving a phantom hold (T-04).
        $lock = $callerholdslock ? null : locks::acquire('override:' . $scope . ':' . $targetid);
        $outermost = !$DB->is_transaction_started();
        try {
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
                'guidehidden' => null,
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
            // Guarded reductions: a cap set below the target's current
            // position parks the whole row as 'pending' until the excess
            // is resolved (blockers listed on the overrides page); the
            // resolver only ever sees 'active' rows.
            $record->blockers = guard::blockers($activity, $record);
            $record->status = $record->blockers ? 'pending' : 'active';

            $blockers = $record->blockers;
            unset($record->blockers);
            if ($existing) {
                $DB->update_record('selfselectadvanced_override', $record);
                $eventclass = \mod_selfselectadvanced\event\override_updated::class;
            } else {
                $record->id = $DB->insert_record('selfselectadvanced_override', $record);
                $eventclass = \mod_selfselectadvanced\event\override_created::class;
            }
            $record->blockers = $blockers;

            $eventclass::create([
                'objectid' => $record->id,
                'context' => $activity->context(),
                'relateduserid' => self::related_userid($scope, $targetid),
                'other' => [
                    'scope' => $scope,
                    'targetid' => $targetid,
                    'oldvalues' => $old,
                    'newvalues' => $new,
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }

        return $record;
    }

    /**
     * Re-evaluate every pending override; rows whose blockers have all
     * been cleared become active (and start resolving). Returns the
     * still-pending rows with their live blockers attached.
     *
     * @param activity $activity the activity
     * @param int $actorid the acting user
     * @return stdClass[] still-pending rows, each with ->blockers
     */
    public static function recheck_pending(activity $activity, int $actorid): array {
        global $DB;

        $stillpending = [];
        $rows = $DB->get_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'status' => 'pending',
        ], 'id ASC');
        foreach ($rows as $row) {
            $blockers = guard::blockers($activity, $row);
            if ($blockers) {
                $row->blockers = $blockers;
                $stillpending[] = $row;
                continue;
            }
            $DB->update_record('selfselectadvanced_override', (object) [
                'id' => $row->id,
                'status' => 'active',
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);
            \mod_selfselectadvanced\event\override_updated::create([
                'objectid' => (int) $row->id,
                'context' => $activity->context(),
                'relateduserid' => in_array($row->scope, ['user', 'guide'], true) ? (int) $row->userid : null,
                'other' => [
                    'scope' => $row->scope,
                    'targetid' => (int) ($row->userid ?? $row->groupid),
                    'oldvalues' => ['status' => 'pending'],
                    'newvalues' => ['status' => 'active'],
                ],
            ])->trigger();
        }

        return $stillpending;
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

        // The scope and target are learned before the lock - they are
        // what names it - and the row itself is then re-read inside it,
        // so a save() racing this delete resolves one way or the other
        // rather than leaving a twin alive (T-02 R5).
        $existing = $DB->get_record('selfselectadvanced_override', [
            'id' => $overrideid,
            'activityid' => $activity->id(),
        ], '*', MUST_EXIST);
        $targetid = (int) ($existing->userid ?? 0)
            ?: ((int) ($existing->groupid ?? 0) ?: (int) ($existing->moveid ?? 0));

        $lock = locks::acquire('override:' . $existing->scope . ':' . $targetid);
        $outermost = !$DB->is_transaction_started();
        try {
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
            $targetid = (int) ($record->userid ?? 0)
                ?: ((int) ($record->groupid ?? 0) ?: (int) ($record->moveid ?? 0));
            \mod_selfselectadvanced\event\override_deleted::create([
                'objectid' => $record->id,
                'context' => $activity->context(),
                'relateduserid' => self::related_userid($record->scope, $targetid),
                'other' => [
                    'scope' => $record->scope,
                    'targetid' => $targetid,
                    'oldvalues' => $old,
                    'newvalues' => [],
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Attach a move-scope bypass to a move row that is being minted
     * inside the CALLER's still-open transaction (the join-accept path).
     *
     * Documented resource: `override:move:{moveid}` - rank 5, which
     * ranks BEFORE `activity:` (6) and `group:` (8). This method does
     * NOT acquire it, and that is deliberate rather than an omission:
     * the caller already holds the activity and group locks when the
     * move id is minted, so taking a rank-5 lock here would break the
     * one global order, and there is no earlier point at which it could
     * be taken ascending because the id does not exist yet. It is safe
     * without the lock because that is exactly what the lock in save()
     * defends: two writers racing to create the ONE row for a target.
     * A move id minted inside an uncommitted transaction is
     * unpublishable - no other session can read it, let alone name it -
     * so no concurrent writer can reach this target at all.
     *
     * Everything else save() does still happens: the field whitelist,
     * the conflict-of-interest guard and the event.
     *
     * @param activity $activity the activity
     * @param int $moveid the move row minted in the caller's transaction
     * @param string $rules comma-separated rule codes to bypass
     * @param int $actorid the acting user
     * @return stdClass the stored row
     */
    public static function save_for_new_move(
        activity $activity,
        int $moveid,
        string $rules,
        int $actorid
    ): stdClass {
        return self::save($activity, 'move', $moveid, ['rulesbypassed' => $rules], $actorid, true);
    }

    /**
     * Who an override row's event is ABOUT.
     *
     * A move-scope row names no user of its own - its target is a move
     * id - so the log used to record an exception granted over somebody
     * with no trace of who that somebody was (D6-6b). The move row
     * knows, in one indexed read.
     *
     * @param string $scope user, group, guide or move
     * @param int $targetid the user/group/move id
     * @return int|null the user the row is about, null when it is about a team
     */
    private static function related_userid(string $scope, int $targetid): ?int {
        global $DB;

        if (in_array($scope, ['user', 'guide'], true)) {
            return $targetid;
        }
        if ($scope === 'move') {
            $userid = $DB->get_field('selfselectadvanced_move', 'userid', ['id' => $targetid]);

            return $userid ? (int) $userid : null;
        }

        return null;
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
