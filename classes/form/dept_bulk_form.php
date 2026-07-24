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

/**
 * Bulk department update: paste one path per line, "/"-separated
 * levels; missing nodes are created, existing ones reused.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dept_bulk_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'action', 'bulk');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('static', 'bulkhelp', '', get_string('deptbulkhelp', 'mod_selfselectadvanced'));
        $mform->addElement(
            'textarea',
            'tree',
            get_string('deptbulk', 'mod_selfselectadvanced'),
            ['rows' => 12, 'cols' => 60]
        );
        $mform->setType('tree', PARAM_TEXT);
        $mform->addRule('tree', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons();
    }
}
