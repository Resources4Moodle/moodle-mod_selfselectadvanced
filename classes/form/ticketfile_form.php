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
 * File a ticket (1.20.44 part 2): converts group.php's per-type ticket
 * section and filehelp.php's own form from hand-rolled HTML to a real
 * moodleform, purely to get file_save_draft_area_files() draft-area
 * handling for the new optional attachment (spec: "do not hand-roll
 * draft handling") - the request/response shape is otherwise unchanged.
 *
 * group.php renders up to SIX of these (one per eligible ticket type,
 * exactly as before this slice) on one page at once. Every field name
 * is suffixed with the ticket type for exactly that reason: MoodleQuickForm
 * derives an element's DOM id from its NAME alone, so six unqualified
 * 'reason'/'attachments' elements would all render id="id_reason" /
 * id="id_attachments" - Behat's "I set the field" (and any assistive
 * technology) resolves a label's `for` attribute to the FIRST element
 * carrying that id, not the one beside the label a person actually read.
 * filehelp.php renders exactly one instance (tickettype is always
 * 'help' there), where the suffix is a no-op beyond a slightly unusual
 * field name.
 *
 * Custom data: tickettype, fileoptions (filemanager options array),
 * disclaimerack (int, forwarded on the hidden field a disclaimer gate
 * screen already required before this form ever renders).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticketfile_form extends \moodleform {
    /**
     * The element name a submitted instance's reason field carries.
     *
     * @param string $tickettype tickets::TYPE_*
     * @return string
     */
    public static function reason_field(string $tickettype): string {
        return 'reason_' . $tickettype;
    }

    /**
     * The element name a submitted instance's attachments field carries.
     *
     * @param string $tickettype tickets::TYPE_*
     * @return string
     */
    public static function attachments_field(string $tickettype): string {
        return 'attachments_' . $tickettype;
    }

    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $tickettype = $this->_customdata['tickettype'];
        $reasonfield = self::reason_field($tickettype);
        $attachmentsfield = self::attachments_field($tickettype);

        $mform->addElement('hidden', 'tickettype', $tickettype);
        $mform->setType('tickettype', PARAM_ALPHA);
        $mform->addElement('hidden', 'disclaimerack', (int) ($this->_customdata['disclaimerack'] ?? 0));
        $mform->setType('disclaimerack', PARAM_BOOL);

        $reasonlabel = get_string('ticketfile' . $tickettype, 'mod_selfselectadvanced');
        // 1.20.52: a real editor, not a textarea. maxfiles is explicitly 0 -
        // embedded images would need their own draft area, pluginfile
        // route, backup and privacy plumbing, the exact cost 1.20.41
        // declined, and the filemanager below already covers anything a
        // person needs to attach. The element posts an ARRAY
        // (['text' => ..., 'format' => ...]), not a scalar, so every
        // reader of $reasonfield (group.php, filehelp.php) stores the
        // format the editor actually returns rather than a hardcoded
        // constant.
        $mform->addElement('editor', $reasonfield, $reasonlabel, null, ['maxfiles' => 0]);
        $mform->setType($reasonfield, PARAM_RAW);
        $mform->addRule($reasonfield, get_string('required'), 'required', null, 'client');
        // 1.20.52: the shared placeholder that used to sit under every
        // type ("Why is this change needed?") is gone - core's own
        // editor_textarea template never prints a placeholder attribute
        // at all, and it was the reason a student could not tell the
        // leadership-help box from the general-help box apart on
        // group.php, where both can render at once. Each type now gets
        // its own one-line help through the plugin's existing
        // addHelpButton idiom instead: leaderchange and help get a
        // dedicated string apiece, and the remaining four types (still
        // genuinely about "why is this change needed") keep the shared
        // ticketreasonhint identifier.
        $helpidentifier = match ($tickettype) {
            tickets::TYPE_LEADERCHANGE => 'ticketfileleaderchange',
            tickets::TYPE_HELP => 'ticketfilehelp',
            default => 'ticketreasonhint',
        };
        $mform->addHelpButton($reasonfield, $helpidentifier, 'mod_selfselectadvanced');

        // Qualified by type, the same reason the reason/attachments
        // FIELD NAMES are (this class's own docblock): up to six of
        // these forms can render on group.php at once, and a bare
        // "Attachments" repeated six times would be exactly the
        // ambiguous-label defect ticketpost_form.php's own filemanager
        // label was fixed for.
        $mform->addElement(
            'filemanager',
            $attachmentsfield,
            get_string('ticketattachmentsfor', 'mod_selfselectadvanced', $reasonlabel),
            null,
            $this->_customdata['fileoptions']
        );

        $this->add_action_buttons(false, get_string('ticketfilebutton', 'mod_selfselectadvanced'));
    }
}
