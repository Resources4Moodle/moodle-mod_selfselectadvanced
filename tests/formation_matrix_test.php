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
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\workflow_refusal;

/**
 * The formation permutation matrix (external audit, 1.20.22), as tests.
 *
 * Both independent audits of this plugin reached the same conclusion: the
 * defects that remain are not missing gates but COMPOSITIONS - individually
 * reasonable rules that disagree at the seam where two of them meet. The
 * answer to that is not another list of findings; it is a matrix that pins
 * the seams so a finding cannot come back.
 *
 * This file is the first tranche: the rows whose behaviour is already in
 * force. Rows that describe rulings not yet built (the Submit-obligation
 * family T05-T18, the source-intent and window rows T13-T17, the seat-plan
 * rows T22-T25) belong with the releases that build them, because a test
 * written today for behaviour ruled but unbuilt is a red gate, not coverage.
 * Each row below names its matrix id so the remainder can be added against
 * the same map.
 *
 * ROW ADAPTED, and worth saying: T01 as written prohibits `:creategroup`
 * to break Submit. Since the capability split that is the wrong capability
 * - creating and leading are separate now, and Submit is a leader verb -
 * so the row is driven with `:lead`. The matrix predates the split.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\joinrequests
 */
final class formation_matrix_test extends \advanced_testcase {
    /**
     * Prohibit a capability for a role, as an administrator would
     * mid-session, and clear the caches the change invalidates.
     *
     * @param string $capability the capability
     * @param \context $context where to prohibit it
     * @param string $shortname the role shortname
     */
    private function prohibit(string $capability, \context $context, string $shortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_change_permission($roleid, $context, $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A forming group, its leader, and spare enrolled students.
     *
     * @param array $settings create_module overrides
     * @return \stdClass bag: activity, api, group, leader, students[3], course, plugingen
     */
    private function world(array $settings = []): \stdClass {
        $w = new \stdClass();
        $gen = $this->getDataGenerator();
        $w->plugingen = $gen->get_plugin_generator('mod_selfselectadvanced');
        $w->course = $gen->create_course();
        $instance = $gen->create_module('selfselectadvanced', array_merge([
            'course' => $w->course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], $settings));
        $w->activity = activity::from_instance((int) $instance->id);
        $w->api = new api($w->activity);
        $mk = function () use ($gen, $w): \stdClass {
            $u = $gen->create_user();
            $gen->enrol_user($u->id, $w->course->id, 'student');

            return $u;
        };
        $w->leader = $mk();
        $w->students = [$mk(), $mk(), $mk()];
        $row = $w->plugingen->create_group([
            'activityid' => $w->activity->id(),
            'leaderid' => (int) $w->leader->id,
            'name' => 'Matrix',
        ]);
        $w->group = groups::get($w->activity, (int) $row->id);

        return $w;
    }

    /**
     * T01 — Submit, with leader authority prohibited after the page
     * rendered. The person pressed a button that was on their screen;
     * they must read why it did not work, and the group must not move.
     */
    public function test_t01_submit_after_leader_authority_is_prohibited(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        // A GUIDE, because leader-selects mode is the default and a
        // submit without one throws refusalguiderequired whatever the
        // actor's capability. The first version of this test omitted it
        // and passed with the prohibition removed - it was catching the
        // missing guide, not the missing authority. The mutation run
        // caught that, which is what the ritual is for.
        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $w->course->id, 'teacher');
        $this->assertNull($w->api->gatekeeper()->can_submit($w->group, (int) $w->leader->id));

        $this->prohibit(authority::LEAD, $w->activity->context(), 'student');

        try {
            $w->api->lifecycle()->submit($w->group, (int) $guide->id, (int) $w->leader->id);
            $this->fail('a prohibited leader must not move the group out of forming');
        } catch (\required_capability_exception $e) {
            // The SERVICE keeps core's type so cron and web services stay
            // loud; group.php's arms turn exactly this into a notice
            // (decision 72). Pinned as the specific class, not as "some
            // refusal", so a different refusal cannot satisfy this row.
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(
            state::FORMING,
            groups::get($w->activity, (int) $w->group->id)->state,
            'and the group has not moved'
        );
    }

    /**
     * T02 — Join Ask, with the responding capability prohibited after
     * the ask form rendered. No request row may appear.
     */
    public function test_t02_join_ask_after_respond_is_prohibited(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $asker = $w->students[0];

        $this->prohibit(authority::RESPOND, $w->activity->context(), 'student');

        try {
            joinrequests::request($w->activity, (int) $w->group->id, 'let me in', (int) $asker->id);
            $this->fail('a prohibited responder must not be able to file a request');
        } catch (\moodle_exception $e) {
            $this->assertTrue(
                $e instanceof workflow_refusal || $e instanceof \required_capability_exception,
                'got ' . get_class($e)
            );
        }
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_move', ['userid' => (int) $asker->id]),
            'no request row appeared'
        );
    }

    /**
     * T03 — Invitation Accept, with the responding capability
     * prohibited after the invitation was rendered. The invitation is
     * not consumed: it stays pending, so it can still be answered if
     * the administrator restores the permission.
     */
    public function test_t03_invitation_accept_after_respond_is_prohibited(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $invitee = $w->students[0];
        $member = $w->plugingen->create_member([
            'groupid' => (int) $w->group->id,
            'userid' => (int) $invitee->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);

        $this->prohibit(authority::RESPOND, $w->activity->context(), 'student');

        try {
            $w->api->invitations()->accept($w->group, (int) $invitee->id);
            $this->fail('a prohibited responder must not consume the invitation');
        } catch (\moodle_exception $e) {
            $this->assertTrue(
                $e instanceof workflow_refusal || $e instanceof \required_capability_exception,
                'got ' . get_class($e)
            );
        }
        $this->assertSame(
            groups::STATUS_INVITED,
            $DB->get_field('selfselectadvanced_member', 'status', ['id' => (int) $member->id]),
            'the invitation remains pending rather than being spent on a refusal'
        );
    }

    /**
     * T05, T06, T07, T08 — the formation sidecars. Four things a group
     * can have in flight while it forms, each settleable only while it
     * is forming: a wind-up request, a member waiting to be let go, a
     * leadership handover awaiting consent, and unanswered invitations.
     *
     * Submit used to advance past all four and strand them - the
     * member's one-click exit is forming-only, confirm-leave is
     * forming-only, the nominee's accept is forming-only - so the
     * intent stayed on the record with no way to act on it. Decision 73
     * blocks instead of silently cancelling, because each is somebody's
     * stated intention.
     *
     * MUTATION CAUGHT (run): deleting any one of the four arms from
     * can_submit() lets that row's group submit and fails its
     * assertion.
     *
     * @dataProvider sidecar_provider
     * @param string $sidecar which one to put in flight
     * @param string $expected the refusal it must produce
     */
    public function test_t05_t08_a_formation_sidecar_blocks_submit(string $sidecar, string $expected): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $member = $w->plugingen->create_member([
            'groupid' => (int) $w->group->id,
            'userid' => (int) $w->students[0]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $this->assertNull(
            $w->api->gatekeeper()->can_submit(
                groups::get($w->activity, (int) $w->group->id),
                (int) $w->leader->id
            ),
            'fixture: the group is submittable before the sidecar exists'
        );

        switch ($sidecar) {
            case 'disband':
                $DB->set_field(
                    'selfselectadvanced_group',
                    'timedisbandrequested',
                    time(),
                    ['id' => (int) $w->group->id]
                );
                break;
            case 'leave':
                $DB->set_field('selfselectadvanced_member', 'leaverequested', time(), ['id' => (int) $member->id]);
                break;
            case 'nomination':
                $DB->set_field(
                    'selfselectadvanced_group',
                    'successorid',
                    (int) $w->students[0]->id,
                    ['id' => (int) $w->group->id]
                );
                break;
            case 'invitation':
                $w->plugingen->create_member([
                    'groupid' => (int) $w->group->id,
                    'userid' => (int) $w->students[1]->id,
                    'status' => groups::STATUS_INVITED,
                    'timeinvited' => time(),
                ]);
                break;
        }

        $refusal = $w->api->gatekeeper()->can_submit(
            groups::get($w->activity, (int) $w->group->id),
            (int) $w->leader->id
        );
        $this->assertSame($expected, $refusal?->stringkey, "the $sidecar sidecar did not block submit");
    }

    /**
     * The four sidecars and the sentence each must produce.
     *
     * @return array[]
     */
    public static function sidecar_provider(): array {
        return [
            'T05 active disband' => ['disband', 'refusalsubmitdisbanding'],
            'T06 pending leave' => ['leave', 'refusalsubmitleavepending'],
            'T07 active nomination' => ['nomination', 'refusalsubmitnomination'],
            'T08 pending invitation' => ['invitation', 'refusalsubmitinvitespending'],
        ];
    }

    /**
     * T26 and T27 — a group formed under an old limit, whose maximum a
     * teacher then lowers. The matrix asks that the chosen policy
     * appear at Submit and at Approve, not only at Freeze. Decision 80
     * chose refusal at all three, with one sentence and one set of
     * figures.
     */
    public function test_t26_t27_over_maximum_answers_the_same_at_every_door(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world(['minsize' => 1, 'maxsize' => 5]);
        foreach ([0, 1] as $i) {
            $w->plugingen->create_member([
                'groupid' => (int) $w->group->id,
                'userid' => (int) $w->students[$i]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $w->course->id, 'teacher');

        // The limit drops under a roster of three.
        $DB->set_field('selfselectadvanced', 'maxsize', 2, ['id' => $w->activity->id()]);
        $activity = activity::from_instance($w->activity->id());
        $gate = (new api($activity))->gatekeeper();

        // T26: Submit.
        $submit = $gate->can_submit(groups::get($activity, (int) $w->group->id), (int) $w->leader->id);
        $this->assertSame('refusalovermaxsize', $submit?->stringkey, 'Submit must not defer this to Freeze');

        // T27: Approve, on the same group once submitted.
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => (int) $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guide->id, ['id' => (int) $w->group->id]);
        $approve = $gate->can_approve(groups::get($activity, (int) $w->group->id), (int) $guide->id);
        $this->assertSame('refusalovermaxsize', $approve?->stringkey, 'nor may Approve create an unlockable group');

        // One rule, one sentence, one set of figures - the property the
        // matrix is really asserting.
        $this->assertEquals($submit->a, $approve->a, 'the two doors described the same fact differently');
    }

    /**
     * T39 — the source membership vanished between asking and being
     * answered. The refusal names the group in the workflow's own
     * words, and the request stays open so the leader can decline it
     * with a note rather than meeting an engine error.
     */
    public function test_t39_join_accept_when_the_source_membership_is_gone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world(['maxmembership' => 1]);
        $mover = $w->students[0];

        $source = $w->plugingen->create_group([
            'activityid' => $w->activity->id(),
            'leaderid' => (int) $w->students[1]->id,
            'name' => 'Source',
        ]);
        $w->plugingen->create_member([
            'groupid' => (int) $source->id,
            'userid' => (int) $mover->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $request = joinrequests::request(
            $w->activity,
            (int) $w->group->id,
            'moving across',
            (int) $mover->id,
            (int) $source->id
        );

        // They leave the source by another route before the answer.
        $DB->set_field(
            'selfselectadvanced_member',
            'status',
            groups::STATUS_REMOVED,
            ['groupid' => (int) $source->id, 'userid' => (int) $mover->id]
        );

        try {
            joinrequests::respond($w->activity, (int) $request->id, true, '', (int) $w->leader->id, [], false);
            $this->fail('the stale source must be refused');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusaljoinsourcegone', $e->errorcode);
        }
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $request->id]),
            'and the request stays resolvable'
        );
    }

    /**
     * T40 — the requester reached the target by another route while
     * their request waited. Accepting must not remove the source
     * membership for a move that is already, in effect, done.
     */
    public function test_t40_join_accept_when_already_in_the_target(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world(['maxmembership' => 2]);
        $mover = $w->students[0];

        $source = $w->plugingen->create_group([
            'activityid' => $w->activity->id(),
            'leaderid' => (int) $w->students[1]->id,
            'name' => 'Source',
        ]);
        $w->plugingen->create_member([
            'groupid' => (int) $source->id,
            'userid' => (int) $mover->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $request = joinrequests::request(
            $w->activity,
            (int) $w->group->id,
            'moving across',
            (int) $mover->id,
            (int) $source->id
        );

        // Admitted to the target meanwhile, by an invitation or a move.
        $w->plugingen->create_member([
            'groupid' => (int) $w->group->id,
            'userid' => (int) $mover->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        try {
            joinrequests::respond($w->activity, (int) $request->id, true, '', (int) $w->leader->id, [], false);
            $this->fail('a request whose outcome already happened must be refused');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusaljointargetalready', $e->errorcode);
        }
        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field(
                'selfselectadvanced_member',
                'status',
                ['groupid' => (int) $source->id, 'userid' => (int) $mover->id]
            ),
            'and the source membership was NOT removed for nothing'
        );
    }
}
