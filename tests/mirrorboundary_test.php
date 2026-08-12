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
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;

/**
 * WHEN the Moodle course group appears, and that it goes away again.
 *
 * TWO DEFECTS AND ONE RULING, all from the maintainer's live report of
 * 2026-08-11.
 *
 * THE BOUNDARY. Since 1.20.6 a mirror was minted at APPROVAL rather than at
 * freeze. That was a real decision, taken because an approved team Moodle
 * cannot see is useless to group forums and group assignments - 21 of 23 on
 * the demo site. But it was recorded only in a commit message, never in the
 * decision ledger, and it left freeze.php contradicting state.php's own
 * definition of FROZEN as "mirrored into a core course group and locked".
 * Ruling 2026-08-12 makes it a setting: FROZEN always mirrors, APPROVAL is the
 * site's choice, and new activities default to freeze.
 *
 * THE ORPHANS. The mint moved forward on 2026-08-05 and no teardown path moved
 * with it. Worse, decision 62 the following day made FIRM -> FORMING legal
 * without reconciling the two, opening a route the maintainer found: approve
 * (mirror minted), have a coordinator return the team, delete it, and the
 * course group is abandoned with nothing pointing at it. Nothing in the suite
 * asserted that any deletion path removes a mirror, which is why it shipped.
 *
 * MUTATIONS CAUGHT (run 2026-08-12), each proved to land before it was run:
 * ignoring the setting so FIRM always mirrors (2 failures); return_group()
 * keeping the mirror (1); delete_group() keeping it (1); dissolve_group()
 * trusting the raw pointer again (1); the validator ceasing to police the
 * setting's domain (1). The delete-path mutation initially did NOT fail, and
 * that is recorded rather than tidied away - see that test's own docblock.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\freeze
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\api
 */
final class mirrorboundary_test extends \advanced_testcase {
    /**
     * A course, an activity at the given mirror boundary, a guide and a team
     * of two that is ready to be submitted.
     *
     * @param int $mirrorat freeze::MIRROR_AT_FREEZE or MIRROR_AT_APPROVAL
     * @return array [activity, api, course, group, leader, member, guide, manager]
     */
    private function world(int $mirrorat): array {
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('mod_selfselectadvanced');
        $course = $gen->create_course();
        $instance = $gen->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
            'mirrorat' => $mirrorat,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $mk = function (string $role) use ($gen, $course): \stdClass {
            $user = $gen->create_user();
            $gen->enrol_user($user->id, $course->id, $role);

            return $user;
        };
        $leader = $mk('student');
        $member = $mk('student');
        $guide = $mk('teacher');
        $manager = $mk('editingteacher');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Mirror team',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity, new api($activity), $course,
            groups::get($activity, (int) $group->id),
            $leader, $member, $guide, $manager,
        ];
    }

    /**
     * Drive a team to approved (FIRM) through the real lifecycle.
     *
     * @param activity $activity the activity
     * @param api $api the facade
     * @param \stdClass $group the group
     * @param \stdClass $leader its leader
     * @param \stdClass $guide the guide who approves
     * @return \stdClass the group row after approval
     */
    private function approve(
        activity $activity,
        api $api,
        \stdClass $group,
        \stdClass $leader,
        \stdClass $guide
    ): \stdClass {
        $submitted = $api->lifecycle()->submit($group, (int) $guide->id, (int) $leader->id);
        $api->lifecycle()->approve($submitted, (int) $guide->id);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * The live mirror of a group, read from Moodle rather than from the
     * plugin's own pointer - the pointer is exactly what can lie.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group row
     * @return int core group id, or 0
     */
    private function mirror(activity $activity, \stdClass $group): int {
        global $DB;

        return (int) $DB->get_field('groups', 'id', [
            'courseid' => $activity->cm()->course,
            'idnumber' => $group->pluginuid,
        ]);
    }

    /**
     * THE DEFAULT. A new activity mirrors at FREEZE, so approving a team
     * creates no Moodle group - which is what the state machine has always
     * said FROZEN means, and what the maintainer expected.
     */
    public function test_by_default_approval_creates_no_moodle_group(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader, , $guide] = $this->world(freeze::MIRROR_AT_FREEZE);

        $firm = $this->approve($activity, $api, $group, $leader, $guide);

        $this->assertSame(state::FIRM, $firm->state, 'the fixture did not actually approve the team');
        $this->assertSame(0, $this->mirror($activity, $firm), 'an approved team was mirrored under the freeze boundary');
        $this->assertEmpty($firm->coregroupid);
    }

    /**
     * ...and freezing it does create one. The half that proves the test above
     * measures the boundary rather than a broken fixture.
     */
    public function test_by_default_the_group_appears_at_freeze(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader, , $guide, $manager] = $this->world(freeze::MIRROR_AT_FREEZE);

        $firm = $this->approve($activity, $api, $group, $leader, $guide);
        $this->assertSame(0, $this->mirror($activity, $firm));

        freeze::freeze_group($activity, $firm, (int) $manager->id);

        $frozen = groups::get($activity, (int) $group->id);
        $this->assertSame(state::FROZEN, $frozen->state);
        $this->assertNotSame(0, $this->mirror($activity, $frozen), 'a frozen team must always be mirrored');
    }

    /**
     * THE OPT-IN. With the setting at approval, the same approval mints the
     * group - the behaviour 1.20.6 introduced, now chosen rather than assumed.
     */
    public function test_at_approval_the_group_appears_when_the_guide_approves(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader, , $guide] = $this->world(freeze::MIRROR_AT_APPROVAL);

        $firm = $this->approve($activity, $api, $group, $leader, $guide);

        $this->assertSame(state::FIRM, $firm->state);
        $this->assertNotSame(0, $this->mirror($activity, $firm), 'the approval boundary did not mint a mirror');
    }

    /**
     * A RETURNED TEAM GIVES THE GROUP BACK. FIRM -> FORMING (decision 62) left
     * the mirror alive for a team that is forming again and entitled to none
     * under either setting. That is the state the delete path then abandoned.
     */
    public function test_returning_an_approved_team_removes_its_moodle_group(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader, , $guide, $manager] = $this->world(freeze::MIRROR_AT_APPROVAL);

        $firm = $this->approve($activity, $api, $group, $leader, $guide);
        $mirrorid = $this->mirror($activity, $firm);
        $this->assertNotSame(0, $mirrorid, 'the fixture must actually produce a mirror');

        $api->lifecycle()->return_group($firm, 'Please revisit the split.', (int) $manager->id);

        $returned = groups::get($activity, (int) $group->id);
        $this->assertSame(state::FORMING, $returned->state);
        $this->assertFalse(
            groups_group_exists($mirrorid),
            'a team returned to forming kept the Moodle group its approval had earned'
        );
        $this->assertEmpty($returned->coregroupid, 'the pointer outlived the group it named');
    }

    /**
     * THE ORPHAN THE MAINTAINER FOUND: a FORMING team that still carries a
     * live mirror is deleted, and the Moodle group goes with it.
     *
     * WHY THE FIXTURE PUTS THE MIRROR THERE BY HAND rather than by driving
     * approve-then-return. It first did exactly that, and the test was
     * VACUOUS: return_group() now removes the mirror itself, so delete_group()
     * met nothing and the assertion passed with the delete-path fix disabled.
     * The mutation caught it (see the docblock note below), and the fixture
     * changed to state the case that actually matters.
     *
     * That case is real and is on live sites today: every activity upgrading
     * from 1.20.6 through 1.20.35 can hold a FORMING team with a live mirror,
     * because the mint moved to approval on 2026-08-05 and FIRM -> FORMING
     * became legal on 2026-08-06 with nothing reconciling them. delete_group()
     * must clear up after that history no matter how the mirror arrived.
     *
     * MUTATION CAUGHT (run 2026-08-12): disabling the remove_mirror() call in
     * api::delete_group() fails this test. With the earlier approve-then-return
     * fixture the same mutation passed, which is what proved the fixture wrong.
     */
    public function test_deleting_a_forming_team_that_still_has_a_mirror_leaves_no_orphan(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader, $member, $guide] = $this->world(freeze::MIRROR_AT_APPROVAL);

        // Earn a real mirror through the real lifecycle...
        $firm = $this->approve($activity, $api, $group, $leader, $guide);
        $mirrorid = $this->mirror($activity, $firm);
        $this->assertNotSame(0, $mirrorid, 'the fixture must actually produce a mirror');

        // ...then put the team back into FORMING the way a pre-1.20.36 site
        // holds it: state changed, mirror and pointer left behind. Written
        // directly because no current code path produces this any more - that
        // is the point of the case.
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => (int) $group->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', null, ['id' => (int) $group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', null, ['id' => (int) $group->id]);
        $this->assertTrue(groups_group_exists($mirrorid), 'the legacy state must really hold a live mirror');

        // Deletion is leader-alone, so the second member leaves first.
        $DB->delete_records('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'userid' => (int) $member->id,
        ]);
        $api->delete_group(groups::get($activity, (int) $group->id), (int) $leader->id);

        $this->assertFalse(
            $DB->record_exists('selfselectadvanced_group', ['id' => (int) $group->id]),
            'the plugin group survived its own deletion'
        );
        $this->assertFalse(
            groups_group_exists($mirrorid),
            'THE DEFECT: deleting the team left its Moodle course group orphaned in the course'
        );
    }

    /**
     * The same guarantee where the pointer has been lost but the group is
     * still there under its idnumber. dissolve_group() read the raw pointer
     * and so deleted nothing in exactly this case, which is the repair case
     * live_coregroupid() exists for.
     */
    public function test_a_lost_pointer_does_not_hide_a_mirror_from_the_teardown(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader, , $guide, $manager] = $this->world(freeze::MIRROR_AT_APPROVAL);

        $firm = $this->approve($activity, $api, $group, $leader, $guide);
        $mirrorid = $this->mirror($activity, $firm);
        $this->assertNotSame(0, $mirrorid);

        // The pointer goes; the group stays, findable only by idnumber.
        $DB->set_field('selfselectadvanced_group', 'coregroupid', null, ['id' => (int) $group->id]);
        $this->assertTrue(groups_group_exists($mirrorid));

        $api->dissolve_group(groups::get($activity, (int) $group->id), 'cleanup', (int) $manager->id);

        $this->assertFalse(
            groups_group_exists($mirrorid),
            'dissolve trusted the pointer and left the mirror behind'
        );
    }

    /**
     * UNFREEZING KEEPS THE COURSE GROUP, AND KEEPS IT IN STEP.
     *
     * The maintainer's expectation, stated 2026-08-12: "when a group is opened
     * out after a freeze, the updated group information should move to the
     * course-wide group". This is the case the mirrorat setting could most
     * easily have broken and nothing else covers, so it is asserted rather
     * than assumed.
     *
     * The hazard is specific. Under the FREEZE boundary, FROZEN needs a mirror
     * and FIRM does not - so unfreezing moves a team from a mirrored state to
     * an unmirrored one while its course group is alive and in use. If
     * state_needs_mirror() had been wired into the teardown, or if the
     * membership sync had been gated on the same predicate, an unfreeze would
     * silently orphan a live group or quietly stop maintaining it. Neither
     * happens: removal is reserved for paths that DESTROY a team (delete) or
     * un-approve it (return), and the diff-based membership sync runs whenever
     * a live mirror exists, whatever the state says.
     *
     * This is the good-neighbour principle applied inside the plugin's own
     * lifecycle: a course group that exists is course data, and the plugin
     * goes on serving it rather than abandoning it on a technicality.
     *
     * MUTATION CAUGHT (run 2026-08-12): gating the membership sync on
     * state_needs_mirror() - the plausible "tidy" refactor - fails this test's
     * roster assertion while every other test in the suite still passes.
     */
    public function test_unfreezing_keeps_the_course_group_and_goes_on_updating_it(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        // The FREEZE boundary deliberately: this is where FIRM does not need a
        // mirror, so it is the setting under which the hazard is real.
        [$activity, $api, , $group, $leader, $member, $guide, $manager] = $this->world(freeze::MIRROR_AT_FREEZE);

        $firm = $this->approve($activity, $api, $group, $leader, $guide);
        freeze::freeze_group($activity, $firm, (int) $manager->id);
        $frozen = groups::get($activity, (int) $group->id);
        $mirrorid = $this->mirror($activity, $frozen);
        $this->assertNotSame(0, $mirrorid, 'the freeze must actually mint a mirror');
        // Three, not two: the mirror carries the GUIDE as well as the roster
        // (decision 7, freeze::expected_core_members()). Asserted as a set
        // rather than a count so the reason is visible in the failure.
        $frozenmembers = array_map('intval', array_keys(groups_get_members($mirrorid)));
        sort($frozenmembers);
        $expectedfrozen = [(int) $leader->id, (int) $member->id, (int) $guide->id];
        sort($expectedfrozen);
        $this->assertSame(
            $expectedfrozen,
            $frozenmembers,
            'the frozen mirror should hold the leader, the member and the guide'
        );

        // Open it out again.
        freeze::unfreeze($activity, $frozen, (int) $manager->id);
        $unfrozen = groups::get($activity, (int) $group->id);
        $this->assertSame(state::FIRM, $unfrozen->state, 'the fixture did not actually unfreeze');
        $this->assertTrue(
            groups_group_exists($mirrorid),
            'unfreezing destroyed the course group, which is course data other activities may use'
        );
        $this->assertSame(
            $mirrorid,
            (int) $unfrozen->coregroupid,
            'unfreezing severed the pointer to a group that still exists'
        );

        // NOW THE HALF THAT MATTERS: a roster change after the unfreeze must
        // reach the course group. A third student joins as a confirmed member.
        $newcomer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($newcomer->id, $activity->cm()->course, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => (int) $group->id,
            'userid' => (int) $newcomer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $sync = freeze::sync_core_group($activity, (int) $group->id, (int) $manager->id);

        $this->assertSame('synced', $sync->status, 'the sync refused to run on an unfrozen team');
        $coremembers = array_map('intval', array_keys(groups_get_members($mirrorid)));
        sort($coremembers);
        $expected = [(int) $leader->id, (int) $member->id, (int) $newcomer->id, (int) $guide->id];
        sort($expected);
        $this->assertSame(
            $expected,
            $coremembers,
            'a roster change after unfreezing did not reach the course group'
        );

        // And a departure reaches it too, so this is a live mirror rather than
        // a one-way append.
        $DB->delete_records('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'userid' => (int) $member->id,
        ]);
        freeze::sync_core_group($activity, (int) $group->id, (int) $manager->id);
        $after = array_map('intval', array_keys(groups_get_members($mirrorid)));
        sort($after);
        $expected = [(int) $leader->id, (int) $newcomer->id, (int) $guide->id];
        sort($expected);
        $this->assertSame($expected, $after, 'a removal after unfreezing did not reach the course group');
    }

    /**
     * The setting is a closed set. An out-of-range value must be refused at
     * the form rather than read by the predicate as "not approval", which
     * would silently pick a boundary nobody chose.
     */
    public function test_the_setting_refuses_a_value_outside_its_domain(): void {
        $valid = [
            'minsize' => 1, 'maxsize' => 4, 'maxlead' => 1, 'maxmembership' => 2,
            'uidprefix' => 'SSA', 'uiddigits' => 4,
        ];
        $validator = \mod_selfselectadvanced\local\rules\settings_validator::class;

        $this->assertArrayNotHasKey('mirrorat', $validator::validate($valid + ['mirrorat' => 0]));
        $this->assertArrayNotHasKey('mirrorat', $validator::validate($valid + ['mirrorat' => 1]));
        $this->assertSame('errmirrorat', $validator::validate($valid + ['mirrorat' => 2])['mirrorat'] ?? null);
        $this->assertSame('errmirrorat', $validator::validate($valid + ['mirrorat' => -1])['mirrorat'] ?? null);
    }
}
