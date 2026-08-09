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
 * A ticket type is never vetoed by the width of the column that stores it.
 *
 * The column was char(12) until 1.20.31. Every shipped type fits only because
 * the longest of them happen to be short, and the 2026-08-09 review of decision
 * 71 found that its proposed type - `leaderchange` - is EXACTLY twelve
 * characters. It would have fitted with no headroom at all, so the type after
 * it, or any rename, would have hit a schema change discovered at the worst
 * possible moment: mid-feature, on a live table.
 *
 * This asserts the width where it actually matters - a real INSERT and read-back
 * on the running engine - rather than by reading db/install.xml, which says only
 * what a FRESH install would get and nothing about a site that upgraded.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class ticket_type_width_test extends \advanced_testcase {
    /**
     * The stored type survives a length no plausible slug will reach.
     *
     * MUTATION CAUGHT (run 2026-08-09): reverting db/install.xml to char(12)
     * and re-initialising fails this test - PostgreSQL raises a length error and
     * MariaDB truncates, so the read-back no longer matches what was written.
     */
    public function test_a_long_ticket_type_round_trips(): void {
        global $DB;
        $this->resetAfterTest();

        // 120 characters: comfortably inside 128, far beyond any real slug, and
        // long enough that a column left at 12 - or widened to some middling
        // value - cannot hold it.
        $longtype = str_repeat('leaderchange', 10);
        $this->assertSame(120, \core_text::strlen($longtype), 'the fixture must actually be long');

        $id = $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => 1,
            'groupid' => 1,
            'type' => $longtype,
            'status' => 'open',
            'requestedby' => 2,
            'request' => 'Width probe.',
            'requestformat' => FORMAT_HTML,
            'resolutionformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->assertSame(
            $longtype,
            $DB->get_field('selfselectadvanced_ticket', 'type', ['id' => $id]),
            'the ticket type column truncated or rejected a 120-character value, so the taxonomy '
                . 'is still limited by the length of a type name'
        );
    }

    /**
     * Every shipped type still fits, which is the control for the test above.
     */
    public function test_every_shipped_type_fits(): void {
        $this->resetAfterTest();

        $reflection = new \ReflectionClass(\mod_selfselectadvanced\local\tickets::class);
        $types = [];
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'TYPE_')) {
                $types[$name] = $value;
            }
        }
        $this->assertNotEmpty($types, 'no TYPE_ constants found - this test would pass vacuously');

        foreach ($types as $name => $value) {
            $this->assertLessThanOrEqual(128, \core_text::strlen((string) $value), $name . ' no longer fits');
        }
    }
}
