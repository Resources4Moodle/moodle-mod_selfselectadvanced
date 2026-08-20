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

use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\output\ticket_page;

/**
 * 1.20.58: how long a ticket has been waiting on staff (deliverable B),
 * and whether that is too long (deliverable C).
 *
 * tickets::is_awaiting_claimant_reply(), staff_wait_since() and
 * staff_wait_since_map() are the ONE place the 1.20.54 whose-move rule
 * is reused for this release (spec: "the whose-move derivation is
 * REUSED from the existing helper, not copied") - ticket_page.php's own
 * whose_move_claimed_line() is refactored onto
 * is_awaiting_claimant_reply() rather than left with its own inline
 * copy of the same condition, and the bulk queue/myrequests wiring goes
 * through staff_wait_since_map() rather than a trail() call per row.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \mod_selfselectadvanced\output\ticket_page
 */
final class ticket_response_wait_test extends \advanced_testcase {
    /**
     * An activity with a firm group: leader, confirmed member, guide,
     * manager, coordinator - the same shape ticket_search_test.php and
     * ticket_thread_test.php already build their fixtures on.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'TGTW1']);
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
            'name' => 'Waiting',
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
     * Set an activity's target, in hours, straight on the row - the
     * same shape mod_form.php writes, without needing a form submission.
     *
     * activity's own $record property is readonly, populated once at
     * construction (classes/activity.php) - exactly right in production,
     * where a page loads the activity fresh on every request, but it
     * means a raw write here would never be seen by the CALLER's copy.
     * Returns a freshly reloaded activity so the caller re-assigns it,
     * the same way a fresh request would naturally pick up the change.
     *
     * @param activity $activity the activity
     * @param int $hours 0 for no target
     * @return activity a freshly reloaded activity carrying the new value
     */
    private function set_target(activity $activity, int $hours): activity {
        global $DB;

        $DB->set_field('selfselectadvanced', 'tickettargethours', $hours, ['id' => $activity->id()]);

        return activity::from_instance($activity->id());
    }

    /**
     * (1) is_awaiting_claimant_reply() states the CLAIMED-with-reply
     * half of the 1.20.54 rule directly: true only for a claimed ticket
     * whose last trail row is the requester's own inforeply, false for
     * every other combination, including a claimed ticket with no rows
     * at all (a shape that should never occur in production - every
     * ticket is logged 'filed' the instant it exists - but the method
     * must not assume a non-null row and must answer false rather than
     * fatal on one).
     */
    public function test_is_awaiting_claimant_reply_matches_the_1_20_54_rule(): void {
        $inforeply = (object) ['action' => tickets::ACTION_INFOREPLY];
        $claimed = (object) ['action' => tickets::ACTION_CLAIMED];

        $this->assertTrue(tickets::is_awaiting_claimant_reply(tickets::STATUS_CLAIMED, $inforeply));
        $this->assertFalse(
            tickets::is_awaiting_claimant_reply(tickets::STATUS_CLAIMED, $claimed),
            'claimed with a non-reply last row is "being handled", not awaiting the claimant'
        );
        $this->assertFalse(
            tickets::is_awaiting_claimant_reply(tickets::STATUS_CLAIMED, null),
            'a claimed ticket with no trail row at all must not fatal, and must answer false'
        );
        // The SAME inforeply row on every OTHER status must never read
        // true - the ball is only ever "back with the claimant" while
        // the ticket is actually claimed.
        foreach ([tickets::STATUS_OPEN, tickets::STATUS_NEEDSINFO, tickets::STATUS_RESOLVED] as $status) {
            $this->assertFalse(
                tickets::is_awaiting_claimant_reply($status, $inforeply),
                $status . ' with an inforeply last row must still answer false - it is not CLAIMED'
            );
        }
    }

    /**
     * (2) staff_wait_since(): OPEN uses timecreated - the moment it was
     * filed, per the reused rule's first bullet.
     */
    public function test_staff_wait_since_open_uses_timecreated(): void {
        $ticket = (object) ['status' => tickets::STATUS_OPEN, 'timecreated' => 1000, 'timeclaimed' => 5000];
        $this->assertSame(1000, tickets::staff_wait_since($ticket, null));
    }

    /**
     * (3) staff_wait_since(): CLAIMED with the requester's own inforeply
     * as the last row uses THAT ROW'S OWN timecreated, deliberately
     * different from timeclaimed in this fixture so the assertion could
     * not pass by the two timestamps coincidentally matching.
     */
    public function test_staff_wait_since_claimed_awaiting_reply_uses_the_replys_own_time(): void {
        $ticket = (object) ['status' => tickets::STATUS_CLAIMED, 'timecreated' => 1000, 'timeclaimed' => 2000];
        $lastrow = (object) ['action' => tickets::ACTION_INFOREPLY, 'timecreated' => 9000];
        $this->assertSame(
            9000,
            tickets::staff_wait_since($ticket, $lastrow),
            'must read the REPLY row\'s own time, not timeclaimed'
        );
    }

    /**
     * (4) staff_wait_since(): CLAIMED otherwise (last row is not an
     * inforeply, or there is no last row) uses timeclaimed.
     */
    public function test_staff_wait_since_claimed_otherwise_uses_timeclaimed(): void {
        $ticket = (object) ['status' => tickets::STATUS_CLAIMED, 'timecreated' => 1000, 'timeclaimed' => 4000];
        $claimedrow = (object) ['action' => tickets::ACTION_CLAIMED, 'timecreated' => 1500];
        $this->assertSame(4000, tickets::staff_wait_since($ticket, $claimedrow));
        $this->assertSame(4000, tickets::staff_wait_since($ticket, null), 'no trail row at all must fall back to timeclaimed too');
    }

    /**
     * (5) staff_wait_since(): NEEDSINFO and every closed status answer
     * null - no staff clock runs at all. THE deliverable's own named
     * risk: "needsinfo must NOT accrue staff waiting time... marking it
     * overdue would be a lie told to a coordinator."
     *
     * @dataProvider no_clock_status_provider
     * @param string $status a status with no staff clock
     */
    public function test_staff_wait_since_no_clock_statuses_return_null(string $status): void {
        $ticket = (object) ['status' => $status, 'timecreated' => 1000, 'timeclaimed' => 2000];
        $this->assertNull(
            tickets::staff_wait_since($ticket, null),
            $status . ' must never report a staff clock'
        );
        // Even with an inforeply-shaped last row present - proving this
        // is a STATUS gate, not merely "no row present".
        $inforeply = (object) ['action' => tickets::ACTION_INFOREPLY, 'timecreated' => 1200];
        $this->assertNull(
            tickets::staff_wait_since($ticket, $inforeply),
            $status . ' with an inforeply-shaped row present must still report no clock'
        );
    }

    /**
     * The statuses with no staff clock at all.
     *
     * @return array<string, array{0: string}>
     */
    public static function no_clock_status_provider(): array {
        return [
            'needsinfo' => [tickets::STATUS_NEEDSINFO],
            'resolved' => [tickets::STATUS_RESOLVED],
            'declined' => [tickets::STATUS_DECLINED],
            'withdrawn' => [tickets::STATUS_WITHDRAWN],
        ];
    }

    /**
     * (6) is_overdue(): a target of 0 - "no target set", the state of
     * EVERY existing activity until this release - answers false
     * unconditionally (spec: "A target of 0 must change nothing,
     * anywhere"), even given a wait that started decades ago. This is
     * the test the spec asks for BY NAME, protecting every site that
     * never configures the feature.
     */
    public function test_is_overdue_target_zero_always_false(): void {
        $ancient = time() - (YEARSECS * 5);
        $this->assertFalse(tickets::is_overdue($ancient, 0), 'target 0 must never mark anything overdue, however long the wait');
        $this->assertFalse(tickets::is_overdue(time(), 0), 'target 0 with a fresh wait must also stay false');
        $this->assertFalse(tickets::is_overdue(null, 0), 'no clock and no target together must stay false');
    }

    /**
     * (7) is_overdue(): a null wait (no staff clock running) is never
     * overdue, whatever the target is set to.
     */
    public function test_is_overdue_null_wait_always_false(): void {
        $this->assertFalse(tickets::is_overdue(null, 1));
        $this->assertFalse(tickets::is_overdue(null, 100));
    }

    /**
     * (8) is_overdue(): the boundary is strictly GREATER THAN the
     * target - a wait exactly equal to the target hours is not yet
     * overdue, and one second past it is.
     *
     * MUTATION: changing the strict `>` in tickets::is_overdue() to
     * `>=` would flip the exact-boundary assertion below from false to
     * true.
     */
    public function test_is_overdue_boundary_is_strictly_after_the_target(): void {
        $target = 2; // Hours.
        $exactly = time() - ($target * HOURSECS);
        $this->assertFalse(tickets::is_overdue($exactly, $target), 'a wait exactly AT the target must not yet be overdue');

        $onesecondpast = time() - ($target * HOURSECS) - 1;
        $this->assertTrue(tickets::is_overdue($onesecondpast, $target), 'one second past the target must be overdue');

        $wellwithin = time() - HOURSECS;
        $this->assertFalse(tickets::is_overdue($wellwithin, $target), 'well within the target must not be overdue');
    }

    /**
     * (9) is_overdue(): a negative target (never reachable through the
     * form's own validation, but a hand-edited row could still hold
     * one) is treated the same as 0 - never overdue - rather than
     * inverting into "always overdue" or throwing.
     */
    public function test_is_overdue_negative_target_is_treated_like_zero(): void {
        $this->assertFalse(tickets::is_overdue(time() - (YEARSECS * 5), -1));
    }

    /**
     * (10) staff_wait_since_map() agrees with staff_wait_since() called
     * once per row, across a fixture spanning every status the whose-
     * move rule branches on: open, claimed fresh, claimed awaiting the
     * claimant's reply, needsinfo, and resolved. Proves the BULK path is
     * not a second, differently-derived answer.
     */
    public function test_staff_wait_since_map_agrees_with_the_single_ticket_derivation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide, $manager] = $this->setup_world();

        $open = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need a specialist', FORMAT_PLAIN, (int) $guide->id);

        $claimedfresh = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $claimedfresh->id, (int) $manager->id);

        $awaitingclaimant = tickets::file_help($activity, $group, 'General question', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $awaitingclaimant->id, (int) $manager->id);
        tickets::request_info($activity, (int) $awaitingclaimant->id, 'Which subject?', FORMAT_PLAIN, (int) $manager->id);
        tickets::provide_info($activity, (int) $awaitingclaimant->id, 'Statistics.', FORMAT_PLAIN, (int) $guide->id);

        $needsinfo = tickets::file($activity, $group, tickets::TYPE_DATES, 'Need more time', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $needsinfo->id, (int) $manager->id);
        tickets::request_info($activity, (int) $needsinfo->id, 'Why?', FORMAT_PLAIN, (int) $manager->id);

        $resolved = tickets::file($activity, $group, tickets::TYPE_PENALTY, 'Waive it', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $resolved->id, (int) $manager->id);
        tickets::close($activity, (int) $resolved->id, tickets::STATUS_RESOLVED, 'Waived', FORMAT_PLAIN, (int) $manager->id);

        $ids = [$open->id, $claimedfresh->id, $awaitingclaimant->id, $needsinfo->id, $resolved->id];
        $rows = $DB->get_records_list('selfselectadvanced_ticket', 'id', $ids);

        $expected = [];
        foreach ($rows as $row) {
            $trail = tickets::trail($activity, (int) $row->id, true);
            $lastrow = $trail ? end($trail) : null;
            $expected[(int) $row->id] = tickets::staff_wait_since($row, $lastrow);
        }

        $map = tickets::staff_wait_since_map($activity, $rows);

        foreach ($ids as $id) {
            $this->assertArrayHasKey((int) $id, $map, 'ticket ' . $id . ' missing from the bulk map');
            $this->assertSame(
                $expected[(int) $id],
                $map[(int) $id],
                'ticket ' . $id . ' (status ' . $rows[$id]->status . '): bulk map disagrees with the single-ticket derivation'
            );
        }
        // Sanity: the fixture genuinely spans a null and a non-null
        // answer, or the loop above could pass by every value being null.
        $this->assertNotNull($expected[(int) $open->id]);
        $this->assertNull($expected[(int) $needsinfo->id]);
        $this->assertNull($expected[(int) $resolved->id]);
    }

    /**
     * (11) staff_wait_since_map() reads the AWAITING-CLAIMANT case from
     * the trail row's own time, not timeclaimed - the identical fact
     * test (3) proves for the single-ticket path, proven again here for
     * the bulk SQL path specifically, since that path restates the
     * condition in SQL rather than calling staff_wait_since() (see that
     * method's own docblock for why).
     *
     * MUTATION: dropping the "l.action = :inforeply" distinction in
     * staff_wait_since_map() (always falling back to t.timeclaimed)
     * would make the assertion below fail, since timeclaimed and the
     * reply's own timecreated are deliberately forced apart.
     */
    public function test_staff_wait_since_map_awaiting_claimant_uses_the_replys_own_time(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide, $manager] = $this->setup_world();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need a specialist', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $manager->id);
        tickets::provide_info($activity, (int) $ticket->id, 'Statistics.', FORMAT_PLAIN, (int) $guide->id);

        // Force timeclaimed and the reply's own trail row far apart, so
        // a fallback to timeclaimed could not pass by coincidence.
        $DB->set_field('selfselectadvanced_ticket', 'timeclaimed', 1000, ['id' => $ticket->id]);
        $replyrow = $DB->get_record(
            'selfselectadvanced_ticketlog',
            ['ticketid' => $ticket->id, 'action' => tickets::ACTION_INFOREPLY],
            '*',
            MUST_EXIST
        );
        $DB->set_field('selfselectadvanced_ticketlog', 'timecreated', 9999999, ['id' => $replyrow->id]);

        $row = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticket->id], '*', MUST_EXIST);
        $map = tickets::staff_wait_since_map($activity, [$row]);

        $this->assertSame(9999999, $map[(int) $ticket->id], 'the bulk map must read the reply row\'s own time, not timeclaimed');
    }

    /**
     * (12) NO QUERY PER ROW (spec deliverable B: "do not add a query
     * per row"). Reads for a batch of eight claimed tickets must not
     * cost meaningfully more than reads for a batch of two - if
     * staff_wait_since_map() called staff_wait_since()/trail() once per
     * ticket, the extra six tickets would each cost at least one extra
     * read and the delta would scale with the batch size, mirroring
     * coordinatorimport_run_test.php::test_run_is_bulk_not_per_line()'s
     * own reasoning and threshold shape.
     */
    public function test_staff_wait_since_map_does_not_query_per_row(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , , , $manager] = $this->setup_world();
        $generator = $this->getDataGenerator();
        $course = (int) $activity->cm()->course;

        $claimhelptickets = function (int $count) use ($activity, $manager, $generator, $course): array {
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $raiser = $generator->create_user();
                $generator->enrol_user($raiser->id, $course, 'student');
                $ticket = tickets::file_help($activity, null, 'Question ' . $i, FORMAT_PLAIN, (int) $raiser->id);
                tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
                $rows[] = tickets::get($activity, (int) $ticket->id);
            }
            return $rows;
        };

        $two = $claimhelptickets(2);
        $before = $DB->perf_get_reads();
        tickets::staff_wait_since_map($activity, $two);
        $reads2 = $DB->perf_get_reads() - $before;

        $eight = $claimhelptickets(8);
        $before = $DB->perf_get_reads();
        tickets::staff_wait_since_map($activity, $eight);
        $reads8 = $DB->perf_get_reads() - $before;

        // The six extra tickets must not cost six (or more) extra reads.
        $this->assertLessThanOrEqual(
            2,
            $reads8 - $reads2,
            "eight claimed tickets cost $reads8 reads against $reads2 for two: the bulk map is scaling per row"
        );
    }

    /**
     * (13) THE THREAD (ticket_page::export_for_template()): an OPEN
     * ticket's waiting age counts from when it was filed, exposed as
     * showwaiting/waitinglabel using core's format_time() idiom, per the
     * spec ("do not write a formatter").
     */
    public function test_thread_export_shows_waiting_age_for_an_open_ticket(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need a specialist', FORMAT_PLAIN, (int) $guide->id);
        $filedat = time() - (3 * HOURSECS);
        $DB->set_field('selfselectadvanced_ticket', 'timecreated', $filedat, ['id' => $ticket->id]);

        $fresh = tickets::get($activity, (int) $ticket->id);
        $output = $PAGE->get_renderer('core');
        $page = new ticket_page($activity, $fresh, $group, (int) $manager->id, false, true);
        $exported = $page->export_for_template($output);

        $this->assertTrue($exported->showwaiting);
        $this->assertSame(
            get_string('ticketwaitingsince', 'mod_selfselectadvanced', format_time(time() - $filedat)),
            $exported->waitinglabel,
            'must use core\'s format_time() idiom, not a hand-rolled formatter'
        );
        $this->assertFalse($exported->overdue, 'no target is set in this fixture');
    }

    /**
     * (14) THE THREAD: needsinfo shows NO waiting age at all - the
     * deliverable's own named risk made concrete. A ticket that has sat
     * in needsinfo for a very long time, on an activity with a very
     * short target, must still show showwaiting=false and overdue=false
     * - anything else would accrue staff time onto the requester's own
     * unanswered question, which the spec calls "a lie told to a
     * coordinator".
     */
    public function test_thread_export_needsinfo_never_shows_waiting_or_overdue(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();
        $activity = $this->set_target($activity, 1);

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need a specialist', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $manager->id);
        // Push the question far into the past - if needsinfo accrued
        // staff time the way claimed does, this would be wildly overdue.
        $DB->set_field('selfselectadvanced_ticket', 'timeclaimed', time() - (YEARSECS * 2), ['id' => $ticket->id]);
        $DB->set_field(
            'selfselectadvanced_ticketlog',
            'timecreated',
            time() - (YEARSECS * 2),
            ['ticketid' => $ticket->id, 'action' => tickets::ACTION_NEEDSINFO]
        );

        $fresh = tickets::get($activity, (int) $ticket->id);
        $this->assertSame(tickets::STATUS_NEEDSINFO, $fresh->status, 'fixture sanity');
        $output = $PAGE->get_renderer('core');
        // The claimant view renders the resolve/decline forms
        // (showclaimantforms), which need a genuine current user for
        // file_prepare_draft_area()'s own draft-area user context - see
        // ticket_thread_test.php's identical comment on the same fact.
        $this->setUser($manager);
        $PAGE->set_url(new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => (int) $fresh->id]));
        $page = new ticket_page($activity, $fresh, $group, (int) $manager->id, false, true);
        $exported = $page->export_for_template($output);

        $this->assertFalse($exported->showwaiting, 'needsinfo must show no waiting age - the ball is with the requester');
        $this->assertSame('', $exported->waitinglabel);
        $this->assertFalse($exported->overdue, 'needsinfo must never be marked overdue');
        $this->assertSame('', $exported->overduenotice);
    }

    /**
     * (15) THE THREAD: a target of 0 shows no overdue marking, however
     * long a claimed ticket has been waiting - proven with a wait
     * pushed years into the past, so the only thing keeping this false
     * is the target being 0, not a wait that merely happens to be short.
     */
    public function test_thread_export_target_zero_never_marks_overdue(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();
        $activity = $this->set_target($activity, 0);

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need a specialist', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $DB->set_field('selfselectadvanced_ticket', 'timeclaimed', time() - (YEARSECS * 3), ['id' => $ticket->id]);

        $fresh = tickets::get($activity, (int) $ticket->id);
        $output = $PAGE->get_renderer('core');
        // See test (14)'s identical comment: the claimant view needs a
        // genuine current user for its draft-area forms.
        $this->setUser($manager);
        $PAGE->set_url(new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => (int) $fresh->id]));
        $page = new ticket_page($activity, $fresh, $group, (int) $manager->id, false, true);
        $exported = $page->export_for_template($output);

        $this->assertTrue($exported->showwaiting, 'the age itself is shown regardless of the target');
        $this->assertFalse($exported->overdue, 'target 0 must never mark overdue, however long the wait');
        $this->assertSame('', $exported->overduenotice);
    }

    /**
     * (16) THE THREAD: with a real target set and breached, the ticket
     * is marked overdue for BOTH audiences (the badge), and the
     * requester alone gets the longer acknowledgement sentence -
     * deliverable C's "to the requester as an acknowledgement rather
     * than silence", distinguished from the claimant's own view of the
     * same page.
     */
    public function test_thread_export_overdue_marks_it_and_tells_the_requester(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();
        $activity = $this->set_target($activity, 4);

        $ticket = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need a specialist', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $DB->set_field('selfselectadvanced_ticket', 'timeclaimed', time() - (5 * HOURSECS), ['id' => $ticket->id]);

        $fresh = tickets::get($activity, (int) $ticket->id);
        $output = $PAGE->get_renderer('core');

        // The claimant's own view: overdue is shown, but the longer
        // acknowledgement sentence is not - that line is the
        // requester's.
        $this->setUser($manager);
        $PAGE->set_url(new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => (int) $fresh->id]));
        $staffpage = new ticket_page($activity, $fresh, $group, (int) $manager->id, false, true);
        $staffexported = $staffpage->export_for_template($output);
        $this->assertTrue($staffexported->overdue);
        $this->assertSame(get_string('ticketoverduebadge', 'mod_selfselectadvanced'), $staffexported->overduelabel);
        $this->assertSame('', $staffexported->overduenotice, 'the acknowledgement sentence is the requester\'s, not staff\'s');

        // The requester's own view of the SAME ticket: overdue, AND
        // told so in as many words.
        $reqpage = new ticket_page($activity, $fresh, $group, (int) $guide->id, true, false);
        $reqexported = $reqpage->export_for_template($output);
        $this->assertTrue($reqexported->overdue);
        $this->assertSame(
            get_string('ticketoverduenotice', 'mod_selfselectadvanced'),
            $reqexported->overduenotice,
            'the requester must be told, not left to infer it from a badge alone'
        );
    }
}
