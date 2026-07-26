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

namespace mod_selfselectadvanced\event;

/**
 * Event fired when a guide declares or updates their volunteered
 * guiding capacity (1.7.0).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class volunteer_updated extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_volunteer';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventvolunteerupdated', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $capacity = $this->other['capacity'] ?? 0;

        return "The user with id '$this->userid' volunteered to guide up to '$capacity' groups "
            . "in the activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * URL to the guide dashboard.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Map the objectid for backup and restore.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_volunteer', 'restore' => 'selfselectadvanced_volunteer'];
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['capacity'])) {
            throw new \coding_exception('The capacity must be set in other.');
        }
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid (the guide) must be set.');
        }
    }
}
