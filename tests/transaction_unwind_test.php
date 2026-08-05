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
use mod_selfselectadvanced\local\contacts;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\notifier;
use mod_selfselectadvanced\local\override\store as overridestore;
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
 * @covers     \mod_selfselectadvanced\local\contacts
 * @covers     \mod_selfselectadvanced\local\eoi
 * @covers     \mod_selfselectadvanced\local\freeze
 * @covers     \mod_selfselectadvanced\local\handover
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\moves
 * @covers     \mod_selfselectadvanced\local\notifier
 * @covers     \mod_selfselectadvanced\local\override\store
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\succession
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class transaction_unwind_test extends \advanced_testcase {
    /** @var string[] Seams whose refusal left a transaction behind. */
    private array $leftopen = [];

    /** @var string[] Seams whose refusal survived the CALLER's rollback. */
    private array $notunwound = [];

    /** @var int Marker-row sequence, so every probe reads back its own row. */
    private int $markerseq = 0;

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
     * THE QUESTION C1 IS ABOUT: drive one refusal with a transaction
     * the TEST owns, having first written a row through it, and ask
     * whether the CALLER's own rollback still reaches the DATABASE.
     *
     * probe_nested_refusal() above asks half of this - it checks that
     * $DB's transaction stack is empty afterwards. That is necessary
     * and it is not sufficient: a stack can be emptied by
     * force_transaction_rollback() in a shutdown handler long after the
     * request decided what it had written. The MARKER ROW is the other
     * half, and it is the half a site notices. It is written by the
     * caller, inside the caller's transaction, before the callee is
     * ever entered; if the caller's rollback reached the database the
     * row is gone, and if it did not the row is still there.
     *
     * MECHANISM, read in core (lib/dml/moodle_database.php):
     * rollback_delegated_transaction() issues the physical ROLLBACK
     * only for the frame on top of $DB's stack, and only when that
     * frame is the LAST one - an inner frame pops, sets force_rollback
     * and lets the cascade continue downwards. A callee that ABANDONS
     * its frame leaves it on top, undisposed, so the caller's
     * rollback() fails the identity check, takes the "better just
     * rethrow" branch, and never rolls anything back. The caller's
     * marker row survives a refusal the caller believes it unwound, and
     * commit_delegated_transaction() throws for every later commit in
     * the request.
     *
     * NEITHER ENGINE CHOOSES ANYTHING HERE. The nested arm is forced by
     * opening the transaction in the test, so the service is genuinely
     * nested on m5pg and on m5my alike, and preventResetByRollback() is
     * required of every caller: without it advanced_testcase holds a
     * third frame underneath on PostgreSQL, no rollback in the chain is
     * ever the last one, and the probe would report a failure the code
     * did not commit.
     *
     * RECORDED rather than asserted on the spot, for the reason
     * probe_refusal() gives: a verdict that names one seam out of five
     * is not evidence about the other four.
     *
     * @param string $label what is being driven, for the verdict
     * @param string $errorcode the refusal expected (coding_exception
     *        raises 'codingerror')
     * @param callable $fn the call that must refuse
     */
    private function probe_nested_marker(string $label, string $errorcode, callable $fn): void {
        global $DB;

        $this->assertFalse(
            $DB->is_transaction_started(),
            $label . ': this probe must start from an empty transaction stack'
        );

        // A table no seam under test reads or writes: only
        // local\attributes\depts touches the vocabulary, and none of
        // these services goes near it. The row is the caller's own
        // write, which is the whole point - it is what a cron sweep
        // would have lost.
        $marker = 'unwind-marker-' . (++$this->markerseq);
        $now = time();
        $DB->insert_record('selfselectadvanced_dept', (object) [
            'name' => $marker,
            'kind' => 'dept',
            'parent' => 0,
            'depth' => 1,
            'path' => '/0',
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $outer = $DB->start_delegated_transaction();
        $inside = $DB->insert_record('selfselectadvanced_dept', (object) [
            'name' => $marker . '-inside',
            'kind' => 'dept',
            'parent' => 0,
            'depth' => 1,
            'path' => '/0',
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        unset($inside);
        $this->assertTrue(
            $DB->record_exists('selfselectadvanced_dept', ['name' => $marker . '-inside']),
            $label . ': the caller must be able to see its own uncommitted write'
        );

        try {
            $fn();
            $this->fail($label . ': expected refusal ' . $errorcode . ', none was thrown');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $label . ': wrong refusal');
        }

        // The callee must not dispose of what it does not own.
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
        $this->assertInstanceOf(\Exception::class, $rethrown, $label . ': the caller rollback must rethrow');
        $this->assertSame('caller unwinds', $rethrown->getMessage(), $label . ': wrong exception rethrown');

        // Read BEFORE any cleanup: force_transaction_rollback() would
        // itself remove the row and turn the defect into a pass.
        $open = $DB->is_transaction_started();
        $survived = $DB->record_exists('selfselectadvanced_dept', ['name' => $marker . '-inside']);
        if ($open) {
            $DB->force_transaction_rollback();
        }
        if ($open || $survived) {
            $this->notunwound[] = $label
                . ' (transaction still open: ' . ($open ? 'yes' : 'no')
                . '; caller row survived its own rollback: ' . ($survived ? 'yes' : 'no') . ')';
        }
        // Scope control on the probe itself, asserted on every run: the
        // row written BEFORE the caller transaction must still be
        // there. Without it a probe that reported "marker gone" could
        // be reporting a connection that lost everything, which is a
        // different bug wearing the same result.
        $this->assertTrue(
            $DB->record_exists('selfselectadvanced_dept', ['name' => $marker]),
            $label . ': the row written before the caller transaction must survive its rollback'
        );
        $DB->delete_records_select('selfselectadvanced_dept', 'name = ? OR name = ?', [$marker, $marker . '-inside']);
    }

    /**
     * The verdict for a set of marker probes.
     *
     * @param string $what which family of seams was probed
     */
    private function assert_every_caller_rollback_landed(string $what): void {
        $this->assertSame(
            [],
            $this->notunwound,
            $what . ': the CALLER rolled back and the database did not hear about it. The callee abandoned its'
                . ' delegated frame, so rollback_delegated_transaction() rethrew without issuing the physical'
                . ' ROLLBACK: the caller kept writes it believed it had discarded, and every later commit in the'
                . ' request throws.'
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
     * THE TICKET QUEUE, both arms, for all five seams of tickets.php.
     *
     * Every one of them left through a private rollback() helper gated
     * on an $outermost flag, so a caller that already held a
     * transaction got its frame abandoned rather than rolled back
     * (T-C1). guide_autoapprove.php:183 catches per group and carries
     * on, which is how one refusal turned into a whole sweep that wrote
     * nothing and logged plausible "skipped" lines.
     *
     * Negative control (RUN, both engines): restore
     * `$outermost && ` in tickets::rollback() and every nested
     * assertion here fails with the caller's row still visible after
     * the caller rolled back. The outermost probes stay green under
     * that revert - which is exactly why they were never enough.
     */
    public function test_nested_ticket_refusals_let_the_callers_rollback_reach_the_database(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, , $group, $leader, $member, , $guide1, $guide2, $manager] = $this->setup_world();

        // The file() verb: a FORMING team has nothing to be released
        // from, and the state is judged only on the row read INSIDE
        // the lock and the transaction.
        $file = fn() => tickets::file(
            $activity,
            $group,
            tickets::TYPE_UNFREEZE,
            'Please release us',
            FORMAT_HTML,
            (int) $leader->id
        );
        $this->probe_refusal('tickets::file (outermost)', 'refusalwrongstate', $file);
        $this->probe_nested_marker('tickets::file (nested)', 'refusalwrongstate', $file);

        // The file_guidecap() verb: one live team-limit request at a
        // time, and the duplicate is looked for inside the
        // transaction.
        $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'groupid' => null,
            'type' => tickets::TYPE_GUIDECAP,
            'status' => tickets::STATUS_OPEN,
            'requestedby' => (int) $guide1->id,
            'request' => 'Four teams please',
            'requestformat' => FORMAT_PLAIN,
            'resolutionformat' => FORMAT_PLAIN,
            'requested' => 4,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $guidecap = fn() => tickets::file_guidecap(
            $activity,
            5,
            'Five teams please',
            FORMAT_PLAIN,
            (int) $guide1->id
        );
        $this->probe_refusal('tickets::file_guidecap (outermost)', 'refusalticketduplicate', $guidecap);
        $this->probe_nested_marker('tickets::file_guidecap (nested)', 'refusalticketduplicate', $guidecap);

        // The withdraw() verb: only the requester may, and the
        // requester is read inside the transaction.
        $open = $DB->insert_record('selfselectadvanced_ticket', (object) [
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
        $withdraw = fn() => tickets::withdraw($activity, (int) $open, (int) $member->id);
        $this->probe_refusal('tickets::withdraw (outermost)', 'refusalticketnotyours', $withdraw);
        $this->probe_nested_marker('tickets::withdraw (nested)', 'refusalticketnotyours', $withdraw);

        // The close() verb: only a CLAIMED ticket closes, and the
        // status is read inside the transaction. The one above is
        // still open.
        $close = fn() => tickets::close(
            $activity,
            (int) $open,
            tickets::STATUS_RESOLVED,
            'Done',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->probe_refusal('tickets::close (outermost)', 'refusalticketnotclaimed', $close);
        $this->probe_nested_marker('tickets::close (nested)', 'refusalticketnotclaimed', $close);

        // The claim() verb: somebody else already has it, which the
        // re-read inside the transaction is what discovers.
        $DB->update_record('selfselectadvanced_ticket', (object) [
            'id' => $open,
            'status' => tickets::STATUS_CLAIMED,
            'claimedby' => (int) $guide2->id,
            'timeclaimed' => time(),
        ]);
        $claim = fn() => tickets::claim($activity, (int) $open, (int) $manager->id);
        $this->probe_refusal('tickets::claim (outermost)', 'refusalticketclaimed', $claim);
        $this->probe_nested_marker('tickets::claim (nested)', 'refusalticketclaimed', $claim);

        $this->assert_every_seam_closed_its_transaction('tickets (outermost)');
        $this->assert_every_caller_rollback_landed('tickets (nested)');
    }

    /**
     * THE JOIN QUEUE, both arms.
     *
     * do_accept() is the one that matters most in production: it opens
     * at joinrequests.php:516 and reaches override\store::save()
     * through save_for_new_move() with its own transaction still open,
     * so BOTH ends of that call used to abandon their frames.
     *
     * NOT COVERED, and stated rather than counted: do_decline()'s
     * transaction (the fourth $outermost site in this file) contains an
     * update, an event and the commit and no refusal at all, so no
     * single-threaded test can make it throw from inside. It was
     * converted for symmetry with the other three; it is unverified,
     * not proven.
     *
     * Negative control (RUN, both engines): restore `$outermost && ` in
     * joinrequests::rollback() and both nested assertions fail.
     */
    public function test_nested_join_refusals_let_the_callers_rollback_reach_the_database(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, , $group, $leader, $member, $outsider] = $this->setup_world();

        // One live request each, and the live one is looked for inside
        // request()'s transaction.
        $now = time();
        $live = $DB->insert_record('selfselectadvanced_move', (object) [
            'activityid' => $activity->id(),
            'userid' => (int) $outsider->id,
            'sourcegroupid' => null,
            'targetgroupid' => (int) $group->id,
            'makeleader' => 0,
            'replaceleader' => 0,
            'status' => joinrequests::STATUS_REQUESTED,
            'reason' => 'Let me in',
            'usermodified' => (int) $outsider->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $request = fn() => joinrequests::request(
            $activity,
            (int) $group->id,
            'Let me in twice',
            (int) $outsider->id
        );
        $this->probe_refusal('joinrequests::request (outermost)', 'refusaljoinduplicate', $request);
        $this->probe_nested_marker('joinrequests::request (nested)', 'refusaljoinduplicate', $request);

        // The withdraw() verb: somebody else's request, judged on the
        // row read inside the transaction.
        $withdraw = fn() => joinrequests::withdraw($activity, (int) $live, (int) $member->id);
        $this->probe_refusal('joinrequests::withdraw (outermost)', 'refusaljoinnotyours', $withdraw);
        $this->probe_nested_marker('joinrequests::withdraw (nested)', 'refusaljoinnotyours', $withdraw);

        // The do_accept() body, through respond(): respond() re-reads
        // the target and hands it over WITHOUT asking whether it is
        // frozen - that
        // question is asked again inside do_accept()'s own transaction,
        // on its own read, which is the refusal being driven here.
        $DB->set_field('selfselectadvanced_group', 'state', state::FROZEN, ['id' => $group->id]);
        $accept = fn() => joinrequests::respond($activity, (int) $live, true, 'Welcome', (int) $leader->id);
        $this->probe_refusal('joinrequests::do_accept (outermost)', 'refusaljointargetfrozen', $accept);
        $this->probe_nested_marker('joinrequests::do_accept (nested)', 'refusaljointargetfrozen', $accept);

        $this->assert_every_seam_closed_its_transaction('joinrequests (outermost)');
        $this->assert_every_caller_rollback_landed('joinrequests (nested)');
    }

    /**
     * contacts.php's two seams and freeze.php's release and discard,
     * both arms.
     *
     * NOT COVERED, and stated rather than counted: freeze_group()'s
     * transaction (freeze.php's third $outermost site) is opened only
     * AFTER every gate has passed and contains the state flip, the
     * snapshot and the sync request, so no single-threaded test can
     * make it throw from inside. Converted for symmetry; unverified,
     * not proven.
     *
     * Negative control (RUN, both engines): restore `$outermost && ` in
     * any one of the three covered catch arms and that seam's nested
     * assertion fails.
     */
    public function test_nested_contact_and_freeze_refusals_reach_the_database(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, , $group, $leader, $member, , $guide1, , $manager] = $this->setup_world();

        // The send() verb: the approach is the leader's to make, and
        // the leader is read from the row under the lock, inside the
        // transaction.
        $send = fn() => contacts::send(
            $activity,
            $group,
            (int) $guide1->id,
            'Would you take us on?',
            FORMAT_HTML,
            (int) $member->id
        );
        $this->probe_refusal('contacts::send (outermost)', 'refusalnotleader', $send);
        $this->probe_nested_marker('contacts::send (nested)', 'refusalnotleader', $send);

        // The respond() verb: the fast path outside the locks asks
        // only whose approach this is and whether it is still open.
        // Whether the
        // TEAM can still take a guide is asked only inside the
        // transaction, and a team that has left FORMING cannot.
        $contact = (object) [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'guideid' => (int) $guide1->id,
            'status' => contacts::STATUS_SENT,
            'sentby' => (int) $leader->id,
            'message' => 'Would you take us on?',
            'messageformat' => FORMAT_HTML,
            'reasonformat' => FORMAT_HTML,
            'timecreated' => time(),
        ];
        $contact->id = $DB->insert_record('selfselectadvanced_contact', $contact);
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $respond = fn() => contacts::respond(
            $activity,
            (int) $contact->id,
            true,
            'Happy to',
            FORMAT_HTML,
            (int) $guide1->id
        );
        $this->probe_refusal('contacts::respond (outermost)', 'refusalcontacthasguide', $respond);
        $this->probe_nested_marker('contacts::respond (nested)', 'refusalcontacthasguide', $respond);

        // The unfreeze() verb: the transaction is opened first and
        // the state is read inside it, so a FIRM team's refusal is
        // thrown from within.
        $unfreeze = fn() => freeze::unfreeze($activity, $group, (int) $manager->id, 'Release it');
        $this->probe_refusal('freeze::unfreeze (outermost)', 'refusalwrongstate', $unfreeze);
        $this->probe_nested_marker('freeze::unfreeze (nested)', 'refusalwrongstate', $unfreeze);

        // The discard_core_group() verb: the pre-lock gate asks only
        // whether the team is frozen. "There is no mirror to discard"
        // is asked on the row read inside the transaction.
        $discard = fn() => freeze::discard_core_group($activity, $group, (int) $manager->id);
        $this->probe_refusal('freeze::discard_core_group (outermost)', 'refusalnodiscardtarget', $discard);
        $this->probe_nested_marker('freeze::discard_core_group (nested)', 'refusalnodiscardtarget', $discard);

        $this->assert_every_seam_closed_its_transaction('contacts + freeze (outermost)');
        $this->assert_every_caller_rollback_landed('contacts + freeze (nested)');
    }

    /**
     * override\store::save(), the seam with the LIVE nested production
     * callers - state::do_approve() opens at state.php:566 and calls
     * save(..., callerholdslock: true) at :582, and
     * joinrequests::do_accept() reaches it through save_for_new_move()
     * with its own transaction open - plus state::submit() as the
     * PASSING CONTROL that proves the marker assertion discriminates.
     *
     * HOW save() IS MADE TO THROW, stated plainly because it is not a
     * refusal: save() contains no user-reachable refusal INSIDE its
     * transaction. Its two throws (unknown scope, field not in the
     * scope's B5 set) and the conflict-of-interest guard all fire
     * BEFORE start_delegated_transaction(), and a refusal raised before
     * the transaction exists would satisfy every assertion here while
     * proving nothing. So the failure is injected where the
     * transaction's only write is: an object handed to a scalar column
     * makes core's own detect_objects() raise a coding_exception from
     * inside insert_record(), on both engines, before any SQL is sent -
     * which is what the catch arm is there for, since it catches
     * \Throwable, not \moodle_exception.
     *
     * store::delete() - the second $outermost site in store.php - is
     * NOT covered: its only in-transaction throw is a MUST_EXIST re-read
     * whose criteria are identical to the read it already did before
     * the lock, so a single-threaded test cannot make the second one
     * fail while the first succeeds. Converted for symmetry with
     * save(); unverified, not proven.
     *
     * Negative control (RUN, both engines): restore `$outermost && ` in
     * store::save()'s catch arm and the save assertion fails while the
     * state::submit() control stays green - submit() has used the
     * unconditional form since wave 3D.
     */
    public function test_a_nested_override_save_failure_lets_the_callers_rollback_land(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $group, $leader, , , $guide1, , $manager] = $this->setup_world();

        // THE CONTROL, FIRST: state::submit() already rolls back
        // unconditionally, so this probe must pass before the fix as
        // well as after it. If it ever fails, the probe is broken and
        // nothing else it reports means anything.
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $firm = groups::get($activity, (int) $group->id);
        $this->probe_nested_marker(
            'state::submit (PASSING CONTROL)',
            'refusalwrongstate',
            fn() => $api->lifecycle()->submit($firm, (int) $guide1->id, (int) $leader->id)
        );
        $this->assertSame(
            [],
            $this->notunwound,
            'the control failed: state::submit() has rolled back unconditionally since wave 3D, so a failure here'
                . ' means the probe is measuring something other than the seam'
        );

        // A move-scope row: guard::blockers() and consistency::
        // violations() both return early for that scope, so the object
        // reaches the write untouched and nothing casts it on the way.
        $save = fn() => overridestore::save(
            $activity,
            'move',
            424242,
            ['rulesbypassed' => new \stdClass()],
            (int) $manager->id
        );
        $this->probe_refusal('override\store::save (outermost)', 'codingerror', $save);
        $this->probe_nested_marker('override\store::save (nested)', 'codingerror', $save);

        $this->assert_every_seam_closed_its_transaction('override\store (outermost)');
        $this->assert_every_caller_rollback_landed('override\store (nested)');
    }

    /**
     * The pin behind C1: after this wave, notifier::send()'s opt-in
     * strict check is the ONLY line of production code in the plugin
     * that reads $DB->is_transaction_started().
     *
     * WHY THIS IS ASSERTED ON THE SOURCE. Sixteen seams read that
     * predicate to decide how to UNWIND, and the predicate answers for
     * the harness under PHPUnit - true on m5my, false on m5pg - so each
     * of them took one arm per engine and the suite stayed green on
     * both while the nested arm was never executed anywhere. No
     * behavioural test can see a branch that neither engine takes; the
     * only durable guard is that the branch is not there.
     *
     * COMMENTS ARE STRIPPED with token_get_all() before matching. Half
     * the files in this plugin now carry a paragraph explaining why the
     * predicate is gone, and a grep those paragraphs satisfy is not a
     * check - that mistake has been made in this codebase three times.
     *
     * SCOPE: the whole plugin except tests/. Tests are allowed to read
     * the predicate - that is how the arms above are forced - and
     * every production file, page and task is in, so a read added to a
     * top-level page or a scheduled task is caught too, not only one
     * added under classes/.
     */
    public function test_only_the_notifier_reads_the_ambient_transaction_state(): void {
        $root = dirname(__DIR__);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            if (str_starts_with($path, 'tests/') || str_starts_with($path, '.')) {
                continue;
            }
            if (str_contains($this->code_without_comments($file->getPathname()), 'is_transaction_started')) {
                $found[] = $path;
            }
        }
        sort($found);

        $this->assertSame(
            ['classes/local/notifier.php'],
            $found,
            'production code reads $DB->is_transaction_started() again. Under PHPUnit that predicate answers'
                . ' for advanced_testcase, not for the method asking it - true on MariaDB, false on PostgreSQL -'
                . ' so any branch keyed on it is one arm per engine and neither engine tries the other.'
                . ' notifier::send() is the one permitted reader: opt-in, default off, and its setter refuses'
                . ' outside PHPUNIT_TEST.'
        );
    }

    /**
     * PHP source with every comment removed.
     *
     * Modelled on contactreach_test::code_without_comments(), for the
     * same reason: a source-text assertion that a COMMENT can satisfy
     * is not an assertion.
     *
     * @param string $path the file to read
     * @return string the code, comments stripped
     */
    private function code_without_comments(string $path): string {
        $source = file_get_contents($path);
        $this->assertIsString($source, 'unreadable: ' . $path);

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /**
     * notifier::send()'s strict transaction check is the ONE production
     * read of $DB->is_transaction_started() left in the tree - and
     * since 1.20 wave 3E that sentence is true. It was not true when it
     * was first written here: sixteen other production reads stood in
     * contacts.php, override/store.php, freeze.php, joinrequests.php
     * and tickets.php at the time, each gating a rollback arm, and they
     * are what C1 removed. This test is the behavioural half of the
     * pin; test_only_the_notifier_reads_the_ambient_transaction_state()
     * is the source-text half.
     *
     * The notifier's read is kept because the engine never chooses its
     * branch: it is opt-in, test-only, and off by default precisely
     * because advanced_testcase holds a transaction on one engine and
     * not the other.
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
