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
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\state;

/**
 * Decision 63: consent-first disband (the maintainer's flow 1,
 * 2026-08-06).
 *
 * "Leader wishes to disband -> sends a request to all members -> the
 * reason to withdraw goes to the members -> members withdraw -> group
 * is dropped." A peopled forming team is never deleted by surprise: the
 * request stands on the group row, every confirmed member is messaged
 * with the leader's composed reason, each member's leave becomes one
 * click (the request IS the leader's standing consent), the team
 * recruits nobody while it stands, and Delete opens only to a
 * leader-alone roster. Staff dissolve_group() remains the unconditional
 * emergency exit.
 *
 * THE RECREATION GUARANTEE is pinned here too (the maintainer's pool
 * concern): a member who left and the leader who deleted both form
 * again IMMEDIATELY - nothing about the wind-up scars their caps,
 * slots or windows.
 *
 * NEGATIVE AND POSITIVE CONTROLS LIVE IN SEPARATE METHODS where a
 * refusal would precede a commit (the PostgreSQL poisoning trap).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class disband_test extends \advanced_testcase {
    /**
     * A forming team of leader + two members, and a bystander.
     *
     * @return array [activity, api, group row, leader, member1, member2, bystander]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $mk = function () use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');

            return $user;
        };
        $leader = $mk();
        $m1 = $mk();
        $m2 = $mk();
        $bystander = $mk();
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Windup',
        ]);
        foreach ([$m1, $m2] as $m) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $m->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $leader, $m1, $m2, $bystander];
    }

    /**
     * The whole protocol, end to end, including the RECREATION
     * guarantee: request -> both members messaged with the reason ->
     * each leaves one-click -> Delete opens -> the ex-member and the
     * ex-leader both form again immediately.
     */
    public function test_the_full_protocol_and_recreation(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, $leader, $m1, $m2] = $this->world();

        $api->request_disband($group, 'The pool cannot give us two more SCOPE members.', FORMAT_PLAIN, (int) $leader->id);

        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);
        $this->assertNotEmpty($row->timedisbandrequested);
        $tolds = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $this->assertContains((int) $m1->id, $tolds, 'every member reads the reason');
        $this->assertContains((int) $m2->id, $tolds);
        $this->assertNotContains((int) $leader->id, $tolds, 'the leader wrote it and is not messaged');

        // One-click: no leader confirmation, own row only.
        $api->invitations()->self_leave($group, (int) $m1->id);
        $api->invitations()->self_leave($group, (int) $m2->id);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_member', [
            'groupid' => $group->id, 'status' => groups::STATUS_CONFIRMED,
        ]), 'the roster drained to the leader alone');

        // Delete opens at leader-alone, and the team ends.
        $this->assertNull($api->gatekeeper()->can_delete_group(
            groups::get($activity, (int) $group->id),
            (int) $leader->id
        ));
        $api->delete_group(groups::get($activity, (int) $group->id), (int) $leader->id);
        $this->assertFalse($DB->record_exists('selfselectadvanced_group', ['id' => $group->id]));

        // RECREATION: the caps and slots are free the same instant.
        $regroup = $api->create_group((int) $m1->id, 'Phoenix', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertNotEmpty($regroup->id, 'an ex-member forms again immediately');
        $releader = $api->create_group((int) $leader->id, 'Phoenix Two', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertNotEmpty($releader->id, 'the ex-leader forms again immediately');
        $sink->close();
    }

    /**
     * A peopled team cannot be deleted without the protocol.
     *
     * MUTATION CAUGHT (run): removing the others-count from
     * can_delete_group() re-admits the surprise delete.
     */
    public function test_delete_with_members_is_refused_toward_the_protocol(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, $leader] = $this->world();

        $refusal = $api->gatekeeper()->can_delete_group($group, (int) $leader->id);
        $this->assertNotNull($refusal, 'a peopled team winds up by consent, never by surprise');
        $this->assertSame('refusaldisbandfirst', $refusal->stringkey);
        $sink->close();
    }

    /**
     * One-click leave WITHOUT a live request is refused: the ordinary
     * leave handshake (request -> leader confirms) still governs.
     *
     * MUTATION CAUGHT (run): dropping the flag check from self_leave()
     * turns every member's leave into a self-service exit.
     */
    public function test_self_leave_needs_the_live_request(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [, $api, $group, , $m1] = $this->world();

        try {
            $api->invitations()->self_leave($group, (int) $m1->id);
            $this->fail('Without a disband request, leaving needs the leader');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaldisbandnone', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * While the request stands the team recruits nobody: the invite
     * door, the join-request door and the invitation-accept door all
     * refuse with the disband reason - and NONE of the composition or
     * bypass machinery is consulted to say so.
     *
     * MUTATION CAUGHT (run): removing the join_change_refusal() arm
     * lets the ask through; removing the invite arm lets the invite
     * through.
     */
    public function test_a_winding_up_team_recruits_nobody(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, $leader, , , $bystander] = $this->world();
        $api->request_disband($group, 'Winding up.', FORMAT_PLAIN, (int) $leader->id);
        $group = groups::get($activity, (int) $group->id);

        $refusal = $api->gatekeeper()->can_invite($group, (int) $bystander->id);
        $this->assertSame('refusaldisbanding', $refusal?->stringkey, 'the invite door');

        try {
            joinrequests::request($activity, (int) $group->id, 'Let me in', (int) $bystander->id);
            $this->fail('The ask door must refuse a winding-up team');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaldisbanding', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * A pending invitation cannot be ACCEPTED into a winding-up team -
     * and cancelling the request revives it untouched, along with every
     * other door. (Commit-after-refusal is safe here: the accept comes
     * AFTER the cancel commit, and the only refusal precedes nothing.)
     */
    public function test_cancel_revives_every_door(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, $leader, , , $bystander] = $this->world();
        $api->invitations()->send($group, (int) $bystander->id, (int) $leader->id);
        $api->request_disband($group, 'Maybe winding up.', FORMAT_PLAIN, (int) $leader->id);
        $fresh = groups::get($activity, (int) $group->id);
        $member = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id, 'userid' => (int) $bystander->id,
        ], '*', MUST_EXIST);

        $this->assertSame(
            'refusaldisbanding',
            $api->gatekeeper()->can_accept($fresh, $member)?->stringkey,
            'the pending invitation waits out the wind-up'
        );

        $api->cancel_disband($fresh, (int) $leader->id);
        $revived = groups::get($activity, (int) $group->id);
        $this->assertNull($api->gatekeeper()->can_accept($revived, $member), 'cancel revives the acceptance');
        $api->invitations()->accept($revived, (int) $bystander->id);
        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $group->id, 'userid' => (int) $bystander->id,
        ]));
        $sink->close();
    }

    /**
     * An empty team needs no consent: the request is refused toward
     * Delete, which is already open to its leader.
     */
    public function test_an_empty_team_is_pointed_at_delete(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, , ] = $this->world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $solo = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($solo->id, (int) $activity->cm()->course, 'student');
        $sologroup = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $solo->id,
            'name' => 'Solo',
        ]);

        try {
            $api->request_disband(
                groups::get($activity, (int) $sologroup->id),
                'Nobody here',
                FORMAT_PLAIN,
                (int) $solo->id
            );
            $this->fail('An empty team needs no consent protocol');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaldisbandempty', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * Staff dissolve remains the unconditional emergency exit, request
     * or no request - the maintainer's flow 3 depends on it.
     */
    public function test_staff_dissolve_is_untouched_by_the_protocol(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, $leader] = $this->world();
        $api->request_disband($group, 'Winding up.', FORMAT_PLAIN, (int) $leader->id);
        $staff = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($staff->id, (int) $activity->cm()->course, 'editingteacher');

        $api->dissolve_group(groups::get($activity, (int) $group->id), 'Emergency exit', (int) $staff->id);

        $this->assertFalse($DB->record_exists('selfselectadvanced_group', ['id' => $group->id]));
        $sink->close();
    }
}
