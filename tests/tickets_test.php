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
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * The sequential request queue and the coordinator role (strategy
 * 1.16 B and D): who may file what and in which state, one live
 * ticket per (group, type), the exclusive claim, close/release
 * authority, auto-resolution by a direct unfreeze, queue order, the
 * conflict-of-interest guard and the coordinator's on-behalf
 * freeze/unfreeze.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \mod_selfselectadvanced\local\coordinatorrole
 */
final class tickets_test extends \advanced_testcase {
    /**
     * An activity with a firm group (leader + 1 member, guide
     * assigned), a manager, and a coordinator holding the
     * groupcoordinator role at course level.
     *
     * @param array $settings instance overrides
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'TKT1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], $settings));

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
            'name' => 'Ticketed',
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
     * Who may file what, in which state, with a reason, once: the
     * leader cannot ask for a composition change, a mere member for
     * anything; unfreeze needs a frozen team, composition change a
     * firm or frozen one; a blank reason is refused; a second live
     * ticket of the same type is refused pointing at the first.
     */
    public function test_filing_gates(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $leader, $member, $guide] = $this->setup_world();

        $this->assert_refused('refusalticketnotguide', fn() => tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'please',
            FORMAT_PLAIN,
            (int) $leader->id
        ));
        $this->assert_refused('refusalticketnotparty', fn() => tickets::file(
            $activity,
            $group,
            tickets::TYPE_UNFREEZE,
            'please',
            FORMAT_PLAIN,
            (int) $member->id
        ));
        // Firm, not frozen: no unfreeze to ask for.
        $this->assert_refused('refusalwrongstate', fn() => tickets::file(
            $activity,
            $group,
            tickets::TYPE_UNFREEZE,
            'please',
            FORMAT_PLAIN,
            (int) $guide->id
        ));
        $this->assert_refused('refusalticketreason', fn() => tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            '   ',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap one member',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);
        $this->assert_refused('refusalticketduplicate', fn() => tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Again',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        // A forming group takes no composition-change ticket.
        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $group->id]);
        $forming = groups::get($activity, (int) $group->id);
        $this->assert_refused('refusalwrongstate', fn() => tickets::file(
            $activity,
            $forming,
            tickets::TYPE_COMPCHANGE,
            'now?',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        // Frozen: the leader may ask for an unfreeze, and the filing
        // notified the queue workers (manager + coordinator, once each).
        $DB->set_field('selfselectadvanced_group', 'state', state::FROZEN, ['id' => $group->id]);
        $frozen = groups::get($activity, (int) $group->id);
        $unfreeze = tickets::file(
            $activity,
            $frozen,
            tickets::TYPE_UNFREEZE,
            'Member left the course',
            FORMAT_PLAIN,
            (int) $leader->id
        );
        $this->assertSame(tickets::TYPE_UNFREEZE, $unfreeze->type);
        $this->assertGreaterThanOrEqual(2, count($sink->get_messages()));
    }

    /**
     * A refusal must leave nothing stranded behind it: the group lock
     * is released, the queue is unchanged, and the next actor on that
     * team can work immediately. Without that, one refused request
     * would block the team until the lock timed out.
     *
     * (The transaction itself is deliberately not asserted on here.
     * Moodle's PHPUnit wraps every test in its own transaction, so
     * is_transaction_started() is true throughout and cannot tell the
     * service's transaction from the harness's own.)
     */
    public function test_refusals_leave_nothing_stranded(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap one member',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);

        $refusals = [
            // Duplicate: throws inside file()'s transaction, under the group lock.
            fn() => tickets::file(
                $activity,
                $group,
                tickets::TYPE_COMPCHANGE,
                'Again',
                FORMAT_PLAIN,
                (int) $guide->id
            ),
            // Already claimed: throws inside claim()'s transaction.
            fn() => tickets::claim($activity, (int) $ticket->id, (int) $manager->id),
            // Not the claimant: throws inside close()'s transaction.
            //
            // The actor is the MANAGER, not the leader, and that is
            // what keeps this case in the transaction (audit A-5).
            // close() now re-asks the queue-worker authority before it
            // takes the lock, so a student is turned away at the door
            // and would never reach the claimant check this case exists
            // to unwind. The manager holds :manage, passes that door,
            // and is still refused inside - they did not claim this
            // ticket, and force-RELEASE is the only outcome their
            // capability buys them.
            fn() => tickets::close(
                $activity,
                (int) $ticket->id,
                tickets::STATUS_RESOLVED,
                'mine',
                FORMAT_PLAIN,
                (int) $manager->id
            ),
        ];
        foreach ($refusals as $index => $refusal) {
            try {
                $refusal();
                $this->fail('Expected a refusal from case ' . $index);
            } catch (\moodle_exception $e) {
                $this->assertStringStartsWith('refusal', $e->errorcode);
            }
            // The refusal wrote nothing: still exactly the one ticket.
            $this->assertSame(
                1,
                $DB->count_records('selfselectadvanced_ticket', ['activityid' => $activity->id()]),
                'Refusal ' . $index . ' left a row behind'
            );
        }

        // The group lock is free: a freeze on the same team still
        // takes it without waiting.
        $frozen = freeze::freeze_group($activity, groups::get($activity, (int) $group->id), (int) $guide->id);
        $this->assertSame(state::FROZEN, $frozen->state);
    }

    /**
     * Filing judges the team as it is when the lock is granted, not as
     * the caller last saw it. A request built from a stale row - the
     * page was loaded before a manager unfroze the team, or before a
     * handover moved the guide - is refused rather than queued against
     * a team it no longer describes.
     */
    public function test_filing_judges_the_group_under_the_lock(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide] = $this->setup_world();

        // The caller's copy says FROZEN; the database has moved on.
        $stale = clone $group;
        $stale->state = state::FROZEN;
        $this->assert_refused('refusalwrongstate', fn() => tickets::file(
            $activity,
            $stale,
            tickets::TYPE_UNFREEZE,
            'the page was loaded before the unfreeze',
            FORMAT_PLAIN,
            (int) $leader->id
        ));

        // The caller's copy still names them guide; the team has a new one.
        $former = clone $group;
        $DB->set_field('selfselectadvanced_group', 'guideid', $leader->id, ['id' => $group->id]);
        $this->assert_refused('refusalticketnotguide', fn() => tickets::file(
            $activity,
            $former,
            tickets::TYPE_COMPCHANGE,
            'handed over while this page was open',
            FORMAT_PLAIN,
            (int) $guide->id
        ));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_ticket', ['activityid' => $activity->id()]));
    }

    /**
     * The claim is exclusive: the first taker wins, the second is
     * refused and told who holds it; a closed ticket cannot be
     * claimed at all.
     */
    public function test_claim_is_exclusive(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap one member',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $claimed = tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);
        $this->assertSame(tickets::STATUS_CLAIMED, $claimed->status);
        $this->assertSame((int) $coordinator->id, (int) $claimed->claimedby);

        // The second taker is refused, with the claimant's name.
        try {
            tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
            $this->fail('Expected refusalticketclaimed');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketclaimed', $e->errorcode);
            $this->assertStringContainsString(fullname($coordinator), $e->getMessage());
        }

        // Nothing was shared: the row still belongs to the first taker.
        $row = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticket->id]);
        $this->assertSame((int) $coordinator->id, (int) $row->claimedby);

        // A resolved ticket is not claimable either.
        tickets::close($activity, (int) $ticket->id, tickets::STATUS_RESOLVED, 'Done', FORMAT_PLAIN, (int) $coordinator->id);
        $this->assert_refused('refusalticketclaimed', fn() => tickets::claim(
            $activity,
            (int) $ticket->id,
            (int) $manager->id
        ));
    }

    /**
     * Closing: only the claimant resolves or declines, with a note;
     * releasing needs no note; a manager may force-release someone
     * else's claim but not resolve it in their name; an unclaimed
     * ticket cannot be closed.
     */
    public function test_close_and_release_authority(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap one member',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->assert_refused('refusalticketnotclaimed', fn() => tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'note',
            FORMAT_PLAIN,
            (int) $manager->id
        ));

        tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);
        $this->assert_refused('refusalticketreason', fn() => tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_DECLINED,
            '  ',
            FORMAT_PLAIN,
            (int) $coordinator->id
        ));
        // The manager is not the claimant: resolving in the
        // coordinator's name is refused, releasing is allowed.
        $this->assert_refused('refusalticketnotclaimant', fn() => tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'mine now',
            FORMAT_PLAIN,
            (int) $manager->id
        ));
        $released = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_OPEN,
            '',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->assertSame(tickets::STATUS_OPEN, $released->status);
        $this->assertNull($released->claimedby);

        // Back in the queue: the manager takes and declines it.
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $declined = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_DECLINED,
            'Not before the review',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->assertSame(tickets::STATUS_DECLINED, $declined->status);
        $this->assertSame('Not before the review', $declined->resolution);
        $this->assertSame((int) $manager->id, (int) $declined->resolvedby);
    }

    /**
     * The conflict-of-interest guard: a coordinator (no manage) is
     * refused on any team they guide, are nominated to guide, or
     * belong to; a manager with the same involvement is exempt.
     */
    public function test_conflict_of_interest_guard(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap one member',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        // As the assigned guide.
        $DB->set_field('selfselectadvanced_group', 'guideid', $coordinator->id, ['id' => $group->id]);
        $this->assert_refused('refusalcoiinvolved', fn() => tickets::claim(
            $activity,
            (int) $ticket->id,
            (int) $coordinator->id
        ));

        // As the nominated successor guide.
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'guidesuccessorid', $coordinator->id, ['id' => $group->id]);
        $this->assert_refused('refusalcoiinvolved', fn() => tickets::claim(
            $activity,
            (int) $ticket->id,
            (int) $coordinator->id
        ));

        // As a confirmed member.
        $DB->set_field('selfselectadvanced_group', 'guidesuccessorid', null, ['id' => $group->id]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $coordinator->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $this->assert_refused('refusalcoiinvolved', fn() => tickets::claim(
            $activity,
            (int) $ticket->id,
            (int) $coordinator->id
        ));

        // The manager guides the team AND may still claim: manage is
        // accountable by role, not by the involvement rule.
        $DB->set_field('selfselectadvanced_group', 'guideid', $manager->id, ['id' => $group->id]);
        $claimed = tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $this->assertSame((int) $manager->id, (int) $claimed->claimedby);
    }

    /**
     * A direct unfreeze resolves the live unfreeze ticket, so the
     * queue never lists work already done; the composition-change
     * ticket of the same team is untouched.
     */
    public function test_direct_unfreeze_autoresolves(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide, $manager] = $this->setup_world();

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $this->assertSame(state::FROZEN, $frozen->state);

        $unfreezeticket = tickets::file(
            $activity,
            $frozen,
            tickets::TYPE_UNFREEZE,
            'Member left the course',
            FORMAT_PLAIN,
            (int) $leader->id
        );
        $compticket = tickets::file(
            $activity,
            $frozen,
            tickets::TYPE_COMPCHANGE,
            'And swap one in',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        freeze::unfreeze($activity, $frozen, (int) $manager->id);

        $resolved = $DB->get_record('selfselectadvanced_ticket', ['id' => $unfreezeticket->id]);
        $this->assertSame(tickets::STATUS_RESOLVED, $resolved->status);
        $this->assertSame((int) $manager->id, (int) $resolved->resolvedby);
        $this->assertSame(
            get_string('ticketautoresolved', 'mod_selfselectadvanced'),
            $resolved->resolution
        );
        $untouched = $DB->get_record('selfselectadvanced_ticket', ['id' => $compticket->id]);
        $this->assertSame(tickets::STATUS_OPEN, $untouched->status);
    }

    /**
     * Queue order: open tickets first come first served, then the
     * claimed one, then closed.
     */
    public function test_queue_order(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide, $manager] = $this->setup_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Three teams, three tickets filed in order.
        $t1 = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'first', FORMAT_PLAIN, (int) $guide->id);
        $others = [];
        foreach (['Second', 'Third'] as $name) {
            $g = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $leader->id,
                'name' => $name,
                'state' => state::FIRM,
                'guideid' => (int) $guide->id,
                'timeapproved' => time(),
            ]);
            $others[] = tickets::file(
                $activity,
                groups::get($activity, (int) $g->id),
                tickets::TYPE_COMPCHANGE,
                'next',
                FORMAT_PLAIN,
                (int) $guide->id
            );
        }
        [$t2, $t3] = $others;
        // Force distinct filing times, oldest first.
        $DB->set_field('selfselectadvanced_ticket', 'timecreated', 1000, ['id' => $t1->id]);
        $DB->set_field('selfselectadvanced_ticket', 'timecreated', 2000, ['id' => $t2->id]);
        $DB->set_field('selfselectadvanced_ticket', 'timecreated', 3000, ['id' => $t3->id]);

        // Claim the oldest, resolve the newest.
        tickets::claim($activity, (int) $t1->id, (int) $manager->id);
        tickets::claim($activity, (int) $t3->id, (int) $manager->id);
        tickets::close($activity, (int) $t3->id, tickets::STATUS_RESOLVED, 'done', FORMAT_PLAIN, (int) $manager->id);

        $ids = array_map(static fn($t) => (int) $t->id, array_values(tickets::queue($activity)));
        $this->assertSame([(int) $t2->id, (int) $t1->id, (int) $t3->id], $ids);
    }

    /**
     * A coordinator is not shown the requests they filed themselves
     * (strategy 1.17 A3). They would be refused if they tried to take
     * one up; leaving it out of their queue removes the invitation to
     * try. A manager still sees everything - somebody has to answer.
     */
    public function test_own_requests_are_not_in_your_own_queue(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide, $manager, $coordinator] = $this->setup_world();

        // The coordinator guides a team of their own and files for it.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $own = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Guided by the coordinator',
            'state' => state::FIRM,
            'guideid' => (int) $coordinator->id,
            'timeapproved' => time(),
        ]);
        $theirs = tickets::file(
            $activity,
            groups::get($activity, (int) $own->id),
            tickets::TYPE_COMPCHANGE,
            'a member of my team has stopped attending',
            FORMAT_PLAIN,
            (int) $coordinator->id
        );
        $someoneelses = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'swap one member',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $theirqueue = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, (int) $coordinator->id))
        );
        $this->assertNotContains((int) $theirs->id, $theirqueue);
        $this->assertContains((int) $someoneelses->id, $theirqueue);

        // The manager's queue still holds both.
        $managerqueue = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, (int) $manager->id))
        );
        $this->assertContains((int) $theirs->id, $managerqueue);
        $this->assertContains((int) $someoneelses->id, $managerqueue);
    }

    /**
     * A coordinator may grant exceptions, but not to themselves and not
     * on a team they are involved in (strategy 1.17 B1). The guard sits
     * at the store, so no page can route round it.
     */
    public function test_coordinator_overrides_except_where_involved(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide, $manager, $coordinator] = $this->setup_world();

        // The role carries the capability now.
        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:override',
            $activity->context(),
            (int) $coordinator->id
        ));

        // An exception for somebody else's team is theirs to grant.
        $granted = \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $group->id,
            ['maxsize' => 7],
            (int) $coordinator->id
        );
        $this->assertSame(7, (int) $granted->maxsize);

        // Not one for themselves.
        $this->assert_refused('refusalcoiself', fn() => \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'guide',
            (int) $coordinator->id,
            ['maxguided' => 99],
            (int) $coordinator->id
        ));

        // Nor one for a team they guide.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $own = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Theirs to guide',
            'state' => state::FIRM,
            'guideid' => (int) $coordinator->id,
            'timeapproved' => time(),
        ]);
        $this->assert_refused('refusalcoiinvolved', fn() => \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $own->id,
            ['maxsize' => 7],
            (int) $coordinator->id
        ));

        // A manager is exempt, on their own record included.
        $exempt = \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'guide',
            (int) $manager->id,
            ['maxguided' => 12],
            (int) $manager->id
        );
        $this->assertSame(12, (int) $exempt->maxguided);
    }

    /**
     * The role exists after install with its capability set at system
     * context, assignable at ACTIVITY level only (1.20.1 - it does work
     * inside one activity and nowhere else); ensure() is idempotent and
     * never duplicates it.
     */
    public function test_coordinator_role_created(): void {
        global $DB;
        $this->resetAfterTest();

        $roleid = coordinatorrole::ensure();
        $this->assertSame($roleid, coordinatorrole::ensure());
        $this->assertSame(1, $DB->count_records('role', ['shortname' => coordinatorrole::SHORTNAME]));

        $levels = array_map('intval', array_values(get_role_contextlevels($roleid)));
        $this->assertSame([CONTEXT_MODULE], $levels);

        $systemcontext = \context_system::instance();
        foreach (
            [
            'mod/selfselectadvanced:coordinate',
            'mod/selfselectadvanced:guide',
            'mod/selfselectadvanced:viewall',
            'mod/selfselectadvanced:freeze',
            'mod/selfselectadvanced:unfreeze',
            ] as $capability
        ) {
            $this->assertTrue($DB->record_exists('role_capabilities', [
                'roleid' => $roleid,
                'capability' => $capability,
                'contextid' => $systemcontext->id,
                'permission' => CAP_ALLOW,
            ]), $capability . ' missing from the coordinator role');
        }
    }

    /**
     * The coordinator handles freeze and unfreeze on behalf of teams
     * they are NOT involved in; on a team they guide, the same acts
     * are refused with the involvement named. A plain teacher who is
     * neither the assigned guide nor privileged stays refused as
     * before.
     */
    public function test_coordinator_freeze_unfreeze_with_coi(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, , $guide, , $coordinator] = $this->setup_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // A second firm team, guided by the coordinator.
        $own = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Guided by the coordinator',
            'state' => state::FIRM,
            'guideid' => (int) $coordinator->id,
            'timeapproved' => time(),
        ]);
        $own = groups::get($activity, (int) $own->id);

        // A teacher with no coordinate/manage and no assignment on the
        // team: refused as before.
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($outsider->id, (int) $activity->cm()->course, 'teacher');
        $this->assert_refused('refusalnotassignedguide', fn() => freeze::freeze_group(
            $activity,
            $group,
            (int) $outsider->id
        ));

        // The coordinator freezes the unrelated team on behalf.
        $frozen = freeze::freeze_group($activity, $group, (int) $coordinator->id);
        $this->assertSame(state::FROZEN, $frozen->state);

        // ... but never their own guided team through the on-behalf
        // door (as its assigned guide they use the guide workflow).
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $own->id]);
        $plugingen->create_member([
            'groupid' => $own->id,
            'userid' => (int) $coordinator->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $this->assert_refused('refusalcoiinvolved', fn() => freeze::freeze_group(
            $activity,
            groups::get($activity, (int) $own->id),
            (int) $coordinator->id
        ));

        // Unfreeze: refused on the team they belong to, granted on the
        // unrelated one.
        $DB->set_field('selfselectadvanced_group', 'state', state::FROZEN, ['id' => $own->id]);
        $this->assert_refused('refusalcoiinvolved', fn() => freeze::unfreeze(
            $activity,
            groups::get($activity, (int) $own->id),
            (int) $coordinator->id
        ));
        $unfrozen = freeze::unfreeze($activity, $frozen, (int) $coordinator->id);
        $this->assertSame(state::FIRM, $unfrozen->state);
    }

    /**
     * Regression pin: the conflict-of-interest rule restrains the new
     * coordinate authority only. A team's own guide, who could
     * unfreeze their team before 1.16.0, still can - installing the
     * coordinator role must never quietly take authority away.
     */
    public function test_guide_keeps_unfreeze_of_their_own_team(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide] = $this->setup_world();

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $this->assertSame(state::FROZEN, $frozen->state);

        $unfrozen = freeze::unfreeze($activity, $frozen, (int) $guide->id);
        $this->assertSame(state::FIRM, $unfrozen->state);
    }
}
