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
 * Staff reach-out form (maintainer decision 18): a subject and a body,
 * both plain text, which travel to the participant as a Moodle message.
 *
 * Plain text on purpose - nothing a sender types can inject markup into
 * somebody else's notification - and no address field of any kind, in
 * either direction.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staffmessage_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'to', $this->_customdata['to']);
        $mform->setType('to', PARAM_INT);
        $mform->addElement('hidden', 'returnurl', $this->_customdata['returnurl'] ?? '');
        $mform->setType('returnurl', PARAM_LOCALURL);

        $mform->addElement(
            'text',
            'subject',
            get_string('messagesendsubject', 'mod_selfselectadvanced'),
            ['size' => 60]
        );
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'body',
            get_string('messagesendbody', 'mod_selfselectadvanced'),
            ['rows' => 8, 'cols' => 60]
        );
        $mform->setType('body', PARAM_TEXT);
        $mform->addRule('body', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('messagesend', 'mod_selfselectadvanced'));
    }
}
