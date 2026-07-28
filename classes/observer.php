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

/**
 * Core event observers (review item M3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A user was deleted: remove their site-wide attribute record and
     * purge the distinct-value cache (M3), then clear their live
     * memberships so no roster keeps counting a ghost (RCA Q1): core
     * has already dropped their core-group rows itself, so each
     * affected FROZEN group gets a fresh snapshot recording the true
     * roster - otherwise a later unfreeze or re-freeze reconciliation
     * would resurrect the deleted account. A deleted leader or guide
     * is surfaced by the existing flagged reports.
     *
     * @param \core\event\user_deleted $event the core event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int) $event->objectid;
        \mod_selfselectadvanced\local\attributes\manager::delete_for_user($userid);

        $rows = $DB->get_records_sql(
            "SELECT m.id AS memberid, g.*
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
              WHERE m.userid = :userid AND m.status IN (:confirmed, :invited)",
            [
                'userid' => $userid,
                'confirmed' => local\groups::STATUS_CONFIRMED,
                'invited' => local\groups::STATUS_INVITED,
            ]
        );
        $now = time();
        foreach ($rows as $row) {
            $DB->set_field('selfselectadvanced_member', 'status', local\groups::STATUS_REMOVED, [
                'id' => (int) $row->memberid,
            ]);
            $DB->set_field('selfselectadvanced_member', 'timemodified', $now, [
                'id' => (int) $row->memberid,
            ]);
            if ($row->state === local\state::FROZEN) {
                local\freeze::append_snapshot($row, (int) ($event->userid ?: get_admin()->id));
            }
        }
    }
}
