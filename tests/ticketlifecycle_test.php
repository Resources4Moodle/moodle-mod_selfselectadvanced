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
use core_external\external_api;
use mod_selfselectadvanced\external\api_list_tickets;
use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use restore_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Ticket-system audit 2026-08-20 (TICKET-AUDIT-20260820.md), the findings
 * that live outside classes/local/tickets.php itself: an activity or a
 * course reset must not orphan the ticket trail or leak ticket attachment
 * file areas (B1/B2); the LLM queue must survive a deleted group underneath
 * a ticket (B4); a course restore must rescue a needs-info ticket whose
 * claimant did not survive, exactly like privacy erasure does (B7); the
 * knowledgebank must travel through a no-userinfo backup/restore the same
 * way quotas and templates do (B8); and restore must decode the encoded
 * links a trail note or a knowledgebank article can carry (B10).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\llmapi
 * @covers     \backup_selfselectadvanced_activity_structure_step
 * @covers     \restore_selfselectadvanced_activity_structure_step
 * @covers     \restore_selfselectadvanced_activity_task
 */
final class ticketlifecycle_test extends \externallib_advanced_testcase {
    /**
     * A course, an activity, a leader with a firm group, and a staff
     * member holding queue authority.
     *
     * @return array [activity, course, cm, leader, staff, group]
     */
    private function scene(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);

        $leader = $generator->create_user(['firstname' => 'Lena', 'lastname' => 'Leader']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $staff = $generator->create_user(['firstname' => 'Tina', 'lastname' => 'Teach']);
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team Blue',
            'state' => state::FIRM,
        ]);

        return [$activity, $course, $cm, $leader, $staff, $group];
    }

    /**
     * Every selfselectadvanced_ticketlog row belonging to any of the given
     * tickets, by id - a direct read, independent of whatever subquery the
     * production code under test uses to find the same rows.
     *
     * @param int[] $ticketids the tickets
     * @return int[] ticketlog row ids
     */
    private function logids_for(array $ticketids): array {
        global $DB;

        if (!$ticketids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ticketids);

        return array_map('intval', $DB->get_fieldset_select('selfselectadvanced_ticketlog', 'id', "ticketid $insql", $params));
    }

    /**
     * B1 (HIGH). selfselectadvanced_delete_instance() must remove every
     * ticketlog row and every knowledgebank row belonging to the deleted
     * activity, or a student's own inforeply prose and every staff-authored
     * FAQ become permanently unreachable orphans (TICKET-AUDIT-20260820.md,
     * B1 / H-6, M-9).
     *
     * MUTATION CAUGHT: reverting the lib.php delete_instance() change (no
     * ticketlog/kb delete_records calls) fails both assertions below.
     */
    public function test_delete_instance_removes_ticketlog_and_kb_rows(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , , $leader, $staff, $group] = $this->scene();

        // A grouped ticket, claimed then resolved (filed/claimed/resolved:
        // three trail rows).
        $grouped = tickets::file_help($activity, $group, 'Please help with a swap.', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $grouped->id, (int) $staff->id);
        tickets::close(
            $activity,
            (int) $grouped->id,
            tickets::STATUS_RESOLVED,
            'Handled.',
            FORMAT_PLAIN,
            (int) $staff->id
        );

        // A groupless ticket (help, no team) - one more trail row, and the
        // shape the reset-side test below exercises without any group at
        // all.
        $groupless = tickets::file_help($activity, null, 'A question with no team.', FORMAT_PLAIN, (int) $leader->id);

        $logids = $this->logids_for([(int) $grouped->id, (int) $groupless->id]);
        $this->assertGreaterThan(1, count($logids), 'the fixture must produce more than one trail row');

        $kbentry = kb::create($activity, (int) $staff->id, [
            'title' => 'Direct-add FAQ',
            'question' => 'How do groups freeze?',
            'answer' => 'At the deadline, automatically.',
            'tickettype' => '',
        ]);

        $this->assertNotEmpty($DB->get_records_list('selfselectadvanced_ticketlog', 'id', $logids), 'fixture setup failed');
        $this->assertTrue(
            $DB->record_exists('selfselectadvanced_kb', ['id' => $kbentry->id]),
            'fixture setup failed'
        );

        $this->assertTrue(selfselectadvanced_delete_instance($activity->id()));

        $this->assertCount(
            0,
            $DB->get_records_list('selfselectadvanced_ticketlog', 'id', $logids),
            'ticketlog rows survived the activity they point at, becoming unreachable orphans'
        );
        $this->assertFalse(
            $DB->record_exists('selfselectadvanced_kb', ['id' => $kbentry->id]),
            'a knowledgebank row survived the activity it belongs to'
        );
    }

    /**
     * B2 (HIGH). Course reset must remove the ticket trail and BOTH ticket
     * file areas ('ticketrequest', 'ticketpost'), even when the activity
     * has NO groups at all - the module context survives a reset, so a
     * groupless help/guidecap ticket's attachment would otherwise sit in it
     * forever, naming an itemid no row resolves
     * (TICKET-AUDIT-20260820.md, B2 / H-3 / H-4 / H-7).
     *
     * This scenario deliberately has ZERO plugin groups, so
     * $groupids is empty throughout selfselectadvanced_reset_userdata():
     * the old code's file-area cleanup was gated on `if ($groupids)`, and a
     * fix that adds the ticket-file cleanup INSIDE that same guard would
     * still pass a test that happens to have a group. Only a groupless
     * fixture proves the $resetcm/$resetcontext lookup was actually hoisted
     * out of it.
     *
     * MUTATION CAUGHT: reverting the reset_userdata() ticketlog delete and
     * the two delete_area_files() calls (or leaving them nested inside
     * `if ($groupids)`) fails one or more assertions below.
     */
    public function test_reset_userdata_removes_ticketlog_and_ticket_files_with_no_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);
        $context = $activity->context();

        $requester = $generator->create_user(['firstname' => 'Remi', 'lastname' => 'Requester']);
        $generator->enrol_user($requester->id, $course->id, 'student');
        $staff = $generator->create_user(['firstname' => 'Tina', 'lastname' => 'Teach']);
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_group', ['activityid' => $activity->id()]),
            'this scenario must have zero plugin groups, to exercise the $groupids hoist'
        );

        $ticket = tickets::file_help($activity, null, 'A groupless request.', FORMAT_PLAIN, (int) $requester->id);
        tickets::claim($activity, (int) $ticket->id, (int) $staff->id);
        $needsinfo = tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which team is this about?',
            FORMAT_PLAIN,
            (int) $staff->id
        );
        $logids = $this->logids_for([(int) $ticket->id]);
        $this->assertGreaterThan(1, count($logids), 'the fixture must produce more than one trail row');
        $needsinfologid = (int) $DB->get_field_select(
            'selfselectadvanced_ticketlog',
            'id',
            'ticketid = ? AND action = ?',
            [(int) $ticket->id, tickets::ACTION_NEEDSINFO]
        );
        $this->assertGreaterThan(0, $needsinfologid, 'fixture setup failed to log the needs-info question');

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_selfselectadvanced',
            'filearea' => tickets::FILEAREA_REQUEST,
            'itemid' => (int) $ticket->id,
            'filepath' => '/',
            'filename' => 'request.txt',
        ], 'the requester attachment');
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_selfselectadvanced',
            'filearea' => tickets::FILEAREA_POST,
            'itemid' => $needsinfologid,
            'filepath' => '/',
            'filename' => 'question.txt',
        ], 'the staff attachment on the question');

        $this->assertCount(
            1,
            $this->area_files($context, tickets::FILEAREA_REQUEST, (int) $ticket->id),
            'fixture setup failed'
        );
        $this->assertCount(
            1,
            $this->area_files($context, tickets::FILEAREA_POST, $needsinfologid),
            'fixture setup failed'
        );

        $status = selfselectadvanced_reset_userdata((object) [
            'courseid' => $course->id,
            'reset_selfselectadvanced_groups' => 1,
        ]);
        $this->assertIsArray($status);

        $this->assertCount(
            0,
            $DB->get_records_list('selfselectadvanced_ticketlog', 'id', $logids),
            'ticketlog rows survived a reset with the module context still standing'
        );
        $this->assertCount(
            0,
            $this->area_files($context, tickets::FILEAREA_REQUEST, (int) $ticket->id),
            'the ticketrequest attachment survived the reset - orphaned in a surviving context'
        );
        $this->assertCount(
            0,
            $this->area_files($context, tickets::FILEAREA_POST, $needsinfologid),
            'the ticketpost attachment survived the reset - orphaned in a surviving context'
        );
    }

    /**
     * Every file currently in one ticket file area/itemid.
     *
     * @param \context_module $context the activity context
     * @param string $filearea tickets::FILEAREA_*
     * @param int $itemid the ticket id (request) or ticketlog row id (post)
     * @return \stored_file[]
     */
    private function area_files(\context_module $context, string $filearea, int $itemid): array {
        return get_file_storage()->get_area_files(
            $context->id,
            'mod_selfselectadvanced',
            $filearea,
            $itemid,
            'filename',
            false
        );
    }

    /**
     * B4 (HIGH). Deleting the group underneath a live ticket must not
     * silence the LLM queue for the WHOLE activity - api_list_tickets must
     * still return every row, including the orphaned one, with a
     * placeholder group name rather than throwing
     * (TICKET-AUDIT-20260820.md, B4 / H-5).
     *
     * MUTATION CAUGHT: reverting llmapi::subject_name()/requester_role() to
     * call tickets::group_of() (which re-queries via groups::get(),
     * MUST_EXIST) throws dml_missing_record_exception here instead of
     * returning a result.
     */
    public function test_api_list_tickets_survives_a_group_deleted_underneath_a_ticket(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $cm, $leader, , $group] = $this->scene();
        $context = $activity->context();

        $service = $this->getDataGenerator()->create_user(['firstname' => 'Automated', 'lastname' => 'Assistant']);
        $this->getDataGenerator()->enrol_user($service->id, $activity->cm()->course, 'student');
        $apirole = $this->getDataGenerator()->create_role();
        assign_capability('mod/selfselectadvanced:api', CAP_ALLOW, $apirole, $context->id, true);
        assign_capability('mod/selfselectadvanced:coordinate', CAP_ALLOW, $apirole, $context->id, true);
        role_assign($apirole, $service->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $ticket = tickets::file_help($activity, $group, 'Please help.', FORMAT_PLAIN, (int) $leader->id);

        // The group row is deleted out from under the live ticket - the
        // same state a solo leader deleting their own forming group
        // produces (gatekeeper::can_delete_group()).
        $DB->delete_records('selfselectadvanced_group', ['id' => (int) $group->id]);

        $this->setUser($service);
        $result = api_list_tickets::execute($cm->id);
        $result = external_api::clean_returnvalue(api_list_tickets::execute_returns(), $result);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['tickets']);
        $row = reset($result['tickets']);
        $this->assertSame((int) $ticket->id, $row['id']);
        $this->assertSame(
            get_string('tickethasnoteam', 'mod_selfselectadvanced'),
            $row['groupname'],
            'an orphaned ticket should read as having no team, not throw'
        );
        // The group row that recorded leaderid/guideid is gone with the
        // group itself, so there is no relational fact left to answer
        // "leader of what" - raiser_role() falls through to its groupless
        // default ('member' -> ROLE_STUDENT) rather than throwing.
        $this->assertSame('student', $row['requester']['role']);
    }

    /**
     * Back an activity up and restore it into a fresh course.
     *
     * @param \stdClass $course the source course
     * @param \stdClass $cm the source course module
     * @param int $userid the acting admin
     * @param bool $userinfo whether to include user data (false = a
     *        duplicate/rollover style backup, per B8)
     * @return \stdClass the restored course module
     */
    private function roundtrip(\stdClass $course, \stdClass $cm, int $userid, bool $userinfo = true): \stdClass {
        global $DB;

        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cm->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $userid
        );
        $bc->get_plan()->get_setting('users')->set_value($userinfo);
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
     * A restore_task good only for get_restoreid() - just enough
     * scaffolding for restore_structure_step::get_mappingid() (which reads
     * backup_ids_temp keyed by restoreid) to work without a real archive
     * file or restore_controller. Genuinely producing an "unmappable
     * claimant" through a full backup/restore round trip is not
     * controllable in a deterministic test: Moodle's same-site restore
     * either re-links a soft-deleted account to its old id (precheck_user()
     * case 1C) or, when the row is truly gone, RECREATES it under a new id
     * whenever the restoring user holds moodle/restore:createuser (which an
     * admin always does) - in both cases claimedby ends up mapped to
     * SOMETHING, defeating the fixture. This drives the exact stepslib
     * method the fix changed directly, with a real backup_ids_temp row
     * standing in for "this old user id was never captured in the
     * archive" (audit B7/M-11's "not in the archive" half).
     *
     * @param string $restoreid the fake restore id
     * @return \restore_task
     */
    private function stub_restore_task(string $restoreid): \restore_task {
        return new class ($restoreid) extends \restore_task {
            /** @var string The restoreid get_restoreid() answers with. */
            private readonly string $fakerestoreid;

            /**
             * Constructor.
             *
             * @param string $fakerestoreid the restoreid get_restoreid() answers with
             */
            public function __construct(string $fakerestoreid) {
                $this->fakerestoreid = $fakerestoreid;
                parent::__construct('ticketlifecycle_stub', null);
            }

            /**
             * The fake restoreid, standing in for a real restore_plan's.
             *
             * @return string
             */
            public function get_restoreid() {
                return $this->fakerestoreid;
            }

            /**
             * Any value: process_ssaticket()'s trailing set_mapping(...,
             * true) asks for this when no explicit file context is given,
             * and no file is involved in this test.
             *
             * @return int
             */
            public function get_old_contextid() {
                return 0;
            }

            /**
             * No settings of its own.
             *
             * @return array
             */
            protected function define_settings() {
                return [];
            }

            /**
             * Nothing to build - this stub is never executed as a real task.
             */
            public function build() {
            }
        };
    }

    /**
     * B7 (MEDIUM). Restore must rescue a NEEDSINFO ticket whose claimant
     * did not survive the restore, exactly as it already rescues a CLAIMED
     * one - otherwise the ticket restores claimed-by-nobody with no exit:
     * the requester's reply goes to nobody (TICKET-AUDIT-20260820.md, B7 /
     * M-11).
     *
     * Drives restore_selfselectadvanced_activity_structure_step::
     * process_ssaticket() directly (see stub_restore_task() for why): a
     * backup_ids_temp mapping is seeded for the requester's old id, and
     * deliberately NOT for the claimant's - the same "unmappable" state
     * get_mappingid('user', ...) reports for a user never captured in the
     * archive, or already gone on the target site.
     *
     * MUTATION CAUGHT: reverting the STATUS_CLAIMED-only test in
     * restore_selfselectadvanced_stepslib.php back to a single-status
     * comparison leaves the restored ticket status='needsinfo' with
     * claimedby non-null (still pointing at the unmappable old id, which
     * PostgreSQL's NOT NULL-less int column would happily store), which
     * fails the assertions below.
     */
    public function test_restore_releases_a_needsinfo_ticket_whose_claimant_did_not_survive(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $requester = $generator->create_user();
        $generator->enrol_user($requester->id, $course->id, 'student');

        $restoreid = 'ticketlifecycle_' . uniqid();
        $task = $this->stub_restore_task($restoreid);
        $step = new \restore_selfselectadvanced_activity_structure_step('teststep', 'selfselectadvanced.xml', $task);

        // Get_new_parentid('selfselectadvanced') reads this in-memory map
        // rather than the DB - normally populated by the XML dispatcher
        // this test bypasses.
        $elementsnewid = new \ReflectionProperty($step, 'elementsnewid');
        $elementsnewid->setAccessible(true);
        $elementsnewid->setValue($step, ['selfselectadvanced' => (int) $instance->id]);

        // The real temporary table get_mappingid()/set_backup_ids_record()
        // read and write, keyed by restoreid - normally created by
        // restore_controller as part of a real restore's precheck.
        // Guarded by table_exists(): it is a genuinely temporary
        // (session-scoped, shared-by-name) table another test in this run
        // may already have created and not yet dropped; only the test that
        // creates it here drops it again, in the finally block below.
        $dbman = $DB->get_manager();
        $createdtemptable = false;
        if (!$dbman->table_exists('backup_ids_temp')) {
            \backup_controller_dbops::create_backup_ids_temp_table($restoreid);
            $createdtemptable = true;
        }

        try {
            $oldrequesterid = 555001;
            $oldclaimantid = 555002;
            \restore_dbops::set_backup_ids_record($restoreid, 'user', $oldrequesterid, (int) $requester->id);
            // No mapping recorded for $oldclaimantid at all.

            $method = new \ReflectionMethod($step, 'process_ssaticket');
            $method->setAccessible(true);
            $now = time();
            $method->invoke($step, [
                'id' => 909001,
                'groupid' => null,
                'type' => tickets::TYPE_HELP,
                'status' => tickets::STATUS_NEEDSINFO,
                'requestedby' => $oldrequesterid,
                'request' => 'Please help.',
                'requestformat' => FORMAT_PLAIN,
                'claimedby' => $oldclaimantid,
                'timeclaimed' => $now,
                'resolvedby' => null,
                'timeresolved' => null,
                'resolution' => null,
                'resolutionformat' => FORMAT_PLAIN,
                'requested' => null,
                'disclaimerack' => 0,
                'escalated' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            $restored = $DB->get_record('selfselectadvanced_ticket', ['activityid' => $instance->id], '*', MUST_EXIST);

            $this->assertSame(
                tickets::STATUS_OPEN,
                $restored->status,
                'a needs-info ticket whose claimant did not survive restore must be released back to the queue'
            );
            $this->assertNull($restored->claimedby, 'the unmappable claimant must not survive as a dangling id');
            $this->assertNull($restored->timeclaimed);
        } finally {
            if ($createdtemptable) {
                \backup_controller_dbops::drop_backup_ids_temp_table($restoreid);
            }
        }
    }

    /**
     * B8 (MEDIUM). The knowledgebank must survive a backup/restore taken
     * WITHOUT user data - the ordinary shape of Duplicate, or a course
     * rollover with "include enrolled users" unticked. Quotas, qslots and
     * templates already travel unconditionally; the knowledgebank did not
     * (TICKET-AUDIT-20260820.md, B8 / M-12).
     *
     * MUTATION CAUGHT: reverting $kbentry->set_source_table() and the
     * ssakbentry restore_path_element back inside `if ($userinfo)` leaves
     * $DB->count_records(...) at 0 after this no-userinfo roundtrip.
     */
    public function test_knowledgebank_survives_a_backup_without_userinfo(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);
        $activity = activity::from_instance((int) $instance->id);

        kb::create($activity, (int) $admin->id, [
            'title' => 'How do groups freeze?',
            'question' => 'What happens at the deadline?',
            'answer' => 'Every unplaced student is auto-grouped.',
            'tickettype' => '',
        ]);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_kb', ['activityid' => $instance->id]));

        $newcm = $this->roundtrip($course, $cm, (int) $admin->id, false);

        $this->assertSame(
            1,
            $DB->count_records('selfselectadvanced_kb', ['activityid' => $newcm->instance]),
            'the knowledgebank did not survive a no-userinfo backup/restore (Duplicate loses every FAQ)'
        );
        $restoredrows = $DB->get_records('selfselectadvanced_kb', ['activityid' => $newcm->instance]);
        $restored = reset($restoredrows);
        $this->assertSame('How do groups freeze?', $restored->title);
    }

    /**
     * B10 (MEDIUM). Restore must decode encoded activity links inside a
     * ticketlog note and inside a knowledgebank article, the same way it
     * already decodes them inside the ticket row itself - otherwise a
     * restored thread or FAQ shows the literal $@...@$ token
     * (TICKET-AUDIT-20260820.md, B10 / M-15).
     *
     * MUTATION CAUGHT: removing either restore_decode_content entry in
     * restore_selfselectadvanced_activity_task.class.php (or, for the kb
     * half, removing process_ssakbentry()'s set_mapping('ssakbentry', ...)
     * call, which the decode rule depends on to find the row at all)
     * leaves the literal encoded token in the restored text instead of a
     * decoded view.php URL.
     */
    public function test_restore_decodes_links_in_ticketlog_notes_and_kb_articles(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);
        $activity = activity::from_instance((int) $instance->id);

        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');
        $requester = $generator->create_user();
        $generator->enrol_user($requester->id, $course->id, 'student');

        $link = (new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]))->out(false);

        $ticket = tickets::file_help($activity, null, 'A question.', FORMAT_PLAIN, (int) $requester->id);
        tickets::claim($activity, (int) $ticket->id, (int) $staff->id);
        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'See the roster at ' . $link,
            FORMAT_PLAIN,
            (int) $staff->id
        );

        kb::create($activity, (int) $staff->id, [
            'title' => 'Where is the roster?',
            'question' => 'Where do I find the roster?',
            'answer' => 'At ' . $link,
            'tickettype' => '',
        ]);

        $newcm = $this->roundtrip($course, $cm, (int) $admin->id, true);
        $newinstance = $newcm->instance;

        $restoredtickets = $DB->get_records('selfselectadvanced_ticket', ['activityid' => $newinstance]);
        $restoredticket = reset($restoredtickets);
        $restoredlogs = $DB->get_records_select(
            'selfselectadvanced_ticketlog',
            'ticketid = ? AND action = ?',
            [(int) $restoredticket->id, tickets::ACTION_RESOLVED]
        );
        $restoredlog = reset($restoredlogs);
        $this->assertNotFalse($restoredlog, 'the resolution trail row did not restore');
        $this->assertStringNotContainsString(
            '$@',
            (string) $restoredlog->note,
            'the ticketlog note carries an undecoded link token'
        );
        $this->assertStringContainsString('/mod/selfselectadvanced/view.php?id=', (string) $restoredlog->note);

        $restoredkbrows = $DB->get_records('selfselectadvanced_kb', ['activityid' => $newinstance]);
        $restoredkb = reset($restoredkbrows);
        $this->assertNotFalse($restoredkb, 'the knowledgebank article did not restore');
        $this->assertStringNotContainsString(
            '$@',
            (string) $restoredkb->answer,
            'the knowledgebank answer carries an undecoded link token'
        );
        $this->assertStringContainsString('/mod/selfselectadvanced/view.php?id=', (string) $restoredkb->answer);
    }
}
