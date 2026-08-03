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
 * review item S6) and NOTHING ELSE - never the email address, for any
 * viewer, in either state of the contact-privacy switch.
 *
 * CONTACT PRIVACY overrides the old A14/S7 rule here, and MATCHING and
 * PRINTING are now two different questions with two different answers.
 *
 * NOBODY MATCHES ON AN ADDRESS, IN EITHER SWITCH STATE (1.20.1 wave
 * 3D). An oracle is a leak even when nothing is rendered: type a full
 * address in, get back exactly one person, and you have confirmed that
 * the address belongs to that named account - the inverse mapping,
 * available to a student leader one query at a time and never rendered
 * anywhere a review could see it. The switch governs DISPLAY; it was
 * never a statement about whether a probe is possible, and gating the
 * probe on it made the picker grow an oracle the moment a teacher
 * changed a setting elsewhere in the activity. eoilist.php (T-07) and
 * {@see \mod_selfselectadvanced\external\search_participants} (O-2)
 * had already answered this question unconditionally; this class was
 * the last surface still answering it per setting.
 *
 * The LABEL keeps the switch, and that is deliberate rather than an
 * oversight: with the switch on no viewer sees an address on any label
 * (maintainer decision 24, which removed the last exemption - until
 * 1.20.1 a :manage holder, and a role granted
 * :viewparticipantidentity, still saw one); with it off, the two core
 * identity capabilities decide alone, which is what the setting is
 * for. Showing the address of somebody a viewer already found BY NAME
 * discloses; it does not confirm a guess.
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
     * @param int $viewerid the searching user (identity gating of the
     *        email LABEL; the match is names-only for everybody)
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
        // a site granted :viewparticipantidentity - WHILE THE SWITCH IS
        // ON, no page, picker, export or web service of this plugin
        // renders or labels an address for any role. This used to read
        // "!$protect OR is_unrestricted() OR :viewparticipantidentity",
        // on the argument that :manage owns the switch and may
        // therefore see through it; the audit found the same question
        // answered the other way on eoilist.php and on
        // search_participants, and the maintainer ruled for the strict
        // answer. The switch is now the whole test for the LABEL.
        //
        // THE MATCH IS NOT GATED ON THE SWITCH AT ALL any more (wave
        // 3D). It is not gated on anything: there is no address
        // condition in the WHERE below, in either switch state, for any
        // viewer. A student leader submitting a full address to a
        // picker that answers "found / not found" learns which named
        // account owns that address whether or not the label prints it,
        // and the switch is a decision about what an activity SHOWS,
        // never a decision about what may be PROBED. Both the previous
        // shape (gate the match on the switch) and the one before it
        // (gate the match on the viewer) were the same mistake at
        // different strengths.
        //
        // THE QUALIFIER ON THE LABEL IS LOAD-BEARING and the claim is
        // false without it (wave-3B audit, G-3). Two things outlive the
        // switch and both are deliberate: THIS method still LABELS the
        // address when the switch is OFF, which is the whole point of
        // the setting; and the two staff imports
        // ({@see coordinatorimport::find_user()} and
        // {@see attributes\csv_importer}) resolve an operator-supplied
        // address by exact lower-cased equality without ever asking
        // contactprivacy::enabled(), because there the address is an
        // input the operator already holds rather than something this
        // plugin discovered - a documented maintainer decision, stated
        // in full in those two classes and in README.md.
        //
        // :viewparticipantidentity is NOT dead: it still governs the
        // mobile columns through contactprivacy::mobile_consent_bypass()
        // and the identity columns of the staff tables. What it no
        // longer does is reopen an address.
        //
        // What survives from the previous design, and must: the switch
        // is AND-ed onto the two core identity capabilities below,
        // never OR-ed, so this class can only ever REMOVE an address
        // from a label and can never restore one the SITE withheld
        // (good-neighbour principle).
        //
        // NO CONNECTION FACTOR, and that is a decision (2026-08-02).
        // The switch-OFF case is NOT connection-scoped: when the
        // activity is not protecting contact details there is nothing
        // for a connection to be an exception TO, and
        // contactprivacy::can_see_map() itself returns all-true for
        // every subject when the switch is off. A per-row connection
        // test here would therefore have been a query per page that
        // could only ever answer "yes" - which is what the previous
        // shape of this method had become once the switch was the
        // whole test, an AND of $protect and !$protect that no row
        // could satisfy. Contact privacy is per activity and binary:
        // on, and nobody sees an address; off, and the two core
        // capabilities decide alone.
        //
        // AND-order, and the order is the good-neighbour principle in
        // code: the two core capabilities remain an unconditional
        // factor, so the plugin's gate can only ever remove the
        // address. NEVER invert this to an OR. Decided BEFORE the
        // query, because it also decides whether the address column is
        // fetched at all - an address that is never selected cannot be
        // printed by a later edit, dumped by a debugger or iterated out
        // of the record by a template.
        $showemail = !$protect
            && (has_capability('moodle/site:viewuseridentity', $context, $viewerid)
                || has_capability('moodle/course:viewhiddenuserfields', $context, $viewerid));

        // U3/S6: match across all core name fields and the full-name
        // concat. NAMES ONLY - see above.
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

        $fieldlist = implode(', ', array_map(static fn($f) => 'u.' . $f, array_unique(array_merge(
            $showemail ? ['id', 'email'] : ['id'],
            $namefields
        ))));
        $sql = "SELECT $fieldlist
                  FROM {user} u
                  JOIN ($enrolsql) eu ON eu.id = u.id
                 WHERE u.deleted = 0 AND u.suspended = 0
                   AND (" . implode(' OR ', $conditions) . ")
              ORDER BY u.lastname, u.firstname";
        $users = $DB->get_records_sql($sql, array_merge($enrolparams, $params), 0, self::MAX_RESULTS);

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
