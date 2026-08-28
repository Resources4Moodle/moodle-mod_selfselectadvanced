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
 * Event fired when a queue ticket changes: the requester answered a
 * needs-info question (maintainer decision 2, 2026-08-15).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_info_provided extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_ticket';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventticketinfoprovided', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $type = $this->other['type'] ?? '';

        return "The user with id '$this->userid' answered the needs-info question on the '$type' "
            . "ticket with id '$this->objectid' in the activity with course module id "
            . "'$this->contextinstanceid'.";
    }

    /**
     * URL to the ticket queue.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/tickets.php', [
            'id' => $this->contextinstanceid,
        ]);
    }

    /**
     * Map the objectid for backup and restore.
     *
     * 1.20.60 (audit L-19): without this, core's logstore restore has no
     * way to translate this event's objectid into the id the row was
     * given on the target site, so the log record is dropped rather than
     * carried through - and the ticket trail, which every other part of
     * this plugin backs up and restores faithfully, loses its matching
     * log entries. Every other event class in this plugin already
     * declares one; the ticket and knowledgebank families were the
     * exception.
     *
     * 'restore' is the name the restore step gave this table's MAPPING
     * (set_mapping('ssaticket', ...) / set_mapping('ssakbentry', ...)),
     * not the table name - a mapping name that does not exist would
     * silently map nothing, which is the state this is fixing.
     *
     * @return array mapping description
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'selfselectadvanced_ticket', 'restore' => 'ssaticket'];
    }
}
