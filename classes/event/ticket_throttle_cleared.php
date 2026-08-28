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
 * Event fired when a member of staff LIFTED the ticket throttle one
 * requester was under (1.20.60).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_throttle_cleared extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'selfselectadvanced_ticketthrottle';
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventticketthrottlecleared', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '$this->userid' lifted the ticket throttle on the user with id "
            . "'$this->relateduserid' in the activity with course module id "
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

    // NO objectid mapping, deliberately.
    //
    // Throttles are not backed up. A rate limit is a moderation decision
    // about one person in one running activity - restoring it into a new
    // course would silently re-impose somebody's judgement on somebody
    // else's students, months later, with no way for them to see where it
    // came from. Since the row cannot survive a restore, claiming a
    // mapping for it would be claiming a translation that does not exist:
    // core's default (no mapping, log record not carried) is the honest
    // answer, and this comment is here so the absence reads as a decision
    // rather than an oversight (audit L-19 is why every OTHER event in
    // this plugin does declare one).
}
