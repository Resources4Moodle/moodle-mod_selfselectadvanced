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
 * Upgrade steps for mod_selfselectadvanced.
 *
 * Versioned from day one (spec section 15.4): the plugin must upgrade
 * cleanly from any released version as well as install cleanly.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute an upgrade from the given old version.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_selfselectadvanced_upgrade($oldversion): bool {
    // First public schema is 2026072400; upgrade steps accumulate below
    // with upgrade_mod_savepoint() calls as the plugin evolves.

    if ($oldversion < 2026072401) {
        // Slice 2: external function, message providers and the
        // invitation expiry task registered from their db/ files; no
        // schema change.
        upgrade_mod_savepoint(true, 2026072401, 'selfselectadvanced');
    }

    if ($oldversion < 2026072402) {
        // Slice 3: nomination and nominationresult message providers.
        upgrade_mod_savepoint(true, 2026072402, 'selfselectadvanced');
    }

    if ($oldversion < 2026072403) {
        // Slice 4: guidequeue, groupreturned and groupapproved
        // message providers.
        upgrade_mod_savepoint(true, 2026072403, 'selfselectadvanced');
    }

    if ($oldversion < 2026072404) {
        // Slice 5: attribute value cache definition and the
        // user_deleted observer.
        upgrade_mod_savepoint(true, 2026072404, 'selfselectadvanced');
    }

    return true;
}
