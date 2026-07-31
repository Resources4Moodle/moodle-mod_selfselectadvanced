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
     * @var int Groups examined per activity per run, for the forced
     *      approvals and for the reminders alike. Overridable per site
     *      with the config_plugins value
     *      mod_selfselectadvanced/autoapprovebatch: an ops escape hatch
     *      for a site whose cron budget cannot take 200 forced
     *      approvals in one run, deliberately NOT an admin setting.
     */
    private const BATCH = 200;

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

        $admin = get_admin();
        if (!$admin) {
            mtrace('selfselectadvanced: no site admin to attribute forced approvals to; skipping sweep');
            return;
        }
        $batch = (int) get_config('mod_selfselectadvanced', 'autoapprovebatch');
        $batch = $batch > 0 ? $batch : self::BATCH;
        $now = time();
        // The activity list is uncapped by design: one row per enabled
        // activity, and the work INSIDE each one is what is capped.
        $activities = $DB->get_records_select(
            'selfselectadvanced',
            'guideautoapprove = 1 AND guidewindow > 0',
            null,
            'id ASC'
        );
        foreach ($activities as $instance) {
            try {
                $activity = activity::from_instance((int) $instance->id);
                $this->sweep($activity, $instance, $now, $batch, (int) $admin->id);
                $this->escalate($activity, $instance, $now, $batch);
            } catch (\Throwable $e) {
                // One activity whose course module has gone (or whose
                // data is broken) must not cost every other activity
                // its sweep: activity::from_instance() throws on a
                // missing cm, and the loop used to die with it.
                mtrace("selfselectadvanced: sweep failed for activity {$instance->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Force the overdue approvals of one activity, bounded and resumable.
     *
     * @param activity $activity the activity
     * @param \stdClass $instance the activity settings row
     * @param int $now the sweep's reference time
     * @param int $batch groups examined in this run
     * @param int $adminid the site admin the forced approvals are attributed to
     */
    private function sweep(activity $activity, \stdClass $instance, int $now, int $batch, int $adminid): void {
        global $DB;

        // Sargable: the cutoff is computed in PHP, so the predicate is
        // a plain range on the column instead of arithmetic on it
        // (timesubmitted + :window < :now scanned every pending row).
        $cutoff = $now - (int) $instance->guidewindow;
        $cursorname = 'autoapprovecursor_' . (int) $instance->id;
        $cursor = (int) get_config('mod_selfselectadvanced', $cursorname);

        // Guideless queue groups are excluded: the deadline stands in
        // for a guide who failed to decide, and a group no guide holds
        // has no decider to stand in for - it stays in the manager
        // assignment queue.
        $overdue = $DB->get_records_select(
            'selfselectadvanced_group',
            'activityid = :activityid AND state = :state AND timesubmitted > 0 AND guideid IS NOT NULL'
                . ' AND timesubmitted < :cutoff AND id > :cursor',
            [
                'activityid' => $instance->id,
                'state' => state::PENDING_GUIDE,
                'cutoff' => $cutoff,
                'cursor' => $cursor,
            ],
            'id ASC',
            '*',
            0,
            $batch
        );

        $api = new api($activity);
        $managers = null;
        $approved = 0;
        $lastid = $cursor;
        foreach ($overdue as $group) {
            $lastid = (int) $group->id;
            try {
                // Every gate, and any relief that explains a forced
                // approval, is decided inside this call's group lock
                // and transaction (T-04). The sweep does not pre-judge.
                //
                // Deliberately NOT batched: count_confirmed() and
                // is_compliant() now feed a gate that must be atomic
                // with the transition, and computing them in bulk out
                // here is exactly the stale-snapshot defect this
                // ticket removes. Only the reads that do NOT feed the
                // gate are batched (the manager set, the grade push,
                // the reminder markers). The bound is ~6 small reads
                // per approved team, capped at the batch size per
                // activity per run - strictly less than before, which
                // paid the same per-team cost PLUS a whole
                // activity-wide grade push for every single team.
                $api->lifecycle()->approve_auto($group, $adminid);
                $approved++;
                mtrace("selfselectadvanced: auto-approved group {$group->pluginuid}");

                if ($managers === null) {
                    // Resolved ONCE per activity, and only when there
                    // is something to announce: the manager set does
                    // not vary per group.
                    $managers = get_users_by_capability(
                        $activity->context(),
                        'mod/selfselectadvanced:manage',
                        'u.id'
                    );
                }
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
                // The refusal already rolled its own transaction back,
                // so nothing of this team survives - including any
                // relief it would have needed (T-04 3d).
                mtrace("selfselectadvanced: auto-approve skipped {$group->pluginuid}: " . $e->getMessage());
            }
        }

        if ($approved) {
            // ONE activity-wide push for the batch. push_grades()
            // recomputes and republishes every confirmed member of
            // every firm/frozen group of the activity, so calling it
            // per approval made a sweep cost O(teams x members).
            \mod_selfselectadvanced\local\penalty\ledger::push_grades($activity);
            mtrace("selfselectadvanced: pushed grades once for {$approved} auto-approval(s)"
                . " in activity {$instance->id}");
        }

        // Checkpoint. A full batch means there is more behind it:
        // remember where we stopped so the next run resumes instead of
        // re-walking the head. A short batch means the pass is done;
        // clearing the cursor makes the next run start from the
        // beginning, which is what re-attempts the teams this pass
        // deferred (an over-cap guide, a pending relief row) instead of
        // letting them block the queue behind them forever. Written at
        // most once per activity per run - set_config() purges the
        // whole config cache, so it must not be called per group.
        //
        // Crash-safety: the cursor is written AFTER the batch, so a
        // killed run re-attempts that batch, which is free - the teams
        // it approved have left pending_guide and no longer match.
        if (count($overdue) >= $batch) {
            set_config($cursorname, $lastid, 'mod_selfselectadvanced');
        } else if ($cursor) {
            unset_config($cursorname, 'mod_selfselectadvanced');
        }
    }

    /**
     * Escalate to the guide at 50% and 90% of the decision window, so
     * the sweep is a last resort and not a surprise.
     *
     * @param activity $activity the activity
     * @param \stdClass $instance the activity settings row
     * @param int $now the sweep's reference time
     * @param int $batch groups examined in this run
     */
    private function escalate(activity $activity, \stdClass $instance, int $now, int $batch): void {
        global $DB;

        $window = (int) $instance->guidewindow;
        $prefname = $DB->sql_concat("'mod_selfselectadvanced_gremind_'", 'g.id');
        // Rows that need nothing are excluded IN SQL: the marker is
        // read by the same query (no per-guide preference-store load),
        // and a team already reminded for its stage cannot occupy a
        // slot in the batch, so the tail is never starved.
        $sql = "SELECT g.*, p.value AS remindstage
                  FROM {selfselectadvanced_group} g
             LEFT JOIN {user_preferences} p ON p.name = $prefname AND p.userid = g.guideid
                 WHERE g.activityid = :activityid
                   AND g.state = :state
                   AND g.timesubmitted > 0
                   AND g.guideid IS NOT NULL
                   AND ( (g.timesubmitted < :ninetycutoff AND (p.value IS NULL OR p.value = '50'))
                      OR (g.timesubmitted < :halfcutoff AND p.value IS NULL) )
              ORDER BY g.guideid ASC, g.id ASC";
        $pending = $DB->get_records_sql($sql, [
            'activityid' => $instance->id,
            'state' => state::PENDING_GUIDE,
            'ninetycutoff' => $now - (int) ($window * 0.9),
            'halfcutoff' => $now - (int) ($window * 0.5),
        ], 0, $batch);

        foreach ($pending as $group) {
            $elapsed = $now - (int) $group->timesubmitted;
            $stage = 0;
            if ($elapsed >= (int) ($window * 0.9)) {
                $stage = 90;
            } else if ($elapsed >= (int) ($window * 0.5)) {
                $stage = 50;
            }
            if (!$stage || (int) $group->remindstage >= $stage) {
                continue;
            }
            // The marker name is unchanged on purpose: submit(),
            // assign_guide() and return_group() clear it by that name.
            set_user_preference('mod_selfselectadvanced_gremind_' . $group->id, $stage, (int) $group->guideid);
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
                    'deadline' => userdate((int) $group->timesubmitted + $window),
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
