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
use mod_selfselectadvanced\local\penalty\ledger;

/**
 * Scheduled task: nightly full ledger reconciliation against current
 * effective dates (spec 11, 14.9) - catches date and override edits
 * made outside the settings-save path.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_penalties extends \core\task\scheduled_task {
    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreconcilepenalties', 'mod_selfselectadvanced');
    }

    /**
     * Recompute every activity's ledger and push grades.
     */
    public function execute(): void {
        global $DB;

        foreach ($DB->get_records('selfselectadvanced', null, 'id ASC', 'id') as $row) {
            try {
                $activity = activity::from_instance((int) $row->id);
            } catch (\moodle_exception $e) {
                continue;
            }
            try {
                $count = ledger::recompute_all($activity);
                // Every pending row, but a window at a time: a single
                // settings edit can park an activity's whole override
                // set (T-08), so asking for all of them in one query is
                // the unbounded read house rule 3 names - on the one
                // caller that runs for every activity on the site. The
                // overrides page sweeps only the window it renders,
                // which makes this the safety net for the rows beyond
                // it, so it must still reach the last row.
                \mod_selfselectadvanced\local\override\store::recheck_all_pending($activity, get_admin()->id);
            } catch (\Throwable $e) {
                // This is the nightly net under every activity on the
                // site, so one broken activity must not take the rest
                // down with it. recompute_all() holds an activity lock
                // that throws errlocktimeout after ten seconds when a
                // teacher is saving that activity's settings at the
                // same moment, and the pending-override recheck reads
                // rows that can vanish mid-pass; either one ended the
                // run at that activity's id and left every later
                // ledger stale until the following night.
                mtrace("mod_selfselectadvanced: reconcile failed for activity {$row->id}: "
                    . get_class($e) . ': ' . $e->getMessage());
                continue;
            }
            if ($count) {
                mtrace("mod_selfselectadvanced: reconciled $count penalty rows in activity {$row->id}");
            }
        }
    }
}
