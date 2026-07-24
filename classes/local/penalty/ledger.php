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
 * The authoritative per-group penalty ledger (spec 11, D5, A12): one
 * current row per approved group, recomputed in place on approval,
 * settings changes and the nightly reconciliation; explicit zero rows
 * are stored for on-time groups. The gradebook deducts each group's
 * penalty cumulatively per confirmed member, floored at zero; students
 * in no firm or frozen group keep a null grade until placed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger {
    /**
     * Compute and store the current penalty of one approved group,
     * firing penalty_recomputed when the value changes.
     *
     * @param activity $activity the activity
     * @param stdClass $group the approved group row
     * @param resolver|null $resolver reuse a resolver, or build one
     * @return stdClass the ledger row
     */
    public static function upsert_for_group(activity $activity, stdClass $group, ?resolver $resolver = null): stdClass {
        global $DB;

        $resolver = $resolver ?? new resolver($activity);
        $penalty = calculator::compute($activity, $group, $resolver);

        $row = $DB->get_record('selfselectadvanced_penalty', ['groupid' => $group->id]);
        $oldvalue = $row ? (float) $row->penaltyvalue : null;
        $isnew = !$row;
        if ($isnew) {
            $row = (object) ['activityid' => $activity->id(), 'groupid' => (int) $group->id];
        }
        $row->dayslate = $penalty->dayslate;
        $row->penaltyvalue = $penalty->penaltyvalue;
        $row->waived = $penalty->waived ? 1 : 0;
        $row->waivereason = $penalty->waivereason;
        $row->basis = $penalty->basis;
        $row->timecomputed = time();
        if ($isnew) {
            $row->id = $DB->insert_record('selfselectadvanced_penalty', $row);
        } else {
            $DB->update_record('selfselectadvanced_penalty', $row);
        }

        if ($oldvalue === null || abs($oldvalue - (float) $row->penaltyvalue) > 0.000001) {
            \mod_selfselectadvanced\event\penalty_recomputed::create([
                'objectid' => $row->id,
                'context' => $activity->context(),
                'other' => [
                    'groupid' => (int) $group->id,
                    'oldvalue' => $oldvalue,
                    'newvalue' => (float) $row->penaltyvalue,
                ],
            ])->trigger();
        }

        return $row;
    }

    /**
     * Recompute every approved group of the activity (settings edits,
     * override changes, the reconciliation task) and push grades.
     *
     * @param activity $activity the activity
     * @return int number of groups recomputed
     */
    public static function recompute_all(activity $activity): int {
        global $DB;

        $resolver = new resolver($activity);
        $groups = $DB->get_records_select(
            'selfselectadvanced_group',
            'activityid = ? AND timeapproved IS NOT NULL',
            [$activity->id()]
        );
        foreach ($groups as $group) {
            self::upsert_for_group($activity, $group, $resolver);
        }
        self::push_grades($activity);

        return count($groups);
    }

    /**
     * Push grades: point value minus the penalty of EACH firm or frozen
     * group the student is a confirmed member of (cumulative, D5),
     * floored at zero. Students without such a membership get a null
     * grade (not zero) until placed (spec 11).
     *
     * @param activity $activity the activity
     * @param int $userid one user, or 0 for all
     */
    /**
     * Set or clear the guide-awarded group mark and republish grades.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group
     * @param float|null $award the mark, null clears
     */
    public static function set_award(activity $activity, stdClass $group, ?float $award): void {
        global $DB;

        $row = $DB->get_record('selfselectadvanced_penalty', [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
        ]);
        if (!$row) {
            $row = self::upsert_for_group($activity, $group);
        }
        $DB->set_field('selfselectadvanced_penalty', 'award', $award, ['id' => $row->id]);
        self::push_grades($activity);
    }

    public static function push_grades(activity $activity, int $userid = 0): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $settings = $activity->settings();

        $params = [
            'activityid' => $activity->id(),
            'confirmed' => groups::STATUS_CONFIRMED,
            'firm' => state::FIRM,
            'frozen' => state::FROZEN,
        ];
        $usersql = '';
        if ($userid) {
            $usersql = ' AND m.userid = :userid';
            $params['userid'] = $userid;
        }
        $sql = "SELECT DISTINCT m.userid
                  FROM {selfselectadvanced_member} m
                  JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                 WHERE g.activityid = :activityid
                   AND m.status = :confirmed
                   AND g.state IN (:firm, :frozen)
                   $usersql";
        $userids = $DB->get_fieldset_sql($sql, $params);

        // 1.4.0: sequence-of-joining decomposition; the per-step
        // breakdown travels as gradebook feedback.
        $grades = [];
        foreach ($userids as $graded) {
            $computed = gradebook::compute_user($activity, (int) $graded);
            $grades[(int) $graded] = (object) [
                'userid' => (int) $graded,
                'rawgrade' => $computed->grade,
                'feedback' => \html_writer::alist($computed->steps, ['class' => 'selfselectadvanced-gradesteps']),
                'feedbackformat' => FORMAT_HTML,
            ];
        }

        // Members who lost their last firm/frozen membership revert to
        // a null grade (not zero) until placed again: null out any user
        // holding a final grade who no longer appears in the totals.
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'selfselectadvanced',
            'iteminstance' => $activity->id(),
            'courseid' => $activity->courseid(),
        ]);
        if ($item) {
            foreach ($item->get_final() as $gradeduser => $final) {
                $gradeduser = (int) $gradeduser;
                if ($userid && $gradeduser !== $userid) {
                    continue;
                }
                if (!isset($grades[$gradeduser]) && $final->finalgrade !== null) {
                    $grades[$gradeduser] = (object) ['userid' => $gradeduser, 'rawgrade' => null];
                }
            }
        }

        if ($grades) {
            grade_update(
                'mod/selfselectadvanced',
                $activity->courseid(),
                'mod',
                'selfselectadvanced',
                $activity->id(),
                0,
                $grades,
                ['itemname' => $settings->name, 'grademax' => (float) $settings->grade, 'grademin' => 0]
            );
        }
    }
}
