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

use mod_selfselectadvanced\local\attributes\manager;

/**
 * Mobile-sharing consent: the visibility rule, the self-service
 * toggle, and the admin write path.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\attributes\manager
 */
final class consent_test extends \advanced_testcase {
    /**
     * The visibility truth table: full-view staff always see a stored
     * number; everyone else only with consent; nobody sees an absent
     * number.
     */
    public function test_mobile_visible(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        manager::set((int) $user->id, ['mobile' => '919800000001'], 2);
        $record = manager::get((int) $user->id);

        $this->assertTrue(manager::mobile_visible($record, true));
        $this->assertFalse(manager::mobile_visible($record, false));

        manager::set_consent((int) $user->id, true, (int) $user->id);
        $record = manager::get((int) $user->id);
        $this->assertTrue(manager::mobile_visible($record, false));

        manager::set_consent((int) $user->id, false, (int) $user->id);
        $record = manager::get((int) $user->id);
        $this->assertFalse(manager::mobile_visible($record, false));

        // No record, or a record without a number: never visible.
        $this->assertFalse(manager::mobile_visible(null, true));
        $bare = $this->getDataGenerator()->create_user();
        manager::set((int) $bare->id, ['department' => 'Science'], 2);
        $this->assertTrue(manager::mobile_visible(manager::get((int) $bare->id), true) === false);
    }

    /**
     * The admin write path accepts the consent flag (CSV import), and
     * omitting it leaves consent untouched.
     */
    public function test_set_accepts_consent(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        manager::set((int) $user->id, ['mobile' => '919800000002', 'shareconsent' => 1], 2);
        $this->assertEquals(1, (int) manager::get((int) $user->id)->shareconsent);

        manager::set((int) $user->id, ['department' => 'Science'], 2);
        $this->assertEquals(1, (int) manager::get((int) $user->id)->shareconsent);

        manager::set((int) $user->id, ['shareconsent' => 0], 2);
        $this->assertEquals(0, (int) manager::get((int) $user->id)->shareconsent);
    }
}
