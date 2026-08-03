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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * The guide search behind every picker (strategy 1.18 B).
 *
 * What the whole feature rests on: the service returns something the
 * chooser can act on, it never returns an address, and somebody with
 * no part in the activity cannot call it at all.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\external\search_guides
 * @covers     \mod_selfselectadvanced\external\search_groups
 */
final class external_guidesearch_test extends \externallib_advanced_testcase {
    /**
     * A course with two guides, a student and an outsider.
     *
     * @return array [activity, guide, fullguide, student, outsider]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxguided' => 2,
        ]);

        $guide = $generator->create_user([
            'firstname' => 'Meera',
            'lastname' => 'Iyer',
            'email' => 'meera.iyer@example.edu',
        ]);
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $fullguide = $generator->create_user(['firstname' => 'Ravi', 'lastname' => 'Menon']);
        $generator->enrol_user($fullguide->id, $course->id, 'teacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $outsider = $generator->create_user();

        return [activity::from_instance((int) $instance->id), $guide, $fullguide, $student, $outsider];
    }

    /**
     * A student choosing a guide gets name, department and load - and
     * no address anywhere in the payload, which is the rule the
     * approach workflow is built on.
     */
    public function test_a_student_can_search_and_sees_no_address(): void {
        $this->resetAfterTest();
        [$activity, $guide, , $student] = $this->setup_world();
        // Written as the site administrator: attributes\manager::set()
        // authorises its actor against :ingestattributes at system
        // context (audit A-6), so a guide can no longer write their own
        // attribute row. This test asks a READ-side question - what the
        // guide picker shows a student - and the write is only its
        // fixture, so the actor changes and nothing else does.
        local\attributes\manager::set((int) $guide->id, ['department' => 'SENSE'], (int) get_admin()->id);

        $this->setUser($student);
        $result = \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Meera');

        $this->assertCount(1, $result);
        $this->assertSame((int) $guide->id, $result[0]['id']);
        $this->assertSame('SENSE', $result[0]['department']);
        $this->assertStringContainsString('SENSE', $result[0]['label']);

        $payload = json_encode($result);
        $this->assertStringNotContainsString('@', $payload);
        $this->assertStringNotContainsString('example.edu', $payload);
    }

    /**
     * An empty query returns nothing rather than the whole school -
     * the entire point of the control.
     *
     * Whitespace never reaches the function at all: PARAM_RAW_TRIMMED
     * refuses it in core's own validation, which is checked here too so
     * that a later loosening of the parameter type cannot quietly turn
     * a stray space into a request for every guide in the school.
     */
    public function test_an_empty_query_returns_nothing(): void {
        $this->resetAfterTest();
        [$activity, , , $student] = $this->setup_world();

        $this->setUser($student);
        $this->assertSame([], \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, ''));

        $this->expectException(\core\exception\invalid_parameter_exception::class);
        \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, '   ');
    }

    /**
     * Students-approach mode hides the load from the teams choosing,
     * and only from them: "Guiding 2 of 3" IS advertised availability,
     * which is the thing that mode exists to stop.
     *
     * The staff assigning work, and a guide nominating a successor,
     * still see it - they are deciding about workload, not shopping.
     */
    public function test_students_approach_hides_the_load_from_teams_only(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxguided' => 5,
            'studentapproach' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $guide = $generator->create_user(['firstname' => 'Meera', 'lastname' => 'Iyer']);
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        $this->setUser($student);
        $seen = \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Meera', false);
        $this->assertCount(1, $seen);
        $this->assertStringNotContainsString('Guiding', $seen[0]['label']);
        $this->assertStringContainsString('Meera', $seen[0]['label']);

        $this->setUser($manager);
        $staffsees = \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Meera', false);
        $this->assertStringContainsString('Guiding', $staffsees[0]['label']);

        // The guide themselves, nominating a successor, sees it too.
        $this->setUser($guide);
        $guidesees = \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Meera', false);
        $this->assertStringContainsString('Guiding', $guidesees[0]['label']);
    }

    /**
     * With the mode off, a team sees the load as they always have.
     */
    public function test_the_load_is_shown_when_the_mode_is_off(): void {
        $this->resetAfterTest();
        [$activity, , , $student] = $this->setup_world();

        $this->setUser($student);
        $seen = \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Meera');
        $this->assertStringContainsString('Guiding', $seen[0]['label']);
    }

    /**
     * The team picker searches by name AND by project id, because staff
     * work from whichever they have in front of them.
     */
    public function test_group_search_matches_name_and_project_id(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'GS1']);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Kingfisher',
            'pluginuid' => 'MDP-GS1-0042',
        ]);

        $this->setUser($manager);
        $byname = \mod_selfselectadvanced\external\search_groups::execute($activity->cm()->id, 'Kingfish');
        $this->assertCount(1, $byname);
        $this->assertSame('Kingfisher', $byname[0]['name']);

        $byuid = \mod_selfselectadvanced\external\search_groups::execute($activity->cm()->id, '0042');
        $this->assertCount(1, $byuid);
        $this->assertSame('MDP-GS1-0042', $byuid[0]['pluginuid']);
        $this->assertStringContainsString('MDP-GS1-0042', $byuid[0]['label']);

        // Empty means nothing, not everything.
        $this->assertSame([], \mod_selfselectadvanced\external\search_groups::execute($activity->cm()->id, ''));

        // A student uses the same picker to choose a team to ask to
        // join (1.19 B), and sees exactly what the pick-a-team page has
        // shown them since 1.11 - the name and the project id.
        $this->setUser($leader);
        $studentsees = \mod_selfselectadvanced\external\search_groups::execute($activity->cm()->id, 'Kingfish');
        $this->assertCount(1, $studentsees);
        $this->assertSame('Kingfisher', $studentsees[0]['name']);
    }

    /**
     * Somebody with no part in the activity cannot enumerate its
     * guides.
     */
    public function test_an_outsider_is_refused(): void {
        $this->resetAfterTest();
        [$activity, , , , $outsider] = $this->setup_world();

        $this->setUser($outsider);
        $this->expectException(\require_login_exception::class);
        \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Meera');
    }

    /**
     * A guide with no room is dropped from an assignment picker, and
     * kept when the caller asks for everybody - which is what the
     * students-approach chooser needs, since omitting a full guide
     * would itself advertise their load.
     */
    public function test_withroom_decides_whether_a_full_guide_is_offered(): void {
        $this->resetAfterTest();
        [$activity, , $fullguide, $student] = $this->setup_world();

        // Pin their capacity to zero, which is a manager override and so
        // survives the volunteering rules either way.
        local\override\store::save($activity, 'guide', (int) $fullguide->id, ['maxguided' => 0], (int) $student->id);

        $this->setUser($student);
        $withroom = \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Menon', true);
        $this->assertSame([], $withroom);

        $everybody = \mod_selfselectadvanced\external\search_guides::execute($activity->cm()->id, 'Menon', false);
        $this->assertCount(1, $everybody);
        $this->assertSame((int) $fullguide->id, $everybody[0]['id']);
    }
}
