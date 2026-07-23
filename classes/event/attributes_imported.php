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
 * Event fired when a participant-attribute CSV import commits.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attributes_imported extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventattributesimported', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $o = $this->other;

        return "The user with id '$this->userid' imported participant attributes: "
            . "{$o['total']} rows, {$o['created']} created, {$o['updated']} updated, "
            . "{$o['warnings']} warnings, {$o['rejected']} rejected.";
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['total', 'created', 'updated', 'warnings', 'rejected'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The $key count must be set in other.");
            }
        }
    }
}
