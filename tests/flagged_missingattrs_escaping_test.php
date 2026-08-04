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

use mod_selfselectadvanced\table\flagged_missingattrs_table;

/**
 * The missing-attributes table renders a user's name into HTML that the
 * flagged report prints through a RAW {{{missingattrstable}}}, so the
 * name must be escaped by the table itself.
 *
 * Two tables on that report took the raw path while the groupless list
 * beside them used {{fullname}}: this one, and flagged_anomalies_table,
 * whose cell carries deliberate markup and so escapes its names where
 * they are gathered. A name carrying markup - which CSV upload, LDAP
 * sync and the user web service can set, unlike the tag-stripping
 * profile form - would otherwise execute for the staff viewer (own
 * review 2026-08-04; the second table found by the 1.20.5 blind audit).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\flagged_missingattrs_table
 * @covers     \mod_selfselectadvanced\table\flagged_anomalies_table
 */
final class flagged_missingattrs_escaping_test extends \advanced_testcase {
    /**
     * A name carrying a script tag comes back HTML-escaped, never raw,
     * from the table's rendered output.
     *
     * MUTATION CAUGHT (run against the pre-fix tree): with the
     * add_data_keyed value left as $row->fullname rather than
     * s($row->fullname), the raw '<script>' reaches the output and the
     * first assertion fails.
     */
    public function test_a_markup_bearing_name_is_escaped_in_the_rendered_table(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $payload = '<script>alert(1)</script>';
        $rows = [(object) ['fullname' => $payload]];

        $table = new flagged_missingattrs_table(
            'ssa_missingattrs_test',
            new \moodle_url('/mod/selfselectadvanced/flagged.php', ['id' => 1, 'tab' => 'students'])
        );

        ob_start();
        $table->display_rows($rows, 50);
        $html = ob_get_clean();

        $this->assertStringNotContainsString(
            $payload,
            $html,
            'the raw script tag must not reach the flagged report, which prints this table unescaped'
        );
        $this->assertStringContainsString(
            s($payload),
            $html,
            'the escaped form of the name must be present - proving the name was rendered, not merely dropped'
        );
    }

    /**
     * The anomalies table's member list is built into a cell that
     * deliberately carries markup, so the NAME is escaped where it is
     * gathered. A markup-bearing name must therefore arrive escaped,
     * while the cell's own span survives.
     *
     * MUTATION CAUGHT (run): dropping the s() at
     * flagged_anomalies_table.php's membernamesbygroup line puts the raw
     * tag into the joined string and the first assertion fails.
     */
    public function test_the_anomalies_member_list_escapes_a_markup_bearing_name(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 1,
        ]);
        $activity = \mod_selfselectadvanced\activity::from_instance((int) $instance->id);
        $resolver = new \mod_selfselectadvanced\local\override\resolver($activity);

        $payload = '<script>alert(1)</script>';
        // Written STRAIGHT to the row, because that is how the real
        // vectors do it. The generator (like the profile form) cleans
        // lastname with PARAM_NOTAGS and would store a harmless
        // 'alert(1)', testing nothing: CSV upload, LDAP sync and the
        // user web service are the paths that CAN store markup, and
        // they land in this column exactly like this.
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $DB->set_field('user', 'lastname', $payload, ['id' => $leader->id]);

        // A maxsize of 1 with a confirmed leader makes the team full and
        // guideless, which is what puts it in the anomalies report at
        // all - and the report is what renders the member names.
        $generator->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => \mod_selfselectadvanced\local\state::FORMING,
        ]);

        // The PRODUCTION path, not a re-implementation of it: this is
        // the method the flagged report calls, and its rows carry the
        // markup-bearing cell.
        $rows = \mod_selfselectadvanced\table\flagged_anomalies_table::build_rows($activity, $resolver);
        $this->assertNotEmpty($rows, 'the fixture must actually reach the anomalies report');
        $issues = implode(' ', array_map(static fn($r): string => (string) $r->issues, $rows));

        $this->assertStringNotContainsString(
            $payload,
            $issues,
            'a raw script tag must not reach the anomalies cell, which is emitted verbatim'
        );
        $this->assertStringContainsString(
            s($payload),
            $issues,
            'the escaped name must be present - proving it was rendered, not merely dropped'
        );
    }
}
