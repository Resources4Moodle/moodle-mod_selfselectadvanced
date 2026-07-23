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

use core_external\external_api;
use mod_selfselectadvanced\local\api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * The candidate-search external function: the AJAX layer behind the
 * native selector (C10, U3), including its capability and IDOR guards.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\external\search_candidates
 */
final class external_search_test extends \externallib_advanced_testcase {
    /**
     * The leader can search by last name and email through the full
     * external wrapper; results carry eligibility.
     */
    public function test_execute_as_leader(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxsize' => 3,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $leader = $generator->create_user(['firstname' => 'Lea', 'lastname' => 'Der']);
        $peer = $generator->create_user([
            'firstname' => 'Uma',
            'lastname' => 'Three',
            'email' => 'uma3@example.com',
        ]);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($peer->id, $course->id, 'student');

        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);
        $this->setUser($leader);
        $group = $api->create_group((int) $leader->id, 'Searchers', 'T', '<p>b</p>', FORMAT_HTML);

        $result = \mod_selfselectadvanced\external\search_candidates::execute(
            $activity->cm()->id,
            (int) $group->id,
            'uma3@example.com'
        );
        $result = external_api::clean_returnvalue(
            \mod_selfselectadvanced\external\search_candidates::execute_returns(),
            $result
        );

        $this->assertCount(1, $result);
        $this->assertSame((int) $peer->id, $result[0]['id']);
        $this->assertTrue($result[0]['eligible']);

        // By last name too.
        $result = \mod_selfselectadvanced\external\search_candidates::execute(
            $activity->cm()->id,
            (int) $group->id,
            'Three'
        );
        $this->assertCount(1, $result);
    }

    /**
     * A non-leader without manage is refused; a foreign group id is
     * refused (IDOR).
     */
    public function test_execute_guards(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $other = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $leader = $generator->create_user();
        $stranger = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($stranger->id, $course->id, 'student');

        $activity = activity::from_instance((int) $instance->id);
        $this->setUser($leader);
        $group = (new api($activity))->create_group((int) $leader->id, 'Mine', 'T', '<p>b</p>', FORMAT_HTML);

        // A stranger may not search candidates for someone else's group.
        $this->setUser($stranger);
        try {
            \mod_selfselectadvanced\external\search_candidates::execute($activity->cm()->id, (int) $group->id, 'x');
            $this->fail('Expected a capability exception');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('permissions', $e->getMessage());
        }

        // The group id must belong to the given activity (IDOR).
        $this->setUser($leader);
        $otheractivity = activity::from_instance((int) $other->id);
        $this->expectException(\dml_missing_record_exception::class);
        \mod_selfselectadvanced\external\search_candidates::execute(
            $otheractivity->cm()->id,
            (int) $group->id,
            'x'
        );
    }
}
