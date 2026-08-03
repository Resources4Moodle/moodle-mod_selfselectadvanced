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

use mod_selfselectadvanced\local\penalty\gradebook;
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\state;

/**
 * The defaulter penalty needs a deadline to be late against.
 *
 * `timedue` carries the plugin's 0-means-unset sentinel (db/install.xml
 * "Penalty-free deadline (0 = not set)"; mod_form.php offers the field
 * with 'optional' => true, so UNSET is what a new activity has), and
 * calculator::days_late() already honours it. The defaulter branch of
 * the sequence decomposition did not: it asked only whether now was
 * past the effective due date, and every real time is past 0, so an
 * activity with no deadline at all docked the defaulter penalty from
 * the moment it was created. These tests pin all three arms - unset,
 * past, future - so neither the defect nor an over-correction that
 * disarms a real deadline can return.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\penalty\gradebook
 */
final class defaulterdue_test extends \advanced_testcase {
    /**
     * A grade-100 activity, minimum 2 memberships, defaulter penalty
     * 10, and one student holding exactly one FIRM membership.
     *
     * @param int $timedue the activity's penalty-free deadline
     * @return array{0: activity, 1: int} the activity and the student's id
     */
    private function fixture(int $timedue): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'grade' => 100,
            'minsize' => 1,
            'maxmembership' => 2,
            'minmembership' => 2,
            'defaulterpenalty' => 10,
            'incompletepenalty' => 0,
            'timedue' => $timedue,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        // One FIRM membership: one short of the minimum, so the
        // defaulter branch has exactly one step to append when - and
        // only when - a deadline has actually passed.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $student->id,
            'name' => 'Solo team',
            'state' => state::FIRM,
        ]);

        return [$activity, (int) $student->id];
    }

    /**
     * Defaulter steps in a computed breakdown.
     *
     * @param \stdClass $computed a compute_user()/compute_activity() result
     * @return int
     */
    private function defaulter_steps(\stdClass $computed): int {
        return count(array_filter(
            $computed->steps,
            static fn(string $step) => stripos($step, 'defaulter') !== false
        ));
    }

    /**
     * With NO deadline set (the shipped default), a student below the
     * minimum is not yet late for anything: no defaulter step, full
     * marks. Before the fix this scored 90.00 with a defaulter step,
     * because `time() > 0` is true at every real moment.
     */
    public function test_unset_due_date_does_not_penalise(): void {
        $this->resetAfterTest();
        [$activity, $student] = $this->fixture(0);

        $computed = gradebook::compute_user($activity, $student);

        $this->assertSame(0, $this->defaulter_steps($computed));
        $this->assertCount(1, $computed->steps);
        $this->assertSame(100.0, $computed->grade);
    }

    /**
     * A deadline in the PAST still penalises: the fix must not disarm
     * the feature it guards.
     */
    public function test_past_due_date_still_penalises(): void {
        $this->resetAfterTest();
        [$activity, $student] = $this->fixture(time() - DAYSECS);

        $computed = gradebook::compute_user($activity, $student);

        $this->assertSame(1, $this->defaulter_steps($computed));
        $this->assertCount(2, $computed->steps);
        $this->assertSame(90.0, $computed->grade);
    }

    /**
     * A deadline in the FUTURE does not penalise: the student still has
     * time to join.
     */
    public function test_future_due_date_does_not_penalise(): void {
        $this->resetAfterTest();
        [$activity, $student] = $this->fixture(time() + DAYSECS);

        $computed = gradebook::compute_user($activity, $student);

        $this->assertSame(0, $this->defaulter_steps($computed));
        $this->assertCount(1, $computed->steps);
        $this->assertSame(100.0, $computed->grade);
    }

    /**
     * The batched path push_grades() drives reaches the REAL gradebook,
     * so the deadline-less activity is read back from the course grade
     * item, not just from the computation: 100.00, not 90.00.
     */
    public function test_unset_due_date_reaches_the_gradebook_unpenalised(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        [$activity, $student] = $this->fixture(0);

        ledger::push_grades($activity);

        $grades = grade_get_grades(
            $activity->courseid(),
            'mod',
            'selfselectadvanced',
            $activity->id(),
            [$student]
        );
        $this->assertSame(100.0, (float) $grades->items[0]->grades[$student]->grade);
    }
}
