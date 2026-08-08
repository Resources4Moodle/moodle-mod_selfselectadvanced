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
use mod_selfselectadvanced\local\autogroup\engine;

/**
 * Scheduled task: auto-group groupless students once their effective
 * cutoffs pass, re-running as override-extended windows close
 * (spec 9.1, review item B4, spec 14.9).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_autogrouping extends \core\task\scheduled_task {
    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrunautogrouping', 'mod_selfselectadvanced');
    }

    /**
     * Sweep every enabled activity whose pool holds unprocessed users.
     */
    public function execute(): void {
        global $DB;

        foreach ($DB->get_records('selfselectadvanced', ['autogroup' => 2], 'id ASC', 'id') as $row) {
            // The WHOLE per-activity body is guarded, not just the
            // lookup. Only activity::from_instance() used to be caught,
            // so one activity that engine::run() choked on (a pool row
            // pointing at a deleted user, a lock it could not take)
            // ended the sweep at that id: every activity with a higher
            // id was silently never auto-grouped, and stayed that way
            // on every later run too, because the sweep dies at the
            // same row each time.
            try {
                $activity = activity::from_instance((int) $row->id);
                if (!engine::sweep_due($activity)) {
                    continue;
                }
                $agrun = engine::run($activity, 0);
                mtrace("mod_selfselectadvanced: autogroup run {$agrun->id} in activity {$row->id}: "
                    . "{$agrun->groupsformed} groups, {$agrun->placed} placed, {$agrun->unplaced} unplaced");
            } catch (\Throwable $e) {
                // The class is named beside the message because the two
                // answer different questions: "can not find data record"
                // out of a missing course module is ordinary tidying,
                // the same words out of a dml_write_exception mean the
                // database refused the write and somebody has to look.
                mtrace("mod_selfselectadvanced: autogroup sweep failed for activity {$row->id}: "
                    . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }
}
