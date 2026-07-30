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

use mod_selfselectadvanced\local\coordinatorimport;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload a list of Group Coordinators (strategy 1.17 B3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coordinator_import_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('coordinatorimportfile', 'mod_selfselectadvanced'),
            null,
            ['accepted_types' => ['.csv', '.txt']]
        );
        $mform->addRule('csvfile', get_string('required'), 'required');
        $mform->addHelpButton('csvfile', 'coordinatorimportfile', 'mod_selfselectadvanced');

        $mform->addElement('select', 'mode', get_string('coordinatorimportmode', 'mod_selfselectadvanced'), [
            coordinatorimport::MODE_ADD_REMOVE => get_string('coordinatorimportaddremove', 'mod_selfselectadvanced'),
            coordinatorimport::MODE_OVERWRITE => get_string('coordinatorimportoverwrite', 'mod_selfselectadvanced'),
        ]);
        $mform->setDefault('mode', coordinatorimport::MODE_ADD_REMOVE);
        $mform->addHelpButton('mode', 'coordinatorimportmode', 'mod_selfselectadvanced');

        $mform->addElement('advcheckbox', 'enrol', get_string('coordinatorimportenrol', 'mod_selfselectadvanced'));
        $mform->setDefault('enrol', 0);
        $mform->addHelpButton('enrol', 'coordinatorimportenrol', 'mod_selfselectadvanced');

        $mform->addElement('advcheckbox', 'unenrol', get_string('coordinatorimportunenrol', 'mod_selfselectadvanced'));
        $mform->setDefault('unenrol', 0);
        $mform->addHelpButton('unenrol', 'coordinatorimportunenrol', 'mod_selfselectadvanced');

        $this->add_action_buttons(false, get_string('coordinatorimportpreviewbutton', 'mod_selfselectadvanced'));
    }
}
