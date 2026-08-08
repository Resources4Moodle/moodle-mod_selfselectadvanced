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
use mod_selfselectadvanced\local\authority;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\teamaccess;

/**
 * The lifecycle verbs answer to the capabilities that NAME them.
 *
 * Two authority waves each gated the actions their ticket numbers
 * named and missed the actions their CAPABILITIES name. Wave 3A gated
 * creation and the leader's roster verbs and missed succession; wave 3B
 * gated succession and the gradebook write and missed approve, return
 * and submit - approve and return twelve and eighty-five lines below
 * the method it had just rewritten, in the same file. This file is the
 * enumeration turned into assertions, one per verb named in a
 * capability's own language string:
 *
 * - :guide  "Act as a project guide: review, return and approve
 *   groups". REVIEW was gated in 1.20.1 (review.php's door), the AWARD
 *   in wave 3B; RETURN and APPROVE admitted on `guideid === actorid`
 *   alone and are gated here.
 * - :lead  "Act as the leader of a group". Before the 1.20.26
 *   split these verbs used the leader half of :creategroup. Invite,
 *   withdraw, confirm-leave, nominate, cancel and delete were gated by
 *   earlier waves; SUBMIT TO GUIDE was the missed leader verb and is
 *   pinned here under its current capability.
 *
 * Every test drives the PRODUCTION service the page calls, never a
 * transcription of its condition, and every refusal is followed by a
 * database assertion: a refusal that still moved the row is not a
 * refusal. Both directions are covered - a prohibited actor refused AND
 * the same actor performing the same verb with the capability in hand -
 * because a gate that refuses everybody passes every one-sided test.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper::can_approve
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper::can_return
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper::can_submit
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\handover
 * @covers     \mod_selfselectadvanced\local\freeze
 * @covers     \mod_selfselectadvanced\output\group_page
 */
final class lifecycle_authority_test extends \advanced_testcase {
    /** @var string The capability whose string names review, return and approve. */
    private const GUIDE = 'mod/selfselectadvanced:guide';

    /** @var string The read capability teamaccess::is_assigned_guide() keys on. */
    private const ASSIGNED = 'mod/selfselectadvanced:viewassignedteams';

    /**
     * A course, an activity, three students, two guides and an editing
     * teacher.
     *
     * @param array $settings instance setting overrides
     * @return array{0: activity, 1: api, 2: \stdClass[], 3: \stdClass, 4: \stdClass, 5: \stdClass, 6: \stdClass}
     *         activity, api, students, guide, second guide, staff, course
     */
    private function fixture(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
            'maxguided' => 5,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $guidetwo = $generator->create_user();
        $generator->enrol_user($guidetwo->id, $course->id, 'teacher');
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        return [$activity, new api($activity), $students, $guide, $guidetwo, $staff, $course];
    }

    /**
     * Prohibit a capability for a role at the ACTIVITY context - the
     * override an administrator makes on the activity's Permissions
     * page.
     *
     * @param string $capability the capability
     * @param \context $context the context
     * @param string $shortname the role shortname
     */
    private function prohibit(string $capability, \context $context, string $shortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_change_permission($roleid, $context, $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A team waiting on its assigned guide's decision.
     *
     * @param activity $activity the activity
     * @param \stdClass $leader the leader
     * @param \stdClass $guide the assigned guide
     * @param string $name the team name
     * @return \stdClass the group row
     */
    private function pending_team(
        activity $activity,
        \stdClass $leader,
        \stdClass $guide,
        string $name = 'Pending'
    ): \stdClass {
        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => $name,
            'guideid' => (int) $guide->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time() - HOURSECS,
        ]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * The state a team is actually in, read fresh from the database.
     *
     * @param int $groupid the team
     * @return string the state column
     */
    private function state_of(int $groupid): string {
        global $DB;

        return (string) $DB->get_field('selfselectadvanced_group', 'state', ['id' => $groupid]);
    }

    // ---------------------------------------------------------------
    // Audit item D1.

    /**
     * D1: the fixture itself. Prohibiting :viewassignedteams shuts every
     * OTHER door on the team - which is the observation that made the
     * approve/return hole a hole rather than a theory: the same actor,
     * in the same second, was refused the page they judge from and
     * allowed the judgement.
     */
    public function test_prohibiting_viewassignedteams_shuts_every_other_door(): void {
        $this->resetAfterTest();
        [$activity, , $students, $guide] = $this->fixture();
        $group = $this->pending_team($activity, $students[0], $guide);
        $guideid = (int) $guide->id;

        $this->assertTrue(teamaccess::may_review_team($activity, $group, $guideid));

        $this->prohibit(self::ASSIGNED, $activity->context(), 'teacher');

        $this->assertFalse(teamaccess::is_assigned_guide($activity, $group, $guideid));
        $this->assertFalse(teamaccess::may_open_team($activity, $group, $guideid));
        $this->assertFalse(teamaccess::may_review_team($activity, $group, $guideid));
        $this->assertFalse(teamaccess::may_read_proposal($activity, $group, $guideid));
        // And the row is untouched: they ARE still the named guide.
        $this->assertSame($guideid, (int) groups::get($activity, (int) $group->id)->guideid);
    }

    /**
     * D1: approve. The verb is consequential - it moves the team to
     * FIRM, stamps timeapproved and writes a penalty-ledger row - and
     * it ran for an actor the whole rest of the plugin had just locked
     * out.
     */
    public function test_approve_refuses_a_guide_who_may_not_reach_the_team(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $group = $this->pending_team($activity, $students[0], $guide);
        $guideid = (int) $guide->id;

        $this->assertNull($api->gatekeeper()->can_approve($group, $guideid));

        $this->prohibit(self::ASSIGNED, $activity->context(), 'teacher');

        $this->assertNotNull($api->gatekeeper()->can_approve($group, $guideid));
        try {
            $api->lifecycle()->approve($group, $guideid);
            $this->fail('approve() moved a team for a guide refused every other door on it');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotassignedguide', $e->errorcode);
        }

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $group->id));
        $this->assertEmpty($DB->get_field('selfselectadvanced_group', 'timeapproved', ['id' => $group->id]));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_penalty', ['groupid' => (int) $group->id]));
    }

    /**
     * D1: approve, the other capability. With :guide itself prohibited
     * the service still approved - the capability whose string is "Act
     * as a project guide: review, return and approve groups" was not
     * consulted by either of the two verbs it names last.
     */
    public function test_approve_refuses_a_guide_prohibited_from_guiding(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $group = $this->pending_team($activity, $students[0], $guide);
        $guideid = (int) $guide->id;

        $this->prohibit(self::GUIDE, $activity->context(), 'teacher');

        $this->assertNotNull($api->gatekeeper()->can_approve($group, $guideid));
        try {
            $api->lifecycle()->approve($group, $guideid);
            $this->fail('approve() ran after "act as a project guide" was prohibited');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotassignedguide', $e->errorcode);
        }

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $group->id));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_penalty', ['groupid' => (int) $group->id]));
    }

    /**
     * D1: return. Consequential in its own way - it sends the team back
     * to FORMING, CLEARS guideid (releasing the guide's L5 slot) and
     * mails the leader.
     */
    public function test_return_refuses_a_guide_who_may_not_reach_the_team(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $group = $this->pending_team($activity, $students[0], $guide);
        $guideid = (int) $guide->id;

        $this->assertNull($api->gatekeeper()->can_return($group, $guideid));

        $this->prohibit(self::ASSIGNED, $activity->context(), 'teacher');

        $this->assertNotNull($api->gatekeeper()->can_return($group, $guideid));
        try {
            $api->lifecycle()->return_group($group, 'Please add a member', $guideid);
            $this->fail('return_group() moved a team for a guide refused every other door on it');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotassignedguide', $e->errorcode);
        }

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $group->id));
        $this->assertSame(
            $guideid,
            (int) $DB->get_field('selfselectadvanced_group', 'guideid', ['id' => $group->id]),
            'a refused return still released the guide slot'
        );
    }

    /**
     * D1: return, with :guide prohibited.
     */
    public function test_return_refuses_a_guide_prohibited_from_guiding(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $group = $this->pending_team($activity, $students[0], $guide);
        $guideid = (int) $guide->id;

        $this->prohibit(self::GUIDE, $activity->context(), 'teacher');

        try {
            $api->lifecycle()->return_group($group, 'Not yet', $guideid);
            $this->fail('return_group() ran after "act as a project guide" was prohibited');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotassignedguide', $e->errorcode);
        }

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $group->id));
    }

    /**
     * D1, the direction that stops the gate being "refuse everybody":
     * the assigned guide holding both capabilities still approves, and
     * still returns.
     */
    public function test_the_assigned_guide_still_approves_and_still_returns(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $sink = $this->redirectMessages();

        $approved = $this->pending_team($activity, $students[0], $guide, 'Yes');
        $api->lifecycle()->approve($approved, (int) $guide->id);
        $this->assertSame(state::FIRM, $this->state_of((int) $approved->id));
        $this->assertNotEmpty($DB->get_field('selfselectadvanced_group', 'timeapproved', ['id' => $approved->id]));

        $returned = $this->pending_team($activity, $students[1], $guide, 'Back');
        $api->lifecycle()->return_group($returned, 'Add one more member', (int) $guide->id);
        $this->assertSame(state::FORMING, $this->state_of((int) $returned->id));
        $this->assertEmpty($DB->get_field('selfselectadvanced_group', 'guideid', ['id' => $returned->id]));

        $sink->close();
    }

    /**
     * D1, the boundary the fix must not cross in the other direction: a
     * :guide holder who is NOT this team's guide is refused, as they
     * always were. The gate must key on the assignment, not merely on
     * the capability - otherwise closing the identity hole would have
     * opened a capability one.
     */
    public function test_another_guide_may_not_decide_this_team(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $guide, $guidetwo] = $this->fixture();
        $group = $this->pending_team($activity, $students[0], $guide);
        $other = (int) $guidetwo->id;

        $this->assertTrue(has_capability(self::GUIDE, $activity->context(), $other));
        $this->assertTrue(has_capability(self::ASSIGNED, $activity->context(), $other));

        $this->assertNotNull($api->gatekeeper()->can_approve($group, $other));
        $this->assertNotNull($api->gatekeeper()->can_return($group, $other));
    }

    /**
     * D1, the second boundary: :manage is the administrative grant for
     * the AWARD (can_grade_team names it) and deliberately not for the
     * lifecycle decision. An editing teacher reassigns a guide or
     * dissolves a team; they do not stand in for one at the review
     * desk, and this wave must not have made them able to by sharing a
     * predicate.
     */
    public function test_manage_does_not_buy_the_guide_decision(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $guide, , $staff] = $this->fixture();
        $group = $this->pending_team($activity, $students[0], $guide);
        $staffid = (int) $staff->id;

        $this->assertTrue(has_capability('mod/selfselectadvanced:manage', $activity->context(), $staffid));
        $this->assertNull($api->gatekeeper()->can_grade_team($group, $staffid), 'the award stays theirs');
        $this->assertNotNull($api->gatekeeper()->can_approve($group, $staffid));
        $this->assertNotNull($api->gatekeeper()->can_return($group, $staffid));
    }

    /**
     * D1: the guide-window SWEEP is a different authority and must not
     * have been caught by this. approve_auto() exists precisely because
     * the lapsed decision window stands in for the guide's identity,
     * and it runs as the site administrator in cron - who is not the
     * team's guide and never will be.
     */
    public function test_the_decision_window_sweep_is_untouched(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $sink = $this->redirectMessages();
        $group = $this->pending_team($activity, $students[0], $guide);

        $admin = get_admin();
        $this->assertNotNull($api->gatekeeper()->can_approve($group, (int) $admin->id));

        $api->lifecycle()->approve_auto($group, (int) $admin->id);
        $this->assertSame(state::FIRM, $this->state_of((int) $group->id));
        $sink->close();
    }

    // ---------------------------------------------------------------
    // Audit item D2.

    /**
     * D2: submit. It is an existing-group leader verb and therefore
     * belongs to :lead after the 1.20.26 capability split.
     * The team stays FORMING and acquires no guide.
     */
    public function test_submit_refuses_a_prohibited_leader(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $leader = (int) $students[0]->id;

        $group = $api->create_group($leader, 'Submitter', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);
        $this->assertNull($api->gatekeeper()->can_submit($group, $leader));

        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertFalse(authority::may_lead($activity, $leader));

        // Ownership, state and every rule gate are still perfect: the
        // ONLY thing that changed is the administrator's decision.
        $this->assertNull($api->gatekeeper()->can_submit($group, $leader));

        try {
            $api->lifecycle()->submit($group, (int) $guide->id, $leader);
            $this->fail('submit() accepted a leader whose capability is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::LEAD), $e->a);
        }

        $this->assertSame(state::FORMING, $this->state_of((int) $group->id));
        $this->assertEmpty($DB->get_field('selfselectadvanced_group', 'guideid', ['id' => $group->id]));
        $this->assertEmpty($DB->get_field('selfselectadvanced_group', 'timesubmitted', ['id' => $group->id]));
    }

    /**
     * D2, the other direction: a leader who holds the capability still
     * submits, the team moves and the guide is recorded.
     */
    public function test_the_leader_still_submits(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $sink = $this->redirectMessages();
        $leader = (int) $students[0]->id;

        $group = $api->create_group($leader, 'Submitter', 'T', '<p>b</p>', FORMAT_HTML);
        $api->lifecycle()->submit(groups::get($activity, (int) $group->id), (int) $guide->id, $leader);

        $this->assertSame(state::PENDING_GUIDE, $this->state_of((int) $group->id));
        $this->assertSame(
            (int) $guide->id,
            (int) $DB->get_field('selfselectadvanced_group', 'guideid', ['id' => $group->id])
        );
        $sink->close();
    }

    /**
     * D2: the check is asked BEFORE the locks, which is what makes it a
     * check and not a cleanup. A refused submit leaves no lock held and
     * no transaction on the stack.
     */
    public function test_a_refused_submit_holds_nothing(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $guide] = $this->fixture();
        $leader = (int) $students[0]->id;

        $group = $api->create_group($leader, 'Submitter', 'T', '<p>b</p>', FORMAT_HTML);
        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        try {
            $api->lifecycle()->submit(groups::get($activity, (int) $group->id), (int) $guide->id, $leader);
            $this->fail('submit() accepted a prohibited leader');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(0, \mod_selfselectadvanced\local\locks::held_count());
    }

    /**
     * D2: the CONTROL goes with the verb. group.php stopped building
     * the form and the exporter carries the authority factor, so the
     * section - heading, blocked reason and button - is not drawn at
     * all for a prohibited leader.
     *
     * Asserted on the real exporter, and the fixture facts are asserted
     * unchanged beside it: still their team, still forming, still on
     * the page.
     */
    public function test_a_prohibited_leader_is_offered_no_submit_control(): void {
        global $PAGE;

        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $api->create_group($leader, 'Submitter', 'T', '<p>b</p>', FORMAT_HTML);

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);
        $renderer = $PAGE->get_renderer('core');
        $form = new \mod_selfselectadvanced\form\submit_form('', [
            'cmid' => $activity->cm()->id,
            'groupid' => (int) $group->id,
            'leaderselects' => true,
            'studentapproach' => false,
            'disabled' => false,
        ]);

        $before = (new \mod_selfselectadvanced\output\group_page(
            $api,
            groups::get($activity, (int) $group->id),
            $leader,
            null,
            null,
            $form
        ))->export_for_template($renderer);
        $this->assertTrue((bool) $before->showsubmit, 'the fixture must draw it before the prohibit');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        $after = (new \mod_selfselectadvanced\output\group_page(
            $api,
            groups::get($activity, (int) $group->id),
            $leader,
            null,
            null,
            $form
        ))->export_for_template($renderer);
        $this->assertFalse((bool) $after->showsubmit);

        $fresh = groups::get($activity, (int) $group->id);
        $this->assertSame($leader, (int) $fresh->leaderid, 'still their team');
        $this->assertSame(state::FORMING, $fresh->state, 'still forming');
    }

    // ---------------------------------------------------------------
    // Audit item D5.

    /**
     * D5: "one predicate, one home", proved behaviourally rather than
     * by grep. Each of the three sites that carried a raw `guideid ===
     * actorid` transcription now follows teamaccess::is_assigned_guide()
     * - so prohibiting the capability that predicate keys on moves all
     * of them at once.
     *
     * Stated as one test because the property is one property: the
     * three used to agree with the predicate and were free to stop.
     *
     * Note what this does NOT claim. None of the three was a hole: the
     * freeze branch asks :freeze inside it, the handover pair sit
     * behind guide.php's :guide gate, and the page flag is unreachable
     * without the very capability it now consults. What it claims is
     * that they ask the plugin's ONE answer to "is this THEIR team?"
     * and cannot drift from it.
     */
    public function test_the_three_transcriptions_now_follow_the_predicate(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        [$activity, $api, $students, $guide, $guidetwo] = $this->fixture();
        $sink = $this->redirectMessages();
        $guideid = (int) $guide->id;

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $firm = groups::get($activity, (int) $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Frozen candidate',
            'guideid' => $guideid,
            'state' => state::FIRM,
        ])->id);
        $handoverteam = groups::get($activity, (int) $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[1]->id,
            'name' => 'Handover candidate',
            'guideid' => $guideid,
            'state' => state::FIRM,
        ])->id);

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);
        $renderer = $PAGE->get_renderer('core');
        $pageflag = function (\stdClass $group) use ($api, $activity, $guideid, $renderer): bool {
            return (bool) (new \mod_selfselectadvanced\output\group_page(
                $api,
                groups::get($activity, (int) $group->id),
                $guideid
            ))->export_for_template($renderer)->canfreeze;
        };

        // All three agree with the predicate while it says yes.
        $this->assertTrue(teamaccess::is_assigned_guide($activity, $firm, $guideid));
        $this->assertTrue($pageflag($firm));
        $api->handover()->propose((int) $handoverteam->id, (int) $guidetwo->id, $guideid);
        $this->assertSame(
            (int) $guidetwo->id,
            (int) $DB->get_field('selfselectadvanced_group', 'guidesuccessorid', ['id' => $handoverteam->id])
        );
        $api->handover()->cancel((int) $handoverteam->id, $guideid);
        $this->assertEmpty($DB->get_field(
            'selfselectadvanced_group',
            'guidesuccessorid',
            ['id' => $handoverteam->id]
        ));

        // One administrator decision, and all three follow it.
        $this->prohibit(self::ASSIGNED, $activity->context(), 'teacher');
        $this->assertFalse(teamaccess::is_assigned_guide($activity, $firm, $guideid));

        $this->assertFalse($pageflag($firm), 'group_page still drew Freeze from a raw identity test');

        try {
            freeze::freeze_group($activity, groups::get($activity, (int) $firm->id), $guideid);
            $this->fail('freeze_group() took the own-guide branch from a raw identity test');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotassignedguide', $e->errorcode);
        }
        $this->assertSame(state::FIRM, $this->state_of((int) $firm->id));

        try {
            $api->handover()->propose((int) $handoverteam->id, (int) $guidetwo->id, $guideid);
            $this->fail('handover::propose() used a raw identity test');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalhandovernotguide', $e->errorcode);
        }
        $this->assertEmpty($DB->get_field(
            'selfselectadvanced_group',
            'guidesuccessorid',
            ['id' => $handoverteam->id]
        ));

        // Cancelling needs something to cancel, arranged behind the
        // service so the refusal below is about the actor and nothing
        // else.
        $DB->set_field('selfselectadvanced_group', 'guidesuccessorid', (int) $guidetwo->id, [
            'id' => $handoverteam->id,
        ]);
        try {
            $api->handover()->cancel((int) $handoverteam->id, $guideid);
            $this->fail('handover::cancel() used a raw identity test');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalhandovernotguide', $e->errorcode);
        }
        $this->assertSame(
            (int) $guidetwo->id,
            (int) $DB->get_field('selfselectadvanced_group', 'guidesuccessorid', ['id' => $handoverteam->id])
        );

        $sink->close();
    }

    /**
     * D5, the site deliberately NOT converted: the release restraint in
     * freeze::unfreeze().
     *
     * Everywhere else `guideid === actorid` ADMITS, so routing it
     * through a predicate that also asks a capability can only narrow.
     * That clause REFUSES - it is what stops a guide releasing a team
     * an editing teacher or a coordinator froze - so asking a
     * capability inside it would mean prohibiting :viewassignedteams
     * LIFTED the restraint. This test states the direction as a rule:
     * with the read capability prohibited the guide is still refused.
     */
    public function test_the_release_restraint_is_not_lifted_by_prohibiting_the_read(): void {
        $this->resetAfterTest();
        [$activity, , $students, $guide, , $staff] = $this->fixture();
        $sink = $this->redirectMessages();
        $guideid = (int) $guide->id;

        $group = groups::get($activity, (int) $this->getDataGenerator()
            ->get_plugin_generator('mod_selfselectadvanced')->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $students[0]->id,
                'name' => 'Staff froze this',
                'guideid' => $guideid,
                'state' => state::FIRM,
            ])->id);

        // The editing teacher freezes it, so frozenbystaff is set.
        freeze::freeze_group($activity, $group, (int) $staff->id);
        $this->assertSame(state::FROZEN, $this->state_of((int) $group->id));

        $this->prohibit(self::ASSIGNED, $activity->context(), 'teacher');

        try {
            freeze::unfreeze($activity, groups::get($activity, (int) $group->id), $guideid);
            $this->fail('the guide released a staff-frozen team after the read capability was prohibited');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalreleasestafffroze', $e->errorcode);
        }
        $this->assertSame(state::FROZEN, $this->state_of((int) $group->id));

        $sink->close();
    }
}
