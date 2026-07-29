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
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\volunteering;

/**
 * Students-approach mode and the group name format (strategy 1.16 A
 * and C): guide-side initiative refused at the service layer even
 * when the settings are flipped directly in the database, full
 * guides staying listed so the list leaks no load, the anchored name
 * pattern, and course-wide name uniqueness across instances.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\volunteering
 * @covers     \mod_selfselectadvanced\local\eoi
 * @covers     \mod_selfselectadvanced\local\guides
 * @covers     \mod_selfselectadvanced\local\groups
 */
final class studentapproach_test extends \advanced_testcase {
    /**
     * An activity with students and a guide.
     *
     * @param array $settings instance overrides
     * @return array [activity, students[], guide]
     */
    private function setup_activity(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], $settings));

        $students = [];
        for ($i = 0; $i < 2; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        return [activity::from_instance((int) $instance->id), $students, $guide];
    }

    /**
     * With the switch on, a guide can neither volunteer capacity nor
     * express interest - even when eoienabled is flipped directly in
     * the database past the settings validator.
     */
    public function test_guide_initiative_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $guide] = $this->setup_activity([
            'studentapproach' => 1,
            'guidevolunteer' => 0,
            'eoienabled' => 0,
        ]);

        try {
            volunteering::set($activity, (int) $guide->id, 2);
            $this->fail('Expected refusalstudentapproach');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalstudentapproach', $e->errorcode);
        }

        // Belt and braces: eoienabled hacked on cannot reopen the door.
        $DB->set_field('selfselectadvanced', 'eoienabled', 1, ['id' => $activity->id()]);
        $hacked = activity::from_instance($activity->id());
        try {
            eoi::express($hacked, 1, (int) $guide->id, 'me please');
            $this->fail('Expected refusalstudentapproach');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalstudentapproach', $e->errorcode);
        }
    }

    /**
     * The leader's guide list leaks no load: with the switch on, a
     * guide already at capacity stays listed (omission would advertise
     * "full"); with it off, the same guide is filtered out.
     */
    public function test_full_guides_stay_listed(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        [$activity, $students, $guide] = $this->setup_activity([
            'studentapproach' => 1,
            'guidevolunteer' => 0,
            'maxguided' => 1,
        ]);

        // The guide is at their ceiling: one approved team already.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Full load',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);

        $selectable = \mod_selfselectadvanced\local\guides::selectable($activity, new resolver($activity));
        $this->assertArrayHasKey((int) $guide->id, $selectable);
        $this->assertSame(0, $selectable[(int) $guide->id]->remaining);

        // Switch off (volunteering also off, so capacity = ceiling):
        // the same full guide disappears from the selection list.
        global $DB;
        $DB->set_field('selfselectadvanced', 'studentapproach', 0, ['id' => $activity->id()]);
        $off = activity::from_instance($activity->id());
        $selectable = \mod_selfselectadvanced\local\guides::selectable($off, new resolver($off));
        $this->assertArrayNotHasKey((int) $guide->id, $selectable);
    }

    /**
     * The approval is BINDING on the students (the 1.16.0 work order):
     * once the guide they approached has approved, no student-side
     * action detaches that guide. This pins the absence of a path -
     * exactly the kind of guarantee that a later feature could open up
     * without anyone noticing.
     */
    public function test_guide_approval_is_binding_on_students(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        [$activity, $students, $guide] = $this->setup_activity([
            'studentapproach' => 1,
            'guidevolunteer' => 0,
            'eoienabled' => 0,
        ]);
        $leaderid = (int) $students[0]->id;
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderid,
            'name' => 'Approached and approved',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $group = groups::get($activity, (int) $group->id);
        $gatekeeper = (new api($activity))->gatekeeper();

        // The leader cannot dissolve the team to escape the guide.
        $this->assertSame(
            'refusalwrongstate',
            $gatekeeper->can_delete_group($group, $leaderid)?->stringkey
        );
        // Nor take it back to another guide by submitting again.
        $this->assertSame(
            'refusalwrongstate',
            $gatekeeper->can_submit($group, $leaderid)?->stringkey
        );
        // And the interest route, the only other way a guide changes
        // hands without staff, is closed in this mode.
        try {
            eoi::express($activity, (int) $group->id, (int) $guide->id, 'again?');
            $this->fail('Expected refusalstudentapproach');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalstudentapproach', $e->errorcode);
        }

        // The guide is still theirs: only staff can change it.
        $this->assertSame(
            (int) $guide->id,
            (int) groups::get($activity, (int) $group->id)->guideid
        );
    }

    /**
     * The anchored name format: a name matching the whole pattern
     * passes, a partial match is not enough, and an empty format
     * means free-form names.
     */
    public function test_name_format_anchored(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_activity(['nameformat' => '[A-Z]{3}-\d{2} .+']);

        $this->assertFalse(groups::name_breaks_format($activity, 'MDP-42 Pendulum study'));
        // A prefix or suffix around a matching core is a break: the
        // pattern is anchored at both ends.
        $this->assertTrue(groups::name_breaks_format($activity, 'xMDP-42 Pendulum study'));
        $this->assertTrue(groups::name_breaks_format($activity, 'MDP-421'));
        $this->assertTrue(groups::name_breaks_format($activity, 'free form'));

        [$freeform] = $this->setup_activity(['nameformat' => '']);
        $this->assertFalse(groups::name_breaks_format($freeform, 'free form'));
    }

    /**
     * Creation refuses a name that breaks the activity's format, with
     * the format and the example in the message.
     */
    public function test_create_group_refuses_format_break(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity([
            'nameformat' => '[A-Z]{3}-\d{2}',
            'nameformatexample' => 'MDP-42',
        ]);
        $api = new api($activity);

        try {
            $api->create_group((int) $students[0]->id, 'anything goes', 'Title', '', FORMAT_HTML);
            $this->fail('Expected refusalnameformat');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnameformat', $e->errorcode);
            $this->assertStringContainsString('MDP-42', $e->getMessage());
        }

        $group = $api->create_group((int) $students[0]->id, 'MDP-77', 'Title', '', FORMAT_HTML);
        $this->assertSame('MDP-77', $group->name);
    }

    /**
     * Group names are unique across every instance of the activity in
     * the course, but not across courses.
     */
    public function test_name_unique_course_wide(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        [$activity, $students] = $this->setup_activity();
        $course = get_course((int) $activity->cm()->course);
        $api = new api($activity);
        $api->create_group((int) $students[0]->id, 'Shared name', 'Title', '', FORMAT_HTML);

        // A second instance in the SAME course refuses the name.
        $instance2 = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity2 = activity::from_instance((int) $instance2->id);
        $this->assertTrue(groups::name_taken($activity2, 'Shared name'));
        try {
            (new api($activity2))->create_group((int) $students[1]->id, 'Shared name', 'Title', '', FORMAT_HTML);
            $this->fail('Expected errnametaken');
        } catch (\moodle_exception $e) {
            $this->assertSame('errnametaken', $e->errorcode);
        }

        // A different course is a different namespace.
        [$other, $otherstudents] = $this->setup_activity();
        $group = (new api($other))->create_group(
            (int) $otherstudents[0]->id, 'Shared name', 'Title', '', FORMAT_HTML
        );
        $this->assertSame('Shared name', $group->name);
    }
}
