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
use mod_selfselectadvanced\local\state;

/**
 * Guide decision window sweep (1.4.0): in activities that enable
 * auto-approval, submitted groups the guide has not decided within the
 * window are approved automatically ("counted as accepted").
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guide_autoapprove extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskguideautoapprove', 'mod_selfselectadvanced');
    }

    /**
     * Sweep every enabled activity.
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $activities = $DB->get_records_select(
            'selfselectadvanced',
            'guideautoapprove = 1 AND guidewindow > 0'
        );
        foreach ($activities as $instance) {
            $activity = activity::from_instance((int) $instance->id);
            $api = new api($activity);
            $overdue = $DB->get_records_select(
                'selfselectadvanced_group',
                'activityid = :activityid AND state = :state AND timesubmitted > 0 AND timesubmitted + :window < :now',
                [
                    'activityid' => $instance->id,
                    'state' => state::PENDING_GUIDE,
                    'window' => (int) $instance->guidewindow,
                    'now' => $now,
                ]
            );
            foreach ($overdue as $group) {
                try {
                    $api->lifecycle()->approve($group, (int) get_admin()->id, true);
                    mtrace("selfselectadvanced: auto-approved group {$group->pluginuid}");
                } catch (\moodle_exception $e) {
                    mtrace("selfselectadvanced: auto-approve skipped {$group->pluginuid}: " . $e->getMessage());
                }
            }
        }
    }
}
