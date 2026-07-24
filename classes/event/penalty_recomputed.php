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
 * Event fired when a group's ledger penalty changes (decision A12).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class penalty_recomputed extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_penalty';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventpenaltyrecomputed', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description with the delta.
     *
     * @return string
     */
    public function get_description(): string {
        $o = $this->other;
        $old = $o['oldvalue'] === null ? 'none' : $o['oldvalue'];

        return "The penalty of group '{$o['groupid']}' changed from $old to {$o['newvalue']}.";
    }

    /**
     * Map the objectid for backup and restore.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_penalty', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['groupid']) || !array_key_exists('newvalue', $this->other)) {
            throw new \coding_exception('groupid and newvalue must be set in other.');
        }
    }
}
