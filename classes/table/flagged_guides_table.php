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
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\state;

/**
 * The flagged report's guides tab (audit round 6 item 6): groups
 * awaiting a guide decision, with the guide's name fields joined in
 * the same query instead of one core_user::get_user() call per row.
 *
 * 1.8.0: gained a guide load column ("used of max", the sole authority
 * being the override resolver) with a drill-down link to guideload.php.
 * The per-guide guiding load for the whole page is computed in one
 * grouped query in query_db(), never once per row; the resolver is
 * still consulted once per DISTINCT guide on the page.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flagged_guides_table extends \table_sql {
    /** @var int Guide decision window in seconds, 0 = no deadline. */
    private int $guidewindow;

    /** @var activity The activity, kept for the per-page guide load lookup. */
    private activity $activity;

    /** @var resolver The override resolver: the sole source of a guide's effective cap. */
    private resolver $resolver;

    /** @var array<int, int> Guide id to current guiding load, filled once per page in query_db(). */
    private array $loadused = [];

    /** @var array<int, int> Guide id to effective maximum guided, filled once per distinct guide. */
    private array $loadmax = [];

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver (sole source of the effective guide cap)
     * @param \moodle_url $baseurl page url (with active filters)
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     * @param string $q name filter over the group name or the guide name, '' = none
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        resolver $resolver,
        \moodle_url $baseurl,
        int $guidewindow,
        string $q
    ) {
        parent::__construct($uniqueid);
        $this->guidewindow = $guidewindow;
        $this->activity = $activity;
        $this->resolver = $resolver;

        $this->define_columns(['groupname', 'pluginuid', 'guidename', 'submitted', 'decideby', 'guideload']);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('guide', 'mod_selfselectadvanced'),
            get_string('flaggedsubmitted', 'mod_selfselectadvanced'),
            get_string('flaggeddecideby', 'mod_selfselectadvanced'),
            get_string('guideloads', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'groupname');
        $this->no_sorting('pluginuid');
        $this->no_sorting('guidename');
        $this->no_sorting('decideby');
        $this->no_sorting('guideload');
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
     * Fetch the page as usual, then compute the current guiding load
     * for every distinct guide on THIS page in one grouped query, and
     * the effective cap once per distinct guide - never one query per
     * row (audit round 6 style N+1 guard).
     *
     * @param int $pagesize rows per page
     * @param bool $useinitialsbar whether to show the alphabetic bar, passed through unchanged
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        parent::query_db($pagesize, $useinitialsbar);

        $guideids = [];
        foreach ($this->rawdata as $row) {
            if (!empty($row->guideid)) {
                $guideids[(int) $row->guideid] = true;
            }
        }
        $guideids = array_keys($guideids);

        $this->loadused = self::guide_load_counts($this->activity, $guideids);
        foreach ($guideids as $guideid) {
            $this->loadmax[$guideid] = $this->resolver->effective_maxguided($guideid)->value;
        }
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
     * Guide load cell: the same "used of max" label as the guides
     * list, linking to the read-only per-guide drill-down; a dash
     * when the group has no guide assigned yet.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_guideload($row) {
        if (!$row->guideid) {
            return '-';
        }

        $guideid = (int) $row->guideid;
        $label = get_string('guideload', 'mod_selfselectadvanced', (object) [
            'used' => $this->loadused[$guideid] ?? 0,
            'max' => $this->loadmax[$guideid] ?? 0,
        ]);
        $url = new \moodle_url('/mod/selfselectadvanced/guideload.php', [
            'id' => $this->activity->cm()->id,
            'guide' => $guideid,
        ]);

        return \html_writer::link($url, $label);
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
     * built from the same FROM/WHERE as the display table. The guide
     * load columns come from the same one-grouped-query helper the
     * display table uses, plus one resolver call per distinct guide.
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver (sole source of the effective guide cap)
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     * @param string $q name filter over the group name or the guide name, '' = none
     * @return \stdClass[] rows with rawname, pluginuid, guidename, since, deadline, overdue,
     *         guideloadused, guideloadmax
     */
    public static function export_rows(activity $activity, resolver $resolver, int $guidewindow, string $q): array {
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

        $guideids = [];
        foreach ($records as $record) {
            if (!empty($record->guideid)) {
                $guideids[(int) $record->guideid] = true;
            }
        }
        $guideids = array_keys($guideids);
        $loadused = self::guide_load_counts($activity, $guideids);
        $loadmax = [];
        foreach ($guideids as $guideid) {
            $loadmax[$guideid] = $resolver->effective_maxguided($guideid)->value;
        }

        $rows = [];
        foreach ($records as $record) {
            [$deadline, $overdue] = self::deadline_info((int) $record->timesubmitted, $guidewindow);
            $guideid = $record->guideid ? (int) $record->guideid : 0;
            $rows[] = (object) [
                'rawname' => $record->rawname,
                'pluginuid' => $record->pluginuid,
                'guidename' => $record->guideid ? fullname(self::guidename_object($record)) : '-',
                'since' => userdate((int) $record->timesubmitted),
                'deadline' => $deadline ? userdate($deadline) : '-',
                'overdue' => $overdue,
                'guideloadused' => $guideid ? ($loadused[$guideid] ?? 0) : 0,
                'guideloadmax' => $guideid ? ($loadmax[$guideid] ?? 0) : 0,
            ];
        }

        return $rows;
    }

    /**
     * Per-guide current guiding load for a set of guides, in one
     * grouped query mirroring the state set counted by
     * groups::count_guiding() (pending_guide, firm, frozen): the
     * single query the display table and the export share, run once
     * per page or per export, never once per row or per guide.
     *
     * @param activity $activity the activity
     * @param int[] $guideids distinct guide ids to compute the load for
     * @return array<int, int> guide id to current guiding load
     */
    public static function guide_load_counts(activity $activity, array $guideids): array {
        global $DB;

        if (!$guideids) {
            return [];
        }

        [$idsql, $params] = $DB->get_in_or_equal($guideids, SQL_PARAMS_NAMED, 'gd');
        [$statesql, $stateparams] = $DB->get_in_or_equal(
            [state::PENDING_GUIDE, state::FIRM, state::FROZEN],
            SQL_PARAMS_NAMED,
            'st'
        );
        $params = array_merge($params, $stateparams);
        $params['activityid'] = $activity->id();
        $records = $DB->get_records_sql(
            "SELECT guideid, COUNT(1) AS loadcount
               FROM {selfselectadvanced_group}
              WHERE activityid = :activityid AND guideid $idsql AND state $statesql
           GROUP BY guideid",
            $params
        );

        $counts = [];
        foreach ($records as $record) {
            $counts[(int) $record->guideid] = (int) $record->loadcount;
        }

        return $counts;
    }

    /**
     * Overdue group count per guide, scoped to the same filtered rows
     * the guides tab currently lists (the q filter): the recipient set
     * for the "Nudge overdue guides" bulk action, de-duplicated to one
     * entry per guide holding the number of their overdue groups.
     *
     * @param activity $activity the activity
     * @param int $guidewindow guide decision window in seconds, 0 = no deadline
     * @param string $q name filter over the group name or the guide name, '' = none
     * @return array<int, int> guide id to overdue group count, guides with none omitted
     */
    public static function overdue_guide_counts(activity $activity, int $guidewindow, string $q): array {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $q);
        $records = $DB->get_records_sql(
            "SELECT g.id, g.guideid, g.timesubmitted FROM $from WHERE $where",
            $params
        );

        $counts = [];
        foreach ($records as $record) {
            if (empty($record->guideid)) {
                continue;
            }
            [, $overdue] = self::deadline_info((int) $record->timesubmitted, $guidewindow);
            if (!$overdue) {
                continue;
            }
            $guideid = (int) $record->guideid;
            $counts[$guideid] = ($counts[$guideid] ?? 0) + 1;
        }

        return $counts;
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
