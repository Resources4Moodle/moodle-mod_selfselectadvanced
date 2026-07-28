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

namespace mod_selfselectadvanced;

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;

/**
 * Freeze/unfreeze and core-group sync (spec 12, T5/T6, A6, 14.5):
 * creation and grouping, snapshot-exact restore, staged moves on
 * frozen groups refreshing snapshots, drift detection and discard,
 * external-deletion repair, restriction warnings and grandfathering.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\freeze
 */
final class freeze_test extends \advanced_testcase {
    /**
     * A firm group of two with an assigned guide.
     *
     * @param array $settings instance overrides
     * @return array [activity, api, group, students[], guide]
     */
    private function setup_firm(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'FRZ1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], $settings), ['idnumber' => 'SSAFRZ']);

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Icy',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $students, $guide];
    }

    /**
     * T5: freezing creates the named core group with all confirmed
     * members, assigns the activity grouping, snapshots the roster and
     * locks the state; a second group reuses the grouping.
     */
    public function test_freeze_creates_core_group(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide] = $this->setup_firm();

        $sink = $this->redirectEvents();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_selfselectadvanced\event\group_frozen);
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame(state::FROZEN, $frozen->state);
        $this->assertNotEmpty($frozen->coregroupid);
        $this->assertNotEmpty($frozen->timefrozen);

        // Core group named "[idnumber] name" with exactly the confirmed members.
        $core = groups_get_group((int) $frozen->coregroupid);
        $this->assertSame('[SSAFRZ] Icy', $core->name);
        $members = array_keys(groups_get_members((int) $frozen->coregroupid, 'u.id'));
        $this->assertEqualsCanonicalizing([(int) $students[0]->id, (int) $students[1]->id], array_map('intval', $members));

        // Grouping created and assigned; reused by a second freeze.
        $groupingname = get_string('groupingname', 'mod_selfselectadvanced', $activity->name());
        $grouping = groups_get_grouping_by_name($activity->courseid(), $groupingname);
        $this->assertNotEmpty($grouping);
        $this->assertTrue($DB->record_exists('groupings_groups', [
            'groupingid' => $grouping,
            'groupid' => $frozen->coregroupid,
        ]));

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $second = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'Icy2',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $frozen2 = freeze::freeze_group($activity, groups::get($activity, (int) $second->id), (int) $guide->id);
        $this->assertSame($grouping, groups_get_grouping_by_name($activity->courseid(), $groupingname));
        $this->assertTrue($DB->record_exists('groupings_groups', [
            'groupingid' => $grouping,
            'groupid' => $frozen2->coregroupid,
        ]));

        // Snapshot stored with the roster.
        $snapshot = freeze::latest_snapshot((int) $frozen->id);
        $this->assertCount(2, json_decode($snapshot->roster, true));
    }

    /**
     * Freeze guards: only the assigned guide; only firm (S2); the
     * defence-in-depth L1 check.
     */
    public function test_freeze_guards(): void {
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide] = $this->setup_firm(['minsize' => 5]);

        // Below the effective minimum: defence-in-depth refusal.
        try {
            freeze::freeze_group($activity, $group, (int) $guide->id);
            $this->fail('Expected L1 refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('minimum', $e->getMessage());
        }

        // Someone other than the assigned guide.
        store::save($activity, 'group', (int) $group->id, ['minsize' => 1], 0);
        try {
            freeze::freeze_group($activity, $group, (int) $students[0]->id);
            $this->fail('Expected assigned-guide refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('assigned guide', $e->getMessage());
        }

        // Not firm (S2).
        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $group->id]);
        $fresh = groups::get($activity, (int) $group->id);
        $this->expectException(\moodle_exception::class);
        freeze::freeze_group($activity, $fresh, (int) $guide->id);
    }

    /**
     * A6: a staged move into a frozen group updates the core group AND
     * the snapshot; unfreeze then restores the MOVED roster, while
     * out-of-band core edits are discarded and reported as drift.
     */
    public function test_moves_refresh_snapshot_and_drift_discarded(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $group, $students, $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $mover = (int) $students[2]->id;

        // Manager staged move into the frozen group (L2 has room).
        $move = $api->moves()->stage($mover, null, (int) $frozen->id, false, null, 99);
        $api->moves()->commit_set([(int) $move->id], 99);

        // Core group mirrored and snapshot refreshed (3 members now).
        $this->assertContains(
            $mover,
            array_map('intval', array_keys(groups_get_members((int) $frozen->coregroupid, 'u.id')))
        );
        $snapshot = json_decode(freeze::latest_snapshot((int) $frozen->id)->roster, true);
        $this->assertCount(3, $snapshot);

        // Out-of-band drift: someone adds a stranger via the groups UI.
        $stranger = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($stranger->id, $activity->courseid(), 'student');
        groups_add_member((int) $frozen->coregroupid, (int) $stranger->id);
        $drift = freeze::drift(groups::get($activity, (int) $frozen->id));
        $this->assertSame([(int) $stranger->id], $drift['extra']);

        // Unfreeze: moved member kept (A6), stranger discarded, core gone.
        $coregroupid = (int) $frozen->coregroupid;
        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), 99);
        $this->assertSame(state::FIRM, $restored->state);
        $this->assertNull($restored->coregroupid);
        $this->assertFalse(groups_group_exists($coregroupid));
        $this->assertSame(3, groups::count_confirmed((int) $restored->id));
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $restored->id,
            'userid' => $stranger->id,
        ]));
        $this->assertSame([(int) $stranger->id], $restored->drift['extra']);
    }

    /**
     * A move OUT of a frozen group takes the member out of the course
     * group too: the mirror follows the roster in both directions, so
     * the course's own group data never drifts from the plugin's.
     */
    public function test_move_out_of_a_frozen_group_updates_the_course_group(): void {
        $this->resetAfterTest();

        [$activity, $api, $group, $students, $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $leaving = (int) $students[1]->id;

        // Both members are in the course group to begin with.
        $before = array_map('intval', array_keys(groups_get_members((int) $frozen->coregroupid, 'u.id')));
        $this->assertContains($leaving, $before);
        $this->assertCount(2, $before);

        // A second team receives them.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $target = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'Receiving',
            'state' => state::FORMING,
        ]);
        $plugingen->create_member([
            'groupid' => $target->id,
            'userid' => (int) $students[2]->id,
            'status' => groups::STATUS_CONFIRMED,
            'isleader' => 1,
        ]);

        $move = $api->moves()->stage($leaving, (int) $frozen->id, (int) $target->id, false, null, 99);
        $api->moves()->commit_set([(int) $move->id], 99);

        // Gone from the course group, and from the newest snapshot, so
        // an unfreeze restores the roster as it now stands.
        $after = array_map('intval', array_keys(groups_get_members((int) $frozen->coregroupid, 'u.id')));
        $this->assertNotContains($leaving, $after);
        $this->assertCount(1, $after);
        $snapshot = json_decode(freeze::latest_snapshot((int) $frozen->id)->roster, true);
        $this->assertCount(1, $snapshot);
        $this->assertNotContains($leaving, array_map(
            static fn(array $entry) => (int) $entry['userid'],
            $snapshot
        ));
        $this->assertSame(1, groups::count_confirmed((int) $frozen->id));
    }

    /**
     * Externally-deleted core groups are recreated by re-freezing; the
     * restriction check lists referencing activities before unfreeze;
     * restore is grandfathered past tightened limits (4A.8).
     */
    public function test_repair_restrictions_and_grandfather(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $oldcoreid = (int) $frozen->coregroupid;

        // External deletion, then repair.
        groups_delete_group($oldcoreid);
        $this->assertFalse(groups_group_exists($oldcoreid));
        $repaired = freeze::freeze_group($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $this->assertTrue(groups_group_exists((int) $repaired->coregroupid));
        $this->assertNotEquals($oldcoreid, (int) $repaired->coregroupid);
        $this->assertSame(
            2,
            count(groups_get_members((int) $repaired->coregroupid, 'u.id'))
        );

        // A page restricted by the core group is reported before unfreeze.
        $this->getDataGenerator()->create_module('page', [
            'course' => $activity->courseid(),
            'name' => 'Restricted page',
            'availability' => json_encode([
                'op' => '&',
                'c' => [['type' => 'group', 'id' => (int) $repaired->coregroupid]],
                'showc' => [true],
            ]),
        ]);
        rebuild_course_cache($activity->courseid(), true);
        $warnings = freeze::check_restrictions($activity, groups::get($activity, (int) $repaired->id));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Restricted page', $warnings[0]);

        // Grandfathering: tighten maxsize below the roster; unfreeze
        // still restores verbatim (no refusal).
        $DB->set_field('selfselectadvanced', 'maxsize', 1, ['id' => $activity->id()]);
        $restored = freeze::unfreeze(
            activity::from_instance($activity->id()),
            groups::get($activity, (int) $repaired->id),
            99
        );
        $this->assertSame(2, groups::count_confirmed((int) $restored->id));
        $this->assertSame(state::FIRM, $restored->state);
    }
}
