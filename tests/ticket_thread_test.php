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
 * Slice B2 - the forum-style ticket thread (UI half): the thread's
 * access rule (tickets::may_view_thread()), tickets::history() (the
 * maintainer's repeated-request blocker), tickets::mine()'s needsinfo
 * ordering (addendum item 2), the thread-URL notifications now carry
 * (addendum item 1/deliverable 3, pinned again here alongside
 * message_placeholder_contract_test.php's end-to-end version), and the
 * event payload bar the addendum requires (relateduserid, and
 * other = {action, type, groupid, ticketlogid}) on claim, info-requested,
 * info-provided, resolve, release and the new ticket_viewed - release
 * restored to the thread's action box after orchestrator review rejected
 * its removal as a functional regression (a claimant with no way to hand
 * a ticket back before 1.20.44's refer/escalate ladder exists).
 *
 * RED-FIRST EVIDENCE (captured 2026-08-15, PHPUnit run on m5pg against
 * this same tree with ONLY tickets::mine()'s ORDER BY temporarily
 * reverted BY HAND to its B1 shape - `WHEN 'open' THEN 0 WHEN 'claimed'
 * THEN 1 ELSE 2`, no 'needsinfo' arm at all - synced and run there; the
 * revert touched nothing else and was undone immediately after
 * capturing the failure, then re-applied and re-verified green. The
 * fixture deliberately makes the needsinfo ticket the OLDER of the two
 * (filed and asked about first) and the resolved one the NEWER (filed
 * and closed second): under B1's CASE both fall into the same ELSE
 * bucket and the timecreated DESC tiebreak alone would rank the newer,
 * resolved ticket first - the exact bug the fix corrects, not merely a
 * status label swap a same-timestamp fixture could not have told apart
 * from a passing test that got lucky on tiebreak order:
 *
 * 1) mod_selfselectadvanced\ticket_thread_test::test_mine_ranks_needsinfo_in_the_live_band
 * the OLDER needsinfo ticket must not sink behind the NEWER resolved one
 * Failed asserting that two strings are identical.
 * Expected 'needsinfo', actual 'resolved'.
 * FAILURES!
 * Tests: 1, Assertions: 2, Failures: 1.
 *
 * Green (all 6 tests in this file, 55 assertions) only after mine()'s
 * ORDER BY regained the `WHEN 'needsinfo' THEN 0` arm
 * (classes/local/tickets.php).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \mod_selfselectadvanced\event\ticket_claimed
 * @covers     \mod_selfselectadvanced\event\ticket_closed
 * @covers     \mod_selfselectadvanced\event\ticket_info_requested
 * @covers     \mod_selfselectadvanced\event\ticket_info_provided
 * @covers     \mod_selfselectadvanced\event\ticket_viewed
 */
final class ticket_thread_test extends \advanced_testcase {
    /**
     * An activity with a firm group (leader + confirmed member, guide
     * assigned), a manager, a coordinator and an uninvolved student who
     * has nothing to do with the group at all. Shaped like
     * ticket_trail_test.php::setup_world(), with the uninvolved student
     * added for the access-arm test.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator, outsider]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'THREAD1']);
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
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_module::instance((int) $instance->cmid));

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Threaded',
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
            $coordinator,
            $outsider,
        ];
    }

    /**
     * Expect one refusal string key from a callable.
     *
     * @param string $stringkey the expected errorcode
     * @param callable $fn the action
     */
    private function assert_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }

    /**
     * (1) ACCESS ARMS (spec deliverable 1): the requester may view, a
     * coordinator may (queue authority), an editing teacher/manager may
     * (queue authority), an uninvolved student may not, and - the arm
     * that matters most, since a group's LEADER is not automatically
     * its guide's requester - the team's own LEADER may not view a
     * ticket the GUIDE filed, leadership alone granting nothing here
     * (filing-authority changes are 1.20.43's, not this slice's).
     */
    public function test_access_arms(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide, $manager, $coordinator, $outsider] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->assertTrue(
            tickets::may_view_thread($activity, $ticket, (int) $guide->id),
            'the requester (the guide, who filed this one) must be admitted'
        );
        $this->assertTrue(
            tickets::may_view_thread($activity, $ticket, (int) $coordinator->id),
            'a coordinator holds queue authority and must be admitted'
        );
        $this->assertTrue(
            tickets::may_view_thread($activity, $ticket, (int) $manager->id),
            'an editing teacher (manage) must be admitted'
        );
        $this->assertFalse(
            tickets::may_view_thread($activity, $ticket, (int) $outsider->id),
            'a student with no connection to this ticket at all must be refused'
        );
        $this->assertFalse(
            tickets::may_view_thread($activity, $ticket, (int) $leader->id),
            "the team's LEADER is not the requester here (the guide filed it) and holds no queue authority - "
                . 'leadership alone must not grant access'
        );
    }

    /**
     * (2) history(): the requester's OTHER tickets in this activity, all
     * statuses, newest first, the current one excluded; a viewer without
     * queue authority is refused outright (never the requester - only
     * myrequests.php/mine() is theirs).
     */
    public function test_history(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator, $outsider] = $this->setup_world();

        $first = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'First ask',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::withdraw($activity, (int) $first->id, (int) $guide->id);
        // A second live one - file() refuses a duplicate compchange
        // while the first is live, so withdraw it first (above) exactly
        // as a real repeat-requester's history would read.
        $second = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Second ask, right after being told no',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $history = tickets::history($activity, (int) $guide->id, (int) $coordinator->id, (int) $second->id);
        $this->assertCount(1, $history, 'history() must return the OTHER ticket, excluding the one on screen');
        $row = reset($history);
        $this->assertSame((int) $first->id, (int) $row->id);
        $this->assertSame(tickets::STATUS_WITHDRAWN, $row->status);

        // Excluding nothing (0) returns both.
        $both = tickets::history($activity, (int) $guide->id, (int) $manager->id);
        $this->assertCount(2, $both, 'excludeticketid=0 must exclude nothing');
        $ids = array_map(static fn($r) => (int) $r->id, array_values($both));
        // Newest first: $second was filed after $first.
        $this->assertSame([(int) $second->id, (int) $first->id], $ids, 'history() must be newest first');

        // The viewer argument is checked, not the requester: an outsider
        // with no queue authority is refused outright, exactly like
        // queue()'s own door.
        $this->assert_refused(
            'nopermissions',
            fn() => tickets::history($activity, (int) $guide->id, (int) $outsider->id)
        );
    }

    /**
     * history() IS BOUNDED, AND history_count() TELLS THE TRUTH ABOUT
     * WHAT WAS LEFT OUT (1.20.60, audit L-22).
     *
     * The thread page asked for EVERY ticket a requester had ever filed
     * and rendered all of them, on every staff view of every thread -
     * unbounded work on the page staff open most. It is now a slice with
     * a count beside it, because a truncated list that does not admit it
     * reads as the whole record.
     *
     * Six tickets, a limit of two: the slice is the two NEWEST, the
     * count is the true total, and the two disagree - which is the whole
     * point, and is asserted rather than assumed.
     */
    public function test_history_is_bounded_and_the_count_states_the_total(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator] = $this->setup_world();

        // Six help tickets - the one type a requester may file
        // repeatedly (file() refuses a second live compchange).
        // Withdrawn as they go: a requester may hold only one LIVE help
        // ticket (file_help()'s own duplicate guard), and history()
        // returns every status, so a withdrawn one is exactly as much
        // history as a live one.
        $filed = [];
        for ($i = 1; $i <= 6; $i++) {
            $one = tickets::file_help($activity, $group, 'Ask number ' . $i, FORMAT_PLAIN, (int) $guide->id);
            tickets::withdraw($activity, (int) $one->id, (int) $guide->id);
            $filed[] = (int) $one->id;
        }

        $total = tickets::history_count($activity, (int) $guide->id, (int) $coordinator->id);
        $this->assertSame(6, $total, 'the count must be the true total, not the page size');

        $slice = tickets::history($activity, (int) $guide->id, (int) $coordinator->id, 0, 0, 2);
        $this->assertCount(2, $slice, 'the slice must respect its limit');
        $this->assertSame(
            [$filed[5], $filed[4]],
            array_map(static fn($r) => (int) $r->id, array_values($slice)),
            'the slice must be the NEWEST, not an arbitrary two'
        );

        // Excluding the one on screen narrows BOTH, in step - a count
        // that ignored the exclusion would over-report by one forever.
        $this->assertSame(5, tickets::history_count($activity, (int) $guide->id, (int) $coordinator->id, $filed[5]));

        // Unlimited is still unlimited: nothing here has quietly capped
        // the callers that ask for everything.
        $this->assertCount(6, tickets::history($activity, (int) $guide->id, (int) $coordinator->id));
    }

    /**
     * The page says so (1.20.60, audit L-22): with more tickets than
     * ticket_page's own cap, the export carries the truncation flag and
     * a sentence naming both numbers - and with fewer, it carries
     * neither, so the notice cannot become permanent furniture.
     */
    public function test_the_thread_page_admits_when_the_history_is_truncated(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator] = $this->setup_world();
        $output = $PAGE->get_renderer('core');

        $onscreen = tickets::file_help($activity, $group, 'The one on screen', FORMAT_PLAIN, (int) $guide->id);
        // Withdrawn so the twenty-five below can be filed at all - the
        // thread page renders a withdrawn ticket perfectly well, and
        // the history it carries is what this test is about.
        tickets::withdraw($activity, (int) $onscreen->id, (int) $guide->id);
        $ticket = tickets::get($activity, (int) $onscreen->id);
        $page = new \mod_selfselectadvanced\output\ticket_page(
            $activity,
            $ticket,
            $group,
            (int) $coordinator->id,
            false,
            true
        );
        $short = $page->export_for_template($output);
        $this->assertFalse(
            (bool) ($short->historytruncated ?? false),
            'a history that fits must not claim to be truncated'
        );

        // Now past the cap. The constant is private, so the number is
        // read from the behaviour rather than restated here: file
        // plainly more than any sane cap and assert the flag flips.
        for ($i = 1; $i <= 25; $i++) {
            $one = tickets::file_help($activity, $group, 'Ask number ' . $i, FORMAT_PLAIN, (int) $guide->id);
            tickets::withdraw($activity, (int) $one->id, (int) $guide->id);
        }
        $page = new \mod_selfselectadvanced\output\ticket_page(
            $activity,
            $ticket,
            $group,
            (int) $coordinator->id,
            false,
            true
        );
        $long = $page->export_for_template($output);
        $this->assertTrue((bool) $long->historytruncated, 'a truncated history must say so');
        $this->assertLessThan(
            25,
            count($long->history),
            'the page must be rendering a slice, not every ticket the person ever filed'
        );
        $this->assertNotEmpty($long->historymore);
        $this->assertStringContainsString(
            (string) count($long->history),
            $long->historymore,
            'the notice must name how many are shown'
        );
        $this->assertStringContainsString('25', $long->historymore, 'and how many there are in total');
    }

    /**
     * (3) tickets::mine() ranks NEEDSINFO in the live band, ahead of
     * open and claimed (addendum item 2) - never sunk to the
     * closed/withdrawn tier the way B1 left it. This is the RED-first
     * proof (see this file's docblock for the captured failure).
     */
    public function test_mine_ranks_needsinfo_in_the_live_band(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        // The OLDER ticket ends up NEEDSINFO. Filed and asked about
        // first, deliberately - its timecreated is earlier than the
        // second ticket's, so ONLY a status-aware ORDER BY can rank it
        // first; a plain "newest first" tiebreak (what both needsinfo
        // and resolved fall into under B1's CASE, which has no
        // 'needsinfo' arm at all) would rank the NEWER ticket below
        // ahead of it by recency alone, which is exactly the bug this
        // test exists to catch - see this file's docblock for the
        // captured RED run.
        $needsinfo = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Needs a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $needsinfo->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $needsinfo->id,
            'Which subject?',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        // The NEWER ticket, filed and resolved second - a different
        // type (dates), since a live compchange ticket already blocks a
        // second one for the same group.
        $resolved = tickets::file(
            $activity,
            $group,
            tickets::TYPE_DATES,
            'Old, already settled',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $resolved->id, (int) $manager->id);
        tickets::close(
            $activity,
            (int) $resolved->id,
            tickets::STATUS_RESOLVED,
            'Settled long ago',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        $mine = array_values(tickets::mine($activity, (int) $guide->id));
        $this->assertCount(2, $mine, 'fixture must hold exactly the two tickets filed above');
        $this->assertSame(
            tickets::STATUS_NEEDSINFO,
            $mine[0]->status,
            'the OLDER needsinfo ticket must not sink behind the NEWER resolved one'
        );
        $this->assertSame((int) $needsinfo->id, (int) $mine[0]->id);
        $this->assertSame(tickets::STATUS_RESOLVED, $mine[1]->status);
        $this->assertSame((int) $resolved->id, (int) $mine[1]->id);
    }

    /**
     * (4) EVENT PAYLOAD BAR (addendum item 1, maintainer: "bad players
     * who post messages can be tracked readily"): claim, request_info,
     * provide_info and resolve all carry relateduserid = the other
     * party, and other = {action, type, groupid, ticketlogid} - a
     * logged event can be joined back to the EXACT stored trail row.
     */
    public function test_event_payload_bar(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $events = $this->redirectEvents();

        // Core's \core\event\base::create() defaults 'userid' to the
        // current $USER when the caller does not set one explicitly, and none
        // of tickets.php's event sites do (they rely on the page having
        // called require_login(), so $USER already IS the $userid
        // argument by the time any of these run in production). A
        // PHPUnit test has no such page, so the acting user is switched
        // by hand before each call - the same fiction a real request's
        // session provides for free.
        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->setUser($manager);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which subject?',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->setUser($guide);
        tickets::provide_info(
            $activity,
            (int) $ticket->id,
            'Statistics.',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->setUser($manager);
        $closed = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Added Priya.',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        unset($closed);

        $byclass = static function (string $class) use ($events): array {
            return array_values(array_filter(
                $events->get_events(),
                static fn($e) => $e instanceof $class
            ));
        };

        // Every ticketlogid named below is cross-checked against the
        // ACTUAL trail row it claims to name - not merely "an int was
        // present" - so a payload that named the wrong row, or the
        // ticket's own id by mistake, would fail here.
        $trail = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $trailbyaction = [];
        foreach ($trail as $row) {
            $trailbyaction[$row->action] = $row;
        }

        $claimed = $byclass(\mod_selfselectadvanced\event\ticket_claimed::class);
        $this->assertCount(1, $claimed);
        $this->assertSame((int) $manager->id, (int) $claimed[0]->userid, 'the actor is the claimant');
        $this->assertSame((int) $guide->id, (int) $claimed[0]->relateduserid, 'relateduserid is the requester');
        $this->assertSame('claimed', $claimed[0]->other['action']);
        $this->assertSame(tickets::TYPE_COMPCHANGE, $claimed[0]->other['type']);
        $this->assertSame((int) $group->id, (int) $claimed[0]->other['groupid']);
        $this->assertSame(
            (int) $trailbyaction['claimed']->id,
            (int) $claimed[0]->other['ticketlogid'],
            'ticketlogid must name the actual claimed trail row'
        );

        $requested = $byclass(\mod_selfselectadvanced\event\ticket_info_requested::class);
        $this->assertCount(1, $requested);
        $this->assertSame((int) $manager->id, (int) $requested[0]->userid, 'the actor is the claimant asking');
        $this->assertSame((int) $guide->id, (int) $requested[0]->relateduserid, 'relateduserid is the requester being asked');
        $this->assertSame('needsinfo', $requested[0]->other['action']);
        $this->assertSame(
            (int) $trailbyaction['needsinfo']->id,
            (int) $requested[0]->other['ticketlogid']
        );

        $provided = $byclass(\mod_selfselectadvanced\event\ticket_info_provided::class);
        $this->assertCount(1, $provided);
        $this->assertSame((int) $guide->id, (int) $provided[0]->userid, 'the actor is the requester replying');
        $this->assertSame((int) $manager->id, (int) $provided[0]->relateduserid, 'relateduserid is the claimant who asked');
        $this->assertSame('inforeply', $provided[0]->other['action']);
        $this->assertSame(
            (int) $trailbyaction['inforeply']->id,
            (int) $provided[0]->other['ticketlogid']
        );

        $closedevents = $byclass(\mod_selfselectadvanced\event\ticket_closed::class);
        $this->assertCount(1, $closedevents, 'only the final resolve should have fired ticket_closed here');
        $this->assertSame((int) $manager->id, (int) $closedevents[0]->userid);
        $this->assertSame((int) $guide->id, (int) $closedevents[0]->relateduserid, 'relateduserid is the requester');
        $this->assertSame('resolved', $closedevents[0]->other['action']);
        $this->assertSame('resolved', $closedevents[0]->other['outcome']);
        $this->assertSame(
            (int) $trailbyaction['resolved']->id,
            (int) $closedevents[0]->other['ticketlogid']
        );

        // Every ticketlogid actually exists in selfselectadvanced_ticketlog
        // and points at THIS ticket - not merely a plausible-looking int.
        foreach ([$claimed[0], $requested[0], $provided[0], $closedevents[0]] as $event) {
            $logrow = $DB->get_record('selfselectadvanced_ticketlog', ['id' => $event->other['ticketlogid']]);
            $this->assertNotFalse($logrow, 'ticketlogid must name a row that exists');
            $this->assertSame((int) $ticket->id, (int) $logrow->ticketid);
        }
    }

    /**
     * (5) THE CLAIMANT'S RELEASE, restored after orchestrator review
     * (2026-08-15): removing this affordance from the thread was
     * REJECTED as a functional regression - until the 1.20.44
     * refer/escalate ladder exists, a claimant who cannot handle a
     * ticket has no other way to hand it back, and it would rot under
     * their claim until a manager happened to notice. ticket.php's
     * 'release' action reuses close()'s existing open outcome VERBATIM
     * (empty resolution, FORMAT_PLAIN - no new service logic), so this
     * drives that exact call and asserts: the ticket returns to OPEN
     * with claimedby/timeclaimed cleared, a 'released' trail row is
     * logged, and ticket_closed fires with the full payload bar. Proved
     * from BOTH statuses close() actually permits it from (claimed and
     * needsinfo, decision 2 LIVENESS) - the button appears in both on
     * the thread, and both must genuinely work, not merely render.
     */
    public function test_claimant_release(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        // Arm 1: release from CLAIMED.
        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->setUser($manager);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);

        $events = $this->redirectEvents();
        // The EXACT call ticket.php's 'release' action makes.
        $released = tickets::close($activity, (int) $ticket->id, tickets::STATUS_OPEN, '', FORMAT_PLAIN, (int) $manager->id);

        $this->assertSame(tickets::STATUS_OPEN, $released->status, 'release must return the ticket to open');
        $this->assertNull($released->claimedby, 'the claim itself must be cleared, not merely the status');
        $this->assertNull($released->timeclaimed);

        $trail = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $this->assertSame('released', end($trail)->action, 'the hand-back must be logged in the trail');
        $this->assertSame((int) $manager->id, (int) end($trail)->actorid);
        $this->assertNull(end($trail)->note, 'a bare release carries no note');

        $closedevents = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_closed
        ));
        $this->assertCount(1, $closedevents);
        $this->assertSame((int) $manager->id, (int) $closedevents[0]->userid);
        $this->assertSame((int) $guide->id, (int) $closedevents[0]->relateduserid, 'relateduserid is the requester');
        $this->assertSame('released', $closedevents[0]->other['action']);
        $this->assertSame('open', $closedevents[0]->other['outcome']);
        $this->assertSame((int) $group->id, (int) $closedevents[0]->other['groupid']);
        $logrow = $DB->get_record('selfselectadvanced_ticketlog', ['id' => $closedevents[0]->other['ticketlogid']]);
        $this->assertNotFalse($logrow, 'ticketlogid must name a row that exists');
        $this->assertSame((int) $ticket->id, (int) $logrow->ticketid);
        $this->assertSame('released', $logrow->action);

        // Released means OPEN, not gone: the duplicate guard (file())
        // treats open exactly as it treats claimed/needsinfo, so a
        // second compchange ticket for the same group must still be
        // refused - release reopens the queue slot for a NEW worker to
        // pick up, not a fresh request the guide could file instead.
        $this->assert_refused('refusalticketduplicate', fn() => tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'A second, contradictory request',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        // Arm 2: release from NEEDSINFO - close() allows this too
        // (decision 2: "release from needsinfo is allowed by the same
        // widening rather than singled out"), and the thread's button
        // appears for both, so both must actually work.
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which subject?',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticket->id], '*', MUST_EXIST);
        $this->assertSame(tickets::STATUS_NEEDSINFO, $fresh->status, 'fixture must actually be needsinfo before this arm');

        $releasedfromneedsinfo = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_OPEN,
            '',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->assertSame(
            tickets::STATUS_OPEN,
            $releasedfromneedsinfo->status,
            'close() must accept the open outcome from needsinfo too - the thread offers the button in both states'
        );
    }

    /**
     * A source pin (the ticket_richtext_test.php idiom) confirming
     * ticket.php's 'release' action actually calls close() with the open
     * outcome - the exact call test_claimant_release() above exercises -
     * rather than some other, untested path.
     */
    public function test_ticket_php_release_action_calls_close_with_open_outcome(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);
        $source = file_get_contents($root . '/ticket.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            "tickets::close(\$activity, \$t, tickets::STATUS_OPEN, '', FORMAT_PLAIN, (int) \$USER->id)",
            $source,
            "ticket.php's release action must call close() with the open outcome, reusing the queue's old call shape"
        );
    }

    /**
     * (6) ticket_viewed (deliverable 4: mirrors mod_forum's
     * discussion_viewed pattern). PHPUnit cannot execute ticket.php
     * end-to-end (require_login()/redirect()/echo $OUTPUT->header(), the
     * same limitation ticket_richtext_test.php's docblock states for the
     * other queue pages), so this drives the EVENT CLASS itself the way
     * ticket.php's view arm builds it, and a source pin (below) confirms
     * the page actually fires it.
     */
    public function test_ticket_viewed_event(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $events = $this->redirectEvents();
        \mod_selfselectadvanced\event\ticket_viewed::create([
            'objectid' => (int) $ticket->id,
            'context' => $activity->context(),
            'other' => ['type' => $ticket->type],
        ])->trigger();

        $viewed = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_viewed
        ));
        $this->assertCount(1, $viewed);
        $this->assertSame((int) $ticket->id, (int) $viewed[0]->objectid);
        $this->assertSame('r', $viewed[0]->crud);
        $this->assertNotSame('', trim(\mod_selfselectadvanced\event\ticket_viewed::get_name()));
        $this->assertStringContainsString('t=' . $ticket->id, $viewed[0]->get_url()->out(false));
    }

    /**
     * A source pin (the ticket_richtext_test.php idiom, for the same
     * reason: PHPUnit cannot run ticket.php end-to-end) confirming the
     * page actually fires ticket_viewed on its view path.
     */
    public function test_ticket_php_fires_the_view_event(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);
        $source = file_get_contents($root . '/ticket.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            '\mod_selfselectadvanced\event\ticket_viewed::create(',
            $source,
            'ticket.php must fire ticket_viewed on its view path'
        );
    }

    /**
     * (7) THE WHOSE-MOVE LINE, "claimed, and the last trail row is the
     * requester's inforeply" (1.20.54 deliverable A, bullet 2). This is
     * the SAME viewer-dependent wording tickets.php's queue already
     * carries (1.20.53: "Waiting on you" to the claimant, the
     * third-person form to any other staff) - now also on the thread
     * itself, plus the requester's own version, which is new: the
     * requester never saw ANY whose-move text before this release.
     *
     * REUSES the 1.20.53 derivation from the trail this page has
     * ALREADY FETCHED (export_for_template()'s own loop) - never a
     * second query, never awaiting_claimant_ids() asked for a single
     * ticket. See ticket_page::whose_move_claimed_line()'s own docblock.
     *
     * RED-FIRST (captured 2026-08-20, PHPUnit run on m5pg against the
     * tree at 7bf9da5, before 'wholine' existed on the exported object
     * at all): see this file's release report for the exact quoted
     * failure - all three assertions below failed with "Undefined
     * property" warnings (--fail-on-warning) because export_for_template()
     * returned no 'wholine' key whatsoever.
     */
    public function test_whose_move_line_claimed_awaiting_reply_by_role(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $manager->id);
        tickets::provide_info($activity, (int) $ticket->id, 'Statistics.', FORMAT_PLAIN, (int) $guide->id);

        $fresh = tickets::get($activity, (int) $ticket->id);
        $output = $PAGE->get_renderer('core');

        // The claimant view renders the resolve/decline forms
        // (showclaimantforms), which go through
        // render_ticketpost_form() -> file_prepare_draft_area() - real
        // Moodle machinery that needs $USER to be a genuine, existing
        // user for its own draft-area user context, exactly like
        // test_event_payload_bar()'s setUser() calls above provide the
        // same fiction require_login() gives a real request for free.
        // set_url() is the matching fiction for $PAGE - ticket.php calls
        // it before ever rendering (PAGE->set_url($baseurl) at the top
        // of the file), and the editor element's own tiny_autosave
        // plugin reads $PAGE->url while rendering, which is exactly what
        // this test is now the first in this class to actually do.
        $this->setUser($manager);
        $PAGE->set_url(new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => (int) $fresh->id]));

        // The claimant: told it is their move.
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $manager->id, false, true);
        $this->assertSame(
            get_string('ticketawaitingclaimant', 'mod_selfselectadvanced'),
            $page->export_for_template($output)->wholine,
            'the claimant must be told it is their move'
        );

        // A different coordinator, holding queue authority but not this
        // claim: told what happened, never "waiting on you" about a
        // ticket they cannot comment on and cannot take up.
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $coordinator->id, false, true);
        $this->assertSame(
            get_string('ticketawaitingclaimantother', 'mod_selfselectadvanced'),
            $page->export_for_template($output)->wholine,
            'a non-claimant coordinator must be told what happened, not told it is their move'
        );

        // The requester, who just replied: told the reply landed, and
        // that it is now waiting on whoever is handling this - never
        // named, and never the second-person "Waiting on you" wording,
        // which is the claimant's alone.
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $guide->id, true, false);
        $this->assertSame(
            get_string('ticketwhosemovereplysent', 'mod_selfselectadvanced'),
            $page->export_for_template($output)->wholine,
            'the requester must be told their reply landed and it is now waiting on whoever holds this'
        );
    }

    /**
     * (8) THE WHOSE-MOVE LINE across every OTHER status (1.20.54
     * deliverable A, bullets 1, 3-4): open ("nobody has picked this up
     * yet", identical for every viewer), needsinfo (staff read "waiting
     * on the requester"; the requester reads that THEIR OWN reply is
     * needed - reusing myrequests.php's own ticketthreadneedsinfohint
     * string verbatim so the two surfaces never disagree), and
     * resolved/declined/withdrawn ("closed, and when").
     */
    public function test_whose_move_line_every_other_status(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();
        $output = $PAGE->get_renderer('core');
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');
        // See the claimant-view comment in the previous test - the
        // NEEDSINFO arm below renders the claimant's resolve/decline
        // forms, which need a genuine current user and a $PAGE url.
        $this->setUser($manager);
        $PAGE->set_url(new \moodle_url('/mod/selfselectadvanced/ticket.php'));

        // OPEN. Re-fetched via tickets::get() rather than used as
        // returned from file() - the same MUST_EXIST full row every
        // other call site in this class works from (file()'s own return
        // value carries only the columns it explicitly set, not
        // claimedby/escalated/etc., which a real request never sees
        // because ticket.php always loads through tickets::get() first).
        $open = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Ask 1', FORMAT_PLAIN, (int) $guide->id);
        $open = tickets::get($activity, (int) $open->id);
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $open, $group, (int) $manager->id, false, true);
        $this->assertSame(
            get_string('ticketwhosemoveopen', 'mod_selfselectadvanced'),
            $page->export_for_template($output)->wholine
        );

        // NEEDSINFO, both audiences on the SAME ticket.
        tickets::claim($activity, (int) $open->id, (int) $manager->id);
        tickets::request_info($activity, (int) $open->id, 'Which subject?', FORMAT_PLAIN, (int) $manager->id);
        $needsinfo = tickets::get($activity, (int) $open->id);
        $staffpage = new \mod_selfselectadvanced\output\ticket_page($activity, $needsinfo, $group, (int) $manager->id, false, true);
        $this->assertSame(
            get_string('ticketstatusneedsinfo', 'mod_selfselectadvanced'),
            $staffpage->export_for_template($output)->wholine
        );
        $reqpage = new \mod_selfselectadvanced\output\ticket_page($activity, $needsinfo, $group, (int) $guide->id, true, false);
        $this->assertSame(
            get_string('ticketthreadneedsinfohint', 'mod_selfselectadvanced'),
            $reqpage->export_for_template($output)->wholine,
            'the requester must read that THEIR OWN reply is needed, the same string myrequests.php already shows'
        );

        // RESOLVED.
        tickets::provide_info($activity, (int) $open->id, 'Statistics.', FORMAT_PLAIN, (int) $guide->id);
        $resolved = tickets::close($activity, (int) $open->id, tickets::STATUS_RESOLVED, 'Done.', FORMAT_PLAIN, (int) $manager->id);
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $resolved, $group, (int) $manager->id, false, true);
        $this->assertSame(
            get_string('ticketwhosemoveresolved', 'mod_selfselectadvanced', userdate((int) $resolved->timemodified, $dateformat)),
            $page->export_for_template($output)->wholine
        );

        // DECLINED - a fresh ticket type on the same group (compchange is
        // already live-then-closed above; dates is still free).
        $declined = tickets::file($activity, $group, tickets::TYPE_DATES, 'Ask 2', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $declined->id, (int) $manager->id);
        $declined = tickets::close(
            $activity,
            (int) $declined->id,
            tickets::STATUS_DECLINED,
            'No.',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $declined, $group, (int) $manager->id, false, true);
        $this->assertSame(
            get_string('ticketwhosemovedeclined', 'mod_selfselectadvanced', userdate((int) $declined->timemodified, $dateformat)),
            $page->export_for_template($output)->wholine
        );

        // WITHDRAWN.
        $withdrawn = tickets::file($activity, $group, tickets::TYPE_PENALTY, 'Ask 3', FORMAT_PLAIN, (int) $guide->id);
        tickets::withdraw($activity, (int) $withdrawn->id, (int) $guide->id);
        $withdrawn = tickets::get($activity, (int) $withdrawn->id);
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $withdrawn, $group, (int) $guide->id, true, false);
        $this->assertSame(
            get_string('ticketwhosemovewithdrawn', 'mod_selfselectadvanced', userdate((int) $withdrawn->timemodified, $dateformat)),
            $page->export_for_template($output)->wholine
        );
    }

    /**
     * (9) THE REQUESTER'S HANDLED-BY LINE (1.20.54 deliverable A's last
     * bullet): showclaimedbyline used to fire only for staff who were
     * not the claimant - "the requester ... is the one viewer told
     * nothing". Extended here to the requester too, anonymised - the
     * SAME string myrequests.php already shows for a claimed row
     * (myrequestsclaimedhint), never the claimant's name. The
     * non-claimant staff line and the claimant's own silence are pinned
     * unchanged alongside it, so a fix that widened the predicate too
     * far (e.g. naming the claimant to the requester too) would be
     * caught here as surely as one that widened it not at all.
     */
    public function test_requester_gets_the_anonymised_handled_by_line(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $fresh = tickets::get($activity, (int) $ticket->id);
        $output = $PAGE->get_renderer('core');

        // The requester: extended, anonymised.
        $reqpage = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $guide->id, true, false);
        $reqexported = $reqpage->export_for_template($output);
        $this->assertTrue($reqexported->showclaimedbyline, 'the requester must now be told somebody is handling it');
        $this->assertSame(get_string('myrequestsclaimedhint', 'mod_selfselectadvanced'), $reqexported->claimedbyline);
        $this->assertStringNotContainsString((string) fullname($manager), $reqexported->claimedbyline);

        // Unchanged: a non-claimant coordinator still gets the NAMED line.
        $staffpage = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $coordinator->id, false, true);
        $staffexported = $staffpage->export_for_template($output);
        $this->assertTrue($staffexported->showclaimedbyline);
        $this->assertSame(
            get_string('ticketthreadclaimedby', 'mod_selfselectadvanced', fullname($manager)),
            $staffexported->claimedbyline
        );

        // Unchanged: the claimant themself still gets nothing here - they
        // already hold it, and telling them so would be pointless. This
        // view renders the resolve/decline forms (showclaimantforms), so
        // it needs the same $USER/$PAGE fiction the earlier tests do.
        $this->setUser($manager);
        $PAGE->set_url(new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => (int) $fresh->id]));
        $claimantpage = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $manager->id, false, true);
        $this->assertFalse($claimantpage->export_for_template($output)->showclaimedbyline);
    }

    /**
     * (10) THE MESSAGE/EVENT SPLIT (1.20.54 deliverable B): a trail row
     * that carries a note is a POST - the actor surfaced as ITS OWN
     * FIELD, 'actorlabel', not left reachable only by parsing
     * actiontext's sentence - and a row with no note is a compact EVENT
     * (hasnote false). Presentation only: trail() itself, and who sees
     * which rows, are unchanged - this asserts the EXPORTED DATA, which
     * is exactly what the template branches on.
     */
    public function test_message_event_split_in_exported_entries(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $manager->id);
        tickets::provide_info($activity, (int) $ticket->id, 'Statistics.', FORMAT_PLAIN, (int) $guide->id);

        $fresh = tickets::get($activity, (int) $ticket->id);
        $output = $PAGE->get_renderer('core');
        // The claimant view renders the resolve/decline forms - see the
        // matching comment on test_whose_move_line_claimed_awaiting_reply_by_role().
        $this->setUser($manager);
        $PAGE->set_url(new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => (int) $fresh->id]));
        $page = new \mod_selfselectadvanced\output\ticket_page($activity, $fresh, $group, (int) $manager->id, false, true);
        $entries = $page->export_for_template($output)->entries;
        $this->assertCount(4, $entries, 'filed, claimed, needsinfo, inforeply');

        // Filed - event, no note, actor is the requester.
        $this->assertObjectHasProperty('actorlabel', $entries[0]);
        $this->assertFalse($entries[0]->hasnote);
        $this->assertSame(fullname($guide), $entries[0]->actorlabel);

        // Claimed - event, no note, actor is the claimant.
        $this->assertFalse($entries[1]->hasnote);
        $this->assertSame(fullname($manager), $entries[1]->actorlabel);

        // Needsinfo - a POST: the claimant's question, actor in its own field.
        $this->assertTrue($entries[2]->hasnote);
        $this->assertSame(fullname($manager), $entries[2]->actorlabel);
        $this->assertStringContainsString('Which subject?', $entries[2]->note);

        // Inforeply - a POST: the requester's answer.
        $this->assertTrue($entries[3]->hasnote);
        $this->assertSame(fullname($guide), $entries[3]->actorlabel);
        $this->assertStringContainsString('Statistics.', $entries[3]->note);

        // Actiontext keeps working for the event lines (unchanged strings,
        // spec: "Keep actiontext working for the event lines").
        $this->assertSame(
            get_string('threadentryfiled', 'mod_selfselectadvanced', fullname($guide)),
            $entries[0]->actiontext
        );
        $this->assertSame(
            get_string('threadentryclaimed', 'mod_selfselectadvanced', fullname($manager)),
            $entries[1]->actiontext
        );
    }

    /**
     * (11) ABSENCE PROOF (spec: "prove absence too"): a requester's
     * WHOLE exported page, serialised, must not contain a genuinely
     * claimed, genuinely asked, genuinely answered ticket's staff
     * member's fullname ANYWHERE - not merely in the specific fields
     * this slice touches. A distinctive name, used nowhere else in this
     * fixture, rules out an accidental substring match.
     *
     * MUTATION CAUGHT (report quotes the run): export_entry()'s
     * anonymised branch temporarily changed to fall through to the
     * staff branch's `$row->actorname` regardless of $withactors -
     * reproducing exactly the kind of leak this test exists to catch.
     * Reverted immediately after capturing the failure.
     */
    public function test_requester_export_contains_no_staff_fullname_anywhere(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide] = $this->setup_world();
        $staffuser = $this->getDataGenerator()->create_user(['firstname' => 'Priyanka', 'lastname' => 'Handlerova']);
        $this->getDataGenerator()->enrol_user((int) $staffuser->id, $activity->courseid(), 'editingteacher');

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $staffuser->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $staffuser->id);
        tickets::provide_info($activity, (int) $ticket->id, 'Statistics.', FORMAT_PLAIN, (int) $guide->id);

        $output = $PAGE->get_renderer('core');

        // Snapshot 1: STILL CLAIMED, and genuinely asked and answered -
        // the spec's own words, and the exact state that exercises BOTH
        // new surfaces at once: whose_move_line()'s claimed-awaiting-reply
        // branch (the last trail row is the requester's own inforeply)
        // AND the extended claimedbyline. A snapshot taken only after
        // resolving would never even reach either branch's anonymised
        // else-arm (showclaimedbyline's predicate requires claimed or
        // needsinfo), so this ordering is deliberate, not incidental.
        $claimedfresh = tickets::get($activity, (int) $ticket->id);
        $claimedpage = new \mod_selfselectadvanced\output\ticket_page(
            $activity,
            $claimedfresh,
            $group,
            (int) $guide->id,
            true,
            false
        );
        $claimedhaystack = json_encode($claimedpage->export_for_template($output));
        $this->assertIsString($claimedhaystack);
        $this->assertStringNotContainsString(
            fullname($staffuser),
            $claimedhaystack,
            'a requester must never learn a staff name anywhere on this page, even from a genuinely claimed and answered ticket'
        );
        $this->assertStringNotContainsString('Priyanka', $claimedhaystack);
        $this->assertStringNotContainsString('Handlerova', $claimedhaystack);

        // Snapshot 2: resolved, for good measure - the closing note and
        // the resolved-and-when whose-move line are new surfaces too.
        $resolvedfresh = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Added a specialist.',
            FORMAT_PLAIN,
            (int) $staffuser->id
        );
        $resolvedpage = new \mod_selfselectadvanced\output\ticket_page(
            $activity,
            $resolvedfresh,
            $group,
            (int) $guide->id,
            true,
            false
        );
        $resolvedhaystack = json_encode($resolvedpage->export_for_template($output));
        $this->assertIsString($resolvedhaystack);
        $this->assertStringNotContainsString(fullname($staffuser), $resolvedhaystack);
        $this->assertStringNotContainsString('Priyanka', $resolvedhaystack);
        $this->assertStringNotContainsString('Handlerova', $resolvedhaystack);
    }
}
