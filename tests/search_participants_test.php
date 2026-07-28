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

use mod_selfselectadvanced\external\search_participants;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;

/**
 * The move form's participant search: authorised by the plugin's own
 * manage capability in the module context, so a coordinator who holds
 * their role only inside the course can use the form (core's site-wide
 * user selector demanded a system capability they never have).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\external\search_participants
 */
final class search_participants_test extends \advanced_testcase {
    /**
     * A course-level coordinator can search, results are scoped to the
     * activity's participants, and each carries their current team.
     */
    public function test_course_coordinator_can_search_participants(): void {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->resetAfterTest();

        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'minsize' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user(['firstname' => 'Student SCOPE', 'lastname' => '26BCE0001']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $loner = $generator->create_user(['firstname' => 'Student SENSE', 'lastname' => '26BEC0002']);
        $generator->enrol_user($loner->id, $course->id, 'student');
        // Enrolled on another course only: must never be offered here.
        $othercourse = $generator->create_course();
        $outsider = $generator->create_user(['firstname' => 'Student SMEC', 'lastname' => '26BME0003']);
        $generator->enrol_user($outsider->id, $othercourse->id, 'student');

        // The generator gives the group its leader's membership row.
        $plugingen->create_group(['activityid' => $activity->id(),
            'leaderid' => (int) $leader->id, 'name' => 'Alpha', 'state' => state::FORMING]);

        // The coordinator holds their role in the COURSE only - exactly
        // the case core's selector could not serve.
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'editingteacher');
        $this->setUser($coordinator);

        $results = search_participants::execute((int) $activity->cm()->id, '26B');
        $labels = array_column($results, 'label');
        $ids = array_column($results, 'id');

        $this->assertContains((int) $leader->id, $ids);
        $this->assertContains((int) $loner->id, $ids);
        $this->assertNotContains((int) $outsider->id, $ids, 'a non-participant was offered');

        $inteam = array_values(array_filter($labels, static fn($l) => str_contains($l, 'Alpha')));
        $this->assertCount(1, $inteam, 'the team a person belongs to must be shown');
        $this->assertNotEmpty(array_filter($labels, static fn($l) => str_contains($l, 'no team yet')));

        // Searching by register number finds exactly that student.
        $byregno = search_participants::execute((int) $activity->cm()->id, '26BEC0002');
        $this->assertCount(1, $byregno);
        $this->assertSame((int) $loner->id, $byregno[0]['id']);

        // An empty query returns nothing rather than the whole cohort
        // (a whitespace-only one never reaches us: the external layer
        // refuses it as untrimmed).
        $this->assertSame([], search_participants::execute((int) $activity->cm()->id, ''));
    }

    /**
     * A student cannot use the coordinator's search.
     */
    public function test_a_student_is_refused(): void {
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();

        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        search_participants::execute((int) $activity->cm()->id, 'Student');
    }
}
