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

use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\local\workflow_refusal;

/**
 * REPLY TO REOPEN (1.20.60; the maintainer's ruling on D-108,
 * 2026-08-27: "let it be `reply to reopen ticket`. To open a closed
 * ticket, the individual should be asked to explain").
 *
 * The rule set this file exists to hold still:
 *   - only the REQUESTER may reopen, and only their OWN ticket;
 *   - only from RESOLVED - never from open, claimed, needs-info,
 *     declined or withdrawn;
 *   - the explanation is REQUIRED, and an editor's empty paragraph is
 *     empty (html_to_text, not strlen);
 *   - it goes back to WHOEVER RESOLVED IT, still claimed by them; only
 *     an unclaimed ticket falls back to the queue;
 *   - resolvedby/timeresolved/resolution are cleared, because they
 *     describe a closure that no longer holds;
 *   - the verdict stays UNANSWERED - reopening is a refusal to answer
 *     "did this help?", not an answer to it - so the question is asked
 *     again when the ticket is resolved again;
 *   - somebody who already ANSWERED that question cannot then reopen.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets::reopen
 * @covers     \mod_selfselectadvanced\local\tickets::count_reopened
 */
final class ticket_reopen_test extends \advanced_testcase {
    /**
     * A course, an activity, a requester with a firm group, and a staff
     * member holding queue authority.
     *
     * @return array [activity, cm, leader, staff, group]
     */
    private function scene(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);

        $leader = $generator->create_user(['firstname' => 'Rina', 'lastname' => 'Requester']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $staff = $generator->create_user(['firstname' => 'Cora', 'lastname' => 'Coordinator']);
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team Blue',
            'state' => state::FIRM,
        ]);

        return [$activity, $cm, $leader, $staff, $group];
    }

    /**
     * A RESOLVED ticket, filed by the requester and closed by staff.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group
     * @param \stdClass $leader the requester
     * @param \stdClass $staff the resolver
     * @return \stdClass the resolved ticket
     */
    private function resolved_ticket(activity $activity, \stdClass $group, \stdClass $leader, \stdClass $staff): \stdClass {
        $ticket = tickets::file_help($activity, $group, 'Please help.', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $ticket->id, (int) $staff->id);
        tickets::close($activity, (int) $ticket->id, tickets::STATUS_RESOLVED, 'Sorted.', FORMAT_PLAIN, (int) $staff->id);

        return tickets::get($activity, (int) $ticket->id);
    }

    /**
     * The whole ruling in one pass: a resolved ticket goes back to the
     * coordinator who resolved it, still claimed by them, with the
     * resolution columns cleared and the explanation on the trail.
     */
    public function test_reopen_returns_the_ticket_to_its_resolver(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        $this->assertSame((int) $staff->id, (int) $resolved->claimedby, 'the fixture must leave the resolver claiming it');

        $after = tickets::reopen(
            $activity,
            (int) $resolved->id,
            'The room is still double-booked.',
            FORMAT_MOODLE,
            (int) $leader->id
        );

        $this->assertSame(tickets::STATUS_CLAIMED, $after->status, 'a claimed resolution goes back to its claimant');
        $this->assertSame((int) $staff->id, (int) $after->claimedby, 'the claim must not be dropped');
        $this->assertNull($after->resolvedby, 'the resolution no longer holds, so nobody is its resolver');
        $this->assertNull($after->timeresolved);
        $this->assertNull($after->resolution);
        $this->assertSame(
            tickets::VERDICT_UNANSWERED,
            (int) $after->verdict,
            'reopening is a refusal to answer "did this help?", not an answer to it'
        );

        // The row on disk, not merely the object handed back.
        $row = $DB->get_record('selfselectadvanced_ticket', ['id' => (int) $resolved->id], '*', MUST_EXIST);
        $this->assertSame(tickets::STATUS_CLAIMED, $row->status);
        $this->assertNull($row->resolvedby);

        // The explanation is the last trail row, and it is the
        // requester's own - never staff-internal, so their own
        // anonymised thread shows it.
        $this->assertNotContains(tickets::ACTION_REOPENED, tickets::STAFF_INTERNAL_ACTIONS);
        $trail = array_values(tickets::trail($activity, (int) $resolved->id, true));
        $last = end($trail);
        $this->assertSame(tickets::ACTION_REOPENED, $last->action);
        $this->assertStringContainsString('double-booked', $last->note);

        // The transient ->ticketlogid property is what ticket.php hands
        // to save_post_attachments() (audit L-3/L-10/L-15): without it an
        // attachment posted with the explanation lands on somebody
        // else's trail row.
        $this->assertSame((int) $last->id, (int) $after->ticketlogid);
    }

    /**
     * A ticket nobody holds - resolved by the unfreeze autoresolver, or
     * stranded by a restore - has no claimant to return to, so it goes
     * back to the queue as OPEN rather than to user 0.
     */
    public function test_an_unclaimed_resolution_goes_back_to_the_queue(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        // The shape autoresolve_unfreeze() leaves behind: resolved, with
        // nobody holding it.
        $DB->set_field('selfselectadvanced_ticket', 'claimedby', null, ['id' => (int) $resolved->id]);
        $DB->set_field('selfselectadvanced_ticket', 'timeclaimed', null, ['id' => (int) $resolved->id]);

        $after = tickets::reopen($activity, (int) $resolved->id, 'Still frozen.', FORMAT_MOODLE, (int) $leader->id);

        $this->assertSame(tickets::STATUS_OPEN, $after->status);
        $this->assertNull($after->claimedby);
        $this->assertNull($after->timeclaimed);
    }

    /**
     * "To open a closed ticket, the individual should be asked to
     * explain" - so an empty explanation is refused, and an editor's
     * empty paragraph is empty. `<p><br></p>` has a positive strlen and
     * says nothing; the refusal is measured on html_to_text().
     */
    public function test_an_empty_explanation_is_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);

        foreach (['', '   ', '<p><br></p>', "<p>\n</p>"] as $empty) {
            try {
                tickets::reopen($activity, (int) $resolved->id, $empty, FORMAT_HTML, (int) $leader->id);
                $this->fail('an explanation of ' . var_export($empty, true) . ' must be refused');
            } catch (workflow_refusal $e) {
                $this->assertSame(
                    get_string('refusalticketreopenreason', 'mod_selfselectadvanced'),
                    $e->getMessage()
                );
            }
        }

        // And nothing moved: the ticket is still resolved.
        $this->assertSame(tickets::STATUS_RESOLVED, tickets::get($activity, (int) $resolved->id)->status);
    }

    /**
     * Only the requester. Not the resolver, not another coordinator,
     * not a manager - authority here is ownership, exactly like
     * withdraw() and give_feedback().
     */
    public function test_only_the_requester_may_reopen(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $cm, $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');
        unset($cm);

        foreach ([$staff, $manager] as $stranger) {
            try {
                tickets::reopen($activity, (int) $resolved->id, 'Let me reopen this.', FORMAT_MOODLE, (int) $stranger->id);
                $this->fail('only the requester may reopen their ticket');
            } catch (workflow_refusal $e) {
                $this->assertSame(get_string('refusalticketnotyours', 'mod_selfselectadvanced'), $e->getMessage());
            }
        }
    }

    /**
     * Only from RESOLVED. Every other status refuses - including
     * DECLINED, which is a closure too but not one the requester may
     * undo (the escalation ladder, not this button, is what a declined
     * request has).
     */
    public function test_only_a_resolved_ticket_may_be_reopened(): void {
        $this->resetAfterTest();
        // This test provokes FIVE refusals from inside a service
        // transaction and then keeps writing. On PostgreSQL a delegated
        // transaction rolled back at a lower level poisons the one
        // wrapping the whole test, and the next commit dies with "Tried
        // to commit transaction after lower level rollback" - which is
        // not this test's subject. Resetting by truncation gives each
        // service call a real top-level transaction of its own.
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        // ONE ticket walked through every other status, rather than four
        // tickets in four states: a requester may hold only one live
        // help ticket at a time (file_help()'s own duplicate guard), so
        // four would refuse at the second filing and this test would be
        // proving that instead.
        $ticket = tickets::file_help($activity, $group, 'One', FORMAT_PLAIN, (int) $leader->id);
        $refuse = function () use ($activity, $ticket, $leader): void {
            try {
                tickets::reopen($activity, (int) $ticket->id, 'Please look again.', FORMAT_MOODLE, (int) $leader->id);
                $this->fail('only a resolved ticket may be reopened');
            } catch (workflow_refusal $e) {
                $this->assertSame(
                    get_string('refusalticketreopennotresolved', 'mod_selfselectadvanced'),
                    $e->getMessage()
                );
            }
        };

        // Open.
        $this->assertSame(tickets::STATUS_OPEN, tickets::get($activity, (int) $ticket->id)->status);
        $refuse();

        // Claimed.
        tickets::claim($activity, (int) $ticket->id, (int) $staff->id);
        $refuse();

        // Needs info.
        tickets::request_info($activity, (int) $ticket->id, 'Which room?', FORMAT_PLAIN, (int) $staff->id);
        $this->assertSame(tickets::STATUS_NEEDSINFO, tickets::get($activity, (int) $ticket->id)->status);
        $refuse();

        // Declined - a closure, but not one the requester may undo.
        tickets::provide_info($activity, (int) $ticket->id, 'Room 3.', FORMAT_PLAIN, (int) $leader->id);
        tickets::close($activity, (int) $ticket->id, tickets::STATUS_DECLINED, 'No.', FORMAT_PLAIN, (int) $staff->id);
        $this->assertSame(tickets::STATUS_DECLINED, tickets::get($activity, (int) $ticket->id)->status);
        $refuse();

        // Withdrawn, on a fresh ticket - the requester's own closure.
        $withdrawn = tickets::file_help($activity, $group, 'Two', FORMAT_PLAIN, (int) $leader->id);
        tickets::withdraw($activity, (int) $withdrawn->id, (int) $leader->id);
        try {
            tickets::reopen($activity, (int) $withdrawn->id, 'Actually, please look.', FORMAT_MOODLE, (int) $leader->id);
            $this->fail('a withdrawn request is not reopened either');
        } catch (workflow_refusal $e) {
            $this->assertSame(
                get_string('refusalticketreopennotresolved', 'mod_selfselectadvanced'),
                $e->getMessage()
            );
        }
    }

    /**
     * The one-shot door give_feedback() keeps, kept here too: somebody
     * who has already said "yes, this helped" is not offered the reopen
     * button, and a stale form must not slip past just because their
     * browser still shows one.
     */
    public function test_a_requester_who_already_answered_cannot_reopen(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        tickets::give_feedback($activity, (int) $resolved->id, tickets::VERDICT_HELPED, 'Thanks!', (int) $leader->id);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketfeedbackalreadygiven', 'mod_selfselectadvanced'));
        tickets::reopen($activity, (int) $resolved->id, 'Actually, no.', FORMAT_MOODLE, (int) $leader->id);
    }

    /**
     * Reopened, resolved again, reopened again - the loop the ruling
     * implies. The verdict question comes back each time, and the
     * counter counts the TICKET once however many times it happened.
     */
    public function test_a_ticket_may_be_reopened_more_than_once_and_counts_once(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);

        tickets::reopen($activity, (int) $resolved->id, 'First complaint.', FORMAT_MOODLE, (int) $leader->id);
        tickets::close($activity, (int) $resolved->id, tickets::STATUS_RESOLVED, 'Try again.', FORMAT_PLAIN, (int) $staff->id);
        tickets::reopen($activity, (int) $resolved->id, 'Second complaint.', FORMAT_MOODLE, (int) $leader->id);

        $this->assertSame(
            1,
            tickets::count_reopened($activity),
            'the card counts requests that came back, not trail rows'
        );

        // A second ticket, reopened once, makes it two. The first is
        // resolved again first: a requester may hold only one LIVE help
        // ticket, and the reopen above made this one live.
        tickets::close(
            $activity,
            (int) $resolved->id,
            tickets::STATUS_RESOLVED,
            'And again.',
            FORMAT_PLAIN,
            (int) $staff->id
        );
        $other = $this->resolved_ticket($activity, $group, $leader, $staff);
        tickets::reopen($activity, (int) $other->id, 'This one too.', FORMAT_MOODLE, (int) $leader->id);
        $this->assertSame(2, tickets::count_reopened($activity));
    }

    /**
     * Absence, and the N>0 rule's sibling: an activity where nothing was
     * reopened reads exactly 0, not a stray count from a resolved or
     * declined ticket.
     */
    public function test_count_reopened_is_zero_when_nothing_was_reopened(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $this->resolved_ticket($activity, $group, $leader, $staff);
        $declined = tickets::file_help($activity, $group, 'Q', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $declined->id, (int) $staff->id);
        tickets::close($activity, (int) $declined->id, tickets::STATUS_DECLINED, 'No.', FORMAT_PLAIN, (int) $staff->id);

        $this->assertSame(0, tickets::count_reopened($activity));
    }

    /**
     * The event fires once, after the lock is released (CONC-001
     * requirement 2), naming the ticket and where it went.
     */
    public function test_the_reopen_event_fires_with_the_destination(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);

        $sink = $this->redirectEvents();
        tickets::reopen($activity, (int) $resolved->id, 'Not fixed.', FORMAT_MOODLE, (int) $leader->id);
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_reopened
        ));
        $sink->close();

        $this->assertCount(1, $events, 'exactly one reopen event');
        $this->assertSame((int) $resolved->id, (int) $events[0]->objectid);
        $this->assertSame((int) $staff->id, (int) $events[0]->relateduserid, 'the person whose answer was rejected');
        $this->assertSame(tickets::STATUS_CLAIMED, $events[0]->other['returnedto']);
    }

    /**
     * The claimant is TOLD. A ticket that silently reappeared in
     * somebody's claimed list would be work nobody knew had come back.
     */
    public function test_the_resolver_is_notified(): void {
        $this->resetAfterTest();
        // Redirected BEFORE the fixture: filing and resolving a ticket
        // both send messages, and core refuses to send one in a unit
        // test that has not redirected. The sink is cleared instead, so
        // what is asserted below is the reopen's own message and not
        // the fixture's.
        $sink = $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        $sink->clear();

        tickets::reopen(
            $activity,
            (int) $resolved->id,
            'The room is still double-booked.',
            FORMAT_MOODLE,
            (int) $leader->id
        );
        $messages = $sink->get_messages();
        $sink->close();

        $tostaff = array_values(array_filter($messages, static fn($m) => (int) $m->useridto === (int) $staff->id));
        $this->assertNotEmpty($tostaff, 'the coordinator whose resolution was rejected must be told');
        $body = implode("\n", array_map(static fn($m) => (string) $m->fullmessage, $tostaff));
        $this->assertStringContainsString('double-booked', $body, 'the explanation travels with the notice');
    }
}
