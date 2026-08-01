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

use mod_selfselectadvanced\local\attributes\csv_importer;
use mod_selfselectadvanced\local\attributes\depts;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Inline per-user attribute editor for the site admin page (spec 8.1).
 *
 * Custom data: userid (0 = adding: shows the core user selector),
 * username (display when editing).
 *
 * RECORDED, NOT FIXED (contact-privacy audit, 2026-08-01). The user
 * picker below is core's own AMD selector, 'core_user/form_user_selector',
 * which calls the core_user_search_identity web service. That service
 * does require_capability('moodle/user:viewalldetails',
 * context_system::instance()) and returns SITE-WIDE users with the
 * $CFG->showuseridentity fields attached. It ignores the module context,
 * the course and every capability this plugin defines, so the
 * per-activity contact-privacy switch has NO effect on it. Reaching it
 * would mean replacing a core component, which the good-neighbour
 * principle says this plugin does not do.
 *
 * Two consequences worth naming rather than rediscovering:
 *
 * - moodle/user:viewalldetails at SYSTEM context is an undocumented HARD
 *   PREREQUISITE of mod/selfselectadvanced:ingestattributes. That
 *   capability is declared with 'archetypes' => [] at CONTEXT_SYSTEM, so
 *   a site that grants it to a non-admin gets an attributes page whose
 *   only user picker throws on every keystroke;
 * - the identity fields the picker shows are the site's, not this
 *   activity's. Do not "fix" this by swapping the selector for a plugin
 *   one; it is a separate ticket with its own audience argument.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attredit_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $userid = (int) $this->_customdata['userid'];

        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'u', $userid);
        $mform->setType('u', PARAM_INT);

        if ($userid) {
            $mform->addElement('static', 'usernamedisplay', get_string('user'), $this->_customdata['username']);
        } else {
            $mform->addElement('autocomplete', 'targetuser', get_string('user'), [], [
                'ajax' => 'core_user/form_user_selector',
                'noselectionstring' => get_string('choosedots'),
            ]);
            $mform->addRule('targetuser', get_string('required'), 'required', null, 'client');
        }

        $mform->addElement('text', 'gender', get_string('attrgender', 'mod_selfselectadvanced'), ['size' => 24]);
        $mform->setType('gender', PARAM_TEXT);
        if (depts::is_configured()) {
            // Pre-defined vocabulary (spec change 2026-07-24): selects
            // fed from the department tree, no free text.
            $mform->addElement(
                'select',
                'department',
                get_string('attrdepartment', 'mod_selfselectadvanced'),
                ['' => get_string('none')] + depts::departments_menu()
            );
            $subgroups = [];
            $subgroups[get_string('none')] = ['' => get_string('none')];
            foreach (depts::subdepartments_grouped() as $department => $children) {
                $subgroups[$department] = $children;
            }
            $mform->addElement(
                'selectgroups',
                'subdepartment',
                get_string('attrsubdepartment', 'mod_selfselectadvanced'),
                $subgroups
            );
        } else {
            $mform->addElement(
                'text',
                'department',
                get_string('attrdepartment', 'mod_selfselectadvanced'),
                ['size' => 40]
            );
            $mform->addElement(
                'text',
                'subdepartment',
                get_string('attrsubdepartment', 'mod_selfselectadvanced'),
                ['size' => 40]
            );
        }
        $mform->setType('department', PARAM_TEXT);
        $mform->setType('subdepartment', PARAM_TEXT);
        $mform->addElement('text', 'mobile', get_string('attrmobile', 'mod_selfselectadvanced'), ['size' => 20]);
        $mform->setType('mobile', PARAM_TEXT);
        $programs = depts::programs_menu();
        if ($programs) {
            $mform->addElement(
                'select',
                'program',
                get_string('attrprogram', 'mod_selfselectadvanced'),
                ['' => get_string('none')] + $programs
            );
        } else {
            $mform->addElement('text', 'program', get_string('attrprogram', 'mod_selfselectadvanced'), ['size' => 30]);
        }
        $mform->setType('program', PARAM_TEXT);
        $mform->addElement(
            'text',
            'seatlocation',
            get_string('attrseatlocation', 'mod_selfselectadvanced'),
            ['size' => 40]
        );
        $mform->setType('seatlocation', PARAM_TEXT);
        $mform->addHelpButton('seatlocation', 'attrseatlocation', 'mod_selfselectadvanced');

        $this->add_action_buttons();
    }

    /**
     * Validate lengths and the mobile format.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        foreach (['gender' => 50, 'department' => 100, 'subdepartment' => 100] as $field => $max) {
            if (\core_text::strlen($data[$field] ?? '') > $max) {
                $errors[$field] = get_string('maximumchars', '', $max);
            }
        }
        $mobile = trim($data['mobile'] ?? '');
        if ($mobile !== '' && !preg_match('/^[0-9+\-\s()]{1,' . csv_importer::MOBILE_MAX . '}$/', $mobile)) {
            $errors['mobile'] = get_string('errbadmobile', 'mod_selfselectadvanced');
        }
        if (depts::is_configured()) {
            $bad = depts::validate_pair(trim($data['department'] ?? ''), trim($data['subdepartment'] ?? ''));
            if ($bad !== null) {
                $errors[$bad] = get_string('errdeptunknown', 'mod_selfselectadvanced');
            }
        }

        return $errors;
    }
}
