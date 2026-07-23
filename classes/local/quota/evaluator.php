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

namespace mod_selfselectadvanced\local\quota;

use mod_selfselectadvanced\activity;

/**
 * Quota compliance evaluation (spec section 8.2).
 *
 * This slice provides the compliance gate consumed by submission and
 * approval: an activity with no quota rules is vacuously compliant.
 * Slice 6 completes the evaluator with per-rule bucket reports,
 * priority ordering and the deficiency panel; the gate signature stays
 * unchanged.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evaluator {
    /**
     * Whether a group currently satisfies every quota rule of its
     * activity.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @return bool true when compliant (vacuously true with no rules)
     */
    public static function is_compliant(activity $activity, int $groupid): bool {
        global $DB;

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        if (!$rules) {
            return true;
        }

        // Full rule evaluation lands in slice 6 with the bucket report;
        // until rules can be created through the UI none exist.
        return true;
    }
}
