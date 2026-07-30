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
     * @param string $namequery keep only guides whose name contains this, matched before any
     *      per-guide override work is done; empty for all of them
     * @return \stdClass[] userid-keyed: user fields + used, max, remaining, label
     */
    public static function with_load(
        activity $activity,
        resolver $resolver,
        bool $includeunavailable = false,
        string $namequery = ''
    ): array {
        $namefields = implode(', ', array_map(
            static fn($field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $users = get_users_by_capability(
            $activity->context(),
            'mod/selfselectadvanced:guide',
            'u.id, ' . $namefields
        );

        // Narrowed here, before the resolver is consulted once. The
        // override lookup is the per-guide cost in this loop, so a
        // search that discards a guide afterwards would pay it for
        // every guide in the school on every keystroke (strategy 1.18 B).
        $namequery = \core_text::strtolower(trim($namequery));
        if ($namequery !== '') {
            $users = array_filter(
                $users,
                static fn($user) => strpos(\core_text::strtolower(fullname($user)), $namequery) !== false
            );
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
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver
     * @param string $query the typed text
     * @param int $limit most rows to return
     * @param bool $onlyselectable drop guides with no room left, as the assignment pickers need
     * @return \stdClass[] matching guides, each with department and subdepartment attached
     */
    public static function search(
        activity $activity,
        resolver $resolver,
        string $query,
        int $limit = 50,
        bool $onlyselectable = true
    ): array {
        $matches = self::with_load($activity, $resolver, false, $query);
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
