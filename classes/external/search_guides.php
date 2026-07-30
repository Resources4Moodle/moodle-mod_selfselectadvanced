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
 * AJAX provider for every guide picker in the plugin (strategy 1.18 B).
 *
 * A dropdown listing 1500 guides is not a control, and there is one per
 * row on the assignment queue. Each picker now ships with no options at
 * all and calls this, which matches the typed text against guide names
 * BEFORE any per-guide override work is done and returns at most
 * self::LIMIT of them.
 *
 * Who sees what is decided by capability rather than by a parameter the
 * caller could choose for themselves. Staff assigning work, and a guide
 * nominating a successor, see how much each guide is carrying. A team
 * choosing for itself sees name and department - and the load too,
 * unless the activity is in students-approach mode, where the load is
 * exactly what must not be advertised (strategy 1.16 A).
 *
 * No address is handled at any point, for anybody: the 1.17 rule for
 * approaches holds here too.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_guides extends external_api {
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
            'withroom' => new external_value(
                PARAM_BOOL,
                'Only guides with capacity left',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Search the activity's guides by name.
     *
     * @param int $cmid course module id
     * @param string $query search text
     * @param bool $withroom drop guides who are already full
     * @return array[] matching guides
     */
    public static function execute(int $cmid, string $query, bool $withroom = true): array {
        [
            'cmid' => $cmid,
            'query' => $query,
            'withroom' => $withroom,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'query' => $query,
            'withroom' => $withroom,
        ]);

        $activity = activity::from_cmid($cmid);
        $context = $activity->context();
        self::validate_context($context);

        // Four audiences may look a guide up, and each is identified by
        // a capability it already holds rather than by a parameter the
        // caller could choose: a student submitting to or approaching
        // one, a guide nominating a successor, and the staff assigning
        // work. Somebody with none of these has no picker to fill.
        //
        // Nothing returned here is private - name, department and load
        // are what every guide list in the plugin already shows, and no
        // address is handled at any point (the 1.17 rule for approaches
        // holds here too).
        $allowed = false;
        foreach (['respond', 'creategroup', 'guide', 'manage', 'coordinate'] as $capability) {
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

        $api = new \mod_selfselectadvanced\local\api($activity);
        $matches = \mod_selfselectadvanced\local\guides::search(
            $activity,
            $api->gatekeeper()->resolver(),
            $query,
            self::LIMIT,
            $withroom
        );

        // Students-approach mode hides how much each guide is carrying,
        // because "Guiding 2 of 3" IS advertised availability and that
        // mode exists to stop teams shopping by it (strategy 1.16 A).
        // The rule belongs here, at the one place every picker is fed
        // from: staff assigning work still need the figure, and a guide
        // nominating a successor still needs it, but the teams choosing
        // do not see it.
        $showload = empty($activity->settings()->studentapproach);
        foreach (['manage', 'coordinate', 'guide'] as $staffcapability) {
            if (has_capability('mod/selfselectadvanced:' . $staffcapability, $context)) {
                $showload = true;
                break;
            }
        }

        $results = [];
        foreach ($matches as $guide) {
            $results[] = [
                'id' => (int) $guide->id,
                'fullname' => $guide->fullname,
                'department' => $guide->department,
                'subdepartment' => $guide->subdepartment,
                'label' => self::label($guide, $showload),
            ];
        }

        return $results;
    }

    /**
     * The one-line label a picker shows for a guide.
     *
     * @param \stdClass $guide a row from guides::search()
     * @param bool $showload whether this viewer may see how much the guide is carrying
     * @return string name, department and - for those entitled to it - load
     */
    private static function label(\stdClass $guide, bool $showload): string {
        $parts = array_filter([$guide->department, $guide->subdepartment]);
        if ($showload) {
            $parts[] = $guide->label;
        }
        if (!$parts) {
            return $guide->fullname;
        }

        return get_string('guidepickerlabel', 'mod_selfselectadvanced', (object) [
            'fullname' => $guide->fullname,
            'label' => implode(' / ', $parts),
        ]);
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Guide user id'),
                'fullname' => new external_value(PARAM_TEXT, 'Guide name'),
                'department' => new external_value(PARAM_TEXT, 'Department, or empty when not recorded'),
                'subdepartment' => new external_value(PARAM_TEXT, 'Sub-department, or empty when not recorded'),
                'label' => new external_value(PARAM_TEXT, 'Display label with department and load'),
            ])
        );
    }
}
