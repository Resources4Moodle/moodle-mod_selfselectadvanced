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
 * Event fired when a queue ticket changes: the requester answered
 * "did this help?" on a resolved ticket (1.20.59, tickets::give_feedback()).
 *
 * No relateduserid: unlike ticket_closed/ticket_info_requested, this
 * event's userid IS the requester already - there is no separate "other
 * party" to name, and the ticket's own claimedby/resolvedby are
 * untouched by this action (D-108: RECORD, NEVER REOPEN).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_feedback_given extends \core\event\base {
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
        return get_string('eventticketfeedbackgiven', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $type = $this->other['type'] ?? '';
        $helped = (int) ($this->other['verdict'] ?? 0) === \mod_selfselectadvanced\local\tickets::VERDICT_HELPED
            ? 'helped' : 'did not help';

        return "The user with id '$this->userid' said the resolution of the '$type' ticket with id "
            . "'$this->objectid' $helped, in the activity with course module id "
            . "'$this->contextinstanceid'.";
    }

    /**
     * URL to the ticket's own thread.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/ticket.php', [
            't' => $this->objectid,
        ]);
    }
}
