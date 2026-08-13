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
}
