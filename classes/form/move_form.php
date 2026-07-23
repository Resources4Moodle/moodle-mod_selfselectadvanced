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
 * Stage-a-move form (spec 7): student, optional source group, target
 * group, leader designation, source successor, and - for holders of
 * the override capability - rule bypass codes attached as a move-scope
 * override.
 *
 * Custom data: cmid, students, groups, canbypass.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'autocomplete',
            'student',
            get_string('movestudent', 'mod_selfselectadvanced'),
            $this->_customdata['students'],
            ['noselectionstring' => get_string('choosedots')]
        );
        $mform->addRule('student', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'select',
            'source',
            get_string('movefrom', 'mod_selfselectadvanced'),
            [0 => get_string('movenosource', 'mod_selfselectadvanced')] + $this->_customdata['groups']
        );
        $mform->addHelpButton('source', 'movefrom', 'mod_selfselectadvanced');

        $mform->addElement(
            'select',
            'target',
            get_string('moveto', 'mod_selfselectadvanced'),
            $this->_customdata['groups']
        );
        $mform->addRule('target', get_string('required'), 'required', null, 'client');

        $mform->addElement('advcheckbox', 'makeleader', get_string('movemakeleader', 'mod_selfselectadvanced'));

        $mform->addElement(
            'select',
            'successor',
            get_string('movesuccessor', 'mod_selfselectadvanced'),
            [0 => get_string('choosedots')] + $this->_customdata['students']
        );
        $mform->addHelpButton('successor', 'movesuccessor', 'mod_selfselectadvanced');

        if ($this->_customdata['canbypass']) {
            $bypass = [];
            foreach (['L1', 'L2', 'L3', 'L4', 'QUOTA'] as $code) {
                $bypass[] = $mform->createElement('advcheckbox', $code, '', $code);
            }
            $mform->addGroup($bypass, 'bypassgroup', get_string('movebypasslabel', 'mod_selfselectadvanced'), ' ', true);
            $mform->addHelpButton('bypassgroup', 'movebypasslabel', 'mod_selfselectadvanced');
        }

        $this->add_action_buttons(true, get_string('movestage', 'mod_selfselectadvanced'));
    }

    /**
     * Validate source/target distinctness.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (!empty($data['source']) && (int) $data['source'] === (int) $data['target']) {
            $errors['target'] = get_string('errmovesamegroup', 'mod_selfselectadvanced');
        }

        return $errors;
    }

    /**
     * Normalise the bypass group into a code list.
     *
     * @return \stdClass|null form data with bypass[] resolved
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data && !empty($data->bypassgroup)) {
            $data->bypass = array_keys(array_filter((array) $data->bypassgroup));
        }

        return $data;
    }
}
