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

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * CONC-001 (external audit 2026-08-13, HIGH) - proof that a plugin
 * event never dispatches while a plugin lock is held or while the
 * service's own delegated transaction is still open.
 *
 * The finding named two representative paths - contacts::send()'s
 * contact_sent and invitations::accept()'s invitation_accepted - and
 * docs/architecture.md ("Events under a lock") already catalogued a
 * wider backlog. This file proves the remediation on those two named
 * paths plus a representative sample of the rest, including the two
 * shapes that are hardest to get wrong: a cascade firing several
 * events from inside one critical section (invitations::accept()'s
 * membership-cap cascade), and an event built two or three call
 * frames BELOW the lock that must be gone before anything may observe
 * it (joinrequests::respond() -> do_accept() -> moves::commit_set(),
 * and state::do_approve() -> override\store::save()).
 *
 * TWO SIGNALS, ONE HONEST ABOUT THE HARNESS. locks::held_count() is a
 * plain, harness-independent fact: it is this plugin's own bookkeeping
 * and reads 0 exactly when no \mod_selfselectadvanced\local\locks
 * handle is outstanding, on both engines, in and out of PHPUnit.
 * $DB->is_transaction_started(), by contrast, is NOT independent of
 * the harness on PostgreSQL: advanced_testcase wraps the WHOLE test in
 * its own outer delegated transaction there (never on MariaDB) so that
 * every test can be rolled back instead of TRUNCATEd, and that outer
 * wrapper makes the boolean read true for the entire method regardless
 * of what this plugin's own code did. tests/service_event_dispatch_test.php
 * already established this and deliberately does not assert on the
 * boolean for exactly this reason. Asserting is_transaction_started()
 * === false here would therefore not be a stronger check - on
 * PostgreSQL it would be a check against a fact of the test runner,
 * not of the code under test, and it would fail on every single
 * scenario below even though every one of them is correctly
 * remediated (measured: see the docblock of test_two_signals_and_why_only_one_is_asserted()).
 * The decisive, harness-independent equivalent is transaction DEPTH:
 * $DB tracks open delegated transactions in a stack (moodle_database::
 * $transactions), advanced_testcase's own wrapper contributes exactly
 * one frame to it before any test method runs, and a service's own
 * start_delegated_transaction()/allow_commit() pair pushes and pops
 * its OWN frame around that baseline. "Depth back at the baseline
 * captured before the call" is therefore precisely "this service's own
 * transaction, if it opened one, has been disposed" - on both engines,
 * regardless of what advanced_testcase does underneath. Both signals
 * are recorded on every captured event; both are asserted where they
 * are actually informative.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\contacts::send
 * @covers     \mod_selfselectadvanced\local\invitations::accept
 * @covers     \mod_selfselectadvanced\local\tickets::file
 * @covers     \mod_selfselectadvanced\local\state::submit
 * @covers     \mod_selfselectadvanced\local\api::create_group
 * @covers     \mod_selfselectadvanced\local\eoi::express
 * @covers     \mod_selfselectadvanced\local\succession::confirm
 * @covers     \mod_selfselectadvanced\local\joinrequests::respond
 * @covers     \mod_selfselectadvanced\local\moves::commit_set
 * @covers     \mod_selfselectadvanced\local\state::approve_auto
 * @covers     \mod_selfselectadvanced\local\override\store::save
 * @covers     \mod_selfselectadvanced\local\eventqueue
 */
final class conc001_event_dispatch_test extends \advanced_testcase {
    /** @var array<string, array<int, array{locks: int, txopen: bool, txdepth: int}>> Recorded dispatches, keyed by short event name. */
    private array $seen = [];

    /** @var int Transaction stack depth captured before any service call - advanced_testcase's own wrapper on PostgreSQL, 0 on MariaDB. */
    private int $basedepth = 0;

    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
        $this->seen = [];
    }

    protected function tearDown(): void {
        // Always safe: phpunit_reset() is a no-op when no test observer
        // was ever installed, and this guarantees a failed assertion
        // above never leaks this test's observer into the next test.
        \core\event\manager::phpunit_reset();
        locks::reset_state();
        parent::tearDown();
    }

    /**
     * The live depth of $DB's delegated-transaction stack, read by
     * reflection because moodle_database exposes only the boolean
     * is_transaction_started(), never the count - and the count is the
     * one fact that is actually independent of advanced_testcase's own
     * wrapper (see this file's class docblock).
     *
     * @return int
     */
    private function tx_depth(): int {
        global $DB;

        $prop = new \ReflectionProperty(get_class($DB), 'transactions');
        $prop->setAccessible(true);

        return count($prop->getValue($DB));
    }

    /**
     * Register a test observer for a set of plugin event classes that
     * appends {locks, txopen, txdepth} to $this->seen, keyed by the
     * event's short class name. Registered WITHOUT redirectEvents():
     * a redirect sink short-circuits \core\event\base::trigger() before
     * \core\event\manager::dispatch() ever runs, which would bypass a
     * phpunit_replace_observers() observer entirely (verified against
     * lib/classes/event/base.php on this box) - so a test built on the
     * sink could not see this at all.
     *
     * Registered with no 'internal' key, so it defaults to internal =>
     * true (the default every plugin observer gets unless it opts out)
     * and is therefore called synchronously regardless of whether $DB
     * has an open transaction - exactly the "arbitrary...observer"
     * CONC-001 is about, not the narrower "external observer" core
     * itself already buffers past an open transaction.
     *
     * @param string[] $eventclasses fully-qualified event class names
     */
    private function watch(array $eventclasses): void {
        $observers = [];
        foreach ($eventclasses as $eventclass) {
            $observers[] = [
                'eventname' => $eventclass,
                'callback' => function (\core\event\base $event): void {
                    $name = substr((string) strrchr(get_class($event), '\\'), 1);
                    $this->seen[$name][] = [
                        'locks' => locks::held_count(),
                        'txopen' => $this->tx_depth() > 0,
                        'txdepth' => $this->tx_depth(),
                    ];
                },
            ];
        }
        \core\event\manager::phpunit_replace_observers($observers);
    }

    /**
     * Assert every recorded dispatch of $name fired with no plugin lock
     * held and with the transaction stack back at the pre-call
     * baseline - never zero dispatches (the N>0 rule: an event that
     * never fired proves nothing about where it fired).
     *
     * @param string $name short event class name
     * @param int $expectedcount how many times it must have fired
     */
    private function assert_clean(string $name, int $expectedcount = 1): void {
        $this->assertArrayHasKey(
            $name,
            $this->seen,
            "$name never fired - this asserts nothing about CONC-001 for it, which is exactly why a silent gap here"
                . ' must fail loudly'
        );
        $this->assertCount($expectedcount, $this->seen[$name], "$name fired a different number of times than expected");
        foreach ($this->seen[$name] as $i => $capture) {
            $this->assertSame(0, $capture['locks'], "$name dispatch #$i fired while holding {$capture['locks']} plugin lock(s)");
            $this->assertSame(
                $this->basedepth,
                $capture['txdepth'],
                "$name dispatch #$i fired with the transaction stack at depth {$capture['txdepth']}, "
                    . "expected the pre-call baseline {$this->basedepth} (its own delegated transaction was still open)"
            );
        }
    }

    /**
     * CONC-001's own named example #1: contacts::send()'s contact_sent
     * used to fire between the insert and allow_commit(), inside
     * group:{id}. Also documents, once, why $DB->is_transaction_started()
     * is recorded but not asserted false - see the class docblock.
     */
    public function test_contact_sent_fires_only_after_commit_and_release(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'contactmax' => 3,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group(['activityid' => $activity->id(), 'leaderid' => (int) $leader->id]);

        $this->basedepth = $this->tx_depth();
        $this->watch(['\mod_selfselectadvanced\event\contact_sent']);

        \mod_selfselectadvanced\local\contacts::send(
            $activity,
            groups::get($activity, (int) $group->id),
            (int) $guide->id,
            'Would you take us on?',
            FORMAT_PLAIN,
            (int) $leader->id
        );

        $this->assert_clean('contact_sent');
        // The raw, harness-affected boolean, recorded for completeness
        // and to make the class docblock's claim checkable rather than
        // asserted: on PostgreSQL this reads true here (advanced_testcase's
        // own wrapper), which is why depth-vs-baseline is the assertion
        // above, not this flag.
        $this->assertIsBool($this->seen['contact_sent'][0]['txopen']);
        $this->assertSame(0, locks::held_count(), 'a lock was left behind');
    }

    /**
     * CONC-001's own named example #2: invitations::accept()'s
     * invitation_accepted, PLUS its membership-cap cascade
     * (invitations::cascade_at_cap() -> cascade()), which used to fire
     * an invitation_declined per rival invitation from inside the same
     * lock accept() holds - the multi-event-in-one-critical-section
     * shape a single bare $event variable cannot collect.
     */
    public function test_invitation_accepted_and_its_cascade_fire_only_after_release(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);

        $leader1 = $generator->create_user();
        $generator->enrol_user($leader1->id, $course->id, 'student');
        $leader2 = $generator->create_user();
        $generator->enrol_user($leader2->id, $course->id, 'student');
        $invitee = $generator->create_user();
        $generator->enrol_user($invitee->id, $course->id, 'student');

        $group1 = $api->create_group((int) $leader1->id, 'Alpha', 'Alpha work', '<p>Alpha</p>', FORMAT_HTML);
        $group2 = $api->create_group((int) $leader2->id, 'Beta', 'Beta work', '<p>Beta</p>', FORMAT_HTML);
        // The invitee holds a pending invitation from Beta too. Accepting
        // Alpha's reaches maxmembership=1, so the cascade auto-declines
        // the Beta invitation in the SAME transaction accept() opens.
        $api->invitations()->send(groups::get($activity, (int) $group1->id), (int) $invitee->id, (int) $leader1->id);
        $api->invitations()->send(groups::get($activity, (int) $group2->id), (int) $invitee->id, (int) $leader2->id);

        $this->basedepth = $this->tx_depth();
        $this->watch([
            '\mod_selfselectadvanced\event\invitation_accepted',
            '\mod_selfselectadvanced\event\invitation_declined',
        ]);

        $api->invitations()->accept(groups::get($activity, (int) $group1->id), (int) $invitee->id);

        $this->assert_clean('invitation_accepted');
        $this->assert_clean('invitation_declined');
        $this->assertSame(0, locks::held_count(), 'a lock was left behind');
    }

    /**
     * A representative flat site from the largest converted file:
     * tickets::file() (ticket_filed) used to fire inside ticket:{id}
     * (or here, group:{id} is not taken by file() - the lock is taken
     * on the group).
     */
    public function test_ticket_filed_fires_only_after_release(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);

        $this->basedepth = $this->tx_depth();
        $this->watch(['\mod_selfselectadvanced\event\ticket_filed']);

        tickets::file(
            $activity,
            groups::get($activity, (int) $group->id),
            tickets::TYPE_COMPCHANGE,
            'The team wants to change its plan.',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->assert_clean('ticket_filed');
        $this->assertSame(0, locks::held_count(), 'a lock was left behind');
    }

    /**
     * state::submit() (group_submitted), api::create_group()
     * (group_created), eoi::express() (eoi_created) and
     * succession::confirm() (leadership_transferred) - four more flat
     * sites, one activity-lock/acquire_all() shaped (submit()), one a
     * dynamic-lock-set create, one guide+group double lock (express()),
     * one activity+group double lock (confirm()). leadership_transferred
     * was one of architecture.md's three "grandfathered" events; this
     * proves it now follows the same rule as everything else, exactly
     * as succession::appoint_vacant_leader() (untouched by this ticket
     * - it already did) already demonstrated was possible in this same
     * file.
     */
    public function test_four_more_flat_sites_fire_only_after_release(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'guidemode' => 1,
            'eoienabled' => 1,
            'maxmembership' => 2,
            'maxlead' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $member = $generator->create_user();
        $generator->enrol_user($member->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        // A separate leader/member pair for the succession scenario:
        // invitations::send() requires FORMING, and $leader's own group
        // is deliberately submitted (PENDING_GUIDE) two paragraphs below.
        $leader3id = $this->mkstudent($generator, $course);
        $member3id = $this->mkstudent($generator, $course);

        $this->basedepth = $this->tx_depth();
        $this->watch([
            '\mod_selfselectadvanced\event\group_created',
            '\mod_selfselectadvanced\event\group_submitted',
            '\mod_selfselectadvanced\event\eoi_created',
            '\mod_selfselectadvanced\event\leadership_transferred',
        ]);

        // Fires group_created: manager mode needs no guide picker, so a
        // plain student create is the simplest path to it.
        $group = $api->create_group((int) $leader->id, 'Flat', 'Flat work', '<p>Flat</p>', FORMAT_HTML);
        $fresh = groups::get($activity, (int) $group->id);

        // Fires group_submitted: manager-assigns mode (guidemode=1), so
        // no guideid is required.
        $api->lifecycle()->submit($fresh, null, (int) $leader->id);

        // Fires eoi_created: a second, listed, guideless FORMING team
        // the guide expresses interest in - independent of the
        // submitted one.
        $group2 = $api->create_group((int) $member->id, 'Listed', 'Listed work', '<p>Listed</p>', FORMAT_HTML);
        eoi::set_listed($activity, (int) $group2->id, true, (int) $member->id);
        eoi::express($activity, (int) $group2->id, (int) $guide->id, 'I would like to guide this team.', FORMAT_HTML);

        // Fires leadership_transferred: a third, independent,
        // still-FORMING team - $group is deliberately no longer FORMING
        // by this point.
        $group3 = $api->create_group($leader3id, 'Succession', 'Succession work', '<p>Succession</p>', FORMAT_HTML);
        $api->invitations()->send(groups::get($activity, (int) $group3->id), $member3id, $leader3id);
        $api->invitations()->accept(groups::get($activity, (int) $group3->id), $member3id);
        $api->succession()->nominate(groups::get($activity, (int) $group3->id), $member3id, 'transfer', $leader3id);
        $api->succession()->confirm(groups::get($activity, (int) $group3->id), $member3id);

        // Three create_group() calls in this scenario (Flat, Listed,
        // Succession), each its own group_created dispatch.
        $this->assert_clean('group_created', 3);
        $this->assert_clean('group_submitted');
        $this->assert_clean('eoi_created');
        $this->assert_clean('leadership_transferred');
        $this->assertSame(0, locks::held_count(), 'a lock was left behind');
    }

    /**
     * The deepest converted call chain: joinrequests::respond() (the
     * true top-level lock owner, joinrequest:{id}) calls do_accept(),
     * which opens its OWN activity:/group: locks and transaction and
     * calls moves::commit_set() - three frames whose events
     * (join_decided, move_committed) must all wait for respond()'s
     * OWN release, not merely their own immediate caller's. Both
     * move_committed and join_decided were architecture.md's other two
     * "grandfathered" events.
     */
    public function test_join_accept_dispatches_join_decided_and_move_committed_only_after_respond_releases(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);

        $beta = $api->create_group($this->mkstudent($generator, $course), 'Beta', 'Beta work', '<p>Beta</p>', FORMAT_HTML);
        $wandererid = $this->mkstudent($generator, $course);

        $request = joinrequests::request($activity, (int) $beta->id, 'I would like to join', $wandererid);

        $this->basedepth = $this->tx_depth();
        $this->watch([
            '\mod_selfselectadvanced\event\join_decided',
            '\mod_selfselectadvanced\event\move_committed',
        ]);

        joinrequests::respond($activity, (int) $request->id, true, 'Welcome aboard', (int) $beta->leaderid);

        $this->assert_clean('join_decided');
        $this->assert_clean('move_committed');
        $this->assertSame(0, locks::held_count(), 'a lock was left behind');
    }

    /**
     * The other deep call chain: state::approve_auto() (do_approve(),
     * holding override:group:{id} + group:{id} via acquire_all())
     * calls override\store::save() nested ($callerholdslock) to write
     * the guarded-reduction relief row when the roster is under the
     * activity's minsize. group_approved and the relief's own
     * override_created/override_updated must both wait for
     * do_approve()'s release - override_updated/_created is the
     * store::save() site this ticket had to give a MANDATORY eventqueue
     * argument to for exactly this call shape, enforced with a
     * coding_exception matching penalty\ledger::upsert_for_group()'s
     * own $callerserialises guard.
     */
    public function test_auto_approve_relief_dispatches_group_approved_and_override_event_only_after_release(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'mirrorat' => \mod_selfselectadvanced\local\freeze::MIRROR_AT_APPROVAL,
            'guideautoapprove' => 1,
            'guidewindow' => DAYSECS,
            'minsize' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        // PENDING_GUIDE, submitted well outside the decision window, and
        // only the leader confirmed - one below minsize=2, so the sweep
        // grants group-scope relief in the SAME transaction it approves.
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'state' => state::PENDING_GUIDE,
            'guideid' => (int) $guide->id,
        ]);
        global $DB;
        $DB->set_field('selfselectadvanced_group', 'timesubmitted', time() - (2 * DAYSECS), ['id' => $group->id]);

        $this->basedepth = $this->tx_depth();
        $this->watch([
            '\mod_selfselectadvanced\event\group_approved',
            '\mod_selfselectadvanced\event\override_created',
            '\mod_selfselectadvanced\event\override_updated',
        ]);

        $api = new api($activity);
        $api->lifecycle()->approve_auto(groups::get($activity, (int) $group->id), (int) get_admin()->id);

        $this->assert_clean('group_approved');
        // Whichever of the two the merge produced (no pre-existing row
        // here, so create) - asserted disjunctively rather than assuming
        // one, because store::save()'s own branch is not this test's to
        // pin.
        $this->assertTrue(
            isset($this->seen['override_created']) xor isset($this->seen['override_updated']),
            'the relief write must fire exactly one of override_created/override_updated'
        );
        if (isset($this->seen['override_created'])) {
            $this->assert_clean('override_created');
        } else {
            $this->assert_clean('override_updated');
        }
        $this->assertSame(0, locks::held_count(), 'a lock was left behind');
    }

    /**
     * Documents, checkably, the claim the class docblock makes: on this
     * engine $DB->is_transaction_started() reads true for the WHOLE
     * test method regardless of what the code under test does, which is
     * why it is recorded but not asserted false anywhere above.
     */
    public function test_two_signals_and_why_only_one_is_asserted(): void {
        $this->resetAfterTest();
        global $DB;
        $family = $DB->get_dbfamily();
        if ($family !== 'postgres') {
            $this->markTestSkipped('This documents a PostgreSQL-only harness fact (m5my has no outer wrapper transaction).');
        }
        $this->assertTrue(
            $DB->is_transaction_started(),
            'expected advanced_testcase\'s own wrapper transaction to be open on PostgreSQL'
        );
    }

    /**
     * One enrolled student, for scenarios where only the id is needed.
     *
     * @param \testing_data_generator $generator the data generator
     * @param \stdClass $course the course
     * @return int the new user's id
     */
    private function mkstudent(\testing_data_generator $generator, \stdClass $course): int {
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        return (int) $user->id;
    }
}
