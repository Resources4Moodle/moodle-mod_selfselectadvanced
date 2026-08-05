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
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\fit;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\quota\evaluator;
use mod_selfselectadvanced\local\quota\slots;
use mod_selfselectadvanced\local\state;

/**
 * One person may hold only one pending claim on one team.
 *
 * FOUND IN LIVE USE, 2026-08-05. A student was invited to a team and then
 * also asked to join it, which nothing prevented: invitations and join
 * requests are separate rows and neither path knew about the other. The
 * advisory projection in fit merged confirmed + invited + the requester
 * WITHOUT DEDUPE, and the seat engine does not collapse a repeated userid -
 * so that one student was counted as two people. A team of two SCOPE members
 * that met "between 2 and 2 with Department SCOPE" EXACTLY was told the
 * maximum was exceeded, by a phantom that was the requester's own invitation.
 *
 * Two fixes, and this file pins both, because either alone leaves the defect
 * reachable: the maintainer's rule that a request to a team which already
 * invited you simply ACCEPTS that invitation, and a dedupe at the projection
 * for every other way a repeat could arrive.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\fit
 */
final class invitation_request_collision_test extends \advanced_testcase {
    /**
     * An activity of five with the maintainer's live rule: exactly two
     * members whose department is SCOPE.
     *
     * @return array [activity, api, leader, invitee]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $plugin = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 5,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $invitee = $generator->create_user();
        foreach ([$leader, $invitee] as $u) {
            $generator->enrol_user($u->id, $course->id, 'student');
            manager::set((int) $u->id, ['department' => 'SCOPE', 'subdepartment' => 'BAI'], 2);
        }

        slots::create($activity, (object) [
            'mincount' => 2, 'maxcount' => 2, 'dimension' => 'department',
            'matchtype' => 'value', 'value' => 'SCOPE', 'allowoverlap' => 0,
        ], (int) get_admin()->id);

        $group = $plugin->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Alpha',
            'state' => state::FORMING,
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $invitee];
    }

    /**
     * Asking to join a team that already invited you makes you a member.
     *
     * MUTATION CAUGHT (run): removing the invitation branch from
     * joinrequests::request() leaves the member row at 'invited' and creates a
     * pending move instead, so the first assertion fails.
     */
    public function test_a_request_to_a_team_that_invited_you_accepts_the_invitation(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $group, $invitee] = $this->world();

        $api->invitations()->send($group, (int) $invitee->id, (int) $group->leaderid);
        $this->assertSame(
            groups::STATUS_INVITED,
            $DB->get_field(
                'selfselectadvanced_member',
                'status',
                ['groupid' => $group->id, 'userid' => $invitee->id]
            )
        );

        $outcome = joinrequests::request(
            $activity,
            (int) $group->id,
            'Alpha sounds interesting',
            (int) $invitee->id
        );

        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field(
                'selfselectadvanced_member',
                'status',
                ['groupid' => $group->id, 'userid' => $invitee->id]
            ),
            'asking to join a team that had already invited you must accept that invitation'
        );
        $this->assertTrue(
            !empty($outcome->acceptedinvitation),
            'the caller must be able to tell the student they are a member, not that they are waiting'
        );
        $this->assertSame(
            0,
            $DB->count_records(
                'selfselectadvanced_move',
                ['targetgroupid' => (int) $group->id, 'status' => joinrequests::STATUS_REQUESTED]
            ),
            'no second pending row may exist for one fact'
        );
    }

    /**
     * The picker projection dedupes a candidate who is already invited.
     *
     * MUTATION CAUGHT (run): removing array_unique() from the projection
     * made fit::for_groups() count the invited candidate twice, report a
     * third SCOPE member against the maximum of two, and add a warning.
     */
    public function test_picker_projection_dedupes_an_invited_candidate(): void {
        $this->resetAfterTest();
        [$activity, $api, $group, $invitee] = $this->world();
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
            'maxcount' => 2,
        ]);

        $api->invitations()->send($group, (int) $invitee->id, (int) $group->leaderid);

        $answers = fit::for_groups($activity, [$group], (int) $invitee->id);
        $this->assertTrue($answers[(int) $group->id]->fits);
        $this->assertSame('', $answers[(int) $group->id]->caution);
        $this->assertSame([], $answers[(int) $group->id]->warnings);
    }
}
