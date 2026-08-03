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

use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\task\coresync_adhoc;

/**
 * The one authoritative mirror routine (T-16): the queued convergence
 * backstop, dangling-pointer repair, the core removal-block callback,
 * the bulk cap-violator query, the idnumber fallback, and the
 * unenrolment observer.
 *
 * READ FIRST - what changed in 1.20, because the previous note here
 * described a guard that no longer exists and must not come back.
 * sync_core_group() USED TO return status='deferred' and write nothing
 * whenever $DB->is_transaction_started(). advanced_testcase opens a
 * delegated transaction before EVERY test on PostgreSQL and none on
 * MariaDB, so that guard made the mirror run on one engine and be
 * skipped on the other for identical inputs - requirement 6's named
 * trap, measured. The branch is gone: the routine now does the same
 * work with a transaction open or not, and
 * test_sync_does_the_same_work_inside_and_outside_a_transaction pins
 * that both ways round.
 *
 * The preventResetByRollback() calls that remain are therefore NO
 * LONGER what makes the mirror run. They are kept where a test needs
 * the harness's own transaction out of the way for its own reasons -
 * to own the transaction it asserts about, or to read committed core
 * rows - and a new test here does not need one to see a core-group
 * write.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\freeze
 */
final class coresync_test extends \advanced_testcase {
    /**
     * A firm group of two with an assigned guide, in its own course.
     *
     * @param int $members how many confirmed members besides the leader
     * @param array $settings instance overrides
     * @return array [activity, group, students[], guide, course]
     */
    private function setup_team(int $members = 1, array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'CSY' . random_int(1000, 9999)]);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 20,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], $settings), ['idnumber' => 'SSACSY']);

        $students = [];
        for ($i = 0; $i <= $members + 1; $i++) {
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
            'name' => 'Mirror',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        for ($i = 1; $i <= $members; $i++) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $students[$i]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return [$activity, groups::get($activity, (int) $group->id), $students, $guide, $course];
    }

    /**
     * 1b: repeat mutations of one group must not stack repair jobs -
     * queue_adhoc_task(..., true) dedupes identical pending rows.
     */
    public function test_request_sync_queues_dedup(): void {
        $this->resetAfterTest();

        [$activity, $group] = $this->setup_team();
        $group->state = state::FROZEN;

        freeze::request_sync($activity, $group);
        freeze::request_sync($activity, $group);

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(coresync_adhoc::class));
    }

    /**
     * The crash window closes: whatever happened to the inline sync,
     * the queued adhoc converges the mirror on the next cron run.
     */
    public function test_adhoc_repairs_crash_window(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, $students, $guide] = $this->setup_team();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $this->runAdhocTasks();

        // Somebody empties the mirror out of band.
        groups_remove_member($coreid, (int) $students[1]->id);
        $this->assertFalse(groups_is_member($coreid, (int) $students[1]->id));
        freeze::request_sync($activity, groups::get($activity, (int) $frozen->id));

        $this->runAdhocTasks();

        $this->assertTrue(groups_is_member($coreid, (int) $students[1]->id));
    }

    /**
     * REQUIREMENT 6, PINNED BOTH WAYS ROUND: the routine does the SAME
     * WORK whether a transaction is open or not.
     *
     * The same starting state and the same call are run twice - once
     * with no transaction, once inside one this test owns - and the two
     * results are compared field by field. Before 1.20 the second run
     * returned status='deferred' with added=[] and wrote nothing, which
     * on PostgreSQL is the state of EVERY test in the suite; on MariaDB
     * it wrote. That is the engine split this test exists to keep out.
     *
     * preventResetByRollback() is still first, and now for the only
     * reason left: it makes "no transaction open" TRUE on both engines
     * so round 1 really is the no-transaction case. It no longer has
     * anything to do with whether the mirror runs.
     */
    public function test_sync_does_the_same_work_inside_and_outside_a_transaction(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, $students, $guide] = $this->setup_team();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $this->runAdhocTasks();
        $member = (int) $students[1]->id;

        // Round 1 - no transaction open.
        $this->assertFalse($DB->is_transaction_started());
        groups_remove_member($coreid, $member);
        $outside = freeze::sync_core_group($activity, (int) $frozen->id, (int) $guide->id);
        $this->assertSame('synced', $outside->status);
        $this->assertSame([$member], $outside->added);
        $this->assertTrue(groups_is_member($coreid, $member));

        // Round 2 - identical inputs, transaction open.
        groups_remove_member($coreid, $member);
        $transaction = $DB->start_delegated_transaction();
        $this->assertTrue($DB->is_transaction_started());
        $inside = freeze::sync_core_group($activity, (int) $frozen->id, (int) $guide->id);
        $transaction->allow_commit();

        $this->assertSame($outside->status, $inside->status);
        $this->assertSame($outside->added, $inside->added);
        $this->assertSame($outside->removed, $inside->removed);
        $this->assertSame($outside->coregroupid, $inside->coregroupid);
        $this->assertTrue(
            groups_is_member($coreid, $member),
            'the mirror was not written inside a transaction - the engine split is back'
        );
    }

    /**
     * A throw halfway through the add loop is REPORTED, not whispered.
     *
     * sync_core_group() used to set status='synced' before
     * classify_mirror() and both loops, and the catch returned with
     * that status intact behind a debugging() that is a no-op in
     * production. Measured: a frozen team with a live mirror, one
     * confirmed member whose user row is hard-deleted (so core's
     * groups_add_member() reads it MUST_EXIST and throws) and a second
     * perfectly addable member queued behind them gave
     * 'status=synced added=0' - and group.php then chose its green
     * "already in step" NOTIFY_SUCCESS branch for a mirror that was
     * missing a member.
     */
    public function test_a_throw_in_the_add_loop_does_not_return_synced(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, $students, $guide] = $this->setup_team(2);
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $this->runAdhocTasks();
        $this->assertNotEmpty($coreid);

        // Two members the mirror is missing, one of them unaddable.
        $ghost = (int) $students[1]->id;
        $addable = (int) $students[2]->id;
        groups_remove_member($coreid, $ghost);
        groups_remove_member($coreid, $addable);
        $DB->delete_records('user', ['id' => $ghost]);
        // The freeze's own queued job would otherwise be counted below.
        $DB->delete_records('task_adhoc');

        $result = freeze::sync_core_group($activity, (int) $frozen->id, (int) $guide->id);
        $this->assertDebuggingCalled();

        $this->assertNotSame('synced', $result->status, 'a failed sync reported itself as in step');
        $this->assertSame('failed', $result->status);
        $this->assertNotSame('', $result->error, 'the failure carried no message out to the caller');
        // The loop stopped: it cannot have added both of them.
        $this->assertLessThan(2, count($result->added));
        // The manual-resync path is the one entry point with no adhoc
        // behind it, so the catch queues the retry itself.
        $this->assertCount(
            1,
            \core\task\manager::get_adhoc_tasks(coresync_adhoc::class),
            'a failed sync left nothing scheduled to try again'
        );
    }

    /**
     * The mirror was deleted out of band while the team was firm: the
     * pointer is cleared instead of dangling, and the caller is told.
     */
    public function test_dangling_mirror_while_firm_is_cleared(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, , $guide] = $this->setup_team();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        // A REAL actor, not the literal 99 that used to stand here
        // (audit A-2): unfreeze() now asks a positive authority
        // question, and an id belonging to no user row could never
        // answer one. The guide froze this team themselves, so
        // releasing it is the release strategy 1.19 C grants them - the
        // mirror behaviour under test is unchanged either way.
        $restored = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $coreid = (int) $restored->coregroupid;
        $this->assertNotEmpty($coreid);

        groups_delete_group($coreid);
        $result = freeze::sync_core_group($activity, (int) $restored->id, 99);

        $this->assertSame('nomirror', $result->status);
        $this->assertNull(groups::get($activity, (int) $restored->id)->coregroupid);
    }

    /**
     * Step 7: core asks this plugin before deleting one of its
     * memberships from a mirrored group. The answer is no while the
     * team is frozen and yes once it is released.
     *
     * This also pins the callback's NAME against core's own resolution
     * (component_callback prefers mod_selfselectadvanced_* and falls
     * back to selfselectadvanced_* for modules, silently).
     */
    public function test_allow_group_member_remove_wiring(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, $students, $guide] = $this->setup_team();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;

        // Pins the NAME. component_callback returns its DEFAULT when it
        // cannot resolve the function, and that default is true, so a
        // probe expecting true proves nothing: only a false answer
        // shows core found this plugin's function.
        $this->assertFalse(component_callback(
            'mod_selfselectadvanced',
            'allow_group_member_remove',
            [(int) $frozen->id, $coreid, (int) $students[1]->id],
            true
        ), 'core did not resolve this plugin\'s allow_group_member_remove callback');

        $this->assertFalse(groups_remove_member_allowed($coreid, (int) $students[1]->id));

        freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);

        $this->assertTrue(groups_remove_member_allowed($coreid, (int) $students[1]->id));
    }

    /**
     * 1g: the cap audit costs the same number of reads whatever the
     * roster size - one grouped count, not one COUNT per member.
     */
    public function test_membership_cap_violators_bulk(): void {
        global $DB;
        $this->resetAfterTest();

        [$smallactivity, $smallgroup] = $this->setup_team(2);
        [$bigactivity, $biggroup] = $this->setup_team(9);

        // Warm anything static both calls share, then measure.
        freeze::membership_cap_violators($smallactivity, $smallgroup);

        $before = $DB->perf_get_reads();
        freeze::membership_cap_violators($smallactivity, $smallgroup);
        $smallreads = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        freeze::membership_cap_violators($bigactivity, $biggroup);
        $bigreads = $DB->perf_get_reads() - $before;

        $this->assertSame(3, groups::count_confirmed((int) $smallgroup->id));
        $this->assertSame(10, groups::count_confirmed((int) $biggroup->id));
        $this->assertSame(
            $smallreads,
            $bigreads,
            "reads scaled with the roster: $smallreads for 3 members, $bigreads for 10"
        );
    }

    /**
     * D7-D2: the mirror is marked with the plugin uid as its idnumber,
     * and when a course group already claims that idnumber the mint
     * falls back to no idnumber rather than failing the freeze.
     */
    public function test_idnumber_collision_falls_back(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, , $guide, $course] = $this->setup_team();
        groups_create_group((object) [
            'courseid' => $course->id,
            'name' => 'Squatter',
            'idnumber' => $group->pluginuid,
        ]);

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);

        $this->assertNotEmpty($frozen->coregroupid);
        $core = groups_get_group((int) $frozen->coregroupid);
        $this->assertSame('', (string) $core->idnumber);
        $this->assertTrue(groups_is_member((int) $frozen->coregroupid, (int) $guide->id));
    }

    /**
     * D7-F1: losing the LAST enrolment in the course drops the seat -
     * core has already emptied its own groups, and a roster that keeps
     * counting the person is the divergence this closes. The mirror
     * follows through the queued adhoc, not an inline sync (this event
     * fires in bulk).
     */
    public function test_unenrolment_drops_member_and_syncs(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, $students, $guide, $course] = $this->setup_team();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $this->runAdhocTasks();
        $this->assertTrue(groups_is_member($coreid, (int) $students[1]->id));

        $this->unenrol((int) $course->id, (int) $students[1]->id);

        $this->assertSame(groups::STATUS_REMOVED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $frozen->id,
            'userid' => $students[1]->id,
        ]));
        $roster = json_decode(freeze::latest_snapshot((int) $frozen->id)->roster, true);
        $this->assertNotContains(
            (int) $students[1]->id,
            array_map(static fn(array $entry) => (int) $entry['userid'], $roster)
        );

        $this->runAdhocTasks();

        $this->assertFalse(groups_is_member($coreid, (int) $students[1]->id));
        $this->assertSame([], freeze::drift(groups::get($activity, (int) $frozen->id))['missing']);
    }

    /**
     * A second enrolment still standing means core keeps their group
     * memberships, so the plugin must eject nobody.
     */
    public function test_unenrolment_with_second_enrolment_is_ignored(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, $students, $guide, $course] = $this->setup_team();
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $selfplugin = enrol_get_plugin('self');
        $selfinstanceid = $selfplugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
        $selfinstance = $DB->get_record('enrol', ['id' => $selfinstanceid], '*', MUST_EXIST);
        $selfplugin->enrol_user($selfinstance, (int) $students[1]->id, $studentrole);

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $this->runAdhocTasks();

        // Drop only the manual enrolment.
        $manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($manual, (int) $students[1]->id);

        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $frozen->id,
            'userid' => $students[1]->id,
        ]));
        $this->assertTrue(groups_is_member($coreid, (int) $students[1]->id));
    }

    /**
     * Remove every enrolment a user has in a course, through the
     * enrolment API so core's own side effects and events run.
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
     * D7-B1 through the OTHER guide-writing path: a manager reassigning
     * the guide of a frozen team swaps the mirrored membership too.
     *
     * Without a hook here the outgoing guide sat in the course group
     * for as long as the team stayed frozen, and the incoming one never
     * appeared in it.
     */
    public function test_assign_guide_swaps_the_mirrored_guide(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $group, , $guide, $course] = $this->setup_team();
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $coreid = (int) $frozen->coregroupid;
        $this->assertTrue(groups_is_member($coreid, (int) $guide->id));

        $newguide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($newguide->id, $course->id, 'teacher');
        // A real manager, not a bare integer: assign_guide() asks
        // has_any_capability([:manage, :assignguide]) of its actor
        // (1.20.1, wave 3C), so the actor has to be someone the
        // capability system can answer for. Nothing else here moves -
        // the path under test is still the mirror swap.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'editingteacher');

        (new \mod_selfselectadvanced\local\api($activity))->lifecycle()->assign_guide(
            groups::get($activity, (int) $frozen->id),
            (int) $newguide->id,
            (int) $manager->id
        );

        $this->assertFalse(groups_is_member($coreid, (int) $guide->id), 'the old guide lingered in the mirror');
        $this->assertTrue(groups_is_member($coreid, (int) $newguide->id));
    }
}
