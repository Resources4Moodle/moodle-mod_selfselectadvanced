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

        $columns = ['fullname', 'username', 'gender', 'department', 'subdepartment', 'mobile', 'timemodified'];
        $headers = [
            get_string('fullname'),
            get_string('username'),
            get_string('attrgender', 'mod_selfselectadvanced'),
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
            get_string('attrmobile', 'mod_selfselectadvanced'),
            get_string('lastmodified'),
        ];
        if (!$download) {
            $columns[] = 'actions';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'lastname');
        $this->no_sorting('actions');
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $namefields = implode(', ', array_map(
            static fn($field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $this->set_sql(
            "a.id, a.userid, a.gender, a.department, a.subdepartment, a.mobile, a.timemodified,
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
