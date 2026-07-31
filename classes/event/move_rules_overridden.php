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
 * Event fired when a staff roster action commits over a composition or
 * limit rule (decision 6).
 *
 * The bypass hatch existed for two releases as a set of checkboxes on
 * one form, recorded in a table nothing listed and absent from the
 * commit event (D6-1, D6-6). This is the named record: which rules were
 * overridden, with the figures that refused them, why, by whom, and for
 * which student. It fires AFTER the commit and AFTER every lock has
 * been released - a new event never travels under either.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_rules_overridden extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_move';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventmoverulesoverridden', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $o = $this->other;
        $rules = implode(', ', (array) ($o['rules'] ?? []));

        return "The user with id '$this->userid' committed a roster change for the user with id "
            . "'$this->relateduserid' overriding rules $rules: {$o['reason']}";
    }

    /**
     * Map the objectid for backup and restore.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_move', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Validate required custom data.
     *
     * The two things that make this event worth having are the rules
     * and the reason; an override with neither is exactly the silent
     * bypass decision 6 exists to end, so it is a coding error here
     * rather than an empty log line later.
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (empty($this->other['rules']) || !is_array($this->other['rules'])) {
            throw new \coding_exception('The rules must be a non-empty array in other.');
        }
        if (trim((string) ($this->other['reason'] ?? '')) === '') {
            throw new \coding_exception('The reason must be a non-empty string in other.');
        }
    }
}
