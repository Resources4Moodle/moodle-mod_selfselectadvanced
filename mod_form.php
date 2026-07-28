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
     * Resolve a section heading, falling back to an already-shipped
     * string when a newer heading key has not landed in the language
     * file yet. This is a coordination seam for the parallel EOI build
     * (UX audit HIGH regroup of the former overloaded penalty section):
     * once the preferred key exists, this starts returning it with no
     * further code change.
     *
     * @param string $key the preferred string identifier
     * @param string $fallback a string identifier already shipped
     * @return string the resolved heading label
     */
    private function section_label(string $key, string $fallback): string {
        if (get_string_manager()->string_exists($key, 'mod_selfselectadvanced')) {
            return get_string($key, 'mod_selfselectadvanced');
        }
        return get_string($fallback, 'mod_selfselectadvanced');
    }

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

        // Audit item 28: the core grading section (grade element,
        // category, grade-to-pass) instead of a bare text field. The
        // penalty/award model is arithmetic on points, so scales are
        // refused in validation.
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);

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
        $mform->addElement('text', 'uidprefix', get_string('uidprefix', 'mod_selfselectadvanced'), ['size' => 8]);
        $mform->setType('uidprefix', PARAM_ALPHANUM);
        $mform->setDefault('uidprefix', 'SSA');
        $mform->addHelpButton('uidprefix', 'uidprefix', 'mod_selfselectadvanced');
        $mform->addElement('text', 'uiddigits', get_string('uiddigits', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('uiddigits', PARAM_INT);
        $mform->setDefault('uiddigits', \mod_selfselectadvanced\local\groups::UID_DIGITS_DEFAULT);
        $mform->addHelpButton('uiddigits', 'uiddigits', 'mod_selfselectadvanced');

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
        $mform->addElement(
            'duration',
            'guidewindow',
            get_string('guidewindow', 'mod_selfselectadvanced'),
            ['optional' => true, 'defaultunit' => DAYSECS]
        );
        $mform->setDefault('guidewindow', 0);
        $mform->addHelpButton('guidewindow', 'guidewindow', 'mod_selfselectadvanced');
        $mform->addElement(
            'advcheckbox',
            'guideautoapprove',
            get_string('guideautoapprove', 'mod_selfselectadvanced')
        );
        $mform->setDefault('guideautoapprove', 0);
        $mform->addHelpButton('guideautoapprove', 'guideautoapprove', 'mod_selfselectadvanced');
        $mform->disabledIf('guideautoapprove', 'guidewindow[enabled]', 'notchecked');
        $mform->addElement(
            'advcheckbox',
            'guidevolunteer',
            get_string('guidevolunteer', 'mod_selfselectadvanced')
        );
        $mform->setDefault('guidevolunteer', 0);
        $mform->addHelpButton('guidevolunteer', 'guidevolunteer', 'mod_selfselectadvanced');

        // Team listing and guide interest ("pick that team"): a leader may
        // list their forming team, guides express interest, and the
        // leader always chooses (spec EOI).
        $mform->addElement('header', 'eoisettings', get_string('eoisettings', 'mod_selfselectadvanced'));
        $mform->addElement(
            'advcheckbox',
            'eoienabled',
            get_string('eoienabled', 'mod_selfselectadvanced')
        );
        $mform->setDefault('eoienabled', 0);
        $mform->addHelpButton('eoienabled', 'eoienabled', 'mod_selfselectadvanced');
        $mform->addElement(
            'duration',
            'eoiwindow',
            get_string('eoiwindow', 'mod_selfselectadvanced'),
            ['optional' => true, 'defaultunit' => DAYSECS]
        );
        $mform->setDefault('eoiwindow', 0);
        $mform->addHelpButton('eoiwindow', 'eoiwindow', 'mod_selfselectadvanced');
        $mform->disabledIf('eoiwindow', 'eoienabled', 'notchecked');
        $mform->addElement('text', 'eoimax', get_string('eoimax', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('eoimax', PARAM_INT);
        $mform->setDefault('eoimax', 3);
        $mform->addHelpButton('eoimax', 'eoimax', 'mod_selfselectadvanced');
        $mform->disabledIf('eoimax', 'eoienabled', 'notchecked');
        $mform->addElement(
            'advcheckbox',
            'eoisequential',
            get_string('eoisequential', 'mod_selfselectadvanced')
        );
        $mform->setDefault('eoisequential', 0);
        $mform->addHelpButton('eoisequential', 'eoisequential', 'mod_selfselectadvanced');
        $mform->disabledIf('eoisequential', 'eoienabled', 'notchecked');
        $mform->addElement(
            'advcheckbox',
            'eoipeers',
            get_string('eoipeers', 'mod_selfselectadvanced')
        );
        $mform->setDefault('eoipeers', 0);
        $mform->addHelpButton('eoipeers', 'eoipeers', 'mod_selfselectadvanced');
        $mform->disabledIf('eoipeers', 'eoienabled', 'notchecked');
        $mform->addElement('text', 'eoigroupmax', get_string('eoigroupmax', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('eoigroupmax', PARAM_INT);
        $mform->setDefault('eoigroupmax', 0);
        $mform->addHelpButton('eoigroupmax', 'eoigroupmax', 'mod_selfselectadvanced');
        $mform->disabledIf('eoigroupmax', 'eoienabled', 'notchecked');

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

        // Penalties. UX audit HIGH: this used to be one "Late penalty"
        // section carrying every setting below down to leader share;
        // each now sits in its own properly named section instead.
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
        $mform->addElement(
            'header',
            'invitationsheading',
            $this->section_label('invitationsheading', 'sendinvitations')
        );
        $mform->addElement('text', 'inviteexpiry', get_string('inviteexpiry', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('inviteexpiry', PARAM_INT);
        $mform->setDefault('inviteexpiry', 0);
        $mform->addHelpButton('inviteexpiry', 'inviteexpiry', 'mod_selfselectadvanced');

        // Auto-grouping.
        $mform->addElement(
            'header',
            'autogroupheading',
            $this->section_label('autogroupheading', 'autogroup')
        );
        $mform->addElement('select', 'autogroup', get_string('autogroup', 'mod_selfselectadvanced'), [
            0 => get_string('autogroupoff', 'mod_selfselectadvanced'),
            1 => get_string('autogroupmanual', 'mod_selfselectadvanced'),
            2 => get_string('autogroupauto', 'mod_selfselectadvanced'),
        ]);
        $mform->setDefault('autogroup', 0);
        $mform->addHelpButton('autogroup', 'autogroup', 'mod_selfselectadvanced');

        // Proposals.
        $mform->addElement(
            'header',
            'proposalsheading',
            $this->section_label('proposalsheading', 'proposal')
        );
        $mform->addElement(
            'advcheckbox',
            'proposalrequired',
            get_string('proposalrequired', 'mod_selfselectadvanced')
        );
        $mform->setDefault('proposalrequired', 0);
        $mform->addHelpButton('proposalrequired', 'proposalrequired', 'mod_selfselectadvanced');

        // Memberships.
        $mform->addElement(
            'header',
            'membershipsheading',
            $this->section_label('membershipsheading', 'maxmembership')
        );
        $mform->addElement('text', 'minmembership', get_string('minmembership', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('minmembership', PARAM_INT);
        $mform->setDefault('minmembership', 0);
        $mform->addHelpButton('minmembership', 'minmembership', 'mod_selfselectadvanced');
        $mform->addElement(
            'text',
            'defaulterpenalty',
            get_string('defaulterpenalty', 'mod_selfselectadvanced'),
            ['size' => 6]
        );
        $mform->setType('defaulterpenalty', PARAM_FLOAT);
        $mform->setDefault('defaulterpenalty', 0);
        $mform->addHelpButton('defaulterpenalty', 'defaulterpenalty', 'mod_selfselectadvanced');

        $mform->addElement(
            'text',
            'incompletepenalty',
            get_string('incompletepenalty', 'mod_selfselectadvanced'),
            ['size' => 6]
        );
        $mform->setType('incompletepenalty', PARAM_FLOAT);
        $mform->setDefault('incompletepenalty', 0);
        $mform->addHelpButton('incompletepenalty', 'incompletepenalty', 'mod_selfselectadvanced');
        $shareoptions = [];
        for ($pct = 50; $pct <= 90; $pct += 10) {
            $shareoptions[$pct] = $pct . '%';
        }
        $mform->addElement('select', 'leadershare', get_string('leadershare', 'mod_selfselectadvanced'), $shareoptions);
        $mform->setDefault('leadershare', 60);
        $mform->addHelpButton('leadershare', 'leadershare', 'mod_selfselectadvanced');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Enforce the numeric-limit validation of spec section 4A.7,
     * delegated to the unit-tested settings validator.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $rules = \mod_selfselectadvanced\local\rules\settings_validator::validate($data);
        foreach ($rules as $field => $stringkey) {
            $errors[$field] = get_string($stringkey, 'mod_selfselectadvanced');
        }

        if ((int) ($data['grade'] ?? 0) < 0) {
            $errors['grade'] = get_string('errpointsonly', 'mod_selfselectadvanced');
        }

        if (!preg_match('/^[A-Za-z0-9]{2,8}$/', trim((string) ($data['uidprefix'] ?? '')))) {
            $errors['uidprefix'] = get_string('erruidprefix', 'mod_selfselectadvanced');
        }

        $digits = (int) ($data['uiddigits'] ?? 0);
        if (
            $digits < \mod_selfselectadvanced\local\groups::UID_DIGITS_MIN
            || $digits > \mod_selfselectadvanced\local\groups::UID_DIGITS_MAX
        ) {
            $errors['uiddigits'] = get_string('erruiddigits', 'mod_selfselectadvanced', (object) [
                'min' => \mod_selfselectadvanced\local\groups::UID_DIGITS_MIN,
                'max' => \mod_selfselectadvanced\local\groups::UID_DIGITS_MAX,
            ]);
        }

        return $errors;
    }

    /**
     * The plugin id prefix is stored upper-case, exactly as it will
     * stamp new groups.
     *
     * @param stdClass $data the submitted form data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (isset($data->uidprefix)) {
            $data->uidprefix = strtoupper(trim($data->uidprefix));
        }
    }
}
