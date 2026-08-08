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
use mod_selfselectadvanced\local\workflow_refusal;

/**
 * Stale-page races at the SERVICE seam (external audit MKT-02/TEST-01,
 * 1.20.21): render-while-allowed, mutate the state underneath, submit
 * the old action - the service's in-lock recheck must refuse with a
 * TYPED expected refusal the controller catches into a notice, and a
 * genuine coding error must NOT be disguised as one.
 *
 * The audit's criticism of the old text-count test was exact: counting
 * NOTIFY_ERROR occurrences in a file cannot prove a particular service
 * call is inside the right catch. These tests drive the SERVICE the
 * stale POST would drive and pin the exception TYPE, which is what the
 * controller's catch keys on. Since 1.20.22, stale_matrix_test extends
 * this harness across fifteen more seams and refusal_arms_test asserts
 * the source contract itself (no untyped refusal, no swallowing catch)
 * instead of counting strings.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\workflow_refusal
 */
final class stale_action_test extends \advanced_testcase {
    /**
     * A forming leader-alone team - delete-eligible at page load.
     *
     * @return array [activity, api, group row, leaderid]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Stale',
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), (int) $leader->id];
    }

    /**
     * The MKT-02 race verbatim: delete is eligible when the
     * confirmation page loads, the state moves before the POST, and
     * the service's in-lock recheck refuses with the TYPED workflow
     * refusal - the exact class group.php's delete arm catches into a
     * notice, so the person never meets the fatal renderer.
     *
     * MUTATION CAUGHT (run): retyping api::delete_group()'s recheck
     * throw back to bare moodle_exception fails the instanceof
     * assertion, exactly the drift that would silently reopen the
     * fatal page.
     */
    public function test_stale_delete_is_a_typed_workflow_refusal(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, $leaderid] = $this->world();

        // Page load: eligible.
        $this->assertNull($api->gatekeeper()->can_delete_group($group, $leaderid));

        // The world moves: the team is submitted before the click.
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => (int) $group->id]);

        try {
            // The stale POST, on the row the page loaded.
            $api->delete_group($group, $leaderid);
            $this->fail('The in-lock recheck must refuse the stale delete');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(
                workflow_refusal::class,
                $e,
                'an expected workflow race travels TYPED, so the controller can catch exactly it'
            );
        }
        $this->assertTrue(
            $DB->record_exists('selfselectadvanced_group', ['id' => (int) $group->id]),
            'and the team survives the refused delete'
        );
        $sink->close();
    }

    /**
     * The other half of the audit's contract: a genuine failure is NOT
     * an expected refusal. The typed class must remain a subclass so
     * broad legacy catches still work, but nothing constructs it for
     * coding errors - a coding_exception is not a workflow_refusal.
     */
    public function test_a_coding_error_is_not_a_workflow_refusal(): void {
        $this->assertFalse(
            is_subclass_of(\coding_exception::class, workflow_refusal::class),
            'genuine failures must never be catchable as expected workflow decisions'
        );
        $this->assertTrue(
            is_subclass_of(workflow_refusal::class, \moodle_exception::class),
            'while the typed refusal stays a moodle_exception for legacy broad catches'
        );
    }

    /**
     * The invite control and the invite door speak with one voice
     * (external audit MKT-03): a team full of CONFIRMED members gets
     * the confirmed-full sentence, a team whose fullness includes a
     * pending invitation gets the withdrawable-invitation one - from
     * the same predicate the control renders.
     *
     * MUTATION CAUGHT (run): hard-coding refusalnoseats in
     * invite_door_refusal() fails the confirmed-full arm.
     */
    public function test_invite_door_chooses_the_honest_full_sentence(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 2,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $mk = function () use ($generator, $course): int {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');

            return (int) $user->id;
        };
        $api = new api($activity);

        // Shape 1: full of confirmed members.
        $confirmedfull = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $mk(),
            'name' => 'ConfirmedFull',
        ]);
        $plugingen->create_member([
            'groupid' => $confirmedfull->id,
            'userid' => $mk(),
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $refusal = $api->gatekeeper()->invite_door_refusal(groups::get($activity, (int) $confirmedfull->id));
        $this->assertSame(
            'refusalnoseatsconfirmed',
            $refusal?->stringkey,
            'nothing to withdraw, and the sentence says so'
        );

        // Shape 2: fullness includes a pending invitation.
        $invitedfull = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $mk(),
            'name' => 'InvitedFull',
        ]);
        $plugingen->create_member([
            'groupid' => $invitedfull->id,
            'userid' => $mk(),
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);
        $refusal = $api->gatekeeper()->invite_door_refusal(groups::get($activity, (int) $invitedfull->id));
        $this->assertSame(
            'refusalnoseats',
            $refusal?->stringkey,
            'a withdrawable invitation exists, so the withdraw advice is true'
        );

        // Shape 3: room remains - the control is offered.
        $open = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $mk(),
            'name' => 'Open',
        ]);
        $this->assertNull($api->gatekeeper()->invite_door_refusal(groups::get($activity, (int) $open->id)));
    }

    /**
     * The freeze door names OVER-maximum for what it is (external
     * audit UX-02): a roster the settings outgrew is not merely
     * "full" - the sentence carries the figures and the remedy.
     */
    public function test_over_maximum_freeze_names_the_figures(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $group, $leaderid] = $this->world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        // Fill to 3 confirmed, then the maximum drops to 2.
        for ($i = 0; $i < 2; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, (int) $activity->cm()->course, 'student');
            $plugingen->create_member([
                'groupid' => (int) $group->id,
                'userid' => (int) $user->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        $DB->set_field('selfselectadvanced', 'maxsize', 2, ['id' => $activity->id()]);
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => (int) $group->id]);
        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, (int) $activity->cm()->course, 'teacher');
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guide->id, ['id' => (int) $group->id]);

        // The activity object caches its settings row; the lowered
        // maximum is only visible to a fresh instance.
        $activity = activity::from_instance($activity->id());
        $fresh = groups::get($activity, (int) $group->id);
        $refusal = (new api($activity))->gatekeeper()->can_freeze($fresh, (int) $guide->id);

        $this->assertSame('refusalovermaxsize', $refusal?->stringkey);
        $this->assertSame(3, (int) $refusal->a->current);
        $this->assertSame(2, (int) $refusal->a->max);
        $this->assertSame(1, (int) $refusal->a->excess, 'the remedy is sized: one member over');
    }
}
