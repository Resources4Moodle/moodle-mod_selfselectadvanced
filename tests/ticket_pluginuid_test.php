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
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * 1.20.56 deliverable A: a ticket you can quote.
 *
 * selfselectadvanced_ticket.pluginuid - CHAR(64) NOT NULL, UNIQUE index,
 * matching selfselectadvanced_group.pluginuid exactly - is minted by every
 * one of the five filers (file(), file_help(), file_guidecap(),
 * file_guidereduce(), file_guidegone()) INSIDE the lock each already
 * holds, exactly once, right after the row's own id exists, and never
 * rewritten afterwards. It is shown wherever a ticket is named: the
 * thread header, the staff queue, My requests, the group page's live
 * request rows.
 *
 * RED-FIRST (run 2026-08-20, PHPUnit on m5pg against this same tree, with
 * the two mint-and-persist lines removed from tickets::file() only - see
 * test_every_filer_mints_a_distinct_non_empty_reference()'s own docblock
 * for the exact mutation):
 *
 *   1) dml_write_exception: Error writing to database (ERROR:  duplicate
 *      key value violates unique constraint "phpu_selftick_plu_uix"
 *      DETAIL:  Key (pluginuid)=() already exists. ...) - file_help()'s
 *      insert collided with file()'s, because file() had just left its
 *      own row's pluginuid at the '' the insert array primes it with.
 *   2) test_the_reference_is_minted_once_and_never_rewritten: "Failed
 *      asserting that a string is not empty."
 *
 *   Tests: 6, Assertions: 6, Errors: 1, Failures: 1.
 *
 * Reverting the mutation restored a full pass (6 tests, 29 assertions)
 * with no other change.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class ticket_pluginuid_test extends \advanced_testcase {
    /**
     * A clean held-lock set per test - several tests here call more than
     * one filer, each taking its own lock key.
     */
    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
    }

    /**
     * Release anything a failed test left behind.
     */
    protected function tearDown(): void {
        locks::reset_state();
        parent::tearDown();
    }

    /**
     * An activity with a firm group: leader, confirmed member, guide,
     * manager, coordinator. Shaped exactly like
     * ticket_richtext_test.php::setup_world() - the same fixture the rest
     * of the ticket queue is tested against.
     *
     * @param string $shortname the course shortname (distinct per test
     *        that builds more than one world, so build_pluginuid()'s
     *        course-derived segment differs too)
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(string $shortname = 'UID1'): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => $shortname]);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($member->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_module::instance((int) $instance->cmid));

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Referenced',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $member, $guide, $manager, $coordinator];
    }

    /**
     * Every one of the five filers mints a non-empty reference matching
     * build_pluginuid()'s own shape (PREFIX-COURSE-Tnumber), and every
     * one of the five is DISTINCT from the others - the ticket's own id
     * is what carries the uniqueness, so five different tickets can never
     * collide even across different filers and different activities.
     *
     * file_guidecap() and file_guidereduce() are filed by two DIFFERENT
     * guides deliberately: file()'s own duplicate guard for those two
     * types is shared per (activity, requestedby) - one guide asking up
     * and down at once is two contradictory instructions in one queue -
     * and that guard is not what this test is about, so it is sidestepped
     * rather than tripped.
     *
     * MUTATION: with the two mint-and-persist lines removed from
     * tickets::file() only (leaving the other four filers untouched),
     * build_pluginuid() is never called for that filer, so the row's
     * pluginuid stays at the '' the insert array primes it with - see
     * the release report for the literal PHPUnit output of both runs.
     */
    public function test_every_filer_mints_a_distinct_non_empty_reference(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $leader, $member, $guide] = $this->setup_world('UID1');

        $tfile = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'The leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        $thelp = tickets::file_help($activity, $group, 'A general question', FORMAT_PLAIN, (int) $leader->id);

        $ceiling = (new api($activity))->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guide->id);
        $tcap = tickets::file_guidecap($activity, $ceiling->value + 1, 'Room for one more', FORMAT_PLAIN, (int) $guide->id);

        // A second, wholly independent world for guidereduce and
        // guidegone - a different activity AND a different guide, so
        // neither the shared cap/reduce duplicate guard nor
        // file_guidegone()'s own state re-read can be tripped by
        // anything the first world already did.
        [$activity2, $group2, , , $guide2] = $this->setup_world('UID2');
        $treduce = tickets::file_guidereduce($activity2, 0, 'Stepping back', FORMAT_PLAIN, (int) $guide2->id);
        $tgone = tickets::file_guidegone(
            $activity2,
            (int) $group2->id,
            (int) $guide2->id,
            'deleted',
            (int) $group2->leaderid
        );
        $this->assertNotNull($tgone, 'file_guidegone() must file against a firm team whose guide matches');

        $shapepattern = '/^[A-Z0-9]{1,8}-[A-Z0-9]{1,12}-T\d{4,}$/';
        $tickets = ['file' => $tfile, 'file_help' => $thelp, 'file_guidecap' => $tcap,
            'file_guidereduce' => $treduce, 'file_guidegone' => $tgone];
        $seen = [];
        foreach ($tickets as $filer => $ticket) {
            $this->assertNotEmpty($ticket->pluginuid, $filer . '() minted an empty reference');
            $this->assertMatchesRegularExpression(
                $shapepattern,
                $ticket->pluginuid,
                $filer . '() minted "' . $ticket->pluginuid . '", not the PREFIX-COURSE-Tnumber shape'
            );
            $this->assertArrayNotHasKey($ticket->pluginuid, $seen, $filer . '() re-used a reference another filer already minted');
            $seen[$ticket->pluginuid] = $filer;
        }
        $this->assertCount(5, $seen, 'five filers must mint five distinct references');
        $sink->close();
    }

    /**
     * The reference minted at file() time is the SAME reference the row
     * carries after every later lifecycle step - claimed, needs-info,
     * answered, commented, resolved - reading it back from the database
     * each time rather than trusting an in-memory copy that could have
     * drifted from what was actually stored.
     */
    public function test_the_reference_is_minted_once_and_never_rewritten(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, , , , $manager] = $this->setup_world();

        $filed = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap in a specialist',
            FORMAT_PLAIN,
            (int) $group->guideid
        );
        $original = $filed->pluginuid;
        $this->assertNotEmpty($original);

        $claimed = tickets::claim($activity, (int) $filed->id, (int) $manager->id);
        $this->assertSame($original, $claimed->pluginuid, 'claim() must not rewrite the reference');

        $needsinfo = tickets::request_info($activity, (int) $filed->id, 'Which subject?', FORMAT_PLAIN, (int) $manager->id);
        $this->assertSame($original, $needsinfo->pluginuid, 'request_info() must not rewrite the reference');

        $answered = tickets::provide_info(
            $activity,
            (int) $filed->id,
            'Chemistry',
            FORMAT_PLAIN,
            (int) $group->guideid
        );
        $this->assertSame($original, $answered->pluginuid, 'provide_info() must not rewrite the reference');

        $commented = tickets::comment($activity, (int) $filed->id, 'Working on it', FORMAT_PLAIN, (int) $manager->id);
        $this->assertSame($original, $commented->pluginuid, 'comment() must not rewrite the reference');

        $closed = tickets::close(
            $activity,
            (int) $filed->id,
            tickets::STATUS_RESOLVED,
            'Specialist assigned',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->assertSame($original, $closed->pluginuid, 'close() must not rewrite the reference');

        $reread = tickets::get($activity, (int) $filed->id);
        $this->assertSame($original, $reread->pluginuid, 'the stored row itself must still carry the original reference');
        $sink->close();
    }

    /**
     * The unique index really is a database constraint, not merely a
     * declaration nobody enforces: a direct INSERT naming a reference
     * already in use is refused by the database itself, before this
     * plugin's own code ever gets a chance to object.
     *
     * NEGATIVE CONTROL, kept in its OWN method (PostgreSQL transaction
     * poisoning): the expected dml_exception aborts the open PHPUnit
     * transaction on PostgreSQL, so no further query in the same test
     * method could be trusted afterwards - this method asserts nothing
     * else.
     */
    public function test_the_unique_index_refuses_a_duplicate_reference(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group] = $this->setup_world();

        $filed = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'need a specialist',
            FORMAT_PLAIN,
            (int) $group->guideid
        );
        $sink->close();

        $now = time();
        $this->expectException(\dml_exception::class);
        $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'pluginuid' => $filed->pluginuid,
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_COMPCHANGE,
            'status' => tickets::STATUS_OPEN,
            'requestedby' => (int) $group->guideid,
            'request' => 'a second, colliding row',
            'requestformat' => FORMAT_PLAIN,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * POSITIVE half of the same fact, kept separate from the negative
     * control above for the same PostgreSQL transaction-poisoning
     * reason: a second, DIFFERENT reference on an otherwise identical
     * row is accepted without complaint.
     */
    public function test_the_unique_index_accepts_a_second_distinct_reference(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group] = $this->setup_world();

        $filed = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'need a specialist',
            FORMAT_PLAIN,
            (int) $group->guideid
        );
        $sink->close();

        $now = time();
        $newid = $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'pluginuid' => $filed->pluginuid . '-DIFFERENT',
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_COMPCHANGE,
            'status' => tickets::STATUS_WITHDRAWN,
            'requestedby' => (int) $group->guideid,
            'request' => 'a second, non-colliding row',
            'requestformat' => FORMAT_PLAIN,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->assertGreaterThan(0, $newid, 'a distinct reference must insert cleanly');
    }

    /**
     * The thread header (classes/output/ticket_page.php +
     * templates/ticket_page.mustache) exports the reference.
     */
    public function test_the_thread_header_exports_the_reference(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, , $member] = $this->setup_world();
        global $PAGE;

        $filed = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Reason',
            FORMAT_PLAIN,
            (int) $member->id
        );
        $sink->close();

        $output = $PAGE->get_renderer('core');
        $page = new \mod_selfselectadvanced\output\ticket_page(
            $activity,
            tickets::get($activity, (int) $filed->id),
            $group,
            (int) $member->id,
            true,
            false
        );
        $exported = $page->export_for_template($output);
        $this->assertSame($filed->pluginuid, $exported->pluginuid, 'the thread header must export the ticket\'s own reference');
    }

    /**
     * The group page's live-request rows (classes/output/group_page.php +
     * templates/group_page.mustache) export the ticket's OWN reference,
     * under a name distinct from the group's own pluginuid the same
     * template already shows elsewhere.
     */
    public function test_the_group_page_live_rows_export_the_ticket_reference(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, , $member] = $this->setup_world();

        $filed = tickets::file_help($activity, $group, 'Help please', FORMAT_PLAIN, (int) $member->id);
        $sink->close();

        $rows = tickets::group_live($activity, (int) $group->id, (int) $member->id, false);
        $this->assertNotEmpty($rows, 'the requester must see their own live request');
        $row = reset($rows);
        $this->assertSame(
            $filed->pluginuid,
            $row->pluginuid,
            'group_live() must return the ticket row with its own pluginuid intact'
        );
    }
}
