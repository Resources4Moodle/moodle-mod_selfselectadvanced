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
 * Post-install hook: create the Group Coordinator role (strategy
 * 1.16 D) - roles cannot be declared in db/access.php.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create the Group Coordinator role on a fresh install.
 */
function xmldb_selfselectadvanced_install(): void {
    // PHP 8.4 floor. STATED PRECISELY, because the previous comment here and
    // the README both claimed this runs "before anything is created" and it
    // does not: xmldb_selfselectadvanced_install() is Moodle's POST-install
    // hook, and core installs the db/install.xml schema before calling it. So
    // on a site below the floor the tables can already exist when this throws.
    // Moodle offers no version.php field for a PHP minimum, so the plugin has
    // to say it itself; expressing it as a real environment requirement, which
    // core WOULD evaluate before installing, is owed (external audit FCA-001).
    // db/upgrade.php makes the same check for an existing site.
    // db/upgrade.php makes the same check for an existing site.
    if (version_compare(PHP_VERSION, '8.4.0', '<')) {
        throw new moodle_exception('errorphptoolow', 'mod_selfselectadvanced', '', PHP_VERSION);
    }

    // Core registers db/access.php AFTER this hook runs, so the
    // capabilities the role needs do not exist yet on a fresh
    // install - register them first (idempotent; core's own later
    // call becomes a no-op). The upgrade path does the same.
    update_capabilities('mod_selfselectadvanced');
    \mod_selfselectadvanced\local\coordinatorrole::ensure();
}
