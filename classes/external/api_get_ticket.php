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
use mod_selfselectadvanced\local\llmapi;
use mod_selfselectadvanced\local\tickets;

/**
 * The LLM API (1.20.46): one ticket's whole thread.
 *
 * A thin wrapper over tickets::get() + tickets::trail(staff view) +
 * tickets::history() - no state logic of its own (non-negotiable 4).
 * Actor names are REPLACED BY ROLE LABELS throughout the trail (the
 * machine needs the shape of the conversation, not staff identities);
 * the ticket's own REQUESTER identity is the one exception D-104 grants,
 * and it is fullname + role, never email or phone (non-negotiable 2).
 * Attachments are filenames only - no URLs, no bytes.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_get_ticket extends external_api {
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
     * One ticket's full thread.
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

        $ticket = tickets::get($activity, $ticketid);

        $entries = [];
        foreach (tickets::trail($activity, $ticketid, true) as $row) {
            $entries[] = [
                'action' => (string) $row->action,
                'actorrole' => llmapi::actor_role_label($activity, $ticket, (int) $row->actorid),
                'note' => $row->note !== null ? trim(html_to_text((string) $row->note)) : '',
                'timecreated' => (int) $row->timecreated,
                'attachments' => llmapi::attachment_filenames($context, tickets::FILEAREA_POST, (int) $row->id),
            ];
        }

        $history = tickets::history($activity, (int) $ticket->requestedby, $userid, $ticketid);

        return [
            'id' => (int) $ticket->id,
            'type' => (string) $ticket->type,
            'status' => (string) $ticket->status,
            'escalated' => (int) ($ticket->escalated ?? 0) === 1,
            'groupname' => llmapi::subject_name($activity, $ticket),
            'requester' => llmapi::requester_identity($activity, $ticket),
            'timerequested' => (int) $ticket->timecreated,
            'requesttext' => trim(html_to_text((string) $ticket->request)),
            'requestattachments' => llmapi::attachment_filenames($context, tickets::FILEAREA_REQUEST, $ticketid),
            'entries' => $entries,
            'previoustickets' => [
                'count' => count($history),
                'ids' => array_values(array_map(static fn($row) => (int) $row->id, $history)),
            ],
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Ticket id'),
            'type' => new external_value(PARAM_ALPHA, 'tickets::TYPE_*'),
            'status' => new external_value(PARAM_ALPHA, 'tickets::STATUS_*'),
            'escalated' => new external_value(PARAM_BOOL, 'Whether this ticket is beyond the machine\'s reach'),
            'groupname' => new external_value(PARAM_TEXT, 'The team name, or a placeholder for a groupless request'),
            'requester' => new external_single_structure([
                'fullname' => new external_value(PARAM_TEXT, 'Requester full name'),
                'role' => new external_value(PARAM_ALPHA, 'student, leader or guide'),
            ]),
            'timerequested' => new external_value(PARAM_INT, 'Unix timestamp the ticket was filed'),
            'requesttext' => new external_value(PARAM_RAW, 'The opening request, as plain text'),
            'requestattachments' => new external_multiple_structure(
                new external_value(PARAM_FILE, 'Filename'),
                'Filenames attached to the opening request - no URLs, no bytes'
            ),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    // PARAM_ALPHANUMEXT, not PARAM_ALPHA: ACTION_PUBLISHED_FAQ
                    // is 'published_faq' - the one tickets::ACTION_* value
                    // with an underscore, and PARAM_ALPHA would silently
                    // strip it on clean_returnvalue().
                    'action' => new external_value(PARAM_ALPHANUMEXT, 'tickets::ACTION_*'),
                    'actorrole' => new external_value(
                        PARAM_TEXT,
                        'requester, coordinator, editing teacher or staff - never a name'
                    ),
                    'note' => new external_value(
                        PARAM_RAW,
                        'The note, question or reply, as plain text; blank for a bare transition'
                    ),
                    'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
                    'attachments' => new external_multiple_structure(
                        new external_value(PARAM_FILE, 'Filename'),
                        'Filenames attached to this post - no URLs, no bytes'
                    ),
                ])
            ),
            'previoustickets' => new external_single_structure([
                'count' => new external_value(PARAM_INT, 'How many OTHER tickets this requester has filed'),
                'ids' => new external_multiple_structure(new external_value(PARAM_INT, 'Ticket id')),
            ]),
        ]);
    }
}
