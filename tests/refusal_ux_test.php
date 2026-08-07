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
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\quota\evaluator;

/**
 * The maintainer's three UX rulings of 2026-08-07 (10:44 IST):
 *
 * 1. "The message feels cryptic" - an unreachable-composition refusal
 *    names the CONCRETE unmet needs (which rules, which values), in
 *    the panel's own vocabulary, never just an aggregate count of
 *    members "who fit the team's rules".
 * 2. (group.php) A refused invitation acceptance answers with a NOTICE
 *    and a redirect, never the raw error page - covered by the live
 *    post-deploy check; the controller arm is framework-idiomatic.
 * 3. "Why not only keep the Decline active?" - an invitation whose
 *    acceptance the gate refuses renders with Accept DISABLED and the
 *    gate's own sentence beside it, for EVERY refusal the gate can
 *    raise, not only the hard-maximum tier the landing page used to
 *    transcribe.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\evaluator
 * @covers     \mod_selfselectadvanced\output\landing
 */
final class refusal_ux_test extends \advanced_testcase {
    /**
     * The g44 world: SCOPE between 2 and 2 plus at least 4 distinct
     * departments on five seats.
     *
     * @param string $leaderdept the leader's department
     * @return array [activity, api, team row, student-maker]
     */
    private function world(string $leaderdept = 'SCE'): array {
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
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
            'maxcount' => 2,
        ]);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'distinct',
            'mincount' => 4,
        ]);
        $student = function (string $dept) use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => 'BCL'], 2);

            return $user;
        };
        $leader = $student($leaderdept);
        $team = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Alpha',
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $team->id), $student];
    }

    /**
     * The landing template context for one viewer.
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
     * Ruling 1: the feasibility verdict carries the concrete unmet
     * needs, in rule priority order, in the panel's vocabulary. The
     * g44 shape with an SCE leader and an SCE candidate reads "2 more
     * from Department SCOPE; 3 more different Department value(s)" -
     * the two facts the cryptic aggregate hid.
     */
    public function test_the_needed_summary_names_the_rules(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $team, $student] = $this->world();
        $duplicate = $student('SCE');

        $rules = $DB->get_records('selfselectadvanced_quota', ['activityid' => $activity->id()], 'priority ASC');
        $memberids = [(int) $team->leaderid, (int) $duplicate->id];
        $result = evaluator::feasibility_from_data(
            $rules,
            [],
            $memberids,
            manager::get_for_users($memberids)
        );

        $dimension = get_string('attrdepartment', 'mod_selfselectadvanced');
        $this->assertSame(
            "2 more from {$dimension} SCOPE; 3 more different {$dimension} value(s)",
            $result->needed,
            'the refusal names WHICH rules are unmet, not an aggregate'
        );
        $this->assertSame(4, $result->missing, 'the corrected interaction bound is unchanged');
    }

    /**
     * Ruling 3, the exact gap: a refusal BEYOND the hard-maximum tier
     * - here an unreachable completion - disables the invitee's Accept
     * with the gate's sentence. Until 1.20.18 the landing page
     * transcribed only the hard-maximum, so this invitation rendered a
     * live Accept whose click answered with a raw error page.
     */
    public function test_an_unreachable_acceptance_disables_the_landing_accept(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $student] = $this->world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        // A DUPLICATE-department invitee: same dept as the leader, so
        // acceptance makes distinct-4 unreachable (2+2=4 > 3 free) -
        // an ENGINE-tier fact, invisible to the old hardmax-only arm.
        $twin = $student('SCE');
        $plugingen->create_member([
            'groupid' => (int) $team->id,
            'userid' => (int) $twin->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);

        $row = $this->landing($activity, $api, (int) $twin->id)->myinvitations[0];

        $this->assertTrue($row->blocked, 'the gate refuses, so the button must not offer');
        $this->assertStringContainsString(
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            $row->blockedreason,
            'and the reason names the concrete unmet needs'
        );
        $sink->close();
    }

    /**
     * The control case: an invitee the team can genuinely absorb keeps
     * a live Accept - the Aadhya lesson (the maintainer's item 4): a
     * NEW-department invitee under the same rules is legal, because the
     * team can still finish correctly around them.
     */
    public function test_a_completable_acceptance_keeps_accept_live(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $student] = $this->world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        // A NEW department: SCE leader + Civil invitee leaves
        // 2 SCOPE + 1 further department achievable in 3 seats.
        $civil = $student('Civil');
        $plugingen->create_member([
            'groupid' => (int) $team->id,
            'userid' => (int) $civil->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);

        $row = $this->landing($activity, $api, (int) $civil->id)->myinvitations[0];

        $this->assertFalse($row->blocked, 'a completable acceptance stays the invitee\'s to make');
        $this->assertSame('', $row->blockedreason);
        $sink->close();
    }

    /**
     * The unreachable refusal's sentence itself carries the concrete
     * list end to end - the gate, not just the evaluator: can_invite
     * for a duplicate-department candidate names both unmet rules.
     */
    public function test_the_gate_sentence_names_the_needs_end_to_end(): void {
        $this->resetAfterTest();
        [$activity, $api, $team, $student] = $this->world();
        $duplicate = $student('SCE');

        $refusal = $api->gatekeeper()->can_invite($team, (int) $duplicate->id);

        $this->assertNotNull($refusal);
        $dimension = get_string('attrdepartment', 'mod_selfselectadvanced');
        $this->assertStringContainsString("2 more from {$dimension} SCOPE", $refusal->get_message());
        $this->assertStringContainsString("3 more different {$dimension} value(s)", $refusal->get_message());
    }
}
