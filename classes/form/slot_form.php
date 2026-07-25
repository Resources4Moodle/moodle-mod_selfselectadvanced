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

use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\quota\slots;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Add one composition-template slot (audit item 26: a real moodleform
 * replacing the hand-rolled markup, with the same value picker the
 * classic quota rules use so typos cannot silently match nothing).
 *
 * Custom data: cmid.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slot_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'slotadd');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'slotid', 0);
        $mform->setType('slotid', PARAM_INT);

        $mform->addElement(
            'text',
            'mincount',
            get_string('slotmincount', 'mod_selfselectadvanced'),
            ['size' => 4]
        );
        $mform->setType('mincount', PARAM_INT);
        $mform->setDefault('mincount', 1);
        $mform->addRule('mincount', get_string('required'), 'required', null, 'client');

        $dims = [];
        foreach (slots::DIMENSIONS as $dim) {
            $dims[$dim] = get_string('attr' . $dim, 'mod_selfselectadvanced');
        }
        $mform->addElement('select', 'dimension', get_string('quotadimension', 'mod_selfselectadvanced'), $dims);

        $mform->addElement('select', 'matchtype', get_string('slotmatchtype', 'mod_selfselectadvanced'), [
            'value' => get_string('slotmatchvalue', 'mod_selfselectadvanced'),
            'distinct' => get_string('slotmatchdistinct', 'mod_selfselectadvanced'),
        ]);

        // Value picker fed from the ingested data per dimension, with
        // "any one value" as the blank option.
        $values = ['' => get_string('slotvaluehint', 'mod_selfselectadvanced')];
        foreach (slots::DIMENSIONS as $dim) {
            foreach (manager::distinct_values($dim) as $value) {
                $values[$dim . '|' . $value] = get_string('attr' . $dim, 'mod_selfselectadvanced') . ': ' . $value;
            }
        }
        $mform->addElement('select', 'valuepick', get_string('slotvalue', 'mod_selfselectadvanced'), $values);
        $mform->hideIf('valuepick', 'matchtype', 'eq', 'distinct');

        $mform->addElement('advcheckbox', 'allowoverlap', get_string('slotallowoverlap', 'mod_selfselectadvanced'));

        $this->add_action_buttons(false, empty($this->_customdata['editing'])
            ? get_string('slotadd', 'mod_selfselectadvanced')
            : get_string('savechanges'));
    }

    /**
     * Validate the count and that a picked value matches the dimension.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ((int) ($data['mincount'] ?? 0) < 1 || (int) $data['mincount'] > 50) {
            $errors['mincount'] = get_string('errslotcount', 'mod_selfselectadvanced');
        }
        if (($data['matchtype'] ?? '') === 'value' && ($data['valuepick'] ?? '') !== '') {
            [$dim] = explode('|', $data['valuepick'], 2);
            if ($dim !== ($data['dimension'] ?? '')) {
                $errors['valuepick'] = get_string('errslotvaluedim', 'mod_selfselectadvanced');
            }
        }

        return $errors;
    }
}
