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

use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\gridreport_table;

/**
 * The group grid report's data assembly (item 5d): one row per group,
 * the leader first and marked with an asterisk, the rest in joining
 * order, last names only, and always exactly as many member cells as
 * the requested column count - even when a group's own confirmed
 * headcount is smaller (blank cells) or larger (the overflow wraps
 * into the last cell, comma-separated).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\gridreport_table
 */
final class gridreport_table_test extends \advanced_testcase {
    /**
     * A course and activity, and the plugin generator.
     *
     * @return array{0: activity, 1: \stdClass, 2: \testing_data_generator} activity, course, generator
     */
    private function setup_activity(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        return [$activity, $course, $generator];
    }

    /**
     * The leader occupies the first member column, marked with a
     * trailing asterisk, and the rest follow in joining order
     * (COALESCE(timeresponded, timecreated) ascending) - never
     * alphabetically, unlike groups::get_roster().
     */
    public function test_leader_first_then_joining_order_by_lastname(): void {
        $this->resetAfterTest();
        [$activity, $course, $generator] = $this->setup_activity();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user(['lastname' => 'Zetaleader']);
        $first = $generator->create_user(['lastname' => 'Alphajoiner']);
        $second = $generator->create_user(['lastname' => 'Betajoiner']);

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);
        // Explicit, out-of-alphabetical-order response times: joining
        // order must win over any alphabetical sort of the surnames.
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $second->id,
            'timeresponded' => 2000,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $first->id,
            'timeresponded' => 1000,
        ]);

        $rows = gridreport_table::build_rows($activity, 4, '');

        $this->assertCount(1, $rows);
        // Alphajoiner responded at 1000, Betajoiner at 2000: response
        // time decides the order, not row-insertion order (Betajoiner's
        // member row was inserted first, above) and not surname order.
        $this->assertSame(
            ['Zetaleader*', 'Alphajoiner', 'Betajoiner', '-'],
            $rows[0]->membercells
        );
    }

    /**
     * The member cell count always equals the requested column count,
     * regardless of how many confirmed members a group actually holds.
     */
    public function test_column_count_matches_requested_membercols(): void {
        $this->resetAfterTest();
        [$activity, $course, $generator] = $this->setup_activity();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);

        foreach ([1, 3, 8] as $membercols) {
            $rows = gridreport_table::build_rows($activity, $membercols, '');
            $this->assertCount(1, $rows);
            $this->assertCount($membercols, $rows[0]->membercells);
        }
    }

    /**
     * A group holding more confirmed members than there are member
     * columns (a per-group override having raised its own effective
     * maximum beyond the activity's maxsize) wraps every member from
     * the last column onward into that column, comma-separated,
     * instead of growing the table.
     */
    public function test_overflow_wraps_into_last_column(): void {
        $this->resetAfterTest();
        [$activity, $course, $generator] = $this->setup_activity();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user(['lastname' => 'Leaderson']);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);
        $overflownames = ['Onejoiner', 'Twojoiner', 'Threejoiner'];
        foreach ($overflownames as $index => $lastname) {
            $member = $generator->create_user(['lastname' => $lastname]);
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => $member->id,
                'timeresponded' => 1000 + $index,
            ]);
        }

        // 4 confirmed members (leader + 3), only 2 member columns.
        $rows = gridreport_table::build_rows($activity, 2, '');

        $this->assertCount(1, $rows);
        $this->assertSame('Leaderson*', $rows[0]->membercells[0]);
        $this->assertSame(implode(', ', $overflownames), $rows[0]->membercells[1]);
    }

    /**
     * The guide column shows the guide's fullname when assigned, and a
     * dash otherwise.
     */
    public function test_guide_column_name_or_dash(): void {
        $this->resetAfterTest();
        [$activity, $course, $generator] = $this->setup_activity();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader1 = $generator->create_user();
        $guide = $generator->create_user(['firstname' => 'Gia', 'lastname' => 'Guideperson']);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader1->id,
            'guideid' => $guide->id,
            'name' => 'Guided group',
            'state' => state::PENDING_GUIDE,
        ]);

        $leader2 = $generator->create_user();
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader2->id,
            'name' => 'Guideless group',
            'state' => state::FORMING,
        ]);

        $rows = gridreport_table::build_rows($activity, 3, '');
        $byname = [];
        foreach ($rows as $row) {
            $byname[$row->rawname] = $row;
        }

        $this->assertSame(fullname($guide), $byname['Guided group']->guidename);
        $this->assertSame('-', $byname['Guideless group']->guidename);
    }

    /**
     * The name filter (rq) narrows the row set to groups whose name
     * matches, case-insensitively.
     */
    public function test_name_filter_narrows_rows(): void {
        $this->resetAfterTest();
        [$activity, $course, $generator] = $this->setup_activity();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader1 = $generator->create_user();
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader1->id,
            'name' => 'Team Mercury',
        ]);
        $leader2 = $generator->create_user();
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader2->id,
            'name' => 'Team Venus',
        ]);

        $rows = gridreport_table::build_rows($activity, 3, 'mercury');

        $this->assertCount(1, $rows);
        $this->assertSame('Team Mercury', $rows[0]->rawname);
    }

    /**
     * A leaderless group (no confirmed rows at all) renders every
     * member cell blank rather than erroring.
     */
    public function test_group_with_no_confirmed_members(): void {
        $this->resetAfterTest();
        [$activity, $course, $generator] = $this->setup_activity();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
            'skipleaderrow' => 1,
        ]);

        $rows = gridreport_table::build_rows($activity, 3, '');

        $this->assertCount(1, $rows);
        $this->assertSame(['-', '-', '-'], $rows[0]->membercells);
    }
}
