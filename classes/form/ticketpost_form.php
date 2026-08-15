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
 * One of the ticket thread's three text-plus-optional-attachment forms
 * (1.20.44 part 2): the claimant's request-info question, the
 * requester's info-reply, or the claimant's resolution note. Decline is
 * deliberately NOT one of these (spec names exactly these three) - it
 * stays the hand-rolled textarea it already was, and a declined ticket
 * carries no attachment.
 *
 * Three coexisting instances never collide: unlike group.php's per-type
 * ticketfile_form, THIS form's field name already varies by kind
 * (question/reply/resolution), which is what a viewer with claimant
 * forms visible actually sees rendered together on one page - no
 * further qualification needed for unique DOM ids.
 *
 * Custom data: field (the element name: question, reply or resolution),
 * label (lang string key for the textarea), buttonlabel (lang string
 * key for the submit button, or a resolved string for the guidecap
 * grant's amount-carrying variant), fileoptions (filemanager options),
 * showpublishfaq (bool, 1.20.45: adds the "Publish as FAQ" checkbox -
 * the RESOLVE instance only, never request-info, reply or the guidecap
 * grant variant, which ticket_page.php's own call site decides).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticketpost_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $field = $this->_customdata['field'];
        $attachmentsfield = $field . 'attachments';

        $mform->addElement('hidden', 't', $this->_customdata['ticketid']);
        $mform->setType('t', PARAM_INT);
        $mform->addElement('hidden', 'action', $this->_customdata['action']);
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'textarea',
            $field,
            $this->_customdata['label'],
            ['rows' => 3]
        );
        $mform->setType($field, PARAM_RAW);
        if (!empty($this->_customdata['required'])) {
            $mform->addRule($field, get_string('required'), 'required', null, 'client');
        }

        // The label is qualified by the text field's OWN label, not left
        // as the bare, generic "Attachments" string: up to two of these
        // forms render on the thread page at once (request-info AND
        // resolve/grant together, when the claimant has just taken up a
        // ticket) - two elements both simply labelled "Attachments"
        // would be a real accessibility defect (an ambiguous `for`
        // target) as well as an ambiguous Behat "I upload ... to
        // ... filemanager" locator.
        $mform->addElement(
            'filemanager',
            $attachmentsfield,
            get_string('ticketattachmentsfor', 'mod_selfselectadvanced', $this->_customdata['label']),
            null,
            $this->_customdata['fileoptions']
        );

        // 1.20.45: the resolve form's own "Publish as FAQ" checkbox -
        // publishing is a SECOND deliberate step (maintainer's own
        // words), never a side effect of resolving, so this only flags
        // the intent; ticket.php's resolve arm is what actually redirects
        // to kb.php's pre-filled draft form once close() has succeeded.
        if (!empty($this->_customdata['showpublishfaq'])) {
            $mform->addElement(
                'advcheckbox',
                'publishfaq',
                get_string('kbpublishfaqcheckbox', 'mod_selfselectadvanced')
            );
            $mform->setType('publishfaq', PARAM_BOOL);
        }

        $this->add_action_buttons(false, $this->_customdata['buttonlabel']);
    }
}
