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

use backup;
use backup_controller;
use restore_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * What actually survives a backup and restore.
 *
 * Written because a whole class of defect had been hiding behind the
 * absence of this file. Proposal attachments were annotated for backup
 * and asked for on restore, but the group mapping was recorded without
 * restorefiles, so core never linked them to the restored groups and
 * every attachment was dropped — silently, because a restore that finds
 * no files to move reports success. Nothing in the suite round-tripped
 * a file, so nothing noticed.
 *
 * These tests assert on the CONTENT that comes out the other side, not
 * on the restore completing.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_selfselectadvanced_activity_structure_step
 * @covers     \restore_selfselectadvanced_activity_structure_step
 */
final class backup_restore_files_test extends \advanced_testcase {
    /**
     * Back an activity up and restore it into a fresh course.
     *
     * @param \stdClass $course the source course
     * @param \stdClass $cm the source course module
     * @param int $userid the acting admin
     * @return \stdClass the restored course module
     */
    private function roundtrip(\stdClass $course, \stdClass $cm, int $userid): \stdClass {
        global $DB;

        // MODE_GENERAL, not MODE_IMPORT: import mode produces no backup
        // file at all, and the group data these tests care about is
        // gated behind the userinfo setting, which import mode turns
        // off. So: a real archive, with users in it.
        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cm->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $results = $bc->get_results();
        $this->assertArrayHasKey('backup_destination', $results, 'The backup produced no archive');
        $file = $results['backup_destination'];
        $dir = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $dir);
        $bc->destroy();

        $target = $this->getDataGenerator()->create_course();
        $rc = new restore_controller(
            $backupid,
            $target->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid,
            backup::TARGET_CURRENT_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $instances = $DB->get_records('selfselectadvanced', ['course' => $target->id], 'id DESC');
        $this->assertNotEmpty($instances, 'The activity did not restore at all');
        $restored = reset($instances);

        return get_coursemodule_from_instance('selfselectadvanced', $restored->id, $target->id, false, MUST_EXIST);
    }

    /**
     * A team's proposal attachment comes back attached to the restored
     * team, with its content intact.
     */
    public function test_proposal_file_survives_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Team With Paperwork',
        ]);

        $content = 'The proposal itself, which must survive.';
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_selfselectadvanced',
            'filearea' => 'proposal',
            'itemid' => (int) $group->id,
            'filepath' => '/',
            'filename' => 'proposal.txt',
        ], $content);

        $newcm = $this->roundtrip($course, $cm, (int) $admin->id);
        $newcontext = \context_module::instance($newcm->id);
        $newgroup = $DB->get_record('selfselectadvanced_group', [
            'activityid' => $newcm->instance,
            'name' => 'Team With Paperwork',
        ], '*', MUST_EXIST);

        $files = get_file_storage()->get_area_files(
            $newcontext->id,
            'mod_selfselectadvanced',
            'proposal',
            (int) $newgroup->id,
            'filename',
            false
        );

        $this->assertCount(1, $files, 'The proposal attachment was dropped by the restore');
        $restoredfile = reset($files);
        $this->assertSame('proposal.txt', $restoredfile->get_filename());
        $this->assertSame($content, $restoredfile->get_content(), 'The attachment came back with different content');
    }

    /**
     * A guide deliberately hidden from the pickers stays hidden after a
     * restore. The flag was stored, and simply not carried, so the
     * restored site quietly undid an administrator's decision.
     */
    public function test_hidden_guide_stays_hidden_after_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);

        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $DB->insert_record('selfselectadvanced_override', (object) [
            'activityid' => $instance->id,
            'scope' => 'guide',
            'userid' => $guide->id,
            'guidehidden' => 1,
            'status' => 'active',
            'usermodified' => $admin->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $newcm = $this->roundtrip($course, $cm, (int) $admin->id);

        $restored = $DB->get_record('selfselectadvanced_override', [
            'activityid' => $newcm->instance,
            'scope' => 'guide',
        ]);
        $this->assertNotEmpty($restored, 'The guide-scope override did not restore');
        $this->assertEquals(1, $restored->guidehidden, 'A hidden guide became visible again after restore');
    }

    /**
     * BACKUP-001: the pending digest queue is operational state of the
     * running site, not course data, and stays out of the archive. A
     * restored queue row carried the ORIGINAL activity's deep link (or
     * a literal restore token nothing ever decoded) and payload text
     * resolved on the source site, and a same-site duplicate doubled
     * every recipient's pending digest. The roundtrip must complete
     * without error and produce ZERO queue rows on the restored side.
     */
    public function test_digest_queue_stays_out_of_the_backup(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);

        $recipient = $generator->create_user();
        $generator->enrol_user($recipient->id, $course->id, 'teacher');
        $DB->insert_record('selfselectadvanced_digestq', (object) [
            'activityid' => $instance->id,
            'userid' => $recipient->id,
            'groupid' => null,
            'provider' => 'guidequeue',
            'subjectkey' => 'msghandoverproposedsubject',
            'bodykey' => 'msghandoverproposedbody',
            'payload' => json_encode(['group' => 'Original Team', 'activity' => 'Original Activity']),
            'contexturl' => 'https://source.invalid/mod/selfselectadvanced/guide.php?id=' . $cm->id,
            'timecreated' => time(),
        ]);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_digestq', ['activityid' => $instance->id]));

        $newcm = $this->roundtrip($course, $cm, (int) $admin->id);

        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_digestq', ['activityid' => $newcm->instance]),
            'A queued digest notification travelled with the backup'
        );
        // The recipient's own pending digest on the SOURCE activity is
        // untouched: the exclusion is about the archive, not the queue.
        $this->assertSame(1, $DB->count_records('selfselectadvanced_digestq', ['activityid' => $instance->id]));
    }

    /**
     * The control for the surgery above: with a digest row queued
     * beside them, the group, its member, the volunteer row and the
     * user-scope override all still make the trip - proof the cut
     * removed the queue and nothing else. The volunteer table matters
     * most here: it sits immediately beside the removed subtree in the
     * backup structure and was the pattern the queue's backup was
     * copied from.
     */
    public function test_the_other_user_tables_still_roundtrip(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $volunteer = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($member->id, $course->id, 'student');
        $generator->enrol_user($volunteer->id, $course->id, 'teacher');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Travelling Team',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $member->id,
            'invitedby' => $leader->id,
        ]);
        $now = time();
        $DB->insert_record('selfselectadvanced_volunteer', (object) [
            'activityid' => $instance->id,
            'userid' => $volunteer->id,
            'capacity' => 3,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('selfselectadvanced_override', (object) [
            'activityid' => $instance->id,
            'scope' => 'user',
            'userid' => $member->id,
            'maxmembership' => 4,
            'status' => 'active',
            'usermodified' => $admin->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('selfselectadvanced_digestq', (object) [
            'activityid' => $instance->id,
            'userid' => $volunteer->id,
            'groupid' => null,
            'provider' => 'guidequeue',
            'subjectkey' => 'msghandoverproposedsubject',
            'bodykey' => 'msghandoverproposedbody',
            'payload' => json_encode(['group' => 'Travelling Team']),
            'contexturl' => 'https://source.invalid/mod/selfselectadvanced/guide.php?id=' . $cm->id,
            'timecreated' => $now,
        ]);

        $newcm = $this->roundtrip($course, $cm, (int) $admin->id);

        $newgroup = $DB->get_record('selfselectadvanced_group', [
            'activityid' => $newcm->instance,
            'name' => 'Travelling Team',
        ], '*', MUST_EXIST);
        $this->assertSame(
            2,
            $DB->count_records('selfselectadvanced_member', ['groupid' => $newgroup->id]),
            'The roster did not survive the digest-queue exclusion'
        );
        $this->assertSame(
            1,
            $DB->count_records('selfselectadvanced_volunteer', ['activityid' => $newcm->instance]),
            'The volunteer row beside the removed subtree did not survive'
        );
        $this->assertSame(
            1,
            $DB->count_records('selfselectadvanced_override', ['activityid' => $newcm->instance]),
            'The user-scope override did not survive'
        );
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_digestq', ['activityid' => $newcm->instance]),
            'The queue travelled after all - the control found the cut in the wrong place'
        );
    }
}
