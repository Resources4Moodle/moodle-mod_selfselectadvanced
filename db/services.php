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
        'capabilities' => 'mod/selfselectadvanced:creategroup',
    ],
    'mod_selfselectadvanced_search_guides' => [
        'classname' => \mod_selfselectadvanced\external\search_guides::class,
        'description' => 'Search this activity\'s guides for the searchable guide pickers, '
            . 'returning name, department and current load.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/selfselectadvanced:respond',
    ],
    'mod_selfselectadvanced_search_participants' => [
        'classname' => \mod_selfselectadvanced\external\search_participants::class,
        'description' => 'Search this activity\'s participants for the manager move form.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/selfselectadvanced:manage',
    ],
];
