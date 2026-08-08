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

namespace mod_selfselectadvanced;

use mod_selfselectadvanced\local\rules\settings_validator;

/**
 * Tests for the section 4A.7 settings validation.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\rules\settings_validator
 */
final class settings_validator_test extends \basic_testcase {
    /**
     * A fully valid settings array.
     *
     * @return array
     */
    private function valid(): array {
        return [
            'minsize' => 2,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 10,
            'grade' => 100,
            'penaltyperday' => 2.5,
            'inviteexpiry' => 7,
            'timeopen' => 1000,
            'timedue' => 2000,
            'timecutoff' => 3000,
        ];
    }

    /**
     * Valid data produces no errors.
     */
    public function test_valid_settings_pass(): void {
        $this->assertSame([], settings_validator::validate($this->valid()));
    }

    /**
     * Decision 75: "Manager assigns the guide" means the manager
     * assigns the guide. Expressions of interest hand that choice to
     * the leader - a guide offers, the leader accepts, and the group
     * arrives at review already carrying the guide it picked - so the
     * pair is refused at the form rather than left to contradict the
     * setting's own help text in production.
     *
     * MUTATION CAUGHT (run): deleting the guidemode arm from
     * settings_validator::validate() fails the first assertion here.
     */
    public function test_manager_mode_and_expressions_of_interest_are_incompatible(): void {
        $both = $this->valid() + ['guidemode' => 1, 'eoienabled' => 1];
        $this->assertSame(
            ['eoienabled' => 'errmanagermodeeoi'],
            settings_validator::validate($both),
            'a teacher must not be able to save a setting that means the opposite of what it says'
        );

        // Each alone is a coherent activity and stays legal.
        $this->assertSame([], settings_validator::validate($this->valid() + ['guidemode' => 1, 'eoienabled' => 0]));
        $this->assertSame([], settings_validator::validate($this->valid() + ['guidemode' => 0, 'eoienabled' => 1]));

        // And leader-selects mode with neither is untouched.
        $this->assertSame([], settings_validator::validate($this->valid()));
    }

    /**
     * Boundary: equal values are legal (minsize = maxsize, maxlead = maxmembership).
     */
    public function test_equal_boundaries_pass(): void {
        $data = $this->valid();
        $data['minsize'] = 6;
        $data['maxsize'] = 6;
        $data['maxlead'] = 2;
        $data['maxmembership'] = 2;
        $this->assertSame([], settings_validator::validate($data));
    }

    /**
     * Each of the five limits refuses zero and negative values: no
     * unlimited sentinels (spec section 4A.7).
     *
     * @dataProvider limit_fields
     * @param string $field the limit field
     */
    public function test_limits_require_positive_integers(string $field): void {
        foreach ([0, -1] as $bad) {
            $data = $this->valid();
            $data[$field] = $bad;
            $errors = settings_validator::validate($data);
            $this->assertArrayHasKey($field, $errors, "$field=$bad must be refused");
            $this->assertSame('errpositiveint', $errors[$field]);
        }
    }

    /**
     * The five limit fields.
     *
     * @return array[]
     */
    public static function limit_fields(): array {
        return [
            'minsize' => ['minsize'],
            'maxsize' => ['maxsize'],
            'maxlead' => ['maxlead'],
            'maxmembership' => ['maxmembership'],
            'maxguided' => ['maxguided'],
        ];
    }

    /**
     * minsize > maxsize is refused.
     */
    public function test_minsize_above_maxsize_refused(): void {
        $data = $this->valid();
        $data['minsize'] = 7;
        $data['maxsize'] = 6;
        $this->assertSame('errminsizegtmax', settings_validator::validate($data)['minsize'] ?? null);
    }

    /**
     * maxlead > maxmembership is refused.
     */
    public function test_maxlead_above_membership_refused(): void {
        $data = $this->valid();
        $data['maxlead'] = 3;
        $data['maxmembership'] = 2;
        $this->assertSame('errleadgtmembership', settings_validator::validate($data)['maxlead'] ?? null);
    }

    /**
     * Negative grade, penalty and expiry are refused.
     */
    public function test_negatives_refused(): void {
        $data = $this->valid();
        $data['grade'] = -1;
        $data['penaltyperday'] = -0.5;
        $data['inviteexpiry'] = -2;
        $errors = settings_validator::validate($data);
        $this->assertSame('errnonnegative', $errors['grade'] ?? null);
        $this->assertSame('errnonnegative', $errors['penaltyperday'] ?? null);
        $this->assertSame('errnonnegative', $errors['inviteexpiry'] ?? null);
    }

    /**
     * Date ordering: open <= due <= cutoff among the set dates; unset
     * dates (0) do not constrain.
     */
    public function test_date_ordering(): void {
        $data = $this->valid();
        $data['timeopen'] = 3000;
        $data['timedue'] = 2000;
        $this->assertSame('errdatesorder', settings_validator::validate($data)['timedue'] ?? null);

        $data = $this->valid();
        $data['timedue'] = 4000;
        $this->assertSame('errdatesorder', settings_validator::validate($data)['timecutoff'] ?? null);

        $data = $this->valid();
        $data['timeopen'] = 4000;
        $data['timedue'] = 0;
        $this->assertSame('errdatesorder', settings_validator::validate($data)['timecutoff'] ?? null);

        $data = $this->valid();
        $data['timeopen'] = 0;
        $data['timedue'] = 0;
        $data['timecutoff'] = 0;
        $this->assertSame([], settings_validator::validate($data));
    }

    /**
     * Students-approach mode (strategy 1.16 A) rejects every setting
     * that lets a guide advertise: EOI, volunteering, guide-first mode.
     * Off together, the switch passes.
     */
    public function test_studentapproach_forces_guide_modes_off(): void {
        $data = $this->valid();
        $data['studentapproach'] = 1;
        $data['eoienabled'] = 1;
        $data['guidevolunteer'] = 1;
        $data['guidemode'] = 1;
        $errors = settings_validator::validate($data);
        $this->assertSame('errstudentapproacheoi', $errors['eoienabled'] ?? null);
        $this->assertSame('errstudentapproachvolunteer', $errors['guidevolunteer'] ?? null);
        $this->assertSame('errstudentapproachguidemode', $errors['guidemode'] ?? null);

        $data['eoienabled'] = 0;
        $data['guidevolunteer'] = 0;
        $data['guidemode'] = 0;
        $this->assertSame([], settings_validator::validate($data));

        // The switch off leaves the guide modes free.
        $data['studentapproach'] = 0;
        $data['eoienabled'] = 1;
        $data['guidevolunteer'] = 1;
        $this->assertSame([], settings_validator::validate($data));
    }

    /**
     * Strategy 1.17 A1: the project-id template must be able to mint a
     * distinct id for every team, and may only use placeholders the
     * plugin knows how to fill.
     */
    public function test_uidformat_must_be_usable(): void {
        $data = $this->valid();

        $data['uidformat'] = '{prefix}-{course}-{number}';
        $this->assertSame([], settings_validator::validate($data));

        $data['uidformat'] = '{prefix}/{number}';
        $this->assertSame([], settings_validator::validate($data));

        // Free text around the number is fine.
        $data['uidformat'] = 'Project {number}';
        $this->assertSame([], settings_validator::validate($data));

        // Empty means the standard shape.
        $data['uidformat'] = '';
        $this->assertSame([], settings_validator::validate($data));

        // Without the number every team would share one id.
        $data['uidformat'] = '{prefix}-{course}';
        $this->assertSame('erruidformatnumber', settings_validator::validate($data)['uidformat'] ?? null);

        // A placeholder the plugin cannot fill would be printed raw.
        $data['uidformat'] = '{prefix}-{faculty}-{number}';
        $this->assertSame('erruidformatunknown', settings_validator::validate($data)['uidformat'] ?? null);
    }
}
