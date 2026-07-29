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

    /** @var int Narrowest project-number width a manager may choose. */
    public const UID_DIGITS_MIN = 2;

    /** @var int Widest project-number width a manager may choose. */
    public const UID_DIGITS_MAX = 10;

    /** @var int Project-number width when the activity does not say. */
    public const UID_DIGITS_DEFAULT = 4;

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
     * Count confirmed members of a set of groups in one query (bulk
     * counterpart of count_confirmed(), L1 basis), used so a report
     * that lists many groups does not issue one query per group.
     *
     * @param int[] $groupids the groups to count
     * @return int[] confirmed member count keyed by groupid; a group
     *               with no confirmed rows comes back as 0, not missing
     */
    public static function count_confirmed_bulk(array $groupids): array {
        return self::count_by_status_bulk($groupids, [self::STATUS_CONFIRMED]);
    }

    /**
     * Count taken seats of a set of groups in one query (bulk
     * counterpart of count_seats_taken(), L2 basis): confirmed members
     * plus pending invitations, used so a report that lists many
     * groups does not issue one query per group.
     *
     * @param int[] $groupids the groups to count
     * @return int[] seats-taken count keyed by groupid; a group with no
     *               qualifying rows comes back as 0, not missing
     */
    public static function count_seats_taken_bulk(array $groupids): array {
        return self::count_by_status_bulk($groupids, [self::STATUS_CONFIRMED, self::STATUS_INVITED]);
    }

    /**
     * Shared bulk counter behind count_confirmed_bulk() and
     * count_seats_taken_bulk(): one GROUP BY query per 1000 ids so a
     * huge activity cannot approach a bind-parameter limit, merged into
     * a single zero-normalised result.
     *
     * @param int[] $groupids the groups to count
     * @param string[] $statuses member statuses that count toward a seat
     * @return int[] count keyed by groupid, every requested id present
     */
    private static function count_by_status_bulk(array $groupids, array $statuses): array {
        global $DB;

        $groupids = array_values(array_unique(array_map('intval', $groupids)));
        $counts = array_fill_keys($groupids, 0);
        if (!$groupids) {
            return $counts;
        }

        foreach (array_chunk($groupids, 1000) as $chunk) {
            [$groupinsql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'gc');
            [$statusinsql, $statusparams] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'st');
            $chunkrows = $DB->get_records_sql(
                "SELECT groupid, COUNT(*) AS cnt
                   FROM {selfselectadvanced_member}
                  WHERE groupid $groupinsql AND status $statusinsql
               GROUP BY groupid",
                $params + $statusparams
            );
            foreach ($chunkrows as $chunkrow) {
                $counts[(int) $chunkrow->groupid] = (int) $chunkrow->cnt;
            }
        }

        return $counts;
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

        // Course-wide, not merely activity-wide (strategy 1.16 C): a
        // project name used by ANY self-selection activity in the same
        // course is taken, so names stay unique where people actually
        // read them - the course.
        $sql = "SELECT 1
                  FROM {selfselectadvanced_group} g
                  JOIN {selfselectadvanced} s ON s.id = g.activityid
                 WHERE s.course = :courseid AND g.id <> :excludeid AND "
            . $DB->sql_equal('g.name', ':name', false, false);

        return $DB->record_exists_sql($sql, [
            'courseid' => $activity->courseid(),
            'excludeid' => $excludegroupid,
            'name' => trim($name),
        ]);
    }

    /**
     * Does this name violate the teacher's project-name format?
     *
     * The format is a PCRE fragment from the activity settings,
     * applied anchored; empty means no constraint (strategy 1.16 C).
     * A format that fails to compile constrains nothing - the settings
     * validator refuses saving one, so this is defence in depth.
     *
     * @param activity $activity the activity
     * @param string $name the proposed project name
     * @return bool true when the name is refused by the format
     */
    public static function name_breaks_format(activity $activity, string $name): bool {
        $format = trim((string) ($activity->settings()->nameformat ?? ''));
        if ($format === '') {
            return false;
        }
        $result = @preg_match('/^' . str_replace('/', '\\/', $format) . '$/u', trim($name));

        return $result === 0;
    }

    /**
     * Build the plugin-scoped unique group id (decision A1):
     * {prefix}-{COURSESHORT sanitised to [A-Z0-9], max 12 chars}-{group id, 4+ digits}.
     *
     * The prefix is the manager-controlled activity setting `uidprefix`
     * (default SSA); it stamps groups created AFTER a change - a
     * pluginuid is minted once and never rewritten. Unique plugin-wide
     * forever because the group's own DB id carries the uniqueness.
     * Distinct from any core group id (decision D3).
     *
     * @param activity $activity the activity
     * @param int $groupid the group's DB id
     * @return string
     */
    public static function build_pluginuid(activity $activity, int $groupid): string {
        $prefix = preg_replace(
            '/[^A-Z0-9]/',
            '',
            strtoupper((string) ($activity->settings()->uidprefix ?? ''))
        );
        if ($prefix === '') {
            $prefix = 'SSA';
        }
        // The middle part names the course: its short name, or its
        // full name when the short name is empty or has no letters or
        // digits at all, and the course id only if both fail.
        $course = get_course($activity->courseid());
        $short = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $course->shortname));
        if ($short === '') {
            $short = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $course->fullname));
        }
        if ($short === '') {
            $short = 'C' . $activity->courseid();
        }
        $short = substr($short, 0, 12);

        $digits = (int) ($activity->settings()->uiddigits ?? self::UID_DIGITS_DEFAULT);
        if ($digits < self::UID_DIGITS_MIN || $digits > self::UID_DIGITS_MAX) {
            $digits = self::UID_DIGITS_DEFAULT;
        }

        // A group id longer than the chosen width keeps all its digits:
        // the number is an identity, never truncated to fit a format.
        return sprintf('%s-%s-%0' . $digits . 'd', substr($prefix, 0, 8), $short, $groupid);
    }
}
