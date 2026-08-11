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
 * Staff fill a leadership vacancy by appointing a confirmed member.
 *
 * WHY THIS IS A ROSTER-SCOPED PICKER and not a site-wide user search. The only
 * lawful candidate is somebody who is ALREADY a confirmed member of this very
 * group, so the candidate set is the group's own roster - bounded by the
 * activity's maximum group size, and typically a handful of people. It is
 * built exactly like the successor picker beside it: a Moodle autocomplete
 * over an eligible list, with the members who cannot be appointed listed
 * separately WITH THE REASON rather than silently omitted, so staff can see
 * why the obvious candidate is missing.
 *
 * A site-wide participant search would be the wrong instrument twice over: it
 * would offer people who are not in the group at all, and it would disclose
 * participants the appointing actor has no business being shown here.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class appointleader_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'g', $this->_customdata['groupid']);
        $mform->setType('g', PARAM_INT);
        $mform->addElement('hidden', 'action', 'appointleader');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'autocomplete',
            'newleader',
            get_string('appointleaderlabel', 'mod_selfselectadvanced'),
            $this->_customdata['eligible'],
            ['noselectionstring' => get_string('choosedots')]
        );
        $mform->setType('newleader', PARAM_INT);
        $mform->addRule('newleader', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('newleader', 'appointleaderlabel', 'mod_selfselectadvanced');

        // Named rather than add_action_buttons(): several forms share the
        // group page, and a fixed name keeps this button's id stable however
        // many of the others are drawn.
        $mform->addElement('submit', 'submitappointleader', get_string('appointleader', 'mod_selfselectadvanced'));
        $mform->closeHeaderBefore('submitappointleader');
    }
}
