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
use mod_selfselectadvanced\local\notifier;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * Refusal cleanup: a service that throws from inside its own delegated
 * transaction must roll that transaction back before the exception
 * leaves the method.
 *
 * WHY THIS FILE EXISTS. Moodle rolls an open delegated transaction back
 * automatically only when the exception reaches the default exception
 * handler. Every page in this plugin catches moodle_exception and
 * redirects with a notification instead, and so do the tests, so a
 * refusal thrown between start_delegated_transaction() and
 * allow_commit() is NOT unwound by anything unless the service unwinds
 * it itself. Left open, the physical transaction survives to the end of
 * the request and every later write is discarded when the connection
 * closes - silently, with no error anywhere.
 *
 * WHY THE ASSERTIONS LOOK THE WAY THEY DO. Two engines, two harness
 * behaviours: advanced_testcase wraps every test in a delegated
 * transaction of its own on PostgreSQL (and on mssql) and NOT on MariaDB
 * - see setup_test_environment() in lib/phpunit/classes/advanced_testcase
 * .php, which starts one only for those families. So
 * $DB->is_transaction_started() answers for the HARNESS on m5pg and for
 * the code under test on m5my, and any production branch keyed on it
 * takes a different arm per engine while the suite stays green on both.
 * Nothing here is allowed to depend on which engine it runs on:
 *
 *  - the outermost case is forced with preventResetByRollback(), which
 *    commits the harness transaction so the service really is the
 *    outermost owner on BOTH engines;
 *  - the nested case is forced by opening a transaction in the test
 *    itself, so the service really is nested on BOTH engines.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\eoi
 * @covers     \mod_selfselectadvanced\local\handover
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\moves
 * @covers     \mod_selfselectadvanced\local\notifier
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\succession
 */
final class transaction_unwind_test extends \advanced_testcase {
    /** @var string[] Seams whose refusal left a transaction behind. */
    private array $leftopen = [];

    /**
     * A course, an activity, the cast, and one FORMING team.
     *
     * @param array $settings module settings overrides
     * @return array [activity, api, group, leader, member, outsider, guide1, guide2, manager]
     */
    private function setup_world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
            'maxguided' => 2,
            'eoienabled' => 1,
            'eoimax' => 3,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $outsider = $generator->create_user();
        foreach ([$leader, $member, $outsider] as $student) {
            $generator->enrol_user($student->id, $course->id, 'student');
        }
        $guide1 = $generator->create_user();
        $guide2 = $generator->create_user();
        foreach ([$guide1, $guide2] as $guide) {
            $generator->enrol_user($guide->id, $course->id, 'teacher');
        }
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        // The generator's create_group() writes the leader's confirmed member row itself.
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team',
            'state' => state::FORMING,
        ]);

        return [
            $activity,
            new api($activity),
            groups::get($activity, (int) $group->id),
            $leader,
            $member,
            $outsider,
            $guide1,
            $guide2,
            $manager,
        ];
    }

    /**
     * Run one refusal and record whether it left a transaction behind.
     *
     * RECORDED, not asserted on the spot, deliberately: these tests
     * exist to say WHICH seams are unsound, and an assertion that
     * aborts on the first one hides the rest - a negative control that
     * names one method out of six is not evidence about the other five.
     * assert_every_seam_closed_its_transaction() makes the verdict.
     *
     * The errorcode IS asserted immediately, because a refusal raised
     * BEFORE the transaction was opened would satisfy the transaction
     * check while proving nothing. Every gate named by a caller below
     * is one the service re-asks on the row it re-read INSIDE its own
     * lock and transaction.
     *
     * The stack is emptied after a leak so the NEXT seam is judged on
     * its own conduct rather than on its predecessor's residue.
     *
     * @param string $label what is being driven, for the verdict
     * @param string $errorcode the refusal expected
     * @param callable $fn the call that must refuse
     */
    private function probe_refusal(string $label, string $errorcode, callable $fn): void {
        global $DB;

        $this->assertFalse(
            $DB->is_transaction_started(),
            $label . ': this probe must start from an empty transaction stack'
        );
        try {
            $fn();
            $this->fail($label . ': expected refusal ' . $errorcode . ', none was thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $label . ': wrong refusal');
        }
        if ($DB->is_transaction_started()) {
            $this->leftopen[] = $label;
            $DB->force_transaction_rollback();
        }
    }

    /**
     * Drive one refusal with a transaction the TEST owns already, and
     * record whether the caller's own rollback still reaches the
     * database afterwards.
     *
     * That is the only question that separates a rolled-back inner
     * frame from an abandoned one. Moodle's
     * rollback_delegated_transaction() issues the physical ROLLBACK
     * only when the transaction handed to it is the top of $DB's stack.
     * A service that abandons its frame leaves that frame on top, so
     * the caller's rollback() falls into the "better just rethrow"
     * branch, never rolls anything back, and the transaction survives -
     * which is what `$outermost && ...` produced whenever a caller held
     * a transaction. Rolling the inner frame back pops it, and the
     * caller's rollback lands.
     *
     * preventResetByRollback() is required of every caller: without it
     * the harness holds a third frame underneath on PostgreSQL, and the
     * caller could not be the top level on that engine either.
     *
     * @param string $label what is being driven, for the verdict
     * @param string $errorcode the refusal expected
     * @param callable $fn the call that must refuse
     */
    private function probe_nested_refusal(string $label, string $errorcode, callable $fn): void {
        global $DB;

        $this->assertFalse(
            $DB->is_transaction_started(),
            $label . ': this probe must start from an empty transaction stack'
        );
        $outer = $DB->start_delegated_transaction();
        try {
            $fn();
            $this->fail($label . ': expected refusal ' . $errorcode . ', none was thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $label . ': wrong refusal');
        }

        // The service must not commit, roll back or dispose what it
        // does not own.
        $this->assertTrue(
            $DB->is_transaction_started(),
            $label . ': the nested service disposed of the caller transaction'
        );

        $rethrown = null;
        try {
            $outer->rollback(new \Exception('caller unwinds'));
        } catch (\Throwable $t) {
            $rethrown = $t;
        }
        $this->assertInstanceOf(\Exception::class, $rethrown, $label . ': rollback() must rethrow');
        $this->assertSame('caller unwinds', $rethrown->getMessage(), $label . ': wrong exception rethrown');
        if ($DB->is_transaction_started()) {
            $this->leftopen[] = $label;
            $DB->force_transaction_rollback();
        }
    }

    /**
     * The verdict for a set of probes.
     *
     * @param string $what which family of seams was probed
     */
    private function assert_every_seam_closed_its_transaction(string $what): void {
        $this->assertSame(
            [],
            $this->leftopen,
            $what . ': these refusals left the delegated transaction open (the physical transaction'
                . ' survives the request, and every later write is discarded when the connection closes)'
        );
    }

    /**
     * api::dissolve_group() and api::delete_group().
     *
     * delete_group() is driven through the stale-row race its second
     * can_delete_group() call exists for: the caller's copy still says
     * FORMING, the row under the lock says FIRM, so the FIRST gate
     * (before the transaction) passes and the SECOND (inside it)
     * refuses. Without that trick the refusal would be raised before
     * the transaction was ever opened and the assertion would be empty.
     *
     * Negative control: remove the catch/rollback arm from either
     * method and the matching assertion fails.
     */
    public function test_api_refusals_leave_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, $leader, , , , , $manager] = $this->setup_world();

        // For dissolve_group(): an open ticket on the team is refused from
        // inside the transaction, with the activity and group locks held.
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_COMPCHANGE,
            'status' => tickets::STATUS_OPEN,
            'requestedby' => (int) $leader->id,
            'request' => 'Swap a member',
            'requestformat' => FORMAT_PLAIN,
            'resolutionformat' => FORMAT_PLAIN,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->probe_refusal(
            'dissolve_group',
            'errdissolveticket',
            fn() => $api->dissolve_group(
                groups::get($activity, (int) $group->id),
                'Winding up',
                (int) $manager->id
            )
        );

        // For delete_group(): the caller's row still says FORMING.
        $stale = groups::get($activity, (int) $group->id);
        $stale->state = state::FORMING;
        $this->probe_refusal(
            'delete_group',
            'refusalwrongstate',
            fn() => $api->delete_group($stale, (int) $leader->id)
        );

        $this->assert_every_seam_closed_its_transaction('api');
    }

    /**
     * Every invitations verb that opens a transaction and can refuse
     * inside it: send(), accept(), decline(), withdraw(),
     * request_leave() and confirm_leave().
     *
     * send() takes the same stale-row treatment as delete_group() - its
     * can_invite() is asked twice, and only the second ask is inside
     * the transaction. The other five ask their gate ONLY under the
     * lock, so flipping the stored row is enough.
     *
     * Negative control: remove any one catch/rollback arm from
     * invitations.php and the matching assertion fails.
     */
    public function test_invitation_refusals_leave_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, $leader, $member, $outsider] = $this->setup_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $invitations = $api->invitations();

        // The outsider is deliberately left off the roster - send() is the
        // one verb here whose FIRST gate would refuse a member outright,
        // before any transaction exists.
        $joined = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($joined->id, (int) $activity->cm()->course, 'student');

        // An invited member and a confirmed one, both real rows.
        $invited = $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_INVITED,
            'invitedby' => (int) $leader->id,
            'timeinvited' => time(),
        ]);
        $confirmed = $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $joined->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        // The stored row leaves FORMING; every gate re-asked under the
        // lock now refuses, while the caller's copies still say FORMING.
        $stale = groups::get($activity, (int) $group->id);
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);

        $this->probe_refusal(
            'invitations::send',
            'refusalwrongstate',
            fn() => $invitations->send($stale, (int) $outsider->id, (int) $leader->id)
        );
        $this->probe_refusal(
            'invitations::accept',
            'refusalwrongstate',
            fn() => $invitations->accept($stale, (int) $member->id)
        );
        $this->probe_refusal(
            'invitations::withdraw',
            'refusalwrongstate',
            fn() => $invitations->withdraw($stale, (int) $invited->id, (int) $leader->id)
        );
        $this->probe_refusal(
            'invitations::request_leave',
            'refusalwrongstate',
            fn() => $invitations->request_leave($stale, (int) $joined->id)
        );
        $this->probe_refusal(
            'invitations::confirm_leave',
            'refusalwrongstate',
            fn() => $invitations->confirm_leave($stale, (int) $confirmed->id, (int) $leader->id)
        );

        // The decline() verb judges the member row, not the group state: a
        // confirmed member has nothing to decline.
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $group->id]);
        $this->probe_refusal(
            'invitations::decline',
            'refusalnotinvited',
            fn() => $invitations->decline($stale, (int) $joined->id)
        );

        $this->assert_every_seam_closed_its_transaction('invitations');
    }

    /**
     * succession::nominate(), confirm() and the shared clear() body
     * behind decline() and cancel().
     *
     * Negative control: remove any one catch/rollback arm from
     * succession.php and the matching assertion fails.
     */
    public function test_succession_refusals_leave_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, $leader, $member] = $this->setup_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $succession = $api->succession();

        $stale = groups::get($activity, (int) $group->id);
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);

        $this->probe_refusal(
            'succession::nominate',
            'refusalwrongstate',
            fn() => $succession->nominate($stale, (int) $member->id, 'transfer', (int) $leader->id)
        );

        // The confirm() verb needs a nomination to judge; the state gate under
        // the lock still refuses it.
        $DB->set_field('selfselectadvanced_group', 'successorid', (int) $member->id, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'successortype', 'transfer', ['id' => $group->id]);
        $this->probe_refusal(
            'succession::confirm',
            'refusalwrongstate',
            fn() => $succession->confirm($stale, (int) $member->id)
        );

        // The clear() body, reached from decline() and from cancel(): no
        // nomination on the row read under the lock.
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'successorid', null, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'successortype', null, ['id' => $group->id]);
        $withnomination = groups::get($activity, (int) $group->id);
        $withnomination->successorid = (int) $member->id;
        $this->probe_refusal(
            'succession::decline',
            'refusalnotnominee',
            fn() => $succession->decline($withnomination, (int) $member->id)
        );
        $this->probe_refusal(
            'succession::cancel',
            'refusalnotnominee',
            fn() => $succession->cancel($withnomination, (int) $leader->id)
        );

        $this->assert_every_seam_closed_its_transaction('succession');
    }

    /**
     * handover::propose(), accept(), decline() and cancel().
     *
     * Negative control: remove any one catch/rollback arm from
     * handover.php and the matching assertion fails.
     */
    public function test_handover_refusals_leave_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, , , , $guide1, $guide2] = $this->setup_world();
        $handover = $api->handover();

        // A FORMING team has no guide to hand over.
        $this->probe_refusal(
            'handover::propose',
            'refusalhandoverstate',
            fn() => $handover->propose((int) $group->id, (int) $guide2->id, (int) $guide1->id)
        );
        $this->probe_refusal(
            'handover::accept',
            'refusalhandovernonominee',
            fn() => $handover->accept((int) $group->id, (int) $guide2->id)
        );
        $this->probe_refusal(
            'handover::decline',
            'refusalhandovernonominee',
            fn() => $handover->decline((int) $group->id, (int) $guide2->id)
        );

        // The cancel() verb asks "is this your team?" first: with a guide in
        // place but nothing pending, the second gate is the one that
        // trips, and both are inside the transaction.
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guide1->id, ['id' => $group->id]);
        $this->probe_refusal(
            'handover::cancel',
            'refusalhandovernonominee',
            fn() => $handover->cancel((int) $group->id, (int) $guide1->id)
        );

        $this->assert_every_seam_closed_its_transaction('handover');
    }

    /**
     * eoi::express(), withdraw(), respond() and stepout().
     *
     * Negative control: remove any one catch/rollback arm from eoi.php
     * and the matching assertion fails.
     */
    public function test_eoi_refusals_leave_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, , $group, $leader, , , $guide1] = $this->setup_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // For express(): the team is not listed for interest.
        $this->probe_refusal(
            'eoi::express',
            'refusaleoinotlisted',
            fn() => eoi::express($activity, (int) $group->id, (int) $guide1->id)
        );

        // For withdraw() and respond(): a settled interest is not pending.
        $settled = $plugingen->create_eoi([
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'guideid' => (int) $guide1->id,
            'status' => eoi::STATUS_WITHDRAWN,
        ]);
        $this->probe_refusal(
            'eoi::withdraw',
            'refusaleoinotpending',
            fn() => eoi::withdraw($activity, (int) $settled->id, (int) $guide1->id)
        );
        $this->probe_refusal(
            'eoi::respond',
            'refusaleoinotpending',
            fn() => eoi::respond($activity, (int) $settled->id, true, (int) $leader->id)
        );

        // For stepout(): this guide is not the one pre-assigned here.
        $this->probe_refusal(
            'eoi::stepout',
            'refusaleoinotassigned',
            fn() => eoi::stepout($activity, (int) $group->id, (int) $guide1->id)
        );

        $this->assert_every_seam_closed_its_transaction('eoi');
    }

    /**
     * state::do_approve(), behind approve().
     *
     * The other three state verbs - submit(), assign_guide() and
     * return_group() - are pinned the same way in
     * races_locking_test::test_state_refusal_leaves_no_dangling_transaction().
     *
     * Negative control: remove the catch/rollback arm from do_approve()
     * and this fails.
     */
    public function test_approve_refusal_leaves_no_open_transaction(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, , , , $guide1] = $this->setup_world();

        // A FORMING team cannot be approved; can_approve() is asked
        // only on the row read inside the group lock.
        $this->probe_refusal(
            'state::approve',
            'refusalwrongstate',
            fn() => $api->lifecycle()->approve(
                groups::get($activity, (int) $group->id),
                (int) $guide1->id
            )
        );

        $this->assert_every_seam_closed_its_transaction('state::approve');
    }

    /**
     * THE NESTED CASE, for every T-1 site whose $outermost gate was
     * removed except commit_set(), which gets its own test below.
     *
     * No engine chooses this branch for us: the transaction is opened
     * by the test, so each service is genuinely nested on m5pg and on
     * m5my alike. See
     * assert_nested_refusal_unwinds_to_the_caller() for what is being
     * asked and why it is the question that matters.
     *
     * Negative control (this is the whole point of the test): restore
     * `if ($outermost && isset($transaction) ...)` in any one of these
     * seven methods and its final assertion fails, on BOTH engines -
     * the transaction is still open after the caller rolled back.
     */
    public function test_nested_refusals_let_the_callers_rollback_reach_the_database(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, $leader, $member, , $guide1, , $manager] = $this->setup_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $joined = $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $formingcopy = groups::get($activity, (int) $group->id);

        // The state::submit() verb - a FIRM team is past submission.
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $firm = groups::get($activity, (int) $group->id);
        $this->probe_nested_refusal(
            'state::submit',
            'refusalwrongstate',
            fn() => $api->lifecycle()->submit($firm, (int) $guide1->id, (int) $leader->id)
        );

        // The state::approve() verb - a guide may not approve what was never
        // submitted to them.
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $group->id]);
        $this->probe_nested_refusal(
            'state::approve',
            'refusalwrongstate',
            fn() => $api->lifecycle()->approve(groups::get($activity, (int) $group->id), (int) $guide1->id)
        );

        // The state::assign_guide() verb - FORMING is not assignable.
        $this->probe_nested_refusal(
            'state::assign_guide',
            'refusalreassignstate',
            fn() => $api->lifecycle()->assign_guide(
                groups::get($activity, (int) $group->id),
                (int) $guide1->id,
                (int) $manager->id
            )
        );

        // The state::return_group() verb - only a submitted team can be returned.
        $this->probe_nested_refusal(
            'state::return_group',
            'refusalwrongstate',
            fn() => $api->lifecycle()->return_group(
                groups::get($activity, (int) $group->id),
                'Needs work',
                (int) $guide1->id
            )
        );

        // The invitations::request_leave() and confirm_leave() verbs both judge
        // the team as it is under the lock, and it is no longer FORMING.
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $this->probe_nested_refusal(
            'invitations::request_leave',
            'refusalwrongstate',
            fn() => $api->invitations()->request_leave($formingcopy, (int) $member->id)
        );
        $this->probe_nested_refusal(
            'invitations::confirm_leave',
            'refusalwrongstate',
            fn() => $api->invitations()->confirm_leave($formingcopy, (int) $joined->id, (int) $leader->id)
        );

        // The api::dissolve_group() verb - an open request in the queue blocks it.
        $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_COMPCHANGE,
            'status' => tickets::STATUS_OPEN,
            'requestedby' => (int) $leader->id,
            'request' => 'Swap a member',
            'requestformat' => FORMAT_PLAIN,
            'resolutionformat' => FORMAT_PLAIN,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->probe_nested_refusal(
            'api::dissolve_group',
            'errdissolveticket',
            fn() => $api->dissolve_group(
                groups::get($activity, (int) $group->id),
                'Winding up',
                (int) $manager->id
            )
        );

        $this->assert_every_seam_closed_its_transaction('nested refusals');
    }

    /**
     * The same question for moves::commit_set(), which is the one
     * method in the plugin with a real nested production caller
     * (joinrequests::do_accept() holds a transaction across it).
     *
     * Negative control: restore the `$outermost &&` gate in
     * commit_set() and the final assertion fails on both engines.
     */
    public function test_a_nested_commit_set_refusal_unwinds_to_the_caller(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, $leader, $member, $outsider] = $this->setup_world(['minsize' => 2]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $target = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $outsider->id,
            'name' => 'Target',
            'state' => state::FIRM,
        ]);
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);

        // Stage the non-leader's move, then take the leader off the
        // source roster: committing would now empty the source below
        // minsize 2, which validate_set() refuses from INSIDE
        // commit_set()'s transaction (the shape
        // races_locking_test uses for the same refusal).
        $move = $api->moves()->stage(
            (int) $member->id,
            (int) $group->id,
            (int) $target->id,
            false,
            null,
            99
        );
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
            'groupid' => $group->id,
            'userid' => (int) $leader->id,
        ]);

        // Nested exactly as joinrequests::do_accept() nests it: the
        // caller's transaction is open across the whole call.
        $this->probe_nested_refusal(
            'moves::commit_set',
            'errmovesetinvalid',
            fn() => $api->moves()->commit_set([(int) $move->id], 99)
        );

        $this->assert_every_seam_closed_its_transaction('moves::commit_set');
    }

    /**
     * notifier::send()'s strict transaction check is the ONE production
     * read of $DB->is_transaction_started() this wave kept, and it is
     * kept because the engine never chooses its branch: it is opt-in,
     * test-only, and off by default precisely because advanced_testcase
     * holds a transaction on one engine and not the other.
     *
     * All three reachable cells are forced here, so the behaviour is
     * pinned rather than inherited from whichever engine is running.
     *
     * Negative control: drop the `self::$stricttransactioncheck &&`
     * conjunct and cell 1 fails (an unexpected debugging() call);
     * drop the `$DB->is_transaction_started()` conjunct and cell 3
     * fails for the same reason.
     */
    public function test_strict_transaction_check_branches_are_chosen_by_the_test_not_the_engine(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, , , $leader] = $this->setup_world();
        $url = new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);
        $send = function () use ($activity, $leader, $url): void {
            notifier::send(
                $activity,
                'invitationresult',
                (int) $leader->id,
                'msgwithdrawnsubject',
                'msgwithdrawnbody',
                (object) ['user' => 'Someone', 'group' => 'Team'],
                $url,
                'Team'
            );
        };

        // Cell 1: check OFF, transaction OPEN - silent. Opening one
        // here makes the predicate true on MariaDB too.
        $transaction = $DB->start_delegated_transaction();
        $this->assertTrue($DB->is_transaction_started());
        $send();
        $this->assertDebuggingNotCalled();

        // Cell 2: check ON, transaction OPEN - the guard fires.
        notifier::set_strict_transaction_check(true);
        try {
            $send();
            $this->assertDebuggingCalled();
        } finally {
            notifier::set_strict_transaction_check(false);
        }
        $transaction->allow_commit();

        // Cell 3: check ON, NO transaction - silent. Only
        // preventResetByRollback() can produce this cell on PostgreSQL,
        // where the harness would otherwise hold one.
        $this->preventResetByRollback();
        $this->assertFalse($DB->is_transaction_started());
        notifier::set_strict_transaction_check(true);
        try {
            $send();
            $this->assertDebuggingNotCalled();
        } finally {
            notifier::set_strict_transaction_check(false);
        }
    }
}
