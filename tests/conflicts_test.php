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

use mod_selfselectadvanced\local\quota\conflicts;
use mod_selfselectadvanced\local\quota\store as quotastore;

/**
 * The composition-clash detector (1.18.2).
 *
 * A distinct rule counts VALUES; a value rule counts MEMBERS. Adding
 * the two as if they were the same number declared the plugin's own
 * headline configuration impossible - "exactly two from one school,
 * and four schools represented" in a team of five - and told the
 * teacher to resolve a wall that was not there.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\conflicts
 */
final class conflicts_test extends \advanced_testcase {
    /**
     * An activity of exactly five.
     *
     * @param array $settings instance overrides
     * @return activity the activity
     */
    private function five(array $settings = []): activity {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'CF1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 5,
            'maxsize' => 5,
        ], $settings));

        return activity::from_instance((int) $instance->id);
    }

    /**
     * The configuration this plugin exists for: a team of five with two
     * from one school and three from three OTHER schools, expressed as
     * a value rule pinning the first school at two and a distinct rule
     * requiring four schools across the team.
     *
     * Five members satisfy it - the two pinned members supply one of
     * the four schools between them, and three more supply the rest -
     * so nothing here is a clash.
     */
    public function test_two_from_one_school_and_four_schools_is_feasible(): void {
        $this->resetAfterTest();
        $activity = $this->five();

        quotastore::save($activity, (object) [
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
            'maxcount' => 2,
        ], (int) get_admin()->id);
        quotastore::save($activity, (object) [
            'dimension' => 'department',
            'rtype' => 'distinct',
            'value' => null,
            'mincount' => 4,
            'maxcount' => null,
        ], (int) get_admin()->id);

        $this->assertSame([], conflicts::detect($activity));
    }

    /**
     * The arithmetic still bites when it genuinely should: two schools
     * pinned at two each supply only two of four required schools, so
     * two more members are needed and six will not fit in five.
     */
    public function test_genuinely_impossible_rules_are_still_caught(): void {
        $this->resetAfterTest();
        $activity = $this->five();

        foreach (['SCOPE', 'SENSE'] as $school) {
            quotastore::save($activity, (object) [
                'dimension' => 'department',
                'rtype' => 'value',
                'value' => $school,
                'mincount' => 2,
                'maxcount' => null,
            ], (int) get_admin()->id);
        }
        quotastore::save($activity, (object) [
            'dimension' => 'department',
            'rtype' => 'distinct',
            'value' => null,
            'mincount' => 4,
            'maxcount' => null,
        ], (int) get_admin()->id);

        $clashes = conflicts::detect($activity);
        $this->assertNotEmpty($clashes);
        $this->assertStringContainsString('6', implode(' ', $clashes));
    }

    /**
     * A distinct rule alone is measured against the group size in its
     * own terms: five distinct schools cannot live in a team of five
     * if two of the seats are already pinned to one school, but four
     * can.
     */
    public function test_a_distinct_rule_alone_needs_one_member_per_value(): void {
        $this->resetAfterTest();
        $activity = $this->five();

        quotastore::save($activity, (object) [
            'dimension' => 'department',
            'rtype' => 'distinct',
            'value' => null,
            'mincount' => 5,
            'maxcount' => null,
        ], (int) get_admin()->id);
        $this->assertSame([], conflicts::detect($activity));

        quotastore::save($activity, (object) [
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
            'maxcount' => null,
        ], (int) get_admin()->id);
        $this->assertNotEmpty(conflicts::detect($activity));
    }

    /**
     * More distinct values than a team can hold members is impossible
     * whatever else is set, and says so in its own words rather than
     * borrowing the message meant for member counts.
     */
    public function test_more_values_than_members_is_reported_as_such(): void {
        $this->resetAfterTest();
        $activity = $this->five();

        quotastore::save($activity, (object) [
            'dimension' => 'department',
            'rtype' => 'distinct',
            'value' => null,
            'mincount' => 6,
            'maxcount' => null,
        ], (int) get_admin()->id);

        $clashes = conflicts::detect($activity);
        $this->assertNotEmpty($clashes);
        $this->assertStringContainsString('distinct values', implode(' ', $clashes));
    }
}
