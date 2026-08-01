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
use mod_selfselectadvanced\local\groups;

/**
 * Leader/member roster (2026-07-24 change): one row per membership —
 * group, state, person, role, department — sortable, filterable by
 * group state and role, downloadable.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster_table extends \table_sql {
    /** @var int Course-module id, for the per-row Move action link. */
    private int $cmid;

    /** @var activity The activity, for the Send-a-message link. */
    private activity $activity;

    /** @var \moodle_url Where a sent message returns to. */
    private \moodle_url $returnurl;

    /** @var bool Whether the viewer may stage a move (manager only). */
    private bool $canmanage;

    /** @var bool Whether the viewer may message a participant. */
    private bool $canmessage;

    /** @var int The viewing user: nobody messages themself. */
    private int $viewerid;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param \moodle_url $baseurl page url (with active filters)
     * @param string $fstate group-state filter ('' = all)
     * @param string $frole 'leader'|'member'|'' filter
     * @param bool $canmanage whether the viewer holds mod/selfselectadvanced:manage
     *        (the page itself is visible to viewall holders, who may not be able to act,
     *        so the per-row Move action only renders for managers)
     * @param bool $download whether a download is in progress (the action column is UI-only)
     * @param bool $canmessage whether the viewer may send a Moodle message to a
     *        participant of this activity - the REACH verdict
     *        (:manage or :viewall), computed ONCE for the page rather than
     *        per row, and re-checked by the service before anything is sent
     * @param int $viewerid the viewing user, so the action is not offered on their own row
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        \moodle_url $baseurl,
        string $fstate,
        string $frole,
        bool $canmanage = false,
        bool $download = false,
        bool $canmessage = false,
        int $viewerid = 0
    ) {
        global $DB;
        parent::__construct($uniqueid);

        $this->cmid = $activity->cm()->id;
        $this->activity = $activity;
        $this->returnurl = $baseurl;
        $this->canmanage = $canmanage;
        $this->canmessage = $canmessage;
        $this->viewerid = $viewerid;

        $showaction = ($canmanage || $canmessage) && !$download;
        $columns = ['groupname', 'state', 'fullname', 'role', 'department', 'subdepartment'];
        $headers = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('member', 'mod_selfselectadvanced'),
            get_string('rosterrole', 'mod_selfselectadvanced'),
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
        ];
        if ($showaction) {
            $columns[] = 'action';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'groupname');
        if ($showaction) {
            $this->no_sorting('action');
        }
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $userfields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $where = 'g.activityid = :activityid AND m.status = :confirmed';
        $params = [
            'activityid' => $activity->id(),
            'confirmed' => groups::STATUS_CONFIRMED,
        ];
        if ($fstate !== '') {
            $where .= ' AND g.state = :fstate';
            $params['fstate'] = $fstate;
        }
        if ($frole === 'leader') {
            $where .= ' AND m.isleader = 1';
        } else if ($frole === 'member') {
            $where .= ' AND m.isleader = 0';
        }
        $this->set_sql(
            "m.id, u.id AS userid, $userfields, m.isleader,
             g.name AS groupname, g.state, a.department, a.subdepartment",
            '{selfselectadvanced_member} m
             JOIN {selfselectadvanced_group} g ON g.id = m.groupid
             JOIN {user} u ON u.id = m.userid
             LEFT JOIN {selfselectadvanced_userattr} a ON a.userid = u.id',
            $where,
            $params
        );
    }

    /**
     * Query the database for this table's rows.
     *
     * On-screen paging delegates entirely to the parent implementation,
     * unchanged. A download instead streams the result via a recordset
     * rather than materialising every membership row of the activity in
     * memory at once (SCALE fix), the same treatment as attributes_table.
     *
     * @param int $pagesize page size for the on-screen table
     * @param bool $useinitialsbar whether to show the initials bar
     * @return void
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;

        if (!$this->is_downloading()) {
            parent::query_db($pagesize, $useinitialsbar);
            return;
        }

        $sort = $this->get_sql_sort();
        if ($sort) {
            $sort = "ORDER BY $sort";
        }
        $sql = "SELECT {$this->sql->fields}
                  FROM {$this->sql->from}
                 WHERE {$this->sql->where}
                       $sort";
        $this->rawdata = $DB->get_recordset_sql($sql, $this->sql->params);
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
     * Role cell — never colour alone.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_role($row) {
        return get_string((int) $row->isleader === 1 ? 'leader' : 'member', 'mod_selfselectadvanced');
    }

    /**
     * Department cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_department($row) {
        return $row->department !== null ? s($row->department) : '-';
    }

    /**
     * Sub-department cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_subdepartment($row) {
        return $row->subdepartment !== null ? s($row->subdepartment) : '-';
    }

    /**
     * Per-row actions: the manager-only Move link (UX audit item 5),
     * and Send a message - a MOODLE MESSAGE to this participant, which
     * is how staff reach a student now that no surface of this plugin
     * shows an address (maintainer decision 18).
     *
     * Both verdicts were computed once for the whole page in the
     * constructor; nothing here asks a capability or runs a query per
     * row.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_action($row) {
        $actions = [];
        if ($this->canmanage) {
            $actions[] = \html_writer::link(
                new \moodle_url('/mod/selfselectadvanced/moveedit.php', [
                    'id' => $this->cmid,
                    'student' => (int) $row->userid,
                ]),
                get_string('move'),
                ['class' => 'btn btn-outline-primary btn-sm']
            );
        }
        if ($this->canmessage && (int) $row->userid !== $this->viewerid) {
            $actions[] = \mod_selfselectadvanced\local\staffmessage::link(
                $this->activity,
                (int) $row->userid,
                $this->returnurl
            );
        }

        return implode(' ', $actions);
    }
}
