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

namespace mod_selfselectadvanced;

/**
 * Core event observers (review item M3).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A user was deleted: remove their site-wide attribute record and
     * purge the distinct-value cache (M3). Group membership rows are
     * course data handled by the plugin's own lifecycle and privacy
     * paths.
     *
     * @param \core\event\user_deleted $event the core event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        \mod_selfselectadvanced\local\attributes\manager::delete_for_user((int) $event->objectid);
    }
}
