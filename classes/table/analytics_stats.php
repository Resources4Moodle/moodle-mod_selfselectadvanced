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

namespace mod_selfselectadvanced\table;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\eoi;
use stdClass;

/**
 * Set-based queries behind the formation analytics report
 * (analytics.php): every median is computed from ONE query fetching
 * the relevant timestamp pairs, with the elapsed-seconds median then
 * worked out in PHP, never a query per row.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analytics_stats {
    /**
     * Formation funnel counts: every group created, then how many of
     * those reached each later stage. One aggregate query.
     *
     * @param activity $activity the activity
     * @return stdClass created, submitted, firm and frozen counts
     */
    public static function funnel(activity $activity): stdClass {
        global $DB;

        $row = $DB->get_record_sql(
            "SELECT COUNT(1) AS created,
                    SUM(CASE WHEN timesubmitted IS NOT NULL THEN 1 ELSE 0 END) AS submitted,
                    SUM(CASE WHEN timeapproved IS NOT NULL THEN 1 ELSE 0 END) AS firm,
                    SUM(CASE WHEN timefrozen IS NOT NULL THEN 1 ELSE 0 END) AS frozen
               FROM {selfselectadvanced_group}
              WHERE activityid = :activityid",
            ['activityid' => $activity->id()]
        );

        return (object) [
            'created' => (int) $row->created,
            'submitted' => (int) ($row->submitted ?? 0),
            'firm' => (int) ($row->firm ?? 0),
            'frozen' => (int) ($row->frozen ?? 0),
        ];
    }

    /**
     * Expression-of-interest totals by outcome, every status present
     * even at zero. One grouped query.
     *
     * @param activity $activity the activity
     * @return int[] status => count
     */
    public static function eoi_status_counts(activity $activity): array {
        global $DB;

        $bystatus = $DB->get_records_sql_menu(
            "SELECT status, COUNT(1)
               FROM {selfselectadvanced_eoi}
              WHERE activityid = :activityid
           GROUP BY status",
            ['activityid' => $activity->id()]
        );

        $statuses = [
            eoi::STATUS_PENDING, eoi::STATUS_ACCEPTED, eoi::STATUS_REJECTED,
            eoi::STATUS_EXPIRED, eoi::STATUS_WITHDRAWN,
        ];
        $counts = [];
        foreach ($statuses as $status) {
            $counts[$status] = (int) ($bystatus[$status] ?? 0);
        }

        return $counts;
    }

    /**
     * Median seconds from group creation to submission.
     *
     * @param activity $activity the activity
     * @return int|null seconds, null when no group has submitted yet
     */
    public static function median_creation_to_submission(activity $activity): ?int {
        global $DB;

        $rows = $DB->get_records_select(
            'selfselectadvanced_group',
            'activityid = :activityid AND timesubmitted IS NOT NULL',
            ['activityid' => $activity->id()],
            '',
            'id, timecreated, timesubmitted'
        );

        return self::median_of(array_map(
            static fn($row): int => (int) $row->timesubmitted - (int) $row->timecreated,
            $rows
        ));
    }

    /**
     * Median seconds from group creation to becoming firm (approved).
     *
     * @param activity $activity the activity
     * @return int|null seconds, null when no group is firm yet
     */
    public static function median_creation_to_firm(activity $activity): ?int {
        global $DB;

        $rows = $DB->get_records_select(
            'selfselectadvanced_group',
            'activityid = :activityid AND timeapproved IS NOT NULL',
            ['activityid' => $activity->id()],
            '',
            'id, timecreated, timeapproved'
        );

        return self::median_of(array_map(
            static fn($row): int => (int) $row->timeapproved - (int) $row->timecreated,
            $rows
        ));
    }

    /**
     * Median seconds from a team being listed to its first expression
     * of interest, across every team that was ever listed and did
     * attract one. One query joins the listed teams to their earliest
     * interest.
     *
     * @param activity $activity the activity
     * @return int|null seconds, null when no listed team has ever had an interest
     */
    public static function median_listing_to_interest(activity $activity): ?int {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT g.id, g.timelisted, MIN(e.timecreated) AS firstinterest
               FROM {selfselectadvanced_group} g
               JOIN {selfselectadvanced_eoi} e ON e.groupid = g.id
              WHERE g.activityid = :activityid AND g.timelisted IS NOT NULL
           GROUP BY g.id, g.timelisted",
            ['activityid' => $activity->id()]
        );

        return self::median_of(array_map(
            static fn($row): int => (int) $row->firstinterest - (int) $row->timelisted,
            $rows
        ));
    }

    /**
     * Median seconds from a guide's expression of interest to the
     * leader's decision (accepted or rejected only; a timeout or a
     * withdrawal is not a leader response).
     *
     * @param activity $activity the activity
     * @return int|null seconds, null when the leader has not decided any yet
     */
    public static function median_interest_to_response(activity $activity): ?int {
        global $DB;

        [$statussql, $params] = $DB->get_in_or_equal(
            [eoi::STATUS_ACCEPTED, eoi::STATUS_REJECTED],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params['activityid'] = $activity->id();
        $rows = $DB->get_records_select(
            'selfselectadvanced_eoi',
            "activityid = :activityid AND status $statussql AND timeresponded IS NOT NULL",
            $params,
            '',
            'id, timecreated, timeresponded'
        );

        return self::median_of(array_map(
            static fn($row): int => (int) $row->timeresponded - (int) $row->timecreated,
            $rows
        ));
    }

    /**
     * The per-group raw lifecycle timestamps the medians and funnel
     * above are computed from, for the report's download (raw values
     * only, no display strings, matching every other export in the
     * plugin): the first-interest timestamp joins in one extra query,
     * keyed by group id, never one query per group.
     *
     * @param activity $activity the activity
     * @return stdClass[] one row per group
     */
    public static function export_rows(activity $activity): array {
        global $DB;

        $groups = $DB->get_records(
            'selfselectadvanced_group',
            ['activityid' => $activity->id()],
            'id ASC',
            'id, pluginuid, name, state, timecreated, timelisted, timesubmitted, timeapproved, timefrozen'
        );

        $firstinterest = $DB->get_records_sql_menu(
            "SELECT g.id, MIN(e.timecreated) AS firstinterest
               FROM {selfselectadvanced_group} g
               JOIN {selfselectadvanced_eoi} e ON e.groupid = g.id
              WHERE g.activityid = :activityid
           GROUP BY g.id",
            ['activityid' => $activity->id()]
        );

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = (object) [
                'rawname' => $group->name,
                'pluginuid' => $group->pluginuid,
                'state' => $group->state,
                'timecreated' => (int) $group->timecreated,
                'timelisted' => (int) ($group->timelisted ?? 0),
                'firstinterest' => (int) ($firstinterest[$group->id] ?? 0),
                'timesubmitted' => (int) ($group->timesubmitted ?? 0),
                'timeapproved' => (int) ($group->timeapproved ?? 0),
                'timefrozen' => (int) ($group->timefrozen ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * A stat card for one median: the elapsed time in human-readable
     * form, or the "not enough data" placeholder when the set behind
     * it is still empty.
     *
     * @param int|null $seconds elapsed seconds, null when no row exists yet
     * @param string $label card label, already localised
     * @return string html
     */
    public static function card_html(?int $seconds, string $label): string {
        $number = $seconds === null
            ? get_string('analyticsnodata', 'mod_selfselectadvanced')
            : format_time($seconds);

        return \html_writer::div(
            \html_writer::div($number, 'ssa-card-number')
            . \html_writer::div($label, 'ssa-card-label'),
            'ssa-card'
        );
    }

    /**
     * The median of a set of integers, computed in PHP.
     *
     * @param int[] $values elapsed-seconds durations
     * @return int|null null when the set is empty
     */
    private static function median_of(array $values): ?int {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
