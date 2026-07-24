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

namespace mod_selfselectadvanced\local\penalty;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\override\resolver;
use stdClass;

/**
 * Pure penalty arithmetic (spec 11, decisions D5/A12, review item B2).
 *
 * Each group's lateness is assessed independently: days between its
 * EFFECTIVE timedue and its timeapproved, times the penalty rate,
 * bounded by the effective timecutoff. Effective dates come from the
 * resolver's assessment chain (P16: group override > the LEADER's user
 * override > activity), so a group formed within an overridden window
 * incurs no penalty by arithmetic; the explicit waiver flag zeroes
 * independently. The full arithmetic is recorded in `basis` for audit
 * and export.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calculator {
    /**
     * Compute the penalty of one approved group.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row (timeapproved required)
     * @param resolver $resolver the override resolver
     * @return stdClass dayslate, penaltyvalue, waived, waivereason, basis
     */
    public static function compute(activity $activity, stdClass $group, resolver $resolver): stdClass {
        if (empty($group->timeapproved)) {
            throw new \coding_exception('Penalties are computed for approved groups only.');
        }
        $settings = $activity->settings();
        $dates = $resolver->assessment_dates((int) $group->id);

        // Raw-settings comparison, only to RECORD that a date override
        // is what zeroed the penalty (behavioural guarantee, spec 10).
        $rawdays = self::days_late(
            (int) $group->timeapproved,
            (int) $settings->timedue,
            (int) $settings->timecutoff
        );
        $days = self::days_late(
            (int) $group->timeapproved,
            $dates->timeopen ? $dates->timedue : $dates->timedue,
            $dates->timecutoff
        );

        $rate = (float) $settings->penaltyperday;
        $perday = ((int) $settings->penaltytype === 0)
            ? ((float) $settings->grade * $rate / 100.0)
            : $rate;
        $value = $days * $perday;

        $waived = false;
        $waivereason = null;
        $waiverflag = $resolver->is_penalty_waived((int) $group->id);
        if ($waiverflag->enabled) {
            $waived = true;
            $waivereason = 'waiver';
            $value = 0.0;
            $days = 0;
        } else if ($days === 0 && $rawdays > 0 && $dates->is_overridden()) {
            // The overridden window absorbed the lateness (spec 10).
            $waived = true;
            $waivereason = 'dateoverride';
        }

        return (object) [
            'dayslate' => $days,
            'penaltyvalue' => round($value, 5),
            'waived' => $waived,
            'waivereason' => $waivereason,
            'basis' => json_encode([
                'timeapproved' => (int) $group->timeapproved,
                'effectivedue' => $dates->timedue,
                'effectivecutoff' => $dates->timecutoff,
                'datesources' => $dates->sources,
                'penaltytype' => (int) $settings->penaltytype,
                'penaltyperday' => $rate,
                'grade' => (int) $settings->grade,
                'perday' => $perday,
                'rawdayslate' => $rawdays,
            ]),
        ];
    }

    /**
     * Whole days late between the effective due date and the approval,
     * bounded by the effective cutoff (spec 11). Unset dates (0) mean
     * no deadline and no bound respectively.
     *
     * @param int $approved approval timestamp
     * @param int $due effective penalty-free deadline (0 = none)
     * @param int $cutoff effective hard stop (0 = none)
     * @return int days late, never negative
     */
    public static function days_late(int $approved, int $due, int $cutoff): int {
        if (!$due) {
            return 0;
        }
        $assessed = ($cutoff && $approved > $cutoff) ? $cutoff : $approved;
        if ($assessed <= $due) {
            return 0;
        }

        return (int) ceil(($assessed - $due) / DAYSECS);
    }
}
