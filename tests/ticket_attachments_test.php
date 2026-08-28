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
    // 1.20.60: where the attachment lands, and what the server refuses.

    /**
     * Put $count files of $bytes each into a fresh draft area belonging
     * to $user, and return its id.
     *
     * @param \stdClass $user the owner
     * @param int $count how many files
     * @param int $bytes how big each one is
     * @return int the draft item id
     */
    private function draft_with_files(\stdClass $user, int $count, int $bytes = 16): int {
        $this->setUser($user);
        $draftid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance((int) $user->id);
        for ($i = 1; $i <= $count; $i++) {
            get_file_storage()->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => '/',
                'filename' => 'evidence' . $i . '.txt',
            ], str_repeat('x', $bytes));
        }

        return $draftid;
    }

    /**
     * THE ATTACHMENT LANDS ON THE ROW THE SERVICE WROTE (audit
     * L-3/L-10/L-15), even when another trail row arrives in between.
     *
     * save_post_attachments() used to re-read the trail and take the
     * LAST row. Its own defence was that the caller is one HTTP request
     * and nothing else could be writing - which stopped being true when
     * the assistant gained comment() and had never been true for
     * escalate(), which any manage holder may fire on a live ticket
     * without holding the claim. The service call's lock is released
     * before the save runs, so a row arriving in that window took the
     * file: on a STAFF_INTERNAL_ACTIONS row, may_access_ticket_file()
     * then refused the uploader their own attachment.
     *
     * This test reproduces that window deliberately - the escalation
     * happens between the service call and the save - and asserts the
     * file is on the needs-info row the claimant actually wrote.
     */
    public function test_an_attachment_lands_on_the_row_the_service_wrote_not_the_newest(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide, $manager, $coordinator1] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap a member',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        $asked = tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which member?',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );
        $askedlogid = (int) $asked->ticketlogid;
        $this->assertGreaterThan(0, $askedlogid, 'the service must hand back the row it wrote');

        // THE WINDOW: a manage holder escalates before the file is
        // saved, writing a newer - and staff-internal - trail row.
        tickets::escalate($activity, (int) $ticket->id, 'Above the coordinator', FORMAT_PLAIN, (int) $manager->id);
        $trail = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $newest = end($trail);
        $this->assertSame(tickets::ACTION_ESCALATED, $newest->action, 'fixture: the newest row must be the escalation');
        $this->assertNotSame($askedlogid, (int) $newest->id);

        $draftid = $this->draft_with_files($coordinator1, 1);
        tickets::save_post_attachments($activity, $askedlogid, $draftid);

        $onasked = get_file_storage()->get_area_files(
            $activity->context()->id,
            'mod_selfselectadvanced',
            tickets::FILEAREA_POST,
            $askedlogid,
            'id',
            false
        );
        $onescalation = get_file_storage()->get_area_files(
            $activity->context()->id,
            'mod_selfselectadvanced',
            tickets::FILEAREA_POST,
            (int) $newest->id,
            'id',
            false
        );
        $this->assertCount(1, $onasked, 'the file belongs to the row the service wrote');
        $this->assertCount(0, $onescalation, 'and must never land on the row that arrived in the window');

        // And the consequence that made this a real defect rather than a
        // tidiness point: the REQUESTER can reach it. The requester of
        // this ticket is the guide who filed it, not the group's leader
        // - a composition change is the guide's to raise - and getting
        // that wrong is the difference between asserting the access rule
        // and asserting that a stranger is refused.
        $this->assertTrue(tickets::may_access_ticket_file(
            $activity,
            tickets::FILEAREA_POST,
            $askedlogid,
            (int) $guide->id
        ), 'the person who asked must be able to read the answer they were sent');
        // The leader is nobody here: not the requester, no queue
        // authority. The rule refuses them, which is what makes the
        // assertion above mean something.
        $this->assertFalse(tickets::may_access_ticket_file(
            $activity,
            tickets::FILEAREA_POST,
            $askedlogid,
            (int) $leader->id
        ));
        unset($DB);
    }

    /**
     * MORE FILES THAN THE DOCUMENTED LIMIT ARE REFUSED BY THE SERVER
     * (audit L-16). file_options() has said "maxfiles 5" since the
     * feature shipped and every filemanager enforced it - in the
     * browser. A replayed or hand-made POST met nothing at all.
     */
    public function test_more_attachments_than_the_limit_are_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , , , , $coordinator1] = $this->setup_world();

        $max = (int) tickets::file_options()['maxfiles'];
        $this->assertGreaterThan(0, $max, 'the limit under test must be a real number');

        // Exactly the limit is fine.
        $ok = $this->draft_with_files($coordinator1, $max);
        tickets::require_within_file_limits($ok);

        // One more is not.
        $toomany = $this->draft_with_files($coordinator1, $max + 1);
        try {
            tickets::require_within_file_limits($toomany);
            $this->fail('a draft area holding more than the documented limit must be refused');
        } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
            $this->assertSame(
                get_string('refusalticketattachmentcount', 'mod_selfselectadvanced', $max),
                $e->getMessage()
            );
        }
    }

    /**
     * A file bigger than the site's own ceiling is refused (audit
     * L-16), and the refusal quotes the ceiling in the units a person
     * reads rather than a raw byte count.
     */
    public function test_an_oversized_attachment_is_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , , , , $coordinator1] = $this->setup_world();

        // A deliberately tiny ceiling, so the fixture file does not have
        // to be large. moodlecourse's own setting is zeroed first: the
        // service prefers it when set, and a site default of "unlimited"
        // must not be able to hide this test's real subject.
        set_config('maxbytes', 0, 'moodlecourse');
        set_config('maxbytes', 100);

        $small = $this->draft_with_files($coordinator1, 1, 50);
        tickets::require_within_file_limits($small);

        $big = $this->draft_with_files($coordinator1, 1, 500);
        try {
            tickets::require_within_file_limits($big);
            $this->fail('a file larger than the site ceiling must be refused');
        } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
            $this->assertSame(
                get_string('refusalticketattachmentsize', 'mod_selfselectadvanced', display_size(100)),
                $e->getMessage()
            );
        }
        unset($activity);
    }

    /**
     * The no-ops: nothing submitted, or no row to attach to. Both are
     * deliberate returns rather than errors, and neither may create a
     * file area out of nothing.
     */
    public function test_saving_with_no_draft_or_no_row_does_nothing(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Swap', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        $asked = tickets::request_info($activity, (int) $ticket->id, 'Which one?', FORMAT_PLAIN, (int) $coordinator1->id);

        // No draft.
        tickets::save_post_attachments($activity, (int) $asked->ticketlogid, 0);
        // No row.
        $draftid = $this->draft_with_files($coordinator1, 1);
        tickets::save_post_attachments($activity, 0, $draftid);

        $this->assertCount(0, get_file_storage()->get_area_files(
            $activity->context()->id,
            'mod_selfselectadvanced',
            tickets::FILEAREA_POST,
            (int) $asked->ticketlogid,
            'id',
            false
        ));
        // A zero draft is the same no-op for require_within_file_limits():
        // there is nothing to measure, and refusing here would
        // break every submission that carries no attachment at all.
        tickets::require_within_file_limits(0);
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
