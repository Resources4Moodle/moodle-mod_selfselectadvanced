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
 * A student asking to join another team (strategy 1.19 B).
 *
 * Custom data: cmid, sources (confirmed group rows keyed by id),
 * headroom (bool: the student's cap has room for one more team).
 *
 * The source select exists because multi-membership is supported: a
 * student in two teams is asking two questions at once - which team do
 * I join, and which do I leave - and only they can answer the second.
 * It lists team NAMES and nothing else: this page has never shown a
 * student's contact details and the picker must not be where that
 * starts.
 *
 * The team is chosen through the searchable picker every other team
 * control uses - a student is choosing among the same fifteen hundred
 * teams the staff are, and a dropdown would serve them no better.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class joinrequest_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'ask');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'autocomplete',
            'target',
            get_string('jointarget', 'mod_selfselectadvanced'),
            [],
            [
                'ajax' => 'mod_selfselectadvanced/groupselector',
                'noselectionstring' => get_string('choosedots'),
                'placeholder' => get_string('grouppickerplaceholder', 'mod_selfselectadvanced'),
                'casesensitive' => false,
                'data-cmid' => $this->_customdata['cmid'],
                // Ask the server to judge each team against this
                // student: a team they do not fit stays listed, with
                // the reason, and a team holding a seat for them says
                // which seat it is.
                'data-fit' => '1',
            ]
        );
        $mform->setType('target', PARAM_INT);
        $mform->addRule('target', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('target', 'jointarget', 'mod_selfselectadvanced');

        $mform->addElement('text', 'reason', get_string('jointreason', 'mod_selfselectadvanced'), ['size' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reason', 'jointreason', 'mod_selfselectadvanced');

        $sources = $this->_customdata['sources'] ?? [];
        $headroom = !empty($this->_customdata['headroom']);

        if (count($sources) === 1 && !$headroom) {
            // One team and no room for another: the answer is not in
            // doubt, so it is shown rather than asked.
            $only = reset($sources);
            $mform->addElement(
                'static',
                'sourceonly',
                get_string('joinsource', 'mod_selfselectadvanced'),
                format_string($only->name)
            );
            $mform->addElement('hidden', 'source', (int) $only->id);
            $mform->setType('source', PARAM_INT);
        } else if ($sources) {
            // A handful of options at most - bounded by the membership
            // cap - so a plain select, not the autocomplete the target
            // needs for fifteen hundred teams.
            $options = [0 => get_string('choosedots')];
            foreach ($sources as $group) {
                $options[(int) $group->id] = format_string($group->name);
            }
            if ($headroom) {
                $options[\mod_selfselectadvanced\local\joinrequests::SOURCE_ADDITIONAL] =
                    get_string('joinsourcekeep', 'mod_selfselectadvanced');
            }
            $mform->addElement('select', 'source', get_string('joinsource', 'mod_selfselectadvanced'), $options);
            $mform->setType('source', PARAM_INT);
            $mform->setDefault('source', 0);
            $mform->addHelpButton('source', 'joinsource', 'mod_selfselectadvanced');
        }

        $mform->addElement('submit', 'askbutton', get_string('joinsend', 'mod_selfselectadvanced'));
    }

    /**
     * Refuse the placeholder: the student has to say which team they leave.
     *
     * The service refuses it too (refusaljoinsourcerequired) - this is
     * the courteous version, on the field, before the redirect.
     *
     * @param array $data submitted values
     * @param array $files submitted files
     * @return array errors keyed by element name
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!empty($this->_customdata['sources']) && (int) ($data['source'] ?? 0) === 0) {
            $errors['source'] = get_string('joinsourcerequired', 'mod_selfselectadvanced');
        }

        return $errors;
    }
}
