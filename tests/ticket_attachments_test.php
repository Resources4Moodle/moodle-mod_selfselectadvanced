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
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * 1.20.44 part 2 - attachments on ticket posts: the two file areas
 * (ticketrequest, itemid = ticket id; ticketpost, itemid = ticketlog row
 * id), served ONLY through tickets::may_access_ticket_file() - the ONE
 * shared implementation the spec demands, proven here through the REAL
 * production entry point (selfselectadvanced_pluginfile()), not the
 * predicate alone, in the style of proposalaccess_test.php (audit A-05).
 *
 * RED-FIRST EVIDENCE (captured 2026-08-15, PHPUnit run on m5pg against
 * this same tree, ONLY the requester-narrowing arm of
 * tickets::may_access_ticket_file() temporarily removed BY HAND - the
 * `if ($isstaff) { return true; } return !in_array(...)` tail replaced
 * with a bare `return true;`, so a requester would see every ticketpost
 * file including a staff-internal one):
 *
 * mod_selfselectadvanced\ticket_attachments_test::test_requester_refused_on_staff_internal_note
 * a requester must be refused a file on a staff-internal (referred) trail row
 * Failed asserting that true is false.
 *
 * /path/.../tests/ticket_attachments_test.php:307
 * FAILURES!
 * Tests: 1, Assertions: 2, Failures: 1, PHPUnit Deprecations: 1.
 *
 * Restored (`diff` confirmed byte-identical) and re-verified green (this
 * whole file, all 12 tests green, 35 assertions).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets::may_access_ticket_file
 * @covers     ::selfselectadvanced_pluginfile
 * @covers     \mod_selfselectadvanced\privacy\provider
 */
final class ticket_attachments_test extends \core_privacy\tests\provider_testcase {
    /** @var string What every fixture file contains. */
    private const BODY = 'the attached evidence';

    /**
     * A firm group (leader, confirmed member, guide), a manager, TWO
     * coordinators and an uninvolved student.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator1, coordinator2, outsider]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'ATTACH1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $outsider = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($member->id, $course->id, 'student');
        $generator->enrol_user($outsider->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $coordinator1 = $generator->create_user();
        $generator->enrol_user($coordinator1->id, $course->id, 'teacher');
        $coordinator2 = $generator->create_user();
        $generator->enrol_user($coordinator2->id, $course->id, 'teacher');
        $modcontext = \context_module::instance((int) $instance->cmid);
        role_assign(coordinatorrole::ensure(), $coordinator1->id, $modcontext);
        role_assign(coordinatorrole::ensure(), $coordinator2->id, $modcontext);

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Attach',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            groups::get($activity, (int) $group->id),
            $leader,
            $member,
            $guide,
            $manager,
            $coordinator1,
            $coordinator2,
            $outsider,
        ];
    }

    /**
     * Drop a real file into a filearea, returning it.
     *
     * @param activity $activity the activity
     * @param string $filearea tickets::FILEAREA_*
     * @param int $itemid the ticket id or ticketlog row id
     * @param string $filename the filename
     * @param string|null $content the body, or null for the shared default
     * @return \stored_file
     */
    private function put_file(
        activity $activity,
        string $filearea,
        int $itemid,
        string $filename,
        ?string $content = null
    ): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $activity->context()->id,
            'component' => 'mod_selfselectadvanced',
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content ?? self::BODY);
    }

    /**
     * Ask the REAL file server (not the predicate alone) for a file, AS
     * somebody - the same 'dontdie' + conditional-request-304
     * accommodation proposalaccess_test.php::fetch() uses, for the same
     * reason (send_stored_file() would otherwise end the process).
     *
     * @param activity $activity the activity
     * @param \stdClass $course the course
     * @param string $filearea tickets::FILEAREA_*
     * @param int $itemid the ticket id or ticketlog row id
     * @param string $filename the filename
     * @param \stdClass $viewer who is asking
     * @param string $contenthash the file's content hash, for the ETag
     * @return bool whether the file server served them
     */
    private function fetch(
        activity $activity,
        \stdClass $course,
        string $filearea,
        int $itemid,
        string $filename,
        \stdClass $viewer,
        string $contenthash
    ): bool {
        $this->setUser($viewer);
        $cm = $activity->cm();

        $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $contenthash . '"';
        try {
            return (bool) @selfselectadvanced_pluginfile(
                $course,
                $cm,
                $activity->context(),
                $filearea,
                [(string) $itemid, $filename],
                true,
                ['dontdie' => true]
            );
        } finally {
            unset($_SERVER['HTTP_IF_NONE_MATCH']);
        }
    }

    // ------------------------------------------------------------------
    // The access rule: tickets::may_access_ticket_file() directly.

    /**
     * ticketrequest: the requester, staff (manage or coordinate), and an
     * uninvolved outsider - all in one method since none of these calls
     * ever refuses (no PostgreSQL transaction-poison risk: this predicate
     * writes nothing at all).
     */
    public function test_ticketrequest_access_rule(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator1, , $outsider] = $this->setup_world();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);

        $this->assertTrue(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, (int) $guide->id),
            'the requester must read their own opening request attachment'
        );
        $this->assertTrue(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, (int) $manager->id),
            'a manage holder must read it'
        );
        $this->assertTrue(
            tickets::may_access_ticket_file(
                $activity,
                tickets::FILEAREA_REQUEST,
                (int) $ticket->id,
                (int) $coordinator1->id
            ),
            'a coordinate holder must read it, unclaimed and uninvolved though they are'
        );
        $this->assertFalse(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, (int) $outsider->id),
            'an uninvolved student must be refused'
        );
    }

    /**
     * ticketpost on the needs-info question: the requester it was asked
     * of may read it, an outsider may not.
     */
    public function test_ticketpost_access_requester_on_question(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, , $outsider] = $this->setup_world();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $coordinator1->id);
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $questionlog = end($trail);

        $this->assertTrue(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_POST, (int) $questionlog->id, (int) $guide->id),
            'the requester must read a file on the question asked of them'
        );
        $this->assertFalse(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_POST, (int) $questionlog->id, (int) $outsider->id),
            'an uninvolved student must be refused even on a question row'
        );
    }

    /**
     * ticketpost on the resolution: the requester may read it.
     */
    public function test_ticketpost_access_requester_on_resolution(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Added Priya.',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $resolvedlog = end($trail);

        $this->assertTrue(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_POST, (int) $resolvedlog->id, (int) $guide->id),
            'the requester must read a file on their own resolution'
        );
    }

    /**
     * RED-FIRST PROOF (see this file's docblock): a requester is refused
     * a file on a STAFF-INTERNAL row (a referral note) - staff read it
     * fine. Negative-only for the requester arm (no PG poison risk: this
     * predicate never writes), positive control for staff alongside it
     * since neither commits anything either.
     */
    public function test_requester_refused_on_staff_internal_note(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::refer(
            $activity,
            (int) $ticket->id,
            (int) $coordinator2->id,
            'Passing this to a colleague',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $referredlog = end($trail);
        $this->assertSame(tickets::ACTION_REFERRED, $referredlog->action, 'fixture: the last row must be the referral');

        $this->assertFalse(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_POST, (int) $referredlog->id, (int) $guide->id),
            'a requester must be refused a file on a staff-internal (referred) trail row'
        );
        $this->assertTrue(
            tickets::may_access_ticket_file(
                $activity,
                tickets::FILEAREA_POST,
                (int) $referredlog->id,
                (int) $coordinator1->id
            ),
            'staff must still read a staff-internal row\'s file'
        );
    }

    /**
     * An unknown itemid (no such ticketlog row) is refused, not fatal.
     */
    public function test_ticketpost_unknown_itemid_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , , , $manager] = $this->setup_world();

        $this->assertFalse(
            tickets::may_access_ticket_file($activity, tickets::FILEAREA_POST, 999999999, (int) $manager->id)
        );
    }

    /**
     * An unknown filearea name is a coding fault, not a silent refusal -
     * lib.php's own whitelist keeps a caller from ever reaching this with
     * anything else, so this pins the contract rather than production
     * behaviour reachable through the file server.
     */
    public function test_unknown_filearea_is_a_coding_exception(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , , , $manager] = $this->setup_world();

        $this->expectException(\coding_exception::class);
        tickets::may_access_ticket_file($activity, 'something_else', 1, (int) $manager->id);
    }

    // ------------------------------------------------------------------
    // The real file server (selfselectadvanced_pluginfile()).

    /**
     * The production entry point agrees with the predicate, both
     * directions, for both fileareas - proposalaccess_test.php's own
     * discipline: a refusal returns false, an admission runs the real
     * send_stored_file() and returns true.
     */
    public function test_the_file_server_agrees_with_the_predicate(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, , $outsider] = $this->setup_world();
        $course = $DB->get_record('course', ['id' => $activity->courseid()], '*', MUST_EXIST);

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        $requestfile = $this->put_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, 'request.txt');

        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $coordinator1->id);
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $questionlog = end($trail);
        $postfile = $this->put_file($activity, tickets::FILEAREA_POST, (int) $questionlog->id, 'question.txt');

        $this->assertTrue(
            $this->fetch(
                $activity,
                $course,
                tickets::FILEAREA_REQUEST,
                (int) $ticket->id,
                'request.txt',
                $guide,
                $requestfile->get_contenthash()
            ),
            'the requester must be served their own opening attachment'
        );
        $this->assertFalse(
            $this->fetch(
                $activity,
                $course,
                tickets::FILEAREA_REQUEST,
                (int) $ticket->id,
                'request.txt',
                $outsider,
                $requestfile->get_contenthash()
            ),
            'an outsider must be refused'
        );
        $this->assertTrue(
            $this->fetch(
                $activity,
                $course,
                tickets::FILEAREA_POST,
                (int) $questionlog->id,
                'question.txt',
                $guide,
                $postfile->get_contenthash()
            ),
            'the requester must be served the question attachment'
        );
        $this->assertFalse(
            $this->fetch(
                $activity,
                $course,
                tickets::FILEAREA_POST,
                (int) $questionlog->id,
                'question.txt',
                $outsider,
                $postfile->get_contenthash()
            ),
            'an outsider must be refused the question attachment too'
        );
    }

    // ------------------------------------------------------------------
    // Backup / restore.

    /**
     * Both file areas survive a backup/restore round trip with their
     * CONTENT intact (asserted by hash, not merely "a file exists").
     */
    public function test_attachments_survive_backup_and_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();
        $cm = $activity->cm();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        $requestfile = $this->put_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, 'request.txt');
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::close($activity, (int) $ticket->id, tickets::STATUS_RESOLVED, 'Sorted.', FORMAT_PLAIN, (int) $coordinator1->id);
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $resolvedlog = end($trail);
        $postfile = $this->put_file($activity, tickets::FILEAREA_POST, (int) $resolvedlog->id, 'resolution.txt');

        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cm->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int) $admin->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $results = $bc->get_results();
        $this->assertArrayHasKey('backup_destination', $results, 'the backup produced no archive');
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
            (int) $admin->id,
            backup::TARGET_CURRENT_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $restoredinstances = $DB->get_records('selfselectadvanced', ['course' => $target->id], 'id DESC');
        $this->assertNotEmpty($restoredinstances, 'the activity did not restore at all');
        $restoredinstance = reset($restoredinstances);
        $restoredcm = get_coursemodule_from_instance('selfselectadvanced', (int) $restoredinstance->id, $target->id);
        $restoredcontext = \context_module::instance((int) $restoredcm->id);

        $restoredticket = $DB->get_record(
            'selfselectadvanced_ticket',
            ['activityid' => (int) $restoredinstance->id, 'type' => tickets::TYPE_COMPCHANGE],
            '*',
            MUST_EXIST
        );
        $fs = get_file_storage();
        $restoredrequestfiles = $fs->get_area_files(
            $restoredcontext->id,
            'mod_selfselectadvanced',
            tickets::FILEAREA_REQUEST,
            (int) $restoredticket->id,
            'id',
            false
        );
        $this->assertCount(1, $restoredrequestfiles, 'the opening request attachment must survive the round trip');
        $this->assertSame(
            $requestfile->get_contenthash(),
            reset($restoredrequestfiles)->get_contenthash(),
            'the restored request attachment content must match exactly'
        );

        $restoredresolvedlog = $DB->get_record(
            'selfselectadvanced_ticketlog',
            ['ticketid' => (int) $restoredticket->id, 'action' => tickets::ACTION_RESOLVED],
            '*',
            MUST_EXIST
        );
        $restoredpostfiles = $fs->get_area_files(
            $restoredcontext->id,
            'mod_selfselectadvanced',
            tickets::FILEAREA_POST,
            (int) $restoredresolvedlog->id,
            'id',
            false
        );
        $this->assertCount(1, $restoredpostfiles, 'the resolution attachment must survive the round trip');
        $this->assertSame(
            $postfile->get_contenthash(),
            reset($restoredpostfiles)->get_contenthash(),
            'the restored resolution attachment content must match exactly'
        );
    }

    // ------------------------------------------------------------------
    // Privacy.

    /**
     * The requester's own export includes the opening request's
     * attachment and the resolution's attachment.
     */
    public function test_privacy_export_includes_ticket_attachments(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();
        $context = $activity->context();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        $requestfile = $this->put_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, 'request.txt');
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::close($activity, (int) $ticket->id, tickets::STATUS_RESOLVED, 'Sorted.', FORMAT_PLAIN, (int) $coordinator1->id);
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $resolvedlog = end($trail);
        $postfile = $this->put_file($activity, tickets::FILEAREA_POST, (int) $resolvedlog->id, 'resolution.txt');

        $this->export_context_data_for_user((int) $guide->id, $context, 'mod_selfselectadvanced');
        $writer = \core_privacy\local\request\writer::with_context($context);

        // AUDIT B11/M-17: each ticket now owns a folder, and a post owns
        // one inside it. Both areas declare subdirs = 0, so before this
        // the filename was the only discriminator and two tickets'
        // same-named attachments shared - and overwrote - one path.
        $base = [get_string('pluginname', 'mod_selfselectadvanced'), get_string('tickets', 'mod_selfselectadvanced')];
        $ticketleaf = array_merge($base, ['ticket-' . (int) $ticket->id]);
        $requestexports = $writer->get_files($ticketleaf);
        $this->assertNotEmpty($requestexports, 'the opening request attachment must be exported');
        $this->assertContains(
            $requestfile->get_contenthash(),
            array_map(static fn($f) => $f->get_contenthash(), $requestexports)
        );
        $postexports = $writer->get_files(array_merge($ticketleaf, ['post-' . (int) $resolvedlog->id]));
        $this->assertNotEmpty($postexports, 'the resolution attachment must be exported under its own post');
        $this->assertContains(
            $postfile->get_contenthash(),
            array_map(static fn($f) => $f->get_contenthash(), $postexports)
        );
    }

    /**
     * TWO tickets, each with an attachment of the SAME NAME, must both
     * survive the export (audit B11/M-17).
     *
     * This is the defect itself rather than the shape of the fix: with
     * one flat folder the second file simply replaced the first, and the
     * subject-access export handed the person one file where they had
     * uploaded two. A single-ticket fixture cannot see that at all,
     * which is why the test above could not have caught it.
     */
    public function test_privacy_export_keeps_same_named_attachments_apart(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , ] = $this->setup_world();
        $context = $activity->context();

        $first = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'One', FORMAT_PLAIN, (int) $guide->id);
        $firstfile = $this->put_file($activity, tickets::FILEAREA_REQUEST, (int) $first->id, 'evidence.txt', 'FIRST');
        tickets::withdraw($activity, (int) $first->id, (int) $guide->id);
        $second = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Two', FORMAT_PLAIN, (int) $guide->id);
        $secondfile = $this->put_file($activity, tickets::FILEAREA_REQUEST, (int) $second->id, 'evidence.txt', 'SECOND');
        $this->assertNotSame(
            $firstfile->get_contenthash(),
            $secondfile->get_contenthash(),
            'the fixture must use two DIFFERENT files sharing one name, or it proves nothing'
        );

        $this->export_context_data_for_user((int) $guide->id, $context, 'mod_selfselectadvanced');
        $writer = \core_privacy\local\request\writer::with_context($context);
        $base = [get_string('pluginname', 'mod_selfselectadvanced'), get_string('tickets', 'mod_selfselectadvanced')];

        $firsthashes = array_map(
            static fn($f) => $f->get_contenthash(),
            $writer->get_files(array_merge($base, ['ticket-' . (int) $first->id]))
        );
        $secondhashes = array_map(
            static fn($f) => $f->get_contenthash(),
            $writer->get_files(array_merge($base, ['ticket-' . (int) $second->id]))
        );
        $this->assertContains($firstfile->get_contenthash(), $firsthashes, 'the first ticket\'s file was lost');
        $this->assertContains($secondfile->get_contenthash(), $secondhashes, 'the second ticket\'s file was lost');
    }

    /**
     * A referral's staff-internal note carries no filemanager, so
     * nothing is ever exported for it even though the requester's own
     * ticket export includes the referral TEXT row (unchanged 1.20.42
     * behaviour, not this slice's to narrow) - a direct file dropped in
     * by hand (never reachable through the UI) proves the export truly
     * has nothing to find, not merely that the UI never gave it
     * anything to find.
     */
    public function test_privacy_export_excludes_staff_internal_note_files(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();
        $context = $activity->context();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::refer(
            $activity,
            (int) $ticket->id,
            (int) $coordinator2->id,
            'Handing over',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $referredlog = end($trail);
        // Dropped in directly - the UI never offers a filemanager here,
        // but the export must not find it even if one somehow existed.
        $strayfile = $this->put_file($activity, tickets::FILEAREA_POST, (int) $referredlog->id, 'stray.txt');

        $this->export_context_data_for_user((int) $guide->id, $context, 'mod_selfselectadvanced');
        $writer = \core_privacy\local\request\writer::with_context($context);
        $subcontext = [get_string('pluginname', 'mod_selfselectadvanced'), get_string('tickets', 'mod_selfselectadvanced')];
        $exports = $writer->get_files($subcontext);
        $hashes = array_map(static fn($f) => $f->get_contenthash(), $exports);
        $this->assertNotContains(
            $strayfile->get_contenthash(),
            $hashes,
            'export_area_files() must never have been called for the staff-internal row\'s itemid'
        );
    }

    /**
     * A full context purge (delete_data_for_all_users_in_context) wipes
     * both file areas.
     */
    public function test_full_context_purge_deletes_ticket_files(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();
        $context = $activity->context();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        $this->put_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, 'request.txt');
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $coordinator1->id);
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $questionlog = end($trail);
        $this->put_file($activity, tickets::FILEAREA_POST, (int) $questionlog->id, 'question.txt');

        $fs = get_file_storage();
        $this->assertNotEmpty($fs->get_area_files($context->id, 'mod_selfselectadvanced', tickets::FILEAREA_REQUEST));
        $this->assertNotEmpty($fs->get_area_files($context->id, 'mod_selfselectadvanced', tickets::FILEAREA_POST));

        \mod_selfselectadvanced\privacy\provider::delete_data_for_all_users_in_context($context);

        $this->assertEmpty(
            $fs->get_area_files($context->id, 'mod_selfselectadvanced', tickets::FILEAREA_REQUEST, false, 'id', false),
            'ticketrequest files must be gone after a full context purge'
        );
        $this->assertEmpty(
            $fs->get_area_files($context->id, 'mod_selfselectadvanced', tickets::FILEAREA_POST, false, 'id', false),
            'ticketpost files must be gone after a full context purge'
        );
    }

    /**
     * Deletion policy (spec, verbatim): a requester purge deletes files;
     * a handler de-link does not touch the requester's own attachment.
     */
    public function test_requester_purge_deletes_files_handler_delink_does_not(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();
        $context = $activity->context();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need help', FORMAT_PLAIN, (int) $guide->id);
        $requestfile = $this->put_file($activity, tickets::FILEAREA_REQUEST, (int) $ticket->id, 'request.txt');
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::close($activity, (int) $ticket->id, tickets::STATUS_RESOLVED, 'Sorted.', FORMAT_PLAIN, (int) $coordinator1->id);
        $trail = tickets::trail($activity, (int) $ticket->id, true);
        $resolvedlog = end($trail);
        $resolutionfile = $this->put_file($activity, tickets::FILEAREA_POST, (int) $resolvedlog->id, 'resolution.txt');

        $fs = get_file_storage();

        // The HANDLER (coordinator1) is purged first: de-linked, never
        // deletes the requester's own request attachment or their own
        // resolution attachment (both belong to the ticket/trail, not
        // to the handler's identity).
        $handlerlist = new \core_privacy\tests\request\approved_contextlist(
            $coordinator1,
            'mod_selfselectadvanced',
            [$context->id]
        );
        \mod_selfselectadvanced\privacy\provider::delete_data_for_user($handlerlist);

        $this->assertNotEmpty(
            $fs->get_area_files($context->id, 'mod_selfselectadvanced', tickets::FILEAREA_REQUEST, (int) $ticket->id),
            'a handler purge must not delete the requester\'s own opening attachment'
        );
        $this->assertNotEmpty(
            $fs->get_area_files($context->id, 'mod_selfselectadvanced', tickets::FILEAREA_POST, (int) $resolvedlog->id),
            'a handler purge must not delete the resolution attachment their own trail row carried'
        );

        // Now the REQUESTER is purged: their ticket is deleted outright,
        // and so are both attachments that travelled with it.
        $requesterlist = new \core_privacy\tests\request\approved_contextlist(
            $guide,
            'mod_selfselectadvanced',
            [$context->id]
        );
        \mod_selfselectadvanced\privacy\provider::delete_data_for_user($requesterlist);

        $this->assertFalse(
            $DB->record_exists('selfselectadvanced_ticket', ['id' => $ticket->id]),
            'fixture: the ticket must be gone'
        );
        $this->assertEmpty(
            $fs->get_area_files($context->id, 'mod_selfselectadvanced', tickets::FILEAREA_REQUEST, (int) $ticket->id, 'id', false),
            'the requester\'s own opening attachment must be deleted with their ticket'
        );
        $this->assertEmpty(
            $fs->get_area_files(
                $context->id,
                'mod_selfselectadvanced',
                tickets::FILEAREA_POST,
                (int) $resolvedlog->id,
                'id',
                false
            ),
            'the resolution attachment must be deleted with the trail it belonged to'
        );
        unset($requestfile, $resolutionfile);
    }
}
