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
 * Every activity setting has a declared backup policy.
 *
 * WHY THIS EXISTS. Twice now a column was added to the activity table,
 * wired through the form, the validator, the runtime and its own tests -
 * and never added to the backup structure. `joinexpiry` went first;
 * `mirrorat` followed it in 1.20.36, added by the same hand that wrote
 * this file. Neither omission failed anything, because nothing compared
 * the two lists. A restored activity simply came back with request expiry
 * switched off, or with Moodle course groups appearing at a different
 * point in the lifecycle than the source site chose.
 *
 * The external audit of 1.20.37 (BAK-001) found both, and its §30
 * recommended exactly this test: compare the live schema against the
 * backup contract and fail when a column belongs to neither list.
 *
 * HOW TO SATISFY IT when you add a column. Either add it to the backup
 * element in `backup_selfselectadvanced_stepslib.php` - the usual answer
 * for anything a teacher can set - or name it below in EXCLUDED with a
 * reason. There is no third option, and that is the point: the decision
 * has to be made and written down rather than forgotten.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_selfselectadvanced_activity_structure_step
 */
final class schema_backup_contract_test extends \advanced_testcase {
    /**
     * Columns deliberately NOT backed up, each with the reason it is not.
     *
     * @return array<string, string> column => reason
     */
    private function excluded(): array {
        return [
            'id' => 'the row identity; the restore mints a new one',
            'course' => 'the destination course, supplied by the restore itself',
            'timecreated' => 'set by the restore when the activity is created',
            'timemodified' => 'set by the restore when the activity is created',
        ];
    }

    /**
     * The backup transports every activity setting, or says why not.
     */
    public function test_every_activity_column_has_a_backup_policy(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $columns = array_keys($DB->get_columns('selfselectadvanced'));
        $this->assertNotEmpty($columns, 'the schema read returned nothing, so this test would pass vacuously');

        // The declared field list, read out of the backup step's source
        // rather than by running a backup: this must fail on a developer's
        // machine the moment the column is added, not only once somebody
        // performs a restore and notices the setting has changed.
        $source = file_get_contents(
            $CFG->dirroot . '/mod/selfselectadvanced/backup/moodle2/backup_selfselectadvanced_stepslib.php'
        );
        $start = strpos($source, "new backup_nested_element('selfselectadvanced', ['id'], [");
        $this->assertNotFalse($start, 'the activity backup element could not be found');
        $end = strpos($source, ']);', $start);
        $declared = [];
        preg_match_all("/'([a-z0-9_]+)'/", substr($source, $start, $end - $start), $found);
        foreach ($found[1] ?? [] as $name) {
            $declared[$name] = true;
        }
        $this->assertGreaterThan(20, count($declared), 'the field list parsed to almost nothing');

        $excluded = $this->excluded();
        $missing = [];
        foreach ($columns as $column) {
            if (isset($declared[$column]) || array_key_exists($column, $excluded)) {
                continue;
            }
            $missing[] = $column;
        }

        $this->assertSame(
            [],
            $missing,
            "These activity columns are neither backed up nor listed as deliberately excluded: "
                . implode(', ', $missing) . ". Add them to the backup element, or add them to this "
                . "test's excluded() list with the reason they should not travel."
        );
    }

    /**
     * The exclusion list does not outlive its columns.
     *
     * A name left behind after a column is dropped silently licenses a
     * FUTURE column of the same name to skip the backup, which is how a
     * safety list becomes a hole.
     */
    public function test_the_exclusion_list_names_only_real_columns(): void {
        global $DB;

        $this->resetAfterTest();
        $columns = array_keys($DB->get_columns('selfselectadvanced'));
        foreach (array_keys($this->excluded()) as $name) {
            $this->assertContains($name, $columns, "excluded() names '$name', which is not a column any more");
        }
    }

    /**
     * The two columns the audit found, pinned by name.
     *
     * The general test above would catch them, but naming them makes the
     * regression legible: if either disappears from the backup again, the
     * failure says which one and why it matters.
     */
    public function test_joinexpiry_and_mirrorat_are_backed_up(): void {
        global $CFG;

        $source = file_get_contents(
            $CFG->dirroot . '/mod/selfselectadvanced/backup/moodle2/backup_selfselectadvanced_stepslib.php'
        );
        $this->assertStringContainsString(
            "'joinexpiry'",
            $source,
            'joinexpiry is not backed up: a restored activity turns join-request expiry off'
        );
        $this->assertStringContainsString(
            "'mirrorat'",
            $source,
            'mirrorat is not backed up: a restored activity moves Moodle group creation to a '
                . 'different lifecycle point than the source site chose'
        );
    }

    /**
     * Child tables that are COURSE CONTENT, not the erased/exported
     * person's own data, keyed to whether their source is set
     * unconditionally in the backup step (audit B8/M-12).
     *
     * WHY THIS EXISTS. selfselectadvanced_kb (1.20.45) was reusable
     * content the whole way through - the privacy provider keeps it
     * through a full context purge for exactly that reason - but its
     * backup source table was set INSIDE `if ($userinfo)` regardless, so
     * a Duplicate or a "include enrolled users" unticked restore silently
     * lost every FAQ, alongside quotas/templates/qslots, which were
     * always correctly unconditional. Nothing compared the two lists,
     * the same shape of gap test_every_activity_column_has_a_backup_
     * policy() above exists to close for activity settings.
     *
     * HOW TO SATISFY IT when you add a course-content child table (staff-
     * authored configuration or reference data, not a participant's own
     * record). Source it OUTSIDE `if ($userinfo)`, alongside quota/
     * template/qslot/kbentry, and add its element variable name here.
     *
     * @return string[] the backup step's local variable names (without
     *         the leading $) whose set_source_table()/set_source_sql()
     *         call must appear before `if ($userinfo) {`
     */
    private function unconditional_child_elements(): array {
        return ['quota', 'tpl', 'qslot', 'kbentry'];
    }

    /**
     * Every course-content child element's source is set BEFORE
     * `if ($userinfo) {`, not inside it.
     *
     * MUTATION CAUGHT: moving $kbentry->set_source_table(...) back inside
     * `if ($userinfo) {` (M-12's actual defect) makes its position land
     * after $ifpos, failing the assertion for 'kbentry'.
     */
    public function test_course_content_child_tables_are_sourced_unconditionally(): void {
        global $CFG;

        $source = file_get_contents(
            $CFG->dirroot . '/mod/selfselectadvanced/backup/moodle2/backup_selfselectadvanced_stepslib.php'
        );
        $this->assertNotFalse($source, 'could not read the backup stepslib source');

        $ifpos = strpos($source, 'if ($userinfo) {');
        $this->assertNotFalse($ifpos, "the backup stepslib has no 'if (\$userinfo) {' block any more - update this test");

        foreach ($this->unconditional_child_elements() as $element) {
            $needle = '$' . $element . '->set_source_';
            $pos = strpos($source, $needle);
            $this->assertNotFalse($pos, "no set_source_table()/set_source_sql() call found for \$$element");
            $this->assertLessThan(
                $ifpos,
                $pos,
                "\$$element's source is set INSIDE if (\$userinfo) - course content must be backed up even "
                    . "when a restore excludes user data (audit B8/M-12), or a Duplicate/rollover silently "
                    . "loses it"
            );
        }
    }

    /**
     * The restore side of the same contract: ssakbentry's path element
     * must be registered unconditionally too, or a no-userinfo archive
     * that DOES carry kb rows (because the backup fix above sourced them)
     * has nothing to route those rows to on restore.
     *
     * MUTATION CAUGHT: moving the 'ssakbentry' restore_path_element back
     * inside `if ($userinfo)` in restore_selfselectadvanced_stepslib.php
     * makes its position land after $ifpos, failing the assertion.
     */
    public function test_ssakbentry_restore_path_is_registered_unconditionally(): void {
        global $CFG;

        $source = file_get_contents(
            $CFG->dirroot . '/mod/selfselectadvanced/backup/moodle2/restore_selfselectadvanced_stepslib.php'
        );
        $this->assertNotFalse($source, 'could not read the restore stepslib source');

        $ifpos = strpos($source, 'if ($userinfo) {');
        $this->assertNotFalse($ifpos, "the restore stepslib has no 'if (\$userinfo) {' block any more - update this test");

        $kbpos = strpos($source, "'ssakbentry'");
        $this->assertNotFalse($kbpos, "no 'ssakbentry' restore_path_element registration found");
        $this->assertLessThan(
            $ifpos,
            $kbpos,
            "'ssakbentry' is registered INSIDE if (\$userinfo) - a no-userinfo archive that carries kb rows "
                . "(the backup half of audit B8/M-12) has nothing to route them to on restore"
        );
    }
}
