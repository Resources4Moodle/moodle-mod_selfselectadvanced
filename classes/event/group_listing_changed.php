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
 * Event fired when a forming team is listed for guides to browse, or
 * withdrawn from that listing.
 *
 * The listing toggle used to be an inline field update on group.php
 * with no service, no authority question and no record that anything
 * had happened (AUTH-001). Listing PUBLISHES a team to every guide in
 * the activity, so the one thing an administrator investigating a
 * listing they did not expect needs is a row saying who listed it and
 * when.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_listing_changed extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_group';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventgrouplistingchanged', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $uid = $this->other['pluginuid'] ?? $this->objectid;
        $verb = !empty($this->other['listed']) ? 'listed' : 'unlisted';

        return "The user with id '$this->userid' $verb the group '$uid' for guide interest "
            . "in the activity with course module id '$this->contextinstanceid'.";
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
     * Custom data validation: the new listing state travels in other.
     *
     * @throws \coding_exception when required keys are absent
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['listed'])) {
            throw new \coding_exception('The listed flag must be set in other.');
        }
        if (!isset($this->other['pluginuid'])) {
            throw new \coding_exception('The pluginuid must be set in other.');
        }
    }
}
