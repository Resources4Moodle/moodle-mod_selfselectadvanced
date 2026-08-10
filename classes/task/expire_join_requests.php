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
use mod_selfselectadvanced\local\joinrequests;

/**
 * Withdraw join requests nobody answered inside the activity's window.
 *
 * Decision 78, half B. Sibling of expire_invitations, and deliberately built to
 * the same shape: one activity at a time, off unless the activity sets a
 * duration, and the affected student always told.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expire_join_requests extends \core\task\scheduled_task {
    /**
     * Name shown on the scheduled-tasks page.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskexpirejoinrequests', 'mod_selfselectadvanced');
    }

    /**
     * Sweep every activity that has switched the expiry on.
     */
    public function execute(): void {
        global $DB;

        // Only activities that opted in. A site with the feature off pays
        // nothing for it, which is why this filters in SQL rather than
        // loading every activity and asking each one.
        $instances = $DB->get_records_select('selfselectadvanced', 'joinexpiry > 0', [], '', 'id');
        $total = 0;
        foreach ($instances as $instance) {
            try {
                $activity = activity::from_instance((int) $instance->id);
            } catch (\moodle_exception $e) {
                // An instance whose course module has gone is not an error
                // worth failing the whole sweep for: the next run will not
                // see it either.
                continue;
            }
            $total += joinrequests::expire_due($activity);
        }
        if ($total > 0) {
            mtrace('mod_selfselectadvanced: withdrew ' . $total . ' unanswered join request(s)');
        }
    }
}
