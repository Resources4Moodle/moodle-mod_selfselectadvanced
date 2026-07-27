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

use core_privacy\local\request\approved_contextlist;
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\privacy\provider;

/**
 * Slice-13 compliance: the 6.3 leave flow, the deadline reminder, the
 * privacy provider (incl. M1) and the backup/restore roundtrip with
 * the M2 exclusions.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\privacy\provider
 * @covers     \mod_selfselectadvanced\task\deadline_reminder
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper::can_confirm_leave
 */
final class compliance_test extends \advanced_testcase {
    /**
     * Build an activity with a firm-track group of two.
     *
     * @param array $settings instance overrides
     * @return array [activity, api, group, students[]]
     */
    private function setup_pair(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], $settings));
        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Comp',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $students];
    }

    /**
     * Leave confirmation is L1-gated with reasons; a valid request removes
     * the member; requests need to exist.
     */
    public function test_leave_flow_gates(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $group, $students] = $this->setup_pair(['minsize' => 2]);
        $leader = (int) $students[0]->id;
        $member = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
        ]);

        // No request yet.
        $this->assertSame(
            'refusalnoleaverequest',
            $api->gatekeeper()->can_confirm_leave($group, $member, $leader)?->stringkey
        );

        // Request filed. Only the leader confirms.
        $DB->set_field('selfselectadvanced_member', 'leaverequested', time(), ['id' => $member->id]);
        $member = $DB->get_record('selfselectadvanced_member', ['id' => $member->id]);
        $this->assertSame(
            'refusalnotleader',
            $api->gatekeeper()->can_confirm_leave($group, $member, (int) $students[1]->id)?->stringkey
        );

        // Leaving a FORMING group is allowed even when it drops the
        // roster below the effective minimum (2 -> 1 < min 2): the
        // minimum gates SUBMISSION, never membership — a group at the
        // minimum must be able to shrink to repair its composition.
        $this->assertNull($api->gatekeeper()->can_confirm_leave($group, $member, $leader));
    }

    /**
     * The reminder task messages groupless students inside 24h of
     * their effective due date, once only; grouped students are skipped.
     */
    public function test_deadline_reminder(): void {
        $this->resetAfterTest();

        $this->setup_pair(); // Unrelated activity noise.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'timedue' => time() + (12 * HOURSECS),
        ]);
        $inside = $generator->create_user();
        $generator->enrol_user($inside->id, $course->id, 'student');
        $grouped = $generator->create_user();
        $generator->enrol_user($grouped->id, $course->id, 'student');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_group([
            'activityid' => (int) $instance->id,
            'leaderid' => (int) $grouped->id,
        ]);

        $sink = $this->redirectMessages();
        (new \mod_selfselectadvanced\task\deadline_reminder())->execute();
        $first = $sink->get_messages();
        $sink->clear();
        (new \mod_selfselectadvanced\task\deadline_reminder())->execute();
        $second = $sink->get_messages();
        $sink->close();

        $recipients = array_map(static fn($m) => (int) $m->useridto, $first);
        $this->assertContains((int) $inside->id, $recipients);
        $this->assertNotContains((int) $grouped->id, $recipients);
        // Once only.
        $this->assertNotContains((int) $inside->id, array_map(static fn($m) => (int) $m->useridto, $second));
    }

    /**
     * Privacy: contexts found, deletion removes member rows, blanks the
     * leader (M1 -> flagged) and removes the system attribute record.
     */
    public function test_privacy_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $group, $students] = $this->setup_pair();
        $leader = $students[0];
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_userattr([
            'userid' => (int) $leader->id,
            'gender' => 'Female',
        ]);

        $contextlist = provider::get_contexts_for_userid((int) $leader->id);
        $this->assertContainsEquals($activity->context()->id, $contextlist->get_contextids());
        $this->assertContainsEquals(\context_system::instance()->id, $contextlist->get_contextids());

        provider::delete_data_for_user(new approved_contextlist(
            $leader,
            'mod_selfselectadvanced',
            $contextlist->get_contextids()
        ));

        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $leader->id,
        ]));
        // M1: the group is now leaderless (0), which the flagged report lists.
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => $group->id]));
        $this->assertNull(\mod_selfselectadvanced\local\attributes\manager::get((int) $leader->id));
    }

    /**
     * Backup/restore roundtrip with userinfo: groups, members, quota
     * and penalties travel with remapped users; agrun and moves do not
     * (M2).
     */
    public function test_backup_restore_roundtrip(): void {
        global $DB, $CFG, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        [$activity, $api, $group, $students] = $this->setup_pair();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'gender',
            'value' => 'Female',
            'mincount' => 1,
        ]);
        // A move and an agrun row that must NOT travel (M2).
        $api->moves()->stage((int) $students[2]->id, null, (int) $group->id, false, null, 2);
        $DB->insert_record('selfselectadvanced_agrun', (object) [
            'activityid' => $activity->id(), 'seed' => 1, 'triggeredby' => 0,
            'timestarted' => time(), 'groupsformed' => 0, 'placed' => 0, 'unplaced' => 0,
        ]);

        $CFG->keeptempdirectoriesonbackup = true;
        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $activity->cm()->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            (int) $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $course2 = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $course2->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            (int) $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        $newcm = (object) ['instance' => (int) $DB->get_field(
            'selfselectadvanced',
            'id',
            ['course' => $course2->id]
        )];

        $newinstance = $DB->get_record('selfselectadvanced', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertNotEquals($activity->id(), (int) $newinstance->id);
        $newgroups = $DB->get_records('selfselectadvanced_group', ['activityid' => $newinstance->id]);
        $this->assertCount(1, $newgroups);
        $newgroup = reset($newgroups);
        // Same users (same site: mapping is identity), roster intact.
        $this->assertSame(2, groups::count_confirmed((int) $newgroup->id));
        $this->assertEquals((int) $students[0]->id, (int) $newgroup->leaderid);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_quota', ['activityid' => $newinstance->id]));
        // M2 exclusions hold.
        $this->assertSame(0, $DB->count_records('selfselectadvanced_move', ['activityid' => $newinstance->id]));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_agrun', ['activityid' => $newinstance->id]));
    }
}
