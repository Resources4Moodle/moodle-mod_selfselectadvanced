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
 * Event fired when a ticket's thread (ticket.php) is opened (slice B2,
 * maintainer: "bad players who post messages can be tracked readily").
 *
 * Mirrors mod_forum's discussion_viewed shape (crud 'r', objectid the
 * thing read) rather than inventing a new one - a read event needs
 * nothing this plugin's write events carry, so there is no note, no
 * ticketlogid (a view logs nothing to selfselectadvanced_ticketlog) and
 * no relateduserid: the standard log store already answers "who read
 * what and when" from userid/objectid/timecreated alone.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_viewed extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_ticket';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventticketviewed', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $type = $this->other['type'] ?? '';

        return "The user with id '$this->userid' viewed the '$type' ticket with id "
            . "'$this->objectid' in the activity with course module id "
            . "'$this->contextinstanceid'.";
    }

    /**
     * URL to the ticket's thread.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/ticket.php', [
            't' => $this->objectid,
        ]);
    }
}
