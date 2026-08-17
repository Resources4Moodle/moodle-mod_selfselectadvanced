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
 * Version metadata for mod_selfselectadvanced.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_selfselectadvanced';
$plugin->version = 2026081700;
// Moodle 5.2 only, by decision rather than by drift. The plugin was previously
// declared for 4.5 LTS to 5.2, but it has only ever been tested on 5.2 - the
// gate that governs this codebase runs one branch. Promising four branches and
// verifying one is a claim the project cannot stand behind, so the promise is
// narrowed to what is actually proven.
$plugin->requires = 2026042001; // Moodle 5.2.1 - 2026042000 is 5.2.0, so this floor is 5.2.1+.
$plugin->supported = [502, 502];
// RC until the audit-response waves are complete and the exact candidate
// passes the full runtime/DB/privacy/backup/upgrade matrix (decision 70,
// consolidated master audit §10): the metadata tells the truth about a
// tree that is wave 2 of a planned response, not a finished stable.
$plugin->maturity = MATURITY_RC;
$plugin->release = '1.20.50';
