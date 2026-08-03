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
use mod_selfselectadvanced\local\locks;
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
     * A firm group of two with an assigned guide, and the member of
     * staff who releases it.
     *
     * THE STAFF ACTOR IS PART OF THE FIXTURE, not decoration (audit
     * A-2). Every unfreeze below used to be driven with the literal
     * `99` - an id belonging to no user row at all. freeze::unfreeze()
     * is documented as a manager action and its parameter is named "the
     * acting manager", so an id that is NOBODY could never have proved
     * anything about that: has_capability() of a non-existent user is
     * false for every capability, which means the one thing those tests
     * could not detect was a missing capability check. They did not
     * detect it, and the service had none.
     *
     * A real, enrolled editing teacher holds :unfreeze by archetype, so
     * the tests that use it assert the same behaviour they always did -
     * a manager releases a frozen team - while now being capable of
     * failing if that authority stops being asked for.
     *
     * @param array $settings instance overrides
     * @return array [activity, api, group, students[], guide, staff]
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
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

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

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $students, $guide, $staff];
    }

    /**
     * T5: freezing creates the named core group with all confirmed
     * members, assigns the activity grouping, snapshots the roster and
     * locks the state; a second group reuses the grouping.
     */
    public function test_freeze_creates_core_group(): void {
        global $DB;
        // The preventResetByRollback() below keeps this test's
        // core-group rows out of the harness's own transaction, so what
        // it reads back is what a live site would hold. It is NO LONGER what makes
        // the mirror run: sync_core_group() lost its
        // is_transaction_started() branch in 1.20 (requirement 6) and
        // now does the same work with a transaction open or not -
        // measured on both engines, and pinned by coresync_test's
        // test_sync_does_the_same_work_inside_and_outside_a_transaction.
        // Forgetting it can no longer make a mirror assertion pass
        // vacuously on PostgreSQL.
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide] = $this->setup_firm();
        // An INVITED member holds a seat but is not confirmed, so the
        // mirror must not carry them - co-asserted here rather than in
        // setup_firm(), which the move tests below build on.
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[2]->id,
            'status' => groups::STATUS_INVITED,
        ]);

        $sink = $this->redirectEvents();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_selfselectadvanced\event\group_frozen);
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame(state::FROZEN, $frozen->state);
        $this->assertNotEmpty($frozen->coregroupid);
        $this->assertNotEmpty($frozen->timefrozen);

        // Core group named "[idnumber] name" holding the confirmed
        // members AND the assigned guide (decision 7), never the
        // invited seat-holder.
        $core = groups_get_group((int) $frozen->coregroupid);
        $this->assertSame('[SSAFRZ] Icy', $core->name);
        $this->assertSame($frozen->pluginuid, $core->idnumber);
        $members = array_map('intval', array_keys(groups_get_members((int) $frozen->coregroupid, 'u.id')));
        $this->assertEqualsCanonicalizing(
            [(int) $students[0]->id, (int) $students[1]->id, (int) $guide->id],
            $members
        );
        $this->assertNotContains((int) $students[2]->id, $members);

        // Every membership this plugin wrote is tagged, so core knows
        // whose row it is and the removal callback can defend it.
        foreach ($DB->get_records('groups_members', ['groupid' => (int) $frozen->coregroupid]) as $memberrow) {
            $this->assertSame('mod_selfselectadvanced', $memberrow->component);
            $this->assertSame((int) $frozen->id, (int) $memberrow->itemid);
        }

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
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $api, $group, $students, $guide, $staff] = $this->setup_firm();
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

        // Unfreeze: moved member kept (A6); the course group and its id
        // are RETAINED (D7-D1), and the stranger stays because it is
        // not this plugin's row to delete.
        $coregroupid = (int) $frozen->coregroupid;
        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $staff->id);
        $this->assertSame(state::FIRM, $restored->state);
        $this->assertSame($coregroupid, (int) $restored->coregroupid);
        $this->assertTrue(groups_group_exists($coregroupid));
        $this->assertTrue(groups_is_member($coregroupid, (int) $stranger->id));
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
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $api, $group, $students, $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $leaving = (int) $students[1]->id;

        // Both members - and the guide (decision 7) - are in the course
        // group to begin with.
        $before = array_map('intval', array_keys(groups_get_members((int) $frozen->coregroupid, 'u.id')));
        $this->assertContains($leaving, $before);
        $this->assertContains((int) $guide->id, $before);
        $this->assertCount(3, $before);

        // A second team receives them.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        // The generator gives the group its leader's membership row.
        $target = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'Receiving',
            'state' => state::FORMING,
        ]);

        $move = $api->moves()->stage($leaving, (int) $frozen->id, (int) $target->id, false, null, 99);
        $api->moves()->commit_set([(int) $move->id], 99);

        // Gone from the course group, and from the newest snapshot, so
        // an unfreeze restores the roster as it now stands.
        $after = array_map('intval', array_keys(groups_get_members((int) $frozen->coregroupid, 'u.id')));
        $this->assertNotContains($leaving, $after);
        $this->assertEqualsCanonicalizing([(int) $students[0]->id, (int) $guide->id], $after);
        // The row the sync wrote carries this plugin's ownership tag.
        $tagged = $DB->get_record('groups_members', [
            'groupid' => (int) $frozen->coregroupid,
            'userid' => (int) $students[0]->id,
        ]);
        $this->assertSame('mod_selfselectadvanced', $tagged->component);
        $this->assertSame((int) $frozen->id, (int) $tagged->itemid);
        $snapshot = json_decode(freeze::latest_snapshot((int) $frozen->id)->roster, true);
        $this->assertCount(1, $snapshot);
        $this->assertNotContains($leaving, array_map(
            static fn(array $entry) => (int) $entry['userid'],
            $snapshot
        ));
        $this->assertSame(1, groups::count_confirmed((int) $frozen->id));
    }

    /**
     * A REPEAT FREEZE IS A REPAIR: it announces nothing a second time.
     *
     * freeze_group()'s docblock has said since 1.19 that re-freezing an
     * already frozen group is "no gates, no state flip, just the sync",
     * and $isrepair correctly skipped the gates, the state flip, the
     * snapshot and request_sync - but the trailing block ran anyway,
     * outside the try. Measured identically on both engines: 'repeat
     * freeze threw=NULL msgs 4->8 group_frozen 1->2'. Two staff
     * clicking Freeze produce it through the lock, and
     * task\bulkfreeze_adhoc re-runs freeze_group() on queued overflow.
     *
     * The sync must still run on the second call - that is what a
     * repair IS - so this asserts the mirror is intact afterwards as
     * well as that nobody was told twice.
     */
    public function test_a_repeat_freeze_adds_to_no_sink_twice(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide] = $this->setup_firm();

        $msgsink = $this->redirectMessages();
        $eventsink = $this->redirectEvents();

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $firstmessages = count($msgsink->get_messages());
        $firstfrozen = count(array_filter(
            $eventsink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\group_frozen
        ));
        $this->assertGreaterThan(0, $firstmessages, 'the first freeze told nobody');
        $this->assertSame(1, $firstfrozen);

        // The repair call, byte for byte the same call.
        $repaired = freeze::freeze_group(
            $activity,
            groups::get($activity, (int) $frozen->id),
            (int) $guide->id
        );

        $this->assertCount(
            $firstmessages,
            $msgsink->get_messages(),
            'a repeat freeze re-mailed the confirmed members'
        );
        $this->assertSame(
            1,
            count(array_filter(
                $eventsink->get_events(),
                static fn($e) => $e instanceof \mod_selfselectadvanced\event\group_frozen
            )),
            'a repeat freeze re-fired group_frozen'
        );
        $msgsink->close();
        $eventsink->close();

        // The repair still did its one job.
        $this->assertSame((int) $frozen->coregroupid, (int) $repaired->coregroupid);
        $this->assertTrue(groups_group_exists((int) $repaired->coregroupid));
        $this->assertSame(
            3,
            count(groups_get_members((int) $repaired->coregroupid, 'u.id')),
            'the repair sync did not run'
        );
    }

    /**
     * Externally-deleted core groups are recreated by re-freezing; the
     * restriction check lists referencing activities before unfreeze;
     * restore is grandfathered past tightened limits (4A.8).
     */
    public function test_repair_restrictions_and_grandfather(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide, $staff] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $oldcoreid = (int) $frozen->coregroupid;

        // External deletion, then repair.
        groups_delete_group($oldcoreid);
        $this->assertFalse(groups_group_exists($oldcoreid));
        $repaired = freeze::freeze_group($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $this->assertTrue(groups_group_exists((int) $repaired->coregroupid));
        $this->assertNotEquals($oldcoreid, (int) $repaired->coregroupid);
        // Two confirmed members plus the guide (decision 7).
        $this->assertSame(
            3,
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
            (int) $staff->id
        );
        $this->assertSame(2, groups::count_confirmed((int) $restored->id));
        $this->assertSame(state::FIRM, $restored->state);
    }

    /**
     * D7-D1: unfreezing KEEPS the course group and its id, so
     * availability conditions, grouping links, calendar events and the
     * group conversation all survive the release.
     */
    public function test_unfreeze_keeps_core_group(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide, $staff] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $this->assertTrue(groups_group_exists($coreid));

        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $staff->id);

        $this->assertSame(state::FIRM, $restored->state);
        $this->assertSame($coreid, (int) $restored->coregroupid);
        $this->assertTrue(groups_group_exists($coreid));
    }

    /**
     * The maintainer's freeze -> change -> refreeze workflow: the same
     * course group id comes back, holding the NEW composition.
     */
    public function test_refreeze_reuses_core_group_and_reflects_changes(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $api, $group, $students, $guide, $staff] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;

        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $staff->id);
        $this->assertSame($coreid, (int) $restored->coregroupid);

        // Swap students[1] out for the still-groupless students[2]
        // while the team is firm.
        $sparelead = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($sparelead->id, $activity->courseid(), 'student');
        $spare = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $sparelead->id,
            'name' => 'Spare',
            'state' => state::FORMING,
        ]);
        $out = $api->moves()->stage((int) $students[1]->id, (int) $restored->id, (int) $spare->id, false, null, 99);
        $api->moves()->commit_set([(int) $out->id], 99);
        $in = $api->moves()->stage((int) $students[2]->id, null, (int) $restored->id, false, null, 99);
        $api->moves()->commit_set([(int) $in->id], 99);

        $refrozen = freeze::freeze_group($activity, groups::get($activity, (int) $restored->id), (int) $guide->id);

        $this->assertSame($coreid, (int) $refrozen->coregroupid, 'the mirror id was not reused');
        $this->assertEqualsCanonicalizing(
            [(int) $students[0]->id, (int) $students[2]->id, (int) $guide->id],
            array_map('intval', array_keys(groups_get_members($coreid, 'u.id')))
        );
    }

    /**
     * D7-C2/B1: on a healthy frozen team drift() is empty in every
     * direction - in particular the guide, who IS expected in the
     * mirror, can no longer be reported as an out-of-band extra.
     */
    public function test_drift_empty_for_healthy_frozen_group(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);

        $drift = freeze::drift(groups::get($activity, (int) $frozen->id));

        $this->assertSame([], $drift['extra']);
        $this->assertSame([], $drift['missing']);
        $this->assertSame([], $drift['repairable']);
        $this->assertTrue(groups_is_member((int) $frozen->coregroupid, (int) $guide->id));
    }

    /**
     * 14.5: a row this plugin did not write is reported and left
     * exactly where it is, however many times the mirror is synced.
     */
    public function test_stranger_reported_never_removed(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;

        $stranger = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($stranger->id, $activity->courseid(), 'student');
        groups_add_member($coreid, (int) $stranger->id);

        $sync = freeze::sync_core_group($activity, (int) $frozen->id, (int) $guide->id);

        $this->assertSame('synced', $sync->status);
        $this->assertSame([(int) $stranger->id], $sync->extra);
        $this->assertSame([], $sync->removed);
        $this->assertTrue(groups_is_member($coreid, (int) $stranger->id));
        $this->assertSame(
            [(int) $stranger->id],
            freeze::drift(groups::get($activity, (int) $frozen->id))['extra']
        );
    }

    /**
     * A member removed from the mirror out of band is put back, and the
     * repair is recorded as an event.
     */
    public function test_owned_member_missing_is_repaired(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $DB->delete_records('groups_members', ['groupid' => $coreid, 'userid' => (int) $students[1]->id]);
        $this->assertFalse(groups_is_member($coreid, (int) $students[1]->id));

        $sink = $this->redirectEvents();
        $sync = freeze::sync_core_group($activity, (int) $frozen->id, (int) $guide->id);
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\coregroup_synced
        ));
        $sink->close();

        $this->assertSame([(int) $students[1]->id], $sync->added);
        $this->assertTrue(groups_is_member($coreid, (int) $students[1]->id));
        $this->assertCount(1, $events);
        $this->assertSame(1, (int) $events[0]->other['added']);
    }

    /**
     * The guide belongs in the MIRROR, never in the plugin roster: the
     * snapshot unfreeze replays is restored as CONFIRMED members, so a
     * guide who leaked into it would silently become a student.
     */
    public function test_unfreeze_never_inserts_guide_into_member_table(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide, $staff] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);

        $snapshot = json_decode(freeze::latest_snapshot((int) $frozen->id)->roster, true);
        $this->assertNotContains(
            (int) $guide->id,
            array_map(static fn(array $entry) => (int) $entry['userid'], $snapshot)
        );

        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $staff->id);

        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $restored->id,
            'userid' => $guide->id,
        ]));
    }

    /**
     * The routine is diff-based: a second call in a row changes
     * nothing and reports nothing.
     */
    public function test_sync_is_idempotent(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $before = array_map('intval', array_keys(groups_get_members($coreid, 'u.id')));

        $first = freeze::sync_core_group($activity, (int) $frozen->id, (int) $guide->id);
        $second = freeze::sync_core_group($activity, (int) $frozen->id, (int) $guide->id);

        foreach ([$first, $second] as $sync) {
            $this->assertSame([], $sync->added);
            $this->assertSame([], $sync->removed);
            $this->assertSame([], $sync->refused);
        }
        $this->assertEqualsCanonicalizing(
            $before,
            array_map('intval', array_keys(groups_get_members($coreid, 'u.id')))
        );
    }

    /**
     * D7-E2: core refuses to put a non-enrolled user in a course group
     * and says so by RETURNING FALSE. The refusal is collected, told to
     * every manager, and never mistaken for drift.
     */
    public function test_sync_refuses_unenrolled_member_visibly(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide] = $this->setup_firm();
        // The enrolment ROWS go, not the enrolment EVENT: the event has
        // its own observer and its own tests, and what this test is
        // about is the state core refuses to write - a roster that
        // still says confirmed for somebody the course no longer
        // enrols (a stale roster after a manual database fix, a failed
        // enrolment sync, an unenrolment that predates the observer).
        $this->strip_enrolment_rows($activity->courseid(), (int) $students[1]->id);
        // Somebody has to be told; the capaudit notice goes to every
        // holder of mod/selfselectadvanced:manage.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');

        $sink = $this->redirectMessages();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertSame([(int) $students[1]->id], $frozen->sync->refused);
        $this->assertFalse(groups_is_member((int) $frozen->coregroupid, (int) $students[1]->id));
        $subjects = array_map(static fn($m) => $m->subject, $messages);
        $this->assertNotEmpty(array_filter(
            $subjects,
            static fn($subject) => str_contains((string) $subject, 'would not take every member')
        ), 'no manager was told about the refusal');
        // The gap is a refusal, not an unowned row.
        $this->assertSame([], freeze::drift(groups::get($activity, (int) $frozen->id))['extra']);
    }

    /**
     * The same refusal for a guide who holds the capability through a
     * course-category role and has no enrolment of their own.
     */
    public function test_guide_without_enrolment_refused(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide] = $this->setup_firm();
        $this->unenrol($activity->courseid(), (int) $guide->id);
        // The capability now comes from a category-level role instead.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $activity->courseid()], '*', MUST_EXIST);
        role_assign($roleid, (int) $guide->id, \context_coursecat::instance((int) $course->category)->id);

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);

        $this->assertSame([(int) $guide->id], $frozen->sync->refused);
        $this->assertFalse(groups_is_member((int) $frozen->coregroupid, (int) $guide->id));
    }

    /**
     * 1h: discard is the only interactive deletion, and it refuses to
     * run while the team is frozen - the next sync would mint the
     * mirror straight back.
     */
    public function test_discard_core_group(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide, $staff] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;

        // Frozen: refused.
        try {
            freeze::discard_core_group($activity, groups::get($activity, (int) $frozen->id), 99);
            $this->fail('Expected refusaldiscardfrozen');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cannot be discarded while the team is frozen', $e->getMessage());
        }
        $this->assertTrue(groups_group_exists($coreid));

        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $staff->id);

        $sink = $this->redirectEvents();
        $result = freeze::discard_core_group($activity, groups::get($activity, (int) $restored->id), 99);
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\coregroup_discarded
        ));
        $sink->close();

        $this->assertSame($coreid, (int) $result->oldcoregroupid);
        $this->assertFalse(groups_group_exists($coreid));
        $this->assertNull(groups::get($activity, (int) $restored->id)->coregroupid);
        $this->assertCount(1, $events);

        // Nothing left to discard.
        $this->expectException(\moodle_exception::class);
        freeze::discard_core_group($activity, groups::get($activity, (int) $restored->id), 99);
    }

    /**
     * Requirement 2: the group_frozen event and every notification this
     * freeze sends happen with NO plugin lock held.
     *
     * The three grandfathered events (move_committed,
     * leadership_transferred, join_decided) are exempt by maintainer
     * decision (T-02 invariant item 3); group_frozen is a relocation
     * this ticket performs, so it is asserted at its NEW site.
     *
     * The assertion is on locks::held_count(), never on
     * $DB->is_transaction_started(): advanced_testcase opens a
     * transaction before every test on PostgreSQL, so that clause would
     * be unsatisfiable on one engine and green on the other.
     */
    public function test_freeze_notifications_and_events_fire_outside_envelope(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide] = $this->setup_firm();

        self::$lockdepths = [];
        \core\event\manager::phpunit_replace_observers([
            [
                'eventname' => '\mod_selfselectadvanced\event\group_frozen',
                'callback' => 'mod_selfselectadvanced\freeze_test::record_lock_depth',
            ],
            [
                'eventname' => '\core\event\notification_sent',
                'callback' => 'mod_selfselectadvanced\freeze_test::record_lock_depth',
            ],
        ]);

        freeze::freeze_group($activity, $group, (int) $guide->id);

        $this->assertNotEmpty(self::$lockdepths, 'neither the event nor a notification was observed');
        foreach (self::$lockdepths as $where => $depths) {
            foreach ($depths as $depth) {
                $this->assertSame(0, $depth, $where . ' fired while a plugin lock was held');
            }
        }
        $this->assertArrayHasKey('\mod_selfselectadvanced\event\group_frozen', self::$lockdepths);
    }

    /**
     * The crash window: when the inline sync cannot run, the plugin
     * state flip has still committed on its own AND the adhoc that
     * repairs the mirror is queued with it.
     *
     * The interleaving is concrete: locks::set_test_hook() throws at
     * the sync's mint acquire - the second group:{id} acquire of the
     * request - which is exactly the failure a crash in that window
     * looks like from the plugin's side.
     */
    public function test_push_exception_rolls_back_and_next_iteration_survives(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, , $group, , $guide] = $this->setup_firm();
        $groupid = (int) $group->id;
        $seen = 0;
        locks::set_test_hook(function (string $resource) use (&$seen, $groupid): void {
            if ($resource !== 'group:' . $groupid) {
                return;
            }
            $seen++;
            if ($seen === 2) {
                throw new \moodle_exception('errlocktimeout', 'mod_selfselectadvanced');
            }
        });
        try {
            freeze::freeze_group($activity, $group, (int) $guide->id);
        } finally {
            locks::set_test_hook(null);
        }
        $this->assertDebuggingCalled();

        $row = $DB->get_record('selfselectadvanced_group', ['id' => $groupid], '*', MUST_EXIST);
        $this->assertSame(state::FROZEN, $row->state, 'the state flip did not commit on its own');
        $this->assertEmpty($row->coregroupid);
        $this->assertNotEmpty(
            \core\task\manager::get_adhoc_tasks(\mod_selfselectadvanced\task\coresync_adhoc::class),
            'no repair job was queued with the commit'
        );
    }

    /** @var array<string, int[]> Lock depths recorded per event name. */
    public static array $lockdepths = [];

    /**
     * Test observer: record how many plugin locks were held when an
     * event fired.
     *
     * @param \core\event\base $event the observed event
     */
    public static function record_lock_depth(\core\event\base $event): void {
        self::$lockdepths[$event->eventname][] = locks::held_count();
    }

    /**
     * Remove every enrolment a user has in a course, through the
     * enrolment API (so core's own unenrolment side effects run).
     *
     * @param int $courseid the course
     * @param int $userid the user
     */
    private function unenrol(int $courseid, int $userid): void {
        global $DB;

        foreach (enrol_get_instances($courseid, true) as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            $enrolled = $plugin && $DB->record_exists('user_enrolments', [
                'enrolid' => $instance->id,
                'userid' => $userid,
            ]);
            if ($enrolled) {
                $plugin->unenrol_user($instance, $userid);
            }
        }
    }

    /**
     * Delete a user's enrolment rows WITHOUT firing the unenrolment
     * event, leaving the plugin roster exactly as it was.
     *
     * @param int $courseid the course
     * @param int $userid the user
     */
    private function strip_enrolment_rows(int $courseid, int $userid): void {
        global $DB;

        $enrolids = $DB->get_fieldset_select('enrol', 'id', 'courseid = ?', [$courseid]);
        if (!$enrolids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($enrolids, SQL_PARAMS_NAMED, 'en');
        $params['userid'] = $userid;
        $DB->delete_records_select('user_enrolments', "enrolid $insql AND userid = :userid", $params);
    }

    /**
     * D6-9: unfreeze was the one staff roster rewrite with no
     * per-member record at all - the event carried two integers and
     * nothing else. It now names every member row the restore touched,
     * and why.
     */
    public function test_unfreeze_event_lists_added_removed(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide, $staff] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);

        // Mutate the plugin roster out of band: one member out, one in.
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
            'groupid' => (int) $frozen->id,
            'userid' => (int) $students[1]->id,
        ]);
        $DB->insert_record('selfselectadvanced_member', (object) [
            'groupid' => (int) $frozen->id,
            'userid' => (int) $students[2]->id,
            'status' => groups::STATUS_CONFIRMED,
            'isleader' => 0,
            'invitedby' => (int) $guide->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $sink = $this->redirectEvents();
        freeze::unfreeze(
            $activity,
            groups::get($activity, (int) $frozen->id),
            (int) $staff->id,
            'Composition change agreed with the guide'
        );
        $unfrozen = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\group_unfrozen
        ));
        $sink->close();

        $this->assertCount(1, $unfrozen);
        $other = $unfrozen[0]->other;
        $this->assertSame([(int) $students[2]->id], $other['removed']);
        $this->assertSame([(int) $students[1]->id], $other['added']);
        $this->assertSame('Composition change agreed with the guide', $other['reason']);
    }

    /**
     * The reason gate is keyed on the RESTORE DELTA, and the page's
     * preview computes exactly the quantity the service enforces.
     *
     * Deliberately not drift(): that is the core-MIRROR health report
     * and is normally zero on a healthy frozen team, so keying on it
     * would let the delta case through with no reason and then throw
     * from inside the lock.
     */
    public function test_unfreeze_reason_required_only_on_restore_delta(): void {
        global $DB;
        $this->resetAfterTest();
        // THE preventResetByRollback() BELOW MUST COME FIRST (1.20
        // wave 3E): the refusals driven here leave services that now
        // roll their own delegated frame back UNCONDITIONALLY, and this
        // test carries on committing afterwards. On PostgreSQL
        // advanced_testcase holds a transaction underneath for the
        // whole test, so that rollback is not the top level: it pops,
        // leaves force_rollback set, and the next allow_commit() raises
        // "Tried to commit transaction after lower level rollback". In
        // production nothing is underneath, the rollback empties the
        // stack and force_rollback is cleared - which is the cascade
        // the fix restores.
        $this->preventResetByRollback();

        [$activity, , $group, $students, $guide] = $this->setup_firm();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);

        // No delta at all: the ordinary guide-release flow still needs
        // no reason.
        $preview = freeze::unfreeze_preview($activity, groups::get($activity, (int) $frozen->id));
        $this->assertSame([], $preview['removed']);
        $this->assertSame([], $preview['added']);
        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $this->assertSame(state::FIRM, $restored->state);

        // Now with a delta, and no reason.
        $refrozen = freeze::freeze_group($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $DB->insert_record('selfselectadvanced_member', (object) [
            'groupid' => (int) $refrozen->id,
            'userid' => (int) $students[2]->id,
            'status' => groups::STATUS_CONFIRMED,
            'isleader' => 0,
            'invitedby' => (int) $guide->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $preview = freeze::unfreeze_preview($activity, groups::get($activity, (int) $refrozen->id));
        $this->assertSame([(int) $students[2]->id], $preview['removed']);
        $this->assertSame([], $preview['added']);

        try {
            freeze::unfreeze($activity, groups::get($activity, (int) $refrozen->id), (int) $guide->id);
            $this->fail('Expected errunfreezereasonrequired');
        } catch (\moodle_exception $e) {
            $this->assertSame('errunfreezereasonrequired', $e->errorcode);
        }
        // Refused, and nothing was written: still frozen, still there.
        $this->assertSame(state::FROZEN, groups::get($activity, (int) $refrozen->id)->state);
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $refrozen->id,
            'userid' => (int) $students[2]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]));

        // The SAME two lists the service enforced.
        $done = freeze::unfreeze(
            $activity,
            groups::get($activity, (int) $refrozen->id),
            (int) $guide->id,
            'Reverting an out-of-band change'
        );
        $this->assertSame(state::FIRM, $done->state);
        $this->assertSame(groups::STATUS_REMOVED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => (int) $refrozen->id,
            'userid' => (int) $students[2]->id,
        ]));
    }

    /**
     * Grandfathering is untouched (decision 4A.8): a snapshot roster
     * beyond the current effective maxsize restores WITHOUT refusal.
     * Nothing here refuses on a LIMIT - only on a missing reason.
     */
    public function test_unfreeze_grandfathering_regression(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $group, $students, $guide] = $this->setup_firm(['maxsize' => 3]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_member([
            'groupid' => (int) $group->id,
            'userid' => (int) $students[2]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $frozen = freeze::freeze_group($activity, groups::get($activity, (int) $group->id), (int) $guide->id);
        $this->assertSame(3, groups::count_confirmed((int) $frozen->id));

        // Take one out of band, and cut the limit below the snapshot.
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
            'groupid' => (int) $frozen->id,
            'userid' => (int) $students[2]->id,
        ]);
        store::save($activity, 'group', (int) $frozen->id, ['maxsize' => 1], (int) $guide->id);

        $restored = freeze::unfreeze(
            $activity,
            groups::get($activity, (int) $frozen->id),
            (int) $guide->id,
            'Restoring the approved roster'
        );
        $this->assertSame(state::FIRM, $restored->state);
        // Three members back, over a maxsize of one: grandfathered.
        $this->assertSame(3, groups::count_confirmed((int) $frozen->id));
    }
}
