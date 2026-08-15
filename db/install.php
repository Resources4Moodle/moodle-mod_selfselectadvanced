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
    // PHP 8.4 floor - a BACKSTOP, not the primary gate (external audit
    // FCA-001, closed by ../environment.xml). The primary gate is now
    // <PLUGIN name="mod_selfselectadvanced"><PHP version="8.4.0"
    // level="required" /></PLUGIN> in this plugin's environment.xml, which
    // Moodle's own environment checker (lib/environmentlib.php) discovers
    // and evaluates - via check_moodle_environment(), which admin/index.php
    // calls on its install/upgrade screens - strictly before
    // upgrade_noncore() reaches this plugin's db/install.xml, let alone this
    // hook. This check stays only because xmldb_selfselectadvanced_install()
    // is itself Moodle's POST-install hook: core has already created the
    // db/install.xml schema by the time it runs, so this line can never be
    // the thing that stops a table from existing - it exists in case a site
    // somehow reaches this hook without honouring the environment gate
    // (e.g. an administrator who bypassed the confirmation screens). The
    // previous wording here, and in README.md, claimed this hook itself ran
    // "before anything is created"; that was never true of a post-install
    // hook. db/upgrade.php makes the same backstop check for an existing
    // site being upgraded past this version.
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
