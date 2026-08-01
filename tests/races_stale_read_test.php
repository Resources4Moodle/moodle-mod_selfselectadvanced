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
use mod_selfselectadvanced\local\contacts;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;

/**
 * "The gate I evaluated is still true when I write" - the wrong belief
 * behind R2, R3 and R4 (T-02).
 *
 * Each of these drives a real interleaving rather than calling
 * something twice: either the racing writer commits between the
 * victim's page load and its action, or - for the contact race - inside
 * the exact window between the pre-lock read and the lock, using
 * locks::set_test_hook().
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 * @covers     \mod_selfselectadvanced\local\freeze
 * @covers     \mod_selfselectadvanced\local\contacts
 */
final class races_stale_read_test extends \advanced_testcase {
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
     * A forming team of three with a guide standing by.
     *
     * @return array [activity, api, group, leader, members[], guide]
     */
    private function setup_forming(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'LV1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
            'maxguided' => 5,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $members = [];
        for ($i = 0; $i < 2; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $members[] = $user;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Leavers',
            'state' => state::FORMING,
        ]);
        foreach ($members as $member) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $member->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return [
            $activity,
            new api($activity),
            groups::get($activity, (int) $group->id),
            $leader,
            $members,
            $guide,
        ];
    }

    /**
     * R2, the headline: the leader's page said FORMING; by the time
     * they clicked Confirm the team had been submitted. The inline
     * group.php code judged the page-loaded row and wrote with no lock,
     * no transaction and no re-read, shrinking a team already in the
     * guide's queue.
     *
     * Negative control: the old inline group.php path - it writes
     * status=removed regardless and count_confirmed drops to 2.
     */
    public function test_confirm_leave_on_a_stale_forming_row_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $group, $leader, $members, $guide] = $this->setup_forming();

        $api->invitations()->request_leave($group, (int) $members[0]->id);
        $memberid = (int) $DB->get_field('selfselectadvanced_member', 'id', [
            'groupid' => $group->id,
            'userid' => (int) $members[0]->id,
        ]);

        // The leader's page, loaded while the team was still forming.
        $stale = groups::get($activity, (int) $group->id);

        // The team is submitted in the meantime.
        $api->lifecycle()->submit(groups::get($activity, (int) $group->id), (int) $guide->id, (int) $leader->id);
        $this->assertSame(state::PENDING_GUIDE, groups::get($activity, (int) $group->id)->state);

        $this->assert_refused(
            'refusalwrongstate',
            fn() => $api->invitations()->confirm_leave($stale, $memberid, (int) $leader->id)
        );
        $this->assertSame(3, groups::count_confirmed((int) $group->id));
        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field('selfselectadvanced_member', 'status', ['id' => $memberid])
        );
    }

    /**
     * R2, the other half: a member's own request is judged on the fresh
     * team too, so a leave request cannot be filed against a team that
     * has already gone to the guide.
     */
    public function test_request_leave_on_a_stale_forming_row_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $group, $leader, $members, $guide] = $this->setup_forming();

        $stale = groups::get($activity, (int) $group->id);
        $api->lifecycle()->submit(groups::get($activity, (int) $group->id), (int) $guide->id, (int) $leader->id);

        $this->assert_refused(
            'refusalwrongstate',
            fn() => $api->invitations()->request_leave($stale, (int) $members[0]->id)
        );
        $this->assertNull($DB->get_field('selfselectadvanced_member', 'leaverequested', [
            'groupid' => $group->id,
            'userid' => (int) $members[0]->id,
        ]));
    }

    /**
     * A leader leaves by nominating a successor, never by asking. The
     * refusal is its own key so the page can say why.
     */
    public function test_leader_cannot_request_leave(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [, $api, $group, $leader] = $this->setup_forming();

        $this->assert_refused(
            'refusalleaveleader',
            fn() => $api->invitations()->request_leave($group, (int) $leader->id)
        );
    }

    /**
     * Somebody who is not a confirmed member of this team has nothing
     * to leave, whatever the page offered them.
     */
    public function test_a_non_member_cannot_request_leave(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $group] = $this->setup_forming();

        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($outsider->id, $activity->courseid(), 'student');

        // No member row at all: the service refuses rather than writing
        // one, and MUST_EXIST is what says so.
        $this->expectException(\dml_missing_record_exception::class);
        $api->invitations()->request_leave($group, (int) $outsider->id);
    }

    /**
     * The leave service takes group:{id} for the whole write and sends
     * the member's notice only after releasing it.
     *
     * Negative control: inline the writes back into group.php - there
     * is no lock at all and the recorded log is empty.
     */
    public function test_confirm_leave_holds_the_group_lock_and_notifies_after_release(): void {
        global $DB;
        $this->resetAfterTest();
        [, $api, $group, $leader, $members] = $this->setup_forming();

        $this->redirectMessages()->close();
        $api->invitations()->request_leave($group, (int) $members[0]->id);
        $memberid = (int) $DB->get_field('selfselectadvanced_member', 'id', [
            'groupid' => $group->id,
            'userid' => (int) $members[0]->id,
        ]);

        $sink = $this->redirectMessages();
        locks::start_recording();
        try {
            $api->invitations()->confirm_leave($group, $memberid, (int) $leader->id);
        } finally {
            $log = locks::stop_recording();
        }
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertDebuggingNotCalled();
        $this->assertSame([
            'acquire group:' . (int) $group->id,
            'release group:' . (int) $group->id,
        ], $log);

        $subjects = array_map(static fn($m) => $m->subject, $messages);
        $this->assertContains(
            get_string('msgleaveconfirmedsubject', 'mod_selfselectadvanced', (object) ['group' => 'Leavers']),
            $subjects
        );
        $this->assertSame(
            groups::STATUS_REMOVED,
            $DB->get_field('selfselectadvanced_member', 'status', ['id' => $memberid])
        );
    }

    /**
     * A firm team with an assigned guide, a coordinator and a manager.
     *
     * @return array [activity, group, guide, coordinator, students[]]
     */
    private function setup_firm_with_coordinator(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'RF1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], ['idnumber' => 'SSARF']);
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 2; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, $activity->context());

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Refrozen',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $guide, $coordinator, $students];
    }

    /**
     * R3, the headline: frozenbystaff is written from the CURRENT actor
     * at freeze time, so it legitimately flips under an open page. The
     * guide froze the team themselves (frozenbystaff = 0), a
     * coordinator released and re-froze it while the guide's page was
     * open (frozenbystaff = 1), and the guide's release was still judged
     * on the row their page had loaded.
     *
     * Negative control: move the guard back above locks::acquire - it
     * passes on the stale row and the team is released.
     */
    public function test_guide_release_of_a_staff_refrozen_team_is_refused_on_a_stale_row(): void {
        // The preventResetByRollback() below keeps this test's
        // core-group rows out of the harness's own transaction, so what
        // it reads back is what a live site would hold. It is NO LONGER what makes
        // the mirror run: sync_core_group() lost its
        // is_transaction_started() branch in 1.20 (requirement 6) and
        // now does the same work with a transaction open or not -
        // measured on both engines, and pinned by coresync_test's
        // test_sync_does_the_same_work_inside_and_outside_a_transaction.
        // Forgetting it can no longer make a mirror assertion pass
        // vacuously on PostgreSQL.
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, $coordinator] = $this->setup_firm_with_coordinator();

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $this->assertSame(0, (int) $frozen->frozenbystaff);

        // The guide's page, loaded now.
        $stale = groups::get($activity, (int) $group->id);

        // The coordinator releases and re-freezes it.
        freeze::unfreeze($activity, groups::get($activity, (int) $group->id), (int) $coordinator->id);
        $refrozen = freeze::freeze_group(
            $activity,
            groups::get($activity, (int) $group->id),
            (int) $coordinator->id
        );
        $this->assertSame(1, (int) $refrozen->frozenbystaff);

        $this->assert_refused(
            'refusalreleasestafffroze',
            fn() => freeze::unfreeze($activity, $stale, (int) $guide->id)
        );

        $after = groups::get($activity, (int) $group->id);
        $this->assertSame(state::FROZEN, $after->state);
        $this->assertNotEmpty($after->coregroupid);
        $this->assertTrue(groups_group_exists((int) $after->coregroupid));
    }

    /**
     * R3's other guard: the conflict-of-interest test is re-judged on
     * the fresh row too, so a coordinator who has become involved since
     * their page loaded is refused.
     *
     * The involvement has to be a GROUP ROW field - here the handover
     * nomination - and not a member row. require_uninvolved() looks
     * membership up LIVE by group id, so a member row inserted after
     * the page load is equally visible through the caller's stale copy:
     * a test built on one passes whether the guard reads $group or
     * $fresh, and proves nothing about where the guard runs. guideid
     * and guidesuccessorid are read off the row itself, so they are the
     * only involvements that can tell the two rows apart.
     */
    public function test_coordinator_coi_is_rejudged_on_the_fresh_row(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, $coordinator] = $this->setup_firm_with_coordinator();

        freeze::freeze_group($activity, $group, (int) $guide->id);

        // The coordinator's page, loaded while they were uninvolved.
        $stale = groups::get($activity, (int) $group->id);
        $this->assertNotSame((int) $coordinator->id, (int) ($stale->guidesuccessorid ?? 0));

        // The guide then nominates them as the team's successor, so the
        // coordinator is a party to the very team they are about to
        // release - and their open page cannot see it.
        $DB->set_field(
            'selfselectadvanced_group',
            'guidesuccessorid',
            (int) $coordinator->id,
            ['id' => $group->id]
        );

        $this->assert_refused(
            'refusalcoiinvolved',
            fn() => freeze::unfreeze($activity, $stale, (int) $coordinator->id)
        );
        $this->assertSame(state::FROZEN, groups::get($activity, (int) $group->id)->state);
    }

    /**
     * A forming team and a guide it has approached.
     *
     * @return array [activity, group, leader, guide, contactid]
     */
    private function setup_approach(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'CT1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 3,
            'contactmax' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Approaching',
            'state' => state::FORMING,
        ]);
        $contact = contacts::send(
            $activity,
            groups::get($activity, (int) $group->id),
            (int) $guide->id,
            'We think you suit our work',
            FORMAT_PLAIN,
            (int) $leader->id
        );

        return [$activity, groups::get($activity, (int) $group->id), $leader, $guide, (int) $contact->id];
    }

    /**
     * R4, the headline. contacts::respond() read the contact and
     * checked STATUS_SENT BEFORE its locks, and re-read only the GROUP
     * inside them; the decline branch guarded nothing and blind-wrote
     * the whole stale row. A double click, or two tabs, left the guide
     * assigned by the accept while the row - and the leader's
     * notification - said declined.
     *
     * The hook commits the winner's answer in exactly the window the
     * finding describes: after the pre-lock read that still says
     * 'sent', and before the locks.
     *
     * Negative control: delete the in-lock re-read - the contact ends
     * 'declined' while the group keeps the guide, and both assertions
     * fail.
     */
    public function test_contact_accept_then_decline_refuses_the_second_answer(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $guide, $contactid] = $this->setup_approach();

        $fired = false;
        locks::set_test_hook(function (string $resource) use (&$fired, $activity, $contactid, $guide): void {
            if ($resource !== 'eoiguide:' . (int) $guide->id || $fired) {
                return;
            }
            $fired = true;
            locks::set_test_hook(null);
            contacts::respond($activity, $contactid, true, 'yes', FORMAT_HTML, (int) $guide->id);
        });

        try {
            $this->assert_refused(
                'refusalcontactanswered',
                fn() => contacts::respond($activity, $contactid, false, 'no', FORMAT_HTML, (int) $guide->id)
            );
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired);
        $this->assertSame(
            contacts::STATUS_ACCEPTED,
            contacts::get($activity, $contactid)->status
        );
        $this->assertSame((int) $guide->id, (int) groups::get($activity, (int) $group->id)->guideid);
    }

    /**
     * The mirror: the decline wins, so the accept that was already
     * in flight is refused and the team stays guideless.
     */
    public function test_contact_decline_then_accept_refuses_the_second_answer(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $guide, $contactid] = $this->setup_approach();

        $fired = false;
        locks::set_test_hook(function (string $resource) use (&$fired, $activity, $contactid, $guide): void {
            if ($resource !== 'eoiguide:' . (int) $guide->id || $fired) {
                return;
            }
            $fired = true;
            locks::set_test_hook(null);
            contacts::respond($activity, $contactid, false, 'no', FORMAT_HTML, (int) $guide->id);
        });

        try {
            $this->assert_refused(
                'refusalcontactanswered',
                fn() => contacts::respond($activity, $contactid, true, 'yes', FORMAT_HTML, (int) $guide->id)
            );
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired);
        $this->assertSame(
            contacts::STATUS_DECLINED,
            contacts::get($activity, $contactid)->status
        );
        $this->assertEmpty(groups::get($activity, (int) $group->id)->guideid);
    }
}
