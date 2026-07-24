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
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\penalty\calculator;
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\state;

/**
 * Penalty math and the authoritative ledger (spec 11, D5, A12, B2):
 * percent and points rates, the cutoff bound, leader-context date
 * overrides zeroing by arithmetic, waiver flags, recomputation, and
 * cumulative multi-group grade deduction with the zero floor and
 * null-until-placed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\penalty\calculator
 * @covers     \mod_selfselectadvanced\local\penalty\ledger
 */
final class penalty_test extends \advanced_testcase {
    /**
     * An activity with dates, one approved group of two students.
     *
     * @param array $settings instance overrides
     * @param int $approveddelta seconds after timedue the approval lands
     * @return array [activity, api, group, students[]]
     */
    private function setup_approved(array $settings = [], int $approveddelta = 0): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $now = time();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'grade' => 100,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
            'penaltytype' => 0,
            'penaltyperday' => 2,
            'timeopen' => $now - (20 * DAYSECS),
            'timedue' => $now - (10 * DAYSECS),
            'timecutoff' => $now + (10 * DAYSECS),
        ], $settings));

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $activity = activity::from_instance((int) $instance->id);
        $due = (int) $activity->settings()->timedue;
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Pen',
            'state' => state::FIRM,
            'timeapproved' => $due + $approveddelta,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $students];
    }

    /**
     * On-time approval stores an explicit zero row (A12); late percent
     * and points approvals compute per day; the cutoff bounds days.
     */
    public function test_calculator_rates_and_bounds(): void {
        $this->resetAfterTest();

        // On time: explicit zero.
        [$activity, $api, $group] = $this->setup_approved([], -DAYSECS);
        $row = ledger::upsert_for_group($activity, $group);
        $this->assertSame(0, (int) $row->dayslate);
        $this->assertSame(0.0, (float) $row->penaltyvalue);

        // 3 days late, percent type: 3 x 2% x 100 = 6 points.
        [$activity2, , $group2] = $this->setup_approved([], 3 * DAYSECS);
        $row = ledger::upsert_for_group($activity2, $group2);
        $this->assertSame(3, (int) $row->dayslate);
        $this->assertEqualsWithDelta(6.0, (float) $row->penaltyvalue, 0.0001);

        // Points type: 3 x 1.5 = 4.5.
        [$activity3, , $group3] = $this->setup_approved(['penaltytype' => 1, 'penaltyperday' => 1.5], 3 * DAYSECS);
        $row = ledger::upsert_for_group($activity3, $group3);
        $this->assertEqualsWithDelta(4.5, (float) $row->penaltyvalue, 0.0001);

        // Bounded by cutoff: approval 30 days after due, cutoff at +10d
        // from now = +20d from due -> capped at 20 days.
        [$activity4, , $group4] = $this->setup_approved([], 30 * DAYSECS);
        $row = ledger::upsert_for_group($activity4, $group4);
        $this->assertSame(20, (int) $row->dayslate);

        // Fractional day rounds up (spec: per day late).
        $this->assertSame(1, calculator::days_late(1000 + 100, 1000, 0));
    }

    /**
     * B2/P16: a leader's personal extension zeroes the penalty by
     * arithmetic with the dateoverride reason; the waiver flag zeroes
     * independently; a non-leader member's extension changes nothing.
     */
    public function test_overrides_zero_the_penalty(): void {
        $this->resetAfterTest();

        // Leader extension beyond the approval time.
        [$activity, , $group, $students] = $this->setup_approved([], 3 * DAYSECS);
        store::save($activity, 'user', (int) $students[0]->id, [
            'timedue' => (int) $group->timeapproved + DAYSECS,
        ], 0);
        $row = ledger::upsert_for_group($activity, $group);
        $this->assertSame(0, (int) $row->dayslate);
        $this->assertEquals(1, $row->waived);
        $this->assertSame('dateoverride', $row->waivereason);

        // A NON-leader member's extension must not zero it (P16).
        [$activity2, , $group2, $students2] = $this->setup_approved([], 3 * DAYSECS);
        store::save($activity2, 'user', (int) $students2[1]->id, [
            'timedue' => (int) $group2->timeapproved + DAYSECS,
        ], 0);
        $row = ledger::upsert_for_group($activity2, $group2);
        $this->assertSame(3, (int) $row->dayslate);
        $this->assertEquals(0, $row->waived);

        // Explicit waiver flag.
        [$activity3, , $group3] = $this->setup_approved([], 3 * DAYSECS);
        store::save($activity3, 'group', (int) $group3->id, ['penaltywaived' => 1], 0);
        $row = ledger::upsert_for_group($activity3, $group3);
        $this->assertEquals(1, $row->waived);
        $this->assertSame('waiver', $row->waivereason);
        $this->assertSame(0.0, (float) $row->penaltyvalue);
    }

    /**
     * D5 cumulative grades: each group's own penalty deducts per
     * confirmed member; the floor is zero; students in no firm/frozen
     * group stay null; recompute_all follows date edits with the event.
     */
    public function test_grades_and_recompute(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $group, $students] = $this->setup_approved([], 3 * DAYSECS);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $s0 = (int) $students[0]->id;
        $s2 = (int) $students[2]->id;

        // A second late group shared by the leader (maxmembership 2).
        $due = (int) $activity->settings()->timedue;
        $group2 = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $s2,
            'name' => 'Pen2',
            'state' => state::FIRM,
            'timeapproved' => $due + (5 * DAYSECS),
        ]);
        $plugingen->create_member([
            'groupid' => $group2->id,
            'userid' => $s0,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        ledger::recompute_all($activity);

        // Leader s0: 100 - 6 (3d) - 10 (5d) = 84. s2 only group2: 90.
        $grades = grade_get_grades($activity->courseid(), 'mod', 'selfselectadvanced', $activity->id(), [
            $s0, $s2, (int) $students[1]->id,
        ]);
        $this->assertEquals(84.0, (float) $grades->items[0]->grades[$s0]->grade);
        $this->assertEquals(90.0, (float) $grades->items[0]->grades[$s2]->grade);
        // Member s1 is only in the first group: 94.
        $this->assertEquals(94.0, (float) $grades->items[0]->grades[(int) $students[1]->id]->grade);

        // Floor at zero: brutal rate.
        $DB->set_field('selfselectadvanced', 'penaltyperday', 90, ['id' => $activity->id()]);
        ledger::recompute_all(activity::from_instance($activity->id()));
        $grades = grade_get_grades($activity->courseid(), 'mod', 'selfselectadvanced', $activity->id(), [$s0]);
        $this->assertEquals(0.0, (float) $grades->items[0]->grades[$s0]->grade);

        // Recompute fires the delta event.
        $DB->set_field('selfselectadvanced', 'penaltyperday', 2, ['id' => $activity->id()]);
        $sink = $this->redirectEvents();
        ledger::recompute_all(activity::from_instance($activity->id()));
        $events = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\penalty_recomputed
        );
        $sink->close();
        $this->assertCount(2, $events);

        // Null-until-placed: a groupless student has no grade.
        $ghost = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($ghost->id, $activity->courseid(), 'student');
        $grades = grade_get_grades($activity->courseid(), 'mod', 'selfselectadvanced', $activity->id(), [
            (int) $ghost->id,
        ]);
        $this->assertNull($grades->items[0]->grades[(int) $ghost->id]->grade ?? null);
    }

    /**
     * Approval writes the ledger row and pushes grades (spec 11 wiring).
     */
    public function test_approval_writes_ledger(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $now = time();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxlead' => 1,
            'maxmembership' => 1,
            'penaltytype' => 1,
            'penaltyperday' => 4,
            'timedue' => $now - (2 * DAYSECS) + HOURSECS,
            'timecutoff' => $now + (5 * DAYSECS),
        ]);
        $leader = $generator->create_user();
        $guide = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);
        $this->setUser($leader);
        $group = $api->create_group((int) $leader->id, 'Wired', 'T', '<p>b</p>', FORMAT_HTML);
        $group = $api->lifecycle()->submit(groups::get($activity, (int) $group->id), (int) $guide->id, (int) $leader->id);
        $api->lifecycle()->approve($group, (int) $guide->id);

        $row = $DB->get_record('selfselectadvanced_penalty', ['groupid' => $group->id], '*', MUST_EXIST);
        $this->assertSame(2, (int) $row->dayslate);
        $this->assertEqualsWithDelta(8.0, (float) $row->penaltyvalue, 0.0001);
        $grades = grade_get_grades($course->id, 'mod', 'selfselectadvanced', $activity->id(), [(int) $leader->id]);
        $this->assertEquals(92.0, (float) $grades->items[0]->grades[(int) $leader->id]->grade);
    }
}
