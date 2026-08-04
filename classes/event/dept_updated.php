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
 * Event fired when an attribute vocabulary entry is renamed or
 * reordered (AUTH-001/LOG-001, 1.20.4).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dept_updated extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_dept';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventdeptupdated', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $name = $this->other['name'] ?? '';
        $kind = $this->other['kind'] ?? '';

        return "The user with id '$this->userid' updated the $kind '$name' "
            . "(id '$this->objectid') in the attribute vocabulary.";
    }

    /**
     * URL to the vocabulary admin page.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/departments.php');
    }

    /**
     * Map the objectid for backup and restore.
     *
     * The vocabulary is site-wide and never part of an activity backup,
     * so restored logs keep no mapping.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_dept', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['name'])) {
            throw new \coding_exception('The name must be set in other.');
        }
        if (!isset($this->other['kind'])) {
            throw new \coding_exception('The kind must be set in other.');
        }
    }
}
