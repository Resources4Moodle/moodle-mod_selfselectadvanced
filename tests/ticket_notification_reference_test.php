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

use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * 1.20.56 deliverable B: richer notification emails.
 *
 * Every msgticket* message gains the ticket's own reference in its
 * SUBJECT and the actual words somebody wrote - the question, the
 * reply, the resolution, the note, the original request - in its BODY,
 * so the recipient can act without logging in.
 *
 * THE CONTACT-PRIVACY RULE GOVERNS THE EMAIL EXACTLY AS IT GOVERNS THE
 * SCREEN: a requester's notification must never name a staff member. No
 * production code touched by this release derives an actor's name into
 * any notification payload at all - the enrichment here is the
 * reference plus text a human already wrote, never an identity - so the
 * guarantee holds by construction; test_no_notification_to_the_requester_names_the_staff_member()
 * below is the regression lock on that fact, run against a fixture
 * where a DISTINCTIVELY named staff member performs every staff action
 * in one ticket's whole lifecycle.
 *
 * RED-FIRST: see each test method's own docblock for the exact mutation
 * this file was proved against and the quoted PHPUnit output of both
 * runs (in the release report, not repeated here).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \mod_selfselectadvanced\local\notifier
 */
final class ticket_notification_reference_test extends \advanced_testcase {
    /** @var string The claimant's distinctive first name - never a real word this suite uses elsewhere. */
    private const STAFF_FIRSTNAME = 'Zbigniew';

    /** @var string The claimant's distinctive last name. */
    private const STAFF_LASTNAME = 'Quandaryheim';

    /**
     * A clean held-lock set per test.
     */
    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
    }

    /**
     * Release anything a failed test left behind.
     */
    protected function tearDown(): void {
        locks::reset_state();
        parent::tearDown();
    }

    /**
     * An activity with a firm group (leader, confirmed member, guide), a
     * distinctively-named claimant/manager and a second coordinator to
     * refer to - shaped like ticket_ladder_test.php::setup_world(), with
     * the manager's name pinned rather than generator-random so a leaked
     * identity is unambiguous to detect.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'NOTIFREF']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($member->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $manager = $generator->create_user([
            'firstname' => self::STAFF_FIRSTNAME,
            'lastname' => self::STAFF_LASTNAME,
        ]);
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_module::instance((int) $instance->cmid));

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Referenced',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $member, $guide, $manager, $coordinator];
    }

    /**
     * The messages a sink captured, indexed by recipient. Shaped like
     * ticket_richtext_test.php::by_recipient().
     *
     * @param \phpunit_message_sink $sink the sink
     * @return array<int, array<int, \stdClass>> userid => messages
     */
    private function by_recipient(\phpunit_message_sink $sink): array {
        $byuser = [];
        foreach ($sink->get_messages() as $message) {
            $byuser[(int) $message->useridto][] = $message;
        }

        return $byuser;
    }

    /**
     * Drive one ticket through every msgticket* action - filed, claimed,
     * needsinfo, inforeply, commented, referred, escalated, claimed
     * again, resolved - and hand back the sink, the ticket id and the
     * people involved, so both test methods below drive the IDENTICAL
     * lifecycle without duplicating it.
     *
     * @return array [sink, ticketid, memberid, managerid, coordinatorid, texts]
     *         texts is [request, question, reply, note (comment), refernote,
     *         escalatenote, resolution] - the exact strings written at
     *         each step, for the positive test to look for verbatim.
     */
    private function run_full_lifecycle(): array {
        $this->resetAfterTest();
        [$activity, $group, , $member, , $manager, $coordinator] = $this->setup_world();

        $sink = $this->redirectMessages();

        $texts = [
            'request' => 'The leader has been unreachable for two weeks.',
            'question' => 'Have you tried messaging them directly?',
            'reply' => 'Yes, no response since the 3rd.',
            'note' => 'Following up with the leader now.',
            'refernote' => 'Handing this to another coordinator to widen coverage.',
            'escalatenote' => 'Needs an editing teacher\'s call.',
            'resolution' => 'Leader stepped back; a successor was installed.',
        ];

        $filed = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            $texts['request'],
            FORMAT_PLAIN,
            (int) $member->id
        );
        $ticketid = (int) $filed->id;

        tickets::claim($activity, $ticketid, (int) $manager->id);
        tickets::request_info($activity, $ticketid, $texts['question'], FORMAT_PLAIN, (int) $manager->id);
        tickets::provide_info($activity, $ticketid, $texts['reply'], FORMAT_PLAIN, (int) $member->id);
        tickets::comment($activity, $ticketid, $texts['note'], FORMAT_PLAIN, (int) $manager->id);
        tickets::refer($activity, $ticketid, (int) $coordinator->id, $texts['refernote'], FORMAT_PLAIN, (int) $manager->id);
        // The coordinator holds no :manage capability, so escalating
        // releases their claim back to open (D-105/1.20.44) - re-claimed
        // by the manager below before it can be closed.
        tickets::escalate($activity, $ticketid, $texts['escalatenote'], FORMAT_PLAIN, (int) $coordinator->id);
        tickets::claim($activity, $ticketid, (int) $manager->id);
        tickets::close(
            $activity,
            $ticketid,
            tickets::STATUS_RESOLVED,
            $texts['resolution'],
            FORMAT_PLAIN,
            (int) $manager->id
        );

        $reference = tickets::get($activity, $ticketid)->pluginuid;

        return [$sink, $ticketid, (int) $member->id, (int) $manager->id, (int) $coordinator->id, $texts, $reference];
    }

    /**
     * POSITIVE: every msgticket* message actually sent across the full
     * lifecycle carries the ticket's reference in its subject, and the
     * text a human actually wrote in its body.
     *
     * RED-FIRST (run 2026-08-20, PHPUnit on m5pg against this same tree,
     * with the 'pluginuid' key temporarily removed from the payload
     * object classes/local/tickets.php's notify() builds - the shared
     * helper behind filed/claimed/closed):
     *
     *   msgticketclaimedsubject must carry the reference
     *   Failed asserting that 'Your request ({$a->pluginuid}) for
     *   "Referenced" is being handled' [ASCII](length: 64) contains
     *   "SSA-NOTIFREF-T..." [ASCII](length: 20).
     *
     * (get_string() left the placeholder literal rather than warning -
     * PHPUnit stops a method at its first failed assertion, so only the
     * claimed-subject check ran before the fixture-wide notify() defect
     * was already visible.) Reverting the mutation restored a full pass
     * (2 tests, 38 assertions) with no other change.
     */
    public function test_every_msgticket_notification_carries_the_reference_and_written_text(): void {
        [$sink, , $memberid, $managerid, $coordinatorid, $texts, $reference] = $this->run_full_lifecycle();
        $this->assertNotEmpty($reference, 'the ticket must have been minted a reference to check for');

        $bymember = $this->by_recipient($sink)[$memberid] ?? [];
        $bymanager = $this->by_recipient($sink)[$managerid] ?? [];
        $bycoordinator = $this->by_recipient($sink)[$coordinatorid] ?? [];

        // Requester-facing: claimed, needsinfo, commented, closed.
        $find = static function (array $messages, string $needle): ?\stdClass {
            foreach ($messages as $message) {
                if (str_contains((string) $message->fullmessage, $needle)) {
                    return $message;
                }
            }
            return null;
        };

        $claimed = $find($bymember, 'has been taken up');
        $this->assertNotNull($claimed, 'the requester must be told the ticket was claimed');
        $this->assertStringContainsString($reference, $claimed->subject, 'msgticketclaimedsubject must carry the reference');

        $needsinfo = $find($bymember, $texts['question']);
        $this->assertNotNull($needsinfo, 'the requester must receive the actual question text');
        $this->assertStringContainsString($reference, $needsinfo->subject, 'msgticketneedsinfosubject must carry the reference');

        $commented = $find($bymember, $texts['note']);
        $this->assertNotNull($commented, 'the requester must receive the actual comment text');
        $this->assertStringContainsString($reference, $commented->subject, 'msgticketcommentedsubject must carry the reference');

        $closed = $find($bymember, $texts['resolution']);
        $this->assertNotNull($closed, 'the requester must receive the actual resolution text');
        $this->assertStringContainsString($reference, $closed->subject, 'msgticketclosedsubject must carry the reference');

        // Staff-facing: inforeply (to the claimant), referred and
        // escalated (to the coordinator/manager they were sent to).
        $inforeply = $find($bymanager, $texts['reply']);
        $this->assertNotNull($inforeply, 'the claimant must receive the requester\'s actual reply text');
        $this->assertStringContainsString($reference, $inforeply->subject, 'msgticketinforeplysubject must carry the reference');

        $referred = $find($bycoordinator, $texts['refernote']);
        $this->assertNotNull($referred, 'the referral target must receive the actual referral note');
        $this->assertStringContainsString($reference, $referred->subject, 'msgticketreferredsubject must carry the reference');

        $escalated = $find($bymanager, $texts['escalatenote']);
        $this->assertNotNull($escalated, 'the manage-level tier must receive the actual escalation note');
        $this->assertStringContainsString($reference, $escalated->subject, 'msgticketescalatedsubject must carry the reference');

        // Filed: the workers (the manager, here) get the ORIGINAL
        // request text now too - previously carried no written text at
        // all.
        $filed = $find($bymanager, $texts['request']);
        $this->assertNotNull($filed, 'the filed notification to workers must carry the original request text');
        $this->assertStringContainsString($reference, $filed->subject, 'msgticketfiledsubject must carry the reference');
    }

    /**
     * NEGATIVE CONTROL, kept in its own method and its own lifecycle run
     * (PostgreSQL transaction poisoning): the SAME distinctively-named
     * staff member (self::STAFF_FIRSTNAME/STAFF_LASTNAME) claims, asks,
     * comments and resolves this ticket - every staff action there is -
     * and not one notification the REQUESTER receives may name them,
     * anywhere in its subject or body.
     *
     * RED-FIRST (run 2026-08-20, PHPUnit on m5pg against this same tree,
     * with tickets::notify()'s 'group' value mutated to append the
     * claimant's real fullname() - `$subject . ($ticket->claimedby ? ' '
     * . fullname(\core_user::get_user((int) $ticket->claimedby)) : '')` -
     * onto the SAME 'group' placeholder msgticketclaimedbody already
     * renders, the plausible-looking mistake this test exists to catch):
     *
     *   a staff member's first name must never reach a requester's
     *   notification subject
     *   Failed asserting that 'Your request (SSA-NOTIFREF-T...) for
     *   "Referenced Zbigniew Quandaryheim" is being handled' ... does not
     *   contain "zbigniew".
     *
     * Reverting the mutation restored a full pass (2 tests, 38
     * assertions) with no other change.
     */
    public function test_no_notification_to_the_requester_names_the_staff_member(): void {
        [$sink, , $memberid] = $this->run_full_lifecycle();

        $bymember = $this->by_recipient($sink)[$memberid] ?? [];
        $this->assertNotEmpty($bymember, 'the requester must have received at least one notification to check');

        foreach ($bymember as $message) {
            $this->assertStringNotContainsStringIgnoringCase(
                self::STAFF_FIRSTNAME,
                (string) $message->subject,
                'a staff member\'s first name must never reach a requester\'s notification subject'
            );
            $this->assertStringNotContainsStringIgnoringCase(
                self::STAFF_LASTNAME,
                (string) $message->subject,
                'a staff member\'s last name must never reach a requester\'s notification subject'
            );
            $this->assertStringNotContainsStringIgnoringCase(
                self::STAFF_FIRSTNAME,
                (string) $message->fullmessage,
                'a staff member\'s first name must never reach a requester\'s notification body'
            );
            $this->assertStringNotContainsStringIgnoringCase(
                self::STAFF_LASTNAME,
                (string) $message->fullmessage,
                'a staff member\'s last name must never reach a requester\'s notification body'
            );
        }
    }
}
