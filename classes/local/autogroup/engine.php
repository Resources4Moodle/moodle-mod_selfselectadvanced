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
        // Hash set built once: a linear scan rebuilt per iteration costs
        // seconds of pure CPU on a course of several thousand students.
        $confirmedset = array_flip(array_map('intval', $confirmed));
        $pool = [];
        foreach ($enrolled as $user) {
            $userid = (int) $user->id;
            if (isset($confirmedset[$userid])) {
                continue;
            }
            $dates = $resolver->effective_dates($userid, null);
            // STRICTLY less than, because the cutoff second is INSIDE
            // the window: effective_dates::is_open() and
            // gatekeeper::check_window() both refuse only once
            // `$now > $timecutoff`. Asking `<= $now` here pooled a
            // student at exactly the cutoff second while the gate was
            // still admitting them. Tightening the gate to `>=` instead
            // would shorten every advertised window by a second, which
            // students can see.
            if ($dates->timecutoff && $dates->timecutoff < $now) {
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
                if ($rule->rtype === 'distinct' && $rule->mincount !== null) {
                    // Distinct rules were invisible to this planner
                    // until 1.20.20 (seam audit B7): every branch
                    // handled only rtype 'value', so "at least N
                    // different departments" was neither honoured nor
                    // bypassed-and-logged - the run just claimed the
                    // groups it formed. Same greedy shape as the value
                    // arm: while the group shows fewer distinct values
                    // than the rule wants, take somebody whose value is
                    // NEW to it; when nobody can add one, spec 9.3 -
                    // bypassed and logged, never silent.
                    $present = static function (array $members) use ($value, $rule): array {
                        $seen = [];
                        foreach ($members as $u) {
                            $v = $value($u, $rule->dimension);
                            if ($v !== null && $v !== '') {
                                $seen[$v] = true;
                            }
                        }

                        return $seen;
                    };
                    while (count($present($members)) < (int) $rule->mincount && count($members) < $size) {
                        $seen = $present($members);
                        $found = $take($remaining, static function ($u) use ($value, $rule, $seen) {
                            $v = $value($u, $rule->dimension);

                            return $v !== null && $v !== '' && !isset($seen[$v]);
                        });
                        if ($found === null) {
                            $bypassed[(int) $rule->id] = true;
                            break;
                        }
                        $members[] = $found;
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
            $template = \mod_selfselectadvanced\local\quota\slots::get_all($activity);
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
            // Auto-placement consumes a membership slot exactly like an
            // accept does; a placed student at their cap must cascade
            // any other pending invitations of theirs (audit: non-accept
            // paths were leaving rivals pending forever). Rows are kept
            // per user for the post-commit notification below.
            $gatekeeper = new \mod_selfselectadvanced\local\rules\gatekeeper($activity, $resolver);
            $invitationservice = new \mod_selfselectadvanced\local\invitations($activity, $gatekeeper);
            $cascadedbyuser = [];
            foreach ($plan->groups as $index => $members) {
                // System-designated leader: first member who may lead and has
                // a free L3 slot. Before the capability split this checked L3
                // only, so auto-grouping could install a user whose :lead had
                // been prohibited and leave an inert group behind.
                $leaderid = 0;
                foreach ($members as $candidate) {
                    if (!$gatekeeper->check_nominee_can_lead($candidate)) {
                        $leaderid = $candidate;
                        break;
                    }
                }
                if (!$leaderid && $members) {
                    // Preserve the existing L3-grandfathering policy: when
                    // every authorised leader is already at their lead limit,
                    // choose the first person who still has :lead and let the
                    // flagged report expose the excess. The capability itself
                    // is not grandfathered.
                    foreach ($members as $candidate) {
                        if (\mod_selfselectadvanced\local\authority::may_lead($activity, (int) $candidate)) {
                            $leaderid = (int) $candidate;
                            break;
                        }
                    }
                }
                if (!$leaderid) {
                    // No member of this planned group is authorised to lead it.
                    // Do not manufacture an inert leader: leave the students as
                    // residue for staff to resolve and continue with the other
                    // planned groups.
                    $log['residue'] = array_values(array_unique(array_merge($log['residue'], $members)));
                    continue;
                }
                // Names must stay unique across the whole course
                // (1.16.0). The generated name carries a sequence and a
                // date, which collides only if a team was hand-named
                // the same thing - so suffix until it is free rather
                // than fail a whole auto-grouping run over a name.
                $autoname = get_string('autogroupname', 'mod_selfselectadvanced', $sequence + $index + 1)
                    . ' (' . userdate($now, get_string('strftimedateshort', 'langconfig')) . ')';
                $suffix = 1;
                while (groups::name_taken($activity, $autoname)) {
                    $suffix++;
                    $autoname = get_string('autogroupname', 'mod_selfselectadvanced', $sequence + $index + 1)
                        . ' (' . userdate($now, get_string('strftimedateshort', 'langconfig')) . ') '
                        . $suffix;
                }
                $group = (object) [
                    'activityid' => $activity->id(),
                    'pluginuid' => '',
                    'name' => $autoname,
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
                    $cascaded = $invitationservice->cascade_at_cap((int) $userid, (int) $group->id);
                    if ($cascaded) {
                        $cascadedbyuser[(int) $userid] = $cascaded;
                    }
                }
                $placed += count($members);
                // Seat-template honesty (seam audit B7, 1.20.20): this
                // planner does not yet PLAN against the seat template
                // (flagged as future work - templates need a solver,
                // not a greedy pass), so the run must not read as
                // compliant where the template went unexamined. Each
                // formed group's unfilled template seats are measured
                // by the same engine the compliance panel uses and
                // recorded in the run log - a deficit named is a
                // deficit somebody can repair.
                $groupentry = ['pluginuid' => $group->pluginuid, 'leaderid' => $leaderid, 'members' => $members];
                if ($template !== []) {
                    $seating = \mod_selfselectadvanced\local\quota\slots::evaluate_from_data(
                        $template,
                        $members,
                        $attrs
                    );
                    $deficits = [];
                    foreach ($seating->slots as $slotentry) {
                        if ((int) $slotentry->missing > 0) {
                            $deficits[] = $slotentry->label . ': ' . $slotentry->missing;
                        }
                    }
                    if ($deficits !== []) {
                        $groupentry['templatedeficits'] = $deficits;
                    }
                }
                $log['groups'][] = $groupentry;
            }

            $agrun = (object) [
                'activityid' => $activity->id(),
                'seed' => $seed,
                'triggeredby' => $triggeredby,
                'timestarted' => $now,
                'timefinished' => time(),
                'groupsformed' => count($log['groups']),
                'placed' => $placed,
                'unplaced' => count($log['residue']),
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

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        // Every placed student is told where they landed; managers get
        // the run summary. Sends happen after the COMMIT (messages are
        // not transactional, and a rollback must never follow a "you
        // have been placed" notification) and after the LOCK RELEASE: a
        // cutoff sweep can place thousands, and thousands of
        // synchronous message_send() calls under activity:{id} block
        // every invitations::accept, api::create_group,
        // moves::commit_set and succession::confirm on the activity
        // until each of them times out at 10s (T-02 R7).
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
        // Cascade notifications alongside the placement notifications
        // above: each affected leader learns their invitation was
        // auto-declined by the student's placement.
        foreach ($cascadedbyuser as $placeduser => $cascaded) {
            $invitationservice->notify_cascaded($cascaded, $placeduser);
        }
        // An auto-grouping run's result - and above all its UNPLACED
        // students - is repaired by moving people, so holders of the
        // narrow composition capability get the summary too,
        // deduplicated (somebody holding both is one recipient).
        foreach (
            \mod_selfselectadvanced\local\notifier::recipients($activity, [
                'mod/selfselectadvanced:manage',
                'mod/selfselectadvanced:managecomposition',
            ]) as $mgr
        ) {
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
