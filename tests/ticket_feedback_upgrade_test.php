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

use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * 1.20.59: selfselectadvanced_ticket.verdict/verdictnote/timeverdict -
 * verdict an INT NOT NULL DEFAULT 0 column (0 unanswered, 1 helped, 2
 * did not help), verdictnote and timeverdict nullable.
 *
 * Same discipline as ticket_response_target_upgrade_test.php's own
 * docblock states for its sibling column: fresh-install-only coverage
 * hides upgrade defects, so this file drives the actual UPGRADE
 * function against ticket rows that ALREADY EXIST, at the pre-1.20.59
 * shape (no verdict/verdictnote/timeverdict columns at all) - the spec's
 * own instruction ("still write an upgrade test that runs the step
 * against a table that already has rows").
 *
 * NOT run through xmldb_selfselectadvanced_upgrade() itself: its new
 * step is guarded by the literal placeholder token PENDING_SERIAL,
 * which is not a condition PHP can evaluate.
 * selfselectadvanced_upgrade_add_ticket_feedback() in db/upgrade.php is
 * the standalone function the guarded step calls, and this file calls
 * it directly.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class ticket_feedback_upgrade_test extends \advanced_testcase {
    /**
     * Put selfselectadvanced_ticket back to its pre-1.20.59 shape: no
     * verdict, verdictnote or timeverdict columns at all. The PHPUnit
     * site is installed FRESH from the current db/install.xml (which
     * already declares all three), so dropping them here is what
     * actually reaches "an existing ticket row with none of these
     * columns", not merely a row whose values happen to be empty.
     */
    private function drop_feedback_columns_to_simulate_the_pre_1_20_59_shape(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('selfselectadvanced_ticket');
        // Drop order is the REVERSE of the add order (timeverdict,
        // verdictnote, then verdict) - each field's own 'previous field'
        // pointer names the field before it, and a drop leaves no such
        // ordering constraint to worry about, but dropping the LAST-added
        // one first mirrors add_field()'s own dependency direction and
        // keeps this method symmetric with selfselectadvanced_upgrade_
        // add_ticket_feedback() itself.
        foreach (
            [
                new \xmldb_field('timeverdict'),
                new \xmldb_field('verdictnote'),
                new \xmldb_field('verdict'),
            ] as $field
        ) {
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }
    }

    /**
     * A course, an activity, a leader with a firm group, and one real
     * ticket row - so the drop-and-upgrade below runs against a
     * POPULATED table, exactly the trap 1.20.56's own docblock names.
     *
     * @return array [activity, ticketid]
     */
    private function scene_with_a_ticket(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'FBK1']);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Legacy Team',
            'state' => state::FIRM,
        ]);

        // Filed via file_help(), not file()/TYPE_COMPCHANGE: a
        // composition-change ticket may only be filed by the group's
        // ASSIGNED GUIDE, and this scene sets up a leader only - the
        // general help channel a leader may always raise, and the
        // schema shape under test here (a populated ticket row) does
        // not care which type it is.
        $ticket = tickets::file_help(
            $activity,
            $group,
            'Please swap a member.',
            FORMAT_PLAIN,
            (int) $leader->id
        );

        return [$activity, (int) $ticket->id];
    }

    /**
     * RED-FIRST EVIDENCE (captured for real on m5pg, this same tree,
     * with selfselectadvanced_upgrade_add_ticket_feedback()'s own
     * verdict xmldb_field() call temporarily edited to drop its DEFAULT
     * argument - the seventh positional parameter - passing null
     * instead of '0'):
     *
     *   EF.E  4 / 4 (100%)
     *   1) test_a_populated_table_upgrades_cleanly_to_unanswered_defaults:
     *   ddl_exception: Unknown DDL library error (Field
     *   selfselectadvanced_ticket->verdict cannot be added. Not null
     *   fields added to non empty tables require default value. Create
     *   skipped)
     *   2) test_running_the_step_twice_does_not_error_or_disturb_existing_values:
     *   the identical ddl_exception.
     *   3) test_the_verdict_column_is_not_null_with_a_zero_default:
     *   the verdict column must declare a DEFAULT
     *   Failed asserting that false is true.
     *
     *   Tests: 4, Assertions: 7, Errors: 2, Failures: 1.
     *
     * PostgreSQL's own DDL layer refuses a NOT NULL column with no
     * default outright on the two populated-table tests - a stronger
     * signal than a silently-NULL backfill would have been - and the
     * schema-shape test below caught the missing DEFAULT directly.
     * Reverted immediately after capturing the failure; full suite green
     * again (Tests: 4, Assertions: 14) with no other change.
     */
    public function test_a_populated_table_upgrades_cleanly_to_unanswered_defaults(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [, $ticketid] = $this->scene_with_a_ticket();

        $this->drop_feedback_columns_to_simulate_the_pre_1_20_59_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_feedback($DB, $dbman);

        $row = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->verdict, 'an existing ticket must read 0 (unanswered), not NULL');
        $this->assertNull($row->verdictnote, 'an existing ticket must have no verdict note');
        $this->assertNull($row->timeverdict, 'an existing ticket must have no verdict timestamp');
    }

    /**
     * The verdict column's own shape is NOT NULL with a DEFAULT of 0 -
     * asserted against the schema metadata directly, not merely
     * inferred from one row's value, so a mutation that keeps existing
     * rows at 0 by accident (e.g. a raw SQL UPDATE instead of a real
     * DEFAULT) would still be caught here.
     *
     * MUTATION: removing XMLDB_NOTNULL from the verdict xmldb_field()
     * call in selfselectadvanced_upgrade_add_ticket_feedback() (passing
     * null instead) flips not_null to false below - proven live in this
     * run; see the class docblock for the sibling mutation's captured
     * output.
     */
    public function test_the_verdict_column_is_not_null_with_a_zero_default(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        $this->drop_feedback_columns_to_simulate_the_pre_1_20_59_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_feedback($DB, $dbman);

        $columns = $DB->get_columns('selfselectadvanced_ticket');
        $this->assertArrayHasKey('verdict', $columns, 'the verdict column must be visible to a fresh schema read');
        $column = $columns['verdict'];
        $this->assertTrue($column->not_null, 'the verdict column must be NOT NULL');
        $this->assertTrue($column->has_default, 'the verdict column must declare a DEFAULT');
        $this->assertSame(0, (int) $column->default_value, 'the default must be exactly 0 ("unanswered")');
    }

    /**
     * verdictnote and timeverdict are both plain nullable columns, with
     * no NOT NULL constraint and no default to violate on a populated
     * table.
     */
    public function test_verdictnote_and_timeverdict_are_nullable(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        $this->drop_feedback_columns_to_simulate_the_pre_1_20_59_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_feedback($DB, $dbman);

        $columns = $DB->get_columns('selfselectadvanced_ticket');
        $this->assertArrayHasKey('verdictnote', $columns);
        $this->assertArrayHasKey('timeverdict', $columns);
        $this->assertFalse($columns['verdictnote']->not_null, 'verdictnote must be nullable');
        $this->assertFalse($columns['timeverdict']->not_null, 'timeverdict must be nullable');
    }

    /**
     * Idempotency: running the step twice (the field_exists() guard
     * every other step in db/upgrade.php uses) does not error and does
     * not disturb a value already written - the same discipline
     * ticket_response_target_upgrade_test.php pins for its own column.
     */
    public function test_running_the_step_twice_does_not_error_or_disturb_existing_values(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [, $ticketid] = $this->scene_with_a_ticket();

        $this->drop_feedback_columns_to_simulate_the_pre_1_20_59_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_feedback($DB, $dbman);
        // A requester has since answered "did this help?" for real.
        $DB->set_field('selfselectadvanced_ticket', 'verdict', tickets::VERDICT_HELPED, ['id' => $ticketid]);
        $DB->set_field('selfselectadvanced_ticket', 'verdictnote', 'Fixed it, thanks.', ['id' => $ticketid]);
        $DB->set_field('selfselectadvanced_ticket', 'timeverdict', 12345, ['id' => $ticketid]);

        // Running the step again (the same guarded call a re-run upgrade
        // would make) must not fatal, and must not reset the answer a
        // requester already gave back to the columns' own defaults.
        selfselectadvanced_upgrade_add_ticket_feedback($DB, $dbman);

        $row = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertSame(tickets::VERDICT_HELPED, (int) $row->verdict, 'an answer already given must survive a re-run');
        $this->assertSame('Fixed it, thanks.', $row->verdictnote);
        $this->assertSame(12345, (int) $row->timeverdict);
    }
}
