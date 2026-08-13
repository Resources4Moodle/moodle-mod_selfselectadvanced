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

use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\quota\evaluator;
use mod_selfselectadvanced\local\quota\slots;
use mod_selfselectadvanced\local\state;

/**
 * Slot-based composition templates (1.3.0): members are booked into at
 * most one slot, so remaining requirements adjust; must-match and
 * must-not-match via slot order and the overlap flag.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\slots
 */
final class slots_test extends \advanced_testcase {
    /**
     * Build an activity with one group of members carrying attributes.
     *
     * @param array[] $memberattrs one [department, gender] per member
     * @return array [activity, groupid]
     */
    private function setup_group(array $memberattrs): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id, 'maxsize' => 10]);
        $activity = activity::from_instance((int) $instance->id);

        $group = null;
        foreach ($memberattrs as $i => [$dept, $gender]) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'gender' => $gender], 2);
            if ($i === 0) {
                $group = $plugingen->create_group([
                    'activityid' => $activity->id(),
                    'leaderid' => (int) $user->id,
                    'name' => 'G',
                    'state' => state::FORMING,
                ]);
            } else {
                $plugingen->create_member([
                    'groupid' => $group->id,
                    'userid' => (int) $user->id,
                    'status' => groups::STATUS_CONFIRMED,
                ]);
            }
        }

        return [$activity, (int) $group->id];
    }

    /**
     * The requested scenario: "two members from one department, and
     * three each from any other distinct department" — with and
     * without permitting the first department to reappear.
     */
    public function test_two_from_one_three_distinct(): void {
        $this->resetAfterTest();

        // 2 Computer + CS/EE/ME distinct = compliant.
        [$activity, $groupid] = $this->setup_group([
            ['Computer', 'Male'], ['Computer', 'Female'],
            ['Civil', 'Male'], ['Electrical', 'Female'], ['Mechanical', 'Male'],
        ]);
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        slots::create($activity, (object) [
            'mincount' => 3, 'dimension' => 'department', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        $result = slots::evaluate($activity, $groupid);
        $this->assertTrue($result->ok);
        $this->assertSame([2, 3], [$result->slots[0]->filled, $result->slots[1]->filled]);

        // Booking adjusts: a THIRD Computer student cannot fill the
        // distinct slot (Computer is consumed by slot 1) - deficient.
        [$activity2, $groupid2] = $this->setup_group([
            ['Computer', 'Male'], ['Computer', 'Female'], ['Computer', 'Male'],
            ['Civil', 'Male'], ['Electrical', 'Female'],
        ]);
        slots::create($activity2, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        slots::create($activity2, (object) [
            'mincount' => 3, 'dimension' => 'department', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        $result = slots::evaluate($activity2, $groupid2);
        $this->assertFalse($result->ok);
        $this->assertSame(1, $result->slots[1]->missing);
        $this->assertStringContainsString('Need 1 more', $result->slots[1]->deficiency);

        // With allowoverlap the extra Computer student is permitted in
        // the distinct slot: compliant again ("if needed a computer
        // student could also be permitted there").
        [$activity3, $groupid3] = $this->setup_group([
            ['Computer', 'Male'], ['Computer', 'Female'], ['Computer', 'Male'],
            ['Civil', 'Male'], ['Electrical', 'Female'],
        ]);
        slots::create($activity3, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        slots::create($activity3, (object) [
            'mincount' => 3, 'dimension' => 'department', 'matchtype' => 'distinct', 'allowoverlap' => 1,
        ], (int) get_admin()->id);
        $result = slots::evaluate($activity3, $groupid3);
        $this->assertTrue($result->ok);

        // Gender joins the mix: an extra slot books one Female; the
        // same person cannot be double-booked, so a second
        // gender-Female slot would starve.
        slots::create($activity3, (object) [
            'mincount' => 1, 'dimension' => 'gender', 'matchtype' => 'value', 'value' => 'Female',
        ], (int) get_admin()->id);
        $result = slots::evaluate($activity3, $groupid3);
        $this->assertFalse($result->ok);
        $lastslot = end($result->slots);
        // REVERSED 2026-08-13. This asserted 0 - the gender-Female seat left
        // empty because the department seats had already taken both Female
        // students. The team is five people against six seats, so one seat is
        // short whichever way they sit; but reporting the Female seat as empty
        // while two Female students sit in the group is the false report the
        // maintainer found on a live group. The scarce seat is filled now and
        // the shortfall lands on the distinct seat, which anybody can fill.
        $this->assertSame(1, $lastslot->filled, 'the Female seat was starved by seats anybody could fill');
    }

    /**
     * No-overlap exclusion works ACROSS dimensions: after "2 with
     * Department Computer", a third Computer student cannot take a
     * distinct-SUB-department seat, because their department value was
     * consumed by the earlier seat rule. With overlap they can.
     */
    public function test_no_overlap_excludes_across_dimensions(): void {
        $this->resetAfterTest();

        $build = function (array $memberattrs) {
            $generator = $this->getDataGenerator();
            $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
            $course = $generator->create_course();
            $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id, 'maxsize' => 10]);
            $activity = activity::from_instance((int) $instance->id);
            $group = null;
            foreach ($memberattrs as $i => [$dept, $subdept]) {
                $user = $generator->create_user();
                $generator->enrol_user($user->id, $course->id, 'student');
                manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => $subdept], 2);
                if ($i === 0) {
                    $group = $plugingen->create_group([
                        'activityid' => $activity->id(),
                        'leaderid' => (int) $user->id,
                        'name' => 'G',
                        'state' => state::FORMING,
                    ]);
                } else {
                    $plugingen->create_member([
                        'groupid' => $group->id,
                        'userid' => (int) $user->id,
                        'status' => groups::STATUS_CONFIRMED,
                    ]);
                }
            }

            return [$activity, (int) $group->id];
        };

        // A third Computer student (Hardware) must NOT count among the
        // three distinct sub-departments: deficient by one.
        [$activity, $groupid] = $build([
            ['Computer', 'AI'], ['Computer', 'ML'], ['Computer', 'Hardware'],
            ['Science', 'Physics'], ['Science', 'Biology'],
        ]);
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        slots::create($activity, (object) [
            'mincount' => 3, 'dimension' => 'subdepartment', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        $result = slots::evaluate($activity, $groupid);
        $this->assertFalse($result->ok);
        $this->assertSame(2, $result->slots[0]->filled);
        $this->assertSame(1, $result->slots[1]->missing);

        // Three genuinely non-Computer sub-departments: compliant.
        [$activity2, $groupid2] = $build([
            ['Computer', 'AI'], ['Computer', 'AI'],
            ['Science', 'Physics'], ['Science', 'Biology'], ['Science', 'Chemistry'],
        ]);
        slots::create($activity2, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        slots::create($activity2, (object) [
            'mincount' => 3, 'dimension' => 'subdepartment', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        $result = slots::evaluate($activity2, $groupid2);
        $this->assertTrue($result->ok);

        // The overlap tick restores the permissive reading: the third
        // Computer student may fill the distinct sub-department seat.
        [$activity3, $groupid3] = $build([
            ['Computer', 'AI'], ['Computer', 'ML'], ['Computer', 'Hardware'],
            ['Science', 'Physics'], ['Science', 'Biology'],
        ]);
        slots::create($activity3, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        slots::create($activity3, (object) [
            'mincount' => 3, 'dimension' => 'subdepartment', 'matchtype' => 'distinct', 'allowoverlap' => 1,
        ], (int) get_admin()->id);
        $result = slots::evaluate($activity3, $groupid3);
        $this->assertTrue($result->ok);
    }

    /**
     * Slots and classic rules gate compliance together through the
     * evaluator (submission/approval/freeze consume is_compliant).
     */
    public function test_evaluator_integration(): void {
        $this->resetAfterTest();

        [$activity, $groupid] = $this->setup_group([
            ['Computer', 'Male'], ['Civil', 'Female'],
        ]);
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        $this->assertFalse(evaluator::is_compliant($activity, $groupid));
        $report = evaluator::evaluate($activity, $groupid);
        $entry = end($report->rules);
        $this->assertStringContainsString('Computer', $entry->label);

        slots::delete($activity, (int) slots::get_all($activity)[0]->id, (int) get_admin()->id);
        $this->assertTrue(evaluator::is_compliant($activity, $groupid));

        // Blank value means "n from any ONE value": 2 share Computer? No -
        // one each, so a 2-same slot is deficient; adding a second
        // Computer member satisfies it.
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => '',
        ], (int) get_admin()->id);
        $this->assertFalse(evaluator::is_compliant($activity, $groupid));
    }

    /**
     * A team the plan can satisfy only by an EXOTIC seating - one no
     * single pass through the slots in order would find - is accepted.
     *
     * Two Computer and two Maths students, a "two from ONE department"
     * seat followed by a "one from Computer" seat: the only seating
     * that works puts the MATHS pair in the shared seat, which the old
     * single-pass booking never tried because it always took the first
     * largest group it saw.
     */
    public function test_exact_engine_accepts_a_valid_exotic_assignment(): void {
        $this->resetAfterTest();

        [$activity, $groupid] = $this->setup_group([
            ['Computer', 'Male'], ['Computer', 'Female'],
            ['Math', 'Male'], ['Math', 'Female'],
        ]);
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => '',
        ], (int) get_admin()->id);
        slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);

        $result = slots::evaluate($activity, $groupid);

        $this->assertTrue($result->ok, 'Maths pair in the shared seat, a Computer in the fixed one');
        $this->assertSame(3, $result->totalfilled);
    }

    /**
     * The same roster and the same two seats declared in either order
     * reach the same verdict. Declaration order still decides which
     * values a later seat must avoid; what it must never decide is
     * whether the team can be seated at all.
     */
    public function test_slot_order_does_not_change_the_verdict(): void {
        $this->resetAfterTest();

        $roster = [
            ['Computer', 'Male'], ['Computer', 'Female'],
            ['Math', 'Male'], ['Math', 'Female'],
        ];

        [$first, $firstgroup] = $this->setup_group($roster);
        slots::create($first, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => '',
        ], (int) get_admin()->id);
        slots::create($first, (object) [
            'mincount' => 1, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);

        [$second, $secondgroup] = $this->setup_group($roster);
        slots::create($second, (object) [
            'mincount' => 1, 'dimension' => 'department', 'matchtype' => 'value', 'value' => 'Computer',
        ], (int) get_admin()->id);
        slots::create($second, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => '',
        ], (int) get_admin()->id);

        $this->assertTrue(slots::evaluate($first, $firstgroup)->ok);
        $this->assertTrue(slots::evaluate($second, $secondgroup)->ok);
    }
}
