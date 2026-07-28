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

namespace mod_selfselectadvanced\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_selfselectadvanced\activity;

/**
 * AJAX provider for the manager's move-form student pickers.
 *
 * Core's site-wide user selector requires moodle/user:viewalldetails in
 * the SYSTEM context, which a coordinator holding their role inside one
 * course can never satisfy, so the move form could not be used at all
 * on a stock site. This provider searches only the participants of THIS
 * activity and is authorised by the plugin's own manage capability in
 * the module context, where a course coordinator does hold it.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_participants extends external_api {
    /** @var int Most rows a single search returns. */
    private const LIMIT = 50;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'query' => new external_value(PARAM_RAW_TRIMMED, 'Search text'),
        ]);
    }

    /**
     * Search this activity's participants by name or identifier.
     *
     * @param int $cmid course module id
     * @param string $query search text
     * @return array[] matching participants
     */
    public static function execute(int $cmid, string $query): array {
        global $DB;

        [
            'cmid' => $cmid,
            'query' => $query,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'query' => $query,
        ]);

        $activity = activity::from_cmid($cmid);
        $context = $activity->context();
        self::validate_context($context);
        require_capability('mod/selfselectadvanced:manage', $context);

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // Only people who can take part in THIS activity are offered.
        [$enrolsql, $enrolparams] = get_enrolled_sql($context, 'mod/selfselectadvanced:respond', 0, true);

        $namefields = \core_user\fields::for_name()->get_required_fields();
        $conditions = [];
        $params = $enrolparams;
        $index = 0;
        foreach ($namefields as $field) {
            $name = 'sp' . $index++;
            $conditions[] = $DB->sql_like('u.' . $field, ':' . $name, false, false);
            $params[$name] = '%' . $DB->sql_like_escape($query) . '%';
        }
        $name = 'sp' . $index++;
        $conditions[] = $DB->sql_like(
            $DB->sql_concat_join("' '", ['u.firstname', 'u.lastname']),
            ':' . $name,
            false,
            false
        );
        $params[$name] = '%' . $DB->sql_like_escape($query) . '%';
        $name = 'sp' . $index++;
        $conditions[] = $DB->sql_like('u.email', ':' . $name, false, false);
        $params[$name] = '%' . $DB->sql_like_escape($query) . '%';

        $selects = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $rows = $DB->get_records_sql(
            "SELECT u.id, u.email $selects
               FROM {user} u
               JOIN ($enrolsql) eu ON eu.id = u.id
              WHERE u.deleted = 0 AND (" . implode(' OR ', $conditions) . ")
           ORDER BY u.lastname, u.firstname",
            $params,
            0,
            self::LIMIT
        );

        // The team each person is in, so the coordinator can see at a
        // glance who they are about to move and from where.
        $teams = [];
        if ($rows) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'mv');
            $inparams['spactivityid'] = $activity->id();
            $inparams['spconfirmed'] = \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED;
            $memberships = $DB->get_records_sql(
                "SELECT m.userid, g.name
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                  WHERE g.activityid = :spactivityid AND m.status = :spconfirmed
                    AND m.userid $insql",
                $inparams
            );
            foreach ($memberships as $membership) {
                $teams[(int) $membership->userid] = $membership->name;
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $label = fullname($row);
            if (isset($teams[(int) $row->id])) {
                $label .= ' - ' . get_string('moveinteam', 'mod_selfselectadvanced', $teams[(int) $row->id]);
            } else {
                $label .= ' - ' . get_string('movenoteam', 'mod_selfselectadvanced');
            }
            $results[] = ['id' => (int) $row->id, 'label' => $label];
        }

        return $results;
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User id'),
                'label' => new external_value(PARAM_TEXT, 'Display label with the current team'),
            ])
        );
    }
}
