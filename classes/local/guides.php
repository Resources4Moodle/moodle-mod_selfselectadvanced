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
use mod_selfselectadvanced\local\override\resolver;

/**
 * The guide list with per-guide load (spec sections 4A.5, 6.5).
 *
 * Guides are the holders of the guide capability in the module
 * context. Every load figure is the L5 counting basis (pending_guide,
 * firm, frozen) against the guide's effective max_guided from the
 * override resolver.
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
     * @return \stdClass[] userid-keyed: user fields + used, max, remaining, label
     */
    public static function with_load(activity $activity, resolver $resolver): array {
        $namefields = implode(', ', array_map(
            static fn($field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $users = get_users_by_capability(
            $activity->context(),
            'mod/selfselectadvanced:guide',
            'u.id, ' . $namefields
        );

        $result = [];
        foreach ($users as $user) {
            $used = groups::count_guiding($activity, (int) $user->id);
            $max = $resolver->effective_maxguided((int) $user->id)->value;
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
        return array_filter(self::with_load($activity, $resolver), static fn($guide) => $guide->remaining > 0);
    }
}
