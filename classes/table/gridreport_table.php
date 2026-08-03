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
use mod_selfselectadvanced\local\groups;

/**
 * The group grid report (item 5d): one row per group with the guide
 * and every confirmed member's last name in its own column.
 *
 * The column COUNT is fixed at the activity's own maxsize setting, not
 * at any per-group effective (override-resolved) maximum: a flexible_table
 * has one static set of columns for every row, so a single group whose
 * own effective_maxsize() was raised by an override cannot be allowed
 * to grow the whole table. The consequence, stated rather than left
 * to be found: such a group's members beyond the first (columns - 1)
 * wrap into the LAST member column as a comma-separated list instead
 * of getting a column of their own - see build_rows(). The leader
 * always occupies the first member column and is marked with a
 * trailing asterisk; a
 * footnote explaining the asterisk is the caller's responsibility
 * (gridreport.php), not this table's.
 *
 * Data assembly (build_rows()) issues exactly two queries regardless
 * of activity size: one for the groups themselves (with the guide's
 * name fields left-joined in), and one batched membership query
 * (chunked at 1000 group ids, mirroring
 * quota\evaluator::compliance_for_activity()) for every confirmed
 * member of every group. Sorting, filtering and pagination all then
 * happen in PHP over that one in-memory array, exactly like
 * flagged_quota_table and flagged_anomalies_table.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gridreport_table extends \flexible_table {
    /** @var int Number of member columns (the activity's maxsize setting, minimum 1). */
    private int $membercols;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl page url (with active filters)
     * @param int $membercols number of member columns to render (activity maxsize, minimum 1)
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl, int $membercols) {
        parent::__construct($uniqueid);

        $this->membercols = max(1, $membercols);

        $columns = ['name', 'state', 'guide'];
        $headers = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('gridguidecol', 'mod_selfselectadvanced'),
        ];
        for ($i = 1; $i <= $this->membercols; $i++) {
            $columns[] = 'member' . $i;
            $headers[] = get_string('gridmembercol', 'mod_selfselectadvanced', $i);
        }

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'name');
        $this->no_sorting('state');
        $this->no_sorting('guide');
        for ($i = 1; $i <= $this->membercols; $i++) {
            $this->no_sorting('member' . $i);
        }
        $this->pageable(true);
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-gridreport');
        $this->setup();
    }

    /**
     * Sort (name only - every other column is PHP-derived and would be
     * meaningless to order by), paginate and render an already-filtered
     * array of grid rows.
     *
     * @param \stdClass[] $rows entries with name, statelabel, guidename, membercells (see build_rows())
     * @param int $perpage rows per page
     */
    public function display_rows(array $rows, int $perpage): void {
        $sort = $this->get_sort_columns();
        if ($sort) {
            foreach (array_reverse($sort, true) as $column => $direction) {
                if ($column !== 'name') {
                    continue;
                }
                \core_collator::asort_objects_by_property($rows, 'name');
                $rows = array_values($rows);
                if ($direction === SORT_DESC) {
                    $rows = array_reverse($rows);
                }
            }
        }

        $this->pagesize($perpage, count($rows));
        foreach (array_slice($rows, $this->get_page_start(), $this->get_page_size()) as $row) {
            $data = [
                'name' => $row->name,
                'state' => $row->statelabel,
                'guide' => $row->guidename,
            ];
            foreach ($row->membercells as $index => $cell) {
                $data['member' . ($index + 1)] = $cell;
            }
            $this->add_data_keyed($data);
        }
        $this->finish_output();
    }

    /**
     * Build one row per group of the activity: the groups themselves
     * (with the guide's name fields) in one query, then every
     * confirmed member of every group in one batched query (chunked at
     * 1000 group ids), assembled in PHP into exactly $membercols member
     * cells per row - never a query per group or per row.
     *
     * Member ordering within a row: the leader first (marked with a
     * trailing asterisk), then the rest in joining order
     * (COALESCE(timeresponded, timecreated) ascending). When a group
     * holds more confirmed members than there are member columns (an
     * override having raised its own effective maximum beyond the
     * activity's maxsize), every member from the last column onward is
     * folded into that last column as a comma-separated list.
     *
     * @param activity $activity the activity
     * @param int $membercols number of member columns (activity maxsize, minimum 1)
     * @param string $rq name filter over the group name, '' = none
     * @return \stdClass[] rows with id, name, rawname, pluginuid, statelabel, guidename, membercells
     */
    public static function build_rows(activity $activity, int $membercols, string $rq): array {
        global $DB;

        $membercols = max(1, $membercols);
        $namefields = self::guide_name_fields();
        $guideselects = implode(', ', array_map(
            static fn(string $field) => "gu.$field AS guide$field",
            $namefields
        ));

        $where = 'g.activityid = :activityid';
        $params = ['activityid' => $activity->id()];
        if ($rq !== '') {
            $where .= ' AND ' . $DB->sql_like('g.name', ':rq', false, false);
            $params['rq'] = '%' . $DB->sql_like_escape($rq) . '%';
        }
        $groups = $DB->get_records_sql(
            "SELECT g.id, g.name, g.pluginuid, g.state, g.guideid, $guideselects
               FROM {selfselectadvanced_group} g
               LEFT JOIN {user} gu ON gu.id = g.guideid
              WHERE $where
           ORDER BY g.name",
            $params
        );

        $groupids = array_map(static fn($group) => (int) $group->id, $groups);
        $membersbygroup = array_fill_keys($groupids, []);
        foreach (array_chunk($groupids, 1000) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'gc');
            $inparams['confirmed'] = groups::STATUS_CONFIRMED;
            $memberrows = $DB->get_records_sql(
                "SELECT m.id, m.groupid, m.isleader, u.lastname
                   FROM {selfselectadvanced_member} m
                   JOIN {user} u ON u.id = m.userid
                  WHERE m.groupid $insql AND m.status = :confirmed
               ORDER BY m.groupid, m.isleader DESC, COALESCE(m.timeresponded, m.timecreated) ASC",
                $inparams
            );
            foreach ($memberrows as $memberrow) {
                $membersbygroup[(int) $memberrow->groupid][] = $memberrow;
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            $groupid = (int) $group->id;
            $cells = array_fill(0, $membercols, '-');
            $overflow = [];
            foreach ($membersbygroup[$groupid] as $index => $member) {
                $label = $member->lastname . (!empty($member->isleader) ? '*' : '');
                if ($index < $membercols - 1) {
                    $cells[$index] = $label;
                } else {
                    $overflow[] = $label;
                }
            }
            if ($overflow) {
                $cells[$membercols - 1] = implode(', ', $overflow);
            }

            $rows[] = (object) [
                'id' => $groupid,
                'name' => format_string($group->name),
                'rawname' => $group->name,
                'pluginuid' => $group->pluginuid,
                'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced'),
                'guidename' => $group->guideid ? fullname(self::guide_name_object($group)) : '-',
                'membercells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * The core name fields needed to build a guide's fullname(), the
     * same set flagged_guides_table joins in for the guide column of
     * the guides-pending tab.
     *
     * @return string[]
     */
    private static function guide_name_fields(): array {
        return \core_user\fields::for_name()->get_required_fields();
    }

    /**
     * Rebuild a name object from a group row's prefixed guide name
     * fields, suitable for passing to fullname().
     *
     * @param \stdClass $group group row with guide$field columns joined in
     * @return \stdClass
     */
    private static function guide_name_object(\stdClass $group): \stdClass {
        $name = new \stdClass();
        foreach (self::guide_name_fields() as $field) {
            $name->$field = $group->{"guide$field"};
        }

        return $name;
    }
}
