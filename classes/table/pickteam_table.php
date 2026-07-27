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
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;

/**
 * The pick-a-team browse listing (scalability rework): one row per
 * listed, still-forming, guideless-or-not team, native table_sql
 * sort/page/filter instead of loading every listed team's g.* (rich
 * text brief included) and cards in PHP. Default sort is timelisted
 * ASC, strict first-come-first-served.
 *
 * The interested column (pending EOI count) is left out of the
 * columns, the SELECT and the JOIN entirely when the activity's
 * eoisequential setting is on, so a browsing guide can never infer
 * live demand the leader has not yet been shown (spec).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pickteam_table extends \table_sql {
    /** @var int The course module id, for the name/action links. */
    private int $cmid;

    /** @var bool Whether the viewing guide has remaining guiding capacity (3b-iii); disables the Pick control when false. */
    private bool $hasbandwidth;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param \moodle_url $baseurl page url (with the active filter)
     * @param string $rq keyword filter over the team name or topic, '' = none
     * @param bool $sequential the activity's eoisequential setting: omits the interested column when true
     * @param bool $hasbandwidth the viewing guide's remaining capacity (eoi::remaining_capacity() > 0)
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        \moodle_url $baseurl,
        string $rq,
        bool $sequential,
        bool $hasbandwidth = true
    ) {
        global $DB;

        parent::__construct($uniqueid);

        $this->cmid = $activity->cm()->id;
        $this->hasbandwidth = $hasbandwidth;

        $columns = ['name', 'topic', 'leader', 'members'];
        $headers = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('worktitle', 'mod_selfselectadvanced'),
            get_string('leader', 'mod_selfselectadvanced'),
            get_string('size', 'mod_selfselectadvanced'),
        ];
        if (!$sequential) {
            $columns[] = 'interested';
            // No short header string exists for a per-team pending-interest
            // count; reuse the existing sentence string, its {$a} stripped.
            $headers[] = get_string('pickteaminterested', 'mod_selfselectadvanced');
        }
        $columns[] = 'timelisted';
        $columns[] = 'action';
        $headers[] = get_string('pickteamlistedsince', 'mod_selfselectadvanced');
        $headers[] = get_string('actions');

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'timelisted', SORT_ASC);
        $this->no_sorting('leader');
        $this->no_sorting('action');
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-pickteamtable');

        $namefields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));

        $fields = "g.id, g.name AS name, g.title AS topic, $namefields, COALESCE(m.cnt, 0) AS members";
        $from = '{selfselectadvanced_group} g
                  JOIN {user} u ON u.id = g.leaderid
             LEFT JOIN (SELECT groupid, COUNT(1) AS cnt
                          FROM {selfselectadvanced_member}
                         WHERE status = :confirmed
                      GROUP BY groupid) m ON m.groupid = g.id';
        $params = [
            'activityid' => $activity->id(),
            'listed' => 1,
            'forming' => state::FORMING,
            'confirmed' => groups::STATUS_CONFIRMED,
        ];

        if (!$sequential) {
            $fields .= ', COALESCE(e.cnt, 0) AS interested';
            $from .= '
             LEFT JOIN (SELECT groupid, COUNT(1) AS cnt
                          FROM {selfselectadvanced_eoi}
                         WHERE status = :pending
                      GROUP BY groupid) e ON e.groupid = g.id';
            $params['pending'] = eoi::STATUS_PENDING;
        }

        $fields .= ', g.timelisted AS timelisted';

        // Not filtered on guideid: a team that just got an accepted guide
        // stays listed and forming until its leader submits it, but
        // eoi::express() refuses it regardless (the sole gate, spec).
        // Teams whose leader already accepted a guide are no longer
        // pickable (express refuses them), so keep them out of the
        // browse table exactly as the old listing did.
        $where = 'g.activityid = :activityid AND g.listed = :listed AND g.state = :forming'
            . ' AND g.guideid IS NULL';
        if ($rq !== '') {
            $where .= ' AND (' . $DB->sql_like('g.name', ':rq1', false, false)
                . ' OR ' . $DB->sql_like('g.title', ':rq2', false, false) . ')';
            $params['rq1'] = '%' . $DB->sql_like_escape($rq) . '%';
            $params['rq2'] = '%' . $DB->sql_like_escape($rq) . '%';
        }

        $this->set_sql($fields, $from, $where, $params);
    }

    /**
     * Team name cell, linked to the single-team pick view.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_name($row) {
        $url = new \moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $this->cmid, 'g' => $row->id]);

        return \html_writer::link($url, format_string($row->name));
    }

    /**
     * Topic (group title) cell.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_topic($row) {
        return format_string($row->topic);
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
     * Confirmed-member count cell.
     *
     * @param \stdClass $row table row
     * @return int
     */
    public function col_members($row) {
        return (int) $row->members;
    }

    /**
     * Pending-EOI count cell; only defined when the column exists (eoisequential off).
     *
     * @param \stdClass $row table row
     * @return int
     */
    public function col_interested($row) {
        return (int) $row->interested;
    }

    /**
     * When the team was first listed.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_timelisted($row) {
        return $row->timelisted ? userdate((int) $row->timelisted) : '-';
    }

    /**
     * Pick-this-team action, linking to the single-team pick view.
     *
     * Disabled (3b-iii) once the viewing guide has no remaining
     * guiding capacity: eoi::express() would only refuse it server-side,
     * so the control is never offered live in the first place.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_action($row) {
        if (!$this->hasbandwidth) {
            return \html_writer::tag('button', get_string('eoipickteam', 'mod_selfselectadvanced'), [
                'type' => 'button',
                'class' => 'btn btn-primary btn-sm',
                'disabled' => 'disabled',
                'aria-disabled' => 'true',
            ]);
        }

        $url = new \moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $this->cmid, 'g' => $row->id]);

        return \html_writer::link(
            $url,
            get_string('eoipickteam', 'mod_selfselectadvanced'),
            ['class' => 'btn btn-primary btn-sm']
        );
    }
}
