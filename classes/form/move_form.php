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
 * Custom data: cmid, selectedstudent, selectedsuccessor, groups, canbypass.
 *
 * UX audit fix: a failed stage() is surfaced back onto this same form
 * instance as a field error (see set_element_error()) rather than
 * fataling, so a hasty or invalid submission never loses the manager's
 * input. The hidden 'replaces' field carries the id of a dead-end move
 * being edited-and-restaged from moves.php, so the caller can cancel
 * it once the replacement stages successfully.
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

        // Carries the id of a dead-end move being edited-and-restaged
        // (moves.php's per-row link), 0 when staging fresh.
        $mform->addElement('hidden', 'replaces', 0);
        $mform->setType('replaces', PARAM_INT);

        // AJAX selector: with thousands of enrolled students a
        // preloaded dropdown is not workable; membership and
        // enrolment are re-validated server-side by the moves engine.
        $mform->addElement(
            'autocomplete',
            'student',
            get_string('movestudent', 'mod_selfselectadvanced'),
            $this->_customdata['selectedstudent'] ?? [],
            [
                // This activity's own participants, authorised by the
                // manage capability here: core's selector demands a
                // SYSTEM-context capability a course coordinator does
                // not hold, which locked them out of this form.
                'ajax' => 'mod_selfselectadvanced/participantselector',
                'noselectionstring' => get_string('choosedots'),
                'valuehtmlcallback' => function ($userid) {
                    $user = \core_user::get_user((int) $userid);

                    return $user ? fullname($user) : '';
                },
                'data-cmid' => $this->_customdata['cmid'],
            ]
        );
        $mform->addRule('student', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'select',
            'source',
            get_string('movefrom', 'mod_selfselectadvanced'),
            [0 => get_string('movenosource', 'mod_selfselectadvanced')] + $this->_customdata['groups']
        );
        $mform->addHelpButton('source', 'movefrom', 'mod_selfselectadvanced');

        // An explicit empty choice (UX audit): without one the select
        // always carries a real value (the alphabetically first group),
        // so a hasty submit silently staged a move nobody chose. The
        // required rule can only bite once a truly-empty state exists.
        $mform->addElement(
            'select',
            'target',
            get_string('moveto', 'mod_selfselectadvanced'),
            ['' => get_string('choosedots')] + $this->_customdata['groups']
        );
        $mform->addRule('target', get_string('required'), 'required', null, 'client');

        $mform->addElement('advcheckbox', 'makeleader', get_string('movemakeleader', 'mod_selfselectadvanced'));

        // Deliberate leadership change: replacing an existing target
        // leader must be an explicit decision, never a side effect —
        // it shifts succession, penalty dates (P16) and gradebook
        // attribution to a different person.
        $mform->addElement(
            'advcheckbox',
            'replaceleader',
            get_string('movereplaceleader', 'mod_selfselectadvanced')
        );
        $mform->addHelpButton('replaceleader', 'movereplaceleader', 'mod_selfselectadvanced');
        $mform->disabledIf('replaceleader', 'makeleader', 'notchecked');

        $mform->addElement(
            'autocomplete',
            'successor',
            get_string('movesuccessor', 'mod_selfselectadvanced'),
            $this->_customdata['selectedsuccessor'] ?? [],
            [
                // This activity's own participants, authorised by the
                // manage capability here: core's selector demands a
                // SYSTEM-context capability a course coordinator does
                // not hold, which locked them out of this form.
                'ajax' => 'mod_selfselectadvanced/participantselector',
                'noselectionstring' => get_string('choosedots'),
                'valuehtmlcallback' => function ($userid) {
                    $user = \core_user::get_user((int) $userid);

                    return $user ? fullname($user) : '';
                },
                'data-cmid' => $this->_customdata['cmid'],
            ]
        );
        $mform->addHelpButton('successor', 'movesuccessor', 'mod_selfselectadvanced');

        if ($this->_customdata['canbypass']) {
            // Each bypass is named in words so ticking one is a
            // deliberate act, not a guess at a code.
            $bypass = [];
            foreach (['L1', 'L2', 'L3', 'L4', 'QUOTA'] as $code) {
                $bypass[] = $mform->createElement(
                    'advcheckbox',
                    $code,
                    '',
                    get_string('movebypass' . strtolower($code), 'mod_selfselectadvanced')
                );
            }
            $mform->addGroup(
                $bypass,
                'bypassgroup',
                get_string('movebypasslabel', 'mod_selfselectadvanced'),
                '<br>',
                true
            );
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

        // The target's own required rule already flags a blank choice;
        // guard against also treating "blank target" as "same as source".
        if (
            !empty($data['source']) && $data['target'] !== ''
            && (int) $data['source'] === (int) $data['target']
        ) {
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

    /**
     * Attach an error message to one field and have it survive the next
     * display() of THIS form instance, without discarding the submitted
     * values. Used by moveedit.php to surface a moves-engine refusal
     * (moodle_exception from stage()) as a field error instead of a
     * fatal, per the catch-and-surface pattern used on pickteam.php.
     *
     * @param string $element the element name to attach the error to
     * @param string $message the error message, already localised
     */
    public function set_element_error(string $element, string $message): void {
        $this->_form->setElementError($element, $message);
    }
}
