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
 * AJAX provider for every team picker in the plugin (strategy 1.18 B).
 *
 * The same rule the guide pickers follow, for the same reason. The move
 * form carried TWO selects holding every team in the activity, and the
 * overrides form an autocomplete filtered in the browser - which still
 * has to render every option first. At the fifteen hundred teams this
 * plugin is built for that is a page nobody can use; at the ten
 * thousand students the override form's user scope offered, worse.
 *
 * Matching is on the team name AND the project id, because staff work
 * from whichever they have in front of them.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_groups extends external_api {
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
     * Search this activity's teams by name or project id.
     *
     * @param int $cmid course module id
     * @param string $query search text
     * @return array[] matching teams
     */
    public static function execute(int $cmid, string $query): array {
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

        // Staff only: this is the picker on the move and override
        // forms, and both of those are already manager or coordinator
        // work. A student has no picker of teams to fill.
        if (
            !has_capability('mod/selfselectadvanced:manage', $context)
            && !has_capability('mod/selfselectadvanced:coordinate', $context)
        ) {
            throw new \required_capability_exception($context, 'mod/selfselectadvanced:manage', 'nopermissions', '');
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $rows = \mod_selfselectadvanced\local\groups::search($activity, $query, self::LIMIT);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => (int) $row->id,
                'name' => format_string($row->name),
                'pluginuid' => (string) $row->pluginuid,
                'label' => get_string('grouppickerlabel', 'mod_selfselectadvanced', (object) [
                    'name' => format_string($row->name),
                    'pluginuid' => $row->pluginuid,
                    'state' => get_string(
                        'state' . str_replace('_', '', $row->state),
                        'mod_selfselectadvanced'
                    ),
                ]),
            ];
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
                'id' => new external_value(PARAM_INT, 'Group id'),
                'name' => new external_value(PARAM_TEXT, 'Team name'),
                'pluginuid' => new external_value(PARAM_TEXT, 'Project id'),
                'label' => new external_value(PARAM_TEXT, 'Display label with the project id and state'),
            ])
        );
    }
}
