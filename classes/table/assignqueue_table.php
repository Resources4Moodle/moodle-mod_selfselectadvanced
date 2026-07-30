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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Teams waiting for a guide, or waiting to have one changed
 * (strategy 1.17 C1).
 *
 * These two lists used to render as plain tables with every row on one
 * page. At the scale this activity is built for - 1500 teams and more -
 * that is unusable and easy to overlook below the fold, so both are now
 * ordinary sortable, filterable, paged tables like the group list above
 * them, each in its own tab.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignqueue_table extends \table_sql {
    /** @var string Teams with no guide at all, waiting to be given one. */
    public const MODE_UNASSIGNED = 'unassigned';

    /** @var string Teams that have a guide, who may be changed. */
    public const MODE_REASSIGN = 'reassign';

    /** @var activity The activity. */
    private activity $activity;

    /** @var string Which of the two lists this is. */
    private string $mode;

    /** @var bool Whether any guide is available to be assigned at all. */
    private bool $hasguides;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param string $mode self::MODE_*
     * @param bool $hasguides whether any guide has room, to tell an empty picker from an empty roster
     * @param \moodle_url $baseurl page url
     * @param string $namefilter '' or a fragment of the team name
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        string $mode,
        bool $hasguides,
        \moodle_url $baseurl,
        string $namefilter = ''
    ) {
        global $DB;

        parent::__construct($uniqueid);
        $this->activity = $activity;
        $this->mode = $mode;
        $this->hasguides = $hasguides;

        $columns = ['name', 'pluginuid', 'title', 'size'];
        $headers = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('worktitle', 'mod_selfselectadvanced'),
            get_string('size', 'mod_selfselectadvanced'),
        ];
        if ($mode === self::MODE_REASSIGN) {
            $columns[] = 'guidename';
            $headers[] = get_string('guidelabelplain', 'mod_selfselectadvanced');
        }
        $columns[] = 'assign';
        $headers[] = get_string('assignguide', 'mod_selfselectadvanced');

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'name');
        $this->no_sorting('size');
        $this->no_sorting('assign');
        $this->collapsible(false);

        $params = ['activityid' => $activity->id(), 'mcactivityid' => $activity->id()];
        if ($mode === self::MODE_UNASSIGNED) {
            $where = 'g.activityid = :activityid AND g.state = :pending AND g.guideid IS NULL';
            $params['pending'] = state::PENDING_GUIDE;
        } else {
            $where = 'g.activityid = :activityid AND g.guideid IS NOT NULL'
                . ' AND g.state IN (:pending, :firm, :frozen)';
            $params['pending'] = state::PENDING_GUIDE;
            $params['firm'] = state::FIRM;
            $params['frozen'] = state::FROZEN;
        }
        if ($namefilter !== '') {
            $where .= ' AND ' . $DB->sql_like('g.name', ':namefilter', false);
            $params['namefilter'] = '%' . $DB->sql_like_escape($namefilter) . '%';
        }

        $namefields = \core_user\fields::get_name_fields();
        $guidefields = implode(', ', array_map(static fn(string $f) => "gu.$f AS guide$f", $namefields));

        // The confirmed-member count rides along as an aggregate rather
        // than a query per row, and the derived table carries its own
        // activity parameter: the planner cannot push the join
        // qualifier through the GROUP BY, so without it every page
        // would count members across every activity on the site.
        $this->set_sql(
            "g.id, g.name, g.pluginuid, g.title, g.guideid, COALESCE(mc.confirmed, 0) AS size, {$guidefields}",
            "{selfselectadvanced_group} g
        LEFT JOIN {user} gu ON gu.id = g.guideid
        LEFT JOIN (SELECT m.groupid, COUNT(1) AS confirmed
                     FROM {selfselectadvanced_member} m
                     JOIN {selfselectadvanced_group} mg ON mg.id = m.groupid
                    WHERE mg.activityid = :mcactivityid AND m.status = 'confirmed'
                 GROUP BY m.groupid) mc ON mc.groupid = g.id",
            $where,
            $params
        );
    }

    /**
     * The team name.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_name($row): string {
        return \html_writer::link(
            new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $this->activity->cm()->id,
                'g' => $row->id,
            ]),
            format_string($row->name)
        );
    }

    /**
     * The title of work.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_title($row): string {
        return format_string($row->title);
    }

    /**
     * The guide this team has now.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_guidename($row): string {
        if (empty($row->guideid)) {
            return '-';
        }
        $guide = (object) ['id' => $row->guideid];
        foreach (\core_user\fields::get_name_fields() as $field) {
            $guide->$field = $row->{'guide' . $field} ?? '';
        }

        return fullname($guide);
    }

    /**
     * The picker and its button, posting to the page's assign action.
     *
     * @param \stdClass $row the row
     * @return string
     */
    public function col_assign($row): string {
        if (!$this->hasguides) {
            return \html_writer::span(get_string('noguidesavailable', 'mod_selfselectadvanced'), 'text-muted small');
        }
        $url = new \moodle_url('/mod/selfselectadvanced/manage.php', [
            'id' => $this->activity->cm()->id,
            'action' => 'assignguide',
        ]);

        // A searchable picker, not a list (strategy 1.18 B). The old
        // control rendered every guide with room as an <option>, once
        // per row: at 1500 guides and 50 rows that is 75,000 options on
        // one page, which is why this one starts empty and searches.
        // The picker sits in a wrapper of its own so that the enhanced
        // control - which core builds in place of the select, alongside
        // it - stays between the hidden fields and the button. Without
        // the wrapper the button renders BEFORE the control it acts on,
        // which reads backwards.
        return \html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false),
            'class' => 'd-flex gap-2 align-items-start flex-wrap'])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'g', 'value' => $row->id])
            . \html_writer::div(
                \mod_selfselectadvanced\local\guidepicker::render(
                    'guide',
                    (int) $this->activity->cm()->id,
                    0,
                    '',
                    true,
                    'ssa-guidepicker-' . $this->mode . '-' . (int) $row->id
                ),
                'selfselectadvanced-pickerwrap flex-grow-1'
            )
            . \html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                'value' => get_string('assign', 'mod_selfselectadvanced')])
            . \html_writer::end_tag('form');
    }
}
