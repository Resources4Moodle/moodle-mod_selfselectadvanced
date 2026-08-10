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

use mod_selfselectadvanced\local\rules\refusal;
use mod_selfselectadvanced\local\ui\control;

/**
 * Decision 83's presentation policy, and the surfaces that must obey it.
 *
 * The ruling asked for a CONVENTION rather than nine fixes, so this file tests
 * the convention and then tests that the surfaces go through it. The static
 * half is /srv/ci/ops/control-state.sh, which fails the build on a refusal
 * collapsed to a boolean; it cannot see the six surfaces that never asked for a
 * refusal at all, and those are held here.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\ui\control
 */
final class control_state_test extends \advanced_testcase {
    /**
     * No capability means the control is not drawn and nothing is said about it.
     *
     * MUTATION CAUGHT (run 2026-08-09): making decide() return show=true for an
     * unpermitted actor fails on the show assertion.
     */
    public function test_a_missing_capability_hides_the_control(): void {
        $this->resetAfterTest();

        $decision = control::decide(false, null);
        $this->assertFalse($decision->show, 'a function that is not for this person is not drawn');
        $this->assertSame('', $decision->reason, 'explaining it would describe the permission model to an outsider');

        // Even with a refusal in hand, the capability answer wins: the reason
        // belongs to somebody who could otherwise act.
        $withrefusal = control::decide(false, new refusal('refusalwrongstate'));
        $this->assertFalse($withrefusal->show);
        $this->assertSame('', $withrefusal->reason);
    }

    /**
     * Permitted and unrefused is simply offered.
     */
    public function test_permitted_and_unrefused_is_enabled(): void {
        $this->resetAfterTest();

        $decision = control::decide(true, null);
        $this->assertTrue($decision->show);
        $this->assertTrue($decision->enabled);
        $this->assertSame('', $decision->reason);
    }

    /**
     * Permitted but refused shows the control disabled, carrying the gate's own sentence.
     *
     * MUTATION CAUGHT (run 2026-08-09): returning show=false on the refused arm
     * fails here - that is precisely the pre-1.20.29 behaviour the ruling ended.
     */
    public function test_permitted_but_refused_is_disabled_with_the_reason(): void {
        $this->resetAfterTest();

        $decision = control::decide(true, new refusal('refusalwrongstate'));
        $this->assertTrue($decision->show, 'an eligible person must see that the action exists');
        $this->assertFalse($decision->enabled);
        $this->assertSame(
            get_string('refusalwrongstate', 'mod_selfselectadvanced'),
            $decision->reason,
            'the gatekeeper wrote a sentence; the page must use it'
        );
    }

    /**
     * A conflict-of-interest refusal is disabled but does NOT name the relationship.
     *
     * refusalcoiinvolved reads "you cannot act because you are THE ASSIGNED GUIDE
     * of it", which discloses a relationship the reader may not be entitled to
     * know. The ruling asked for recoverability without disclosure.
     *
     * MUTATION CAUGHT (run 2026-08-09): emptying control::SHIELDED makes the
     * real text surface and fails the assertNotSame below.
     */
    public function test_a_conflict_of_interest_reason_is_shielded(): void {
        $this->resetAfterTest();

        $real = new refusal('refusalcoiinvolved', get_string('coiguide', 'mod_selfselectadvanced'));
        $decision = control::decide(true, $real);

        $this->assertTrue($decision->show);
        $this->assertFalse($decision->enabled);
        $this->assertSame(get_string('refusalcoishielded', 'mod_selfselectadvanced'), $decision->reason);
        $this->assertNotSame($real->get_message(), $decision->reason, 'the real text names the relationship');
        $this->assertStringNotContainsString(
            get_string('coiguide', 'mod_selfselectadvanced'),
            $decision->reason,
            'the shielded sentence must not leak the involvement it is hiding'
        );
        // The reader still learns who can act, so recoverability survives.
        $this->assertNotSame('', trim($decision->reason));
    }

    /**
     * Only genuinely disclosing refusals are shielded.
     *
     * refusalcoiself says "you cannot grant yourself an exception", which tells
     * the actor only what they already know about themselves. Shielding it would
     * replace a precise sentence with a vaguer one for no privacy gain.
     */
    public function test_self_conflict_is_not_shielded(): void {
        $this->resetAfterTest();

        $this->assertFalse(control::is_shielded('refusalcoiself'));
        $this->assertTrue(control::is_shielded('refusalcoiinvolved'), 'the shield list must not be silently emptied');

        $decision = control::decide(true, new refusal('refusalcoiself'));
        $this->assertSame(get_string('refusalcoiself', 'mod_selfselectadvanced'), $decision->reason);
    }

    /**
     * The string-reason factory obeys the same policy.
     */
    public function test_the_string_factory_follows_the_same_policy(): void {
        $this->resetAfterTest();

        $hidden = control::decide_with_reason(false, 'anything at all');
        $this->assertFalse($hidden->show);
        $this->assertSame('', $hidden->reason, 'an unpermitted actor is told nothing, whatever was passed');

        $this->assertTrue(control::decide_with_reason(true, '')->enabled);
        $refused = control::decide_with_reason(true, 'Frozen by staff');
        $this->assertTrue($refused->show);
        $this->assertFalse($refused->enabled);
        $this->assertSame('Frozen by staff', $refused->reason);
    }

    /**
     * The nine surfaces of decision 83 route through the convention.
     *
     * A source-level check, deliberately: six of the nine never asked a service
     * for a refusal, so there is no behaviour to observe until the page is
     * rendered under nine different fixtures. What CAN be stated cheaply and
     * exactly is that each surface exports a companion reason - the shape the
     * ruling requires - rather than a lone boolean.
     *
     * MUTATION CAUGHT (run 2026-08-09): deleting any one of the exported reason
     * keys from group_page.php fails this test naming that key.
     */
    public function test_every_ruled_surface_exports_a_reason_beside_its_flag(): void {
        $root = realpath(__DIR__ . '/..');
        $exporter = file_get_contents($root . '/classes/output/group_page.php');
        $this->assertNotFalse($exporter);

        // Each flag, paired with the companion key that carries the sentence.
        $pairs = [
            'canunfreeze' => 'unfreezereason',
            'canrequestleave' => 'leavereason',
            'canreturnforming' => 'returnformingreason',
            'caneoirespond' => 'eoiblockedreason',
            'showjoinpanel' => 'joinblockedreason',
            'showrespond' => 'respondblocked',
            'tabsuccession' => 'successionempty',
        ];
        foreach ($pairs as $flag => $reason) {
            $this->assertStringContainsString(
                "'" . $flag . "' =>",
                $exporter,
                'the ' . $flag . ' surface has gone; this test needs revisiting, not deleting'
            );
            $this->assertStringContainsString(
                "'" . $reason . "' =>",
                $exporter,
                $flag . ' is exported without ' . $reason . ', so a refused-but-eligible person '
                    . 'sees an absence rather than an explanation (decision 83)'
            );
        }

        // The landing page's invitation row is the ninth surface and lives in
        // its own template: the prompt must survive a prohibited :respond.
        $landing = file_get_contents($root . '/templates/landing.mustache');
        $this->assertNotFalse($landing);
        $this->assertStringContainsString(
            '{{^mayrespond}}',
            $landing,
            'a prohibited invitee must be told why the buttons are absent, not left with a bare row'
        );
    }
    /**
     * Decision 73: the readiness panel lists EVERY live sidecar, not the first.
     *
     * can_submit() is a chain of early returns, so a leader with two live
     * sidecars - two pending invitations and a member's leave request, an
     * ordinary end-of-formation state - read one sentence, dealt with it,
     * pressed Submit and was handed a second. The service half has enforced
     * these four since 1.20.28; what was missing was anywhere to see them
     * together, which made a working rule feel arbitrary.
     *
     * MUTATION CAUGHT (run 2026-08-10): making submit_sidecars() return after
     * its first hit fails the count assertion.
     */
    public function test_the_readiness_panel_lists_every_live_sidecar(): void {
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
        $activity = \mod_selfselectadvanced\activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $invitee = $generator->create_user();
        $generator->enrol_user($invitee->id, $course->id, 'student');
        $member = $generator->create_user();
        $generator->enrol_user($member->id, $course->id, 'student');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Sidecars',
        ]);
        // Two live sidecars at once: an unanswered invitation AND a member who
        // has asked to leave. This is an ordinary end-of-formation state.
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $invitee->id,
            'status' => \mod_selfselectadvanced\local\groups::STATUS_INVITED,
        ]);
        $leaver = $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
        ]);
        $DB->set_field('selfselectadvanced_member', 'leaverequested', time(), ['id' => $leaver->id]);

        $fresh = \mod_selfselectadvanced\local\groups::get($activity, (int) $group->id);
        $sidecars = (new \mod_selfselectadvanced\local\api($activity))->gatekeeper()->submit_sidecars($fresh);

        $keys = array_map(static fn($r) => $r->stringkey, $sidecars);
        $this->assertCount(
            2,
            $sidecars,
            'the panel must show BOTH live sidecars; can_submit() shows only the first, which is the defect'
        );
        $this->assertContains('refusalsubmitleavepending', $keys);
        $this->assertContains('refusalsubmitinvitespending', $keys);

        // A team with nothing outstanding shows no panel - the control,
        // without which the assertion above would pass against a method that
        // always returns two rows.
        $clean = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Ready',
        ]);
        $this->assertSame(
            [],
            (new \mod_selfselectadvanced\local\api($activity))->gatekeeper()->submit_sidecars(
                \mod_selfselectadvanced\local\groups::get($activity, (int) $clean->id)
            )
        );
    }
}
