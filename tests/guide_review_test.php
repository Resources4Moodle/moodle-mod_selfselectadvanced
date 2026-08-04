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
use mod_selfselectadvanced\local\guides;
use mod_selfselectadvanced\local\state;

/**
 * Guide review: T2 submit, T3 return, T4 approve, the L5 counting
 * basis and boundaries, the A5 manager-assigns queue (spec 6.5, 4A.5).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\guides
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class guide_review_test extends \advanced_testcase {
    /**
     * Create a course, an instance, students and guides.
     *
     * @param array $settings instance setting overrides
     * @param int $students number of students
     * @param int $guidecount number of non-editing teachers (guides)
     * @return array [activity, api, students[], guides[]]
     */
    private function setup_activity(array $settings = [], int $students = 3, int $guidecount = 2): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
        ], $settings));

        $users = [];
        for ($i = 0; $i < $students; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $users[] = $user;
        }
        $guideusers = [];
        for ($i = 0; $i < $guidecount; $i++) {
            $guide = $generator->create_user();
            $generator->enrol_user($guide->id, $course->id, 'teacher');
            $guideusers[] = $guide;
        }

        $activity = activity::from_instance((int) $instance->id);

        return [$activity, new api($activity), $users, $guideusers];
    }

    /**
     * The plugin generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * T2 leader-selects: submission validates L1, the guide's L5 slot
     * and the leader; the guide is notified; state and timestamps move.
     */
    public function test_submit_leader_selects(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $guideusers] = $this->setup_activity([
            'minsize' => 2,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $leader = (int) $students[0]->id;
        $guide = (int) $guideusers[0]->id;
        $group = $api->create_group($leader, 'Submit', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);

        // L1 one below: 1 confirmed < minsize 2, with the reason shown.
        $refusal = $api->gatekeeper()->can_submit($group, $leader);
        $this->assertSame('refusalbelowminsize', $refusal?->stringkey);

        // Reach the minimum; only the leader may submit.
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $this->assertSame(
            'refusalnotleader',
            $api->gatekeeper()->can_submit($group, (int) $students[1]->id)?->stringkey
        );
        $this->assertNull($api->gatekeeper()->can_submit($group, $leader));

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        $fresh = $api->lifecycle()->submit($group, $guide, $leader);
        $submitted = array_filter(
            $eventsink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\group_submitted
        );
        $eventsink->close();
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assertCount(1, $submitted);
        $this->assertSame(state::PENDING_GUIDE, $fresh->state);
        $this->assertEquals($guide, $fresh->guideid);
        $this->assertNotEmpty($fresh->timesubmitted);
        $this->assertNotEmpty(array_filter($messages, fn($m) => (int) $m->useridto === $guide));

        // L5 basis: the pending_guide group occupies the guide's slot.
        $this->assertSame(1, groups::count_guiding($activity, $guide));
    }

    /**
     * L5 boundary at submission: a guide at capacity is excluded from
     * the selectable list and refused, with the load figures shown.
     */
    public function test_guide_capacity_boundary(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $guideusers] = $this->setup_activity([
            'maxguided' => 1,
            'maxlead' => 2,
            'maxmembership' => 2,
        ]);
        $guide = (int) $guideusers[0]->id;

        // Fill the guide's single slot.
        $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[1]->id,
            'name' => 'Occupies',
            'state' => state::PENDING_GUIDE,
            'guideid' => $guide,
        ]);

        $this->assertSame('refusalguidecap', $api->gatekeeper()->can_take_guide($guide)?->stringkey);
        $selectable = guides::selectable($activity, $api->gatekeeper()->resolver());
        $this->assertArrayNotHasKey($guide, $selectable);
        // The other guide is selectable and shows its load.
        $other = (int) $guideusers[1]->id;
        $this->assertArrayHasKey($other, $selectable);
        $this->assertSame(0, $selectable[$other]->used);
        $this->assertSame(1, $selectable[$other]->max);

        // Submitting to the full guide is refused atomically.
        $leader = (int) $students[0]->id;
        $group = $api->create_group($leader, 'Wants', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);
        try {
            $api->lifecycle()->submit($group, $guide, $leader);
            $this->fail('Expected guide-capacity refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('already guiding', $e->getMessage());
        }
    }

    /**
     * T3: only the assigned guide returns, a comment is mandatory, the
     * state returns to forming, the L5 slot releases immediately and
     * the comment reaches the leader; resubmission to another guide works.
     */
    public function test_return_flow(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $guideusers] = $this->setup_activity(['maxlead' => 1, 'maxmembership' => 2]);
        $leader = (int) $students[0]->id;
        $guide = (int) $guideusers[0]->id;
        $group = $api->create_group($leader, 'Returned', 'T', '<p>b</p>', FORMAT_HTML);
        $group = $api->lifecycle()->submit(groups::get($activity, (int) $group->id), $guide, $leader);

        // Only the assigned guide.
        $this->assertSame(
            'refusalnotassignedguide',
            $api->gatekeeper()->can_return($group, (int) $guideusers[1]->id)?->stringkey
        );

        // Comment mandatory.
        try {
            $api->lifecycle()->return_group($group, '   ', $guide);
            $this->fail('Expected comment-required exception');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('comment', $e->getMessage());
        }

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        $fresh = $api->lifecycle()->return_group($group, 'Please add a second member.', $guide);
        $returned = array_values(array_filter(
            $eventsink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\group_returned
        ));
        $eventsink->close();
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assertSame(state::FORMING, $fresh->state);
        $this->assertNull($fresh->guideid);
        $this->assertSame('Please add a second member.', $fresh->returncomment);
        // DATA-002: the format is written in the same transaction as
        // the text - the plain queue-return door passes no format, so
        // the default must be the schema's own plain-text default -
        // and it rides the event payload beside the comment. Both
        // sides cast, because Moodle's FORMAT_* constants are strings.
        $this->assertSame((int) FORMAT_PLAIN, (int) $fresh->returncommentformat);
        $this->assertSame('Please add a second member.', $returned[0]->get_data()['other']['comment']);
        $this->assertSame((int) FORMAT_PLAIN, (int) $returned[0]->get_data()['other']['commentformat']);
        // Slot released immediately (spec 4A.5, decision A11).
        $this->assertSame(0, groups::count_guiding($activity, $guide));
        // The leader received the comment.
        $leadermsgs = array_filter($messages, fn($m) => (int) $m->useridto === $leader);
        $this->assertStringContainsString('second member', reset($leadermsgs)->fullmessage);

        // Resubmit to a different guide.
        $fresh = $api->lifecycle()->submit($fresh, (int) $guideusers[1]->id, $leader);
        $this->assertEquals((int) $guideusers[1]->id, $fresh->guideid);
    }

    /**
     * T4: only the assigned guide approves; the state becomes firm with
     * timeapproved set; approval is irreversible; the atomic L5
     * re-check refuses when the guide's load exceeds a tightened cap;
     * all members are notified.
     */
    public function test_approve_flow(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $guideusers] = $this->setup_activity(['maxlead' => 1, 'maxmembership' => 2]);
        $leader = (int) $students[0]->id;
        $guide = (int) $guideusers[0]->id;
        $group = $api->create_group($leader, 'Approve', 'T', '<p>b</p>', FORMAT_HTML);
        $group = $api->lifecycle()->submit(groups::get($activity, (int) $group->id), $guide, $leader);

        $this->assertSame(
            'refusalnotassignedguide',
            $api->gatekeeper()->can_approve($group, (int) $guideusers[1]->id)?->stringkey
        );

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        $fresh = $api->lifecycle()->approve($group, $guide);
        $approved = array_filter(
            $eventsink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\group_approved
        );
        $eventsink->close();
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assertCount(1, $approved);
        $this->assertSame(state::FIRM, $fresh->state);
        $this->assertNotEmpty($fresh->timeapproved);
        // Every confirmed member notified (single-member group: the leader).
        $this->assertNotEmpty(array_filter($messages, fn($m) => (int) $m->useridto === $leader));
        // Approval keeps occupying the guide's load (spec 4A.5).
        $this->assertSame(1, groups::count_guiding($activity, $guide));

        // Irreversible: no return from firm (S2 guard).
        $this->assertSame('refusalwrongstate', $api->gatekeeper()->can_return($fresh, $guide)?->stringkey);
        $this->assertSame('refusalwrongstate', $api->gatekeeper()->can_approve($fresh, $guide)?->stringkey);

        // Atomic L5 re-check on approve: overload the guide beyond a
        // tightened cap, then try to approve a second pending group.
        $DB->set_field('selfselectadvanced', 'maxguided', 1, ['id' => $activity->id()]);
        $activity2 = activity::from_instance($activity->id());
        $api2 = new api($activity2);
        $second = $this->plugingen()->create_group([
            'activityid' => $activity2->id(),
            'leaderid' => (int) $students[1]->id,
            'name' => 'Second',
            'state' => state::PENDING_GUIDE,
            'guideid' => $guide,
        ]);
        $secondrow = groups::get($activity2, (int) $second->id);
        $this->assertSame('refusalguidecap', $api2->gatekeeper()->can_approve($secondrow, $guide)?->stringkey);
    }

    /**
     * DATA-002's two-door interleave, pinned: a rich-text return
     * through the review door stores FORMAT_HTML with its comment, and
     * a later plain return through the queue door replaces BOTH text
     * and format - the stale-format mismatch the old companion write
     * left behind (an HTML format surviving against a PARAM_TEXT
     * comment) can no longer occur, because there is no second write
     * for a crash or an interleave to split from the first.
     */
    public function test_return_comment_format_travels_with_the_text(): void {
        $this->resetAfterTest();
        $messagesink = $this->redirectMessages();

        [$activity, $api, $students, $guideusers] = $this->setup_activity(['maxlead' => 1, 'maxmembership' => 2]);
        $leader = (int) $students[0]->id;
        $guide = (int) $guideusers[0]->id;
        $group = $api->create_group($leader, 'Formats', 'T', '<p>b</p>', FORMAT_HTML);
        $group = $api->lifecycle()->submit(groups::get($activity, (int) $group->id), $guide, $leader);

        // Door one: review.php's editor passes FORMAT_HTML explicitly.
        $fresh = $api->lifecycle()->return_group($group, '<p>Use <em>fewer</em> words.</p>', $guide, FORMAT_HTML);
        $this->assertSame('<p>Use <em>fewer</em> words.</p>', $fresh->returncomment);
        $this->assertSame((int) FORMAT_HTML, (int) $fresh->returncommentformat);

        // Door two: guide.php's queue return is PARAM_TEXT and passes
        // nothing, taking the plain default. Text AND format move.
        $fresh = $api->lifecycle()->submit($fresh, $guide, $leader);
        $fresh = $api->lifecycle()->return_group($fresh, 'Shorter now <ok>.', $guide);
        $this->assertSame('Shorter now <ok>.', $fresh->returncomment);
        $this->assertSame((int) FORMAT_PLAIN, (int) $fresh->returncommentformat);
        $messagesink->close();
    }

    /**
     * A5 manager-assigns mode: submission without a guide queues the
     * group; the manager assigns a guide with the L5 gate applied.
     */
    public function test_manager_assigns_mode(): void {
        $this->resetAfterTest();

        [$activity, $api, $students, $guideusers] = $this->setup_activity([
            'guidemode' => 1,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $leader = (int) $students[0]->id;
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');

        $group = $api->create_group($leader, 'Queued', 'T', '<p>b</p>', FORMAT_HTML);
        $fresh = $api->lifecycle()->submit(groups::get($activity, (int) $group->id), null, $leader);
        $this->assertSame(state::PENDING_GUIDE, $fresh->state);
        $this->assertNull($fresh->guideid);

        $guide = (int) $guideusers[0]->id;
        $assigned = $api->lifecycle()->assign_guide($fresh, $guide, (int) $manager->id);
        $this->assertEquals($guide, $assigned->guideid);
        $this->assertSame(1, groups::count_guiding($activity, $guide));

        // The assigned guide can approve.
        $this->assertNull($api->gatekeeper()->can_approve($assigned, $guide));
    }

    /**
     * assign_guide refuses a guide at capacity (under the per-guide
     * lock), but re-assigning the guide a group ALREADY has is a
     * cap-neutral no-op and must not be refused — that guide's slot is
     * held by this very group.
     */
    public function test_assign_guide_capacity_and_reassign(): void {
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

        [$activity, $api, $students, $guideusers] = $this->setup_activity([
            'guidemode' => 1,
            'maxguided' => 1,
            'maxlead' => 2,
            'maxmembership' => 2,
        ], 3, 2);
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');
        $guide = (int) $guideusers[0]->id;

        $held = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[1]->id,
            'name' => 'Holds',
            'state' => state::PENDING_GUIDE,
            'guideid' => $guide,
        ]);
        $queued = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Queued',
            'state' => state::PENDING_GUIDE,
        ]);

        // A second group cannot be assigned to the full guide.
        try {
            $api->lifecycle()->assign_guide(groups::get($activity, (int) $queued->id), $guide, (int) $manager->id);
            $this->fail('Expected guide-capacity refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('already guiding', $e->getMessage());
        }

        // Re-assigning the same guide to the group they already hold
        // succeeds even at capacity.
        $again = $api->lifecycle()->assign_guide(groups::get($activity, (int) $held->id), $guide, (int) $manager->id);
        $this->assertEquals($guide, $again->guideid);
    }
}
