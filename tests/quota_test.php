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

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\quota\evaluator;
use mod_selfselectadvanced\local\quota\store;

/**
 * Quota rules (spec 8.2): evaluation with priority ordering, boundary
 * behaviour, missing-attribute handling, the S1-safe store, and the
 * submission/approval gates with real rules.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\evaluator
 * @covers     \mod_selfselectadvanced\local\quota\store
 */
final class quota_test extends \advanced_testcase {
    /**
     * Create a course, instance, students with attributes, and a group.
     *
     * @return array [activity, api, group, students[]]
     */
    private function setup_group(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);

        $profiles = [
            ['Female', 'Civil', 'Structures'],
            ['Male', 'Civil', 'Hydraulics'],
            ['Female', 'Mechanical', 'Design'],
            [null, null, null], // Missing attributes.
        ];
        $students = [];
        foreach ($profiles as $i => [$gender, $dept, $subdept]) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            if ($gender !== null) {
                $plugingen->create_userattr([
                    'userid' => $user->id,
                    'gender' => $gender,
                    'department' => $dept,
                    'subdepartment' => $subdept,
                ]);
            }
            $students[] = $user;
        }

        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Quota',
        ]);
        foreach ([1, 2, 3] as $i) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $students[$i]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return [$activity, $api, groups::get($activity, (int) $group->id), $students];
    }

    /**
     * Value rules at the boundary (one below, exactly at, one above),
     * case-insensitive matching, and the explicit deficiency wording.
     */
    public function test_value_rule_boundaries(): void {
        $this->resetAfterTest();
        [$activity, , $group] = $this->setup_group();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Roster: 2 Female, 1 Male, 1 unknown.
        // Exactly at: >= 2 Female is satisfied.
        $rule = $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'gender',
            'value' => 'female',
            'mincount' => 2,
        ]);
        $report = evaluator::evaluate($activity, (int) $group->id);
        $this->assertTrue($report->compliant);
        $this->assertSame(2, $report->rules[0]->current);

        // One below: >= 3 Female fails with the worded deficiency.
        global $DB;
        $DB->set_field('selfselectadvanced_quota', 'mincount', 3, ['id' => $rule->id]);
        $report = evaluator::evaluate($activity, (int) $group->id);
        $this->assertFalse($report->compliant);
        $this->assertStringContainsString('Needs 1 more', $report->rules[0]->deficiency);

        // Maximum side: <= 0 Male fails, <= 1 passes.
        $DB->delete_records('selfselectadvanced_quota');
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'gender',
            'value' => 'Male',
            'maxcount' => 0,
        ]);
        $report = evaluator::evaluate($activity, (int) $group->id);
        $this->assertFalse($report->compliant);
        $this->assertStringContainsString('too many', $report->rules[0]->deficiency);
        $DB->set_field('selfselectadvanced_quota', 'maxcount', 1, []);
        $this->assertTrue(evaluator::is_compliant($activity, (int) $group->id));

        // Unknown-attribute members are surfaced, never counted.
        $this->assertSame(1, $report->unknowncount);
    }

    /**
     * Distinct rules and the priority ordering of the report.
     */
    public function test_distinct_rule_and_priority_order(): void {
        $this->resetAfterTest();
        [$activity, , $group] = $this->setup_group();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Two rules created in reverse priority order.
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'subdepartment',
            'rtype' => 'distinct',
            'mincount' => 4,
            'priority' => 2,
        ]);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'value' => 'Civil',
            'mincount' => 1,
            'priority' => 1,
        ]);

        $report = evaluator::evaluate($activity, (int) $group->id);
        // Priority order respected: the Civil rule (priority 1) first.
        $this->assertSame('department', $report->rules[0]->dimension);
        $this->assertTrue($report->rules[0]->satisfied);
        // Distinct: 3 distinct sub-departments < 4 required.
        $this->assertSame('distinct', $report->rules[1]->rtype);
        $this->assertSame(3, $report->rules[1]->current);
        $this->assertFalse($report->rules[1]->satisfied);
        $this->assertFalse($report->compliant);
    }

    /**
     * The gates consume real rules now: submission and approval are
     * refused while deficient and pass once satisfied (spec 8.2 gates
     * a and b).
     */
    public function test_gates_enforce_quota(): void {
        $this->resetAfterTest();
        [$activity, $api, $group, $students] = $this->setup_group();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $activity->courseid(), 'teacher');

        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'value' => 'Mechanical',
            'mincount' => 2,
        ]);

        // Submit refused with the quota reason (1 Mechanical < 2).
        $leader = (int) $students[0]->id;
        $this->assertSame('refusalquota', $api->gatekeeper()->can_submit($group, $leader)?->stringkey);

        // Satisfy the rule by adding attributes to the unknown member.
        $plugingen->create_userattr([
            'userid' => (int) $students[3]->id,
            'gender' => 'Male',
            'department' => 'Mechanical',
            'subdepartment' => 'Thermal',
        ]);
        $this->assertNull($api->gatekeeper()->can_submit($group, $leader));

        // Approval re-checks quota: break compliance after submission.
        $fresh = $api->lifecycle()->submit($group, (int) $guide->id, $leader);
        global $DB;
        $DB->set_field('selfselectadvanced_quota', 'mincount', 5, []);
        $this->assertSame('refusalquota', $api->gatekeeper()->can_approve($fresh, (int) $guide->id)?->stringkey);
    }

    /**
     * The S1 store: appended priorities stay unique, moves swap
     * neighbours and renumber 1..n, deletes close gaps.
     */
    public function test_store_reorder_safety(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_group();

        $r1 = store::save($activity, (object) [
            'rtype' => 'value',
            'dimension' => 'gender',
            'value' => 'Female',
            'mincount' => 1,
            'maxcount' => null,
        ]);
        $r2 = store::save($activity, (object) [
            'rtype' => 'distinct',
            'dimension' => 'department',
            'value' => null,
            'mincount' => 2,
            'maxcount' => null,
        ]);
        $r3 = store::save($activity, (object) [
            'rtype' => 'value',
            'dimension' => 'department',
            'value' => 'Civil',
            'mincount' => null,
            'maxcount' => 3,
        ]);

        $this->assertSame([1, 2, 3], array_map(fn($r) => (int) $r->priority, store::get_all($activity)));

        // Move the last rule up twice: order r3, r1, r2; priorities unique 1..n.
        store::move($activity, (int) $r3->id, -1);
        store::move($activity, (int) $r3->id, -1);
        $all = store::get_all($activity);
        $this->assertSame([(int) $r3->id, (int) $r1->id, (int) $r2->id], array_map(fn($r) => (int) $r->id, $all));
        $this->assertSame([1, 2, 3], array_map(fn($r) => (int) $r->priority, $all));

        // Moving the top rule up is a no-op.
        store::move($activity, (int) $r3->id, -1);
        $this->assertSame((int) $r3->id, (int) store::get_all($activity)[0]->id);

        // Delete the middle rule: gap closes.
        store::delete($activity, (int) $r1->id);
        $all = store::get_all($activity);
        $this->assertSame([(int) $r3->id, (int) $r2->id], array_map(fn($r) => (int) $r->id, $all));
        $this->assertSame([1, 2], array_map(fn($r) => (int) $r->priority, $all));
    }
}
