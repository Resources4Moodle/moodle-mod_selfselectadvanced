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
use mod_selfselectadvanced\local\state;

/**
 * Guides table (2026-07-24 change): its own sortable, filterable,
 * downloadable listing — guide identity, department, sub-department,
 * seat location and live guiding load — separated from the group
 * table so neither drowns the other at scale.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guides_table extends \table_sql {
    /** @var activity The activity. */
    private activity $activity;

    /** @var \mod_selfselectadvanced\local\override\resolver Effective-value reader. */
    private \mod_selfselectadvanced\local\override\resolver $resolver;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param int[] $guideids enrolled holders of the guide capability (page-resolved)
     * @param \moodle_url $baseurl page url (with active filters)
     * @param string $fdept department filter ('' = all)
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        array $guideids,
        \moodle_url $baseurl,
        string $fdept
    ) {
        global $DB;
        parent::__construct($uniqueid);
        $this->activity = $activity;
        $this->resolver = new \mod_selfselectadvanced\local\override\resolver($activity);

        $this->define_columns(['fullname', 'department', 'subdepartment', 'seatlocation', 'guiding']);
        $this->define_headers([
            get_string('guide', 'mod_selfselectadvanced'),
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
            get_string('attrseatlocation', 'mod_selfselectadvanced'),
            get_string('guideloads', 'mod_selfselectadvanced'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'lastname');
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $userfields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            array_merge(['id'], \core_user\fields::for_name()->get_required_fields())
        ));
        [$idsql, $idparams] = $DB->get_in_or_equal($guideids ?: [-1], SQL_PARAMS_NAMED, 'gid');
        [$statesql, $stateparams] = $DB->get_in_or_equal(
            [state::PENDING_GUIDE, state::FIRM, state::FROZEN],
            SQL_PARAMS_NAMED,
            'st'
        );
        $where = "u.id $idsql";
        $params = ['activityid' => $activity->id()] + $idparams + $stateparams;
        if ($fdept !== '') {
            $where .= ' AND ' . $DB->sql_equal('a.department', ':fdept', false, false);
            $params['fdept'] = $fdept;
        }
        $this->set_sql(
            "$userfields, a.department, a.subdepartment, a.seatlocation,
             (SELECT COUNT(1) FROM {selfselectadvanced_group} lg
               WHERE lg.guideid = u.id AND lg.activityid = :activityid AND lg.state $statesql) AS guiding",
            '{user} u LEFT JOIN {selfselectadvanced_userattr} a ON a.userid = u.id',
            $where,
            $params
        );
    }

    /**
     * Live load with the effective (override-resolved) cap.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_guiding($row) {
        $max = $this->resolver->effective_maxguided((int) $row->id)->value;

        return get_string('guideload', 'mod_selfselectadvanced', (object) [
            'used' => (int) $row->guiding,
            'max' => $max,
        ]);
    }

    /**
     * Blank attribute cells read as em-free dashes.
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
     * Seat location cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_seatlocation($row) {
        return $row->seatlocation !== null ? s($row->seatlocation) : '-';
    }
}
