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
 * Event fired when a team loses its guide because the guide's account
 * was deleted or lost its last enrolment in the course (OBS-001).
 *
 * Fired only on the clearing half of the policy - forming and
 * submitted teams, whose guideid is released the way a return releases
 * it. A firm or frozen team keeps its state and files a succession
 * ticket instead, which is ticket_filed's record, not this one's.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guide_removed extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_group';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventguideremoved', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $uid = $this->other['pluginuid'] ?? $this->objectid;
        $reason = $this->other['reason'] ?? '';

        return "The group '$uid' lost its guide (user id '$this->relateduserid'): "
            . "the account was $reason, so the guide assignment was released.";
    }

    /**
     * URL to the group page.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $this->contextinstanceid,
            'g' => $this->objectid,
        ]);
    }

    /**
     * Map the objectid for backup and restore.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_group', 'restore' => 'selfselectadvanced_group'];
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['pluginuid'])) {
            throw new \coding_exception('The pluginuid must be set in other.');
        }
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The reason must be set in other.');
        }
    }
}
