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
 * Students-approach mode and the project-id template (strategy 1.16 A,
 * 1.17 A1): guide-side initiative refused at the service layer even
 * when the settings are flipped directly in the database, full guides
 * staying listed so the list leaks no load, a binding approval, the
 * teacher's id template, and course-wide name uniqueness across
 * instances.
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
     * A new activity starts in students-approach mode (1.17.0). The
     * test generator deliberately builds the older configuration, so
     * this reads the shipped default rather than a fixture's.
     */
    public function test_new_activities_default_to_students_approach(): void {
        global $DB;
        $this->resetAfterTest();

        $column = $DB->get_columns('selfselectadvanced')['studentapproach'] ?? null;
        $this->assertNotNull($column, 'studentapproach column missing');
        $this->assertSame(1, (int) $column->default_value);
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
     * A full guide's refusal must not hand back the load figure the
     * chooser was careful not to show. With the switch off the numbers
     * are useful and stay; with it on they would defeat the mode.
     */
    public function test_full_guide_refusal_is_load_blind(): void {
        global $DB;
        $this->resetAfterTest();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        [$activity, $students, $guide] = $this->setup_activity([
            'studentapproach' => 1,
            'guidevolunteer' => 0,
            'maxguided' => 1,
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Already at capacity',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);

        $refusal = (new api($activity))->gatekeeper()->can_take_guide((int) $guide->id);
        $this->assertNotNull($refusal);
        $this->assertSame('refusalguideunavailable', $refusal->stringkey);
        $this->assertStringNotContainsString('1 of 1', $refusal->get_message());

        // Switch off: the numbers are helpful to staff and come back.
        $DB->set_field('selfselectadvanced', 'studentapproach', 0, ['id' => $activity->id()]);
        $off = activity::from_instance($activity->id());
        $refusal = (new api($off))->gatekeeper()->can_take_guide((int) $guide->id);
        $this->assertSame('refusalguidecap', $refusal->stringkey);
    }

    /**
     * Turning the switch on settles the guide-side switches instead of
     * refusing the save. The form disables them, so a disabled control
     * submits nothing and an activity that already had them on could
     * otherwise never adopt the mode.
     */
    public function test_switching_on_settles_the_guide_side_modes(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/selfselectadvanced/lib.php');
        $this->resetAfterTest();
        [$activity] = $this->setup_activity([
            'guidevolunteer' => 1,
            'eoienabled' => 1,
            'guidemode' => 1,
        ]);

        $data = $DB->get_record('selfselectadvanced', ['id' => $activity->id()], '*', MUST_EXIST);
        $data->instance = $data->id;
        $data->studentapproach = 1;
        selfselectadvanced_update_instance($data);

        $saved = $DB->get_record('selfselectadvanced', ['id' => $activity->id()], '*', MUST_EXIST);
        $this->assertSame(1, (int) $saved->studentapproach);
        $this->assertSame(0, (int) $saved->guidevolunteer);
        $this->assertSame(0, (int) $saved->eoienabled);
        $this->assertSame(0, (int) $saved->guidemode);
    }

    /**
     * The project id follows the teacher's template (strategy 1.17 A1),
     * and an activity that says nothing keeps the shape the plugin has
     * always issued - no site's ids change under it.
     */
    public function test_project_id_follows_the_template(): void {
        $this->resetAfterTest();

        [$plain, $students] = $this->setup_activity(['uidprefix' => 'MDP', 'uiddigits' => 4]);
        $group = (new api($plain))->create_group(
            (int) $students[0]->id,
            'Default shape',
            'Title',
            '',
            FORMAT_HTML
        );
        // The {prefix}-{course}-{number} shape, shipped since 1.15.
        $this->assertMatchesRegularExpression('/^MDP-[A-Z0-9]+-\d{4,}$/', $group->pluginuid);

        [$shaped, $others] = $this->setup_activity([
            'uidprefix' => 'MDP',
            'uiddigits' => 3,
            'uidformat' => '{prefix}/{number}',
        ]);
        $group = (new api($shaped))->create_group(
            (int) $others[0]->id,
            'Own shape',
            'Title',
            '',
            FORMAT_HTML
        );
        $this->assertMatchesRegularExpression('/^MDP\/\d{3,}$/', $group->pluginuid);

        // A template that lost its number would mint one id for every
        // team, so the standard shape is used instead of a broken one.
        [$broken, $more] = $this->setup_activity(['uidformat' => 'FIXED']);
        $group = (new api($broken))->create_group(
            (int) $more[0]->id,
            'Broken template',
            'Title',
            '',
            FORMAT_HTML
        );
        $this->assertStringNotContainsString('FIXED', $group->pluginuid);
    }

    /**
     * A team name may repeat - anywhere. Maintainer ruling, 2026-08-05.
     *
     * This test asserted the OPPOSITE until that ruling: names were unique
     * course-wide (strategy 1.16 C) so a reader browsing a course met one
     * "Alpha". The maintainer's position is that identity belongs to the
     * generated PROJECT ID, which is built from the team's own database key
     * and is unique plugin-wide forever, and that refusing a student's chosen
     * label to protect a display convention is the wrong trade.
     *
     * What is asserted here is therefore the property that actually matters:
     * the labels may collide, the IDS MAY NOT.
     *
     * MUTATION CAUGHT (run): restoring the name_taken() refusal in
     * api::create_group() makes the second create_group() throw
     * errnametaken and the test errors out.
     */
    public function test_a_team_name_may_repeat_but_its_project_id_may_not(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        [$activity, $students] = $this->setup_activity();
        $course = get_course((int) $activity->cm()->course);
        $api = new api($activity);
        $first = $api->create_group((int) $students[0]->id, 'Shared name', 'Title', '', FORMAT_HTML);

        // The SAME activity accepts the same name again.
        $samehere = $api->create_group((int) $students[1]->id, 'Shared name', 'Title', '', FORMAT_HTML);
        $this->assertSame('Shared name', $samehere->name);
        $this->assertNotSame($first->pluginuid, $samehere->pluginuid);

        // So does a second instance in the same course.
        $instance2 = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity2 = activity::from_instance((int) $instance2->id);
        $elsewhere = (new api($activity2))->create_group(
            (int) $students[1]->id,
            'Shared name',
            'Title',
            '',
            FORMAT_HTML
        );
        $this->assertSame('Shared name', $elsewhere->name);

        // Three teams, one label, three distinct identities.
        $uids = [$first->pluginuid, $samehere->pluginuid, $elsewhere->pluginuid];
        $this->assertCount(3, array_unique($uids), 'project ids must stay unique when names do not');
    }
}
