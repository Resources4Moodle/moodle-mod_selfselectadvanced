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

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\penalty\gradebook;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\flagged_defaulters_table;

/**
 * The defaulter REPORT and the defaulter GRADE must count the same
 * memberships.
 *
 * They did not. The report counted every confirmed membership row
 * whatever state its group was in, while the grade counted only
 * memberships of FIRM or FROZEN groups, so a student parked in a
 * forming team was docked a defaulter penalty without ever appearing on
 * the report that exists to warn them. The maintainer's decision is
 * that BOTH surfaces count firm/frozen only, so these tests drive both
 * surfaces over one fixture and assert they answer the same question -
 * patching one side alone reddens them again.
 *
 * The single deliberate divergence that survives is pinned below: a
 * student in NO qualifying group keeps a null grade (ledger.php's
 * contract, which is what nulls out a departed student's grade), so
 * they are listed and not penalised.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\flagged_defaulters_table
 * @covers     \mod_selfselectadvanced\local\penalty\gradebook
 */
final class defaulterbasis_test extends \advanced_testcase {
    /** @var int The minimum memberships the fixture activity demands. */
    private const MINMEMBERSHIP = 2;

    /**
     * One activity, deadline already past, and three students:
     * "mixed" holds one FIRM plus one FORMING membership, "complete"
     * holds two FIRM memberships, "groupless" holds none.
     *
     * @return array{0: activity, 1: array<string, int>} activity, userids by role
     */
    private function fixture(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'grade' => 100,
            'minsize' => 1,
            'maxlead' => 2,
            'maxmembership' => 2,
            'minmembership' => self::MINMEMBERSHIP,
            'defaulterpenalty' => 10,
            'incompletepenalty' => 0,
            // Past, so the defaulter branch is armed at all.
            'timedue' => time() - DAYSECS,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        // Explicit, mutually non-overlapping surnames: create_user()
        // draws random names, and the report's filter is a LIKE on the
        // full name.
        $users = [];
        foreach (['mixed' => 'Mixedonly', 'complete' => 'Completeonly', 'groupless' => 'Grouplessonly'] as $role => $surname) {
            $user = $generator->create_user(['firstname' => 'Basis', 'lastname' => $surname]);
            $generator->enrol_user($user->id, $course->id, 'student');
            $users[$role] = (int) $user->id;
        }

        // Mixed: one membership the grade counts, one it does not.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $users['mixed'],
            'name' => 'Mixed firm',
            'state' => state::FIRM,
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $users['mixed'],
            'name' => 'Mixed forming',
            'state' => state::FORMING,
        ]);

        // Complete: two memberships the grade counts, so at the minimum.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $users['complete'],
            'name' => 'Complete one',
            'state' => state::FIRM,
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $users['complete'],
            'name' => 'Complete two',
            'state' => state::FROZEN,
        ]);

        return [$activity, $users];
    }

    /**
     * What the report says each listed student is short of, keyed by
     * userid; a student the report does not list is short of nothing.
     *
     * @param activity $activity the activity
     * @param int[] $userids the students to report on
     * @return array<int, int> userid => shortfall
     */
    private function report_shortfall(activity $activity, array $userids): array {
        $url = new \moodle_url('/mod/selfselectadvanced/flagged.php', ['id' => $activity->cm()->id]);
        $table = new flagged_defaulters_table('defaulterbasis', $activity, $url, self::MINMEMBERSHIP, '');
        $table->define_baseurl($url);
        $table->setup();
        $table->query_db(50, false);

        $shortfall = array_fill_keys($userids, 0);
        foreach ($table->rawdata as $row) {
            if (array_key_exists((int) $row->id, $shortfall)) {
                $shortfall[(int) $row->id] = $table->col_missing($row);
            }
        }

        return $shortfall;
    }

    /**
     * How many defaulter steps the grade decomposition appends, keyed
     * by userid.
     *
     * @param activity $activity the activity
     * @param int[] $userids the students to compute
     * @return array<int, int> userid => defaulter step count
     */
    private function grade_shortfall(activity $activity, array $userids): array {
        $steps = [];
        foreach (gradebook::compute_activity($activity, $userids) as $userid => $computed) {
            $steps[(int) $userid] = count(array_filter(
                $computed->steps,
                static fn(string $step) => stripos($step, 'defaulter') !== false
            ));
        }

        return $steps;
    }

    /**
     * The two surfaces count the same memberships: what the report says
     * a student still owes is exactly what the gradebook docks them for.
     *
     * Before the fix the report counted the forming membership and the
     * gradebook did not, so "mixed" was docked a defaulter step while
     * the report showed them as having met the minimum - penalised
     * without ever being told.
     */
    public function test_report_and_grade_count_the_same_memberships(): void {
        $this->resetAfterTest();
        [$activity, $users] = $this->fixture();
        $counted = [$users['mixed'], $users['complete']];

        $report = $this->report_shortfall($activity, $counted);
        $grade = $this->grade_shortfall($activity, $counted);

        // Absolute values first, so the agreement below cannot be
        // satisfied by both surfaces going blank.
        $this->assertSame(1, $grade[$users['mixed']]);
        $this->assertSame(0, $grade[$users['complete']]);
        $this->assertSame($grade, $report);

        // And the set the "message all defaulters" action would write
        // to agrees with the grade: the student being docked is on it.
        $recipients = flagged_defaulters_table::recipient_ids($activity, self::MINMEMBERSHIP, '');
        $this->assertContains($users['mixed'], $recipients);
        $this->assertNotContains($users['complete'], $recipients);
    }

    /**
     * The raw count the report publishes is the firm/frozen count, not
     * the every-state count: the export column a teacher reads must not
     * credit a forming membership the grade ignores.
     */
    public function test_report_credits_only_the_memberships_the_grade_counts(): void {
        $this->resetAfterTest();
        [$activity, $users] = $this->fixture();

        $rows = flagged_defaulters_table::export_rows($activity, self::MINMEMBERSHIP, 'Mixedonly');

        $this->assertCount(1, $rows);
        [, $has, $missing] = $rows[0];
        $this->assertSame(1, $has);
        $this->assertSame(1, $missing);
    }

    /**
     * The one divergence the maintainer accepted with this decision: a
     * student in no qualifying group at all is LISTED by the report but
     * carries a null grade rather than a penalised one, because
     * push_grades() only grades students holding a firm or frozen
     * membership and nulls everybody else out. Pinned rather than
     * hidden, so reversing the decision reddens a test.
     */
    public function test_groupless_student_is_listed_yet_keeps_a_null_grade(): void {
        $this->resetAfterTest();
        [$activity, $users] = $this->fixture();

        $recipients = flagged_defaulters_table::recipient_ids($activity, self::MINMEMBERSHIP, '');
        $this->assertContains($users['groupless'], $recipients);

        $computed = gradebook::compute_user($activity, $users['groupless']);
        $this->assertFalse($computed->hasmembership);
        $this->assertNull($computed->grade);
        $this->assertSame([], $computed->steps);
    }

    /**
     * A membership that is not confirmed was never counted by either
     * surface and still is not: the state filter must not be read as
     * loosening the status filter.
     */
    public function test_unconfirmed_membership_counts_for_neither_surface(): void {
        $this->resetAfterTest();
        [$activity, $users] = $this->fixture();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $extra = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $users['complete'],
            'name' => 'Invitation only',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => (int) $extra->id,
            'userid' => $users['mixed'],
            'status' => groups::STATUS_INVITED,
        ]);

        $report = $this->report_shortfall($activity, [$users['mixed']]);
        $grade = $this->grade_shortfall($activity, [$users['mixed']]);

        $this->assertSame(1, $report[$users['mixed']]);
        $this->assertSame($grade, $report);
    }
}
