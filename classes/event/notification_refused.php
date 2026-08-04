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
 * Event fired when the messaging subsystem refuses one of this
 * plugin's notifications outright - message_send() returned false.
 *
 * This is the durable record MSG-001 asked for: a refusal used to earn
 * one DEBUG_DEVELOPER debugging() call, which a production site never
 * shows anybody, so a misregistered provider dropped every message it
 * carried without a trace. A refusal is never routine (a recipient who
 * has merely turned a notification off is still reported as sent), so
 * each one is worth a log row an administrator can find.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notification_refused extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventnotificationrefused', 'mod_selfselectadvanced');
    }

    /**
     * Human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $o = $this->other;

        return "Moodle messaging refused the '{$o['provider']}' notification"
            . " to the user with id '{$o['touserid']}'. Check that the provider is"
            . " registered in db/messages.php and that the plugin version was raised"
            . " so the upgrade re-read it.";
    }

    /**
     * Validate required custom data.
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['provider', 'touserid'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The $key must be set in other.");
            }
        }
    }
}
