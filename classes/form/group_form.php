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

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\groups;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Group creation form: name (unique within the activity, fixed by the
 * student), title of work and brief of work via the core editor
 * (spec section 6.1).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $editing = !empty($this->_customdata['editgroup']);
        if ($editing) {
            // Audit item 21: the guide return/rework loop needs the
            // leader to revise title and brief; the NAME stays fixed
            // for life (spec) and shows read-only.
            $mform->addElement('hidden', 'g', (int) $this->_customdata['editgroup']->id);
            $mform->setType('g', PARAM_INT);
            $mform->addElement(
                'static',
                'namedisplay',
                get_string('groupname', 'mod_selfselectadvanced'),
                format_string($this->_customdata['editgroup']->name)
            );
        } else {
            $mform->addElement('text', 'name', get_string('groupname', 'mod_selfselectadvanced'), ['size' => 48]);
            $mform->setType('name', PARAM_TEXT);
            $mform->addRule('name', get_string('required'), 'required', null, 'client');
            $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
            $mform->addHelpButton('name', 'groupname', 'mod_selfselectadvanced');
        }

        $mform->addElement('text', 'title', get_string('worktitle', 'mod_selfselectadvanced'), ['size' => 64]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('editor', 'brief', get_string('workbrief', 'mod_selfselectadvanced'));
        $mform->setType('brief', PARAM_RAW);
        $mform->addRule('brief', get_string('required'), 'required', null, 'client');

        if (!empty($this->_customdata['staffmode'])) {
            // Decision 6, D6-4: staff create a DESTINATION team, so the
            // leader is somebody else. Same searching picker the move
            // form uses - a preloaded list of ten thousand students is
            // not workable and never was (strategy 1.18 B).
            $mform->addElement(
                'autocomplete',
                'leader',
                get_string('newteamleader', 'mod_selfselectadvanced'),
                $this->_customdata['selectedleader'] ?? [],
                [
                    'ajax' => 'mod_selfselectadvanced/participantselector',
                    'noselectionstring' => get_string('choosedots'),
                    'valuehtmlcallback' => function ($userid) {
                        $user = \core_user::get_user((int) $userid);

                        return $user ? fullname($user) : '';
                    },
                    'data-cmid' => $this->_customdata['cmid'],
                ]
            );
            $mform->setType('leader', PARAM_INT);
            $mform->addRule('leader', get_string('required'), 'required', null, 'client');
            $mform->addHelpButton('leader', 'newteamleader', 'mod_selfselectadvanced');
        }

        $this->add_action_buttons(true, $editing
            ? get_string('savechanges')
            : get_string('creategroup', 'mod_selfselectadvanced'));
    }

    /**
     * Attach an error message to one field and have it survive the next
     * display() of THIS form instance, without discarding the submitted
     * values - the catch-and-surface pattern move_form uses, so a
     * refused staff creation never loses the manager's input.
     *
     * @param string $element the element name to attach the error to
     * @param string $message the error message, already localised
     */
    public function set_element_error(string $element, string $message): void {
        $this->_form->setElementError($element, $message);
    }

    /**
     * Server-side validation: required fields and name uniqueness.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        /** @var activity $activity */
        $activity = $this->_customdata['activity'];

        if (empty($this->_customdata['editgroup'])) {
            if (trim($data['name'] ?? '') === '') {
                $errors['name'] = get_string('required');
            } else if (groups::name_taken($activity, $data['name'])) {
                $errors['name'] = get_string('errnametaken', 'mod_selfselectadvanced');
            }
        }
        if (trim($data['title'] ?? '') === '') {
            $errors['title'] = get_string('required');
        }
        if (!empty($this->_customdata['staffmode']) && empty($data['leader'])) {
            $errors['leader'] = get_string('required');
        }

        return $errors;
    }
}
