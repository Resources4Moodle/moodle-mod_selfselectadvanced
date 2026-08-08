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
use mod_selfselectadvanced\local\eoi;

/**
 * Expire pending expressions of interest whose leader-response window
 * has lapsed, so the guide can spend their interest elsewhere. The
 * guide may express interest in the same team again afterwards.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expire_eoi extends \core\task\scheduled_task {
    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskexpireeoi', 'mod_selfselectadvanced');
    }

    /**
     * Sweep every activity with the feature and a window configured.
     */
    public function execute(): void {
        global $DB;

        $instances = $DB->get_records_select(
            'selfselectadvanced',
            'eoienabled = 1 AND eoiwindow > 0',
            [],
            'id ASC',
            'id'
        );
        foreach ($instances as $instance) {
            try {
                $activity = activity::from_instance((int) $instance->id);
            } catch (\moodle_exception $e) {
                continue;
            }
            try {
                $expired = eoi::expire_due($activity);
            } catch (\Throwable $e) {
                // The sweep is per activity, so the failure has to be
                // per activity too. expire_due() takes a group lock for
                // every pending row and re-reads it MUST_EXIST inside,
                // then notifies the guides afterwards: a row deleted
                // mid-sweep, a lock that times out under contention or
                // one unreachable recipient ended the whole run, and
                // every activity with a higher id kept holding interest
                // past its window until the next cron tick.
                mtrace("mod_selfselectadvanced: interest expiry failed for activity {$instance->id}: "
                    . get_class($e) . ': ' . $e->getMessage());
                continue;
            }
            if ($expired > 0) {
                mtrace("mod_selfselectadvanced: expired $expired interest(s) in activity {$instance->id}");
            }
        }
    }
}
