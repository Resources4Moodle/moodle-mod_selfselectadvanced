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

/**
 * A requester can see their own requests, and only their own.
 *
 * WHY THIS EXISTS. Until 1.20.39 nothing showed a requester the request
 * they had made. The queue is staff-only by design and the outcome was
 * supposed to travel in the closing message - but on the dev site a
 * student filed a request, a coordinator claimed it, and the student had
 * no way to see any of it. tickets::mine() and myrequests.php are the
 * answer, and the property that matters most about them is the negative
 * one: mine() must return MY rows and nobody else's, whoever asks.
 *
 * withdraw() is tested here too. It has enforced requester ownership
 * since 1.18, but its only caller sat behind the guide capability, so no
 * student or leader could ever reach it. A gate with no reachable caller
 * is not a gate, and nothing failed while that was true.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class myrequests_test extends \advanced_testcase {
    /**
     * An activity with a firm group: leader, confirmed member, guide,
     * manager, coordinator.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'MYREQ']);
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
            'name' => 'Requested',
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
     * My list holds my request and not the one somebody else filed.
     *
     * The whole point of the page. If this ever passes for the wrong
     * reason - an empty list on both sides - the counts below say so.
     *
     * MUTATION CAUGHT (run 2026-08-14): dropping `AND t.requestedby =
     * :userid` from tickets::mine() makes each viewer see both rows and
     * fails both count assertions.
     */
    public function test_mine_returns_only_the_rows_i_filed(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, , $member, $guide] = $this->setup_world();

        $this->redirectMessages();
        $mineticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        $theirs = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'This group needs a different mix',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $mine = tickets::mine($activity, (int) $member->id);
        $theirlist = tickets::mine($activity, (int) $guide->id);

        // Both people filed exactly one, so neither list may be empty
        // and neither may hold two.
        $this->assertCount(1, $mine, 'the member sees their own request');
        $this->assertCount(1, $theirlist, 'the guide sees their own request');
        $minefirst = reset($mine);
        $theirfirst = reset($theirlist);
        $this->assertSame((int) $mineticket->id, (int) $minefirst->id);
        $this->assertSame((int) $theirs->id, (int) $theirfirst->id);
        $this->assertSame(2, tickets::queue_count($activity), 'both rows exist - the lists are scoped, not empty');
        $this->assertSame(1, tickets::mine_count($activity, (int) $member->id));
        $this->assertSame(1, tickets::mine_count($activity, (int) $guide->id));

        // And somebody who filed nothing sees nothing.
        $this->assertSame([], tickets::mine($activity, (int) $group->leaderid));
        $this->assertSame(0, tickets::mine_count($activity, (int) $group->leaderid));
    }

    /**
     * The list carries what the page needs to draw: the group it is
     * about, the status, and the resolution once it is closed.
     */
    public function test_mine_carries_the_outcome_the_requester_could_not_see(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, , $member, , $manager] = $this->setup_world();

        $this->redirectMessages();
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $rows = tickets::mine($activity, (int) $member->id);
        $open = reset($rows);
        $this->assertSame(tickets::STATUS_OPEN, $open->status);
        $this->assertSame('Requested', $open->groupname, 'the page labels the row without a second query');

        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $rows = tickets::mine($activity, (int) $member->id);
        $claimed = reset($rows);
        $this->assertSame(tickets::STATUS_CLAIMED, $claimed->status);

        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Spoken to the leader, all settled',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $rows = tickets::mine($activity, (int) $member->id);
        $closed = reset($rows);
        $this->assertSame(tickets::STATUS_RESOLVED, $closed->status);
        $this->assertSame('Spoken to the leader, all settled', $closed->resolution);
    }

    /**
     * Open first, then claimed, then everything closed.
     *
     * The order the page relies on, asserted here so a change to the SQL
     * cannot quietly rearrange somebody's screen.
     */
    public function test_mine_puts_live_requests_above_finished_ones(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, , $member, , $manager] = $this->setup_world();

        $this->redirectMessages();
        $first = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'The first ask',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $first->id, (int) $manager->id);
        tickets::close(
            $activity,
            (int) $first->id,
            tickets::STATUS_DECLINED,
            'Not this time',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        // Only now is a second one of the same type allowed: the
        // duplicate guard spans open and claimed.
        $second = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Asking again',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $mine = array_values(tickets::mine($activity, (int) $member->id));
        $this->assertCount(2, $mine);
        $this->assertSame((int) $second->id, (int) $mine[0]->id, 'the live one comes first');
        $this->assertSame((int) $first->id, (int) $mine[1]->id, 'the closed one follows it');
    }

    /**
     * The requester can take back an open request, and cannot take back
     * one that is claimed or somebody else's.
     *
     * MUTATION CAUGHT (run 2026-08-14): removing the requestedby test
     * from withdraw() lets the guide withdraw the member's request and
     * the second refusal assertion fails.
     */
    public function test_a_requester_can_withdraw_their_own_open_request(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, , $member, $guide, $manager] = $this->setup_world();

        $this->redirectMessages();
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );

        // Not somebody else's to take back, even a guide of the group.
        try {
            tickets::withdraw($activity, (int) $ticket->id, (int) $guide->id);
            $this->fail('Expected refusalticketnotyours');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketnotyours', $e->errorcode);
        }

        // The requester's own, while it is still open: allowed.
        $withdrawn = tickets::withdraw($activity, (int) $ticket->id, (int) $member->id);
        $this->assertSame(tickets::STATUS_WITHDRAWN, $withdrawn->status);
        $rows = tickets::mine($activity, (int) $member->id);
        $this->assertSame(
            tickets::STATUS_WITHDRAWN,
            reset($rows)->status,
            'the page shows it withdrawn'
        );

        // Once claimed it is somebody\'s work in progress.
        $second = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Asking again',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $second->id, (int) $manager->id);
        try {
            tickets::withdraw($activity, (int) $second->id, (int) $member->id);
            $this->fail('Expected refusalticketclaimed');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketclaimed', $e->errorcode);
        }
    }

    /**
     * The landing page offers the way in only to somebody who has asked
     * for something.
     *
     * myrequests.php admits every participant, so an unconditional
     * button would put a link to an empty page in front of every
     * student - the NAV-02 mistake of 1.20.5, which drew the join-request
     * button for viewers the page then refused.
     */
    public function test_the_landing_page_offers_my_requests_only_to_a_requester(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, $leader, $member] = $this->setup_world();
        $PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $before = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $member->id
        ))->export_for_template($output);
        $this->assertFalse($before->hasmyrequests, 'nothing asked for, no button');

        $this->redirectMessages();
        tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $after = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $member->id
        ))->export_for_template($output);
        $this->assertTrue($after->hasmyrequests, 'the requester is offered the way in');
        $this->assertStringContainsString('myrequests.php', $after->myrequestsurl);

        // The leader filed nothing, so the leader is offered nothing -
        // the button follows the row, not the role.
        $others = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $leader->id
        ))->export_for_template($output);
        $this->assertFalse($others->hasmyrequests);
    }

    /**
     * 1.20.53 deliverable B: the "My requests" panel now STATES THE
     * POSITION ("My requests (N)") and highlights a needsinfo row - built
     * from a requester with TWO live tickets, one needsinfo and one
     * plain open, so the two counts could not pass by being equal to
     * each other or to the row total.
     */
    public function test_the_landing_panel_states_the_requesters_position_and_highlights_needsinfo(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, , $member, , $manager] = $this->setup_world();
        $PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $this->redirectMessages();
        $waiting = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $waiting->id, (int) $manager->id);
        tickets::request_info($activity, (int) $waiting->id, 'Since when?', FORMAT_PLAIN, (int) $manager->id);
        // A second, unrelated live ticket from the same requester - open,
        // not needsinfo - so the fixture genuinely has more than one row.
        tickets::file_help($activity, $group, 'A separate question entirely', FORMAT_PLAIN, (int) $member->id);

        $data = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $member->id
        ))->export_for_template($output);

        $this->assertTrue($data->hasmyrequests);
        $this->assertSame(2, $data->myrequestcount, 'both live tickets must be counted');
        $this->assertSame(
            get_string('myrequestscount', 'mod_selfselectadvanced', 2),
            $data->myrequestslabel
        );
        $this->assertTrue($data->hasmyrequestsneedsinfo);
        $this->assertSame(1, $data->myrequestsneedsinfocount, 'only the needsinfo ticket, not both');
        $this->assertSame(
            get_string('myrequestsneedsreply', 'mod_selfselectadvanced', 1),
            $data->myrequestsneedsinfoline
        );

        // A requester with nothing outstanding gets no highlight, even
        // though they DO have a live ticket - proving the highlight is
        // conditioned on needsinfo, not merely on hasmyrequests.
        tickets::provide_info($activity, (int) $waiting->id, 'Since Tuesday.', FORMAT_PLAIN, (int) $member->id);
        $answered = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $member->id
        ))->export_for_template($output);
        $this->assertTrue($answered->hasmyrequests);
        $this->assertFalse($answered->hasmyrequestsneedsinfo, 'the requester already replied - nothing to highlight');
    }

    /**
     * 1.20.53 deliverable B: a queue-authority viewer gets a DIRECT route
     * carrying the number waiting (open, unclaimed) and the number they
     * are handling (claimed by them); deliverable C folds in how many of
     * those are actually waiting on THIS claimant's next move. Built
     * from two live tickets on two different requesters so neither
     * figure could pass by counting the same row twice or by coincidence
     * with the total.
     */
    public function test_the_landing_panel_gives_queue_authority_a_direct_route_with_counts(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, $leader, $member, $guide, $manager, $coordinator] = $this->setup_world();
        $PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $this->redirectMessages();
        // Stays open and unclaimed - the "waiting" figure.
        tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a different mix',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        // Claimed by the coordinator, then answered - "handling", and
        // within it, "waiting on your reply".
        $handled = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $handled->id, (int) $coordinator->id);
        tickets::request_info($activity, (int) $handled->id, 'Since when?', FORMAT_PLAIN, (int) $coordinator->id);
        tickets::provide_info($activity, (int) $handled->id, 'Since Tuesday.', FORMAT_PLAIN, (int) $member->id);
        // A SECOND ticket the coordinator holds and NOBODY has replied
        // to. Without it "handling" and "waiting on your reply" were
        // both 1 for the only viewer where either was non-zero, so
        // wiring the second figure to handling_count() - a one-word slip
        // between two adjacent calls in the exporter - passed every
        // assertion while telling a coordinator to go hunting for
        // replies that do not exist. Two must not be able to read as
        // one.
        $alsohandled = tickets::file(
            $activity,
            $group,
            tickets::TYPE_DATES,
            'We need the deadline moved',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $alsohandled->id, (int) $coordinator->id);

        $coorddata = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $coordinator->id
        ))->export_for_template($output);
        $this->assertTrue($coorddata->hasqueueauthority);
        $this->assertStringContainsString('tickets.php', $coorddata->directticketsurl);
        $this->assertSame(1, $coorddata->ticketswaitingcount);
        $this->assertTrue($coorddata->highlightticketswaiting);
        // TWO handled, ONE of them awaiting this claimant's reply: the
        // two figures must be numerically different, or neither is
        // proven to come from its own query.
        $this->assertSame(2, $coorddata->tickethandlingcount);
        $this->assertTrue($coorddata->hastickethandlingneedingreply);
        $this->assertSame(1, $coorddata->tickethandlingneedingreplycount);
        $this->assertNotSame(
            $coorddata->tickethandlingcount,
            $coorddata->tickethandlingneedingreplycount,
            'the two landing figures must be distinguishable, or a mis-wiring between them is invisible'
        );
        $this->assertSame(
            get_string('tickethandlingneedsreply', 'mod_selfselectadvanced', 1),
            $coorddata->tickethandlingneedingreplyline
        );

        // The manager also holds queue authority but claimed nothing:
        // the SAME waiting figure (it is a fact about the queue), a
        // DIFFERENT (zero) handling figure - counts are per-viewer, not
        // shared state.
        $managerdata = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $manager->id
        ))->export_for_template($output);
        $this->assertTrue($managerdata->hasqueueauthority);
        $this->assertSame(1, $managerdata->ticketswaitingcount);
        $this->assertSame(0, $managerdata->tickethandlingcount);
        $this->assertFalse($managerdata->hastickethandlingneedingreply);

        // A plain student holds no queue authority at all: the panel is
        // absent entirely, proven against a fixture that genuinely has
        // live tickets waiting.
        $leaderdata = (new \mod_selfselectadvanced\output\landing(
            new \mod_selfselectadvanced\local\api($activity),
            (int) $leader->id
        ))->export_for_template($output);
        $this->assertFalse($leaderdata->hasqueueauthority);
    }
}
