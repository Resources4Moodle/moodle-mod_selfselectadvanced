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

namespace mod_selfselectadvanced\local\quota;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use stdClass;

/**
 * Slot-based composition templates (2026-07-24 change).
 *
 * A template is an ordered list of slots; each slot books `mincount`
 * members whose attribute in `dimension` (department, subdepartment,
 * gender, program) either all equal `value` (matchtype "value", null
 * value = any one shared value) or are pairwise different (matchtype
 * "distinct"). A member is booked into at most ONE slot — once booked
 * under a slot, later slots no longer see them, so the remaining
 * requirements adjust themselves.
 *
 * `allowoverlap` governs the consumption registry. A slot that books
 * at least one member RECORDS what it used — its own value, or every
 * booked member's value for a distinct slot — whether or not it allows
 * overlap; a slot that books nobody records nothing. A slot WITHOUT
 * `allowoverlap` then refuses a member when ANY of their attribute
 * values, in any dimension and not just the slot's own, was recorded
 * by an EARLIER slot ("must not match"): after "2 with Department
 * Computer", a third Computer student cannot fill a
 * distinct-sub-department seat. With `allowoverlap` such members stay
 * eligible, and the slot still records.
 *
 * The evaluation is EXACT (1.20, replacing the greedy heuristic of
 * audit item 14): {@see \mod_selfselectadvanced\local\quota\allocator}
 * searches every assignment, so a template is reported satisfied if and
 * only if SOME seating of the roster satisfies it. The verdict does not
 * depend on the order the search explores. Slot ORDER remains
 * load-bearing SEMANTICS, because the no-overlap rule above is defined
 * against EARLIER slots; what is gone is slot order deciding
 * satisfiability by accident. One caveat, stated plainly rather than
 * buried: an unusually large roster or template can exhaust the
 * allocator's node budget, and that team is then answered by the old
 * heuristic and flagged with `exact => false`. The heuristic books a
 * genuinely valid seating, so it can only ever under-report a team's
 * fill - it can never call a team compliant that is not.
 *
 * Where several maximum-fill assignments exist, the one reported is the
 * one leaving the shortfall on the MOST restrictive seats (the
 * maintainer's least-restrictive placement rule: a seat many people
 * could fill is offered before a seat almost nobody can). So WHICH slot
 * shows a shortfall can differ from the old heuristic. The total fill,
 * and therefore `ok`, is canonical.
 *
 * Example — "two members from one department, and three each from
 * distinct other departments, computer students also permitted":
 *   slot 1: mincount 2, department, value "Computer"
 *   slot 2: mincount 3, department, distinct, allowoverlap on
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slots {
    /** @var string[] Slot dimensions. */
    public const DIMENSIONS = ['department', 'subdepartment', 'gender', 'program'];

    /**
     * @var string Configure rules, quotas and dates. The authority
     * behind every write in this class.
     */
    public const MANAGE = 'mod/selfselectadvanced:manage';

    /**
     * Refuse unless this actor may configure this activity's quotas.
     *
     * AUTHORISED HERE (audit A-6), not by quotas.php. The composition
     * template is what every feasibility verdict, every fit score and
     * every auto-grouping run is computed against, so editing it moves
     * who can join which team across the whole activity - and the only
     * thing that had ever asked about the actor was a require_capability
     * at the top of one page. Three methods trusted it; nothing that
     * did not come through that page was checked at all.
     *
     * The actor is passed EXPLICITLY, never read from $USER, and the
     * parameter is required rather than defaulted for the reason
     * authority.php gives: a default of "the current user" is silently
     * wrong in every context that has no current user - cron, an adhoc
     * task, a CLI seed - and would turn a missing argument into a
     * capability answer about whoever happened to be logged in.
     *
     * @param activity $activity the activity
     * @param int $actorid the person acting
     * @throws \required_capability_exception when the capability is not effective
     */
    private static function require_manage(activity $activity, int $actorid): void {
        require_capability(self::MANAGE, $activity->context(), $actorid);
    }

    /**
     * All slots of an activity in slot order.
     *
     * @param activity $activity the activity
     * @return stdClass[]
     */
    public static function get_all(activity $activity): array {
        global $DB;

        return array_values($DB->get_records('selfselectadvanced_qslot', [
            'activityid' => $activity->id(),
        ], 'slotno, id'));
    }

    /**
     * Create a slot at the end of the template.
     *
     * @param activity $activity the activity
     * @param stdClass $data mincount, dimension, matchtype, value, allowoverlap
     * @param int $actorid the acting manager
     * @return stdClass the stored row
     * @throws \required_capability_exception when the actor may not manage this activity
     */
    public static function create(activity $activity, stdClass $data, int $actorid): stdClass {
        global $DB;

        self::require_manage($activity, $actorid);

        if (!in_array($data->dimension, self::DIMENSIONS, true)) {
            throw new \coding_exception('Bad slot dimension');
        }
        if (!in_array($data->matchtype, ['value', 'distinct'], true)) {
            throw new \coding_exception('Bad slot matchtype');
        }
        $now = time();
        $record = (object) [
            'activityid' => $activity->id(),
            'slotno' => 1 + (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(slotno), 0) FROM {selfselectadvanced_qslot} WHERE activityid = ?',
                [$activity->id()]
            ),
            'mincount' => max(1, (int) $data->mincount),
            'dimension' => $data->dimension,
            'matchtype' => $data->matchtype,
            'value' => $data->matchtype === 'value' && trim((string) ($data->value ?? '')) !== ''
                ? trim((string) $data->value) : null,
            'allowoverlap' => empty($data->allowoverlap) ? 0 : 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('selfselectadvanced_qslot', $record);

        return $record;
    }

    /**
     * Update an existing slot in place (2026-07-25 request: rows are
     * editable, not just add/delete).
     *
     * @param activity $activity the activity
     * @param int $slotid the row id
     * @param stdClass $data mincount, dimension, matchtype, value, allowoverlap
     * @param int $actorid the acting manager
     * @return stdClass the updated row
     * @throws \required_capability_exception when the actor may not manage this activity
     */
    public static function update(activity $activity, int $slotid, stdClass $data, int $actorid): stdClass {
        global $DB;

        self::require_manage($activity, $actorid);

        $slot = $DB->get_record('selfselectadvanced_qslot', [
            'id' => $slotid,
            'activityid' => $activity->id(),
        ], '*', MUST_EXIST);
        if (!in_array($data->dimension, self::DIMENSIONS, true)) {
            throw new \coding_exception('Bad slot dimension');
        }
        if (!in_array($data->matchtype, ['value', 'distinct'], true)) {
            throw new \coding_exception('Bad slot matchtype');
        }
        $slot->mincount = max(1, (int) $data->mincount);
        $slot->dimension = $data->dimension;
        $slot->matchtype = $data->matchtype;
        $slot->value = $data->matchtype === 'value' && trim((string) ($data->value ?? '')) !== ''
            ? trim((string) $data->value) : null;
        $slot->allowoverlap = empty($data->allowoverlap) ? 0 : 1;
        $slot->timemodified = time();
        $DB->update_record('selfselectadvanced_qslot', $slot);

        return $slot;
    }

    /**
     * Delete a slot and renumber.
     *
     * @param activity $activity the activity
     * @param int $slotid the row id
     * @param int $actorid the acting manager
     * @throws \required_capability_exception when the actor may not manage this activity
     */
    public static function delete(activity $activity, int $slotid, int $actorid): void {
        global $DB;

        self::require_manage($activity, $actorid);

        $DB->delete_records('selfselectadvanced_qslot', ['id' => $slotid, 'activityid' => $activity->id()]);
        $slotno = 1;
        foreach (self::get_all($activity) as $slot) {
            if ((int) $slot->slotno !== $slotno) {
                $DB->set_field('selfselectadvanced_qslot', 'slotno', $slotno, ['id' => $slot->id]);
            }
            $slotno++;
        }
    }

    /**
     * Evaluate the template against a group's confirmed members.
     *
     * Two queries — the template and the roster — then the exact
     * evaluation of evaluate_from_data() below, which is the single
     * implementation every composition verdict funnels through.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @return stdClass {ok, slots: [{slot, filled, missing, label,
     *                  deficiency}], assignment, totalfilled, exact}
     */
    public static function evaluate(activity $activity, int $groupid): stdClass {
        global $DB;

        $template = self::get_all($activity);
        if (!$template) {
            return self::evaluate_from_data([], [], []);
        }

        $memberids = $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$groupid, groups::STATUS_CONFIRMED]
        );
        $memberids = array_map('intval', $memberids);
        $attrs = manager::get_for_users($memberids);

        return self::evaluate_from_data($template, $memberids, $attrs);
    }

    /**
     * Evaluate the template against an already-loaded confirmed member
     * set of one group.
     *
     * The evaluation behind evaluate(), extracted so the batch quota
     * compliance path (evaluator::compliance_for_activity()) can reuse
     * the exact same logic against data it loaded once for a whole
     * activity, instead of these three queries per group. The seating
     * itself is the exact search described in the class docblock and
     * implemented in allocator::solve(); this method issues no queries
     * of its own, and given identical inputs it returns an identical
     * result — which is what lets a pre-lock check and the in-lock
     * re-check of the same data agree.
     *
     * The result carries three fields beyond the panel's own shape:
     * `assignment` maps each seated userid to the ARRAY INDEX of its
     * entry in `slots` (not to `slotno`), `totalfilled` is the number
     * of seats filled across the template, and `exact` is false only in
     * the rare case where the input-size guard or the search budget
     * made the allocator fall back to its heuristic.
     *
     * @param stdClass[] $template slot rows in slot order
     * @param int[] $memberids the group's confirmed member ids
     * @param stdClass[] $attrs participant attribute records keyed by userid
     * @return stdClass {ok, slots: [{slot, filled, missing, label,
     *                  deficiency}], assignment, totalfilled, exact}
     */
    public static function evaluate_from_data(array $template, array $memberids, array $attrs): stdClass {
        $result = (object) [
            'ok' => true,
            'slots' => [],
            'assignment' => [],
            'totalfilled' => 0,
            'exact' => true,
        ];
        if (!$template) {
            return $result;
        }

        $template = array_values($template);
        $solution = allocator::solve($template, $memberids, $attrs);
        $result->assignment = $solution->assignment;
        $result->totalfilled = (int) $solution->totalfilled;
        $result->exact = (bool) $solution->exact;

        foreach ($template as $index => $slot) {
            $filled = (int) ($solution->filled[$index] ?? 0);
            $missing = max(0, (int) $slot->mincount - $filled);
            $label = self::label($slot);
            $result->slots[] = (object) [
                'slot' => $slot,
                'filled' => $filled,
                'missing' => $missing,
                'label' => $label,
                'deficiency' => $missing
                    ? get_string('slotdeficiency', 'mod_selfselectadvanced', (object) [
                        'missing' => $missing,
                        'label' => $label,
                    ])
                    : '',
            ];
            if ($missing) {
                $result->ok = false;
            }
        }

        return $result;
    }

    /**
     * Human label for a slot.
     *
     * @param stdClass $slot the row
     * @return string
     */
    public static function label(stdClass $slot): string {
        $a = (object) [
            'count' => (int) $slot->mincount,
            'dimension' => get_string('attr' . $slot->dimension, 'mod_selfselectadvanced'),
            'value' => $slot->value,
        ];
        if ($slot->matchtype === 'distinct') {
            $key = $slot->allowoverlap ? 'slotlabeldistinctoverlap' : 'slotlabeldistinct';
        } else if ($slot->value !== null) {
            $key = 'slotlabelvalue';
        } else {
            $key = 'slotlabelsame';
        }

        return get_string($key, 'mod_selfselectadvanced', $a);
    }
}
