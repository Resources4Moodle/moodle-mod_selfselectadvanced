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
 * Edit one notification template override.
 *
 * Custom data: msgkey, defaultsubject, defaultbody (the raw language
 * strings, shown as the reference and used to seed an empty form).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'static',
            'kinddisplay',
            get_string('templatekind', 'mod_selfselectadvanced'),
            get_string('tpl' . $this->_customdata['msgkey'], 'mod_selfselectadvanced')
        );
        $mform->addElement(
            'static',
            'defaultdisplay',
            get_string('templatedefault', 'mod_selfselectadvanced'),
            \html_writer::div(s($this->_customdata['defaultsubject']), 'fw-bold')
            . \html_writer::div(s($this->_customdata['defaultbody']))
        );

        $mform->addElement('text', 'subject', get_string('subject', 'mod_selfselectadvanced'), ['size' => 64]);
        $mform->setType('subject', PARAM_TEXT);
        $mform->setDefault('subject', $this->_customdata['defaultsubject']);

        $mform->addElement(
            'textarea',
            'body',
            get_string('templatebody', 'mod_selfselectadvanced'),
            ['rows' => 8, 'cols' => 70]
        );
        $mform->setType('body', PARAM_RAW);
        $mform->setDefault('body', $this->_customdata['defaultbody']);

        $mform->addElement('advcheckbox', 'resetdefault', get_string('templatereset', 'mod_selfselectadvanced'));

        $this->add_action_buttons();
    }

    /**
     * Require subject and body unless resetting.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (empty($data['resetdefault'])) {
            if (trim($data['subject'] ?? '') === '') {
                $errors['subject'] = get_string('required');
            }
            if (trim($data['body'] ?? '') === '') {
                $errors['body'] = get_string('required');
            }
        }

        return $errors;
    }
}
