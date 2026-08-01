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

    // The two narrow slices of :manage (1.20.0). A Group Coordinator
    // who resolves a composition-change ticket can now carry the change
    // out, without also gaining settings, quotas, dates and
    // auto-grouping. The conflict-of-interest guard refuses both on any
    // team the holder is involved in, unless they also hold :manage.
    //
    // Both declare archetypes AND clonepermissionsfrom, which core
    // treats as ALTERNATIVES, not as an addition (accesslib.php: "we
    // ignore archetype key if we have cloned permissions"), and which
    // of the two fires depends on the install path: on a FRESH install
    // :manage does not exist yet when the pass reads its existing-caps
    // list, so archetypes wins; on an UPGRADE it does, so the clone
    // wins and every role holding :manage inherits these at the same
    // context and permission. The one holder that differs between the
    // paths is the manager role, and no gate can tell: every seam these
    // capabilities open is has_any_capability([:manage, <narrow>]) and
    // the conflict-of-interest guard exempts :manage outright.
    // Documented in full in docs/architecture.md section 4.

    // Stage, commit and cancel student moves without the full manage
    // power.
    'mod/selfselectadvanced:managecomposition' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/selfselectadvanced:manage',
    ],

    // Assign or reassign a team's guide, and decide expressions of
    // interest, without the full manage power.
    'mod/selfselectadvanced:assignguide' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/selfselectadvanced:manage',
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

    // Open the team page of a team this person is the ASSIGNED guide
    // of - and only that team. Split out of :viewall in 1.20.1: the
    // entry gate at group.php conflated "may see everything in this
    // activity" with "may see the team I am responsible for", so a site
    // that withdrew :viewall from its non-editing teachers locked every
    // guide out of the page carrying Freeze, Release and the roster
    // they are there to judge.
    //
    // clonepermissionsfrom is :guide, NOT :viewall, and the choice is
    // load-bearing. Core copies the SOURCE ROLE'S PERMISSION VERBATIM,
    // CAP_PREVENT included (lib/accesslib.php, the $newcaps clone loop:
    // assign_capability($capname, $rolecapability->permission, ...)).
    // Cloning from :viewall would therefore hand CAP_PREVENT to exactly
    // the sites that have already withdrawn it - the sites this
    // capability exists for - and they would upgrade into the same
    // lockout. :guide is the right source because the population that
    // needs this is precisely the population that guides teams.
    //
    // Declaring BOTH archetypes and clonepermissionsfrom is deliberate
    // and the two are ALTERNATIVES, not an addition (see the note above
    // :managecomposition): a FRESH install takes the archetype list
    // because :guide is not yet in the capabilities table when core
    // captures its existing-caps snapshot; an UPGRADE takes the clone.
    'mod/selfselectadvanced:viewassignedteams' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/selfselectadvanced:guide',
    ],

    // See all groups, members, states and the penalty ledger - the whole
    // activity, every team, whether or not this person guides any of
    // them. The non-editing teacher archetype was removed in 1.20.1:
    // it made every one of them an unrestricted viewer by default,
    // which is what the maintainer's participant-visibility policy
    // withdraws. What a guide actually needs is :viewassignedteams.
    //
    // This edit reaches FRESH INSTALLS ONLY. Core's update_capabilities()
    // applies an archetype list only to capabilities that are NEW to the
    // capabilities table, so an upgraded site keeps every row it has -
    // measured on both engines, and asserted by
    // tests/viewassignedteams_test.php. Withdrawing it from an existing
    // site is an administrator's act, deliberately not the plugin's:
    // role_capabilities carries no provenance, so the plugin cannot tell
    // its own install-time grant from a permission somebody chose.
    'mod/selfselectadvanced:viewall' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // See a participant's EMAIL and MOBILE inside this activity - the
    // identity half of what :viewall used to imply. Split out in 1.20
    // because :viewall is a "how many teams" question and this is a
    // "whose contact details" question, and because a site may withdraw
    // the core participant capabilities entirely: after that, this and
    // the per-activity contact-privacy switch are the only controls the
    // plugin has over identity disclosure.
    //
    // No archetype and no clone: NOBODY holds it by default, not even
    // the editing teacher, who passes through contactprivacy's :manage
    // arm instead. A site that wants a reports role to read contact
    // fields grants it deliberately.
    //
    // clonepermissionsfrom is deliberately ABSENT. Core copies the
    // source role's permission verbatim, CAP_PREVENT included, so
    // cloning from :viewall would hand this to every non-editing
    // teacher on every existing site - the exact exposure 1.20 closes.
    //
    // It NEVER restores what the site removed at core level: every
    // consumer AND-composes it (good-neighbour principle), and it is
    // NOT part of contactprivacy::is_unrestricted() - it is a
    // permission to see fields, not a permission to bypass the
    // connection map.
    'mod/selfselectadvanced:viewparticipantidentity' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [],
    ],
];
