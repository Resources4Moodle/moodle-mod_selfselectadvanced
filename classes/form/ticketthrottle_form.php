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

use mod_selfselectadvanced\local\throttle;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The limit staff put on ONE requester who is flooding the queue
 * (1.20.60, maintainer instruction 2026-08-27).
 *
 * The two halves of the instruction are the two halves of this form -
 * "number of tickets per" (maxtickets every windowhours) and "wait till
 * before next ticket" (nextallowed) - plus the reason, which is not
 * optional because the requester is quoted it when a request of theirs
 * is refused. A limit somebody meets without being told why is a bug
 * report waiting to happen.
 *
 * The form validates SHAPE only (a non-negative whole number, a window
 * inside the allowed range). Whether the limit is meaningful at all,
 * whether the target is staff, and whether the person setting it holds
 * queue authority are decided by throttle::set(), which is also what an
 * external caller or a test reaches - so this class can be deleted
 * without weakening a single rule.
 *
 * Custom data: cmid, userid, targetname.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticketthrottle_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'user', $this->_customdata['userid']);
        $mform->setType('user', PARAM_INT);
        $mform->addElement('hidden', 'action', 'set');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'static',
            'targetdisplay',
            get_string('ticketthrottleheading', 'mod_selfselectadvanced'),
            $this->_customdata['targetname']
        );

        $mform->addElement(
            'text',
            'maxtickets',
            get_string('ticketthrottlemax', 'mod_selfselectadvanced'),
            ['size' => 4]
        );
        $mform->setType('maxtickets', PARAM_RAW_TRIMMED);
        $mform->setDefault('maxtickets', 0);

        $mform->addElement(
            'text',
            'windowhours',
            get_string('ticketthrottlewindow', 'mod_selfselectadvanced'),
            ['size' => 4]
        );
        $mform->setType('windowhours', PARAM_RAW_TRIMMED);
        $mform->setDefault('windowhours', 24);

        // Optional, and that is the point: the two halves stand alone or
        // together. An unchecked selector arrives as 0, which the page
        // turns back into null.
        $mform->addElement(
            'date_time_selector',
            'nextallowed',
            get_string('ticketthrottlenextallowed', 'mod_selfselectadvanced'),
            ['optional' => true]
        );

        $mform->addElement(
            'textarea',
            'reason',
            get_string('ticketthrottlereason', 'mod_selfselectadvanced'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('ticketthrottleset', 'mod_selfselectadvanced'));
    }

    /**
     * Shape only - see the class docblock.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array errors keyed by element name
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $max = (string) ($data['maxtickets'] ?? '');
        if ($max === '' || !preg_match('/^\d+$/', $max)) {
            $errors['maxtickets'] = get_string('refusalthrottlenegative', 'mod_selfselectadvanced');
        }
        $window = (string) ($data['windowhours'] ?? '');
        if (
            $window === ''
            || !preg_match('/^\d+$/', $window)
            || (int) $window < 1
            || (int) $window > throttle::MAX_WINDOW_HOURS
        ) {
            $errors['windowhours'] = get_string(
                'refusalthrottlewindow',
                'mod_selfselectadvanced',
                throttle::MAX_WINDOW_HOURS
            );
        }

        return $errors;
    }
}
