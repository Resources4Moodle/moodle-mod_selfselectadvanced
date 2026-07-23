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

use mod_selfselectadvanced\local\attributes\csv_importer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Inline per-user attribute editor for the site admin page (spec 8.1).
 *
 * Custom data: userid (0 = adding: shows the core user selector),
 * username (display when editing).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attredit_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $userid = (int) $this->_customdata['userid'];

        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'u', $userid);
        $mform->setType('u', PARAM_INT);

        if ($userid) {
            $mform->addElement('static', 'usernamedisplay', get_string('user'), $this->_customdata['username']);
        } else {
            $mform->addElement('autocomplete', 'targetuser', get_string('user'), [], [
                'ajax' => 'core_user/form_user_selector',
                'noselectionstring' => get_string('choosedots'),
            ]);
            $mform->addRule('targetuser', get_string('required'), 'required', null, 'client');
        }

        $mform->addElement('text', 'gender', get_string('attrgender', 'mod_selfselectadvanced'), ['size' => 24]);
        $mform->setType('gender', PARAM_TEXT);
        $mform->addElement('text', 'department', get_string('attrdepartment', 'mod_selfselectadvanced'), ['size' => 40]);
        $mform->setType('department', PARAM_TEXT);
        $mform->addElement(
            'text',
            'subdepartment',
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
            ['size' => 40]
        );
        $mform->setType('subdepartment', PARAM_TEXT);
        $mform->addElement('text', 'mobile', get_string('attrmobile', 'mod_selfselectadvanced'), ['size' => 20]);
        $mform->setType('mobile', PARAM_TEXT);

        $this->add_action_buttons();
    }

    /**
     * Validate lengths and the mobile format.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        foreach (['gender' => 50, 'department' => 100, 'subdepartment' => 100] as $field => $max) {
            if (\core_text::strlen($data[$field] ?? '') > $max) {
                $errors[$field] = get_string('maximumchars', '', $max);
            }
        }
        $mobile = trim($data['mobile'] ?? '');
        if ($mobile !== '' && !preg_match('/^[0-9+\-\s()]{1,' . csv_importer::MOBILE_MAX . '}$/', $mobile)) {
            $errors['mobile'] = get_string('errbadmobile', 'mod_selfselectadvanced');
        }

        return $errors;
    }
}
