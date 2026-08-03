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

/**
 * The site-admin listing of ingested participant attributes: core
 * table_sql with native sorting, paging and download (C12).
 *
 * THE PHONE NUMBER DOES NOT LEAVE THE SCREEN (1.20.1 wave 3D). This
 * listing has no course and no activity scope, so a download here can
 * match every ingested attribute row on the site - one file, every
 * student's mobile number, no connection and no consent anywhere in the
 * decision. That is the BULK EXTRACTION the contact-privacy cardinal
 * rule forbids without qualification, and it forbids it for the site
 * administrator too: this page's audience is the one viewer for whom
 * every capability check answers yes, so a capability was never going
 * to be what stopped it. The mobile column is therefore built only when
 * the table is rendering, and the download does not even SELECT
 * a.mobile - a column that is never fetched cannot be printed by a
 * later edit or iterated out of the record by a formatter.
 *
 * The column STAYS on screen, and the argument for the asymmetry is the
 * one the rule itself makes. On screen the number is paged (twenty rows
 * by default), read by an administrator who is about to correct or
 * delete that one row, and it goes no further than the session; the
 * edit form at attributes.php has to show the value it is editing in
 * any case, so removing the column would hide the field from the only
 * screen that repairs a bad import while leaving it perfectly readable
 * one click away. A spreadsheet is the opposite of all of that: it is
 * unpaged, it is the whole site, it outlives the session and it is the
 * easiest thing in the world to forward. What the rule bans is the
 * BULK copy, not the administrator's sight of one row.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attributes_table extends \table_sql {
    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl page url for sorting/paging links
     * @param bool $download whether a download is in progress
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl, bool $download) {
        parent::__construct($uniqueid);

        $columns = ['fullname', 'username', 'gender', 'department', 'subdepartment'];
        $headers = [
            get_string('fullname'),
            get_string('username'),
            get_string('attrgender', 'mod_selfselectadvanced'),
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
        ];
        // Screen only. See the class comment: the consent STATE still
        // exports, because a consent audit is a legitimate site-wide
        // question and the answer is a yes or a no, not a number.
        if (!$download) {
            $columns[] = 'mobile';
            $headers[] = get_string('attrmobile', 'mod_selfselectadvanced');
        }
        $columns = array_merge($columns, ['shareconsent', 'timemodified']);
        $headers = array_merge($headers, [
            get_string('shareconsent', 'mod_selfselectadvanced'),
            get_string('lastmodified'),
        ]);
        if (!$download) {
            $columns[] = 'actions';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'lastname');
        $this->no_sorting('shareconsent');
        $this->no_sorting('actions');
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $namefields = implode(', ', array_map(
            static fn($field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $mobilefield = $download ? '' : 'a.mobile, ';
        $this->set_sql(
            "a.id, a.userid, a.gender, a.department, a.subdepartment, {$mobilefield}a.shareconsent, a.timemodified,
             u.username, $namefields",
            '{selfselectadvanced_userattr} a JOIN {user} u ON u.id = a.userid',
            'u.deleted = 0'
        );
        $this->set_count_sql(
            'SELECT COUNT(1) FROM {selfselectadvanced_userattr} a JOIN {user} u ON u.id = a.userid WHERE u.deleted = 0'
        );
    }

    /**
     * Query the database for this table's rows.
     *
     * On-screen paging delegates entirely to the parent implementation,
     * unchanged. A download instead streams the result via a recordset:
     * this listing has no course or activity scope, so on a large site
     * a download can match every ingested attribute row site-wide, and
     * table_sql's default download path would materialise all of them
     * into memory at once (SCALE fix).
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
     * Render the full name from the user name fields.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_fullname($row) {
        return fullname($row);
    }

    /**
     * Whether the participant has consented to share their mobile
     * number with the people the activity connects them to. A yes or a
     * no, never the number - which is why this column survives into the
     * download while the number itself does not.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_shareconsent($row) {
        return !empty($row->shareconsent) ? get_string('yes') : get_string('no');
    }

    /**
     * Format the modification time.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_timemodified($row) {
        return $row->timemodified ? userdate($row->timemodified) : '';
    }

    /**
     * Edit action link.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_actions($row) {
        $url = new \moodle_url('/mod/selfselectadvanced/attributes.php', [
            'action' => 'edit',
            'u' => $row->userid,
        ]);

        return \html_writer::link($url, get_string('edit'), ['class' => 'btn btn-secondary btn-sm']);
    }
}
