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
 * The manager dashboard groups listing (spec 14.13, 4A.6, C12): core
 * table_sql with native sort, paging, state filter and download.
 * The Size column shows confirmed+pending against min-max.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groups_table extends \table_sql {
    /** @var \mod_selfselectadvanced\activity The activity. */
    private $activity;

    /** @var \mod_selfselectadvanced\local\rules\gatekeeper The gatekeeper. */
    private $gatekeeper;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \mod_selfselectadvanced\activity $activity the activity
     * @param \mod_selfselectadvanced\local\rules\gatekeeper $gatekeeper for seat positions
     * @param \moodle_url $baseurl page url
     * @param string $statefilter '' or a state name
     * @param bool $download whether a download is in progress
     */
    public function __construct(
        string $uniqueid,
        \mod_selfselectadvanced\activity $activity,
        \mod_selfselectadvanced\local\rules\gatekeeper $gatekeeper,
        \moodle_url $baseurl,
        string $statefilter,
        bool $download
    ) {
        parent::__construct($uniqueid);
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;

        $columns = ['name', 'pluginuid', 'state', 'leadername', 'guidename', 'size', 'penaltyvalue'];
        $headers = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('leader', 'mod_selfselectadvanced'),
            get_string('guidelabelplain', 'mod_selfselectadvanced'),
            get_string('size', 'mod_selfselectadvanced'),
            get_string('ledgerpenalty', 'mod_selfselectadvanced'),
        ];
        if (!$download) {
            $columns[] = 'actions';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'name');
        $this->no_sorting('size');
        $this->no_sorting('actions');
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);

        $where = 'g.activityid = :activityid';
        $params = ['activityid' => $activity->id()];
        if ($statefilter !== '') {
            $where .= ' AND g.state = :statefilter';
            $params['statefilter'] = $statefilter;
        }
        $leaderfields = 'l.firstname AS leaderfirst, l.lastname AS leaderlast';
        $this->set_sql(
            "g.id, g.name, g.pluginuid, g.state, g.leaderid, g.guideid, p.penaltyvalue,
             $leaderfields, gu.firstname AS guidefirst, gu.lastname AS guidelast",
            '{selfselectadvanced_group} g
             JOIN {user} l ON l.id = g.leaderid
             LEFT JOIN {user} gu ON gu.id = g.guideid
             LEFT JOIN {selfselectadvanced_penalty} p ON p.groupid = g.id',
            $where,
            $params
        );
    }

    /**
     * Group name.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_name($row) {
        return format_string($row->name);
    }

    /**
     * Localised state.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_state($row) {
        return get_string('state' . str_replace('_', '', $row->state), 'mod_selfselectadvanced');
    }

    /**
     * Leader name.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_leadername($row) {
        return $row->leaderfirst . ' ' . $row->leaderlast;
    }

    /**
     * Guide name.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_guidename($row) {
        return $row->guideid ? ($row->guidefirst . ' ' . $row->guidelast) : '';
    }

    /**
     * Size against the effective band (4A.6).
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_size($row) {
        $seats = $this->gatekeeper->seat_position($row);

        return get_string('sizecell', 'mod_selfselectadvanced', $seats);
    }

    /**
     * Penalty value.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_penaltyvalue($row) {
        return $row->penaltyvalue === null ? '' : format_float((float) $row->penaltyvalue, 2);
    }

    /**
     * View and unfreeze actions.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_actions($row) {
        $out = \html_writer::link(
            new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $this->activity->cm()->id,
                'g' => $row->id,
            ]),
            get_string('view'),
            ['class' => 'btn btn-secondary btn-sm']
        );
        if ($row->state === \mod_selfselectadvanced\local\state::FROZEN) {
            $out .= ' ' . \html_writer::link(
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $this->activity->cm()->id,
                    'g' => $row->id,
                    'action' => 'unfreeze',
                ]),
                get_string('unfreeze', 'mod_selfselectadvanced'),
                ['class' => 'btn btn-warning btn-sm']
            );
        }

        return $out;
    }
}
