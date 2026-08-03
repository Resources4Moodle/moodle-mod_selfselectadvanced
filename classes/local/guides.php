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
use mod_selfselectadvanced\local\override\effective_value;
use mod_selfselectadvanced\local\override\resolver;

/**
 * The guide list with per-guide load (spec sections 4A.5, 6.5).
 *
 * Guides are the holders of the guide capability in the module
 * context. Every load figure is the L5 counting basis (pending_guide,
 * firm, frozen) against the guide's effective max_guided from the
 * override resolver.
 *
 * Guide volunteering (1.7.0): when the activity has guidevolunteer
 * enabled, a guide whose effective cap is 0 purely because they have
 * not volunteered (or volunteered for zero groups) is not offered in
 * any picker built from with_load() - a manager-overridden guide (even
 * an explicit always-full 0) stays visible, per precedence.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guides {
    /**
     * All guides of the activity with their load and remaining capacity.
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver
     * @param bool $includeunavailable keep guides who are unavailable purely for want of volunteering,
     *      for manager-facing target pickers such as the overrides page
     * @param string $searchquery keep only guides whose NAME contains this - or, when the typed
     *      text contains '@', whose name OR EMAIL ADDRESS contains it - matched before any
     *      per-guide override work is done; empty for all of them
     * @return \stdClass[] userid-keyed: id, fullname, used, max, remaining, label - never an address
     */
    public static function with_load(
        activity $activity,
        resolver $resolver,
        bool $includeunavailable = false,
        string $searchquery = ''
    ): array {
        $namefields = implode(', ', array_map(
            static fn($field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));

        // THE ADDRESS ARM ENGAGES ONLY WHEN THE TYPED TEXT CONTAINS
        // '@', AND THAT IS MAINTAINER DECISION 41 (2026-08-04)
        // ANSWERING A MEASUREMENT RATHER THAN A TASTE: substring
        // matching leaks the string it matches, and a plain enrolled
        // student holding nothing but :respond reconstructed an entire
        // guide address - p7x9qz@confidential.invalid, a local part
        // with no relation to the guide's name - in 453 calls to
        // search_guides, using only found/not-found on single-character
        // extensions of a substring.
        //
        // WHAT '@' BUYS, STATED HONESTLY: it does not close that oracle.
        // A determined prober can anchor on the '@' and grow the
        // substring in both directions from there. It removes the
        // no-cost blind sweep over name-shaped fragments, and the
        // maintainer accepted the residue in those terms - "staff
        // directory is available to anyone who opens picker, but @
        // slows deliberate probe" - because this pool is the holders of
        // mod/selfselectadvanced:guide in this module context, i.e.
        // staff being approached. Exact equality was considered and NOT
        // taken.
        //
        // WITHOUT '@' THIS IS THE PRE-DECISION-32 MATCHER, unchanged:
        // names only. {@see \mod_selfselectadvanced\local\candidates}
        // is names-only for every viewer in both states of the
        // contact-privacy switch and stays that way - an address probe
        // against a pool of STUDENTS is an oracle over protected
        // people, and no part of this file may be "aligned" with it.
        $searchquery = \core_text::strtolower(trim($searchquery));
        $matchaddress = strpos($searchquery, '@') !== false;

        // THE FIELD LIST IS DECIDED BEFORE THE QUERY, which is the rule
        // {@see \mod_selfselectadvanced\local\candidates} already states
        // in its own words: an address that is never selected "cannot be
        // printed by a later edit, dumped by a debugger or iterated out
        // of the record by a template". So the column is fetched on
        // exactly the calls that can use it - a query carrying an '@' -
        // and on no others.
        //
        // The condition is the QUERY, never the caller, and that
        // distinction is load-bearing. An earlier draft of this comment
        // listed contact.php among the callers that "ask with no query
        // and are handed no addresses at all". THAT WAS FALSE, and false
        // on the one page where a student holds the keyboard:
        // contact.php:79 reads a guidefilter parameter, :162 renders it
        // as a visible text input, and :183 passes it here - behind
        // require_capability(':creategroup') at :45, a student
        // capability. Measured on both engines: a plain student leading
        // a group, querying 'p7x9qz@', matches the guide who owns that
        // address.
        // That is CORRECT - the page exists so a student can find a
        // guide, and the '@' rule governs it exactly as it governs every
        // other caller. What was wrong was a sentence claiming a
        // student-facing page could never fetch an address. Callers that
        // genuinely pass no query - guidequeue.php, the unfiltered Loads
        // tab and every selectable() site - are handed no address
        // because their query is empty, not because of who they are.
        $users = get_users_by_capability(
            $activity->context(),
            'mod/selfselectadvanced:guide',
            'u.id, ' . ($matchaddress ? 'u.email, ' : '') . $namefields
        );

        // Narrowed here, before the resolver is consulted once. The
        // override lookup is the per-guide cost in this loop, so a
        // search that discards a guide afterwards would pay it for
        // every guide in the school on every keystroke (strategy 1.18 B).
        //
        // MATCHING IS NOT DISPLAYING, and that half is absolute rather
        // than a slowdown: the row built below carries no address,
        // {@see \mod_selfselectadvanced\external\search_guides::label()}
        // composes name, department, sub-department and load, and
        // guidepickeraddress_test asserts the payload of a search made
        // BY ADDRESS carries no '@' at all.
        if ($searchquery !== '') {
            $users = array_filter($users, static function ($user) use ($searchquery, $matchaddress) {
                if (strpos(\core_text::strtolower(fullname($user)), $searchquery) !== false) {
                    return true;
                }
                if (!$matchaddress) {
                    // No '@' typed, so no address was fetched and none
                    // is compared. See above.
                    return false;
                }

                // Both sides lowered, as the name arm already does, so
                // that an address a student wrote down in capitals
                // still reaches the guide who owns it.
                return isset($user->email)
                    && strpos(\core_text::strtolower((string) $user->email), $searchquery) !== false;
            });
        }

        // Bulk maps (RCA-2, 10k probe): one volunteering query and two
        // grouped commitment queries replace three reads per guide.
        // The precedence stays with the resolver: only the volunteer
        // lookup is fed from the preloaded map, through the same
        // min(n, ceiling) rule effective_maxguided() applies.
        $volunteers = \mod_selfselectadvanced\local\volunteering::all_for_activity($activity);
        $commitments = \mod_selfselectadvanced\local\eoi::guide_commitments_all($activity);
        $volunteeringon = !empty($activity->settings()->guidevolunteer);

        $result = [];
        foreach ($users as $user) {
            if ($resolver->is_guide_hidden((int) $user->id)) {
                // 1.5.0: overridden out of every guide picker.
                continue;
            }
            $ceiling = $resolver->guide_capacity_ceiling((int) $user->id);
            if (
                $ceiling->source === \mod_selfselectadvanced\local\override\effective_value::SOURCE_GUIDE
                || !$volunteeringon
            ) {
                $maxvalue = $ceiling;
            } else {
                $row = $volunteers[(int) $user->id] ?? null;
                $n = $row !== null ? (int) $row->capacity : 0;
                $maxvalue = new \mod_selfselectadvanced\local\override\effective_value(
                    min($n, $ceiling->value),
                    \mod_selfselectadvanced\local\override\effective_value::SOURCE_VOLUNTEER
                );
            }
            if (
                !$includeunavailable
                && $maxvalue->source === effective_value::SOURCE_VOLUNTEER && $maxvalue->value === 0
            ) {
                // 1.7.0: has not volunteered (or volunteered for zero
                // groups) - unavailable for new assignments, so out of
                // every ASSIGNMENT picker built from this list. Manager
                // target pickers pass $includeunavailable, otherwise the
                // guides most in need of an override become unreachable.
                continue;
            }
            $used = $commitments[(int) $user->id] ?? 0;
            $max = $maxvalue->value;
            // Built field by field rather than by decorating $user, so
            // the address the filter above matched on cannot travel any
            // further than the filter. Do not turn this into a clone.
            $entry = (object) [
                'id' => (int) $user->id,
                'fullname' => fullname($user),
                'used' => $used,
                'max' => $max,
                'remaining' => max(0, $max - $used),
            ];
            $entry->label = get_string('guideload', 'mod_selfselectadvanced', $entry);
            $result[$entry->id] = $entry;
        }

        return $result;
    }

    /**
     * Guides with remaining capacity, for the leader's selection list
     * (at-capacity guides are excluded, spec 6.5).
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver
     * @return \stdClass[] userid-keyed subset of with_load()
     */
    public static function selectable(activity $activity, resolver $resolver): array {
        // Student-approach mode: omitting a full guide from the list
        // would itself advertise their load, so every guide stays
        // listed and a full one refuses at submission with the
        // existing reason (strategy 1.16 A).
        if (!empty($activity->settings()->studentapproach)) {
            return self::with_load($activity, $resolver);
        }

        return array_filter(self::with_load($activity, $resolver), static fn($guide) => $guide->remaining > 0);
    }

    /**
     * Guides matching a typed query, for the searchable pickers
     * (strategy 1.18 B).
     *
     * A school with 1500 guides cannot be offered as a list, so every
     * picker asks this instead. The result carries department and load
     * so the person choosing does not need a second page to decide.
     * The typed text is matched against a guide's name, and - only when
     * that text contains '@' - against their email address as well
     * (maintainer decisions 32 and 41). Nothing downstream returns an
     * address.
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver
     * @param string $query the typed text
     * @param int $limit most rows to return
     * @param bool $onlyselectable drop guides with no room left, as the assignment pickers need
     * @param bool $includeunavailable keep guides who are unavailable purely for want of
     *      volunteering, as the override target picker needs
     * @return \stdClass[] matching guides, each with department and subdepartment attached
     */
    public static function search(
        activity $activity,
        resolver $resolver,
        string $query,
        int $limit = 50,
        bool $onlyselectable = true,
        bool $includeunavailable = false
    ): array {
        // The third argument to with_load() was hard-coded false here,
        // so the parameter its own docblock says "manager target
        // pickers pass" was reachable by nobody: every picker in the
        // plugin funnels through here. A guide who has not volunteered
        // was therefore invisible to the overrides page - one of the two
        // guides an override exists for. Default false, so every caller
        // that does not ask keeps exactly today's behaviour.
        $matches = self::with_load($activity, $resolver, $includeunavailable, $query);
        if ($onlyselectable) {
            $matches = array_filter($matches, static fn($guide) => $guide->remaining > 0);
        }

        // Stable, useful ordering: those with the most room first, so a
        // query matching many people leads with the ones who can take
        // work, then by name for a predictable list.
        uasort($matches, static fn($a, $b) => [$b->remaining, $a->fullname] <=> [$a->remaining, $b->fullname]);
        $matches = array_slice($matches, 0, $limit, true);

        // One attribute read for the whole page of results.
        $attributes = \mod_selfselectadvanced\local\attributes\manager::get_for_users(array_keys($matches));
        foreach ($matches as $guide) {
            $record = $attributes[$guide->id] ?? null;
            $guide->department = (string) ($record->department ?? '');
            $guide->subdepartment = (string) ($record->subdepartment ?? '');
        }

        return $matches;
    }
}
