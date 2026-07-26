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

namespace mod_selfselectadvanced\local;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\override\resolver;
use stdClass;

/**
 * Guide volunteering (1.7.0): a guide's own declared capacity to take on
 * groups, one row per (activity, guide). Consulted exclusively by
 * override\resolver::effective_maxguided() - never read anywhere else -
 * so every existing enforcement point and picker inherits the feature
 * automatically. An active manager guide-scope maxguided override always
 * takes precedence over the volunteered number (the resolver enforces
 * this, not this class).
 *
 * No named lock is used: a single-row upsert per guide carries no
 * cross-group invariant to protect.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class volunteering {
    /**
     * The guide's volunteer row, if any.
     *
     * @param activity $activity the activity
     * @param int $userid the guide
     * @return stdClass|null the row, or null when the guide has never volunteered
     */
    public static function get(activity $activity, int $userid): ?stdClass {
        global $DB;

        return $DB->get_record('selfselectadvanced_volunteer', [
            'activityid' => $activity->id(),
            'userid' => $userid,
        ]) ?: null;
    }

    /**
     * Declare or update the guide's volunteered capacity (upsert); a
     * capacity of 0 withdraws the guide without deleting their history
     * row.
     *
     * Validated against the guide's manager-override-aware effective
     * maximum (N), obtained from the resolver so this never duplicates
     * the override precedence logic: 0 <= capacity <= N.
     *
     * @param activity $activity the activity
     * @param int $userid the guide declaring capacity
     * @param int $capacity groups volunteered for; 0 withdraws
     * @throws \moodle_exception when capacity is outside 0..N
     */
    public static function set(activity $activity, int $userid, int $capacity): void {
        global $DB;

        $ceiling = (new resolver($activity))->guide_capacity_ceiling($userid)->value;
        if ($capacity < 0 || $capacity > $ceiling) {
            throw new \moodle_exception('refusalvolunteercapacity', 'mod_selfselectadvanced', '', $ceiling);
        }

        $now = time();
        $existing = self::get($activity, $userid);
        if ($existing) {
            $existing->capacity = $capacity;
            $existing->timemodified = $now;
            $DB->update_record('selfselectadvanced_volunteer', $existing);
            $record = $existing;
        } else {
            $record = (object) [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'capacity' => $capacity,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('selfselectadvanced_volunteer', $record);
        }

        \mod_selfselectadvanced\event\volunteer_updated::create([
            'objectid' => (int) $record->id,
            'context' => $activity->context(),
            'relateduserid' => $userid,
            'other' => ['capacity' => $capacity],
        ])->trigger();
    }
}
