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
        ]);
        slots::create($activity, (object) [
            'mincount' => 3, 'dimension' => 'department', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ]);
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
        ]);
        slots::create($activity2, (object) [
            'mincount' => 3, 'dimension' => 'department', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ]);
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
        ]);
        slots::create($activity3, (object) [
            'mincount' => 3, 'dimension' => 'department', 'matchtype' => 'distinct', 'allowoverlap' => 1,
        ]);
        $result = slots::evaluate($activity3, $groupid3);
        $this->assertTrue($result->ok);

        // Gender joins the mix: an extra slot books one Female; the
        // same person cannot be double-booked, so a second
        // gender-Female slot would starve.
        slots::create($activity3, (object) [
            'mincount' => 1, 'dimension' => 'gender', 'matchtype' => 'value', 'value' => 'Female',
        ]);
        $result = slots::evaluate($activity3, $groupid3);
        $this->assertFalse($result->ok);
        $lastslot = end($result->slots);
        $this->assertSame(0, $lastslot->filled);
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
        ]);
        $this->assertFalse(evaluator::is_compliant($activity, $groupid));
        $report = evaluator::evaluate($activity, $groupid);
        $entry = end($report->rules);
        $this->assertStringContainsString('Computer', $entry->label);

        slots::delete($activity, (int) slots::get_all($activity)[0]->id);
        $this->assertTrue(evaluator::is_compliant($activity, $groupid));

        // Blank value means "n from any ONE value": 2 share Computer? No -
        // one each, so a 2-same slot is deficient; adding a second
        // Computer member satisfies it.
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'value' => '',
        ]);
        $this->assertFalse(evaluator::is_compliant($activity, $groupid));
    }
}
