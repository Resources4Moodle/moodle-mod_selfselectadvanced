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

use mod_selfselectadvanced\local\tickets;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The one form kb.php renders for all three of its writing surfaces
 * (1.20.45): a resolved ticket's pre-filled draft (title/question/
 * answer from the ticket, staff EDITS before saving - kb.php sets the
 * ticketid hidden field), an existing entry's edit (kb.php sets entryid
 * and pre-fills every field from the row), and a brand new direct-add
 * article (neither hidden field carries a nonzero value).
 *
 * Text fields are plain textareas + PARAM_RAW, format hardcoded to
 * FORMAT_MOODLE on save - the same convention the ticket thread's own
 * resolution/question/reply fields already use (ticket.php never reads
 * a form-supplied format for those either), rather than a richtext
 * editor this release does not need.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class kb_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'ticketid', (int) ($this->_customdata['ticketid'] ?? 0));
        $mform->setType('ticketid', PARAM_INT);
        $mform->addElement('hidden', 'entryid', (int) ($this->_customdata['entryid'] ?? 0));
        $mform->setType('entryid', PARAM_INT);

        $mform->addElement('text', 'title', get_string('kbtitlelabel', 'mod_selfselectadvanced'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');

        $typeoptions = ['' => get_string('kbtypegeneral', 'mod_selfselectadvanced')];
        foreach (tickets::known_types() as $type) {
            $typeoptions[$type] = get_string('tickettype' . $type, 'mod_selfselectadvanced');
        }
        $mform->addElement('select', 'tickettype', get_string('kbtypelabel', 'mod_selfselectadvanced'), $typeoptions);
        $mform->setType('tickettype', PARAM_ALPHA);

        $mform->addElement(
            'textarea',
            'question',
            get_string('kbquestionlabel', 'mod_selfselectadvanced'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('question', PARAM_RAW);
        $mform->addRule('question', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'answer',
            get_string('kbanswerlabel', 'mod_selfselectadvanced'),
            ['rows' => 6, 'cols' => 60]
        );
        $mform->setType('answer', PARAM_RAW);
        $mform->addRule('answer', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'text',
            'keywords',
            get_string('kbkeywordslabel', 'mod_selfselectadvanced'),
            ['size' => 60]
        );
        $mform->setType('keywords', PARAM_TEXT);

        $mform->addElement('advcheckbox', 'published', get_string('kbpublishedlabel', 'mod_selfselectadvanced'));
        $mform->setDefault('published', 1);

        $this->add_action_buttons(true, get_string('kbsave', 'mod_selfselectadvanced'));
    }
}
