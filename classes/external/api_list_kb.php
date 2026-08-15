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
use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\llmapi;

/**
 * The LLM API (1.20.46): every published knowledgebank entry.
 *
 * A thin wrapper over kb::search() - published entries only - returning
 * kb::export_entry()'s exact key set VERBATIM (non-negotiable 6): the
 * 1.20.45 serialiser already strips sourceticketid and every author
 * userid, so there is no provenance for this endpoint to leak even by
 * accident.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_list_kb extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Every published knowledgebank entry for this activity.
     *
     * @param int $cmid course module id
     * @return array{entries: array[]}
     */
    public static function execute(int $cmid): array {
        global $USER;

        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $activity = activity::from_cmid($cmid);
        $context = $activity->context();
        self::validate_context($context);

        $userid = (int) $USER->id;
        llmapi::require_api_authority($activity, $userid);

        $entries = array_map(
            [kb::class, 'export_entry'],
            array_values(kb::search($activity, '', '', true))
        );

        return ['entries' => $entries];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'entries' => new external_multiple_structure(api_search_kb::entry_structure()),
        ]);
    }
}
