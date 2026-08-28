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
use mod_selfselectadvanced\local\guides;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\tickets;

/**
 * A guide asking the coordinators for a higher team limit, and the
 * searchable guide pickers (strategy 1.18 B and C).
 *
 * The capacity request is the first ticket type that is not about a
 * team, so most of what is checked here is that the queue, the claim
 * and the close all cope with a ticket whose group is absent - and
 * that granting one writes the override and closes the ticket
 * together, never one without the other.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \mod_selfselectadvanced\local\guides
 */
final class guidecap_test extends \advanced_testcase {
    /**
     * An activity with two guides, a manager and a coordinator.
     *
     * @param array $settings instance overrides
     * @return array [activity, guide, otherguide, manager, coordinator, course]
     */
    private function setup_world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'CAP1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'maxguided' => 3,
        ], $settings));

        $guide = $generator->create_user(['firstname' => 'Anita', 'lastname' => 'Raman']);
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $otherguide = $generator->create_user(['firstname' => 'Bala', 'lastname' => 'Krishnan']);
        $generator->enrol_user($otherguide->id, $course->id, 'teacher');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_module::instance((int) $instance->cmid));

        return [activity::from_instance((int) $instance->id), $guide, $otherguide, $manager, $coordinator, $course];
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
     * A request has to ask for more than the guide already has, has to
     * carry a reason, and only one may be live at a time.
     */
    public function test_filing_gates(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $guide] = $this->setup_world();

        $this->assert_refused('refusalguidecapzero', fn() => tickets::file_guidecap(
            $activity,
            0,
            'More please',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        // The activity's own ceiling is 3, so 3 is not a request.
        $this->assert_refused('refusalguidecapnotmore', fn() => tickets::file_guidecap(
            $activity,
            3,
            'More please',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        $this->assert_refused('refusalticketreason', fn() => tickets::file_guidecap(
            $activity,
            5,
            '   ',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        $ticket = tickets::file_guidecap($activity, 5, 'Two more finalists', FORMAT_PLAIN, (int) $guide->id);
        $this->assertSame(tickets::TYPE_GUIDECAP, $ticket->type);
        $this->assertNull($ticket->groupid);
        $this->assertSame(5, (int) $ticket->requested);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);

        // A second one while the first is live is refused.
        $this->assert_refused('refusalticketduplicate', fn() => tickets::file_guidecap(
            $activity,
            6,
            'And another',
            FORMAT_PLAIN,
            (int) $guide->id
        ));
        $sink->close();
    }

    /**
     * Granting writes the override AND closes the ticket. Either alone
     * would be a lie: a raised limit with the request still open has
     * the guide asking again, and a closed request with no override
     * has them told yes and nothing changed.
     */
    public function test_granting_writes_the_override_and_closes(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $guide, , $manager] = $this->setup_world();
        $resolver = (new local\api($activity))->gatekeeper()->resolver();

        $this->assertSame(3, $resolver->guide_capacity_ceiling((int) $guide->id)->value);

        $ticket = tickets::file_guidecap($activity, 6, 'Six, for the finals', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $closed = tickets::grant_guidecap(
            $activity,
            (int) $ticket->id,
            'Agreed for this term',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        $this->assertSame(tickets::STATUS_RESOLVED, $closed->status);
        $fresh = (new local\api($activity))->gatekeeper()->resolver();
        $this->assertSame(6, $fresh->guide_capacity_ceiling((int) $guide->id)->value);
        $sink->close();
    }

    /**
     * Granting is setting an exception, so it needs the capability for
     * setting one. Working the queue and granting an exception are two
     * authorities, and a site is free to separate them.
     */
    public function test_granting_needs_the_override_capability(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $guide, , $manager, $coordinator, $course] = $this->setup_world();

        $ticket = tickets::file_guidecap($activity, 5, 'Five', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);

        // Take the capability away from the coordinator role in this
        // course and the grant is refused, while declining still works.
        $coursecontext = \context_course::instance($course->id);
        assign_capability(
            'mod/selfselectadvanced:override',
            CAP_PROHIBIT,
            coordinatorrole::ensure(),
            $coursecontext->id,
            true
        );
        \context_helper::reset_caches();

        $this->expectException(\required_capability_exception::class);
        tickets::grant_guidecap($activity, (int) $ticket->id, 'Yes', FORMAT_PLAIN, (int) $coordinator->id);
        $sink->close();
        unset($manager);
    }

    /**
     * A groupless ticket travels the whole queue: it is listed, it can
     * be claimed, and closing it notifies without reaching for a team
     * that was never there.
     *
     * MUTATION CAUGHT (run): file_guidecap() stores groupid 0 instead of
     * NULL; this method and test_filing_gates both failed on the sentinel.
     */
    public function test_a_ticket_without_a_team_survives_the_queue(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $guide, , $manager] = $this->setup_world();

        $ticket = tickets::file_guidecap($activity, 4, 'One more', FORMAT_PLAIN, (int) $guide->id);

        $queue = tickets::queue($activity, (int) $manager->id);
        $this->assertArrayHasKey((int) $ticket->id, $queue);
        $this->assertNull($queue[(int) $ticket->id]->groupid, 'a team-limit ticket stored a sentinel groupid');
        $this->assertNull(tickets::group_of($activity, $queue[(int) $ticket->id]));

        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $closed = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_DECLINED,
            'Not this term',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->assertSame(tickets::STATUS_DECLINED, $closed->status);
        $sink->close();
    }

    /**
     * Nobody works their own request. A coordinator who filed one is
     * refused the claim, and does not see it in their own queue.
     */
    public function test_a_coordinator_cannot_work_their_own_request(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , , , $coordinator] = $this->setup_world();

        // The coordinator is enrolled as a non-editing teacher, so they
        // hold the guide capability and can file one.
        $ticket = tickets::file_guidecap($activity, 5, 'Mine', FORMAT_PLAIN, (int) $coordinator->id);

        $this->assertArrayNotHasKey((int) $ticket->id, tickets::queue($activity, (int) $coordinator->id));
        $this->assert_refused(
            'refusalcoiself',
            fn() => tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id)
        );
        $sink->close();
    }

    /**
     * A request filed by mistake can be taken back while it is open,
     * by its author and nobody else, and not once somebody is working
     * it - that is their work in progress to close.
     */
    public function test_withdrawing_ones_own_open_request(): void {
        $this->resetAfterTest();
        // THE preventResetByRollback() BELOW MUST COME FIRST (1.20
        // wave 3E): the refusals driven here leave services that now
        // roll their own delegated frame back UNCONDITIONALLY, and this
        // test carries on committing afterwards. On PostgreSQL
        // advanced_testcase holds a transaction underneath for the
        // whole test, so that rollback is not the top level: it pops,
        // leaves force_rollback set, and the next allow_commit() raises
        // "Tried to commit transaction after lower level rollback". In
        // production nothing is underneath, the rollback empties the
        // stack and force_rollback is cleared - which is the cascade
        // the fix restores.
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        [$activity, $guide, $otherguide, $manager] = $this->setup_world();

        $ticket = tickets::file_guidecap($activity, 5, 'Asked too soon', FORMAT_PLAIN, (int) $guide->id);
        $this->assert_refused(
            'refusalticketnotyours',
            fn() => tickets::withdraw($activity, (int) $ticket->id, (int) $otherguide->id)
        );

        $withdrawn = tickets::withdraw($activity, (int) $ticket->id, (int) $guide->id);
        $this->assertSame(tickets::STATUS_WITHDRAWN, $withdrawn->status);

        // Withdrawn frees the slot, so a fresh request is accepted.
        $second = tickets::file_guidecap($activity, 5, 'Asking properly', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $second->id, (int) $manager->id);
        // 1.20.60 (audit L-4): withdraw() now refuses with the
        // REQUESTER'S own wording. It used to reuse
        // refusalticketclaimed, whose {$a} is a PERSON - so the sentence
        // this guide actually read was "already been taken up by
        // claimed", the status name standing where a name belonged.
        // myrequests_test.php pins the same rename from the other side.
        $this->assert_refused(
            'refusalticketnolongeropen',
            fn() => tickets::withdraw($activity, (int) $second->id, (int) $guide->id)
        );
        $sink->close();
    }

    /**
     * The picker searches rather than lists: a query narrows by name,
     * the result carries department and load for the choice, and the
     * cap is honoured.
     */
    public function test_guide_search_narrows_and_carries_department(): void {
        $this->resetAfterTest();
        [$activity, $guide, $otherguide] = $this->setup_world();
        // Written as the site administrator: attributes\manager::set()
        // authorises its actor against :ingestattributes at system
        // context (audit A-6). The question under test is what the
        // guide picker NARROWS to and what it carries, not who may
        // write an attribute row.
        local\attributes\manager::set((int) $guide->id, [
            'department' => 'SCOPE',
            'subdepartment' => 'BCE',
        ], (int) get_admin()->id);

        $resolver = (new local\api($activity))->gatekeeper()->resolver();

        $hits = guides::search($activity, $resolver, 'Anita');
        $this->assertArrayHasKey((int) $guide->id, $hits);
        $this->assertArrayNotHasKey((int) $otherguide->id, $hits);
        $this->assertSame('SCOPE', $hits[(int) $guide->id]->department);
        $this->assertSame('BCE', $hits[(int) $guide->id]->subdepartment);

        // A surname works as well as a first name, and a query nobody
        // matches returns nothing rather than everybody.
        $this->assertArrayHasKey((int) $otherguide->id, guides::search($activity, $resolver, 'Krishnan'));
        $this->assertSame([], guides::search($activity, $resolver, 'Nobody At All'));

        // The cap is a cap.
        $this->assertLessThanOrEqual(1, count(guides::search($activity, $resolver, 'a', 1)));
    }

    /**
     * The name filter narrows before the resolver is consulted, which
     * is what keeps a keystroke from costing the whole school - and it
     * must not change what an unfiltered call returns.
     */
    public function test_with_load_name_filter(): void {
        $this->resetAfterTest();
        [$activity, $guide, $otherguide] = $this->setup_world();
        $resolver = (new local\api($activity))->gatekeeper()->resolver();

        $all = guides::with_load($activity, $resolver, true);
        $this->assertArrayHasKey((int) $guide->id, $all);
        $this->assertArrayHasKey((int) $otherguide->id, $all);

        $filtered = guides::with_load($activity, $resolver, true, 'raman');
        $this->assertSame([(int) $guide->id], array_keys($filtered));
        $this->assertSame($all[(int) $guide->id]->max, $filtered[(int) $guide->id]->max);
    }

    /**
     * An override granted by a request is an override like any other:
     * it shows in the store, so the overrides page lists it and a
     * manager can change or remove it later.
     */
    public function test_a_granted_limit_is_a_visible_override(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $guide, , $manager] = $this->setup_world();

        $ticket = tickets::file_guidecap($activity, 7, 'Seven', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::grant_guidecap($activity, (int) $ticket->id, 'Yes', FORMAT_PLAIN, (int) $manager->id);

        $override = store::get($activity, 'guide', (int) $guide->id);
        $this->assertNotNull($override);
        $this->assertSame(7, (int) $override->maxguided);
        $sink->close();
    }
}
