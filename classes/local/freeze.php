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
use stdClass;

/**
 * Core-group synchronisation (spec 12). This slice ships the pieces
 * staged moves need - membership sync into an owned core group and
 * append-only snapshots (decision A6); freeze/unfreeze themselves
 * (T5/T6) complete the service in slice 10.
 *
 * Good-neighbour rules (spec 14.5): only the official groups API, only
 * groups this plugin created (tracked by coregroupid).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class freeze {
    /**
     * Mirror one membership change of a frozen group into its core
     * group and append a fresh snapshot (A6): a committed staged move
     * on a frozen group updates plugin roster, core group and snapshot
     * in the same transaction, so unfreeze restores the latest
     * plugin-authorised state.
     *
     * No-op for groups that are not frozen or carry no owned core group.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row (post-change)
     * @param int $userid the moved user
     * @param bool $added true when added, false when removed
     * @param int $actorid the acting manager
     */
    public static function sync_membership_change(
        activity $activity,
        stdClass $group,
        int $userid,
        bool $added,
        int $actorid
    ): void {
        global $CFG;

        if ($group->state !== state::FROZEN || empty($group->coregroupid)) {
            return;
        }
        require_once($CFG->dirroot . '/group/lib.php');

        if (groups_group_exists((int) $group->coregroupid)) {
            if ($added) {
                groups_add_member((int) $group->coregroupid, $userid);
            } else {
                groups_remove_member((int) $group->coregroupid, $userid);
            }
        }
        self::append_snapshot($group, $actorid);
    }

    /**
     * T5: freeze a firm group its assigned guide reviews (spec 12).
     *
     * Creates (or repairs) the mirrored core course group named
     * "[idnumber or activity name] groupname", adds all confirmed
     * members, ensures the activity grouping, appends the snapshot and
     * locks the plugin group. Idempotent: an externally-deleted core
     * group is recreated by re-freezing (drift rule).
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @param int $actorid the acting guide (must hold the freeze capability)
     * @return stdClass the updated group row
     * @throws \moodle_exception when a gate refuses
     */
    public static function freeze_group(activity $activity, stdClass $group, int $actorid): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $gatekeeper = new rules\gatekeeper($activity, new override\resolver($activity));

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($activity, (int) $group->id);
            $isrepair = $fresh->state === state::FROZEN
                && (empty($fresh->coregroupid) || !groups_group_exists((int) $fresh->coregroupid));
            if (!$isrepair) {
                if ($refusal = $gatekeeper->can_freeze($fresh)) {
                    throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
                }
                if ((int) $fresh->guideid !== $actorid) {
                    throw new \moodle_exception('refusalnotassignedguide', 'mod_selfselectadvanced');
                }
            }

            // Create or reconcile the owned core group (official API only).
            $coregroupid = (int) ($fresh->coregroupid ?? 0);
            if (!$coregroupid || !groups_group_exists($coregroupid)) {
                $prefix = trim((string) ($activity->cm()->idnumber ?: $activity->name()));
                $coregroupid = groups_create_group((object) [
                    'courseid' => $activity->courseid(),
                    'name' => \core_text::substr('[' . $prefix . '] ' . $fresh->name, 0, 254),
                    'description' => get_string('coregroupdescription', 'mod_selfselectadvanced', $fresh->pluginuid),
                    'descriptionformat' => FORMAT_HTML,
                ]);
            }
            $confirmed = $DB->get_fieldset_select(
                'selfselectadvanced_member',
                'userid',
                'groupid = ? AND status = ?',
                [$fresh->id, groups::STATUS_CONFIRMED]
            );
            $current = array_keys(groups_get_members($coregroupid, 'u.id'));
            foreach (array_diff($confirmed, $current) as $userid) {
                groups_add_member($coregroupid, (int) $userid);
            }
            foreach (array_diff($current, $confirmed) as $userid) {
                groups_remove_member($coregroupid, (int) $userid);
            }

            // Ensure the activity grouping and the membership in it.
            $groupingname = \core_text::substr(get_string('groupingname', 'mod_selfselectadvanced', $activity->name()), 0, 254);
            $grouping = groups_get_grouping_by_name($activity->courseid(), $groupingname);
            if (!$grouping) {
                $grouping = groups_create_grouping((object) [
                    'courseid' => $activity->courseid(),
                    'name' => $groupingname,
                ]);
            }
            groups_assign_grouping((int) $grouping, $coregroupid);

            $now = time();
            $fresh->state = state::FROZEN;
            $fresh->coregroupid = $coregroupid;
            $fresh->timefrozen = $now;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);

            self::append_snapshot($fresh, $actorid);

            \mod_selfselectadvanced\event\group_frozen::create([
                'objectid' => $fresh->id,
                'context' => $activity->context(),
                'other' => ['pluginuid' => $fresh->pluginuid, 'coregroupid' => $coregroupid],
            ])->trigger();

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        foreach ($confirmed as $userid) {
            notifier::send(
                $activity,
                'groupfrozen',
                (int) $userid,
                'msgfrozensubject',
                'msgfrozenbody',
                (object) ['group' => format_string($fresh->name)],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => $fresh->id,
                ]),
                format_string($fresh->name)
            );
        }

        return $fresh;
    }

    /**
     * T6: unfreeze (manager action): delete the owned core group,
     * restore the roster to the LATEST snapshot exactly (A6; only
     * out-of-band core edits are discarded, and they are reported as
     * drift), state back to firm - even if current limits would now
     * reject the roster (grandfathering, spec 4A.8).
     *
     * @param activity $activity the activity
     * @param stdClass $group the frozen group row
     * @param int $actorid the acting manager
     * @return stdClass the updated group row with ->drift attached
     * @throws \moodle_exception when the group is not frozen
     */
    public static function unfreeze(activity $activity, stdClass $group, int $actorid): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($activity, (int) $group->id);
            if ($fresh->state !== state::FROZEN) {
                throw new \moodle_exception('refusalwrongstate', 'mod_selfselectadvanced');
            }

            $snapshot = self::latest_snapshot((int) $fresh->id);
            if (!$snapshot) {
                throw new \moodle_exception('errnosnapshot', 'mod_selfselectadvanced');
            }
            $roster = json_decode($snapshot->roster, true) ?: [];
            $drift = self::drift($fresh);

            // Delete only the core group this plugin created (14.5).
            if (!empty($fresh->coregroupid) && groups_group_exists((int) $fresh->coregroupid)) {
                groups_delete_group((int) $fresh->coregroupid);
            }

            // Restore the plugin roster to the snapshot exactly.
            $now = time();
            $snapshotids = [];
            $leaderid = (int) $fresh->leaderid;
            foreach ($roster as $entry) {
                $snapshotids[] = (int) $entry['userid'];
                if (!empty($entry['isleader'])) {
                    $leaderid = (int) $entry['userid'];
                }
            }
            $currentconfirmed = $DB->get_fieldset_select(
                'selfselectadvanced_member',
                'userid',
                'groupid = ? AND status = ?',
                [$fresh->id, groups::STATUS_CONFIRMED]
            );
            foreach (array_diff(array_map('intval', $currentconfirmed), $snapshotids) as $userid) {
                $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
                    'groupid' => $fresh->id,
                    'userid' => $userid,
                ]);
            }
            foreach ($roster as $entry) {
                $userid = (int) $entry['userid'];
                $existing = $DB->get_record('selfselectadvanced_member', [
                    'groupid' => $fresh->id,
                    'userid' => $userid,
                ]);
                if ($existing) {
                    $existing->status = groups::STATUS_CONFIRMED;
                    $existing->isleader = (int) !empty($entry['isleader']);
                    $existing->timemodified = $now;
                    $DB->update_record('selfselectadvanced_member', $existing);
                } else {
                    $DB->insert_record('selfselectadvanced_member', (object) [
                        'groupid' => $fresh->id,
                        'userid' => $userid,
                        'status' => groups::STATUS_CONFIRMED,
                        'isleader' => (int) !empty($entry['isleader']),
                        'invitedby' => $actorid,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }

            $fresh->state = state::FIRM;
            $fresh->leaderid = $leaderid;
            $fresh->coregroupid = null;
            $fresh->timefrozen = null;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);

            \mod_selfselectadvanced\event\group_unfrozen::create([
                'objectid' => $fresh->id,
                'context' => $activity->context(),
                'other' => ['pluginuid' => $fresh->pluginuid, 'drift' => $drift],
            ])->trigger();

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        // Members AND the guide (db/messages.php documents both).
        $recipients = $snapshotids;
        if (!empty($fresh->guideid) && !in_array((int) $fresh->guideid, $recipients, true)) {
            $recipients[] = (int) $fresh->guideid;
        }
        foreach ($recipients as $userid) {
            notifier::send(
                $activity,
                'groupunfrozen',
                $userid,
                'msgunfrozensubject',
                'msgunfrozenbody',
                (object) ['group' => format_string($fresh->name)],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => $fresh->id,
                ]),
                format_string($fresh->name)
            );
        }

        $fresh->drift = $drift;

        return $fresh;
    }

    /**
     * Course modules and sections whose availability references the
     * mirrored core group (the spec-12 unfreeze warning).
     *
     * @param activity $activity the activity
     * @param stdClass $group the frozen group row
     * @return string[] human-readable references
     */
    public static function check_restrictions(activity $activity, stdClass $group): array {
        global $DB;

        if (empty($group->coregroupid)) {
            return [];
        }
        $needle = '"type":"group","id":' . (int) $group->coregroupid;
        $references = [];
        $cms = $DB->get_records_select(
            'course_modules',
            'course = ? AND ' . $DB->sql_like('availability', '?'),
            [$activity->courseid(), '%' . $DB->sql_like_escape($needle) . '%']
        );
        foreach ($cms as $cm) {
            $modinfo = get_fast_modinfo($activity->courseid());
            $references[] = get_string(
                'restrictionreferencecm',
                'mod_selfselectadvanced',
                $modinfo->cms[$cm->id]->name ?? $cm->id
            );
        }
        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND ' . $DB->sql_like('availability', '?'),
            [$activity->courseid(), '%' . $DB->sql_like_escape($needle) . '%']
        );
        foreach ($sections as $section) {
            $references[] = get_string('restrictionreferencesection', 'mod_selfselectadvanced', $section->section);
        }

        return $references;
    }

    /**
     * Membership drift between the latest snapshot and the live core
     * group: reported, never silently overwritten (spec 14.5).
     *
     * @param stdClass $group the frozen group row
     * @return array{extra: int[], missing: int[]} userids added/removed out of band
     */
    public static function drift(stdClass $group): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $snapshot = self::latest_snapshot((int) $group->id);
        if (!$snapshot || empty($group->coregroupid) || !groups_group_exists((int) $group->coregroupid)) {
            return ['extra' => [], 'missing' => []];
        }
        $expected = array_map(
            static fn($entry) => (int) $entry['userid'],
            json_decode($snapshot->roster, true) ?: []
        );
        $actual = array_map('intval', array_keys(groups_get_members((int) $group->coregroupid, 'u.id')));

        return [
            'extra' => array_values(array_diff($actual, $expected)),
            'missing' => array_values(array_diff($expected, $actual)),
        ];
    }

    /**
     * The newest snapshot of a group.
     *
     * @param int $groupid the group
     * @return stdClass|null
     */
    public static function latest_snapshot(int $groupid): ?stdClass {
        global $DB;

        $rows = $DB->get_records('selfselectadvanced_snapshot', ['groupid' => $groupid], 'id DESC', '*', 0, 1);

        return $rows ? reset($rows) : null;
    }

    /**
     * Append a roster snapshot for a group (A6, append-only history;
     * the newest row is what unfreeze restores).
     *
     * @param stdClass $group the group row
     * @param int $actorid the acting user
     * @return stdClass the snapshot row
     */
    public static function append_snapshot(stdClass $group, int $actorid): stdClass {
        global $DB;

        $roster = [];
        foreach (
            $DB->get_records('selfselectadvanced_member', [
                'groupid' => $group->id,
                'status' => groups::STATUS_CONFIRMED,
            ], 'id ASC', 'userid, isleader') as $member
        ) {
            $roster[] = ['userid' => (int) $member->userid, 'isleader' => (int) $member->isleader];
        }

        $snapshot = (object) [
            'groupid' => (int) $group->id,
            'coregroupid' => (int) ($group->coregroupid ?? 0),
            'roster' => json_encode($roster),
            'takenby' => $actorid,
            'timecreated' => time(),
        ];
        $snapshot->id = $DB->insert_record('selfselectadvanced_snapshot', $snapshot);

        return $snapshot;
    }
}
