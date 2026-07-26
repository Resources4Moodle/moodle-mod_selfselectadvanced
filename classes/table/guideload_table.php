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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\state;

/**
 * The guide load drill-down (1.8.0): one guide's groups in an
 * activity, linked from the guide load column on the flagged report's
 * guides tab. Read-only, table_sql, downloaded through the exporter
 * with raw values only (never display strings, audit round 6 item 1).
 *
 * The listed groups are exactly those counted in the guiding load
 * (pending_guide, firm, frozen), mirroring groups::count_guiding() so
 * the row count here always agrees with the "used" figure it drills
 * down from.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guideload_table extends \table_sql {
    /** @var int Guide decision window in seconds, 0 = no deadline. */
    private int $guidewindow;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param int $guideid the guide whose groups are listed
     * @param \moodle_url $baseurl page url
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        int $guideid,
        \moodle_url $baseurl,
        int $guidewindow
    ) {
        parent::__construct($uniqueid);
        $this->guidewindow = $guidewindow;

        $this->define_columns(['groupname', 'pluginuid', 'state', 'submitted', 'decideby']);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('flaggedsubmitted', 'mod_selfselectadvanced'),
            get_string('flaggeddecideby', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'groupname');
        $this->no_sorting('state');
        $this->no_sorting('decideby');
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-guideload');

        [$from, $where, $params] = self::sql_parts($activity, $guideid);
        $this->set_sql(
            'g.id, g.name AS groupname, g.pluginuid, g.state, g.timesubmitted AS submitted',
            $from,
            $where,
            $params
        );
    }

    /**
     * Group name cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_groupname($row) {
        return format_string($row->groupname);
    }

    /**
     * Localised state cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_state($row) {
        return get_string('state' . str_replace('_', '', $row->state), 'mod_selfselectadvanced');
    }

    /**
     * Submission time cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_submitted($row) {
        return $row->submitted ? userdate((int) $row->submitted) : '-';
    }

    /**
     * Decision deadline cell, styled when overdue; only pending_guide
     * groups have a live deadline, a decided group shows a dash.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_decideby($row) {
        if ($row->state !== state::PENDING_GUIDE) {
            return '-';
        }

        [$deadline, $overdue] = self::deadline_info((int) $row->submitted, $this->guidewindow);
        if (!$deadline) {
            return '-';
        }

        return $overdue
            ? \html_writer::span(
                userdate($deadline) . ' ' . get_string('flaggedoverdue', 'mod_selfselectadvanced'),
                'text-danger fw-bold'
            )
            : userdate($deadline);
    }

    /**
     * Cheap row count, sharing the display query's FROM/WHERE.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide whose groups are listed
     * @return int
     */
    public static function count_rows(activity $activity, int $guideid): int {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $guideid);

        return $DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * The full raw-value dataset for export (audit round 6 item 1:
     * raw values only, never display strings): the state as its raw
     * code, submitted and deadline as raw unix timestamps (0 = none),
     * overdue as a raw boolean.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide whose groups are listed
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     * @return \stdClass[] rows with rawname, pluginuid, state, submitted, deadline, overdue
     */
    public static function export_rows(activity $activity, int $guideid, int $guidewindow): array {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $guideid);
        $records = $DB->get_records_sql(
            "SELECT g.id, g.name AS rawname, g.pluginuid, g.state, g.timesubmitted
               FROM $from
              WHERE $where
           ORDER BY g.name",
            $params
        );

        $rows = [];
        foreach ($records as $record) {
            $deadline = 0;
            $overdue = false;
            if ($record->state === state::PENDING_GUIDE) {
                [$deadline, $overdue] = self::deadline_info((int) $record->timesubmitted, $guidewindow);
            }
            $rows[] = (object) [
                'rawname' => $record->rawname,
                'pluginuid' => $record->pluginuid,
                'state' => $record->state,
                'submitted' => (int) $record->timesubmitted,
                'deadline' => $deadline,
                'overdue' => $overdue,
            ];
        }

        return $rows;
    }

    /**
     * The decision deadline (0 = none) and whether it has passed.
     *
     * @param int $timesubmitted submission time
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     * @return array{0: int, 1: bool} deadline timestamp (0 = none), overdue
     */
    private static function deadline_info(int $timesubmitted, int $guidewindow): array {
        $deadline = $guidewindow > 0 && $timesubmitted ? $timesubmitted + $guidewindow : 0;

        return [$deadline, $deadline > 0 && $deadline < time()];
    }

    /**
     * Build the FROM/WHERE/params shared by the display query, the
     * row count and the export dataset: the same guiding states
     * counted by groups::count_guiding() (pending_guide, firm, frozen).
     *
     * @param activity $activity the activity
     * @param int $guideid the guide whose groups are listed
     * @return array{0: string, 1: string, 2: array} from, where, params
     */
    private static function sql_parts(activity $activity, int $guideid): array {
        global $DB;

        [$statesql, $stateparams] = $DB->get_in_or_equal(
            [state::PENDING_GUIDE, state::FIRM, state::FROZEN],
            SQL_PARAMS_NAMED,
            'st'
        );
        $from = '{selfselectadvanced_group} g';
        $where = 'g.activityid = :activityid AND g.guideid = :guideid AND g.state ' . $statesql;
        $params = array_merge(['activityid' => $activity->id(), 'guideid' => $guideid], $stateparams);

        return [$from, $where, $params];
    }
}
