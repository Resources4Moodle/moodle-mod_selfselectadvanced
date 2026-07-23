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
 * Group creation (transition T1), deletion (T7), the plugin uid and the
 * L3/L4 counting bases and boundaries at creation time.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\groups
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class creation_test extends \advanced_testcase {
    /**
     * Create a course, an instance with the given settings and n students.
     *
     * @param array $settings instance setting overrides
     * @param int $students number of enrolled students
     * @return array [activity, api, students[]]
     */
    private function setup_activity(array $settings = [], int $students = 3): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'PHY101']);
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
     * Creating a group writes the row, the leader member row, the
     * plugin uid (decision A1) and fires group_created.
     */
    public function test_create_group_basic(): void {
        global $DB;
        $this->resetAfterTest();

        [, $api, $users] = $this->setup_activity(['maxlead' => 2, 'maxmembership' => 2]);
        $leader = $users[0];

        $sink = $this->redirectEvents();
        $group = $api->create_group((int) $leader->id, 'Team Alpha', 'Pendulums', '<p>Study</p>', FORMAT_HTML);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_selfselectadvanced\event\group_created);
        $sink->close();

        $this->assertCount(1, $events);
        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id], '*', MUST_EXIST);
        $this->assertSame('Team Alpha', $row->name);
        $this->assertSame(state::FORMING, $row->state);
        $this->assertMatchesRegularExpression('/^SSA-PHY101-\d{4,}$/', $row->pluginuid);

        $member = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $leader->id,
        ], '*', MUST_EXIST);
        $this->assertSame(groups::STATUS_CONFIRMED, $member->status);
        $this->assertEquals(1, $member->isleader);

        // The uid is unique plugin-wide.
        $group2 = $api->create_group((int) $leader->id, 'Team Beta', 'Optics', '<p>Study</p>', FORMAT_HTML);
        $this->assertNotEquals($row->pluginuid, $group2->pluginuid);
    }

    /**
     * L3 boundary at creation: one below passes, at the cap refused.
     */
    public function test_maxlead_boundary(): void {
        $this->resetAfterTest();

        [, $api, $users] = $this->setup_activity(['maxlead' => 2, 'maxmembership' => 3]);
        $leader = (int) $users[0]->id;

        $api->create_group($leader, 'G1', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertNull($api->gatekeeper()->can_create_group($leader));

        $api->create_group($leader, 'G2', 'T', '<p>b</p>', FORMAT_HTML);
        $refusal = $api->gatekeeper()->can_create_group($leader);
        $this->assertNotNull($refusal);
        $this->assertSame('refusalleadcap', $refusal->stringkey);

        $this->expectException(\moodle_exception::class);
        $api->create_group($leader, 'G3', 'T', '<p>b</p>', FORMAT_HTML);
    }

    /**
     * L4 boundary at creation: confirmed memberships (led plus joined)
     * block creation at the cap; a pending invitation does not count.
     */
    public function test_maxmembership_boundary(): void {
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity(['maxlead' => 2, 'maxmembership' => 2]);
        /** @var \mod_selfselectadvanced_generator $plugingen */
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $student = (int) $users[0]->id;
        $other = (int) $users[1]->id;

        // Student leads one group and is a confirmed member of another.
        $api->create_group($student, 'Own', 'T', '<p>b</p>', FORMAT_HTML);
        $othergroup = $plugingen->create_group(['activityid' => $activity->id(), 'leaderid' => $other]);
        $plugingen->create_member([
            'groupid' => $othergroup->id,
            'userid' => $student,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $this->assertSame(2, groups::count_memberships($activity, $student));
        $refusal = $api->gatekeeper()->can_create_group($student);
        $this->assertNotNull($refusal);
        $this->assertSame('refusalmembershipcap', $refusal->stringkey);

        // A pending invitation does not consume the student's own cap.
        $third = (int) $users[2]->id;
        $invitegroup = $plugingen->create_group(['activityid' => $activity->id(), 'leaderid' => $other, 'name' => 'Inv']);
        $plugingen->create_member([
            'groupid' => $invitegroup->id,
            'userid' => $third,
            'status' => groups::STATUS_INVITED,
        ]);
        $this->assertSame(0, groups::count_memberships($activity, $third));
        $this->assertNull($api->gatekeeper()->can_create_group($third));
    }

    /**
     * The formation window gates creation: before open and after cutoff
     * refused, inside allowed.
     */
    public function test_window_boundaries(): void {
        $this->resetAfterTest();

        $now = time();
        [, $api, $users] = $this->setup_activity([
            'timeopen' => $now - 100,
            'timecutoff' => $now + 100,
        ]);
        $student = (int) $users[0]->id;

        $this->assertNull($api->gatekeeper()->can_create_group($student, $now));
        $this->assertSame(
            'refusalnotopen',
            $api->gatekeeper()->can_create_group($student, $now - 200)?->stringkey
        );
        $this->assertSame(
            'refusalcutoffpassed',
            $api->gatekeeper()->can_create_group($student, $now + 200)?->stringkey
        );
    }

    /**
     * Group names are unique within the activity, case-insensitively.
     */
    public function test_name_uniqueness(): void {
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity(['maxlead' => 2, 'maxmembership' => 2]);
        $api->create_group((int) $users[0]->id, 'Team Alpha', 'T', '<p>b</p>', FORMAT_HTML);

        $this->assertTrue(groups::name_taken($activity, 'team ALPHA'));
        $this->expectException(\moodle_exception::class);
        $api->create_group((int) $users[1]->id, 'team alpha', 'T', '<p>b</p>', FORMAT_HTML);
    }

    /**
     * T7: the leader deletes a forming group; rows are removed and
     * group_deleted fires. Non-leaders and non-forming states are refused.
     */
    public function test_delete_group(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity(['maxlead' => 2, 'maxmembership' => 2]);
        /** @var \mod_selfselectadvanced_generator $plugingen */
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leader = (int) $users[0]->id;

        $group = $api->create_group($leader, 'Doomed', 'T', '<p>b</p>', FORMAT_HTML);
        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id], '*', MUST_EXIST);

        // Not the leader: refused.
        $refusal = $api->gatekeeper()->can_delete_group($row, (int) $users[1]->id);
        $this->assertSame('refusalnotleader', $refusal?->stringkey);

        // Leader in forming: allowed; rows disappear and the event fires.
        $sink = $this->redirectEvents();
        $api->delete_group($row, $leader);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_selfselectadvanced\event\group_deleted);
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertFalse($DB->record_exists('selfselectadvanced_group', ['id' => $group->id]));
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', ['groupid' => $group->id]));

        // A firm group cannot be deleted by the leader (state precondition S2).
        $firm = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader,
            'state' => state::FIRM,
        ]);
        $firmrow = $DB->get_record('selfselectadvanced_group', ['id' => $firm->id], '*', MUST_EXIST);
        $this->assertSame('refusalwrongstate', $api->gatekeeper()->can_delete_group($firmrow, $leader)?->stringkey);
    }

    /**
     * Counting bases: L3 counts current leadership across live states;
     * L4 counts confirmed rows only; seats count confirmed plus invited.
     */
    public function test_counting_bases(): void {
        $this->resetAfterTest();

        [$activity, , $users] = $this->setup_activity(['maxlead' => 5, 'maxmembership' => 5], 4);
        /** @var \mod_selfselectadvanced_generator $plugingen */
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leader = (int) $users[0]->id;

        foreach ([state::FORMING, state::PENDING_GUIDE, state::FIRM, state::FROZEN] as $i => $groupstate) {
            $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => $leader,
                'name' => 'S' . $i,
                'state' => $groupstate,
            ]);
        }
        $this->assertSame(4, groups::count_leading($activity, $leader));
        $this->assertSame(4, groups::count_memberships($activity, $leader));

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $users[1]->id,
            'name' => 'Seats',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users[2]->id,
            'status' => groups::STATUS_INVITED,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users[3]->id,
            'status' => groups::STATUS_DECLINED,
        ]);

        $this->assertSame(1, groups::count_confirmed((int) $group->id));
        $this->assertSame(1, groups::count_invited((int) $group->id));
        $this->assertSame(2, groups::count_seats_taken((int) $group->id));
    }
}
