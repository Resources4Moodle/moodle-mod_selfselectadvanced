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

namespace mod_selfselectadvanced\local\penalty;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\state;
use stdClass;

/**
 * Sequence-of-joining grade decomposition (1.4.0).
 *
 * Every awarded mark or penalty is LINKED to one of the student's
 * groups; when a student belongs to several, their groups are taken in
 * the order they JOINED (time of confirmation, then membership id) and
 * each group's contribution is applied as one step with the running
 * total clamped to [0, grademax] after every step — so the sequence is
 * the deterministic target of every reward and penalty, and the
 * breakdown is published as gradebook feedback.
 *
 * Per step (group g, student s):
 *   award_g (guide's group mark; when NO group of s carries an award
 *   the base is grademax on the first step, preserving the classic
 *   deduction model) − latepenalty_g − incompleteshare(s, g).
 *
 * incompleteshare: when g holds fewer confirmed members than its
 * effective minimum, the activity's incomplete penalty applies — the
 * leader carries the teacher-set majority share (leadershare %), the
 * remainder splits equally among the other members.
 *
 * After all real groups, a student below the activity's minimum
 * memberships is a DEFAULTER: the defaulter penalty applies once per
 * missing group ("the groups the student has not made himself part
 * of"), as further sequence steps.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradebook {
    /**
     * Compute one student's grade and its per-group breakdown.
     *
     * A thin single-user wrapper over compute_activity(): grade_item
     * updates and individual recalculation still issue only the tiny
     * one-student query, they just share its implementation with the
     * batched path.
     *
     * @param activity $activity the activity
     * @param int $userid the student
     * @return stdClass {grade: float|null, steps: string[], hasmembership: bool}
     */
    public static function compute_user(activity $activity, int $userid): stdClass {
        $results = self::compute_activity($activity, [$userid]);

        return $results[$userid] ?? (object) ['grade' => null, 'steps' => [], 'hasmembership' => false];
    }

    /**
     * Compute the grade and breakdown of every listed student in one pass.
     *
     * This is the batched counterpart of compute_user(), used by
     * push_grades() so that grading a whole activity issues a handful
     * of queries instead of one gradebook query per student. The
     * member/group/penalty rows behind every listed student's
     * decomposition are loaded in a single query ordered by userid, one
     * override resolver is shared for the whole batch instead of built
     * per student, and the confirmed-member counts incomplete_share()
     * needs are precomputed in bulk. Each student's steps are then built
     * from that in-memory data with exactly the arithmetic compute_user()
     * has always used: the sequence-of-joining decomposition, clamped to
     * [0, grademax] after every step, defaulter steps appended after the
     * real groups.
     *
     * @param activity $activity the activity
     * @param int[] $userids the students to compute, in any order
     * @return stdClass[] {grade, steps, hasmembership} keyed by userid;
     *                    every listed userid is present in the result
     */
    public static function compute_activity(activity $activity, array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        $settings = $activity->settings();
        $resolver = new resolver($activity);
        $grademax = (float) $settings->grade;

        [$useridsql, $useridparams] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'gu');
        [$statesql, $stateparams] = $DB->get_in_or_equal([state::FIRM, state::FROZEN], SQL_PARAMS_NAMED, 'st');
        $rows = $DB->get_records_sql(
            "SELECT m.id AS memberid, m.userid, m.timeresponded, m.timecreated, m.isleader,
                    g.id AS groupid, g.name, g.leaderid, g.state,
                    p.penaltyvalue, p.award
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
          LEFT JOIN {selfselectadvanced_penalty} p ON p.groupid = g.id
              WHERE g.activityid = :activityid AND m.userid $useridsql
                AND m.status = :confirmed AND g.state $statesql
           ORDER BY m.userid, COALESCE(NULLIF(m.timeresponded, 0), m.timecreated), m.id",
            $useridparams + $stateparams + [
                'activityid' => $activity->id(),
                'confirmed' => groups::STATUS_CONFIRMED,
            ]
        );

        $byuser = [];
        $groupids = [];
        foreach ($rows as $row) {
            $byuser[(int) $row->userid][] = $row;
            $groupids[(int) $row->groupid] = true;
        }

        // Incomplete_share() short-circuits before touching the count
        // when there is no incomplete penalty, so only bulk-fetch it
        // when it will actually be used, exactly like the per-row query
        // it replaces.
        $confirmedcounts = (float) $settings->incompletepenalty > 0
            ? groups::count_confirmed_bulk(array_keys($groupids))
            : [];

        $results = [];
        foreach ($userids as $userid) {
            $userid = (int) $userid;
            $results[$userid] = self::compute_for_user(
                $activity,
                $resolver,
                $settings,
                $grademax,
                $userid,
                $byuser[$userid] ?? [],
                $confirmedcounts
            );
        }

        return $results;
    }

    /**
     * Build one student's grade and breakdown from already-loaded rows.
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver shared for the whole batch
     * @param stdClass $settings the activity settings
     * @param float $grademax the activity's maximum grade
     * @param int $userid the student
     * @param stdClass[] $rows this student's confirmed membership rows, in join order
     * @param int[] $confirmedcounts confirmed member counts keyed by groupid
     * @return stdClass {grade: float|null, steps: string[], hasmembership: bool}
     */
    private static function compute_for_user(
        activity $activity,
        resolver $resolver,
        stdClass $settings,
        float $grademax,
        int $userid,
        array $rows,
        array $confirmedcounts
    ): stdClass {
        $result = (object) ['grade' => null, 'steps' => [], 'hasmembership' => !empty($rows)];
        if (!$rows) {
            return $result;
        }

        $anyaward = false;
        foreach ($rows as $row) {
            if ($row->award !== null) {
                $anyaward = true;
            }
        }

        $total = 0.0;
        $position = 0;
        foreach ($rows as $row) {
            $position++;
            $parts = [];
            $delta = 0.0;
            if ($anyaward) {
                $award = (float) ($row->award ?? 0);
                $delta += $award;
                $parts[] = get_string('gradestepaward', 'mod_selfselectadvanced', format_float($award, 2, true, true));
            } else if ($position === 1) {
                $delta += $grademax;
                $parts[] = get_string('gradestepbase', 'mod_selfselectadvanced', format_float($grademax, 2, true, true));
            }
            $late = (float) ($row->penaltyvalue ?? 0);
            if ($late > 0) {
                $delta -= $late;
                $parts[] = get_string('gradesteplate', 'mod_selfselectadvanced', format_float($late, 2, true, true));
            }
            $share = self::incomplete_share($activity, $resolver, $row, $userid, $confirmedcounts);
            if ($share > 0) {
                $delta -= $share;
                $parts[] = get_string(
                    (int) $row->leaderid === $userid ? 'gradestepincompleteleader' : 'gradestepincomplete',
                    'mod_selfselectadvanced',
                    format_float($share, 2, true, true)
                );
            }
            $total = max(0.0, min($grademax, $total + $delta));
            $result->steps[] = get_string('gradestep', 'mod_selfselectadvanced', (object) [
                'position' => $position,
                'group' => format_string($row->name),
                'detail' => $parts ? implode(', ', $parts) : get_string('gradestepnone', 'mod_selfselectadvanced'),
                'running' => format_float($total, 2, true, true),
            ]);
        }

        // Defaulter steps: one per missing membership.
        $minmembership = (int) $settings->minmembership;
        $penalty = (float) $settings->defaulterpenalty;
        $effectivedue = $resolver->effective_dates($userid)->timedue;
        if ($minmembership > 0 && $penalty > 0 && time() > (int) $effectivedue) {
            for ($missing = count($rows) + 1; $missing <= $minmembership; $missing++) {
                $position++;
                $total = max(0.0, min($grademax, $total - $penalty));
                $result->steps[] = get_string('gradestepdefaulter', 'mod_selfselectadvanced', (object) [
                    'position' => $position,
                    'penalty' => format_float($penalty, 2, true, true),
                    'running' => format_float($total, 2, true, true),
                ]);
            }
        }

        $result->grade = $total;

        return $result;
    }

    /**
     * This member's share of the incomplete-group penalty, zero when
     * the group meets its effective minimum or no penalty is set.
     *
     * @param activity $activity the activity
     * @param resolver $resolver effective values
     * @param stdClass $row membership row (groupid, leaderid)
     * @param int $userid the student
     * @param int[] $confirmedcounts confirmed member counts keyed by groupid, from
     *                               groups::count_confirmed_bulk(); falls back to a
     *                               direct count when a groupid is not present
     * @return float
     */
    private static function incomplete_share(
        activity $activity,
        resolver $resolver,
        stdClass $row,
        int $userid,
        array $confirmedcounts = []
    ): float {
        $penalty = (float) $activity->settings()->incompletepenalty;
        if ($penalty <= 0) {
            return 0.0;
        }
        $groupid = (int) $row->groupid;
        $confirmed = $confirmedcounts[$groupid] ?? groups::count_confirmed($groupid);
        if ($confirmed >= $resolver->effective_minsize($groupid)->value) {
            return 0.0;
        }
        $sharepct = max(0, min(100, (int) $activity->settings()->leadershare));
        $leaderpart = $penalty * $sharepct / 100.0;
        if ((int) $row->leaderid === $userid) {
            return $confirmed > 1 ? $leaderpart : $penalty;
        }
        if ($confirmed <= 1) {
            return 0.0;
        }

        return ($penalty - $leaderpart) / ($confirmed - 1);
    }
}
