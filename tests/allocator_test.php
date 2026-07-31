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

namespace mod_selfselectadvanced;

use mod_selfselectadvanced\local\quota\allocator;
use mod_selfselectadvanced\local\quota\slots;
use stdClass;

/**
 * The seat allocator: exact, order-independent, deterministic.
 *
 * The engine of record for every composition verdict in the plugin, so
 * it is tested as a unit against arrays rather than through the
 * database: these cases are the ones that pin the semantics, and the
 * randomised case at the end is an oracle - it compares the search
 * against brute force over every possible seating.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\allocator
 * @covers     \mod_selfselectadvanced\local\quota\slots::evaluate_from_data
 */
final class allocator_test extends \advanced_testcase {
    /**
     * One slot row, as the template array holds it.
     *
     * @param int $slotno the declared position
     * @param int $mincount seats
     * @param string $dimension the attribute dimension
     * @param string $matchtype 'value' or 'distinct'
     * @param string|null $value the required value, null for "any one value"
     * @param int $allowoverlap 1 to ignore the consumption registry
     * @return stdClass the slot row
     */
    private function slot(
        int $slotno,
        int $mincount,
        string $dimension,
        string $matchtype,
        ?string $value = null,
        int $allowoverlap = 0
    ): stdClass {
        return (object) [
            'slotno' => $slotno,
            'mincount' => $mincount,
            'dimension' => $dimension,
            'matchtype' => $matchtype,
            'value' => $value,
            'allowoverlap' => $allowoverlap,
        ];
    }

    /**
     * Attribute records keyed by user id.
     *
     * @param array $spec userid => [dimension => value]
     * @return stdClass[] attribute records keyed by user id
     */
    private function attrs(array $spec): array {
        $attrs = [];
        foreach ($spec as $userid => $values) {
            $attrs[(int) $userid] = (object) array_merge([
                'gender' => null,
                'department' => null,
                'subdepartment' => null,
                'program' => null,
            ], $values);
        }

        return $attrs;
    }

    /**
     * The finding's own scenario: two slots whose order used to decide
     * whether a perfectly seatable team was called compliant.
     */
    public function test_order_independent_same_value_then_fixed_value(): void {
        $attrs = $this->attrs([
            1 => ['department' => 'Computer'],
            2 => ['department' => 'Computer'],
            3 => ['department' => 'Math'],
            4 => ['department' => 'Math'],
        ]);
        $members = [1, 2, 3, 4];

        $samefirst = [
            $this->slot(1, 2, 'department', 'value', null, 0),
            $this->slot(2, 1, 'department', 'value', 'Computer', 0),
        ];
        $valuefirst = [
            $this->slot(1, 1, 'department', 'value', 'Computer', 0),
            $this->slot(2, 2, 'department', 'value', null, 0),
        ];

        $a = slots::evaluate_from_data($samefirst, $members, $attrs);
        $b = slots::evaluate_from_data($valuefirst, $members, $attrs);

        $this->assertTrue($a->ok, 'Two Maths share a department and a Computer takes the fixed seat');
        $this->assertSame(3, $a->totalfilled);
        $this->assertTrue($b->ok, 'The same team, the same seats, the opposite declaration order');
        $this->assertSame(3, $b->totalfilled);
    }

    /**
     * A distinct slot must not eat the only member a later value slot
     * could ever use.
     */
    public function test_distinct_slot_does_not_starve_a_later_value_slot(): void {
        $attrs = $this->attrs([
            1 => ['department' => 'Computer', 'subdepartment' => 'AI'],
            2 => ['department' => 'Computer', 'subdepartment' => 'ML'],
            3 => ['department' => 'Math', 'subdepartment' => 'Stats'],
        ]);
        $template = [
            $this->slot(1, 2, 'department', 'distinct', null, 0),
            $this->slot(2, 1, 'subdepartment', 'value', 'AI', 1),
        ];

        $result = slots::evaluate_from_data($template, [1, 2, 3], $attrs);

        $this->assertTrue($result->ok);
        $this->assertSame(3, $result->totalfilled);
        $this->assertSame(1, $result->assignment[1], 'Only user 1 can fill the AI seat, so the plan must leave them for it');
    }

    /**
     * Exclusivity: one person, two seats they both qualify for, one
     * seat filled. The tie-break puts them in the earlier seat, both
     * being equally restrictive.
     */
    public function test_a_member_never_fills_two_seats(): void {
        $attrs = $this->attrs([
            1 => ['gender' => 'Female', 'department' => 'Computer'],
        ]);
        $template = [
            $this->slot(1, 1, 'department', 'value', 'Computer', 1),
            $this->slot(2, 1, 'gender', 'value', 'Female', 1),
        ];

        $solution = allocator::solve($template, [1], $attrs);
        $result = slots::evaluate_from_data($template, [1], $attrs);

        $this->assertSame(1, $solution->totalfilled);
        $this->assertFalse($result->ok);
        $this->assertSame([1, 0], $solution->filled);
    }

    /**
     * A slot that allows overlap still RECORDS the value it used, and
     * the recording blocks a later no-overlap slot across dimensions.
     */
    public function test_allowoverlap_slot_still_consumes_its_values(): void {
        $attrs = $this->attrs([
            1 => ['department' => 'Computer', 'gender' => 'Male'],
            2 => ['department' => 'Computer', 'gender' => 'Male'],
            3 => ['department' => 'Computer', 'gender' => 'Female'],
        ]);
        $template = [
            $this->slot(1, 2, 'department', 'value', 'Computer', 1),
            $this->slot(2, 1, 'gender', 'value', 'Female', 0),
        ];

        $solution = allocator::solve($template, [1, 2, 3], $attrs);
        $result = slots::evaluate_from_data($template, [1, 2, 3], $attrs);

        $this->assertFalse($result->ok);
        $this->assertSame(2, $solution->totalfilled);
        $this->assertSame([2, 0], $solution->filled);
    }

    /**
     * The maintainer's least-restrictive placement rule, pinned where
     * it actually decides something: two maximum-fill seatings exist,
     * and the one reported must leave the shortfall on the seat FEWEST
     * people could fill.
     *
     * User 2 could take either the single Female seat (which only they
     * qualify for) or one of the two Computer seats (which both members
     * qualify for). Seating them in the roomier Computer pair fills the
     * same two seats and leaves the shortfall where it is genuinely
     * hard to fill, so that is the seating shown.
     */
    public function test_least_restrictive_seat_takes_the_shortfall(): void {
        $attrs = $this->attrs([
            1 => ['department' => 'Computer', 'gender' => 'Male'],
            2 => ['department' => 'Computer', 'gender' => 'Female'],
        ]);
        $template = [
            $this->slot(1, 1, 'gender', 'value', 'Female', 1),
            $this->slot(2, 2, 'department', 'value', 'Computer', 1),
        ];

        $solution = allocator::solve($template, [1, 2], $attrs);

        $this->assertSame(2, $solution->totalfilled);
        $this->assertSame([0, 2], $solution->filled, 'The shortfall belongs on the seat only one person can fill');
        $this->assertSame([1 => 1, 2 => 1], $solution->assignment);
    }

    /**
     * The oracle. Three hundred seeded random templates and rosters,
     * every one of them checked against a brute-force enumeration of
     * every possible seating written out longhand from the documented
     * rules. Nothing else in this suite proves the search is EXACT.
     */
    public function test_matches_brute_force_on_randomised_rosters(): void {
        mt_srand(20260731);

        $dimensions = ['department', 'subdepartment'];
        $vocabulary = ['Alpha', 'Beta', 'Gamma'];

        for ($case = 0; $case < 300; $case++) {
            $membercount = mt_rand(1, 6);
            $attrspec = [];
            for ($u = 1; $u <= $membercount; $u++) {
                $values = [];
                foreach ($dimensions as $dimension) {
                    $pick = mt_rand(0, 3);
                    $values[$dimension] = $pick < 3 ? $vocabulary[$pick] : null;
                }
                $attrspec[$u] = $values;
            }
            $attrs = $this->attrs($attrspec);
            $members = range(1, $membercount);

            $template = [];
            $slotcount = mt_rand(1, 3);
            for ($s = 1; $s <= $slotcount; $s++) {
                $matchtype = mt_rand(0, 1) ? 'value' : 'distinct';
                $value = null;
                if ($matchtype === 'value') {
                    $pick = mt_rand(0, 3);
                    $value = $pick < 3 ? $vocabulary[$pick] : null;
                }
                $template[] = $this->slot(
                    $s,
                    mt_rand(1, 3),
                    $dimensions[mt_rand(0, 1)],
                    $matchtype,
                    $value,
                    mt_rand(0, 1)
                );
            }

            $solution = allocator::solve($template, $members, $attrs);
            $expected = $this->brute_force($template, $members, $attrs);
            $this->assertSame(
                $expected,
                $solution->totalfilled,
                'Case ' . $case . ' disagrees with brute force: ' . json_encode([$template, $attrspec])
            );
            $this->assertTrue($solution->exact, 'Case ' . $case . ' fell back to the heuristic');
            $this->assertSame(
                $expected,
                array_sum($solution->filled),
                'Case ' . $case . ' reports a fill its own seat counts do not add up to'
            );
        }
    }

    /**
     * Identical inputs must produce byte-identical output, because the
     * composition check that runs before a lock and the one that runs
     * inside it must never disagree over unchanged data.
     */
    public function test_deterministic_across_repeated_runs(): void {
        $attrs = $this->attrs([
            1 => ['department' => 'Computer', 'subdepartment' => 'AI'],
            2 => ['department' => 'Computer', 'subdepartment' => 'ML'],
            3 => ['department' => 'Math', 'subdepartment' => 'Stats'],
        ]);
        $template = [
            $this->slot(1, 2, 'department', 'distinct', null, 0),
            $this->slot(2, 1, 'subdepartment', 'value', 'AI', 1),
        ];

        $first = serialize(allocator::solve($template, [1, 2, 3], $attrs));
        for ($run = 0; $run < 25; $run++) {
            $this->assertSame($first, serialize(allocator::solve($template, [1, 2, 3], $attrs)));
        }

        $one = slots::evaluate_from_data($template, [1, 2, 3], $attrs);
        $two = slots::evaluate_from_data($template, [1, 2, 3], $attrs);
        $this->assertSame($one->ok, $two->ok);
        $this->assertSame($one->assignment, $two->assignment);
        $this->assertSame(
            array_map(static fn($entry) => $entry->filled, $one->slots),
            array_map(static fn($entry) => $entry->filled, $two->slots)
        );
    }

    /**
     * The maximum seats any seating of this roster can fill, found by
     * enumerating EVERY member-to-seat-or-nowhere mapping and checking
     * it against the documented rules written out longhand.
     *
     * @param stdClass[] $template slot rows in template order
     * @param int[] $memberids the roster
     * @param stdClass[] $attrs attribute records keyed by user id
     * @return int the maximum number of seats fillable
     */
    private function brute_force(array $template, array $memberids, array $attrs): int {
        $members = array_values(array_unique(array_map('intval', $memberids)));
        sort($members);
        $slotcount = count($template);
        $choices = $slotcount + 1;
        $mappings = (int) ($choices ** count($members));

        $best = 0;
        for ($code = 0; $code < $mappings; $code++) {
            $assign = [];
            $remainder = $code;
            foreach ($members as $userid) {
                $assign[$userid] = $remainder % $choices;
                $remainder = intdiv($remainder, $choices);
            }
            $fill = $this->fill_of($template, $members, $attrs, $assign);
            if ($fill !== null && $fill > $best) {
                $best = $fill;
            }
        }

        return $best;
    }

    /**
     * The input-size guard and the heuristic behind it.
     *
     * Until 1.20 nothing in the suite reached greedy() at all:
     * replacing its whole body with a throw left the suite green, so
     * MAX_MEMBERS / MAX_SLOTS / MAX_SEATS, the OverflowException catch
     * and the fallback itself were reached by no test, and NOTHING
     * anywhere asserted `exact === false` - the only assertion on that
     * field was an assertTrue. The guard is not hypothetical: slot_form
     * accepts a mincount up to 50 and slots::create caps the number of
     * rows at nothing, so a manager can cross MAX_SEATS or MAX_SLOTS
     * through the plugin's own form.
     *
     * Each case crosses ONE guard by exactly one unit, sized from the
     * constant itself so a change to the constant moves the fixture
     * with it, and asserts the two properties that matter:
     *
     *  - the result says so: `exact === false`;
     *  - FAIL-CLOSED: the fill it reports is realised by the assignment
     *    it returns. fill_of() re-checks that assignment against the
     *    rules from scratch and must agree with both totalfilled and
     *    the per-slot counts, so the heuristic can under-report a team
     *    (which only ever refuses a compliant team - conservative) but
     *    can never claim a seating that does not exist, which would
     *    call a non-compliant team compliant.
     *
     * The analytic maximum is stated per case rather than brute-forced:
     * at 31 members brute force is 2^31 mappings.
     *
     * Negative control: replace the body of greedy() with a throw -
     * every case here dies, and before 1.20 that same throw left the
     * whole suite green.
     */
    public function test_the_input_size_guard_falls_back_and_stays_fail_closed(): void {
        $cases = [];

        // 1. One member over MAX_MEMBERS. One slot of three Computer
        // seats and an all-Computer roster, so the exact answer is 3.
        $template = [$this->slot(1, 3, 'department', 'value', 'Computer')];
        $spec = [];
        for ($u = 1; $u <= allocator::MAX_MEMBERS + 1; $u++) {
            $spec[$u] = ['department' => 'Computer'];
        }
        $cases['members'] = [$template, array_keys($spec), $this->attrs($spec), 3];

        // 2. One slot over MAX_SLOTS: N one-seat slots on N distinct
        // departments, one member per department, so the exact answer
        // is N.
        $slotcount = allocator::MAX_SLOTS + 1;
        $template = [];
        $spec = [];
        for ($s = 1; $s <= $slotcount; $s++) {
            $template[] = $this->slot($s, 1, 'department', 'value', 'Dept' . $s);
            $spec[$s] = ['department' => 'Dept' . $s];
        }
        $cases['slots'] = [$template, array_keys($spec), $this->attrs($spec), $slotcount];

        // 3. One seat over MAX_SEATS, on a small roster: a wide
        // Computer row plus a single Math seat. Five Computer members
        // and one Math member, so the exact answer is 6.
        $template = [
            $this->slot(1, allocator::MAX_SEATS, 'department', 'value', 'Computer'),
            $this->slot(2, 1, 'department', 'value', 'Math'),
        ];
        $spec = [];
        for ($u = 1; $u <= 5; $u++) {
            $spec[$u] = ['department' => 'Computer'];
        }
        $spec[6] = ['department' => 'Math'];
        $cases['seats'] = [$template, array_keys($spec), $this->attrs($spec), 6];

        foreach ($cases as $name => [$template, $members, $attrs, $exactmax]) {
            $solution = allocator::solve($template, $members, $attrs);

            $this->assertFalse(
                $solution->exact,
                $name . ': crossing the input-size guard must report exact = false'
            );

            // Fail-closed, checked rather than asserted: the returned
            // assignment is re-validated from scratch and must account
            // for exactly the fill the solver claims.
            $assign = array_fill_keys($members, count($template));
            foreach ($solution->assignment as $userid => $index) {
                $assign[(int) $userid] = (int) $index;
            }
            $realised = $this->fill_of($template, $members, $attrs, $assign);
            $this->assertNotNull(
                $realised,
                $name . ': the fallback returned an assignment that breaks the seating rules'
            );
            $this->assertSame(
                (int) $solution->totalfilled,
                $realised,
                $name . ': the fallback reports a fill its own assignment does not realise'
            );
            $this->assertSame(
                (int) $solution->totalfilled,
                array_sum($solution->filled),
                $name . ': the fallback reports a fill its own seat counts do not add up to'
            );
            $this->assertLessThanOrEqual(
                $exactmax,
                (int) $solution->totalfilled,
                $name . ': the fallback OVER-reports fill, which would call a non-compliant team compliant'
            );
        }
    }

    /**
     * The other side of the same boundary: one member BELOW
     * MAX_MEMBERS, and one seat below MAX_SEATS, are still decided
     * exactly. Without this the guard cases above would pass equally
     * against a build that had given up on exactness entirely.
     */
    public function test_just_inside_the_input_size_guard_is_still_exact(): void {
        $spec = [];
        for ($u = 1; $u <= allocator::MAX_MEMBERS; $u++) {
            $spec[$u] = ['department' => 'Computer'];
        }
        $solution = allocator::solve(
            [$this->slot(1, 3, 'department', 'value', 'Computer')],
            array_keys($spec),
            $this->attrs($spec)
        );
        $this->assertTrue($solution->exact);
        $this->assertSame(3, (int) $solution->totalfilled);

        $spec = [];
        for ($u = 1; $u <= 5; $u++) {
            $spec[$u] = ['department' => 'Computer'];
        }
        $spec[6] = ['department' => 'Math'];
        $solution = allocator::solve(
            [
                $this->slot(1, allocator::MAX_SEATS - 1, 'department', 'value', 'Computer'),
                $this->slot(2, 1, 'department', 'value', 'Math'),
            ],
            array_keys($spec),
            $this->attrs($spec)
        );
        $this->assertTrue($solution->exact);
        $this->assertSame(6, (int) $solution->totalfilled);
    }

    /**
     * The seats one candidate mapping fills, or null when it breaks a rule.
     *
     * @param stdClass[] $template slot rows in template order
     * @param int[] $members the roster, unique and ascending
     * @param stdClass[] $attrs attribute records keyed by user id
     * @param int[] $assign userid => slot index, or the slot count for "unseated"
     * @return int|null seats filled, or null when the mapping is invalid
     */
    private function fill_of(array $template, array $members, array $attrs, array $assign): ?int {
        $consumed = [];
        $fill = 0;

        foreach ($template as $index => $slot) {
            $booked = [];
            foreach ($members as $userid) {
                if ($assign[$userid] === $index) {
                    $booked[] = $userid;
                }
            }
            if (count($booked) > (int) $slot->mincount) {
                // A slot books at most mincount members.
                return null;
            }

            $values = [];
            foreach ($booked as $userid) {
                $value = \core_text::strtolower(trim((string) ($attrs[$userid]->{$slot->dimension} ?? '')));
                if ($value === '') {
                    // An empty value in the slot's dimension is never eligible.
                    return null;
                }
                if (empty($slot->allowoverlap)) {
                    foreach ($consumed as $dimension => $used) {
                        $own = \core_text::strtolower(trim((string) ($attrs[$userid]->{$dimension} ?? '')));
                        if ($own !== '' && isset($used[$own])) {
                            // A value recorded by an EARLIER slot.
                            return null;
                        }
                    }
                }
                $values[] = $value;
            }

            if ($slot->matchtype === 'value') {
                $target = $slot->value !== null ? \core_text::strtolower((string) $slot->value) : null;
                foreach ($values as $value) {
                    if ($value !== ($target ?? $values[0])) {
                        return null;
                    }
                }
                if ($values) {
                    $consumed[$slot->dimension][$values[0]] = true;
                }
            } else {
                if (count(array_unique($values)) !== count($values)) {
                    return null;
                }
                foreach ($values as $value) {
                    $consumed[$slot->dimension][$value] = true;
                }
            }
            $fill += count($booked);
        }

        return $fill;
    }
}
