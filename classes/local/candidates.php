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
 * review item S6) and, for viewers the identity gate admits, the email
 * address.
 *
 * CONTACT PRIVACY overrides the old A14/S7 rule here. Matching by
 * address and printing it are ONE gate now, because an oracle is a leak
 * even when nothing is rendered: typing a full address and getting back
 * exactly one person confirms that address belongs to that person and
 * names them - the inverse mapping, handed to a viewer who is not
 * allowed the forward one. So while {@see contactprivacy} protects the
 * activity, EVERY viewer searches names only and sees no address on any
 * label - maintainer decision 24, which removed the last exemption:
 * until 1.20.1 a :manage holder, and a role granted
 * :viewparticipantidentity, still matched and still saw the address
 * while the switch was on.
 *
 * AND-ORDER RULE (good-neighbour principle). The plugin's own gate is
 * AND-ed onto the two core identity capabilities, never OR-ed: this
 * class can only ever REMOVE an address from a label, never restore one
 * the SITE withheld. Two facts make the composition non-obvious and are
 * recorded here rather than rediscovered:
 *
 * - the two core capabilities are ALTERNATIVES, so preventing only
 *   moodle/site:viewuseridentity leaves addresses printing, because
 *   moodle/course:viewhiddenuserfields is still granted to
 *   teacher/editingteacher/manager in core. A lockdown runbook naming
 *   one capability ships half-done;
 * - with the switch ON the address is appended for NOBODY, so the
 *   core arm is the only thing the switch-OFF case still depends on.
 *
 * Accepted residual: a partial email that collides with a display-name
 * substring still matches. That reveals nothing an enrolled user's name
 * search does not already show.
 *
 * classes/external/search_candidates.php is the student-leader path
 * into search() and INHERITS all of this - it needs no gate of its own,
 * and adding a second one would put the plugin back where it started.
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
     * @param int $viewerid the searching user (identity gating: both the
     *        email match condition and the email label)
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

        $protect = contactprivacy::enabled($activity);
        // MAINTAINER DECISION 24 (2026-08-02): while the per-activity
        // switch is ON there is no exempt viewer. Not the editing
        // teacher, not the manager, not the administrator, not a role
        // a site granted :viewparticipantidentity - no surface of this
        // plugin matches, renders, exports or labels an address for
        // ANY role. This used to read "!$protect OR is_unrestricted()
        // OR :viewparticipantidentity",
        // on the argument that :manage owns the switch and may
        // therefore see through it; the audit found the same question
        // answered the other way on eoilist.php and on
        // search_participants, and the maintainer ruled for the strict
        // answer. The switch is now the whole test.
        //
        // :viewparticipantidentity is NOT dead: it still governs the
        // mobile columns through contactprivacy::mobile_consent_bypass()
        // and the identity columns of the staff tables. What it no
        // longer does is reopen an address.
        //
        // What survives from the previous design, and must: the
        // remaining factor is AND-ed onto the two core identity
        // capabilities below, never OR-ed, so this class can only ever
        // REMOVE an address from a label and can never restore one the
        // SITE withheld (good-neighbour principle).
        $mayseeidentity = !$protect;

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
        // The MATCH moves onto the same gate as the DISPLAY. Splitting
        // them is what left the oracle open: the label was gated and the
        // query was not.
        if ($mayseeidentity) {
            $param = 'q' . $i++;
            $conditions[] = $DB->sql_like('email', ':' . $param, false, false);
            $params[$param] = '%' . $DB->sql_like_escape($query) . '%';
        }

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

        // AND-order, and the order is the good-neighbour principle in
        // code: the two core capabilities remain an unconditional
        // factor, so the plugin's gate can only ever remove the
        // address. NEVER invert this to an OR.
        $showemail = $mayseeidentity
            && (has_capability('moodle/site:viewuseridentity', $context, $viewerid)
                || has_capability('moodle/course:viewhiddenuserfields', $context, $viewerid));
        // One bulk connection lookup for the page, not one per row.
        $privacymap = ($showemail && $protect)
            ? contactprivacy::can_see_map($activity, $viewerid, array_keys($users))
            : null;

        $results = [];
        foreach ($users as $user) {
            $label = fullname($user);
            if ($showemail && ($privacymap === null || !empty($privacymap[(int) $user->id]))) {
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
