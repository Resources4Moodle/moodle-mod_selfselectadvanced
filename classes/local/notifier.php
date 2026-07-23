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

namespace mod_selfselectadvanced\local;

use core\message\message;
use mod_selfselectadvanced\activity;

/**
 * All plugin notifications funnel through here (spec section 14.8):
 * one provider per notification kind, deep links on every message,
 * user preferences respected by the messaging subsystem.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /**
     * Send one plugin notification.
     *
     * @param activity $activity the activity
     * @param string $provider message provider name from db/messages.php
     * @param int $touserid recipient
     * @param string $subjectkey lang key for the subject
     * @param string $bodykey lang key for the body
     * @param \stdClass|array|string|null $a string parameters
     * @param \moodle_url $contexturl deep link target
     * @param string $contextname link label lang key rendered value
     */
    public static function send(
        activity $activity,
        string $provider,
        int $touserid,
        string $subjectkey,
        string $bodykey,
        $a,
        \moodle_url $contexturl,
        string $contextname
    ): void {
        $subject = get_string($subjectkey, 'mod_selfselectadvanced', $a);
        $body = get_string($bodykey, 'mod_selfselectadvanced', $a);

        $message = new message();
        $message->component = 'mod_selfselectadvanced';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $touserid;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>' . s($body) . '</p>';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->courseid = $activity->courseid();
        $message->contexturl = $contexturl->out(false);
        $message->contexturlname = $contextname;

        message_send($message);
    }
}
