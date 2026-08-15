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

/**
 * External function declarations for mod_selfselectadvanced.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_selfselectadvanced_search_candidates' => [
        'classname' => \mod_selfselectadvanced\external\search_candidates::class,
        'description' => 'Search the course-level candidate pool for group invitations, '
            . 'with per-candidate eligibility and reasons.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/selfselectadvanced:lead',
    ],
    'mod_selfselectadvanced_search_groups' => [
        'classname' => \mod_selfselectadvanced\external\search_groups::class,
        'description' => 'Search this activity\'s groups by name or project id, for the move, '
            . 'override and join-request pickers.',
        'type' => 'read',
        'ajax' => true,
        // The REAL door, not one of its three arms. execute() admits manage,
        // coordinate OR respond; naming only respond understated the audience
        // to any administrator reading this file (external audit API-001,
        // 2026-08-13). This field is informational - execute() enforces - but
        // an inaccurate declaration misleads exactly the people auditing it.
        'capabilities' => 'mod/selfselectadvanced:manage, mod/selfselectadvanced:coordinate, '
            . 'mod/selfselectadvanced:respond',
    ],
    'mod_selfselectadvanced_search_guides' => [
        'classname' => \mod_selfselectadvanced\external\search_guides::class,
        'description' => 'Search this activity\'s guides for the searchable guide pickers, '
            . 'returning name, department and current load.',
        'type' => 'read',
        'ajax' => true,
        // As above: execute() admits any of these six.
        'capabilities' => 'mod/selfselectadvanced:respond, mod/selfselectadvanced:lead, '
            . 'mod/selfselectadvanced:guide, mod/selfselectadvanced:manage, '
            . 'mod/selfselectadvanced:coordinate, mod/selfselectadvanced:assignguide',
    ],
    'mod_selfselectadvanced_search_participants' => [
        'classname' => \mod_selfselectadvanced\external\search_participants::class,
        'description' => 'Search this activity\'s participants for the manager move form.',
        'type' => 'read',
        'ajax' => true,
        // Advisory and comma-separated, per Moodle convention; the
        // enforcing check is in search_participants::execute().
        'capabilities' => 'mod/selfselectadvanced:manage, mod/selfselectadvanced:managecomposition',
    ],

    // The LLM API (1.20.46): read tickets and the knowledgebank, claim,
    // request information, respond and escalate. Deliberately NOT ajax -
    // these are called by an external LLM-based system holding a
    // standard admin-issued web service token for the dedicated service
    // account, not by this plugin's own pages. Every function's
    // 'capabilities' entry is advisory (Moodle convention, as above);
    // execute() enforces BOTH mod/selfselectadvanced:api AND the same
    // coordinate-level authority a human queue worker needs
    // (mod_selfselectadvanced\local\llmapi::require_api_authority()) -
    // the api capability alone is never enough.
    'mod_selfselectadvanced_api_list_tickets' => [
        'classname' => \mod_selfselectadvanced\external\api_list_tickets::class,
        'description' => 'List this activity\'s ticket queue: id, type, status, escalated flag, group name, '
            . 'requester identity (fullname + role, never email or phone) and queue position.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
    'mod_selfselectadvanced_api_get_ticket' => [
        'classname' => \mod_selfselectadvanced\external\api_get_ticket::class,
        'description' => 'One ticket\'s full thread: the staff trail with actor identities replaced by role '
            . 'labels, the requester\'s own identity, attachment filenames (no URLs or bytes) and the '
            . 'requester\'s previous-ticket count.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
    'mod_selfselectadvanced_api_list_kb' => [
        'classname' => \mod_selfselectadvanced\external\api_list_kb::class,
        'description' => 'Every published knowledgebank entry for this activity, via the 1.20.45 serialiser '
            . '(kb::export_entry()) verbatim.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
    'mod_selfselectadvanced_api_search_kb' => [
        'classname' => \mod_selfselectadvanced\external\api_search_kb::class,
        'description' => 'Search the published knowledgebank by free text and/or ticket type, via the 1.20.45 '
            . 'serialiser (kb::export_entry()) verbatim.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
    'mod_selfselectadvanced_api_claim' => [
        'classname' => \mod_selfselectadvanced\external\api_claim::class,
        'description' => 'Claim an open ticket - a thin wrapper over tickets::claim(), no new state logic.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
    'mod_selfselectadvanced_api_request_info' => [
        'classname' => \mod_selfselectadvanced\external\api_request_info::class,
        'description' => 'Ask the requester a question on a claimed ticket - a thin wrapper over '
            . 'tickets::request_info(), no new state logic.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
    'mod_selfselectadvanced_api_respond' => [
        'classname' => \mod_selfselectadvanced\external\api_respond::class,
        'description' => 'Post a requester-visible reply on a claimed ticket without closing it - a thin '
            . 'wrapper over tickets::comment(), no new state logic. There is no resolve or decline endpoint: '
            . 'closing a ticket is human-only.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
    'mod_selfselectadvanced_api_escalate' => [
        'classname' => \mod_selfselectadvanced\external\api_escalate::class,
        'description' => 'Hand a ticket up to the editing-teacher/manager tier - a thin wrapper over '
            . 'tickets::escalate(), no new state logic.',
        'type' => 'write',
        'ajax' => false,
        'capabilities' => 'mod/selfselectadvanced:api, mod/selfselectadvanced:coordinate, mod/selfselectadvanced:manage',
    ],
];

$services = [
    'selfselectadvanced_llm' => [
        'functions' => [
            'mod_selfselectadvanced_api_list_tickets',
            'mod_selfselectadvanced_api_get_ticket',
            'mod_selfselectadvanced_api_list_kb',
            'mod_selfselectadvanced_api_search_kb',
            'mod_selfselectadvanced_api_claim',
            'mod_selfselectadvanced_api_request_info',
            'mod_selfselectadvanced_api_respond',
            'mod_selfselectadvanced_api_escalate',
        ],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'selfselectadvanced_llm',
        // Attachments are NOT exposed to the machine in 1.20.46 - a read
        // payload lists attachment FILENAMES only (classes/external/
        // api_get_ticket.php). Exposing the bytes is a separate,
        // deliberately unmade maintainer decision.
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
