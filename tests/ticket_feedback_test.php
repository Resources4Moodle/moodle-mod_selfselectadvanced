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
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\local\workflow_refusal;
use mod_selfselectadvanced\privacy\provider;

/**
 * 1.20.59: "did this help?" - once a ticket is RESOLVED, its requester
 * may say once whether it helped, with an optional note.
 *
 * D-108 WAS DECIDED THE OTHER WAY IN 1.20.60. This file used to open by
 * saying "D-108 IS DECIDED AS RECORD, NEVER REOPEN" - that a "this did
 * not help" answer must be recorded and surfaced and never acted on -
 * and its strongest pair of tests proved the ticket's whole row was
 * byte-identical after one. The maintainer ruled on 2026-08-27 that the
 * second button should instead be REPLY TO REOPEN, with an explanation
 * required, so the verdict that provoked that reasoning no longer
 * exists to be given: give_feedback() records VERDICT_HELPED only.
 *
 * What survives, and is still proven here:
 *   - the ask itself - once, requester-only, resolved-only, optional
 *     note, format stored beside it;
 *   - that the surviving verdict changes no state
 *     (test_feedback_never_changes_status_or_claim_fields_helped);
 *   - that the RETIRED verdict is refused outright
 *     (test_give_feedback_refuses_the_retired_verdict);
 *   - that rows written by 1.20.59 still read correctly everywhere -
 *     the queue row, the dashboard counter and the privacy export -
 *     which is what legacy_nothelped_row() below exists for.
 * The reopen path itself has its own file: ticket_reopen_test.php.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets::give_feedback
 * @covers     \mod_selfselectadvanced\local\tickets::count_feedback_nothelped
 * @covers     \mod_selfselectadvanced\output\ticket_page
 * @covers     \mod_selfselectadvanced\privacy\provider
 */
final class ticket_feedback_test extends \advanced_testcase {
    /**
     * A course, an activity, a leader with a firm group, and a staff
     * member holding queue authority - the same shape
     * ticketlifecycle_test.php's own scene() builds.
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
     * A RESOLVED ticket, filed by the leader and closed by staff.
     *
     * @param activity $activity
     * @param \stdClass $group
     * @param \stdClass $leader
     * @param \stdClass $staff
     * @return \stdClass the resolved ticket, freshly re-read
     */
    private function resolved_ticket(activity $activity, \stdClass $group, \stdClass $leader, \stdClass $staff): \stdClass {
        $ticket = tickets::file_help($activity, $group, 'Please help.', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $ticket->id, (int) $staff->id);
        tickets::close($activity, (int) $ticket->id, tickets::STATUS_RESOLVED, 'Sorted.', FORMAT_PLAIN, (int) $staff->id);

        return tickets::get($activity, (int) $ticket->id);
    }

    /**
     * A PRE-1.20.60 "this did not help" row, written straight to the
     * database because nothing in the plugin writes one any more.
     *
     * The D-108 ruling replaced that second button with REPLY TO
     * REOPEN, and give_feedback() now refuses VERDICT_NOTHELPED outright
     * (test_give_feedback_refuses_the_retired_verdict below is the guard
     * on that). The VALUE still has to be readable, though: every site
     * that ran 1.20.59 has rows carrying it, and the queue, the
     * dashboard card, the trail and the privacy export must all still
     * render them. This helper is how those rows are reached now - a
     * fixture for a shape the service will never produce again, which is
     * exactly what a legacy-data test needs.
     *
     * @param activity $activity the activity
     * @param int $ticketid the resolved ticket
     * @param string $note the requester's note, '' for none
     */
    private function legacy_nothelped_row(activity $activity, int $ticketid, string $note): void {
        global $DB;

        $now = time();
        $ticket = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $DB->update_record('selfselectadvanced_ticket', (object) [
            'id' => $ticketid,
            'verdict' => tickets::VERDICT_NOTHELPED,
            'verdictnote' => trim($note) === '' ? null : $note,
            'verdictnoteformat' => FORMAT_MOODLE,
            'timeverdict' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('selfselectadvanced_ticketlog', (object) [
            'ticketid' => $ticketid,
            'actorid' => (int) $ticket->requestedby,
            'action' => tickets::ACTION_FEEDBACK_NOTHELPED,
            'note' => trim($note) === '' ? null : $note,
            'noteformat' => FORMAT_MOODLE,
            'timecreated' => $now,
        ]);
    }

    // Deliverable A: the ask.

    /**
     * The requester may answer once, either way, with an optional note -
     * an empty note is stored as null rather than an empty string.
     */
    public function test_the_requester_may_answer_a_resolved_ticket_once(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $helped = $this->resolved_ticket($activity, $group, $leader, $staff);
        $updated = tickets::give_feedback(
            $activity,
            (int) $helped->id,
            tickets::VERDICT_HELPED,
            'Great, thanks!',
            (int) $leader->id
        );
        $this->assertSame(tickets::VERDICT_HELPED, (int) $updated->verdict);
        $this->assertSame('Great, thanks!', $updated->verdictnote);
        // 1.20.52's rule, applied to this column too: the format the note
        // was written in is STORED beside it, never guessed at render.
        $this->assertSame(
            (int) FORMAT_MOODLE,
            (int) $updated->verdictnoteformat,
            'the note format must be stored, not hardcoded wherever it is rendered'
        );
        $this->assertNotEmpty($updated->timeverdict, 'timeverdict must be set once answered');

        // A second, independent ticket, answered with a note of pure
        // whitespace - stored as null, not '', matching every other
        // optional note column in this class. This used to be the "no"
        // verdict's own case; since 1.20.60 (D-108) there is only one
        // verdict give_feedback() will accept, so the whitespace rule is
        // proven on that one.
        $blanknote = $this->resolved_ticket($activity, $group, $leader, $staff);
        $updated2 = tickets::give_feedback($activity, (int) $blanknote->id, tickets::VERDICT_HELPED, '   ', (int) $leader->id);
        $this->assertSame(tickets::VERDICT_HELPED, (int) $updated2->verdict);
        $this->assertNull($updated2->verdictnote, 'whitespace-only note must be stored as null, not a blank string');
    }

    /**
     * RED-FIRST EVIDENCE (captured on m5pg, this same tree, with
     * give_feedback()'s own "already answered" guard temporarily
     * commented out):
     *
     *   1) test_a_second_answer_is_refused:
     *   Failed asserting that exception of type "mod_selfselectadvanced\local\workflow_refusal" is thrown.
     *
     *   Tests: 1, Assertions: 1, Failures: 1.
     *
     * Reverted immediately after capturing the failure; full suite green
     * again with no other change. MUTATION: this is that same guard,
     * proven live.
     */
    public function test_a_second_answer_is_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $ticket = $this->resolved_ticket($activity, $group, $leader, $staff);
        tickets::give_feedback($activity, (int) $ticket->id, tickets::VERDICT_HELPED, '', (int) $leader->id);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketfeedbackalreadygiven', 'mod_selfselectadvanced'));
        tickets::give_feedback($activity, (int) $ticket->id, tickets::VERDICT_HELPED, 'Changed my mind', (int) $leader->id);
    }

    /**
     * Only the requester may answer - not the claimant, not the
     * resolver, not any other staff member, even though they hold queue
     * authority and even though they are the very person who resolved
     * this ticket.
     */
    public function test_only_the_requester_may_answer(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $ticket = $this->resolved_ticket($activity, $group, $leader, $staff);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketnotyours', 'mod_selfselectadvanced'));
        tickets::give_feedback($activity, (int) $ticket->id, tickets::VERDICT_HELPED, '', (int) $staff->id);
    }

    /**
     * Not yet offered while the ticket is still OPEN.
     *
     * PostgreSQL transaction poisoning (house rule): each "not yet
     * resolved" status gets its OWN test method rather than sharing one
     * with the others - three sequential caught refusals in a single
     * method reproduced "Database transaction error (Tried to commit
     * transaction after lower level rollback)" on m5pg when captured
     * live, exactly the failure mode this split exists to avoid.
     */
    public function test_feedback_is_refused_while_open(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, , $group] = $this->scene();

        $open = tickets::file_help($activity, $group, 'Q1', FORMAT_PLAIN, (int) $leader->id);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketfeedbacknotresolved', 'mod_selfselectadvanced'));
        tickets::give_feedback($activity, (int) $open->id, tickets::VERDICT_HELPED, '', (int) $leader->id);
    }

    /**
     * Not yet offered while the ticket is CLAIMED. Own method - see
     * test_feedback_is_refused_while_open()'s docblock.
     */
    public function test_feedback_is_refused_while_claimed(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $claimed = tickets::file_help($activity, $group, 'Q2', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $claimed->id, (int) $staff->id);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketfeedbacknotresolved', 'mod_selfselectadvanced'));
        tickets::give_feedback($activity, (int) $claimed->id, tickets::VERDICT_HELPED, '', (int) $leader->id);
    }

    /**
     * Not yet offered while the ticket is NEEDSINFO. Own method - see
     * test_feedback_is_refused_while_open()'s docblock.
     */
    public function test_feedback_is_refused_while_needsinfo(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $needsinfo = tickets::file_help($activity, $group, 'Q3', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $needsinfo->id, (int) $staff->id);
        tickets::request_info($activity, (int) $needsinfo->id, 'Which one?', FORMAT_PLAIN, (int) $staff->id);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketfeedbacknotresolved', 'mod_selfselectadvanced'));
        tickets::give_feedback($activity, (int) $needsinfo->id, tickets::VERDICT_HELPED, '', (int) $leader->id);
    }

    /**
     * Absence proof: a DECLINED ticket never asked "did this help?" and
     * must refuse the same way an unresolved one does. Own method - the
     * same PostgreSQL transaction-poisoning split as the OPEN/CLAIMED/
     * NEEDSINFO trio above; declined and withdrawn shared one method
     * originally and reproduced the identical dml_transaction_exception.
     */
    public function test_feedback_is_refused_on_a_declined_ticket(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $declined = tickets::file_help($activity, $group, 'Q1', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $declined->id, (int) $staff->id);
        tickets::close($activity, (int) $declined->id, tickets::STATUS_DECLINED, 'No.', FORMAT_PLAIN, (int) $staff->id);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketfeedbacknotresolved', 'mod_selfselectadvanced'));
        tickets::give_feedback($activity, (int) $declined->id, tickets::VERDICT_HELPED, '', (int) $leader->id);
    }

    /**
     * Absence proof: a WITHDRAWN ticket never asked "did this help?"
     * either. Own method - see
     * test_feedback_is_refused_on_a_declined_ticket()'s docblock.
     */
    public function test_feedback_is_refused_on_a_withdrawn_ticket(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, , $group] = $this->scene();

        $withdrawn = tickets::file_help($activity, $group, 'Q2', FORMAT_PLAIN, (int) $leader->id);
        tickets::withdraw($activity, (int) $withdrawn->id, (int) $leader->id);

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalticketfeedbacknotresolved', 'mod_selfselectadvanced'));
        tickets::give_feedback($activity, (int) $withdrawn->id, tickets::VERDICT_HELPED, '', (int) $leader->id);
    }

    /**
     * A verdict outside {helped, did not help} is a caller bug, not
     * something a person typed through this plugin's own controls - the
     * same reasoning close()'s own $outcome validation states - so it
     * raises coding_exception rather than a workflow_refusal.
     */
    public function test_an_unknown_verdict_value_raises_a_coding_exception(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $ticket = $this->resolved_ticket($activity, $group, $leader, $staff);

        $this->expectException(\coding_exception::class);
        tickets::give_feedback($activity, (int) $ticket->id, 3, '', (int) $leader->id);
    }

    /**
     * The thread offers the control ONLY to the requester of a resolved,
     * unanswered ticket - never to staff, never to the requester on any
     * other status, and never once already answered. Read-only checks
     * against ticket_page::export_for_template(), the "testable without
     * executing the page script" seam this whole output class is built
     * on (its own docblock).
     *
     * RED-FIRST EVIDENCE (captured on m5pg, with export_actionbox()'s
     * own `$ticket->status === tickets::STATUS_RESOLVED` check
     * temporarily widened to `true` unconditionally - simulating the
     * exact bug this test exists to catch, offering the control on a
     * ticket that is not resolved at all):
     *
     *   1) test_the_thread_offers_the_control_only_to_the_eligible_requester:
     *   Failed asserting that false is true.
     *   (an OPEN ticket's own requester was offered "did this help?"
     *   before staff had even looked at the request)
     *
     *   Tests: 1, Assertions: 7, Failures: 1.
     *
     * Reverted immediately after capturing the failure; full suite green
     * again with no other change.
     */
    public function test_the_thread_offers_the_control_only_to_the_eligible_requester(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $output = $PAGE->get_renderer('core');

        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);

        // Positive: the requester, resolved, unanswered.
        $reqpage = new \mod_selfselectadvanced\output\ticket_page($activity, $resolved, $group, (int) $leader->id, true, false);
        $this->assertTrue(
            $reqpage->export_for_template($output)->showfeedback,
            'the requester of a resolved, unanswered ticket must be offered the control'
        );

        // Negative: staff, on the very same ticket.
        $staffpage = new \mod_selfselectadvanced\output\ticket_page($activity, $resolved, $group, (int) $staff->id, false, true);
        $this->assertFalse(
            $staffpage->export_for_template($output)->showfeedback,
            'staff must never be offered the requester\'s own control'
        );

        // Negative: the requester, but the ticket is not resolved.
        $open = tickets::get($activity, (int) tickets::file_help($activity, $group, 'Q', FORMAT_PLAIN, (int) $leader->id)->id);
        $openpage = new \mod_selfselectadvanced\output\ticket_page($activity, $open, $group, (int) $leader->id, true, false);
        $this->assertFalse(
            $openpage->export_for_template($output)->showfeedback,
            'an open ticket must never offer the control'
        );

        // Negative: the requester, already answered.
        tickets::give_feedback($activity, (int) $resolved->id, tickets::VERDICT_HELPED, '', (int) $leader->id);
        $answered = tickets::get($activity, (int) $resolved->id);
        $answeredpage = new \mod_selfselectadvanced\output\ticket_page(
            $activity,
            $answered,
            $group,
            (int) $leader->id,
            true,
            false
        );
        $this->assertFalse(
            $answeredpage->export_for_template($output)->showfeedback,
            'once answered the control must never be offered again'
        );
    }

    // Deliverable B: what staff see.

    /**
     * The staff queue's own row (tickets::queue(), the exact data
     * tickets.php reads to draw its badge with no further query) carries
     * the verdict and note for a resolved ticket - visible without
     * opening it.
     *
     * RED-FIRST EVIDENCE (captured for real on m5pg, with the SELECT
     * list in queue() temporarily narrowed from `t.*` to the
     * pre-1.20.59 column list, omitting verdict/verdictnote/
     * timeverdict):
     *
     *   1) test_the_queue_row_carries_the_verdict_and_note:
     *   Failed asserting that 0 is identical to 2.
     *   PHP warning: Undefined property: stdClass::$verdict
     *
     *   Tests: 1, Assertions: 2, Failures: 1, Warnings: 1.
     *
     * Reverted immediately after capturing the failure; full suite green
     * again with no other change.
     */
    public function test_the_queue_row_carries_the_verdict_and_note(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        // Deliberately the RETIRED verdict (1.20.60, D-108): a value the
        // service no longer writes is exactly the one a narrowed SELECT
        // list would drop unnoticed, because no new row would ever miss
        // it again.
        $this->legacy_nothelped_row($activity, (int) $resolved->id, 'Still broken.');

        $rows = tickets::queue($activity, (int) $staff->id);
        $this->assertArrayHasKey((int) $resolved->id, $rows);
        $row = $rows[(int) $resolved->id];
        $this->assertSame(tickets::VERDICT_NOTHELPED, (int) $row->verdict);
        $this->assertSame('Still broken.', $row->verdictnote);
    }

    /**
     * count_feedback_nothelped() - the coordinator dashboard's own card -
     * counts only RESOLVED tickets whose verdict is "did not help",
     * never an unanswered ticket, a "helped" one, or a live one.
     */
    public function test_count_feedback_nothelped_counts_only_resolved_nothelped(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $helped = $this->resolved_ticket($activity, $group, $leader, $staff);
        tickets::give_feedback($activity, (int) $helped->id, tickets::VERDICT_HELPED, '', (int) $leader->id);

        $unanswered = $this->resolved_ticket($activity, $group, $leader, $staff);

        // Legacy rows (1.20.60, D-108): the counter survives to read
        // what 1.20.59 wrote, so its test has to WRITE what 1.20.59
        // wrote. The dashboard card that used to show this number is
        // gone - count_reopened() replaced it - but the method stays
        // and must stay correct.
        $nothelped1 = $this->resolved_ticket($activity, $group, $leader, $staff);
        $this->legacy_nothelped_row($activity, (int) $nothelped1->id, '');
        $nothelped2 = $this->resolved_ticket($activity, $group, $leader, $staff);
        $this->legacy_nothelped_row($activity, (int) $nothelped2->id, '');

        // A live (still-open) ticket contributes nothing either.
        tickets::file_help($activity, $group, 'Live one', FORMAT_PLAIN, (int) $leader->id);

        $this->assertSame(2, tickets::count_feedback_nothelped($activity));
        // The unanswered/helped rows are not silently miscounted - proof
        // the count is not simply "every resolved ticket".
        $this->assertNotEquals(4, tickets::count_feedback_nothelped($activity));
        unset($unanswered);
    }

    /**
     * Absence: an activity with no "did not help" answers at all reads
     * exactly 0, never a stray count from another activity or from a
     * live/declined/withdrawn ticket.
     */
    public function test_count_feedback_nothelped_is_zero_with_no_verdicts(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $this->resolved_ticket($activity, $group, $leader, $staff);
        $declined = tickets::file_help($activity, $group, 'Q', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $declined->id, (int) $staff->id);
        tickets::close($activity, (int) $declined->id, tickets::STATUS_DECLINED, 'No.', FORMAT_PLAIN, (int) $staff->id);

        $this->assertSame(0, tickets::count_feedback_nothelped($activity));
    }

    // Deliverable C: the trail.

    /**
     * The answer is an ordinary trail row, last in order, carrying the
     * requester's own note - and its two actions are declared nowhere
     * in STAFF_INTERNAL_ACTIONS, so the anonymised requester trail
     * (trail($withactors = false)) includes it exactly like filed,
     * inforeply and withdrawn do.
     *
     * RED-FIRST EVIDENCE (captured for real on m5pg, with ACTION_
     * FEEDBACK_NOTHELPED temporarily added to tickets::
     * STAFF_INTERNAL_ACTIONS - simulating the exact mistake of treating
     * the requester's own answer as staff-internal narration):
     *
     *   1) test_feedback_is_logged_as_an_ordinary_trail_entry_in_order:
     *   Failed asserting that an array does not contain 'feedbacknothelped'.
     *   (the assertNotContains(ACTION_FEEDBACK_NOTHELPED,
     *   STAFF_INTERNAL_ACTIONS) check bit first; the anonymised
     *   requester trail would have gone on to lose the row entirely -
     *   the requester unable to see their own answer on their own
     *   thread)
     *
     *   Tests: 1, Assertions: 2, Failures: 1.
     *
     * Reverted immediately after capturing the failure; full suite green
     * again with no other change.
     */
    public function test_feedback_is_logged_as_an_ordinary_trail_entry_in_order(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        // 1.20.60 (D-108): driven through the REAL service call, which
        // now accepts one verdict only. The retired verdict's own trail
        // row is a legacy shape and is proven where it still matters -
        // the queue row and the privacy export below.
        tickets::give_feedback($activity, (int) $resolved->id, tickets::VERDICT_HELPED, 'Still stuck.', (int) $leader->id);

        // Never staff-internal - the requester's own action about their
        // own request, exactly like filed/inforeply/withdrawn. BOTH
        // actions are asserted, the retired one included: rows carrying
        // it exist on every site that ran 1.20.59, and hiding them from
        // the requester's own thread now would be the same defect.
        $this->assertNotContains(tickets::ACTION_FEEDBACK_HELPED, tickets::STAFF_INTERNAL_ACTIONS);
        $this->assertNotContains(tickets::ACTION_FEEDBACK_NOTHELPED, tickets::STAFF_INTERNAL_ACTIONS);

        $staffrows = array_values(tickets::trail($activity, (int) $resolved->id, true));
        $last = end($staffrows);
        $this->assertSame(tickets::ACTION_FEEDBACK_HELPED, $last->action, 'the answer must be the last trail row');
        $this->assertSame('Still stuck.', $last->note);

        // The anonymised requester view includes the SAME row - a
        // requester-authored action, never withheld.
        $anonrows = array_values(tickets::trail($activity, (int) $resolved->id, false));
        $anonlast = end($anonrows);
        $this->assertSame(tickets::ACTION_FEEDBACK_HELPED, $anonlast->action);
        $this->assertSame('Still stuck.', $anonlast->note);
        $this->assertSame(count($staffrows), count($anonrows), 'no staff-internal row exists here to exclude');
    }

    /**
     * export_entry() prints "You" for the requester's own feedback row
     * on their own anonymised thread - the same REQUESTER_ACTIONS split
     * filed/inforeply/withdrawn already get.
     */
    public function test_the_requester_reads_their_own_feedback_row_as_you(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $output = $PAGE->get_renderer('core');

        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        tickets::give_feedback($activity, (int) $resolved->id, tickets::VERDICT_HELPED, 'Cheers.', (int) $leader->id);
        $answered = tickets::get($activity, (int) $resolved->id);

        $reqpage = new \mod_selfselectadvanced\output\ticket_page($activity, $answered, $group, (int) $leader->id, true, false);
        $entries = $reqpage->export_for_template($output)->entries;
        $last = end($entries);
        $this->assertSame(get_string('threadactorself', 'mod_selfselectadvanced'), $last->actorlabel);
        $this->assertStringContainsString('helped', strtolower($last->actiontext));
    }

    // THE HARDEST CONSTRAINT: no status transition. None.

    /**
     * give_feedback() REFUSES the retired verdict (1.20.60, D-108).
     *
     * WHAT THIS TEST REPLACED, and why the replacement is not a
     * weakening. Until this release the guard here was
     * test_feedback_never_changes_status_or_claim_fields_nothelped(),
     * which proved that answering "this did not help" left status,
     * claimedby, timeclaimed, resolvedby and timeresolved byte-identical
     * - the "a no must not reopen the ticket" rule. Its RED-FIRST
     * EVIDENCE, captured on m5pg with give_feedback() mutated to add
     * `if ($verdict === self::VERDICT_NOTHELPED) { $fresh->status =
     * self::STATUS_OPEN; ... }` right after the verdict assignment, was:
     *
     *   F.  2 / 2 (100%)
     *   1) test_feedback_never_changes_status_or_claim_fields_nothelped:
     *   status must never change on feedback
     *   Failed asserting that two strings are identical.
     *   --- Expected
     *   +++ Actual
     *   -'resolved'
     *   +'open'
     *
     *   Tests: 2, Assertions: 7, Failures: 1.
     *
     * The maintainer's D-108 ruling removed the button that produced
     * that verdict: the second answer is now REPLY TO REOPEN, which
     * reopens the ticket DELIBERATELY, through tickets::reopen(), at the
     * requester's explicit request and with a required explanation. The
     * old test would now be asserting that a code path nothing can reach
     * behaves a certain way - a green check examining nothing.
     *
     * So the constraint moved rather than went: the machine still never
     * changes a ticket's state off the back of a satisfaction answer
     * (test_feedback_never_changes_status_or_claim_fields_helped() below
     * still proves that for the one verdict that survives), and THIS
     * test proves the retired verdict cannot be written at all - by any
     * caller, including a stale external client posting the old integer.
     * Where the state DOES change now, it changes through a named method
     * with its own rules; ticket_reopen_test.php is that guard.
     */
    public function test_give_feedback_refuses_the_retired_verdict(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);

        $before = $DB->get_record('selfselectadvanced_ticket', ['id' => (int) $resolved->id], '*', MUST_EXIST);

        try {
            tickets::give_feedback(
                $activity,
                (int) $resolved->id,
                tickets::VERDICT_NOTHELPED,
                'Did not fix it.',
                (int) $leader->id
            );
            $this->fail('give_feedback() must refuse the retired VERDICT_NOTHELPED since 1.20.60');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('1.20.60', $e->getMessage());
        }

        // AND NOTHING WAS WRITTEN. A refusal that half-applied would be
        // worse than none: the row must be exactly as it was, verdict
        // still unanswered.
        $after = $DB->get_record('selfselectadvanced_ticket', ['id' => (int) $resolved->id], '*', MUST_EXIST);
        $this->assertEquals($before, $after, 'a refused verdict must leave the ticket row untouched');
        $this->assertSame(tickets::VERDICT_UNANSWERED, (int) $after->verdict);
    }

    /**
     * The same guard, the "helped" verdict - proven separately so a fix
     * that special-cases one verdict cannot hide behind the other's
     * passing test.
     */
    public function test_feedback_never_changes_status_or_claim_fields_helped(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);

        $before = $DB->get_record('selfselectadvanced_ticket', ['id' => (int) $resolved->id], '*', MUST_EXIST);

        tickets::give_feedback($activity, (int) $resolved->id, tickets::VERDICT_HELPED, '', (int) $leader->id);

        $after = $DB->get_record('selfselectadvanced_ticket', ['id' => (int) $resolved->id], '*', MUST_EXIST);

        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->claimedby, $after->claimedby);
        $this->assertSame($before->timeclaimed, $after->timeclaimed);
        $this->assertSame($before->resolvedby, $after->resolvedby);
        $this->assertSame($before->timeresolved, $after->timeresolved);
        $this->assertSame(tickets::VERDICT_HELPED, (int) $after->verdict);
    }

    // Privacy.

    /**
     * The requester's own export carries their verdict, note and
     * timestamp.
     */
    public function test_the_requesters_own_export_carries_their_verdict(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $cm, $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        // The retired verdict again (1.20.60, D-108): a subject-access
        // export must still hand back what the person actually said on
        // 1.20.59, not only what the current form can produce.
        $this->legacy_nothelped_row($activity, (int) $resolved->id, 'Nope.');

        $context = \context_module::instance($cm->id);
        $contextlist = new approved_contextlist(
            \core_user::get_user((int) $leader->id),
            'mod_selfselectadvanced',
            [$context->id]
        );

        \core_privacy\local\request\writer::reset();
        provider::export_user_data($contextlist);

        $exported = \core_privacy\local\request\writer::with_context($context)
            ->get_data([get_string('pluginname', 'mod_selfselectadvanced')]);
        $this->assertNotEmpty($exported->tickets, 'the requester export must carry their own ticket');
        $found = null;
        foreach ($exported->tickets as $exportedticket) {
            if ((bool) $exportedticket->wasrequester) {
                $found = $exportedticket;
            }
        }
        $this->assertNotNull($found, 'the requester must find their own ticket in their export');
        $this->assertSame(tickets::VERDICT_NOTHELPED, (int) $found->verdict);
        $this->assertNotNull($found->verdictnote);
        $this->assertStringContainsString('Nope', $found->verdictnote);
    }

    /**
     * Absence: erasing the requester removes their whole ticket row, and
     * with it every trace of the verdict, note and timestamp - the same
     * "their tickets go outright" policy scrub_user_in_activity()
     * already applies to request/resolution.
     */
    public function test_erasing_the_requester_removes_their_verdict_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $cm, $leader, $staff, $group] = $this->scene();
        $resolved = $this->resolved_ticket($activity, $group, $leader, $staff);
        tickets::give_feedback($activity, (int) $resolved->id, tickets::VERDICT_HELPED, 'Thanks.', (int) $leader->id);
        $this->assertTrue($DB->record_exists('selfselectadvanced_ticket', ['id' => (int) $resolved->id]));

        $context = \context_module::instance($cm->id);
        $contextlist = new approved_contextlist(
            \core_user::get_user((int) $leader->id),
            'mod_selfselectadvanced',
            [$context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertFalse(
            $DB->record_exists('selfselectadvanced_ticket', ['id' => (int) $resolved->id]),
            'the whole ticket row, verdict included, must be gone once the requester is erased'
        );
    }
}
