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

namespace mod_selfselectadvanced\task;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\api;

/**
 * Scheduled task: expire pending invitations past their activity's
 * expiry window, releasing their reserved seats (spec sections 4.9,
 * 14.9).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expire_invitations extends \core\task\scheduled_task {
    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskexpireinvitations', 'mod_selfselectadvanced');
    }

    /**
     * Expire due invitations across all activities with expiry enabled.
     */
    public function execute(): void {
        global $DB;

        $activities = $DB->get_records_select('selfselectadvanced', 'inviteexpiry > 0', [], 'id ASC', 'id');
        foreach ($activities as $row) {
            try {
                $activity = activity::from_instance((int) $row->id);
            } catch (\moodle_exception $e) {
                // Instance mid-deletion: skip.
                continue;
            }
            $count = (new api($activity))->invitations()->expire_due();
            if ($count) {
                mtrace("mod_selfselectadvanced: expired $count invitation(s) in activity {$row->id}");
            }
        }
    }
}
