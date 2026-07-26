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
     * Guide-facing providers a recipient may defer to their digest
     * (1.8.0) instead of receiving immediately.
     */
    private const DIGESTIBLE = ['guidequeue', 'deadlinereminder', 'autogroupresult'];

    /**
     * Send one plugin notification.
     *
     * When the recipient's digest preference (mod_selfselectadvanced_digest)
     * is daily or weekly and the provider is one of the DIGESTIBLE
     * kinds, the notification is queued in selfselectadvanced_digestq
     * instead of being sent now; the send_digests scheduled task flushes
     * it later using the same subject/body resolution.
     *
     * @param activity $activity the activity
     * @param string $provider message provider name from db/messages.php
     * @param int $touserid recipient
     * @param string $subjectkey lang key for the subject
     * @param string $bodykey lang key for the body
     * @param \stdClass|array|null $a string parameters (never a scalar:
     *        standard recipient placeholders are merged into the object)
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
        global $DB;

        // Standard placeholders available to EVERY template (site
        // admins can rewrite any message via Language customisation):
        // firstname, lastname, fullname of the recipient, and url.
        $a = $a === null ? new \stdClass() : (object) (array) $a;
        $recipient = \core_user::get_user($touserid);
        if ($recipient) {
            $a->firstname = $a->firstname ?? $recipient->firstname;
            $a->lastname = $a->lastname ?? $recipient->lastname;
            $a->fullname = $a->fullname ?? fullname($recipient);
        }
        $a->url = $a->url ?? $contexturl->out(false);

        if (in_array($provider, self::DIGESTIBLE, true)) {
            $period = get_user_preferences('mod_selfselectadvanced_digest', 'immediate', $touserid);
            if (in_array($period, ['daily', 'weekly'], true)) {
                // Store the already-resolved $a so the digest renders
                // identical text later, whatever templates change to
                // in the meantime.
                $DB->insert_record('selfselectadvanced_digestq', (object) [
                    'userid' => $touserid,
                    'activityid' => $activity->id(),
                    'groupid' => null,
                    'provider' => $provider,
                    'subjectkey' => $subjectkey,
                    'bodykey' => $bodykey,
                    'payload' => json_encode($a),
                    'contexturl' => $contexturl->out(false),
                    'timecreated' => time(),
                ]);

                return;
            }
        }

        [$subject, $body] = self::resolve_text($activity, $subjectkey, $bodykey, $a);

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

    /**
     * Resolve the subject and body text for a message kind: a
     * per-activity template override wins over the language string;
     * both use {$a->name} placeholders. Shared by send() and the
     * send_digests task so a queued item renders exactly as it would
     * have if sent immediately.
     *
     * @param activity $activity the activity
     * @param string $subjectkey lang key for the subject
     * @param string $bodykey lang key for the body
     * @param \stdClass $a resolved placeholder values
     * @return string[] [subject, body]
     */
    public static function resolve_text(activity $activity, string $subjectkey, string $bodykey, \stdClass $a): array {
        $custom = templates::get($activity, $bodykey);
        if ($custom) {
            return [templates::render($custom->subject, $a), templates::render($custom->body, $a)];
        }

        return [
            get_string($subjectkey, 'mod_selfselectadvanced', $a),
            get_string($bodykey, 'mod_selfselectadvanced', $a),
        ];
    }
}
