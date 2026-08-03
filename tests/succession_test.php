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
 * Leadership transfer and step-out (spec 6.4, decision A3): nominee L3
 * boundary and atomic re-check, step-out L1 replacement rule, slot
 * release on transfer, single-nomination rule.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\succession
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class succession_test extends \advanced_testcase {
    /**
     * Create a course, an instance and n students.
     *
     * @param array $settings instance setting overrides
     * @param int $students number of enrolled students
     * @return array [activity, api, students[]]
     */
    private function setup_activity(array $settings = [], int $students = 4): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
        ], $settings));

        $users = [];
        for ($i = 0; $i < $students; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $users[] = $user;
        }

        $activity = activity::from_instance((int) $instance->id);

        return [$activity, new api($activity), $users];
    }

    /**
     * The plugin generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * Build a forming group with a leader and one confirmed member.
     *
     * @param activity $activity the activity
     * @param int $leaderid the leader
     * @param int $memberid the confirmed member
     * @param string $name group name
     * @return \stdClass group row
     */
    private function make_group(activity $activity, int $leaderid, int $memberid, string $name = 'G'): \stdClass {
        $group = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderid,
            'name' => $name,
        ]);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => $memberid,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * Transfer: nomination requires a confirmed member with a free L3
     * slot; confirmation swaps leadership, keeps the ex-leader as a
     * confirmed member, releases their lead slot and fires the event.
     */
    public function test_transfer(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity(['maxlead' => 1, 'maxmembership' => 2]);
        $leader = (int) $users[0]->id;
        $member = (int) $users[1]->id;
        $group = $this->make_group($activity, $leader, $member);

        // Non-members and the leader cannot be nominated.
        $this->assertSame(
            'refusalnomineenotmember',
            $api->gatekeeper()->can_nominate($group, (int) $users[2]->id, 'transfer', $leader)?->stringkey
        );
        $this->assertSame(
            'refusalnomineeisleader',
            $api->gatekeeper()->can_nominate($group, $leader, 'transfer', $leader)?->stringkey
        );

        // Only the leader nominates.
        $this->assertSame(
            'refusalnotleader',
            $api->gatekeeper()->can_nominate($group, $member, 'transfer', $member)?->stringkey
        );

        $api->succession()->nominate($group, $member, 'transfer', $leader);
        $group = groups::get($activity, (int) $group->id);
        $this->assertEquals($member, $group->successorid);

        // Single active nomination (A3).
        $this->assertSame(
            'refusalnominationactive',
            $api->gatekeeper()->can_nominate($group, $member, 'transfer', $leader)?->stringkey
        );

        // Confirm: leadership swaps, ex-leader stays confirmed, event fires.
        $sink = $this->redirectEvents();
        $type = $api->succession()->confirm($group, $member);
        $events = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\leadership_transferred
        );
        $sink->close();

        $this->assertSame('transfer', $type);
        $this->assertCount(1, $events);
        $group = groups::get($activity, (int) $group->id);
        $this->assertEquals($member, $group->leaderid);
        $this->assertNull($group->successorid);
        $this->assertEquals(1, $DB->get_field('selfselectadvanced_member', 'isleader', [
            'groupid' => $group->id,
            'userid' => $member,
        ]));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_member', 'isleader', [
            'groupid' => $group->id,
            'userid' => $leader,
        ]));
        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $group->id,
            'userid' => $leader,
        ]));

        // The outgoing leader's lead slot is released: they can create again.
        $this->assertSame(0, groups::count_leading($activity, $leader));
        $this->assertSame(1, groups::count_leading($activity, $member));
    }

    /**
     * Nominee L3 boundary: a member at their lead cap cannot be
     * nominated (with the reason), and the slot is re-checked
     * atomically at confirmation - a nominee who gained a lead
     * elsewhere between nomination and confirmation is refused.
     */
    public function test_nominee_leadcap_boundary_and_recheck(): void {
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity(['maxlead' => 1, 'maxmembership' => 3]);
        $leader = (int) $users[0]->id;
        $member = (int) $users[1]->id;
        $group = $this->make_group($activity, $leader, $member, 'Main');

        // Member already leads another group: nomination refused with reason.
        $other = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $member,
            'name' => 'Theirs',
        ]);
        $refusal = $api->gatekeeper()->can_nominate($group, $member, 'transfer', $leader);
        $this->assertSame('refusalnomineeleadcap', $refusal?->stringkey);

        // Free the slot, nominate, then re-occupy it before confirming.
        global $DB;
        $DB->delete_records('selfselectadvanced_member', ['groupid' => $other->id]);
        $DB->delete_records('selfselectadvanced_group', ['id' => $other->id]);
        $api->succession()->nominate($group, $member, 'transfer', $leader);

        $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $member,
            'name' => 'Regained',
        ]);
        $group = groups::get($activity, (int) $group->id);
        try {
            $api->succession()->confirm($group, $member);
            $this->fail('Expected atomic L3 re-check to refuse');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('already leads', $e->getMessage());
        }
    }

    /**
     * Step-out: blocked while the departure would violate the minimum
     * size (L1) until a replacement is confirmed; then the ex-leader is
     * removed and may hold a pending invitation elsewhere (a held place).
     */
    public function test_stepout_replacement_rule(): void {
        global $DB;
        $this->resetAfterTest();
        // Wave 3D: a refusal now rolls its OWN delegated transaction
        // back instead of abandoning it, which sets $DB's force_rollback
        // until the transaction stack empties. This test refuses a verb
        // and then commits another one, and on PostgreSQL - and only
        // there - advanced_testcase holds a frame underneath that never
        // lets the stack empty, so the later commit would be refused on
        // one engine and not the other. Committing the harness frame
        // here is what makes the two engines agree; the same line, for
        // the same reason, as in races_locking_test.
        $this->preventResetByRollback();

        [$activity, $api, $users] = $this->setup_activity([
            'minsize' => 2,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], 5);
        $leader = (int) $users[0]->id;
        $member = (int) $users[1]->id;
        $group = $this->make_group($activity, $leader, $member, 'Step');

        $api->succession()->nominate($group, $member, 'stepout', $leader);
        $group = groups::get($activity, (int) $group->id);

        // Two confirmed members; leaving one behind violates minsize 2.
        $refusal = $api->gatekeeper()->can_confirm_succession($group, $member);
        $this->assertSame('refusalreplacementneeded', $refusal?->stringkey);
        try {
            $api->succession()->confirm($group, $member);
            $this->fail('Expected replacement-needed refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('replacement', $e->getMessage());
        }

        // A replacement joins (invited, cap-checked, then accepts).
        $api->invitations()->send($group, (int) $users[2]->id, $leader);
        $api->invitations()->accept($group, (int) $users[2]->id);

        // Now the nominee can confirm; the ex-leader leaves the group.
        $type = $api->succession()->confirm(groups::get($activity, (int) $group->id), $member);
        $this->assertSame('stepout', $type);
        $group = groups::get($activity, (int) $group->id);
        $this->assertEquals($member, $group->leaderid);
        $this->assertSame(groups::STATUS_REMOVED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $group->id,
            'userid' => $leader,
        ]));
        $this->assertSame(2, groups::count_confirmed((int) $group->id));

        // Held place: the former leader can be invited elsewhere.
        $elsewhere = $this->make_group($activity, (int) $users[3]->id, (int) $users[4]->id, 'Else');
        $this->assertNull($api->gatekeeper()->can_invite($elsewhere, $leader));
    }

    /**
     * Decline and cancel clear the nomination; S2 blocks nomination
     * actions outside forming.
     */
    public function test_decline_cancel_and_state_guard(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity(['maxlead' => 1, 'maxmembership' => 2]);
        $leader = (int) $users[0]->id;
        $member = (int) $users[1]->id;
        $group = $this->make_group($activity, $leader, $member);

        // Decline clears.
        $api->succession()->nominate($group, $member, 'transfer', $leader);
        $api->succession()->decline(groups::get($activity, (int) $group->id), $member);
        $this->assertNull(groups::get($activity, (int) $group->id)->successorid);

        // Cancel clears (leader only).
        $api->succession()->nominate(groups::get($activity, (int) $group->id), $member, 'transfer', $leader);
        try {
            $api->succession()->cancel(groups::get($activity, (int) $group->id), $member);
            $this->fail('Only the leader cancels');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('leader', $e->getMessage());
        }
        $api->succession()->cancel(groups::get($activity, (int) $group->id), $leader);
        $this->assertNull(groups::get($activity, (int) $group->id)->successorid);

        // S2: no nominations outside forming.
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $group->id]);
        $fresh = groups::get($activity, (int) $group->id);
        $this->assertSame(
            'refusalwrongstate',
            $api->gatekeeper()->can_nominate($fresh, $member, 'transfer', $leader)?->stringkey
        );
        $this->assertSame(
            'refusalwrongstate',
            $api->gatekeeper()->can_confirm_succession($fresh, $member)?->stringkey
        );
    }
}
