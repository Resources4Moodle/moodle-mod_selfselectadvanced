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

namespace mod_selfselectadvanced\local;

use mod_selfselectadvanced\activity;
use stdClass;

/**
 * Core-group synchronisation (spec 12). This slice ships the pieces
 * staged moves need - membership sync into an owned core group and
 * append-only snapshots (decision A6); freeze/unfreeze themselves
 * (T5/T6) complete the service in slice 10.
 *
 * Good-neighbour rules (spec 14.5): only the official groups API, only
 * groups this plugin created (tracked by coregroupid).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class freeze {
    /**
     * Mirror one membership change of a frozen group into its core
     * group and append a fresh snapshot (A6): a committed staged move
     * on a frozen group updates plugin roster, core group and snapshot
     * in the same transaction, so unfreeze restores the latest
     * plugin-authorised state.
     *
     * No-op for groups that are not frozen or carry no owned core group.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row (post-change)
     * @param int $userid the moved user
     * @param bool $added true when added, false when removed
     * @param int $actorid the acting manager
     */
    public static function sync_membership_change(
        activity $activity,
        stdClass $group,
        int $userid,
        bool $added,
        int $actorid
    ): void {
        global $CFG;

        if ($group->state !== state::FROZEN || empty($group->coregroupid)) {
            return;
        }
        require_once($CFG->dirroot . '/group/lib.php');

        if (groups_group_exists((int) $group->coregroupid)) {
            if ($added) {
                groups_add_member((int) $group->coregroupid, $userid);
            } else {
                groups_remove_member((int) $group->coregroupid, $userid);
            }
        }
        self::append_snapshot($group, $actorid);
    }

    /**
     * Append a roster snapshot for a group (A6, append-only history;
     * the newest row is what unfreeze restores).
     *
     * @param stdClass $group the group row
     * @param int $actorid the acting user
     * @return stdClass the snapshot row
     */
    public static function append_snapshot(stdClass $group, int $actorid): stdClass {
        global $DB;

        $roster = [];
        foreach (
            $DB->get_records('selfselectadvanced_member', [
                'groupid' => $group->id,
                'status' => groups::STATUS_CONFIRMED,
            ], 'id ASC', 'userid, isleader') as $member
        ) {
            $roster[] = ['userid' => (int) $member->userid, 'isleader' => (int) $member->isleader];
        }

        $snapshot = (object) [
            'groupid' => (int) $group->id,
            'coregroupid' => (int) ($group->coregroupid ?? 0),
            'roster' => json_encode($roster),
            'takenby' => $actorid,
            'timecreated' => time(),
        ];
        $snapshot->id = $DB->insert_record('selfselectadvanced_snapshot', $snapshot);

        return $snapshot;
    }
}
