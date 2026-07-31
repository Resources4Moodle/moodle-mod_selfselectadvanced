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
 * Event observers for mod_selfselectadvanced (review item M3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\mod_selfselectadvanced\observer::user_deleted',
    ],
    [
        // An unenrolled student cannot hold a seat, and core purges
        // their course-group rows on the last enrolment. Without this
        // the plugin roster kept counting them and the mirror silently
        // disagreed with the course (T-16, D7-F1).
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\mod_selfselectadvanced\observer::user_enrolment_deleted',
    ],
];
