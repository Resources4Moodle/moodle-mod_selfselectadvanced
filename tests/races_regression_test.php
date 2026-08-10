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
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * R9: the concurrency fixes that landed in 1.19.1 are genuinely fixed,
 * and NO CODE CHANGED FOR THEM IN 1.19.2.
 *
 * They are pinned here because the R1/R6 restructuring rewrites the
 * move engine and the join-request path around them, and a lock quietly
 * dropped or a re-read quietly widened would otherwise show up as
 * nothing at all. Every assertion below describes the 1.19.1 shape, not
 * a new one.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\moves
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class races_regression_test extends \advanced_testcase {
    /**
     * A clean held-set before every test.
     */
    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
    }

    /**
     * Expect one refusal string key from a callable.
     *
     * @param string $stringkey the expected errorcode
     * @param callable $fn the action
     */
    private function assert_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }

    /**
     * Two forming teams and a wanderer confirmed in the first.
     *
     * @return array [activity, api, alpha, beta, wanderer, course]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'RG1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            // Two since decision 77: these tests race two writers against ONE
            // join request, and the requester already belongs to a team. At a
            // cap of one the request is refused before any lock is taken and
            // the race under test never happens.
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $mk = function (string $role) use ($generator, $course) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, $role);

            return $user;
        };
        $alphalead = $mk('student');
        $betalead = $mk('student');
        $wanderer = $mk('student');

        $alpha = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $alphalead->id,
            'name' => 'Alpha',
        ]);
        $beta = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $betalead->id,
            'name' => 'Beta',
        ]);
        $plugingen->create_member([
            'groupid' => $alpha->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            new api($activity),
            groups::get($activity, (int) $alpha->id),
            groups::get($activity, (int) $beta->id),
            $wanderer,
            $course,
        ];
    }

    /**
     * cancel() keeps the 1.19.1 shape: the SAME activity lock
     * commit_set takes, and a re-read filtered on status = 'pending',
     * so a commit landing between the read and the write cannot be
     * relabelled 'cancelled' while its membership changes stand.
     *
     * T-02 must not widen this to the group locks: T-15 later appends a
     * move-scope override cleanup AFTER the release on top of exactly
     * this shape.
     */
    public function test_move_cancel_still_takes_the_activity_lock_and_filters_on_pending(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $alpha, $beta, $wanderer] = $this->setup_world();

        $tocancel = $api->moves()->stage((int) $wanderer->id, (int) $alpha->id, (int) $beta->id, false, null, 99);

        locks::start_recording();
        try {
            $api->moves()->cancel((int) $tocancel->id, 99);
        } finally {
            $log = locks::stop_recording();
        }
        $this->assertSame([
            'acquire activity:' . $activity->id(),
            'release activity:' . $activity->id(),
        ], $log);
        $this->assertSame('cancelled', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $tocancel->id]));

        // A committed move can no longer be cancelled: the re-read
        // sees the status has moved and refuses TYPED (1.20.22), so
        // the loser of the race reads a sentence, not a stack trace.
        $committed = $api->moves()->stage((int) $wanderer->id, (int) $alpha->id, (int) $beta->id, false, null, 99);
        $api->moves()->commit_set([(int) $committed->id], 99);

        $sink = $this->redirectEvents();
        try {
            $api->moves()->cancel((int) $committed->id, 99);
            $this->fail('Expected the committed move to be uncancellable');
        } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
            $this->assertSame('refusalmovegone', $e->errorcode);
        }
        $cancelledevents = array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\move_cancelled
        );
        $sink->close();

        $this->assertCount(0, $cancelledevents);
        $this->assertSame('committed', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $committed->id]));
    }

    /**
     * respond() and withdraw() serialise on the SAME joinrequest:{id}
     * resource, so an answer and a withdrawal racing each other resolve
     * one way or the other and never both.
     */
    public function test_joinrequest_respond_and_withdraw_share_the_request_lock(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Closer to my work', (int) $wanderer->id);

        locks::start_recording();
        try {
            joinrequests::respond($activity, (int) $request->id, false, 'Not this time', (int) $beta->leaderid);
        } finally {
            $log = locks::stop_recording();
        }
        $this->assertSame([
            'acquire joinrequest:' . (int) $request->id,
            'release joinrequest:' . (int) $request->id,
        ], $log);

        locks::start_recording();
        try {
            $this->assert_refused(
                'refusaljoinnotopen',
                fn() => joinrequests::withdraw($activity, (int) $request->id, (int) $wanderer->id)
            );
        } finally {
            $withdrawlog = locks::stop_recording();
        }
        $this->assertSame('acquire joinrequest:' . (int) $request->id, $withdrawlog[0]);
        $this->assertSame('release joinrequest:' . (int) $request->id, end($withdrawlog));
    }

    /**
     * The duplicate check is serialised on the ASKER, because that is
     * what the rule is about: one live request each. Two clicks, or two
     * tabs, cannot both pass the check and insert.
     */
    public function test_joinrequest_duplicate_request_is_serialised_per_user(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $beta, $wanderer] = $this->setup_world();

        locks::start_recording();
        try {
            $request = joinrequests::request($activity, (int) $beta->id, 'Closer to my work', (int) $wanderer->id);
        } finally {
            $log = locks::stop_recording();
        }
        $this->assertSame([
            'acquire joinrequest:user:' . (int) $wanderer->id,
            'release joinrequest:user:' . (int) $wanderer->id,
        ], $log);
        $this->assertSame(joinrequests::STATUS_REQUESTED, $request->status);

        $this->assert_refused(
            'refusaljoinduplicate',
            fn() => joinrequests::request($activity, (int) $beta->id, 'Again', (int) $wanderer->id)
        );
    }

    /**
     * delete_group() re-checks inside group:{id} and removes the
     * proposal attachments only after the deletion has COMMITTED - file
     * storage is not part of the transaction, so an earlier delete
     * would destroy the attachments of a group a rollback then kept.
     * The member notices go out after the release, so the guard stays
     * silent.
     */
    public function test_delete_group_rechecks_inside_the_lock_and_deletes_files_after(): void {
        $this->resetAfterTest();
        [$activity, $api, $alpha, , $wanderer] = $this->setup_world();

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $activity->context()->id,
            'component' => 'mod_selfselectadvanced',
            'filearea' => 'proposal',
            'itemid' => (int) $alpha->id,
            'filepath' => '/',
            'filename' => 'proposal.txt',
        ], 'Our plan');
        $this->assertCount(1, $fs->get_area_files(
            $activity->context()->id,
            'mod_selfselectadvanced',
            'proposal',
            (int) $alpha->id,
            'id',
            false
        ));

        // REFIT for decision 63: a peopled team is no longer the
        // leader's to delete, and this test's property - the in-lock
        // recheck and the file deletion AFTER the transaction - is
        // about delete's mechanics, not its roster. The wanderer leaves
        // through the protocol's own write first.
        global $DB;
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
            'groupid' => (int) $alpha->id,
            'userid' => (int) $wanderer->id,
        ]);

        $sink = $this->redirectMessages();
        locks::start_recording();
        try {
            $api->delete_group($alpha, (int) $alpha->leaderid);
        } finally {
            $log = locks::stop_recording();
        }
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertDebuggingNotCalled();
        $this->assertSame([
            'acquire group:' . (int) $alpha->id,
            'release group:' . (int) $alpha->id,
        ], $log);
        $this->assertSame([], $fs->get_area_files(
            $activity->context()->id,
            'mod_selfselectadvanced',
            'proposal',
            (int) $alpha->id,
            'id',
            false
        ));
        // Decision 63 moved the "member is told" property EARLIER, to
        // the disband broadcast (pinned by creation_test and
        // disband_test); a compliant delete happens at leader-alone,
        // where there is nobody left to tell.
        $this->assertEmpty($messages, 'a leader-alone delete has nobody to notify');
    }

    /**
     * The ticket claim is a compare-and-set under ticket:{id}: the
     * second claimant is refused rather than silently taking over.
     */
    public function test_ticket_claim_compare_and_set_still_refuses_the_second_claimant(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'RG2']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $first = $generator->create_user();
        $generator->enrol_user($first->id, $course->id, 'editingteacher');
        $second = $generator->create_user();
        $generator->enrol_user($second->id, $course->id, 'editingteacher');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Ticketed',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $ticket = tickets::file(
            $activity,
            groups::get($activity, (int) $group->id),
            tickets::TYPE_COMPCHANGE,
            'One more member please',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        locks::start_recording();
        try {
            tickets::claim($activity, (int) $ticket->id, (int) $first->id);
        } finally {
            $log = locks::stop_recording();
        }
        $this->assertSame([
            'acquire ticket:' . (int) $ticket->id,
            'release ticket:' . (int) $ticket->id,
        ], $log);

        $this->assert_refused(
            'refusalticketclaimed',
            fn() => tickets::claim($activity, (int) $ticket->id, (int) $second->id)
        );
    }

    /**
     * The accepted residual of the 1.19.1 ordering fix, pinned so it
     * does not drift: grant_guidecap() refuses an empty resolution
     * BEFORE writing the override, so a coordinator pressing Grant with
     * a blank note no longer raises the guide's cap permanently and
     * only then hits close()'s refusal.
     */
    public function test_grant_guidecap_window_is_still_the_documented_one(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'RG3']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, $activity->context());

        $ticket = tickets::file_guidecap($activity, 5, 'I can take more', FORMAT_PLAIN, (int) $guide->id);
        tickets::claim($activity, (int) $ticket->id, (int) $coordinator->id);

        $this->assert_refused('refusalticketreason', fn() => tickets::grant_guidecap(
            $activity,
            (int) $ticket->id,
            '   ',
            FORMAT_PLAIN,
            (int) $coordinator->id
        ));

        // Nothing was written: no guide-scope override exists at all.
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'guide',
        ]));
        $this->assertSame(
            tickets::STATUS_CLAIMED,
            $DB->get_field('selfselectadvanced_ticket', 'status', ['id' => $ticket->id])
        );
    }
}
