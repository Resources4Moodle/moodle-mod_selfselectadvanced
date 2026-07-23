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

namespace mod_selfselectadvanced\local;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\rules\gatekeeper;

/**
 * The course-level candidate pool and its search (C10, U3).
 *
 * Pool: every user enrolled in the course holding the respond
 * capability, whether or not they ever opened the activity. Search
 * matches the full core name-field set (first/last/middle/alternate,
 * review item S6) OR the email address (decision A14/S7: matching for
 * all inviters; email display identity-gated).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class candidates {
    /** @var int Maximum results returned per search. */
    public const MAX_RESULTS = 20;

    /**
     * Search the candidate pool for a group's invitations.
     *
     * Every match is returned with its eligibility verdict and, when
     * ineligible, the localised reason (spec section 6.2: the selector
     * "filters out only users the rules make ineligible, and says why").
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group being invited into
     * @param gatekeeper $gatekeeper the rule gatekeeper
     * @param string $query search text
     * @param int $viewerid the searching user (email display gating)
     * @return array[] list of ['id', 'label', 'eligible', 'reason']
     */
    public static function search(
        activity $activity,
        \stdClass $group,
        gatekeeper $gatekeeper,
        string $query,
        int $viewerid
    ): array {
        global $DB;

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $context = $activity->context();
        [$enrolsql, $enrolparams] = get_enrolled_sql($context, 'mod/selfselectadvanced:respond', 0, true);

        // U3/S6: match across all core name fields, the full-name concat and email.
        $namefields = \core_user\fields::for_name()->get_required_fields();
        $conditions = [];
        $params = [];
        $i = 0;
        foreach ($namefields as $field) {
            $param = 'q' . $i++;
            $conditions[] = $DB->sql_like($field, ':' . $param, false, false);
            $params[$param] = '%' . $DB->sql_like_escape($query) . '%';
        }
        $param = 'q' . $i++;
        $conditions[] = $DB->sql_like(
            $DB->sql_concat_join("' '", ['firstname', 'lastname']),
            ':' . $param,
            false,
            false
        );
        $params[$param] = '%' . $DB->sql_like_escape($query) . '%';
        $param = 'q' . $i++;
        $conditions[] = $DB->sql_like('email', ':' . $param, false, false);
        $params[$param] = '%' . $DB->sql_like_escape($query) . '%';

        $fieldlist = implode(', ', array_map(static fn($f) => 'u.' . $f, array_unique(array_merge(
            ['id', 'email'],
            $namefields
        ))));
        $sql = "SELECT $fieldlist
                  FROM {user} u
                  JOIN ($enrolsql) eu ON eu.id = u.id
                 WHERE u.deleted = 0 AND u.suspended = 0
                   AND (" . implode(' OR ', $conditions) . ")
              ORDER BY u.lastname, u.firstname";
        $users = $DB->get_records_sql($sql, array_merge($enrolparams, $params), 0, self::MAX_RESULTS);

        $showemail = has_capability('moodle/site:viewuseridentity', $context, $viewerid)
            || has_capability('moodle/course:viewhiddenuserfields', $context, $viewerid);

        $results = [];
        foreach ($users as $user) {
            $label = fullname($user);
            if ($showemail) {
                $label .= ' (' . $user->email . ')';
            }
            $refusal = $gatekeeper->can_invite($group, (int) $user->id);
            $results[] = [
                'id' => (int) $user->id,
                'label' => $label,
                'eligible' => $refusal === null,
                'reason' => $refusal?->get_message() ?? '',
            ];
        }

        return $results;
    }
}
