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

use mod_selfselectadvanced\local\coordinatorrole;

/**
 * A role this plugin did not create is never widened by it.
 *
 * Until 1.19.1 the installer adopted any role carrying the shortname
 * "groupcoordinator" and granted it six capabilities at system context,
 * :override and :viewall among them. A site that already used that
 * shortname for its own purposes therefore handed every holder of that
 * role powers across the whole site the moment this plugin was
 * installed. Sharing a shortname is not consent.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\coordinatorrole
 */
final class coordinatorrole_collision_test extends \advanced_testcase {
    /**
     * Put the site back to "this plugin has never made its role".
     *
     * The plugin's own install step calls ensure(), so the PHPUnit base
     * state already carries the role. Every test here reasons about
     * what ensure() does on a site that does NOT yet have it, so the
     * starting state has to be built rather than assumed.
     */
    private function forget_our_role(): void {
        global $DB;

        $roleid = $DB->get_field('role', 'id', ['shortname' => coordinatorrole::SHORTNAME]);
        if ($roleid) {
            delete_role((int) $roleid);
        }
        unset_config(coordinatorrole::CONFIG_ROLEID, 'mod_selfselectadvanced');
        unset_config(coordinatorrole::CONFIG_COLLISION, 'mod_selfselectadvanced');
    }

    /**
     * A foreign role of the same shortname is left completely alone,
     * and the clash is recorded for an administrator to resolve.
     */
    public function test_a_foreign_role_is_not_touched(): void {
        global $DB;

        $this->resetAfterTest();
        $this->forget_our_role();

        // A site role that happens to use the shortname, created for
        // something else entirely.
        $foreignid = create_role(
            'Faculty group liaison',
            coordinatorrole::SHORTNAME,
            'Nothing to do with this plugin.',
            ''
        );
        $before = $DB->count_records('role_capabilities', ['roleid' => $foreignid]);

        $roleid = coordinatorrole::ensure();

        $this->assertSame(0, $roleid, 'ensure() must refuse rather than adopt a foreign role');
        $this->assertSame(
            $before,
            $DB->count_records('role_capabilities', ['roleid' => $foreignid]),
            'No capability may be granted to a role this plugin did not create'
        );
        foreach (coordinatorrole::capabilities() as $capability) {
            $this->assertFalse(
                $DB->record_exists('role_capabilities', [
                    'roleid' => $foreignid,
                    'capability' => $capability,
                ]),
                "Foreign role was granted $capability"
            );
        }
        $this->assertSame(coordinatorrole::SHORTNAME, coordinatorrole::collision());
    }

    /**
     * With no clash the role is created, recorded and granted its
     * capabilities, and a second call is a no-op returning the same id.
     */
    public function test_the_role_is_created_once_and_recorded(): void {
        global $DB;

        $this->resetAfterTest();
        $this->forget_our_role();

        $roleid = coordinatorrole::ensure();
        $this->assertGreaterThan(0, $roleid);
        $this->assertNull(coordinatorrole::collision());
        $this->assertSame(
            $roleid,
            (int) get_config('mod_selfselectadvanced', coordinatorrole::CONFIG_ROLEID),
            'The id of the role we created must be recorded, so later runs know it is ours'
        );
        foreach (coordinatorrole::capabilities() as $capability) {
            $this->assertTrue($DB->record_exists('role_capabilities', [
                'roleid' => $roleid,
                'capability' => $capability,
            ]), "Our own role is missing $capability");
        }

        $this->assertSame($roleid, coordinatorrole::ensure());
        $this->assertSame(1, $DB->count_records('role', ['shortname' => coordinatorrole::SHORTNAME]));
    }

    /**
     * A capability an administrator sets back to "Not set" stays unset.
     *
     * ensure() writes capabilities, and it used to be called from page
     * views. `assign_capability()` with overwrite off declines to
     * change a setting that EXISTS; "Not set" is the absence of one, so
     * it was refilled the next time anybody opened the coordinators
     * page. Runtime now resolves the role without provisioning it.
     */
    public function test_a_removed_capability_is_not_restored_at_runtime(): void {
        global $DB;

        $this->resetAfterTest();
        $this->forget_our_role();

        $roleid = coordinatorrole::ensure();
        $capability = 'mod/selfselectadvanced:override';

        // Exactly what "Not set" does in the interface.
        unassign_capability($capability, $roleid, \context_system::instance()->id);
        $this->assertFalse($DB->record_exists('role_capabilities', [
            'roleid' => $roleid,
            'capability' => $capability,
        ]));

        // What a page view now does.
        $this->assertSame($roleid, coordinatorrole::roleid());

        $this->assertFalse(
            $DB->record_exists('role_capabilities', [
                'roleid' => $roleid,
                'capability' => $capability,
            ]),
            'A page view restored a capability the administrator had removed'
        );
    }

    /**
     * The binding is the recorded id, not the shortname: an
     * administrator who renames the role keeps it, and the plugin does
     * not create a second one alongside it.
     */
    public function test_a_renamed_role_is_still_ours(): void {
        global $DB;

        $this->resetAfterTest();
        $this->forget_our_role();

        $roleid = coordinatorrole::ensure();
        $DB->set_field('role', 'shortname', 'campuscoordinator', ['id' => $roleid]);

        $this->assertSame($roleid, coordinatorrole::ensure(), 'A renamed role must still be recognised as ours');
        $this->assertSame(
            0,
            $DB->count_records('role', ['shortname' => coordinatorrole::SHORTNAME]),
            'No duplicate role may be created behind the renamed one'
        );
    }
}
