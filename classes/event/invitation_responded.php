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
 * Shared shape for the invitation outcome events (accepted, declined,
 * expired, withdrawn): member row object, invitee as related user,
 * group identity in other.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class invitation_responded extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_member';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * URL to the group page.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $this->contextinstanceid,
            'g' => $this->other['groupid'],
        ]);
    }

    /**
     * Map the objectid for backup and restore.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_member', 'restore' => 'selfselectadvanced_member'];
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid (invitee) must be set.');
        }
        if (!isset($this->other['groupid']) || !isset($this->other['pluginuid'])) {
            throw new \coding_exception('The groupid and pluginuid must be set in other.');
        }
    }
}
