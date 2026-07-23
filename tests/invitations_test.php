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
 * The invitation engine: reserved seats (L2), the invitee cap (L4),
 * the acceptance cascade, expiry, and the S2 state guards
 * (spec sections 4A.2, 4A.4, 6.2).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 * @covers     \mod_selfselectadvanced\local\candidates
 * @covers     \mod_selfselectadvanced\task\expire_invitations
 */
final class invitations_test extends \advanced_testcase {
    /**
     * Create a course, an instance and n students.
     *
     * @param array $settings instance setting overrides
     * @param int $students number of enrolled students
     * @return array [activity, api, students[]]
     */
    private function setup_activity(array $settings = [], int $students = 4): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'CHEM1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
        ], $settings));

        $users = [];
        for ($i = 0; $i < $students; $i++) {
            $user = $generator->create_user([
                'firstname' => 'Stu' . $i,
                'lastname' => 'Dent' . $i,
                'email' => 'stu' . $i . '@example.com',
            ]);
            $generator->enrol_user($user->id, $course->id, 'student');
            $users[] = $user;
        }

        $activity = activity::from_instance((int) $instance->id);

        return [$activity, new api($activity), $users];
    }

    /**
     * The plugin generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * Sending reserves a seat; a full group (confirmed plus pending =
     * max) refuses further invitations; withdrawing frees the seat.
     * L2 boundary: one below, exactly at, one above.
     */
    public function test_reserved_seats_boundary(): void {
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity([
            'maxsize' => 2,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $leader = (int) $users[0]->id;
        $group = $api->create_group($leader, 'Seats', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);

        // One below the cap: invite allowed and reserves the seat.
        $sink = $this->redirectEvents();
        $api->invitations()->send($group, (int) $users[1]->id, $leader);
        $sent = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_selfselectadvanced\event\invitation_sent);
        $sink->close();
        $this->assertCount(1, $sent);
        $this->assertSame(2, groups::count_seats_taken((int) $group->id));

        // Exactly at the cap: no free seat, refusal names the reason.
        $refusal = $api->gatekeeper()->can_invite($group, (int) $users[2]->id);
        $this->assertSame('refusalnoseats', $refusal?->stringkey);
        try {
            $api->invitations()->send($group, (int) $users[2]->id, $leader);
            $this->fail('Expected refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('No free seats', $e->getMessage());
        }

        // Withdrawing releases the seat and the invite becomes possible.
        global $DB;
        $memberid = (int) $DB->get_field('selfselectadvanced_member', 'id', [
            'groupid' => $group->id,
            'userid' => $users[1]->id,
        ]);
        $api->invitations()->withdraw($group, $memberid, $leader);
        $this->assertSame(1, groups::count_seats_taken((int) $group->id));
        $this->assertNull($api->gatekeeper()->can_invite($group, (int) $users[2]->id));
    }

    /**
     * The 6.2 blocked conditions: already invited, already confirmed,
     * invitee at their cap (L4 boundary), and the S2 state guard.
     */
    public function test_invite_blocked_conditions(): void {
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity([
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $leader = (int) $users[0]->id;
        $group = $api->create_group($leader, 'Rules', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);

        // Blocked condition (b): a pending invitation already exists.
        $api->invitations()->send($group, (int) $users[1]->id, $leader);
        $this->assertSame('refusalalreadyinvited', $api->gatekeeper()->can_invite($group, (int) $users[1]->id)?->stringkey);

        // Blocked condition (c): already a confirmed member.
        $this->assertSame('refusalalreadymember', $api->gatekeeper()->can_invite($group, $leader)?->stringkey);

        // Blocked condition (a): invitee at the effective cap; with n = 1 this is
        // exactly "a confirmed student of another group cannot be invited" (D2).
        $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $users[2]->id,
            'name' => 'Other',
        ]);
        $this->assertSame('refusalinviteecap', $api->gatekeeper()->can_invite($group, (int) $users[2]->id)?->stringkey);

        // S2: a submitted group refuses invitations from a stale POST.
        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $group->id]);
        $fresh = groups::get($activity, (int) $group->id);
        $this->assertSame('refusalwrongstate', $api->gatekeeper()->can_invite($fresh, (int) $users[3]->id)?->stringkey);
    }

    /**
     * Acceptance confirms the member atomically and notifies the leader;
     * the seat re-check refuses when the group is already over-full;
     * the state guard refuses after submission.
     */
    public function test_accept(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity([
            'maxsize' => 2,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $leader = (int) $users[0]->id;
        $invitee = (int) $users[1]->id;
        $group = $api->create_group($leader, 'Accepting', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);
        $api->invitations()->send($group, $invitee, $leader);

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        $api->invitations()->accept($group, $invitee);
        $accepted = array_filter(
            $eventsink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\invitation_accepted
        );
        $eventsink->close();
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assertCount(1, $accepted);
        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field('selfselectadvanced_member', 'status', ['groupid' => $group->id, 'userid' => $invitee])
        );
        // The leader got the acceptance notification.
        $this->assertNotEmpty(array_filter(
            $messages,
            fn($m) => (int) $m->useridto === $leader && $m->eventtype === 'invitationresult'
        ));

        // Over-full re-check: force an extra confirmed member, then accept
        // a further invitation - the loser of the race is refused.
        $late = (int) $users[2]->id;
        $DB->execute("UPDATE {selfselectadvanced_group} SET state = ? WHERE id = ?", [state::FORMING, $group->id]);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => $late,
            'status' => groups::STATUS_INVITED,
        ]);
        $this->plugingen()->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users[3]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        try {
            $api->invitations()->accept($group, $late);
            $this->fail('Expected refusal: group already over its maximum');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('No free seats', $e->getMessage());
        }

        // S2: acceptance refused once the group left forming.
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $group->id]);
        try {
            $api->invitations()->accept(groups::get($activity, (int) $group->id), $late);
            $this->fail('Expected refusal: wrong state');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('current state', $e->getMessage());
        }
    }

    /**
     * The acceptance cascade (4A.4): reaching the cap auto-declines
     * every other pending invitation in the same transaction, records
     * the reason and notifies the affected leaders.
     */
    public function test_acceptance_cascade(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity([
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 1,
        ], 5);
        $student = (int) $users[0]->id;
        $leaderb = (int) $users[1]->id;
        $leaderg = (int) $users[2]->id;

        $blue = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderb,
            'name' => 'Blue',
        ]);
        $green = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderg,
            'name' => 'Green',
        ]);
        $bluerow = groups::get($activity, (int) $blue->id);
        $greenrow = groups::get($activity, (int) $green->id);
        $api->invitations()->send($bluerow, $student, $leaderb);
        $api->invitations()->send($greenrow, $student, $leaderg);

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        $api->invitations()->accept($bluerow, $student);
        $declined = array_values(array_filter(
            $eventsink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\invitation_declined
        ));
        $eventsink->close();
        $messages = $messagesink->get_messages();
        $messagesink->close();

        // The Green invitation auto-declined with the cascade reason.
        $this->assertCount(1, $declined);
        $this->assertSame('membershipcap', $declined[0]->get_data()['other']['reason']);
        $this->assertSame(
            groups::STATUS_DECLINED,
            $DB->get_field('selfselectadvanced_member', 'status', ['groupid' => $green->id, 'userid' => $student])
        );
        // Green's seat was released.
        $this->assertSame(1, groups::count_seats_taken((int) $green->id));
        // Green's leader was told why.
        $greenmsgs = array_filter($messages, fn($m) => (int) $m->useridto === $leaderg);
        $this->assertNotEmpty($greenmsgs);
        $this->assertStringContainsString('automatically declined', reset($greenmsgs)->fullmessage);
    }

    /**
     * Declining is always allowed - even after the cutoff - and frees
     * the reserved seat (spec 6.2). Re-inviting after a decline reuses
     * the row (decision A2).
     */
    public function test_decline_and_reinvite(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        [$activity, $api, $users] = $this->setup_activity([
            'maxsize' => 3,
            'maxlead' => 1,
            'maxmembership' => 2,
            'timecutoff' => $now + 100,
        ]);
        $leader = (int) $users[0]->id;
        $invitee = (int) $users[1]->id;
        $group = $api->create_group($leader, 'Declining', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);
        $api->invitations()->send($group, $invitee, $leader);

        // Cutoff passes; acceptance would be refused, declining is not.
        $DB->set_field('selfselectadvanced', 'timecutoff', $now - 50, ['id' => $activity->id()]);
        $activity2 = activity::from_instance($activity->id());
        $api2 = new api($activity2);
        $fresh = groups::get($activity2, (int) $group->id);

        $this->assertSame(
            'refusalcutoffpassed',
            $api2->gatekeeper()->can_accept(
                $fresh,
                $DB->get_record('selfselectadvanced_member', ['groupid' => $group->id, 'userid' => $invitee])
            )?->stringkey
        );
        $api2->invitations()->decline($fresh, $invitee);
        $this->assertSame(1, groups::count_seats_taken((int) $group->id));

        // Re-invite reuses the same row (unique groupid+userid).
        $DB->set_field('selfselectadvanced', 'timecutoff', $now + 100, ['id' => $activity->id()]);
        $activity3 = activity::from_instance($activity->id());
        (new api($activity3))->invitations()->send(groups::get($activity3, (int) $group->id), $invitee, $leader);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $invitee,
        ]));
        $this->assertSame(
            groups::STATUS_INVITED,
            $DB->get_field('selfselectadvanced_member', 'status', ['groupid' => $group->id, 'userid' => $invitee])
        );
    }

    /**
     * Expiry: invitations older than the activity's expiry window
     * auto-decline via the scheduled task, release their seats and fire
     * events; younger invitations are untouched.
     */
    public function test_expiry(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity([
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
            'inviteexpiry' => 1,
        ]);
        $leader = (int) $users[0]->id;
        $group = $api->create_group($leader, 'Expiring', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);
        $api->invitations()->send($group, (int) $users[1]->id, $leader);
        $api->invitations()->send($group, (int) $users[2]->id, $leader);

        // Age the first invitation past the expiry window.
        $DB->set_field('selfselectadvanced_member', 'timeinvited', time() - (2 * DAYSECS), [
            'groupid' => $group->id,
            'userid' => $users[1]->id,
        ]);

        $eventsink = $this->redirectEvents();
        $messagesink = $this->redirectMessages();
        $task = new \mod_selfselectadvanced\task\expire_invitations();
        $this->expectOutputRegex('/expired 1 invitation/');
        $task->execute();
        $expired = array_filter(
            $eventsink->get_events(),
            fn($e) => $e instanceof \mod_selfselectadvanced\event\invitation_expired
        );
        $eventsink->close();
        $messagesink->close();

        $this->assertCount(1, $expired);
        $this->assertSame(groups::STATUS_EXPIRED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $group->id,
            'userid' => $users[1]->id,
        ]));
        $this->assertSame(groups::STATUS_INVITED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => $group->id,
            'userid' => $users[2]->id,
        ]));
        $this->assertSame(2, groups::count_seats_taken((int) $group->id));
    }

    /**
     * The candidate search (U3): matches by first name, last name and
     * email; ineligible candidates carry their reason.
     */
    public function test_candidate_search(): void {
        $this->resetAfterTest();

        [$activity, $api, $users] = $this->setup_activity([
            'maxsize' => 3,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $leader = (int) $users[0]->id;
        $group = $api->create_group($leader, 'Searchers', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);

        // By last name.
        $results = \mod_selfselectadvanced\local\candidates::search(
            $activity,
            $group,
            $api->gatekeeper(),
            'Dent1',
            $leader
        );
        $this->assertCount(1, $results);
        $this->assertSame((int) $users[1]->id, $results[0]['id']);
        $this->assertTrue($results[0]['eligible']);

        // By email.
        $results = \mod_selfselectadvanced\local\candidates::search(
            $activity,
            $group,
            $api->gatekeeper(),
            'stu2@example.com',
            $leader
        );
        $this->assertCount(1, $results);
        $this->assertSame((int) $users[2]->id, $results[0]['id']);

        // By first name matches several; the leader (already a member)
        // is returned as ineligible with the reason.
        $results = \mod_selfselectadvanced\local\candidates::search(
            $activity,
            $group,
            $api->gatekeeper(),
            'Stu',
            $leader
        );
        $this->assertCount(4, $results);
        $byid = array_column($results, null, 'id');
        $this->assertFalse($byid[$leader]['eligible']);
        $this->assertNotSame('', $byid[$leader]['reason']);

        // A confirmed member of another group is ineligible at n = 1 (D2).
        $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $users[3]->id,
            'name' => 'Elsewhere',
        ]);
        $results = \mod_selfselectadvanced\local\candidates::search(
            $activity,
            $group,
            $api->gatekeeper(),
            'Dent3',
            $leader
        );
        $this->assertFalse($results[0]['eligible']);
    }
}
