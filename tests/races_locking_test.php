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
use mod_selfselectadvanced\local\autogroup\engine;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\notifier;
use mod_selfselectadvanced\local\state;

/**
 * The one lock order, and the rule that no message travels under a lock
 * (T-02 R1, R6, R7).
 *
 * Moodle's named locks are per DB SESSION and the PHPUnit process has
 * exactly one, so no in-process test can demonstrate cross-session
 * mutual exclusion - a second acquire of the same resource in the same
 * process succeeds on both engines. What CAN be pinned, and is what
 * actually fails when a lock is dropped, is the recorded acquisition:
 * which resources, in which order, released in reverse, across which
 * span. The no-mail-under-lock half is pinned by notifier::send()'s own
 * runtime guard, which fires debugging() when locks::held_count() is
 * not zero.
 *
 * WHAT THAT GUARD IS AND IS NOT (corrected 2026-07-31; the previous
 * wording claimed PHPUnit turns an unexpected debugging() into a
 * failure, which was measured false). Moodle turns an unconsumed
 * debugging() into an E_USER_NOTICE. PHPUnit 11 reports that as a
 * Notice, and the suite used to run --fail-on-warning only, under which
 * a test that emits one unconsumed debugging() and asserts nothing
 * still exits 0. --fail-on-notice is now passed in BOTH places that
 * run this suite: .github/workflows/moodle-ci.yml (so it travels with
 * the repository, to every push and every fork) and the maintainer's
 * gate. An unconsumed guard notice therefore does fail a run - but
 * only for as long as that flag survives in those two files.
 *
 * So the flag is the backstop, never the detector. Every test in this
 * file that means to pin the no-mail property ends with an explicit
 * assertDebuggingNotCalled(), and any new one must do the same; a test
 * that merely happens not to emit a notice proves nothing.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\locks
 * @covers     \mod_selfselectadvanced\local\lockhandle
 * @covers     \mod_selfselectadvanced\local\moves
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\autogroup\engine
 */
final class races_locking_test extends \advanced_testcase {
    /**
     * A clean held-set before every test: static state outlives a test
     * that leaves a lock behind, and a stale held-set would make the
     * ordering guard and the notifier guard lie.
     */
    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
    }

    /**
     * Two firm groups of two, five students, room to move.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, api, students[], groupA, groupB]
     */
    private function setup_two_groups(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 3,
            'maxlead' => 2,
            'maxmembership' => 2,
        ], $settings));

        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $activity = activity::from_instance((int) $instance->id);

        // 1.20.6: team A is the OTHER team these tests' student belongs to. It
        // was the SOURCE they were moved out of until decision 77, and a firm
        // team may only be left once its guide has released it. Without the
        // flag every test in this file used to die on the source-approved
        // refusal - a key this release deleted - before reaching the lock and
        // transaction behaviour it exists to measure. The flag is kept because
        // the fixture's meaning is kept: a settled team the student legitimately
        // belongs to, which is the state FIRM alone used to imply. Note the
        // accept path no longer reads team A at all, so the flag is now
        // fixture hygiene rather than a precondition.
        $a = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'A',
            'state' => state::FIRM,
            'releasedbyguide' => 1,
        ]);
        $plugingen->create_member([
            'groupid' => $a->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        // Team B is the TARGET these tests move a student into, and the same
        // 1.20.6 guard applies to both ends of a move: a firm team may be
        // joined only once its guide has released it. Both A and B therefore
        // carry the flag - the fixture models two settled teams whose guides
        // have opened them for a legitimate swap, which is what these tests
        // were always exercising.
        $b = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'B',
            'state' => state::FIRM,
            'releasedbyguide' => 1,
        ]);
        $plugingen->create_member([
            'groupid' => $b->id,
            'userid' => (int) $students[3]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            new api($activity),
            $students,
            groups::get($activity, (int) $a->id),
            groups::get($activity, (int) $b->id),
        ];
    }

    /**
     * R1: a commit takes the activity lock AND every touched group,
     * ascending by id, and releases them in reverse. The activity lock
     * alone never excluded invitations::accept or freeze_group, which
     * serialise on group:{id}.
     *
     * Negative control: put back locks::acquire('activity:'.$id) in
     * place of acquire_all() - the two group: entries vanish and this
     * assertion fails outright.
     */
    public function test_commit_set_holds_activity_then_every_touched_group_ascending(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $students, $a, $b] = $this->setup_two_groups();

        $one = $api->moves()->stage((int) $students[1]->id, (int) $a->id, (int) $b->id, false, null, 99);
        $two = $api->moves()->stage((int) $students[3]->id, (int) $b->id, (int) $a->id, false, null, 99);

        locks::start_recording();
        try {
            $api->moves()->commit_set([(int) $one->id, (int) $two->id], 99);
        } finally {
            $log = locks::stop_recording();
        }

        $this->assertLessThan((int) $b->id, (int) $a->id);
        $this->assertSame([
            'acquire activity:' . $activity->id(),
            'acquire group:' . (int) $a->id,
            'acquire group:' . (int) $b->id,
            'release group:' . (int) $b->id,
            'release group:' . (int) $a->id,
            'release activity:' . $activity->id(),
        ], $log);
    }

    /**
     * R6: apply() used to call notifier::send() from inside the commit
     * transaction, under the activity lock - and core buffers a message
     * to the outermost commit, which is still inside the lock. The
     * messages still go out; they go out afterwards.
     *
     * Negative control: restore the notifier::send() in apply() - the
     * step-2 guard fires debugging() and assertDebuggingNotCalled()
     * fails.
     */
    public function test_commit_set_sends_nothing_under_lock(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $a, $b] = $this->setup_two_groups(['minsize' => 1]);

        $move = $api->moves()->stage(
            (int) $students[1]->id,
            (int) $a->id,
            (int) $b->id,
            true,
            null,
            99,
            true
        );

        $sink = $this->redirectMessages();
        $api->moves()->commit_set([(int) $move->id], 99);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertDebuggingNotCalled();

        // The demoted incumbent hears about it, and so does the moved
        // student - both after the lock, neither lost.
        $subjects = array_map(static fn($m) => $m->subject, $messages);
        $torecipients = array_map(static fn($m) => (int) $m->useridto, $messages);
        $this->assertCount(2, $messages);
        $this->assertContains(
            get_string('msgleaderreplacedsubject', 'mod_selfselectadvanced', (object) ['group' => 'B']),
            $subjects
        );
        $this->assertContains((int) $students[2]->id, $torecipients);
        $this->assertContains((int) $students[1]->id, $torecipients);
    }

    /**
     * R1c/R6 on the nested path: accepting a join request runs the move
     * engine inside the caller's transaction, under respond()'s
     * joinrequest:{id} lock. Both the engine's messages and the join
     * notice must leave every lock first.
     *
     * The ORDERING is pinned by the step-2 guard rather than by
     * inspecting the sink mid-flight: the guard counts held locks
     * around every send, so a send left at the end of do_accept() -
     * where joinrequest:{id} is still held - fires debugging() and
     * fails this test. Both of the ticket's negative controls land on
     * assertDebuggingNotCalled(): dropping the $deferred argument
     * buffers the engine's sends inside the outer transaction under
     * all three locks, and moving send_all() back into do_accept()
     * sends under the per-request lock.
     */
    public function test_joinrequest_accept_sends_nothing_under_lock_or_transaction(): void {
        $this->resetAfterTest();
        [$activity, , $students, $a, $b] = $this->setup_two_groups(['minsize' => 1]);

        $request = joinrequests::request($activity, (int) $b->id, 'Closer to my work', (int) $students[1]->id);

        $sink = $this->redirectMessages();
        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $b->leaderid);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertDebuggingNotCalled();

        $subjects = array_map(static fn($m) => $m->subject, $messages);
        $this->assertContains(
            get_string('msgmovedsubject', 'mod_selfselectadvanced', (object) ['group' => 'B']),
            $subjects
        );
        $this->assertContains(
            get_string('msgjoinacceptedsubject', 'mod_selfselectadvanced', (object) ['group' => 'B']),
            $subjects
        );

        // And the student really did join - additively, since decision 77, so
        // A keeps them and B gains them.
        $joined = array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $students[1]->id)));
        sort($joined);
        $expected = [(int) $a->id, (int) $b->id];
        sort($expected);
        $this->assertSame($expected, $joined);
    }

    /**
     * R1c: the move engine's locks are taken by do_accept() itself, so
     * they cover the OUTER transaction - commit_set's own finally would
     * otherwise release them while that transaction was still open, and
     * a writer slipping into the window would read uncommitted state.
     * joinrequest:{id} is outermost and released last.
     */
    public function test_joinrequest_accept_takes_activity_and_group_locks_before_its_transaction(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $students, $a, $b] = $this->setup_two_groups(['minsize' => 1]);

        $request = joinrequests::request($activity, (int) $b->id, 'Closer to my work', (int) $students[1]->id);

        locks::start_recording();
        try {
            joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $b->leaderid);
        } finally {
            $log = locks::stop_recording();
        }

        // ONE GROUP LOCK, NOT TWO, since decision 77. Acceptance used to change
        // two rosters and so locked both; a join changes only the team being
        // joined. Team A is deliberately absent from this list - if it comes
        // back, something is writing to a team the request no longer touches.
        $this->assertSame([
            'acquire joinrequest:' . (int) $request->id,
            'acquire activity:' . $activity->id(),
            'acquire group:' . (int) $b->id,
            'release group:' . (int) $b->id,
            'release activity:' . $activity->id(),
            'release joinrequest:' . (int) $request->id,
        ], $log);
    }

    /**
     * R7: a cutoff sweep can place thousands, and thousands of
     * synchronous message_send() calls under activity:{id} blocked
     * every other writer on the activity until it timed out at 10s.
     *
     * Negative control: move the send loops back above the finally -
     * the guard fires and assertDebuggingNotCalled() fails.
     */
    public function test_autogroup_run_sends_after_the_lock_is_released(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $now = time();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 3,
            'maxlead' => 1,
            'maxmembership' => 1,
            'autogroup' => 2,
            'timecutoff' => $now - 100,
        ]);
        $students = [];
        for ($i = 0; $i < 6; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = (int) $user->id;
        }
        $activity = activity::from_instance((int) $instance->id);

        $sink = $this->redirectMessages();
        $agrun = engine::run($activity, 0, 777);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertDebuggingNotCalled();
        $this->assertSame(6, (int) $agrun->placed);

        $placedsubject = get_string('msgautogroupedsubject', 'mod_selfselectadvanced');
        $told = [];
        foreach ($messages as $message) {
            if ($message->subject === $placedsubject) {
                $told[] = (int) $message->useridto;
            }
        }
        $this->assertEqualsCanonicalizing($students, $told);
    }

    /**
     * The ordering guard itself. Report only - a production request
     * must not die on this. What makes an inversion a RED gate is the
     * explicit assertDebuggingCalled() below, not the ambient run
     * flags: see the file docblock above, and do not restore the old
     * wording here, which claimed PHPUnit fails on an unexpected
     * debugging() by itself.
     *
     * Negative control: delete check_order() - the two
     * assertDebuggingCalled() expectations fail.
     */
    public function test_lock_order_violation_is_reported(): void {
        $this->resetAfterTest();

        // A group: lock (rank 8) then activity: (rank 6) inverts the order.
        $group = locks::acquire('group:1');
        $activitylock = locks::acquire('activity:1');
        $this->assertDebuggingCalled();
        $activitylock->release();
        $group->release();

        // Same rank stacking is legal for group: only in ASCENDING id.
        $nine = locks::acquire('group:9');
        $two = locks::acquire('group:2');
        $this->assertDebuggingCalled();
        $two->release();
        $nine->release();

        // The lawful shape says nothing at all.
        $activitylock = locks::acquire('activity:1');
        $one = locks::acquire('group:1');
        $two = locks::acquire('group:2');
        $this->assertDebuggingNotCalled();
        locks::release_all([$activitylock, $one, $two]);
        $this->assertSame(0, locks::held_count());

        // Same rank other than group: may never stack.
        $first = locks::acquire('ticket:1');
        $second = locks::acquire('ticket:2');
        $this->assertDebuggingCalled();
        $second->release();
        $first->release();

        // An unranked prefix is a coding error, not a silent pass.
        try {
            locks::acquire('overrides:42');
            $this->fail('Expected a coding_exception for an unranked resource');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unranked lock resource', $e->getMessage());
        }
    }

    /**
     * Step 4.5: submit(), assign_guide(), return_group() and
     * commit_set() each throw their refusals from INSIDE their own
     * transaction, and none of them had a catch. A refused call left a
     * dangling delegated transaction behind it.
     *
     * preventResetByRollback() MUST come first: advanced_testcase holds
     * a delegated transaction for the whole of every test on
     * PostgreSQL, so without it $outermost is false there and this
     * assertion would pass on MariaDB and fail on PostgreSQL.
     *
     * Negative control: drop the catch/rollback arm from any one of the
     * four - the transaction is left open and the assertion fails.
     */
    public function test_state_refusal_leaves_no_dangling_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups();
        $guide = $this->getDataGenerator()->create_user();

        // Submitting a FIRM group: can_submit refuses inside the
        // transaction, with the guide lock and the group lock held.
        $this->assert_refused(
            'refusalwrongstate',
            fn() => $api->lifecycle()->submit($a, (int) $guide->id, (int) $a->leaderid)
        );
        $this->assertFalse($DB->is_transaction_started());

        // Assigning a guide: FIRM is assignable, so drive the state
        // gate with a FORMING group instead.
        $forming = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'Forming',
            'state' => state::FORMING,
        ]);
        // A real manager, not a bare integer: assign_guide() asks
        // has_any_capability([:manage, :assignguide]) of its actor
        // (1.20.1, wave 3C), and that question is asked BEFORE the
        // locks - so a bare integer would now be refused there and
        // never reach the state gate this test is about. The refusal
        // under test is still refusalreassignstate, still thrown from
        // inside the transaction with both locks held.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user(
            $manager->id,
            (int) $activity->cm()->course,
            'editingteacher'
        );
        $this->assert_refused(
            'refusalreassignstate',
            fn() => $api->lifecycle()->assign_guide(
                groups::get($activity, (int) $forming->id),
                (int) $guide->id,
                (int) $manager->id
            )
        );
        $this->assertFalse($DB->is_transaction_started());

        // Returning a group. Since decision 62 a FIRM team routes to
        // the STAFF arm of return_group() - queue authority first - so
        // a student leader is refused 'nopermissions' where the old
        // single-arm gate said 'refusalwrongstate'. The property under
        // test is unchanged: the refusal is thrown from inside the
        // transaction and leaves it closed. The wrongstate flavour of
        // this gate is pinned separately by
        // return_to_forming_test::test_a_frozen_team_must_be_unfrozen_first.
        $this->assert_refused(
            'nopermissions',
            fn() => $api->lifecycle()->return_group($a, 'Needs work', (int) $a->leaderid)
        );
        $this->assertFalse($DB->is_transaction_started());

        // Committing a set: a move that empties its source below
        // minsize 2 fails L1, and errmovesetinvalid is thrown inside the
        // transaction with no catch anywhere in the call chain.
        $move = $api->moves()->stage((int) $students[1]->id, (int) $a->id, (int) $b->id, false, null, 99);
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
            'groupid' => $a->id,
            'userid' => (int) $students[0]->id,
        ]);
        $this->assert_refused('errmovesetinvalid', fn() => $api->moves()->commit_set([(int) $move->id], 99));
        $this->assertFalse($DB->is_transaction_started());

        // And nothing the refused commit wrote survives.
        $this->assertSame('pending', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $move->id]));
        $this->assertSame(state::FIRM, $DB->get_field('selfselectadvanced_group', 'state', ['id' => $a->id]));
    }

    /**
     * The transaction half of invariant 3, on the four paths this
     * ticket restructured (steps 3b, 4, 5 and 9).
     *
     * It cannot be a permanent runtime guard: advanced_testcase holds a
     * delegated transaction for the whole of every test on PostgreSQL,
     * so a live is_transaction_started() branch would fire at every
     * existing send site and turn the suite red on one engine and green
     * on the other. So the check is opt-in and test-only, and this test
     * is the place it is switched on - with preventResetByRollback()
     * first, or the services under test are not the outermost
     * transaction owners and the check has nothing to see.
     *
     * Negative control: move any of the four send sites back inside its
     * transaction - the strict guard fires and this fails.
     */
    public function test_restructured_paths_send_outside_every_transaction(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups(['minsize' => 1]);
        $forming = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'Forming',
            'state' => state::FORMING,
        ]);
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => $forming->id,
            'userid' => (int) $students[3]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $sink = $this->redirectMessages();
        notifier::set_strict_transaction_check(true);
        try {
            // Step 4: the move engine.
            $move = $api->moves()->stage((int) $students[1]->id, (int) $a->id, (int) $b->id, false, null, 99);
            $api->moves()->commit_set([(int) $move->id], 99);

            // Step 5: the nested join-accept path.
            $request = joinrequests::request($activity, (int) $a->id, 'Back again', (int) $students[1]->id);
            joinrequests::respond($activity, (int) $request->id, true, 'Welcome back', (int) $a->leaderid);

            // Step 3b: the leave service, both halves.
            $group = groups::get($activity, (int) $forming->id);
            $api->invitations()->request_leave($group, (int) $students[3]->id);
            $memberid = (int) $this->get_member_id((int) $forming->id, (int) $students[3]->id);
            $api->invitations()->confirm_leave($group, $memberid, (int) $forming->leaderid);
        } finally {
            notifier::set_strict_transaction_check(false);
        }
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertDebuggingNotCalled();
        $this->assertNotEmpty($messages);
    }

    /**
     * The member row id for a user in a group.
     *
     * @param int $groupid the group
     * @param int $userid the user
     * @return int the member row id
     */
    private function get_member_id(int $groupid, int $userid): int {
        global $DB;

        return (int) $DB->get_field('selfselectadvanced_member', 'id', [
            'groupid' => $groupid,
            'userid' => $userid,
        ], MUST_EXIST);
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
}
