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
 * SLICE C2: the staff ticket queue (tickets.php, door = manage-or-coordinate)
 * gets TYPE and STATUS filters so a busy queue can be triaged, instead of
 * offering only paging over an undifferentiated list.
 *
 * tickets::queue()/queue_count() take two new optional parameters, $type
 * and $status, each '' (no filter) or one of the matching TYPE_* / STATUS_*
 * constant. Validation lives at the seam (validate_type_filter()/
 * validate_status_filter() in classes/local/tickets.php) and throws
 * coding_exception for an unknown value: tickets.php whitelists the GET
 * params BEFORE calling in, so a bad value reaching the service is a
 * caller bug, not a request a person made - see this file's last two
 * tests. count_open()/open_before() take the same filter so the queue's
 * 1, 2, 3 position numbering stays true under it too (position = open
 * tickets before the offset WITHIN the filtered ordering; asserted
 * indirectly here via queue_count() agreement, and directly by
 * tests/behat/tickets.feature end to end).
 *
 * MUTATION CAUGHT (run 2026-08-15). test_type_filter_returns_matching_subset()
 * was written and run with `--filter` against the UNMODIFIED service -
 * queue(activity $activity, int $viewerid = 0, int $limitfrom = 0, int
 * $limitnum = 0), four parameters, no filter of any kind. PHP does not
 * refuse a call that supplies MORE positional arguments than a function
 * declares (that error is only raised for too FEW); the fifth argument
 * this test passes was silently discarded, so the unmodified queue()
 * returned all three fixture tickets regardless, and the assertion that
 * only the compchange one came back failed, verbatim:
 *
 *   1) mod_selfselectadvanced\ticket_queue_filter_test::test_type_filter_returns_matching_subset
 *   Failed asserting that two arrays are identical.
 *   Expected: Array ( 0 => 571000 )
 *   Actual:   Array ( 0 => 571000, 1 => 571001, 2 => 571002 )
 *
 * Green only after queue() gained the real $type parameter,
 * validate_type_filter() and the "AND t.type = :type" clause to go with it.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class ticket_queue_filter_test extends \advanced_testcase {
    /**
     * An activity with a firm group (leader + confirmed member, guide
     * assigned), a manager and a coordinator. Shaped exactly like
     * tickets_test.php::setup_world() and ticket_richtext_test.php's copy
     * of it - the same fixture the rest of the ticket queue is tested
     * against.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'TKTF1']);
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
            'name' => 'Filtered',
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
     * Three tickets of three different types, progressed to three
     * different statuses exactly the way real ones would be:
     *  - compchange, filed by the guide, left OPEN;
     *  - leaderchange, filed by the confirmed member, then CLAIMED by
     *    the manager (never closed);
     *  - guidecap, filed by the guide (asking one above the activity's
     *    default maxguided of 10), claimed and then RESOLVED.
     *
     * Three types and three statuses in one fixture, rather than one
     * axis varying at a time, is deliberate: it is the only way a type
     * filter, a status filter and the two combined can each be shown to
     * pick out a DIFFERENT single ticket, which is what makes assertion
     * (b), (c) and (d) below meaningful rather than vacuous.
     *
     * @return array{0: activity, 1: \stdClass, 2: \stdClass, 3: \stdClass}
     *         [activity, compchange/open ticket, leaderchange/claimed ticket, guidecap/resolved ticket]
     */
    private function three_tickets(): array {
        [$activity, $group, , $member, $guide, $manager] = $this->setup_world();

        $compchange = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap in a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $leaderchange = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $leaderchange->id, (int) $manager->id);
        $leaderchange = tickets::get($activity, (int) $leaderchange->id);

        $guidecap = tickets::file_guidecap($activity, 11, 'Taking on more groups', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $guidecap->id, (int) $manager->id);
        $guidecap = tickets::close(
            $activity,
            (int) $guidecap->id,
            tickets::STATUS_RESOLVED,
            'Granted separately',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        return [$activity, $compchange, $leaderchange, $guidecap];
    }

    /**
     * (a) Unfiltered, queue() still returns every ticket - the count is
     * asserted explicitly (not just "not empty") so a filter default
     * that accidentally narrowed the unfiltered case could not pass
     * silently.
     */
    public function test_unfiltered_queue_returns_everything(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $compchange, $leaderchange, $guidecap] = $this->three_tickets();

        $ids = array_map(static fn($t) => (int) $t->id, array_values(tickets::queue($activity)));
        sort($ids);
        $expected = [(int) $compchange->id, (int) $leaderchange->id, (int) $guidecap->id];
        sort($expected);
        $this->assertSame(3, count($ids), 'the fixture must actually hold three tickets');
        $this->assertSame($expected, $ids);
    }

    /**
     * (b) A type filter returns exactly the matching subset - by id, not
     * just by count, so a filter that happened to return the right
     * NUMBER of the wrong tickets would still be caught.
     *
     * This is the assertion proved RED against the unmodified service
     * before queue() gained its $type parameter - see this file's
     * docblock for the captured failure.
     */
    public function test_type_filter_returns_matching_subset(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $compchange, , ] = $this->three_tickets();

        $ids = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, tickets::TYPE_COMPCHANGE))
        );
        $this->assertSame([(int) $compchange->id], $ids);
    }

    /**
     * (c) A status filter returns exactly the matching subset. The
     * leaderchange ticket is the only one CLAIMED (compchange is open,
     * guidecap is resolved), so this also proves the filter is a real
     * WHERE clause and not, say, a type filter under another name.
     */
    public function test_status_filter_returns_matching_subset(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leaderchange, ] = $this->three_tickets();

        $ids = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, '', tickets::STATUS_CLAIMED))
        );
        $this->assertSame([(int) $leaderchange->id], $ids);
    }

    /**
     * (d) Type and status combined narrow to their intersection, not
     * their union - guidecap/resolved is the ONE ticket both hold true
     * for, even though compchange also matches no filter here and
     * leaderchange is claimed rather than resolved.
     */
    public function test_combined_type_and_status_filter(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $guidecap] = $this->three_tickets();

        $ids = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, tickets::TYPE_GUIDECAP, tickets::STATUS_RESOLVED))
        );
        $this->assertSame([(int) $guidecap->id], $ids);
    }

    /**
     * (e) queue_count() must agree with count(queue()) under the same
     * filter, unfiltered and under each of the three filters exercised
     * above - the position math in tickets.php trusts queue_count() for
     * the total, and a paging bar built on a total that disagreed with
     * the rows actually returned would mislabel every page after the
     * first.
     */
    public function test_queue_count_agrees_with_filtered_queue(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity] = $this->three_tickets();

        $cases = [
            ['', ''],
            [tickets::TYPE_COMPCHANGE, ''],
            ['', tickets::STATUS_CLAIMED],
            [tickets::TYPE_GUIDECAP, tickets::STATUS_RESOLVED],
        ];
        foreach ($cases as [$type, $status]) {
            $rows = tickets::queue($activity, 0, 0, 0, $type, $status);
            $count = tickets::queue_count($activity, 0, $type, $status);
            $this->assertSame(
                count($rows),
                $count,
                "queue_count() disagreed with count(queue()) for type='$type' status='$status'"
            );
        }
    }

    /**
     * THE VIEWER EXCLUSION, proven on all three counters (audit L-27).
     *
     * A coordinator may not work their own request - the conflict-of-
     * interest rule the queue enforces by simply not showing it to them
     * - and queue(), queue_count() and count_open() each carry their own
     * copy of the `AND t.requestedby <> :viewerid` fragment. Three
     * copies of one rule, and nothing compared them: a queue that hides
     * the row while the counter above it still counts it produces a
     * "12 requests" heading over eleven rows, and a position number that
     * is off by one for everybody below it.
     *
     * A manage holder is deliberately exempt (the same `if` names the
     * capability): they may act on their own request, so nothing is
     * hidden from them, and that arm is asserted too - otherwise a
     * mutation that dropped the exclusion entirely would pass half this
     * test.
     */
    public function test_a_coordinators_own_request_is_hidden_from_them_but_not_from_a_manager(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();

        // Somebody else's request, so the counts are never zero on both
        // sides of the comparison (a test where everything is hidden
        // proves nothing about what is hidden).
        tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Swap a member', FORMAT_PLAIN, (int) $guide->id);
        // And the coordinator's own, filed as a guide-capacity request -
        // the one type a coordinator can raise for themself.
        $own = tickets::file_guidecap($activity, 11, 'I can take one more', FORMAT_PLAIN, (int) $coordinator->id);

        $seen = tickets::queue($activity, (int) $coordinator->id);
        $this->assertArrayNotHasKey(
            (int) $own->id,
            $seen,
            'a coordinator must not be shown their own request in the queue they work'
        );
        $this->assertCount(1, $seen, 'the other request is still there - the exclusion is not hiding everything');

        // The three counters agree with what the queue actually returned.
        $this->assertSame(count($seen), tickets::queue_count($activity, (int) $coordinator->id));
        $this->assertSame(
            count($seen),
            tickets::count_open($activity, (int) $coordinator->id),
            'both fixture requests are open, so the open count and the queue count coincide here'
        );

        // The manager sees both, and counts both.
        $managerseen = tickets::queue($activity, (int) $manager->id);
        $this->assertArrayHasKey((int) $own->id, $managerseen, 'a manage holder is exempt from the exclusion');
        $this->assertCount(2, $managerseen);
        $this->assertSame(2, tickets::queue_count($activity, (int) $manager->id));
        $this->assertSame(2, tickets::count_open($activity, (int) $manager->id));
    }

    /**
     * The exclusion survives a FILTER. queue() and queue_count() build
     * their WHERE clauses separately, so the pairing has to hold when
     * both fragments are present at once - the drift audit L-27 named
     * would show up here first, as a paging bar promising a page the
     * list cannot fill.
     */
    public function test_the_viewer_exclusion_and_a_filter_agree_on_the_total(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, , $coordinator] = $this->setup_world();

        tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Swap a member', FORMAT_PLAIN, (int) $guide->id);
        tickets::file_guidecap($activity, 11, 'I can take one more', FORMAT_PLAIN, (int) $coordinator->id);

        foreach (['', tickets::TYPE_COMPCHANGE, tickets::TYPE_GUIDECAP] as $type) {
            $rows = tickets::queue($activity, (int) $coordinator->id, 0, 0, $type);
            $this->assertSame(
                count($rows),
                tickets::queue_count($activity, (int) $coordinator->id, $type),
                "the count and the list disagree for type filter '" . ($type === '' ? 'none' : $type) . "'"
            );
        }

        // And the guidecap arm is genuinely empty for this viewer -
        // their own request is the only one of that type, so the loop
        // above compared 0 with 0 there ON PURPOSE, and this states it
        // rather than leaving it to be assumed.
        $this->assertSame(0, tickets::queue_count($activity, (int) $coordinator->id, tickets::TYPE_GUIDECAP));
        $this->assertSame(1, tickets::queue_count($activity, (int) $coordinator->id, tickets::TYPE_COMPCHANGE));
    }

    /**
     * (f) A type value that is not '' and not one of the known TYPE_*
     * constants is a coding_exception, not a workflow_refusal or a
     * silently-ignored no-op: the page whitelists before ever calling
     * in (tickets.php), so this can only be reached by a caller bug.
     */
    public function test_unknown_type_filter_throws_coding_exception(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_world();

        $this->expectException(\coding_exception::class);
        tickets::queue($activity, 0, 0, 0, 'notarealtype');
    }

    /**
     * The status twin of the previous test - same reasoning, same
     * exception type.
     */
    public function test_unknown_status_filter_throws_coding_exception(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_world();

        $this->expectException(\coding_exception::class);
        tickets::queue($activity, 0, 0, 0, '', 'notarealstatus');
    }
}
