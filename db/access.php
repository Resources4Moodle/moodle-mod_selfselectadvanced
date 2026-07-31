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
 * Capability definitions for mod_selfselectadvanced.
 *
 * Behaviour maps to capabilities, never to role names (spec section 3).
 * Freeze belongs to guides and unfreeze to managers by default; sites may
 * re-assign both because they are plain capabilities.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Add a new instance to a course.
    'mod/selfselectadvanced:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],

    // Create groups and act as leader.
    'mod/selfselectadvanced:creategroup' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
    ],

    // Accept or decline invitations and nominations; defines the candidate pool.
    'mod/selfselectadvanced:respond' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
    ],

    // Appear in the guide list, review and approve groups.
    'mod/selfselectadvanced:guide' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
        ],
    ],

    // Group Coordinators: work the ticket queue, freeze a firm team on
    // its guide's behalf and unfreeze - deliberately NOT manage. The
    // conflict-of-interest guard refuses these actions on any group
    // the holder is involved in (guide, successor guide or member)
    // unless they also hold manage. Granted to no archetype: the
    // groupcoordinator role created at install carries it.
    'mod/selfselectadvanced:coordinate' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [],
    ],

    // Freeze groups, single and bulk (guides by default, spec D4).
    'mod/selfselectadvanced:freeze' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
        ],
    ],

    // Unfreeze groups. Managers AND editing teachers by default, spec
    // D4 - which is what this comment said from the start while the
    // archetype list granted the editing teacher alone (decision 6,
    // D6-7): the code now matches the documented intent.
    'mod/selfselectadvanced:unfreeze' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Configure rules, quotas and dates; staged moves; run auto-grouping.
    'mod/selfselectadvanced:manage' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Create, edit and delete overrides.
    'mod/selfselectadvanced:override' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Override composition/limit rules on a staff roster action (decision 6):
    // bypass L1-L4/QUOTA on a staged move, park a student with no
    // destination, dissolve a dead-end team. Always with a typed reason
    // and a logged event - never silent.
    'mod/selfselectadvanced:overriderules' => [
        'riskbitmask' => RISK_CONFIG | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/selfselectadvanced:override',
    ],

    // Ingest and edit participant attributes. System context, no archetype:
    // in practice only site administrators hold it (spec section 8).
    'mod/selfselectadvanced:ingestattributes' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],

    // See all groups, members, states and the penalty ledger.
    'mod/selfselectadvanced:viewall' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
