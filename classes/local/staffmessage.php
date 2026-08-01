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

use mod_selfselectadvanced\activity;

/**
 * Staff reaching one participant, without an address in either
 * direction (maintainer decision 18, 2026-08-01).
 *
 * The message is a Moodle message: the recipient's own notification
 * preferences decide whether it arrives as a popup, an email, both or
 * neither, and neither party is shown the other's address. There is no
 * SMTP here and there must never be one - this is the replacement for
 * every mailto: link 1.20 removed, not a second channel beside them.
 *
 * The sender's NAME is disclosed, because a message from an anonymous
 * member of staff is unusable; their ADDRESS is not, because
 * notifier::send() fixes userfrom to the no-reply user. Do not
 * "improve" that by setting userfrom to the real sender.
 *
 * Read-then-send only: no lock, no transaction, no plugin-table write,
 * no event. Never call from db/upgrade.php.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staffmessage {
    /** @var int Subjects resolved per query round trip. */
    private const CHUNK = 1000;

    /**
     * Bulk verdict: subjectid => whether this viewer may message them.
     *
     * Deliberately NOT can_see_map(): that helper is all-true when the
     * contact-privacy switch is off, which is right for a display gate
     * and would turn this action into "everyone may message everyone"
     * the moment an editing teacher turns protection off. The
     * connection is asked directly, through guided_subjects().
     *
     * :viewall IS legitimate here - this is a REACH question ("may I
     * see this whole activity"), not an identity question, and no
     * address is disclosed either way. That is exactly why identity
     * decisions elsewhere in the plugin may no longer read it and this
     * one may.
     *
     * Two capability checks and at most two bounded queries for the
     * whole page: never call may_message() in a loop.
     *
     * @param activity $activity the activity
     * @param int $viewerid the sender
     * @param int[] $subjectids the candidate recipients
     * @return bool[] subjectid => whether the viewer may message them
     */
    public static function may_message_map(activity $activity, int $viewerid, array $subjectids): array {
        global $DB;

        $subjectids = array_values(array_unique(array_map('intval', $subjectids)));
        if (!$subjectids) {
            return [];
        }
        $map = array_fill_keys($subjectids, false);

        $context = $activity->context();
        $broadreach = has_capability('mod/selfselectadvanced:manage', $context, $viewerid)
            || has_capability('mod/selfselectadvanced:viewall', $context, $viewerid);

        // Nobody messages themself: the roster shows the action on
        // other people's rows only.
        $candidates = array_values(array_filter($subjectids, static fn(int $id) => $id !== $viewerid));
        if (!$broadreach) {
            $candidates = contactprivacy::guided_subjects($activity, $viewerid, $candidates);
        }
        if (!$candidates) {
            return $map;
        }

        // The recipient must actually be able to take part in this
        // activity. One bounded query over the candidates, not a
        // per-row capability check.
        [$enrolsql, $enrolparams] = get_enrolled_sql($context, 'mod/selfselectadvanced:respond', 0, true);
        foreach (array_chunk($candidates, self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'sm');
            $rows = $DB->get_fieldset_sql(
                "SELECT eu.id FROM ($enrolsql) eu WHERE eu.id $insql",
                $enrolparams + $inparams
            );
            foreach ($rows as $userid) {
                $map[(int) $userid] = true;
            }
        }

        return $map;
    }

    /**
     * Whether this viewer may message this participant at all.
     *
     * Single-subject convenience over may_message_map(). A page with a
     * roster asks the bulk form once instead of calling this per row.
     *
     * @param activity $activity the activity
     * @param int $viewerid the sender
     * @param int $subjectid the recipient
     * @return bool whether the message is allowed
     */
    public static function may_message(activity $activity, int $viewerid, int $subjectid): bool {
        return self::may_message_map($activity, $viewerid, [$subjectid])[$subjectid] ?? false;
    }

    /**
     * Send one message.
     *
     * The gate is re-checked here and not merely on the page: the
     * service is the authority, a page is only a caller.
     *
     * @param activity $activity the activity
     * @param int $viewerid the sender
     * @param int $subjectid the recipient
     * @param string $subject the subject line, PARAM_TEXT
     * @param string $body the message, PARAM_TEXT
     * @throws \moodle_exception refusalcannotmessage when the gate refuses
     */
    public static function send(
        activity $activity,
        int $viewerid,
        int $subjectid,
        string $subject,
        string $body
    ): void {
        if (!self::may_message($activity, $viewerid, $subjectid)) {
            throw new \moodle_exception('refusalcannotmessage', 'mod_selfselectadvanced');
        }

        $sender = \core_user::get_user($viewerid);
        notifier::send(
            $activity,
            'staffmessage',
            $subjectid,
            'msgstaffmessagesubject',
            'msgstaffmessagebody',
            (object) [
                'sender' => $sender ? fullname($sender) : '',
                'subject' => $subject,
                'message' => $body,
                'activity' => $activity->name(),
            ],
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            $activity->name()
        );
    }

    /**
     * Where the Send-a-message form for one participant lives.
     *
     * A plain link to a form: following it sends nothing (house rule -
     * no state-mutating GET).
     *
     * @param activity $activity the activity
     * @param int $subjectid the recipient
     * @param \moodle_url $returnurl where to go back to after sending
     * @return \moodle_url the form url
     */
    public static function url(activity $activity, int $subjectid, \moodle_url $returnurl): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/message.php', [
            'id' => $activity->cm()->id,
            'to' => $subjectid,
            'returnurl' => $returnurl->out_as_local_url(false),
        ]);
    }

    /**
     * The Send-a-message link for one participant, for a table cell.
     *
     * @param activity $activity the activity
     * @param int $subjectid the recipient
     * @param \moodle_url $returnurl where to go back to after sending
     * @param string $class css classes for the anchor
     * @return string the rendered link
     */
    public static function link(
        activity $activity,
        int $subjectid,
        \moodle_url $returnurl,
        string $class = 'btn btn-outline-secondary btn-sm'
    ): string {
        return \html_writer::link(
            self::url($activity, $subjectid, $returnurl),
            get_string('messagesend', 'mod_selfselectadvanced'),
            ['class' => $class]
        );
    }
}
