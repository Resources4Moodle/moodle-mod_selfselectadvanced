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
use mod_selfselectadvanced\local\joinrequests;

/**
 * Decision 64: WHO may pass a join acceptance the rules refuse.
 *
 * The maintainer's live breach of 2026-08-07 (thinkinghat g=44): under
 * "SCOPE between 2 and 2" plus "at least 4 distinct departments" on
 * five seats, a student leader (SCE) accepted a second SCE member -
 * the engine refused on QUOTA, and the ACCEPT CONFIRM CLICK wrote a
 * QUOTA override in the leader's name. The response note read "Should
 * not allow it, but let us see". It allowed it.
 *
 * The ruling, verbatim intent: "Student leaders should not have been
 * allowed to accept such a request. In such case, the accept button
 * should also be disabled." Rules are the STAFF'S to declare breakable
 * (standing rule b); the leader's confirm tier carries consent notes
 * only, never rule codes.
 *
 * THE MATRIX below sweeps seat and composition conditions crossed with
 * both actors. Refusal cases and commit cases live in SEPARATE test
 * methods (the PostgreSQL poisoned-transaction trap).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\fit
 */
final class accept_authority_test extends \advanced_testcase {
    /**
     * A configurable world: rules, roster attributes, a requester.
     *
     * @param array $quotas rows for the quota generator (dimension/rtype/value/min/max)
     * @param string[] $memberdepts departments of confirmed members BEYOND the leader
     * @param string $leaderdept the leader's department
     * @param string $candidatedept the requester's department
     * @param int $maxsize seats
     * @param array $extra extra activity settings
     * @return array [activity, team, requester, leaderid, staff]
     */
    private function world(
        array $quotas,
        array $memberdepts,
        string $leaderdept,
        string $candidatedept,
        int $maxsize = 5,
        array $extra = []
    ): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => $maxsize,
            'maxlead' => 1,
            'maxmembership' => 1,
        ], $extra));
        $activity = activity::from_instance((int) $instance->id);
        foreach ($quotas as $q) {
            $plugingen->create_quota(array_merge(['activityid' => $activity->id()], $q));
        }

        $student = function (string $dept) use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => 'BCL'], 2);

            return $user;
        };

        $leader = $student($leaderdept);
        $team = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Alpha',
        ]);
        foreach ($memberdepts as $dept) {
            $plugingen->create_member([
                'groupid' => $team->id,
                'userid' => (int) $student($dept)->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        $requester = $student($candidatedept);
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        return [$activity, groups::get($activity, (int) $team->id), $requester, (int) $leader->id, $staff];
    }
    /**
     * The g44 shape verbatim: SCOPE between 2 and 2 plus at least four
     * distinct departments on five seats, an SCE leader, an SCE
     * requester - the composition the live breach admitted.
     *
     * @return array [activity, team, requester, leaderid, staff]
     */
    private function g44world(): array {
        return $this->world(
            [
                ['dimension' => 'department', 'rtype' => 'value', 'value' => 'SCOPE',
                    'mincount' => 2, 'maxcount' => 2],
                ['dimension' => 'department', 'rtype' => 'distinct', 'mincount' => 4],
            ],
            [],
            'SCE',
            'SCE'
        );
    }

    /**
     * The seat-and-composition matrix, each condition crossed with both
     * actors' expected decision surfaces.
     *
     * Every case: [quotas, memberdepts, leaderdept, candidatedept,
     * maxsize, leader-expectation, staff-expectation]. Expectations:
     * 'clean' (accept live, nothing to confirm), 'hard' (button
     * disabled, no bypass), 'override' (staff: accept live behind the
     * explicit bypass confirmation).
     */
    public static function matrix(): array {
        $scope22 = ['dimension' => 'department', 'rtype' => 'value', 'value' => 'SCOPE',
            'mincount' => 2, 'maxcount' => 2];
        $distinct4 = ['dimension' => 'department', 'rtype' => 'distinct', 'mincount' => 4];

        return [
            'no rules, seats free' => [
                [], [], 'SCE', 'SCE', 5, 'clean', 'clean',
            ],
            'the g44 breach: SCOPE 2-2 + distinct>=4, SCE joins SCE' => [
                [$scope22, $distinct4], [], 'SCE', 'SCE', 5, 'hard', 'override',
            ],
            'distinct>=4 alone made unreachable on four seats' => [
                [$distinct4], ['SCE'], 'SCE', 'SCE', 4, 'hard', 'override',
            ],
            'value maximum already met: third SCOPE walks up' => [
                [$scope22], ['SCOPE'], 'SCOPE', 'SCOPE', 5, 'hard', 'override',
            ],
            'min shortfall still reachable is no refusal at all' => [
                [['dimension' => 'department', 'rtype' => 'value', 'value' => 'SCOPE', 'mincount' => 2]],
                [], 'SCE', 'SCE', 5, 'clean', 'clean',
            ],
            'seats full (L2)' => [
                [], ['SCE'], 'SCE', 'SCE', 2, 'hard', 'override',
            ],
        ];
    }

    /**
     * The decision surfaces, both actors, every matrix row.
     *
     * The leader NEVER sees a live accept over a refused rule - no
     * bypassrules, no confirm path - and the staff answer is the
     * explicit override surface or clean, never an auto-confirm.
     *
     * @dataProvider matrix
     * @param array $quotas quota rows
     * @param string[] $memberdepts confirmed member departments
     * @param string $leaderdept leader department
     * @param string $candidatedept requester department
     * @param int $maxsize seats
     * @param string $leaderexpect clean|hard
     * @param string $staffexpect clean|override
     */
    public function test_decision_matrix(
        array $quotas,
        array $memberdepts,
        string $leaderdept,
        string $candidatedept,
        int $maxsize,
        string $leaderexpect,
        string $staffexpect
    ): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $team, $requester, $leaderid, $staff] =
            $this->world($quotas, $memberdepts, $leaderdept, $candidatedept, $maxsize);

        $request = joinrequests::request($activity, (int) $team->id, 'May I join', (int) $requester->id);

        $leader = joinrequests::accept_decision($activity, $request, $leaderid, $team);
        if ($leaderexpect === 'clean') {
            $this->assertTrue($leader->canaccept, 'a legal admission stays the leader\'s to make');
            $this->assertFalse($leader->confirmationrequired);
            $this->assertFalse($leader->confirmacceptrequired);
            $this->assertSame([], $leader->bypassrules);
        } else {
            $this->assertFalse($leader->canaccept, 'the accept control goes DISABLED for the leader');
            $this->assertNotSame('', $leader->hardreason, 'and the disabled button says why');
            $this->assertSame([], $leader->bypassrules, 'no bypass surface is offered to a student');
            $this->assertFalse($leader->confirmacceptrequired, 'no confirm click can carry a rule');
        }
        $this->assertObjectNotHasProperty('autobypassrules', $leader, 'the auto-bypass surface is gone');

        $staffdecision = joinrequests::accept_decision($activity, $request, (int) $staff->id, $team);
        if ($staffexpect === 'clean') {
            $this->assertTrue($staffdecision->canaccept);
            $this->assertSame([], $staffdecision->bypassrules);
        } else {
            $this->assertTrue($staffdecision->canaccept, 'staff authority keeps the deliberate path open');
            $this->assertTrue($staffdecision->confirmationrequired, 'behind the explicit override confirmation');
            $this->assertNotSame([], $staffdecision->bypassrules, 'with the rule named for the bypass form');
        }
        $sink->close();
    }

    /**
     * End to end, the g44 replay: the leader's accept THROWS, the
     * roster does not move, no override row appears, and the request
     * stays open for a decline. A crafted bypass[] from the same
     * student meets the capability refusal (decision 6).
     */
    public function test_leader_cannot_pass_the_g44_breach(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $team, $requester, $leaderid] = $this->g44world();
        $request = joinrequests::request($activity, (int) $team->id, 'May I join', (int) $requester->id);

        try {
            joinrequests::respond($activity, (int) $request->id, true, 'Should not allow it', $leaderid, [], true);
            $this->fail('The engine refusal must not be the leader\'s to confirm away');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * The crafted-bypass tamper: a student leader POSTING bypass codes
     * meets the capability refusal whatever the form rendered.
     */
    public function test_crafted_bypass_meets_the_capability(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $team, $requester, $leaderid] = $this->g44world();
        $request = joinrequests::request($activity, (int) $team->id, 'May I join', (int) $requester->id);

        try {
            joinrequests::respond($activity, (int) $request->id, true, 'crafted', $leaderid, ['QUOTA'], true);
            $this->fail('Posted bypass codes are staff-only');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinbypasscap', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * The aftermath of the two refusals above, in a method of its own
     * (commit after refusal poisons a PostgreSQL transaction): the
     * roster, the override store and the request row are untouched by
     * a refused leader accept.
     */
    public function test_the_refused_accept_leaves_no_trace(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $team, $requester, $leaderid] = $this->g44world();
        $request = joinrequests::request($activity, (int) $team->id, 'May I join', (int) $requester->id);

        try {
            joinrequests::respond($activity, (int) $request->id, true, '', $leaderid, [], true);
        } catch (\moodle_exception $e) {
            // The refusal itself is pinned in the sibling method.
            unset($e);
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_member', [
            'groupid' => (int) $team->id, 'userid' => (int) $requester->id,
        ]), 'the roster did not move');
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override'), 'no override row was authored');
        $this->assertSame('requested', $DB->get_field('selfselectadvanced_move', 'status', [
            'id' => (int) $request->id,
        ]), 'the request stays open for a decline with a note');
        $sink->close();
    }

    /**
     * The staff path stays whole: the same acceptance passes with an
     * explicit bypass and a written reason, the override row is
     * authored by the STAFF account, and the admission lands.
     */
    public function test_staff_override_still_passes_with_authorship(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $team, $requester, , $staff] = $this->g44world();
        $request = joinrequests::request($activity, (int) $team->id, 'May I join', (int) $requester->id);

        joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            'Cohort exception, dept head approved.',
            (int) $staff->id,
            ['QUOTA']
        );

        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => (int) $team->id, 'userid' => (int) $requester->id,
        ]), 'the deliberate staff admission lands');
        $override = $DB->get_record('selfselectadvanced_override', ['scope' => 'move'], '*', MUST_EXIST);
        $this->assertSame(
            (int) $staff->id,
            (int) $override->usermodified,
            'the override row is authored by the staff account, never the leader'
        );
        $sink->close();
    }

    /**
     * The consent tier survives decision 64 untouched: a maximum only
     * PENDING INVITATIONS push over needs the confirm click, commits
     * without it refused, and writes NO override row when confirmed -
     * no rule is broken, so no bypass may be recorded.
     */
    public function test_consent_tier_commits_without_an_override(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        [$activity, $team, $requester, $leaderid] = $this->world(
            [
                ['dimension' => 'department', 'rtype' => 'value', 'value' => 'SCOPE',
                    'mincount' => 1, 'maxcount' => 2],
            ],
            [],
            'SCOPE',
            'SCOPE'
        );
        $pending = $generator->create_user();
        $generator->enrol_user($pending->id, (int) $activity->cm()->course, 'student');
        manager::set((int) $pending->id, ['department' => 'SCOPE', 'subdepartment' => 'BCL'], 2);
        $plugingen->create_member([
            'groupid' => (int) $team->id,
            'userid' => (int) $pending->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);

        $request = joinrequests::request($activity, (int) $team->id, 'May I join', (int) $requester->id);
        $decision = joinrequests::accept_decision($activity, $request, $leaderid, $team);
        $this->assertTrue($decision->canaccept, 'no rule is broken');
        $this->assertTrue($decision->confirmacceptrequired, 'but the consequence must be read');
        $this->assertSame([], $decision->bypassrules, 'and nothing is bypassed');

        joinrequests::respond($activity, (int) $request->id, true, '', $leaderid, [], true);
        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => (int) $team->id, 'userid' => (int) $requester->id,
        ]));
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_override'),
            'consent is not a bypass: no override row exists to claim one'
        );
        $sink->close();
    }

    /**
     * L1 is the SOURCE team's minimum, and decision 64 takes it from
     * the target leader too: draining a team below its minimum is a
     * hard stop for the leader and an explicit override for staff.
     */
    public function test_source_minimum_is_not_the_target_leaders_to_waive(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        [$activity, $team, , $leaderid, $staff] = $this->world([], [], 'SCE', 'SCE', 5, [
            'minsize' => 2, 'maxmembership' => 1,
        ]);
        // A second team at exactly its minimum; one member asks to move.
        $student = function (string $dept) use ($generator, $activity): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, (int) $activity->cm()->course, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => 'BCL'], 2);

            return $user;
        };
        $srcleader = $student('SCE');
        $mover = $student('SCE');
        $source = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $srcleader->id,
            'name' => 'Donor',
        ]);
        $plugingen->create_member([
            'groupid' => $source->id,
            'userid' => (int) $mover->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $request = joinrequests::request(
            $activity,
            (int) $team->id,
            'Moving over',
            (int) $mover->id,
            (int) $source->id
        );

        $leader = joinrequests::accept_decision($activity, $request, $leaderid, $team);
        $this->assertFalse($leader->canaccept, 'the donor team\'s minimum is not the target leader\'s to waive');
        $this->assertSame('moveruleL1', $leader->hardkey);
        $this->assertSame([], $leader->bypassrules);

        $staffdecision = joinrequests::accept_decision($activity, $request, (int) $staff->id, $team);
        $this->assertTrue($staffdecision->canaccept);
        $this->assertContains('L1', $staffdecision->bypassrules, 'staff bypass the named rule deliberately');
        $sink->close();
    }
}
