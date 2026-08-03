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

        // Decision 6: a bypassed commit needs a typed reason. Updated
        // here rather than relaxed in the service - the reason is the
        // point of the change.
        $unusednotifications = null;
        $unusedsync = null;
        $api2->moves()->commit_set(
            [(int) $move->id],
            99,
            false,
            $unusednotifications,
            $unusedsync,
            'Agreed with the guide: one over on Team B for this term.'
        );
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
        ], (int) get_admin()->id);

        $move = $api->moves()->stage((int) $students[4]->id, null, (int) $a->id, false, null, 99);
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->permove[(int) $move->id]['QUOTA']['ok']);
    }

    /**
     * SUCC judges the leader at APPLY time, and both ways round: a
     * crown GAINED earlier in the same set makes a later move-out a
     * leader move that demands a successor, and a crown LOST earlier
     * in the same set relieves the outgoing incumbent of naming one.
     *
     * The gain is staged through the successor route because that is
     * the only route left: a makeleader move can no longer designate
     * somebody already inside the target team (TGT), so a crown
     * gained by makeleader always belongs to an incomer, who cannot
     * also be moved out of a team they have not joined yet. The
     * makeleader branch of the same tracking is exercised below, in
     * the direction it can still reach.
     */
    public function test_intra_set_leadership_gain_requires_successor(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 4, 'minsize' => 1, 'maxmembership' => 2,
        ]);
        // A third member, so A still satisfies L1 once two leave and
        // only the leadership verdicts are in play.
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => $a->id,
            'userid' => (int) $students[4]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $leader = (int) $students[0]->id;
        $member = (int) $students[1]->id;

        // Staged first, so it applies first: A's crown passes to
        // $member, who is a plain member when their own move-out is
        // staged (stage() looks at today's leader and asks nothing).
        $handover = $api->moves()->stage($leader, (int) $a->id, (int) $b->id, false, $member, 99);
        $out = $api->moves()->stage($member, (int) $a->id, (int) $b->id, false, null, 99);

        $verdicts = $api->moves()->validate_set([(int) $handover->id, (int) $out->id]);
        $this->assertFalse($verdicts->valid);
        // The verdict EXISTS because the crown was tracked to $member:
        // judged against today's roster the move-out is not a leader
        // move at all and no SUCC verdict would be raised.
        $this->assertArrayHasKey('SUCC', $verdicts->permove[(int) $out->id]);
        $this->assertFalse($verdicts->permove[(int) $out->id]['SUCC']['ok']);
        // The handover's own SUCC refuses for the sibling reason: the
        // successor it names is removed from the source by this very
        // set, so promoting them would leave A leaderless anyway.
        $this->assertFalse($verdicts->permove[(int) $handover->id]['SUCC']['ok']);
    }

    /**
     * The other direction of the same tracking: an incoming leader
     * takes the target team's crown inside the set, so the incumbent's
     * own move out of it is no longer a leader move and raises no SUCC
     * verdict - apply() re-reads the leader and performs no succession
     * there either. Judged against today's roster instead, the set
     * would demand a successor for a crown its own first move has
     * already taken away.
     */
    public function test_intra_set_leadership_loss_needs_no_successor(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'maxsize' => 4, 'minsize' => 1, 'maxmembership' => 2,
        ]);
        $incomer = (int) $students[4]->id;
        $incumbent = (int) $students[2]->id;

        // The incomer takes B's crown; the incumbent leaves for A,
        // naming the successor stage() demands of today's leader.
        $crown = $api->moves()->stage($incomer, null, (int) $b->id, true, null, 99, true);
        $outgoing = $api->moves()->stage(
            $incumbent,
            (int) $b->id,
            (int) $a->id,
            false,
            (int) $students[3]->id,
            99
        );

        $verdicts = $api->moves()->validate_set([(int) $crown->id, (int) $outgoing->id]);
        $this->assertTrue($verdicts->valid);
        $this->assertArrayNotHasKey('SUCC', $verdicts->permove[(int) $outgoing->id]);
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
     * A move into a team the student is ALREADY a confirmed member of
     * is refused at the seam: it gains them nothing, and its source
     * half still deletes a membership. Nothing is staged and nothing
     * is removed.
     */
    public function test_a_move_into_the_students_own_team_is_refused(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'minsize' => 1, 'maxsize' => 6, 'maxmembership' => 2,
        ]);
        $wanderer = (int) $students[1]->id;
        // Confirmed in BOTH teams - the roster a staff "move" used to
        // tidy by deleting the source membership for nothing.
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => $b->id,
            'userid' => $wanderer,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        try {
            $api->moves()->stage($wanderer, (int) $a->id, (int) $b->id, false, null, 99);
            $this->fail('Expected the already-in-target refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalmovetargetalready', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('selfselectadvanced_move', [
            'activityid' => $activity->id(),
        ]));
        $this->assertSame(2, groups::count_memberships($activity, $wanderer));
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $a->id,
            'userid' => $wanderer,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * The stale queue, which needs no staff error at all: a correct
     * move is staged, the student then reaches the target by another
     * route entirely (an invitation they accept), and the queued
     * commit - still selected, still green when it was staged - is
     * refused on the roster commit_set() reads inside its own locks.
     * TGT is the only verdict that fails, and the membership the
     * commit would have deleted survives.
     */
    public function test_a_queued_move_the_student_overtook_is_refused_at_commit(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        [$activity, $api, $students, $a] = $this->setup_two_groups([
            'minsize' => 1, 'maxsize' => 6, 'maxmembership' => 2,
        ]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        // A forming team, so the ordinary invitation route is open.
        $c = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'C',
            'state' => state::FORMING,
        ]);
        $mover = (int) $students[1]->id;

        // Staged against a correct roster: the set validates.
        $move = $api->moves()->stage($mover, (int) $a->id, (int) $c->id, false, null, 99);
        $this->assertTrue($api->moves()->validate_set([(int) $move->id])->valid);

        // The student arrives in the target by another route.
        $target = groups::get($activity, (int) $c->id);
        $api->invitations()->send($target, $mover, (int) $students[4]->id);
        $api->invitations()->accept($target, $mover);
        $this->assertSame(2, groups::count_memberships($activity, $mover));

        // The same move, untouched, is now refused - by TGT and by
        // nothing else.
        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['TGT']['ok']);
        $this->assertSame(['TGT'], array_keys(array_filter(
            $verdicts->permove[(int) $move->id],
            static fn($verdict) => !$verdict['ok']
        )));

        try {
            $api->moves()->commit_set([(int) $move->id], 99);
            $this->fail('Expected the commit to refuse the stale move');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesetinvalid', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $a->id,
            'userid' => $mover,
            'status' => groups::STATUS_CONFIRMED,
        ]));
        $this->assertSame(2, groups::count_memberships($activity, $mover));
        // Still pending: the manager cancels it, the engine does not
        // quietly consume it.
        $this->assertSame('pending', $DB->get_field('selfselectadvanced_move', 'status', [
            'id' => $move->id,
        ]));
        $sink->close();
    }

    /**
     * TGT is not bypassable, and that is the whole point: no staff
     * repair deletes a membership in exchange for nothing, so the
     * override hatch must not reopen the door. A move-scope override
     * naming every code the UI can produce AND the code itself leaves
     * the verdict red and the commit refused.
     */
    public function test_no_override_reopens_a_move_into_the_students_own_team(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'minsize' => 1, 'maxsize' => 6, 'maxmembership' => 2,
        ]);
        $mover = (int) $students[1]->id;
        $move = $api->moves()->stage($mover, (int) $a->id, (int) $b->id, false, null, 99);

        // The roster moves on under the staged row.
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => $b->id,
            'userid' => $mover,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        store::save($activity, 'move', (int) $move->id, [
            'rulesbypassed' => 'L1,L2,L3,L4,QUOTA,TGT',
        ], 99);

        $verdicts = $api->moves()->validate_set([(int) $move->id]);
        $this->assertFalse($verdicts->valid);
        $this->assertFalse($verdicts->permove[(int) $move->id]['TGT']['ok']);
        $this->assertFalse($verdicts->permove[(int) $move->id]['TGT']['bypassed']);
        // Null both hand-backs: this is the outermost path, the one a
        // manager's Commit button takes, and a typed reason is on the
        // table - the strongest form of the ask.
        $nodeferrednotifications = null;
        $nodeferredsync = null;
        try {
            $api->moves()->commit_set(
                [(int) $move->id],
                99,
                false,
                $nodeferrednotifications,
                $nodeferredsync,
                'staff insists'
            );
            $this->fail('Expected the commit to refuse despite the override');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesetinvalid', $e->errorcode);
        }
        $this->assertSame(2, groups::count_memberships($activity, $mover));
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $a->id,
            'userid' => $mover,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * The L4 cap holds over the SET, counting TEAMS and not move rows.
     * Two moves out of one source used to subtract that source once
     * per row, so a cap of ONE membership committed two behind two
     * verdicts both reading "Would belong to 1 of 1 groups".
     */
    public function test_the_membership_cap_is_not_evaded_by_two_moves_out_of_one_source(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'minsize' => 1, 'maxsize' => 6, 'maxmembership' => 1,
        ]);
        $c = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'C',
            'state' => state::FIRM,
        ]);
        $mover = (int) $students[1]->id;

        $m1 = $api->moves()->stage($mover, (int) $a->id, (int) $b->id, false, null, 99);
        $m2 = $api->moves()->stage($mover, (int) $a->id, (int) $c->id, false, null, 99);

        // Either alone is a genuine one-for-one move and validates.
        $this->assertTrue($api->moves()->validate_set([(int) $m1->id])->valid);
        $this->assertTrue($api->moves()->validate_set([(int) $m2->id])->valid);

        // Together they are two teams entered against one left.
        $joint = $api->moves()->validate_set([(int) $m1->id, (int) $m2->id]);
        $this->assertFalse($joint->valid);
        $this->assertFalse($joint->permove[(int) $m1->id]['L4']['ok']);
        $this->assertFalse($joint->permove[(int) $m2->id]['L4']['ok']);
        $this->assertSame(
            get_string('moveruleL4', 'mod_selfselectadvanced', (object) ['after' => 2, 'max' => 1]),
            $joint->permove[(int) $m1->id]['L4']['reason']
        );

        try {
            $api->moves()->commit_set([(int) $m1->id, (int) $m2->id], 99);
            $this->fail('Expected the set to be refused at the cap');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesetinvalid', $e->errorcode);
        }
        $this->assertSame(1, groups::count_memberships($activity, $mover));
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $a->id,
            'userid' => $mover,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * The L1 figure a manager is shown is the roster the commit
     * actually leaves behind. One member taken out of one source by
     * two moves was subtracted twice, and a compliant set was refused
     * on "Source keeps 0 confirmed members (minimum 1)" for a team
     * that keeps one - on the repair path, with the message inviting
     * staff to attach an override to a set that never broke a rule.
     */
    public function test_the_l1_figure_equals_the_roster_the_commit_leaves(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();

        [$activity, $api, $students, $a, $b] = $this->setup_two_groups([
            'minsize' => 1, 'maxsize' => 6, 'maxmembership' => 3,
        ]);
        $c = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'C',
            'state' => state::FIRM,
        ]);
        // A holds its leader and this member: one departure, twice
        // staged, must leave one behind.
        $mover = (int) $students[1]->id;
        $m1 = $api->moves()->stage($mover, (int) $a->id, (int) $b->id, false, null, 99);
        $m2 = $api->moves()->stage($mover, (int) $a->id, (int) $c->id, false, null, 99);

        $verdicts = $api->moves()->validate_set([(int) $m1->id, (int) $m2->id]);
        $this->assertTrue($verdicts->valid);
        $this->assertSame(
            get_string('moveruleL1', 'mod_selfselectadvanced', (object) ['after' => 1, 'min' => 1]),
            $verdicts->permove[(int) $m1->id]['L1']['reason']
        );

        $this->assertSame(2, $api->moves()->commit_set([(int) $m1->id, (int) $m2->id], 99));
        // The figure and the roster are the same number.
        $this->assertSame(1, groups::count_confirmed((int) $a->id));
        $sink->close();
    }

    /**
     * A BLANK source is inferred to the student's only team - which is
     * the target itself whenever a manager tries to place somebody
     * where they already are. classes/form/move_form.php cannot see
     * that shape (its check needs a source the manager typed), so the
     * seam refuses it. The leader case is why it matters: committing
     * it demoted the leader in silence, handing the crown to the
     * successor stage() had just forced the manager to name.
     */
    public function test_a_source_inferred_to_be_the_target_is_refused(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a] = $this->setup_two_groups([
            'minsize' => 1, 'maxsize' => 6, 'maxmembership' => 2,
        ]);
        $leader = (int) $students[0]->id;
        $plain = (int) $students[1]->id;

        // Plain member, blank source, target = their own team.
        try {
            $api->moves()->stage($plain, null, (int) $a->id, false, null, 99);
            $this->fail('Expected the same-group refusal (inferred source)');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesamegroup', $e->errorcode);
        }
        // The leader, with a successor named: the demotion shape.
        try {
            $api->moves()->stage($leader, null, (int) $a->id, false, $plain, 99);
            $this->fail('Expected the same-group refusal (leader, inferred source)');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesamegroup', $e->errorcode);
        }
        // Typed rather than inferred: the same refusal, from the seam
        // and not only from the form.
        try {
            $api->moves()->stage($plain, (int) $a->id, (int) $a->id, false, null, 99);
            $this->fail('Expected the same-group refusal (explicit source)');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovesamegroup', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_move', [
            'activityid' => $activity->id(),
        ]));
        $this->assertSame($leader, (int) groups::get($activity, (int) $a->id)->leaderid);
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
        ], (int) get_admin()->id);

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
