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
 * Event fired when an override is created, carrying the actor, target,
 * old and new values (spec 10).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_created extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_override';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventoverridecreated', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description with the value delta.
     *
     * @return string
     */
    public function get_description(): string {
        $o = $this->other;

        return "The user with id '$this->userid' created a {$o['scope']}-scope override "
            . "(target id {$o['targetid']}): old " . json_encode($o['oldvalues'])
            . ", new " . json_encode($o['newvalues']) . '.';
    }

    /**
     * Map the objectid for backup and restore.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_override', 'restore' => 'selfselectadvanced_override'];
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['scope', 'targetid', 'oldvalues', 'newvalues'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The $key must be set in other.");
            }
        }
    }
}
