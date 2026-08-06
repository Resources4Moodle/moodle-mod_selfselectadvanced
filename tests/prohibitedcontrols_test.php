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
use mod_selfselectadvanced\local\state;

/**
 * A control an administrator has PROHIBITED is not drawn.
 *
 * The 1.20.1 authorisation wave put :creategroup and :respond at the
 * service seam, which is where authority belongs - but it left the
 * screens exactly as they were. A student whose capability had been
 * prohibited was still shown Invite, Delete team, Confirm leave, Accept
 * and Decline, and every one of them now ends at a Moodle
 * no-permission page. review.php's own comment states the standard the
 * rest of the plugin is held to: "A form that always refuses on submit
 * is worse than no form."
 *
 * These tests drive the real exporters - the same
 * export_for_template() calls group.php and view.php make - and assert
 * the template flags the templates actually branch on. Nothing here
 * restates has_capability(); the flags come out of the production
 * export, which calls authority::may_lead()/may_respond().
 *
 * The invitation LIST is deliberately still shown when :respond is
 * prohibited (hasmyinvitations stays true): the student needs to know a
 * team is waiting on them, and their leader can still withdraw it. It
 * is the two buttons that go.
 *
 * 1.20.1 (audit F-1) adds the SUCCESSION controls, which wave 3A missed
 * because it enumerated the audit's ticket numbers instead of the
 * actions the capabilities name: leadership can be ACQUIRED as well as
 * created, so Confirm and Decline on a nomination are :respond ("Accept
 * or decline invitations AND NOMINATIONS") and Cancel nomination is the
 * leader authority. Same rule as the invitation list - the banner
 * stays, the buttons go.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\output\group_page
 * @covers     \mod_selfselectadvanced\output\landing
 */
final class prohibitedcontrols_test extends \advanced_testcase {
    /**
     * Prohibit a capability for a role at the activity context.
     *
     * @param string $cap the capability
     * @param \context $context the context
     * @param string $shortname the role shortname
     */
    private function prohibit(string $cap, \context $context, string $shortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_change_permission($roleid, $context, $cap, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A team with a leader, a confirmed member who has asked to leave,
     * and one pending invitee.
     *
     * @return array activity, api, group, leader, invitee, leaver
     */
    private function fixture(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 2,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);

        $people = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $people[] = $user;
        }
        [$leader, $invitee, $leaver] = $people;

        $group = $api->create_group((int) $leader->id, 'Alpha', 'T', '<p>b</p>', FORMAT_HTML);
        $api->invitations()->send($group, (int) $invitee->id, (int) $leader->id);
        $api->invitations()->send($group, (int) $leaver->id, (int) $leader->id);
        $api->invitations()->accept($group, (int) $leaver->id);
        $api->invitations()->request_leave($group, (int) $leaver->id);

        return [
            $activity,
            $api,
            groups::get($activity, (int) $group->id),
            $leader,
            $invitee,
            $leaver,
        ];
    }

    /**
     * Export the real group page for one viewer.
     *
     * @param activity $activity the activity
     * @param api $api the facade
     * @param \stdClass $group the group row
     * @param int $userid the viewer
     * @return \stdClass the template context
     */
    private function grouppage(activity $activity, api $api, \stdClass $group, int $userid): \stdClass {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);

        return (new \mod_selfselectadvanced\output\group_page(
            $api,
            groups::get($activity, (int) $group->id),
            $userid
        ))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Export the real landing page for one viewer.
     *
     * @param activity $activity the activity
     * @param api $api the facade
     * @param int $userid the viewer
     * @return \stdClass the template context
     */
    private function landing(activity $activity, api $api, int $userid): \stdClass {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);

        return (new \mod_selfselectadvanced\output\landing($api, $userid))
            ->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * With :creategroup prohibited the leader's own controls go, and
     * the ownership and lifecycle facts they used to be drawn from are
     * asserted to be UNCHANGED - so the only thing that moved is the
     * administrator's decision.
     *
     * Ownership is checked on the GROUP ROW rather than on the exported
     * isleader flag, because that flag is a render instruction: the two
     * places the template consults it are both leader controls (the
     * Withdraw button on a pending invitation and Cancel nomination),
     * so since 1.20.1 it carries the authority as well as the identity.
     * Reading the row is the stronger fixture check anyway - it is the
     * thing that must not have moved.
     */
    public function test_a_prohibited_leader_is_offered_no_leader_control(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $group, $leader] = $this->fixture();

        $before = $this->grouppage($activity, $api, $group, (int) $leader->id);
        $this->assertTrue($before->isleader, 'fixture: the viewer must lead the team');
        // Decision 63: a peopled team offers Request-disband where it
        // once offered Delete; both are leader controls and both must
        // vanish under the PROHIBIT below.
        $this->assertFalse($before->candelete, 'a peopled team is not deletable (decision 63)');
        $this->assertTrue($before->showdisbandrequest, 'fixture: the wind-up control must be offered to start with');
        $this->assertTrue($before->caninvite, 'fixture: there must be a free seat to start with');
        $this->assertTrue($before->hasleaverequests, 'fixture: a leave request must be waiting');
        $this->assertTrue($before->haspendinginvites, 'fixture: an invitation must be pending');

        $this->prohibit('mod/selfselectadvanced:creategroup', $activity->context(), 'student');

        $this->assertSame(
            (int) $leader->id,
            (int) $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => (int) $group->id]),
            'the viewer stopped leading the team, so this proves nothing'
        );
        $this->assertSame(
            state::FORMING,
            $DB->get_field('selfselectadvanced_group', 'state', ['id' => (int) $group->id]),
            'the team left FORMING, so this proves nothing'
        );

        $after = $this->grouppage($activity, $api, $group, (int) $leader->id);
        $this->assertFalse($after->candelete, 'Delete team was still offered to a prohibited leader');
        $this->assertFalse($after->showdisbandrequest, 'Request disband was still offered to a prohibited leader');
        $this->assertFalse($after->caninvite, 'Invite was still offered to a prohibited leader');
        $this->assertSame('', $after->inviteformhtml, 'the invite FORM was still rendered');
        $this->assertFalse($after->hasleaverequests, 'Confirm leave was still offered to a prohibited leader');
        $this->assertFalse($after->isleader, 'Withdraw was still offered to a prohibited leader');
        // Deliberate, and the same principle as the invitation list: the
        // pending invitations are still LISTED. What goes is the button.
        $this->assertTrue(
            $after->haspendinginvites,
            'the pending invitations vanished - a leader must still see who the team is waiting on'
        );
    }

    /**
     * With :respond prohibited the Accept/Decline pair goes from both
     * screens that draw it, and the invitation is still LISTED.
     */
    public function test_a_prohibited_invitee_is_offered_no_response_control(): void {
        $this->resetAfterTest();
        [$activity, $api, $group, , $invitee] = $this->fixture();

        $before = $this->grouppage($activity, $api, $group, (int) $invitee->id);
        $this->assertTrue($before->showrespond, 'fixture: the invitee must be offered the pair to start with');
        $landingbefore = $this->landing($activity, $api, (int) $invitee->id);
        $this->assertTrue($landingbefore->hasmyinvitations, 'fixture: the invitation must be listed');
        $this->assertTrue($landingbefore->mayrespond);

        $this->prohibit('mod/selfselectadvanced:respond', $activity->context(), 'student');

        $after = $this->grouppage($activity, $api, $group, (int) $invitee->id);
        $this->assertFalse($after->showrespond, 'the team page still offered Accept/Decline after a PROHIBIT');

        $landingafter = $this->landing($activity, $api, (int) $invitee->id);
        $this->assertFalse($landingafter->mayrespond, 'the landing page still offered Accept/Decline after a PROHIBIT');
        // Deliberate: the row stays. Only the two buttons go.
        $this->assertTrue(
            $landingafter->hasmyinvitations,
            'the invitation vanished from the list - the student can no longer see the team is waiting on them'
        );
    }

    /**
     * F-1: the nominee's Confirm/Decline pair goes with :respond, and
     * the BANNER stays.
     *
     * Leadership can be acquired as well as created, and the succession
     * banner is where it is acquired. Before this wave the pair was
     * drawn from the successorid column alone, so an administrator's
     * PROHIBIT left both buttons exactly where they were - on the one
     * control in the plugin that HANDS somebody a team.
     */
    public function test_a_prohibited_nominee_is_offered_no_nomination_control(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $group, $leader, , $nominee] = $this->fixture();
        $api->succession()->nominate($group, (int) $nominee->id, 'transfer', (int) $leader->id);

        $before = $this->grouppage($activity, $api, $group, (int) $nominee->id);
        $this->assertTrue($before->hasnomination, 'fixture: a nomination must be active');
        $this->assertTrue($before->isnominee, 'fixture: the viewer must be offered the pair to start with');

        $this->prohibit('mod/selfselectadvanced:respond', $activity->context(), 'student');

        // The nomination itself is untouched: only the administrator's
        // decision moved.
        $this->assertSame(
            (int) $nominee->id,
            (int) $DB->get_field('selfselectadvanced_group', 'successorid', ['id' => (int) $group->id])
        );

        $after = $this->grouppage($activity, $api, $group, (int) $nominee->id);
        $this->assertFalse($after->isnominee, 'the team page still offered Confirm/Decline after a PROHIBIT');
        $this->assertFalse($after->nomineeblocked);
        // Deliberate, and the same principle as the invitation list: a
        // student must still be able to see their team is waiting on
        // them, and their leader can still cancel.
        $this->assertTrue(
            $after->hasnomination,
            'the nomination vanished from the page - the nominee can no longer see the team is waiting on them'
        );
    }

    /**
     * F-1: the leader's Cancel nomination goes with :creategroup.
     */
    public function test_a_prohibited_leader_is_offered_no_cancel_nomination(): void {
        $this->resetAfterTest();
        [$activity, $api, $group, $leader, , $nominee] = $this->fixture();
        $api->succession()->nominate($group, (int) $nominee->id, 'transfer', (int) $leader->id);

        $before = $this->grouppage($activity, $api, $group, (int) $leader->id);
        $this->assertTrue($before->hasnomination, 'fixture: a nomination must be active');
        $this->assertTrue($before->isleader, 'fixture: the leader must be offered Cancel to start with');

        $this->prohibit('mod/selfselectadvanced:creategroup', $activity->context(), 'student');

        $after = $this->grouppage($activity, $api, $group, (int) $leader->id);
        $this->assertFalse($after->isleader, 'the team page still offered Cancel nomination after a PROHIBIT');
        $this->assertTrue($after->hasnomination, 'the leader can no longer see the nomination they raised');
    }

    /**
     * F-6: the team page's Freeze control goes with :freeze.
     *
     * Added by the wave-3B prover for a reason worth recording. F-6
     * replaced a transcribed has_capability(':freeze', ...) here with
     * authority::may_freeze(), and reported - correctly - that no test
     * could go red for the swap, because the two are the same question.
     * What that reasoning missed is the stronger fact: the capability
     * factor had NO behavioural cover at all. Forcing the whole
     * $canfreeze predicate to true in the instance left the full plugin
     * suite green on 576 tests, so a future edit that deletes the
     * capability - not just moves it - would also pass unnoticed. The
     * de-duplication really is unobservable; the LINE is not, and this
     * pins it.
     *
     * The team stays FIRM and the viewer stays its assigned guide
     * across the PROHIBIT, so the only thing that moves is the
     * administrator's decision.
     */
    public function test_a_prohibited_guide_is_offered_no_freeze_control(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team Frost',
            'guideid' => (int) $guide->id,
            'state' => state::FIRM,
        ]);

        $before = $this->grouppage($activity, $api, $group, (int) $guide->id);
        $this->assertTrue($before->canfreeze, 'fixture: the assigned guide must be offered Freeze to start with');

        $this->prohibit('mod/selfselectadvanced:freeze', $activity->context(), 'teacher');

        $row = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame((int) $guide->id, (int) $row->guideid, 'the viewer stopped guiding the team');
        $this->assertSame(state::FIRM, $row->state, 'the team left FIRM, so this proves nothing');

        $after = $this->grouppage($activity, $api, $group, (int) $guide->id);
        $this->assertFalse($after->canfreeze, 'Freeze was still offered after :freeze was prohibited');
    }

    /**
     * The rendered team page, not just the flags: all three nomination
     * forms have to leave the HTML.
     *
     * Rendered twice for the nominee and twice for the leader, because
     * the two halves are gated on two different capabilities and a
     * single render could pass on either one being effective.
     */
    public function test_the_rendered_group_page_drops_the_nomination_buttons(): void {
        global $PAGE;

        $this->resetAfterTest();
        [$activity, $api, $group, $leader, , $nominee] = $this->fixture();
        $api->succession()->nominate($group, (int) $nominee->id, 'transfer', (int) $leader->id);
        $output = $PAGE->get_renderer('core');

        $render = fn(int $userid): string => $output->render_from_template(
            'mod_selfselectadvanced/group_page',
            $this->grouppage($activity, $api, $group, $userid)
        );

        $nomineehtml = $render((int) $nominee->id);
        $this->assertStringContainsString('value="confirmnomination"', $nomineehtml, 'fixture: Confirm must start present');
        $this->assertStringContainsString('value="declinenomination"', $nomineehtml, 'fixture: Decline must start present');
        $leaderhtml = $render((int) $leader->id);
        $this->assertStringContainsString('value="cancelnomination"', $leaderhtml, 'fixture: Cancel must start present');

        $this->prohibit('mod/selfselectadvanced:respond', $activity->context(), 'student');
        $nomineehtml = $render((int) $nominee->id);
        $this->assertStringNotContainsString(
            'value="confirmnomination"',
            $nomineehtml,
            'the Confirm form survived the PROHIBIT in the rendered page'
        );
        $this->assertStringNotContainsString('value="declinenomination"', $nomineehtml);

        $this->prohibit('mod/selfselectadvanced:creategroup', $activity->context(), 'student');
        $this->assertStringNotContainsString(
            'value="cancelnomination"',
            $render((int) $leader->id),
            'the Cancel form survived the PROHIBIT in the rendered page'
        );
    }

    /**
     * The rendered HTML, not just the flag: the template must actually
     * branch on what the exporter now sets.
     */
    public function test_the_rendered_landing_drops_the_buttons(): void {
        global $PAGE;

        $this->resetAfterTest();
        [$activity, $api, , , $invitee] = $this->fixture();
        $PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $html = $output->render_from_template(
            'mod_selfselectadvanced/landing',
            $this->landing($activity, $api, (int) $invitee->id)
        );
        $this->assertStringContainsString('name="action" value="accept"', $html, 'fixture: the button must start present');

        $this->prohibit('mod/selfselectadvanced:respond', $activity->context(), 'student');

        $html = $output->render_from_template(
            'mod_selfselectadvanced/landing',
            $this->landing($activity, $api, (int) $invitee->id)
        );
        $this->assertStringNotContainsString(
            'name="action" value="accept"',
            $html,
            'the accept form survived the PROHIBIT in the rendered page'
        );
        $this->assertStringNotContainsString('name="action" value="decline"', $html);
    }
}
