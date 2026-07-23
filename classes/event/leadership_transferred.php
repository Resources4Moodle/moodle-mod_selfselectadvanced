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
 * Event fired when group leadership transfers to a confirmed successor,
 * by plain transfer or by leader step-out (spec section 6.4).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class leadership_transferred extends \core\event\base {
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
        return get_string('eventleadershiptransferred', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $uid = $this->other['pluginuid'] ?? $this->objectid;
        $type = $this->other['type'] ?? 'transfer';

        return "Leadership of the group '$uid' transferred from the user with id "
            . "'{$this->other['fromuserid']}' to the user with id '$this->relateduserid' ($type).";
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
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid (new leader) must be set.');
        }
        if (!isset($this->other['fromuserid']) || !isset($this->other['pluginuid']) || !isset($this->other['type'])) {
            throw new \coding_exception('fromuserid, pluginuid and type must be set in other.');
        }
    }
}
