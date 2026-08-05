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
 * Filter form for the core-group mirror status report.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coresync_filter_form extends \moodleform {
    /**
     * Define the form.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $mform->_attributes['method'] = 'get';

        $mform->addElement('hidden', 'id', (int) $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'q', get_string('coresyncsearch', 'mod_selfselectadvanced'));
        $mform->setType('q', PARAM_TEXT);
        $mform->setDefault('q', $this->_customdata['q'] ?? '');

        $mform->addElement('select', 'state', get_string('coresyncstatefilter', 'mod_selfselectadvanced'), [
            '' => get_string('all'),
            \mod_selfselectadvanced\local\state::FORMING => get_string('stateforming', 'mod_selfselectadvanced'),
            \mod_selfselectadvanced\local\state::PENDING_GUIDE => get_string('statependingguide', 'mod_selfselectadvanced'),
            \mod_selfselectadvanced\local\state::FIRM => get_string('statefirm', 'mod_selfselectadvanced'),
            \mod_selfselectadvanced\local\state::FROZEN => get_string('statefrozen', 'mod_selfselectadvanced'),
        ]);
        $mform->setDefault('state', $this->_customdata['state'] ?? '');

        $mform->addElement('select', 'status', get_string('coresyncstatusfilter', 'mod_selfselectadvanced'), [
            '' => get_string('all'),
            'nomirror' => 'nomirror',
            'synced' => 'synced',
            'failed' => 'failed',
        ]);
        $mform->setDefault('status', $this->_customdata['status'] ?? '');

        $mform->addElement('submit', 'submitbutton', get_string('filter'));
    }
}
