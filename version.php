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
$plugin->version = 2026080706;
// Moodle 5.2 only, by decision rather than by drift. The plugin was previously
// declared for 4.5 LTS to 5.2, but it has only ever been tested on 5.2 - the
// gate that governs this codebase runs one branch. Promising four branches and
// verifying one is a claim the project cannot stand behind, so the promise is
// narrowed to what is actually proven.
$plugin->requires = 2026042001; // Moodle 5.2.
$plugin->supported = [502, 502];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.20.21';
