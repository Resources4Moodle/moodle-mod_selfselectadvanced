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

namespace mod_selfselectadvanced;

/**
 * Core event observers (review item M3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A user was deleted: remove their site-wide attribute record and
     * purge the distinct-value cache (M3), then clear their live
     * memberships so no roster keeps counting a ghost (RCA Q1): core
     * has already dropped their core-group rows itself, so each
     * affected FROZEN group gets a fresh snapshot recording the true
     * roster - otherwise a later unfreeze or re-freeze reconciliation
     * would resurrect the deleted account. A deleted leader or guide
     * is surfaced by the existing flagged reports.
     *
     * @param \core\event\user_deleted $event the core event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int) $event->objectid;
        \mod_selfselectadvanced\local\attributes\manager::delete_for_user($userid);
        $actorid = (int) ($event->userid ?: get_admin()->id);

        // Each group's flip + snapshot runs under the group lock in
        // one transaction, exactly like every other roster mutation
        // (A7) - otherwise a concurrent unfreeze can re-confirm the
        // ghost from the pre-deletion snapshot, and a crash between
        // the flip and the snapshot leaves a frozen mirror that would
        // resurrect it.
        $rows = $DB->get_records_sql(
            "SELECT m.id AS memberid, g.id AS groupid
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
              WHERE m.userid = :userid AND m.status IN (:confirmed, :invited)",
            [
                'userid' => $userid,
                'confirmed' => local\groups::STATUS_CONFIRMED,
                'invited' => local\groups::STATUS_INVITED,
            ]
        );
        $now = time();
        foreach ($rows as $row) {
            $lock = local\locks::acquire('group:' . (int) $row->groupid);
            try {
                $transaction = $DB->start_delegated_transaction();
                $member = $DB->get_record('selfselectadvanced_member', ['id' => (int) $row->memberid]);
                $live = [local\groups::STATUS_CONFIRMED, local\groups::STATUS_INVITED];
                if ($member && in_array($member->status, $live, true)) {
                    $member->status = local\groups::STATUS_REMOVED;
                    $member->timemodified = $now;
                    $DB->update_record('selfselectadvanced_member', $member);
                    $group = $DB->get_record('selfselectadvanced_group', ['id' => (int) $row->groupid]);
                    if ($group && $group->state === local\state::FROZEN) {
                        local\freeze::append_snapshot($group, $actorid);
                    }
                }
                $transaction->allow_commit();
            } finally {
                $lock->release();
            }
        }

        // The student's PENDING staged moves would re-insert the ghost
        // at commit; cancel them under each activity's lock - the lock
        // commit_set itself holds.
        $activityids = $DB->get_fieldset_sql(
            "SELECT DISTINCT activityid
               FROM {selfselectadvanced_move}
              WHERE userid = :userid AND status = :pending",
            ['userid' => $userid, 'pending' => 'pending']
        );
        foreach ($activityids as $activityid) {
            $lock = local\locks::acquire('activity:' . (int) $activityid);
            try {
                $transaction = $DB->start_delegated_transaction();
                $moveids = $DB->get_fieldset_select(
                    'selfselectadvanced_move',
                    'id',
                    'activityid = ? AND userid = ? AND status = ?',
                    [(int) $activityid, $userid, 'pending']
                );
                if ($moveids) {
                    [$insql, $inparams] = $DB->get_in_or_equal($moveids);
                    $DB->set_field_select('selfselectadvanced_move', 'status', 'cancelled', "id $insql", $inparams);
                    $DB->set_field_select('selfselectadvanced_move', 'timemodified', $now, "id $insql", $inparams);
                }
                $transaction->allow_commit();
            } finally {
                $lock->release();
            }
        }
    }
}
