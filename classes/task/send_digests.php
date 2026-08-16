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

namespace mod_selfselectadvanced\task;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\notifier;
use mod_selfselectadvanced\local\templates;

/**
 * Scheduled task: flush queued guide-facing notifications into one
 * digest message per recipient whose daily or weekly period has
 * elapsed (1.8.0). Rows are grouped by userid; a user's oldest queued
 * row decides whether the period has elapsed (daily: older than 24
 * hours, weekly: older than 7 days). Sent rows are deleted after the
 * message is sent; sending happens outside any transaction, exactly
 * like every other notification in this plugin.
 *
 * Each recipient is flushed inside its own try/catch: one failure is
 * logged and skipped (its rows stay queued for the next pass) rather
 * than aborting every other recipient's digest in the same run. The
 * task throws only when EVERY recipient failed.
 *
 * BOUNDED SINCE THIS RELEASE (PERF-001). What one run used to load was
 * a function of the TABLE, not of the work:
 *
 *   - `SELECT DISTINCT userid` returned every recipient with anything
 *     queued, however far from due;
 *   - then, for each of them, EVERY queued row of that recipient was
 *     read into memory - before their period was consulted, so the
 *     rows of a weekly recipient one day into their week were loaded
 *     and thrown away, on every run, for six days;
 *   - then get_user_preferences() was called per recipient, which for
 *     a user who is not the current one is one query each, loading all
 *     of that user's preferences;
 *   - and templates::get() was called once PER QUEUED ITEM, so a
 *     digest of n items cost n queries for one activity's override.
 *
 * MEASURED on m5pg, on a seeded queue of 60 recipients x 3 items with
 * nobody due: 122 reads before and 1 after, and the 180 item rows are
 * not read at all where 240 rows (60 userids + 180 items) used to be.
 * At the dev site's scale - 13,000 enrolled users with one queued item
 * each - a run loaded 13,000 candidate rows plus 13,000 item rows plus
 * 13,000 preference loads; it now loads at most BATCH candidate rows
 * plus the items of the recipients actually flushed, whatever the
 * table holds.
 *
 * The three bounds, in the order they apply:
 *   1. the SQL returns only recipients whose period HAS elapsed - the
 *      preference is joined and the cutoff compared IN the query, the
 *      same technique guide_autoapprove::escalate() uses on its
 *      reminder markers, so a not-due recipient cannot occupy a slot;
 *   2. at most BATCH recipients per run, oldest queue first;
 *   3. at most ITEMS rows per recipient per run, and only the rows
 *      loaded are deleted, so the remainder flushes on the next pass.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_digests extends \core\task\scheduled_task {
    /**
     * @var int Recipients flushed per run. Overridable per site with
     *      the config_plugins value mod_selfselectadvanced/digestbatch:
     *      the same ops escape hatch guide_autoapprove uses for
     *      autoapprovebatch, and deliberately NOT an admin setting - a
     *      site whose cron budget cannot take 200 digests in one pass
     *      turns it down, and nobody else has to think about it.
     */
    private const BATCH = 200;

    /**
     * @var int Queued items read per recipient per run, overridable
     *      with mod_selfselectadvanced/digestitembatch. A recipient
     *      with more than this many queued items gets the oldest ITEMS
     *      of them in this run's digest and the rest in the next one:
     *      their period has elapsed either way, so nothing is delayed
     *      that was not already waiting. This is the cap that keeps one
     *      pathological queue from deciding the memory cost of the run.
     */
    private const ITEMS = 100;

    /**
     * @var int send_one_digest(): the digest message was submitted to
     *      Moodle messaging. The only outcome $sent counts (NEW-001).
     */
    private const SUBMITTED = 0;

    /**
     * @var int send_one_digest(): nothing was sendable because every
     *      row's activity has been deleted; no message was constructed.
     *      The rows must still leave the queue, but as CLEANUP - never
     *      as a submission, never in $sent, never in the log line that
     *      claims one (NEW-001: a stale-only batch used to log
     *      "submitted a digest" and could mask the all-failed
     *      escalation).
     */
    private const STALE_DISCARDED = 1;

    /**
     * @var int send_one_digest(): message_send() refused the
     *      submission; the rows must stay queued (DIGEST-001).
     */
    private const FAILED = 2;

    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksenddigests', 'mod_selfselectadvanced');
    }

    /**
     * Flush every elapsed queue into one digest message per recipient,
     * up to this run's caps.
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $activities = [];
        $overrides = [];
        $sent = 0;
        $failed = 0;
        $stale = 0;
        $skippedrows = 0;
        $batch = (int) get_config('mod_selfselectadvanced', 'digestbatch');
        $batch = $batch > 0 ? $batch : self::BATCH;
        $itembatch = (int) get_config('mod_selfselectadvanced', 'digestitembatch');
        $itembatch = $itembatch > 0 ? $itembatch : self::ITEMS;

        // WHO IS DUE, decided in the query. The period lives in a user
        // preference, so the preference is JOINED and the comparison
        // made against the cutoff its value selects - exactly the
        // technique guide_autoapprove::escalate() uses to keep teams
        // that need nothing out of its batch.
        //
        // Two things follow, and both matter. A recipient whose period
        // has not elapsed costs this task NOTHING: not a row of their
        // queue, not a preference load. And because they never appear,
        // they cannot occupy a slot in the batch either - order the
        // page by the oldest queued item and the head of it is always
        // the most overdue, so no recipient can be starved behind a
        // crowd of not-yet-due ones.
        //
        // The three arms are the PHP thresholds that stood here before,
        // unchanged: weekly is 7 days, daily is 24 hours, and anything
        // else - no preference row at all, 'immediate', or a value this
        // plugin does not recognise - is due the moment it is queued.
        //
        // Written as three AND-ed comparisons rather than one CASE
        // returning the cutoff, because a placeholder inside a CASE has
        // nothing to take its type from: PostgreSQL reads the whole
        // expression as text and refuses `bigint <= text`. Each cutoff
        // here sits directly against MIN(q.timecreated), so both engines
        // type it as the integer it is.
        $sql = "SELECT q.userid, MIN(q.timecreated) AS oldest, COUNT(1) AS queued
                  FROM {selfselectadvanced_digestq} q
             LEFT JOIN {user_preferences} p ON p.userid = q.userid AND p.name = :prefname
              GROUP BY q.userid, p.value
                HAVING (p.value = :weeklyvalue AND MIN(q.timecreated) <= :weekcutoff)
                    OR (p.value = :dailyvalue AND MIN(q.timecreated) <= :daycutoff)
                    OR ((p.value IS NULL OR (p.value <> :weeklyother AND p.value <> :dailyother))
                        AND MIN(q.timecreated) <= :nowcutoff)
              ORDER BY MIN(q.timecreated) ASC";
        $due = $DB->get_records_sql($sql, [
            'prefname' => 'mod_selfselectadvanced_digest',
            'weeklyvalue' => 'weekly',
            'dailyvalue' => 'daily',
            'weeklyother' => 'weekly',
            'dailyother' => 'daily',
            'weekcutoff' => $now - WEEKSECS,
            'daycutoff' => $now - DAYSECS,
            'nowcutoff' => $now,
        ], 0, $batch);

        foreach ($due as $candidate) {
            $userid = (int) $candidate->userid;
            // ITEM CAP. The queue of one recipient is no longer read
            // whole: the oldest $itembatch rows are read, sent and
            // deleted, and any remainder is still queued and still
            // elapsed, so the next run takes the next slice.
            $rows = $DB->get_records(
                'selfselectadvanced_digestq',
                ['userid' => $userid],
                // The id breaks the tie: this batch reads the oldest rows and then deletes them.
                'timecreated ASC, id ASC',
                '*',
                0,
                $itembatch
            );
            if (!$rows) {
                // Flushed by another pass between the two queries.
                continue;
            }

            // One recipient's failure (a deleted account, a messaging
            // backend hiccup) must not abort every other user's digest
            // in the same run; the row is left queued so nothing is
            // silently lost, and the next scheduled pass retries it.
            //
            // And a failure is not only a THROW: message_send() reports
            // a problem by returning false, and treating that as sent
            // deleted the queue rows for a notification nobody received
            // (1.20.3 closure evaluation, DIGEST-001). The rows are
            // deleted only when send_one_digest() says the message was
            // submitted - or when there was legitimately nothing to
            // send, because rows whose activity no longer exists must
            // still leave the queue or they retry for ever. The two
            // are not the same outcome and are not counted or logged
            // as the same outcome (NEW-001).
            try {
                $skipped = 0;
                $status = $this->send_one_digest($userid, $rows, $activities, $overrides, $skipped);
                if ($status === self::FAILED) {
                    $failed++;
                    mtrace("mod_selfselectadvanced: messaging refused the digest for user $userid; "
                        . count($rows) . " row(s) left queued for the next run");
                } else {
                    \mod_selfselectadvanced\local\notifier::purge_digests(array_keys($rows));
                    if ($status === self::SUBMITTED) {
                        if ($skipped > 0) {
                            // Rows whose activity vanished inside an
                            // otherwise sendable digest: they leave
                            // the queue with the batch, but never
                            // silently (MSG-002 r2).
                            $skippedrows += $skipped;
                            mtrace("mod_selfselectadvanced: dropped $skipped stale row(s) (activity gone)"
                                . " from user $userid's digest");
                        }
                        $sent++;
                        $more = (int) $candidate->queued - count($rows);
                        mtrace("mod_selfselectadvanced: submitted a digest of " . (count($rows) - $skipped)
                            . " item(s) to user $userid"
                            . ($more > 0 ? " ($more more queued for the next run)" : ''));
                    } else {
                        $stale++;
                        $skippedrows += $skipped;
                        mtrace("mod_selfselectadvanced: discarded " . count($rows) . " stale digest row(s)"
                            . " for user $userid (every activity gone); nothing was submitted");
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                mtrace("mod_selfselectadvanced: send_digests failed to notify user $userid: " . $e->getMessage());
            }
        }

        // What this run EXAMINED, stated rather than implied: a pass
        // that found nothing and a pass that was cut off by the cap
        // look identical in the task log otherwise.
        mtrace("mod_selfselectadvanced: send_digests examined " . count($due) . " due recipient(s)"
            . " (caps: $batch recipients, $itembatch items each)"
            . ($stale > 0 ? "; $stale stale batch(es) discarded" : '')
            . ($skippedrows > 0 ? "; $skippedrows stale row(s) dropped in all" : '')
            . (count($due) >= $batch ? '; the cap was reached, more remain for the next run' : ''));

        if ($failed > 0 && $sent === 0) {
            // A scheduled task failing this run is not itself a
            // problem (the next pass tries again); only escalate when
            // NOTHING got through, so a genuinely broken run is still
            // visible in the admin task log rather than failing
            // silently. $sent counts SUBMITTED alone, so a run that
            // merely swept stale rows cannot pass for one that
            // delivered something (NEW-001).
            throw new \RuntimeException(
                "mod_selfselectadvanced: send_digests could not deliver any of $failed queued digest(s)"
            );
        }
    }

    /**
     * Send one aggregated digest message listing every queued item.
     *
     * Returns what actually happened, in three states the caller must
     * tell apart (NEW-001; the old bool overloaded "submitted" with
     * "all rows stale, nothing sent", so cleanup was counted and
     * logged as delivery):
     *
     *   - SUBMITTED: the message went to Moodle messaging (submitted,
     *     not delivered - message_send() promises no more). The caller
     *     may delete the rows and count a send.
     *   - STALE_DISCARDED: every row's activity is gone; no message
     *     was constructed. The caller must still delete the rows
     *     (retrying them is pointless) but count and log CLEANUP.
     *   - FAILED: message_send() reported a problem; the rows must
     *     stay queued (DIGEST-001).
     *
     * @param int $userid the recipient
     * @param \stdClass[] $rows queued rows for this user, oldest first
     * @param activity[] $activities activityid-keyed cache, filled in as needed
     * @param array[] $overrides activityid-keyed message-template
     *        overrides, filled in as needed; ONE query per activity per
     *        run instead of the one per queued ITEM that
     *        notifier::resolve_text() makes when it has to look them up
     *        itself (PERF-001). The overrides of an activity do not
     *        vary between two rows of the same digest.
     * @param int $skipped out: rows omitted from the digest because
     *        their activity has been deleted. Nonzero beside SUBMITTED
     *        means the batch was sent short (MSG-002 r2); equal to
     *        count($rows) beside STALE_DISCARDED.
     * @return int one of self::SUBMITTED, self::STALE_DISCARDED,
     *         self::FAILED
     */
    private function send_one_digest(int $userid, array $rows, array &$activities, array &$overrides, int &$skipped): int {
        $items = [];
        $firsturl = '';
        foreach ($rows as $row) {
            $activityid = (int) $row->activityid;
            if (!isset($activities[$activityid])) {
                try {
                    $activities[$activityid] = activity::from_instance($activityid);
                } catch (\moodle_exception $e) {
                    // The activity behind this row is gone. The row is
                    // COUNTED, not just passed over: an invisible skip
                    // is how a deleted row leaves no trace (MSG-002 r2).
                    $skipped++;
                    continue;
                }
            }
            $activity = $activities[$activityid];
            if (!isset($overrides[$activityid])) {
                $overrides[$activityid] = templates::get_all($activity);
            }
            $a = json_decode((string) $row->payload) ?: new \stdClass();
            [$subject, $body] = notifier::resolve_text(
                $activity,
                $row->subjectkey,
                $row->bodykey,
                $a,
                $overrides[$activityid]
            );
            $items[] = get_string('digestitem', 'mod_selfselectadvanced', (object) [
                'activity' => $activity->name(),
                'subject' => $subject,
                'body' => $body,
                'url' => $row->contexturl,
            ]);
            $firsturl = $firsturl ?: $row->contexturl;
        }
        if (!$items) {
            // Nothing sendable - every row's activity has been deleted.
            // Stale rows must still leave the queue, but as cleanup:
            // nothing was submitted and nothing may claim to have been.
            return self::STALE_DISCARDED;
        }

        $fullbody = get_string('digestintro', 'mod_selfselectadvanced') . "\n\n" . implode("\n\n", $items);

        $message = new \core\message\message();
        $message->component = 'mod_selfselectadvanced';
        $message->name = 'digest';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $userid;
        $message->subject = get_string('digestsubject', 'mod_selfselectadvanced', count($items));
        $message->fullmessage = $fullbody;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>' . nl2br(s($fullbody)) . '</p>';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->courseid = SITEID;
        $message->contexturl = $firsturl;
        $message->contexturlname = get_string('pluginname', 'mod_selfselectadvanced');

        // Moodle's message_send() reports failure by RETURN (false),
        // not only by throwing. Believing it is what keeps a refused
        // submission's rows in the queue.
        return message_send($message) !== false ? self::SUBMITTED : self::FAILED;
    }
}
