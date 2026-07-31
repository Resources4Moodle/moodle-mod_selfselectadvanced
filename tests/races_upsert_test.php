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

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\state;

/**
 * Two upserts that were kept honest by convention alone (T-02 R5, R8).
 *
 * One override row per (activity, scope, target) had neither a lock nor
 * an index behind it - and cannot have an index, because the four
 * scopes target four NULLABLE columns and NULLs are distinct in a
 * unique index on both supported engines. One penalty row per group DID
 * have a unique key, so the lost update was a loud failure instead of
 * silent corruption - landing on a guide whose approval had already
 * committed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\override\store
 * @covers     \mod_selfselectadvanced\local\override\resolver
 * @covers     \mod_selfselectadvanced\local\penalty\ledger
 */
final class races_upsert_test extends \advanced_testcase {
    /** @var array[] One entry per penalty_recomputed dispatch: held lock count and payload. */
    public static array $heldatevent = [];

    /**
     * A clean held-set, and a clean event probe, before every test.
     */
    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
        self::$heldatevent = [];
    }

    /**
     * Event observer used as an ordering probe: it records how many
     * plugin locks were held at the instant the event was dispatched.
     *
     * @param \core\event\base $event the dispatched event
     */
    public static function observe_penalty(\core\event\base $event): void {
        self::$heldatevent[] = [
            'held' => locks::held_count(),
            'oldvalue' => $event->other['oldvalue'],
            'newvalue' => $event->other['newvalue'],
        ];
    }

    /**
     * An activity with one student, for user-scope overrides.
     *
     * @return array [activity, userid]
     */
    private function setup_activity(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        return [activity::from_instance((int) $instance->id), (int) $user->id];
    }

    /**
     * Insert an override row straight into the table, bypassing save().
     *
     * @param activity $activity the activity
     * @param string $scope user, group, guide or move
     * @param int $targetid the target
     * @param array $values extra fields
     * @return int the new row id
     */
    private function insert_raw(activity $activity, string $scope, int $targetid, array $values = []): int {
        global $DB;

        $column = match ($scope) {
            'user', 'guide' => 'userid',
            'group' => 'groupid',
            'move' => 'moveid',
        };
        $now = time();

        return (int) $DB->insert_record('selfselectadvanced_override', (object) array_merge([
            'activityid' => $activity->id(),
            'scope' => $scope,
            $column => $targetid,
            'status' => 'active',
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $values));
    }

    /**
     * The red-capable detector for step 8.2: save() serialises on the
     * row's own lock, and on nothing else.
     *
     * Negative control: delete the locks::acquire() in save() - BOTH
     * entries disappear and this assertion fails outright.
     */
    public function test_override_save_is_serialised_on_the_row_lock(): void {
        $this->resetAfterTest();
        [$activity, $userid] = $this->setup_activity();

        locks::start_recording();
        try {
            store::save($activity, 'user', $userid, ['maxlead' => 3], 0);
        } finally {
            $log = locks::stop_recording();
        }

        $this->assertSame([
            'acquire override:user:' . $userid,
            'release override:user:' . $userid,
        ], $log);
    }

    /**
     * delete() takes the same resource, so a save racing a delete
     * resolves one way or the other instead of leaving a twin alive.
     */
    public function test_override_delete_is_serialised_on_the_same_row_lock(): void {
        $this->resetAfterTest();
        [$activity, $userid] = $this->setup_activity();

        $row = store::save($activity, 'user', $userid, ['maxlead' => 3], 0);

        locks::start_recording();
        try {
            store::delete($activity, (int) $row->id, 0);
        } finally {
            $log = locks::stop_recording();
        }

        $this->assertSame([
            'acquire override:user:' . $userid,
            'release override:user:' . $userid,
        ], $log);
    }

    /**
     * The in-lock re-read: a twin committed in the window between the
     * caller's intent and the lock is found and MERGED, not duplicated.
     *
     * This test does NOT detect removal of the lock - the pre-fix
     * get() was a plain get_record(), so it would find the pre-inserted
     * twin and UPDATE it too, leaving one row exactly as the fixed code
     * does. Two rows require the hook, and the hook requires the lock.
     * The detector for the lock itself is
     * test_override_save_is_serialised_on_the_row_lock() above.
     */
    public function test_concurrent_override_save_produces_one_row(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $userid] = $this->setup_activity();

        $fired = false;
        locks::set_test_hook(function (string $resource) use (&$fired, $activity, $userid): void {
            if ($resource !== 'override:user:' . $userid || $fired) {
                return;
            }
            $fired = true;
            locks::set_test_hook(null);
            // The winner, already committed, in the exact window
            // between the loser's intent and its lock.
            $this->insert_raw($activity, 'user', $userid, ['maxmembership' => 5]);
        });

        try {
            store::save($activity, 'user', $userid, ['maxlead' => 3], 0);
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired);
        $rows = $DB->get_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'user',
            'userid' => $userid,
        ]);
        $this->assertCount(1, $rows);
        $survivor = reset($rows);
        $this->assertSame(3, (int) $survivor->maxlead);
        // The winner's own value survived the merge.
        $this->assertSame(5, (int) $survivor->maxmembership);
    }

    /**
     * get() is deterministic on a site that already has twins: the
     * OLDEST row wins, which is also the row the upgrade keeps and the
     * row the resolver reads, so read path and write path never
     * disagree.
     *
     * Two things make this a real test rather than a coincidence.
     * First, the debugging assertion names the NEW message: a bare
     * assertDebuggingCalled() is green on the unfixed tree too, because
     * core's own get_record() already says "found more than one
     * record". Second, the older row is UPDATED first, which moves its
     * physical position on both engines - without that, reset() on an
     * unordered fetch happens to return the lower id anyway and the
     * ORDER BY would prove nothing.
     */
    public function test_override_get_is_deterministic_with_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $userid] = $this->setup_activity();

        $lowid = $this->insert_raw($activity, 'user', $userid, ['maxlead' => 2]);
        $highid = $this->insert_raw($activity, 'user', $userid, ['maxlead' => 7]);
        $this->assertLessThan($highid, $lowid);

        // Move the older row's physical position.
        $DB->set_field('selfselectadvanced_override', 'timemodified', time() + 1, ['id' => $lowid]);

        $row = store::get($activity, 'user', $userid);
        $this->assertDebuggingCalled('Duplicate override rows for user:' . $userid);
        $this->assertSame($lowid, (int) $row->id);
        $this->assertSame(2, (int) $row->maxlead);

        // The resolver agrees with get(): oldest wins on both sides.
        $this->assertSame(2, (new resolver($activity))->effective_maxlead($userid)->value);
        $this->assertDebuggingCalled('Duplicate override rows for user:' . $userid . '; oldest wins');

        // And a following save() updates that same oldest row.
        store::save($activity, 'user', $userid, ['maxlead' => 4], 0);
        $this->assertDebuggingCalled('Duplicate override rows for user:' . $userid);
        $this->assertSame(4, (int) $DB->get_field('selfselectadvanced_override', 'maxlead', ['id' => $lowid]));
        $this->assertSame(7, (int) $DB->get_field('selfselectadvanced_override', 'maxlead', ['id' => $highid]));
    }

    /**
     * The data-only upgrade step: twins that already exist are merged,
     * the oldest of each set survives, and rows that were never
     * duplicated are untouched.
     *
     * Negative control: delete the $oldversion < 2026073110 block - all
     * five rows survive and every count assertion fails.
     */
    public function test_upgrade_merges_duplicate_override_rows(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity, $userid] = $this->setup_activity();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $userid,
            'name' => 'Dupes',
            'state' => state::FORMING,
        ]);

        // Three group-scope twins and two user-scope twins.
        $groupids = [
            $this->insert_raw($activity, 'group', (int) $group->id, ['maxsize' => 3]),
            $this->insert_raw($activity, 'group', (int) $group->id, ['maxsize' => 4]),
            $this->insert_raw($activity, 'group', (int) $group->id, ['maxsize' => 5]),
        ];
        $userids = [
            $this->insert_raw($activity, 'user', $userid, ['maxlead' => 2]),
            $this->insert_raw($activity, 'user', $userid, ['maxlead' => 9]),
        ];
        // An unrelated single row, which must survive untouched.
        $lonely = $this->insert_raw($activity, 'guide', $userid, ['maxguided' => 6]);

        $this->assertSame(6, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
        ]));

        // The phpunit site is installed at the CURRENT version, so the
        // savepoint at the end of the block would refuse as a
        // downgrade. Wind the recorded version back to the release
        // before this step, which is exactly the state of a site about
        // to receive it.
        set_config('version', 2026073100, 'mod_selfselectadvanced');
        xmldb_selfselectadvanced_upgrade(2026073100);
        $this->assertSame('2026073110', get_config('mod_selfselectadvanced', 'version'));

        $this->assertSame(3, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
        ]));
        $this->assertTrue($DB->record_exists('selfselectadvanced_override', ['id' => min($groupids)]));
        $this->assertTrue($DB->record_exists('selfselectadvanced_override', ['id' => min($userids)]));
        $this->assertTrue($DB->record_exists('selfselectadvanced_override', ['id' => $lonely]));
        $this->assertSame(3, (int) $DB->get_field('selfselectadvanced_override', 'maxsize', [
            'id' => min($groupids),
        ]));
        $this->assertSame(2, (int) $DB->get_field('selfselectadvanced_override', 'maxlead', [
            'id' => min($userids),
        ]));
    }

    /**
     * An approved group with no ledger row yet.
     *
     * @param array $settings instance overrides
     * @return array [activity, group]
     */
    private function setup_approved_group(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 1,
            'penaltytype' => 1,
            'penaltyperday' => 5,
            'timedue' => time() - (3 * DAYSECS),
        ], $settings));
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Approved',
            'state' => state::FIRM,
            'timeapproved' => time(),
        ]);

        return [$activity, groups::get($activity, (int) $group->id)];
    }

    /**
     * R8: fk_groupid is foreign-unique, so the loser of a get-then-
     * insert race THREW - on the approving guide's request, after their
     * approval had already committed. With the group lock held across
     * the read and the write, a row that appeared in the meantime is
     * found and updated instead.
     *
     * The hook commits the racing insert in the window the finding
     * describes; if the lock were removed the hook would never fire,
     * which is what the $fired assertion says.
     */
    public function test_penalty_upsert_survives_a_racing_insert(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group] = $this->setup_approved_group();

        $fired = false;
        locks::set_test_hook(function (string $resource) use (&$fired, $activity, $group): void {
            global $DB;

            if ($resource !== 'group:' . (int) $group->id || $fired) {
                return;
            }
            $fired = true;
            locks::set_test_hook(null);
            $DB->insert_record('selfselectadvanced_penalty', (object) [
                'activityid' => $activity->id(),
                'groupid' => (int) $group->id,
                'dayslate' => 0,
                'penaltyvalue' => 0,
                'waived' => 0,
                'timecomputed' => time(),
            ]);
        });

        try {
            $row = ledger::upsert_for_group($activity, $group);
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_penalty', ['groupid' => (int) $group->id]));
        $this->assertGreaterThan(0, (float) $row->penaltyvalue);
        $this->assertSame(
            (float) $row->penaltyvalue,
            (float) $DB->get_field('selfselectadvanced_penalty', 'penaltyvalue', ['groupid' => (int) $group->id])
        );
    }

    /**
     * The detector for step 10's lock: the upsert holds group:{id}
     * across the read and the write, and nothing else.
     *
     * Negative control: delete the locks::acquire() in
     * upsert_for_group() - both entries disappear and this fails.
     */
    public function test_penalty_upsert_is_serialised_on_the_group_lock(): void {
        $this->resetAfterTest();
        [$activity, $group] = $this->setup_approved_group();

        locks::start_recording();
        try {
            ledger::upsert_for_group($activity, $group);
        } finally {
            $log = locks::stop_recording();
        }

        $this->assertSame([
            'acquire group:' . (int) $group->id,
            'release group:' . (int) $group->id,
        ], $log);
    }

    /**
     * The penalty event stays OUTSIDE the lock. A real ordering probe:
     * the observer records locks::held_count() at the instant of
     * dispatch, so a refactor that moved the trigger inside the lock
     * would record 1 instead of 0.
     *
     * The event is NOT redirected to a sink here, deliberately:
     * \core\event\manager::dispatch() short-circuits to the sink and
     * never reaches an observer while events are being redirected, so
     * a sink would silence the probe.
     */
    public function test_penalty_event_fires_once_with_the_right_old_value(): void {
        $this->resetAfterTest();
        [$activity, $group] = $this->setup_approved_group();

        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_selfselectadvanced\event\penalty_recomputed',
            'callback' => '\mod_selfselectadvanced\races_upsert_test::observe_penalty',
        ]]);

        $row = ledger::upsert_for_group($activity, $group);

        $this->assertCount(1, self::$heldatevent);
        $this->assertSame(0, self::$heldatevent[0]['held']);
        $this->assertNull(self::$heldatevent[0]['oldvalue']);
        $this->assertSame((float) $row->penaltyvalue, (float) self::$heldatevent[0]['newvalue']);

        // Recomputing an unchanged value fires nothing more.
        self::$heldatevent = [];
        ledger::upsert_for_group($activity, $group);
        $this->assertSame([], self::$heldatevent);
    }

    /**
     * Step 10's $callerserialises half. recompute_all() runs
     * synchronously from selfselectadvanced_update_instance() over
     * every approved group of the activity - ~1500 on the target site.
     * One activity lock covers the whole sweep; a per-group acquire
     * would put one round trip per group inside a teacher's Save and
     * display, and locks::acquire()'s 10s errlocktimeout has no
     * per-group catch, so one contended group would abort the sweep
     * part-way and push_grades() would never run.
     *
     * Negative control: pass false (or drop $callerserialises) - the
     * log grows by one acquire/release pair per group and this exact
     * comparison fails.
     *
     * The fixture is 40 groups rather than the ticket's 200: the
     * assertion is exact, so it fails at N = 2, and 200 users buys
     * nothing but runtime.
     */
    public function test_recompute_all_serialises_once_for_the_whole_sweep(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 40,
            'maxmembership' => 40,
            'penaltytype' => 1,
            'penaltyperday' => 5,
            'timedue' => time() - (2 * DAYSECS),
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        for ($i = 0; $i < 40; $i++) {
            $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $leader->id,
                'name' => 'Sweep ' . $i,
                'state' => state::FIRM,
                'timeapproved' => time(),
                'skipleaderrow' => $i > 0,
            ]);
        }

        locks::start_recording();
        try {
            $count = ledger::recompute_all($activity);
        } finally {
            $log = locks::stop_recording();
        }

        $this->assertSame(40, $count);
        $this->assertSame([
            'acquire activity:' . $activity->id(),
            'release activity:' . $activity->id(),
        ], $log);
    }
}
