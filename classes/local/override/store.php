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
        $eventclass = null;
        $eventdata = [];
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
            //
            // The tuple checker joins it here rather than at the form,
            // because per-field fallthrough means the invariant's real
            // domain is the MERGED effective tuple, which comes into
            // existence at resolve time and had no write-path validator
            // at all (finding-9). $record already carries the merged
            // old+new values, so CLEARING a field is checked correctly:
            // dropping a group row's minsize while the activity's
            // minsize exceeds that row's maxsize parks it.
            $record->blockers = array_merge(
                guard::blockers($activity, $record),
                consistency::blockers($activity, $record)
            );
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

            // Built here, where the data is at hand, and fired below -
            // after THIS call's commit and lock release (requirement
            // 2). Only the three pre-existing events of the two hottest
            // services are grandfathered inside a lock; this path is
            // being rewritten, so it moves out. On the nested path
            // ($callerholdslock: state::approve_auto pre-acquires this
            // row's lock and owns the transaction) the CALLER's lock
            // and transaction are necessarily still open - that is
            // T-04's handshake, and not this seam's to unwind.
            $eventdata = [
                'objectid' => $record->id,
                'context' => $activity->context(),
                'relateduserid' => self::related_userid($scope, $targetid),
                'other' => [
                    'scope' => $scope,
                    'targetid' => $targetid,
                    'oldvalues' => $old,
                    'newvalues' => $new,
                ],
            ];

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

        $eventclass::create($eventdata)->trigger();

        return $record;
    }

    /**
     * Normalise submitted override values to what the store stores.
     *
     * Shared by the page and the form's pre-check so both judge the
     * SAME candidate: empty and zero clear a field (falling through to
     * the next precedence level), the three flags are 1 or nothing, and
     * a guide's maxguided keeps its explicit zero ("always full").
     * Only keys actually present in $data come back.
     *
     * @param string $scope user, group, guide or move
     * @param array $data submitted field => raw value
     * @return array field => int|null
     */
    public static function normalise(string $scope, array $data): array {
        $values = [];
        foreach (self::FIELDS[$scope] ?? [] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['quotaexempt', 'penaltywaived', 'guidehidden'], true)) {
                $value = $value ? 1 : null;
            } else if ($field === 'maxguided' && $scope === 'guide' && $value !== '' && $value !== null) {
                // 1.5.0: an EXPLICIT zero is a real guide cap ("always
                // full"), unlike every other limit where 0 means unset.
                $value = (int) $value;
            } else if ($value === '' || $value === null || (int) $value === 0) {
                $value = null;
            } else {
                $value = (int) $value;
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * Re-evaluate every pending override; rows whose blockers have all
     * been cleared become active (and start resolving). Returns the
     * still-pending rows with their live blockers attached.
     *
     * Locking: one row at a time. `override:{scope}:{targetid}` is
     * rank 5 and same-rank stacking is illegal (only 'group:' may
     * stack), so each row's lock is acquired and released inside the
     * loop, in the ascending id order the query already produces, and
     * every activation event fires after that row's release. There is
     * deliberately no activity-wide resource: 'overrides:{id}' is
     * unrankable - str_starts_with('overrides:42', 'override:') is
     * false - and would throw on every acquire.
     *
     * BOUNDED, three ways. commit_set() and the join-accept path call
     * this on their hot path, where sweeping every pending row of a
     * 10,000-student activity to re-price rows nothing just touched is
     * waste: they pass the targets their committed move set actually
     * moved. The overrides page passes a keyset WINDOW instead
     * ($fromid/$limitnum), because T-08 made a large pending set
     * reachable from a single settings edit and an unpaged sweep of it
     * on every page visit is the house rule's own example. And the
     * per-row consistency check is handed the chunk's MEMBERSHIP index
     * so it reads memberships once for the sweep rather than once per
     * row.
     *
     * Ordering note: the membership half of that index is preloaded and
     * the OVERRIDE half deliberately is not - consistency::blockers()
     * still re-reads the active rows per call, so a row activated
     * earlier in this loop is visible to later rows' checks. Two
     * mutually conflicting pending rows therefore resolve
     * deterministically - the lower id activates and the other stays
     * pending - rather than both activating. Memberships cannot change
     * that verdict mid-sweep; active override rows can.
     *
     * A row whose lock is CONTENDED is skipped, not fatal: this sweep
     * runs after commit_set() has already committed and released, and
     * after the nightly task has reconciled other activities, so
     * throwing errlocktimeout out of here would turn one busy row into
     * a visible failure of work that has already succeeded. The sweep
     * is idempotent - the next visit or the nightly task catches it.
     *
     * @param activity $activity the activity
     * @param int $actorid the acting user
     * @param array|null $restricttargets ['user' => [userids],
     *        'group' => [groupids]] to sweep only those rows; null
     *        sweeps every pending row of the activity
     * @param int $fromid keyset cursor: examine rows with id > this
     * @param int $limitnum examine at most this many rows, 0 for all
     * @param int|null $lastexamined out: the id of the last row this
     *        call examined, for the caller's next cursor; 0 when the
     *        window was empty
     * @return stdClass[] still-pending rows, each with ->blockers
     */
    public static function recheck_pending(
        activity $activity,
        int $actorid,
        ?array $restricttargets = null,
        int $fromid = 0,
        int $limitnum = 0,
        ?int &$lastexamined = null
    ): array {
        global $DB;

        $lastexamined = 0;
        $select = 'activityid = :activityid AND status = :status AND id > :fromid';
        $params = [
            'activityid' => $activity->id(),
            'status' => 'pending',
            'fromid' => $fromid,
        ];
        if ($restricttargets !== null) {
            $userids = array_values(array_unique(array_filter(array_map(
                'intval',
                $restricttargets['user'] ?? []
            ))));
            $groupids = array_values(array_unique(array_filter(array_map(
                'intval',
                $restricttargets['group'] ?? []
            ))));
            if (!$userids && !$groupids) {
                return [];
            }
            $ors = [];
            if ($userids) {
                [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ru');
                $ors[] = "userid $insql";
                $params += $inparams;
            }
            if ($groupids) {
                [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'rg');
                $ors[] = "groupid $insql";
                $params += $inparams;
            }
            $select .= ' AND (' . implode(' OR ', $ors) . ')';
        }
        $rows = $DB->get_records_select(
            'selfselectadvanced_override',
            $select,
            $params,
            'id ASC',
            '*',
            0,
            $limitnum > 0 ? $limitnum : 0
        );
        if (!$rows) {
            return [];
        }
        // Memberships only (see the ordering note above): the override
        // half of the index is left out so each row still reads the
        // active rows live.
        $preload = ['memberships' => consistency::preload_memberships($activity, $rows)];

        $stillpending = [];
        $events = [];
        foreach ($rows as $row) {
            $lastexamined = (int) $row->id;
            try {
                $lock = locks::acquire('override:' . $row->scope . ':' . self::row_target($row));
            } catch (\moodle_exception $e) {
                // Contended: somebody else is writing this very row, so
                // their write decides its status. Skipped, never fatal.
                continue;
            }
            try {
                // Re-read inside the lock: the copy above may be stale
                // by the time this row's turn comes round (house rule
                // A7), and a save() racing this sweep must win or lose
                // cleanly rather than have its status overwritten.
                $fresh = $DB->get_record('selfselectadvanced_override', [
                    'id' => $row->id,
                    'activityid' => $activity->id(),
                ]);
                if (!$fresh || $fresh->status !== 'pending') {
                    continue;
                }
                $blockers = array_merge(
                    guard::blockers($activity, $fresh),
                    consistency::blockers($activity, $fresh, $preload)
                );
                if ($blockers) {
                    $fresh->blockers = $blockers;
                    $stillpending[] = $fresh;
                    continue;
                }
                $DB->update_record('selfselectadvanced_override', (object) [
                    'id' => $fresh->id,
                    'status' => 'active',
                    'usermodified' => $actorid,
                    'timemodified' => time(),
                ]);
                $events[] = self::status_event($activity, $fresh, 'pending', 'active');
            } finally {
                $lock->release();
            }
        }
        foreach ($events as $event) {
            \mod_selfselectadvanced\event\override_updated::create($event)->trigger();
        }

        return $stillpending;
    }

    /**
     * Sweep EVERY pending row of the activity, one WINDOW at a time.
     *
     * recheck_pending() is windowed because a single settings edit can
     * park an activity's whole override set, and the page that sweeps it
     * must not fetch ten thousand rows to render fifty. A caller whose
     * job IS the whole set - the nightly reconcile, which is also the
     * safety net for the rows the overrides page's window did not reach
     * - still has to arrive at the last row, so it walks the windows
     * here instead of asking for all of them in one query. Same reason,
     * same shape as park_inconsistent(): the cursor is a KEYSET, never
     * an offset, because activating a row removes it from
     * status='pending' and an offset window would step over rows it
     * never examined.
     *
     * @param activity $activity the activity
     * @param int $actorid the acting user
     * @param int $window rows examined per pass
     * @return int the number of passes that examined at least one row
     */
    public static function recheck_all_pending(activity $activity, int $actorid, int $window = 500): int {
        $window = max(1, $window);
        $fromid = 0;
        $passes = 0;
        while (true) {
            $lastexamined = 0;
            self::recheck_pending($activity, $actorid, null, $fromid, $window, $lastexamined);
            if ($lastexamined <= $fromid) {
                // The window was empty: every row has been examined.
                return $passes;
            }
            $passes++;
            $fromid = $lastexamined;
        }
    }

    /**
     * Park every ACTIVE row whose merged effective tuple the activity's
     * new settings have just invalidated (the settings-edit hole).
     *
     * A settings edit changes the fallthrough value of every field no
     * override row sets, so rows that were consistent when written can
     * become inconsistent without anybody touching them. They are moved
     * back to 'pending', where the overrides page's own recheck heals
     * them again the moment the conflict is resolved.
     *
     * CHUNKED with a keyset cursor, never an offset: parking a row
     * removes it from the status='active' set, so a $limitfrom window
     * would step over rows it never examined. The loop stops when a
     * pass returns NO ROWS - not when a pass parks nothing, which would
     * stop at a clean first chunk and never look at row 501. The
     * counterpart index is rebuilt once per pass, so a later chunk sees
     * the parks the earlier chunks made.
     *
     * @param activity $activity the activity
     * @param int $actorid the acting user
     * @return stdClass[] the rows parked, each with ->blockers
     */
    public static function park_inconsistent(activity $activity, int $actorid): array {
        global $DB;

        $parked = [];
        $lastid = 0;
        while (true) {
            $rows = $DB->get_records_select(
                'selfselectadvanced_override',
                'activityid = :activityid AND status = :status AND id > :lastid',
                ['activityid' => $activity->id(), 'status' => 'active', 'lastid' => $lastid],
                'id ASC',
                '*',
                0,
                consistency::CHUNK
            );
            if (!$rows) {
                return $parked;
            }
            // Two reads for the chunk's memberships and its
            // counterparties' override rows, and ONE pass of name
            // lookups for every violation the chunk produces - not one
            // per parked row, which is what the first cut cost and what
            // its docblock denied.
            $preload = consistency::preload($activity, $rows);
            $chunkblockers = consistency::blockers_many($activity, $rows, $preload);
            $events = [];
            foreach ($rows as $row) {
                $lastid = (int) $row->id;
                $blockers = $chunkblockers[(int) $row->id] ?? [];
                if (!$blockers) {
                    continue;
                }
                $lock = locks::acquire('override:' . $row->scope . ':' . self::row_target($row));
                try {
                    $fresh = $DB->get_record('selfselectadvanced_override', [
                        'id' => $row->id,
                        'activityid' => $activity->id(),
                    ]);
                    if (!$fresh || $fresh->status !== 'active') {
                        continue;
                    }
                    $DB->update_record('selfselectadvanced_override', (object) [
                        'id' => $fresh->id,
                        'status' => 'pending',
                        'usermodified' => $actorid,
                        'timemodified' => time(),
                    ]);
                    $fresh->status = 'pending';
                    $fresh->blockers = $blockers;
                    $parked[] = $fresh;
                    $events[] = self::status_event($activity, $fresh, 'active', 'pending');
                } finally {
                    $lock->release();
                }
            }
            foreach ($events as $event) {
                \mod_selfselectadvanced\event\override_updated::create($event)->trigger();
            }
        }
    }

    /**
     * The override_updated payload for a status transition.
     *
     * @param activity $activity the activity
     * @param stdClass $row the override row
     * @param string $from the old status
     * @param string $to the new status
     * @return array the event's create() arguments
     */
    private static function status_event(activity $activity, stdClass $row, string $from, string $to): array {
        return [
            'objectid' => (int) $row->id,
            'context' => $activity->context(),
            'relateduserid' => in_array($row->scope, ['user', 'guide'], true) ? (int) $row->userid : null,
            'other' => [
                'scope' => $row->scope,
                'targetid' => self::row_target($row),
                'oldvalues' => ['status' => $from],
                'newvalues' => ['status' => $to],
            ],
        ];
    }

    /**
     * The target id a stored override row names, whatever its scope.
     *
     * @param stdClass $row the override row
     * @return int
     */
    private static function row_target(stdClass $row): int {
        return (int) ($row->userid ?? 0)
            ?: ((int) ($row->groupid ?? 0) ?: (int) ($row->moveid ?? 0));
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
