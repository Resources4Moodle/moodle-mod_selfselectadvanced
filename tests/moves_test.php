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
            $api->moves()->stage((int) $students[0]->id, (int) $a->id, (int) $b->id, false,
                (int) $students[3]->id, 99);
            $this->fail('bad successor expected');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('successor must be a confirmed member', $e->getMessage());
        }

        // makeleader into a led group without consent: LEADR blocks.
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
}
