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
 * from whichever they have in front of them - and since 1.19 a student
 * choosing a team to ask to join uses the same control.
 *
 * FIELD-VISIBILITY MATRIX (contact-privacy audit, 2026-08-01): this
 * endpoint discloses to any student every TEAM's name, project id and
 * state. It returns no person, no name and no contact detail, so it is
 * not a cardinal-rule surface and needs no gate from the contact-privacy
 * work. Recorded here so the matrix has a row for it rather than a
 * silence.
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
            'fit' => new external_value(
                PARAM_BOOL,
                'Judge each team against the calling user and return the caution and the seat',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Search this activity's teams by name or project id.
     *
     * @param int $cmid course module id
     * @param string $query search text
     * @param bool $fit judge each team against the calling user
     * @return array[] matching teams
     */
    public static function execute(int $cmid, string $query, bool $fit = false): array {
        global $USER;

        [
            'cmid' => $cmid,
            'query' => $query,
            'fit' => $fit,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'query' => $query,
            'fit' => $fit,
        ]);

        $activity = activity::from_cmid($cmid);
        $context = $activity->context();
        self::validate_context($context);

        // Three audiences, each identified by a capability it already
        // holds: the staff moving somebody or granting an exception,
        // and - since 1.19 - a student choosing a team to ask to join.
        //
        // A team's name and project id are not secret from the people
        // in the activity: the pick-a-team page has listed them to
        // students since 1.11. What this returns is exactly that, and
        // it is capped and searched rather than listed.
        $allowed = false;
        foreach (['manage', 'coordinate', 'respond', 'creategroup'] as $capability) {
            if (has_capability('mod/selfselectadvanced:' . $capability, $context)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \required_capability_exception($context, 'mod/selfselectadvanced:respond', 'nopermissions', '');
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $rows = \mod_selfselectadvanced\local\groups::search($activity, $query, self::LIMIT);

        // Judged against the CALLING user only - the picker never
        // reports how a third party would fare, so this exposes nothing
        // about anybody else. Staff pickers leave $fit off and pay
        // nothing for it.
        $verdicts = [];
        if ($fit) {
            $verdicts = \mod_selfselectadvanced\local\fit::for_groups($activity, $rows, (int) $USER->id);
        }

        $results = [];
        foreach ($rows as $row) {
            $verdict = $verdicts[(int) $row->id] ?? null;
            $label = get_string('grouppickerlabel', 'mod_selfselectadvanced', (object) [
                'name' => format_string($row->name),
                'pluginuid' => $row->pluginuid,
                'state' => get_string(
                    'state' . str_replace('_', '', $row->state),
                    'mod_selfselectadvanced'
                ),
            ]);
            // The caution rides in the LABEL as well as its own field,
            // because core/form-autocomplete renders the label and
            // nothing else - a student who never opens the suggestion
            // still reads the warning.
            if ($verdict !== null && $verdict->seat !== null) {
                $label .= ' - ' . get_string('joinfitseat', 'mod_selfselectadvanced', $verdict->seat);
            }
            if ($verdict !== null && !$verdict->fits) {
                $label .= ' - ' . get_string('joinfitcaution', 'mod_selfselectadvanced') . ' ' . $verdict->caution;
            }

            $results[] = [
                'id' => (int) $row->id,
                'name' => format_string($row->name),
                'pluginuid' => (string) $row->pluginuid,
                'label' => $label,
                'fits' => $verdict === null ? true : (bool) $verdict->fits,
                'caution' => $verdict === null ? '' : (string) $verdict->caution,
                'seat' => $verdict === null || $verdict->seat === null ? '' : (string) $verdict->seat,
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
                'fits' => new external_value(PARAM_BOOL, 'Whether the calling user meets this team\'s requirements'),
                'caution' => new external_value(PARAM_TEXT, 'Why the calling user does not fit, empty when they do'),
                'seat' => new external_value(PARAM_TEXT, 'Seat the calling user would fill, empty when none'),
            ])
        );
    }
}
