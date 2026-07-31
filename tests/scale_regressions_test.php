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

        $table = new \mod_selfselectadvanced\table\groups_table(
            'scaletest',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/'),
            '',
            true
        );
        $rows = $DB->get_records_sql(
            "SELECT {$table->sql->fields} FROM {$table->sql->from} WHERE {$table->sql->where}",
            $table->sql->params
        );
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(2, (int) $row->confirmedcount);
        $this->assertSame(1, (int) $row->invitedcount);
        $fromrow = $api->gatekeeper()->seat_position(
            $row,
            (int) $row->confirmedcount,
            (int) $row->invitedcount
        );
        $this->assertEquals($counted, $fromrow);
    }

    /**
     * T-16 requirement 1: the mirror sync costs the same number of
     * reads whatever the roster size - it reads two sets and applies a
     * delta, and the delta of an ordinary change is 0-2 core calls.
     *
     * preventResetByRollback() first: the sync writes to core only when
     * no transaction is open, and advanced_testcase opens one before
     * every test on PostgreSQL - the deferral path would make every
     * figure below a measurement of nothing.
     */
    public function test_sync_cost_scales_with_delta_not_roster(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$smallactivity, $smallfrozen] = $this->frozen_team(3);
        [$bigactivity, $bigfrozen] = $this->frozen_team(20);

        // Warm the per-activity caches on BOTH sides first (course,
        // course module, module context): a cold second course would
        // otherwise be measured as if the roster had cost it.
        \mod_selfselectadvanced\local\freeze::sync_core_group($smallactivity, (int) $smallfrozen->id, 0);
        \mod_selfselectadvanced\local\freeze::sync_core_group($bigactivity, (int) $bigfrozen->id, 0);

        $before = $DB->perf_get_reads();
        \mod_selfselectadvanced\local\freeze::sync_core_group($smallactivity, (int) $smallfrozen->id, 0);
        $smallreads = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        \mod_selfselectadvanced\local\freeze::sync_core_group($bigactivity, (int) $bigfrozen->id, 0);
        $bigreads = $DB->perf_get_reads() - $before;

        $this->assertSame(
            $smallreads,
            $bigreads,
            "an in-step sync scaled with the roster: $smallreads reads for 4 members, $bigreads for 21"
        );

        // One member removed out of band: the repair costs a small
        // constant more, not a function of the roster.
        $membertoremove = (int) $DB->get_field_sql(
            "SELECT userid FROM {selfselectadvanced_member} WHERE groupid = ? AND status = ? ORDER BY id",
            [(int) $bigfrozen->id, groups::STATUS_CONFIRMED],
            IGNORE_MULTIPLE
        );
        $DB->delete_records('groups_members', [
            'groupid' => (int) $bigfrozen->coregroupid,
            'userid' => $membertoremove,
        ]);

        $before = $DB->perf_get_reads();
        $sync = \mod_selfselectadvanced\local\freeze::sync_core_group($bigactivity, (int) $bigfrozen->id, 0);
        $repairreads = $DB->perf_get_reads() - $before;

        $this->assertSame([$membertoremove], $sync->added);
        $this->assertLessThanOrEqual(
            $bigreads + 10,
            $repairreads,
            "repairing one member cost $repairreads reads against an in-step $bigreads"
        );
    }

    /**
     * D7-E1: one bulk-freeze request never freezes an unbounded
     * selection. The first BULK_FREEZE_INLINE_MAX are handled inline
     * and the remainder is handed to cron, which freezes them there.
     */
    public function test_bulk_freeze_caps_inline(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        $ids = range(1, 25);
        $split = \mod_selfselectadvanced\local\freeze::split_bulk_selection($ids);

        $this->assertCount(20, $split['inline']);
        $this->assertCount(5, $split['queued']);
        $this->assertSame(range(21, 25), array_values($split['queued']));

        // End to end, through the SAME entry point guide.php calls -
        // asserting the split helper on its own leaves the page free to
        // stop calling it, which is the defect, not the helper.
        [$activity, $guideid, $groupids] = $this->firm_teams(25);
        $result = \mod_selfselectadvanced\local\freeze::bulk_freeze($activity, $groupids, $guideid);

        $this->assertSame(20, $result->done, 'the request did not stop at the inline cap');
        $this->assertSame([], $result->skipped);
        $this->assertSame(5, $result->queued);
        $states = [];
        foreach ($groupids as $groupid) {
            $states[] = groups::get($activity, $groupid)->state;
        }
        $this->assertSame(20, count(array_filter($states, static fn($s) => $s === state::FROZEN)));
        $this->assertSame(5, count(array_filter($states, static fn($s) => $s === state::FIRM)));

        // Exactly one task, holding exactly the overflow.
        $tasks = \core\task\manager::get_adhoc_tasks(\mod_selfselectadvanced\task\bulkfreeze_adhoc::class);
        $this->assertCount(1, $tasks);
        $queueddata = (object) reset($tasks)->get_custom_data();
        $this->assertSame(
            array_slice($groupids, 20),
            array_map('intval', (array) $queueddata->groupids)
        );

        // And cron really does freeze the remainder.
        $this->runAdhocTasks();

        foreach ($groupids as $groupid) {
            $after = groups::get($activity, $groupid);
            $this->assertSame(state::FROZEN, $after->state);
            $this->assertNotEmpty($after->coregroupid);
        }
    }

    /**
     * A course of firm one-person teams sharing one guide, ready to be
     * bulk-frozen.
     *
     * @param int $count how many teams
     * @return array [activity, guide userid, plugin group ids in creation order]
     */
    private function firm_teams(int $count): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ], ['idnumber' => 'SSABLK']);
        $activity = activity::from_instance((int) $instance->id);

        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $groupids = [];
        for ($i = 0; $i < $count; $i++) {
            $leader = $generator->create_user();
            $generator->enrol_user($leader->id, $course->id, 'student');
            $row = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $leader->id,
                'name' => 'Bulk ' . $i,
                'state' => state::FIRM,
                'guideid' => (int) $guide->id,
                'timeapproved' => time(),
            ]);
            $groupids[] = (int) $row->id;
        }

        return [$activity, (int) $guide->id, $groupids];
    }

    /**
     * A frozen team of the given size, with its mirror in step.
     *
     * @param int $members confirmed members besides the leader
     * @param int $spares extra FIRM teams to leave unfrozen
     * @return array [activity, frozen group row, first spare group row|null]
     */
    private function frozen_team(int $members, int $spares = 0): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 30,
            'maxlead' => 5,
            'maxmembership' => 5,
        ], ['idnumber' => 'SSASCL']);
        $activity = activity::from_instance((int) $instance->id);

        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Scaled',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        for ($i = 0; $i < $members; $i++) {
            $member = $generator->create_user();
            $generator->enrol_user($member->id, $course->id, 'student');
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $member->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        $frozen = \mod_selfselectadvanced\local\freeze::freeze_group(
            $activity,
            groups::get($activity, (int) $group->id),
            (int) $guide->id
        );

        $spare = null;
        for ($i = 0; $i < $spares; $i++) {
            $sparelead = $generator->create_user();
            $generator->enrol_user($sparelead->id, $course->id, 'student');
            $row = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $sparelead->id,
                'name' => 'Spare' . $i,
                'state' => state::FIRM,
                'guideid' => (int) $guide->id,
                'timeapproved' => time(),
            ]);
            $spare ??= groups::get($activity, (int) $row->id);
        }

        return [$activity, $frozen, $spare];
    }
}
