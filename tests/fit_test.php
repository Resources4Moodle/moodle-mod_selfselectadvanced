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
use mod_selfselectadvanced\local\fit;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\quota\slots;

/**
 * The selection rules a student sees when choosing a team to join.
 *
 * The maintainer's requirement, in their words: a student who does not
 * fit the group-formation logic is "listed with caution that the
 * student will not fit the requirements", and a team holding "the
 * particular seat that the student will fit if filled" says which seat
 * that is. So the two properties under test are that a misfit is
 * REPORTED rather than hidden, and that the named seat is the seat the
 * booking algorithm would actually give them.
 *
 * The plan is the live one: five members, two from department Computer
 * and three distinct sub-departments.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\fit
 */
final class fit_test extends \advanced_testcase {
    /**
     * An exactly-five activity with the seat plan and an attributed cast.
     *
     * @return array [activity, students keyed by shorthand, course]
     */
    private function setup_plan(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 5,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $cast = [
            'c1' => ['Computer', 'AI'],
            'c2' => ['Computer', 'ML'],
            'c3' => ['Computer', 'Hardware'],
            'p1' => ['Science', 'Physics'],
            'b1' => ['Science', 'Biology'],
            'k1' => ['Science', 'Chemistry'],
            'p2' => ['Science', 'Physics'],
        ];
        $students = [];
        foreach ($cast as $key => [$dept, $subdept]) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => $subdept], 2);
            $students[$key] = $user;
        }

        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 0,
        ]);
        slots::create($activity, (object) [
            'mincount' => 3, 'dimension' => 'subdepartment', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ]);

        return [$activity, $students, $course];
    }

    /**
     * A team short of its second Computer member names that seat to the
     * Computer student who would fill it.
     */
    public function test_seat_waiting_is_named(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Seated: one Computer, two distinct Science sub-departments.
        // The plan still wants a second Computer.
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Alpha',
        ]);
        foreach (['p1', 'b1'] as $key) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => $students[$key]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $verdict = fit::for_person($activity, $group, (int) $students['c2']->id);

        $this->assertTrue($verdict->fits, 'A second Computer student fits a team wanting one');
        $this->assertSame('', $verdict->caution);
        $this->assertNotNull($verdict->seat, 'The waiting Computer seat must be named');
        $this->assertStringContainsStringIgnoringCase('computer', $verdict->seat);
    }

    /**
     * A student the composition rules cannot accommodate is REPORTED
     * with the reason, not silently dropped: the leader keeps the
     * choice, and the student is told why it is a hard sell.
     */
    public function test_a_misfit_is_reported_not_hidden(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Seated: one Computer plus three distinct Science
        // sub-departments = four of five. The last seat MUST be the
        // second Computer, so a fourth Science student cannot be
        // accommodated.
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Beta',
        ]);
        foreach (['p1', 'b1', 'k1'] as $key) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => $students[$key]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $verdict = fit::for_person($activity, $group, (int) $students['p2']->id);

        $this->assertFalse($verdict->fits, 'A duplicate Science sub-department cannot take the Computer seat');
        $this->assertNotSame('', $verdict->caution, 'The reason must be stated, not left blank');
    }

    /**
     * The bulk path the picker uses must agree with the authoritative
     * per-person path, team by team. If these two ever drifted apart a
     * student would be shown one answer and given another.
     */
    public function test_bulk_agrees_with_the_gate(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $alpha = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Alpha',
        ]);
        $plugingen->create_member([
            'groupid' => $alpha->id,
            'userid' => $students['p1']->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $beta = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c3']->id,
            'name' => 'Team Beta',
        ]);
        foreach (['b1', 'k1'] as $key) {
            $plugingen->create_member([
                'groupid' => $beta->id,
                'userid' => $students[$key]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $candidate = (int) $students['c2']->id;
        $groups = [$alpha, $beta];
        $bulk = fit::for_groups($activity, $groups, $candidate);

        foreach ($groups as $group) {
            $single = fit::for_person($activity, $group, $candidate);
            $this->assertSame(
                $single->fits,
                $bulk[(int) $group->id]->fits,
                'Picker and gate disagree on whether ' . $group->name . ' fits'
            );
            $this->assertSame(
                $single->seat,
                $bulk[(int) $group->id]->seat,
                'Picker and gate name different seats for ' . $group->name
            );
        }
    }

    /**
     * The picker's cost must not grow with the number of teams it
     * annotates: this plugin is built for fifteen hundred teams and the
     * control fires on every keystroke. Judging four teams must cost no
     * more queries than judging one, plus a small fixed margin.
     */
    public function test_bulk_cost_does_not_grow_with_team_count(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $leaders = ['c1', 'c3', 'p1', 'b1'];
        $groups = [];
        foreach ($leaders as $index => $key) {
            $groups[] = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => $students[$key]->id,
                'name' => 'Team ' . $index,
            ]);
        }
        $candidate = (int) $students['c2']->id;

        // Warm anything the first call would populate once, so what is
        // measured is the marginal cost of the teams themselves.
        fit::for_groups($activity, [$groups[0]], $candidate);

        $before = $DB->perf_get_reads();
        fit::for_groups($activity, [$groups[0]], $candidate);
        $one = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        fit::for_groups($activity, $groups, $candidate);
        $four = $DB->perf_get_reads() - $before;

        $this->assertLessThanOrEqual(
            $one + 2,
            $four,
            "Judging four teams cost $four reads against $one for a single team: the picker is scaling per team"
        );
    }

    /**
     * A team that is not forming is reported as unavailable rather than
     * silently offered, and no seat is invented for it.
     */
    public function test_a_settled_team_reports_its_state(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Firm',
            'state' => \mod_selfselectadvanced\local\state::FIRM,
        ]);

        $bulk = fit::for_groups($activity, [$group], (int) $students['c2']->id);
        $verdict = $bulk[(int) $group->id];

        $this->assertFalse($verdict->fits);
        $this->assertNotSame('', $verdict->caution);
        $this->assertNull($verdict->seat, 'A team that is not forming has no seat to offer');
    }
}
