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
use mod_selfselectadvanced\local\guides;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\volunteering;

/**
 * The 10k-probe fixes must not drift from the scalar definitions they
 * batch (docs/audits/rca-scale-10k.md): the bulk commitment and
 * volunteer maps equal the per-guide calls for every precedence case,
 * and a preloaded seat position equals a counted one.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\guides
 */
final class scale_regressions_test extends \advanced_testcase {
    /**
     * Bulk maps equal scalars across the precedence matrix:
     * override-capped, explicit-zero override, volunteer-capped,
     * non-volunteer, and hidden guides.
     */
    public function test_with_load_bulk_equals_scalar(): void {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->resetAfterTest();

        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'maxguided' => 5, 'guidevolunteer' => 1, 'minsize' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);
        $resolver = $api->gatekeeper()->resolver();

        $g = [];
        foreach (range(0, 4) as $i) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'teacher');
            $g[$i] = (int) $user->id;
        }
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        // Guide 0: manager override cap 4; guide 1: explicit-zero
        // override; guide 2: volunteered 2; guide 3: non-volunteer;
        // guide 4: hidden.
        \mod_selfselectadvanced\local\override\store::save($activity, 'guide', $g[0], ['maxguided' => 4], 0);
        \mod_selfselectadvanced\local\override\store::save($activity, 'guide', $g[1], ['maxguided' => 0], 0);
        volunteering::set($activity, $g[2], 2);
        \mod_selfselectadvanced\local\override\store::save($activity, 'guide', $g[4], ['guidehidden' => 1], 0);

        // Commitments: g0 guides one pending and one forming
        // preassignment; g2 guides one firm.
        $plugingen->create_group(['activityid' => $activity->id(), 'leaderid' => (int) $student->id,
            'name' => 'P', 'state' => state::PENDING_GUIDE, 'guideid' => $g[0]]);
        $other = $generator->create_user();
        $generator->enrol_user($other->id, $course->id, 'student');
        $plugingen->create_group(['activityid' => $activity->id(), 'leaderid' => (int) $other->id,
            'name' => 'F', 'state' => state::FORMING, 'guideid' => $g[0]]);
        $third = $generator->create_user();
        $generator->enrol_user($third->id, $course->id, 'student');
        $plugingen->create_group(['activityid' => $activity->id(), 'leaderid' => (int) $third->id,
            'name' => 'G', 'state' => state::FIRM, 'guideid' => $g[2]]);

        $freshapi = new api(activity::from_instance($activity->id()));
        $freshresolver = $freshapi->gatekeeper()->resolver();

        // The bulk commitment map equals the scalar for every guide.
        $bulk = eoi::guide_commitments_all($activity);
        foreach ($g as $guideid) {
            $this->assertSame(
                eoi::guide_commitments($activity, $guideid),
                $bulk[$guideid] ?? 0,
                "commitments diverge for guide $guideid"
            );
        }

        // The bulk-fed with_load equals the scalar precedence per guide.
        $load = guides::with_load($activity, $freshresolver, true);
        $this->assertArrayNotHasKey($g[4], $load, 'hidden guide leaked');
        foreach ([$g[0], $g[1], $g[2], $g[3]] as $guideid) {
            $this->assertArrayHasKey($guideid, $load);
            $this->assertSame(
                $freshresolver->effective_maxguided($guideid)->value,
                $load[$guideid]->max,
                "max diverges for guide $guideid"
            );
            $this->assertSame(
                eoi::guide_commitments($activity, $guideid),
                $load[$guideid]->used,
                "used diverges for guide $guideid"
            );
        }
        // Assignment pickers still exclude the zero-capacity cases.
        $assignable = guides::with_load($activity, $freshresolver, false);
        $this->assertArrayNotHasKey($g[3], $assignable, 'non-volunteer leaked into assignment picker');
    }

    /**
     * A preloaded seat position equals a counted one.
     */
    public function test_seat_position_preload_equivalence(): void {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->resetAfterTest();

        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'minsize' => 2, 'maxsize' => 6,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $member = $generator->create_user();
        $generator->enrol_user($member->id, $course->id, 'student');
        $invitee = $generator->create_user();
        $generator->enrol_user($invitee->id, $course->id, 'student');

        $group = $plugingen->create_group(['activityid' => $activity->id(),
            'leaderid' => (int) $leader->id, 'name' => 'S', 'state' => state::FORMING]);
        $plugingen->create_member(['groupid' => $group->id, 'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED]);
        $plugingen->create_member(['groupid' => $group->id, 'userid' => (int) $invitee->id,
            'status' => groups::STATUS_INVITED]);

        $counted = $api->gatekeeper()->seat_position($group);
        $preloaded = $api->gatekeeper()->seat_position($group, 2, 1);
        $this->assertEquals($counted, $preloaded);

        // The table's OWN query must carry the same counts - and only
        // this activity's: a busier team in another activity must not
        // leak into the aggregates or the row set.
        global $DB;
        $othercourse = $generator->create_course();
        $otherinstance = $generator->create_module('selfselectadvanced', [
            'course' => $othercourse->id, 'minsize' => 2, 'maxsize' => 6,
        ]);
        $otheractivity = activity::from_instance((int) $otherinstance->id);
        $otherleader = $generator->create_user();
        $generator->enrol_user($otherleader->id, $othercourse->id, 'student');
        $othergroup = $plugingen->create_group(['activityid' => $otheractivity->id(),
            'leaderid' => (int) $otherleader->id, 'name' => 'O', 'state' => state::FORMING]);
        foreach (range(1, 3) as $i) {
            $extra = $generator->create_user();
            $generator->enrol_user($extra->id, $othercourse->id, 'student');
            $plugingen->create_member(['groupid' => $othergroup->id,
                'userid' => (int) $extra->id, 'status' => groups::STATUS_CONFIRMED]);
        }

        $table = new \mod_selfselectadvanced\table\groups_table('scaletest', $activity,
            $api->gatekeeper(), new \moodle_url('/'), '', true);
        $rows = $DB->get_records_sql(
            "SELECT {$table->sql->fields} FROM {$table->sql->from} WHERE {$table->sql->where}",
            $table->sql->params
        );
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(2, (int) $row->confirmedcount);
        $this->assertSame(1, (int) $row->invitedcount);
        $this->assertEquals($counted, $api->gatekeeper()->seat_position(
            $row, (int) $row->confirmedcount, (int) $row->invitedcount
        ));
    }
}
