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
use mod_selfselectadvanced\local\state;

/**
 * A leave request has three endings, not one.
 *
 * WHY THIS EXISTS. request_leave() set a timestamp and told the leader.
 * The only thing that cleared it was confirm_leave(), which removes the
 * member. So a request could end exactly one way - by the member
 * leaving - and two obvious answers had no code behind them:
 *
 *   - the MEMBER changing their mind. Their own control went disabled
 *     with "you have asked to leave" and there was nothing to click.
 *   - the LEADER saying no. The only button in the leader's box was
 *     Confirm, so a leader who wanted to keep the member had to leave
 *     the request standing indefinitely, with the member's control
 *     disabled underneath it.
 *
 * Both were reported by the maintainer from the dev site. The property
 * that matters most in what follows is the negative one: declining must
 * NOT remove anybody, and withdrawing must NOT be something one member
 * can do to another.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class leave_answer_test extends \advanced_testcase {
    /**
     * A forming group: leader, two confirmed members.
     *
     * @return array [activity, api, group, leader, member, other]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'LEAVE1']);
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
            'name' => 'Leaving',
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
     * @param stdClass $group the group
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
     * A member takes their own request back, and is a member again with
     * nothing pending.
     *
     * MUTATION CAUGHT (run 2026-08-14): making cancel_leave() write
     * status = REMOVED alongside clearing the timestamp - the shape of
     * confirm_leave()'s write - fails the status assertion here.
     */
    public function test_a_member_can_take_their_own_leave_request_back(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $api, $group, , $member] = $this->setup_world();

        $this->redirectMessages();
        $api->invitations()->request_leave($group, (int) $member->id);
        $asked = $this->row($group, (int) $member->id);
        $this->assertNotEmpty($asked->leaverequested, 'the fixture did not actually raise a request');

        $api->invitations()->cancel_leave($group, (int) $member->id);

        $after = $this->row($group, (int) $member->id);
        $this->assertNull($after->leaverequested, 'the request is gone');
        $this->assertSame(groups::STATUS_CONFIRMED, $after->status, 'withdrawing must not remove anybody');

        // And they can ask again: withdrawing returns them to the state
        // they were in before they asked, not to a worse one.
        $api->invitations()->request_leave($group, (int) $member->id);
        $this->assertNotEmpty($this->row($group, (int) $member->id)->leaverequested);
    }

    /**
     * Withdrawing is something you do to your own request only, and only
     * while one exists.
     */
    public function test_only_the_asker_withdraws_and_only_a_live_request(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $api, $group, $leader, $member, $other] = $this->setup_world();

        $this->redirectMessages();

        // Nothing asked yet: there is nothing to take back.
        try {
            $api->invitations()->cancel_leave($group, (int) $member->id);
            $this->fail('Expected refusalleavenothingtowithdraw');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleavenothingtowithdraw', $e->errorcode);
        }

        $api->invitations()->request_leave($group, (int) $member->id);

        // Another member cannot withdraw it: cancel_leave() acts on the
        // ACTOR's own row, so the other member's own (requestless) row is
        // what it finds.
        try {
            $api->invitations()->cancel_leave($group, (int) $other->id);
            $this->fail('Expected refusalleavenothingtowithdraw for a different member');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleavenothingtowithdraw', $e->errorcode);
        }

        // Nor can the leader, who is not a member row here at all.
        try {
            $api->invitations()->cancel_leave($group, (int) $leader->id);
            $this->fail('Expected a refusal for the leader');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('', $e->errorcode);
        }

        // The request is untouched by any of that.
        $this->assertNotEmpty($this->row($group, (int) $member->id)->leaverequested);
    }

    /**
     * The leader declines: the request ends, the membership does not.
     *
     * MUTATION CAUGHT (run 2026-08-14): making decline_leave() write
     * status = REMOVED - which is confirm_leave()'s write, and the easy
     * copy-paste error here - fails the membership assertion, and the
     * roster count with it.
     */
    public function test_the_leader_can_decline_without_removing_the_member(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $api, $group, $leader, $member] = $this->setup_world();

        $this->redirectMessages();
        $api->invitations()->request_leave($group, (int) $member->id);

        $before = $DB->count_records('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        // Three: the leader holds a confirmed member row of their own,
        // which the generator writes with isleader = 1.
        $this->assertSame(3, $before, 'the fixture should hold the leader and two members');

        $api->invitations()->decline_leave($group, (int) $this->row($group, (int) $member->id)->id, (int) $leader->id);

        $after = $this->row($group, (int) $member->id);
        $this->assertNull($after->leaverequested, 'the request is answered and gone');
        $this->assertSame(groups::STATUS_CONFIRMED, $after->status, 'declining must not remove the member');
        $this->assertSame(0, (int) $after->isleader, 'declining must not change who leads');
        $this->assertSame($before, $DB->count_records('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'status' => groups::STATUS_CONFIRMED,
        ]), 'the roster is the same size');

        // Declined is not barred: the member may ask again.
        $api->invitations()->request_leave($group, (int) $member->id);
        $this->assertNotEmpty($this->row($group, (int) $member->id)->leaverequested);
    }

    /**
     * Declining is the leader's answer, and only theirs, and only when
     * there is something to answer.
     */
    public function test_declining_is_the_leaders_alone(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $api, $group, $leader, $member, $other] = $this->setup_world();

        $this->redirectMessages();
        $api->invitations()->request_leave($group, (int) $member->id);
        $memberrowid = (int) $this->row($group, (int) $member->id)->id;

        // Another member is not the leader.
        try {
            $api->invitations()->decline_leave($group, $memberrowid, (int) $other->id);
            $this->fail('Expected refusalnotleader');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotleader', $e->errorcode);
        }
        $this->assertNotEmpty($this->row($group, (int) $member->id)->leaverequested, 'the request survived');

        // The leader answers it once; a second answer has nothing to act on.
        $api->invitations()->decline_leave($group, $memberrowid, (int) $leader->id);
        try {
            $api->invitations()->decline_leave($group, $memberrowid, (int) $leader->id);
            $this->fail('Expected refusalnoleaverequest');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnoleaverequest', $e->errorcode);
        }
    }

    /**
     * Both answers tell the other party, because both change what the
     * other party is waiting for.
     */
    public function test_each_answer_reaches_the_person_waiting_on_it(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $api, $group, $leader, $member] = $this->setup_world();

        // The member withdraws: the leader is told, because the leader
        // was told about the request.
        $sink = $this->redirectMessages();
        $api->invitations()->request_leave($group, (int) $member->id);
        $sink->clear();
        $api->invitations()->cancel_leave($group, (int) $member->id);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'exactly one message, to the leader');
        $this->assertSame((int) $leader->id, (int) $messages[0]->useridto);
        $this->assertStringNotContainsString('{$a', $messages[0]->subject);

        // The leader declines: the member is told.
        $api->invitations()->request_leave($group, (int) $member->id);
        $sink->clear();
        $api->invitations()->decline_leave($group, (int) $this->row($group, (int) $member->id)->id, (int) $leader->id);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'exactly one message, to the member');
        $this->assertSame((int) $member->id, (int) $messages[0]->useridto);
        $this->assertStringNotContainsString('{$a', $messages[0]->subject);
    }

    /**
     * The page offers exactly one of the two controls, never both and
     * never neither.
     *
     * The member always has a next move: ask, or take the ask back. A
     * state in which they have neither is the defect this release fixes,
     * and it is worth asserting as an invariant rather than as two
     * separate positives.
     */
    public function test_a_member_is_always_offered_exactly_one_of_ask_or_withdraw(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $api, $group, , $member] = $this->setup_world();
        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $exported = static function () use ($api, $group, $member, $output) {
            return (new \mod_selfselectadvanced\output\group_page(
                $api,
                $group,
                (int) $member->id
            ))->export_for_template($output);
        };

        $before = $exported();
        $this->assertTrue((bool) $before->canrequestleave, 'nothing asked: the ask is offered');
        $this->assertFalse((bool) $before->cancancelleave, 'nothing asked: nothing to take back');

        $this->redirectMessages();
        $api->invitations()->request_leave($group, (int) $member->id);

        $pending = $exported();
        $this->assertFalse((bool) $pending->canrequestleave, 'asked already: the ask is not offered twice');
        $this->assertTrue((bool) $pending->cancancelleave, 'asked already: the take-back is offered');

        $api->invitations()->cancel_leave($group, (int) $member->id);

        $after = $exported();
        $this->assertTrue((bool) $after->canrequestleave, 'withdrawn: the ask is offered again');
        $this->assertFalse((bool) $after->cancancelleave, 'withdrawn: nothing to take back');
    }
}
