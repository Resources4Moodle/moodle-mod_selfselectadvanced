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
use mod_selfselectadvanced\local\llmapi;
use mod_selfselectadvanced\local\tickets;

/**
 * The LLM API (1.20.46): list a ticket queue.
 *
 * A thin wrapper over tickets::queue()/queue_count() - no state logic of
 * its own (non-negotiable 4). Every row carries the requester's identity
 * (fullname + role, D-104's "definite yes") and NOTHING else about them:
 * no email, no phone, ever (non-negotiable 2).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_list_tickets extends external_api {
    /** @var int Rows per page - the same default the human queue's perpage control opens on. */
    private const PERPAGE = 50;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'status' => new external_value(PARAM_ALPHA, 'tickets::STATUS_*, or blank for every status', VALUE_DEFAULT, ''),
            'type' => new external_value(PARAM_ALPHA, 'tickets::TYPE_*, or blank for every type', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Zero-based page number', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * List this activity's ticket queue.
     *
     * @param int $cmid course module id
     * @param string $status tickets::STATUS_*, or '' for every status
     * @param string $type tickets::TYPE_*, or '' for every type
     * @param int $page zero-based page number
     * @return array{tickets: array[], total: int}
     */
    public static function execute(int $cmid, string $status = '', string $type = '', int $page = 0): array {
        global $DB, $USER;

        [
            'cmid' => $cmid,
            'status' => $status,
            'type' => $type,
            'page' => $page,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'status' => $status,
            'type' => $type,
            'page' => $page,
        ]);

        $activity = activity::from_cmid($cmid);
        $context = $activity->context();
        self::validate_context($context);

        $userid = (int) $USER->id;
        llmapi::require_api_authority($activity, $userid);

        $type = llmapi::known_type_or_blank($type);
        $status = llmapi::known_status_or_blank($status);
        $page = max(0, $page);
        $limitfrom = $page * self::PERPAGE;

        $rows = tickets::queue($activity, $userid, $limitfrom, self::PERPAGE, $type, $status);
        $position = tickets::open_before($activity, $userid, $limitfrom, $type, $status);

        // 1.20.60 (audit L-11): every requester on this page in ONE
        // query, instead of a core_user::get_user() per row inside the
        // loop below. get_user() reads the database each time it is
        // called, so a full page of tickets paid for a query per name.
        $requesters = [];
        $requesterids = array_values(array_unique(array_map(
            static fn($row) => (int) $row->requestedby,
            $rows
        )));
        if ($requesterids !== []) {
            // Whole rows, like core_user::get_user() itself returns:
            // fullname() reads every name field a site may have
            // configured, and guessing that list here would be the kind
            // of narrowing that breaks on a site using middlename or
            // alternatename. One query for at most a page of people.
            $requesters = $DB->get_records_list('user', 'id', $requesterids);
        }

        $out = [];
        foreach ($rows as $row) {
            $isopen = $row->status === tickets::STATUS_OPEN;
            $position += $isopen ? 1 : 0;

            $out[] = [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'status' => (string) $row->status,
                'escalated' => (int) ($row->escalated ?? 0) === 1,
                'groupname' => llmapi::subject_name($activity, $row),
                'requester' => llmapi::requester_identity(
                    $activity,
                    $row,
                    $requesters[(int) $row->requestedby] ?? null
                ),
                'timerequested' => (int) $row->timecreated,
                'position' => $isopen ? $position : 0,
            ];
        }

        return [
            'tickets' => $out,
            'total' => tickets::queue_count($activity, $userid, $type, $status),
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'tickets' => new external_multiple_structure(
                new external_single_structure([
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
                    'position' => new external_value(PARAM_INT, 'Queue position while open, 0 otherwise'),
                ])
            ),
            'total' => new external_value(PARAM_INT, 'Total tickets matching the filter, across every page'),
        ]);
    }
}
