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

use mod_selfselectadvanced\table\agrun_history_table;

/**
 * Manager-facing auto-grouping run-history export (1.8.0): the raw-value
 * run-summary rows and the flattened per-group decision-log rows.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\agrun_history_table
 */
final class agrunhistory_test extends \advanced_testcase {
    /**
     * Create a course and activity.
     *
     * @return activity
     */
    private function setup_activity(): activity {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);

        return activity::from_instance((int) $instance->id);
    }

    /**
     * The run-summary export: raw values, newest run first, the
     * scheduled task shown by name when triggeredby is 0.
     */
    public function test_export_rows_shape(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->setup_activity();
        $manager = $this->getDataGenerator()->create_user(['firstname' => 'Mira', 'lastname' => 'Kapoor']);

        $earlier = time() - DAYSECS;
        $later = time();
        $manualrun = (object) [
            'activityid' => $activity->id(),
            'seed' => 1,
            'triggeredby' => (int) $manager->id,
            'timestarted' => $earlier,
            'timefinished' => $earlier + 5,
            'groupsformed' => 2,
            'placed' => 5,
            'unplaced' => 1,
            'log' => json_encode(['pool' => [], 'bypassedrules' => [], 'residue' => [], 'groups' => []]),
        ];
        $manualrun->id = $DB->insert_record('selfselectadvanced_agrun', $manualrun);
        $scheduledrun = (object) [
            'activityid' => $activity->id(),
            'seed' => 2,
            'triggeredby' => 0,
            'timestarted' => $later,
            'timefinished' => $later + 5,
            'groupsformed' => 1,
            'placed' => 2,
            'unplaced' => 0,
            'log' => json_encode(['pool' => [], 'bypassedrules' => [], 'residue' => [], 'groups' => []]),
        ];
        $scheduledrun->id = $DB->insert_record('selfselectadvanced_agrun', $scheduledrun);

        $rows = agrun_history_table::export_rows($activity);
        $this->assertCount(2, $rows);

        // Newest first: the scheduled run started later.
        $this->assertSame((int) $scheduledrun->id, $rows[0]->id);
        $this->assertSame(get_string('agrunscheduled', 'mod_selfselectadvanced'), $rows[0]->triggeredby);
        $this->assertSame(1, $rows[0]->groupsformed);
        $this->assertSame(2, $rows[0]->placed);
        $this->assertSame(0, $rows[0]->unplaced);
        $this->assertSame(userdate($later), $rows[0]->timestarted);

        $this->assertSame((int) $manualrun->id, $rows[1]->id);
        $this->assertSame(fullname($manager), $rows[1]->triggeredby);
        $this->assertSame(2, $rows[1]->groupsformed);
        $this->assertSame(5, $rows[1]->placed);
        $this->assertSame(1, $rows[1]->unplaced);
    }

    /**
     * The flattened decision-log export: one row per formed group,
     * with the run's bypassed-rule and residue summary repeated on
     * every row belonging to that run.
     */
    public function test_export_log_rows_shape(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->setup_activity();
        $leader1 = $this->getDataGenerator()->create_user(['firstname' => 'Asha', 'lastname' => 'Rao']);
        $leader2 = $this->getDataGenerator()->create_user(['firstname' => 'Devi', 'lastname' => 'Nair']);
        $member = $this->getDataGenerator()->create_user();

        $log = [
            'pool' => [(int) $leader1->id, (int) $leader2->id, (int) $member->id],
            'bypassedrules' => [7, 12],
            'residue' => [999],
            'groups' => [
                ['pluginuid' => 'SSA-TEST-0001', 'leaderid' => (int) $leader1->id, 'members' => [
                    (int) $leader1->id, (int) $member->id,
                ]],
                ['pluginuid' => 'SSA-TEST-0002', 'leaderid' => (int) $leader2->id, 'members' => [(int) $leader2->id]],
            ],
        ];
        $run = (object) [
            'activityid' => $activity->id(),
            'seed' => 3,
            'triggeredby' => 0,
            'timestarted' => time(),
            'timefinished' => time(),
            'groupsformed' => 2,
            'placed' => 3,
            'unplaced' => 1,
            'log' => json_encode($log),
        ];
        $run->id = $DB->insert_record('selfselectadvanced_agrun', $run);

        $rows = agrun_history_table::export_log_rows($activity);
        $this->assertCount(2, $rows);

        $this->assertSame((int) $run->id, $rows[0]->runid);
        $this->assertSame('SSA-TEST-0001', $rows[0]->pluginuid);
        $this->assertSame(fullname($leader1), $rows[0]->leader);
        $this->assertSame(2, $rows[0]->membercount);
        $this->assertSame('7, 12', $rows[0]->bypassed);
        $this->assertSame(1, $rows[0]->residue);

        $this->assertSame((int) $run->id, $rows[1]->runid);
        $this->assertSame('SSA-TEST-0002', $rows[1]->pluginuid);
        $this->assertSame(fullname($leader2), $rows[1]->leader);
        $this->assertSame(1, $rows[1]->membercount);
        // The run-level summary is repeated identically on every row.
        $this->assertSame('7, 12', $rows[1]->bypassed);
        $this->assertSame(1, $rows[1]->residue);
    }

    /**
     * No bypassed rules renders as a dash rather than an empty string.
     */
    public function test_export_log_rows_no_bypassed_rules(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->setup_activity();
        $leader = $this->getDataGenerator()->create_user();

        $log = [
            'pool' => [(int) $leader->id],
            'bypassedrules' => [],
            'residue' => [],
            'groups' => [
                ['pluginuid' => 'SSA-TEST-0003', 'leaderid' => (int) $leader->id, 'members' => [(int) $leader->id]],
            ],
        ];
        $run = (object) [
            'activityid' => $activity->id(),
            'seed' => 4,
            'triggeredby' => 0,
            'timestarted' => time(),
            'timefinished' => time(),
            'groupsformed' => 1,
            'placed' => 1,
            'unplaced' => 0,
            'log' => json_encode($log),
        ];
        $DB->insert_record('selfselectadvanced_agrun', $run);

        $rows = agrun_history_table::export_log_rows($activity);
        $this->assertCount(1, $rows);
        $this->assertSame('-', $rows[0]->bypassed);
        $this->assertSame(0, $rows[0]->residue);
    }
}
