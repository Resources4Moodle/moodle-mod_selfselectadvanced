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
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\state;

/**
 * One approval authority: the guide-window sweep enforces every gate
 * the guide's own click enforces except identity, an active relief
 * override exists only for an approval that committed, and one run does
 * bounded, resumable work (T-04).
 *
 * A note for whoever writes the next test here: advanced_testcase opens
 * a delegated transaction before every test on PostgreSQL, so inside
 * the service $outermost is FALSE and its rollback never runs. Any test
 * that asserts "nothing of the failed attempt survived" must call
 * preventResetByRollback() as its FIRST statement, or it passes on
 * MariaDB and fails on PostgreSQL - which is worse than failing.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\task\guide_autoapprove
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 * @covers     \mod_selfselectadvanced\local\state
 */
final class autoapprove_test extends \advanced_testcase {
    /** @var int The group the interleaving observer moves out of the queue. */
    private static int $victim = 0;

    /** @var bool Whether the one-shot interleaving has already fired. */
    private static bool $flipped = false;

    /**
     * A clean held-lock set and a clean interleaving state per test.
     */
    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
        self::$victim = 0;
        self::$flipped = false;
    }

    /**
     * Release the test seams the interleaving tests install.
     */
    protected function tearDown(): void {
        locks::reset_state();
        parent::tearDown();
    }

    /**
     * The one-shot interleaving used by test 4: the guide returns the
     * victim team in the window between the sweep's batch read and that
     * team's own lock. Fires on the FIRST auto-approval of the run.
     *
     * @param \core\event\base $event the group_approved event that opened the window
     */
    public static function flip_state(\core\event\base $event): void {
        global $DB;

        if (self::$flipped || !self::$victim) {
            return;
        }
        self::$flipped = true;
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => self::$victim]);
    }

    /**
     * A course, an auto-approving activity, a guide, a manager and four
     * students. Caller settings win over the defaults.
     *
     * @param array $settings activity settings overriding the defaults
     * @return array [activity, course, guide, manager, students[]]
     */
    private function world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', $settings + [
            'course' => $course->id,
            'guideautoapprove' => 1,
            'guidewindow' => DAYSECS,
            'minsize' => 3,
            'maxguided' => 10,
            'maxlead' => 5,
            'maxmembership' => 5,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }

        return [$activity, $course, $guide, $manager, $students];
    }

    /**
     * A team sitting in the guide's queue, submitted $age seconds ago.
     *
     * @param activity $a the activity
     * @param int $leaderid the leader
     * @param int $guideid the assigned guide
     * @param int $age seconds since submission
     * @return \stdClass the group row
     */
    private function overdue(activity $a, int $leaderid, int $guideid, int $age = 2 * DAYSECS): \stdClass {
        global $DB;

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $a->id(),
            'leaderid' => $leaderid,
            'guideid' => $guideid,
            'state' => state::PENDING_GUIDE,
        ]);
        $group->timesubmitted = time() - $age;
        $DB->set_field('selfselectadvanced_group', 'timesubmitted', $group->timesubmitted, ['id' => $group->id]);

        return $group;
    }

    /**
     * Add confirmed members to a team.
     *
     * @param int $groupid the team
     * @param array $users user records to seat
     */
    private function seat(int $groupid, array $users): void {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        foreach ($users as $user) {
            $plugingen->create_member([
                'groupid' => $groupid,
                'userid' => (int) $user->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
    }

    /**
     * Run the sweep, capturing its cron output.
     *
     * @return string everything the task wrote
     */
    private function run_sweep(): string {
        ob_start();
        try {
            (new \mod_selfselectadvanced\task\guide_autoapprove())->execute();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    /**
     * How many guide-queue reminders the sink holds. Filtered, because
     * enrolling the fixture's users also sends each of them a course
     * welcome message.
     *
     * @param \phpunit_message_sink $sink the redirected message sink
     * @return int
     */
    private function reminders(\phpunit_message_sink $sink): int {
        return count(array_filter(
            $sink->get_messages(),
            fn($m) => $m->eventtype === 'guidequeue'
        ));
    }

    /**
     * The current lifecycle state of a team.
     *
     * @param int $groupid the team
     * @return string the state
     */
    private function state_of(int $groupid): string {
        global $DB;

        return (string) $DB->get_field('selfselectadvanced_group', 'state', ['id' => $groupid]);
    }

    /**
     * One user's published grade, or null when they hold none.
     *
     * @param activity $a the activity
     * @param int $courseid the course
     * @param int $userid the user
     * @return float|null the grade
     */
    private function grade_of(activity $a, int $courseid, int $userid): ?float {
        $grades = grade_get_grades($courseid, 'mod', 'selfselectadvanced', $a->id(), [$userid]);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;

        return $grade === null ? null : (float) $grade;
    }

    /**
     * 1. A guide already over their team limit has their whole queue
     *    deferred, exactly as their own click would be refused - and
     *    nothing is recorded to excuse it.
     */
    public function test_guide_over_cap_defers_the_sweep(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        [$activity, , $guide, , $students] = $this->world(['maxguided' => 1]);
        $one = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $two = $this->overdue($activity, (int) $students[1]->id, (int) $guide->id);

        $output = $this->run_sweep();

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $one->id));
        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $two->id));
        // Nothing was written to excuse an approval that never happened.
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override', ['scope' => 'group']));
        $this->assertStringContainsString('already guiding', $output);
        $this->assertStringContainsString('auto-approve skipped', $output);
        $this->assertSame([], array_filter(
            $sink->get_messages(),
            fn($m) => $m->eventtype === 'groupapproved'
        ));
    }

    /**
     * 2. A guide exactly AT their limit is still auto-approved: approval
     *    does not raise count_guiding(), so the gate is a strict ">"
     *    and a ">=" would deadlock every full guide's queue.
     */
    public function test_guide_exactly_at_cap_still_autoapproves(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, , $guide, , $students] = $this->world(['maxguided' => 2]);
        $one = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $two = $this->overdue($activity, (int) $students[1]->id, (int) $guide->id);

        $this->run_sweep();

        // The second team still sees used == 2, because the first is
        // now FIRM and count_guiding() counts firm teams too.
        $this->assertSame(state::FIRM, $this->state_of((int) $one->id));
        $this->assertSame(state::FIRM, $this->state_of((int) $two->id));
    }

    /**
     * 3. The manual click and the sweep read one gate body, so their
     *    verdicts and their reason payloads cannot drift.
     */
    public function test_manual_and_auto_share_one_gate_authority(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $guide, , $students] = $this->world();
        $group = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $group = groups::get($activity, (int) $group->id);
        $gatekeeper = (new api($activity))->gatekeeper();

        $manual = $gatekeeper->can_approve($group, (int) $guide->id);
        $this->assertNotNull($manual);
        $this->assertSame('refusalbelowminsize', $manual->stringkey);
        $this->assertSame(1, (int) $manual->a->current);
        $this->assertSame(3, (int) $manual->a->min);
        $this->assertSame(
            'refusalnotassignedguide',
            $gatekeeper->can_approve($group, (int) $students[1]->id)->stringkey
        );

        // The sweep sees no hard refusal, only the exact relief the
        // manual refusal describes.
        $plan = $gatekeeper->autoapprove_plan($group);
        $this->assertNull($plan->refusal);
        $this->assertSame(['minsize' => 1], $plan->relief);
        $this->assertSame('refusalbelowminsize', $plan->reliefreasons['minsize']->stringkey);
        $this->assertEquals($manual->a, $plan->reliefreasons['minsize']->a);

        // A guide over cap: both paths report it identically, and for
        // the sweep it is HARD - never relief.
        $DB->set_field('selfselectadvanced', 'maxguided', 0, ['id' => $activity->id()]);
        $reloaded = activity::from_instance($activity->id());
        $gatekeeper = (new api($reloaded))->gatekeeper();
        $manualcap = $gatekeeper->can_approve($group, (int) $guide->id);
        $plancap = $gatekeeper->autoapprove_plan($group);
        $this->assertSame('refusalguidecap', $manualcap->stringkey);
        $this->assertNotNull($plancap->refusal);
        $this->assertSame('refusalguidecap', $plancap->refusal->stringkey);
        $this->assertEquals($manualcap->a, $plancap->refusal->a);
        $this->assertSame([], $plancap->relief);
    }

    /**
     * 4. (3d) Relief and approval commit or roll back together, and a
     *    refusal does not cost the approvals queued behind it.
     */
    public function test_relief_and_approval_commit_or_roll_back_together(): void {
        global $DB;
        // Must be first: on PostgreSQL the per-test transaction would
        // otherwise make the service a nested writer, so the rollback
        // under test never runs.
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, , $guide, , $students] = $this->world();
        $a = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $b = $this->overdue($activity, (int) $students[1]->id, (int) $guide->id);
        $c = $this->overdue($activity, (int) $students[2]->id, (int) $guide->id);

        // The concrete interleaving: the guide returns B to its leader
        // in the window between the sweep's batch read and B's lock.
        self::$victim = (int) $b->id;
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_selfselectadvanced\event\group_approved',
            'callback' => 'mod_selfselectadvanced\autoapprove_test::flip_state',
        ]]);

        $output = $this->run_sweep();

        $this->assertTrue(self::$flipped, 'the interleaving never fired');
        $this->assertSame(state::FIRM, $this->state_of((int) $a->id));
        $arelief = $DB->get_record('selfselectadvanced_override', [
            'scope' => 'group',
            'groupid' => (int) $a->id,
        ], '*', MUST_EXIST);
        $this->assertSame('active', $arelief->status);
        $this->assertSame(1, (int) $arelief->minsize);

        // B moved on, so B is refused - and nothing of B survives.
        $this->assertSame(state::FORMING, $this->state_of((int) $b->id));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override', [
            'scope' => 'group',
            'groupid' => (int) $b->id,
        ]));

        // A refusal must not discard the approvals that follow it. The
        // transaction assertion is the detector for the missing
        // rollback: without it B's delegated transaction stays open, C
        // commits into a frame that is never popped to zero, and the
        // whole run is force-rolled back at dispose().
        $this->assertSame(state::FIRM, $this->state_of((int) $c->id));
        $this->assertTrue($DB->record_exists('selfselectadvanced_override', [
            'scope' => 'group',
            'groupid' => (int) $c->id,
            'status' => 'active',
        ]));
        $this->assertFalse($DB->is_transaction_started(), 'the refusal left a transaction open');
        $this->assertSame(1, substr_count($output, 'auto-approve skipped'));
    }

    /**
     * 5. (3b) The gates - and the relief that records them - are judged
     *    on the roster as it is at commit time, not on a snapshot taken
     *    before the lock.
     */
    public function test_gates_are_judged_on_the_roster_at_commit_time(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, , $guide, , $students] = $this->world(['minsize' => 5]);
        $group = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $this->seat((int) $group->id, [$students[1], $students[2]]);
        $this->assertSame(3, groups::count_confirmed((int) $group->id));

        // A member leaves in the exact window between any pre-lock read
        // and the lock itself: locks::set_test_hook() fires immediately
        // BEFORE each acquire.
        $leaver = $DB->get_field('selfselectadvanced_member', 'id', [
            'groupid' => (int) $group->id,
            'userid' => (int) $students[2]->id,
        ]);
        $fired = false;
        locks::set_test_hook(function (string $resource) use (&$fired, $leaver, $group): void {
            global $DB;
            if ($fired || $resource !== 'override:group:' . (int) $group->id) {
                return;
            }
            $fired = true;
            $DB->delete_records('selfselectadvanced_member', ['id' => $leaver]);
        });

        $this->run_sweep();
        locks::set_test_hook(null);

        $this->assertTrue($fired, 'the interleaving never fired');
        $this->assertSame(state::FIRM, $this->state_of((int) $group->id));
        $relief = $DB->get_record('selfselectadvanced_override', [
            'scope' => 'group',
            'groupid' => (int) $group->id,
        ], '*', MUST_EXIST);
        // 2 - the roster at commit. A plan computed before the lock
        // would have recorded the 3 that were seated a moment earlier.
        $this->assertSame(2, (int) $relief->minsize);
    }

    /**
     * 6. A relief the resolver cannot see defers the team, and the
     *    manager's own override row is left exactly as it was.
     */
    public function test_pending_relief_defers_and_leaves_the_manager_row_untouched(): void {
        global $DB;
        // Must be first - see the note on test 4.
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, , $guide, $manager, $students] = $this->world(['minsize' => 5]);
        $group = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $this->seat((int) $group->id, [$students[1], $students[2]]);

        // An ACTIVE manager row carrying a cap the team already exceeds:
        // merging any field into it re-runs guard::blockers(), which
        // parks the merged row as pending. Inserted directly, because
        // store::save() would itself park a cap of 1 under 3 seats.
        $now = time() - 100;
        $rowid = $DB->insert_record('selfselectadvanced_override', (object) [
            'activityid' => $activity->id(),
            'scope' => 'group',
            'groupid' => (int) $group->id,
            'maxsize' => 1,
            'status' => 'active',
            'usermodified' => (int) $manager->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $before = $DB->get_record('selfselectadvanced_override', ['id' => $rowid], '*', MUST_EXIST);

        $output = $this->run_sweep();

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $group->id));
        $this->assertStringContainsString(
            get_string('refusalreliefpending', 'mod_selfselectadvanced'),
            $output
        );
        // Byte-identical: the merged write was rolled back with the
        // approval it was meant to explain.
        $after = $DB->get_record('selfselectadvanced_override', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertEquals($before, $after);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_override', ['scope' => 'group']));
        $this->assertFalse($DB->is_transaction_started(), 'the refusal left a transaction open');
    }

    /**
     * 7. The activity-wide grade push is deferred on the auto path and
     *    immediate on the manual one; the per-group ledger row is
     *    written on both.
     */
    public function test_grade_push_is_deferred_on_the_auto_path(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        // Minimum two, so the auto team (leader only) needs recorded
        // relief and the manual team (leader plus one) does not - the
        // lock log below is only worth reading if store::save() really
        // runs inside the transition.
        [$activity, $course, $guide, , $students] = $this->world(['minsize' => 2]);
        $auto = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $manual = $this->overdue($activity, (int) $students[1]->id, (int) $guide->id);
        $this->seat((int) $manual->id, [$students[2]]);
        $api = new api($activity);
        $admin = get_admin();

        locks::start_recording();
        $api->lifecycle()->approve_auto(groups::get($activity, (int) $auto->id), (int) $admin->id);
        $log = locks::stop_recording();

        // The relief write happens under the caller's own override lock,
        // so store::save() must NOT take it again: a same-process
        // re-acquire is granted by both engines' factories and released
        // once, leaving a phantom hold that no other test can see. The
        // order is ascending (override rank 5 before group rank 8) and
        // the release is in reverse; the trailing pair is the penalty
        // ledger's own group lock, taken after ours is released.
        $gid = (int) $auto->id;
        $this->assertSame([
            'acquire override:group:' . $gid,
            'acquire group:' . $gid,
            'release group:' . $gid,
            'release override:group:' . $gid,
            'acquire group:' . $gid,
            'release group:' . $gid,
        ], $log);

        $this->assertSame(state::FIRM, $this->state_of((int) $auto->id));
        $this->assertTrue($DB->record_exists('selfselectadvanced_penalty', ['groupid' => (int) $auto->id]));
        $this->assertNull($this->grade_of($activity, (int) $course->id, (int) $students[0]->id));

        ledger::push_grades($activity);
        $this->assertIsFloat($this->grade_of($activity, (int) $course->id, (int) $students[0]->id));
        // The manual team has not been approved yet, so its leader is
        // still ungraded - the push above did not invent a grade.
        $this->assertNull($this->grade_of($activity, (int) $course->id, (int) $students[1]->id));

        $api->lifecycle()->approve(groups::get($activity, (int) $manual->id), (int) $guide->id);
        $this->assertIsFloat($this->grade_of($activity, (int) $course->id, (int) $students[1]->id));
    }

    /**
     * 8. One sweep pushes grades once per activity, and the grades are
     *    really published.
     */
    public function test_sweep_pushes_grades_once_and_publishes_them(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, $course, $guide, , $students] = $this->world(['minsize' => 1]);
        $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $this->overdue($activity, (int) $students[1]->id, (int) $guide->id);

        $output = $this->run_sweep();

        $this->assertIsFloat($this->grade_of($activity, (int) $course->id, (int) $students[0]->id));
        $this->assertIsFloat($this->grade_of($activity, (int) $course->id, (int) $students[1]->id));
        $this->assertSame(1, substr_count($output, 'pushed grades once'));
        $this->assertStringContainsString('for 2 auto-approval(s)', $output);
    }

    /**
     * 9. A run does a bounded amount of work, remembers where it
     *    stopped, and resumes there.
     */
    public function test_batch_cap_checkpoints_and_resumes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        set_config('autoapprovebatch', 2, 'mod_selfselectadvanced');
        [$activity, , $guide, , $students] = $this->world(['minsize' => 1]);
        $one = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $two = $this->overdue($activity, (int) $students[1]->id, (int) $guide->id);
        $three = $this->overdue($activity, (int) $students[2]->id, (int) $guide->id);
        $cursorname = 'autoapprovecursor_' . $activity->id();

        $this->run_sweep();

        $this->assertSame(state::FIRM, $this->state_of((int) $one->id));
        $this->assertSame(state::FIRM, $this->state_of((int) $two->id));
        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $three->id));
        $this->assertSame(2, $DB->count_records('selfselectadvanced_group', [
            'activityid' => $activity->id(),
            'state' => state::FIRM,
        ]));
        $this->assertSame((int) $two->id, (int) get_config('mod_selfselectadvanced', $cursorname));

        $this->run_sweep();

        $this->assertSame(state::FIRM, $this->state_of((int) $three->id));
        $this->assertSame(3, $DB->count_records('selfselectadvanced_group', [
            'activityid' => $activity->id(),
            'state' => state::FIRM,
        ]));
        // The short batch finished the pass, so the cursor is cleared
        // and the next run starts at the head again.
        $this->assertFalse(get_config('mod_selfselectadvanced', $cursorname));
    }

    /**
     * 10. The window itself is what decides, at both of its edges.
     */
    public function test_window_boundary_parity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, , $guide, , $students] = $this->world(['minsize' => 1]);
        // Five seconds either side, never the exact boundary:
        // execute() calls time() again and a one-second advance would
        // flip the verdict.
        $inside = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id, DAYSECS - 5);
        $over = $this->overdue($activity, (int) $students[1]->id, (int) $guide->id, DAYSECS + 5);
        $never = $this->overdue($activity, (int) $students[2]->id, (int) $guide->id);
        $DB->set_field('selfselectadvanced_group', 'timesubmitted', 0, ['id' => (int) $never->id]);

        $this->run_sweep();

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $inside->id));
        $this->assertSame(state::FIRM, $this->state_of((int) $over->id));
        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $never->id));
    }

    /**
     * 11. The reminder phase is bounded too, and a team already
     *     reminded for its stage cannot occupy a batch slot and starve
     *     the tail.
     */
    public function test_reminder_batch_excludes_settled_rows_and_resumes(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        set_config('autoapprovebatch', 1, 'mod_selfselectadvanced');
        [$activity, , $guide, , $students] = $this->world();
        $early = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id, (int) (DAYSECS * 0.10));
        $half = $this->overdue($activity, (int) $students[1]->id, (int) $guide->id, (int) (DAYSECS * 0.60));
        $later = $this->overdue($activity, (int) $students[2]->id, (int) $guide->id, (int) (DAYSECS * 0.62));

        $this->run_sweep();
        $this->assertSame(1, $this->reminders($sink));
        $sink->clear();

        // The second team is reached only because the first no longer
        // matches the query: with a batch of one, a PHP-side skip would
        // hand the single slot back to the already-reminded team every
        // run and this team would never be told.
        $this->run_sweep();
        $this->assertSame(1, $this->reminders($sink));
        $sink->clear();

        $this->run_sweep();
        $this->assertSame(0, $this->reminders($sink));

        $this->assertSame(0, (int) get_user_preferences(
            'mod_selfselectadvanced_gremind_' . (int) $early->id,
            0,
            (int) $guide->id
        ));
        foreach ([$half, $later] as $reminded) {
            $this->assertSame(50, (int) get_user_preferences(
                'mod_selfselectadvanced_gremind_' . (int) $reminded->id,
                0,
                (int) $guide->id
            ));
        }

        // Push one team to 90% of the window: one further reminder, and
        // no repeat on the run after.
        $DB->set_field(
            'selfselectadvanced_group',
            'timesubmitted',
            time() - (int) (DAYSECS * 0.95),
            ['id' => (int) $later->id]
        );
        $sink->clear();
        $this->run_sweep();
        $this->assertSame(1, $this->reminders($sink));
        $this->assertSame(90, (int) get_user_preferences(
            'mod_selfselectadvanced_gremind_' . (int) $later->id,
            0,
            (int) $guide->id
        ));
        $sink->clear();
        $this->run_sweep();
        $this->assertSame(0, $this->reminders($sink));
    }

    /**
     * 13. A batch full of teams the sweep must defer does not starve
     *     the teams behind them.
     *
     * This is what the resume cursor is FOR, and the only shape that
     * proves it: teams that are approved leave pending_guide and stop
     * matching the query on their own, so a batch of approvals resumes
     * correctly with or without the cursor. Only a batch that is still
     * pending afterwards can block the queue behind it.
     */
    public function test_deferred_teams_do_not_starve_the_tail(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        set_config('autoapprovebatch', 2, 'mod_selfselectadvanced');
        // The shared guide holds two teams against a cap of one, so
        // both are deferred on every pass; the tail team's guide is
        // within cap and is approvable the moment it is reached.
        [$activity, $course, $overcap, , $students] = $this->world(['maxguided' => 1, 'minsize' => 1]);
        $healthy = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($healthy->id, $course->id, 'teacher');
        $blockedone = $this->overdue($activity, (int) $students[0]->id, (int) $overcap->id);
        $blockedtwo = $this->overdue($activity, (int) $students[1]->id, (int) $overcap->id);
        $tail = $this->overdue($activity, (int) $students[2]->id, (int) $healthy->id);

        $this->run_sweep();

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $blockedone->id));
        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $blockedtwo->id));
        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $tail->id));
        $this->assertSame(
            (int) $blockedtwo->id,
            (int) get_config('mod_selfselectadvanced', 'autoapprovecursor_' . $activity->id())
        );

        // Run two resumes AFTER the two it could not approve.
        $this->run_sweep();

        $this->assertSame(state::FIRM, $this->state_of((int) $tail->id));
    }

    /**
     * 14. A lock this method could not take does not leave behind the
     *     one it already had.
     *
     * An INVARIANT test, not a race: the second acquire is made to fail
     * by injection rather than by real contention, because a genuine
     * 10-second timeout cannot be produced from one process. What it
     * pins is real - the sweep takes override:group:{id} and then
     * group:{id}, and if the second times out the first must not
     * survive. A leaked handle is worse than a held resource: it leaves
     * locks::held_count() non-zero for the rest of the process, and
     * that count is the question notifier::send() asks to decide
     * whether it is speaking from inside a lock.
     */
    public function test_a_failed_second_acquire_releases_the_first(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        [$activity, , $guide, , $students] = $this->world(['minsize' => 2]);
        $group = $this->overdue($activity, (int) $students[0]->id, (int) $guide->id);
        $gid = (int) $group->id;

        // The group lock (rank 8) refuses, with the override lock
        // (rank 5) already held - exactly what errlocktimeout does.
        locks::set_test_hook(function (string $resource) use ($gid): void {
            if ($resource === 'group:' . $gid) {
                throw new \moodle_exception('errlocktimeout', 'mod_selfselectadvanced');
            }
        });

        $thrown = null;
        try {
            (new api($activity))->lifecycle()->approve_auto(
                groups::get($activity, $gid),
                (int) get_admin()->id
            );
            $this->fail('the injected acquire failure did not propagate');
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            locks::set_test_hook(null);
        }

        // The primary assertion. Two bare acquires leave the override
        // handle behind: self::$held keeps it (only lockhandle::release()
        // forgets), so held_count() is 1 for the rest of the process even
        // though core's own lock destructor eventually frees the row -
        // loudly, as a coding_exception that replaces the real error.
        $this->assertSame(0, locks::held_count(), 'the override lock outlived the failed acquire');
        $this->assertInstanceOf(\moodle_exception::class, $thrown);
        $this->assertSame('errlocktimeout', $thrown->errorcode);
        $this->assertSame(state::PENDING_GUIDE, $this->state_of($gid));
    }

    /**
     * 12. One broken activity costs only itself.
     */
    public function test_a_broken_activity_does_not_kill_the_sweep(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        // Created first, so it is the lower instance id and the sweep
        // reaches it first (the loop is ordered by id).
        [$broken, $brokencourse, $brokenguide, , $brokenstudents] = $this->world(['minsize' => 1]);
        $lost = $this->overdue($broken, (int) $brokenstudents[0]->id, (int) $brokenguide->id);
        [$healthy, , $guide, , $students] = $this->world(['minsize' => 1]);
        $survivor = $this->overdue($healthy, (int) $students[0]->id, (int) $guide->id);

        $DB->delete_records('course_modules', ['id' => (int) $broken->cm()->id]);
        // The course cache still lists the module until it is rebuilt,
        // and get_course_and_cm_from_instance() reads it from there.
        rebuild_course_cache((int) $brokencourse->id, true);

        $output = $this->run_sweep();

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $lost->id));
        $this->assertSame(state::FIRM, $this->state_of((int) $survivor->id));
        $this->assertStringContainsString('sweep failed for activity', $output);
    }
}
