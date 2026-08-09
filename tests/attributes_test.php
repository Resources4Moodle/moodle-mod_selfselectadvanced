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

use mod_selfselectadvanced\local\attributes\csv_importer;
use mod_selfselectadvanced\local\attributes\manager;

/**
 * Participant attributes: the plugin-local store, the distinct-value
 * cache, the M3 observer and the U4/A9 CSV importer.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\attributes\manager
 * @covers     \mod_selfselectadvanced\local\attributes\csv_importer
 * @covers     \mod_selfselectadvanced\observer
 */
final class attributes_test extends \advanced_testcase {
    /**
     * Build a csv_import_reader from inline content.
     *
     * @param string $content CSV text
     * @return \csv_import_reader initialised reader
     */
    private function reader(string $content): \csv_import_reader {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        $iid = \csv_import_reader::get_new_iid('mod_selfselectadvanced_attr');
        $reader = new \csv_import_reader($iid, 'mod_selfselectadvanced_attr');
        $reader->load_csv_content($content, 'UTF-8', 'comma');

        return $reader;
    }

    /**
     * set() creates and updates records, fires the event, never touches
     * the user table, and the distinct-value cache follows writes.
     */
    public function test_manager_set_and_distinct_values(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $admin = get_admin();
        $profilebefore = $DB->get_record('user', ['id' => $user->id]);

        $sink = $this->redirectEvents();
        manager::set((int) $user->id, [
            'gender' => 'Female',
            'department' => 'Civil',
            'subdepartment' => 'Structures',
            'mobile' => '+91 98765 43210',
        ], (int) $admin->id);
        $events = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\attributes_updated
        );
        $sink->close();
        $this->assertCount(1, $events);

        $record = manager::get((int) $user->id);
        $this->assertSame('Female', $record->gender);
        $this->assertSame(['Civil'], manager::distinct_values('department'));

        // Update path: same row, new value, cache refreshed.
        manager::set((int) $user->id, ['department' => 'Mechanical'], (int) $admin->id);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_userattr'));
        $this->assertSame(['Mechanical'], manager::distinct_values('department'));
        // Untouched fields survive a partial update.
        $this->assertSame('Structures', manager::get((int) $user->id)->subdepartment);

        // C11 / D8: the core user record is byte-identical after writes.
        $this->assertEquals($profilebefore, $DB->get_record('user', ['id' => $user->id]));

        // Unknown dimension is a coding error.
        $this->expectException(\coding_exception::class);
        manager::distinct_values('mobile');
    }

    /**
     * The user_deleted observer removes the record and refreshes the
     * cache (review item M3).
     */
    public function test_user_deleted_observer(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        manager::set((int) $user->id, ['gender' => 'Male'], (int) get_admin()->id);
        $this->assertSame(['Male'], manager::distinct_values('gender'));

        delete_user($DB->get_record('user', ['id' => $user->id]));

        $this->assertNull(manager::get((int) $user->id));
        $this->assertSame([], manager::distinct_values('gender'));
    }

    /**
     * Importer happy path: create and update by username, email
     * fallback, counts, event on commit, transaction visible.
     */
    public function test_importer_matching_and_commit(): void {
        global $DB;
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $u1 = $gen->create_user(['username' => 'alpha', 'firstname' => 'Al', 'lastname' => 'Pha']);
        $u2 = $gen->create_user(['username' => 'beta', 'email' => 'beta@example.com']);
        manager::set((int) $u2->id, ['gender' => 'Old'], (int) get_admin()->id);

        $csv = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number,Email\n"
            . "alpha,Al,Pha,Female,Civil,Structures,+91 11111 22222,\n"
            . ",,,Male,Mech,Design,9999988888,beta@example.com\n";

        // Dry run writes nothing.
        $report = csv_importer::run($this->reader($csv), (int) get_admin()->id, false);
        $this->assertTrue($report->ok);
        $this->assertSame(2, $report->total);
        $this->assertSame(1, $report->created);
        $this->assertSame(1, $report->updated);
        $this->assertNull(manager::get((int) $u1->id));

        // Commit writes both and fires the import event with counts.
        $sink = $this->redirectEvents();
        $report = csv_importer::run($this->reader($csv), (int) get_admin()->id, true);
        $imported = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\attributes_imported
        ));
        $sink->close();

        $this->assertCount(1, $imported);
        $this->assertSame(1, $imported[0]->get_data()['other']['created']);
        $this->assertSame('Structures', manager::get((int) $u1->id)->subdepartment);
        $this->assertSame('Male', manager::get((int) $u2->id)->gender);
        $this->assertSame(2, $DB->count_records('selfselectadvanced_userattr'));
    }

    /**
     * A9/C11 rules: unknown users rejected with report lines; name
     * mismatches warned but ingested; bad mobiles skipped with warning;
     * header case-insensitive; missing columns refuse the file.
     */
    public function test_importer_validation_rules(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $gen->create_user(['username' => 'gamma', 'firstname' => 'Gail', 'lastname' => 'Ma']);

        $csv = "USERNAME,FIRSTNAME,LASTNAME,GENDER,DEPARTMENT,SUBDEPARTMENT,MOBILENUMBER\n"
            . "gamma,Wrong,Name,Female,Civil,Structures,badmobile!!!\n"
            . "ghost,Gh,Ost,Male,Mech,Design,123\n";
        $report = csv_importer::run($this->reader($csv), (int) get_admin()->id, true);

        $this->assertTrue($report->ok);
        $this->assertSame(2, $report->total);
        $this->assertSame(1, $report->created);
        // Unknown user rejected with the create-the-account guidance.
        $this->assertCount(1, $report->rejected);
        $this->assertStringContainsString('ghost', $report->rejected[0]);
        // Name mismatch warned; bad mobile warned; unknown vocabulary
        // auto-created with a warning (1.3.0 admin-level policy).
        $this->assertCount(3, $report->warnings);
        $this->assertNotEmpty(array_filter(
            $report->warnings,
            static fn($w) => str_contains($w, 'Civil / Structures')
        ));
        $record = manager::get((int) \core_user::get_user_by_username('gamma')->id);
        $this->assertSame('Civil', $record->department);
        $this->assertNull($record->mobile);

        // Missing column refuses the whole file.
        $report = csv_importer::run(
            $this->reader("username,firstname,lastname,gender\nx,y,z,F\n"),
            (int) get_admin()->id,
            false
        );
        $this->assertFalse($report->ok);
        $this->assertStringContainsString('mobile', $report->headererror);
    }
    /**
     * 1.5.0 import modes: override applies defaults to empty cells;
     * fillmissing never touches existing values.
     */
    public function test_importer_modes_and_defaults(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $gen->create_user(['username' => 'modey']);
        $header = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number\n";

        // Override mode with a default gender filling the empty cell.
        $report = csv_importer::run(
            $this->reader($header . "modey,,,,Engineering,Mechanical,\n"),
            2,
            true,
            (object) ['mode' => 'override', 'defaults' => ['gender' => 'Female']]
        );
        $this->assertSame(1, $report->created);
        $user = \core_user::get_user_by_username('modey');
        $record = manager::get((int) $user->id);
        $this->assertSame('Female', $record->gender);
        $this->assertSame('Engineering', $record->department);

        // Fillmissing: existing values survive, only gaps are filled.
        $report = csv_importer::run(
            $this->reader($header . "modey,,,Male,Science,Physics,12345\n"),
            2,
            true,
            (object) ['mode' => 'fillmissing', 'defaults' => []]
        );
        $this->assertSame(1, $report->updated);
        $record = manager::get((int) $user->id);
        $this->assertSame('Female', $record->gender);
        $this->assertSame('Engineering', $record->department);
        $this->assertSame('Mechanical', $record->subdepartment);
        $this->assertSame('12345', $record->mobile);
    }

    /**
     * A CSV cannot set a student's mobile-sharing consent (maintainer decision 85).
     *
     * The importer used to write this column. That gave one flag two owners:
     * the student, whose interface calls it their own choice, and whoever
     * uploads a spreadsheet - who could revoke it silently, with nothing
     * recording who did it, when, or on what basis. Grant-only was considered
     * and rejected for the same reason: a single boolean cannot answer those
     * questions, so consent has ONE owner.
     *
     * The column is still ACCEPTED, so files written for older releases import
     * cleanly; it simply has no effect.
     *
     * MUTATION CAUGHT (run 2026-08-09): restoring the $set['shareconsent']
     * write in csv_importer fails the grant, the revoke and the survives-import
     * assertions below.
     */
    public function test_a_csv_cannot_set_mobile_sharing_consent(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $u1 = $gen->create_user(['username' => 'consenta']);
        $u2 = $gen->create_user(['username' => 'consentb']);
        $header = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number,Share Consent\n";

        // A file that tries to GRANT changes nothing.
        $csv = $header . "consenta,,,,,,,1\nconsentb,,,,,,,YES\n";
        $report = csv_importer::run($this->reader($csv), (int) get_admin()->id, true);
        $this->assertTrue($report->ok, 'an old file carrying the column must still import cleanly');
        $this->assertFalse((bool) manager::get((int) $u1->id)->shareconsent, 'an upload may not grant consent');
        $this->assertFalse((bool) manager::get((int) $u2->id)->shareconsent, 'an upload may not grant consent');

        // The student grants it themselves - the only route there is.
        manager::set_consent((int) $u1->id, true, (int) $u1->id);
        $this->assertTrue((bool) manager::get((int) $u1->id)->shareconsent);

        // A file that tries to REVOKE it changes nothing either. This is the
        // destructive direction and the reason the ruling went further than
        // grant-only: getting it wrong here is silent and unrecoverable.
        $csv = $header . "consenta,,,,,,,0\n";
        $report = csv_importer::run($this->reader($csv), (int) get_admin()->id, true);
        $this->assertTrue($report->ok);
        $this->assertTrue(
            (bool) manager::get((int) $u1->id)->shareconsent,
            'a spreadsheet must not revoke a consent the student gave themselves'
        );

        // And the rest of the row still imports, so the column is ignored
        // rather than the whole file being refused.
        $csv = $header . "consenta,Ann,Ford,,Engineering,Mechanical,55501,0\n";
        csv_importer::run($this->reader($csv), (int) get_admin()->id, true);
        $record = manager::get((int) $u1->id);
        $this->assertSame('Engineering', $record->department, 'the other columns must still land');
        $this->assertSame('55501', $record->mobile);
        $this->assertTrue((bool) $record->shareconsent, 'consent survives an import that carried a 0');
    }
}
