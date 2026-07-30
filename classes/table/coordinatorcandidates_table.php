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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Who may hold the coordinator role, and who does (strategy 1.18 D).
 *
 * Modelled on Moodle's own role screens rather than on the upload form
 * that preceded it: appointing one or two people should not require a
 * spreadsheet, and the list of who holds the role was previously an
 * unpaged, unfiltered, undownloadable dump of every holder.
 *
 * Defaults to the course's non-editing teachers, who are the people a
 * coordinator is normally drawn from, and widens to every participant
 * on request - the role is not reserved to one course role, and an
 * appointment that cannot be made from this page would just send
 * somebody back to the spreadsheet.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coordinatorcandidates_table extends \table_sql {
    /** @var activity The activity. */
    private activity $activity;

    /** @var int The coordinator role id. */
    private int $roleid;

    /** @var int[] User ids holding the role now. */
    private array $holders;

    /** @var string[] userid => their other course roles, as one line. */
    private array $courseroles;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param int $roleid the coordinator role
     * @param int[] $holders user ids holding it now
     * @param string[] $courseroles userid => rendered role list
     * @param \moodle_url $baseurl page url (with active filters)
     * @param string $namefilter '' or a fragment of the name
     * @param string $rolefilter 'teacher' for non-editing teachers, 'all' for every participant,
     *      'coordinators' for those already holding the role
     * @param string $download '' to render, or the format the reader asked for
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        int $roleid,
        array $holders,
        array $courseroles,
        \moodle_url $baseurl,
        string $namefilter = '',
        string $rolefilter = 'teacher',
        string $download = ''
    ) {
        global $DB;

        parent::__construct($uniqueid);
        $this->activity = $activity;
        $this->roleid = $roleid;
        $this->holders = $holders;
        $this->courseroles = $courseroles;

        $this->define_columns(['fullname', 'username', 'roles', 'iscoordinator', 'action']);
        $this->define_headers([
            get_string('fullname'),
            get_string('username'),
            get_string('courserolescolumn', 'mod_selfselectadvanced'),
            get_string('coordinatorcolumn', 'mod_selfselectadvanced'),
            get_string('actions'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'lastname');
        $this->no_sorting('roles');
        $this->no_sorting('iscoordinator');
        $this->no_sorting('action');
        $this->collapsible(false);
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);
        if ($download !== '') {
            $this->is_downloading($download, \mod_selfselectadvanced\local\exporter::stamp('coordinators'));
        }

        $coursecontext = \context_course::instance($activity->cm()->course);
        [$enrolsql, $params] = get_enrolled_sql($coursecontext);

        $where = 'u.deleted = 0';
        if ($rolefilter === 'coordinators') {
            if ($holders) {
                [$insql, $inparams] = $DB->get_in_or_equal($holders, SQL_PARAMS_NAMED, 'hold');
                $where .= " AND u.id $insql";
                $params += $inparams;
            } else {
                // Nobody holds it: an impossible condition beats a
                // filter that quietly shows everybody instead.
                $where .= ' AND 1 = 0';
            }
        } else if ($rolefilter !== 'all') {
            // The course's non-editing teachers, which is the default:
            // the people a coordinator is normally drawn from.
            $where .= " AND EXISTS (SELECT 1
                                      FROM {role_assignments} ra
                                      JOIN {role} r ON r.id = ra.roleid
                                     WHERE ra.userid = u.id
                                       AND ra.contextid = :rolectx
                                       AND r.shortname = :teachershort)";
            $params['rolectx'] = $coursecontext->id;
            $params['teachershort'] = 'teacher';
        }
        if ($namefilter !== '') {
            $namelike = $DB->sql_like(
                $DB->sql_concat_join("' '", ['u.firstname', 'u.lastname']),
                ':namefilter',
                false,
                false
            );
            $userlike = $DB->sql_like('u.username', ':userfilter', false, false);
            $where .= " AND ($namelike OR $userlike)";
            $params['namefilter'] = '%' . $DB->sql_like_escape($namefilter) . '%';
            $params['userfilter'] = '%' . $DB->sql_like_escape($namefilter) . '%';
        }

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $this->set_sql(
            "u.id, u.username, $namefields",
            "{user} u JOIN ($enrolsql) eu ON eu.id = u.id",
            $where,
            $params
        );
    }

    /**
     * The person's name.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_fullname($row): string {
        return fullname($row);
    }

    /**
     * Their other roles in the course, as Moodle's own screens show them.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_roles($row): string {
        return $this->courseroles[(int) $row->id] ?? '-';
    }

    /**
     * Whether they hold the coordinator role.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_iscoordinator($row): string {
        $holds = in_array((int) $row->id, $this->holders, true);
        if ($this->is_downloading()) {
            return $holds ? get_string('yes') : get_string('no');
        }

        return $holds
            ? \html_writer::span(get_string('yes'), 'badge bg-success')
            : \html_writer::span(get_string('no'), 'text-muted');
    }

    /**
     * Appoint or remove, one person at a time.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_action($row): string {
        if ($this->is_downloading()) {
            return '';
        }
        $holds = in_array((int) $row->id, $this->holders, true);
        $url = new \moodle_url('/mod/selfselectadvanced/coordinators.php', ['id' => $this->activity->cm()->id]);

        return \html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline'])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
                'value' => $holds ? 'remove' : 'appoint'])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'u', 'value' => (int) $row->id])
            . \html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => $holds ? 'btn btn-outline-danger btn-sm' : 'btn btn-primary btn-sm',
                'value' => $holds
                    ? get_string('coordinatorremove', 'mod_selfselectadvanced')
                    : get_string('coordinatorappoint', 'mod_selfselectadvanced'),
            ])
            . \html_writer::end_tag('form');
    }
}
