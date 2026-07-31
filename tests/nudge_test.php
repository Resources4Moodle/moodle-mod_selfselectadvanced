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
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\flagged_defaulters_table;
use mod_selfselectadvanced\table\flagged_guides_table;

/**
 * 1.8.0: the flagged report's guides-tab load counting query (one
 * grouped query for the whole page, mirroring
 * groups::count_guiding()) and the bulk nudge recipient sets
 * (de-duplicated per guide, scoped to the currently filtered rows).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\flagged_guides_table
 * @covers     \mod_selfselectadvanced\table\flagged_defaulters_table
 */
final class nudge_test extends \advanced_testcase {
    /**
     * Create a course, an activity instance, enrolled students and
     * enrolled guides for the fixtures below.
     *
     * @param array $overrides instance setting overrides
     * @return array{0: activity, 1: \stdClass[], 2: \stdClass[]} activity, students, guides
     */
    private function setup_activity(array $overrides = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minmembership' => 2,
            'guidewindow' => HOURSECS * 24,
        ], $overrides));
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            // NAMED, not generated. create_user() draws its names at
            // random from a pool of 300 first/last combinations
            // (lib/testing/generator/data_generator.php uses rand(),
            // and nothing seeds it), so two of these three students
            // shared a full name on about one run in 280 - measured
            // 1068/300000. The recipient filter below is a LIKE on the
            // full name, so that collision made it return two ids and
            // reddened the gate for a tree that had not changed.
            $student = $generator->create_user([
                'firstname' => 'Defaulter',
                'lastname' => 'Student' . $i,
            ]);
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }
        $guides = [];
        for ($i = 0; $i < 2; $i++) {
            $guide = $generator->create_user();
            $generator->enrol_user($guide->id, $course->id, 'teacher');
            $guides[] = $guide;
        }

        return [$activity, $students, $guides];
    }

    /**
     * The grouped load-count query counts only the guiding states
     * (pending_guide, firm, frozen), matching groups::count_guiding(),
     * scoped to exactly the requested guide ids, in one query.
     */
    public function test_guide_load_counts_matches_count_guiding(): void {
        $this->resetAfterTest();
        [$activity, $students, $guides] = $this->setup_activity();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        [$guidea, $guideb] = $guides;

        // Guide A: two counting groups (pending_guide, firm) plus one
        // forming group that must NOT count.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[0]->id,
            'guideid' => $guidea->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time(),
            'name' => 'Guide A pending',
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[1]->id,
            'guideid' => $guidea->id,
            'state' => state::FIRM,
            'timesubmitted' => time(),
            'name' => 'Guide A firm',
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[2]->id,
            'guideid' => $guidea->id,
            'state' => state::FORMING,
            'name' => 'Guide A forming',
        ]);

        // Guide B: one frozen group.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[0]->id,
            'guideid' => $guideb->id,
            'state' => state::FROZEN,
            'timesubmitted' => time(),
            'name' => 'Guide B frozen',
        ]);

        $counts = flagged_guides_table::guide_load_counts($activity, [(int) $guidea->id, (int) $guideb->id]);
        $this->assertSame(2, $counts[(int) $guidea->id]);
        $this->assertSame(1, $counts[(int) $guideb->id]);
        $this->assertSame(groups::count_guiding($activity, (int) $guidea->id), $counts[(int) $guidea->id]);
        $this->assertSame(groups::count_guiding($activity, (int) $guideb->id), $counts[(int) $guideb->id]);

        // An empty request never explodes and contributes nothing.
        $this->assertSame([], flagged_guides_table::guide_load_counts($activity, []));
        // A guide id with no groups is simply absent from the result.
        $this->assertArrayNotHasKey(999999, flagged_guides_table::guide_load_counts($activity, [999999]));
    }

    /**
     * Defaulter recipients are exactly the enrolled respond-capability
     * holders below the minimum, scoped to the current name filter, one
     * id per student and never duplicated.
     */
    public function test_defaulter_recipients_scoped_and_deduplicated(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity(['minmembership' => 2]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Student 0 reaches the minimum (leads one group, joins a
        // second); students 1 and 2 have only their own led group, so
        // both default.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[0]->id,
            'name' => 'Alpha team',
        ]);
        $second = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[1]->id,
            'name' => 'Bravo team',
        ]);
        $plugingen->create_member([
            'groupid' => $second->id,
            'userid' => $students[0]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[2]->id,
            'name' => 'Charlie team',
        ]);

        $ids = flagged_defaulters_table::recipient_ids($activity, 2, '');
        sort($ids);
        $expected = [(int) $students[1]->id, (int) $students[2]->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertCount(count(array_unique($ids)), $ids);

        // The name filter scopes the recipient set to the currently
        // listed rows only.
        $filtered = flagged_defaulters_table::recipient_ids($activity, 2, fullname($students[1]));
        $this->assertSame([(int) $students[1]->id], $filtered);
    }

    /**
     * Overdue guide counts hold one entry per guide with an overdue
     * group, counting every overdue group of theirs (the
     * de-duplicated "one message per guide" recipient set), never
     * counting non-overdue or unassigned groups, and honouring the
     * name filter shared with the display table.
     */
    public function test_overdue_guide_counts_deduplicated_and_filtered(): void {
        $this->resetAfterTest();
        [$activity, $students, $guides] = $this->setup_activity(['guidewindow' => HOURSECS * 24]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        [$guidea, $guideb] = $guides;

        // Guide A: two overdue groups, past the 24 hour window - one
        // recipient, count 2.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[0]->id,
            'guideid' => $guidea->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time() - (HOURSECS * 48),
            'name' => 'Overdue one',
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[1]->id,
            'guideid' => $guidea->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time() - (HOURSECS * 48),
            'name' => 'Overdue two',
        ]);
        // Guide B: still within the window, must be excluded entirely.
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[2]->id,
            'guideid' => $guideb->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time(),
            'name' => 'Within window',
        ]);

        $counts = flagged_guides_table::overdue_guide_counts($activity, HOURSECS * 24, '');
        $this->assertSame([(int) $guidea->id => 2], $counts);

        // Filtering to one of guide A's two groups scopes the count
        // to exactly the filtered (listed) rows.
        $filtered = flagged_guides_table::overdue_guide_counts($activity, HOURSECS * 24, 'Overdue one');
        $this->assertSame([(int) $guidea->id => 1], $filtered);
    }
}
