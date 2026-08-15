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
 * The LLM API (1.20.46): search the published knowledgebank.
 *
 * A thin wrapper over kb::search() - published entries only - returning
 * kb::export_entry()'s exact key set VERBATIM (non-negotiable 6).
 * entry_structure() below is the one definition of that key set's
 * external_single_structure, shared with api_list_kb - the two endpoints
 * read the same serialiser and must describe the same shape.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_search_kb extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'q' => new external_value(PARAM_RAW_TRIMMED, 'Free text, matched against title and keywords', VALUE_DEFAULT, ''),
            'type' => new external_value(PARAM_ALPHA, 'tickets::TYPE_*, or blank for every type', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search the published knowledgebank.
     *
     * @param int $cmid course module id
     * @param string $q free text
     * @param string $type tickets::TYPE_*, or '' for every type
     * @return array{entries: array[]}
     */
    public static function execute(int $cmid, string $q = '', string $type = ''): array {
        global $USER;

        [
            'cmid' => $cmid,
            'q' => $q,
            'type' => $type,
        ] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'q' => $q, 'type' => $type]);

        $activity = activity::from_cmid($cmid);
        $context = $activity->context();
        self::validate_context($context);

        $userid = (int) $USER->id;
        llmapi::require_api_authority($activity, $userid);

        $type = llmapi::known_type_or_blank($type);

        $entries = array_map(
            [kb::class, 'export_entry'],
            array_values(kb::search($activity, $type, $q, true))
        );

        return ['entries' => $entries];
    }

    /**
     * kb::export_entry()'s exact key set (non-negotiable 6), as one
     * external_single_structure shared by this endpoint and
     * api_list_kb, so the two can never describe it differently.
     *
     * @return external_single_structure
     */
    public static function entry_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Entry id'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'question' => new external_value(PARAM_RAW, 'The question, as plain text'),
            'answerhtml' => new external_value(PARAM_RAW, 'The answer, formatted HTML'),
            'answertext' => new external_value(PARAM_RAW, 'The answer, as plain text'),
            'type' => new external_value(PARAM_ALPHA, 'tickets::TYPE_*, or blank for a general entry'),
            'keywords' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Keyword')),
            'timemodified' => new external_value(PARAM_INT, 'Unix timestamp'),
        ]);
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'entries' => new external_multiple_structure(self::entry_structure()),
        ]);
    }
}
