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
 * Succession nomination form (spec 6.4): the native autocomplete
 * selector scoped to the roster's eligible members; members at their
 * lead cap are excluded from the list and shown with the reason.
 *
 * Custom data: cmid, groupid, eligible (value => label),
 * excluded (list of ['userid', 'name', 'reason']).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nominate_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'g', $this->_customdata['groupid']);
        $mform->setType('g', PARAM_INT);
        $mform->addElement('hidden', 'action', 'nominate');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('select', 'stype', get_string('successiontype', 'mod_selfselectadvanced'), [
            'transfer' => get_string('successiontransfer', 'mod_selfselectadvanced'),
            'stepout' => get_string('successionstepout', 'mod_selfselectadvanced'),
        ]);
        $mform->setDefault('stype', 'transfer');
        $mform->addHelpButton('stype', 'successiontype', 'mod_selfselectadvanced');

        $mform->addElement(
            'autocomplete',
            'nominee',
            get_string('nominee', 'mod_selfselectadvanced'),
            $this->_customdata['eligible'],
            ['noselectionstring' => get_string('choosedots')]
        );
        $mform->addRule('nominee', get_string('required'), 'required', null, 'client');

        foreach ($this->_customdata['excluded'] as $excluded) {
            $mform->addElement(
                'static',
                'excluded' . $excluded['userid'],
                '',
                get_string('nomineeexcluded', 'mod_selfselectadvanced', (object) $excluded)
            );
        }

        $this->add_action_buttons(false, get_string('nominate', 'mod_selfselectadvanced'));
    }
}
