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

namespace mod_selfselectadvanced\local\rules;

use mod_selfselectadvanced\local\groups;

/**
 * Pure validation of the instance settings (spec section 4A.7),
 * delegated to by mod_form and unit-tested directly.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_validator {
    /**
     * Validate submitted settings data.
     *
     * Enforces: minsize >= 1, minsize <= maxsize, maxlead >= 1,
     * maxlead <= maxmembership, maxguided >= 1, integers with no
     * unlimited/zero sentinels, non-negative grade, penalty and expiry,
     * and date ordering timeopen <= timedue <= timecutoff among the
     * dates that are set.
     *
     * @param array $data form data (field => value)
     * @return array field => lang string key for each error
     */
    public static function validate(array $data): array {
        $errors = [];

        foreach (['minsize', 'maxsize', 'maxlead', 'maxmembership', 'maxguided'] as $field) {
            if (empty($data[$field]) || (int) $data[$field] < 1) {
                $errors[$field] = 'errpositiveint';
            }
        }
        if (
            !isset($errors['minsize']) && !isset($errors['maxsize'])
            && (int) $data['minsize'] > (int) $data['maxsize']
        ) {
            $errors['minsize'] = 'errminsizegtmax';
        }
        if (
            !isset($errors['maxlead']) && !isset($errors['maxmembership'])
            && (int) $data['maxlead'] > (int) $data['maxmembership']
        ) {
            $errors['maxlead'] = 'errleadgtmembership';
        }

        if (isset($data['grade']) && (int) $data['grade'] < 0) {
            $errors['grade'] = 'errnonnegative';
        }
        if (isset($data['penaltyperday']) && (float) $data['penaltyperday'] < 0) {
            $errors['penaltyperday'] = 'errnonnegative';
        }
        if (isset($data['inviteexpiry']) && (int) $data['inviteexpiry'] < 0) {
            $errors['inviteexpiry'] = 'errnonnegative';
        }

        // ONE CANONICAL DOMAIN FOR THE FIVE SENTINEL FIELDS. Each of these
        // already treats 0 as a documented sentinel at runtime - contacts.php
        // disables approaches below 1, expire_due() and the expiry task want
        // a positive interval, eoi::express() caps only when the value is
        // above 0, and the gradebook penalises only a positive minimum. A
        // NEGATIVE value therefore behaved as a second, undocumented spelling
        // of the same sentinel: it saved cleanly, changed nothing, and made
        // the stored configuration unreadable.
        //
        // Deliberately NOT folded into the positive loop above. Zero is valid
        // for all five and means something specific in each; errpositiveint
        // would reject it and change behaviour on existing sites.
        foreach (['contactmax', 'joinexpiry', 'eoimax', 'eoigroupmax', 'minmembership'] as $field) {
            if (isset($data[$field]) && (int) $data[$field] < 0) {
                $errors[$field] = 'errnonnegative';
            }
        }

        $open = empty($data['timeopen']) ? 0 : (int) $data['timeopen'];
        $due = empty($data['timedue']) ? 0 : (int) $data['timedue'];
        $cutoff = empty($data['timecutoff']) ? 0 : (int) $data['timecutoff'];
        if ($open && $due && $open > $due) {
            $errors['timedue'] = 'errdatesorder';
        }
        if ($due && $cutoff && $due > $cutoff) {
            $errors['timecutoff'] = 'errdatesorder';
        }
        if ($open && $cutoff && $open > $cutoff && !isset($errors['timecutoff'])) {
            $errors['timecutoff'] = 'errdatesorder';
        }

        // The relationship is judged only once the lower bound has passed, so
        // a single field never collects two competing errors and the teacher
        // is told the more basic thing first.
        if (
            !isset($errors['minmembership'])
            && (int) ($data['minmembership'] ?? 0) > (int) ($data['maxmembership'] ?? PHP_INT_MAX)
        ) {
            $errors['minmembership'] = 'errminmembership';
        }
        foreach (['defaulterpenalty', 'incompletepenalty'] as $pfield) {
            if ((float) ($data[$pfield] ?? 0) < 0) {
                $errors[$pfield] = 'errnegativepenalty';
            }
        }

        // Student-approach mode (strategy 1.16 A): guides advertise
        // nothing, so the modes that let them - volunteering their
        // capacity, browsing listed teams, manager assignment - cannot
        // be on at the same time. The form must describe a coherent
        // activity, not rely on runtime refusals alone.
        if (!empty($data['studentapproach'])) {
            if (!empty($data['eoienabled'])) {
                $errors['eoienabled'] = 'errstudentapproacheoi';
            }
            if (!empty($data['guidevolunteer'])) {
                $errors['guidevolunteer'] = 'errstudentapproachvolunteer';
            }
            if ((int) ($data['guidemode'] ?? 0) !== 0) {
                $errors['guidemode'] = 'errstudentapproachguidemode';
            }
        }

        // THE PUBLIC PROMISE WINS (decision 75). "Manager assigns the
        // guide" tells the teacher, in its own help text, that groups
        // arrive without a guide and a manager allocates one. Expressions
        // of interest quietly contradict that: a guide expresses interest,
        // the leader accepts, eoi::respond() writes group.guideid there
        // and then, and submit gives a preassigned guide precedence over
        // the mode - so the group goes straight to the guide the LEADER
        // chose and never reaches the manager's queue. Both features are
        // coherent alone; together the setting means the opposite of what
        // it says. Refused at the form, so a teacher cannot configure a
        // contradiction and discover it from behaviour weeks later.
        // Only when student-approach mode is OFF. With it on, the block
        // above has already refused both halves of this pair for a
        // reason that explains more, and writing a second message onto
        // the same field would replace the better explanation with a
        // narrower one - which is exactly what it did before this guard
        // existed, as the student-approach test noticed immediately.
        if (
            empty($data['studentapproach'])
            && (int) ($data['guidemode'] ?? 0) === 1
            && !empty($data['eoienabled'])
        ) {
            $errors['eoienabled'] = 'errmanagermodeeoi';
        }

        // The project-id template must be able to mint a distinct id
        // for every team, and must not carry a placeholder the plugin
        // does not know how to fill (strategy 1.17 A1).
        $template = trim((string) ($data['uidformat'] ?? ''));
        if ($template !== '') {
            if (strpos($template, '{number}') === false) {
                $errors['uidformat'] = 'erruidformatnumber';
            } else {
                preg_match_all('/\{[a-z]*\}/', $template, $found);
                $unknown = array_diff($found[0], groups::uid_placeholders());
                if ($unknown) {
                    $errors['uidformat'] = 'erruidformatunknown';
                }
            }
        }

        return $errors;
    }
}
