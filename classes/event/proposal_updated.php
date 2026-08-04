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
 * Event fired when a team's written proposal is uploaded, replaced or
 * removed.
 *
 * The upload used to be an inline file_save_draft_area_files() on
 * group.php with no service behind it (AUTH-002). The proposal is the
 * document a guide reads before taking a team on, so a replacement is
 * exactly the kind of change somebody later has to be able to date.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposal_updated extends \core\event\base {
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
        return get_string('eventproposalupdated', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $uid = $this->other['pluginuid'] ?? $this->objectid;
        $count = (int) ($this->other['filecount'] ?? 0);
        $verb = $count > 0 ? 'saved' : 'removed';

        return "The user with id '$this->userid' $verb the proposal of the group '$uid' "
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
     * Custom data validation: the resulting file count travels in other.
     *
     * @throws \coding_exception when required keys are absent
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['filecount'])) {
            throw new \coding_exception('The filecount must be set in other.');
        }
        if (!isset($this->other['pluginuid'])) {
            throw new \coding_exception('The pluginuid must be set in other.');
        }
    }
}
