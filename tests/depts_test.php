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
use mod_selfselectadvanced\local\attributes\depts;
use mod_selfselectadvanced\local\attributes\manager;

/**
 * The pre-defined department vocabulary tree (2026-07-24 change):
 * course-category format, enforcement in the form/importer only once
 * configured, free text before that.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\attributes\depts
 */
final class depts_test extends \advanced_testcase {
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
        $reader->load_csv_content($content, 'utf-8', 'comma');

        return $reader;
    }

    /**
     * Tree construction: paths, depths, display order, menus.
     */
    public function test_tree_and_menus(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;

        $this->assertFalse(depts::is_configured());
        $eng = depts::create('Engineering', 0, $admin);
        $sci = depts::create('Science', 0, $admin);
        $mech = depts::create('Mechanical', (int) $eng->id, $admin);
        $civil = depts::create('Civil', (int) $eng->id, $admin);
        $physics = depts::create('Physics', (int) $sci->id, $admin);
        // A third level is allowed by the format.
        $thermo = depts::create('Thermodynamics', (int) $mech->id, $admin);

        $this->assertTrue(depts::is_configured());
        $this->assertSame('/' . $eng->id . '/' . $mech->id, $mech->path);
        $this->assertSame(3, (int) $thermo->depth);

        // Depth-first display order.
        $names = array_map(static fn($r) => $r->name, array_values(depts::get_all()));
        $this->assertSame(
            ['Engineering', 'Mechanical', 'Thermodynamics', 'Civil', 'Science', 'Physics'],
            $names
        );
        $this->assertSame(['Engineering', 'Science'], array_values(depts::departments_menu()));
        $grouped = depts::subdepartments_grouped();
        $this->assertSame(['Mechanical', 'Civil'], array_values($grouped['Engineering']));
        $this->assertSame(['Physics'], array_values($grouped['Science']));

        // Duplicate names: refused at the same level, fine elsewhere.
        $this->expectException(\moodle_exception::class);
        depts::create('Mechanical', (int) $eng->id, $admin);
    }

    /**
     * validate_pair: empty always valid; department must be top level;
     * sub-department must be a child of its department.
     */
    public function test_validate_pair(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;

        $eng = depts::create('Engineering', 0, $admin);
        depts::create('Mechanical', (int) $eng->id, $admin);
        $sci = depts::create('Science', 0, $admin);
        depts::create('Physics', (int) $sci->id, $admin);

        $this->assertNull(depts::validate_pair('', ''));
        $this->assertNull(depts::validate_pair('Engineering', ''));
        $this->assertNull(depts::validate_pair('Engineering', 'Mechanical'));
        $this->assertSame('department', depts::validate_pair('Astrology', ''));
        $this->assertSame('subdepartment', depts::validate_pair('Engineering', 'Physics'));
        $this->assertSame('subdepartment', depts::validate_pair('', 'Mechanical'));
    }

    /**
     * Deletion guards: children first, then in-use values; rename and
     * sibling moves work.
     */
    public function test_delete_guards_rename_move(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;

        $eng = depts::create('Engineering', 0, $admin);
        $mech = depts::create('Mechanical', (int) $eng->id, $admin);
        $user = $this->getDataGenerator()->create_user();
        manager::set((int) $user->id, ['department' => 'Engineering', 'subdepartment' => 'Mechanical'], 2);

        try {
            depts::delete((int) $eng->id, $admin);
            $this->fail('children guard expected');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('sub-categories', $e->getMessage());
        }
        try {
            depts::delete((int) $mech->id, $admin);
            $this->fail('in-use guard expected');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('attributes use this value', $e->getMessage());
        }

        manager::set((int) $user->id, ['department' => 'Engineering', 'subdepartment' => ''], 2);
        depts::delete((int) $mech->id, $admin);
        $this->assertSame([], depts::subdepartments_grouped());

        depts::rename((int) $eng->id, 'Engg', $admin);
        $this->assertSame(['Engg'], array_values(depts::departments_menu()));

        $sci = depts::create('Science', 0, $admin);
        depts::move((int) $sci->id, -1, $admin);
        $this->assertSame(['Science', 'Engg'], array_values(depts::departments_menu()));
    }

    /**
     * CSV enforcement: unknown values reject the row once the tree is
     * configured; valid values and the not-configured case import.
     */
    public function test_csv_enforcement(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $u1 = $gen->create_user(['username' => 'alpha']);
        $header = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number\n";

        // Not configured: free text imports.
        $report = csv_importer::run($this->reader($header . "alpha,,,F,Astrology,Starlore,\n"), 2, true);
        $this->assertSame(1, $report->created);
        $this->assertSame([], $report->rejected);

        // 1.3.0 policy: the ingest runs at ADMIN level, so unknown
        // vocabulary is CREATED (warned), never rejected.
        $admin = (int) get_admin()->id;
        $eng = depts::create('Engineering', 0, $admin);
        depts::create('Mechanical', (int) $eng->id, $admin);
        $report = csv_importer::run($this->reader($header . "alpha,,,F,Alchemy,Potions,\n"), 2, true);
        $this->assertSame([], $report->rejected);
        $this->assertNotEmpty(array_filter(
            $report->warnings,
            static fn($w) => str_contains($w, 'Alchemy / Potions') && str_contains($w, 'created')
        ));
        $this->assertNull(depts::validate_pair('Alchemy', 'Potions'));
        $this->assertSame('Alchemy', manager::get((int) $u1->id)->department);

        // A known pair imports silently.
        $report = csv_importer::run(
            $this->reader($header . "alpha,,,F,Engineering,Mechanical,\n"),
            2,
            true
        );
        $this->assertSame([], $report->rejected);
        $this->assertSame(1, $report->updated);
        $this->assertSame('Engineering', manager::get((int) $u1->id)->department);

        // A sub-department under a different parent becomes a NEW
        // child of that parent (same name may live under two parents).
        $sci = depts::create('Science', 0, $admin);
        depts::create('Physics', (int) $sci->id, $admin);
        $report = csv_importer::run($this->reader($header . "alpha,,,F,Engineering,Physics,\n"), 2, true);
        $this->assertSame([], $report->rejected);
        $this->assertNull(depts::validate_pair('Engineering', 'Physics'));
        $this->assertNull(depts::validate_pair('Science', 'Physics'));
    }
}
