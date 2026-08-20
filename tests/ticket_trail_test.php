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

use backup;
use backup_controller;
use restore_controller;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * A ticket's HISTORY TRAIL (maintainer decision 1, 2026-08-15) and the
 * NEEDS-INFO state (decision 2): every transition writes one
 * selfselectadvanced_ticketlog row, tickets::trail() reads them back
 * either with staff-only actor identity or, for the requester, without
 * it, and a ticket waiting on the requester's answer counts as LIVE
 * everywhere open and claimed already did.
 *
 * RED-FIRST EVIDENCE (captured 2026-08-15, against the tip before this
 * file's own tree changes - tickets.php, db/install.xml unmodified).
 * The liveness test below (test_needsinfo_blocks_a_duplicate_ticket) was
 * proved red as a standalone scaffold run against the deployed m5pg copy
 * of the UNMODIFIED plugin: a ticket claimed and then hand-set to a
 * 'needsinfo' status (STATUS_NEEDSINFO/request_info() do not exist yet
 * on that tree, so the status column was written directly) did NOT
 * block a second compchange ticket for the same group. Captured PHPUnit
 * output:
 *
 *   1) mod_selfselectadvanced\zz_redproof_needsinfo_liveness_test::
 *      test_needsinfo_is_not_yet_treated_as_live_by_the_duplicate_guard
 *   RED-PROOF: expected refusalticketduplicate while the first ticket
 *   sits in a needsinfo status, but file() let a second one through -
 *   the unmodified duplicate guard only checks status IN
 *   ('open','claimed').
 *   FAILURES!
 *   Tests: 1, Assertions: 1, Failures: 1.
 *
 * Green only after file()'s duplicate guard gained STATUS_NEEDSINFO
 * alongside open and claimed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \backup_selfselectadvanced_activity_structure_step
 * @covers     \restore_selfselectadvanced_activity_structure_step
 */
final class ticket_trail_test extends \advanced_testcase {
    /**
     * An activity with a firm group (leader + confirmed member, guide
     * assigned), a manager and a coordinator. Shaped exactly like
     * tickets_test.php::setup_world().
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'TRAIL1']);
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
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_module::instance((int) $instance->cmid));

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Trailed',
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
     * Expect one refusal string key from a callable.
     *
     * @param string $stringkey the expected errorcode
     * @param callable $fn the action
     */
    private function assert_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }

    /**
     * The messages a sink captured, indexed by recipient.
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
     * (1) A full life - file, claim, needsinfo, inforeply, resolve -
     * leaves exactly 5 trail rows in order, with the right actions and
     * actors; trail($withactors=true) carries names, trail(false)
     * carries NO actor field at all.
     */
    public function test_full_life_leaves_five_ordered_trail_rows(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap in a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which subject does the specialist need to cover?',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        tickets::provide_info(
            $activity,
            (int) $ticket->id,
            'Statistics - our current roster has nobody who has done it.',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Added Priya, who has taught statistics before.',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        $staff = tickets::trail($activity, (int) $ticket->id, true);
        $this->assertCount(5, $staff, 'the full life must leave exactly five trail rows');
        $staff = array_values($staff);

        $expectedactions = ['filed', 'claimed', 'needsinfo', 'inforeply', 'resolved'];
        $expectedactors = [
            (int) $guide->id,
            (int) $manager->id,
            (int) $manager->id,
            (int) $guide->id,
            (int) $manager->id,
        ];
        foreach ($staff as $index => $row) {
            $this->assertSame($expectedactions[$index], $row->action, "row $index has the wrong action");
            $this->assertSame($expectedactors[$index], (int) $row->actorid, "row $index has the wrong actor");
            $this->assertObjectHasProperty('actorname', $row, "row $index must carry an actor name for staff");
            $this->assertNotSame('', trim($row->actorname), "row $index's actor name must not be blank");
        }
        // Rows are truly ORDERED, not merely five of the right shape:
        // each timestamp is no earlier than the one before it, and where
        // two ticks land in the same second the ids still climb.
        for ($i = 1; $i < count($staff); $i++) {
            $this->assertGreaterThanOrEqual($staff[$i - 1]->timecreated, $staff[$i]->timecreated);
            if ($staff[$i - 1]->timecreated === $staff[$i]->timecreated) {
                $this->assertGreaterThan($staff[$i - 1]->id, $staff[$i]->id);
            }
        }
        // The two content-bearing rows carry what was actually written.
        $this->assertStringContainsString('specialist need to cover', (string) $staff[2]->note);
        $this->assertStringContainsString('Statistics', (string) $staff[3]->note);
        $this->assertStringContainsString('Priya', (string) $staff[4]->note);
        // Bare transitions carry no note.
        $this->assertNull($staff[0]->note);
        $this->assertNull($staff[1]->note);

        $requester = tickets::trail($activity, (int) $ticket->id, false);
        $this->assertCount(5, $requester, 'the requester-facing trail must hold the same five rows');
        foreach (array_values($requester) as $index => $row) {
            $this->assertSame($expectedactions[$index], $row->action);
            $this->assertObjectNotHasProperty(
                'actorid',
                $row,
                'the requester-facing trail must not carry the actor id at all'
            );
            $this->assertObjectNotHasProperty(
                'actorname',
                $row,
                'the requester-facing trail must not carry the actor name at all'
            );
        }
    }

    /**
     * (1b) AUDIT A5 (2026-08-20): trail()'s STAFF branch used to INNER
     * JOIN {user} on actorid, so a row de-linked to the 0 sentinel by a
     * privacy erasure (classes/privacy/provider.php's
     * scrub_user_in_activity(): "actorid = 0, note = NULL", deliberately
     * keeping the row) vanished from the staff view entirely - the
     * requester's own anonymised trail (no join at all) kept it. The
     * scrub itself is out of scope for this file (provider.php belongs
     * to another fix); the 0 sentinel is reproduced directly here, on
     * the exact column the scrub writes.
     */
    public function test_trail_survives_an_actor_delinked_to_zero(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which subject does the specialist need to cover?',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        // Reproduce the scrub's own de-link, exactly as provider.php
        // writes it, on the 'claimed' row alone.
        $DB->execute(
            "UPDATE {selfselectadvanced_ticketlog} SET actorid = 0, note = NULL
              WHERE ticketid = :ticketid AND action = :action",
            ['ticketid' => (int) $ticket->id, 'action' => tickets::ACTION_CLAIMED]
        );

        $staff = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $this->assertCount(
            3,
            $staff,
            'the de-linked row must still be RETURNED by the staff trail, not dropped by the join'
        );
        $delinked = $staff[1];
        $this->assertSame(tickets::ACTION_CLAIMED, $delinked->action, 'fixture: row 1 must be the de-linked claim');
        $this->assertSame(0, (int) $delinked->actorid);
        $this->assertNotSame('', trim((string) $delinked->actorname), 'a de-linked row must still carry a placeholder name');
        $this->assertStringNotContainsString(
            fullname($manager),
            $delinked->actorname,
            'a de-linked row must never resolve back to the real actor\'s name'
        );
    }

    /**
     * (2) request_info(): refused for a non-claimant staff member,
     * refused from an open ticket, refused with an empty question; on
     * success the requester is notified exactly once, and the subject
     * renders without a literal {$a.
     */
    public function test_request_info_refusals_and_notification(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        [$activity, $group, , , $guide, $manager, $coordinator] = $this->setup_world();

        $open = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->assert_refused('refusalticketnotclaimed', fn() => tickets::request_info(
            $activity,
            (int) $open->id,
            'Anything at all?',
            FORMAT_PLAIN,
            (int) $manager->id
        ));

        tickets::claim($activity, (int) $open->id, (int) $manager->id);

        // A staff member who is entitled to WORK the queue in general,
        // but is not THIS ticket's claimant.
        $this->assert_refused('refusalticketnotclaimant', fn() => tickets::request_info(
            $activity,
            (int) $open->id,
            'Coordinator butting in',
            FORMAT_PLAIN,
            (int) $coordinator->id
        ));

        $this->assert_refused('refusalticketreason', fn() => tickets::request_info(
            $activity,
            (int) $open->id,
            '   ',
            FORMAT_PLAIN,
            (int) $manager->id
        ));

        $sink->clear();
        $updated = tickets::request_info(
            $activity,
            (int) $open->id,
            'Which subject does the specialist need to cover?',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->assertSame(tickets::STATUS_NEEDSINFO, $updated->status);

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'exactly one message must be sent when a question is asked');
        $this->assertSame((int) $guide->id, (int) $messages[0]->useridto, 'the requester must be the recipient');
        $this->assertStringNotContainsString('{$a', $messages[0]->subject, 'the subject shipped an unresolved placeholder');
        $this->assertStringNotContainsString('{$a', $messages[0]->fullmessage, 'the body shipped an unresolved placeholder');
        $this->assertStringContainsString('specialist need to cover', $messages[0]->fullmessage);
    }

    /**
     * (2b) AUDIT A1 (2026-08-20): request_info() had no authority gate
     * of its own. A REQUESTER (no queue authority at all) opening their
     * own thread and POSTing action=requestinfo used to reach the
     * not-claimant check inside the lock and be handed the claimant's
     * fullname by workflow_refusal - ticket.php catches that exception
     * type and redirects with $e->getMessage() as the notice text, so
     * the requester would read the claimant's real name. RED-FIRST
     * PROOF (see the report): before the fix this throws
     * workflow_refusal('refusalticketnotclaimant', ..., fullname($manager))
     * and the second catch arm below explicitly fails the test on that
     * leak; after the fix it throws core's required_capability_exception
     * first, before the lock is ever taken, carrying no name at all.
     */
    public function test_request_info_by_the_requester_never_names_the_claimant(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);

        try {
            // The GUIDE is this ticket's own requester - no queue
            // authority (:manage/:coordinate) at all, only 'teacher'.
            tickets::request_info(
                $activity,
                (int) $ticket->id,
                'Why is this taking so long?',
                FORMAT_PLAIN,
                (int) $guide->id
            );
            $this->fail('a requester with no queue authority must be refused before request_info() ever runs');
        } catch (\required_capability_exception $e) {
            $this->assertStringNotContainsString(
                fullname($manager),
                $e->getMessage(),
                'a requester must never learn the claimant\'s name from this refusal'
            );
        } catch (local\workflow_refusal $e) {
            $this->fail(
                'expected core\'s required_capability_exception (no name); got a workflow_refusal ('
                . $e->errorcode . ') instead - it reached the not-claimant check, which names the claimant'
            );
        }
    }

    /**
     * (2c) AUDIT A1's identical leak through comment() (2026-08-20):
     * latent (only reachable via api_respond, which checks authority
     * itself) rather than live, but the same missing door. Same RED-
     * FIRST shape as the request_info() proof above.
     */
    public function test_comment_by_a_non_staff_actor_never_names_the_claimant(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);

        try {
            tickets::comment($activity, (int) $ticket->id, 'Trying to post into my own ticket', FORMAT_PLAIN, (int) $guide->id);
            $this->fail('an actor with no queue authority must be refused before comment() ever runs');
        } catch (\required_capability_exception $e) {
            $this->assertStringNotContainsString(fullname($manager), $e->getMessage());
        } catch (local\workflow_refusal $e) {
            $this->fail(
                'expected core\'s required_capability_exception (no name); got a workflow_refusal ('
                . $e->errorcode . ') instead'
            );
        }
    }

    /**
     * (3) provide_info(): refused for anyone but the requester, refused
     * when the ticket is not waiting on one; on success status returns
     * to claimed with the ORIGINAL claimant intact, and the claimant is
     * notified.
     */
    public function test_provide_info_refusals_and_resumption(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);

        // Not yet needs-info: still just claimed.
        $this->assert_refused('refusalticketnotneedsinfo', fn() => tickets::provide_info(
            $activity,
            (int) $ticket->id,
            'An answer nobody asked for',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which subject does the specialist need to cover?',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        // The claimant is not the requester, and may not answer their
        // own question in the requester's place.
        $this->assert_refused('refusalticketnotyours', fn() => tickets::provide_info(
            $activity,
            (int) $ticket->id,
            'Statistics',
            FORMAT_PLAIN,
            (int) $manager->id
        ));

        $this->assert_refused('refusalticketreason', fn() => tickets::provide_info(
            $activity,
            (int) $ticket->id,
            '',
            FORMAT_PLAIN,
            (int) $guide->id
        ));

        $sink->clear();
        $resumed = tickets::provide_info(
            $activity,
            (int) $ticket->id,
            'Statistics - our current roster has nobody who has done it.',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->assertSame(tickets::STATUS_CLAIMED, $resumed->status, 'a reply resumes the ticket as claimed');
        $this->assertSame(
            (int) $manager->id,
            (int) $resumed->claimedby,
            'the ORIGINAL claimant must still hold the ticket - a reply is not a reopening'
        );

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages, 'exactly one message must be sent when a reply is given');
        $this->assertSame((int) $manager->id, (int) $messages[0]->useridto, 'the claimant must be the recipient');
        $this->assertStringNotContainsString('{$a', $messages[0]->subject);
        $this->assertStringContainsString('Statistics', $messages[0]->fullmessage);
    }

    /**
     * (4) LIVENESS: while a ticket sits in needsinfo, filing a second
     * ticket of the same type for the same group is refused as a
     * duplicate. See this file's docblock for the RED evidence captured
     * against the unmodified tree.
     */
    public function test_needsinfo_blocks_a_duplicate_ticket(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $first = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $first->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $first->id,
            'Which subject does the specialist need to cover?',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        try {
            tickets::file(
                $activity,
                $group,
                tickets::TYPE_COMPCHANGE,
                'A second, contradictory request',
                FORMAT_PLAIN,
                (int) $guide->id
            );
            $this->fail('a second compchange ticket must be refused while the first sits in needsinfo');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalticketduplicate', $e->errorcode);
        }
    }

    /**
     * (5) close() works from needsinfo: the claimant may resolve a
     * ticket without the answer they asked for, and the trail logs
     * 'resolved' exactly as it would from claimed.
     */
    public function test_close_works_from_needsinfo(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, , , $guide, $manager] = $this->setup_world();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which subject does the specialist need to cover?',
            FORMAT_PLAIN,
            (int) $manager->id
        );

        $closed = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Decided without waiting - added Priya.',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $this->assertSame(tickets::STATUS_RESOLVED, $closed->status);

        $trail = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $this->assertCount(4, $trail, 'filed, claimed, needsinfo, then resolved without a reply');
        $this->assertSame('resolved', end($trail)->action);
        $this->assertSame((int) $manager->id, (int) end($trail)->actorid);
        $this->assertStringContainsString('Decided without waiting', (string) end($trail)->note);
    }

    /**
     * (6) Backup and restore: the trail survives with ticketid and
     * actorid remapped onto the restored ticket and users. Modelled on
     * backup_restore_files_test.php's roundtrip() helper - no existing
     * test in this suite exercises a ticket through a backup/restore
     * round trip at all, so this is new rather than an extension.
     */
    public function test_trail_survives_backup_and_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = get_admin();
        $this->redirectMessages();

        [$activity, $group, , , $guide, $manager] = $this->setup_world();
        $cm = $activity->cm();

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need one more pair of hands',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $ticket->id,
            'Which subject does the specialist need to cover?',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        tickets::provide_info(
            $activity,
            (int) $ticket->id,
            'Statistics.',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $originaltrail = array_values(tickets::trail($activity, (int) $ticket->id, true));
        $this->assertCount(4, $originaltrail, 'fixture must actually hold four trail rows before the round trip');

        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cm->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int) $admin->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $results = $bc->get_results();
        $this->assertArrayHasKey('backup_destination', $results, 'the backup produced no archive');
        $file = $results['backup_destination'];
        $dir = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $dir);
        $bc->destroy();

        $target = $this->getDataGenerator()->create_course();
        $rc = new restore_controller(
            $backupid,
            $target->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int) $admin->id,
            backup::TARGET_CURRENT_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $restoredinstances = $DB->get_records('selfselectadvanced', ['course' => $target->id], 'id DESC');
        $this->assertNotEmpty($restoredinstances, 'the activity did not restore at all');
        $restoredinstance = reset($restoredinstances);
        $restoredactivity = activity::from_instance((int) $restoredinstance->id);

        $restoredticket = $DB->get_record(
            'selfselectadvanced_ticket',
            ['activityid' => $restoredactivity->id(), 'type' => tickets::TYPE_COMPCHANGE],
            '*',
            MUST_EXIST
        );
        $this->assertNotSame((int) $ticket->id, (int) $restoredticket->id, 'the restore must mint a new ticket id');

        $restoredtrail = array_values(tickets::trail($restoredactivity, (int) $restoredticket->id, true));
        $this->assertCount(4, $restoredtrail, 'the whole trail must survive the round trip');

        $originalactions = array_map(static fn($r) => $r->action, $originaltrail);
        $restoredactions = array_map(static fn($r) => $r->action, $restoredtrail);
        $this->assertSame($originalactions, $restoredactions, 'action order must be preserved');

        // Every row must point at the RESTORED ticket, never the
        // original one - proof ticketid was actually remapped rather
        // than copied verbatim.
        foreach ($restoredtrail as $row) {
            $this->assertNotSame(
                (int) $ticket->id,
                (int) $DB->get_field('selfselectadvanced_ticketlog', 'ticketid', ['id' => $row->id]),
                'a trail row still names the SOURCE ticket id after restore'
            );
        }

        // Actor identity survives too: the restored trail's actor names
        // still resolve to real (newly restored) users, not to id 0 or
        // to nobody.
        foreach ($restoredtrail as $row) {
            $this->assertObjectHasProperty('actorid', $row);
            $this->assertGreaterThan(0, (int) $row->actorid, 'actorid must not degrade to 0 on a clean restore');
            $this->assertNotSame('', trim((string) $row->actorname));
        }
    }
}
