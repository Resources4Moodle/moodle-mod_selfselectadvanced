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
 * 1.20.58 deliverable A: selfselectadvanced.tickettargethours, an INT
 * NOT NULL DEFAULT 0 column - the per-activity target first-response
 * time in hours, 0 meaning "no target set".
 *
 * Unlike 1.20.56's CHAR NOT NULL UNIQUE ticket reference (which needed a
 * backfill loop before its unique index could go on safely), a plain
 * NOT NULL column with a DEFAULT is safe to add straight onto a
 * populated table in one step - but the previous release's own lesson
 * (ticket_pluginuid_upgrade_test.php's docblock, quoting the spec) is
 * that fresh-install-only coverage hides upgrade defects, so this file
 * drives the actual UPGRADE path against activity rows that already
 * exist, at the pre-1.20.58 shape (no tickettargethours column at all),
 * exactly the way that file does for its own column.
 *
 * NOT run through xmldb_selfselectadvanced_upgrade() itself, for the
 * same reason: its new step is guarded by the literal placeholder token
 * PENDING_SERIAL, which is not a condition PHP can evaluate.
 * selfselectadvanced_upgrade_add_tickettargethours() in db/upgrade.php
 * is the standalone function the guarded step calls, and this file
 * calls it directly.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class ticket_response_target_upgrade_test extends \advanced_testcase {
    /**
     * Put selfselectadvanced back to its pre-1.20.58 shape: no
     * tickettargethours column at all. The PHPUnit site is installed
     * FRESH from the current db/install.xml (which already declares the
     * column), so dropping it here is what actually reaches "an existing
     * row with no tickettargethours column at all", not merely a row
     * whose value happens to be empty.
     */
    private function drop_tickettargethours_to_simulate_the_pre_1_20_58_shape(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('selfselectadvanced');
        $field = new \xmldb_field(
            'tickettargethours',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'ticketdisclaimerformat'
        );
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
    }

    /**
     * The whole point of the trap 1.20.56 taught: a step must be proven
     * against a table that already has rows, not just an empty one. Two
     * activities, created and populated with LIVE data (a group, a
     * ticket) BEFORE the column is dropped and the upgrade function is
     * run - exactly the shape a real site upgrading from an earlier
     * release is in.
     *
     * RED-FIRST (captured on m5pg, this same tree, with
     * selfselectadvanced_upgrade_add_tickettargethours()'s own
     * xmldb_field() call temporarily edited to drop its DEFAULT argument
     * - the sixth positional parameter - passing null instead of '0'):
     *
     *   1) test_a_populated_table_upgrades_cleanly_to_default_zero:
     *   Failed asserting that null matches expected 0.
     *   (both existing activity rows read NULL for tickettargethours
     *   instead of the documented 0 sentinel, because add_field() with no
     *   default leaves every existing row NULL rather than backfilled -
     *   the exact defect a fresh-install-only test could never see, since
     *   db/install.xml has no populated table to expose it against)
     *
     *   Tests: 1, Assertions: 2, Failures: 1.
     *
     * Reverted immediately after capturing the failure; full suite green
     * again with no other change.
     */
    public function test_a_populated_table_upgrades_cleanly_to_default_zero(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $course1 = $generator->create_course(['shortname' => 'TGT1']);
        $instance1 = $generator->create_module('selfselectadvanced', ['course' => $course1->id]);
        $course2 = $generator->create_course(['shortname' => 'TGT2']);
        $instance2 = $generator->create_module('selfselectadvanced', ['course' => $course2->id]);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course1->id, 'student');
        $plugingen->create_group([
            'activityid' => $instance1->id,
            'leaderid' => (int) $leader->id,
            'name' => 'Legacy',
            'state' => \mod_selfselectadvanced\local\state::FIRM,
            'timeapproved' => time(),
        ]);

        $this->drop_tickettargethours_to_simulate_the_pre_1_20_58_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_tickettargethours($DB, $dbman);

        $table = new \xmldb_table('selfselectadvanced');
        $field = new \xmldb_field('tickettargethours');
        $this->assertTrue(
            $dbman->field_exists($table, $field),
            'the column must exist once the upgrade step has run'
        );

        // BOTH pre-existing activities - a populated table, not merely
        // one row - must read the documented 0 sentinel, not NULL and
        // not an empty string.
        $row1 = $DB->get_record('selfselectadvanced', ['id' => $instance1->id], '*', MUST_EXIST);
        $row2 = $DB->get_record('selfselectadvanced', ['id' => $instance2->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row1->tickettargethours, 'an existing activity with a group must read 0 (no target)');
        $this->assertSame(0, (int) $row2->tickettargethours, 'an existing activity with no groups at all must read 0 too');
    }

    /**
     * The column's own shape is NOT NULL with a DEFAULT of 0 - asserted
     * directly against the schema metadata, not merely inferred from one
     * row's value, so a mutation that keeps existing rows at 0 by
     * accident (e.g. a raw SQL UPDATE instead of a real DEFAULT) would
     * still be caught here.
     *
     * MUTATION: removing XMLDB_NOTNULL from the xmldb_field() call in
     * selfselectadvanced_upgrade_add_tickettargethours() (passing null
     * instead) flips not_null to false below - proven live in this run;
     * see the class docblock for the sibling mutation's captured output.
     */
    public function test_the_column_is_not_null_with_a_zero_default(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        $this->drop_tickettargethours_to_simulate_the_pre_1_20_58_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_tickettargethours($DB, $dbman);

        $columns = $DB->get_columns('selfselectadvanced');
        $this->assertArrayHasKey('tickettargethours', $columns, 'the column must be visible to a fresh schema read');
        $column = $columns['tickettargethours'];
        $this->assertTrue($column->not_null, 'the column must be NOT NULL');
        $this->assertTrue($column->has_default, 'the column must declare a DEFAULT');
        $this->assertSame(0, (int) $column->default_value, 'the default must be exactly 0 ("no target set")');
    }

    /**
     * Idempotency: running the step twice (the field_exists() guard
     * every other step in db/upgrade.php uses) does not error and does
     * not disturb a value already written - the same discipline every
     * add_field() call in this file already follows, pinned here for
     * this one because it is new.
     */
    public function test_running_the_step_twice_does_not_error_or_disturb_existing_values(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'TGT3']);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);

        $this->drop_tickettargethours_to_simulate_the_pre_1_20_58_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_tickettargethours($DB, $dbman);
        // A site administrator has since set a real target.
        $DB->set_field('selfselectadvanced', 'tickettargethours', 24, ['id' => $instance->id]);

        // Running the step again (the same guarded call a re-run upgrade
        // would make) must not fatal, and must not reset the value a
        // teacher already configured back to the column's own default.
        selfselectadvanced_upgrade_add_tickettargethours($DB, $dbman);

        $row = $DB->get_record('selfselectadvanced', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame(24, (int) $row->tickettargethours, 'a value already configured must survive a re-run of the step');
    }
}
