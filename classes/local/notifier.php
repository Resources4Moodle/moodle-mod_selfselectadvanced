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

    /** @var bool Test-only: also warn when send() runs inside a transaction. */
    private static bool $stricttransactioncheck = false;

    /**
     * Test-only: also warn when send() is called inside an open
     * transaction.
     *
     * Off by default because advanced_testcase holds a delegated
     * transaction for the whole of every test on PostgreSQL, so a
     * runtime guard on the transaction state would fail the suite on
     * one engine and pass it on the other (T-02).
     *
     * @param bool $on whether to warn on an open transaction
     * @throws \coding_exception outside PHPUnit
     */
    public static function set_strict_transaction_check(bool $on): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('test-only');
        }
        self::$stricttransactioncheck = $on;
    }

    /**
     * A deferred notification: everything send() needs, captured so a
     * caller holding a lock can hand it back and send it after release.
     *
     * @param string $provider message provider name from db/messages.php
     * @param int $touserid recipient
     * @param string $subjectkey lang key for the subject
     * @param string $bodykey lang key for the body
     * @param \stdClass|array|null $a string parameters
     * @param \moodle_url $contexturl deep link target
     * @param string $contextname link label
     * @return \stdClass the intent, for send_all()
     */
    public static function intent(
        string $provider,
        int $touserid,
        string $subjectkey,
        string $bodykey,
        $a,
        \moodle_url $contexturl,
        string $contextname
    ): \stdClass {
        return (object) [
            'provider' => $provider,
            'touserid' => $touserid,
            'subjectkey' => $subjectkey,
            'bodykey' => $bodykey,
            'a' => $a,
            'url' => $contexturl,
            'contextname' => $contextname,
        ];
    }

    /**
     * Users holding ANY of the given capabilities in the activity
     * context, deduplicated - a person holding two of them is returned
     * once.
     *
     * One bounded get_users_by_capability() per capability, never a
     * per-user check in a loop: at ten thousand students an operational
     * notice must not cost a query per enrolment (house rule 3). Keying
     * by id is what makes the union a union - an editing teacher who
     * also holds a narrow capability is one recipient, not two.
     *
     * @param activity $activity the activity
     * @param string[] $capabilities capability names
     * @return \stdClass[] users keyed by id
     */
    public static function recipients(activity $activity, array $capabilities): array {
        $users = [];
        foreach ($capabilities as $capability) {
            foreach (get_users_by_capability($activity->context(), $capability, 'u.id') as $user) {
                $users[(int) $user->id] = $user;
            }
        }

        return $users;
    }

    /**
     * Send a list of intent() records, in order.
     *
     * @param activity $activity the activity
     * @param \stdClass[] $intents what intent() returned, in send order
     */
    public static function send_all(activity $activity, array $intents): void {
        foreach ($intents as $intent) {
            self::send(
                $activity,
                $intent->provider,
                (int) $intent->touserid,
                $intent->subjectkey,
                $intent->bodykey,
                $intent->a,
                $intent->url,
                $intent->contextname
            );
        }
    }

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

        // House rule (the 1.15.0 lesson, restated by T-02): a message
        // never travels under a plugin lock - core buffers it to the
        // outermost commit, which is still inside the lock, so a slow
        // relay extends an activity-wide hold against a 10s budget.
        if (locks::held_count() > 0) {
            debugging(
                'notifier::send() called while holding a plugin lock (provider ' . $provider . ')',
                DEBUG_DEVELOPER
            );
        }
        if (self::$stricttransactioncheck && $DB->is_transaction_started()) {
            debugging(
                'notifier::send() called inside an open transaction (provider ' . $provider . ')',
                DEBUG_DEVELOPER
            );
        }

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
        $message->fullmessage = self::plain($activity, $body, $a, $contexturl, $contextname);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = self::html($activity, $body, $a, $contexturl, $contextname);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->courseid = $activity->courseid();
        $message->contexturl = $contexturl->out(false);
        $message->contexturlname = $contextname;

        // The return value is CHECKED, because ignoring it is how this
        // plugin once dropped every notification it sent through a
        // completely green test run: db/messages.php had gained a
        // provider, no upgrade had registered it, message_send()
        // returned false for each one, and nothing anywhere said so.
        //
        // false here means a delivery the messaging subsystem refused
        // outright - an unregistered provider, a malformed message -
        // not a user who has merely turned this notification off, which
        // message_send() handles internally and still reports as sent.
        // So there is no such thing as a routine false, and it is worth
        // being loud about.
        if (message_send($message) === false) {
            debugging(
                'mod_selfselectadvanced: message_send refused the "' . $provider
                . '" notification to user ' . $touserid
                . '. Check that the provider is registered in db/messages.php and that the'
                . ' plugin version was raised so the upgrade re-read it.',
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Split a body into paragraphs.
     *
     * Templates are written as sentences; a blank line, or a line
     * break, means a new paragraph. Anything else would run the whole
     * message together, which is what these notifications used to do.
     *
     * @param string $body the resolved body text
     * @return string[] paragraphs, trimmed, none empty
     */
    private static function paragraphs(string $body): array {
        $parts = preg_split('/\R\s*\R|\R/u', trim($body)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
    }

    /**
     * The signature line: which activity, in which course.
     *
     * A notification that does not say where it came from is a puzzle
     * for anybody enrolled in more than one thing.
     *
     * @param activity $activity the activity
     * @return string
     */
    private static function signature(activity $activity): string {
        $course = get_course($activity->courseid());

        return get_string('msgsignature', 'mod_selfselectadvanced', (object) [
            'activity' => format_string($activity->name()),
            'course' => format_string($course->fullname),
        ]);
    }

    /**
     * The plain-text message: greeting, the body in paragraphs, the
     * link, and the signature.
     *
     * @param activity $activity the activity
     * @param string $body the resolved body
     * @param \stdClass $a the resolved placeholders
     * @param \moodle_url $contexturl where to go
     * @param string $contextname what is there
     * @return string
     */
    private static function plain(
        activity $activity,
        string $body,
        \stdClass $a,
        \moodle_url $contexturl,
        string $contextname
    ): string {
        $lines = [];
        if (!empty($a->firstname)) {
            $lines[] = get_string('msggreeting', 'mod_selfselectadvanced', $a->firstname);
            $lines[] = '';
        }
        foreach (self::paragraphs($body) as $paragraph) {
            $lines[] = $paragraph;
            $lines[] = '';
        }
        $lines[] = $contextname . ': ' . $contexturl->out(false);
        $lines[] = '';
        $lines[] = self::signature($activity);

        return implode("\n", $lines);
    }

    /**
     * The HTML message: the same thing, laid out so the eye can find
     * the part that matters.
     *
     * The first paragraph carries the news and is set in bold; the rest
     * follow as ordinary paragraphs; the link is a button-ish anchor;
     * the signature is small and quiet at the foot.
     *
     * @param activity $activity the activity
     * @param string $body the resolved body
     * @param \stdClass $a the resolved placeholders
     * @param \moodle_url $contexturl where to go
     * @param string $contextname what is there
     * @return string
     */
    private static function html(
        activity $activity,
        string $body,
        \stdClass $a,
        \moodle_url $contexturl,
        string $contextname
    ): string {
        $out = '';
        if (!empty($a->firstname)) {
            $out .= \html_writer::tag(
                'p',
                s(get_string('msggreeting', 'mod_selfselectadvanced', $a->firstname))
            );
        }
        foreach (self::paragraphs($body) as $index => $paragraph) {
            $out .= \html_writer::tag(
                'p',
                s($paragraph),
                $index === 0 ? ['style' => 'font-weight:600;'] : []
            );
        }
        $out .= \html_writer::tag('p', \html_writer::link(
            $contexturl->out(false),
            s($contextname),
            ['style' => 'display:inline-block;padding:8px 14px;background:#0f6cbf;'
                . 'color:#fff;text-decoration:none;border-radius:4px;']
        ));
        $out .= \html_writer::tag('hr', '', ['style' => 'border:0;border-top:1px solid #ddd;margin:16px 0;']);
        $out .= \html_writer::tag(
            'p',
            s(self::signature($activity)),
            ['style' => 'color:#666;font-size:0.9em;']
        );

        return $out;
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
     * @param array|null $overrides this activity's overrides, msgkey-keyed
     *        as templates::get_all() returns them, when the CALLER has
     *        already loaded them. Null means look it up, which is one
     *        query - fine for a single message, and once per queued
     *        ITEM for the digest task, which is why that task now
     *        loads them once per activity and passes them in
     *        (PERF-001). The RULE is unchanged either way: an override
     *        wins over the language string, and a missing key is an
     *        absent override, exactly as templates::get() reports it.
     * @return string[] [subject, body]
     */
    public static function resolve_text(
        activity $activity,
        string $subjectkey,
        string $bodykey,
        \stdClass $a,
        ?array $overrides = null
    ): array {
        $custom = $overrides === null
            ? templates::get($activity, $bodykey)
            : ($overrides[$bodykey] ?? null);
        if ($custom) {
            return [templates::render($custom->subject, $a), templates::render($custom->body, $a)];
        }

        return [
            get_string($subjectkey, 'mod_selfselectadvanced', $a),
            get_string($bodykey, 'mod_selfselectadvanced', $a),
        ];
    }
}
