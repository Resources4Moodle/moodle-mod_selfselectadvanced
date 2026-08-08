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
use mod_selfselectadvanced\local\authority;
use mod_selfselectadvanced\local\autogroup\engine as autogroup_engine;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\workflow_refusal;

/**
 * Creation and existing-group leadership are independent authorities.
 *
 * The split exists so an administrator can stop new groups without
 * stranding groups that already exist. These tests keep both halves
 * visible: :creategroup owns creation, while :lead owns every action
 * performed as the leader of an existing group. Appointment paths are
 * stricter: staff creation and succession never install somebody who
 * lacks :lead.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\authority
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 * @covers     \mod_selfselectadvanced\local\succession
 * @covers     \mod_selfselectadvanced\local\moves
 * @covers     \mod_selfselectadvanced\local\autogroup\engine
 */
final class capability_split_test extends \advanced_testcase {
    /**
     * Build one activity with students, a guide and trusted staff.
     *
     * @return array{0: activity, 1: api, 2: \stdClass[], 3: \stdClass, 4: \stdClass}
     *         activity, API, students, guide, staff
     */
    private function fixture(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 3,
            'maxmembership' => 2,
            'maxguided' => 5,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        return [$activity, new api($activity), $students, $guide, $staff];
    }

    /**
     * Prohibit one capability for the stock student role in this activity.
     *
     * @param string $capability capability name
     * @param \context $context activity context
     */
    private function prohibit_student(string $capability, \context $context): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_change_permission($roleid, $context, $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Give one user a module-context role that prohibits :lead.
     *
     * A role-specific prohibition lets the current leader stay
     * authorised while a particular nominee is tested independently.
     *
     * @param activity $activity activity
     * @param int $userid user receiving the prohibition
     * @return int created role id
     */
    private function prohibit_lead_for_user(activity $activity, int $userid): int {
        $roleid = create_role('Cannot lead here', 'selfselectadvanced_nolead_' . $userid, 'Test role');
        set_role_contextlevels($roleid, [CONTEXT_MODULE]);
        role_change_permission($roleid, $activity->context(), authority::LEAD, CAP_PROHIBIT);
        role_assign($roleid, $userid, $activity->context()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $roleid;
    }

    /**
     * :lead survives a creation prohibition and keeps existing work usable.
     */
    public function test_lead_without_creategroup_can_run_existing_group_but_not_create(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $leaderid = (int) $students[0]->id;
        $peerid = (int) $students[1]->id;
        $group = $api->create_group($leaderid, 'Existing group', 'Original', '<p>Original</p>', FORMAT_HTML);

        $this->prohibit_student(authority::CREATEGROUP, $activity->context());
        $this->assertFalse(has_capability(authority::CREATEGROUP, $activity->context(), $leaderid));
        $this->assertTrue(authority::may_lead($activity, $leaderid));

        $member = $api->invitations()->send($group, $peerid, $leaderid);
        $api->invitations()->accept(groups::get($activity, (int) $group->id), $peerid);
        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field('selfselectadvanced_member', 'status', ['id' => (int) $member->id])
        );

        $api->update_group_details(
            groups::get($activity, (int) $group->id),
            'Revised',
            '<p>Revised</p>',
            FORMAT_HTML,
            $leaderid
        );
        $this->assertSame(
            'Revised',
            $DB->get_field('selfselectadvanced_group', 'title', ['id' => (int) $group->id])
        );

        $submitted = $api->lifecycle()->submit(
            groups::get($activity, (int) $group->id),
            (int) $guide->id,
            $leaderid
        );
        $this->assertSame(state::PENDING_GUIDE, $submitted->state);

        try {
            $api->create_group($leaderid, 'Second group', 'Second', '<p>Second</p>', FORMAT_HTML);
            $this->fail('A student with :creategroup prohibited created another group');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::CREATEGROUP), $e->a);
        }
    }

    /**
     * :creategroup alone can create, but it confers no leader actions.
     */
    public function test_creategroup_without_lead_can_create_but_cannot_run_the_group(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $leaderid = (int) $students[0]->id;

        $this->prohibit_student(authority::LEAD, $activity->context());
        $this->assertTrue(has_capability(authority::CREATEGROUP, $activity->context(), $leaderid));
        $this->assertFalse(authority::may_lead($activity, $leaderid));

        $group = $api->create_group($leaderid, 'Creation only', 'Created', '<p>Created</p>', FORMAT_HTML);
        $this->assertSame($leaderid, (int) groups::get($activity, (int) $group->id)->leaderid);

        $attempts = [
            fn() => $api->invitations()->send($group, (int) $students[1]->id, $leaderid),
            fn() => $api->update_group_details($group, 'Blocked', '<p>Blocked</p>', FORMAT_HTML, $leaderid),
            fn() => $api->lifecycle()->submit($group, (int) $guide->id, $leaderid),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('An existing-group leader action accepted an actor without :lead');
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string(authority::LEAD), $e->a);
            }
        }
    }

    /**
     * A nominee without :lead is refused before a live nomination exists.
     */
    public function test_nomination_preflight_refuses_member_without_lead(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leaderid = (int) $students[0]->id;
        $nomineeid = (int) $students[1]->id;
        $group = $api->create_group($leaderid, 'Nomination', 'Nomination', '<p>N</p>', FORMAT_HTML);
        $api->invitations()->send($group, $nomineeid, $leaderid);
        $api->invitations()->accept($group, $nomineeid);
        $this->prohibit_lead_for_user($activity, $nomineeid);

        $fresh = groups::get($activity, (int) $group->id);
        $refusal = $api->gatekeeper()->can_nominate($fresh, $nomineeid, 'transfer', $leaderid);
        $this->assertNotNull($refusal);
        $this->assertSame('refusalnomineecannotlead', $refusal->stringkey);

        try {
            $api->succession()->nominate($fresh, $nomineeid, 'transfer', $leaderid);
            $this->fail('A member without :lead was nominated as successor');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalnomineecannotlead', $e->errorcode);
        }
        $this->assertNull($DB->get_field('selfselectadvanced_group', 'successorid', ['id' => (int) $group->id]));
    }

    /**
     * Confirmation rechecks :lead because it is the write that installs the nominee.
     */
    public function test_succession_confirmation_rechecks_lead(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leaderid = (int) $students[0]->id;
        $nomineeid = (int) $students[1]->id;
        $group = $api->create_group($leaderid, 'Handover', 'Handover', '<p>H</p>', FORMAT_HTML);
        $api->invitations()->send($group, $nomineeid, $leaderid);
        $api->invitations()->accept($group, $nomineeid);
        $api->succession()->nominate(groups::get($activity, (int) $group->id), $nomineeid, 'transfer', $leaderid);

        $this->prohibit_lead_for_user($activity, $nomineeid);
        $fresh = groups::get($activity, (int) $group->id);
        try {
            $api->succession()->confirm($fresh, $nomineeid);
            $this->fail('A nominee without :lead was installed as leader');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalnomineecannotlead', $e->errorcode);
        }

        $after = groups::get($activity, (int) $group->id);
        $this->assertSame($leaderid, (int) $after->leaderid);
        $this->assertSame($nomineeid, (int) $after->successorid);
        $this->assertSame(1, (int) $DB->get_field('selfselectadvanced_member', 'isleader', [
            'groupid' => (int) $group->id,
            'userid' => $leaderid,
        ]));
    }

    /**
     * Trusted staff may repair groups, but cannot appoint a leader who lacks :lead.
     */
    public function test_staff_create_refuses_a_leader_without_lead(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students, , $staff] = $this->fixture();
        $leaderid = (int) $students[0]->id;
        $this->prohibit_lead_for_user($activity, $leaderid);
        $before = $DB->count_records('selfselectadvanced_group', ['activityid' => $activity->id()]);

        try {
            $api->create_group(
                (int) $staff->id,
                'Staff group',
                'Staff',
                '<p>Staff</p>',
                FORMAT_HTML,
                $leaderid,
                true
            );
            $this->fail('Staff installed a leader who lacks :lead');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalnomineecannotlead', $e->errorcode);
        }

        $this->assertSame(
            $before,
            $DB->count_records('selfselectadvanced_group', ['activityid' => $activity->id()]),
            'A refused staff creation still inserted a group'
        );
    }

    /**
     * A staged target-leader move is rechecked before it can install the student.
     */
    public function test_staged_makeleader_refuses_a_student_without_lead(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students, , $staff] = $this->fixture();
        $targetleader = (int) $students[0]->id;
        $candidate = (int) $students[1]->id;
        $target = $api->create_group(
            $targetleader,
            'Move target',
            'Target',
            '<p>Target</p>',
            FORMAT_HTML
        );
        $this->prohibit_lead_for_user($activity, $candidate);

        $move = $api->moves()->stage(
            $candidate,
            null,
            (int) $target->id,
            true,
            null,
            (int) $staff->id,
            true
        );
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['LEAD']['ok']);
        $this->assertSame(
            get_string('refusalnomineecannotlead', 'mod_selfselectadvanced'),
            $verdicts->permove[(int) $move->id]['LEAD']['reason']
        );

        try {
            $api->moves()->commit_set([(int) $move->id], (int) $staff->id);
            $this->fail('A staged move installed a target leader who lacks :lead');
        } catch (workflow_refusal $e) {
            $this->assertSame('errmovesetinvalid', $e->errorcode);
        }

        $this->assertSame(
            $targetleader,
            (int) $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => (int) $target->id])
        );
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $target->id,
            'userid' => $candidate,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * A staged source successor also needs :lead at the eventual commit.
     */
    public function test_staged_successor_refuses_a_student_without_lead(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students, , $staff] = $this->fixture();
        $sourceleader = (int) $students[0]->id;
        $successor = (int) $students[1]->id;
        $targetleader = (int) $students[2]->id;
        $source = $api->create_group(
            $sourceleader,
            'Move source',
            'Source',
            '<p>Source</p>',
            FORMAT_HTML
        );
        $api->invitations()->send($source, $successor, $sourceleader);
        $api->invitations()->accept($source, $successor);
        $target = $api->create_group(
            $targetleader,
            'Move destination',
            'Destination',
            '<p>Destination</p>',
            FORMAT_HTML
        );
        $this->prohibit_lead_for_user($activity, $successor);

        $move = $api->moves()->stage(
            $sourceleader,
            (int) $source->id,
            (int) $target->id,
            false,
            $successor,
            (int) $staff->id
        );
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['LEADS']['ok']);

        try {
            $api->moves()->commit_set([(int) $move->id], (int) $staff->id);
            $this->fail('A staged move installed a source successor who lacks :lead');
        } catch (workflow_refusal $e) {
            $this->assertSame('errmovesetinvalid', $e->errorcode);
        }

        $this->assertSame(
            $sourceleader,
            (int) $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => (int) $source->id])
        );
        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => (int) $source->id,
            'userid' => $sourceleader,
        ]));
    }

    /**
     * Auto-grouping leaves a planned group unplaced when nobody may lead it.
     */
    public function test_autogroup_does_not_manufacture_a_leader_without_lead(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 2,
            'maxlead' => 1,
            'maxmembership' => 1,
            'autogroup' => 2,
            'timecutoff' => time() - 100,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $students = [];
        for ($i = 0; $i < 2; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = (int) $student->id;
        }
        $this->prohibit_student(authority::LEAD, $activity->context());

        $run = autogroup_engine::run($activity, 0, 260826);
        $this->assertSame(0, (int) $run->groupsformed);
        $this->assertSame(0, (int) $run->placed);
        $this->assertSame(2, (int) $run->unplaced);
        $this->assertSame(0, $DB->count_records('selfselectadvanced_group', [
            'activityid' => $activity->id(),
            'autoformed' => 1,
        ]));
        $log = json_decode($run->log, true);
        $this->assertEqualsCanonicalizing($students, $log['residue']);
    }
}
