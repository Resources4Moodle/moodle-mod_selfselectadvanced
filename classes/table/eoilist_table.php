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
use context_module;

/**
 * A guide's own expressions of interest (EOI 1.11.0), one row per
 * interest they have raised, newest first, optionally filtered to a
 * single status by the guide dashboard's stat cards. The "view team"
 * action links back to eoilist.php with the group id so the caller can
 * render the member drill-down (spec: mailto, WhatsApp, mail-the-whole-team).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eoilist_table extends \table_sql {
    /** @var context_module The activity context, for format_text on remarks. */
    private context_module $context;

    /** @var int The course module id, for the view-team action link. */
    private int $cmid;

    /** @var string The active status filter, carried into the view-team link so its back link returns here. */
    private string $status;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param int $guideid the guide whose interests are listed
     * @param \moodle_url $baseurl page url (with the active status filter)
     * @param string $status status filter, '' = every status
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        int $guideid,
        \moodle_url $baseurl,
        string $status
    ) {
        parent::__construct($uniqueid);

        $this->context = $activity->context();
        $this->cmid = $activity->cm()->id;
        $this->status = $status;

        $this->define_columns(['groupname', 'leader', 'topic', 'remarks', 'status', 'timecreated', 'timeresponded', 'actions']);
        $this->define_headers([
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('leader', 'mod_selfselectadvanced'),
            get_string('worktitle', 'mod_selfselectadvanced'),
            get_string('eoiremarks', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('timecreated'),
            get_string('lastmodified'),
            get_string('actions'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->no_sorting('leader');
        $this->no_sorting('topic');
        $this->no_sorting('remarks');
        $this->no_sorting('actions');
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-eoilist');

        $namefields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $where = 'e.activityid = :activityid AND e.guideid = :guideid';
        $params = ['activityid' => $activity->id(), 'guideid' => $guideid];
        if ($status !== '') {
            $where .= ' AND e.status = :status';
            $params['status'] = $status;
        }
        $this->set_sql(
            "e.id, g.id AS groupid, g.name AS groupname, g.title AS topic,
             e.remarks, e.remarksformat, e.status, e.timecreated, e.timeresponded, $namefields",
            '{selfselectadvanced_eoi} e
             JOIN {selfselectadvanced_group} g ON g.id = e.groupid
             JOIN {user} u ON u.id = g.leaderid',
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
     * Leader full name cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_leader($row) {
        return fullname($row);
    }

    /**
     * Work title cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_topic($row) {
        return format_string($row->topic);
    }

    /**
     * Remarks cell, rich text rendered only through format_text.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_remarks($row) {
        if (trim((string) $row->remarks) === '') {
            return '-';
        }

        return format_text($row->remarks, (int) $row->remarksformat, ['context' => $this->context]);
    }

    /**
     * Localised status cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_status($row) {
        return get_string('eoistatus' . $row->status, 'mod_selfselectadvanced');
    }

    /**
     * When the interest was expressed.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_timecreated($row) {
        return $row->timecreated ? userdate((int) $row->timecreated) : '-';
    }

    /**
     * When the leader decided, or a dash while still pending.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_timeresponded($row) {
        return $row->timeresponded ? userdate((int) $row->timeresponded) : '-';
    }

    /**
     * View-team action, linking to the member drill-down on the same page.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_actions($row) {
        $params = array_filter(['id' => $this->cmid, 'viewgroup' => $row->groupid, 'status' => $this->status]);
        $url = new \moodle_url('/mod/selfselectadvanced/eoilist.php', $params);

        $actions = \html_writer::link(
            $url,
            get_string('eoimembers', 'mod_selfselectadvanced'),
            ['class' => 'btn btn-secondary btn-sm']
        );
        if ($row->status === \mod_selfselectadvanced\local\eoi::STATUS_PENDING) {
            // A pending interest can be taken back; the confirm step
            // lives on the page's withdraw action.
            $withdrawurl = new \moodle_url('/mod/selfselectadvanced/eoilist.php', array_filter([
                'id' => $this->cmid,
                'action' => 'withdraw',
                'eoiid' => $row->id,
                'status' => $this->status,
            ]));
            $actions .= ' ' . \html_writer::link(
                $withdrawurl,
                get_string('eoiwithdraw', 'mod_selfselectadvanced'),
                ['class' => 'btn btn-outline-secondary btn-sm ms-1']
            );
        }

        return $actions;
    }

    /**
     * The full raw-value dataset for export: the group name and status
     * as their raw codes, the leader's plain full name, timecreated and
     * timeresponded as raw unix timestamps, and remarks reduced to
     * plain text (never HTML) since a spreadsheet cell is not a rich
     * text renderer.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide whose interests are listed
     * @param string $status status filter, '' = every status
     * @return \stdClass[] rows with rawname, leader, status, timecreated, timeresponded, remarks
     */
    public static function export_rows(activity $activity, int $guideid, string $status): array {
        global $DB;

        $namefields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $where = 'e.activityid = :activityid AND e.guideid = :guideid';
        $params = ['activityid' => $activity->id(), 'guideid' => $guideid];
        if ($status !== '') {
            $where .= ' AND e.status = :status';
            $params['status'] = $status;
        }
        $records = $DB->get_records_sql(
            "SELECT e.id, g.name AS rawname, $namefields,
                    e.remarks, e.remarksformat, e.status, e.timecreated, e.timeresponded
               FROM {selfselectadvanced_eoi} e
               JOIN {selfselectadvanced_group} g ON g.id = e.groupid
               JOIN {user} u ON u.id = g.leaderid
              WHERE $where
           ORDER BY e.timecreated ASC",
            $params
        );

        $rows = [];
        foreach ($records as $record) {
            $rows[] = (object) [
                'rawname' => $record->rawname,
                'leader' => fullname($record),
                'status' => $record->status,
                'timecreated' => (int) $record->timecreated,
                'timeresponded' => (int) $record->timeresponded,
                'remarks' => trim((string) $record->remarks) !== ''
                    ? format_text_email($record->remarks, (int) $record->remarksformat)
                    : '',
            ];
        }

        return $rows;
    }
}
