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
use mod_selfselectadvanced\local\authority;
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
 * The actor's authority is re-established HERE, before every single
 * freeze, on the same predicate guide.php used to accept the click
 * (authority::require_freeze). A queue moves work into the future, and
 * a capability check performed at queue time answers a question about
 * the past: between the button and this cron pass the administrator may
 * have prohibited :freeze, deleted the role assignment or unenrolled
 * the actor. Measured on both engines before the fix - actor with
 * freeze_cap = 0, the 21st queued firm team frozen regardless (A-01).
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
     * loop treats them - so a lost capability is reported once per
     * skipped team rather than aborting the run, and the mtrace lines
     * are the cron log's record of teams that were NOT frozen.
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
                // Inside the loop and before the mutation, deliberately:
                // "the actor could do this when they asked" is not the
                // question a write has to answer. Cheap - accesslib
                // answers from its per-request cache after the first
                // call - and it puts the check on the same statement
                // sequence as the write it guards, where a later edit
                // cannot separate them without noticing.
                authority::require_freeze($activity, $actorid);
                $group = groups::get($activity, (int) $groupid);
                freeze::freeze_group($activity, $group, $actorid);
            } catch (\Throwable $e) {
                mtrace('mod_selfselectadvanced: bulk freeze skipped group ' . (int) $groupid
                    . ': ' . $e->getMessage());
            }
        }
    }
}
