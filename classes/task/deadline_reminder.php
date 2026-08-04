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
use mod_selfselectadvanced\local\override\resolver;

/**
 * Scheduled task: remind groupless students 24 hours before their
 * effective penalty-free deadline (spec 14.9). Sent once per user per
 * activity via a user preference marker.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deadline_reminder extends \core\task\scheduled_task {
    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskdeadlinereminder', 'mod_selfselectadvanced');
    }

    /**
     * Scan activities with a due date and remind groupless students
     * inside the 24-hour window before their effective deadline.
     */
    public function execute(): void {
        global $DB;

        $now = time();
        foreach ($DB->get_records_select('selfselectadvanced', 'timedue > 0', [], 'id ASC', 'id') as $row) {
            try {
                $activity = activity::from_instance((int) $row->id);
            } catch (\moodle_exception $e) {
                continue;
            }
            $resolver = new resolver($activity);
            $confirmed = $DB->get_fieldset_sql(
                "SELECT DISTINCT m.userid
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                  WHERE g.activityid = ? AND m.status = ?",
                [$activity->id(), \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
            );
            $prefkey = 'mod_selfselectadvanced_reminded_' . $activity->id();
            $enrolled = get_enrolled_users($activity->context(), 'mod/selfselectadvanced:respond', 0, 'u.id');
            // Hash set built once: a linear scan rebuilt per iteration
            // costs seconds of CPU on a course of several thousand.
            $confirmedset = array_flip(array_map('intval', $confirmed));
            foreach ($enrolled as $user) {
                $userid = (int) $user->id;
                if (isset($confirmedset[$userid])) {
                    continue;
                }
                $due = $resolver->effective_dates($userid, null)->timedue;
                if (!$due || $due <= $now || $due > $now + DAYSECS) {
                    continue;
                }
                if (get_user_preferences($prefkey, 0, $userid)) {
                    continue;
                }
                $submitted = notifier::send(
                    $activity,
                    'deadlinereminder',
                    $userid,
                    'msgremindersubject',
                    'msgreminderbody',
                    (object) ['activity' => $activity->name(), 'due' => userdate($due)],
                    new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
                    $activity->name()
                );
                // The once-only flag follows the SEND (MSG-003): a
                // refused submission leaves the user unflagged, so the
                // next run retries instead of a refusal becoming a
                // permanent silence. The inverse risk is accepted on
                // purpose - a crash between the send and the flag
                // re-sends one reminder on the next run, and a
                // duplicate reminder beats a reminder nobody ever got.
                if ($submitted) {
                    set_user_preference($prefkey, 1, $userid);
                }
            }
        }
    }
}
