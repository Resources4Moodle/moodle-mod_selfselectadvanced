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
 * A student asking to join another team (strategy 1.19 B).
 *
 * Custom data: cmid.
 *
 * The team is chosen through the searchable picker every other team
 * control uses - a student is choosing among the same fifteen hundred
 * teams the staff are, and a dropdown would serve them no better.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class joinrequest_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'ask');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'autocomplete',
            'target',
            get_string('jointarget', 'mod_selfselectadvanced'),
            [],
            [
                'ajax' => 'mod_selfselectadvanced/groupselector',
                'noselectionstring' => get_string('choosedots'),
                'placeholder' => get_string('grouppickerplaceholder', 'mod_selfselectadvanced'),
                'casesensitive' => false,
                'data-cmid' => $this->_customdata['cmid'],
            ]
        );
        $mform->setType('target', PARAM_INT);
        $mform->addRule('target', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'reason', get_string('jointreason', 'mod_selfselectadvanced'), ['size' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reason', 'jointreason', 'mod_selfselectadvanced');

        $mform->addElement('submit', 'askbutton', get_string('joinsend', 'mod_selfselectadvanced'));
    }
}
