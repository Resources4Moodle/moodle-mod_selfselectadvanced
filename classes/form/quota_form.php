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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Quota rule form (spec 8.2, 4.7): value rules pick their value from
 * the INGESTED attribute data via a grouped select ("Gender: Female"),
 * distinct rules take a dimension and a minimum distinct count.
 *
 * Custom data: cmid, ruleid.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quota_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'rule', $this->_customdata['ruleid']);
        $mform->setType('rule', PARAM_INT);

        $mform->addElement('select', 'rtype', get_string('quotartype', 'mod_selfselectadvanced'), [
            'value' => get_string('quotartypevalue', 'mod_selfselectadvanced'),
            'distinct' => get_string('quotartypedistinct', 'mod_selfselectadvanced'),
        ]);
        $mform->addHelpButton('rtype', 'quotartype', 'mod_selfselectadvanced');

        // Value picker fed by the ingested data (spec 4.7), grouped by dimension.
        $groups = [];
        foreach (manager::DIMENSIONS as $dimension) {
            $options = [];
            foreach (manager::distinct_values($dimension) as $value) {
                $options[$dimension . '|' . $value] = $value;
            }
            if ($options) {
                $groups[get_string('attr' . $dimension, 'mod_selfselectadvanced')] = $options;
            }
        }
        // Decision 86: an empty picker used to fail the save with a bare
        // "Required", which tells a teacher nothing about WHY. The vocabulary
        // is institutional and stays under ingest control - the ruling was
        // explicit that free text here would let one teacher invent values
        // competing with centrally imported data - so the fix is to name the
        // dependency instead of removing it. The empty case is stated with the
        // dimensions that are missing, and the route to fix it is shown only
        // to somebody who can actually take it.
        if (!$groups) {
            $missing = array_map(
                static fn(string $d): string => get_string('attr' . $d, 'mod_selfselectadvanced'),
                manager::DIMENSIONS
            );
            $mform->addElement(
                'static',
                'dimensionvalueempty',
                get_string('quotavalue', 'mod_selfselectadvanced'),
                get_string('quotanovalues', 'mod_selfselectadvanced', implode(', ', $missing))
                    . (has_capability('mod/selfselectadvanced:ingestattributes', \context_system::instance())
                        ? ' ' . \html_writer::link(
                            new \moodle_url('/mod/selfselectadvanced/attributes.php'),
                            get_string('quotanovalueslink', 'mod_selfselectadvanced')
                        )
                        : '')
            );
        }
        $mform->addElement(
            'selectgroups',
            'dimensionvalue',
            get_string('quotavalue', 'mod_selfselectadvanced'),
            $groups
        );
        $mform->hideIf('dimensionvalue', 'rtype', 'eq', 'distinct');
        $mform->addHelpButton('dimensionvalue', 'quotavalue', 'mod_selfselectadvanced');

        $dimensions = [];
        foreach (manager::DIMENSIONS as $dimension) {
            $dimensions[$dimension] = get_string('attr' . $dimension, 'mod_selfselectadvanced');
        }
        $mform->addElement('select', 'dimension', get_string('quotadimension', 'mod_selfselectadvanced'), $dimensions);
        $mform->hideIf('dimension', 'rtype', 'eq', 'value');

        $mform->addElement('text', 'mincount', get_string('quotamin', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('mincount', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('mincount', 'quotamin', 'mod_selfselectadvanced');

        $mform->addElement('text', 'maxcount', get_string('quotamax', 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType('maxcount', PARAM_RAW_TRIMMED);
        $mform->hideIf('maxcount', 'rtype', 'eq', 'distinct');
        $mform->addHelpButton('maxcount', 'quotamax', 'mod_selfselectadvanced');

        $this->add_action_buttons();
    }

    /**
     * Cross-field validation per rule type.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $min = trim((string) ($data['mincount'] ?? ''));
        $max = trim((string) ($data['maxcount'] ?? ''));
        foreach (['mincount' => $min, 'maxcount' => $max] as $field => $value) {
            if ($value !== '' && (!ctype_digit($value) || (int) $value < 0)) {
                $errors[$field] = get_string('errnonnegative', 'mod_selfselectadvanced');
            }
        }

        if (($data['rtype'] ?? '') === 'distinct') {
            if ($min === '' || (int) $min < 1) {
                $errors['mincount'] = get_string('errpositiveint', 'mod_selfselectadvanced');
            }
        } else {
            if (empty($data['dimensionvalue'])) {
                $errors['dimensionvalue'] = get_string('required');
            }
            if ($min === '' && $max === '') {
                $errors['mincount'] = get_string('errquotanobound', 'mod_selfselectadvanced');
            }
            if (
                $min !== '' && $max !== '' && !isset($errors['mincount']) && !isset($errors['maxcount'])
                && (int) $min > (int) $max
            ) {
                $errors['mincount'] = get_string('errminsizegtmax', 'mod_selfselectadvanced');
            }
        }

        return $errors;
    }
}
