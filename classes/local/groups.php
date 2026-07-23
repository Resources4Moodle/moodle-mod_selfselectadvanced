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
 * Group and membership queries: the single source of truth for the
 * counting bases of the five limits (architecture plan section 2.3).
 *
 * - L1 basis: confirmed members of a group (leader included).
 * - L2 basis: confirmed members plus pending invitations (reserved seats).
 * - L3 basis: groups of the activity the user currently leads, any live state.
 * - L4 basis: groups of the activity the user is a confirmed member of.
 * - L5 basis: groups assigned to a guide in pending_guide, firm or frozen.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groups {
    /** @var string Member row status: invited (seat reserved). */
    public const STATUS_INVITED = 'invited';

    /** @var string Member row status: confirmed member. */
    public const STATUS_CONFIRMED = 'confirmed';

    /** @var string Member row status: invitation declined. */
    public const STATUS_DECLINED = 'declined';

    /** @var string Member row status: invitation expired. */
    public const STATUS_EXPIRED = 'expired';

    /** @var string Member row status: removed from the group. */
    public const STATUS_REMOVED = 'removed';

    /**
     * Fetch a group row, asserting it belongs to the activity.
     *
     * Server-side ownership verification for every id arriving from a
     * request (IDOR rule, spec section 14.12).
     *
     * @param activity $activity the activity
     * @param int $groupid the group id
     * @return stdClass the group row
     */
    public static function get(activity $activity, int $groupid): stdClass {
        global $DB;

        return $DB->get_record('selfselectadvanced_group', [
            'id' => $groupid,
            'activityid' => $activity->id(),
        ], '*', MUST_EXIST);
    }

    /**
     * Count confirmed members of a group (L1 counting basis).
     *
     * @param int $groupid the group
     * @return int
     */
    public static function count_confirmed(int $groupid): int {
        global $DB;

        return $DB->count_records('selfselectadvanced_member', [
            'groupid' => $groupid,
            'status' => self::STATUS_CONFIRMED,
        ]);
    }

    /**
     * Count taken seats: confirmed members plus pending invitations
     * (L2 counting basis - pending invitations hold reserved seats).
     *
     * @param int $groupid the group
     * @return int
     */
    public static function count_seats_taken(int $groupid): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal([self::STATUS_CONFIRMED, self::STATUS_INVITED]);
        $params[] = $groupid;

        return $DB->count_records_select('selfselectadvanced_member', "status $insql AND groupid = ?", $params);
    }

    /**
     * Count pending invitations of a group.
     *
     * @param int $groupid the group
     * @return int
     */
    public static function count_invited(int $groupid): int {
        global $DB;

        return $DB->count_records('selfselectadvanced_member', [
            'groupid' => $groupid,
            'status' => self::STATUS_INVITED,
        ]);
    }

    /**
     * Count groups of the activity a user currently leads (L3 counting
     * basis): current leader, states forming, pending_guide, firm or
     * frozen. Deleted groups are gone; a transferred leadership releases
     * the slot because leaderid moves on.
     *
     * @param activity $activity the activity
     * @param int $userid the user
     * @return int
     */
    public static function count_leading(activity $activity, int $userid): int {
        global $DB;

        return $DB->count_records('selfselectadvanced_group', [
            'activityid' => $activity->id(),
            'leaderid' => $userid,
        ]);
    }

    /**
     * Count groups of the activity a user is a confirmed member of, any
     * state, leadership included (L4 counting basis). Pending
     * invitations do not count toward the user's own cap.
     *
     * @param activity $activity the activity
     * @param int $userid the user
     * @return int
     */
    public static function count_memberships(activity $activity, int $userid): int {
        global $DB;

        $sql = "SELECT COUNT(1)
                  FROM {selfselectadvanced_member} m
                  JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                 WHERE g.activityid = :activityid
                   AND m.userid = :userid
                   AND m.status = :status";

        return $DB->count_records_sql($sql, [
            'activityid' => $activity->id(),
            'userid' => $userid,
            'status' => self::STATUS_CONFIRMED,
        ]);
    }

    /**
     * Count groups assigned to a guide in states that occupy guiding
     * load: pending_guide, firm and frozen (L5 counting basis). A
     * returned group released its slot because guideid was cleared.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide
     * @return int
     */
    public static function count_guiding(activity $activity, int $guideid): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal([state::PENDING_GUIDE, state::FIRM, state::FROZEN]);
        $params[] = $activity->id();
        $params[] = $guideid;

        return $DB->count_records_select(
            'selfselectadvanced_group',
            "state $insql AND activityid = ? AND guideid = ?",
            $params
        );
    }

    /**
     * The confirmed roster of a group, leader first, with user name fields.
     *
     * @param int $groupid the group
     * @return stdClass[] member rows joined with user records
     */
    public static function get_roster(int $groupid): array {
        global $DB;

        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT m.id AS memberid, m.userid, m.status, m.isleader, m.timecreated, $userfields
                  FROM {selfselectadvanced_member} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.groupid = :groupid AND m.status = :status
              ORDER BY m.isleader DESC, u.lastname, u.firstname";

        return $DB->get_records_sql($sql, ['groupid' => $groupid, 'status' => self::STATUS_CONFIRMED]);
    }

    /**
     * Groups of the activity the user is confirmed in (led and joined).
     *
     * @param activity $activity the activity
     * @param int $userid the user
     * @return stdClass[] group rows
     */
    public static function get_groups_of_user(activity $activity, int $userid): array {
        global $DB;

        $sql = "SELECT g.*
                  FROM {selfselectadvanced_group} g
                  JOIN {selfselectadvanced_member} m ON m.groupid = g.id
                 WHERE g.activityid = :activityid
                   AND m.userid = :userid
                   AND m.status = :status
              ORDER BY g.timecreated ASC";

        return $DB->get_records_sql($sql, [
            'activityid' => $activity->id(),
            'userid' => $userid,
            'status' => self::STATUS_CONFIRMED,
        ]);
    }

    /**
     * Whether a group name is already taken in the activity
     * (case-insensitive; spec section 6.1 name uniqueness).
     *
     * @param activity $activity the activity
     * @param string $name proposed name
     * @param int $excludegroupid group id to exclude when editing
     * @return bool true when taken
     */
    public static function name_taken(activity $activity, string $name, int $excludegroupid = 0): bool {
        global $DB;

        $sql = 'activityid = :activityid AND id <> :excludeid AND '
            . $DB->sql_equal('name', ':name', false, false);

        return $DB->record_exists_select('selfselectadvanced_group', $sql, [
            'activityid' => $activity->id(),
            'excludeid' => $excludegroupid,
            'name' => trim($name),
        ]);
    }

    /**
     * Build the plugin-scoped unique group id (decision A1):
     * SSA-{COURSESHORT sanitised to [A-Z0-9], max 12 chars}-{group id, 4+ digits}.
     *
     * Unique plugin-wide forever because the group's own DB id carries
     * the uniqueness. Distinct from any core group id (decision D3).
     *
     * @param activity $activity the activity
     * @param int $groupid the group's DB id
     * @return string
     */
    public static function build_pluginuid(activity $activity, int $groupid): string {
        $short = get_course($activity->courseid())->shortname;
        $short = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $short));
        if ($short === '') {
            $short = 'C' . $activity->courseid();
        }
        $short = substr($short, 0, 12);

        return sprintf('SSA-%s-%04d', $short, $groupid);
    }
}
