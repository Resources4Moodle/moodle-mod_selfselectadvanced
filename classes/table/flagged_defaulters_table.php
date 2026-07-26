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
 * The flagged report's defaulters tab (audit round 6 item 6): core
 * table_sql over enrolled respond-capability holders, left joined to
 * a per-user confirmed-membership count, so the below-minimum test
 * and the name filter both run in SQL instead of a PHP array pass.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flagged_defaulters_table extends \table_sql {
    /** @var int Minimum memberships required, used to derive the shortfall column. */
    protected int $minmembership = 0;

    /** @var int Course module id, used to build the per-row staged-move action link. */
    protected int $cmid = 0;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param \moodle_url $baseurl page url (with active filters)
     * @param int $minmembership minimum confirmed memberships a student must hold
     * @param string $q name filter, '' = none
     * @param bool $canmanage whether the viewer holds mod/selfselectadvanced:manage
     *        (the table itself is visible to viewall holders, who may not be able to act,
     *        so the per-row action column only renders for managers)
     */
    public function __construct(
        string $uniqueid,
        activity $activity,
        \moodle_url $baseurl,
        int $minmembership,
        string $q,
        bool $canmanage = false
    ) {
        parent::__construct($uniqueid);

        $columns = ['fullname', 'has', 'missing'];
        $headers = [
            get_string('member', 'mod_selfselectadvanced'),
            get_string('defaultershas', 'mod_selfselectadvanced'),
            get_string('defaultersmissing', 'mod_selfselectadvanced'),
        ];
        if ($canmanage) {
            $columns[] = 'action';
            $headers[] = get_string('actions');
        }
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'lastname');
        if ($canmanage) {
            $this->no_sorting('action');
        }
        $this->is_downloadable(false);
        $this->set_attribute('class', 'generaltable selfselectadvanced-defaulters');
        $this->cmid = $activity->cm()->id;

        [$from, $where, $params] = self::sql_parts($activity, $minmembership, $q);
        $namefields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        // The shortfall is derived in PHP from the confirmed count. A
        // second :minmembership placeholder here would be counted twice
        // while the value is supplied once, which the database rejects.
        $this->minmembership = $minmembership;
        $this->set_sql(
            "u.id, $namefields, COALESCE(mc.confirmedcount, 0) AS has",
            $from,
            $where,
            $params
        );
    }

    /**
     * Groups still to join, derived rather than queried.
     *
     * @param \stdClass $row the fetched row
     * @return int
     */
    public function col_missing($row): int {
        return max(0, $this->minmembership - (int) $row->has);
    }

    /**
     * Per-row staged-move action cell, manager-only: a link to
     * moveedit.php pre-filled with this student, the same staged-move
     * page the students tab's groupless list already links to.
     *
     * @param \stdClass $row the fetched row
     * @return string
     */
    public function col_action($row): string {
        $url = new \moodle_url('/mod/selfselectadvanced/moveedit.php', [
            'id' => $this->cmid,
            'student' => (int) $row->id,
        ]);

        return \html_writer::link($url, get_string('flagplace', 'mod_selfselectadvanced'), [
            'class' => 'btn btn-primary btn-sm',
        ]);
    }

    /**
     * Cheap row count for the tab label, sharing the display query's
     * FROM/WHERE so the label always agrees with what the table shows.
     *
     * @param activity $activity the activity
     * @param int $minmembership minimum confirmed memberships a student must hold
     * @param string $q name filter, '' = none
     * @return int
     */
    public static function count_rows(activity $activity, int $minmembership, string $q): int {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $minmembership, $q);

        return $DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * The full (unpaginated, filtered) raw-value dataset for export,
     * built from the same FROM/WHERE as the display table.
     *
     * @param activity $activity the activity
     * @param int $minmembership minimum confirmed memberships a student must hold
     * @param string $q name filter, '' = none
     * @return array[] rows of [fullname, has, missing]
     */
    public static function export_rows(activity $activity, int $minmembership, string $q): array {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $minmembership, $q);
        $namefields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $records = $DB->get_records_sql(
            "SELECT u.id, $namefields, COALESCE(mc.confirmedcount, 0) AS has
               FROM $from
              WHERE $where
           ORDER BY u.lastname, u.firstname",
            $params
        );

        return array_map(
            static fn($record) => [
                fullname($record),
                (int) $record->has,
                max(0, $minmembership - (int) $record->has),
            ],
            array_values($records)
        );
    }

    /**
     * The distinct recipient ids for the "Message all defaulters" bulk
     * action: exactly the currently filtered rows, one id per student
     * since each row is already one user (never duplicated).
     *
     * @param activity $activity the activity
     * @param int $minmembership minimum confirmed memberships a student must hold
     * @param string $q name filter, '' = none
     * @return int[] user ids
     */
    public static function recipient_ids(activity $activity, int $minmembership, string $q): array {
        global $DB;

        [$from, $where, $params] = self::sql_parts($activity, $minmembership, $q);
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT u.id FROM $from WHERE $where", $params);

        return array_map('intval', $ids);
    }

    /**
     * Build the FROM/WHERE/params shared by the display query, the
     * tab-label count and the export dataset.
     *
     * @param activity $activity the activity
     * @param int $minmembership minimum confirmed memberships a student must hold
     * @param string $q name filter, '' = none
     * @return array{0: string, 1: string, 2: array} from, where, params
     */
    private static function sql_parts(activity $activity, int $minmembership, string $q): array {
        global $DB;

        [$enrolledsql, $params] = get_enrolled_sql($activity->context(), 'mod/selfselectadvanced:respond');
        $params['activityid'] = $activity->id();
        $params['confirmed'] = groups::STATUS_CONFIRMED;
        $params['minmembership'] = $minmembership;

        $from = "{user} u
                 JOIN ($enrolledsql) eu ON eu.id = u.id
                 LEFT JOIN (
                     SELECT m.userid, COUNT(1) AS confirmedcount
                       FROM {selfselectadvanced_member} m
                       JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                      WHERE g.activityid = :activityid AND m.status = :confirmed
                   GROUP BY m.userid
                 ) mc ON mc.userid = u.id";
        $where = 'u.deleted = 0 AND COALESCE(mc.confirmedcount, 0) < :minmembership';
        if ($q !== '') {
            $where .= ' AND ' . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':q', false, false);
            $params['q'] = '%' . $DB->sql_like_escape($q) . '%';
        }

        return [$from, $where, $params];
    }
}
