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
use mod_selfselectadvanced\local\moves;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;

/**
 * Staff override authority on the move engine (decision 6): the
 * participant check at the stage seam (D6-10, SECURITY), the park verb
 * (D6-2), the typed reason and its event (D6-1/D6-6), the commit cap
 * and the paged label budget (D6-8).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\moves
 */
final class moves_override_test extends \advanced_testcase {
    /**
     * Two firm groups of two, five students, a staff member holding
     * manage + override + overriderules, and tight limits.
     *
     * Copied from moves_test's shape rather than shared, deliberately:
     * that file's helper is private and its fixture is load-bearing for
     * a different set of assertions.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, api, students[], groupA, groupB, staff, course]
     */
    private function setup_two_groups(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 2,
            'maxlead' => 1,
            'maxmembership' => 1,
        ], $settings));

        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        $activity = activity::from_instance((int) $instance->id);

        $a = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'A',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => $a->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $b = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'B',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => $b->id,
            'userid' => (int) $students[3]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, new api($activity), $students, $a, $b, $staff, $course];
    }

    /**
     * Commit a set with a reason, without caring about the hand-back
     * parameters. Keeps the by-reference arguments out of every test.
     *
     * @param api $api the api
     * @param int[] $moveids the set
     * @param int $actorid the actor
     * @param string $reason the override reason
     * @return int committed count
     */
    private function commit(api $api, array $moveids, int $actorid, string $reason = ''): int {
        $notifications = null;
        $sync = null;
        $events = null;

        return $api->moves()->commit_set($moveids, $actorid, false, $notifications, $sync, $reason, $events);
    }

    /**
     * D6-10 (SECURITY): stage() validated the GROUPS but never the
     * USER, so a userid from another course could be posted straight at
     * the service and apply() would insert the member row.
     */
    public function test_stage_refuses_nonparticipant(): void {
        global $DB;
        $this->resetAfterTest();
        [, $api, , , $b, $staff] = $this->setup_two_groups(['maxsize' => 4]);

        $generator = $this->getDataGenerator();
        $othercourse = $generator->create_course();
        $outsider = $generator->create_user();
        $generator->enrol_user($outsider->id, $othercourse->id, 'student');

        try {
            $api->moves()->stage((int) $outsider->id, null, (int) $b->id, false, null, (int) $staff->id);
            $this->fail('Expected errmovenotparticipant');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovenotparticipant', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $b->id,
            'userid' => (int) $outsider->id,
        ]));
        $this->assertFalse($DB->record_exists('selfselectadvanced_move', ['userid' => (int) $outsider->id]));
    }

    /**
     * Enrolled is not enough: the picker restricts to :respond holders
     * and the seam must say the same thing.
     */
    public function test_stage_refuses_enrolled_nonrespondent(): void {
        $this->resetAfterTest();
        [, $api, , , $b, $staff, $course] = $this->setup_two_groups(['maxsize' => 4]);

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');

        try {
            $api->moves()->stage((int) $teacher->id, null, (int) $b->id, false, null, (int) $staff->id);
            $this->fail('Expected errmovenotparticipant');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovenotparticipant', $e->errorcode);
        }
    }

    /**
     * Regression: the new check must not break the case it sits next
     * to - placing a groupless participant (auto-grouping residue).
     */
    public function test_stage_accepts_groupless_participant_regression(): void {
        $this->resetAfterTest();
        [, $api, $students, , $b, $staff] = $this->setup_two_groups(['maxsize' => 4]);

        $move = $api->moves()->stage(
            (int) $students[4]->id,
            null,
            (int) $b->id,
            false,
            null,
            (int) $staff->id
        );
        $this->assertGreaterThan(0, (int) $move->id);
        $this->assertSame((int) $b->id, (int) $move->targetgroupid);
    }

    /**
     * D6-2: a park removes with no destination. Nothing is added
     * anywhere, no target-side verdict is computed, and no group:0
     * lock is taken.
     */
    public function test_park_removes_without_destination(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, , $b, $staff] = $this->setup_two_groups();
        $parked = (int) $students[3]->id;

        $before = $DB->count_records('selfselectadvanced_member', ['userid' => $parked]);
        $move = $api->moves()->stage($parked, (int) $b->id, null, false, null, (int) $staff->id);
        $this->assertNull($move->targetgroupid);

        // L1 fails on the source (2 -> 1, minimum 2); bypass it.
        store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L1'], (int) $staff->id);
        $fresh = new api($activity);
        $verdicts = $fresh->moves()->validate_set([(int) $move->id]);
        // A park's verdicts are L1, source QUOTA and L4 - never L2.
        $this->assertArrayNotHasKey('L2', $verdicts->permove[(int) $move->id]);
        $this->assertArrayHasKey('L1', $verdicts->permove[(int) $move->id]);
        $this->assertTrue($verdicts->permove[(int) $move->id]['L1']['bypassed']);

        locks::start_recording();
        $this->commit($fresh, [(int) $move->id], (int) $staff->id, 'over quota');
        $log = locks::stop_recording();

        // T-02's lock_resources_for() null-guards the target: without
        // it a park would cast NULL to 0 and take a site-wide lock
        // shared by every activity on the site.
        $this->assertNotContains('acquire group:0', $log);
        $this->assertContains('acquire group:' . (int) $b->id, $log);

        $this->assertSame(1, groups::count_confirmed((int) $b->id));
        $this->assertSame(groups::STATUS_REMOVED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $b->id,
            'userid' => $parked,
        ]));
        // No new membership row anywhere.
        $this->assertSame($before, $DB->count_records('selfselectadvanced_member', ['userid' => $parked]));
        $this->assertSame(0, groups::count_memberships($activity, $parked));
    }

    /**
     * The park verb is authorised at the seam, not by the form: a
     * manager without :overriderules cannot park.
     */
    public function test_park_requires_overriderules(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, , $b, $staff] = $this->setup_two_groups();

        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, (int) $staff->id, $activity->context()->id);
        assign_capability(
            'mod/selfselectadvanced:overriderules',
            CAP_PROHIBIT,
            $roleid,
            $activity->context()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability(
            'mod/selfselectadvanced:overriderules',
            $activity->context(),
            (int) $staff->id
        ));
        $this->assertTrue(has_capability('mod/selfselectadvanced:manage', $activity->context(), (int) $staff->id));

        try {
            $api->moves()->stage((int) $students[3]->id, (int) $b->id, null, false, null, (int) $staff->id);
            $this->fail('Expected errmoveparkcapability');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmoveparkcapability', $e->errorcode);
        }
    }

    /**
     * A park on a FROZEN source still runs the source-side mirror hook:
     * snapshot inside the transaction, core-group convergence after the
     * commit and the lock release (T-16's seam, consumed here).
     */
    public function test_park_frozen_source_syncs_core_group(): void {
        global $DB;
        // MUST be first: advanced_testcase opens a delegated
        // transaction before every test on PostgreSQL, and
        // sync_core_group() USED TO defer silently inside one, which is
        // the one-engine split this ticket set exists to prevent. That
        // branch is gone in 1.20 (requirement 6) and the routine now
        // behaves the same either way; the call is kept so this test's
        // core-group rows are ordinary committed rows.
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $api, $students, , $b, $staff] = $this->setup_two_groups(['minsize' => 1]);
        $frozen = freeze::freeze_group($activity, groups::get($activity, (int) $b->id), (int) $staff->id);
        $coregroupid = (int) $frozen->coregroupid;
        $this->assertGreaterThan(0, $coregroupid);
        $parked = (int) $students[3]->id;
        $this->assertTrue(groups_is_member($coregroupid, $parked));

        $snapshotsbefore = $DB->count_records('selfselectadvanced_snapshot', ['groupid' => (int) $b->id]);

        $move = $api->moves()->stage($parked, (int) $b->id, null, false, null, (int) $staff->id);
        $this->commit($api, [(int) $move->id], (int) $staff->id, 'left the programme');

        $this->assertFalse(groups_is_member($coregroupid, $parked));
        $this->assertSame(
            $snapshotsbefore + 1,
            $DB->count_records('selfselectadvanced_snapshot', ['groupid' => (int) $b->id])
        );
    }

    /**
     * An override commits only with a typed reason, and the reason is
     * persisted on the move row.
     */
    public function test_commit_requires_reason_when_bypassed(): void {
        global $DB;
        $this->resetAfterTest();
        // Wave 3D: a refusal now rolls its OWN delegated transaction
        // back instead of abandoning it, which sets $DB's force_rollback
        // until the transaction stack empties. This test refuses a verb
        // and then commits another one, and on PostgreSQL - and only
        // there - advanced_testcase holds a frame underneath that never
        // lets the stack empty, so the later commit would be refused on
        // one engine and not the other. Committing the harness frame
        // here is what makes the two engines agree; the same line, for
        // the same reason, as in races_locking_test.
        $this->preventResetByRollback();
        [$activity, $api, $students, , $b, $staff] = $this->setup_two_groups();

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, false, null, (int) $staff->id);
        store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L2'], (int) $staff->id);
        $fresh = new api($activity);

        try {
            $this->commit($fresh, [(int) $move->id], (int) $staff->id, '   ');
            $this->fail('Expected errmoveoverridereasonrequired');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmoveoverridereasonrequired', $e->errorcode);
        }
        $this->assertSame('pending', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $move->id]));

        $fresh2 = new api($activity);
        $this->assertSame(1, $this->commit($fresh2, [(int) $move->id], (int) $staff->id, 'Guide agreed'));
        $this->assertSame('Guide agreed', $DB->get_field('selfselectadvanced_move', 'responsenote', [
            'id' => $move->id,
        ]));
    }

    /**
     * The selection is bounded: an unbounded commit is an unbounded
     * lock hold.
     */
    public function test_commit_cap(): void {
        $this->resetAfterTest();
        [, $api, , , , $staff] = $this->setup_two_groups();

        try {
            $api->moves()->commit_set(range(1, moves::MAX_COMMIT + 1), (int) $staff->id);
            $this->fail('Expected errmovetoomanyselected');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovetoomanyselected', $e->errorcode);
        }
        // Exactly at the cap is legal (no such moves exist, so nothing
        // commits - the point is that the guard did not fire).
        $this->assertSame(0, $api->moves()->commit_set(range(1, moves::MAX_COMMIT), (int) $staff->id));
    }

    /**
     * D6-6a: the commit event is self-describing - always carrying the
     * bypassed rules, empty array on a clean commit.
     */
    public function test_move_committed_carries_bypassedrules(): void {
        $this->resetAfterTest();
        // Room for one more in each team, but a membership cap of one:
        // the clean placement fits, and the SECOND membership is what
        // needs the bypass.
        [$activity, $api, $students, $a, $b, $staff] = $this->setup_two_groups(['maxsize' => 3]);

        $clean = $api->moves()->stage((int) $students[4]->id, null, (int) $a->id, false, null, (int) $staff->id);
        $sink = $this->redirectEvents();
        $this->commit(new api($activity), [(int) $clean->id], (int) $staff->id);
        $committed = $this->events_of($sink, \mod_selfselectadvanced\event\move_committed::class);
        $sink->close();
        $this->assertCount(1, $committed);
        $this->assertSame([], reset($committed)->other['bypassedrules']);

        // A second membership breaks L4 (cap 1).
        $bypassed = (new api($activity))->moves()->stage(
            (int) $students[4]->id,
            null,
            (int) $b->id,
            false,
            null,
            (int) $staff->id,
            false,
            true
        );
        store::save($activity, 'move', (int) $bypassed->id, ['rulesbypassed' => 'L4'], (int) $staff->id);
        $sink = $this->redirectEvents();
        $this->commit(new api($activity), [(int) $bypassed->id], (int) $staff->id, 'agreed');
        $committed = $this->events_of($sink, \mod_selfselectadvanced\event\move_committed::class);
        $sink->close();
        $this->assertCount(1, $committed);
        $this->assertSame(['L4'], reset($committed)->other['bypassedrules']);
    }

    /**
     * D6-6: the named record. One event per bypassed move, carrying the
     * rules, the figures that refused them, the reason and the kind.
     */
    public function test_move_rules_overridden_event_payload(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $a, $b, $staff] = $this->setup_two_groups();

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, false, null, (int) $staff->id);
        store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L2'], (int) $staff->id);
        $sink = $this->redirectEvents();
        $this->commit(new api($activity), [(int) $move->id], (int) $staff->id, 'Guide agreed one over');
        $overridden = $this->events_of($sink, \mod_selfselectadvanced\event\move_rules_overridden::class);
        $sink->close();

        $this->assertCount(1, $overridden);
        $event = reset($overridden);
        $this->assertSame((int) $students[4]->id, (int) $event->relateduserid);
        $this->assertSame(['L2'], $event->other['rules']);
        $this->assertNotEmpty($event->other['figures'][0]);
        $this->assertSame('Guide agreed one over', $event->other['reason']);
        $this->assertSame('move', $event->other['kind']);
        $this->assertSame((int) $b->id, (int) $event->other['targetgroupid']);

        // A park records kind 'park'. Taken out of A, which is at its
        // minimum of two, so L1 genuinely refuses and the bypass bites.
        $park = (new api($activity))->moves()->stage(
            (int) $students[1]->id,
            (int) $a->id,
            null,
            false,
            null,
            (int) $staff->id
        );
        store::save($activity, 'move', (int) $park->id, ['rulesbypassed' => 'L1'], (int) $staff->id);
        $sink = $this->redirectEvents();
        $this->commit(new api($activity), [(int) $park->id], (int) $staff->id, 'left the programme');
        $overridden = $this->events_of($sink, \mod_selfselectadvanced\event\move_rules_overridden::class);
        $sink->close();
        $this->assertCount(1, $overridden);
        $this->assertSame('park', reset($overridden)->other['kind']);
        $this->assertNull(reset($overridden)->other['targetgroupid']);

        // A clean commit fires none at all.
        $clean = (new api($activity))->moves()->stage(
            (int) $students[4]->id,
            (int) $b->id,
            null,
            false,
            null,
            (int) $staff->id
        );
        $sink = $this->redirectEvents();
        $this->commit(new api($activity), [(int) $clean->id], (int) $staff->id, 'tidy up');
        $this->assertCount(0, $this->events_of($sink, \mod_selfselectadvanced\event\move_rules_overridden::class));
        $sink->close();
    }

    /**
     * Requirement 2: a NEW event never fires inside a lock.
     *
     * Probed from INSIDE the observer with locks::held_count(), not
     * with a zero-timeout get_lock(): locks::acquire() builds a NEW
     * factory per call, so postgres_lock_factory's per-instance token
     * guard is empty and its static branch GRANTS the probe, while
     * MariaDB's GET_LOCK is re-entrant on the same session. That probe
     * passes whether or not the lock is held, which makes it no probe
     * at all.
     */
    public function test_override_event_fires_after_lock_release(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, , $b, $staff] = $this->setup_two_groups();

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, false, null, (int) $staff->id);
        store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L2'], (int) $staff->id);

        $seen = [];
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_selfselectadvanced\event\move_rules_overridden',
            'callback' => static function ($event) use (&$seen): void {
                $seen[] = [
                    'locks' => locks::held_count(),
                    'rules' => $event->other['rules'],
                ];
            },
        ]]);
        $this->commit(new api($activity), [(int) $move->id], (int) $staff->id, 'agreed');
        \core\event\manager::phpunit_reset();

        $this->assertCount(1, $seen);
        $this->assertSame(0, $seen[0]['locks']);
        $this->assertSame(['L2'], $seen[0]['rules']);
    }

    /**
     * On the nested path the caller owns the firing: commit_set hands
     * the payloads back and triggers nothing.
     */
    public function test_deferred_override_events_are_not_fired_by_commit_set(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, , $b, $staff] = $this->setup_two_groups();

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, false, null, (int) $staff->id);
        store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L2'], (int) $staff->id);

        $notifications = [];
        $sync = [];
        $deferred = [];
        $sink = $this->redirectEvents();
        (new api($activity))->moves()->commit_set(
            [(int) $move->id],
            (int) $staff->id,
            false,
            $notifications,
            $sync,
            'agreed',
            $deferred
        );
        $fired = $this->events_of($sink, \mod_selfselectadvanced\event\move_rules_overridden::class);
        $sink->close();

        $this->assertCount(0, $fired);
        $this->assertCount(1, $deferred);
        $this->assertSame(['L2'], $deferred[0]['other']['rules']);
        $this->assertSame('agreed', $deferred[0]['other']['reason']);
    }

    /**
     * D6-6e: cancelling a bypassed move deletes its override row, and
     * the delete happens OUTSIDE the activity lock - override: is rank
     * 5 and activity: is rank 6, so doing it inside would break the one
     * global order.
     */
    public function test_cancel_deletes_move_override(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, , $b, $staff] = $this->setup_two_groups();

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, false, null, (int) $staff->id);
        $override = store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L2'], (int) $staff->id);
        $this->assertTrue($DB->record_exists('selfselectadvanced_override', ['id' => $override->id]));

        locks::start_recording();
        $sink = $this->redirectEvents();
        (new api($activity))->moves()->cancel((int) $move->id, (int) $staff->id);
        $deleted = $this->events_of($sink, \mod_selfselectadvanced\event\override_deleted::class);
        $sink->close();
        $log = locks::stop_recording();

        $this->assertFalse($DB->record_exists('selfselectadvanced_override', ['id' => $override->id]));
        $this->assertCount(1, $deleted);
        // D6-6b: a move-scope override row names no user of its own, so
        // the log used to record an exception granted over somebody
        // with no trace of who. The event now names the moved student.
        $this->assertSame((int) $students[4]->id, (int) reset($deleted)->relateduserid);
        // The override lock is taken only AFTER the activity lock has
        // been released, never inside it.
        $releaseat = array_search('release activity:' . $activity->id(), $log, true);
        $acquireat = array_search('acquire override:move:' . (int) $move->id, $log, true);
        $this->assertNotFalse($releaseat);
        $this->assertNotFalse($acquireat);
        $this->assertGreaterThan($releaseat, $acquireat);

        // Finding m6: a cancelled PARK records a null target, never group 0.
        $park = (new api($activity))->moves()->stage(
            (int) $students[3]->id,
            (int) $b->id,
            null,
            false,
            null,
            (int) $staff->id
        );
        $sink = $this->redirectEvents();
        (new api($activity))->moves()->cancel((int) $park->id, (int) $staff->id);
        $cancelled = $this->events_of($sink, \mod_selfselectadvanced\event\move_cancelled::class);
        $sink->close();
        $this->assertCount(1, $cancelled);
        $this->assertNull(reset($cancelled)->other['targetgroupid']);
    }

    /**
     * LEADR and SUCC stay unbypassable: naming them in an override row
     * changes nothing and the set stays invalid.
     */
    public function test_leadr_succ_stay_unbypassable_regression(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $a, $b, $staff] = $this->setup_two_groups([
            'maxsize' => 4,
            'maxmembership' => 2,
        ]);

        // Make a leader of A while B already has one: LEADR refuses.
        $move = $api->moves()->stage(
            (int) $students[3]->id,
            null,
            (int) $a->id,
            true,
            null,
            (int) $staff->id
        );
        store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'LEADR,SUCC'], (int) $staff->id);
        $verdicts = (new api($activity))->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['LEADR']['ok']);
        $this->assertFalse($verdicts->permove[(int) $move->id]['LEADR']['bypassed']);
        unset($b);
    }

    /**
     * D6-3: a solo leader gets the verb that resolves it, not the
     * dead end that cannot.
     */
    public function test_solo_leader_park_names_dissolve(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, , , $staff] = $this->setup_two_groups(['minsize' => 1]);

        $solo = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'Solo',
            'state' => state::FIRM,
        ]);

        try {
            $api->moves()->stage(
                (int) $students[4]->id,
                (int) $solo->id,
                null,
                false,
                null,
                (int) $staff->id
            );
            $this->fail('Expected errmovesololeader');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesololeader', $e->errorcode);
        }
    }

    /**
     * A multi-member team keeps the old, correct message.
     */
    public function test_multi_member_leader_park_still_asks_for_successor(): void {
        $this->resetAfterTest();
        [, $api, $students, , $b, $staff] = $this->setup_two_groups(['minsize' => 1]);

        try {
            $api->moves()->stage(
                (int) $students[2]->id,
                (int) $b->id,
                null,
                false,
                null,
                (int) $staff->id
            );
            $this->fail('Expected errmovesuccessorrequired');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesuccessorrequired', $e->errorcode);
        }
    }

    /**
     * D6-8: the page's label lookups are batched, so their cost does
     * not grow with the number of rows on the page.
     */
    public function test_page_scope_label_queries_bounded(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, , $b, $staff] = $this->setup_two_groups(['maxsize' => 200]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Six teams and 120 pending rows written straight to the table:
        // this test is about the LABEL block, not the engine.
        $groupids = [(int) $b->id];
        for ($i = 0; $i < 5; $i++) {
            $extra = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $students[$i % 5]->id,
                'name' => 'Bulk ' . $i,
                'state' => state::FIRM,
            ]);
            $groupids[] = (int) $extra->id;
        }
        for ($i = 0; $i < 120; $i++) {
            $plugingen->create_pendingmove([
                'activityid' => $activity->id(),
                'userid' => (int) $students[$i % 5]->id,
                'sourcegroupid' => $groupids[$i % 6],
                'targetgroupid' => $groupids[($i + 1) % 6],
                'actorid' => (int) $staff->id,
            ]);
        }

        $page = $DB->get_records(
            'selfselectadvanced_move',
            ['activityid' => $activity->id(), 'status' => 'pending'],
            'timecreated ASC, id ASC',
            '*',
            0,
            50
        );
        $this->assertCount(50, $page);

        // The page's label block, through the PRODUCTION helpers the
        // page itself calls - so de-batching them makes this red.
        $groupidsinplay = [];
        $userids = [];
        foreach ($page as $row) {
            foreach ([$row->sourcegroupid, $row->targetgroupid] as $gid) {
                if ($gid) {
                    $groupidsinplay[(int) $gid] = true;
                }
            }
            $userids[] = (int) $row->userid;
        }

        $before = $DB->perf_get_reads();
        $grouplabels = \mod_selfselectadvanced\table\moves_table::group_labels(
            $activity,
            array_keys($groupidsinplay)
        );
        $userlabels = \mod_selfselectadvanced\table\moves_table::user_labels($userids);
        $reads = $DB->perf_get_reads() - $before;

        $this->assertCount(6, $grouplabels);
        $this->assertCount(5, $userlabels);
        // Two batched reads plus slack - not fifty, and not a hundred.
        $this->assertLessThanOrEqual(5, $reads);
    }

    /**
     * Events of one class from a sink.
     *
     * @param \core\event\event_sink $sink the sink
     * @param string $class the event class
     * @return \core\event\base[] matching events
     */
    private function events_of($sink, string $class): array {
        return array_values(array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof $class
        ));
    }
}
