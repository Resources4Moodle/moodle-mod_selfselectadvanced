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

/**
 * Instance settings form for mod_selfselectadvanced.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Instance settings form, enforcing the validation of spec section 4A.7.
 */
class mod_selfselectadvanced_mod_form extends moodleform_mod {
    /**
     * Define the form elements.
     */
    public function definition(): void {
        $mform = $this->_form;

        // General.
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        $mform->addElement('text', 'grade', get_string('grade', 'mod_selfselectadvanced'), ['size' => 6]);
        $mform->setType('grade', PARAM_INT);
        $mform->setDefault('grade', 100);
        $mform->addHelpButton('grade', 'grade', 'mod_selfselectadvanced');

        // Group size (L1, L2).
        $mform->addElement('header', 'groupsizeheading', get_string('groupsizeheading', 'mod_selfselectadvanced'));
        $mform->addElement('text', 'minsize', get_string('minsize', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('minsize', PARAM_INT);
        $mform->setDefault('minsize', 1);
        $mform->addHelpButton('minsize', 'minsize', 'mod_selfselectadvanced');
        $mform->addElement('text', 'maxsize', get_string('maxsize', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('maxsize', PARAM_INT);
        $mform->setDefault('maxsize', 6);
        $mform->addHelpButton('maxsize', 'maxsize', 'mod_selfselectadvanced');

        // Student limits (L3, L4).
        $mform->addElement('header', 'studentlimitsheading', get_string('studentlimitsheading', 'mod_selfselectadvanced'));
        $mform->addElement('text', 'maxlead', get_string('maxlead', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('maxlead', PARAM_INT);
        $mform->setDefault('maxlead', 1);
        $mform->addHelpButton('maxlead', 'maxlead', 'mod_selfselectadvanced');
        $mform->addElement('text', 'maxmembership', get_string('maxmembership', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('maxmembership', PARAM_INT);
        $mform->setDefault('maxmembership', 1);
        $mform->addHelpButton('maxmembership', 'maxmembership', 'mod_selfselectadvanced');

        // Guides (L5).
        $mform->addElement('header', 'guidesheading', get_string('guidesheading', 'mod_selfselectadvanced'));
        $mform->addElement('select', 'guidemode', get_string('guidemode', 'mod_selfselectadvanced'), [
            0 => get_string('guidemodeleader', 'mod_selfselectadvanced'),
            1 => get_string('guidemodemanager', 'mod_selfselectadvanced'),
        ]);
        $mform->setDefault('guidemode', 0);
        $mform->addHelpButton('guidemode', 'guidemode', 'mod_selfselectadvanced');
        $mform->addElement('text', 'maxguided', get_string('maxguided', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('maxguided', PARAM_INT);
        $mform->setDefault('maxguided', 10);
        $mform->addHelpButton('maxguided', 'maxguided', 'mod_selfselectadvanced');

        // Formation window.
        $mform->addElement('header', 'formationwindow', get_string('formationwindow', 'mod_selfselectadvanced'));
        $mform->addElement(
            'date_time_selector',
            'timeopen',
            get_string('timeopen', 'mod_selfselectadvanced'),
            ['optional' => true]
        );
        $mform->addHelpButton('timeopen', 'timeopen', 'mod_selfselectadvanced');
        $mform->addElement(
            'date_time_selector',
            'timedue',
            get_string('timedue', 'mod_selfselectadvanced'),
            ['optional' => true]
        );
        $mform->addHelpButton('timedue', 'timedue', 'mod_selfselectadvanced');
        $mform->addElement(
            'date_time_selector',
            'timecutoff',
            get_string('timecutoff', 'mod_selfselectadvanced'),
            ['optional' => true]
        );
        $mform->addHelpButton('timecutoff', 'timecutoff', 'mod_selfselectadvanced');

        // Late penalty.
        $mform->addElement('header', 'penaltyheading', get_string('penaltyheading', 'mod_selfselectadvanced'));
        $mform->addElement('select', 'penaltytype', get_string('penaltytype', 'mod_selfselectadvanced'), [
            0 => get_string('penaltytypepercent', 'mod_selfselectadvanced'),
            1 => get_string('penaltytypepoints', 'mod_selfselectadvanced'),
        ]);
        $mform->setDefault('penaltytype', 0);
        $mform->addHelpButton('penaltytype', 'penaltytype', 'mod_selfselectadvanced');
        $mform->addElement('text', 'penaltyperday', get_string('penaltyperday', 'mod_selfselectadvanced'), ['size' => 6]);
        $mform->setType('penaltyperday', PARAM_FLOAT);
        $mform->setDefault('penaltyperday', 0);
        $mform->addHelpButton('penaltyperday', 'penaltyperday', 'mod_selfselectadvanced');

        // Invitations.
        $mform->addElement('text', 'inviteexpiry', get_string('inviteexpiry', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('inviteexpiry', PARAM_INT);
        $mform->setDefault('inviteexpiry', 0);
        $mform->addHelpButton('inviteexpiry', 'inviteexpiry', 'mod_selfselectadvanced');

        // Auto-grouping.
        $mform->addElement('advcheckbox', 'autogroup', get_string('autogroup', 'mod_selfselectadvanced'));
        $mform->setDefault('autogroup', 0);
        $mform->addHelpButton('autogroup', 'autogroup', 'mod_selfselectadvanced');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Enforce the numeric-limit validation of spec section 4A.7.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // Integers of at least 1; no unlimited/zero sentinel values.
        foreach (['minsize', 'maxsize', 'maxlead', 'maxmembership', 'maxguided'] as $field) {
            if (empty($data[$field]) || (int) $data[$field] < 1) {
                $errors[$field] = get_string('errpositiveint', 'mod_selfselectadvanced');
            }
        }
        if (
            empty($errors['minsize']) && empty($errors['maxsize'])
                && (int) $data['minsize'] > (int) $data['maxsize']
        ) {
            $errors['minsize'] = get_string('errminsizegtmax', 'mod_selfselectadvanced');
        }
        if (
            empty($errors['maxlead']) && empty($errors['maxmembership'])
                && (int) $data['maxlead'] > (int) $data['maxmembership']
        ) {
            $errors['maxlead'] = get_string('errleadgtmembership', 'mod_selfselectadvanced');
        }

        if ((int) $data['grade'] < 0) {
            $errors['grade'] = get_string('errnonnegative', 'mod_selfselectadvanced');
        }
        if ((float) $data['penaltyperday'] < 0) {
            $errors['penaltyperday'] = get_string('errnonnegative', 'mod_selfselectadvanced');
        }
        if ((int) $data['inviteexpiry'] < 0) {
            $errors['inviteexpiry'] = get_string('errnonnegative', 'mod_selfselectadvanced');
        }

        // Dates in order among those enabled: open <= due <= cutoff.
        $open = empty($data['timeopen']) ? 0 : (int) $data['timeopen'];
        $due = empty($data['timedue']) ? 0 : (int) $data['timedue'];
        $cutoff = empty($data['timecutoff']) ? 0 : (int) $data['timecutoff'];
        if ($open && $due && $open > $due) {
            $errors['timedue'] = get_string('errdatesorder', 'mod_selfselectadvanced');
        }
        if ($due && $cutoff && $due > $cutoff) {
            $errors['timecutoff'] = get_string('errdatesorder', 'mod_selfselectadvanced');
        }
        if ($open && $cutoff && $open > $cutoff) {
            $errors['timecutoff'] = get_string('errdatesorder', 'mod_selfselectadvanced');
        }

        return $errors;
    }
}
