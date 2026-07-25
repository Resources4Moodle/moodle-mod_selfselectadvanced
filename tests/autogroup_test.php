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

use mod_selfselectadvanced\local\autogroup\engine;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;

/**
 * Auto-grouping (spec 9, D6, A13 as corrected by B1, B4): the sizing
 * sweep that can never overflow, determinism by seed, the priority
 * relaxation cascade, the per-user-cutoff pool with re-runs, and the
 * end-to-end run into the A5 queue.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\autogroup\engine
 * @covers     \mod_selfselectadvanced\task\run_autogrouping
 */
final class autogroup_test extends \advanced_testcase {
    /**
     * B1 overflow sweep: for min/max 1..8 and pools 0..30, every
     * formed group stays within [min, max]; residue only when
     * unavoidable. Includes the review's min4/max6/pool7 case.
     */
    public function test_sizing_never_overflows(): void {
        foreach ([[2, 6], [4, 6], [1, 3], [5, 5], [3, 8]] as [$min, $max]) {
            for ($p = 0; $p <= 30; $p++) {
                $pool = range(1, $p);
                $plan = engine::plan($p ? $pool : [], $min, $max, [], [], 42);
                $placed = 0;
                foreach ($plan->groups as $members) {
                    $this->assertGreaterThanOrEqual($min, count($members), "min=$min max=$max p=$p");
                    $this->assertLessThanOrEqual($max, count($members), "min=$min max=$max p=$p");
                    $placed += count($members);
                }
                $this->assertSame($p, $placed + count($plan->residue));
            }
        }

        // The review example: min 4 / max 6 / pool 7 -> one group of 6, one residue.
        $plan = engine::plan(range(1, 7), 4, 6, [], [], 1);
        $this->assertCount(1, $plan->groups);
        $this->assertCount(6, $plan->groups[0]);
        $this->assertCount(1, $plan->residue);

        // Balanced case: min 2 / max 6 / pool 7 -> 4 + 3.
        $plan = engine::plan(range(1, 7), 2, 6, [], [], 1);
        $this->assertEqualsCanonicalizing([4, 3], array_map('count', $plan->groups));
    }

    /**
     * Determinism: identical inputs and seed produce identical plans;
     * a different seed reshuffles.
     */
    public function test_determinism(): void {
        $pool = range(1, 12);
        $a = engine::plan($pool, 2, 4, [], [], 12345);
        $b = engine::plan($pool, 2, 4, [], [], 12345);
        $this->assertEquals($a, $b);
        $c = engine::plan($pool, 2, 4, [], [], 54321);
        $this->assertNotEquals($a->groups, $c->groups);
    }

    /**
     * Relaxation cascade (spec 9.3): a min-value rule fills while
     * eligible students remain, then is bypassed and logged.
     */
    public function test_relaxation_cascade(): void {
        $rules = [(object) [
            'id' => 7,
            'rtype' => 'value',
            'dimension' => 'gender',
            'value' => 'Female',
            'mincount' => 1,
            'maxcount' => null,
            'priority' => 1,
        ]];
        $attrs = [1 => (object) ['gender' => 'Female']];
        $plan = engine::plan(range(1, 8), 4, 4, $rules, $attrs, 9);

        $this->assertCount(2, $plan->groups);
        // The single Female landed in exactly one group; the rule was
        // bypassed for the rest of the run.
        $withfemale = array_filter($plan->groups, fn($g) => in_array(1, $g, true));
        $this->assertCount(1, $withfemale);
        $this->assertSame([7], $plan->bypassed);
    }

    /**
     * B4 pool + end-to-end run: extended students are excluded until
     * their window closes; the run forms pending_guide autoformed
     * groups with leaders in the A5 queue; the task guard prevents
     * duplicate sweeps and re-runs when a window closes.
     */
    public function test_pool_run_and_task_guard(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $now = time();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 3,
            'maxlead' => 1,
            'maxmembership' => 1,
            'autogroup' => 2,
            'timecutoff' => $now - 100,
        ]);
        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = (int) $user->id;
        }
        $activity = activity::from_instance((int) $instance->id);

        // One student holds a cutoff extension: excluded (B4).
        store::save($activity, 'user', $students[4], ['timecutoff' => $now + DAYSECS], 0);
        $resolver = new resolver($activity);
        $pool = engine::pool($activity, $resolver, $now);
        $this->assertEqualsCanonicalizing(array_slice($students, 0, 4), $pool);

        // Run: 4 students -> groups within [2,3], autoformed, queued.
        $sink = $this->redirectEvents();
        $agrun = engine::run($activity, 0, 777);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_selfselectadvanced\event\autogroup_run);
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame(4, (int) $agrun->placed);
        $this->assertSame(0, (int) $agrun->unplaced);
        $formed = $DB->get_records('selfselectadvanced_group', [
            'activityid' => $activity->id(),
            'autoformed' => 1,
        ]);
        $this->assertCount((int) $agrun->groupsformed, $formed);
        foreach ($formed as $group) {
            $this->assertSame(state::PENDING_GUIDE, $group->state);
            $this->assertNull($group->guideid);
            $this->assertNotEmpty($group->leaderid);
            $this->assertEquals(1, $DB->get_field('selfselectadvanced_member', 'isleader', [
                'groupid' => $group->id,
                'userid' => $group->leaderid,
            ]));
            $size = groups::count_confirmed((int) $group->id);
            $this->assertGreaterThanOrEqual(2, $size);
            $this->assertLessThanOrEqual(3, $size);
        }

        // Seed replay: log records the seed used.
        $this->assertSame(777, (int) $agrun->seed);

        // Guard: nothing new -> no sweep due.
        $this->assertFalse(engine::sweep_due(activity::from_instance($activity->id())));

        // The extended student's window closes -> sweep due again (B4).
        store::save($activity, 'user', $students[4], ['timecutoff' => $now - 1], 0);
        $this->assertTrue(engine::sweep_due(activity::from_instance($activity->id())));
        $this->expectOutputRegex('/autogroup run/');
        (new \mod_selfselectadvanced\task\run_autogrouping())->execute();
        // The last student is residue (alone, below minsize 2).
        $lastrun = $DB->get_records('selfselectadvanced_agrun', [], 'id DESC', '*', 0, 1);
        $this->assertSame(1, (int) reset($lastrun)->unplaced);
    }
}
