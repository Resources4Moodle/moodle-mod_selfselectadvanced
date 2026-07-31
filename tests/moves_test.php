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
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;

/**
 * Staged moves (spec 7, A4, B3): joint set validation over the net
 * post-state, atomic commit, leadership succession within the move,
 * bypass overrides, and the no-visible-change rule while pending.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\moves
 */
final class moves_test extends \advanced_testcase {
    /**
     * Two firm groups of two, four students, tight limits.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, api, students[], groupA, groupB]
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

        return [
            $activity,
            new api($activity),
            $students,
            groups::get($activity, (int) $a->id),
            groups::get($activity, (int) $b->id),
        ];
    }

    /**
     * 1.2.0: leadership replacement is a deliberate act. Without the
     * explicit flag the LEADR verdict blocks commit (and is not
     * code-bypassable); with it, the incumbent is demoted to member
     * and notified. A bad successor (non-member of the source) is
     * refused at stage time.
     */
    public function test_leader_replacement_consent(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 3,
            'maxlead' => 2,
            'maxmembership' => 2,
        ]);

        // Successor must be a confirmed member of the source group.
        try {
            $api->moves()->stage(
                (int) $students[0]->id,
                (int) $a->id,
                (int) $b->id,
                false,
                (int) $students[3]->id,
                99
            );
            $this->fail('bad successor expected');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('successor must be a confirmed member', $e->getMessage());
        }

        // Making leader in a led group without consent: LEADR blocks.
        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, true, null, 99);
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['LEADR']['ok']);
        $api->moves()->cancel((int) $move->id, 99);

        // With replaceleader consent: valid, commits, demotes, notifies.
        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, true, null, 99, true);
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertTrue($verdicts->permove[(int) $move->id]['LEADR']['ok']);
        $sink = $this->redirectMessages();
        $api->moves()->commit_set([(int) $move->id], 99);
        $messages = $sink->get_messages();
        $sink->close();

        $fresh = groups::get($activity, (int) $b->id);
        $this->assertSame((int) $students[4]->id, (int) $fresh->leaderid);
        $this->assertSame(0, (int) $DB->get_field('selfselectadvanced_member', 'isleader', [
            'groupid' => $b->id,
            'userid' => (int) $students[2]->id,
        ]));
        $demotednote = array_filter(
            $messages,
            fn($m) => (int) $m->useridto === (int) $students[2]->id
                && str_contains($m->fullmessage, 'appointed a new leader')
        );
        $this->assertNotEmpty($demotednote);
    }

    /**
     * A single move that would break the source minimum or the target
     * maximum is invalid alone, but a SWAP of two students commits as
     * a jointly-valid set (A4) - and nothing changes while pending.
     */
    public function test_swap_commits_as_a_set(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $a, $b] = $this->setup_two_groups();
        $s1 = (int) $students[1]->id;
        $s3 = (int) $students[3]->id;

        // Single move: source drops to 1 < min 2 AND target exceeds 2.
        $move1 = $api->moves()->stage($s1, (int) $a->id, (int) $b->id, false, null, 99);
        $verdict = $api->moves()->validate_set([(int) $move1->id]);
        $this->assertFalse($verdict->valid);
        $this->assertFalse($verdict->permove[(int) $move1->id]['L1']['ok']);
        $this->assertFalse($verdict->permove[(int) $move1->id]['L2']['ok']);

        // Pending move causes no visible change (spec 7).
        $this->assertSame(2, groups::count_confirmed((int) $a->id));
        $this->assertSame(2, groups::count_confirmed((int) $b->id));

        // Committing the invalid single move is refused.
        try {
            $api->moves()->commit_set([(int) $move1->id], 99);
            $this->fail('Expected joint-validation refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('jointly', $e->getMessage());
        }

        // The counter-move completes the swap; the SET is valid.
        $move2 = $api->moves()->stage($s3, (int) $b->id, (int) $a->id, false, null, 99);
        $set = [(int) $move1->id, (int) $move2->id];
        $this->assertTrue($api->moves()->validate_set($set)->valid);

        $sink = $this->redirectEvents();
        $count = $api->moves()->commit_set($set, 99);
        $committed = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\move_committed
        );
        $sink->close();

        $this->assertSame(2, $count);
        $this->assertCount(2, $committed);
        // The swap happened: s1 now in B, s3 now in A, sizes preserved.
        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $b->id,
            'userid' => $s1,
        ]));
        $this->assertSame(groups::STATUS_REMOVED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $a->id,
            'userid' => $s1,
        ]));
        $this->assertSame(2, groups::count_confirmed((int) $a->id));
        $this->assertSame(2, groups::count_confirmed((int) $b->id));
    }

    /**
     * Moving a leader out requires a successor, who takes over
     * atomically with an L3 check (B3); the moved user can be made
     * leader of the target in the same move.
     */
    public function test_leadership_moves(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $a, $b] = $this->setup_two_groups(['maxlead' => 1, 'maxmembership' => 2]);
        $leadera = (int) $students[0]->id;
        $membera = (int) $students[1]->id;

        // No successor: staging refuses.
        try {
            $api->moves()->stage($leadera, (int) $a->id, (int) $b->id, false, null, 99);
            $this->fail('Expected successor-required refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('successor', $e->getMessage());
        }

        // With a successor: valid only as part of a jointly-valid set
        // (B needs a leaver too; maxsize 2). Swap the two leaders.
        $leaderb = (int) $students[2]->id;
        $m1 = $api->moves()->stage($leadera, (int) $a->id, (int) $b->id, false, $membera, 99);
        $m2 = $api->moves()->stage($leaderb, (int) $b->id, (int) $a->id, true, (int) $students[3]->id, 99);
        $set = [(int) $m1->id, (int) $m2->id];
        $verdict = $api->moves()->validate_set($set);
        $this->assertTrue($verdict->valid);
        $api->moves()->commit_set($set, 99);

        // Successions applied: membera leads A... but m2 made leaderb
        // leader of A (makeleader on the target beats the successor
        // arrangement chronologically - both were validated).
        $a2 = groups::get($activity, (int) $a->id);
        $this->assertEquals($leaderb, $a2->leaderid);
        $b2 = groups::get($activity, (int) $b->id);
        $this->assertEquals((int) $students[3]->id, $b2->leaderid);
        // The old A-leader is a plain member of B now.
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_member', 'isleader', [
            'groupid' => $b->id,
            'userid' => $leadera,
        ]));
    }

    /**
     * B3 under grandfathering: an over-cap state cannot grow via
     * moves; a move-scope bypass override (P13) unblocks exactly the
     * named rule and is reported as bypassed.
     */
    public function test_bypass_override(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $a, $b] = $this->setup_two_groups();
        $s4 = (int) $students[4]->id; // Groupless student.

        // Placing the groupless student into full B: L2 fails.
        $move = $api->moves()->stage($s4, null, (int) $b->id, false, null, 99);
        $verdict = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdict->valid);
        $this->assertFalse($verdict->permove[(int) $move->id]['L2']['ok']);

        // Attach an L2 bypass (spec 9.4's override-backed placement).
        store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L2'], 99);
        $api2 = new api($activity);
        $verdict = $api2->moves()->validate_set([(int) $move->id]);
        $this->assertTrue($verdict->valid);
        $this->assertTrue($verdict->permove[(int) $move->id]['L2']['bypassed']);

        $api2->moves()->commit_set([(int) $move->id], 99);
        $this->assertSame(3, groups::count_confirmed((int) $b->id));
    }

    /**
     * Cancel clears a pending move without any change; foreign or
     * already-committed ids are refused.
     */
    public function test_cancel_and_scoping(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $a, $b] = $this->setup_two_groups();

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $b->id, false, null, 99);
        $api->moves()->cancel((int) $move->id, 99);
        $this->assertSame('cancelled', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $move->id]));
        $this->assertSame(2, groups::count_confirmed((int) $b->id));

        // A cancelled move cannot be committed (not pending any more).
        $this->assertSame(0, $api->moves()->commit_set([(int) $move->id], 99));

        // Cancelling it again is refused.
        $this->expectException(\dml_missing_record_exception::class);
        $api->moves()->cancel((int) $move->id, 99);
    }

    /**
     * L4 holds JOINTLY across a set: two staged moves adding the same
     * user to two different groups each look fine alone but together
     * exceed the membership cap, so the set refuses.
     */
    public function test_l4_validated_jointly_across_the_set(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups(['maxsize' => 3]);
        $free = (int) $students[4]->id;

        $move1 = $api->moves()->stage($free, null, (int) $a->id, false, null, 99);
        $move2 = $api->moves()->stage($free, null, (int) $b->id, false, null, 99);

        $single = $api->moves()->validate_set([(int) $move1->id]);
        $this->assertTrue($single->permove[(int) $move1->id]['L4']['ok']);

        $joint = $api->moves()->validate_set([(int) $move1->id, (int) $move2->id]);
        $this->assertFalse($joint->valid);
        $this->assertFalse($joint->permove[(int) $move1->id]['L4']['ok']);
        $this->assertFalse($joint->permove[(int) $move2->id]['L4']['ok']);
    }

    /**
     * SUCC: a staged leader-out move whose successor has since left
     * the source group refuses at validation, and the verdict is not
     * bypassable — a stale successor is corruption, not policy.
     */
    public function test_stale_successor_refused(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 3, 'minsize' => 1, 'maxmembership' => 2,
        ]);
        $leadera = (int) $students[0]->id;
        $succ = (int) $students[1]->id;

        $move = $api->moves()->stage($leadera, (int) $a->id, (int) $b->id, false, $succ, 99);
        $this->assertTrue($api->moves()->validate_set([(int) $move->id])->valid);

        // The successor leaves the source after staging.
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
            'groupid' => (int) $a->id,
            'userid' => $succ,
        ]);
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['SUCC']['ok']);
        $this->assertFalse($verdicts->permove[(int) $move->id]['SUCC']['bypassed']);
    }

    /**
     * A successor removed by the SAME set cannot inherit leadership:
     * the pair "leader out with successor S" + "S out" refuses.
     */
    public function test_successor_removed_by_same_set_refused(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 4, 'minsize' => 1, 'maxmembership' => 2,
        ]);
        $leadera = (int) $students[0]->id;
        $succ = (int) $students[1]->id;

        $move1 = $api->moves()->stage($leadera, (int) $a->id, (int) $b->id, false, $succ, 99);
        $move2 = $api->moves()->stage($succ, (int) $a->id, (int) $b->id, false, null, 99);

        $verdicts = $api->moves()->validate_set([(int) $move1->id, (int) $move2->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move1->id]['SUCC']['ok']);
    }

    /**
     * Committing a leader-out move promotes the successor with a
     * message and a leadership_transferred event — the manager path
     * writes the same audit trail as succession.
     */
    public function test_promoted_successor_notified_and_logged(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 3, 'minsize' => 1, 'maxmembership' => 2,
        ]);
        $leadera = (int) $students[0]->id;
        $succ = (int) $students[1]->id;

        $move = $api->moves()->stage($leadera, (int) $a->id, (int) $b->id, false, $succ, 99);

        $sink = $this->redirectMessages();
        $events = $this->redirectEvents();
        $api->moves()->commit_set([(int) $move->id], 99);
        $events->close();

        $this->assertEquals($succ, $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => $a->id]));
        $transfers = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\leadership_transferred
        ));
        $this->assertCount(1, $transfers);
        $this->assertSame('movesuccession', $transfers[0]->get_data()['other']['type']);

        $promoted = array_values(array_filter(
            $sink->get_messages(),
            static fn($m) => (int) $m->useridto === $succ && str_contains($m->subject, 'You now lead')
        ));
        $sink->close();
        $this->assertCount(1, $promoted);
    }

    /**
     * The QUOTA verdict evaluates the SEAT PLAN, not only counting
     * rules: with a slot template no roster here can satisfy, every
     * move set shows QUOTA not-ok instead of a false green.
     */
    public function test_quota_verdict_covers_seat_plan(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups(['maxsize' => 3]);
        \mod_selfselectadvanced\local\quota\slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 0,
        ]);

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $a->id, false, null, 99);
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->permove[(int) $move->id]['QUOTA']['ok']);
    }

    /**
     * SUCC judges the leader at APPLY time: a crown gained from an
     * earlier makeleader move in the same set makes the later
     * move-out a leader move, so it demands a successor — otherwise
     * the commit would leave the group leaderless.
     */
    public function test_intra_set_leadership_gain_requires_successor(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 3, 'minsize' => 1, 'maxmembership' => 2,
        ]);
        $member = (int) $students[1]->id;

        $crown = $api->moves()->stage($member, null, (int) $a->id, true, null, 99, true);
        $out = $api->moves()->stage($member, (int) $a->id, (int) $b->id, false, null, 99);

        $verdicts = $api->moves()->validate_set([(int) $crown->id, (int) $out->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $out->id]['SUCC']['ok']);
    }

    /**
     * The joint L4 credits a source removal only while the user is
     * still confirmed there: a staged move whose source membership
     * has since gone must not commit the user over their cap behind
     * a green verdict.
     */
    public function test_l4_ignores_stale_source_removal(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups(['maxsize' => 3]);
        $member = (int) $students[1]->id;
        $move = $api->moves()->stage($member, (int) $a->id, (int) $b->id, false, null, 99);

        // The source membership evaporates after staging; the user is
        // meanwhile confirmed elsewhere, sitting exactly at the cap.
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
            'groupid' => (int) $a->id,
            'userid' => $member,
        ]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $c = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'C',
            'state' => state::FORMING,
        ]);
        $plugingen->create_member([
            'groupid' => $c->id,
            'userid' => $member,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['L4']['ok']);
    }

    /**
     * A blank source is inferred when the student has exactly one
     * confirmed membership, and refused outright when they have
     * several — a silent second membership must be impossible.
     */
    public function test_source_inference(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 3, 'maxmembership' => 2,
        ]);
        $member = (int) $students[1]->id;

        // One membership (in A): the source is inferred.
        $move = $api->moves()->stage($member, null, (int) $b->id, false, null, 99);
        $this->assertEquals((int) $a->id, (int) $move->sourcegroupid);

        // Two memberships: staging without a source refuses.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $c = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'C',
            'state' => state::FORMING,
        ]);
        $plugingen->create_member([
            'groupid' => $c->id,
            'userid' => $member,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        try {
            $api->moves()->stage($member, null, (int) $b->id, false, null, 99);
            $this->fail('Expected source-required refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalmovesourcerequired', $e->errorcode);
        }
    }

    /**
     * A caller that MEANS the null source - "add a membership, leave
     * nothing" - says so, and then L4 is what judges it. Inference and
     * the ambiguity refusal are unchanged for every caller that does
     * not, which is the guarantee moveedit.php relies on.
     */
    public function test_an_explicit_null_source_stages_an_extra_membership(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 3, 'maxmembership' => 2,
        ]);
        $member = (int) $students[1]->id;

        // Explicit: no inference, and the cap has room for the second
        // membership, so the set validates.
        $extra = $api->moves()->stage($member, null, (int) $b->id, false, null, 99, false, true);
        $this->assertNull($extra->sourcegroupid);
        $this->assertTrue((bool) $api->moves()->validate_set([(int) $extra->id])->valid);

        // Defaults unchanged: one membership is still inferred.
        $inferred = $api->moves()->stage($member, null, (int) $b->id, false, null, 99);
        $this->assertEquals((int) $a->id, (int) $inferred->sourcegroupid);

        // Defaults unchanged: two memberships still refuse to be guessed.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $c = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'C',
            'state' => state::FORMING,
        ]);
        $plugingen->create_member([
            'groupid' => $c->id,
            'userid' => $member,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        try {
            $api->moves()->stage($member, null, (int) $b->id, false, null, 99);
            $this->fail('Expected source-required refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalmovesourcerequired', $e->errorcode);
        }
        $this->assertDebuggingNotCalled();
    }

    /**
     * Two groups of two under a seat plan wanting one Computer member,
     * plus a groupless fifth student.
     *
     * @param array $departments department per student, index 0-4
     * @return array [activity, api, students[], groupA, groupB]
     */
    private function setup_quota_pair(array $departments): array {
        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'minsize' => 1,
            'maxsize' => 3,
        ]);
        foreach ($students as $index => $student) {
            \mod_selfselectadvanced\local\attributes\manager::set(
                (int) $student->id,
                ['department' => $departments[$index] ?? 'Elsewhere'],
                2
            );
        }
        \mod_selfselectadvanced\local\quota\slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 0,
        ]);

        return [$activity, $api, $students, $a, $b];
    }

    /**
     * Mark one group quota-exempt.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     */
    private function exempt_group(activity $activity, int $groupid): void {
        store::save($activity, 'group', $groupid, ['quotaexempt' => 1], 0);
    }

    /**
     * Quota exemption is a PER-GROUP property: a move into an exempt
     * team is judged on the SOURCE's compliance alone, because the
     * exempt team is not held to the rules at all. Conjoining both
     * groups' compliance and only then allowing one set-level
     * exemption refused this legitimate move.
     */
    public function test_quota_exemption_is_per_group_target_exempt(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        // A keeps its Computer member when s1 leaves; B never had one.
        [$activity, $api, $students, $a, $b] = $this->setup_quota_pair([
            0 => 'Computer', 1 => 'Elsewhere', 2 => 'Elsewhere', 3 => 'Elsewhere', 4 => 'Elsewhere',
        ]);
        $this->exempt_group($activity, (int) $b->id);

        $api2 = new api($activity);
        $move = $api2->moves()->stage((int) $students[1]->id, (int) $a->id, (int) $b->id, false, null, 99);
        $verdicts = $api2->moves()->validate_set([(int) $move->id]);

        $this->assertTrue($verdicts->permove[(int) $move->id]['QUOTA']['ok']);
        $this->assertTrue($verdicts->valid);
        $this->assertSame(1, $api2->moves()->commit_set([(int) $move->id], 99));
        $sink->close();
    }

    /**
     * The mirror image: the SOURCE is exempt and non-compliant after
     * the move, the target complies, and the set is valid.
     */
    public function test_quota_exemption_is_per_group_source_exempt(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        // A has no Computer member at all; B keeps one throughout.
        [$activity, $api, $students, $a, $b] = $this->setup_quota_pair([
            0 => 'Elsewhere', 1 => 'Elsewhere', 2 => 'Computer', 3 => 'Elsewhere', 4 => 'Elsewhere',
        ]);
        $this->exempt_group($activity, (int) $a->id);

        $api2 = new api($activity);
        $move = $api2->moves()->stage((int) $students[1]->id, (int) $a->id, (int) $b->id, false, null, 99);
        $verdicts = $api2->moves()->validate_set([(int) $move->id]);

        $this->assertTrue($verdicts->permove[(int) $move->id]['QUOTA']['ok']);
        $sink->close();
    }

    /**
     * The negative control for the two above: with NEITHER group exempt
     * and the source left non-compliant, QUOTA still refuses. The fix
     * makes exemption per group; it does not wave the rules through.
     */
    public function test_quota_verdict_fails_when_neither_group_is_exempt(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        [$activity, $api, $students, $a, $b] = $this->setup_quota_pair([
            0 => 'Elsewhere', 1 => 'Elsewhere', 2 => 'Computer', 3 => 'Elsewhere', 4 => 'Elsewhere',
        ]);

        $move = $api->moves()->stage((int) $students[1]->id, (int) $a->id, (int) $b->id, false, null, 99);
        $verdicts = $api->moves()->validate_set([(int) $move->id]);

        $this->assertFalse($verdicts->permove[(int) $move->id]['QUOTA']['ok']);
        $this->assertFalse($verdicts->valid);
        $sink->close();
    }

    /**
     * A move with NO source into an exempt, non-compliant target stays
     * valid: there is no second group to hold to the rules, and the one
     * group in the move is exempt.
     */
    public function test_null_source_move_into_exempt_target(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        [$activity, $api, $students, $a] = $this->setup_quota_pair([
            0 => 'Elsewhere', 1 => 'Elsewhere', 2 => 'Elsewhere', 3 => 'Elsewhere', 4 => 'Elsewhere',
        ]);
        $this->exempt_group($activity, (int) $a->id);

        $api2 = new api($activity);
        $move = $api2->moves()->stage((int) $students[4]->id, null, (int) $a->id, false, null, 99);
        $verdicts = $api2->moves()->validate_set([(int) $move->id]);

        $this->assertNull($move->sourcegroupid);
        $this->assertTrue($verdicts->permove[(int) $move->id]['QUOTA']['ok']);
        $this->assertSame(1, $api2->moves()->commit_set([(int) $move->id], 99));
        $sink->close();
    }
}
