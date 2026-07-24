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
     * @param activity $activity the activity
     * @param int $userid the student
     * @return stdClass {grade: float|null, steps: string[], hasmembership: bool}
     */
    public static function compute_user(activity $activity, int $userid): stdClass {
        global $DB;

        $settings = $activity->settings();
        $resolver = new resolver($activity);
        $grademax = (float) $settings->grade;

        [$insql, $inparams] = $DB->get_in_or_equal([state::FIRM, state::FROZEN], SQL_PARAMS_NAMED, 'st');
        $rows = $DB->get_records_sql(
            "SELECT m.id AS memberid, m.timeresponded, m.timecreated, m.isleader,
                    g.id AS groupid, g.name, g.leaderid, g.state,
                    p.penaltyvalue, p.award
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
          LEFT JOIN {selfselectadvanced_penalty} p ON p.groupid = g.id
              WHERE g.activityid = :activityid AND m.userid = :userid
                AND m.status = :confirmed AND g.state $insql
           ORDER BY COALESCE(NULLIF(m.timeresponded, 0), m.timecreated), m.id",
            $inparams + [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'confirmed' => groups::STATUS_CONFIRMED,
            ]
        );

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
            $share = self::incomplete_share($activity, $resolver, $row, $userid);
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
        if ($minmembership > 0 && $penalty > 0 && time() > (int) $settings->timedue) {
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
     * @return float
     */
    private static function incomplete_share(activity $activity, resolver $resolver, stdClass $row, int $userid): float {
        $penalty = (float) $activity->settings()->incompletepenalty;
        if ($penalty <= 0) {
            return 0.0;
        }
        $confirmed = groups::count_confirmed((int) $row->groupid);
        if ($confirmed >= $resolver->effective_minsize((int) $row->groupid)->value) {
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
