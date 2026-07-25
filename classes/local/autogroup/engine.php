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

namespace mod_selfselectadvanced\local\autogroup;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\state;
use stdClass;

/**
 * Deterministic auto-grouping of groupless students at cutoff
 * (spec 9, decisions D6/A13 as corrected by review B1, review B4).
 *
 * Pool (B4): groupless candidates whose OWN effective cutoff has
 * passed - a student holding an extension is excluded until their
 * window closes; the task re-runs as windows close. Sizing (A13/B1):
 * g = ceil(P/max); if g*min > P then g = floor(P/min); members are
 * distributed balanced (all sizes within [min,max]); when g*max < P
 * or g = 0 the remainder is residue for the flagged report - the only
 * path onward is an override-backed staged move (spec 9.4). Quota
 * rules apply strictly in priority order; an unfillable rule is
 * bypassed for the rest of the run and logged (spec 9.3). Formed
 * groups get a system-designated leader (free L3 slot; a groupless
 * student always has one since caps are >= 1 - the manager fallback of
 * spec 9.5 is kept for defence) and enter the A5 guide-assignment
 * queue as pending_guide with no guide. Every decision lands in the
 * agrun log; a stored seed replays the run exactly.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /**
     * The B4 pool: groupless respond-holders whose effective cutoff
     * has passed.
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver
     * @param int|null $now time of the sweep
     * @return int[] userids
     */
    public static function pool(activity $activity, resolver $resolver, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $enrolled = get_enrolled_users($activity->context(), 'mod/selfselectadvanced:respond', 0, 'u.id');
        $confirmed = $DB->get_fieldset_sql(
            "SELECT DISTINCT m.userid
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
              WHERE g.activityid = ? AND m.status = ?",
            [$activity->id(), groups::STATUS_CONFIRMED]
        );
        $pool = [];
        foreach ($enrolled as $user) {
            $userid = (int) $user->id;
            if (in_array($userid, array_map('intval', $confirmed), true)) {
                continue;
            }
            $dates = $resolver->effective_dates($userid, null);
            if ($dates->timecutoff && $dates->timecutoff <= $now) {
                $pool[] = $userid;
            }
        }
        sort($pool);

        return $pool;
    }

    /**
     * Pure partition plan: deterministic given the seed (A13/B1).
     *
     * @param int[] $pool userids
     * @param int $min effective minimum size
     * @param int $max effective maximum size
     * @param stdClass[] $rules quota rules in priority order
     * @param stdClass[] $attrs userid-keyed attribute rows
     * @param int $seed the shuffle seed
     * @return stdClass groups (arrays of userids), residue, bypassed rule ids
     */
    public static function plan(array $pool, int $min, int $max, array $rules, array $attrs, int $seed): stdClass {
        // Seeded Fisher-Yates for deterministic replay.
        mt_srand($seed);
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }

        $p = count($pool);
        $g = $max > 0 ? (int) ceil($p / $max) : 0;
        if ($g > 0 && $g * $min > $p) {
            $g = (int) floor($p / $min);
        }
        if ($g < 1) {
            return (object) ['groups' => [], 'residue' => $pool, 'bypassed' => []];
        }
        $placeable = min($p, $g * $max);
        $sizes = [];
        $base = intdiv($placeable, $g);
        $rem = $placeable % $g;
        for ($i = 0; $i < $g; $i++) {
            $sizes[$i] = $base + ($i < $rem ? 1 : 0);
        }

        $remaining = $pool;
        $bypassed = [];
        $formed = [];
        $value = static function (int $userid, string $dimension) use ($attrs): string {
            return \core_text::strtolower((string) ($attrs[$userid]->$dimension ?? ''));
        };
        $take = static function (array &$from, callable $match): ?int {
            foreach ($from as $index => $userid) {
                if ($match($userid)) {
                    unset($from[$index]);

                    return $userid;
                }
            }

            return null;
        };

        foreach ($sizes as $size) {
            $members = [];
            foreach ($rules as $rule) {
                if (isset($bypassed[(int) $rule->id])) {
                    continue;
                }
                if ($rule->rtype === 'value' && $rule->mincount !== null) {
                    $need = (int) $rule->mincount
                        - count(array_filter($members, fn($u) => $value($u, $rule->dimension)
                            === \core_text::strtolower((string) $rule->value)));
                    while ($need > 0 && count($members) < $size) {
                        $found = $take($remaining, fn($u) => $value($u, $rule->dimension)
                            === \core_text::strtolower((string) $rule->value));
                        if ($found === null) {
                            // Spec 9.3: unfillable rule bypassed and logged.
                            $bypassed[(int) $rule->id] = true;
                            break;
                        }
                        $members[] = $found;
                        $need--;
                    }
                }
            }
            // Fill the remainder, honouring value-rule maxima.
            while (count($members) < $size && $remaining) {
                $found = $take($remaining, function ($u) use ($rules, $members, $value, $bypassed) {
                    foreach ($rules as $rule) {
                        if (isset($bypassed[(int) $rule->id]) || $rule->rtype !== 'value' || $rule->maxcount === null) {
                            continue;
                        }
                        $current = count(array_filter($members, fn($m) => $value($m, $rule->dimension)
                            === \core_text::strtolower((string) $rule->value)));
                        if (
                            $current >= (int) $rule->maxcount
                            && $value($u, $rule->dimension) === \core_text::strtolower((string) $rule->value)
                        ) {
                            return false;
                        }
                    }

                    return true;
                });
                if ($found === null) {
                    // Only max-rule-blocked students remain: relax (log).
                    foreach ($rules as $rule) {
                        if ($rule->rtype === 'value' && $rule->maxcount !== null) {
                            $bypassed[(int) $rule->id] = true;
                        }
                    }
                    $found = array_shift($remaining);
                    if ($found === null) {
                        break;
                    }
                }
                $members[] = $found;
            }
            if ($members) {
                $formed[] = $members;
            }
        }

        return (object) [
            'groups' => $formed,
            'residue' => array_values($remaining),
            'bypassed' => array_keys($bypassed),
        ];
    }

    /**
     * Execute a run: form groups, queue them for guide assignment,
     * store the agrun record and fire the event.
     *
     * @param activity $activity the activity
     * @param int $triggeredby 0 for the scheduled task, else the manager
     * @param int|null $seed replay seed, random when null
     * @return stdClass the agrun row
     */
    public static function run(activity $activity, int $triggeredby, ?int $seed = null): stdClass {
        global $DB;

        $seed = $seed ?? random_int(1, mt_getrandmax());
        $resolver = new resolver($activity);

        $lock = locks::acquire('activity:' . $activity->id());
        try {
            $transaction = $DB->start_delegated_transaction();

            $now = time();
            $pool = self::pool($activity, $resolver, $now);
            $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
            $attrs = manager::get_for_users($pool);
            // Band from the activity settings via the resolver's
            // activity fallthrough (auto groups have no group overrides yet).
            $min = (int) $activity->settings()->minsize;
            $max = (int) $activity->settings()->maxsize;
            $plan = self::plan($pool, $min, $max, array_values($rules), $attrs, $seed);

            $log = [
                'pool' => $pool,
                'bypassedrules' => $plan->bypassed,
                'residue' => $plan->residue,
                'groups' => [],
            ];
            $placed = 0;
            // Names stay unique across runs via a running sequence
            // (the date alone collides when two sweeps share a second).
            // MAX(id) never decreases, so deleted auto-groups can
            // no longer resurrect a taken name (audit round 4 item 4).
            $sequence = (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(id), 0) FROM {selfselectadvanced_group} WHERE activityid = ?',
                [$activity->id()]
            );
            foreach ($plan->groups as $index => $members) {
                // System-designated leader: first member with a free L3 slot.
                $leaderid = 0;
                foreach ($members as $candidate) {
                    if (
                        !(new \mod_selfselectadvanced\local\rules\gatekeeper($activity, $resolver))
                        ->check_nominee_leadslot($candidate)
                    ) {
                        $leaderid = $candidate;
                        break;
                    }
                }
                if (!$leaderid && $members) {
                    // Nobody has a free L3 slot: designate the first
                    // member anyway rather than insert a leaderless
                    // group; the excess is grandfathered and the group
                    // shows on the flagged report (audit item 15).
                    $leaderid = (int) reset($members);
                }
                $group = (object) [
                    'activityid' => $activity->id(),
                    'pluginuid' => '',
                    'name' => get_string('autogroupname', 'mod_selfselectadvanced', $sequence + $index + 1)
                        . ' (' . userdate($now, get_string('strftimedateshort', 'langconfig')) . ')',
                    'title' => get_string('autogrouptitle', 'mod_selfselectadvanced'),
                    'brief' => get_string('autogroupbrief', 'mod_selfselectadvanced'),
                    'briefformat' => FORMAT_HTML,
                    'leaderid' => $leaderid,
                    'guideid' => null,
                    'state' => state::PENDING_GUIDE,
                    'autoformed' => 1,
                    'timesubmitted' => $now,
                    'usermodified' => $triggeredby,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $group->id = $DB->insert_record('selfselectadvanced_group', $group);
                $group->pluginuid = groups::build_pluginuid($activity, (int) $group->id);
                $DB->set_field('selfselectadvanced_group', 'pluginuid', $group->pluginuid, ['id' => $group->id]);
                foreach ($members as $userid) {
                    $DB->insert_record('selfselectadvanced_member', (object) [
                        'groupid' => $group->id,
                        'userid' => $userid,
                        'status' => groups::STATUS_CONFIRMED,
                        'isleader' => (int) ($userid === $leaderid),
                        'invitedby' => $triggeredby,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
                $placed += count($members);
                $log['groups'][] = ['pluginuid' => $group->pluginuid, 'leaderid' => $leaderid, 'members' => $members];
            }

            $agrun = (object) [
                'activityid' => $activity->id(),
                'seed' => $seed,
                'triggeredby' => $triggeredby,
                'timestarted' => $now,
                'timefinished' => time(),
                'groupsformed' => count($plan->groups),
                'placed' => $placed,
                'unplaced' => count($plan->residue),
                'log' => json_encode($log),
            ];
            $agrun->id = $DB->insert_record('selfselectadvanced_agrun', $agrun);

            \mod_selfselectadvanced\event\autogroup_run::create([
                'objectid' => $agrun->id,
                'context' => $activity->context(),
                'other' => [
                    'groupsformed' => (int) $agrun->groupsformed,
                    'placed' => $placed,
                    'unplaced' => (int) $agrun->unplaced,
                ],
            ])->trigger();

        // Audit round 4 item 1: every placed student is told where
        // they landed; managers get the run summary.
        foreach ($log['groups'] as $planned) {
            foreach ($planned['members'] as $placeduser) {
                \mod_selfselectadvanced\local\notifier::send(
                    $activity,
                    'autogroupresult',
                    (int) $placeduser,
                    'msgautogroupedsubject',
                    'msgautogroupedbody',
                    (object) [
                        'group' => $planned['pluginuid'],
                        'activity' => $activity->name(),
                    ],
                    new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
                    $activity->name()
                );
            }
        }
        foreach (get_users_by_capability($activity->context(), 'mod/selfselectadvanced:manage', 'u.id') as $mgr) {
            \mod_selfselectadvanced\local\notifier::send(
                $activity,
                'autogroupresult',
                (int) $mgr->id,
                'msgautogroupransubject',
                'msgautogroupranbody',
                (object) [
                    'activity' => $activity->name(),
                    'placed' => (int) $agrun->placed,
                    'unplaced' => (int) $agrun->unplaced,
                    'groups' => (int) $agrun->groupsformed,
                ],
                new \moodle_url('/mod/selfselectadvanced/flagged.php', ['id' => $activity->cm()->id]),
                $activity->name()
            );
        }

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        return $agrun;
    }

    /**
     * Whether a scheduled sweep is due: auto-grouping enabled and the
     * pool holds users not covered by the latest run (B4 re-runs as
     * per-user windows close, without spamming identical runs).
     *
     * @param activity $activity the activity
     * @return bool
     */
    public static function sweep_due(activity $activity): bool {
        global $DB;

        // Three-state mode (audit round 3 item 3): 0 off, 1 manual
        // trigger only, 2 manual + automatic at the effective cutoff.
        if ((int) $activity->settings()->autogroup < 2) {
            return false;
        }
        $pool = self::pool($activity, new resolver($activity));
        if (!$pool) {
            return false;
        }
        $last = $DB->get_records('selfselectadvanced_agrun', ['activityid' => $activity->id()], 'id DESC', '*', 0, 1);
        if (!$last) {
            return true;
        }
        $log = json_decode(reset($last)->log, true) ?: [];
        $covered = array_map('intval', array_merge($log['pool'] ?? [], []));

        return (bool) array_diff($pool, $covered);
    }
}
