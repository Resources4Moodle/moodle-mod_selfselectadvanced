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
use mod_selfselectadvanced\local\groups;
use moodle_url;
use stdClass;

/**
 * The 4A.7 invariants applied to the MERGED effective tuple (finding-9).
 *
 * Override precedence resolves every field independently - group beats
 * user beats activity, field by field (resolver::effective_dates) - so a
 * single-field write is individually valid and still produces an
 * effective tuple with timeopen > timedue, timedue > timecutoff,
 * minsize > maxsize or maxlead > maxmembership. The two places that DID
 * validate the invariants only ever see a complete tuple by accident:
 * the activity settings form (settings_validator) and one override row's
 * simultaneously submitted fields (override_form). The merge itself -
 * the thing every rule check actually consumes - was validated nowhere,
 * which is how an extension could be granted past a stale cutoff and a
 * group made permanently un-freezable by a merged minsize > maxsize.
 *
 * This class is that missing check, and it is a PURE checker: no state,
 * no writes, no events. store::save() merges its blockers with
 * guard::blockers() and parks the row 'pending' when either speaks, so
 * the resolver (which reads active rows only) never serves an
 * inconsistent merge. Tuple ORDER is this class's job; OCCUPANCY - a cap
 * reduced below a position somebody already holds - stays guard's.
 *
 * Cost per call, measured and corrected in 1.20.1: zero queries for
 * guide/move scope or a candidate that sets no tuple field; otherwise
 * one bounded membership read (a user's teams, or a team's members),
 * then - only when that read returned counterparties - one override
 * read RESTRICTED to those counterparties, and then, only when a
 * violation is actually reported against a named counterparty, up to
 * one {user} and one {selfselectadvanced_group} name lookup. The first
 * cut asked for the activity's whole active override set and paid the
 * name lookups per row: at 802 active rows a single dated save
 * materialised all 802 to build a twelve-entry index, and a 500-row
 * park paid 500 name queries. blockers_many() resolves the names for a
 * whole chunk at once and the batch sweep passes preload(), which keys
 * both reads off the CHUNK's targets rather than the activity's.
 *
 * DOCUMENTED RESIDUAL, deliberately not attempted: enforcement lives at
 * the WRITE seam, so two individually consistent active rows - a user's
 * and a team's - can be paired for the first time by a membership change
 * made LATER, and no write ever examined that pairing. A write seam
 * cannot see future joins. The overrides page's recheck, and the
 * settings-edit sweep (store::park_inconsistent), are what catch up.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class consistency {
    /** @var string The value came from the row being checked. */
    public const SOURCE_THIS = 'this';

    /** @var string The value fell through to the activity settings. */
    public const SOURCE_ACTIVITY = 'activity';

    /** @var string The value came from a user-scope override row. */
    public const SOURCE_USER = 'user';

    /** @var string The value came from a group-scope override row. */
    public const SOURCE_GROUP = 'group';

    /** @var int Rows examined per batch pass (store::park_inconsistent). */
    public const CHUNK = 500;

    /** @var string[] The window fields, each resolved independently. */
    private const DATES = ['timeopen', 'timedue', 'timecutoff'];

    /** @var array<int, string[]> The ordered date pairs 4A.7 constrains. */
    private const DATEPAIRS = [
        ['timeopen', 'timedue'],
        ['timedue', 'timecutoff'],
        ['timeopen', 'timecutoff'],
    ];

    /** @var array<string, string[]> Each scope's [lower, upper] numeric pair. */
    private const LIMITS = [
        'user' => ['maxlead', 'maxmembership'],
        'group' => ['minsize', 'maxsize'],
    ];

    /**
     * Guard-compatible blockers for one candidate override row.
     *
     * The shape is exactly guard::blockers()' - {rule, current, limit,
     * description, fixurl} - so store::save(), recheck_pending() and the
     * overrides page's pending list consume both without knowing which
     * checker produced which.
     *
     * @param activity $activity the activity
     * @param stdClass $row the candidate row, already carrying its
     *        merged old+new field values and its scope/target columns
     * @param array|null $preload the batch index from preload(), when a
     *        sweep is amortising the queries across many rows
     * @return stdClass[] each {rule, current, limit, description, fixurl}
     */
    public static function blockers(activity $activity, stdClass $row, ?array $preload = null): array {
        $violations = self::violations($activity, $row, $preload);
        if (!$violations) {
            return [];
        }

        return self::compose(
            $activity,
            (string) $row->scope,
            $violations,
            self::describe_all($activity, $violations)
        );
    }

    /**
     * blockers() for MANY rows, with every counterparty name resolved
     * across the whole set instead of once per row.
     *
     * The batch park calls this: describe_all() costs up to one {user}
     * and one {selfselectadvanced_group} query, and paying that per
     * PARKED ROW is one query per row on the exact path a single
     * settings edit can drive over an activity's whole override set.
     *
     * @param activity $activity the activity
     * @param stdClass[] $rows the candidate rows, each carrying its
     *        merged values, its scope/target columns and its id
     * @param array|null $preload the batch index from preload()
     * @return array<int, stdClass[]> row id => blockers, in the order given
     */
    public static function blockers_many(activity $activity, array $rows, ?array $preload = null): array {
        $byrow = [];
        $flat = [];
        foreach ($rows as $row) {
            $violations = self::violations($activity, $row, $preload);
            $byrow[(int) $row->id] = $violations;
            foreach ($violations as $violation) {
                $flat[] = $violation;
            }
        }
        if (!$flat) {
            return array_fill_keys(array_keys($byrow), []);
        }

        $descriptions = self::describe_all($activity, $flat);
        $blockers = [];
        $i = 0;
        foreach ($rows as $row) {
            $violations = $byrow[(int) $row->id];
            $blockers[(int) $row->id] = self::compose(
                $activity,
                (string) $row->scope,
                $violations,
                array_slice($descriptions, $i, count($violations))
            );
            $i += count($violations);
        }

        return $blockers;
    }

    /**
     * Turn one row's violations and their sentences into blockers.
     *
     * @param activity $activity the activity
     * @param string $scope the candidate row's scope
     * @param stdClass[] $violations that row's violations
     * @param string[] $descriptions their sentences, same order
     * @return stdClass[] each {rule, current, limit, description, fixurl}
     */
    private static function compose(
        activity $activity,
        string $scope,
        array $violations,
        array $descriptions
    ): array {
        $blockers = [];
        foreach (array_values($violations) as $i => $violation) {
            $blockers[] = (object) [
                // Alphanumeric only: the overrides page feeds this into
                // a popup window NAME, where punctuation is not legal.
                'rule' => 'tuple' . $violation->firstfield . $violation->secondfield
                    . ($violation->counterpartyid ?: ''),
                'current' => (int) $violation->firstvalue,
                'limit' => (int) $violation->secondvalue,
                'description' => $descriptions[$i],
                'fixurl' => self::fixurl($activity, $scope, $violation),
            ];
        }

        return $blockers;
    }

    /**
     * Every 4A.7 invariant this candidate row would break, in any
     * effective tuple it can take part in.
     *
     * The tuples examined are the base merge (candidate over the
     * activity settings) and, for the date fields, each cross-scope
     * merge the candidate can be paired into: for a user candidate, the
     * active group rows of the teams they are confirmed or invited to;
     * for a group candidate, the active user rows of that team's
     * confirmed or invited members. Group fields always win, exactly as
     * resolver::effective_dates() resolves them.
     *
     * A pair is only reported when at least one of its two values came
     * from the CANDIDATE: an unrelated save must never be blocked by a
     * conflict somebody else's rows already had.
     *
     * @param activity $activity the activity
     * @param stdClass $row the candidate row (scope, target column and
     *        merged values)
     * @param array|null $preload the batch index from preload()
     * @return stdClass[] each {firstfield, firstvalue, firstsource,
     *         secondfield, secondvalue, secondsource, counterpartyscope,
     *         counterpartyid, counterpartrowid}
     */
    public static function violations(activity $activity, stdClass $row, ?array $preload = null): array {
        $scope = (string) ($row->scope ?? '');
        if ($scope !== 'user' && $scope !== 'group') {
            // A guide row's maxguided has no tuple partner and a move
            // row's rulesbypassed is the staff hatch's own business.
            return [];
        }

        $set = self::candidate_values($row, $scope);
        if (!$set) {
            // Nothing tuple-relevant is being written, so every merge
            // this row takes part in is one that was validated when its
            // other participants were written.
            return [];
        }

        $settings = $activity->settings();
        $violations = [];

        // The scope's own numeric pair, against whatever the other side
        // resolves to. Both directions: a lone maxsize under the
        // activity minsize, and a lone minsize over the activity
        // maxsize. guard's reductions-only asymmetry is about occupancy
        // and does not exempt tuple order.
        [$lower, $upper] = self::LIMITS[$scope];
        if (array_key_exists($lower, $set) || array_key_exists($upper, $set)) {
            $lowervalue = $set[$lower] ?? (int) $settings->$lower;
            $uppervalue = $set[$upper] ?? (int) $settings->$upper;
            if ($lowervalue > $uppervalue) {
                $violations[] = self::violation(
                    $lower,
                    $lowervalue,
                    array_key_exists($lower, $set) ? self::SOURCE_THIS : self::SOURCE_ACTIVITY,
                    $upper,
                    $uppervalue,
                    array_key_exists($upper, $set) ? self::SOURCE_THIS : self::SOURCE_ACTIVITY
                );
            }
        }

        $setsdate = false;
        foreach (self::DATES as $field) {
            if (array_key_exists($field, $set)) {
                $setsdate = true;
                break;
            }
        }
        if (!$setsdate) {
            return self::dedupe($violations);
        }

        // Base merge: the candidate over the activity settings.
        $values = [];
        $sources = [];
        foreach (self::DATES as $field) {
            if (array_key_exists($field, $set)) {
                $values[$field] = $set[$field];
                $sources[$field] = self::SOURCE_THIS;
            } else {
                $values[$field] = (int) $settings->$field;
                $sources[$field] = self::SOURCE_ACTIVITY;
            }
        }
        $violations = array_merge($violations, self::date_violations($values, $sources, null, 0, 0));

        // Cross-scope merges. The membership read comes FIRST because
        // it is the bounded one - a user's teams, or a team's members -
        // and it is exactly the set of counterparties whose override
        // rows can change this merge. Until 1.20.1 the override read
        // came first and was the whole activity's active rows,
        // unfiltered: 802 active rows materialised to produce a
        // twelve-entry index, once per save and once per pending row
        // swept. Nothing else needed them.
        $opposite = $scope === 'user' ? 'group' : 'user';
        $targetid = self::target_id($row);
        $counterparties = $preload !== null
            ? ($preload['memberships'][$scope][$targetid] ?? [])
            : self::counterparties($activity, $scope, $targetid);
        if (!$counterparties) {
            return self::dedupe($violations);
        }
        $index = $preload['overrides'][$opposite] ?? self::active_index($activity, $opposite, $counterparties);

        foreach ($counterparties as $counterpartyid) {
            $other = $index[(int) $counterpartyid] ?? null;
            if ($other === null) {
                continue;
            }
            $values = [];
            $sources = [];
            foreach (self::DATES as $field) {
                $groupside = $scope === 'group' ? ($set[$field] ?? null) : self::field($other, $field);
                $userside = $scope === 'group' ? self::field($other, $field) : ($set[$field] ?? null);
                if ($groupside !== null) {
                    $values[$field] = $groupside;
                    $sources[$field] = $scope === 'group' ? self::SOURCE_THIS : self::SOURCE_GROUP;
                } else if ($userside !== null) {
                    $values[$field] = $userside;
                    $sources[$field] = $scope === 'group' ? self::SOURCE_USER : self::SOURCE_THIS;
                } else {
                    $values[$field] = (int) $settings->$field;
                    $sources[$field] = self::SOURCE_ACTIVITY;
                }
            }
            $violations = array_merge($violations, self::date_violations(
                $values,
                $sources,
                $opposite,
                (int) $counterpartyid,
                (int) $other->id
            ));
        }

        return self::dedupe($violations);
    }

    /**
     * The blocker sentence for one violation: both effective values,
     * both their sources, and the counterparty the conflict arises for.
     *
     * Names only - never an address or a number (contact privacy).
     *
     * @param activity $activity the activity
     * @param stdClass $violation one violations() entry
     * @return string
     */
    public static function describe(activity $activity, stdClass $violation): string {
        return self::describe_all($activity, [$violation])[0];
    }

    /**
     * The batch index a sweep checks ONE CHUNK OF ROWS against.
     *
     * Keyed off the chunk's own targets, never off the activity: the
     * membership read asks for those targets' counterparties, and the
     * override read asks for the counterparties that read found. Both
     * are chunked, so the cost of a pass is bounded by the chunk and by
     * real membership - an activity with ten thousand dated rows and a
     * five-hundred-row chunk pays for five hundred rows' worth of teams,
     * not for ten thousand rows.
     *
     * @param activity $activity the activity
     * @param stdClass[] $rows the chunk's candidate rows (scope + target
     *        columns are all that is read)
     * @return array ['overrides' => scope => targetid => row,
     *         'memberships' => scope => targetid => counterparty ids]
     */
    public static function preload(activity $activity, array $rows): array {
        $memberships = self::preload_memberships($activity, $rows);

        $counterusers = [];
        $countergroups = [];
        foreach ($memberships['group'] as $userids) {
            foreach ($userids as $userid) {
                $counterusers[(int) $userid] = true;
            }
        }
        foreach ($memberships['user'] as $groupids) {
            foreach ($groupids as $groupid) {
                $countergroups[(int) $groupid] = true;
            }
        }

        return [
            'overrides' => [
                'user' => self::active_index($activity, 'user', array_keys($counterusers)),
                'group' => self::active_index($activity, 'group', array_keys($countergroups)),
            ],
            'memberships' => $memberships,
        ];
    }

    /**
     * The MEMBERSHIP half of preload() on its own.
     *
     * store::recheck_pending() takes this half and deliberately leaves
     * the override half out: memberships cannot change a verdict in the
     * middle of a sweep, but active override rows can - a row the sweep
     * has just activated has to be visible to the rows after it, or two
     * mutually conflicting pending rows both activate.
     *
     * @param activity $activity the activity
     * @param stdClass[] $rows the candidate rows (scope + target columns
     *        are all that is read)
     * @return array scope => targetid => counterparty ids
     */
    public static function preload_memberships(activity $activity, array $rows): array {
        global $DB;

        $memberships = ['user' => [], 'group' => []];
        $userids = [];
        $groupids = [];
        foreach ($rows as $row) {
            $scope = (string) ($row->scope ?? '');
            $targetid = self::target_id($row);
            if (!$targetid) {
                continue;
            }
            if ($scope === 'user') {
                $userids[$targetid] = true;
            } else if ($scope === 'group') {
                $groupids[$targetid] = true;
            }
        }

        // The counterparties of the chunk's own targets: for a user
        // row, the teams that user is confirmed or invited to; for a
        // group row, that team's confirmed or invited members.
        $sides = [
            ['group', array_keys($groupids), 'm.groupid'],
            ['user', array_keys($userids), 'm.userid'],
        ];
        foreach ($sides as [$side, $ids, $column]) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'pm');
                $memberrows = $DB->get_recordset_sql(
                    "SELECT m.id, m.userid, m.groupid
                       FROM {selfselectadvanced_member} m
                       JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                      WHERE g.activityid = :activityid
                        AND m.status IN (:confirmed, :invited)
                        AND $column $insql",
                    [
                        'activityid' => $activity->id(),
                        'confirmed' => groups::STATUS_CONFIRMED,
                        'invited' => groups::STATUS_INVITED,
                    ] + $inparams
                );
                foreach ($memberrows as $memberrow) {
                    if ($side === 'group') {
                        $memberships['group'][(int) $memberrow->groupid][] = (int) $memberrow->userid;
                    } else {
                        $memberships['user'][(int) $memberrow->userid][] = (int) $memberrow->groupid;
                    }
                }
                $memberrows->close();
            }
        }

        return $memberships;
    }

    /**
     * The tuple-relevant fields the candidate actually sets.
     *
     * @param stdClass $row the candidate row
     * @param string $scope user or group
     * @return array<string, int> field => value, absent when unset
     */
    private static function candidate_values(stdClass $row, string $scope): array {
        $set = [];
        foreach (array_merge(self::DATES, self::LIMITS[$scope]) as $field) {
            $value = self::field($row, $field);
            if ($value !== null) {
                $set[$field] = $value;
            }
        }

        return $set;
    }

    /**
     * One field of an override row as an int, or null when it is unset
     * (and so falls through to the next precedence level).
     *
     * @param stdClass $row an override row
     * @param string $field the field name
     * @return int|null
     */
    private static function field(stdClass $row, string $field): ?int {
        $value = $row->$field ?? null;

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Date-pair violations in one already-merged tuple.
     *
     * @param array $values field => effective value
     * @param array $sources field => source constant
     * @param string|null $counterpartyscope the opposite scope, when this is a cross-scope merge
     * @param int $counterpartyid the counterparty user/group id, 0 for the base merge
     * @param int $counterpartrowid the counterpart override row id, 0 for the base merge
     * @return stdClass[]
     */
    private static function date_violations(
        array $values,
        array $sources,
        ?string $counterpartyscope,
        int $counterpartyid,
        int $counterpartrowid
    ): array {
        $violations = [];
        foreach (self::DATEPAIRS as [$first, $second]) {
            // Zero is "unset", not "1970": the same convention
            // settings_validator and override_form already use.
            if (!$values[$first] || !$values[$second] || $values[$first] <= $values[$second]) {
                continue;
            }
            if ($sources[$first] !== self::SOURCE_THIS && $sources[$second] !== self::SOURCE_THIS) {
                continue;
            }
            // When neither side of THIS pair came from the counterpart
            // row, the merge is the base merge: report it once, without
            // a counterparty, and let dedupe() collapse the repeats.
            $fromcounterpart = $counterpartyscope !== null
                && ($sources[$first] === $counterpartyscope || $sources[$second] === $counterpartyscope);
            $violations[] = self::violation(
                $first,
                $values[$first],
                $sources[$first],
                $second,
                $values[$second],
                $sources[$second],
                $fromcounterpart ? $counterpartyscope : null,
                $fromcounterpart ? $counterpartyid : 0,
                $fromcounterpart ? $counterpartrowid : 0
            );
        }

        return $violations;
    }

    /**
     * Build one violation record.
     *
     * @param string $firstfield the field whose value must not exceed the second's
     * @param int $firstvalue its effective value
     * @param string $firstsource where that value came from
     * @param string $secondfield the field it must not exceed
     * @param int $secondvalue its effective value
     * @param string $secondsource where that value came from
     * @param string|null $counterpartyscope user or group, when a counterparty is involved
     * @param int $counterpartyid the counterparty user/group id, 0 when none
     * @param int $counterpartrowid the counterpart override row id, 0 when none
     * @return stdClass
     */
    private static function violation(
        string $firstfield,
        int $firstvalue,
        string $firstsource,
        string $secondfield,
        int $secondvalue,
        string $secondsource,
        ?string $counterpartyscope = null,
        int $counterpartyid = 0,
        int $counterpartrowid = 0
    ): stdClass {
        return (object) [
            'firstfield' => $firstfield,
            'firstvalue' => $firstvalue,
            'firstsource' => $firstsource,
            'secondfield' => $secondfield,
            'secondvalue' => $secondvalue,
            'secondsource' => $secondsource,
            'counterpartyscope' => $counterpartyscope,
            'counterpartyid' => $counterpartyid,
            'counterpartrowid' => $counterpartrowid,
        ];
    }

    /**
     * Collapse repeats of the same pair against the same counterpart row.
     *
     * @param stdClass[] $violations the raw list
     * @return stdClass[] re-indexed from zero
     */
    private static function dedupe(array $violations): array {
        $seen = [];
        foreach ($violations as $violation) {
            $key = $violation->firstfield . '|' . $violation->secondfield . '|' . $violation->counterpartrowid;
            $seen[$key] ??= $violation;
        }

        return array_values($seen);
    }

    /**
     * The ACTIVE override rows of ONE scope, for NAMED targets only,
     * that set at least one date - keyed by target id.
     *
     * Bounded by construction: the caller has already worked out which
     * counterparties can change the merge (a user's teams, or a team's
     * members), and only those are asked for. The same precedence
     * resolver::load_overrides() applies: status='active' only, oldest
     * row wins a legacy duplicate. The id list is chunked so a large
     * batch pass cannot exceed a driver's placeholder limit.
     *
     * @param activity $activity the activity
     * @param string $scope user or group - the scope whose rows are wanted
     * @param int[] $targetids the target ids to fetch, never "all"
     * @return array targetid => row
     */
    private static function active_index(activity $activity, string $scope, array $targetids): array {
        global $DB;

        $targetids = array_values(array_unique(array_filter(array_map('intval', $targetids))));
        if (!$targetids || ($scope !== 'user' && $scope !== 'group')) {
            return [];
        }

        $targetfield = $scope === 'user' ? 'userid' : 'groupid';
        $index = [];
        foreach (array_chunk($targetids, self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'ai');
            $rows = $DB->get_records_select(
                'selfselectadvanced_override',
                "activityid = :activityid AND status = :status AND scope = :scope AND $targetfield $insql",
                [
                    'activityid' => $activity->id(),
                    'status' => 'active',
                    'scope' => $scope,
                ] + $inparams,
                'id ASC'
            );
            foreach ($rows as $row) {
                $dated = false;
                foreach (self::DATES as $field) {
                    if (self::field($row, $field) !== null) {
                        $dated = true;
                        break;
                    }
                }
                if (!$dated) {
                    continue;
                }
                $targetid = self::target_id($row);
                if ($targetid && !isset($index[$targetid])) {
                    $index[$targetid] = $row;
                }
            }
        }

        return $index;
    }

    /**
     * The counterparties one candidate can be merged with, in one
     * bounded query: a user's teams (at most their effective
     * maxmembership) or a team's members (at most its effective
     * maxsize). Invited seats count - accepting an invitation is
     * exactly when effective_dates(user, group) gates the action.
     *
     * @param activity $activity the activity
     * @param string $scope the candidate's scope
     * @param int $targetid the candidate's target
     * @return int[] counterparty ids
     */
    private static function counterparties(activity $activity, string $scope, int $targetid): array {
        global $DB;

        if (!$targetid) {
            return [];
        }
        if ($scope === 'user') {
            return array_map('intval', $DB->get_fieldset_sql(
                "SELECT DISTINCT m.groupid
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                  WHERE g.activityid = ? AND m.userid = ? AND m.status IN (?, ?)",
                [$activity->id(), $targetid, groups::STATUS_CONFIRMED, groups::STATUS_INVITED]
            ));
        }

        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT m.userid
               FROM {selfselectadvanced_member} m
              WHERE m.groupid = ? AND m.status IN (?, ?)",
            [$targetid, groups::STATUS_CONFIRMED, groups::STATUS_INVITED]
        ));
    }

    /**
     * The target id an override row names.
     *
     * @param stdClass $row an override row
     * @return int
     */
    private static function target_id(stdClass $row): int {
        return (int) ($row->userid ?? 0) ?: (int) ($row->groupid ?? 0);
    }

    /**
     * Describe a list of violations, looking every name up in at most
     * two batched queries - and only for violations actually reported,
     * and only for those that name a counterparty. A conflict between a
     * row and the activity settings needs no name at all, so a settings
     * edit whose parks are all base-merge conflicts adds no lookups;
     * one whose parks are CROSS-SCOPE conflicts does, which is why the
     * batch path calls blockers_many() and hands this method the whole
     * chunk's violations in one list instead of one row's at a time.
     *
     * @param activity $activity the activity
     * @param stdClass[] $violations the violations
     * @return string[] one sentence per violation, same order
     */
    private static function describe_all(activity $activity, array $violations): array {
        global $DB;

        $userids = [];
        $groupids = [];
        foreach ($violations as $violation) {
            if ($violation->counterpartyscope === 'user') {
                $userids[(int) $violation->counterpartyid] = true;
            } else if ($violation->counterpartyscope === 'group') {
                $groupids[(int) $violation->counterpartyid] = true;
            }
        }
        $names = ['user' => [], 'group' => []];
        if ($userids) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'cu');
            $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', true)->selects;
            foreach ($DB->get_records_sql("SELECT id{$namefields} FROM {user} WHERE id $insql", $params) as $user) {
                // A name, never an address or a number (cardinal rule).
                $names['user'][(int) $user->id] = fullname($user);
            }
        }
        if ($groupids) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($groupids), SQL_PARAMS_NAMED, 'cg');
            $params['activityid'] = $activity->id();
            $rows = $DB->get_records_select(
                'selfselectadvanced_group',
                "id $insql AND activityid = :activityid",
                $params,
                '',
                'id, name'
            );
            foreach ($rows as $row) {
                $names['group'][(int) $row->id] = format_string($row->name);
            }
        }

        $described = [];
        foreach ($violations as $violation) {
            $isdate = in_array($violation->firstfield, self::DATES, true);
            $counterparty = $violation->counterpartyscope === null
                ? ''
                : ($names[$violation->counterpartyscope][(int) $violation->counterpartyid] ?? '');
            $a = (object) [
                'firstlabel' => get_string('overridefield' . $violation->firstfield, 'mod_selfselectadvanced'),
                'firstvalue' => $isdate ? userdate($violation->firstvalue) : (string) $violation->firstvalue,
                'firstsource' => self::source_text($violation->firstsource, $counterparty),
                'secondlabel' => get_string('overridefield' . $violation->secondfield, 'mod_selfselectadvanced'),
                'secondvalue' => $isdate ? userdate($violation->secondvalue) : (string) $violation->secondvalue,
                'secondsource' => self::source_text($violation->secondsource, $counterparty),
            ];
            $text = get_string(
                $isdate ? 'overrideblockertupledates' : 'overrideblockertuplelimits',
                'mod_selfselectadvanced',
                $a
            );
            if ($counterparty !== '') {
                $text .= ' ' . get_string('overrideblockertuplefor', 'mod_selfselectadvanced', $counterparty);
            }
            $described[] = $text;
        }

        return $described;
    }

    /**
     * Where one effective value came from, in words.
     *
     * @param string $source one of the SOURCE_* constants
     * @param string $counterparty the counterparty's name, '' when there is none
     * @return string
     */
    private static function source_text(string $source, string $counterparty): string {
        return match ($source) {
            self::SOURCE_USER => get_string('overridesourceuser', 'mod_selfselectadvanced', $counterparty),
            self::SOURCE_GROUP => get_string('overridesourcegroup', 'mod_selfselectadvanced', $counterparty),
            self::SOURCE_ACTIVITY => get_string('overridesourceactivity', 'mod_selfselectadvanced'),
            default => get_string('overridesourcethis', 'mod_selfselectadvanced'),
        };
    }

    /**
     * The page on which this conflict can actually be resolved.
     *
     * @param activity $activity the activity
     * @param string $scope the candidate row's scope
     * @param stdClass $violation the violation
     * @return moodle_url
     */
    private static function fixurl(activity $activity, string $scope, stdClass $violation): moodle_url {
        $cmid = $activity->cm()->id;
        if ($violation->counterpartrowid) {
            return new moodle_url('/mod/selfselectadvanced/overrides.php', [
                'id' => $cmid,
                'mode' => $violation->counterpartyscope,
                'action' => 'edit',
                'override' => (int) $violation->counterpartrowid,
            ]);
        }
        if (
            $violation->firstsource === self::SOURCE_ACTIVITY
            || $violation->secondsource === self::SOURCE_ACTIVITY
        ) {
            return new moodle_url('/course/modedit.php', ['update' => $cmid]);
        }

        // Both sides are the candidate's own, and the candidate may not
        // have a row id yet (it is checked before it is written), so its
        // own scope's list page is the only address that certainly
        // exists.
        return new moodle_url('/mod/selfselectadvanced/overrides.php', ['id' => $cmid, 'mode' => $scope]);
    }
}
