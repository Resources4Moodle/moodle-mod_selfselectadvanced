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
use mod_selfselectadvanced\local\groups;

/**
 * The overflow of a bulk freeze.
 *
 * A guide selecting every team they hold used to freeze all of them in
 * one web request - each freeze taking a lock, writing a snapshot and
 * pushing a roster into the course's groups, with no bound at all.
 * guide.php now freezes freeze::BULK_FREEZE_INLINE_MAX inline and hands
 * the remainder here, where the work is off the web path and its
 * notifications and core-group syncs are legal.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bulkfreeze_adhoc extends \core\task\adhoc_task {
    /**
     * Freeze the queued remainder, one group at a time.
     *
     * A refusal on one group (a cap audit, a changed state) must not
     * take the rest of the batch down with it, exactly as the inline
     * loop treats them.
     */
    public function execute(): void {
        $data = (object) $this->get_custom_data();
        try {
            $activity = activity::from_instance((int) $data->activityid);
        } catch (\dml_missing_record_exception | \moodle_exception $e) {
            return;
        }

        $actorid = (int) ($data->actorid ?? 0) ?: (int) ($this->get_userid() ?: get_admin()->id);
        foreach ((array) ($data->groupids ?? []) as $groupid) {
            try {
                $group = groups::get($activity, (int) $groupid);
                freeze::freeze_group($activity, $group, $actorid);
            } catch (\Throwable $e) {
                mtrace('mod_selfselectadvanced: bulk freeze skipped group ' . (int) $groupid
                    . ': ' . $e->getMessage());
            }
        }
    }
}
