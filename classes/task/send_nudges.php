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

use core\task\adhoc_task;
use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\notifier;
use moodle_url;

/**
 * Adhoc task: deliver one bulk nudge notification to many recipients
 * outside the request that queued it (SCALE).
 *
 * Intended call pattern (queued by the flagged report's two bulk-nudge
 * confirmation POSTs, once per distinct $a value):
 *
 *   $task = new \mod_selfselectadvanced\task\send_nudges();
 *   $task->set_custom_data([
 *       'activityid' => $activity->id(),
 *       'provider' => 'deadlinereminder',
 *       'subjectkey' => 'msgremindersubject',
 *       'bodykey' => 'msgreminderbody',
 *       'userids' => $recipients,
 *       'a' => ['activity' => $activity->name()],
 *   ]);
 *   \core\task\manager::queue_adhoc_task($task);
 *
 * This runs the send in chunks on the next cron pass instead of a
 * manager's bulk-nudge confirmation POST looping
 * \mod_selfselectadvanced\local\notifier::send() once per recipient
 * inline, which is unsafe once the recipient list can run into the
 * thousands. The same subject/body/placeholder values are sent to
 * every listed user; a nudge whose message legitimately differs per
 * recipient (a per-user due date, a per-guide overdue count) needs one
 * queued instance of this task per distinct value, not one instance
 * for the whole list.
 *
 * Two custom data keys are optional and default to the groupless
 * students deep link when absent: 'contexturl' (a plain string, not a
 * moodle_url, since custom data is JSON-encoded) and 'contextname'.
 * The guide-facing nudge sets both, since a guide's overdue queue is
 * reviewed from guide.php, not view.php.
 *
 * Each recipient is sent inside its own try/catch: one failure is
 * logged and skipped rather than aborting the rest of the batch. The
 * task throws only when EVERY recipient failed, because Moodle retries
 * a thrown adhoc task in full and would re-notify anyone who already
 * got their message.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_nudges extends adhoc_task {
    /** @var int Recipients sent per chunk before the next progress log line. */
    private const CHUNK_SIZE = 200;

    /**
     * Localised task name shown in the adhoc task admin listing.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksendnudges', 'mod_selfselectadvanced');
    }

    /**
     * Send the queued notification to every recipient, in chunks.
     */
    public function execute(): void {
        $data = $this->get_custom_data();

        $activity = activity::from_instance((int) $data->activityid);
        $provider = (string) $data->provider;
        $subjectkey = (string) $data->subjectkey;
        $bodykey = (string) $data->bodykey;
        $userids = array_map('intval', (array) $data->userids);
        $a = $data->a ?? null;

        $contexturl = isset($data->contexturl)
            ? new moodle_url((string) $data->contexturl)
            : new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);
        $contextname = isset($data->contextname) ? (string) $data->contextname : $activity->name();

        $sent = 0;
        $failed = 0;
        foreach (array_chunk($userids, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $userid) {
                // One bad recipient (a deleted account, a messaging
                // backend hiccup) must not abort the rest of a batch
                // that can run into the thousands.
                try {
                    if (notifier::send($activity, $provider, $userid, $subjectkey, $bodykey, $a, $contexturl, $contextname)) {
                        $sent++;
                    } else {
                        // Counted on the RETURN, not on the mere
                        // absence of a throw (MSG-001): message_send()
                        // reports an outright refusal by returning
                        // false, and counting non-throw as sent once
                        // logged "sent N of N" through a run in which
                        // every single submission was refused - and
                        // kept the all-failed escalation below from
                        // ever firing.
                        $failed++;
                        mtrace("mod_selfselectadvanced: messaging refused the nudge for user $userid");
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    mtrace("mod_selfselectadvanced: send_nudges failed to notify user $userid: " . $e->getMessage());
                }
            }
            mtrace("mod_selfselectadvanced: send_nudges sent $sent of " . count($userids) . " nudge(s)");
        }

        if ($failed > 0 && $sent === 0) {
            // Moodle retries a THROWN adhoc task in full, re-sending to
            // every recipient again; only escalate when nothing at all
            // got through, never for a partial batch, or the recipients
            // already notified would receive the same nudge twice.
            throw new \RuntimeException(
                "mod_selfselectadvanced: send_nudges could not deliver any of $failed queued nudge(s)"
            );
        }
    }
}
