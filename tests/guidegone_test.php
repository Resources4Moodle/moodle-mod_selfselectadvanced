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

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * OBS-001: a guide who is deleted or fully unenrolled is a NON-MEMBER
 * involvement the member-row observers never saw, so the group kept a
 * dead guideid for ever - the frozen mirror's expected set went on
 * demanding it, one refused sync and one capaudit mail per run.
 *
 * The policy (maintainer decision, veto window closed): forming and
 * submitted teams lose the guide the way a return releases one -
 * guideid cleared under the group lock on a re-read row, guide_removed
 * fired after commit and release, the leader told. Firm and frozen
 * teams keep their state AND their guideid, and a guidegone ticket
 * puts the succession in front of a coordinator as deliberate work.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\observer
 * @covers     \mod_selfselectadvanced\local\tickets::file_guidegone
 */
final class guidegone_test extends \advanced_testcase {
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
     * A course, an activity, a leader, and a manager who works the
     * ticket queue.
     *
     * @return array [activity, course, leader user, manager user]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        return [$activity, $course, $leader, $manager];
    }

    /**
     * The plugin's own observers plus a probe on guide_removed, so a
     * test can both LET the observers run and see what they dispatch.
     * phpunit_replace_observers() replaces the whole table, so the two
     * plugin entries from db/events.php must ride along.
     *
     * @param array $seen collector, filled by reference
     */
    private function observe_with_probe(array &$seen): void {
        $probe = static function (\core\event\base $event) use (&$seen): void {
            global $DB;
            $seen[] = [
                'locks' => locks::held_count(),
                'row' => $DB->get_record('selfselectadvanced_group', ['id' => $event->objectid]),
                'data' => $event->get_data(),
            ];
        };
        \core\event\manager::phpunit_replace_observers([
            ['eventname' => '\core\event\user_deleted', 'callback' => '\mod_selfselectadvanced\observer::user_deleted'],
            [
                'eventname' => '\core\event\user_enrolment_deleted',
                'callback' => '\mod_selfselectadvanced\observer::user_enrolment_deleted',
            ],
            ['eventname' => '\mod_selfselectadvanced\event\guide_removed', 'callback' => $probe],
        ]);
    }

    /**
     * Deleting the account of a submitted team's guide releases the
     * team the way a return does: guideid and the pending handover
     * cleared together, guide_removed dispatched with no lock held and
     * the write visible, the leader notified, and NO ticket - a
     * pending team belongs in the assignment queue, not the ticket
     * queue.
     *
     * The guide is deliberately NOT enrolled: enrolment is not what
     * names a guide on a group row, and an unenrolled account pins the
     * user_deleted arm alone (an enrolled one is unenrolled first by
     * core, so its clearing records 'unenrolled').
     */
    public function test_deleting_a_submitted_teams_guide_releases_it(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $leader] = $this->world();

        $guide = $this->getDataGenerator()->create_user();
        $nominee = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nominee->id, $course->id, 'teacher');
        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Orphaned',
            'guideid' => (int) $guide->id,
            'guidesuccessorid' => (int) $nominee->id,
            'state' => state::PENDING_GUIDE,
        ]);
        $adhocbefore = $DB->count_records('task_adhoc', [
            'classname' => '\mod_selfselectadvanced\task\coresync_adhoc',
        ]);

        $seen = [];
        $this->observe_with_probe($seen);
        delete_user($guide);
        \core\event\manager::phpunit_reset();

        $row = groups::get($activity, (int) $group->id);
        $this->assertNull($row->guideid, 'the dead account must not stay the guide of record');
        $this->assertNull($row->guidesuccessorid, 'the nomination belonged to the guide who left');
        $this->assertNull($row->timeguidenominated);
        $this->assertSame(state::PENDING_GUIDE, $row->state, 'the lifecycle state is not this cleanup\'s to move');

        $this->assertCount(1, $seen, 'guide_removed must fire exactly once');
        $this->assertSame(0, $seen[0]['locks'], 'guide_removed was dispatched while a plugin lock was held');
        $this->assertNull($seen[0]['row']->guideid, 'the write must precede the dispatch');
        $this->assertSame('deleted', $seen[0]['data']['other']['reason']);
        $this->assertSame((int) $guide->id, (int) $seen[0]['data']['relateduserid']);

        // The leader heard, through the notifier.
        $messages = array_filter($sink->get_messages(), fn($m) => (int) $m->useridto === (int) $leader->id);
        $this->assertNotEmpty($messages, 'the leader must be told their team lost its guide');
        $this->assertStringContainsString('needs a new guide', reset($messages)->subject);
        $sink->close();

        // No ticket, and no sync work queued: a pending team has no
        // mirror, and the assignment queue is its surface.
        $this->assertSame(0, $DB->count_records('selfselectadvanced_ticket', [
            'type' => tickets::TYPE_GUIDEGONE,
        ]));
        $this->assertSame($adhocbefore, $DB->count_records('task_adhoc', [
            'classname' => '\mod_selfselectadvanced\task\coresync_adhoc',
        ]), 'the guide branch of a mirrorless team must queue no sync');
    }

    /**
     * Deleting a FROZEN team's guide keeps the row exactly as it is -
     * state and guideid both - and files one open guidegone ticket so
     * a coordinator resolves the succession deliberately; the queue
     * workers are notified of the new work.
     */
    public function test_deleting_a_frozen_teams_guide_files_a_ticket(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $leader, $manager] = $this->world();

        $guide = $this->getDataGenerator()->create_user(['firstname' => 'Gone', 'lastname' => 'Guide']);
        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Kept',
            'guideid' => (int) $guide->id,
            'state' => state::FROZEN,
        ]);

        delete_user($guide);

        $row = groups::get($activity, (int) $group->id);
        $this->assertSame(state::FROZEN, $row->state);
        $this->assertSame(
            (int) $guide->id,
            (int) $row->guideid,
            'a frozen roster is never mutated behind the coordinators\' backs'
        );

        $ticket = $DB->get_record('selfselectadvanced_ticket', [
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_GUIDEGONE,
        ], '*', MUST_EXIST);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);
        $this->assertStringContainsString('Gone Guide', $ticket->request);
        $this->assertStringContainsString('no longer has an account', $ticket->request);

        // The queue workers heard about the new work.
        $workermsgs = array_filter($sink->get_messages(), fn($m) => (int) $m->useridto === (int) $manager->id);
        $this->assertNotEmpty($workermsgs, 'the ticket queue workers must be told of the succession work');
        $sink->close();
    }

    /**
     * Losing the LAST enrolment releases a submitted team's guide with
     * reason 'unenrolled'; a FIRM team files a ticket instead, keeping
     * state and guideid - both halves of the policy through the
     * enrolment door in one deletion-free pass.
     */
    public function test_last_unenrolment_runs_both_halves(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $leader] = $this->world();

        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $course->id, 'teacher');
        $second = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($second->id, $course->id, 'student');
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $pending = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Pending',
            'guideid' => (int) $guide->id,
            'state' => state::PENDING_GUIDE,
        ]);
        $firm = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $second->id,
            'name' => 'Firm',
            'guideid' => (int) $guide->id,
            'state' => state::FIRM,
            'timeapproved' => time() - DAYSECS,
        ]);

        $seen = [];
        $this->observe_with_probe($seen);
        $manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($manual, (int) $guide->id);
        \core\event\manager::phpunit_reset();

        $this->assertNull(groups::get($activity, (int) $pending->id)->guideid);
        $this->assertCount(1, $seen, 'one cleared team, one guide_removed');
        $this->assertSame('unenrolled', $seen[0]['data']['other']['reason']);

        $firmrow = groups::get($activity, (int) $firm->id);
        $this->assertSame(state::FIRM, $firmrow->state);
        $this->assertSame((int) $guide->id, (int) $firmrow->guideid);
        $ticket = $DB->get_record('selfselectadvanced_ticket', [
            'groupid' => (int) $firm->id,
            'type' => tickets::TYPE_GUIDEGONE,
        ], '*', MUST_EXIST);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);
        $this->assertStringContainsString('no longer enrolled', $ticket->request);
        $sink->close();
    }

    /**
     * A second enrolment still standing means the guide is still here:
     * the observer must release nothing and file nothing.
     */
    public function test_a_second_enrolment_keeps_the_guide(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $leader] = $this->world();

        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $course->id, 'teacher');
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        $selfplugin = enrol_get_plugin('self');
        $selfinstanceid = $selfplugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
        $selfinstance = $DB->get_record('enrol', ['id' => $selfinstanceid], '*', MUST_EXIST);
        $selfplugin->enrol_user($selfinstance, (int) $guide->id, (int) $teacherrole);

        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Twice enrolled',
            'guideid' => (int) $guide->id,
            'state' => state::PENDING_GUIDE,
        ]);

        $manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($manual, (int) $guide->id);

        $this->assertSame(
            (int) $guide->id,
            (int) groups::get($activity, (int) $group->id)->guideid,
            'a guide with another enrolment standing keeps their teams'
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_ticket', [
            'type' => tickets::TYPE_GUIDEGONE,
        ]));
        $sink->close();
    }

    /**
     * Deleting an ENROLLED guide fires both observers - core unenrols
     * before it deletes - and the frozen team must end with exactly
     * ONE live succession ticket: the second observer finds the live
     * one and leaves it alone.
     */
    public function test_the_double_fire_files_a_single_ticket(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $leader] = $this->world();

        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $course->id, 'teacher');
        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Once only',
            'guideid' => (int) $guide->id,
            'state' => state::FROZEN,
        ]);

        delete_user($guide);

        $this->assertSame(1, $DB->count_records('selfselectadvanced_ticket', [
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_GUIDEGONE,
        ]), 'the unenrol and the delete must not each file a ticket');
        $this->assertSame(
            (int) $guide->id,
            (int) groups::get($activity, (int) $group->id)->guideid
        );
        $sink->close();
    }

    /**
     * The guide who was ALSO a confirmed member: the member cleanup
     * and the guide cleanup are different involvements of one account,
     * and one deletion must run each of them exactly once.
     */
    public function test_a_guide_who_is_also_a_member_gets_both_cleanups_once(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $leader] = $this->world();

        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $course->id, 'teacher');
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Doubly involved',
            'guideid' => (int) $guide->id,
            'state' => state::PENDING_GUIDE,
        ]);
        $member = $plugingen->create_member([
            'groupid' => (int) $group->id,
            'userid' => (int) $guide->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $seen = [];
        $this->observe_with_probe($seen);
        delete_user($guide);
        \core\event\manager::phpunit_reset();

        // The member half ran: the seat is released.
        $this->assertSame(
            groups::STATUS_REMOVED,
            $DB->get_field('selfselectadvanced_member', 'status', ['id' => (int) $member->id])
        );
        // The guide half ran too, and exactly once - the second
        // observer met a guideid already cleared.
        $this->assertNull(groups::get($activity, (int) $group->id)->guideid);
        $this->assertCount(1, $seen, 'the two observers must not each clear and announce the guide');
        $sink->close();
    }

    /**
     * The departed user is the NOMINATED SUCCESSOR of a pending
     * handover, not the assigned guide (wave-2 blind audit, the
     * medium): the handover lapses - successor and nomination time
     * cleared - the assigned guide keeps the team untouched, and the
     * proposer is TOLD, because they were waiting on an acceptance
     * that can never come.
     *
     * MUTATIONS CAUGHT (run): reverting the successor arm of
     * guide_gone()'s query leaves guidesuccessorid pointing at a
     * deleted account and the first two assertions fail.
     */
    public function test_deleting_a_nominated_successor_lapses_the_handover(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $leader] = $this->world();

        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $course->id, 'teacher');
        $nominee = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nominee->id, $course->id, 'teacher');
        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Waiting',
            'guideid' => (int) $guide->id,
            'guidesuccessorid' => (int) $nominee->id,
            'state' => state::FROZEN,
        ]);

        delete_user($nominee);

        $row = groups::get($activity, (int) $group->id);
        $this->assertNull($row->guidesuccessorid, 'a deleted nominee cannot stay the successor of record');
        $this->assertNull($row->timeguidenominated);
        $this->assertSame((int) $guide->id, (int) $row->guideid, 'the assigned guide keeps the team untouched');
        $this->assertSame(state::FROZEN, $row->state, 'a lapsed nomination moves no lifecycle state');
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_ticket', ['groupid' => (int) $group->id]),
            'a lapsed SUCCESSOR files no guide-succession ticket - the team still has its guide'
        );

        $messages = $sink->get_messages();
        $sink->close();
        // Filtered by CONTENT as well as recipient: enrolment sends the
        // guide a course welcome first, and the first-message grab took
        // that for the notice (the assertion caught its own sloppiness).
        $lapsed = array_values(array_filter(
            $messages,
            fn($m) => (int) $m->useridto === (int) $guide->id
                && str_contains((string) $m->subject, 'lapsed')
        ));
        $this->assertCount(1, $lapsed, 'the proposer must hear, once, that the handover lapsed');
        $this->assertStringContainsString('Waiting', $lapsed[0]->subject);
    }
}
