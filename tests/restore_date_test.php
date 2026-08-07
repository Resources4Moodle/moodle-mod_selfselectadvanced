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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

/**
 * The schedule shifts with a restore (seam audit, 1.20.19).
 *
 * A term-rollover restore used to carry last term's
 * timeopen/timedue/timecutoff verbatim, so the whole new cohort landed
 * after the cutoff and the window gate refused everything anybody
 * tried. Core's own restore_date_testcase drives a real backup and
 * restore with a date shift and asserts the fields rolled forward -
 * the same harness every core module uses.
 *
 * MUTATION CAUGHT (run): removing the apply_date_offset() calls from
 * process_selfselectadvanced() fails assertFieldsRolledForward.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \restore_selfselectadvanced_activity_structure_step
 */
final class restore_date_test extends \restore_date_testcase {
    /**
     * The three window dates roll forward; zero stays "not set".
     */
    public function test_restore_dates(): void {
        global $DB;

        $time = 100000;
        [$course, $instance] = $this->create_course_and_module('selfselectadvanced', [
            'timeopen' => $time,
            'timedue' => $time + DAYSECS,
            'timecutoff' => 0,
        ]);

        $newcourseid = $this->backup_and_restore($course);
        $new = $DB->get_record('selfselectadvanced', ['course' => $newcourseid]);

        $this->assertFieldsRolledForward($instance, $new, ['timeopen', 'timedue']);
        $this->assertSame(0, (int) $new->timecutoff, 'zero means "not set" and never shifts');
    }
}
