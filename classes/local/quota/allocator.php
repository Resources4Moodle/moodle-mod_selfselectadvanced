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

use mod_selfselectadvanced\local\attributes\manager;
use stdClass;

/**
 * The seat allocator behind the composition template (1.20).
 *
 * Seating a roster into a slot template is a matching problem: a member
 * fills at most one seat, seats carry value predicates, and a filled
 * seat can consume attribute values that later seats must then avoid.
 * The first implementation booked slots in order and never backtracked,
 * so whether a team was reported compliant depended on the order the
 * manager happened to declare the seats in - a team that plainly works
 * could be told it does not. This class replaces that heuristic with an
 * EXACT search: a template is satisfied if and only if SOME assignment
 * of members to seats satisfies it, and the answer never depends on the
 * order the search happens to explore.
 *
 * Slot ORDER remains load-bearing SEMANTICS, and deliberately so: the
 * no-overlap rule is defined against the values consumed by EARLIER
 * slots, which is what "must not match" means to a manager reading the
 * template top to bottom. What this class removes is order deciding
 * SATISFIABILITY by accident.
 *
 * The constraint system searched here, unchanged from the heuristic:
 *
 *  - a member fills at most ONE seat, in one slot, ever;
 *  - a slot books at most mincount members, and may book fewer;
 *  - a "value" slot with a value books members whose attribute in the
 *    slot's dimension equals it; with a NULL value it books members who
 *    all share one value; a "distinct" slot books members whose values
 *    are pairwise different; an empty value is never eligible;
 *  - a slot that books at least one member RECORDS the value(s) it used
 *    (every booked member's value, for a distinct slot) whether or not
 *    it allows overlap; a slot that books nobody records nothing;
 *  - a slot without allowoverlap refuses a member ANY of whose values,
 *    in ANY dimension, was recorded by an earlier slot.
 *
 * Where several maximum-fill assignments exist, the one shown is the
 * one that leaves the shortfall on the MOST restrictive seats: the
 * maintainer's least-restrictive placement rule (a seat many people
 * could fill is offered before a seat almost nobody can). Validity is
 * decided before that tie-break and is never affected by it.
 *
 * The search is pure computation over arrays the caller already loaded:
 * no queries, no capability checks, no strings, no clock and no random
 * source. Cost is bounded three ways - a deterministic input-size
 * guard, memoisation of search states, and a node BUDGET that is a step
 * counter rather than a timeout, because a timeout would let two runs
 * over identical data disagree and the pre-lock and in-lock composition
 * checks must always agree.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class allocator {
    /**
     * Search-node ceiling: a COUNTER, never a timeout.
     *
     * A budget rather than a clock because two runs over identical data
     * must agree: the pre-lock composition check and the in-lock
     * re-check would otherwise be free to disagree on an unchanged
     * roster, and a team could pass the gate and then fail the commit.
     *
     * Set to 200000 by maintainer decision (2026-07-31), choosing
     * exactness on large templates over sweep latency. What that buys,
     * measured over three hundred adversarially-random templates per
     * shape (four dimensions, four values, one to three seats per
     * rule): teams of six or eight members under three seat rules never
     * approach the ceiling at all - 650 and 1655 nodes at worst - so
     * for the common shapes this number changes nothing. It matters for
     * the shapes that DO exhaust a smaller budget: two of three hundred
     * at eight members under four seat rules, seventy of three hundred
     * at ten members under five. Those teams are now decided exactly
     * instead of falling back.
     *
     * What it costs, on the same hardware: a node is just under three
     * microseconds, so an exhausted budget is about 580ms, and the most
     * expensive ten-member five-rule template found costs 173ms per
     * team.
     *
     * WHO PAYS IT (corrected 2026-07-31 after the wave-1 audit, which
     * measured this; the previous wording named flagged.php alone and
     * that is the wrong end of the cost model).
     *
     *  - The JOIN PICKER, per keystroke, is the hot path. Every
     *    keystroke in the team autocomplete reaches
     *    search_groups::execute (LIMIT 50) via amd/src/groupselector.js,
     *    which calls fit::for_groups, which runs this class TWICE per
     *    team - so up to a hundred solves inside one student-facing
     *    request, on a page that is not paged. joinrequest.php pays the
     *    same shape through fit::for_person on every waiting request.
     *    Measured on a twelve-member team against a six-row / two-seat
     *    plan: one solve is about 2.9 ms on benign random templates and
     *    up to 73 ms on adversarial ones, and the audit measured the
     *    whole picker call at 4571 ms per keystroke on that shape (50
     *    ms before the exact engine). Until 1.20 it was three solves a
     *    team, the third a literal duplicate of the first.
     *  - The batch compliance sweep of flagged.php runs this once per
     *    TEAM over EVERY forming and pending-guide team of an activity
     *    in one request - fifteen hundred on a busy site. Measured
     *    end-to-end through evaluator::compliance_for_activity: 0.7 s
     *    before the exact engine, 41.2 s after, on twelve-member teams
     *    with a six-row / two-seat plan.
     *
     * Both pages are unpaged today; T-12 owns paging and batching them,
     * and until then an activity built entirely from large templates
     * will be slow to report and slow to search. Lower this constant if
     * that trade stops being worth it - the fallback below is
     * fail-CLOSED, so a smaller budget can only under-report a team's
     * fill, never call a non-compliant team compliant.
     *
     * @var int
     */
    public const MAX_NODES = 200000;

    /** @var int Roster size above which the greedy fallback is used. */
    public const MAX_MEMBERS = 30;

    /** @var int Slot count above which the greedy fallback is used. */
    public const MAX_SLOTS = 12;

    /** @var int Sum of mincount above which the greedy fallback is used. */
    public const MAX_SEATS = 40;

    /** @var stdClass[] Slot rows in template array order. */
    private array $template = [];

    /** @var int Number of slots. */
    private int $n = 0;

    /** @var int Total seats in the template (sum of mincount). */
    private int $seats = 0;

    /** @var int Objective weight, chosen so fill strictly dominates rank. */
    private int $weight = 1;

    /** @var string[] Dimension per slot index. */
    private array $dim = [];

    /** @var int[] Seat count per slot index. */
    private array $mincount = [];

    /** @var string[] Match type per slot index. */
    private array $matchtype = [];

    /** @var array Lower-cased slot value per slot index, null for "any one value". */
    private array $slotvalue = [];

    /** @var bool[] Whether the slot ignores the consumption registry. */
    private array $overlap = [];

    /** @var int[] Restrictiveness rank per slot index; 0 = least restrictive. */
    private array $rank = [];

    /** @var int[] Seats offered by slot index and everything after it. */
    private array $suffixseats = [];

    /** @var int[][] Prefix sums of the suffix's per-seat ranks, ascending. */
    private array $suffixranks = [];

    /** @var int[] Member count per profile index. */
    private array $counts = [];

    /** @var int[][] User ids per profile index, ascending. */
    private array $membersof = [];

    /** @var string[][] Normalised value per profile index and dimension. */
    private array $profilevalues = [];

    /** @var bool[][] Individual seat predicate per slot index and profile index. */
    private array $okvalue = [];

    /** @var string[][] Profile value in the slot's dimension, per slot index. */
    private array $pval = [];

    /** @var array Memoised [score, bookings] keyed by search state. */
    private array $memo = [];

    /** @var int Search nodes consumed by the current run. */
    private int $nodes = 0;

    /**
     * Instances are private working state; callers use solve().
     */
    private function __construct() {
    }

    /**
     * The best assignment of these members to these seats.
     *
     * @param stdClass[] $template slot rows in template order
     * @param int[] $memberids the roster to seat
     * @param stdClass[] $attrs participant attribute records keyed by userid
     * @return stdClass {filled: int[] by template array index, assignment:
     *                  [userid => template array index] ascending by userid,
     *                  totalfilled: int, exact: bool - false ONLY when the
     *                  input-size guard or the node budget forced the fallback}
     */
    public static function solve(array $template, array $memberids, array $attrs): stdClass {
        $engine = new self();

        return $engine->run(array_values($template), $memberids, $attrs);
    }

    /**
     * Set up the working state and run the search.
     *
     * @param stdClass[] $template slot rows, re-indexed from zero
     * @param int[] $memberids the roster to seat
     * @param stdClass[] $attrs participant attribute records keyed by userid
     * @return stdClass the solve() result object
     */
    private function run(array $template, array $memberids, array $attrs): stdClass {
        $this->template = $template;
        $this->n = count($template);
        $memberids = array_values(array_unique(array_map('intval', $memberids)));
        sort($memberids);

        if (!$this->n) {
            return (object) [
                'filled' => [],
                'assignment' => [],
                'totalfilled' => 0,
                'exact' => true,
            ];
        }

        $this->build_slots();

        // Input-size guard. Deterministic and data-driven on purpose:
        // it must never depend on load, time, configuration or debug
        // flags, or two runs over the same data could disagree.
        if (
            count($memberids) > self::MAX_MEMBERS
            || $this->n > self::MAX_SLOTS
            || $this->seats > self::MAX_SEATS
        ) {
            return $this->greedy($template, $memberids, $attrs);
        }

        $this->build_profiles($memberids, $attrs);
        $this->build_predicates();
        $this->build_ranks();
        $this->build_bounds();

        try {
            [, $bookings] = $this->best_from(0, $this->counts, []);
        } catch (\OverflowException $e) {
            // The node budget ran out. The heuristic booking is itself
            // a valid assignment, so falling back to it can only
            // under-report fill, never over-report it.
            return $this->greedy($template, $memberids, $attrs);
        }

        return $this->result_from($bookings);
    }

    /**
     * Per-slot constants: dimension, seats, predicate and overlap flag.
     */
    private function build_slots(): void {
        $this->seats = 0;
        foreach ($this->template as $i => $slot) {
            $this->dim[$i] = (string) $slot->dimension;
            $this->mincount[$i] = max(0, (int) $slot->mincount);
            $this->matchtype[$i] = (string) $slot->matchtype;
            // Slot values are lower-cased but NOT trimmed, exactly as
            // the template matching has always done; attributes are
            // trimmed at write time so the two agree on real data.
            $this->slotvalue[$i] = $slot->value !== null
                ? \core_text::strtolower((string) $slot->value)
                : null;
            $this->overlap[$i] = !empty($slot->allowoverlap);
            $this->seats += $this->mincount[$i];
        }
        $this->weight = $this->n * $this->seats + 1;
    }

    /**
     * Group the roster into attribute PROFILES.
     *
     * Two members carrying the same values in every dimension are
     * interchangeable for every purpose this class has, so the search
     * state can count profiles rather than name people. Members inside
     * a profile are always consumed in ascending-userid order, which is
     * what makes the availability vector a complete description of the
     * state - and therefore what makes memoisation sound.
     *
     * @param int[] $memberids the roster, unique and ascending
     * @param stdClass[] $attrs participant attribute records keyed by userid
     */
    private function build_profiles(array $memberids, array $attrs): void {
        $bykey = [];
        foreach ($memberids as $userid) {
            $record = $attrs[$userid] ?? null;
            $values = [];
            foreach (manager::DIMENSIONS as $dimension) {
                $values[$dimension] = \core_text::strtolower(trim((string) ($record->{$dimension} ?? '')));
            }
            $key = implode("\x1f", $values);
            if (!isset($bykey[$key])) {
                $bykey[$key] = ['values' => $values, 'members' => []];
            }
            $bykey[$key]['members'][] = (int) $userid;
        }
        ksort($bykey, SORT_STRING);

        $this->counts = [];
        $this->membersof = [];
        $this->profilevalues = [];
        foreach (array_values($bykey) as $p => $profile) {
            $members = $profile['members'];
            sort($members);
            $this->counts[$p] = count($members);
            $this->membersof[$p] = $members;
            $this->profilevalues[$p] = $profile['values'];
        }
    }

    /**
     * The individual seat predicate for every (slot, profile) pair.
     */
    private function build_predicates(): void {
        $this->okvalue = [];
        $this->pval = [];
        foreach ($this->template as $i => $unusedslot) {
            $this->okvalue[$i] = [];
            $this->pval[$i] = [];
            $dimension = $this->dim[$i];
            foreach ($this->profilevalues as $p => $values) {
                $value = $values[$dimension] ?? '';
                $this->pval[$i][$p] = $value;
                $ok = $value !== '';
                if ($ok && $this->matchtype[$i] === 'value' && $this->slotvalue[$i] !== null) {
                    $ok = $value === $this->slotvalue[$i];
                }
                $this->okvalue[$i][$p] = $ok;
            }
        }
    }

    /**
     * Rank the slots from least to most restrictive.
     *
     * The maintainer's placement rule: a seat many people could fill is
     * offered before a seat almost nobody can, so where several
     * maximum-fill assignments exist the shortfall lands on the seats
     * that are hardest to fill. Ties fall back to the manager's own
     * declared order, so the ranking is total and deterministic.
     */
    private function build_ranks(): void {
        $order = [];
        foreach ($this->template as $i => $unusedslot) {
            $supply = 0;
            foreach ($this->counts as $p => $count) {
                if ($this->okvalue[$i][$p]) {
                    $supply += $count;
                }
            }
            $flexible = $this->matchtype[$i] === 'distinct' || $this->slotvalue[$i] === null;
            $order[] = [
                -$supply,
                $this->overlap[$i] ? 0 : 1,
                $flexible ? 0 : 1,
                $i,
            ];
        }
        usort($order, static fn(array $a, array $b): int => $a <=> $b);

        $this->rank = [];
        foreach ($order as $position => $entry) {
            $this->rank[$entry[3]] = $position;
        }
    }

    /**
     * Per-suffix seat totals and cheapest-rank prefix sums.
     *
     * These feed the STATE-LOCAL upper bound the search prunes with. It
     * has to be state-local: pruning against a global best-so-far would
     * store non-optimal suffix results in the memo and poison every
     * later lookup of the same state.
     */
    private function build_bounds(): void {
        $this->suffixseats = [];
        $this->suffixranks = [];
        $seats = 0;
        $ranks = [];
        for ($i = $this->n - 1; $i >= 0; $i--) {
            $seats += $this->mincount[$i];
            for ($k = 0; $k < $this->mincount[$i]; $k++) {
                $ranks[] = $this->rank[$i];
            }
            $sorted = $ranks;
            sort($sorted, SORT_NUMERIC);
            $prefix = [0];
            $running = 0;
            foreach ($sorted as $value) {
                $running += $value;
                $prefix[] = $running;
            }
            $this->suffixseats[$i] = $seats;
            $this->suffixranks[$i] = $prefix;
        }
    }

    /**
     * The best score reachable from this search state, and how.
     *
     * Score is fill * weight - ranksum with weight = slots * seats + 1,
     * so the ranksum of any assignment is strictly smaller than one
     * unit of fill: maximising the score maximises fill first and
     * minimises ranksum second, in pure integer arithmetic.
     *
     * @param int $i the slot index to fill next
     * @param int[] $avail members still unbooked, per profile index
     * @param array $consumed dimension => value => true, recorded by earlier slots
     * @return array [score, bookings keyed by slot index]
     * @throws \OverflowException when the node budget is exhausted
     */
    private function best_from(int $i, array $avail, array $consumed): array {
        if ($i === $this->n) {
            return [0, []];
        }
        $key = $i . "\x01" . implode(',', $avail) . "\x02" . self::consumed_key($consumed);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $maxseats = min($this->suffixseats[$i], array_sum($avail));
        $bound = $maxseats * $this->weight - $this->suffixranks[$i][$maxseats];

        $best = [PHP_INT_MIN, []];
        foreach ($this->candidate_bookings($i, $avail, $consumed) as $booking) {
            if (++$this->nodes > self::MAX_NODES) {
                throw new \OverflowException('composition search budget exhausted');
            }
            $next = $avail;
            foreach ($booking as $p) {
                $next[$p]--;
            }
            $sub = $this->best_from(
                $i + 1,
                $next,
                self::merge_consumed($consumed, $this->used_values($i, $booking))
            );
            $score = count($booking) * ($this->weight - $this->rank[$i]) + $sub[0];
            if ($score > $best[0]) {
                // Strictly greater, so the FIRST result found wins a
                // tie and the canonical enumeration order below decides
                // which one that is.
                $best = [$score, [$i => $booking] + $sub[1]];
            }
            if ($best[0] >= $bound) {
                break;
            }
        }

        return $this->memo[$key] = $best;
    }

    /**
     * Every booking this slot could take, in canonical order.
     *
     * Canonical means: seat count k descending, then values ascending,
     * then profile indices ascending - equivalently, the first booking
     * yielded is the lexicographically smallest of the fullest ones.
     * Because ties are broken by first-found, this order is the whole
     * of the determinism guarantee.
     *
     * @param int $i the slot index
     * @param int[] $avail members still unbooked, per profile index
     * @param array $consumed dimension => value => true, recorded by earlier slots
     * @return \Generator each booking, a list of profile indices (one per seat)
     */
    private function candidate_bookings(int $i, array $avail, array $consumed): \Generator {
        $eligible = [];
        foreach ($this->counts as $p => $unusedcount) {
            if ($avail[$p] < 1 || !$this->okvalue[$i][$p]) {
                continue;
            }
            if (!$this->overlap[$i] && $this->profile_consumed($p, $consumed)) {
                continue;
            }
            $eligible[] = $p;
        }

        $mincount = $this->mincount[$i];
        if ($mincount < 1 || !$eligible) {
            yield [];

            return;
        }
        if ($this->matchtype[$i] === 'distinct') {
            yield from $this->distinct_bookings($i, $eligible, $mincount);

            return;
        }
        if ($this->slotvalue[$i] === null) {
            yield from $this->shared_value_bookings($i, $eligible, $avail, $mincount);

            return;
        }

        $supply = 0;
        foreach ($eligible as $p) {
            $supply += $avail[$p];
        }
        for ($k = min($mincount, $supply); $k >= 1; $k--) {
            yield from $this->multisets($eligible, $avail, $k, 0);
        }
        yield [];
    }

    /**
     * Bookings for a "n from ONE value" slot: every shared value, every size.
     *
     * @param int $i the slot index
     * @param int[] $eligible profile indices that may fill this seat
     * @param int[] $avail members still unbooked, per profile index
     * @param int $mincount the slot's seat count
     * @return \Generator each booking, a list of profile indices
     */
    private function shared_value_bookings(int $i, array $eligible, array $avail, int $mincount): \Generator {
        $byvalue = [];
        foreach ($eligible as $p) {
            $byvalue[$this->pval[$i][$p]][] = $p;
        }
        ksort($byvalue, SORT_STRING);

        $supply = [];
        $kmax = 0;
        foreach ($byvalue as $value => $profiles) {
            $count = 0;
            foreach ($profiles as $p) {
                $count += $avail[$p];
            }
            $supply[$value] = min($mincount, $count);
            $kmax = max($kmax, $supply[$value]);
        }

        for ($k = $kmax; $k >= 1; $k--) {
            foreach ($byvalue as $value => $profiles) {
                if ($supply[$value] < $k) {
                    continue;
                }
                yield from $this->multisets($profiles, $avail, $k, 0);
            }
        }
        yield [];
    }

    /**
     * Bookings for a "distinct values" slot: one member per chosen value.
     *
     * Two members of the same profile share a value, so a distinct slot
     * never books the same profile twice and availability beyond one is
     * irrelevant here.
     *
     * @param int $i the slot index
     * @param int[] $eligible profile indices that may fill this seat
     * @param int $mincount the slot's seat count
     * @return \Generator each booking, a list of profile indices
     */
    private function distinct_bookings(int $i, array $eligible, int $mincount): \Generator {
        $byvalue = [];
        foreach ($eligible as $p) {
            $byvalue[$this->pval[$i][$p]][] = $p;
        }
        ksort($byvalue, SORT_STRING);
        $groups = array_values($byvalue);

        for ($k = min($mincount, count($groups)); $k >= 1; $k--) {
            foreach ($this->value_subsets($groups, $k, 0) as $chosen) {
                yield from $this->one_per_value($chosen, 0);
            }
        }
        yield [];
    }

    /**
     * Multisets of profile indices of a given size, ascending.
     *
     * @param int[] $eligible profile indices to draw from, ascending
     * @param int[] $avail members still unbooked, per profile index
     * @param int $k how many seats to fill
     * @param int $from first position of $eligible still allowed
     * @return \Generator each multiset as a list of profile indices
     */
    private function multisets(array $eligible, array $avail, int $k, int $from): \Generator {
        if ($k === 0) {
            yield [];

            return;
        }
        $count = count($eligible);
        for ($j = $from; $j < $count; $j++) {
            $p = $eligible[$j];
            if ($avail[$p] < 1) {
                continue;
            }
            $next = $avail;
            $next[$p]--;
            foreach ($this->multisets($eligible, $next, $k - 1, $j) as $rest) {
                array_unshift($rest, $p);
                yield $rest;
            }
        }
    }

    /**
     * Every ascending choice of k value-groups out of the list.
     *
     * @param array[] $groups profile index lists, one per value, value order
     * @param int $k how many values to choose
     * @param int $from first position still allowed
     * @return \Generator each choice as a list of profile index lists
     */
    private function value_subsets(array $groups, int $k, int $from): \Generator {
        if ($k === 0) {
            yield [];

            return;
        }
        $count = count($groups);
        for ($j = $from; $j + $k <= $count; $j++) {
            foreach ($this->value_subsets($groups, $k - 1, $j + 1) as $rest) {
                array_unshift($rest, $groups[$j]);
                yield $rest;
            }
        }
    }

    /**
     * One profile from each chosen value group, profile indices ascending.
     *
     * @param array[] $chosen profile index lists, one per chosen value
     * @param int $index which chosen value is being picked for
     * @return \Generator each pick as a list of profile indices
     */
    private function one_per_value(array $chosen, int $index): \Generator {
        if ($index === count($chosen)) {
            yield [];

            return;
        }
        foreach ($chosen[$index] as $p) {
            foreach ($this->one_per_value($chosen, $index + 1) as $rest) {
                array_unshift($rest, $p);
                yield $rest;
            }
        }
    }

    /**
     * The values a booking records into the consumption registry.
     *
     * A slot that books nobody records nothing; a slot that books
     * somebody records what it used whether or not it allows overlap.
     *
     * @param int $i the slot index
     * @param int[] $booking the profile indices booked into the slot
     * @return array dimension => value => true
     */
    private function used_values(int $i, array $booking): array {
        if (!$booking) {
            return [];
        }
        $dimension = $this->dim[$i];
        if ($this->matchtype[$i] === 'distinct') {
            $values = [];
            foreach ($booking as $p) {
                $values[$this->pval[$i][$p]] = true;
            }

            return [$dimension => $values];
        }

        // Fixed-value and "any one value" slots book one shared value.
        return [$dimension => [$this->pval[$i][reset($booking)] => true]];
    }

    /**
     * Fold newly recorded values into the registry.
     *
     * @param array $consumed dimension => value => true so far
     * @param array $used dimension => value => true just recorded
     * @return array the merged registry
     */
    private static function merge_consumed(array $consumed, array $used): array {
        foreach ($used as $dimension => $values) {
            foreach ($values as $value => $unusedflag) {
                $consumed[$dimension][$value] = true;
            }
        }

        return $consumed;
    }

    /**
     * A canonical string for a registry, so equal registries share a memo entry.
     *
     * @param array $consumed dimension => value => true
     * @return string
     */
    private static function consumed_key(array $consumed): string {
        ksort($consumed, SORT_STRING);
        $parts = [];
        foreach ($consumed as $dimension => $values) {
            ksort($values, SORT_STRING);
            $parts[] = $dimension . "\x1d" . implode("\x1e", array_keys($values));
        }

        return implode("\x1f", $parts);
    }

    /**
     * Whether any of a profile's values, in any dimension, is consumed.
     *
     * This is the no-overlap exclusion, and it is deliberately
     * cross-dimensional: after "2 with Department Computer", a third
     * Computer student must not fill a later distinct-sub-department
     * seat.
     *
     * @param int $p the profile index
     * @param array $consumed dimension => value => true
     * @return bool
     */
    private function profile_consumed(int $p, array $consumed): bool {
        foreach ($consumed as $dimension => $values) {
            $own = $this->profilevalues[$p][$dimension] ?? '';
            if ($own !== '' && isset($values[$own])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn the winning bookings back into named people.
     *
     * Members inside a profile are consumed in ascending-userid order,
     * which is the invariant the memo relies on, so walking the
     * bookings in template order and taking the smallest still-unused
     * userid of each booked profile reproduces the assignment exactly.
     *
     * @param array $bookings profile index lists keyed by slot index
     * @return stdClass the solve() result object
     */
    private function result_from(array $bookings): stdClass {
        $filled = array_fill(0, $this->n, 0);
        $taken = $this->counts ? array_fill(0, count($this->counts), 0) : [];
        $assignment = [];
        $total = 0;
        for ($i = 0; $i < $this->n; $i++) {
            $booking = $bookings[$i] ?? [];
            $filled[$i] = count($booking);
            $total += $filled[$i];
            foreach ($booking as $p) {
                $assignment[$this->membersof[$p][$taken[$p]++]] = $i;
            }
        }
        ksort($assignment, SORT_NUMERIC);

        return (object) [
            'filled' => $filled,
            'assignment' => $assignment,
            'totalfilled' => $total,
            'exact' => true,
        ];
    }

    /**
     * The original single-pass booking heuristic, kept as the fallback.
     *
     * Slots book in template order and never backtrack. It is used ONLY
     * when the input-size guard or the node budget refuses the exact
     * search, and nothing else may call it. Its booking is a valid
     * assignment under the same constraints, so its fill is a lower
     * bound on the exact answer: falling back can under-report a team's
     * fill but can never claim a seat that could not be filled.
     *
     * @param stdClass[] $template slot rows, re-indexed from zero
     * @param int[] $memberids the roster, unique and ascending
     * @param stdClass[] $attrs participant attribute records keyed by userid
     * @return stdClass the solve() result object, with exact => false
     */
    private function greedy(array $template, array $memberids, array $attrs): stdClass {
        $booked = [];
        $usedvalues = [];
        $filled = [];
        $assignment = [];
        $total = 0;

        foreach ($template as $i => $slot) {
            $eligible = [];
            foreach ($memberids as $userid) {
                if (isset($booked[$userid])) {
                    continue;
                }
                $value = \core_text::strtolower(trim((string) ($attrs[$userid]->{$slot->dimension} ?? '')));
                if ($value === '') {
                    continue;
                }
                if (!$slot->allowoverlap && self::consumed($attrs[$userid] ?? null, $usedvalues)) {
                    continue;
                }
                $eligible[$value][] = (int) $userid;
            }
            ksort($eligible);

            $bookednow = [];
            if ($slot->matchtype === 'value') {
                $target = $slot->value !== null ? \core_text::strtolower($slot->value) : null;
                if ($target === null) {
                    // Null value = "n from ONE value": pick the largest value-group.
                    $best = null;
                    foreach ($eligible as $value => $ids) {
                        if ($best === null || count($ids) > count($eligible[$best])) {
                            $best = $value;
                        }
                    }
                    $target = $best;
                }
                $pool = $target !== null ? ($eligible[$target] ?? []) : [];
                sort($pool);
                $bookednow = array_slice($pool, 0, (int) $slot->mincount);
                if ($bookednow && $target !== null) {
                    $usedvalues[$slot->dimension][$target] = true;
                }
            } else {
                // Distinct: one member per value, scarcest values first.
                uksort($eligible, static fn($a, $b) => [count($eligible[$a]), $a] <=> [count($eligible[$b]), $b]);
                foreach ($eligible as $value => $ids) {
                    if (count($bookednow) >= (int) $slot->mincount) {
                        break;
                    }
                    sort($ids);
                    $bookednow[] = $ids[0];
                    $usedvalues[$slot->dimension][$value] = true;
                }
            }

            foreach ($bookednow as $userid) {
                $booked[$userid] = $i;
                $assignment[$userid] = $i;
            }
            $filled[$i] = count($bookednow);
            $total += $filled[$i];
        }
        ksort($assignment, SORT_NUMERIC);

        return (object) [
            'filled' => $filled,
            'assignment' => $assignment,
            'totalfilled' => $total,
            'exact' => false,
        ];
    }

    /**
     * Whether any of a member's attribute values - in any dimension -
     * was already consumed by an earlier slot, for the fallback.
     *
     * @param stdClass|null $attr the member's attribute record
     * @param array $usedvalues dimension => value => true, consumed so far
     * @return bool
     */
    private static function consumed(?stdClass $attr, array $usedvalues): bool {
        foreach ($usedvalues as $dimension => $values) {
            $own = \core_text::strtolower(trim((string) ($attr->{$dimension} ?? '')));
            if ($own !== '' && isset($values[$own])) {
                return true;
            }
        }

        return false;
    }
}
