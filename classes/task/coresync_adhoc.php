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
use mod_selfselectadvanced\local\freeze;

/**
 * The convergence backstop for one group's mirrored course group.
 *
 * Queued by freeze::request_sync() INSIDE the transaction that changes
 * the plugin roster, so the job commits atomically with the change it
 * has to mirror. That is what closes the crash window between the
 * plugin commit and the inline freeze::sync_core_group() call: if the
 * request dies in between, cron converges the mirror instead of leaving
 * it silently wrong. Identical pending rows are deduped at queue time.
 *
 * Adhoc tasks need no db/tasks.php entry.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class coresync_adhoc extends \core\task\adhoc_task {
    /**
     * Converge one group's mirror, letting failures escape so core's
     * retry and backoff engine owns the retry.
     */
    public function execute(): void {
        $data = (object) $this->get_custom_data();
        try {
            $activity = activity::from_instance((int) $data->activityid);
        } catch (\dml_missing_record_exception | \moodle_exception $e) {
            // The instance is gone; there is nothing left to mirror and
            // the task has done its job.
            return;
        }

        freeze::sync_core_group(
            $activity,
            (int) $data->groupid,
            (int) ($this->get_userid() ?: get_admin()->id),
            [],
            true
        );
    }
}
