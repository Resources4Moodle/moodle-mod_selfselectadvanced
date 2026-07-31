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

/**
 * Uninstall hook for mod_selfselectadvanced.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Clean up what core will not, and say what is deliberately kept.
 *
 * Core drops this plugin's tables, its configuration and the
 * capabilities declared in db/access.php. It does NOT drop the Group
 * Coordinator ROLE, because a role is site data rather than plugin
 * data - so until 1.19.1 uninstalling left the role behind for good,
 * while README claimed uninstall removed everything.
 *
 * The role is removed only when BOTH hold: this plugin created it (its
 * id was recorded at the time), and nobody is assigned to it anywhere.
 * A role somebody is using is a decision an administrator made, and an
 * uninstall must not revoke people's access as a side effect. Anything
 * kept is named in the notice rather than passed over in silence.
 *
 * Frozen core course groups are also retained on purpose (spec 14.5):
 * they are course data that outlives this activity.
 *
 * @return bool always true
 */
function xmldb_selfselectadvanced_uninstall(): bool {
    global $DB;

    \core\notification::add(
        get_string('uninstallnotice', 'mod_selfselectadvanced'),
        \core\output\notification::NOTIFY_INFO
    );

    $roleid = (int) get_config(
        'mod_selfselectadvanced',
        \mod_selfselectadvanced\local\coordinatorrole::CONFIG_ROLEID
    );
    if ($roleid <= 0 || !$DB->record_exists('role', ['id' => $roleid])) {
        return true;
    }

    if ($DB->record_exists('role_assignments', ['roleid' => $roleid])) {
        \core\notification::add(
            get_string('uninstallrolekept', 'mod_selfselectadvanced'),
            \core\output\notification::NOTIFY_WARNING
        );

        return true;
    }

    delete_role($roleid);

    return true;
}
