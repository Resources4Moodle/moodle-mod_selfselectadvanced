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

use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;

/**
 * Decision 61: a course-level suspension must not become a rules
 * violation for a settled team.
 *
 * The institution suspends a student AFTER their team was approved by
 * its guide or frozen by staff. Nothing the team can do repairs that,
 * and a FIRM or FROZEN team is re-validated on full compliance - so
 * without compensation, the next move touching the team is refused
 * over a fact none of its members control. The engine therefore writes
 * the group-scope quotaexempt override ITSELF, the moment the
 * suspension lands.
 *
 * The maintainer, 2026-08-06: "When that happens (after guide approval
 * or freeze by group coordinator or editing teacher), the engine
 * should automatically add an override ensuring that the specified
 * rule is not violated for that group. This has always been my stand."
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\observer
 */
final class suspension_override_test extends \advanced_testcase {
    /**
     * A team in the given state whose one non-leader member can be
     * suspended.
     *
     * @param string $groupstate the lifecycle state to pin the team in
     * @return array [activity, group row, member user, course]
     */
    private function world(string $groupstate): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
        ]);

        $mk = function () use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => 'SCOPE', 'subdepartment' => 'BAI'], 2);

            return $user;
        };
        $leader = $mk();
        $member = $mk();
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Settled',
            'state' => $groupstate,
            'timeapproved' => in_array($groupstate, [state::FIRM, state::FROZEN], true) ? time() : null,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, $group, $member, $course];
    }

    /**
     * Suspend a user's manual enrolment through the enrol API, so the
     * real user_enrolment_updated event fires exactly as it does when
     * the institution does it.
     *
     * @param \stdClass $course the course
     * @param int $userid the user
     * @param int $status ENROL_USER_SUSPENDED or ENROL_USER_ACTIVE
     */
    private function set_enrol_status(\stdClass $course, int $userid, int $status): void {
        global $DB;

        $instance = $DB->get_record('enrol', [
            'courseid' => $course->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, $userid, $status);
    }

    /**
     * The quotaexempt override row for a group, or null.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @return \stdClass|null
     */
    private function override_row(activity $activity, int $groupid): ?\stdClass {
        return \mod_selfselectadvanced\local\override\store::get($activity, 'group', $groupid);
    }

    /**
     * A member of a guide-approved (FIRM) team is suspended: the
     * engine grants the exemption itself.
     *
     * MUTATION CAUGHT (run): removing the observer registration, or
     * the FIRM state from its query, leaves the override table empty
     * and this test red.
     */
    public function test_suspension_in_a_firm_team_writes_the_exemption(): void {
        $this->resetAfterTest();
        [$activity, $group, $member, $course] = $this->world(state::FIRM);

        $this->set_enrol_status($course, (int) $member->id, ENROL_USER_SUSPENDED);

        $row = $this->override_row($activity, (int) $group->id);
        $this->assertNotNull($row, 'the engine must compensate a settled team for an institutional fact');
        $this->assertSame(1, (int) $row->quotaexempt);
        $this->assertTrue(
            (new \mod_selfselectadvanced\local\override\resolver($activity))
                ->is_quota_exempt((int) $group->id)->enabled,
            'and the resolver must actually serve it'
        );
    }

    /**
     * The same for a FROZEN team.
     */
    public function test_suspension_in_a_frozen_team_writes_the_exemption(): void {
        $this->resetAfterTest();
        [$activity, $group, $member, $course] = $this->world(state::FROZEN);

        $this->set_enrol_status($course, (int) $member->id, ENROL_USER_SUSPENDED);

        $row = $this->override_row($activity, (int) $group->id);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->quotaexempt);
    }

    /**
     * A FORMING team gets nothing: it can still rebuild, and an
     * automatic waiver would hide a roster problem the team can fix.
     */
    public function test_suspension_in_a_forming_team_writes_nothing(): void {
        $this->resetAfterTest();
        [$activity, $group, $member, $course] = $this->world(state::FORMING);

        $this->set_enrol_status($course, (int) $member->id, ENROL_USER_SUSPENDED);

        $this->assertNull(
            $this->override_row($activity, (int) $group->id),
            'a forming team can rebuild; the engine must not waive its rules for it'
        );
    }

    /**
     * Unsuspension does not retract the waiver, and a repeat
     * suspension does not duplicate it: one row, staff may delete it.
     */
    public function test_the_exemption_survives_unsuspension_and_repeats(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, $member, $course] = $this->world(state::FIRM);

        $this->set_enrol_status($course, (int) $member->id, ENROL_USER_SUSPENDED);
        $this->set_enrol_status($course, (int) $member->id, ENROL_USER_ACTIVE);
        $this->set_enrol_status($course, (int) $member->id, ENROL_USER_SUSPENDED);

        $this->assertSame(
            1,
            $DB->count_records('selfselectadvanced_override', ['groupid' => (int) $group->id]),
            'one row per group, whatever the suspension history'
        );
        $this->assertSame(1, (int) $this->override_row($activity, (int) $group->id)->quotaexempt);
    }

    /**
     * The auto-exemption MERGES into a staff-set override instead of
     * clobbering it: a maxsize the coordinator granted survives.
     */
    public function test_the_exemption_merges_with_a_staff_override(): void {
        $this->resetAfterTest();
        [$activity, $group, $member, $course] = $this->world(state::FIRM);
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $group->id,
            ['maxsize' => 6],
            (int) get_admin()->id
        );

        $this->set_enrol_status($course, (int) $member->id, ENROL_USER_SUSPENDED);

        $row = $this->override_row($activity, (int) $group->id);
        $this->assertSame(1, (int) $row->quotaexempt);
        $this->assertSame(6, (int) $row->maxsize, 'the staff-set field must survive the automatic write');
    }
}
