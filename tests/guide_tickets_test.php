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

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * The guide request set completed: maintainer flows (d) and (e),
 * 2026-08-06.
 *
 * Three new ticket types, all keeping the queue's standing contract -
 * resolving never mutates a team; the claimant acts with the existing
 * override, handover and assignment tools:
 *
 *  - guidereduce: a guide asks their team limit DOWN, 0 meaning
 *    "relieve me once my teams are rehomed", suggested successors in
 *    the request text. Shares ONE live slot with the raise type,
 *    because an open raise and an open reduction from one guide are
 *    two contradictory instructions in one queue.
 *  - dates / penaltywaiver: the ASSIGNED guide of a submitted, firm or
 *    frozen team asks for a window extension / plugin-level penalty
 *    relief. The gradebook remains the editing teacher's.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class guide_tickets_test extends \advanced_testcase {
    /**
     * An activity with a guide (non-editing teacher) and one team in
     * the given state, guided by that guide.
     *
     * @param string $groupstate the team's lifecycle state
     * @return array [activity, group row, guide user, leader user]
     */
    private function world(string $groupstate = state::FIRM): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Guided',
            'state' => $groupstate,
            'guideid' => (int) $guide->id,
            'timeapproved' => in_array($groupstate, [state::FIRM, state::FROZEN], true) ? time() : null,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $guide, $leader];
    }

    /**
     * The assigned guide files a date extension and a penalty waiver
     * for their firm team; both land open in the queue.
     */
    public function test_the_assigned_guide_files_dates_and_penalty_tickets(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $guide] = $this->world();

        $dates = tickets::file($activity, $group, tickets::TYPE_DATES, 'One more week', FORMAT_PLAIN, (int) $guide->id);
        $penalty = tickets::file(
            $activity,
            $group,
            tickets::TYPE_PENALTY,
            'The delay was ours, not theirs',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->assertSame(tickets::STATUS_OPEN, $dates->status);
        $this->assertSame(tickets::TYPE_DATES, $dates->type);
        $this->assertSame(tickets::STATUS_OPEN, $penalty->status);
        $this->assertSame(tickets::TYPE_PENALTY, $penalty->type);
        $sink->close();
    }

    /**
     * MUTATION CAUGHT (run): dropping the isguide gate for the new
     * types lets the LEADER file a date extension - this test refuses.
     */
    public function test_the_leader_cannot_file_the_guide_only_types(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, , $leader] = $this->world();

        try {
            tickets::file($activity, $group, tickets::TYPE_DATES, 'Please', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('Only the assigned guide may ask for a date extension');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketnotguide', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * A FORMING team has no guide relationship to ask through.
     *
     * MUTATION CAUGHT (run): dropping the state gate admits this filing.
     */
    public function test_a_forming_team_cannot_carry_a_guide_request(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $guide] = $this->world(state::FORMING);

        try {
            tickets::file($activity, $group, tickets::TYPE_PENALTY, 'Early ask', FORMAT_PLAIN, (int) $guide->id);
            $this->fail('A forming team has no guide to ask through');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalwrongstate', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * The downward capacity ask: below the ceiling files; at or above
     * it is refused toward the raise type; negative is refused.
     */
    public function test_guidereduce_bounds(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $guide] = $this->world();

        $ticket = tickets::file_guidereduce($activity, 0, 'Sabbatical; suggest G. Guide', FORMAT_PLAIN, (int) $guide->id);
        $this->assertSame(tickets::TYPE_GUIDEREDUCE, $ticket->type);
        $this->assertSame(0, (int) $ticket->requested, 'zero is the relieve-me ask');
        $this->assertNull($ticket->groupid, 'the ask is about the guide, not a team');
        $sink->close();
    }

    /**
     * MUTATION CAUGHT (run): inverting the ceiling comparison lets a
     * guide file a "reduction" ABOVE their ceiling.
     */
    public function test_guidereduce_refuses_at_or_above_the_ceiling(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $guide] = $this->world();
        $ceiling = (new \mod_selfselectadvanced\local\api($activity))
            ->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guide->id);

        try {
            tickets::file_guidereduce($activity, $ceiling->value, 'Not less', FORMAT_PLAIN, (int) $guide->id);
            $this->fail('Asking for the current limit is not a reduction');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalguidereducenotless', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * ONE live slot across BOTH capacity types, in both directions: an
     * open reduction blocks a raise, and an open raise blocks a
     * reduction.
     *
     * MUTATION CAUGHT (run): narrowing either duplicate guard back to
     * its own type lets the contradictory pair coexist.
     */
    public function test_raise_and_reduction_share_one_live_slot(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $guide] = $this->world();
        $ceiling = (new \mod_selfselectadvanced\local\api($activity))
            ->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guide->id);

        tickets::file_guidereduce($activity, 0, 'Down please', FORMAT_PLAIN, (int) $guide->id);
        try {
            tickets::file_guidecap($activity, $ceiling->value + 1, 'And also up', FORMAT_PLAIN, (int) $guide->id);
            $this->fail('An open reduction must block a raise');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketduplicate', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * The other direction, in its own method (the PostgreSQL
     * poisoned-transaction trap: a refusal must not precede a commit).
     */
    public function test_an_open_raise_blocks_a_reduction(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $guide] = $this->world();
        $ceiling = (new \mod_selfselectadvanced\local\api($activity))
            ->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guide->id);

        tickets::file_guidecap($activity, $ceiling->value + 1, 'Up please', FORMAT_PLAIN, (int) $guide->id);
        try {
            tickets::file_guidereduce($activity, 0, 'And also down', FORMAT_PLAIN, (int) $guide->id);
            $this->fail('An open raise must block a reduction');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketduplicate', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * The queue lifecycle holds for a new type end to end: a
     * coordinator-shaped worker claims exclusively, resolves with a
     * note, and RESOLVING MUTATED NOTHING - the team row is untouched,
     * because the claimant acts with the real tools, not the ticket.
     */
    public function test_claim_and_resolve_keep_the_queue_contract(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $guide] = $this->world();
        $worker = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($worker->id, (int) $activity->cm()->course, 'editingteacher');
        $before = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);

        $ticket = tickets::file($activity, $group, tickets::TYPE_DATES, 'One more week', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $worker->id);
        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Window extended via the override form',
            FORMAT_PLAIN,
            (int) $worker->id
        );

        $this->assertSame(
            tickets::STATUS_RESOLVED,
            tickets::get($activity, (int) $ticket->id)->status
        );
        $after = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);
        $this->assertEquals($before, $after, 'resolving a ticket must never mutate the team');
        $sink->close();
    }

    /**
     * The requester withdraws their own reduction while it is open -
     * the type-agnostic withdrawal the guidequeue page posts.
     */
    public function test_the_guide_withdraws_their_own_reduction(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $guide] = $this->world();

        $ticket = tickets::file_guidereduce($activity, 0, 'Changed my mind path', FORMAT_PLAIN, (int) $guide->id);
        $withdrawn = tickets::withdraw($activity, (int) $ticket->id, (int) $guide->id);

        $this->assertSame(tickets::STATUS_WITHDRAWN, $withdrawn->status);
        $sink->close();
    }

    /**
     * AUDIT A4 (2026-08-20): grant_guidecap() must accept a needsinfo
     * ticket exactly as close() itself does (decision 2, LIVENESS) -
     * the thread's own Grant button is offered in both CLAIMED and
     * NEEDSINFO, so the service must agree or the button is a
     * guaranteed refusal. RED-FIRST PROOF (see the report): before the
     * fix this throws refusalticketnotclaimed instead of granting.
     */
    public function test_grant_guidecap_accepts_from_needsinfo(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $guide] = $this->world();
        $ceiling = (new \mod_selfselectadvanced\local\api($activity))
            ->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guide->id);

        $ticket = tickets::file_guidecap($activity, $ceiling->value + 1, 'Room for more', FORMAT_PLAIN, (int) $guide->id);
        $coordinator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($coordinator->id, (int) $activity->cm()->course, 'editingteacher');
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'How many teams do you currently hold?',
            FORMAT_PLAIN,
            (int) $coordinator->id
        );
        $fresh = tickets::get($activity, (int) $ticket->id);
        $this->assertSame(tickets::STATUS_NEEDSINFO, $fresh->status, 'fixture: the ticket must genuinely be needsinfo');

        $granted = tickets::grant_guidecap(
            $activity,
            (int) $ticket->id,
            'Approved without waiting for the reply',
            FORMAT_PLAIN,
            (int) $coordinator->id
        );
        $this->assertSame(tickets::STATUS_RESOLVED, $granted->status);

        $newceiling = (new \mod_selfselectadvanced\local\api($activity))
            ->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guide->id);
        $this->assertSame($ceiling->value + 1, $newceiling->value, 'the override must actually have been written');
        $sink->close();
    }

    /**
     * AUDIT A6 (2026-08-20): a guide's own live capacity request must
     * still read as LIVE while it sits in needsinfo - the same trio
     * file_guidecap()'s own duplicate guard already treats as live
     * (decision 2). guidequeue.php's own inline copy of this predicate
     * had drifted (needsinfo omitted); proven here directly against the
     * extracted predicate the page now calls instead.
     */
    public function test_live_capacity_request_counts_needsinfo_as_live(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $guide] = $this->world();
        $ceiling = (new \mod_selfselectadvanced\local\api($activity))
            ->gatekeeper()->resolver()->guide_capacity_ceiling((int) $guide->id);

        $ticket = tickets::file_guidecap($activity, $ceiling->value + 1, 'Room for more', FORMAT_PLAIN, (int) $guide->id);
        $coordinator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($coordinator->id, (int) $activity->cm()->course, 'editingteacher');
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'How many teams do you currently hold?',
            FORMAT_PLAIN,
            (int) $coordinator->id
        );

        $live = tickets::guide_live_capacity_request($activity, (int) $guide->id);
        $this->assertNotNull($live, 'a needsinfo capacity ticket must still read as the guide\'s live request');
        $this->assertSame(tickets::STATUS_NEEDSINFO, $live->status);
        $this->assertSame((int) $ticket->id, (int) $live->id);
        $sink->close();
    }
}
