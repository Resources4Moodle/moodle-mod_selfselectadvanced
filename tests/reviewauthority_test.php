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
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\state;

/**
 * Who may write a team's guide notes and its gradebook award.
 *
 * review.php gates its page on require_capability(':guide') over the
 * ACTIVITY and takes the team from the 'g' URL parameter. Until 1.19.1
 * neither the savenotes nor the saveaward handler checked that the
 * caller was the guide ASSIGNED to that team, so any holder of :guide
 * could post to the page naming any team and rewrite another guide's
 * notes or set that team's award.
 *
 * That is not a narrow hole. Every non-editing teacher holds :guide,
 * and so does the Group Coordinator role this plugin creates — which
 * made a grade-tampering path out of a role the plugin hands out
 * itself. The 'approve' handler on the same page had always gated on
 * the assignment; these two simply did not.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper::can_grade_team
 */
final class reviewauthority_test extends \advanced_testcase {
    /**
     * A course with two guides, and a team assigned to the first.
     *
     * @return array [activity, api, group, assigned guide, other guide, course]
     */
    private function setup_two_guides(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $mine = $generator->create_user();
        $other = $generator->create_user();
        $generator->enrol_user($mine->id, $course->id, 'teacher');
        $generator->enrol_user($other->id, $course->id, 'teacher');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Team Under Review',
            'guideid' => $mine->id,
            'state' => state::FIRM,
        ]);

        return [$activity, new api($activity), $group, $mine, $other, $course];
    }

    /**
     * The guide the team is assigned to may write its notes and award.
     */
    public function test_the_assigned_guide_may_grade(): void {
        $this->resetAfterTest();
        [, $api, $group, $mine] = $this->setup_two_guides();

        $this->assertNull(
            $api->gatekeeper()->can_grade_team($group, (int) $mine->id),
            'The assigned guide must be able to write their own notes and award'
        );
    }

    /**
     * A different guide — with :guide, and therefore able to open the
     * page — may not touch this team.
     */
    public function test_another_guide_may_not_grade_someone_elses_team(): void {
        $this->resetAfterTest();
        [$activity, $api, $group, , $other] = $this->setup_two_guides();

        // They really do hold the capability the page gates on, which is
        // exactly why the missing assignment check mattered.
        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:guide',
            $activity->context(),
            $other->id
        ), 'Fixture is wrong: the other guide should hold :guide');

        $refusal = $api->gatekeeper()->can_grade_team($group, (int) $other->id);

        $this->assertNotNull($refusal, 'A guide who is not assigned must be refused');
        $this->assertSame('refusalnotassignedguide', $refusal->stringkey);
    }

    /**
     * A Group Coordinator is refused too. The plugin grants that role
     * :guide itself, so if the assignment were not checked the plugin
     * would be creating its own grade-tampering path.
     */
    public function test_a_coordinator_may_not_grade_a_team_they_do_not_guide(): void {
        $this->resetAfterTest();
        [$activity, $api, $group, , , $course] = $this->setup_two_guides();

        $coordinator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($coordinator->id, $course->id, 'teacher');
        $roleid = coordinatorrole::ensure();
        $this->assertGreaterThan(0, $roleid);
        role_assign($roleid, $coordinator->id, \context_course::instance($course->id));

        $refusal = $api->gatekeeper()->can_grade_team($group, (int) $coordinator->id);

        $this->assertNotNull($refusal, 'A coordinator who does not guide this team must be refused');
        $this->assertSame('refusalnotassignedguide', $refusal->stringkey);
    }

    /**
     * A manager keeps access: correcting an award is their job, and
     * review.php is the only place an award can be set at all.
     */
    public function test_a_manager_may_grade(): void {
        $this->resetAfterTest();
        [$activity, $api, $group, , , $course] = $this->setup_two_guides();

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'editingteacher');
        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:manage',
            $activity->context(),
            $manager->id
        ), 'Fixture is wrong: an editing teacher should hold :manage');

        $this->assertNull(
            $api->gatekeeper()->can_grade_team($group, (int) $manager->id),
            'A manager must keep administrative access to the award'
        );
    }

    /**
     * A team with no guide yet is not open season: an unassigned team
     * still refuses everyone but a manager.
     */
    public function test_an_unguided_team_still_refuses_a_passing_guide(): void {
        $this->resetAfterTest();
        [$activity, $api, , , $other, $course] = $this->setup_two_guides();

        $leader = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($leader->id, $course->id, 'student');
        $unguided = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'name' => 'Team Without A Guide',
        ]);

        $refusal = $api->gatekeeper()->can_grade_team($unguided, (int) $other->id);

        $this->assertNotNull($refusal, 'An unassigned team must not be writable by any passing guide');
    }
}
