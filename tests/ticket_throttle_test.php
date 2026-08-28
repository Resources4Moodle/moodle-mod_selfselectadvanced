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
use mod_selfselectadvanced\local\throttle;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\local\workflow_refusal;

/**
 * Request limits (1.20.60; maintainer instruction 2026-08-27: "To
 * prevent the support ticket system from being flooded, we should have
 * a mechanism where the agent (Teacher or group coordinator) can
 * initiate a throttle (number of tickets per + wait till before next
 * ticket)").
 *
 * The rules this file holds still:
 *   - a limit is STAFF-INITIATED and PER PERSON: only queue authority
 *     may set one, and nobody is limited by default;
 *   - both halves of the instruction work, alone or together - a count
 *     per rolling window, and a wait until a moment;
 *   - the reason is required, because the requester is quoted it;
 *   - a limit that limits nothing is refused rather than stored;
 *   - STAFF cannot be throttled;
 *   - THE COUNT INCLUDES WITHDRAWN AND DECLINED REQUESTS. This is the
 *     one that matters: counting only live tickets would let somebody
 *     withdraw their way back to an empty allowance, which is precisely
 *     the flood the instruction is about;
 *   - and the limit is asked at EVERY filing door, not just the one
 *     that was easiest to patch.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\throttle
 */
final class ticket_throttle_test extends \advanced_testcase {
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
     * Nobody is limited until somebody says so, and the stored row is
     * exactly what was asked for.
     */
    public function test_a_limit_is_stored_only_once_staff_set_one(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff] = $this->scene();

        $this->assertNull(throttle::get($activity, (int) $leader->id), 'nobody is limited by default');
        $this->assertSame([], throttle::all($activity));

        $row = throttle::set(
            $activity,
            (int) $leader->id,
            3,
            24,
            null,
            'Please use one request per issue.',
            (int) $staff->id
        );

        $this->assertSame(3, (int) $row->maxtickets);
        $this->assertSame(24, (int) $row->windowhours);
        $this->assertNull($row->nextallowed);
        $this->assertSame((int) $staff->id, (int) $row->setby);
        $this->assertNotNull(throttle::get($activity, (int) $leader->id));
        $this->assertCount(1, throttle::all($activity));
    }

    /**
     * Setting a limit twice REPLACES it. The unique index on
     * (activityid, userid) is what makes "one limit per person" a fact
     * about the database rather than a promise in a method, and this is
     * the test that would go red if set() ever inserted instead.
     */
    public function test_setting_a_limit_twice_replaces_it(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff] = $this->scene();

        $first = throttle::set($activity, (int) $leader->id, 3, 24, null, 'One per issue.', (int) $staff->id);
        $second = throttle::set($activity, (int) $leader->id, 1, 48, null, 'Slower still.', (int) $staff->id);

        $this->assertSame((int) $first->id, (int) $second->id, 'the same row is updated, never a second one inserted');
        $this->assertCount(1, throttle::all($activity));
        $this->assertSame(1, (int) throttle::get($activity, (int) $leader->id)->maxtickets);
        $this->assertSame(48, (int) throttle::get($activity, (int) $leader->id)->windowhours);
    }

    /**
     * Only queue authority may set or lift a limit. A student cannot
     * un-limit themself, which is the obvious attack on a rate limit.
     */
    public function test_a_student_may_neither_set_nor_lift_a_limit(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff] = $this->scene();
        throttle::set($activity, (int) $leader->id, 1, 24, null, 'One a day.', (int) $staff->id);

        try {
            throttle::clear($activity, (int) $leader->id, (int) $leader->id);
            $this->fail('a student must not be able to lift their own limit');
        } catch (\required_capability_exception $e) {
            $this->assertNotNull(throttle::get($activity, (int) $leader->id), 'the limit must still stand');
        }

        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $activity->courseid(), 'student');
        $this->expectException(\required_capability_exception::class);
        throttle::set($activity, (int) $other->id, 1, 24, null, 'Mine now.', (int) $leader->id);
    }

    /**
     * Staff are not throttled: their filings are their work, and one
     * coordinator restraining another is an authority nobody granted.
     */
    public function test_staff_cannot_be_throttled(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $staff] = $this->scene();

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');

        $this->expectException(workflow_refusal::class);
        $this->expectExceptionMessage(get_string('refusalthrottlestaff', 'mod_selfselectadvanced'));
        throttle::set($activity, (int) $manager->id, 1, 24, null, 'Too many.', (int) $staff->id);
    }

    /**
     * The shapes that are refused rather than stored: no reason, a
     * negative count, a window outside the allowed range, a wait that
     * has already passed, and a limit that limits nothing at all.
     *
     * Each is checked to have stored NOTHING - a refusal that half-
     * applied would be worse than none.
     */
    public function test_meaningless_limits_are_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff] = $this->scene();
        $id = (int) $leader->id;
        $by = (int) $staff->id;

        $cases = [
            'refusalthrottlereason' => fn() => throttle::set($activity, $id, 3, 24, null, '   ', $by),
            'refusalthrottlenegative' => fn() => throttle::set($activity, $id, -1, 24, null, 'Why.', $by),
            'refusalthrottleempty' => fn() => throttle::set($activity, $id, 0, 24, null, 'Why.', $by),
            'refusalthrottlepast' => fn() => throttle::set($activity, $id, 0, 24, time() - 60, 'Why.', $by),
        ];
        foreach ($cases as $key => $call) {
            try {
                $call();
                $this->fail("a limit refused by $key must not be stored");
            } catch (workflow_refusal $e) {
                $this->assertSame(get_string($key, 'mod_selfselectadvanced'), $e->getMessage());
            }
        }

        // The window, both ends.
        foreach ([0, throttle::MAX_WINDOW_HOURS + 1] as $window) {
            try {
                throttle::set($activity, $id, 3, $window, null, 'Why.', $by);
                $this->fail("a window of $window hours must be refused");
            } catch (workflow_refusal $e) {
                $this->assertSame(
                    get_string('refusalthrottlewindow', 'mod_selfselectadvanced', throttle::MAX_WINDOW_HOURS),
                    $e->getMessage()
                );
            }
        }

        $this->assertNull(throttle::get($activity, $id), 'not one of those refusals may have stored a row');
    }

    /**
     * The "wait till before next ticket" half, alone: a count of 0 with
     * a future date is a valid limit, and it is what refuses the filing.
     */
    public function test_a_wait_alone_is_a_valid_limit_and_refuses_a_filing(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        throttle::set($activity, (int) $leader->id, 0, 24, time() + DAYSECS, 'Take a day.', (int) $staff->id);

        try {
            tickets::file_help($activity, $group, 'Another one', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('a wait that has not elapsed must refuse the filing');
        } catch (workflow_refusal $e) {
            $this->assertStringContainsString('Take a day.', $e->getMessage(), 'the requester is told the reason');
        }
    }

    /**
     * A wait that HAS elapsed stops refusing. The row is not cleaned up
     * by anything - it simply stops biting - so this is the test that
     * proves the comparison is against now and not against existence.
     */
    public function test_an_elapsed_wait_no_longer_refuses(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        throttle::set($activity, (int) $leader->id, 0, 24, time() + DAYSECS, 'Take a day.', (int) $staff->id);
        // Wound back past now, which set() itself would refuse to store -
        // it is a state only the passage of time can reach.
        $DB->set_field(
            'selfselectadvanced_ticketthrottle',
            'nextallowed',
            time() - 60,
            ['activityid' => $activity->id(), 'userid' => (int) $leader->id]
        );

        $ticket = tickets::file_help($activity, $group, 'Now allowed', FORMAT_PLAIN, (int) $leader->id);
        $this->assertNotEmpty($ticket->id);
    }

    /**
     * The count half. THE ONE THAT MATTERS: withdrawn and declined
     * requests still count, so nobody can withdraw their way back to an
     * empty allowance.
     */
    public function test_the_count_includes_requests_that_are_no_longer_live(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        throttle::set($activity, (int) $leader->id, 2, 24, null, 'Two a day, please.', (int) $staff->id);

        // Filed and disposed of one at a time: a requester may hold only
        // one LIVE help ticket (file_help()'s own duplicate guard), and
        // the point here is precisely that DEAD ones still count.
        $one = tickets::file_help($activity, $group, 'First', FORMAT_PLAIN, (int) $leader->id);
        tickets::withdraw($activity, (int) $one->id, (int) $leader->id);
        $two = tickets::file_help($activity, $group, 'Second', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $two->id, (int) $staff->id);
        tickets::close($activity, (int) $two->id, tickets::STATUS_DECLINED, 'No.', FORMAT_PLAIN, (int) $staff->id);

        try {
            tickets::file_help($activity, $group, 'Third', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('withdrawing a request must not buy back an allowance');
        } catch (workflow_refusal $e) {
            $this->assertStringContainsString('Two a day, please.', $e->getMessage());
        }
    }

    /**
     * The window is ROLLING: a request filed before it began does not
     * count against the allowance.
     */
    public function test_requests_older_than_the_window_do_not_count(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $old = tickets::file_help($activity, $group, 'Ancient', FORMAT_PLAIN, (int) $leader->id);
        // Withdrawn so it is not the LIVE help ticket blocking the next
        // filing - the duplicate guard and the limit are two different
        // rules, and this test is about the limit.
        tickets::withdraw($activity, (int) $old->id, (int) $leader->id);
        $DB->set_field('selfselectadvanced_ticket', 'timecreated', time() - (48 * HOURSECS), ['id' => (int) $old->id]);

        throttle::set($activity, (int) $leader->id, 1, 24, null, 'One a day.', (int) $staff->id);

        // The old one is outside the 24-hour window, so this is allowed.
        $fresh = tickets::file_help($activity, $group, 'Today', FORMAT_PLAIN, (int) $leader->id);
        $this->assertNotEmpty($fresh->id);
        tickets::withdraw($activity, (int) $fresh->id, (int) $leader->id);

        // And the next one is not - refused by the LIMIT, which the
        // message is checked for: a duplicate-help refusal here would
        // pass a bare expectException and prove nothing.
        try {
            tickets::file_help($activity, $group, 'Also today', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('the second request inside the window must be refused');
        } catch (workflow_refusal $e) {
            $this->assertStringContainsString('One a day.', $e->getMessage());
        }
    }

    /**
     * A limit belongs to ONE activity. The same person filing in another
     * activity is not limited by it, and their tickets there do not
     * count towards it.
     */
    public function test_a_limit_does_not_reach_another_activity(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();
        [$other, , , , $othergroup] = $this->scene();

        // The same person, in both activities.
        $generator = $this->getDataGenerator();
        $generator->enrol_user($leader->id, $other->courseid(), 'student');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $secondgroup = $plugingen->create_group([
            'activityid' => $other->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team Green',
            'state' => state::FIRM,
        ]);
        unset($othergroup);

        throttle::set($activity, (int) $leader->id, 1, 24, null, 'One here.', (int) $staff->id);
        $used = tickets::file_help($activity, $group, 'Used it', FORMAT_PLAIN, (int) $leader->id);
        // Withdrawn so the refusal below can only be the limit's - see
        // test_requests_older_than_the_window_do_not_count() for the
        // same reasoning.
        tickets::withdraw($activity, (int) $used->id, (int) $leader->id);

        // Refused here...
        try {
            tickets::file_help($activity, $group, 'Blocked', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('the limit must bite in the activity it was set in');
        } catch (workflow_refusal $e) {
            $this->assertStringContainsString('One here.', $e->getMessage());
        }

        // ...and not there.
        $elsewhere = tickets::file_help($other, $secondgroup, 'Fine', FORMAT_PLAIN, (int) $leader->id);
        $this->assertNotEmpty($elsewhere->id);
    }

    /**
     * Lifting a limit lets the next request through, and lifting one
     * that is not there is a harmless no-op (a double-press must not be
     * an error).
     */
    public function test_lifting_a_limit_lets_the_next_request_through(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        throttle::set($activity, (int) $leader->id, 1, 24, null, 'One a day.', (int) $staff->id);
        $first = tickets::file_help($activity, $group, 'First', FORMAT_PLAIN, (int) $leader->id);
        tickets::withdraw($activity, (int) $first->id, (int) $leader->id);

        $this->assertTrue(throttle::clear($activity, (int) $leader->id, (int) $staff->id));
        $this->assertFalse(
            throttle::clear($activity, (int) $leader->id, (int) $staff->id),
            'lifting a limit that is not there is a no-op, not an error'
        );

        $second = tickets::file_help($activity, $group, 'Second', FORMAT_PLAIN, (int) $leader->id);
        $this->assertNotEmpty($second->id);
    }

    /**
     * EVERY FILING DOOR asks. This is the shape of audit L-9 - a rule
     * enforced at the door somebody happened to look at, and bypassed
     * through the one beside it - so the guard is a source scan over
     * the service's own filing methods rather than one behavioural test
     * per door.
     *
     * file_guidegone() is the deliberate exception and is named here as
     * one: it is filed BY the system when a guide disappears, with no
     * requester to throttle.
     */
    public function test_every_filing_door_asks_the_limit(): void {
        $source = file_get_contents(__DIR__ . '/../classes/local/tickets.php');
        $this->assertNotEmpty($source);

        // The doors, by name, taken from the service's own public API.
        $doors = ['file', 'file_guidecap', 'file_guidereduce', 'file_help'];
        $exempt = ['file_guidegone'];

        $examined = 0;
        $missing = [];
        foreach ($doors as $door) {
            $needle = 'public static function ' . $door . '(';
            $at = strpos($source, $needle);
            if ($at === false) {
                // A renamed door is a real failure: this test would
                // otherwise quietly examine three doors and pass.
                $missing[] = $door . ' (no such method any more)';
                continue;
            }
            $examined++;
            // The body, to the next public method or the end.
            $next = strpos($source, "\n    public static function ", $at + strlen($needle));
            $body = substr($source, $at, ($next === false ? strlen($source) : $next) - $at);
            if (!str_contains($body, 'throttle::require_within(')) {
                $missing[] = $door;
            }
        }

        $this->assertSame(count($doors), $examined, 'the scan must have examined every named door');
        $this->assertSame([], $missing, 'these filing doors do not ask the request limit: ' . implode(', ', $missing));

        // The exemption is real and deliberate - asserted, so that
        // removing the exception from the list without removing the
        // reason fails here.
        foreach ($exempt as $door) {
            $this->assertStringContainsString('public static function ' . $door . '(', $source);
        }
    }

    /**
     * Both events fire, naming the person the limit is about.
     */
    public function test_setting_and_lifting_a_limit_are_logged(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff] = $this->scene();

        $sink = $this->redirectEvents();
        throttle::set($activity, (int) $leader->id, 2, 24, null, 'Two a day.', (int) $staff->id);
        throttle::clear($activity, (int) $leader->id, (int) $staff->id);
        $events = $sink->get_events();
        $sink->close();

        $set = array_values(array_filter(
            $events,
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_throttle_set
        ));
        $cleared = array_values(array_filter(
            $events,
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_throttle_cleared
        ));
        $this->assertCount(1, $set);
        $this->assertCount(1, $cleared);
        $this->assertSame((int) $leader->id, (int) $set[0]->relateduserid);
        $this->assertSame((int) $leader->id, (int) $cleared[0]->relateduserid);
        $this->assertSame(2, (int) $set[0]->other['maxtickets']);
    }
}
