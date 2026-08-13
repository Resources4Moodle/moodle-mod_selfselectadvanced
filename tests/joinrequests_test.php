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

use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\authority;
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;

/**
 * Asking to join another team, and who says yes (strategy 1.19 B and C).
 *
 * The maintainer's rule in one sentence: self-service until the leader
 * accepts, the guide releases a settled team before it can change, and
 * a coordinator may answer anything. These check each clause, and that
 * acceptance really does go through the move engine rather than around
 * it - a request that would break the target team is refused.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\freeze
 */
final class joinrequests_test extends \advanced_testcase {
    /**
     * Every team this student is confirmed in, as a sorted list of ids.
     *
     * Sorted because the assertions that use it are about WHICH teams, never
     * about the order current_groups() happens to return them in.
     *
     * @param activity $activity the activity
     * @param int $userid the student
     * @return int[] group ids, ascending
     */
    private function groups_of(activity $activity, int $userid): array {
        $ids = array_map('intval', array_keys(joinrequests::current_groups($activity, $userid)));
        sort($ids);

        return $ids;
    }

    /**
     * Two teams with room, a wanderer, a guide, a coordinator.
     *
     * @param array $settings instance overrides
     * @return array [activity, alpha, beta, wanderer, guide, coordinator, manager, course]
     */
    private function setup_world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'JR1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            // Decision 77 changed what this fixture has to be. It used to pin
            // maxmembership at 1, which made every ask-to-join a SWAP - the
            // everyday path the ruling abolished. With no swap, a student at
            // their cap simply cannot ask, so a cap of 1 would make almost
            // every test here assert the refusal rather than the behaviour it
            // was written for. Headroom is now the default world; the tests
            // that are ABOUT the cap set it back to 1 themselves.
            'maxmembership' => 2,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $mk = function (string $role) use ($generator, $course) {
            $u = $generator->create_user();
            $generator->enrol_user($u->id, $course->id, $role);

            return $u;
        };
        $alphalead = $mk('student');
        $betalead = $mk('student');
        $wanderer = $mk('student');
        $guide = $mk('teacher');
        $manager = $mk('editingteacher');
        $coordinator = $mk('teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, $activity->context());

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
        // The generator already gives the leader their member row.
        $plugingen->create_member([
            'groupid' => $alpha->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            groups::get($activity, (int) $alpha->id),
            groups::get($activity, (int) $beta->id),
            $wanderer,
            $guide,
            $coordinator,
            $manager,
            $course,
        ];
    }

    /**
     * A world where two teams each are legal: the wanderer is
     * confirmed in Alpha AND in Gamma, and Beta is what they ask for.
     *
     * @param int $cap the activity's maxmembership
     * @return array [activity, alpha, beta, gamma, wanderer, guide, coordinator, manager]
     */
    private function setup_multi_world(int $cap = 2): array {
        global $DB;

        [$activity, $alpha, $beta, $wanderer, $guide, $coordinator, $manager, $course]
            = $this->setup_world(['maxmembership' => $cap]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $gammalead = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($gammalead->id, $course->id, 'student');
        $gamma = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $gammalead->id,
            'name' => 'Gamma',
        ]);
        $plugingen->create_member([
            'groupid' => $gamma->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        // The three teams are made in the same second, and
        // "timecreated ASC" over a tie is no order at all. Back-dating
        // Alpha pins the sequence the ordering assertions rely on
        // without weakening them.
        $DB->set_field('selfselectadvanced_group', 'timecreated', time() - 5, ['id' => $alpha->id]);

        return [$activity, $alpha, $beta, groups::get($activity, (int) $gamma->id),
            $wanderer, $guide, $coordinator, $manager];
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
     * Prohibit one capability for a role in this activity.
     *
     * @param activity $activity the activity
     * @param string $capability capability name
     * @param string $shortname role shortname
     */
    private function prohibit(activity $activity, string $capability, string $shortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_change_permission($roleid, $activity->context(), $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Decision 78A: a late request is refused AT THE DOOR, against the asker's own clock.
     *
     * Before 1.20.32 request() asked no date question. A student past the
     * formation cut-off filed successfully and read "Your request has gone to
     * the group leader." It could never be accepted - the leader's Answer tab
     * refused it against the LEADER'S window - so nothing was wrongly admitted,
     * but the student was told the opposite of the truth with no way to find
     * out why.
     *
     * MUTATION CAUGHT (run 2026-08-10): removing the check_window() call from
     * joinrequests::request() lets the filing succeed and fails this test.
     */
    public function test_a_request_past_the_cutoff_is_refused_at_filing(): void {
        $this->resetAfterTest();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world([
            'timecutoff' => time() - DAYSECS,
        ]);

        $this->assert_refused('refusalcutoffpassed', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Closer to my programme',
            (int) $wanderer->id
        ));

        // And nothing was written: a refused filing must leave no row behind.
        $this->assertSame(
            0,
            count(joinrequests::waiting_for_group($activity, (int) $beta->id)),
            'a refused request must not create a row the leader then has to decline'
        );
    }

    /**
     * The requester's OWN extension is what counts - the control for the test above.
     *
     * A per-user timecutoff override made this student's window later than the
     * activity's. That extension works when they create a team and when they
     * accept an invitation; decision 78A makes it work here too.
     */
    public function test_a_personal_extension_lets_the_request_through(): void {
        $this->resetAfterTest();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world([
            'timecutoff' => time() - DAYSECS,
        ]);

        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'user',
            (int) $wanderer->id,
            ['timecutoff' => time() + DAYSECS],
            (int) get_admin()->id
        );

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'My deadline was extended',
            (int) $wanderer->id
        );
        $this->assertSame(joinrequests::STATUS_REQUESTED, $request->status);
    }

    /**
     * A REQUEST FILED BEFORE THE RULING IS NOT HONOURED AS A SWAP.
     *
     * Decision 77 removed the student-chosen source, but a site that upgrades
     * has rows already waiting for an answer that still carry one. Nothing
     * rewrites them - the row is a true record of what was asked - so the
     * question is what the accept path does when it meets one.
     *
     * It ignores it. Honouring it would take the student out of a team whose
     * leader never agreed to lose them, which is the exact act the ruling
     * forbids, and it would do so days after the site was told the plugin no
     * longer works that way. The student is added to the team they asked for
     * and stays where they were.
     *
     * MUTATION CAUGHT (run 2026-08-10): restoring the source read in
     * do_accept() - `$source = $sourceid ? groups::get(...) : null` and passing
     * it to stage() - empties Alpha and this fails on the membership list.
     */
    public function test_a_request_filed_before_the_ruling_is_not_honoured_as_a_swap(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        // Forge the legacy shape the old code wrote: the same request, with the
        // team the student had picked to leave. It cannot be made through the
        // service any more, which is the point.
        $DB->set_field('selfselectadvanced_move', 'sourcegroupid', (int) $alpha->id, ['id' => (int) $request->id]);
        $this->assertSame(
            (int) $alpha->id,
            (int) $DB->get_field('selfselectadvanced_move', 'sourcegroupid', ['id' => (int) $request->id]),
            'fixture: the row must carry a source, or this test proves nothing'
        );

        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);

        $this->assertSame(
            [(int) $alpha->id, (int) $beta->id],
            $this->groups_of($activity, (int) $wanderer->id),
            'an upgraded site completed a swap the ruling had already abolished'
        );
        $this->assertSame(
            (int) $alpha->id,
            (int) $DB->get_field('selfselectadvanced_move', 'sourcegroupid', ['id' => (int) $request->id]),
            'the record of what the student asked for was rewritten; it should be ignored, not edited'
        );
        $sink->close();
    }

    /**
     * SIX TESTS WERE RETIRED HERE ON 2026-08-10, AND THIS IS THEIR RECORD.
     *
     * Maintainer decision 77 settled that a commitment to a group is not the
     * member's alone to break. A student can no longer swap themselves out of
     * group A into group B: the join form stopped asking "which group will you
     * leave", the service stopped accepting an answer, and a join is now
     * additive or it is refused.
     *
     * These six pinned guarantees about a choice that no longer exists:
     *
     * - the_chosen_source_is_the_one_recorded_and_the_one_left
     * - a_source_left_between_asking_and_answering_is_refused_readably
     * - a_source_that_is_not_theirs_is_refused_by_the_server (an IDOR guard on
     *   a posted field that is no longer posted)
     * - the_source_is_decided_by_the_read_inside_the_lock
     * - the_frozen_refusal_follows_the_selected_source
     * - a_two_team_student_must_say_which_team_they_leave
     *
     * They are recorded rather than silently deleted because a reader finding
     * the gap later would reasonably suspect coverage had been dropped to make
     * a build green. It was not: the behaviour went, so the tests went with it.
     *
     * ONE PROTECTION IS WORTH NAMING, because it moved rather than vanished.
     * A student in a settled team could not previously be moved out by their
     * own request - the source arm refused it. That arm is gone, but
     * gatekeeper::can_request_leave() refuses any group past FORMING, so the
     * guarantee still holds on the LEAVE path. It is enforced by a rule, not by
     * the absence of a feature, and leave-related tests cover it.
     *
     * @return void
     */
    public function test_the_retired_source_tests_are_accounted_for(): void {
        // A test, not a comment, so the accounting cannot rot unnoticed: if
        // anybody reintroduces a student-chosen source, this goes red and
        // sends them to the ruling above.
        $service = file_get_contents(__DIR__ . '/../classes/local/joinrequests.php');
        $this->assertNotFalse($service);
        $this->assertStringNotContainsString(
            'SOURCE_ADDITIONAL',
            $service,
            'a student-chosen source is back; decision 77 removed it and six tests went with it'
        );
        $form = file_get_contents(__DIR__ . '/../classes/form/joinrequest_form.php');
        $this->assertNotFalse($form);
        // BOTH BRANCHES, because the form had two. The select was the
        // multi-source case; the single-source case used a HIDDEN field that
        // pinned the answer without asking - which is the shape the ruling was
        // most pointed about, and the one a guard on 'select' alone would miss.
        $this->assertStringNotContainsString(
            "addElement('select', 'source'",
            $form,
            'the join form is asking which group to leave again (decision 77 forbids it)'
        );
        $this->assertStringNotContainsString(
            "'source'",
            $form,
            'the join form carries a source field again - including the hidden pin, which asked '
                . 'nothing and decided everything'
        );
    }

    /**
     * The ordinary case: a student asks, the TARGET team's leader
     * accepts, and the move happens through the engine.
     */
    public function test_leader_accepts_and_the_membership_is_added(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Closer to my programme', (int) $wanderer->id);
        $this->assertSame(joinrequests::STATUS_REQUESTED, $request->status);
        $this->assertNull(
            $request->sourcegroupid,
            'decision 77: a join request carries no team to leave, so accepting it can take nobody out of one'
        );

        joinrequests::respond($activity, (int) $request->id, true, 'Glad to have you', (int) $beta->leaderid);

        // BOTH. Until decision 77 this asserted [beta] alone, because
        // acceptance quietly removed the student from Alpha - a team Alpha's
        // leader had committed to, ended by a decision Alpha never saw.
        $now = array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $wanderer->id)));
        sort($now);
        $expected = [(int) $alpha->id, (int) $beta->id];
        sort($expected);
        $this->assertSame($expected, $now, 'the join was a move, not an addition');
        $sink->close();
    }

    /**
     * Only the target team's leader answers - not the source team's,
     * and not another student.
     */
    public function test_only_the_target_leader_answers(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);

        $this->assert_refused('refusaljoinnotleader', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $alpha->leaderid
        ));
        $this->assert_refused('refusaljoinnotleader', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $wanderer->id
        ));
        $sink->close();
    }

    /**
     * A stored leader whose leader capability has been prohibited may not
     * decide a join request by direct service call.
     *
     * MUTATION CAUGHT (run): require_decider() returns on leaderid equality
     * alone; the prohibited leader decided the request and the expected
     * refusal was not thrown.
     */
    public function test_a_prohibited_target_leader_cannot_answer_a_join_request(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer] = $this->setup_world();
        $leaderid = (int) $beta->leaderid;
        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);

        $this->assertTrue(authority::may_lead($activity, $leaderid), 'fixture: the leader must start authorised');
        $this->prohibit($activity, authority::LEAD, 'student');
        $this->assertFalse(authority::may_lead($activity, $leaderid));
        $this->assertSame(
            $leaderid,
            (int) $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => (int) $beta->id]),
            'the actor stopped being the stored leader, so this test proves nothing'
        );

        $this->assert_refused('refusaljoinnotleader', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            'Welcome',
            $leaderid
        ));
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $request->id]),
            'a refused decision still moved the request'
        );
        $sink->close();
    }

    /**
     * The maintainer's escape hatch: a coordinator may answer any
     * request, for an absent leader or a contested case.
     */
    public function test_a_coordinator_may_answer_anything(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer, , $coordinator] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, true, 'Approved centrally', (int) $coordinator->id);

        $this->assertSame(
            [(int) $alpha->id, (int) $beta->id],
            $this->groups_of($activity, (int) $wanderer->id),
            'a coordinator answering does not make the join a move either (decision 77)'
        );
        $sink->close();
    }

    /**
     * Asking twice, asking to join one's own team, and asking with no
     * reason are all refused.
     */
    public function test_the_asking_gates(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $this->assert_refused('refusaljoinreason', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            '   ',
            (int) $wanderer->id
        ));
        $this->assert_refused('refusaljoinalready', fn() => joinrequests::request(
            $activity,
            (int) $alpha->id,
            'Staying put',
            (int) $wanderer->id
        ));
        $this->assert_refused('refusaljoinownteam', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'It is mine',
            (int) $beta->leaderid
        ));

        joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $this->assert_refused('refusaljoinduplicate', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Again',
            (int) $wanderer->id
        ));
        $sink->close();
    }

    /**
     * A request that would break the target team's composition is
     * refused AT ACCEPTANCE, naming the rule - and the request stays
     * open so the leader can see why.
     */
    public function test_acceptance_runs_the_composition_rules(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // Teams of one: admitting anybody exceeds the maximum.
        [$activity, , $beta, $wanderer] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $this->assert_refused('refusaljoinrules', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $beta->leaderid
        ));

        $fresh = joinrequests::get($activity, (int) $request->id);
        $this->assertSame(joinrequests::STATUS_REQUESTED, $fresh->status);
        $sink->close();
    }

    /**
     * A firm team that was approved but not guide-released is closed to
     * join requests; otherwise a student leader can alter a graded
     * roster after approval.
     *
     * MUTATION CAUGHT (run): join_change_refusal() allowed every FIRM
     * team; the request was created instead of refusing with
     * refusaljointargetapproved.
     */
    public function test_an_unreleased_firm_target_refuses_join_requests(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, $guide] = $this->setup_world();
        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => (int) $beta->id,
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
            'releasedbyguide' => 0,
        ]);

        $this->assert_refused('refusaljointargetapproved', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Please',
            (int) $wanderer->id
        ));
    }

    /**
     * A team in guide review is also closed; the leader must ask the
     * guide to release the team before the roster changes.
     *
     * MUTATION CAUGHT (run): join_change_refusal() allowed
     * PENDING_GUIDE teams; the request was created instead of refusing
     * with refusaljointargetpending.
     */
    public function test_a_pending_guide_target_refuses_join_requests(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, $guide] = $this->setup_world();
        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => (int) $beta->id,
            'state' => state::PENDING_GUIDE,
            'guideid' => (int) $guide->id,
            'timesubmitted' => time(),
            'releasedbyguide' => 0,
        ]);

        $this->assert_refused('refusaljointargetpending', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Please',
            (int) $wanderer->id
        ));
    }

    /**
     * A settled team keeps its people - now enforced on the leave path.
     *
     * THIS TEST CHANGED SIDES, and the note matters more than the code.
     *
     * Until decision 77 the guarantee lived here: a student whose current team
     * had been approved and not released could not ask to join another,
     * because asking WAS leaving, and the service refused with
     * `refusaljoinsourceapproved`. The ruling made a join additive, so that
     * refusal has nothing left to refuse - the settled team is not being left.
     *
     * The protection itself is not gone. It moved to the only path that can
     * still take somebody out of a settled team: the leave request, which
     * gatekeeper::can_request_leave() refuses for any state past FORMING.
     * Asserted here as well as in the leave tests, because a reader arriving
     * at the join service with this worry deserves to find the answer here.
     */
    public function test_an_unreleased_firm_team_still_keeps_its_member(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer, $guide] = $this->setup_world();
        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => (int) $alpha->id,
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
            'releasedbyguide' => 0,
        ]);

        // The ask is now allowed: it does not touch Alpha at all.
        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, true, '', (int) $beta->leaderid);
        $this->assertSame(
            [(int) $alpha->id, (int) $beta->id],
            $this->groups_of($activity, (int) $wanderer->id),
            'joining Beta must leave the settled team Alpha exactly as it was'
        );

        // And the route that WOULD take them out of Alpha is shut.
        $membership = $DB->get_record('selfselectadvanced_member', [
            'groupid' => (int) $alpha->id,
            'userid' => (int) $wanderer->id,
        ]);
        $refusal = (new api($activity))->gatekeeper()->can_request_leave(
            groups::get($activity, (int) $alpha->id),
            $membership,
            (int) $wanderer->id
        );
        $this->assertNotNull(
            $refusal,
            'the leave path let a member walk out of an unreleased settled team - the protection '
                . 'decision 77 relocated has been lost, not moved'
        );
        $this->assertSame('refusalwrongstate', $refusal->stringkey);
        $sink->close();
    }

    /**
     * A frozen team neither takes anybody nor lets anybody go until it
     * is released.
     *
     * MUTATION CAUGHT (run): freeze::unfreeze() wrote
     * releasedbyguide = 0; the post-release assertion saw 0 instead
     * of 1 before the join could proceed.
     */
    public function test_a_frozen_team_is_closed_until_released(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer, $guide] = $this->setup_world();

        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $beta->id]);
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $guide->id);

        $this->assert_refused('refusaljointargetfrozen', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Please',
            (int) $wanderer->id
        ));

        // The guide froze it, so the guide may release it - and then it
        // takes people again.
        freeze::unfreeze($activity, groups::get($activity, (int) $beta->id), (int) $guide->id);
        $this->assertSame(1, (int) groups::get($activity, (int) $beta->id)->releasedbyguide);
        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, true, '', (int) $beta->leaderid);
        $this->assertSame(
            [(int) $alpha->id, (int) $beta->id],
            $this->groups_of($activity, (int) $wanderer->id)
        );
        $sink->close();
    }

    /**
     * A guide-released firm team has already been approved, so a later
     * accepted join is a roster delta the guide must hear about. The
     * message names the student and the source/target teams, but never
     * contact details.
     *
     * MUTATION CAUGHT (run): disabling the post-accept
     * notify_released_guide_change() call left the assigned guide with
     * zero joinrequests messages.
     */
    public function test_accept_into_released_firm_team_notifies_the_guide_of_the_roster_change(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $alpha, $beta, $wanderer, $guide] = $this->setup_world();
        $phone = '555-SSA-CHANGE';
        $DB->set_field('user', 'phone1', $phone, ['id' => (int) $wanderer->id]);
        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => (int) $beta->id,
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
            'releasedbyguide' => 1,
        ]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $sink = $this->redirectMessages();
        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);
        $messages = $sink->get_messages();
        $sink->close();

        $guidechanges = array_values(array_filter(
            $messages,
            static fn($m): bool => (int) $m->useridto === (int) $guide->id
                && $m->eventtype === 'joinrequests'
        ));
        $this->assertCount(1, $guidechanges, 'the assigned guide was not told about the released-team join');
        $body = (string) $guidechanges[0]->fullmessage;
        $this->assertStringContainsString(fullname($wanderer), $body);
        $this->assertStringContainsString(format_string($beta->name), $body);
        // The guide is told the roster GREW, and is not told a team was left,
        // because none was: decision 77 made the join additive. Naming Alpha
        // here would tell the guide of Beta about a change to a team that has
        // not changed.
        $this->assertStringNotContainsString(format_string($alpha->name), $body);
        // And it does not append a sentence about the group NOT left either.
        // That trailing clause could only ever say one thing after the ruling,
        // so it was removed; asserting its absence keeps it from creeping back
        // as boilerplate the guide learns to skip.
        $this->assertStringNotContainsString('did not leave', $body);
        $this->assertStringNotContainsString((string) $wanderer->email, $body);
        $this->assertStringNotContainsString($phone, $body);
        $this->assertStringNotContainsString((string) $wanderer->email, (string) $guidechanges[0]->subject);
        $this->assertStringNotContainsString($phone, (string) $guidechanges[0]->subject);
    }

    /**
     * A forming target has no assigned guide and no approved roster to
     * invalidate, so accepting its join request must not emit the
     * released-team guide-change notice.
     *
     * MUTATION CAUGHT (run): treating a forming guide-less target as
     * eligible and falling back to the target leader emitted an
     * unwanted "Released team" notice.
     */
    public function test_accept_into_forming_guideless_team_sends_no_guide_change_notice(): void {
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, $guide] = $this->setup_world();

        $this->assertEmpty(groups::get($activity, (int) $beta->id)->guideid);
        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $sink = $this->redirectMessages();
        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertEmpty(array_filter(
            $messages,
            static fn($m): bool => (int) $m->useridto === (int) $guide->id
        ));
        $this->assertEmpty(array_filter(
            $messages,
            static fn($m): bool => strpos((string) $m->subject, 'Released team') !== false
        ));
    }

    /**
     * Submitting and approving clear any stale release flag, so a
     * later approved team does not stay mutable merely because an older
     * lifecycle turn had been released.
     *
     * MUTATION CAUGHT (run): submit() kept releasedbyguide = 1; the
     * submit assertion failed with "submit left the release flag set".
     * MUTATION CAUGHT (run): approve() kept releasedbyguide = 1; the
     * approve assertion failed with "approve left the release flag set".
     */
    public function test_submit_and_approve_clear_the_guide_release_flag(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, , $guide] = $this->setup_world();
        $api = new api($activity);

        $DB->set_field('selfselectadvanced_group', 'releasedbyguide', 1, ['id' => (int) $beta->id]);
        $submitted = $api->lifecycle()->submit(
            groups::get($activity, (int) $beta->id),
            (int) $guide->id,
            (int) $beta->leaderid
        );
        $this->assertSame(0, (int) $submitted->releasedbyguide, 'submit left the release flag set');

        $DB->set_field('selfselectadvanced_group', 'releasedbyguide', 1, ['id' => (int) $beta->id]);
        $approved = $api->lifecycle()->approve(
            groups::get($activity, (int) $beta->id),
            (int) $guide->id
        );
        $this->assertSame(0, (int) $approved->releasedbyguide, 'approve left the release flag set');
        $sink->close();
    }

    /**
     * The limit the maintainer set on releasing: a guide releases until
     * staff enforce a freeze, and not afterwards.
     */
    public function test_a_guide_cannot_release_what_staff_froze(): void {
        $this->resetAfterTest();
        // THE preventResetByRollback() BELOW MUST COME FIRST (1.20
        // wave 3E): the refusals driven here leave services that now
        // roll their own delegated frame back UNCONDITIONALLY, and this
        // test carries on committing afterwards. On PostgreSQL
        // advanced_testcase holds a transaction underneath for the
        // whole test, so that rollback is not the top level: it pops,
        // leaves force_rollback set, and the next allow_commit() raises
        // "Tried to commit transaction after lower level rollback". In
        // production nothing is underneath, the rollback empties the
        // stack and force_rollback is cleared - which is the cascade
        // the fix restores.
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        [$activity, , $beta, , $guide, $coordinator, $manager] = $this->setup_world();

        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $beta->id]);

        // A manager's freeze is staff-enforced and holds.
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $manager->id);
        $frozen = groups::get($activity, (int) $beta->id);
        $this->assertSame(1, (int) $frozen->frozenbystaff);
        $this->assert_refused(
            'refusalreleasestafffroze',
            fn() => freeze::unfreeze($activity, $frozen, (int) $guide->id)
        );

        // The staff who froze it can still release it.
        freeze::unfreeze($activity, $frozen, (int) $manager->id);

        // A coordinator's freeze holds against the guide too.
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $coordinator->id);
        $frozen = groups::get($activity, (int) $beta->id);
        $this->assertSame(1, (int) $frozen->frozenbystaff);
        $this->assert_refused(
            'refusalreleasestafffroze',
            fn() => freeze::unfreeze($activity, $frozen, (int) $guide->id)
        );
        $sink->close();
    }

    /**
     * A guide's own freeze is not staff-enforced, so the guide may
     * release it - which is the whole of what 1.19 C adds.
     *
     * Who ELSE may call the service is deliberately unchanged: it has
     * always trusted its callers on the capability and left the pages
     * to enforce it. Widening a service guard would have taken
     * authority from actors who already had it, the mistake 1.16 and
     * 1.17 each made once.
     */
    public function test_a_guide_releases_their_own_freeze(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, , $guide] = $this->setup_world();

        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $beta->id]);
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $guide->id);

        $frozen = groups::get($activity, (int) $beta->id);
        $this->assertSame(0, (int) $frozen->frozenbystaff);

        freeze::unfreeze($activity, $frozen, (int) $guide->id);
        $this->assertSame(state::FIRM, groups::get($activity, (int) $beta->id)->state);
        $sink->close();
    }

    /**
     * A request can be taken back while nobody has answered it, by its
     * author and nobody else.
     */
    public function test_withdrawing_ones_own_request(): void {
        $this->resetAfterTest();
        // THE preventResetByRollback() BELOW MUST COME FIRST, for the
        // reason given on the first test in this file that needed it
        // (1.20 wave 3E).
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $this->assert_refused('refusaljoinnotyours', fn() => joinrequests::withdraw(
            $activity,
            (int) $request->id,
            (int) $beta->leaderid
        ));

        $withdrawn = joinrequests::withdraw($activity, (int) $request->id, (int) $wanderer->id);
        $this->assertSame('cancelled', $withdrawn->status);

        // Withdrawn frees the slot, so a fresh request is accepted.
        joinrequests::request($activity, (int) $beta->id, 'Asking properly', (int) $wanderer->id);
        $sink->close();
    }

    /**
     * Acceptance funnels through the move engine, so the move engine's
     * per-group quota exemption reaches it: a request into a team that
     * is EXEMPT from the composition rules is accepted even though the
     * team does not satisfy them. There is no second condition: decision 77
     * made the join additive, so no other team's composition is consulted.
     *
     * The set-level reading of exemption used to refuse this, and the
     * student saw only "refusaljoinrules" with no way to act on it.
     */
    public function test_accept_into_exempt_group_commits(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        // One Computer seat. Alpha's leader fills it and stays; Beta
        // can never fill it, so Beta is exempted instead.
        \mod_selfselectadvanced\local\quota\slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        \mod_selfselectadvanced\local\attributes\manager::set((int) $alpha->leaderid, ['department' => 'Computer'], 2);
        \mod_selfselectadvanced\local\attributes\manager::set((int) $beta->leaderid, ['department' => 'Elsewhere'], 2);
        \mod_selfselectadvanced\local\attributes\manager::set((int) $wanderer->id, ['department' => 'Elsewhere'], 2);
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $beta->id,
            ['quotaexempt' => 1],
            0
        );

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $decided = joinrequests::respond($activity, (int) $request->id, true, 'ok', (int) $beta->leaderid);

        $this->assertSame('committed', $decided->status);
        $this->assertSame(
            [(int) $alpha->id, (int) $beta->id],
            $this->groups_of($activity, (int) $wanderer->id)
        );
        $sink->close();
    }



    /**
     * "Already in that team" is judged across every membership, not
     * against whichever row an unordered fetch returned first - and it
     * is judged BEFORE the source question, so a student in the target
     * is told the useful thing.
     */
    public function test_a_member_of_the_target_is_refused_whichever_row_comes_back_first(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['maxmembership' => 2]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_member([
            'groupid' => $beta->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        // Already-in-target is answered before anything else is asked.
        //
        // THERE WERE TWO CALLS HERE, the second passing $alpha as a fifth
        // "source" argument to prove that stating a source did not change the
        // answer. request() takes four parameters since decision 77, and PHP
        // discards extra positional arguments to a userland function without a
        // word - so the second call was byte-for-byte the first one, asserting
        // the same thing twice while reading as though it covered a second
        // case. One call, and the source concept is not mentioned.
        $this->assert_refused('refusaljoinalready', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'x',
            (int) $wanderer->id
        ));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }


    /**
     * Acceptance adds a membership and removes none.
     *
     * This was "a choice the student can state" while the form offered
     * keep-them-all as one option among several. Decision 77 made it the only
     * outcome a join can have, so the property is now unconditional - which is
     * a stronger thing to assert, not a weaker one.
     */
    public function test_an_extra_membership_is_the_only_thing_a_join_can_do(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['maxmembership' => 2]);

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Both, please',
            (int) $wanderer->id
        );
        $this->assertNull($request->sourcegroupid);

        joinrequests::respond($activity, (int) $request->id, true, 'ok', (int) $beta->leaderid);

        $status = fn(int $groupid): string => (string) $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['groupid' => $groupid, 'userid' => (int) $wanderer->id]
        );
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $alpha->id));
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $beta->id));
        $this->assertSame(2, groups::count_memberships($activity, (int) $wanderer->id));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * An extra membership the cap has no room for is refused when it is
     * asked for, naming the numbers.
     */
    public function test_an_extra_membership_at_the_cap_is_refused_when_asked(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // A cap of one, which is this test's whole subject. The shared world
        // allows two so that the OTHER tests can reach the behaviour they are
        // about; here the cap has to bite, so it is set here.
        [$activity, , $beta, $wanderer] = $this->setup_world(['maxmembership' => 1]);

        try {
            joinrequests::request(
                $activity,
                (int) $beta->id,
                'x',
                (int) $wanderer->id
            );
            $this->fail('Expected refusal refusaljoinnoheadroom');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinnoheadroom', $e->errorcode);
            $this->assertSame(1, (int) $e->a->current);
            $this->assertSame(1, (int) $e->a->max);
        }
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The dead end of finding 2d, dissolved: a request carrying a
     * deliberate NULL source whose author has since filled their cap is
     * judged by the composition rules - a refusal the leader can act on
     * - and never silently swapped for a membership the student did not
     * offer.
     */
    public function test_an_extra_membership_over_cap_is_refused_at_acceptance_not_silently_swapped(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer, , , , $course] = $this->setup_world(['maxmembership' => 2]);

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Both, please',
            (int) $wanderer->id
        );
        $this->assertNull($request->sourcegroupid);

        // MEANWHILE: the student fills their cap elsewhere.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $gammalead = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($gammalead->id, $course->id, 'student');
        $gamma = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $gammalead->id,
            'name' => 'Gamma',
        ]);
        $plugingen->create_member([
            'groupid' => $gamma->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $this->assert_refused('refusaljoinrules', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $beta->leaderid
        ));

        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            joinrequests::get($activity, (int) $request->id)->status
        );
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
        ]));
        $status = fn(int $groupid): string => (string) $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['groupid' => $groupid, 'userid' => (int) $wanderer->id]
        );
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $alpha->id));
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $gamma->id));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }


    /**
     * The interleaving that used to destroy a membership in silence.
     *
     * require_room_to_ask() refuses at ASK time when the student is already
     * confirmed in the target (refusaljoinalready). Nothing re-checked
     * it at ANSWER time, and the check matters most there: between the
     * two the student can be admitted to the target by a different
     * route entirely - an invitation they accept, a manager's move.
     *
     * The move engine then sees gain=0 (they are already in the
     * target) and loss=1, a NET -1, so the L4 cap check waves it
     * through, the source membership is set to removed, and respond()
     * mails the student that their request succeeded. They lose a team
     * and are told they gained one.
     *
     * The guard sits on the same in-lock re-read as
     * refusaljoinsourcegone and keeps the request OPEN, so the decider
     * can decline it with a note and nothing is destroyed.
     */
    public function test_a_target_joined_between_asking_and_answering_cannot_cost_the_source(): void {
        global $DB;
        $this->resetAfterTest();
        // THE preventResetByRollback() BELOW MUST COME FIRST, for the
        // reason given on the first test in this file that needed it
        // (1.20 wave 3E).
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        // Cap 2: Alpha AND Beta together is a legal end state, which is
        // what makes the loss silent rather than a cap refusal.
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['maxmembership' => 2]);

        // 1. ASK: join Beta. No team is offered up - decision 77 removed the
        // offer, and the fifth argument that used to name Alpha here was
        // silently discarded by PHP once request() lost the parameter, which
        // made this read like a swap test while behaving like an additive one.
        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'please',
            (int) $wanderer->id
        );
        $this->assertNull($request->sourcegroupid, 'fixture: the ask names no team to leave');

        // 2. MEANWHILE: they get into Beta by the other supported
        // route - Beta's leader invites them and they accept.
        $invitations = (new local\api($activity))->invitations();
        $invitations->send($beta, (int) $wanderer->id, (int) $beta->leaderid);
        $invitations->accept($beta, (int) $wanderer->id);

        $confirmed = function (int $groupid) use ($DB, $wanderer): bool {
            return $DB->record_exists('selfselectadvanced_member', [
                'groupid' => $groupid,
                'userid' => (int) $wanderer->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        };
        $this->assertTrue($confirmed((int) $alpha->id));
        $this->assertTrue($confirmed((int) $beta->id));

        // 3. ANSWER: Beta's leader presses Accept on the stale request.
        $this->assert_refused('refusaljointargetalready', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $beta->leaderid
        ));

        // Nothing was destroyed: both memberships survive.
        $this->assertTrue($confirmed((int) $alpha->id));
        $this->assertTrue($confirmed((int) $beta->id));

        // The request is still open, so the decider can decline it with a note
        // - the contract every readable join refusal keeps.
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            joinrequests::get($activity, (int) $request->id)->status
        );
        $decided = joinrequests::respond(
            $activity,
            (int) $request->id,
            false,
            'You are already in Beta.',
            (int) $beta->leaderid
        );
        $this->assertSame(joinrequests::STATUS_DECLINED, $decided->status);
        $this->assertTrue($confirmed((int) $alpha->id));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The plural lookup lists every team in a defined order and warns
     * about nothing - the single-row fetch it replaced did neither.
     */
    public function test_current_groups_lists_every_team_in_order_without_a_warning(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, , $gamma, $wanderer] = $this->setup_multi_world();

        $all = joinrequests::current_groups($activity, (int) $wanderer->id);
        $this->assertCount(2, $all);
        $this->assertSame([(int) $alpha->id, (int) $gamma->id], array_map('intval', array_keys($all)));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }



    /**
     * D6-5: the refusal used to say nothing. validate_set() returns
     * each verdict as an ARRAY and first_reason() read it with OBJECT
     * syntax, so every branch was empty and the message always fell
     * through to the general string - the staff member never saw which
     * rule refused, or by how much.
     */
    public function test_first_reason_names_rule_regression(): void {
        $this->resetAfterTest();
        // With maxsize 1 Beta's leader alone already fills it, so an
        // acceptance breaks L2 with real figures.
        [$activity, , $beta, $wanderer, , , $manager] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        try {
            joinrequests::respond($activity, (int) $request->id, true, 'ok', (int) $manager->id);
            $this->fail('Expected refusaljoinrules');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
            $this->assertStringContainsString('L2', $e->getMessage());
            $this->assertStringContainsString(
                get_string('moveruleL2', 'mod_selfselectadvanced', (object) ['after' => 2, 'max' => 1]),
                $e->getMessage()
            );
            $this->assertStringNotContainsString(
                get_string('refusaljoinrulesgeneral', 'mod_selfselectadvanced'),
                $e->getMessage()
            );
        }
    }

    /**
     * Staff who can override rules see a live accept decision for an L2
     * refusal that an ordinary leader cannot accept from the same row.
     *
     * MUTATION CAUGHT (run): forcing accept_decision() to ignore
     * :overriderules left the staff decision with canaccept=false.
     */
    public function test_staff_override_authority_keeps_bypassable_hard_accept_live(): void {
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, , , $manager] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $leaderdecision = joinrequests::accept_decision($activity, $request, (int) $beta->leaderid, $beta);
        $this->assertFalse($leaderdecision->canaccept);
        // The honest sibling key since 1.20.20: every seat here is
        // held by a CONFIRMED member, and the sentence says so.
        $this->assertSame('refusalnoseatsconfirmed', $leaderdecision->hardkey);
        $this->assertFalse($leaderdecision->confirmationrequired);

        $this->assertTrue(has_capability('mod/selfselectadvanced:overriderules', $activity->context(), (int) $manager->id));
        $staffdecision = joinrequests::accept_decision($activity, $request, (int) $manager->id, $beta);
        $this->assertTrue($staffdecision->canaccept, 'staff override authority still produced a disabled Accept');
        $this->assertSame('refusalnoseatsconfirmed', $staffdecision->hardkey);
        $this->assertTrue($staffdecision->confirmationrequired);
        $this->assertFalse($staffdecision->confirmacceptrequired);
        $this->assertSame(['L2'], $staffdecision->bypassrules);
        $this->assertObjectNotHasProperty(
            'autobypassrules',
            $staffdecision,
            'decision 64: staff L2 requires the explicit override - no auto-bypass surface exists to ride'
        );
    }

    /**
     * Decision 6: staff may accept over a failing rule, with a note,
     * through the SAME move-scope override the staging form uses.
     */
    public function test_staff_accept_with_bypass(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, , , $manager] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $sink = $this->redirectEvents();
        joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            'Guide agreed: one over on Beta',
            (int) $manager->id,
            ['L2']
        );
        $overridden = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\move_rules_overridden
        ));
        $sink->close();

        $this->assertSame('committed', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id]));
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]));
        $this->assertCount(1, $overridden);
        $this->assertSame(['L2'], $overridden[0]->other['rules']);
        $this->assertSame('Guide agreed: one over on Beta', $overridden[0]->other['reason']);
        $this->assertSame((int) $wanderer->id, (int) $overridden[0]->relateduserid);
    }

    /**
     * T-10 boundary: the staff override never leaks into the
     * participant matrix. The target team's own student leader posting
     * a crafted bypass[] is refused on the ACTOR's capability, whatever
     * the form rendered.
     */
    public function test_student_leader_crafted_bypass_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer] = $this->setup_world(['maxsize' => 1]);
        $betaleader = (int) $beta->leaderid;

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $this->assert_refused('refusaljoinbypasscap', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            'I say so',
            $betaleader,
            ['L2']
        ));

        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id])
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'move',
        ]));
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * The note IS the reason: a bypass with an empty one is refused at
     * the same seam a staged commit is.
     */
    public function test_bypass_requires_note(): void {
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, , , $manager] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $this->assert_refused('errmoveoverridereasonrequired', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '   ',
            (int) $manager->id,
            ['L2']
        ));
    }
    /**
     * Decision 78B: an unanswered request expires, and the student is told.
     *
     * A request was never immortal - it auto-declines when the target team is
     * deleted or disbanded, the student can withdraw, and the leader's Decline
     * is never disabled even when Accept is. What was missing is a CLOCK: an
     * unanswered request sat in the queue indefinitely while the student waited
     * with no way to tell "nobody has looked yet" from "this will never happen".
     *
     * MUTATION CAUGHT (run 2026-08-10): making expire_due() return early
     * regardless of joinexpiry fails the status assertion; dropping the
     * notifier::send() call fails the recipient assertion.
     */
    /**
     * THE EXPIRY SWEEP CANNOT OVERWRITE AN ANSWER (external audit TX-001).
     *
     * accept() and withdraw() both serialise on `joinrequest:{id}` and re-read
     * the row under that lock; expire_due() used to do neither. It selected a
     * candidate set and wrote to it blindly, so this interleaving was live:
     *
     *   1. the sweep SELECTs the request, sees `requested`
     *   2. the leader accepts - membership committed, request answered
     *   3. the sweep overwrites the same row to `declined`
     *   4. the student is told their request expired
     *
     * leaving a confirmed member whose request record says it expired. A
     * sequential "accept, then expire" test cannot see it, which is why the
     * suite missed it; this one uses locks::set_test_hook() to run the accept
     * INSIDE the sweep's window - after its discovery query, at the moment it
     * reaches for the per-request lock.
     *
     * MUTATION CAUGHT (run 2026-08-13): removing the status re-read inside the
     * lock - the exact pre-fix behaviour - fails this test on the status
     * assertion.
     */
    public function test_the_expiry_sweep_does_not_overwrite_a_request_accepted_in_its_window(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['joinexpiry' => 3]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $DB->set_field('selfselectadvanced_move', 'timecreated', time() - (4 * DAYSECS), ['id' => $request->id]);

        // The leader accepts in the instant between the sweep's discovery
        // query and its lock on this request.
        $accepted = false;
        locks::set_test_hook(function (string $resource) use (
            &$accepted,
            $activity,
            $request,
            $beta
        ): void {
            if ($accepted || $resource !== 'joinrequest:' . (int) $request->id) {
                return;
            }
            $accepted = true;
            locks::set_test_hook(null);
            joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);
        });

        $expired = joinrequests::expire_due($activity);
        locks::set_test_hook(null);

        $this->assertTrue($accepted, 'the fixture never reached the sweep\'s lock, so it proved nothing');
        $this->assertSame(0, $expired, 'the sweep expired a request that had just been accepted');
        $this->assertNotSame(
            joinrequests::STATUS_DECLINED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $request->id]),
            'THE DEFECT: an accepted request was overwritten as expired'
        );
    }

    public function test_an_unanswered_request_expires_and_the_student_is_told(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['joinexpiry' => 3]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        // Age it past the window.
        $DB->set_field('selfselectadvanced_move', 'timecreated', time() - (4 * DAYSECS), ['id' => $request->id]);

        $this->assertSame(1, joinrequests::expire_due($activity));
        $this->assertSame(
            joinrequests::STATUS_DECLINED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id])
        );

        $tolds = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $this->assertContains(
            (int) $wanderer->id,
            $tolds,
            'an expiry the student discovers by absence is the same silence this decision ends'
        );
        $sink->close();
    }

    /**
     * Off by default, and a request inside the window is untouched.
     *
     * The control. Without it the test above would pass against an
     * expire_due() that withdrew everything it saw.
     */
    public function test_expiry_is_off_by_default_and_spares_a_fresh_request(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();

        // ONE world: setup_world() pins a course shortname, so calling it
        // twice in a test collides. The activity's setting is moved instead,
        // which is also the truer test - the same request, the same data, and
        // only the switch changing.
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();
        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $DB->set_field('selfselectadvanced_move', 'timecreated', time() - (400 * DAYSECS), ['id' => $request->id]);
        $this->assertSame(0, joinrequests::expire_due($activity), 'expiry must be off unless the activity sets it');
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id]),
            'a request older than any window must survive while the feature is off'
        );

        // Switched on, with a window the request is comfortably inside.
        $DB->set_field('selfselectadvanced', 'joinexpiry', 3000, ['id' => $activity->id()]);
        $reloaded = activity::from_instance($activity->id());
        $this->assertSame(0, joinrequests::expire_due($reloaded), 'a request inside the window must survive');
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id])
        );

        // And the same request DOES expire once the window is short enough,
        // which is what proves the two assertions above are discriminating.
        $DB->set_field('selfselectadvanced', 'joinexpiry', 1, ['id' => $activity->id()]);
        $this->assertSame(1, joinrequests::expire_due(activity::from_instance($activity->id())));
    }
}
