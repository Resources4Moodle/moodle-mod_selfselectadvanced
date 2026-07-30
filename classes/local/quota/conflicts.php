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
 * Composition clash detector (2026-07-24 request): rules and slots
 * that contradict each other or the size limits are highlighted on the
 * quota page so an administrator resolves them deliberately — a group
 * cannot satisfy an infeasible template no matter who joins.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conflicts {
    /**
     * Detect infeasible combinations.
     *
     * Checks: (a) total slot demand exceeds the maximum group size;
     * (b) the sum of rule minimums on one dimension exceeds the
     * maximum size; (c) a rule's own min exceeds max or the group
     * maximum; (d) a value demanded by a slot while a rule caps that
     * same value below the slot's need.
     *
     * @param activity $activity the activity
     * @return string[] localised clash descriptions (empty = feasible)
     */
    public static function detect(activity $activity): array {
        global $DB;

        $clashes = [];
        $maxsize = (int) $activity->settings()->maxsize;
        $rules = array_values($DB->get_records('selfselectadvanced_quota', [
            'activityid' => $activity->id(),
        ], 'priority'));
        $slotlist = slots::get_all($activity);

        // Check a: slot demand vs the group maximum.
        $demand = 0;
        foreach ($slotlist as $slot) {
            $demand += (int) $slot->mincount;
        }
        if ($demand > $maxsize) {
            $clashes[] = get_string('clashslotdemand', 'mod_selfselectadvanced', (object) [
                'demand' => $demand,
                'max' => $maxsize,
            ]);
        }

        // Check b: the fewest members each dimension's rules can be
        // satisfied by, against the group maximum.
        //
        // A distinct rule counts VALUES, not members, and the members a
        // value rule pins already supply one of those values. Summing
        // the two counts as if they were both member counts declares a
        // perfectly ordinary rule set impossible: "exactly 2 from
        // SCOPE" plus "at least 4 distinct schools" reads as 6 members
        // when it is satisfied by 5 - two from SCOPE and three from
        // three other schools, which is four distinct schools between
        // them. That configuration is the one this plugin was built
        // for, and it was being reported as an impossible wall.
        //
        // So: the pinned members, plus one more for each distinct value
        // they do not already cover.
        $pinnedmembers = [];
        $pinnedvalues = [];
        $distinctneed = [];
        foreach ($rules as $rule) {
            if ($rule->mincount === null) {
                continue;
            }
            $dimension = $rule->dimension;
            if ($rule->rtype === 'distinct') {
                $distinctneed[$dimension] = max($distinctneed[$dimension] ?? 0, (int) $rule->mincount);
            } else {
                $pinnedmembers[$dimension] = ($pinnedmembers[$dimension] ?? 0) + (int) $rule->mincount;
                $pinnedvalues[$dimension][(string) $rule->value] = true;
            }
        }
        $minsums = [];
        foreach (array_unique(array_merge(array_keys($pinnedmembers), array_keys($distinctneed))) as $dimension) {
            $members = $pinnedmembers[$dimension] ?? 0;
            $covered = count($pinnedvalues[$dimension] ?? []);
            $minsums[$dimension] = $members + max(0, ($distinctneed[$dimension] ?? 0) - $covered);
        }

        foreach ($rules as $rule) {
            // Check c: a rule contradicting itself or the group size.
            if (
                $rule->mincount !== null && $rule->maxcount !== null
                    && (int) $rule->mincount > (int) $rule->maxcount
            ) {
                $clashes[] = get_string('clashruleminmax', 'mod_selfselectadvanced', s((string) $rule->value));
            }
            // A distinct rule needing more values than the group can
            // hold members is impossible for the same reason a value
            // rule needing more members is, so both are checked - but
            // against their own meaning of the number.
            if ($rule->mincount !== null && (int) $rule->mincount > $maxsize) {
                $clashes[] = get_string(
                    $rule->rtype === 'distinct' ? 'clashdistincttoobig' : 'clashruletoobig',
                    'mod_selfselectadvanced',
                    (object) [
                        'value' => s((string) ($rule->value ?? '')),
                        'need' => (int) $rule->mincount,
                        'max' => $maxsize,
                    ]
                );
            }
        }
        foreach ($minsums as $dimension => $sum) {
            if ($sum > $maxsize) {
                $clashes[] = get_string('clashminsum', 'mod_selfselectadvanced', (object) [
                    'dimension' => get_string('attr' . $dimension, 'mod_selfselectadvanced'),
                    'sum' => $sum,
                    'max' => $maxsize,
                ]);
            }
        }

        // Check d: slot demands a value a rule caps below the slot's need.
        foreach ($slotlist as $slot) {
            if ($slot->matchtype !== 'value' || $slot->value === null) {
                continue;
            }
            foreach ($rules as $rule) {
                if (
                    $rule->dimension === $slot->dimension && $rule->maxcount !== null
                        && \core_text::strtolower((string) $rule->value) === \core_text::strtolower($slot->value)
                        && (int) $rule->maxcount < (int) $slot->mincount
                ) {
                    $clashes[] = get_string('clashslotvsrule', 'mod_selfselectadvanced', (object) [
                        'value' => s($slot->value),
                        'need' => (int) $slot->mincount,
                        'cap' => (int) $rule->maxcount,
                    ]);
                }
            }
        }

        return $clashes;
    }
}
