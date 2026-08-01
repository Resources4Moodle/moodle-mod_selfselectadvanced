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
 * The default pool is the backend's own eligibility set - non-editing
 * teachers, decided by role ARCHETYPE, which is the only group that may
 * hold the role. "Every participant" widens the VIEW, for auditing who
 * holds it and for standing somebody down; it does not widen the
 * policy. Appointing anybody outside the set is refused by the service,
 * so no button is offered for them and the row says why. Display and
 * enforcement read the same list, computed once per request, rather
 * than each keeping its own idea of who counts.
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

    /** @var int[] User ids the backend would accept an appointment for. */
    private array $eligible;

    /** @var string[] userid => their other course roles, as one line. */
    private array $courseroles;

    /** @var bool Whether the viewer may see (and match on) the username identifier. */
    private bool $showusername = false;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param int $roleid the coordinator role
     * @param int[] $holders user ids holding it now
     * @param int[] $eligible user ids the backend would accept, from
     *      coordinatorimport::eligible_userids()
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
        array $eligible,
        array $courseroles,
        \moodle_url $baseurl,
        string $namefilter = '',
        string $rolefilter = 'teacher',
        string $download = ''
    ) {
        global $DB, $USER;

        parent::__construct($uniqueid);
        $this->activity = $activity;
        $this->roleid = $roleid;
        $this->holders = $holders;
        $this->eligible = array_map('intval', array_values($eligible));
        $this->courseroles = $courseroles;

        // The username column is an IDENTITY column, not a name: on a
        // site with email usernames it IS an address, and this table is
        // downloadable, so it puts one in a spreadsheet. It therefore
        // sits behind the same composition step 8 of the contact-privacy
        // work uses - the plugin's own identity permission AND-ed onto
        // core's, never OR-ed, so the plugin can only ever remove the
        // column and never restore one the SITE withheld. The page's
        // audience holds :manage, so is_unrestricted() carries them past
        // the plugin arm; when a site withdraws the core identity
        // capabilities the column (and the username filter below) go
        // with them. Note the two core capabilities are ALTERNATIVES:
        // preventing only moodle/site:viewuseridentity leaves the column
        // showing, because moodle/course:viewhiddenuserfields is still
        // granted to teacher/editingteacher/manager in core.
        $modulecontext = $activity->context();
        $mayseeidentity = !\mod_selfselectadvanced\local\contactprivacy::enabled($activity)
            || \mod_selfselectadvanced\local\contactprivacy::is_unrestricted($activity, (int) $USER->id)
            || has_capability('mod/selfselectadvanced:viewparticipantidentity', $modulecontext);
        $this->showusername = $mayseeidentity
            && (has_capability('moodle/site:viewuseridentity', $modulecontext)
                || has_capability('moodle/course:viewhiddenuserfields', $modulecontext));

        $columns = ['fullname'];
        $headers = [get_string('fullname')];
        if ($this->showusername) {
            $columns[] = 'username';
            $headers[] = get_string('username');
        }
        $columns = array_merge($columns, ['roles', 'iscoordinator', 'action']);
        $headers = array_merge($headers, [
            get_string('courserolescolumn', 'mod_selfselectadvanced'),
            get_string('coordinatorcolumn', 'mod_selfselectadvanced'),
            get_string('actions'),
        ]);
        $this->define_columns($columns);
        $this->define_headers($headers);
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
            // The eligibility predicate, verbatim: the ids the service
            // would accept, so what the default pool shows and what an
            // appointment does cannot diverge. Filtering by id rather
            // than repeating the SQL is the point - the previous
            // EXISTS-on-r.shortname was a second, quieter policy that
            // disagreed with the backend on any site that renamed its
            // non-editing-teacher role.
            if ($this->eligible) {
                [$insql, $inparams] = $DB->get_in_or_equal($this->eligible, SQL_PARAMS_NAMED, 'elig');
                $where .= " AND u.id $insql";
                $params += $inparams;
            } else {
                // Nobody qualifies: an impossible condition beats a
                // filter that quietly shows everybody instead.
                $where .= ' AND 1 = 0';
            }
        }
        if ($namefilter !== '') {
            $namelike = $DB->sql_like(
                $DB->sql_concat_join("' '", ['u.firstname', 'u.lastname']),
                ':namefilter',
                false,
                false
            );
            $params['namefilter'] = '%' . $DB->sql_like_escape($namefilter) . '%';
            // The MATCH follows the DISPLAY onto one gate: a viewer who
            // may not be shown the identifier may not confirm one by
            // typing it either. Matching without rendering is still an
            // oracle.
            if ($this->showusername) {
                $userlike = $DB->sql_like('u.username', ':userfilter', false, false);
                $where .= " AND ($namelike OR $userlike)";
                $params['userfilter'] = '%' . $DB->sql_like_escape($namefilter) . '%';
            } else {
                $where .= " AND ($namelike)";
            }
        }

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $this->set_sql(
            $this->showusername ? "u.id, u.username, $namefields" : "u.id, $namefields",
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
     * A holder always gets Remove, even one today's rule would not
     * appoint: a grandfathered appointment has to be undoable. Somebody
     * who is neither a holder nor eligible gets no form at all - the
     * service would refuse the POST, and a button that only ever
     * produces an error message is worse than saying so up front.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_action($row): string {
        if ($this->is_downloading()) {
            return '';
        }
        $holds = in_array((int) $row->id, $this->holders, true);
        if (!$holds && !in_array((int) $row->id, $this->eligible, true)) {
            return \html_writer::span(
                get_string('coordinatornoteligible', 'mod_selfselectadvanced'),
                'text-muted'
            );
        }
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
