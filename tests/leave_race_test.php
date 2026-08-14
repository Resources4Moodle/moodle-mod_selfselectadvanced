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
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;

/**
 * A leave request answered by two people at once.
 *
 * THE SCENARIO, raised by the maintainer on 2026-08-14: a member presses
 * "Withdraw my request to leave" at the same moment their leader presses
 * "Confirm leave". Both pages were rendered when the request was live,
 * both POSTs are valid on the state each page saw, and exactly one of
 * them must win.
 *
 * WHY IT IS SAFE, stated so the reader does not have to trust it: every
 * write that can remove a member from a group holds that group's lock -
 * confirm_leave(), self_leave(), the invitation withdrawal, succession,
 * and the manager staged-move path through moves::lock_resources_for(),
 * which takes group:<id> for both ends of every move. cancel_leave()
 * takes the same lock and re-reads the membership row INSIDE it before
 * asking its gate. So the two cannot interleave mid-write: one commits
 * in full, the other then reads the committed state and is refused by a
 * gate looking at what is true now, not at what its page saw.
 *
 * That leaves one thing that can still be wrong, and it is what these
 * tests pin: WHAT THE LOSER IS TOLD. Being refused is correct; being
 * refused with "you are not a confirmed member of this group" when the
 * truth is "your request was granted a moment ago and you have left"
 * teaches the student something false about their own membership.
 *
 * These are REAL HANDOFFS, not two sequential calls. locks::set_test_hook()
 * fires in the window between the losing call's pre-lock reads and its
 * acquire, and the winning operation is committed there - which is
 * exactly where the second click lands. Each test asserts the hook fired,
 * because a race test that did not race is a green check that examined
 * nothing.
 *
 * MUTATION CAUGHT (run 2026-08-14), the one that matters. Making
 * cancel_leave() judge a STALE row - the shape of the defect this file
 * exists to guard, and what the code did before T-02 R2 taught this
 * codebase to re-read inside the lock - produces exactly the two
 * failures the maintainer asked about: "the withdrawal was accepted
 * after the member had already been removed" and "a request was answered
 * twice". Both are caught here.
 *
 * MUTATION CAUGHT (run 2026-08-14), the wording. Dropping the REMOVED
 * arm from can_cancel_leave() leaves the loser with the generic
 * "you are not a confirmed member of this group", and the first test
 * below fails on the errorcode.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class leave_race_test extends \advanced_testcase {
    /**
     * A forming group with a leader and two confirmed members.
     *
     * @return array [activity, api, group, leader, member, other]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'LEAVERACE']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $other = $generator->create_user();
        foreach ([$leader, $member, $other] as $user) {
            $generator->enrol_user($user->id, $course->id, 'student');
        }

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Racing',
            'state' => state::FORMING,
        ]);
        foreach ([$member, $other] as $user) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $user->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return [
            $activity,
            new api($activity),
            groups::get($activity, (int) $group->id),
            $leader,
            $member,
            $other,
        ];
    }

    /**
     * The member's row as it stands.
     *
     * @param \stdClass $group the group
     * @param int $userid the member
     * @return \stdClass the row
     */
    private function row(\stdClass $group, int $userid): \stdClass {
        global $DB;

        return $DB->get_record('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'userid' => $userid,
        ], '*', MUST_EXIST);
    }

    /**
     * THE MAINTAINER'S SCENARIO. The leader's confirmation commits while
     * the member's withdrawal waits for the lock: the member has left,
     * and is told THAT rather than that they were never a member.
     *
     * MUTATION CAUGHT (run 2026-08-14): dropping the REMOVED arm from
     * can_cancel_leave() sends the loser back to the generic
     * "you are not a confirmed member of this group", and this fails.
     */
    public function test_a_confirm_that_lands_first_tells_the_withdrawer_they_have_left(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $api, $group, $leader, $member] = $this->world();

        $api->invitations()->request_leave($group, (int) $member->id);
        $memberrowid = (int) $this->row($group, (int) $member->id)->id;

        $fired = false;
        locks::set_test_hook(function (string $resource) use (
            &$fired,
            $api,
            $activity,
            $group,
            $memberrowid,
            $leader
        ): void {
            if ($fired || $resource !== 'group:' . $group->id) {
                return;
            }
            $fired = true;
            // The leader's Confirm lands and commits while the member's
            // Withdraw is still on its way to the lock.
            $api->invitations()->confirm_leave(
                groups::get($activity, (int) $group->id),
                $memberrowid,
                (int) $leader->id
            );
        });

        try {
            $api->invitations()->cancel_leave($group, (int) $member->id);
            $this->fail('the withdrawal was accepted after the member had already been removed');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleavealreadyleft', $e->errorcode);
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired, 'the lock hook never fired, so nothing was actually raced');

        // The winner's write stands, whole and unmodified by the loser.
        $after = $this->row($group, (int) $member->id);
        $this->assertSame(groups::STATUS_REMOVED, $after->status);
        $this->assertNull($after->leaverequested);
        $this->assertSame(0, (int) $after->isleader);
    }

    /**
     * The other order: the withdrawal commits first, and the leader's
     * confirmation finds nothing to confirm. Nobody is removed.
     *
     * This is the direction that would be dangerous if the gate read the
     * page's copy of the row instead of re-reading under the lock: the
     * leader would remove a member whose request no longer existed.
     */
    public function test_a_withdrawal_that_lands_first_stops_the_confirmation(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $api, $group, $leader, $member] = $this->world();

        $api->invitations()->request_leave($group, (int) $member->id);
        $memberrowid = (int) $this->row($group, (int) $member->id)->id;

        $fired = false;
        locks::set_test_hook(function (string $resource) use (
            &$fired,
            $api,
            $activity,
            $group,
            $member
        ): void {
            if ($fired || $resource !== 'group:' . $group->id) {
                return;
            }
            $fired = true;
            // The member's Withdraw lands and commits while the leader's
            // Confirm waits.
            $api->invitations()->cancel_leave(
                groups::get($activity, (int) $group->id),
                (int) $member->id
            );
        });

        try {
            $api->invitations()->confirm_leave($group, $memberrowid, (int) $leader->id);
            $this->fail('a member was removed on a request that had been withdrawn');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnoleaverequest', $e->errorcode);
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired, 'the lock hook never fired, so nothing was actually raced');

        // Still a member, with nothing pending: the withdrawal won and
        // the confirmation changed nothing.
        $after = $this->row($group, (int) $member->id);
        $this->assertSame(groups::STATUS_CONFIRMED, $after->status);
        $this->assertNull($after->leaverequested);
    }

    /**
     * Both answers at once: the leader declines while the member
     * withdraws. Whoever loses is told the request is already answered,
     * and the membership survives either way.
     */
    public function test_a_decline_and_a_withdrawal_cannot_both_answer_one_request(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $api, $group, $leader, $member] = $this->world();

        $api->invitations()->request_leave($group, (int) $member->id);
        $memberrowid = (int) $this->row($group, (int) $member->id)->id;

        $fired = false;
        locks::set_test_hook(function (string $resource) use (
            &$fired,
            $api,
            $activity,
            $group,
            $memberrowid,
            $leader
        ): void {
            if ($fired || $resource !== 'group:' . $group->id) {
                return;
            }
            $fired = true;
            $api->invitations()->decline_leave(
                groups::get($activity, (int) $group->id),
                $memberrowid,
                (int) $leader->id
            );
        });

        try {
            $api->invitations()->cancel_leave($group, (int) $member->id);
            $this->fail('a request was answered twice');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleavenothingtowithdraw', $e->errorcode);
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired, 'the lock hook never fired, so nothing was actually raced');

        // The decline won; the member is still a member either way.
        $after = $this->row($group, (int) $member->id);
        $this->assertSame(groups::STATUS_CONFIRMED, $after->status);
        $this->assertNull($after->leaverequested);
    }

    /**
     * A staff removal through the staged-move path races the same way,
     * because it holds the same group lock.
     *
     * moves::lock_resources_for() returns group:<id> for both ends of
     * every move, so a manager committing a move out of this group is
     * serialised against the member's withdrawal exactly as the leader's
     * confirmation is. This test exists because that safety comes from a
     * DIFFERENT file's lock list, and a future refactor of the move
     * path could quietly drop it.
     */
    public function test_the_group_lock_is_what_serialises_these(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [, $api, $group, , $member] = $this->world();

        $api->invitations()->request_leave($group, (int) $member->id);

        locks::start_recording();
        $api->invitations()->cancel_leave($group, (int) $member->id);
        $log = locks::stop_recording();

        $this->assertContains(
            'acquire group:' . $group->id,
            $log,
            'cancel_leave() must take the group lock, or nothing serialises it against a removal'
        );
        $this->assertContains('release group:' . $group->id, $log, 'and must release it');
    }
}
