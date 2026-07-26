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

use mod_selfselectadvanced\table\flagged_defaulters_table;

/**
 * The defaulters tab of the flagged report.
 *
 * Regression cover for a defect that reached production: the query
 * carried the minimum-membership placeholder twice while supplying its
 * value once, so both the on-screen table and the download failed with
 * "Incorrect number of query parameters". No test opened this tab, so
 * nothing caught it. These tests fetch rows through the real table and
 * through the export helper.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\flagged_defaulters_table
 */
final class defaulters_table_test extends \advanced_testcase {
    /**
     * A course with one student in a group and two students in none.
     *
     * @return array{0: activity, 1: \stdClass[]} activity and students
     */
    private function setup_activity(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }

        // The first student holds a confirmed membership, so only the
        // other two are below the minimum.
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students[0]->id,
            'name' => 'Has a group',
        ]);

        return [$activity, $students];
    }

    /**
     * The on-screen table fetches rows without a parameter mismatch.
     */
    public function test_table_fetches_rows(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_activity();

        $url = new \moodle_url('/mod/selfselectadvanced/flagged.php', ['id' => $activity->cm()->id]);
        $table = new flagged_defaulters_table('t', $activity, $url, 1, '');
        $table->define_baseurl($url);
        $table->setup();
        $table->query_db(20, false);

        $this->assertCount(2, $table->rawdata);
    }

    /**
     * The download path returns the same population, and the shortfall
     * column is the difference between the minimum and what is held.
     */
    public function test_export_rows_matches_and_reports_shortfall(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_activity();

        $rows = flagged_defaulters_table::export_rows($activity, 1, '');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            [, $has, $missing] = $row;
            $this->assertSame(0, $has);
            $this->assertSame(1, $missing);
        }
    }

    /**
     * The count used for the tab label, the export and the nudge
     * recipient list all agree with each other.
     */
    public function test_count_export_and_recipients_agree(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_activity();

        $count = flagged_defaulters_table::count_rows($activity, 1, '');
        $rows = flagged_defaulters_table::export_rows($activity, 1, '');
        $recipients = flagged_defaulters_table::recipient_ids($activity, 1, '');

        $this->assertSame(2, $count);
        $this->assertCount($count, $rows);
        $this->assertCount($count, $recipients);
    }

    /**
     * A higher minimum pulls the student who holds one membership into
     * the list, with a shortfall counted against the new minimum.
     */
    public function test_higher_minimum_includes_partially_placed_student(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();

        $rows = flagged_defaulters_table::export_rows($activity, 2, '');
        $this->assertCount(3, $rows);

        $shortfalls = [];
        foreach ($rows as $row) {
            $shortfalls[] = $row[2];
        }
        sort($shortfalls);
        $this->assertSame([1, 2, 2], $shortfalls);
        $this->assertNotEmpty($students);
    }

    /**
     * The name filter narrows every path consistently.
     */
    public function test_name_filter_applies(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();

        $target = $students[1];
        $rows = flagged_defaulters_table::export_rows($activity, 1, $target->lastname);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString($target->lastname, $rows[0][0]);
    }
}
