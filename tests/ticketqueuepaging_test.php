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

use mod_selfselectadvanced\local\tickets;

/**
 * The request queue is bounded, and stays correct once it is.
 *
 * Resolved and declined tickets are never removed, so an activity's
 * queue grows all semester; returning the whole of it was a page that
 * got slower every week. The page also resolved team names by loading
 * EVERY group in the activity — fifteen hundred rows to label one
 * screenful — which is the opposite of what this plugin is built for.
 *
 * Paging a queue is easy to get subtly wrong, so what is pinned here is
 * the part that breaks quietly: the position numbers the people waiting
 * in the queue are shown must still be right on page two.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets::queue
 * @covers     \mod_selfselectadvanced\local\tickets::queue_count
 * @covers     \mod_selfselectadvanced\local\tickets::open_before
 */
final class ticketqueuepaging_test extends \advanced_testcase {
    /**
     * An activity with a given number of open unfreeze requests.
     *
     * @param int $count how many tickets to file
     * @return array [activity, manager]
     */
    private function setup_queue(int $count): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $now = time();
        for ($i = 0; $i < $count; $i++) {
            $leader = $generator->create_user();
            $generator->enrol_user($leader->id, $course->id, 'student');
            $group = $plugingen->create_group([
                'activityid' => $instance->id,
                'leaderid' => $leader->id,
                'name' => 'Team ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ]);
            $DB->insert_record('selfselectadvanced_ticket', (object) [
                'activityid' => $instance->id,
                'groupid' => $group->id,
                'type' => tickets::TYPE_UNFREEZE,
                'status' => tickets::STATUS_OPEN,
                'requestedby' => $leader->id,
                'request' => 'Please unfreeze team ' . $i,
                'requestformat' => FORMAT_HTML,
                // Ordered so the queue's own sort is deterministic.
                'timecreated' => $now + $i,
                'timemodified' => $now + $i,
            ]);
        }

        return [$activity, $manager];
    }

    /**
     * A page returns only its page, and the count reports the whole.
     */
    public function test_the_queue_returns_one_page_at_a_time(): void {
        $this->resetAfterTest();
        [$activity, $manager] = $this->setup_queue(12);

        $this->assertSame(12, tickets::queue_count($activity, (int) $manager->id));

        $first = tickets::queue($activity, (int) $manager->id, 0, 5);
        $second = tickets::queue($activity, (int) $manager->id, 5, 5);
        $third = tickets::queue($activity, (int) $manager->id, 10, 5);

        $this->assertCount(5, $first);
        $this->assertCount(5, $second);
        $this->assertCount(2, $third, 'The last page should hold the remainder');

        // No ticket may appear on two pages.
        $ids = array_merge(array_keys($first), array_keys($second), array_keys($third));
        $this->assertSame(count($ids), count(array_unique($ids)), 'A ticket appeared on more than one page');
        $this->assertSame(12, count($ids), 'Paging lost or duplicated tickets');
    }

    /**
     * The queue position shown to people waiting stays true on page two.
     */
    public function test_queue_positions_continue_across_pages(): void {
        $this->resetAfterTest();
        [$activity, $manager] = $this->setup_queue(12);

        $this->assertSame(0, tickets::open_before($activity, (int) $manager->id, 0));
        $this->assertSame(5, tickets::open_before($activity, (int) $manager->id, 5));
        $this->assertSame(10, tickets::open_before($activity, (int) $manager->id, 10));

        // Past the end of the open tickets the offset stops counting up,
        // so a page of already-resolved ones cannot invent positions.
        $this->assertSame(
            12,
            tickets::open_before($activity, (int) $manager->id, 50),
            'Positions must not run past the number of open tickets'
        );
    }

    /**
     * AUDIT A7 (2026-08-20): the escalated ordering tier sorts a live
     * escalated ticket that is NOT open ahead of every open one
     * (queue()'s ORDER BY), so a run of them before an offset displaces
     * the opens open_before() used to assume were simply "the first N".
     * RED-FIRST PROOF (see the report): before the fix, open_before()
     * answers min($limitfrom, count_open()) and inflates every one of
     * the mid-range assertions below by exactly the one escalated
     * non-open row that precedes the offset.
     *
     * MUTATION CAUGHT (documented, see the report): reverting
     * escalated_live_nonopen_count()'s use back to the old
     * min($limitfrom, count_open()) formula makes the offset-1 and
     * offset-5 assertions fail (1 and 5 instead of 0 and 4).
     */
    public function test_escalated_claimed_ticket_does_not_inflate_open_positions(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $manager] = $this->setup_queue(12);

        $claimant = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($claimant->id, (int) $activity->cm()->course, 'editingteacher');
        $leader = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($leader->id, (int) $activity->cm()->course, 'student');
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'name' => 'Escalated team',
        ]);
        $now = time();
        // Deliberately timestamped BEFORE every open ticket in the
        // fixture, to prove the escalated tier - not recency - is what
        // sorts this row first.
        $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'groupid' => $group->id,
            'type' => tickets::TYPE_UNFREEZE,
            'status' => tickets::STATUS_CLAIMED,
            'claimedby' => $claimant->id,
            'timeclaimed' => $now,
            'escalated' => 1,
            'requestedby' => $leader->id,
            'request' => 'Escalated ahead of the open queue',
            'requestformat' => FORMAT_HTML,
            'timecreated' => $now - 1000,
            'timemodified' => $now,
        ]);

        // The open count is unaffected - the escalated ticket is
        // claimed, not open.
        $this->assertSame(12, tickets::count_open($activity, (int) $manager->id));

        // Fixture check: the escalated claimed ticket really is the
        // very first physical row (queue()'s own ordering), ahead of
        // every open ticket despite being the oldest by far.
        $firstpage = tickets::queue($activity, (int) $manager->id, 0, 1);
        $firstrow = reset($firstpage);
        $this->assertSame(tickets::STATUS_CLAIMED, $firstrow->status, 'fixture: the escalated ticket must sort first');

        $this->assertSame(0, tickets::open_before($activity, (int) $manager->id, 0));
        $this->assertSame(
            0,
            tickets::open_before($activity, (int) $manager->id, 1),
            'the one row before offset 1 is the escalated claimed ticket, not an open one'
        );
        $this->assertSame(
            4,
            tickets::open_before($activity, (int) $manager->id, 5),
            'one escalated non-open row precedes offset 5, so only 4 of those 5 rows are open'
        );
        $this->assertSame(
            12,
            tickets::open_before($activity, (int) $manager->id, 50),
            'positions still cap at the total number open once the offset runs past every live ticket'
        );
    }

    /**
     * The team's name travels with the ticket, so the page never has to
     * load every group to label a screenful.
     */
    public function test_the_team_name_comes_back_with_the_ticket(): void {
        $this->resetAfterTest();
        [$activity, $manager] = $this->setup_queue(3);

        $page = tickets::queue($activity, (int) $manager->id, 0, 3);
        $this->assertNotEmpty($page);
        foreach ($page as $ticket) {
            $this->assertObjectHasProperty('groupname', $ticket);
            $this->assertNotNull($ticket->groupname, 'The ticket did not carry its team name');
            $this->assertNotNull($ticket->grouppluginuid);
        }
    }

    /**
     * A team-limit request is about no team at all, stores NULL in groupid,
     * and must survive the join that fetches team names - the same LEFT JOIN
     * lesson the privacy export had to learn.
     *
     * MUTATION CAUGHT (run): changing the queue() LEFT JOIN to an INNER JOIN;
     * the page dropped the guide-capacity ticket and returned only two rows.
     */
    public function test_a_guidecap_ticket_is_not_dropped_by_the_join(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $manager] = $this->setup_queue(2);

        $guide = $this->getDataGenerator()->create_user();
        $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'groupid' => null,
            'type' => tickets::TYPE_GUIDECAP,
            'status' => tickets::STATUS_OPEN,
            'requestedby' => $guide->id,
            'requested' => 9,
            'request' => 'I can take more teams',
            'requestformat' => FORMAT_HTML,
            'timecreated' => time() + 100,
            'timemodified' => time() + 100,
        ]);

        $this->assertSame(3, tickets::queue_count($activity, (int) $manager->id));
        $page = tickets::queue($activity, (int) $manager->id, 0, 50);
        $this->assertCount(3, $page, 'An inner join would have dropped the team-limit request');

        $types = array_map(static fn($t) => $t->type, array_values($page));
        $this->assertContains(tickets::TYPE_GUIDECAP, $types);
        $guidecap = array_values(array_filter($page, static fn($t) => $t->type === tickets::TYPE_GUIDECAP))[0];
        $this->assertNull($guidecap->groupid, 'a team-limit ticket stored a sentinel groupid');
        $this->assertNull($guidecap->groupname, 'a team-limit ticket was joined to a team');
    }
}
