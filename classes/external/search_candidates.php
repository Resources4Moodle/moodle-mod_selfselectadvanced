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
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\candidates;
use mod_selfselectadvanced\local\groups;

/**
 * AJAX provider for the invitation candidate autocomplete (C10, U3).
 *
 * The sole custom transport in the plugin; justified in the
 * architecture plan (S5b): core selectors cannot attach per-candidate
 * eligibility verdicts and localised refusal reasons, which spec
 * section 6.2 requires.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_candidates extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'groupid' => new external_value(PARAM_INT, 'Plugin group id'),
            'query' => new external_value(PARAM_RAW_TRIMMED, 'Search text'),
        ]);
    }

    /**
     * Search the candidate pool for a group.
     *
     * @param int $cmid course module id
     * @param int $groupid plugin group id
     * @param string $query search text
     * @return array[] candidate list
     */
    public static function execute(int $cmid, int $groupid, string $query): array {
        global $USER;

        [
            'cmid' => $cmid,
            'groupid' => $groupid,
            'query' => $query,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'groupid' => $groupid,
            'query' => $query,
        ]);

        $activity = activity::from_cmid($cmid);
        self::validate_context($activity->context());

        // IDOR: the group must belong to this activity, and only its
        // leader (or a manager) may search candidates for it.
        $group = groups::get($activity, $groupid);
        if ((int) $group->leaderid !== (int) $USER->id) {
            require_capability('mod/selfselectadvanced:manage', $activity->context());
        }

        $api = new api($activity);

        return candidates::search($activity, $group, $api->gatekeeper(), $query, (int) $USER->id);
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
                'label' => new external_value(PARAM_TEXT, 'Display label'),
                'eligible' => new external_value(PARAM_BOOL, 'Whether the user can currently be invited'),
                'reason' => new external_value(PARAM_TEXT, 'Why the user cannot be invited, when ineligible'),
            ])
        );
    }
}
