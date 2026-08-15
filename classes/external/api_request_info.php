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
use core_external\external_single_structure;
use core_external\external_value;
use mod_selfselectadvanced\local\llmapi;
use mod_selfselectadvanced\local\tickets;

/**
 * The LLM API (1.20.46): ask the requester a question on a claimed
 * ticket.
 *
 * A thin wrapper over tickets::request_info() - no new state logic
 * (non-negotiable 4).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_request_info extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'note' => new external_value(PARAM_RAW, 'The question for the requester'),
            'noteformat' => new external_value(PARAM_INT, 'Text format of note', VALUE_DEFAULT, FORMAT_PLAIN),
        ]);
    }

    /**
     * Ask the requester a question.
     *
     * @param int $ticketid the claimed ticket
     * @param string $note the question
     * @param int $noteformat text format
     * @return array
     */
    public static function execute(int $ticketid, string $note, int $noteformat = FORMAT_PLAIN): array {
        global $USER;

        [
            'ticketid' => $ticketid,
            'note' => $note,
            'noteformat' => $noteformat,
        ] = self::validate_parameters(self::execute_parameters(), [
            'ticketid' => $ticketid,
            'note' => $note,
            'noteformat' => $noteformat,
        ]);

        $activity = llmapi::activity_for_ticket($ticketid);
        $context = $activity->context();
        self::validate_context($context);

        $userid = (int) $USER->id;
        llmapi::require_api_authority($activity, $userid);

        $ticket = tickets::request_info($activity, $ticketid, $note, $noteformat, $userid);

        return llmapi::status_snapshot($ticket);
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return api_claim::status_structure();
    }
}
