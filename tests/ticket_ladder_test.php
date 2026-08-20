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
 * 1.20.44 part 1 - the handling ladder: refer (coordinator -> coordinator)
 * and escalate (-> editing teachers / managers). Proven at the SERVICE
 * (direct calls, never through a page - UI hiding is not enforcement).
 *
 * D-105 (human authority does not narrow): editing teachers/managers keep
 * full close authority on any ticket, escalated or not - nothing here
 * takes anything away from a manage-level holder. D-107 is still open
 * with the recommendation "no down-ladder"; nothing here de-escalates.
 *
 * RED-FIRST EVIDENCE (captured 2026-08-15, PHPUnit run on m5pg against
 * this same tree):
 *
 * 1) With tickets::claim()'s escalated-authority gate temporarily removed
 *    BY HAND (the `if ((int) $fresh->escalated === 1 && !has_capability(...))`
 *    block deleted), test_coordinator_cannot_claim_escalated_ticket failed:
 *
 *    mod_selfselectadvanced\ticket_ladder_test::test_coordinator_cannot_claim_escalated_ticket
 *    a mere coordinator must be refused claiming an escalated ticket
 *
 *    /path/.../tests/ticket_ladder_test.php:603
 *    FAILURES!
 *    Tests: 1, Assertions: 1, Failures: 1, PHPUnit Deprecations: 1.
 *
 *    Restored and re-verified green (this whole file, all 21 tests green,
 *    60 assertions - `diff` against the pre-revert copy confirmed byte-
 *    identical restoration before the full-file re-run).
 *
 * 2) With tickets::trail()'s STAFF_INTERNAL_ACTIONS filter temporarily
 *    reverted BY HAND (the non-staff branch's WHERE clause returned to
 *    its pre-1.20.44 shape, no action exclusion at all),
 *    test_refer_requester_trail_unchanged failed:
 *
 *    mod_selfselectadvanced\ticket_ladder_test::test_refer_requester_trail_unchanged
 *    a referral must add nothing to the requester's own trail
 *    Failed asserting that actual size 3 matches expected size 2.
 *
 *    /path/.../tests/ticket_ladder_test.php:369
 *    FAILURES!
 *    Tests: 1, Assertions: 2, Failures: 1, PHPUnit Deprecations: 1.
 *
 *    Restored and re-verified green (same full-file re-run as above).
 *
 * 3) With 'escalated' temporarily removed BY HAND from
 *    backup_selfselectadvanced_stepslib.php's ticket nested element list,
 *    test_escalated_survives_backup_and_restore failed:
 *
 *    mod_selfselectadvanced\ticket_ladder_test::test_escalated_survives_backup_and_restore
 *    the escalated flag must survive the round trip
 *    Failed asserting that 0 is identical to 1.
 *
 *    /path/.../tests/ticket_ladder_test.php:831
 *    FAILURES!
 *    Tests: 1, Assertions: 4, Failures: 1, PHPUnit Deprecations: 1.
 *
 *    Restored (`diff` confirmed byte-identical) and re-verified green.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets::refer
 * @covers     \mod_selfselectadvanced\local\tickets::escalate
 * @covers     \mod_selfselectadvanced\local\tickets::eligible_referral_targets
 * @covers     \mod_selfselectadvanced\local\tickets::claim
 * @covers     \mod_selfselectadvanced\local\tickets::trail
 * @covers     \mod_selfselectadvanced\local\tickets::queue
 * @covers     \mod_selfselectadvanced\event\ticket_referred
 * @covers     \mod_selfselectadvanced\event\ticket_escalated
 */
final class ticket_ladder_test extends \advanced_testcase {
    /**
     * An activity with a firm group (leader + confirmed member, guide
     * assigned), a manager, TWO coordinators and an uninvolved student -
     * shaped like ticket_thread_test.php::setup_world() with a second
     * coordinator added for refer()'s target.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator1, coordinator2, outsider]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'LADDER1']);
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
            'name' => 'Ladder',
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
     * File a compchange ticket from the guide - the fixture every test
     * below starts from, matching ticket_thread_test.php's own idiom.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group
     * @param \stdClass $guide who files it
     * @return \stdClass the filed ticket
     */
    private function file(activity $activity, \stdClass $group, \stdClass $guide): \stdClass {
        return tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
    }

    // ------------------------------------------------------------------
    // Refer.

    /**
     * Happy path: the claimant refers to an eligible, uninvolved
     * coordinator. claimedby moves, status is UNCHANGED (D-105), and the
     * STAFF trail carries the note under the claimant's own name.
     */
    public function test_refer_happy_path_moves_claim_and_logs_note(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        $referred = tickets::refer(
            $activity,
            (int) $ticket->id,
            (int) $coordinator2->id,
            'You know this specialism better than I do.',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );

        $this->assertSame((int) $coordinator2->id, (int) $referred->claimedby, 'claimedby must move to the target');
        $this->assertSame(tickets::STATUS_CLAIMED, $referred->status, 'status must be unchanged by a referral');

        $trail = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $last = end($trail);
        $this->assertSame(tickets::ACTION_REFERRED, $last->action);
        $this->assertSame((int) $coordinator1->id, (int) $last->actorid, 'the referral is logged under the REFERRING coordinator');
        $this->assertSame('You know this specialism better than I do.', $last->note);
    }

    /**
     * Non-claimant refused - the same ownership rule request_info() uses.
     * Negative-only (PostgreSQL transaction-poison rule: a refused call
     * followed by a later commit in the same method poisons the
     * delegated transaction stack on PG - see ticket_authority_test.php).
     */
    public function test_refer_non_claimant_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        try {
            tickets::refer(
                $activity,
                (int) $ticket->id,
                (int) $coordinator1->id,
                'I am not the claimant',
                FORMAT_PLAIN,
                (int) $coordinator2->id
            );
            $this->fail('a non-claimant must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketnotclaimant', $e->errorcode);
        }
    }

    /**
     * AUDIT A1 (2026-08-20): refer() had no authority gate of its own
     * either. The ticket's own REQUESTER (no queue authority at all)
     * POSTing action=refer used to reach the not-claimant check inside
     * the lock and be handed the claimant's fullname by
     * workflow_refusal - the identical leak request_info() had.
     * RED-FIRST PROOF (see the report): before the fix this throws
     * workflow_refusal('refusalticketnotclaimant', ..., fullname($coordinator1))
     * and the second catch arm below fails the test on that leak; after
     * the fix, core's required_capability_exception is thrown before
     * the lock is ever taken.
     */
    public function test_refer_by_the_requester_never_names_the_claimant(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        try {
            // The GUIDE is this ticket's own requester.
            tickets::refer(
                $activity,
                (int) $ticket->id,
                (int) $coordinator2->id,
                'Trying to redirect my own ticket',
                FORMAT_PLAIN,
                (int) $guide->id
            );
            $this->fail('a requester with no queue authority must be refused before refer() ever runs');
        } catch (\required_capability_exception $e) {
            $this->assertStringNotContainsString(
                fullname($coordinator1),
                $e->getMessage(),
                'a requester must never learn the claimant\'s name from this refusal'
            );
        } catch (local\workflow_refusal $e) {
            $this->fail(
                'expected core\'s required_capability_exception (no name); got a workflow_refusal ('
                . $e->errorcode . ') instead - it reached the not-claimant check, which names the claimant'
            );
        }
    }

    /**
     * A target who holds queue authority but is INVOLVED with the team
     * (here: the guide, also role-assigned Group Coordinator) is refused
     * - require_uninvolved(), mirrored exactly as claim()'s own gate.
     * Negative-only.
     */
    public function test_refer_involved_target_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();
        role_assign(coordinatorrole::ensure(), $guide->id, $activity->context());

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        try {
            tickets::refer(
                $activity,
                (int) $ticket->id,
                (int) $guide->id,
                'The guide happens to hold queue authority too',
                FORMAT_PLAIN,
                (int) $coordinator1->id
            );
            $this->fail('a target involved with the team must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalcoiinvolved', $e->errorcode);
        }
    }

    /**
     * A referral to oneself is refused (discretionary call: the spec
     * does not say this in words, but a claimant "referring" to
     * themselves is not a referral at all).
     */
    public function test_refer_target_self_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        try {
            tickets::refer($activity, (int) $ticket->id, (int) $coordinator1->id, 'Myself', FORMAT_PLAIN, (int) $coordinator1->id);
            $this->fail('referring to oneself must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketrefertargetself', $e->errorcode);
        }
    }

    /**
     * A target with no queue authority at all (a plain student) is
     * refused with a message naming the TARGET's problem, not the
     * actor's - never core's required_capability_exception, which would
     * misname who lacks what here.
     */
    public function test_refer_target_without_authority_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, , $outsider] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        try {
            tickets::refer(
                $activity,
                (int) $ticket->id,
                (int) $outsider->id,
                'Not staff at all',
                FORMAT_PLAIN,
                (int) $coordinator1->id
            );
            $this->fail('a target with no queue authority must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketrefertargetauthority', $e->errorcode);
        }
    }

    /**
     * While escalated, a referral to a mere coordinate-only target is
     * refused - closing the back door a referral would otherwise be
     * around claim()'s and escalate()'s own manage-only narrowing
     * (discretionary call, flagged in the report: the spec's escalated-
     * claim restriction only names claim() by name, but refer() moves
     * claimedby exactly like claim() does, so the same rule has to
     * apply or referral is simply a bypass).
     *
     * The claimant here is a MANAGE holder (the only way a ticket stays
     * CLAIMED once escalated - escalate() releases a mere coordinator's
     * claim), so the claimant identity itself proves nothing about the
     * target; the target check is what this test isolates.
     */
    public function test_refer_target_must_be_manage_when_escalated(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, , $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::escalate($activity, (int) $ticket->id, 'Needs a manager\'s eyes', FORMAT_PLAIN, (int) $manager->id);

        try {
            tickets::refer(
                $activity,
                (int) $ticket->id,
                (int) $coordinator2->id,
                'Passing it sideways',
                FORMAT_PLAIN,
                (int) $manager->id
            );
            $this->fail('a mere coordinator must be refused as a referral target on an escalated ticket');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketescalated', $e->errorcode);
        }
    }

    /**
     * RED-FIRST PROOF (see this file's docblock): the requester's own
     * (state-change-only, anonymised) trail is BYTE-FOR-BYTE unaffected
     * by a referral - "Somebody is handling this." stays true. Compared
     * before and after rather than merely asserting the row count, so a
     * referral that changed an EXISTING row's content (not just added
     * one) would also be caught.
     */
    public function test_refer_requester_trail_unchanged(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        $before = tickets::trail($activity, (int) $ticket->id, false);
        $beforeserialised = array_map(static fn($row) => (array) $row, array_values($before));

        tickets::refer(
            $activity,
            (int) $ticket->id,
            (int) $coordinator2->id,
            'Handing this to a colleague',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );

        $after = tickets::trail($activity, (int) $ticket->id, false);
        $afterserialised = array_map(static fn($row) => (array) $row, array_values($after));

        $this->assertCount(2, $before, 'fixture: filed + claimed, both already visible to the requester');
        $this->assertCount(2, $after, 'a referral must add nothing to the requester\'s own trail');
        $this->assertEquals($beforeserialised, $afterserialised, 'not one existing row may change either');
    }

    /**
     * Event payload bar: relateduserid = target, other =
     * {type, action, groupid, ticketlogid} - and ticketlogid is
     * cross-checked against the ACTUAL stored trail row, not merely "an
     * int was present" (ticket_thread_test.php's own discipline).
     */
    public function test_refer_event_payload_bar(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        $this->setUser($coordinator1);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        $events = $this->redirectEvents();
        // Core's \core\event\base::create() defaults 'userid' to the
        // current $USER when the caller does not set one explicitly
        // (ticket_thread_test.php's own idiom, test_event_payload_bar).
        tickets::refer(
            $activity,
            (int) $ticket->id,
            (int) $coordinator2->id,
            'For the payload bar',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );

        $referred = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_referred
        ));
        $this->assertCount(1, $referred);
        $this->assertSame((int) $coordinator1->id, (int) $referred[0]->userid, 'the actor is the referring coordinator');
        $this->assertSame((int) $coordinator2->id, (int) $referred[0]->relateduserid, 'relateduserid is the target');
        $this->assertSame(tickets::ACTION_REFERRED, $referred[0]->other['action']);
        $this->assertSame(tickets::TYPE_COMPCHANGE, $referred[0]->other['type']);
        $this->assertSame((int) $group->id, (int) $referred[0]->other['groupid']);

        $trail = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $last = end($trail);
        $this->assertSame(
            (int) $last->id,
            (int) $referred[0]->other['ticketlogid'],
            'ticketlogid must name the actual referred trail row'
        );
        $logrow = $DB->get_record('selfselectadvanced_ticketlog', ['id' => $referred[0]->other['ticketlogid']]);
        $this->assertNotFalse($logrow);
        $this->assertSame((int) $ticket->id, (int) $logrow->ticketid);
    }

    /**
     * The TARGET alone is notified - pinned on the message sink.
     */
    public function test_refer_notifies_target(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        // The sink has already captured the coordinators' enrolment
        // welcome messages from setup_world() (redirectMessages() ran
        // before it) - filtered out by content below rather than by
        // taking the chronologically FIRST message to this recipient.
        tickets::refer(
            $activity,
            (int) $ticket->id,
            (int) $coordinator2->id,
            'Over to you',
            FORMAT_PLAIN,
            (int) $coordinator1->id
        );

        $totarget = array_values(array_filter(
            $sink->get_messages(),
            static fn($m) => (int) $m->useridto === (int) $coordinator2->id
                && str_contains((string) $m->fullmessage, 'Over to you')
        ));
        $this->assertNotEmpty($totarget, 'the target must be notified');
        $this->assertStringContainsString('Over to you', reset($totarget)->fullmessage);
        $sink->close();
    }

    /**
     * The bounded, server-built eligible-target list excludes the
     * actor themself and every involved person, and includes an
     * uninvolved coordinator.
     */
    public function test_eligible_referral_targets(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        $fresh = tickets::get($activity, (int) $ticket->id);

        $targets = tickets::eligible_referral_targets($activity, $fresh, (int) $coordinator1->id);

        $this->assertArrayHasKey((int) $coordinator2->id, $targets, 'an uninvolved coordinator must be eligible');
        $this->assertArrayNotHasKey((int) $coordinator1->id, $targets, 'the actor themself must never be offered');
        $this->assertArrayNotHasKey(
            (int) $guide->id,
            $targets,
            'the involved guide must never be offered, even if they held queue authority'
        );
    }

    // ------------------------------------------------------------------
    // Escalate.

    /**
     * The coordinator-claimant path: escalating releases their claim -
     * status back to open, claimedby/timeclaimed cleared - and sets
     * escalated.
     */
    public function test_escalate_coordinator_claimant_releases_claim(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        $escalated = tickets::escalate($activity, (int) $ticket->id, 'This is above me', FORMAT_PLAIN, (int) $coordinator1->id);

        $this->assertSame(1, (int) $escalated->escalated);
        $this->assertSame(tickets::STATUS_OPEN, $escalated->status, 'a mere coordinator\'s claim must be released');
        $this->assertNull($escalated->claimedby);
        $this->assertNull($escalated->timeclaimed);
    }

    /**
     * A manage-level holder may escalate a ticket nobody has claimed at
     * all ("even when unclaimed", spec verbatim).
     */
    public function test_escalate_manage_holder_can_escalate_unclaimed(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status, 'fixture: the ticket must genuinely be unclaimed');

        $escalated = tickets::escalate($activity, (int) $ticket->id, 'Needs staff attention now', FORMAT_PLAIN, (int) $manager->id);

        $this->assertSame(1, (int) $escalated->escalated);
        $this->assertSame(tickets::STATUS_OPEN, $escalated->status, 'an already-unclaimed ticket stays open');
        $this->assertNull($escalated->claimedby);
    }

    /**
     * A manage-level holder's OWN claim is left exactly as it is when
     * they escalate their own ticket - they already qualify to keep
     * handling it, so nothing is released.
     */
    public function test_escalate_manage_holder_claim_preserved(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);

        $escalated = tickets::escalate(
            $activity,
            (int) $ticket->id,
            'Flagging it while I keep working it',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        $this->assertSame(1, (int) $escalated->escalated);
        $this->assertSame(
            tickets::STATUS_CLAIMED,
            $escalated->status,
            'a manage holder\'s own claim must not be released'
        );
        $this->assertSame((int) $manager->id, (int) $escalated->claimedby);
    }

    /**
     * Already escalated is refused - there is no further ladder rung
     * (D-107: no down-ladder, and no double-escalation either).
     * Negative-only.
     */
    public function test_escalate_already_escalated_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::escalate($activity, (int) $ticket->id, 'First escalation', FORMAT_PLAIN, (int) $manager->id);

        try {
            tickets::escalate($activity, (int) $ticket->id, 'Second escalation', FORMAT_PLAIN, (int) $manager->id);
            $this->fail('an already-escalated ticket must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketalreadyescalated', $e->errorcode);
        }
    }

    /**
     * Neither the claimant nor a manage-level holder: refused.
     * Negative-only.
     */
    public function test_escalate_non_claimant_non_manager_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        try {
            tickets::escalate($activity, (int) $ticket->id, 'Not mine to escalate', FORMAT_PLAIN, (int) $coordinator2->id);
            $this->fail('a bystander coordinator must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketescalateauthority', $e->errorcode);
        }
    }

    /**
     * RED-FIRST PROOF (see this file's docblock): once escalated, a mere
     * coordinator's Take up is refused in the SERVICE - claim(), not
     * merely hidden in the UI. This is the arm the spec calls out by
     * name ("coordinator cannot touch an escalated ticket (refusal
     * string)").
     */
    public function test_coordinator_cannot_claim_escalated_ticket(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1, $coordinator2] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::escalate($activity, (int) $ticket->id, 'Over my head', FORMAT_PLAIN, (int) $coordinator1->id);

        try {
            tickets::claim($activity, (int) $ticket->id, (int) $coordinator2->id);
            $this->fail('a mere coordinator must be refused claiming an escalated ticket');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketescalated', $e->errorcode);
        }
    }

    /**
     * The positive control for the arm above, in its OWN method
     * (PostgreSQL transaction-poison rule): a manage-level holder MAY
     * claim an escalated, unclaimed ticket.
     */
    public function test_manager_can_claim_escalated_ticket(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator1] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::escalate($activity, (int) $ticket->id, 'Over my head', FORMAT_PLAIN, (int) $coordinator1->id);

        $claimed = tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $this->assertSame((int) $manager->id, (int) $claimed->claimedby);
    }

    /**
     * Queue ordering: an escalated ticket sorts FIRST within the live
     * band, ahead of an older open ticket and an older claimed one -
     * spec: "escalated tickets first within the live band".
     */
    public function test_queue_orders_escalated_first_within_live_band(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator1] = $this->setup_world();

        // Filed and claimed FIRST (older), so a status/recency-only sort
        // would rank it ahead of anything filed afterwards.
        $older = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $older->id, (int) $coordinator1->id);

        // A SECOND group's ticket, filed and escalated AFTERWARDS
        // (newer) - it must still sort ahead of the older claimed one.
        $leader2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($leader2->id, $activity->courseid(), 'student');
        $group2 = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader2->id,
            'name' => 'Ladder second',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $group2 = groups::get($activity, (int) $group2->id);
        $newer = tickets::file($activity, $group2, tickets::TYPE_COMPCHANGE, 'Second team', FORMAT_PLAIN, (int) $guide->id);
        tickets::escalate($activity, (int) $newer->id, 'Escalate the newer one', FORMAT_PLAIN, (int) $manager->id);

        $queue = array_values(tickets::queue($activity));
        $this->assertSame(
            (int) $newer->id,
            (int) $queue[0]->id,
            'the escalated ticket must sort first, ahead of the older claimed one'
        );
        $this->assertSame((int) $older->id, (int) $queue[1]->id);
    }

    /**
     * Event payload bar for escalate: relateduserid = requester, other =
     * {type, action, groupid, ticketlogid}, ticketlogid cross-checked
     * against the actual stored trail row.
     */
    public function test_escalate_event_payload_bar(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        $this->setUser($coordinator1);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);

        $events = $this->redirectEvents();
        tickets::escalate($activity, (int) $ticket->id, 'For the payload bar', FORMAT_PLAIN, (int) $coordinator1->id);

        $escalated = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_escalated
        ));
        $this->assertCount(1, $escalated);
        $this->assertSame((int) $coordinator1->id, (int) $escalated[0]->userid);
        $this->assertSame((int) $guide->id, (int) $escalated[0]->relateduserid, 'relateduserid is the requester');
        $this->assertSame(tickets::ACTION_ESCALATED, $escalated[0]->other['action']);
        $this->assertSame(tickets::TYPE_COMPCHANGE, $escalated[0]->other['type']);
        $this->assertSame((int) $group->id, (int) $escalated[0]->other['groupid']);
        $this->assertSame(1, (int) $escalated[0]->other['released'], 'a mere coordinator\'s claim was released');

        $logrow = $DB->get_record('selfselectadvanced_ticketlog', ['id' => $escalated[0]->other['ticketlogid']]);
        $this->assertNotFalse($logrow);
        $this->assertSame((int) $ticket->id, (int) $logrow->ticketid);
        $this->assertSame(tickets::ACTION_ESCALATED, $logrow->action);
    }

    /**
     * The manage-level tier is notified, the requester is not (spec,
     * verbatim: "requester unaffected") - and the escalating actor is
     * not told about their own action either.
     */
    public function test_escalate_notifies_manage_holders_only(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator1] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        $sink->clear();

        tickets::escalate($activity, (int) $ticket->id, 'Needs a manager', FORMAT_PLAIN, (int) $coordinator1->id);

        $messages = $sink->get_messages();
        $tomanager = array_values(array_filter($messages, static fn($m) => (int) $m->useridto === (int) $manager->id));
        $torequester = array_values(array_filter($messages, static fn($m) => (int) $m->useridto === (int) $guide->id));
        $toactor = array_values(array_filter($messages, static fn($m) => (int) $m->useridto === (int) $coordinator1->id));

        $this->assertNotEmpty($tomanager, 'the manage-level holder must be notified');
        $this->assertStringContainsString('Needs a manager', reset($tomanager)->fullmessage);
        $this->assertEmpty($torequester, 'the requester must not be notified (spec: "requester unaffected")');
        $this->assertEmpty($toactor, 'the escalating actor must not be told about their own action');
        $sink->close();
    }

    /**
     * Discretionary call (flagged in the report): the requester's own
     * trail is unaffected by an escalation too, exactly like a referral
     * - Part 2 of this same release calls both notes "staff-internal" in
     * so many words.
     */
    public function test_escalate_requester_trail_unchanged(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        $before = array_values(tickets::trail($activity, (int) $ticket->id, false));

        tickets::escalate($activity, (int) $ticket->id, 'Staff-internal reasoning', FORMAT_PLAIN, (int) $coordinator1->id);

        $after = array_values(tickets::trail($activity, (int) $ticket->id, false));
        $this->assertCount(2, $before);
        $this->assertCount(2, $after, 'an escalation must add nothing to the requester\'s own trail');
    }

    /**
     * AUDIT A2 (2026-08-20): the requester's own thread export must
     * never carry the 'escalated' flag as true, even though the column
     * genuinely is 1 on the row - ticket_page.php used to export it
     * unconditionally, the one surface 1.20.44's "narration withheld,
     * never narrated" rule (this file's own docblock, and the trail
     * test right above) was not applied to. A staff viewer of the SAME
     * ticket must still see it - PROVING ABSENCE, not merely presence,
     * on both sides of one genuinely escalated ticket.
     */
    public function test_escalated_badge_hidden_from_requester_shown_to_staff(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator1] = $this->setup_world();

        $ticket = $this->file($activity, $group, $guide);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator1->id);
        tickets::escalate($activity, (int) $ticket->id, 'Needs a manager\'s eyes', FORMAT_PLAIN, (int) $coordinator1->id);
        $fresh = tickets::get($activity, (int) $ticket->id);
        $this->assertSame(1, (int) $fresh->escalated, 'fixture: the ticket must genuinely be escalated');

        $output = $PAGE->get_renderer('core');

        $reqpage = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $guide->id, true, false);
        $reqexported = $reqpage->export_for_template($output);
        $this->assertFalse(
            $reqexported->escalated,
            'the requester must never see the escalated badge, even on a genuinely escalated ticket'
        );

        $staffpage = new \mod_selfselectadvanced\output\ticket_page(
            $activity,
            $fresh,
            $group,
            (int) $coordinator1->id,
            false,
            true
        );
        $staffexported = $staffpage->export_for_template($output);
        $this->assertTrue($staffexported->escalated, 'a staff viewer of the same ticket must still see the badge');
    }

    /**
     * The new `escalated` column round-trips through backup/restore -
     * added to backup_selfselectadvanced_stepslib.php's ticket element
     * alongside this slice's other work, since a field missing from that
     * list is silently dropped on restore rather than merely defaulted.
     * Shaped like ticket_trail_test.php::test_trail_survives_backup_and_restore().
     */
    public function test_escalated_survives_backup_and_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();
        $cm = $activity->cm();

        $ticket = $this->file($activity, $group, $guide);
        tickets::escalate($activity, (int) $ticket->id, 'Needs staff', FORMAT_PLAIN, (int) $manager->id);
        $fresh = tickets::get($activity, (int) $ticket->id);
        $this->assertSame(1, (int) $fresh->escalated, 'fixture must actually be escalated before the round trip');

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

        $restoredticket = $DB->get_record(
            'selfselectadvanced_ticket',
            ['activityid' => (int) $restoredinstance->id, 'type' => tickets::TYPE_COMPCHANGE],
            '*',
            MUST_EXIST
        );
        $this->assertSame(1, (int) $restoredticket->escalated, 'the escalated flag must survive the round trip');
    }
}
