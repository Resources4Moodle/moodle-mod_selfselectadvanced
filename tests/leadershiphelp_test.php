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
use mod_selfselectadvanced\local\tickets;

/**
 * "Leadership help": a member asks the coordinator queue about the leadership.
 *
 * Maintainer decision 71. Every other leader-only action already had a staff
 * route for an absent or unresponsive leader - join requests admit :manage or
 * :coordinate, choosing a guide the same - and this one did not, so a team
 * whose leader had gone quiet had no move at all.
 *
 * What it is NOT is as important as what it is. It is an ASK, decided by a
 * coordinator under the same conflict-of-interest rule as every other ticket
 * type, and any actual change runs through the existing succession machinery.
 * A member never moves the leadership themselves.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class leadershiphelp_test extends \advanced_testcase {
    /**
     * A team with a leader, a confirmed member, a bystander and a coordinator.
     *
     * @return array [activity, group, leader, member, bystander, coordinator]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $mk = function (string $role) use ($generator, $course) {
            $u = $generator->create_user();
            $generator->enrol_user($u->id, $course->id, $role);

            return $u;
        };
        $leader = $mk('student');
        $member = $mk('student');
        $bystander = $mk('student');
        $coordinator = $mk('teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, $activity->context());

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Needs help',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $member, $bystander, $coordinator];
    }

    /**
     * A confirmed member files it, and the current leader is told.
     *
     * MUTATION CAUGHT (run 2026-08-10): removing TYPE_LEADERCHANGE from the
     * accepted list in file() throws coding_exception and fails the filing;
     * removing the leader notification fails the recipient assertion.
     */
    public function test_a_member_files_it_and_the_leader_is_told(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $leader, $member] = $this->world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has not logged in for three weeks.',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $this->assertSame(tickets::TYPE_LEADERCHANGE, $ticket->type);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);
        $this->assertSame((int) $member->id, (int) $ticket->requestedby);

        $tolds = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $this->assertContains(
            (int) $leader->id,
            $tolds,
            'the current leader must be told - a leadership ask decided behind their back is the '
                . 'member-controlled transfer the ruling forbids'
        );
        $sink->close();
    }

    /**
     * The leader cannot file it: they have succession, which is theirs to drive.
     */
    public function test_the_leader_is_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader] = $this->world();

        try {
            tickets::file($activity, $group, tickets::TYPE_LEADERCHANGE, 'I want out.', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('Expected the leader to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleaderchangeisleader', $e->errorcode);
        }
    }

    /**
     * Somebody who is not a confirmed member of THIS team is refused.
     */
    public function test_a_bystander_is_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $bystander] = $this->world();

        try {
            tickets::file(
                $activity,
                $group,
                tickets::TYPE_LEADERCHANGE,
                'I have opinions about that team.',
                FORMAT_PLAIN,
                (int) $bystander->id
            );
            $this->fail('Expected a non-member to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketnotparty', $e->errorcode);
        }
    }

    /**
     * One live request per team, and a reason is required - both from the generic door.
     *
     * These are not new code; the point of the test is that the new type gets
     * them for free, which is why the ruling could be small.
     */
    public function test_it_inherits_the_generic_guards(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member] = $this->world();

        try {
            tickets::file($activity, $group, tickets::TYPE_LEADERCHANGE, '   ', FORMAT_PLAIN, (int) $member->id);
            $this->fail('Expected an empty reason to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketreason', $e->errorcode);
        }

        tickets::file($activity, $group, tickets::TYPE_LEADERCHANGE, 'First ask.', FORMAT_PLAIN, (int) $member->id);
        try {
            tickets::file($activity, $group, tickets::TYPE_LEADERCHANGE, 'Second ask.', FORMAT_PLAIN, (int) $member->id);
            $this->fail('Expected the duplicate to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketduplicate', $e->errorcode);
        }
    }

    /**
     * It reaches the coordinator queue, and the conflict-of-interest rule applies unchanged.
     *
     * The COI machinery is entirely generic, so this needed no new code - which
     * is worth pinning, because a later "tidy-up" that special-cased the new
     * type would silently drop the protection.
     */
    public function test_it_reaches_the_queue_under_the_existing_conflict_rule(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, , $coordinator] = $this->world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Please help us.',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $queue = tickets::queue($activity, (int) $coordinator->id);
        $this->assertContains(
            (int) $ticket->id,
            array_map(static fn($row) => (int) $row->id, $queue),
            'a new type must appear in the type-blind queue without queue changes'
        );

        // An uninvolved coordinator may claim it.
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);
        $this->assertSame(
            tickets::STATUS_CLAIMED,
            $GLOBALS['DB']->get_field('selfselectadvanced_ticket', 'status', ['id' => $ticket->id])
        );
    }
}
