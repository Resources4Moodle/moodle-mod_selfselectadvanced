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
 * Where several maximum-fill assignments exist, the one shown seats each
 * member in the MOST constrained seat they can take - the specialist seat
 * goes to the specialist, and the shortfall lands on the seats that anybody
 * could still fill. REVERSED 2026-08-13; see build_ranks() for the live
 * defect that forced it. Validity is decided before that tie-break and is
 * never affected by it: fill strictly dominates the ranking, and the
 * no-overlap exclusion accumulates in DECLARED slot order regardless.
 *
 * The search is pure computation over arrays the caller already loaded:
 * no queries, no capability checks, no strings, no clock and no random
 * source. Cost is bounded by a deterministic input-size guard and by
 * TWO budgets - MAX_NODES for time and MAX_MEMO_BYTES for memory. Both
 * are counters over the data rather than a clock or a reading of the
 * process's own memory, because both of those would let two runs over
 * identical data disagree, and the pre-lock composition check and the
 * in-lock re-check must always agree or a team passes the gate and then
 * fails the commit.
 *
 * The attribute VALUES never appear in a memo key: intern_values()
 * replaces them with small integer ids, so what a course calls its
 * departments cannot change how much of the memory budget a search
 * gets. That is the property the 1.20 memory work was missing, and it
 * is why one budget replaces the two this class carried through wave
 * 3B.
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

    /**
     * The memo's memory budget in BYTES, and the ONLY memory limit this
     * class has.
     *
     * Moodle's MEMORY_STANDARD is 128 MB on 64-bit and NO entry point
     * that reaches this class raises it - not search_groups (per
     * keystroke), not joinrequest.php, not flagged.php's sweep. The
     * failure mode of exceeding it is a fatal on one team, not a slow
     * page, which is why the ceiling is enforced here rather than
     * documented and hoped for.
     *
     * Charged per entry as the two strings the memo stores plus
     * {@see self::MEMO_ENTRY_BYTES} for what PHP spends around them. It
     * is a COUNTER OVER THE DATA, not a reading of the process. The
     * distinction is the same one that keeps MAX_NODES from being a
     * timeout: memory_get_usage() depends on whatever the CALLER already
     * allocated, so the same roster would be decided differently from
     * the join picker and from flagged.php's sweep, and the pre-lock
     * composition check and the in-lock re-check would be free to
     * disagree on unchanged data - a team passing the gate and then
     * failing the commit. strlen() over the memo's own strings depends
     * on nothing but the template and the roster.
     *
     * WHY THERE IS ONE LIMIT HERE AND NOT TWO, corrected 2026-08-02
     * after an independent blind audit measured the pair this replaces.
     * Through wave 3B the memo was bounded by an entry ceiling AND a
     * byte budget, and the byte budget was wrong in the place it bound,
     * for one reason: a memo key embedded the ATTRIBUTE VALUES. A course
     * that writes "Electronics and Communication Engineering" in a
     * free-text Department - 41 characters, and attributes/csv_importer
     * accepts it - paid roughly ten times the key length of a course
     * that writes "eng", for the same search over the same shaped data,
     * and so was given roughly ten times fewer remembered states out of
     * the same budget. Measured here over 432 generated cases at value
     * lengths of 7, 51, 131 and 311 characters, the byte budget took the
     * exact search away on 16 of them and cost seats on 12 - EVERY ONE
     * at 51 characters or longer, none at 7. Both corpora that were
     * supposed to defend it used seven-character values, which is why
     * nothing noticed.
     *
     * {@see intern_values()} is the repair: the values become small
     * integer ids at load, and a key carries ids. An entry's cost now
     * depends on the roster and the template and on nothing else -
     * measured worst single entry, 231 bytes - so the same shape costs
     * the same wherever it is deployed. With that true, the entry
     * ceiling had nothing left to do that this budget does not do
     * better, and a second limit that can bind first is a second way to
     * lose a seat. It is gone.
     *
     * WHAT IT IS NOT: the entry ceiling was NOT replaced by simply
     * dropping the byte budget. Measured on the same 432 cases, an entry
     * ceiling of 131072 with no byte budget peaks at 296.9 MB, and 192.3
     * MB of that is one thirty-member roster with 311-character values -
     * over MEMORY_STANDARD entirely, on a page nothing raises the limit
     * for. The audit's reading that the byte budget "buys no memory it
     * needed" holds for short values and fails badly for long ones; what
     * makes the byte budget affordable is the interning, not its
     * removal.
     *
     * THE ENVELOPE, measured 2026-08-02 on PHP 8.4 with the memo budget
     * DISABLED, so these are what the search WANTS rather than what it
     * is allowed. 9264 cases: 7392 from 4 to 30 members, 6 to 12 slots,
     * 1 to 4 seats, 3 to 8 values at all four value lengths, plus 1872
     * deliberately at the wide end (26 to 30 members, up to 9 values, so
     * almost every member is a distinct profile and the keys are as long
     * as the guard permits):
     *
     *   worst entries filed                     174523
     *   worst charged                            34.76 MB
     *   worst peak for ONE solve                 35.15 MB
     *   worst single entry                          231 bytes
     *   largest gap between charge and peak        6.39 MB
     *
     * Entries can never exceed MAX_NODES + 1 = 200001, because a node is
     * counted before every recursion and each call files at most one
     * entry - so the entry count the deleted ceiling used to bound is
     * bounded by the time budget already. What is NOT bounded by it is
     * the PRODUCT: 200001 entries at 231 bytes is 46.2 MB, over the 43
     * MB tests/allocator_memory_test.php sanctions. That product is what
     * this budget exists to catch, and 34.76 MB of it has been reached
     * by an ordinary generated case, so it is not a theoretical worry.
     *
     * 36 MB is the largest budget whose worst ADMISSIBLE peak - 36 plus
     * the 6.39 MB charge-to-peak gap measured above - still lands inside
     * that 43 MB. It clears the worst charge measured by 1.24 MB, which
     * is deliberately not much: the 43 MB envelope is what decides this
     * number and there is no room to be generous with it. The previous
     * 32 MB does NOT clear it - seven of the wide-end cases charge more
     * than 32 MB - so even after the interning the old number would
     * still have cost seats.
     *
     * WHAT NO TEST CAN DO, stated because a constant nothing notices is
     * how the wave-3B defect survived a wave. Nothing in this repository
     * makes this budget BIND: no shape inside the input-size guard has
     * been found that charges 36 MB, the highest being 34.76, so no test
     * pins a verdict produced by exhausting it and deleting the
     * enforcement below would leave the suite green. What the tests do
     * cover is everything around it - that the budget is a counter and
     * not a reading of the process
     * (tests/allocator_exactness_test.php), that lowering it costs
     * exactness rather than being ignored, that the envelope holds
     * (tests/allocator_memory_test.php), and above all that the answer
     * does not move when the same case is run at 7, 51, 131 and 311
     * characters (tests/allocator_longvalues_test.php), which is exactly
     * what the wave-3B pair failed. Lower this number if a site must,
     * and raise it in step with a raised PHP memory limit; lowering it
     * can only under-report a fill.
     *
     * (The 1.20.0 nested-array memo, before the packed rewrite, reached
     * 169.2 MB on one solve. That figure is the wave-3A note's, over its
     * own 680-case corpus, and is repeated here only to say what the
     * rewrite - not this budget - removed.)
     *
     * @var int
     */
    public const MAX_MEMO_BYTES = 36 * 1024 * 1024;

    /**
     * What one memo entry costs beyond the length of its two strings.
     *
     * A hash bucket, two zend_string headers and the allocator's size
     * classes. Measured at 121 to 137 bytes on the corpus above, by
     * subtracting the string bytes a run stored from the peak it
     * allocated and dividing by the entries it filed; 128 is the round
     * figure inside that range. The budget above is set from measured
     * PEAKS and not from this number, so this is the SHAPE of the
     * charge - it makes a long-keyed entry cost more than a short one -
     * rather than a claim about any particular allocator.
     *
     * @var int
     */
    private const MEMO_ENTRY_BYTES = 128;

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

    /**
     * Interned id per dimension and normalised value, from 1.
     *
     * @var int[][]
     */
    private array $valueid = [];

    /** @var int[][] The interned ids a profile carries, per profile index. */
    private array $profileids = [];

    /** @var int[][] Interned id of the profile's value in the slot's dimension. */
    private array $pvalid = [];

    /** @var string[] Memoised "score|profile,profile" keyed by search state. */
    private array $memo = [];

    /** @var int Bytes of memory budget the memo has spent this run. */
    private int $memobytes = 0;

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
        $this->intern_values();
        $this->build_predicates();
        $this->build_ranks();
        $this->build_bounds();

        try {
            $this->best_from(0, $this->counts, []);
            $bookings = $this->reconstruct();
        } catch (\OverflowException $e) {
            // A budget ran out - search nodes or memo bytes. The
            // heuristic booking is itself a valid assignment, so falling
            // back to it can only under-report fill, never over-report
            // it.
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
     * Give every (dimension, value) the roster carries a small integer id.
     *
     * THIS IS WHAT KEEPS A MEMO ENTRY A BOUNDED SIZE, and it is the
     * whole reason the memory budget below can be a single number. The
     * consumption registry is part of every memo key, and until this was
     * done the key embedded the attribute VALUES themselves - so a
     * course that writes "Electronics and Communication Engineering" in
     * a free-text Department field (41 characters, and the CSV importer
     * takes it) paid ten times the key length of a course that writes
     * "eng", for exactly the same search. A memory budget then bought
     * ten times fewer remembered states on the first course than on the
     * second and truncated searches that the second finished, which is
     * seats lost for no reason but a naming convention. Measured on
     * PHP 8.4 over 432 generated cases, the byte budget that preceded
     * this bound on 16 of them and cost seats on 12, every one of them
     * at value lengths of 51 characters and up.
     *
     * An id is per (dimension, value) rather than per value, which is
     * exactly the pairing {@see profile_consumed()} needs: consuming
     * "computer" as a DEPARTMENT must not block a member whose PROGRAM
     * happens to be "computer", and that has always been the rule.
     * Because the interning is injective, two registries collide in the
     * key if and only if they were equal before - so the memo's
     * equivalence classes, its hit rate, the node count and every
     * verdict are the ones the string keys produced.
     *
     * Ids are handed out in profile order and, inside a profile, in
     * {@see manager::DIMENSIONS} order. Both are already fixed by
     * {@see build_profiles()}, so the ids - and therefore the keys - are
     * a pure function of the input, which is what the pre-lock check and
     * the in-lock re-check need in order to agree.
     */
    private function intern_values(): void {
        $this->valueid = [];
        $this->profileids = [];
        $next = 1;
        foreach ($this->profilevalues as $p => $values) {
            $this->profileids[$p] = [];
            foreach ($values as $dimension => $value) {
                if ($value === '') {
                    // An empty value is never eligible for a seat and
                    // never blocks one, so it needs no id.
                    continue;
                }
                if (!isset($this->valueid[$dimension][$value])) {
                    $this->valueid[$dimension][$value] = $next++;
                }
                $this->profileids[$p][] = $this->valueid[$dimension][$value];
            }
        }
    }

    /**
     * The individual seat predicate for every (slot, profile) pair.
     */
    private function build_predicates(): void {
        $this->okvalue = [];
        $this->pval = [];
        $this->pvalid = [];
        foreach ($this->template as $i => $unusedslot) {
            $this->okvalue[$i] = [];
            $this->pval[$i] = [];
            $this->pvalid[$i] = [];
            $dimension = $this->dim[$i];
            foreach ($this->profilevalues as $p => $values) {
                $value = $values[$dimension] ?? '';
                $this->pval[$i][$p] = $value;
                // 0 for a value no profile carries in this dimension -
                // an empty one, or a slot naming a dimension the
                // attribute manager does not know. No profile is ever
                // given id 0, so recording one blocks nobody, which is
                // what an empty value has always done.
                $this->pvalid[$i][$p] = $this->valueid[$dimension][$value] ?? 0;
                $ok = $value !== '';
                if ($ok && $this->matchtype[$i] === 'value' && $this->slotvalue[$i] !== null) {
                    $ok = $value === $this->slotvalue[$i];
                }
                $this->okvalue[$i][$p] = $ok;
            }
        }
    }

    /**
     * Rank the slots from MOST to least restrictive.
     *
     * REVERSED 2026-08-13, from a defect the maintainer found on a live
     * group. The rule here used to be the opposite - "a seat many people
     * could fill is offered before a seat almost nobody can, so the
     * shortfall lands on the seats that are hardest to fill" - and that
     * produced an answer no reader could accept.
     *
     * THE CASE. Seat 1 wants two members with Department SCOPE; seat 2 wants
     * three members from departments "not used by an earlier seat rule"; the
     * group holds exactly one SCOPE student. Both placements seat one person,
     * so fill cannot separate them and this ranking decides. Ranking the
     * flexible seat first made a seated member worth MORE there, so the panel
     * credited the SCOPE student to the distinct seat - a seat whose own label
     * excludes SCOPE, because seat 1 uses it - and reported the SCOPE seat as
     * needing two more while its only possible occupant sat in the group.
     *
     * The old rule is not merely cosmetic when read: it takes a member out of
     * the one seat only they can fill and puts them in a seat anybody could,
     * then reports the scarce seat as empty. "Most constrained first" is the
     * standard rule for exactly this reason, and it is what a human does by
     * hand: give the specialist seat to the specialist, then fill the general
     * seats from whoever is left.
     *
     * WHAT THIS DOES NOT CHANGE, stated because the previous docblock made a
     * claim about it. Fill still strictly dominates - `weight` is chosen so
     * that one more seated member always beats any rank preference - so the
     * MAXIMUM number of seats filled is identical before and after; only the
     * choice among equally-full assignments moves. And validity is genuinely
     * untouched: `best_from()` recurses in DECLARED slot order, so the
     * no-overlap `consumed` set accumulates in the manager's own order
     * whatever this ranking says. That is what makes "an earlier seat rule"
     * in the labels mean what it says.
     *
     * Ties fall back to the manager's declared order, so the ranking is total
     * and deterministic.
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
                // Fewest candidates first: the scarce seat gets its scarce
                // member before a seat that anybody could fill.
                $supply,
                // A seat naming one value is more constrained than one that
                // takes any value, or any distinct set of them.
                $flexible ? 1 : 0,
                // A seat that refuses already-used values is more constrained
                // than one that tolerates them.
                $this->overlap[$i] ? 1 : 0,
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
     * The state key a memo entry is filed under.
     *
     * Every part of it is a small integer: the slot index, one count per
     * profile, and the interned ids of the consumed values. Nothing in a
     * key scales with the length of an attribute value, so an entry's
     * cost depends on the ROSTER and the TEMPLATE and on nothing else -
     * which is what lets {@see self::MAX_MEMO_BYTES} be a budget rather
     * than a lottery on how a course names its departments.
     *
     * @param int $i the slot index to fill next
     * @param int[] $avail members still unbooked, per profile index
     * @param array $consumed interned value id => true, recorded by earlier slots
     * @return string the canonical key
     */
    private static function state_key(int $i, array $avail, array $consumed): string {
        return $i . "\x01" . implode(',', $avail) . "\x02" . self::consumed_key($consumed);
    }

    /**
     * The best score reachable from this search state, and the booking
     * THIS slot takes to reach it.
     *
     * Score is fill * weight - ranksum with weight = slots * seats + 1,
     * so the ranksum of any assignment is strictly smaller than one
     * unit of fill: maximising the score maximises fill first and
     * minimises ranksum second, in pure integer arithmetic.
     *
     * MEMORY (1.20, O-4) - see the envelope note on
     * {@see self::MAX_MEMO_BYTES}. A memo
     * entry is ONE flat string, "score|profile,profile", holding this
     * slot's booking and nothing else. Until this change every entry
     * carried `[$i => $booking] + $sub[1]` - a fresh NESTED array naming
     * a booking for this slot and for every slot after it - so a single
     * solve held O(nodes x slots) small PHP arrays and could allocate a
     * sixth of a gigabyte on its own, against a 128 MB
     * MEMORY_STANDARD that no entry point raises. The optimal
     * assignment is recovered instead by {@see reconstruct()}, which
     * walks the same states forward from the root and reads each one's
     * own booking out of the memo. Every state on that path was
     * necessarily visited and memoised on the way down, the transition
     * (avail, consumed) is a pure function of the booking, and nothing
     * here changes which booking wins a tie - so the recovered
     * assignment is the one this search chose. Verified against the
     * pre-rewrite engine over an adversarial corpus: identical filled /
     * assignment / totalfilled / exact for every case once the memory
     * budget stops truncating the search, on PostgreSQL and MariaDB
     * alike (the class touches neither). The cases where a BUDGET rather
     * than the rewrite moved an answer are pinned by
     * tests/allocator_exactness_test.php, and the ones a budget moved
     * because the attribute values were long by
     * tests/allocator_longvalues_test.php.
     *
     * @param int $i the slot index to fill next
     * @param int[] $avail members still unbooked, per profile index
     * @param array $consumed interned value id => true, recorded by earlier slots
     * @return int the best score reachable from this state
     * @throws \OverflowException when the node budget or the memory budget is exhausted
     */
    private function best_from(int $i, array $avail, array $consumed): int {
        if ($i === $this->n) {
            return 0;
        }
        $key = self::state_key($i, $avail, $consumed);
        if (isset($this->memo[$key])) {
            return self::packed_score($this->memo[$key]);
        }

        $maxseats = min($this->suffixseats[$i], array_sum($avail));
        $bound = $maxseats * $this->weight - $this->suffixranks[$i][$maxseats];

        $bestscore = PHP_INT_MIN;
        $bestbooking = [];
        foreach ($this->candidate_bookings($i, $avail, $consumed) as $booking) {
            if (++$this->nodes > self::MAX_NODES) {
                throw new \OverflowException('composition search budget exhausted');
            }
            $next = $avail;
            foreach ($booking as $p) {
                $next[$p]--;
            }
            $score = count($booking) * ($this->weight - $this->rank[$i]) + $this->best_from(
                $i + 1,
                $next,
                self::merge_consumed($consumed, $this->used_values($i, $booking))
            );
            if ($score > $bestscore) {
                // Strictly greater, so the FIRST result found wins a
                // tie and the canonical enumeration order below decides
                // which one that is.
                $bestscore = $score;
                $bestbooking = $booking;
            }
            if ($bestscore >= $bound) {
                break;
            }
        }
        // The memory budget, charged in bytes because that is what is
        // scarce - and charged in bytes ALONE because interned keys make
        // an entry's cost a function of the roster and the template, so
        // a second limit counting entries could only bind earlier and
        // cost a seat for nothing. Thrown rather than simply not
        // memoised: dropping entries would leave the search correct but
        // re-deriving states it has already paid for, which spends the
        // NODE budget to buy nothing, and reconstruct() needs every
        // state on the winning path.
        $packed = $bestscore . '|' . implode(',', $bestbooking);
        $cost = strlen($key) + strlen($packed) + self::MEMO_ENTRY_BYTES;
        if ($this->memobytes + $cost > self::MAX_MEMO_BYTES) {
            throw new \OverflowException('composition search memory envelope exhausted');
        }
        $this->memo[$key] = $packed;
        $this->memobytes += $cost;

        return $bestscore;
    }

    /**
     * The score half of a packed memo entry.
     *
     * @param string $packed the entry, "score|profile,profile"
     * @return int the score
     */
    private static function packed_score(string $packed): int {
        return (int) substr($packed, 0, (int) strpos($packed, '|'));
    }

    /**
     * The booking half of a packed memo entry.
     *
     * @param string $packed the entry, "score|profile,profile"
     * @return int[] the profile indices booked into that slot
     */
    private static function packed_booking(string $packed): array {
        $list = substr($packed, (int) strpos($packed, '|') + 1);

        return $list === '' ? [] : array_map('intval', explode(',', $list));
    }

    /**
     * Walk the memo forward from the root and collect the winning
     * booking of every slot.
     *
     * Only ever called after a best_from() that RETURNED - a run that
     * threw has no optimal path to recover - so every state on the path
     * is present. The ?? '' is a belt on those braces and not a
     * behaviour: an absent state would mean the memo and the transition
     * disagree, and booking nobody in the remaining slots is the
     * fail-closed reading of that.
     *
     * @return array profile index lists keyed by slot index
     */
    private function reconstruct(): array {
        $bookings = [];
        $avail = $this->counts;
        $consumed = [];
        for ($i = 0; $i < $this->n; $i++) {
            $packed = $this->memo[self::state_key($i, $avail, $consumed)] ?? '';
            $booking = $packed === '' ? [] : self::packed_booking($packed);
            $bookings[$i] = $booking;
            foreach ($booking as $p) {
                $avail[$p]--;
            }
            $consumed = self::merge_consumed($consumed, $this->used_values($i, $booking));
        }

        return $bookings;
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
     * @param array $consumed interned value id => true, recorded by earlier slots
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
     * Reported as {@see intern_values()} ids, because the registry is
     * part of every memo key and a key may not carry an attribute value.
     *
     * @param int $i the slot index
     * @param int[] $booking the profile indices booked into the slot
     * @return int[] the interned ids the booking records
     */
    private function used_values(int $i, array $booking): array {
        if (!$booking) {
            return [];
        }
        if ($this->matchtype[$i] === 'distinct') {
            $ids = [];
            foreach ($booking as $p) {
                $ids[$this->pvalid[$i][$p]] = true;
            }

            return array_keys($ids);
        }

        // Fixed-value and "any one value" slots book one shared value.
        return [$this->pvalid[$i][reset($booking)]];
    }

    /**
     * Fold newly recorded values into the registry.
     *
     * @param array $consumed interned value id => true so far
     * @param int[] $used interned ids just recorded
     * @return array the merged registry
     */
    private static function merge_consumed(array $consumed, array $used): array {
        foreach ($used as $id) {
            $consumed[$id] = true;
        }

        return $consumed;
    }

    /**
     * A canonical string for a registry, so equal registries share a memo entry.
     *
     * An id is per (dimension, value), so a flat set of ids says exactly
     * what the dimension => value => true map said, in a form whose
     * length is bounded by the roster.
     *
     * @param array $consumed interned value id => true
     * @return string
     */
    private static function consumed_key(array $consumed): string {
        ksort($consumed, SORT_NUMERIC);

        return implode(',', array_keys($consumed));
    }

    /**
     * Whether any of a profile's values, in any dimension, is consumed.
     *
     * This is the no-overlap exclusion, and it is deliberately
     * cross-dimensional: after "2 with Department Computer", a third
     * Computer student must not fill a later distinct-sub-department
     * seat. It is NOT cross-DIMENSION on the value: an id is per
     * (dimension, value), so consuming "computer" as a department does
     * not block a member whose program is called "computer".
     *
     * @param int $p the profile index
     * @param array $consumed interned value id => true
     * @return bool
     */
    private function profile_consumed(int $p, array $consumed): bool {
        foreach ($this->profileids[$p] as $id) {
            if (isset($consumed[$id])) {
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
