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

/**
 * INV-001 (external audit of 1.20.37): the group page's own Accept
 * control must ask gatekeeper::can_accept() - the same gate the landing
 * page (classes/output/landing.php:240-247) and the leader's own
 * pending-invites panel (classes/output/group_page.php, decision 60)
 * already ask - rather than deriving "can accept" from the :respond
 * capability alone.
 *
 * Before this fix, `showrespond` was `$ownrow && STATUS_INVITED &&
 * $mayrespond`: a group that filled up, started winding up or left
 * Forming after an invitation was sent still drew a live Accept button
 * on this page. The service (invitations::accept()) enforces the real
 * gate under lock, so no data was ever at risk - but a click on that
 * button landed on a refusal the page had promised would not happen.
 * Decline is not re-gated on the same rule: withdrawing from an offer
 * the group has outgrown is cleanup, not the join the gate refuses, and
 * the audit remediation says it must stay independently available.
 *
 * These tests drive the real exporter - the same export_for_template()
 * call group.php makes - exactly as tests/prohibitedcontrols_test.php
 * does for the capability side of the same panel.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\output\group_page
 */
final class groupaccept_gate_test extends \advanced_testcase {
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
     * An invitation into an open group offers Accept as well as Decline.
     *
     * The control case: nothing refuses, so all three flags read the way
     * they did before this fix - this is the assertion that would have
     * caught a fix that hid Accept unconditionally instead of asking the
     * gate.
     */
    public function test_accept_is_offered_when_the_gate_is_open(): void {
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
        $invitee = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($invitee->id, $course->id, 'student');

        $group = $api->create_group((int) $leader->id, 'Team Open', 'T', '<p>b</p>', FORMAT_HTML);
        $api->invitations()->send($group, (int) $invitee->id, (int) $leader->id);

        $ctx = $this->grouppage($activity, $api, $group, (int) $invitee->id);

        $this->assertTrue($ctx->hasinvitationhere, 'fixture: the invitation must exist');
        $this->assertTrue($ctx->candecline, 'Decline must be offered whenever :respond is not prohibited');
        $this->assertTrue($ctx->showrespond, 'Accept must be offered when can_accept() refuses nothing');
        $this->assertFalse($ctx->acceptgateblocked, 'nothing refuses the accept gate in this fixture');
        $this->assertSame('', $ctx->acceptblockedreason);
    }

    /**
     * A group the invitee's acceptance would now overshoot renders
     * Accept DISABLED, with the reason, and keeps Decline live (D-106,
     * maintainer decision 2026-08-15 07:32).
     *
     * Until D-106 this page OMITTED the Accept button outright when the
     * gate refused - the landing page has always rendered a DISABLED
     * Accept with the reason instead (classes/output/landing.php,
     * templates/landing.mustache's `{{#blocked}}disabled
     * title="{{blockedreason}}"{{/blocked}}` idiom). The maintainer chose
     * disabled-with-reason on BOTH pages, so this test now asserts the
     * group page's markup matches that idiom rather than asserting the
     * button's absence - the exported flags (`candecline`,
     * `acceptgateblocked`, `acceptblockedreason`) are unchanged by D-106,
     * only what templates/group_page.mustache does with them changed, so
     * the render_from_template() assertions below are what actually
     * proves the markup shifted.
     */
    public function test_accept_is_disabled_with_reason_but_decline_stays_when_the_group_has_outgrown_the_invitation(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);

        $leader = $generator->create_user();
        $invitee = $generator->create_user();
        $latecomer = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($invitee->id, $course->id, 'student');
        $generator->enrol_user($latecomer->id, $course->id, 'student');

        // Maxsize 2: leader (confirmed, 1 seat) + invitee (invited, 1
        // reserved seat) exactly fills it - a legitimate invitation.
        $group = $api->create_group((int) $leader->id, 'Team Tight', 'T', '<p>b</p>', FORMAT_HTML);
        $api->invitations()->send($group, (int) $invitee->id, (int) $leader->id);

        // The roster changes AFTER the invitation was issued - a
        // confirmed member is added directly (as the audit's scenario
        // describes: "the group becomes full ... after the invite"),
        // pushing confirmed+invited past maxsize. This is deliberately a
        // raw fixture rather than a second live invite/accept, because
        // the point under test is what the PAGE says about a roster that
        // has already changed, not how it got that way.
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $latecomer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        // The gate's own answer, independent of the exporter under test:
        // this is the ground truth the page must not contradict.
        $grouprow = groups::get($activity, (int) $group->id);
        $memberrow = $DB->get_record('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'userid' => (int) $invitee->id,
        ], '*', MUST_EXIST);
        $refusal = $api->gatekeeper()->can_accept($grouprow, $memberrow);
        $this->assertNotNull($refusal, 'fixture: the gate must actually refuse - otherwise this proves nothing');
        $this->assertSame('refusalnoseatsheld', $refusal->stringkey);

        $ctx = $this->grouppage($activity, $api, $group, (int) $invitee->id);

        $this->assertTrue($ctx->hasinvitationhere, 'fixture: the invitation must still exist');
        $this->assertTrue(
            $ctx->candecline,
            'Decline must stay available - cleanup must never be blocked by the rule that blocks joining'
        );
        $this->assertTrue($ctx->acceptgateblocked, 'the page must know WHY Accept is withheld, not just that it is');
        $this->assertNotSame('', $ctx->acceptblockedreason, 'a refused-but-eligible viewer is told why (decision 83)');
        $this->assertStringContainsString(
            get_string('refusalnoseatsheld', 'mod_selfselectadvanced'),
            $ctx->acceptblockedreason,
            'the reason exported must be the real refusal, not a placeholder'
        );

        // D-106: the RENDERED page, not just the exported flags - this is
        // what actually proves the markup shifted from omission to a
        // disabled button, mirroring landing.mustache's own idiom.
        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);
        $html = $PAGE->get_renderer('core')->render_from_template('mod_selfselectadvanced/group_page', $ctx);
        $this->assertStringContainsString(
            'name="action" value="accept"',
            $html,
            'D-106: the Accept form must still be RENDERED, not omitted, when the gate refuses'
        );
        // The disabled attribute and the refusal text must sit on the
        // SAME button, not merely appear somewhere on the page - pull
        // out the accept form block and assert both inside it.
        preg_match('/<form[^>]*>((?:(?!<\/form>).)*?value="accept"(?:(?!<\/form>).)*)<\/form>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'fixture: the accept form block must be found in the rendered markup');
        $this->assertStringContainsString('disabled', $matches[1], 'D-106: the Accept button must be DISABLED, not absent');
        $this->assertStringContainsString(
            get_string('refusalnoseatsheld', 'mod_selfselectadvanced'),
            $matches[1],
            'D-106: the refusal reason must be attached to the disabled button (title attribute)'
        );
    }
}
