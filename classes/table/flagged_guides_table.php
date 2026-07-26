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
 * The flagged report's guides tab (audit round 6 item 6): groups
 * awaiting a guide decision, with the guide's name fields joined in
 * the same query instead of one core_user::get_user() call per row.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flagged_guides_table extends \table_sql {
    /** @var int Guide decision window in seconds, 0 = no deadline. */
    private int $guidewindow;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param \moodle_url $baseurl page url (with active filters)
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     * @param string $q name filter over the group name or the guide name, '' = none
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        \moodle_url $baseurl,
        int $guidewindow,
        string $q
    ) {
        parent::__construct($uniqueid);
        $this->guidewindow = $guidewindow;

        $this->define_columns(['groupname', 'pluginuid', 'guidename', 'submitted', 'decideby']);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('guide', 'mod_selfselectadvanced'),
            get_string('flaggedsubmitted', 'mod_selfselectadvanced'),
            get_string('flaggeddecideby', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'groupname');
        $this->no_sorting('pluginuid');
        $this->no_sorting('guidename');
        $this->no_sorting('decideby');
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-guidespending');

        [$from, $where, $params] = self::sql_parts($activity, $q);
        $guidenamefields = implode(', ', self::guidename_select());
        $this->set_sql(
            "g.id, g.name AS groupname, g.pluginuid, g.guideid, g.timesubmitted AS submitted,
             $guidenamefields",
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
     * Guide name cell, built from the joined name fields so the site's
     * configured name display order is respected; a dash when the
     * group has no guide assigned yet.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_guidename($row) {
        if (!$row->guideid) {
            return '-';
        }

        return fullname(self::guidename_object($row));
    }

    /**
     * Submission time cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_submitted($row) {
        return userdate((int) $row->submitted);
    }

    /**
     * Decision deadline cell, styled when overdue.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_decideby($row) {
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
     * Cheap row count for the tab label, sharing the display query's
     * FROM/WHERE so the label always agrees with what the table shows.
     *
     * @param activity $activity the activity
     * @param string $q name filter over the group name or the guide name, '' = none
     * @return int
     */
    public static function count_rows(activity $activity, string $q): int {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $q);

        return $DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * The full (unpaginated, filtered) raw-value dataset for export,
     * built from the same FROM/WHERE as the display table.
     *
     * @param activity $activity the activity
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     * @param string $q name filter over the group name or the guide name, '' = none
     * @return \stdClass[] rows with rawname, pluginuid, guidename, since, deadline, overdue
     */
    public static function export_rows(activity $activity, int $guidewindow, string $q): array {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $q);
        $guidenamefields = implode(', ', self::guidename_select());
        $records = $DB->get_records_sql(
            "SELECT g.id, g.name AS rawname, g.pluginuid, g.guideid, g.timesubmitted, $guidenamefields
               FROM $from
              WHERE $where
           ORDER BY g.name",
            $params
        );

        $rows = [];
        foreach ($records as $record) {
            [$deadline, $overdue] = self::deadline_info((int) $record->timesubmitted, $guidewindow);
            $rows[] = (object) [
                'rawname' => $record->rawname,
                'pluginuid' => $record->pluginuid,
                'guidename' => $record->guideid ? fullname(self::guidename_object($record)) : '-',
                'since' => userdate((int) $record->timesubmitted),
                'deadline' => $deadline ? userdate($deadline) : '-',
                'overdue' => $overdue,
            ];
        }

        return $rows;
    }

    /**
     * The SELECT list entries for the guide's name fields, each
     * aliased with a "guide" prefix so they cannot collide with the
     * group's own columns.
     *
     * @return string[] select expressions, e.g. "gu.firstname AS guidefirstname"
     */
    private static function guidename_select(): array {
        return array_map(
            static fn(string $field) => "gu.$field AS guide$field",
            \core_user\fields::for_name()->get_required_fields()
        );
    }

    /**
     * Rebuild a name object from a row's prefixed guide name fields,
     * suitable for passing to fullname().
     *
     * @param \stdClass $row table row or export record
     * @return \stdClass
     */
    private static function guidename_object(\stdClass $row): \stdClass {
        $name = new \stdClass();
        foreach (\core_user\fields::for_name()->get_required_fields() as $field) {
            $name->$field = $row->{"guide$field"};
        }

        return $name;
    }

    /**
     * The SQL expression for the guide's concatenated first and last
     * name, used only to match the name filter, not to display it (the
     * display respects the site's configured name order via fullname()).
     *
     * @return string
     */
    private static function guidename_like_sql(): string {
        global $DB;

        return $DB->sql_fullname('gu.firstname', 'gu.lastname');
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
     * tab-label count and the export dataset.
     *
     * @param activity $activity the activity
     * @param string $q name filter over the group name or the guide name, '' = none
     * @return array{0: string, 1: string, 2: array} from, where, params
     */
    private static function sql_parts(activity $activity, string $q): array {
        global $DB;

        $from = '{selfselectadvanced_group} g LEFT JOIN {user} gu ON gu.id = g.guideid';
        $params = [
            'activityid' => $activity->id(),
            'pendingguide' => state::PENDING_GUIDE,
        ];
        $where = 'g.activityid = :activityid AND g.state = :pendingguide';
        if ($q !== '') {
            $where .= ' AND (' . $DB->sql_like('g.name', ':q1', false, false)
                . ' OR ' . $DB->sql_like(self::guidename_like_sql(), ':q2', false, false) . ')';
            $params['q1'] = '%' . $DB->sql_like_escape($q) . '%';
            $params['q2'] = '%' . $DB->sql_like_escape($q) . '%';
        }

        return [$from, $where, $params];
    }
}
