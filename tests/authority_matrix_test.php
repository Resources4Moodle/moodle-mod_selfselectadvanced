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
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\teamaccess;

/**
 * The capability x verb matrix, driven through the services.
 *
 * Built by enumerating db/access.php against lang/en - for every
 * capability, every VERB its string names, and the service method that
 * performs it - and then prohibiting the capability and driving the
 * production path. That enumeration is what found the two lifecycle
 * writes that admitted on identity alone, the leader verb that two
 * authority waves walked past, and state::assign_guide(), which named
 * :assignguide in its own queue notification and asked it of nobody.
 *
 * It overlaps tests/lifecycle_authority_test.php on purpose and is not
 * a copy of it: the assertions here are made against the row read back
 * out of selfselectadvanced_group with $DB, never against the object a
 * service returned, because a service that throws AFTER writing has
 * still written and an in-memory row cannot tell you so. Where the two
 * files agree, they agree from two directions.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class authority_matrix_test extends \advanced_testcase {
    /**
     * Course, activity, students, guides.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, api, course, students[], guides[]]
     */
    private function scene(array $settings = []): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $instance = $gen->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 3,
        ], $settings));
        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $u = $gen->create_user();
            $gen->enrol_user($u->id, $course->id, 'student');
            $students[] = $u;
        }
        $guides = [];
        for ($i = 0; $i < 2; $i++) {
            $u = $gen->create_user();
            $gen->enrol_user($u->id, $course->id, 'teacher');
            $guides[] = $u;
        }
        $activity = activity::from_instance((int) $instance->id);
        return [$activity, new api($activity), $course, $students, $guides];
    }

    /**
     * The plugin generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * Prohibit a capability for an archetype's role in this activity.
     *
     * Keyed on ARCHETYPE, never on the shortname: a site may rename the
     * role and the test would then silently prohibit nothing.
     *
     * @param activity $activity the activity
     * @param string $archetype the role archetype
     * @param string $capability the capability to prohibit
     */
    private function prohibit(activity $activity, string $archetype, string $capability): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['archetype' => $archetype], IGNORE_MULTIPLE);
        $this->assertGreaterThan(0, $roleid, "no role with archetype $archetype");
        assign_capability($capability, CAP_PROHIBIT, $roleid, $activity->context()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A team that has been submitted and is waiting on its guide.
     *
     * @param api $api the api
     * @param activity $activity the activity
     * @param array $students students
     * @param int $guideid the guide
     * @param string $name team name
     * @param int $l leader index
     * @param int $m member index
     * @return \stdClass the pending_guide group row
     */
    private function pending(
        api $api,
        activity $activity,
        array $students,
        int $guideid,
        string $name,
        int $l = 0,
        int $m = 1
    ): \stdClass {
        $leader = (int) $students[$l]->id;
        $group = $api->create_group($leader, $name, strtoupper(substr($name, 0, 3)), '<p>b</p>', FORMAT_HTML);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[$m]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $group = groups::get($activity, (int) $group->id);
        return $api->lifecycle()->submit($group, $guideid, $leader);
    }

    /**
     * Read the stored row straight from the table, bypassing every
     * service, so a refusal that wrote first cannot hide behind a
     * cached object.
     *
     * @param int $groupid the group
     * @return \stdClass the raw row
     */
    private function row(int $groupid): \stdClass {
        global $DB;
        return $DB->get_record('selfselectadvanced_group', ['id' => $groupid], '*', MUST_EXIST);
    }

    // D1: the guide verbs - approve and return.

    /**
     * Prohibiting :viewassignedteams must stop approve() writing.
     *
     * The guide is still named in guideid and still holds :guide; the
     * one thing the administrator withdrew is the capability that says
     * they may reach their assigned team at all.
     */
    public function test_approve_writes_nothing_when_viewassignedteams_is_prohibited(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $guideid = (int) $guides[0]->id;
        $group = $this->pending($api, $activity, $students, $guideid, 'Pine');
        $this->assertSame(state::PENDING_GUIDE, $this->row((int) $group->id)->state);

        $this->prohibit($activity, 'teacher', 'mod/selfselectadvanced:viewassignedteams');
        $this->assertFalse(teamaccess::is_assigned_guide($activity, $group, $guideid));
        $this->assertSame($guideid, (int) $this->row((int) $group->id)->guideid);

        try {
            $api->lifecycle()->approve($group, $guideid);
            $this->fail('approve() ran for a guide who may not reach the team');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $after = $this->row((int) $group->id);
        $this->assertSame(state::PENDING_GUIDE, $after->state);
        $this->assertEmpty($after->timeapproved);
    }

    /**
     * Prohibiting :guide itself must stop approve() writing.
     */
    public function test_approve_writes_nothing_when_guide_is_prohibited(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $guideid = (int) $guides[0]->id;
        $group = $this->pending($api, $activity, $students, $guideid, 'Cedar');

        $this->prohibit($activity, 'teacher', 'mod/selfselectadvanced:guide');

        try {
            $api->lifecycle()->approve($group, $guideid);
            $this->fail('approve() ran after "act as a project guide" was prohibited');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $after = $this->row((int) $group->id);
        $this->assertSame(state::PENDING_GUIDE, $after->state);
        $this->assertEmpty($after->timeapproved);
    }

    /**
     * Prohibiting :viewassignedteams must stop return_group() writing -
     * and in particular must not release the guide's L5 slot.
     */
    public function test_return_writes_nothing_when_viewassignedteams_is_prohibited(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $guideid = (int) $guides[0]->id;
        $group = $this->pending($api, $activity, $students, $guideid, 'Larch');

        $this->prohibit($activity, 'teacher', 'mod/selfselectadvanced:viewassignedteams');

        try {
            $api->lifecycle()->return_group($group, 'Please add another member.', $guideid);
            $this->fail('return_group() ran for a guide who may not reach the team');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $after = $this->row((int) $group->id);
        $this->assertSame(state::PENDING_GUIDE, $after->state);
        $this->assertSame($guideid, (int) $after->guideid);
    }

    /**
     * Prohibiting :guide must stop return_group() writing.
     */
    public function test_return_writes_nothing_when_guide_is_prohibited(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $guideid = (int) $guides[0]->id;
        $group = $this->pending($api, $activity, $students, $guideid, 'Maple');

        $this->prohibit($activity, 'teacher', 'mod/selfselectadvanced:guide');

        try {
            $api->lifecycle()->return_group($group, 'Not yet.', $guideid);
            $this->fail('return_group() ran after "act as a project guide" was prohibited');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $after = $this->row((int) $group->id);
        $this->assertSame(state::PENDING_GUIDE, $after->state);
        $this->assertSame($guideid, (int) $after->guideid);
    }

    /**
     * The positive control: with nothing prohibited the assigned guide
     * still approves and still returns. Without this the four refusals
     * above are satisfied by a gate that refuses everybody.
     */
    public function test_the_assigned_guide_still_approves_and_still_returns(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $guideid = (int) $guides[0]->id;
        $a = $this->pending($api, $activity, $students, $guideid, 'Rowan', 0, 1);
        $b = $this->pending($api, $activity, $students, $guideid, 'Alder', 2, 3);

        $api->lifecycle()->approve($a, $guideid);
        $this->assertSame(state::FIRM, $this->row((int) $a->id)->state);
        $this->assertNotEmpty($this->row((int) $a->id)->timeapproved);

        $api->lifecycle()->return_group($b, 'One more member please.', $guideid);
        $rb = $this->row((int) $b->id);
        $this->assertSame(state::FORMING, $rb->state);
        $this->assertEmpty($rb->guideid);
    }

    /**
     * A different guide, fully capable, still may not decide this team:
     * the gate keys on the ASSIGNMENT and not merely on the capability.
     */
    public function test_a_capable_stranger_guide_may_not_decide_this_team(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $mine = (int) $guides[0]->id;
        $other = (int) $guides[1]->id;
        $group = $this->pending($api, $activity, $students, $mine, 'Hazel');

        try {
            $api->lifecycle()->approve($group, $other);
            $this->fail('approve() ran for a guide this team was never assigned to');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(state::PENDING_GUIDE, $this->row((int) $group->id)->state);
    }

    /**
     * :manage is an administrative grant, not a guide decision: an
     * editing teacher may correct an award but may not approve or
     * return in the guide's place.
     */
    public function test_manage_does_not_buy_the_guide_decision(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        [$activity, $api, $course, $students, $guides] = $this->scene();
        $manager = $gen->create_user();
        $gen->enrol_user($manager->id, $course->id, 'editingteacher');
        $group = $this->pending($api, $activity, $students, (int) $guides[0]->id, 'Birch');

        try {
            $api->lifecycle()->approve($group, (int) $manager->id);
            $this->fail('approve() ran for a :manage holder who guides nothing');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        try {
            $api->lifecycle()->return_group($group, 'No.', (int) $manager->id);
            $this->fail('return_group() ran for a :manage holder who guides nothing');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(state::PENDING_GUIDE, $this->row((int) $group->id)->state);
    }

    /**
     * The lapsed-window sweep deliberately stands in for guide
     * identity, and must keep doing so: it runs as the site
     * administrator on cron, long after any guide's capability.
     */
    public function test_the_decision_window_sweep_still_fires(): void {
        $this->resetAfterTest();
        global $DB;
        [$activity, $api, , $students, $guides] = $this->scene([
            'guideautoapprove' => 1,
            'guidewindow' => DAYSECS,
        ]);
        $guideid = (int) $guides[0]->id;
        $group = $this->pending($api, $activity, $students, $guideid, 'Willow');
        $DB->set_field(
            'selfselectadvanced_group',
            'timesubmitted',
            time() - (10 * DAYSECS),
            ['id' => $group->id]
        );
        $this->setAdminUser();
        $fresh = groups::get($activity, (int) $group->id);
        $api->lifecycle()->approve_auto($fresh, (int) get_admin()->id);
        $this->assertSame(state::FIRM, $this->row((int) $group->id)->state);
    }

    // D2: submit is a leader verb.

    /**
     * Prohibiting :lead must stop submit() moving the team out
     * of forming - every other gate being perfect.
     */
    public function test_submit_writes_nothing_when_lead_is_prohibited(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $leader = (int) $students[0]->id;
        $group = $api->create_group($leader, 'Sorrel', 'SOR', '<p>b</p>', FORMAT_HTML);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $group = groups::get($activity, (int) $group->id);
        // Every non-authority gate passes before the prohibit.
        $this->assertNull($api->gatekeeper()->can_submit($group, $leader));

        $this->prohibit($activity, 'student', 'mod/selfselectadvanced:lead');

        try {
            $api->lifecycle()->submit($group, (int) $guides[0]->id, $leader);
            $this->fail('submit() accepted a leader whose capability is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $after = $this->row((int) $group->id);
        $this->assertSame(state::FORMING, $after->state);
        $this->assertEmpty($after->guideid);
        $this->assertEmpty($after->timesubmitted);
    }

    /**
     * A refused submit must hold no lock: the capability question is
     * asked before the guide lock, the group lock and the transaction.
     */
    public function test_a_refused_submit_holds_no_lock(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $leader = (int) $students[0]->id;
        $group = $api->create_group($leader, 'Tansy', 'TAN', '<p>b</p>', FORMAT_HTML);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $group = groups::get($activity, (int) $group->id);
        $this->prohibit($activity, 'student', 'mod/selfselectadvanced:lead');

        try {
            $api->lifecycle()->submit($group, (int) $guides[0]->id, $leader);
            $this->fail('submit() accepted a prohibited leader');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(0, \mod_selfselectadvanced\local\locks::held_count());
    }

    /**
     * The positive control for submit.
     */
    public function test_the_leader_still_submits(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $guides] = $this->scene();
        $group = $this->pending($api, $activity, $students, (int) $guides[0]->id, 'Yarrow');
        $this->assertSame(state::PENDING_GUIDE, $this->row((int) $group->id)->state);
    }

    /**
     * The control the page draws must follow the same answer: a leader
     * whose capability is prohibited is offered no Submit section.
     */
    public function test_a_prohibited_leader_is_drawn_no_submit_control(): void {
        $this->resetAfterTest();
        global $PAGE;
        [$activity, $api, , $students, $guides] = $this->scene();
        $leader = (int) $students[0]->id;
        $group = $api->create_group($leader, 'Sedge', 'SED', '<p>b</p>', FORMAT_HTML);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $group = groups::get($activity, (int) $group->id);
        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);
        $output = $PAGE->get_renderer('core');
        $form = new \mod_selfselectadvanced\form\submit_form(
            (new \moodle_url('/mod/selfselectadvanced/group.php'))->out(false),
            [
                'cmid' => $activity->cm()->id,
                'groupid' => (int) $group->id,
                'leaderselects' => true,
                'studentapproach' => false,
                'disabled' => false,
            ]
        );

        $before = (new \mod_selfselectadvanced\output\group_page(
            $api,
            $group,
            $leader,
            null,
            null,
            $form
        ))->export_for_template($output);
        $this->assertTrue((bool) $before->showsubmit);

        $this->prohibit($activity, 'student', 'mod/selfselectadvanced:lead');

        $after = (new \mod_selfselectadvanced\output\group_page(
            $api,
            groups::get($activity, (int) $group->id),
            $leader,
            null,
            null,
            $form
        ))->export_for_template($output);
        $this->assertFalse((bool) $after->showsubmit, 'the Submit section survived the prohibit');
        // The facts the control is NOT allowed to have moved.
        $this->assertSame(state::FORMING, $this->row((int) $group->id)->state);
        $this->assertSame($leader, (int) $this->row((int) $group->id)->leaderid);
    }

    // The matrix cell neither authority wave reached: :assignguide
    // names "assign or reassign a team's guide", and
    // state::assign_guide() is where that happens.

    /**
     * Assigning a team's guide is what :assignguide (and :manage) name.
     * An actor holding neither must not be able to do it, however the
     * service is reached.
     */
    public function test_assign_guide_refuses_an_actor_holding_neither_capability(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        [$activity, $api, $course, $students, $guides] = $this->scene(['guidemode' => 1]);
        // A non-editing teacher: guides teams, holds neither :manage nor
        // :assignguide, and is enrolled on the course like any other.
        $outsider = $gen->create_user();
        $gen->enrol_user($outsider->id, $course->id, 'teacher');
        $this->assertFalse(has_capability('mod/selfselectadvanced:manage', $activity->context(), $outsider->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:assignguide', $activity->context(), $outsider->id));

        $leader = (int) $students[0]->id;
        $group = $api->create_group($leader, 'Juniper', 'JUN', '<p>b</p>', FORMAT_HTML);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $group = $api->lifecycle()->submit(groups::get($activity, (int) $group->id), null, $leader);
        $this->assertEmpty($this->row((int) $group->id)->guideid);

        try {
            $api->lifecycle()->assign_guide($group, (int) $guides[0]->id, (int) $outsider->id);
            $this->fail('assign_guide() ran for an actor holding neither :manage nor :assignguide');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEmpty($this->row((int) $group->id)->guideid);
        $this->assertSame(0, \mod_selfselectadvanced\local\locks::held_count());
    }

    /**
     * The positive control: both named holders still assign.
     */
    public function test_manage_and_assignguide_both_still_assign(): void {
        $this->resetAfterTest();
        global $DB;
        $gen = $this->getDataGenerator();
        [$activity, $api, $course, $students, $guides] = $this->scene(['guidemode' => 1]);
        $manager = $gen->create_user();
        $gen->enrol_user($manager->id, $course->id, 'editingteacher');
        // A narrow holder: the capability alone, on a role that has
        // nothing else, exactly as a Group Coordinator carries it.
        $narrowid = create_role('Narrow assigner', 'narrowassign3c', 'assignguide only');
        set_role_contextlevels($narrowid, [CONTEXT_MODULE]);
        assign_capability(
            'mod/selfselectadvanced:assignguide',
            CAP_ALLOW,
            $narrowid,
            $activity->context()->id,
            true
        );
        $narrow = $gen->create_user();
        $gen->enrol_user($narrow->id, $course->id, 'student');
        role_assign($narrowid, $narrow->id, $activity->context()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $a = $this->submitted_without_guide($api, $activity, $students, 'Ash', 0, 1);
        $api->lifecycle()->assign_guide($a, (int) $guides[0]->id, (int) $manager->id);
        $this->assertSame((int) $guides[0]->id, (int) $this->row((int) $a->id)->guideid);

        $b = $this->submitted_without_guide($api, $activity, $students, 'Elm', 2, 3);
        $api->lifecycle()->assign_guide($b, (int) $guides[1]->id, (int) $narrow->id);
        $this->assertSame((int) $guides[1]->id, (int) $this->row((int) $b->id)->guideid);
        $this->assertTrue($DB->record_exists('role', ['id' => $narrowid]));
    }

    /**
     * A team submitted in manager-assigns mode, waiting for a guide.
     *
     * @param api $api the api
     * @param activity $activity the activity
     * @param array $students students
     * @param string $name team name
     * @param int $l leader index
     * @param int $m member index
     * @return \stdClass the group row
     */
    private function submitted_without_guide(
        api $api,
        activity $activity,
        array $students,
        string $name,
        int $l,
        int $m
    ): \stdClass {
        $leader = (int) $students[$l]->id;
        $group = $api->create_group($leader, $name, strtoupper(substr($name, 0, 3)), '<p>b</p>', FORMAT_HTML);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[$m]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        return $api->lifecycle()->submit(groups::get($activity, (int) $group->id), null, $leader);
    }
}
