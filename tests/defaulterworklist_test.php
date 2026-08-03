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

/**
 * The defaulters report is a WORKLIST; the defaulter penalty is a PENALTY.
 * They count the same memberships and answer different questions.
 *
 * A wave-3E blind audit held the release because the CHANGELOG claimed the
 * report and the grade "can no longer tell different stories", and on the
 * shipped default - a new activity with no deadline - they still did:
 * listed_on_report=YES while grade=100.0 and defaulter steps=0. The
 * paragraph then contradicted itself two sentences later by describing a
 * case where they diverge.
 *
 * The resolution was NOT to make the report silent. It is deliberate that
 * a teacher can see who is short of approved teams at any time, deadline
 * or no deadline - that is what the page is for. What was wrong was the
 * claim, two labels that did not say what they counted, and a reminder
 * that told a student their deadline was 1 January 1970.
 *
 * So this file pins the divergence as INTENDED, in the direction that
 * matters: a later "consistency" pass that silences the report, or that
 * makes the grade penalise without a deadline, turns these red. Without
 * it the next reader sees an inconsistency and "fixes" one of the two
 * surfaces, which is how C2 came to exist in the first place.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\penalty\gradebook
 * @covers     \mod_selfselectadvanced\table\flagged_defaulters_table
 */
final class defaulterworklist_test extends \advanced_testcase {
    /** @var int The membership minimum used throughout this file. */
    private const MINMEMBERSHIP = 2;

    /**
     * With NO deadline set, the report lists the student and the grade
     * does not penalise them. Both halves are asserted in one test so
     * neither can be "aligned" away without reddening it.
     *
     * @return void
     */
    public function test_no_deadline_lists_on_the_worklist_and_penalises_nobody(): void {
        $this->resetAfterTest();
        [$activity, $student] = $this->make_activity_with_a_short_student(0);

        $this->assertTrue(
            $this->is_listed($activity, $student),
            'the worklist must still show a student short of approved teams when no deadline is set - '
                . 'it is a worklist, not a penalty ledger'
        );
        $this->assertSame(
            0,
            $this->defaulter_steps($activity, $student),
            'the defaulter penalty must NOT apply when no deadline exists (the 0 sentinel, db/install.xml)'
        );
    }

    /**
     * With a deadline that has PASSED, both surfaces agree: listed AND
     * penalised. This is the control that proves the assertion above is
     * discriminating rather than vacuously true.
     *
     * @return void
     */
    public function test_a_passed_deadline_both_lists_and_penalises(): void {
        $this->resetAfterTest();
        [$activity, $student] = $this->make_activity_with_a_short_student(time() - DAYSECS);

        $this->assertTrue($this->is_listed($activity, $student), 'still a worklist entry');
        $this->assertGreaterThan(
            0,
            $this->defaulter_steps($activity, $student),
            'a deadline that has passed MUST penalise - otherwise the first test is proving nothing'
        );
    }

    /**
     * A deadline still to come lists but does not penalise - the second
     * control, guarding the fix against over-application.
     *
     * @return void
     */
    public function test_a_future_deadline_lists_but_does_not_penalise(): void {
        $this->resetAfterTest();
        [$activity, $student] = $this->make_activity_with_a_short_student(time() + DAYSECS);

        $this->assertTrue($this->is_listed($activity, $student), 'still a worklist entry');
        $this->assertSame(0, $this->defaulter_steps($activity, $student), 'not yet late');
    }

    /**
     * Nobody is told their deadline is 1 January 1970.
     *
     * flagged.php buckets nudge recipients by effective timedue and
     * msgreminderbody reads "The penalty-free deadline is {$a->due}".
     * A recipient with no deadline was bucketed under 0 and rendered
     * userdate(0). They stay on the worklist; they are not nudged.
     *
     * @return void
     */
    public function test_a_student_with_no_deadline_is_not_nudged(): void {
        $this->resetAfterTest();
        $src = file_get_contents(__DIR__ . '/../flagged.php');
        $this->assertNotFalse($src, 'flagged.php must be readable');
        $stripped = self::code_without_comments($src);
        $this->assertStringContainsString(
            'if ($due <= 0)',
            $stripped,
            'the nudge bucketing must skip recipients with no deadline; without it '
                . 'msgreminderbody renders userdate(0) and tells a student their deadline was 1970'
        );
    }

    /**
     * Strip comments before searching source. A bare substring search is
     * satisfied by a comment - that has hidden a defect in this codebase
     * three times, so every source assertion here tokenises first.
     *
     * @param string $src php source
     * @return string the source with comments removed
     */
    private static function code_without_comments(string $src): string {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /**
     * An activity with minmembership 2 and a defaulter penalty, and one
     * enrolled student holding exactly ONE firm membership - short of the
     * minimum, but NOT teamless.
     *
     * This distinction is the whole fixture. A student in no team at all
     * keeps an empty grade whatever the deadline says (ledger.php's
     * null-grade contract), so a teamless fixture reports zero defaulter
     * steps for a reason that has nothing to do with the deadline - and
     * the no-deadline test would pass while proving nothing. The control
     * in this file caught exactly that.
     *
     * @param int $timedue the deadline, 0 for none
     * @return array [activity, student]
     */
    private function make_activity_with_a_short_student(int $timedue): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $cm = $gen->create_module('selfselectadvanced', [
            'course' => $course->id,
            'grade' => 100,
            'minsize' => 1,
            'maxlead' => 2,
            // Maxmembership must be >= minmembership or the activity asks
            // for more memberships than it permits, and the defaulter
            // branch is never armed. Omitting it made this file's own
            // control fail, which is what a control is for.
            'maxmembership' => 2,
            'minmembership' => self::MINMEMBERSHIP,
            'defaulterpenalty' => 10,
            'incompletepenalty' => 0,
            'timedue' => $timedue,
        ]);
        $student = $gen->create_user(['firstname' => 'Worklist', 'lastname' => 'Shortonly']);
        $gen->enrol_user($student->id, $course->id, 'student');

        $activity = \mod_selfselectadvanced\activity::from_instance((int) $cm->id);
        $gen->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $student->id,
            'name' => 'Worklist firm',
            'state' => \mod_selfselectadvanced\local\state::FIRM,
        ]);

        return [$activity, $student];
    }

    /**
     * Whether the defaulters worklist lists this student.
     *
     * @param mixed $activity the activity
     * @param \stdClass $student the student
     * @return bool listed
     */
    private function is_listed($activity, \stdClass $student): bool {
        $recipients = \mod_selfselectadvanced\table\flagged_defaulters_table::recipient_ids(
            $activity,
            self::MINMEMBERSHIP,
            ''
        );
        return in_array((int) $student->id, array_map('intval', $recipients), true);
    }

    /**
     * How many defaulter steps the grade applies to this student.
     *
     * @param mixed $activity the activity
     * @param \stdClass $student the student
     * @return int steps
     */
    private function defaulter_steps($activity, \stdClass $student): int {
        $computed = \mod_selfselectadvanced\local\penalty\gradebook::compute_activity(
            $activity,
            [(int) $student->id]
        );
        $row = $computed[(int) $student->id] ?? null;
        if ($row === null) {
            return 0;
        }
        // Steps is a list of localised strings and carries MORE than the
        // defaulter penalty, so a bare count() answers a different
        // question - it counted a non-defaulter step here and reddened
        // two tests. Filter for the defaulter step, the same way
        // defaulterbasis_test does.
        return count(array_filter(
            $row->steps,
            static fn(string $step) => stripos($step, 'defaulter') !== false
        ));
    }
}
