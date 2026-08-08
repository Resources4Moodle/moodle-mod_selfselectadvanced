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
use mod_selfselectadvanced\local\coresync_backfill;

/**
 * SQL-paged status table for Moodle group mirrors.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coresync_report_table extends \table_sql {
    /** @var activity Activity being reported. */
    private activity $activity;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param \moodle_url $baseurl page url carrying active filters
     * @param array $filters status, state and q filters
     */
    public function __construct(string $uniqueid, activity $activity, \moodle_url $baseurl, array $filters) {
        parent::__construct($uniqueid);

        $this->activity = $activity;
        $this->define_columns([
            'name',
            'pluginuid',
            'state',
            'guide',
            'mirrorpresent',
            'counts',
            'drift',
            'lastsyncstatus',
            'lastsynctime',
        ]);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('guide', 'mod_selfselectadvanced'),
            get_string('coresyncmirrorpresent', 'mod_selfselectadvanced'),
            get_string('coresynccounts', 'mod_selfselectadvanced'),
            get_string('coresyncdrift', 'mod_selfselectadvanced'),
            get_string('coresynclaststatus', 'mod_selfselectadvanced'),
            get_string('coresynclasttime', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'name');
        foreach (['guide', 'mirrorpresent', 'counts', 'drift', 'lastsyncstatus', 'lastsynctime'] as $column) {
            $this->no_sorting($column);
        }
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-coresync');

        [$fields, $from, $where, $params] = self::sql_parts($activity, $filters);
        $this->set_sql($fields, $from, $where, $params);
    }

    /**
     * Build SQL parts shared by table, count tests and filter probes.
     *
     * @param activity $activity the activity
     * @param array $filters status, state and q filters
     * @return array fields, from, where, params
     */
    public static function sql_parts(activity $activity, array $filters): array {
        global $DB;

        $namefields = implode(', ', array_map(
            static fn(string $field): string => 'gu.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $fields = "g.id, g.activityid, g.pluginuid, g.name, g.state, g.guideid, g.coregroupid, "
            . "cg.id AS livecoregroupid, COALESCE(pc.pluginmembercount, 0) AS pluginmembercount, "
            . "COALESCE(cc.coremembercount, 0) AS coremembercount, "
            . "COALESCE(ls.lastsuccess, 0) AS lastsuccess, COALESCE(lf.lastfailure, 0) AS lastfailure, "
            . ($namefields !== '' ? $namefields . ', ' : '') . "gu.id AS guideuserid";

        $from = "{selfselectadvanced_group} g
            JOIN {selfselectadvanced} s ON s.id = g.activityid
            LEFT JOIN {groups} cg ON cg.courseid = s.course AND cg.idnumber = g.pluginuid
            LEFT JOIN {user} gu ON gu.id = g.guideid
            LEFT JOIN (
                SELECT mg.id AS groupid,
                       COUNT(DISTINCT mm.userid)
                       + CASE
                           WHEN MAX(mg.guideid) IS NULL THEN 0
                           WHEN MAX(CASE WHEN mm.userid = mg.guideid THEN 1 ELSE 0 END) = 1 THEN 0
                           ELSE 1
                         END AS pluginmembercount
                  FROM {selfselectadvanced_group} mg
             LEFT JOIN {selfselectadvanced_member} mm
                    ON mm.groupid = mg.id AND mm.status = :confirmed
              GROUP BY mg.id
            ) pc ON pc.groupid = g.id
            LEFT JOIN (
                SELECT groupid, COUNT(1) AS coremembercount
                  FROM {groups_members}
              GROUP BY groupid
            ) cc ON cc.groupid = cg.id
            LEFT JOIN (
                SELECT objectid, MAX(timecreated) AS lastsuccess
                  FROM {logstore_standard_log}
                 WHERE eventname = :syncevent AND contextinstanceid = :cmid
              GROUP BY objectid
            ) ls ON ls.objectid = g.id
            LEFT JOIN (
                SELECT objectid, MAX(timecreated) AS lastfailure
                  FROM {logstore_standard_log}
                 WHERE eventname = :failevent AND contextinstanceid = :cmidfail
              GROUP BY objectid
            ) lf ON lf.objectid = g.id";
        $params = [
            'activityid' => $activity->id(),
            'confirmed' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
            'syncevent' => '\\mod_selfselectadvanced\\event\\coregroup_synced',
            'failevent' => '\\mod_selfselectadvanced\\event\\coregroup_sync_failed',
            'cmid' => $activity->cm()->id,
            'cmidfail' => $activity->cm()->id,
        ];
        $where = 'g.activityid = :activityid';

        $statefilter = (string) ($filters['state'] ?? '');
        if ($statefilter !== '') {
            $where .= ' AND g.state = :statefilter';
            $params['statefilter'] = $statefilter;
        }

        $q = \core_text::strtolower(trim((string) ($filters['q'] ?? '')));
        if ($q !== '') {
            $where .= ' AND (' . $DB->sql_like('LOWER(g.name)', ':qname', false, false)
                . ' OR ' . $DB->sql_like('LOWER(g.pluginuid)', ':quid', false, false) . ')';
            $params['qname'] = '%' . $DB->sql_like_escape($q) . '%';
            $params['quid'] = '%' . $DB->sql_like_escape($q) . '%';
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'nomirror') {
            $where .= ' AND cg.id IS NULL';
        } else if ($status === 'failed') {
            $where .= ' AND COALESCE(lf.lastfailure, 0) > COALESCE(ls.lastsuccess, 0)';
        } else if ($status === 'synced') {
            $where .= ' AND cg.id IS NOT NULL AND COALESCE(lf.lastfailure, 0) <= COALESCE(ls.lastsuccess, 0)';
        }

        return [$fields, $from, $where, $params];
    }

    /**
     * Count rows using the same SQL filter as the table.
     *
     * @param activity $activity the activity
     * @param array $filters status, state and q filters
     * @return int row count
     */
    public static function count_rows(activity $activity, array $filters): int {
        global $DB;

        [, $from, $where, $params] = self::sql_parts($activity, $filters);

        return $DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * Format name.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_name($row): string {
        return format_string($row->name);
    }

    /**
     * Format state.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_state($row): string {
        return get_string('state' . str_replace('_', '', (string) $row->state), 'mod_selfselectadvanced');
    }

    /**
     * Format guide.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_guide($row): string {
        // ESCAPED AT SOURCE, like every other name-bearing cell in this
        // plugin's tables (the 2026-08-04 rule recorded on
        // flagged_missingattrs_table): fullname() returns names
        // unescaped, a name can carry markup through CSV upload, LDAP
        // sync or the user web service - only the profile form strips
        // tags - and flexible_table emits cell bodies verbatim into the
        // {{{tablehtml}}} this report renders. The sibling tables were
        // fixed then; this column arrived later with the 1.20.7 report
        // and was missed (audit F06).
        return empty($row->guideuserid) ? get_string('none') : s(fullname($row));
    }

    /**
     * Format mirror presence.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_mirrorpresent($row): string {
        return !empty($row->livecoregroupid) ? get_string('yes') : get_string('no');
    }

    /**
     * Format counts.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_counts($row): string {
        $report = coresync_backfill::report_row($this->activity, $row);

        return (int) $report->pluginmembercount . ' / ' . (int) $report->coremembercount;
    }

    /**
     * Format drift detail from the engine's drift report.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_drift($row): string {
        $report = coresync_backfill::report_row($this->activity, $row);

        return $report->driftlabel;
    }

    /**
     * Format last sync status.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_lastsyncstatus($row): string {
        $report = coresync_backfill::report_row($this->activity, $row);

        return $report->status;
    }

    /**
     * Format last sync time.
     *
     * @param \stdClass $row row
     * @return string
     */
    public function col_lastsynctime($row): string {
        $time = max((int) ($row->lastsuccess ?? 0), (int) ($row->lastfailure ?? 0));

        return $time ? userdate($time) : '';
    }
}
