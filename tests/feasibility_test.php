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
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\quota\evaluator;
use mod_selfselectadvanced\local\quota\slots;
use mod_selfselectadvanced\local\quota\store as quotastore;
use mod_selfselectadvanced\local\state;

/**
 * The admission feasibility gate: inviting or accepting a member who
 * makes the composition unreachable within the group maximum is
 * refused, so a group can never fill up into a dead end it cannot
 * shrink out of.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\evaluator
 */
final class feasibility_test extends \advanced_testcase {
    /**
     * An exactly-five activity with the TCDEMO seat plan (2 x
     * Department Computer + 3 x distinct sub-department, no overlap)
     * and a cast of attributed students.
     *
     * @return array [activity, api, students keyed by shorthand]
     */
    private function setup_plan(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 5,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $cast = [
            'c1' => ['Computer', 'AI'],
            'c2' => ['Computer', 'ML'],
            'c3' => ['Computer', 'Hardware'],
            'p1' => ['Science', 'Physics'],
            'p2' => ['Science', 'Physics'],
            'b1' => ['Science', 'Biology'],
            'k1' => ['Science', 'Chemistry'],
        ];
        $students = [];
        foreach ($cast as $key => [$dept, $subdept]) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => $subdept], 2);
            $students[$key] = $user;
        }

        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        slots::create($activity, (object) [
            'mincount' => 3, 'dimension' => 'subdepartment', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ], (int) get_admin()->id);

        return [$activity, new api($activity), $students];
    }

    /**
     * The live-test scenario: with one Computer member and three
     * distinct Science members seated, the fifth seat MUST go to a
     * Computer student — a duplicate-sub-department Science student is
     * refused at invite time, before their seat is wasted.
     */
    public function test_invite_refused_when_plan_becomes_unreachable(): void {
        $this->resetAfterTest();

        [$activity, $api, $students] = $this->setup_plan();
        $leader = (int) $students['p1']->id;
        $group = $api->create_group($leader, 'Beta', 'T', '<p>b</p>', FORMAT_HTML);

        // Confirmed: Physics (leader) + ML + Biology + Chemistry.
        foreach (['c2', 'b1', 'k1'] as $key) {
            $api->invitations()->send($group, (int) $students[$key]->id, $leader);
            $api->invitations()->accept($group, (int) $students[$key]->id);
        }

        // A second Physics student can never complete the plan: one
        // Computer seat would remain with zero free seats behind it.
        $refusal = $api->gatekeeper()->can_invite($group, (int) $students['p2']->id);
        $this->assertSame('refusalcompositionunreachable', $refusal?->stringkey);

        // A Computer student completes it and is welcome.
        $this->assertNull($api->gatekeeper()->can_invite($group, (int) $students['c1']->id));
        $api->invitations()->send($group, (int) $students['c1']->id, $leader);
        $api->invitations()->accept($group, (int) $students['c1']->id);
        $this->assertTrue(evaluator::is_compliant($activity, (int) $group->id));
    }

    /**
     * The accept-time re-check: an invitation that was feasible when
     * sent is refused at accept after the world has moved on (here the
     * invitee's attributes changed under them), and invited members'
     * reserved seats count in the feasibility basis, so a doomed
     * second invitation is refused up front.
     */
    public function test_accept_recheck_catches_roster_drift(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students] = $this->setup_plan();
        $leader = (int) $students['c1']->id;
        $group = $api->create_group($leader, 'Drift', 'T', '<p>b</p>', FORMAT_HTML);

        foreach (['c2', 'p1'] as $key) {
            $api->invitations()->send($group, (int) $students[$key]->id, $leader);
            $api->invitations()->accept($group, (int) $students[$key]->id);
        }

        // Chemistry is invited while feasible...
        $api->invitations()->send($group, (int) $students['k1']->id, $leader);
        // ...then their attributes change to a duplicate Physics: the
        // acceptance re-check refuses what the invitation allowed.
        manager::set((int) $students['k1']->id, ['department' => 'Science', 'subdepartment' => 'Physics'], 2);
        $member = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => (int) $students['k1']->id,
        ], '*', MUST_EXIST);
        // The key changed with decision 82 and the coverage did not:
        // the acceptance door now asks the SHARED verdict's engine tier,
        // the same one join-acceptance has always asked, so both routes
        // to this membership give one answer. The tier names the group
        // it is talking about, which the old full-invited re-ask could
        // not - and the old basis would also have refused this acceptor
        // on OTHER people's unanswered invitations, which decision 60
        // forbids.
        $this->assertSame(
            'refusalcompositionunreachableteam',
            $api->gatekeeper()->can_accept($group, $member)?->stringkey
        );

        // And with Chemistry's reserved seat in the basis, inviting a
        // duplicate Physics into the last free seat is refused up front.
        manager::set((int) $students['k1']->id, ['department' => 'Science', 'subdepartment' => 'Chemistry'], 2);
        $this->assertSame(
            'refusalcompositionunreachable',
            $api->gatekeeper()->can_invite($group, (int) $students['p2']->id)?->stringkey
        );
    }

    /**
     * An exceeded counting-rule MAXIMUM is refused immediately: adding
     * members can never repair it.
     */
    public function test_exceeded_maximum_refused(): void {
        $this->resetAfterTest();

        [$activity, $api, $students] = $this->setup_plan();
        quotastore::save($activity, (object) [
            'dimension' => 'subdepartment',
            'rtype' => 'value',
            'value' => 'Physics',
            'mincount' => null,
            'maxcount' => 1,
        ], (int) get_admin()->id);
        $leader = (int) $students['p1']->id;
        $group = $api->create_group($leader, 'Capped', 'T', '<p>b</p>', FORMAT_HTML);

        $refusal = $api->gatekeeper()->can_invite($group, (int) $students['p2']->id);
        $this->assertSame('refusalcompositionmax', $refusal?->stringkey);
    }

    /**
     * The quota-exempt group override bypasses the gate, exactly as it
     * bypasses the submit-time compliance check.
     */
    public function test_quota_exempt_bypasses_gate(): void {
        $this->resetAfterTest();

        [$activity, $api, $students] = $this->setup_plan();
        $leader = (int) $students['p1']->id;
        $group = $api->create_group($leader, 'Exempt', 'T', '<p>b</p>', FORMAT_HTML);
        foreach (['c2', 'b1', 'k1'] as $key) {
            $api->invitations()->send($group, (int) $students[$key]->id, $leader);
            $api->invitations()->accept($group, (int) $students[$key]->id);
        }
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $group->id,
            ['quotaexempt' => 1],
            0
        );

        $api2 = new api($activity);
        $this->assertNull($api2->gatekeeper()->can_invite($group, (int) $students['p2']->id));
    }

    /**
     * A member who can fill no seat still consumes one: students with
     * missing attributes are refused once the free-seat slack is gone,
     * and welcome while slack remains.
     */
    public function test_attributeless_member_consumes_slack(): void {
        $this->resetAfterTest();

        [$activity, $api, $students] = $this->setup_plan();
        $generator = $this->getDataGenerator();
        $blank = $generator->create_user();
        $generator->enrol_user($blank->id, $activity->courseid(), 'student');

        $leader = (int) $students['c1']->id;
        $group = $api->create_group($leader, 'Slack', 'T', '<p>b</p>', FORMAT_HTML);

        // 1 confirmed, 4 seats free, 4 seats still required (1
        // Computer + 3 distinct): zero slack, so the attribute-less
        // student is refused; with a smaller plan they would fit.
        $this->assertSame(
            'refusalcompositionunreachable',
            $api->gatekeeper()->can_invite($group, (int) $blank->id)?->stringkey
        );
    }

    /**
     * Counting-rule MINIMUMS bound admission too: with more required
     * members of a value than free seats could ever supply, the
     * admission is refused — while enough slack remains, it is not.
     */
    public function test_rule_minimum_deficit_bounds_admission(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        quotastore::save($activity, (object) [
            'dimension' => 'gender',
            'rtype' => 'value',
            'value' => 'Female',
            'mincount' => 3,
            'maxcount' => null,
        ], (int) get_admin()->id);
        $males = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['gender' => 'Male', 'department' => 'Science'], 2);
            $males[] = $user;
        }
        $female = $generator->create_user();
        $generator->enrol_user($female->id, $course->id, 'student');
        manager::set((int) $female->id, ['gender' => 'Female', 'department' => 'Science'], 2);

        $api = new api($activity);
        $leader = (int) $males[0]->id;
        $group = $api->create_group($leader, 'Minimums', 'T', '<p>b</p>', FORMAT_HTML);
        $api->invitations()->send($group, (int) $males[1]->id, $leader);
        $api->invitations()->accept($group, (int) $males[1]->id);

        // Two males seated, three free seats, three Females required:
        // a third male leaves only two free seats for three Females.
        $this->assertSame(
            'refusalcompositionunreachable',
            $api->gatekeeper()->can_invite($group, (int) $males[2]->id)?->stringkey
        );
        // A Female shrinks the deficit with the seat she takes.
        $this->assertNull($api->gatekeeper()->can_invite($group, (int) $female->id));
    }

    /**
     * A team of four whose members hold none of the demanded values,
     * with room for seven, and a fifth candidate who holds none either.
     *
     * The rosters are built through the generator rather than through
     * invitations on purpose: the point of these fixtures is a roster
     * the admission gate would itself refuse to assemble.
     *
     * @param int $maxsize the group maximum
     * @return array [activity, api, group row, candidate user]
     */
    private function setup_bare_roster(int $maxsize): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => $maxsize,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => 'Elsewhere', 'gender' => 'Male'], 2);
            $students[] = $user;
        }

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Bare',
            'state' => state::FORMING,
        ]);
        for ($i = 1; $i < 4; $i++) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $students[$i]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $students[4]];
    }

    /**
     * Two unmet MINIMA on the SAME dimension demand disjoint people, so
     * their deficits ADD. Taking the larger of the two - as the gate
     * used to - admitted members into a team whose composition was
     * already out of reach.
     */
    public function test_same_dimension_minima_sum(): void {
        $this->resetAfterTest();

        [$activity, $api, $group, $candidate] = $this->setup_bare_roster(7);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        foreach (['DeptA', 'DeptB'] as $value) {
            $plugingen->create_quota([
                'activityid' => $activity->id(),
                'dimension' => 'department',
                'rtype' => 'value',
                'value' => $value,
                'mincount' => 2,
            ]);
        }

        $feasibility = evaluator::feasibility($activity, (int) $group->id, null);
        $this->assertSame(4, $feasibility->missing, 'Two DeptA plus two DeptB are four different people');

        // Four seated, five with the candidate, seven allowed: two free
        // seats can never carry four further members.
        $this->assertSame(
            'refusalcompositionunreachable',
            $api->gatekeeper()->can_invite($group, (int) $candidate->id)?->stringkey
        );
    }

    /**
     * The guard against over-correcting: minima on DIFFERENT dimensions
     * can be met by the SAME people, so their deficits do not add. A
     * naive "sum every deficit" would refuse this admission, and
     * refusing a legitimate invitation is as much a defect as admitting
     * a doomed one.
     */
    public function test_cross_dimension_minima_share_members(): void {
        $this->resetAfterTest();

        [$activity, $api, $group, $candidate] = $this->setup_bare_roster(7);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'gender',
            'rtype' => 'value',
            'value' => 'Female',
            'mincount' => 2,
        ]);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'DeptX',
            'mincount' => 2,
        ]);

        $feasibility = evaluator::feasibility($activity, (int) $group->id, null);
        $this->assertSame(2, $feasibility->missing, 'Two Female DeptX students satisfy both minima at once');
        $this->assertNull($api->gatekeeper()->can_invite($group, (int) $candidate->id));
    }

    /**
     * A counting rule and a seat plan on ONE dimension demand disjoint
     * people just as two rules do: the seat needs members of its value,
     * the rule needs members of another, and nobody holds two values of
     * one dimension.
     */
    public function test_rule_and_slot_on_one_dimension_sum(): void {
        $this->resetAfterTest();

        [$activity, , $group] = $this->setup_bare_roster(7);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'DeptA',
            'mincount' => 2,
        ]);
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'DeptB', 'allowoverlap' => 0,
        ], (int) get_admin()->id);

        $this->assertSame(4, evaluator::feasibility($activity, (int) $group->id, null)->missing);
    }
}
