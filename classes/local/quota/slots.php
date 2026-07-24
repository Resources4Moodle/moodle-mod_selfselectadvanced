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
 * requirements adjust themselves. Unless a slot sets `allowoverlap`,
 * attribute values consumed by earlier slots are excluded from it
 * ("must not match"); with `allowoverlap` they stay eligible.
 *
 * The evaluation is a GREEDY HEURISTIC (documented, audit item 14):
 * slots book in order and never backtrack, so a rare roster with a
 * valid exotic assignment can still report a deficiency; managers can
 * reorder slots to guide the booking.
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
     * @return stdClass the stored row
     */
    public static function create(activity $activity, stdClass $data): stdClass {
        global $DB;

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
     * Delete a slot and renumber.
     *
     * @param activity $activity the activity
     * @param int $slotid the row id
     */
    public static function delete(activity $activity, int $slotid): void {
        global $DB;

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
     * Booking is greedy in slot order. For a "value" slot with a fixed
     * value, matching unbooked members are booked up to mincount. For
     * a null-value "value" slot ("n from ONE x"), the largest unbooked
     * value-group is used. For a "distinct" slot, one member per
     * eligible value is booked, preferring values with the FEWEST
     * remaining members (so plentiful values stay available for later
     * slots). Deterministic: ties resolve by value name, then userid.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @return stdClass {ok, slots: [{slot, filled, missing, label, deficiency}]}
     */
    public static function evaluate(activity $activity, int $groupid): stdClass {
        global $DB;

        $template = self::get_all($activity);
        $result = (object) ['ok' => true, 'slots' => []];
        if (!$template) {
            return $result;
        }

        $memberids = $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$groupid, groups::STATUS_CONFIRMED]
        );
        sort($memberids);
        $attrs = manager::get_for_users(array_map('intval', $memberids));

        $booked = [];               // Userid => slotno.
        $usedvalues = [];           // Dimension => value => true (consumed by earlier slots).
        foreach ($template as $slot) {
            $eligible = [];         // Value => userids, unbooked members with a usable value.
            foreach ($memberids as $userid) {
                if (isset($booked[$userid])) {
                    continue;
                }
                $value = \core_text::strtolower(trim((string) ($attrs[$userid]->{$slot->dimension} ?? '')));
                if ($value === '') {
                    continue;
                }
                if (!$slot->allowoverlap && isset($usedvalues[$slot->dimension][$value])) {
                    continue;
                }
                $eligible[$value][] = (int) $userid;
            }
            ksort($eligible);

            $bookednow = [];
            if ($slot->matchtype === 'value') {
                $target = $slot->value !== null ? \core_text::strtolower($slot->value) : null;
                if ($target === null) {
                    // "n from ONE value": pick the largest value-group.
                    $best = null;
                    foreach ($eligible as $value => $ids) {
                        if ($best === null || count($ids) > count($eligible[$best])) {
                            $best = $value;
                        }
                    }
                    $target = $best;
                }
                $pool = $target !== null ? ($eligible[$target] ?? []) : [];
                sort($pool);
                $bookednow = array_slice($pool, 0, (int) $slot->mincount);
                if ($bookednow && $target !== null) {
                    $usedvalues[$slot->dimension][$target] = true;
                }
            } else {
                // Distinct: one member per value, scarcest values first.
                uksort($eligible, static fn($a, $b) => [count($eligible[$a]), $a] <=> [count($eligible[$b]), $b]);
                foreach ($eligible as $value => $ids) {
                    if (count($bookednow) >= (int) $slot->mincount) {
                        break;
                    }
                    sort($ids);
                    $bookednow[] = $ids[0];
                    $usedvalues[$slot->dimension][$value] = true;
                }
            }
            foreach ($bookednow as $userid) {
                $booked[$userid] = (int) $slot->slotno;
            }

            $filled = count($bookednow);
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
