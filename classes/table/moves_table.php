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
 * The pending staged moves queue (decision 6, D6-8).
 *
 * Replaces the un-paged moves_list template: that rendered EVERY
 * pending move of the activity into one form and validated the whole
 * set, which on the enrolment this plugin is built for is an unbounded
 * page and an unbounded joint validation.
 *
 * \flexible_table rather than \table_sql deliberately: the verdict
 * chips are computed by moves::validate_set() over the rows of ONE
 * page, jointly, which is not something a set_sql() query can express.
 * The caller fetches the page (limitfrom/limitnum), validates it and
 * hands the finished cells here.
 *
 * The queue is chronological, so there is no sorting: a "sort by
 * student" over one page of a queue would be a lie about the order the
 * moves are applied in.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moves_table extends \flexible_table {
    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param \moodle_url $baseurl the moves page url
     */
    public function __construct(string $uniqueid, \moodle_url $baseurl) {
        parent::__construct($uniqueid);

        $this->define_columns(['select', 'student', 'from', 'to', 'validation', 'actions']);
        $this->define_headers([
            get_string('select'),
            get_string('movestudent', 'mod_selfselectadvanced'),
            get_string('movefrom', 'mod_selfselectadvanced'),
            get_string('moveto', 'mod_selfselectadvanced'),
            get_string('movevalidation', 'mod_selfselectadvanced'),
            get_string('actions'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(false);
        $this->pageable(true);
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-moveslist');
        $this->setup();
    }

    /**
     * Render one already-fetched, already-validated page of the queue.
     *
     * The rows passed in ARE the page (the caller queried them with
     * limitfrom/limitnum), so nothing is sliced here; $total is what
     * the paging bar counts.
     *
     * @param \stdClass[] $rows cells built by moves.php: select, student,
     *        from, to, validation, actions - each already HTML
     * @param int $perpage rows per page
     * @param int $total pending moves in the whole activity
     */
    public function display_rows(array $rows, int $perpage, int $total): void {
        $this->pagesize($perpage, $total);
        foreach ($rows as $row) {
            $this->add_data_keyed([
                'select' => $row->select,
                'student' => $row->student,
                'from' => $row->from,
                'to' => $row->to,
                'validation' => $row->validation,
                'actions' => $row->actions,
            ]);
        }
        $this->finish_output();
    }

    /**
     * Team labels for exactly the ids on one page, in ONE query.
     *
     * The render loop used to call groups::get() twice per row, so the
     * cost of the labels grew with the queue (D6-8). It lives here
     * rather than in the page so the budget can be measured against the
     * code that actually runs.
     *
     * @param \mod_selfselectadvanced\activity $activity the activity
     * @param int[] $groupids the team ids in play
     * @return string[] group id => label
     */
    public static function group_labels(\mod_selfselectadvanced\activity $activity, array $groupids): array {
        global $DB;

        $groupids = array_values(array_unique(array_filter(array_map('intval', $groupids))));
        if (!$groupids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'mg');
        $params['activityid'] = $activity->id();
        $labels = [];
        $rows = $DB->get_records_select(
            'selfselectadvanced_group',
            "id $insql AND activityid = :activityid",
            $params,
            '',
            'id, name'
        );
        foreach ($rows as $row) {
            $labels[(int) $row->id] = format_string($row->name);
        }

        return $labels;
    }

    /**
     * Student names for exactly the ids on one page, in ONE query.
     *
     * Names only - never an email address or a phone number (cardinal
     * contact-privacy rule); this queue shows who is being moved and
     * nothing else about them.
     *
     * @param int[] $userids the students in play
     * @return string[] user id => full name
     */
    public static function user_labels(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mu');
        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', true)->selects;
        $labels = [];
        foreach ($DB->get_records_sql("SELECT id{$namefields} FROM {user} WHERE id $insql", $params) as $user) {
            $labels[(int) $user->id] = fullname($user);
        }

        return $labels;
    }
}
