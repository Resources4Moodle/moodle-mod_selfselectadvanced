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
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\task\bulkfreeze_adhoc;

/**
 * What an administrator's PROHIBIT is worth at each service seam.
 *
 * Every test here follows the same shape, because it is the only shape
 * that proves anything: establish that the capability is effective,
 * PROHIBIT it at the activity context, call the SAME production
 * function the page calls, and watch it refuse - then check the
 * database, because a refusal that still wrote the row is not a
 * refusal.
 *
 * Nothing here restates the condition under test. There is no copy of
 * has_capability() in this file to be right about while the service is
 * wrong; every assertion runs through api::create_group(),
 * api::delete_group(), invitations::*, freeze::*,
 * task\bulkfreeze_adhoc::execute() or ledger::set_award().
 *
 * The four holes these pin (1.20 authorisation audit):
 *
 * - A-02 :creategroup was never asked for. gatekeeper::can_create_group()
 *   answers windows and caps - rule eligibility - and can_delete_group()
 *   answers "forming?" and "are you the leader?" - record ownership.
 *   Neither is authority, and nothing else asked.
 * - A-03 the invitation service ignored :respond, while its sibling
 *   joinrequests::request() had required it from the start.
 * - A-01 bulk freeze checked the capability when QUEUEING and the
 *   queued half never checked again.
 * - A-06 ledger::set_award() took no actor, so it authorised nobody,
 *   and took a group row without checking it belonged to the activity.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\authority
 * @covers     \mod_selfselectadvanced\local\api
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\penalty\ledger
 * @covers     \mod_selfselectadvanced\local\freeze
 * @covers     \mod_selfselectadvanced\task\bulkfreeze_adhoc
 */
final class prohibited_capability_test extends \advanced_testcase {
    /**
     * A course, an activity, three students, a guide and an editing
     * teacher.
     *
     * @param array $settings instance setting overrides
     * @return array{0: activity, 1: api, 2: \stdClass[], 3: \stdClass, 4: \stdClass, 5: \stdClass}
     *         activity, api, students, guide, staff, course
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
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        return [$activity, new api($activity), $students, $guide, $staff, $course];
    }

    /**
     * Prohibit a capability for a role at the ACTIVITY context - the
     * override an administrator actually makes on the activity's
     * Permissions page.
     *
     * @param string $capability the capability to prohibit
     * @param \context $context the context to prohibit it in
     * @param string $shortname the role's shortname
     */
    private function prohibit(string $capability, \context $context, string $shortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_change_permission($roleid, $context, $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A-02: a prohibited student cannot create a team through the
     * service, however the request reaches it.
     */
    public function test_create_group_refuses_a_prohibited_student(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;

        // The fixture is only meaningful if the capability was live.
        $this->assertTrue(authority::may_lead($activity, $leader));

        $this->prohibit(authority::CREATEGROUP, $activity->context(), 'student');
        $this->assertFalse(authority::may_lead($activity, $leader));

        try {
            $api->create_group($leader, 'Team Prohibited', 'T', '<p>b</p>', FORMAT_HTML);
            $this->fail('create_group() accepted an actor whose capability is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::CREATEGROUP), $e->a);
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_group', [
            'activityid' => $activity->id(),
        ]), 'A refused creation still inserted a team');
        $this->assertSame(0, $DB->count_records('selfselectadvanced_member', []));
    }

    /**
     * A-02, the other half: the STAFF creation path is authorised by
     * :manage and must be untouched. An editing teacher does not hold
     * :creategroup at all - it is a student capability (D6-4) - so a
     * fix that reached for it here would have broken the repair path
     * the verb exists for.
     */
    public function test_staff_creation_is_not_caught_by_the_leader_gate(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, , $staff] = $this->fixture();

        $this->assertFalse(authority::may_lead($activity, (int) $staff->id));
        $this->prohibit(authority::CREATEGROUP, $activity->context(), 'student');

        $group = $api->create_group(
            (int) $staff->id,
            'Made by staff',
            'Work',
            '<p>Brief</p>',
            FORMAT_HTML,
            (int) $students[0]->id,
            true
        );

        $this->assertSame(
            (int) $students[0]->id,
            (int) groups::get($activity, (int) $group->id)->leaderid
        );
    }

    /**
     * A-02: a leader whose capability has since been prohibited cannot
     * delete their own forming team. Owning the record is not authority
     * over it, and can_delete_group() only ever asked about the record.
     */
    public function test_delete_group_refuses_a_prohibited_leader(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;

        $group = $api->create_group($leader, 'Doomed', 'T', '<p>b</p>', FORMAT_HTML);
        $this->prohibit(authority::CREATEGROUP, $activity->context(), 'student');

        // Ownership and state are both still perfect: the ONLY thing
        // that changed is the administrator's decision.
        $this->assertNull($api->gatekeeper()->can_delete_group($group, $leader));

        try {
            $api->delete_group($group, $leader);
            $this->fail('delete_group() accepted a leader whose capability is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::CREATEGROUP), $e->a);
        }

        $this->assertTrue($DB->record_exists('selfselectadvanced_group', ['id' => $group->id]));
    }

    /**
     * A-02: the leader's roster verbs - invite, withdraw, confirm a
     * leave - are the same authority as creating the team, and all
     * three refuse once it is prohibited.
     */
    public function test_leader_roster_verbs_refuse_a_prohibited_leader(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;

        $group = $api->create_group($leader, 'Roster', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);

        // A confirmed member who has asked to leave, and a pending
        // invitation - both arranged while the capability was live.
        $api->invitations()->send($group, (int) $students[1]->id, $leader);
        $api->invitations()->accept($group, (int) $students[1]->id);
        $api->invitations()->request_leave($group, (int) $students[1]->id);
        $pending = $api->invitations()->send($group, (int) $students[2]->id, $leader);
        $leaver = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
        ], '*', MUST_EXIST);

        $this->prohibit(authority::CREATEGROUP, $activity->context(), 'student');

        $attempts = [
            fn() => $api->invitations()->send($group, (int) $students[2]->id, $leader),
            fn() => $api->invitations()->withdraw($group, (int) $pending->id, $leader),
            fn() => $api->invitations()->confirm_leave($group, (int) $leaver->id, $leader),
        ];
        $refused = 0;
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('A leader verb accepted a prohibited leader');
            } catch (\required_capability_exception $e) {
                $refused++;
            }
        }
        $this->assertSame(3, $refused);

        // Nothing moved.
        $this->assertSame(groups::STATUS_INVITED, $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['id' => $pending->id]
        ));
        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['id' => $leaver->id]
        ));
    }

    /**
     * A-03: an invited user with :respond prohibited called the
     * production invitation service and became CONFIRMED. The gate now
     * sits before the lock, the write, the event and the message - and
     * this asserts all four.
     */
    public function test_invitation_accept_refuses_a_prohibited_invitee(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $invitee = (int) $students[1]->id;

        $group = groups::get($activity, (int) $api->create_group(
            $leader,
            'Inviting',
            'T',
            '<p>b</p>',
            FORMAT_HTML
        )->id);
        $member = $api->invitations()->send($group, $invitee, $leader);

        $this->assertTrue(authority::may_respond($activity, $invitee));
        $this->prohibit(authority::RESPOND, $activity->context(), 'student');
        $this->assertFalse(authority::may_respond($activity, $invitee));

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        try {
            $api->invitations()->accept($group, $invitee);
            $this->fail('accept() confirmed a membership for a prohibited invitee');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::RESPOND), $e->a);
        }

        $this->assertSame(groups::STATUS_INVITED, $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['id' => $member->id]
        ), 'A refused acceptance still confirmed the membership');
        foreach ($eventsink->get_events() as $event) {
            $this->assertNotInstanceOf(\mod_selfselectadvanced\event\invitation_accepted::class, $event);
        }
        $this->assertSame([], $messagesink->get_messages());
        $eventsink->close();
        $messagesink->close();
    }

    /**
     * A-03: declining is a response too. "Always allowed" in the spec is
     * a statement about RULES - no window, seat or cap may block it -
     * not a statement about authority, and the decline path writes a
     * row, fires an event and mails the leader like any other.
     */
    public function test_invitation_decline_refuses_a_prohibited_invitee(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $invitee = (int) $students[1]->id;

        $group = groups::get($activity, (int) $api->create_group(
            $leader,
            'Declining',
            'T',
            '<p>b</p>',
            FORMAT_HTML
        )->id);
        $member = $api->invitations()->send($group, $invitee, $leader);

        $this->prohibit(authority::RESPOND, $activity->context(), 'student');

        try {
            $api->invitations()->decline($group, $invitee);
            $this->fail('decline() answered an invitation for a prohibited invitee');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::RESPOND), $e->a);
        }

        $this->assertSame(groups::STATUS_INVITED, $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['id' => $member->id]
        ));
    }

    /**
     * A-03, the cleanup question answered rather than assumed: an
     * invitation the invitee may no longer answer is not stranded,
     * because the LEADER can still withdraw it and the scheduled task
     * can still expire it. Neither path asks the invitee for anything,
     * which is why the decline gate needs no exception.
     */
    public function test_a_prohibited_invitee_is_not_stranded(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture(['inviteexpiry' => 1]);
        $leader = (int) $students[0]->id;

        $group = groups::get($activity, (int) $api->create_group(
            $leader,
            'Cleanup',
            'T',
            '<p>b</p>',
            FORMAT_HTML
        )->id);
        $withdrawme = $api->invitations()->send($group, (int) $students[1]->id, $leader);
        $expireme = $api->invitations()->send($group, (int) $students[2]->id, $leader);
        $DB->set_field(
            'selfselectadvanced_member',
            'timeinvited',
            time() - (3 * DAYSECS),
            ['id' => $expireme->id]
        );

        $this->prohibit(authority::RESPOND, $activity->context(), 'student');

        $api->invitations()->withdraw($group, (int) $withdrawme->id, $leader);
        $this->assertSame(groups::STATUS_REMOVED, $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['id' => $withdrawme->id]
        ));

        $this->assertSame(1, $api->invitations()->expire_due());
        $this->assertSame(groups::STATUS_EXPIRED, $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['id' => $expireme->id]
        ));
    }

    /**
     * Two firm teams guided by the same guide.
     *
     * @param activity $activity the activity
     * @param \stdClass[] $students the students
     * @param \stdClass $guide the guide
     * @return int[] the two plugin group ids
     */
    private function two_firm_teams(activity $activity, array $students, \stdClass $guide): array {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $ids = [];
        foreach ([['Fir', 0], ['Oak', 1]] as [$name, $index]) {
            $ids[] = (int) $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $students[$index]->id,
                'name' => 'Team ' . $name,
                'guideid' => (int) $guide->id,
                'state' => state::FIRM,
            ])->id;
        }

        return $ids;
    }

    /**
     * A-01: the queued half of a bulk freeze re-establishes the actor's
     * CURRENT authority. The task is executed exactly as cron executes
     * it, carrying exactly the custom data freeze::bulk_freeze() writes.
     */
    public function test_queued_bulk_freeze_refuses_a_revoked_actor(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide] = $this->fixture();
        $ids = $this->two_firm_teams($activity, $students, $guide);

        $this->assertTrue(authority::may_freeze($activity, (int) $guide->id));
        $this->prohibit(authority::FREEZE, $activity->context(), 'teacher');
        $this->assertFalse(authority::may_freeze($activity, (int) $guide->id));

        $task = new bulkfreeze_adhoc();
        $task->set_custom_data([
            'activityid' => $activity->id(),
            'groupids' => $ids,
            'actorid' => (int) $guide->id,
        ]);
        $task->set_userid((int) $guide->id);

        ob_start();
        $task->execute();
        $log = ob_get_clean();

        foreach ($ids as $id) {
            $this->assertSame(
                state::FIRM,
                $DB->get_field('selfselectadvanced_group', 'state', ['id' => $id]),
                'A queued freeze ran on an actor who no longer holds the capability'
            );
            $this->assertStringContainsString('bulk freeze skipped group ' . $id, $log);
            // No snapshot either: freeze_group() writes one in the same
            // transaction as the state flip, so its absence is the
            // second, independent witness that nothing ran.
            $this->assertSame(0, $DB->count_records('selfselectadvanced_snapshot', ['groupid' => $id]));
        }
    }

    /**
     * A-01 control: with the capability intact the very same task
     * freezes both teams, so the test above is measuring the capability
     * and not a broken fixture.
     */
    public function test_queued_bulk_freeze_still_works_with_the_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide] = $this->fixture();
        $ids = $this->two_firm_teams($activity, $students, $guide);

        $task = new bulkfreeze_adhoc();
        $task->set_custom_data([
            'activityid' => $activity->id(),
            'groupids' => $ids,
            'actorid' => (int) $guide->id,
        ]);
        $task->set_userid((int) $guide->id);

        ob_start();
        $task->execute();
        ob_get_clean();

        foreach ($ids as $id) {
            $this->assertSame(
                state::FROZEN,
                $DB->get_field('selfselectadvanced_group', 'state', ['id' => $id])
            );
        }
    }

    /**
     * A-01 at the strongest seam: freeze::freeze_group() itself. The
     * assigned guide's own branch asked for identity and nothing else,
     * so every caller of the service - inline bulk, single freeze,
     * anything future - inherited the hole.
     */
    public function test_freeze_service_refuses_a_prohibited_assigned_guide(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide] = $this->fixture();
        $ids = $this->two_firm_teams($activity, $students, $guide);

        $this->prohibit(authority::FREEZE, $activity->context(), 'teacher');

        try {
            freeze::freeze_group($activity, groups::get($activity, $ids[0]), (int) $guide->id);
            $this->fail('freeze_group() froze a team for a guide whose capability is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::FREEZE), $e->a);
        }
        $this->assertSame(state::FIRM, $DB->get_field('selfselectadvanced_group', 'state', ['id' => $ids[0]]));

        // And the inline half of a bulk freeze inherits the refusal
        // rather than reporting a success it did not perform.
        $bulk = freeze::bulk_freeze($activity, $ids, (int) $guide->id);
        $this->assertSame(0, $bulk->done);
        $this->assertCount(2, $bulk->skipped);
        $this->assertSame(state::FIRM, $DB->get_field('selfselectadvanced_group', 'state', ['id' => $ids[1]]));
    }

    /**
     * A-01: the manager / coordinator on-behalf grant is a DIFFERENT
     * authority (:manage, :coordinate - strategy 1.16 D) and the new
     * check must not have quietly widened it into a :freeze demand. An
     * editing teacher holds no :freeze at all by default.
     */
    public function test_on_behalf_freeze_keeps_its_own_authority(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, $staff] = $this->fixture();
        $ids = $this->two_firm_teams($activity, $students, $guide);

        $this->assertFalse(authority::may_freeze($activity, (int) $staff->id));
        freeze::freeze_group($activity, groups::get($activity, $ids[0]), (int) $staff->id);

        $this->assertSame(state::FROZEN, $DB->get_field('selfselectadvanced_group', 'state', ['id' => $ids[0]]));
    }

    /**
     * A firm, approved team with a guide, ready to be awarded.
     *
     * @param activity $activity the activity
     * @param \stdClass $leader the team's leader
     * @param \stdClass $guide the assigned guide
     * @param string $name the team name
     * @return \stdClass the group row
     */
    private function awardable_team(
        activity $activity,
        \stdClass $leader,
        \stdClass $guide,
        string $name = 'Awardable'
    ): \stdClass {
        global $DB;

        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => $name,
            'guideid' => (int) $guide->id,
            'state' => state::FIRM,
        ]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time() - DAYSECS, ['id' => $group->id]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * A-06: the award seam took no actor at all, so it authorised
     * nobody. Measured with $USER an unrelated student; asserted here
     * on the actor the service is now given.
     */
    public function test_award_refuses_an_unrelated_actor(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide] = $this->fixture();
        $group = $this->awardable_team($activity, $students[0], $guide);

        // The unrelated student is signed in, exactly as measured - and
        // is now irrelevant, because the actor travels with the write.
        $this->setUser($students[2]);

        try {
            ledger::set_award($activity, $group, 91.0, (int) $students[2]->id);
            $this->fail('set_award() wrote an award for an unrelated actor');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotassignedguide', $e->errorcode);
        }

        $this->assertFalse($DB->record_exists_select(
            'selfselectadvanced_penalty',
            'groupid = ? AND award IS NOT NULL',
            [(int) $group->id]
        ));
    }

    /**
     * A-06: activity A was accepted together with a team belonging to
     * activity B, producing a penalty row owned by A for B's group. The
     * re-read is activity-scoped and MUST_EXIST, so the foreign team is
     * now a missing record rather than a cross-activity write.
     */
    public function test_award_refuses_a_team_from_another_activity(): void {
        global $DB;
        $this->resetAfterTest();
        [$activitya, , $studentsa, $guidea, $staffa, $course] = $this->fixture();

        $instanceb = $this->getDataGenerator()->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
        ]);
        $activityb = activity::from_instance((int) $instanceb->id);
        $groupb = $this->awardable_team($activityb, $studentsa[0], $guidea, 'Foreign');

        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:manage',
            $activitya->context(),
            (int) $staffa->id
        ), 'Fixture is wrong: the actor must otherwise be entitled to award');

        try {
            ledger::set_award($activitya, $groupb, 55.0, (int) $staffa->id);
            $this->fail('set_award() accepted a team belonging to another activity');
        } catch (\dml_missing_record_exception $e) {
            $this->assertStringContainsString('selfselectadvanced_group', $e->getMessage());
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_penalty', [
            'activityid' => $activitya->id(),
        ]), 'A penalty row was created under the wrong activity');
        $this->assertSame(0, $DB->count_records('selfselectadvanced_penalty', [
            'groupid' => (int) $groupb->id,
        ]));
    }

    /**
     * A-06: the existing-row path is the common one - correcting a mark
     * already given - and it had neither the authority check nor the
     * group lock that the creating path had.
     */
    public function test_award_correction_is_authorised_too(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, $staff, $course] = $this->fixture();
        $group = $this->awardable_team($activity, $students[0], $guide);

        ledger::set_award($activity, $group, 40.0, (int) $staff->id);
        $this->assertEqualsWithDelta(40.0, (float) $DB->get_field(
            'selfselectadvanced_penalty',
            'award',
            ['groupid' => (int) $group->id]
        ), 0.0001);

        // Another guide - holding :guide, so able to open review.php,
        // and assigned to nothing.
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'teacher');

        try {
            ledger::set_award($activity, $group, 99.0, (int) $other->id);
            $this->fail('set_award() let an unassigned guide rewrite an existing award');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotassignedguide', $e->errorcode);
        }

        $this->assertEqualsWithDelta(40.0, (float) $DB->get_field(
            'selfselectadvanced_penalty',
            'award',
            ['groupid' => (int) $group->id]
        ), 0.0001, 'A refused correction still changed the mark');
    }

    /**
     * A-06: an award belongs to a team that has been approved. The page
     * has always drawn the field for firm and frozen teams only; the
     * service now says so, which is what makes it a rule.
     */
    public function test_award_refuses_a_forming_team(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, $staff] = $this->fixture();
        $forming = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Still forming',
            'guideid' => (int) $guide->id,
        ]);

        try {
            ledger::set_award($activity, groups::get($activity, (int) $forming->id), 10.0, (int) $staff->id);
            $this->fail('set_award() marked a forming team');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalwrongstate', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_penalty', [
            'groupid' => (int) $forming->id,
        ]));
    }

    /**
     * A-06 control: the assigned guide's own award still lands, and the
     * ledger row it needs is still created for it. A gate that refused
     * everybody would pass every test above and be useless.
     */
    public function test_the_assigned_guide_may_still_award(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide] = $this->fixture();
        $group = $this->awardable_team($activity, $students[0], $guide);

        ledger::set_award($activity, $group, 73.5, (int) $guide->id);

        $row = $DB->get_record('selfselectadvanced_penalty', [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(73.5, (float) $row->award, 0.0001);

        ledger::set_award($activity, $group, null, (int) $guide->id);
        $this->assertNull($DB->get_field('selfselectadvanced_penalty', 'award', ['id' => $row->id]));
    }

    /**
     * The award write holds the group lock and drops it before the
     * grade push and the events - the discipline the creating path had
     * and the correcting path did not.
     */
    public function test_award_releases_every_lock_it_takes(): void {
        $this->resetAfterTest();
        [$activity, , $students, $guide] = $this->fixture();
        $group = $this->awardable_team($activity, $students[0], $guide);

        ledger::set_award($activity, $group, 12.0, (int) $guide->id);

        $this->assertSame(0, \mod_selfselectadvanced\local\locks::held_count());
    }
}
