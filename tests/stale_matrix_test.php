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
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\local\workflow_refusal;

/**
 * The generic stale-action harness (consolidated master audit §15.2,
 * wave 1.5): for every human transition, prepare a world in which the
 * action is allowed, move exactly one relevant fact underneath the
 * open page, submit the stale action at the SERVICE seam, and pin two
 * things - the refusal travels as the TYPED workflow_refusal the
 * controller catches, and the domain state did not partially change.
 *
 * These are the seams whose transport changed in 1.20.22. Delete and
 * the invite-door shapes stay in stale_action_test (1.20.21);
 * proposal::save is excluded here because its fixture is a draft file
 * area, and its two refusals travel through the same typed transport
 * the refusal-typing gate guard pins statically.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\workflow_refusal
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\invitations
 */
final class stale_matrix_test extends \advanced_testcase {
    /**
     * One world, explicitly peopled: a forming leader-led team plus
     * spare students, two guides and an editing teacher.
     *
     * @param array $modsettings create_module overrides
     * @return \stdClass bag: activity, api, course, group, leader,
     *         students[3], guide, guide2, staff, staff2, gen, plugingen
     */
    private function world(array $modsettings = []): \stdClass {
        $w = new \stdClass();
        $w->gen = $this->getDataGenerator();
        $w->plugingen = $w->gen->get_plugin_generator('mod_selfselectadvanced');
        $w->course = $w->gen->create_course();
        $instance = $w->gen->create_module('selfselectadvanced', array_merge([
            'course' => $w->course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'contactmax' => 2,
            'eoienabled' => 1,
        ], $modsettings));
        $w->activity = activity::from_instance((int) $instance->id);
        $w->api = new api($w->activity);
        $enrol = function (string $role) use ($w): \stdClass {
            $user = $w->gen->create_user();
            $w->gen->enrol_user($user->id, $w->course->id, $role);

            return $user;
        };
        $w->leader = $enrol('student');
        $w->students = [$enrol('student'), $enrol('student'), $enrol('student')];
        $w->guide = $enrol('teacher');
        $w->guide2 = $enrol('teacher');
        $w->staff = $enrol('editingteacher');
        $w->staff2 = $enrol('editingteacher');
        $group = $w->plugingen->create_group([
            'activityid' => $w->activity->id(),
            'leaderid' => (int) $w->leader->id,
            'name' => 'Matrix',
        ]);
        $w->group = groups::get($w->activity, (int) $group->id);

        return $w;
    }

    /**
     * Fresh row for the world's team.
     *
     * @param \stdClass $w the world
     * @return \stdClass current group row
     */
    private function fresh(\stdClass $w): \stdClass {
        return groups::get($w->activity, (int) $w->group->id);
    }

    /**
     * Run the stale action and pin the transport type.
     *
     * @param callable $act the service call the stale POST would make
     * @param string|null $errorcode exact reason code, when pinned
     * @return workflow_refusal the caught refusal, for extra asserts
     */
    private function assert_refuses_typed(callable $act, ?string $errorcode = null): workflow_refusal {
        try {
            $act();
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(
                workflow_refusal::class,
                $e,
                "expected a TYPED refusal, got {$e->errorcode} as " . get_class($e)
            );
            if ($errorcode !== null) {
                $this->assertSame($errorcode, $e->errorcode);
            } else {
                $this->assertMatchesRegularExpression(
                    '/^(refusal|err)/',
                    $e->errorcode,
                    'the machine reason stays a stable plugin code'
                );
            }

            return $e;
        }
        $this->fail('The stale action must be refused');
    }

    /**
     * Submit after leadership moved: the old leader's page is open,
     * the crown has passed, the POST lands typed and the team stays
     * FORMING.
     */
    public function test_submit_by_superseded_leader(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $this->assertNull($w->api->gatekeeper()->can_submit($w->group, (int) $w->leader->id));

        $DB->set_field('selfselectadvanced_group', 'leaderid', (int) $w->students[0]->id, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => $w->api->lifecycle()->submit($w->group, null, (int) $w->leader->id)
        );
        $this->assertSame(state::FORMING, $this->fresh($w)->state, 'no partial transition');
    }

    /**
     * Invite after the last seat filled behind the picker.
     */
    public function test_invite_after_last_seat_filled(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world(['maxsize' => 2]);
        $candidate = $w->students[0];
        $this->assertNull($w->api->gatekeeper()->can_invite($w->group, (int) $candidate->id));

        // The world moves: somebody else takes the seat.
        $w->plugingen->create_member([
            'groupid' => $w->group->id,
            'userid' => (int) $w->students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $e = $this->assert_refuses_typed(
            fn() => $w->api->invitations()->send($this->fresh($w), (int) $candidate->id, (int) $w->leader->id)
        );
        $this->assertStringStartsWith('refusal', $e->errorcode);
        $this->assertSame(0, $DB->count_records('selfselectadvanced_member', [
            'groupid' => (int) $w->group->id,
            'userid' => (int) $candidate->id,
        ]), 'no invitation row appeared');
    }

    /**
     * Accept after the invitation was withdrawn: the documented
     * ordinary race, now pinned to the type at the seam.
     */
    public function test_accept_after_withdrawal(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $invitee = $w->students[0];
        $member = $w->plugingen->create_member([
            'groupid' => $w->group->id,
            'userid' => (int) $invitee->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);
        $this->assertNull($w->api->gatekeeper()->can_accept($this->fresh($w), $DB->get_record(
            'selfselectadvanced_member',
            ['id' => (int) $member->id]
        )));

        $w->api->invitations()->withdraw($this->fresh($w), (int) $member->id, (int) $w->leader->id);

        $this->assert_refuses_typed(
            fn() => $w->api->invitations()->accept($this->fresh($w), (int) $invitee->id),
            'refusalnotinvited'
        );
        $this->assertSame(
            groups::STATUS_REMOVED,
            $DB->get_field('selfselectadvanced_member', 'status', ['id' => (int) $member->id]),
            'the withdrawal stands'
        );
    }

    /**
     * A leave request filed after the team was submitted.
     */
    public function test_request_leave_after_submission(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $member = $w->plugingen->create_member([
            'groupid' => $w->group->id,
            'userid' => (int) $w->students[0]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => $w->api->invitations()->request_leave($this->fresh($w), (int) $w->students[0]->id)
        );
        $this->assertEmpty(
            (int) $DB->get_field('selfselectadvanced_member', 'leaverequested', ['id' => (int) $member->id]),
            'no leave request was recorded'
        );
    }

    /**
     * A succession nomination by the superseded leader.
     */
    public function test_nominate_by_superseded_leader(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $w->plugingen->create_member([
            'groupid' => $w->group->id,
            'userid' => (int) $w->students[0]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $DB->set_field('selfselectadvanced_group', 'leaderid', (int) $w->students[0]->id, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => $w->api->succession()->nominate(
                $w->group,
                (int) $w->students[0]->id,
                'transfer',
                (int) $w->leader->id
            )
        );
        $this->assertEmpty(
            (int) $this->fresh($w)->successorid,
            'no nomination was written'
        );
    }

    /**
     * Contact-a-guide after an accepted expression already assigned
     * one.
     */
    public function test_contact_after_guide_arrived(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();

        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $w->guide2->id, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => contacts::send(
                $w->activity,
                $this->fresh($w),
                (int) $w->guide->id,
                'please guide us',
                FORMAT_PLAIN,
                (int) $w->leader->id
            ),
            'refusalcontacthasguide'
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_contact'), 'nothing was filed');
    }

    /**
     * A guide's response to a contact that was reassigned meanwhile -
     * one of the nine 1.20.21 surfaces, pinned at the seam.
     */
    public function test_contact_respond_by_reassigned_guide(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $contact = contacts::send(
            $w->activity,
            $w->group,
            (int) $w->guide->id,
            'please guide us',
            FORMAT_PLAIN,
            (int) $w->leader->id
        );

        $this->assert_refuses_typed(
            fn() => contacts::respond(
                $w->activity,
                (int) $contact->id,
                true,
                '',
                FORMAT_PLAIN,
                (int) $w->guide2->id
            ),
            'refusalcontactnotyours'
        );
        $this->assertSame(
            contacts::STATUS_SENT,
            $DB->get_field('selfselectadvanced_contact', 'status', ['id' => (int) $contact->id]),
            'the contact still awaits its addressee'
        );
    }

    /**
     * An expression of interest on a team unlisted underneath the
     * guide's open pick page.
     */
    public function test_eoi_express_after_unlisting(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'timelisted', time(), ['id' => $w->group->id]);

        $DB->set_field('selfselectadvanced_group', 'listed', 0, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => eoi::express($w->activity, (int) $w->group->id, (int) $w->guide->id, 'me', FORMAT_PLAIN),
            'refusaleoinotlisted'
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_eoi'), 'no interest row appeared');
    }

    /**
     * Approve after the team returned to forming.
     */
    public function test_approve_after_return_to_forming(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $w->guide->id, ['id' => $w->group->id]);
        $this->assertNull($w->api->gatekeeper()->can_approve($this->fresh($w), (int) $w->guide->id));

        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => $w->api->lifecycle()->approve($this->fresh($w), (int) $w->guide->id)
        );
        $this->assertSame(state::FORMING, $this->fresh($w)->state, 'no approval was stamped');
    }

    /**
     * Return after the approval was already undone.
     */
    public function test_return_after_already_returned(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $w->guide->id, ['id' => $w->group->id]);
        $stale = $this->fresh($w);
        $this->assertNull($w->api->gatekeeper()->can_return($stale, (int) $w->guide->id));

        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => $w->api->lifecycle()->return_group($stale, 'roster needs work', (int) $w->guide->id)
        );
        $this->assertSame(state::FORMING, $this->fresh($w)->state);
    }

    /**
     * Freeze after the state moved off FIRM.
     */
    public function test_freeze_after_state_moved(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $w->guide->id, ['id' => $w->group->id]);
        $stale = $this->fresh($w);

        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => freeze::freeze_group($w->activity, $stale, (int) $w->guide->id)
        );
        $this->assertEmpty((int) $this->fresh($w)->coregroupid, 'no course group was minted');
    }

    /**
     * The unfreeze reason gate as a RACE: no roster delta when the
     * confirmation page rendered, so its reason field was optional -
     * the roster then moved, and the empty-reason POST must land as
     * the typed refusal, not a fatal page (the errunfreezereasonrequired
     * retype this wave exists for exactly this).
     */
    public function test_unfreeze_reason_required_when_delta_appears(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        $w = $this->world();
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $w->guide->id, ['id' => $w->group->id]);
        $member = $w->plugingen->create_member([
            'groupid' => $w->group->id,
            'userid' => (int) $w->students[0]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        freeze::freeze_group($w->activity, $this->fresh($w), (int) $w->guide->id);
        $this->runAdhocTasks();

        // The world moves: a member's plugin row leaves the roster, so
        // the restore now carries a delta and demands its reason.
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, ['id' => (int) $member->id]);

        $this->assert_refuses_typed(
            fn() => freeze::unfreeze($w->activity, $this->fresh($w), (int) $w->guide->id, ''),
            'errunfreezereasonrequired'
        );
        $this->assertSame(state::FROZEN, $this->fresh($w)->state, 'the team stays locked');
    }

    /**
     * The requester left the course between asking and being answered
     * (R2, 1.20.25). The waiting list never asks whether a requester is
     * still a participant, so the leader is shown a name and a live
     * Accept for somebody who has withdrawn, been suspended, or whose
     * enrolment simply ran out. Pressing it used to hand him the move
     * engine's own untyped participant error, which is to say the fatal
     * page - for doing the one thing the page offered.
     *
     * MUTATION CAUGHT (run): removing the is_enrolled arm from
     * do_accept() lets the engine error escape untyped and fails the
     * typed assertion here.
     */
    public function test_join_accept_after_the_requester_left_the_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $asker = $w->students[0];
        $request = joinrequests::request($w->activity, (int) $w->group->id, 'let me in', (int) $asker->id);

        // They leave the course. The request does not know that.
        $DB->set_field(
            'user_enrolments',
            'status',
            ENROL_USER_SUSPENDED,
            ['userid' => (int) $asker->id]
        );

        $e = $this->assert_refuses_typed(
            fn() => joinrequests::respond(
                $w->activity,
                (int) $request->id,
                true,
                '',
                (int) $w->leader->id,
                [],
                false
            ),
            'refusaljoinleft'
        );
        $this->assertStringNotContainsString(
            'errmove',
            $e->getMessage(),
            'the leader reads about the student, not about the move engine'
        );
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $request->id]),
            'and the request stays open so the leader can decline it with a note'
        );
    }

    /**
     * Two people act on one group inside ten seconds and one loses the
     * lock. That is a stopwatch expiring, not a fault, and its sentence
     * has always read like a notice - it was simply delivered on the
     * fatal page because the key happens to begin "err" (R2, 1.20.25).
     */
    public function test_a_lock_timeout_is_an_ordinary_refusal(): void {
        $this->resetAfterTest();
        $this->assertTrue(
            is_subclass_of(workflow_refusal::class, \moodle_exception::class),
            'fixture check'
        );
        \mod_selfselectadvanced\local\locks::set_test_hook(function (string $resource): void {
            throw new workflow_refusal('errlocktimeout', 'mod_selfselectadvanced');
        });
        try {
            $w = $this->world();
            $this->assert_refuses_typed(
                fn() => $w->api->invitations()->send($w->group, (int) $w->students[0]->id, (int) $w->leader->id),
                'errlocktimeout'
            );
        } finally {
            \mod_selfselectadvanced\local\locks::set_test_hook(null);
        }
    }

    /**
     * ROUTE PARITY (decision 82). Two ways exist to become a confirmed
     * member of the same group with the same eventual roster: the
     * leader invites and the student accepts, or the student asks and
     * the leader accepts. They must agree on the hard composition
     * question, because the roster they would produce is identical and
     * a rule that depends on which door you came through is not a rule.
     *
     * Until this ruling they did not. Join acceptance asked the shared
     * verdict's engine tier; invitation acceptance computed the very
     * same verdict, honoured only its maximum, and then re-asked a
     * weaker question over a basis that counted other people's
     * unanswered invitations.
     *
     * MUTATION CAUGHT (run): restoring the old full-invited re-ask in
     * can_accept() makes the two doors return different keys and fails
     * the identity assertion below.
     */
    public function test_both_join_routes_give_one_composition_answer(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world(['maxsize' => 3, 'minsize' => 3]);
        $plugingen = $w->plugingen;

        // A composition the group cannot finish once this candidate is
        // in it: one dimension, a minimum of two distinct values, and
        // only one seat left after the candidate takes theirs.
        $DB->insert_record('selfselectadvanced_quota', (object) [
            'activityid' => $w->activity->id(),
            'dimension' => 'department',
            'rtype' => 'distinct',
            'value' => null,
            'mincount' => 3,
            'maxcount' => 0,
            'priority' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $candidate = $w->students[0];
        $fresh = groups::get($w->activity, (int) $w->group->id);

        // Door 1: the student asks, the leader would accept.
        $request = joinrequests::request($w->activity, (int) $w->group->id, 'let me in', (int) $candidate->id);
        $askdecision = joinrequests::accept_decision(
            $w->activity,
            $DB->get_record('selfselectadvanced_move', ['id' => (int) $request->id], '*', MUST_EXIST),
            (int) $w->leader->id
        );
        joinrequests::withdraw($w->activity, (int) $request->id, (int) $candidate->id);

        // Door 2: the leader invites, the student would accept. Same
        // person, same group, same eventual roster.
        $member = $w->plugingen->create_member([
            'groupid' => (int) $w->group->id,
            'userid' => (int) $candidate->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);
        $row = $DB->get_record('selfselectadvanced_member', ['id' => (int) $member->id], '*', MUST_EXIST);
        $acceptrefusal = $w->api->gatekeeper()->can_accept(groups::get($w->activity, (int) $w->group->id), $row);

        // Whatever the rules say about that roster, both doors say it.
        $this->assertSame(
            !$askdecision->canaccept,
            $acceptrefusal !== null,
            'one eventual roster, two routes, and they disagreed about whether it is allowed'
        );
        if (!$askdecision->canaccept) {
            $this->assertSame(
                $askdecision->hardkey,
                $acceptrefusal?->stringkey,
                'both doors refuse, but for reasons a person would read as different rules'
            );
        }
    }

    /**
     * Decision 72: an administrator adjusting a role while somebody has
     * a page open is an ordinary fact of a live site, not a fault. The
     * services still throw core's permission exception - so web
     * services, cron and CLI keep failing loudly, where a missing
     * capability really is a configuration fault - but the page arms
     * answer it as a notice, because the person pressed a button that
     * was on the page a moment earlier and did nothing wrong.
     *
     * MUTATION CAUGHT (run): narrowing group.php's catches back to the
     * typed refusal alone fails the count assertion here, and removing
     * the required_capability_exception branch from
     * selfselectadvanced_refusal_notice() fails the sentence assertion.
     */
    public function test_a_revoked_capability_reads_as_a_notice_not_a_fault(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/lib.php');

        // The helper is the decision, so ask it directly.
        $capability = new \required_capability_exception(
            \context_system::instance(),
            'mod/selfselectadvanced:creategroup',
            'nopermissions',
            ''
        );
        $notice = selfselectadvanced_refusal_notice($capability);
        $this->assertSame(
            get_string('refusalauthoritygone', 'mod_selfselectadvanced'),
            $notice,
            'a capability taken away mid-session is explained in the plugin&apos;s own words'
        );
        $this->assertStringNotContainsString(
            'Sorry',
            $notice,
            'and not in core&apos;s generic permission wording, which reads like a fault'
        );

        // A workflow refusal still speaks for itself.
        $workflow = new workflow_refusal('refusalnotleader', 'mod_selfselectadvanced');
        $this->assertSame(
            $workflow->getMessage(),
            selfselectadvanced_refusal_notice($workflow),
            'the workflow&apos;s own sentence is never replaced'
        );

        // And every page arm that catches a refusal catches this too -
        // the half no unit test can reach, since a page has no seam.
        $page = file_get_contents(__DIR__ . '/../group.php');
        $this->assertSame(
            substr_count($page, 'catch (\mod_selfselectadvanced\local\workflow_refusal'),
            substr_count($page, '| \required_capability_exception $e) {'),
            'an arm that answers a workflow refusal must answer a revoked capability the same way'
        );
        $this->assertSame(
            0,
            substr_count($page, '$e->getMessage()'),
            'and none of them may print the raw message, which would leak core&apos;s wording'
        );
    }

    /**
     * Decision 80: grandfathering is not automatically fair. A group
     * formed at five under an old limit, whose maximum a teacher then
     * lowers, used to sail through Submit and Approve and meet its
     * first objection at Freeze - after a guide had already spent the
     * review effort on a group that could never be locked. Submit now
     * says so, in the same sentence with the same figures that Freeze
     * has always used, so all three doors describe one rule.
     *
     * MUTATION CAUGHT (run): removing the over-maximum arm from
     * can_submit() lets the group submit and fails the assertion here.
     */
    public function test_over_maximum_is_refused_at_submit_not_first_at_freeze(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world(['minsize' => 1, 'maxsize' => 5]);

        // Three confirmed on a roster formed while five were allowed.
        foreach ([0, 1] as $i) {
            $w->plugingen->create_member([
                'groupid' => (int) $w->group->id,
                'userid' => (int) $w->students[$i]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        $fresh = groups::get($w->activity, (int) $w->group->id);
        $this->assertNull(
            $w->api->gatekeeper()->can_submit($fresh, (int) $w->leader->id),
            'fixture: this group is submittable while the limit allows it'
        );

        // The teacher lowers the maximum under them.
        $DB->set_field('selfselectadvanced', 'maxsize', 2, ['id' => $w->activity->id()]);
        $activity = activity::from_instance($w->activity->id());
        $refusal = (new api($activity))->gatekeeper()->can_submit(
            groups::get($activity, (int) $w->group->id),
            (int) $w->leader->id
        );

        $this->assertSame('refusalovermaxsize', $refusal?->stringkey);
        $this->assertSame(3, (int) $refusal->a->current);
        $this->assertSame(2, (int) $refusal->a->max);
        $this->assertSame(1, (int) $refusal->a->excess, 'the remedy is sized: one member over');
    }

    /**
     * A join request decided by the superseded leader.
     */
    public function test_join_accept_by_superseded_leader(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $request = joinrequests::request($w->activity, (int) $w->group->id, 'let me in', (int) $w->students[0]->id);

        $DB->set_field('selfselectadvanced_group', 'leaderid', (int) $w->students[1]->id, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => joinrequests::respond($w->activity, (int) $request->id, true, '', (int) $w->leader->id, [], false),
            'refusaljoinnotleader'
        );
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $request->id]),
            'the request still awaits the real leader'
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_member', [
            'groupid' => (int) $w->group->id,
            'userid' => (int) $w->students[0]->id,
        ]), 'nobody joined');
    }

    /**
     * A handover accepted after it was cancelled.
     */
    public function test_handover_accept_after_cancel(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $w->guide->id, ['id' => $w->group->id]);
        $w->api->handover()->propose((int) $w->group->id, (int) $w->guide2->id, (int) $w->guide->id);

        $w->api->handover()->cancel((int) $w->group->id, (int) $w->guide->id);

        $this->assert_refuses_typed(
            fn() => $w->api->handover()->accept((int) $w->group->id, (int) $w->guide2->id)
        );
        $this->assertSame(
            (int) $w->guide->id,
            (int) $this->fresh($w)->guideid,
            'the guide did not change'
        );
    }

    /**
     * The exclusive claim, pinned to the TYPE (the message and row
     * outcome are pinned in tickets_test): the second worker's stale
     * queue page answers with a notice, never the fatal renderer.
     */
    public function test_ticket_claim_already_claimed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        $w = $this->world();
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $w->group->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $w->guide->id, ['id' => $w->group->id]);
        $ticket = tickets::file(
            $w->activity,
            $this->fresh($w),
            tickets::TYPE_COMPCHANGE,
            'swap one member',
            FORMAT_PLAIN,
            (int) $w->guide->id
        );
        tickets::claim($w->activity, (int) $ticket->id, (int) $w->staff->id);

        $this->assert_refuses_typed(
            fn() => tickets::claim($w->activity, (int) $ticket->id, (int) $w->staff2->id),
            'refusalticketclaimed'
        );
        $this->assertSame(
            (int) $w->staff->id,
            (int) $DB->get_field('selfselectadvanced_ticket', 'claimedby', ['id' => (int) $ticket->id])
        );
    }

    /**
     * Cancelling a move that was committed meanwhile: the
     * dml-exception hole this wave closed - two workers, one queue,
     * and the loser must read an answer, not a stack trace.
     *
     * MUTATION CAUGHT (run): restoring MUST_EXIST to cancel()'s
     * pending read fails this test with dml_missing_record_exception.
     */
    public function test_move_cancel_after_commit(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $staged = $w->api->moves()->stage(
            (int) $w->students[0]->id,
            null,
            (int) $w->group->id,
            false,
            null,
            (int) $w->staff->id
        );

        $DB->set_field('selfselectadvanced_move', 'status', 'committed', ['id' => (int) $staged->id]);

        $this->assert_refuses_typed(
            fn() => $w->api->moves()->cancel((int) $staged->id, (int) $w->staff->id),
            'refusalmovegone'
        );
        $this->assertSame(
            'committed',
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $staged->id]),
            'the committed move was not relabelled'
        );
    }

    /**
     * A team edit by the superseded leader.
     */
    public function test_group_edit_by_superseded_leader(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        $w = $this->world();
        $oldtitle = (string) $w->group->title;

        $DB->set_field('selfselectadvanced_group', 'leaderid', (int) $w->students[0]->id, ['id' => $w->group->id]);

        $this->assert_refuses_typed(
            fn() => $w->api->update_group_details($w->group, 'New title', 'New brief', FORMAT_HTML, (int) $w->leader->id),
            'refusalnotleader'
        );
        $this->assertSame(
            $oldtitle,
            (string) $this->fresh($w)->title,
            'the edit did not land'
        );
    }
}
