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

namespace mod_selfselectadvanced\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Participant-attribute CSV upload (spec 8.1, U4): file, delimiter and
 * encoding; submission produces the dry-run validation report first.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attributes_upload_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('static', 'csvformat', '', get_string('csvformathelp', 'mod_selfselectadvanced'));

        $mform->addElement('filepicker', 'csvfile', get_string('csvfile', 'mod_selfselectadvanced'), null, [
            'accepted_types' => ['.csv', '.txt'],
        ]);
        $mform->addRule('csvfile', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'select',
            'delimiter',
            get_string('csvdelimiter', 'mod_selfselectadvanced'),
            \csv_import_reader::get_delimiter_list()
        );
        $mform->setDefault('delimiter', 'comma');

        $mform->addElement(
            'select',
            'encoding',
            get_string('encoding', 'grades'),
            array_combine(\core_text::get_encodings(), \core_text::get_encodings())
        );
        $mform->setDefault('encoding', 'UTF-8');

        $mform->addElement('select', 'updatemode', get_string('csvupdatemode', 'mod_selfselectadvanced'), [
            'override' => get_string('csvmodeoverride', 'mod_selfselectadvanced'),
            'fillmissing' => get_string('csvmodefillmissing', 'mod_selfselectadvanced'),
        ]);
        $mform->addHelpButton('updatemode', 'csvupdatemode', 'mod_selfselectadvanced');

        // Default values applied wherever the file cell is empty
        // (mirrors core user upload's defaults section).
        $mform->addElement('header', 'csvdefaults', get_string('csvdefaults', 'mod_selfselectadvanced'));
        foreach (['gender', 'department', 'subdepartment', 'program', 'seatlocation'] as $field) {
            $mform->addElement(
                'text',
                'default' . $field,
                get_string('attr' . $field, 'mod_selfselectadvanced'),
                ['size' => 30]
            );
            $mform->setType('default' . $field, PARAM_TEXT);
        }

        $this->add_action_buttons(false, get_string('csvpreview', 'mod_selfselectadvanced'));
    }
}
