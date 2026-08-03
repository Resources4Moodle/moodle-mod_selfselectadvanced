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
 * Invitation form: the native core autocomplete element fed by the
 * plugin's candidate search (C10, U3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invite_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $cmid = $this->_customdata['cmid'];
        $groupid = $this->_customdata['groupid'];

        $mform->addElement('hidden', 'id', $cmid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'g', $groupid);
        $mform->setType('g', PARAM_INT);
        $mform->addElement('hidden', 'action', 'invite');
        $mform->setType('action', PARAM_ALPHA);

        $options = [
            'multiple' => true,
            'ajax' => 'mod_selfselectadvanced/candidateselector',
            'noselectionstring' => get_string('invitenoselection', 'mod_selfselectadvanced'),
            // A placeholder must not promise a match the query will not
            // make, and there is now exactly one true answer for every
            // viewer: NAMES ONLY. candidates::search() matches no
            // address in either state of the contact-privacy switch
            // (1.20.1 wave 3D), so the string is unconditional. It used
            // to be chosen from an 'emailmatch' customdata flag that
            // group.php computed from the switch and the viewer's
            // capabilities - a condition that outlived the query it was
            // meant to describe, and that made the legacy activity
            // advertise "Search by name or email" over a names-only
            // query. There is no per-viewer or per-activity case left:
            // if this ever needs a condition again, the query has to
            // grow one first.
            'placeholder' => get_string('inviteplaceholdername', 'mod_selfselectadvanced'),
            'valuehtmlcallback' => function ($userid) {
                $user = \core_user::get_user((int) $userid);

                return $user ? fullname($user) : '';
            },
            'data-cmid' => $cmid,
            'data-groupid' => $groupid,
        ];
        $mform->addElement(
            'autocomplete',
            'invitees',
            get_string('invitemembers', 'mod_selfselectadvanced'),
            [],
            $options
        );
        $mform->setType('invitees', PARAM_INT);

        // Named button rather than add_action_buttons(): several forms
        // share the group page, and a fixed name keeps this button's id
        // the same no matter which of the others are on the page.
        $mform->addElement('submit', 'submitinvite', get_string('sendinvitations', 'mod_selfselectadvanced'));
        $mform->closeHeaderBefore('submitinvite');
    }
}
