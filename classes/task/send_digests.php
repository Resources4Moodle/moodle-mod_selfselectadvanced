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

/**
 * Scheduled task: flush queued guide-facing notifications into one
 * digest message per recipient whose daily or weekly period has
 * elapsed (1.8.0). Rows are grouped by userid; a user's oldest queued
 * row decides whether the period has elapsed (daily: older than 24
 * hours, weekly: older than 7 days). Sent rows are deleted after the
 * message is sent; sending happens outside any transaction, exactly
 * like every other notification in this plugin.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_digests extends \core\task\scheduled_task {
    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksenddigests', 'mod_selfselectadvanced');
    }

    /**
     * Flush every user's elapsed queue into one digest message.
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $activities = [];
        $userids = $DB->get_fieldset_sql('SELECT DISTINCT userid FROM {selfselectadvanced_digestq}');
        foreach ($userids as $userid) {
            $userid = (int) $userid;
            $rows = $DB->get_records('selfselectadvanced_digestq', ['userid' => $userid], 'timecreated ASC');
            if (!$rows) {
                continue;
            }

            // A user whose preference has since reverted to immediate
            // has no elapsed-period grace: their stragglers flush right
            // away, exactly like a threshold of zero would.
            $period = get_user_preferences('mod_selfselectadvanced_digest', 'immediate', $userid);
            $thresholdsecs = $period === 'weekly' ? WEEKSECS : ($period === 'daily' ? DAYSECS : 0);
            $oldest = reset($rows);
            if ((int) $oldest->timecreated > $now - $thresholdsecs) {
                continue;
            }

            $this->send_one_digest($userid, $rows, $activities);
            $DB->delete_records_list('selfselectadvanced_digestq', 'id', array_keys($rows));
            mtrace("mod_selfselectadvanced: sent a digest of " . count($rows) . " item(s) to user $userid");
        }
    }

    /**
     * Send one aggregated digest message listing every queued item.
     *
     * @param int $userid the recipient
     * @param \stdClass[] $rows queued rows for this user, oldest first
     * @param activity[] $activities activityid-keyed cache, filled in as needed
     */
    private function send_one_digest(int $userid, array $rows, array &$activities): void {
        $items = [];
        $firsturl = '';
        foreach ($rows as $row) {
            $activityid = (int) $row->activityid;
            if (!isset($activities[$activityid])) {
                try {
                    $activities[$activityid] = activity::from_instance($activityid);
                } catch (\moodle_exception $e) {
                    continue;
                }
            }
            $activity = $activities[$activityid];
            $a = json_decode((string) $row->payload) ?: new \stdClass();
            [$subject, $body] = notifier::resolve_text($activity, $row->subjectkey, $row->bodykey, $a);
            $items[] = get_string('digestitem', 'mod_selfselectadvanced', (object) [
                'activity' => $activity->name(),
                'subject' => $subject,
                'body' => $body,
                'url' => $row->contexturl,
            ]);
            $firsturl = $firsturl ?: $row->contexturl;
        }
        if (!$items) {
            return;
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

        message_send($message);
    }
}
