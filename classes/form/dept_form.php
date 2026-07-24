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

use mod_selfselectadvanced\local\attributes\depts;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add / rename a department category.
 *
 * Custom data: action ('add'|'rename'), id (parent for add, target for
 * rename).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dept_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $action = $this->_customdata['action'];
        $id = (int) $this->_customdata['id'];

        $mform->addElement('hidden', 'action', $action);
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'd', $id);
        $mform->setType('d', PARAM_INT);

        if ($action === 'add') {
            $options = [0 => get_string('top')];
            foreach (depts::get_all() as $record) {
                $prefix = str_repeat('　', (int) $record->depth - 1);
                $options[(int) $record->id] = $prefix . format_string($record->name);
            }
            $mform->addElement('select', 'parent', get_string('deptparent', 'mod_selfselectadvanced'), $options);
            $mform->setDefault('parent', $id);
        }

        $mform->addElement('text', 'name', get_string('name'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons();
    }

    /**
     * Validate the name.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $name = trim($data['name'] ?? '');
        if ($name === '' || \core_text::strlen($name) > 100) {
            $errors['name'] = get_string('errdeptname', 'mod_selfselectadvanced');
        }

        return $errors;
    }
}
