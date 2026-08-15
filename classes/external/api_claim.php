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
 * The LLM API (1.20.46): claim an open ticket.
 *
 * A thin wrapper over tickets::claim() - no new state logic (non-
 * negotiable 4): the service account's userid is the one passed to the
 * existing service method, exactly as a human coordinator's is. An
 * escalated ticket refuses a mere :coordinate holder exactly as it
 * refuses a human one (non-negotiable 5) - tickets::claim() itself
 * enforces that, unchanged.
 *
 * status_structure() below is the one definition of the minimal
 * post-write snapshot every write endpoint (this one, request_info,
 * respond, escalate) returns, so the four cannot describe it differently.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_claim extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
        ]);
    }

    /**
     * Claim an open ticket.
     *
     * @param int $ticketid the ticket
     * @return array
     */
    public static function execute(int $ticketid): array {
        global $USER;

        ['ticketid' => $ticketid] = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid]);

        $activity = llmapi::activity_for_ticket($ticketid);
        $context = $activity->context();
        self::validate_context($context);

        $userid = (int) $USER->id;
        llmapi::require_api_authority($activity, $userid);

        $ticket = tickets::claim($activity, $ticketid, $userid);

        return llmapi::status_snapshot($ticket);
    }

    /**
     * The minimal post-write snapshot every write endpoint returns.
     *
     * @return external_single_structure
     */
    public static function status_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Ticket id'),
            'status' => new external_value(PARAM_ALPHA, 'tickets::STATUS_*'),
            'claimedby' => new external_value(PARAM_INT, 'The claimant userid, or 0 when unclaimed'),
            'escalated' => new external_value(PARAM_BOOL, 'Whether this ticket is beyond the machine\'s reach'),
        ]);
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::status_structure();
    }
}
