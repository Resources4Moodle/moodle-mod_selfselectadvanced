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

namespace mod_selfselectadvanced\local\override;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\groups;
use moodle_url;
use stdClass;

/**
 * Guarded reductions (2026-07-24 change): an override that REDUCES a
 * cap below the target's current position must not silently strand
 * them over the limit. Such an override is stored with status
 * 'pending' and only becomes 'active' (visible to the resolver) once
 * every blocker is cleared; the blockers are listed with links to the
 * page where a manager can resolve each one.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guard {
    /**
     * Blockers for one stored override row, computed live.
     *
     * @param activity $activity the activity
     * @param stdClass $row the override row (any status)
     * @return stdClass[] each {rule, current, limit, description, fixurl}
     */
    public static function blockers(activity $activity, stdClass $row): array {
        global $DB;

        $blockers = [];
        $cmid = $activity->cm()->id;

        if ($row->scope === 'user' || $row->scope === 'guide') {
            $userid = (int) $row->userid;
            if ($row->scope === 'user' && $row->maxlead !== null) {
                $current = (int) $DB->count_records('selfselectadvanced_group', [
                    'activityid' => $activity->id(),
                    'leaderid' => $userid,
                ]);
                if ($current > (int) $row->maxlead) {
                    $blockers[] = self::blocker(
                        $activity,
                        'maxlead',
                        $current,
                        (int) $row->maxlead,
                        $userid,
                        new moodle_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cmid, 'student' => $userid])
                    );
                }
            }
            if ($row->scope === 'user' && $row->maxmembership !== null) {
                $current = (int) $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM {selfselectadvanced_member} m
                       JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                      WHERE g.activityid = ? AND m.userid = ? AND m.status = ?",
                    [$activity->id(), $userid, groups::STATUS_CONFIRMED]
                );
                if ($current > (int) $row->maxmembership) {
                    $blockers[] = self::blocker(
                        $activity,
                        'maxmembership',
                        $current,
                        (int) $row->maxmembership,
                        $userid,
                        new moodle_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cmid, 'student' => $userid])
                    );
                }
            }
            if ($row->scope === 'guide' && $row->maxguided !== null) {
                [$insql, $params] = $DB->get_in_or_equal(
                    [\mod_selfselectadvanced\local\state::PENDING_GUIDE,
                        \mod_selfselectadvanced\local\state::FIRM,
                        \mod_selfselectadvanced\local\state::FROZEN]
                );
                $current = (int) $DB->count_records_sql(
                    "SELECT COUNT(1) FROM {selfselectadvanced_group}
                      WHERE guideid = ? AND activityid = ? AND state $insql",
                    array_merge([$userid, $activity->id()], $params)
                );
                if ($current > (int) $row->maxguided) {
                    $blockers[] = self::blocker(
                        $activity,
                        'maxguided',
                        $current,
                        (int) $row->maxguided,
                        $userid,
                        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cmid])
                    );
                }
            }
        }

        if ($row->scope === 'group' && $row->maxsize !== null) {
            $groupid = (int) $row->groupid;
            $seats = (int) $DB->count_records_sql(
                "SELECT COUNT(1) FROM {selfselectadvanced_member}
                  WHERE groupid = ? AND status IN (?, ?)",
                [$groupid, groups::STATUS_CONFIRMED, groups::STATUS_INVITED]
            );
            if ($seats > (int) $row->maxsize) {
                $blockers[] = self::blocker(
                    $activity,
                    'maxsize',
                    $seats,
                    (int) $row->maxsize,
                    $groupid,
                    new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cmid, 'g' => $groupid])
                );
            }
        }

        return $blockers;
    }

    /**
     * Build one blocker record.
     *
     * @param activity $activity the activity
     * @param string $rule the limit field name
     * @param int $current current position
     * @param int $limit the reduced limit
     * @param int $targetid user or group id (for the description)
     * @param moodle_url $fixurl page where the excess can be resolved
     * @return stdClass
     */
    private static function blocker(
        activity $activity,
        string $rule,
        int $current,
        int $limit,
        int $targetid,
        moodle_url $fixurl
    ): stdClass {
        return (object) [
            'rule' => $rule,
            'current' => $current,
            'limit' => $limit,
            'description' => get_string('overrideblocker' . $rule, 'mod_selfselectadvanced', (object) [
                'current' => $current,
                'limit' => $limit,
            ]),
            'fixurl' => $fixurl,
        ];
    }
}
