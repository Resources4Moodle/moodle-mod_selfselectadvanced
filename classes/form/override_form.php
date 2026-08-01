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
 * Override add/edit form with the B5 per-mode field sets:
 * user  -> dates + maxlead + maxmembership;
 * group -> dates + minsize + maxsize + quotaexempt + penaltywaived;
 * guide -> maxguided only.
 *
 * Custom data: cmid, mode, overrideid, targetmodule (the AMD
 * transport its picker searches through), targetid and targetlabel
 * (the target already chosen, when editing one).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_form extends \moodleform {
    /**
     * Define the form elements.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $mode = $this->_customdata['mode'];
        $overrideid = (int) $this->_customdata['overrideid'];

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'mode', $mode);
        $mform->setType('mode', PARAM_ALPHA);
        $mform->addElement('hidden', 'override', $overrideid);
        $mform->setType('override', PARAM_INT);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);

        if ($overrideid) {
            $mform->addElement(
                'static',
                'targetdisplay',
                get_string('overridetarget' . $mode, 'mod_selfselectadvanced'),
                $this->_customdata['targetlabel']
            );
            $mform->addElement('hidden', 'target', 0);
            $mform->setType('target', PARAM_INT);
        } else {
            // Searchable, never a list (strategy 1.18 B). This element
            // used to be handed every possible target up front - every
            // team, every guide, or every enrolled student - and merely
            // filtered them in the browser, which still means rendering
            // ten thousand options before one can be hidden.
            $mform->addElement(
                'autocomplete',
                'target',
                get_string('overridetarget' . $mode, 'mod_selfselectadvanced'),
                [],
                [
                    'ajax' => $this->_customdata['targetmodule'],
                    'noselectionstring' => get_string('choosedots'),
                    'placeholder' => get_string(
                        $mode === 'group' ? 'grouppickerplaceholder' : 'guidepickerplaceholder',
                        'mod_selfselectadvanced'
                    ),
                    'casesensitive' => false,
                    'data-cmid' => $this->_customdata['cmid'],
                ]
            );
            $mform->setType('target', PARAM_INT);
            $mform->addRule('target', get_string('required'), 'required', null, 'client');
        }

        if ($mode === 'user' || $mode === 'group') {
            foreach (['timeopen', 'timedue', 'timecutoff'] as $field) {
                $mform->addElement(
                    'date_time_selector',
                    $field,
                    get_string($field, 'mod_selfselectadvanced'),
                    ['optional' => true]
                );
            }
        }
        if ($mode === 'user') {
            $this->optional_int('maxlead', 'maxlead');
            $this->optional_int('maxmembership', 'maxmembership');
        }
        if ($mode === 'group') {
            $this->optional_int('minsize', 'minsize');
            $this->optional_int('maxsize', 'maxsize');
            $mform->addElement('advcheckbox', 'quotaexempt', get_string('overridequotaexempt', 'mod_selfselectadvanced'));
            $mform->addElement(
                'advcheckbox',
                'penaltywaived',
                get_string('overridepenaltywaived', 'mod_selfselectadvanced')
            );
        }
        if ($mode === 'guide') {
            $this->optional_int('maxguided', 'maxguided');
            $mform = $this->_form;
            $mform->addElement(
                'advcheckbox',
                'guidehidden',
                get_string('guidehidden', 'mod_selfselectadvanced')
            );
            $mform->addHelpButton('guidehidden', 'guidehidden', 'mod_selfselectadvanced');
        }

        $this->add_action_buttons();
    }

    /**
     * An optional positive-int override field (empty = no override).
     *
     * @param string $name field name
     * @param string $stringkey label lang key
     */
    private function optional_int(string $name, string $stringkey): void {
        $mform = $this->_form;
        $mform->addElement('text', $name, get_string($stringkey, 'mod_selfselectadvanced'), ['size' => 4]);
        $mform->setType($name, PARAM_RAW_TRIMMED);
        $mform->addHelpButton($name, 'overrideemptyfield', 'mod_selfselectadvanced');
    }

    /**
     * Validate optional ints and cross-field sanity (4A.7 invariants
     * applied to the fields set in this override).
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array field => error message
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (empty($this->_customdata['overrideid']) && empty($data['target'])) {
            $errors['target'] = get_string('required');
        }

        foreach (['maxlead', 'maxmembership', 'maxguided', 'minsize', 'maxsize'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            // A guide capped at zero is a real setting ("always full"),
            // so zero is accepted there while every other limit still
            // needs at least one.
            $floor = ($field === 'maxguided' && $this->_customdata['mode'] === 'guide') ? 0 : 1;
            if (!ctype_digit($value) || (int) $value < $floor) {
                $errors[$field] = get_string(
                    $floor === 0 ? 'errnonnegativeint' : 'errpositiveint',
                    'mod_selfselectadvanced'
                );
            }
        }
        $min = trim((string) ($data['minsize'] ?? ''));
        $max = trim((string) ($data['maxsize'] ?? ''));
        if ($min !== '' && $max !== '' && (int) $min > (int) $max) {
            $errors['minsize'] = get_string('errminsizegtmax', 'mod_selfselectadvanced');
        }
        $lead = trim((string) ($data['maxlead'] ?? ''));
        $member = trim((string) ($data['maxmembership'] ?? ''));
        if ($lead !== '' && $member !== '' && (int) $lead > (int) $member) {
            $errors['maxlead'] = get_string('errleadgtmembership', 'mod_selfselectadvanced');
        }

        $open = empty($data['timeopen']) ? 0 : (int) $data['timeopen'];
        $due = empty($data['timedue']) ? 0 : (int) $data['timedue'];
        $cutoff = empty($data['timecutoff']) ? 0 : (int) $data['timecutoff'];
        if ($open && $due && $open > $due) {
            $errors['timedue'] = get_string('errdatesorder', 'mod_selfselectadvanced');
        }
        if (($due && $cutoff && $due > $cutoff) || ($open && $cutoff && $open > $cutoff)) {
            $errors['timecutoff'] = get_string('errdatesorder', 'mod_selfselectadvanced');
        }

        return array_merge($errors, $this->tuple_errors($data, $errors));
    }

    /**
     * The MERGED effective tuple, checked as a courtesy (finding-9).
     *
     * The checks above only ever see the fields of THIS submission, so
     * they cannot see the tuple a per-field fallthrough actually
     * produces: a lone timedue merged over the activity's cutoff, or a
     * user's window merged under a group override's. This runs the same
     * shared checker store::save() runs, so an admin is told which two
     * effective values conflict and where each came from, inline,
     * instead of the row silently parking pending. The seam still
     * enforces independently - the form is courtesy, the seam is law.
     *
     * @param array $data submitted data
     * @param array $errors the errors found so far
     * @return array field => error message, for fields not already flagged
     */
    private function tuple_errors(array $data, array $errors): array {
        $mode = (string) $this->_customdata['mode'];
        if ($mode !== 'user' && $mode !== 'group') {
            // A guide row's maxguided has no tuple partner; the checker
            // returns nothing for it anyway.
            return [];
        }
        $activity = $this->_customdata['activity'] ?? null;
        if (!$activity instanceof \mod_selfselectadvanced\activity) {
            return [];
        }
        $targetid = empty($this->_customdata['overrideid'])
            ? (int) ($data['target'] ?? 0)
            : (int) $this->_customdata['targetid'];
        if (!$targetid) {
            // The 'required' error has already fired.
            return [];
        }

        $fields = \mod_selfselectadvanced\local\override\store::FIELDS[$mode];
        $candidate = (object) array_merge(
            array_fill_keys($fields, null),
            \mod_selfselectadvanced\local\override\store::normalise($mode, $data),
            [
                'scope' => $mode,
                'userid' => $mode === 'user' ? $targetid : null,
                'groupid' => $mode === 'group' ? $targetid : null,
            ]
        );

        $tuple = [];
        $violations = \mod_selfselectadvanced\local\override\consistency::violations($activity, $candidate);
        foreach ($violations as $violation) {
            // Attach it to the side the submitter can actually change.
            $field = $violation->firstsource === \mod_selfselectadvanced\local\override\consistency::SOURCE_THIS
                ? $violation->firstfield
                : $violation->secondfield;
            // The same-submission checks above speak about a PAIR and
            // write to whichever of the two fields they choose: open >
            // due lands on `timedue`, due > cutoff and open > cutoff
            // both land on `timecutoff`. Testing only the field this
            // violation picked therefore missed every date duplicate
            // and the admin was told the same thing twice on two
            // fields - the numeric pairs deduped only because both
            // messages happen to land on `minsize`. Both ends of the
            // pair are tested against the errors found so far.
            if (
                isset($errors[$violation->firstfield])
                || isset($errors[$violation->secondfield])
                || isset($tuple[$field])
            ) {
                continue;
            }
            $tuple[$field] = \mod_selfselectadvanced\local\override\consistency::describe($activity, $violation);
        }

        return $tuple;
    }
}
