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
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\state;

/**
 * Guide decision window sweep (1.4.0): in activities that enable
 * auto-approval, submitted groups the guide has not decided within the
 * window are approved automatically ("counted as accepted").
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guide_autoapprove extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskguideautoapprove', 'mod_selfselectadvanced');
    }

    /**
     * Sweep every enabled activity.
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $activities = $DB->get_records_select(
            'selfselectadvanced',
            'guideautoapprove = 1 AND guidewindow > 0'
        );
        foreach ($activities as $instance) {
            $activity = activity::from_instance((int) $instance->id);
            $api = new api($activity);
            // Guideless queue groups are excluded: the deadline stands
            // in for a guide who failed to decide, and a group no guide
            // holds has no decider to stand in for — it stays in the
            // manager assignment queue.
            $overdue = $DB->get_records_select(
                'selfselectadvanced_group',
                'activityid = :activityid AND state = :state AND timesubmitted > 0 AND guideid IS NOT NULL'
                    . ' AND timesubmitted + :window < :now',
                [
                    'activityid' => $instance->id,
                    'state' => state::PENDING_GUIDE,
                    'window' => (int) $instance->guidewindow,
                    'now' => $now,
                ]
            );
            $resolver = new \mod_selfselectadvanced\local\override\resolver($activity);
            foreach ($overdue as $group) {
                try {
                    // Audit 2026-07-24 item 1: the deadline DOES force
                    // approval (owner decision), but never silently -
                    // any gate the manual path would have enforced is
                    // recorded as a group-scope override first, so the
                    // firm state is explained in the override ledger.
                    $confirmed = \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id);
                    $relief = [];
                    if ($confirmed < $resolver->effective_minsize((int) $group->id)->value) {
                        $relief['minsize'] = max(1, $confirmed);
                    }
                    if (!\mod_selfselectadvanced\local\quota\evaluator::is_compliant($activity, (int) $group->id)) {
                        $relief['quotaexempt'] = 1;
                    }
                    if ($relief) {
                        $reliefrow = \mod_selfselectadvanced\local\override\store::save(
                            $activity,
                            'group',
                            (int) $group->id,
                            $relief,
                            (int) get_admin()->id
                        );
                        if ($reliefrow->status !== 'active') {
                            // A pre-existing guarded reduction keeps
                            // the merged row pending; approving now
                            // would be unexplained (round 4 item 3).
                            mtrace("selfselectadvanced: auto-approve deferred for {$group->pluginuid}: "
                                . 'relief override is pending on unresolved blockers');
                            continue;
                        }
                        mtrace("selfselectadvanced: auto-approve recorded relief for {$group->pluginuid}: "
                            . implode(',', array_keys($relief)));
                    }
                    $api->lifecycle()->approve($group, (int) get_admin()->id, true);
                    mtrace("selfselectadvanced: auto-approved group {$group->pluginuid}");
                    $managers = get_users_by_capability(
                        $activity->context(),
                        'mod/selfselectadvanced:manage',
                        'u.id'
                    );
                    foreach ($managers as $manager) {
                        \mod_selfselectadvanced\local\notifier::send(
                            $activity,
                            'groupapproved',
                            (int) $manager->id,
                            'msgautoapprovedsubject',
                            'msgautoapprovedbody',
                            (object) [
                                'group' => format_string($group->name),
                                'pluginuid' => $group->pluginuid,
                                'activity' => $activity->name(),
                            ],
                            new \moodle_url('/mod/selfselectadvanced/review.php', [
                                'id' => $activity->cm()->id,
                                'g' => (int) $group->id,
                            ]),
                            format_string($group->name)
                        );
                    }
                } catch (\moodle_exception $e) {
                    mtrace("selfselectadvanced: auto-approve skipped {$group->pluginuid}: " . $e->getMessage());
                }
            }

            // Review round 3: escalate to the guide at 50% and 90% of
            // the window so the sweep is a last resort, not a surprise.
            $pending = $DB->get_records_select(
                'selfselectadvanced_group',
                'activityid = :activityid AND state = :state AND timesubmitted > 0 AND guideid IS NOT NULL',
                ['activityid' => $instance->id, 'state' => state::PENDING_GUIDE]
            );
            foreach ($pending as $group) {
                $elapsed = $now - (int) $group->timesubmitted;
                $stage = 0;
                if ($elapsed >= (int) ($instance->guidewindow * 0.9)) {
                    $stage = 90;
                } else if ($elapsed >= (int) ($instance->guidewindow * 0.5)) {
                    $stage = 50;
                }
                if (!$stage) {
                    continue;
                }
                $marker = 'mod_selfselectadvanced_gremind_' . $group->id;
                if ((int) get_user_preferences($marker, 0, (int) $group->guideid) >= $stage) {
                    continue;
                }
                set_user_preference($marker, $stage, (int) $group->guideid);
                \mod_selfselectadvanced\local\notifier::send(
                    $activity,
                    'guidequeue',
                    (int) $group->guideid,
                    'msgguideremindersubject',
                    'msgguidereminderbody',
                    (object) [
                        'group' => format_string($group->name),
                        'pluginuid' => $group->pluginuid,
                        'activity' => $activity->name(),
                        'deadline' => userdate((int) $group->timesubmitted + (int) $instance->guidewindow),
                    ],
                    new \moodle_url('/mod/selfselectadvanced/review.php', [
                        'id' => $activity->cm()->id,
                        'g' => (int) $group->id,
                    ]),
                    format_string($group->name)
                );
                mtrace("selfselectadvanced: {$stage}% reminder for {$group->pluginuid}");
            }
        }
    }
}
