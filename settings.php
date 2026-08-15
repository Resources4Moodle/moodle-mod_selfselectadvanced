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
 * Admin tree for mod_selfselectadvanced: Site administration >
 * Plugins > Activity modules > Group self-selection (Advanced) >
 * Participant attributes (spec 8.1).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('modsettings', new admin_category(
        'modselfselectadvancedcat',
        new lang_string('pluginname', 'mod_selfselectadvanced')
    ));
    $ADMIN->add('modselfselectadvancedcat', new admin_externalpage(
        'modselfselectadvancedattributes',
        new lang_string('participantattributes', 'mod_selfselectadvanced'),
        new moodle_url('/mod/selfselectadvanced/attributes.php'),
        'mod/selfselectadvanced:ingestattributes'
    ));
    $ADMIN->add('modselfselectadvancedcat', new admin_externalpage(
        'modselfselectadvanceddepartments',
        new lang_string('departments', 'mod_selfselectadvanced'),
        new moodle_url('/mod/selfselectadvanced/departments.php'),
        'mod/selfselectadvanced:ingestattributes'
    ));
}

// One site-wide preference (everything else is per instance): the
// default report export format (2026-07-25 request).
if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'modsettingselfselectadvanced',
        new lang_string('pluginsettings', 'mod_selfselectadvanced'),
        'moodle/site:config'
    );
    $settings->add(new admin_setting_configselect(
        'mod_selfselectadvanced/exportformat',
        new lang_string('exportformat', 'mod_selfselectadvanced'),
        new lang_string('exportformat_desc', 'mod_selfselectadvanced'),
        'excel',
        [
            'ods' => new lang_string('exportods', 'mod_selfselectadvanced'),
            'excel' => new lang_string('exportexcel', 'mod_selfselectadvanced'),
            'csv' => new lang_string('exportcsv', 'mod_selfselectadvanced'),
            'txt' => new lang_string('exporttxt', 'mod_selfselectadvanced'),
        ]
    ));
    // 1.20.46: the LLM API's display name (BUILD spec section D - "NOT a
    // per-activity setting", maintainer decision, exact default string
    // "Automated Assistant"). classes/output/ticket_page.php reads it at
    // RENDER time for any thread post whose actor holds mod/
    // selfselectadvanced:api in that activity's context, so renaming here
    // applies retroactively to every past post rather than being copied
    // onto the row when it was written.
    $settings->add(new admin_setting_configtext(
        'mod_selfselectadvanced/assistantname',
        new lang_string('assistantname', 'mod_selfselectadvanced'),
        new lang_string('assistantname_desc', 'mod_selfselectadvanced'),
        'Automated Assistant',
        PARAM_TEXT
    ));
    $ADMIN->add('modselfselectadvancedcat', $settings);
    $settings = null;
} else {
    $settings = null;
}
