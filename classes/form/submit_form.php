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
 * Submit-to-guide form (T2, spec 6.5). In leader-selects mode the
 * guide list shows each guide's used/remaining load and excludes those
 * at capacity; in manager-assigns mode (A5) no guide is chosen here.
 *
 * Custom data: cmid, groupid, leaderselects (bool),
 * guides (value => "Name — Guiding x of y").
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'g', $this->_customdata['groupid']);
        $mform->setType('g', PARAM_INT);
        $mform->addElement('hidden', 'action', 'submit');
        $mform->setType('action', PARAM_ALPHA);

        if ($this->_customdata['leaderselects']) {
            // Searchable, never a list (strategy 1.18 B): a school with
            // 1500 guides used to render every one of them here, which
            // is neither usable nor quick to load on a phone.
            $mform->addElement(
                'autocomplete',
                'guide',
                get_string('chooseguide', 'mod_selfselectadvanced'),
                [],
                [
                    'ajax' => 'mod_selfselectadvanced/guideselector',
                    'placeholder' => get_string('guidepickerplaceholder', 'mod_selfselectadvanced'),
                    'noselectionstring' => get_string('guidepickernone', 'mod_selfselectadvanced'),
                    'casesensitive' => false,
                    'valuehtmlcallback' => function ($userid) {
                        $user = \core_user::get_user((int) $userid);

                        return $user ? fullname($user) : '';
                    },
                    'data-cmid' => $this->_customdata['cmid'],
                    // Student-approach mode keeps a full guide on the
                    // list: omitting them would itself advertise their
                    // load, which is the thing that mode hides
                    // (strategy 1.16 A). Capacity is still enforced,
                    // silently, at submission.
                    'data-withroom' => empty($this->_customdata['studentapproach']) ? '1' : '0',
                ]
            );
            $mform->setType('guide', PARAM_INT);
            $mform->addRule('guide', get_string('required'), 'required', null, 'client');
            $mform->addHelpButton('guide', 'chooseguide', 'mod_selfselectadvanced');
        } else {
            $mform->addElement('static', 'guidenote', '', get_string('submitmanagerassigns', 'mod_selfselectadvanced'));
        }

        // Named button rather than add_action_buttons(): several forms
        // share the group page, and a fixed name keeps this button's id
        // the same no matter which of the others are on the page. While
        // a blocker stands, the button renders disabled (the reason is
        // shown beside it); the guide picker stays interactive so the
        // leader can still browse guides. The state machine re-checks
        // on POST, so the attribute is presentation, not protection.
        $mform->addElement(
            'submit',
            'submitguide',
            get_string('submittoguide', 'mod_selfselectadvanced'),
            !empty($this->_customdata['disabled']) ? ['disabled' => 'disabled'] : null
        );
        $mform->closeHeaderBefore('submitguide');
    }
}
