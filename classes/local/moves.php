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
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\rules\gatekeeper;
use stdClass;

/**
 * Staged manager moves (spec 7, decisions A4/A6, review item B3).
 *
 * A removal is always expressed as a move to somewhere. Moves sit in
 * `pending` with NO visible change until a manager commits a selected
 * SET; the set is validated jointly against the net post-state of
 * every touched group (a swap of two students commits as a set).
 * Rules L1-L4 and quota apply, each bypassable only by a move-scope
 * override attached to a specific move; member moves never change
 * guide assignments, so L5 is structurally unaffected (documented).
 * Committing a move on a frozen group mirrors the core group and
 * refreshes the snapshot in the same transaction (A6).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moves {
    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param gatekeeper $gatekeeper the gatekeeper (resolver source)
     */
    public function __construct(activity $activity, gatekeeper $gatekeeper) {
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;
    }

    /**
     * Stage a move (no visible change occurs).
     *
     * @param int $userid the student to move
     * @param int|null $sourcegroupid group to leave, null when placing a groupless student
     * @param int $targetgroupid group to join
     * @param bool $makeleader designate the student leader of the target
     * @param int|null $successorid new leader for the source when moving its leader out
     * @param int $actorid the acting manager
     * @return stdClass the pending move row with validation results
     */
    public function stage(
        int $userid,
        ?int $sourcegroupid,
        int $targetgroupid,
        bool $makeleader,
        ?int $successorid,
        int $actorid
    ): stdClass {
        global $DB;

        // Server-side ownership of every id (IDOR).
        groups::get($this->activity, $targetgroupid);
        if ($sourcegroupid !== null) {
            $source = groups::get($this->activity, $sourcegroupid);
            $ismember = $DB->record_exists('selfselectadvanced_member', [
                'groupid' => $sourcegroupid,
                'userid' => $userid,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            if (!$ismember) {
                throw new \moodle_exception('errmovenotmember', 'mod_selfselectadvanced');
            }
            if ((int) $source->leaderid === $userid && !$successorid) {
                throw new \moodle_exception('errmovesuccessorrequired', 'mod_selfselectadvanced');
            }
        }

        $now = time();
        $move = (object) [
            'activityid' => $this->activity->id(),
            'userid' => $userid,
            'sourcegroupid' => $sourcegroupid,
            'targetgroupid' => $targetgroupid,
            'makeleader' => $makeleader ? 1 : 0,
            'successorid' => $successorid,
            'status' => 'pending',
            'statusinfo' => null,
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $move->id = $DB->insert_record('selfselectadvanced_move', $move);

        // Store the initial per-rule verdicts for the manager UI.
        $verdicts = $this->validate_set([(int) $move->id]);
        $move->statusinfo = json_encode($verdicts->permove[(int) $move->id] ?? []);
        $DB->set_field('selfselectadvanced_move', 'statusinfo', $move->statusinfo, ['id' => $move->id]);

        \mod_selfselectadvanced\event\move_staged::create([
            'objectid' => $move->id,
            'context' => $this->activity->context(),
            'relateduserid' => $userid,
            'other' => [
                'sourcegroupid' => $sourcegroupid,
                'targetgroupid' => $targetgroupid,
            ],
        ])->trigger();

        return $move;
    }

    /**
     * Jointly validate a set of pending moves against the net
     * post-state of every touched group (A4).
     *
     * @param int[] $moveids the selected pending moves
     * @return stdClass {valid: bool, permove: [moveid => [rule => {ok, reason, bypassed}]]}
     */
    public function validate_set(array $moveids): stdClass {
        global $DB;

        $resolver = $this->gatekeeper->resolver();
        $moves = $this->load_pending($moveids);

        // Net membership deltas per group: a user both added and removed
        // in the same group cancels out; additions only count when the
        // user does not already hold that state in the group.
        $removals = [];
        $additions = [];
        foreach ($moves as $move) {
            if ($move->sourcegroupid) {
                $removals[(int) $move->sourcegroupid][] = (int) $move->userid;
            }
            $additions[(int) $move->targetgroupid][] = (int) $move->userid;
        }
        $confirmedin = [];
        $seatsin = [];
        foreach (array_unique(array_merge(array_keys($removals), array_keys($additions))) as $gid) {
            $rows = $DB->get_records_select(
                'selfselectadvanced_member',
                'groupid = ? AND status IN (?, ?)',
                [$gid, groups::STATUS_CONFIRMED, groups::STATUS_INVITED],
                '',
                'userid, status'
            );
            $confirmedin[$gid] = [];
            $seatsin[$gid] = [];
            foreach ($rows as $row) {
                $seatsin[$gid][] = (int) $row->userid;
                if ($row->status === groups::STATUS_CONFIRMED) {
                    $confirmedin[$gid][] = (int) $row->userid;
                }
            }
        }
        $confirmedafter = function (int $gid) use ($additions, $removals, $confirmedin): int {
            $add = array_diff($additions[$gid] ?? [], $removals[$gid] ?? []);
            $rem = array_diff($removals[$gid] ?? [], $additions[$gid] ?? []);

            return count($confirmedin[$gid] ?? [])
                + count(array_diff($add, $confirmedin[$gid] ?? []))
                - count(array_intersect($rem, $confirmedin[$gid] ?? []));
        };
        $seatsafterfn = function (int $gid) use ($additions, $removals, $seatsin): int {
            $add = array_diff($additions[$gid] ?? [], $removals[$gid] ?? []);
            $rem = array_diff($removals[$gid] ?? [], $additions[$gid] ?? []);

            return count($seatsin[$gid] ?? [])
                + count(array_diff($add, $seatsin[$gid] ?? []))
                - count(array_intersect($rem, $seatsin[$gid] ?? []));
        };

        $result = (object) ['valid' => true, 'permove' => []];
        foreach ($moves as $move) {
            $moveid = (int) $move->id;
            $bypasses = $resolver->move_bypasses($moveid);
            $verdicts = [];

            // L1 on the source group's net post-state.
            if ($move->sourcegroupid) {
                $sourceid = (int) $move->sourcegroupid;
                $after = $confirmedafter($sourceid);
                $min = $resolver->effective_minsize($sourceid)->value;
                $verdicts['L1'] = $this->verdict(
                    $after >= $min,
                    in_array('L1', $bypasses, true),
                    get_string('moveruleL1', 'mod_selfselectadvanced', (object) ['after' => $after, 'min' => $min])
                );
            }

            // L2 on the target group's net post-state (confirmed + pending seats).
            $targetid = (int) $move->targetgroupid;
            $seatsafter = $seatsafterfn($targetid);
            $max = $resolver->effective_maxsize($targetid)->value;
            $verdicts['L2'] = $this->verdict(
                $seatsafter <= $max,
                in_array('L2', $bypasses, true),
                get_string('moveruleL2', 'mod_selfselectadvanced', (object) ['after' => $seatsafter, 'max' => $max])
            );

            // L4 for the moved user's net memberships.
            $membershipdelta = 1 - ($move->sourcegroupid ? 1 : 0);
            $membershipsafter = groups::count_memberships($this->activity, (int) $move->userid) + $membershipdelta;
            $capvalue = $resolver->effective_maxmembership((int) $move->userid)->value;
            $verdicts['L4'] = $this->verdict(
                $membershipsafter <= $capvalue,
                in_array('L4', $bypasses, true),
                get_string('moveruleL4', 'mod_selfselectadvanced', (object) [
                    'after' => $membershipsafter,
                    'max' => $capvalue,
                ])
            );

            // L3 when the move designates a leader (target) or a successor (source).
            if ($move->makeleader) {
                $verdicts['L3'] = $this->leadverdict((int) $move->userid, $moves, $bypasses);
            }
            if ($move->successorid) {
                $verdicts['L3S'] = $this->leadverdict((int) $move->successorid, $moves, $bypasses);
            }

            // Quota on both groups' net post-state rosters.
            $quotaok = $this->quota_after($targetid, $additions[$targetid] ?? [], $removals[$targetid] ?? []);
            if ($move->sourcegroupid) {
                $sourceid = (int) $move->sourcegroupid;
                $quotaok = $quotaok
                    && $this->quota_after($sourceid, $additions[$sourceid] ?? [], $removals[$sourceid] ?? []);
            }
            $exempt = $resolver->is_quota_exempt($targetid)->enabled
                && (!$move->sourcegroupid || $resolver->is_quota_exempt((int) $move->sourcegroupid)->enabled);
            $verdicts['QUOTA'] = $this->verdict(
                $quotaok || $exempt,
                in_array('QUOTA', $bypasses, true),
                get_string('moveruleQUOTA', 'mod_selfselectadvanced')
            );

            $result->permove[$moveid] = $verdicts;
            foreach ($verdicts as $verdict) {
                if (!$verdict['ok'] && !$verdict['bypassed']) {
                    $result->valid = false;
                }
            }
        }

        return $result;
    }

    /**
     * Commit a selected set atomically: all moves apply, or none.
     *
     * @param int[] $moveids the selected pending moves
     * @param int $actorid the acting manager
     * @return int number of committed moves
     * @throws \moodle_exception when the joint validation refuses
     */
    public function commit_set(array $moveids, int $actorid): int {
        global $DB;

        $lock = locks::acquire('activity:' . $this->activity->id());
        try {
            $transaction = $DB->start_delegated_transaction();

            $verdicts = $this->validate_set($moveids);
            if (!$verdicts->valid) {
                throw new \moodle_exception('errmovesetinvalid', 'mod_selfselectadvanced');
            }
            $moves = $this->load_pending($moveids);

            $now = time();
            foreach ($moves as $move) {
                $this->apply($move, $actorid, $now);
                $DB->update_record('selfselectadvanced_move', (object) [
                    'id' => $move->id,
                    'status' => 'committed',
                    'statusinfo' => json_encode($verdicts->permove[(int) $move->id]),
                    'usermodified' => $actorid,
                    'timemodified' => $now,
                    'timecommitted' => $now,
                ]);
                \mod_selfselectadvanced\event\move_committed::create([
                    'objectid' => (int) $move->id,
                    'context' => $this->activity->context(),
                    'relateduserid' => (int) $move->userid,
                    'other' => [
                        'sourcegroupid' => $move->sourcegroupid ? (int) $move->sourcegroupid : null,
                        'targetgroupid' => (int) $move->targetgroupid,
                    ],
                ])->trigger();
            }

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        // Post-commit notifications.
        foreach ($moves as $move) {
            $target = groups::get($this->activity, (int) $move->targetgroupid);
            notifier::send(
                $this->activity,
                'movecommitted',
                (int) $move->userid,
                'msgmovedsubject',
                'msgmovedbody',
                (object) ['group' => format_string($target->name)],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $this->activity->cm()->id,
                    'g' => $target->id,
                ]),
                format_string($target->name)
            );
        }

        return count($moves);
    }

    /**
     * Cancel a pending move.
     *
     * @param int $moveid the move
     * @param int $actorid the acting manager
     */
    public function cancel(int $moveid, int $actorid): void {
        global $DB;

        $move = $DB->get_record('selfselectadvanced_move', [
            'id' => $moveid,
            'activityid' => $this->activity->id(),
            'status' => 'pending',
        ], '*', MUST_EXIST);
        $DB->update_record('selfselectadvanced_move', (object) [
            'id' => $move->id,
            'status' => 'cancelled',
            'usermodified' => $actorid,
            'timemodified' => time(),
        ]);
        \mod_selfselectadvanced\event\move_cancelled::create([
            'objectid' => (int) $move->id,
            'context' => $this->activity->context(),
            'relateduserid' => (int) $move->userid,
            'other' => [
                'sourcegroupid' => $move->sourcegroupid ? (int) $move->sourcegroupid : null,
                'targetgroupid' => (int) $move->targetgroupid,
            ],
        ])->trigger();
    }

    /**
     * Apply one validated move inside the commit transaction.
     *
     * @param stdClass $move the move row
     * @param int $actorid the acting manager
     * @param int $now the commit time
     */
    private function apply(stdClass $move, int $actorid, int $now): void {
        global $DB;

        $userid = (int) $move->userid;

        if ($move->sourcegroupid) {
            $source = groups::get($this->activity, (int) $move->sourcegroupid);
            $row = $DB->get_record('selfselectadvanced_member', [
                'groupid' => $source->id,
                'userid' => $userid,
            ], '*', MUST_EXIST);
            $row->status = groups::STATUS_REMOVED;
            $row->isleader = 0;
            $row->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $row);

            // Leadership succession on the source, atomically (B3-checked).
            if ((int) $source->leaderid === $userid) {
                $DB->update_record('selfselectadvanced_group', (object) [
                    'id' => $source->id,
                    'leaderid' => (int) $move->successorid,
                    'usermodified' => $actorid,
                    'timemodified' => $now,
                ]);
                $DB->set_field('selfselectadvanced_member', 'isleader', 1, [
                    'groupid' => $source->id,
                    'userid' => (int) $move->successorid,
                ]);
            }
            freeze::sync_membership_change(
                $this->activity,
                groups::get($this->activity, (int) $source->id),
                $userid,
                false,
                $actorid
            );
        }

        $target = groups::get($this->activity, (int) $move->targetgroupid);
        $existing = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $target->id,
            'userid' => $userid,
        ]);
        if ($existing) {
            $existing->status = groups::STATUS_CONFIRMED;
            $existing->timeresponded = $now;
            $existing->timemodified = $now;
            $DB->update_record('selfselectadvanced_member', $existing);
        } else {
            $DB->insert_record('selfselectadvanced_member', (object) [
                'groupid' => $target->id,
                'userid' => $userid,
                'status' => groups::STATUS_CONFIRMED,
                'isleader' => 0,
                'invitedby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        if ($move->makeleader) {
            $DB->set_field('selfselectadvanced_member', 'isleader', 0, [
                'groupid' => $target->id,
                'userid' => (int) $target->leaderid,
            ]);
            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $target->id,
                'leaderid' => $userid,
                'usermodified' => $actorid,
                'timemodified' => $now,
            ]);
            $DB->set_field('selfselectadvanced_member', 'isleader', 1, [
                'groupid' => $target->id,
                'userid' => $userid,
            ]);
        }
        freeze::sync_membership_change(
            $this->activity,
            groups::get($this->activity, (int) $target->id),
            $userid,
            true,
            $actorid
        );
    }

    /**
     * Load pending moves of this activity by id, refusing foreign rows.
     *
     * @param int[] $moveids the ids
     * @return stdClass[] move rows
     */
    private function load_pending(array $moveids): array {
        global $DB;

        if (!$moveids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $moveids));
        $params[] = $this->activity->id();

        return array_values($DB->get_records_select(
            'selfselectadvanced_move',
            "id $insql AND activityid = ? AND status = 'pending'",
            $params,
            'id ASC'
        ));
    }

    /**
     * An L3 verdict for a user who would become a leader.
     *
     * @param int $userid the prospective leader
     * @param stdClass[] $moves the selected set (leaderships gained count)
     * @param string[] $bypasses rule codes bypassed for this move
     * @return array verdict
     */
    private function leadverdict(int $userid, array $moves, array $bypasses): array {
        $resolver = $this->gatekeeper->resolver();
        $gained = 0;
        $released = 0;
        foreach ($moves as $other) {
            if ($other->makeleader && (int) $other->userid === $userid) {
                $gained++;
            }
            if ((int) ($other->successorid ?? 0) === $userid && $other->sourcegroupid) {
                $gained++;
            }
            // A leader moving out of their group releases that slot to
            // the designated successor within the same set.
            if ($other->sourcegroupid && (int) $other->userid === $userid) {
                $source = groups::get($this->activity, (int) $other->sourcegroupid);
                if ((int) $source->leaderid === $userid) {
                    $released++;
                }
            }
        }
        $after = groups::count_leading($this->activity, $userid) + $gained - $released;
        $max = $resolver->effective_maxlead($userid)->value;

        return $this->verdict(
            $after <= $max,
            in_array('L3', $bypasses, true),
            get_string('moveruleL3', 'mod_selfselectadvanced', (object) ['after' => $after, 'max' => $max])
        );
    }

    /**
     * Quota compliance of a group's net post-state roster.
     *
     * @param int $groupid the group
     * @param int[] $add userids joining
     * @param int[] $remove userids leaving
     * @return bool compliant
     */
    private function quota_after(int $groupid, array $add, array $remove): bool {
        global $DB;

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $this->activity->id()]);
        if (!$rules) {
            return true;
        }

        // Evaluate against the virtual roster.
        $current = $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$groupid, groups::STATUS_CONFIRMED]
        );
        $virtual = array_diff(array_merge(array_map('intval', $current), $add), $remove);
        $attrs = attributes\manager::get_for_users($virtual);

        foreach ($rules as $rule) {
            $values = [];
            foreach ($virtual as $userid) {
                $value = $attrs[(int) $userid]->{$rule->dimension} ?? null;
                if ($value !== null && $value !== '') {
                    $values[] = \core_text::strtolower($value);
                }
            }
            if ($rule->rtype === 'distinct') {
                if (count(array_unique($values)) < (int) $rule->mincount) {
                    return false;
                }
            } else {
                $count = count(array_filter(
                    $values,
                    static fn($v) => $v === \core_text::strtolower((string) $rule->value)
                ));
                if ($rule->mincount !== null && $count < (int) $rule->mincount) {
                    return false;
                }
                if ($rule->maxcount !== null && $count > (int) $rule->maxcount) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Build one rule verdict.
     *
     * @param bool $ok rule satisfied on the net post-state
     * @param bool $bypassed rule bypassed by a move-scope override
     * @param string $reason localised figures
     * @return array verdict
     */
    private function verdict(bool $ok, bool $bypassed, string $reason): array {
        return ['ok' => $ok, 'bypassed' => $bypassed && !$ok, 'reason' => $reason];
    }
}
