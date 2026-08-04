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

use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\notifier;

/**
 * A refused message_send() is OBSERVABLE and RETRIED (MSG-001,
 * MSG-003): notifier::send() reports the refusal by return, records it
 * durably, and the deadline reminder's once-only flag follows the send
 * instead of preceding it.
 *
 * The seam every test here uses: for a notification, message_send()
 * consults {message_providers} BEFORE the PHPUnit redirection sink
 * gets a say, so deleting a provider's row forces the real false
 * return even under redirectMessages(). Each forced refusal makes
 * message_send() debug once at DEBUG_NORMAL and notifier::send()
 * debug once more, and every test consumes exactly that count - a
 * drifted count here means the refusal path changed shape.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\notifier
 * @covers     \mod_selfselectadvanced\task\deadline_reminder
 * @covers     \mod_selfselectadvanced\event\notification_refused
 */
final class notification_refusal_test extends \advanced_testcase {
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
     * A course, an activity and an enrolled recipient.
     *
     * @return array [activity, user]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'teacher');
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);

        return [activity::from_instance((int) $instance->id), $user];
    }

    /**
     * Unregister a provider so message_send() refuses it.
     *
     * @param string $name the provider
     * @return \stdClass the deleted row, reinsertable to restore it
     */
    private function unregister_provider(string $name): \stdClass {
        global $DB;
        $row = $DB->get_record(
            'message_providers',
            ['component' => 'mod_selfselectadvanced', 'name' => $name],
            '*',
            MUST_EXIST
        );
        $DB->delete_records('message_providers', ['id' => $row->id]);

        return $row;
    }

    /**
     * One send() call with fixed sample arguments.
     *
     * @param activity $activity the activity
     * @param int $touserid the recipient
     * @return bool what send() reported
     */
    private function send_sample(activity $activity, int $touserid): bool {
        return notifier::send(
            $activity,
            'invitation',
            $touserid,
            'msginvitationsubject',
            'msginvitationbody',
            (object) ['group' => 'Team Alpha', 'pluginuid' => 'X', 'activity' => $activity->name(), 'expirynote' => ''],
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            'Team Alpha'
        );
    }

    /**
     * A submitted notification reports true. The positive control for
     * everything below: if this fails, false returns elsewhere prove
     * nothing.
     */
    public function test_send_reports_a_submission_true(): void {
        $this->resetAfterTest();
        [$activity, $user] = $this->world();

        $sink = $this->redirectMessages();
        $ok = $this->send_sample($activity, (int) $user->id);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertTrue($ok);
        $this->assertCount(1, $messages);
    }

    /**
     * A digest-queued notification reports true too: insert_record()
     * throws on failure, so reaching the return means the digest task
     * owns the row now - the deadline reminder's flag rightly follows
     * a queueing exactly as it follows a submission.
     */
    public function test_a_queued_digestible_reports_true(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $user] = $this->world();
        set_user_preference('mod_selfselectadvanced_digest', 'daily', $user->id);

        $sink = $this->redirectMessages();
        $ok = notifier::send(
            $activity,
            'guidequeue',
            (int) $user->id,
            'msgqueuedsubject',
            'msgqueuedbody',
            (object) ['group' => 'Team Alpha', 'pluginuid' => 'X', 'activity' => $activity->name()],
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            'Team Alpha'
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertTrue($ok);
        $this->assertCount(0, $messages);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_digestq', ['userid' => $user->id]));
    }

    /**
     * A refusal reports FALSE and leaves a durable record: the
     * notification_refused event, carrying the provider and the
     * recipient, in the activity's context (MSG-001).
     */
    public function test_a_refusal_reports_false_and_fires_the_event(): void {
        $this->resetAfterTest();
        [$activity, $user] = $this->world();
        $this->unregister_provider('invitation');

        $sink = $this->redirectMessages();
        $events = $this->redirectEvents();
        $ok = $this->send_sample($activity, (int) $user->id);
        $messages = $sink->get_messages();
        $sink->close();
        $refusals = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\notification_refused
        ));
        $events->close();
        $this->assertDebuggingCalledCount(2);

        $this->assertFalse($ok, 'a refused submission reported success');
        $this->assertCount(0, $messages);
        $this->assertCount(1, $refusals, 'the refusal left no durable record');
        $this->assertSame('invitation', $refusals[0]->other['provider']);
        $this->assertSame((int) $user->id, (int) $refusals[0]->other['touserid']);
        $this->assertSame($activity->context()->id, (int) $refusals[0]->contextid);
        // The description renders without throwing - a malformed event
        // is one nobody can read in the log it was written for.
        $this->assertStringContainsString('invitation', $refusals[0]->get_description());
    }

    /**
     * Under a held plugin lock the refusal still reports false but
     * fires NO event - observers must not run under the lock - and
     * falls back to error_log (the placement decision MSG-001 left
     * open: the event triggers inside send() when no lock is held,
     * because a lock-holding send() is already a separately-flagged
     * house-rule violation, not a path to design for).
     */
    public function test_a_refusal_under_a_lock_fires_no_event(): void {
        $this->resetAfterTest();
        [$activity, $user] = $this->world();
        $this->unregister_provider('invitation');

        $sink = $this->redirectMessages();
        $events = $this->redirectEvents();
        $lock = locks::acquire('activity:' . $activity->id());
        try {
            $ok = $this->send_sample($activity, (int) $user->id);
        } finally {
            $lock->release();
        }
        $messages = $sink->get_messages();
        $sink->close();
        $refusals = array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\notification_refused
        );
        $events->close();
        // Three: the held-lock warning, message_send()'s refusal
        // notice, and notifier's own refusal notice.
        $this->assertDebuggingCalledCount(3);

        $this->assertFalse($ok);
        $this->assertCount(0, $messages);
        $this->assertCount(0, $refusals, 'an event was dispatched while a plugin lock was held');
    }

    /**
     * The deadline reminder's once-only flag follows the SEND
     * (MSG-003): a refused submission leaves the flag unwritten so the
     * next run retries; a submitted one writes it, once.
     */
    public function test_deadline_reminder_retries_a_refused_send(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'timedue' => time() + (12 * HOURSECS),
        ]);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $prefkey = 'mod_selfselectadvanced_reminded_' . (int) $instance->id;
        $provider = $this->unregister_provider('deadlinereminder');

        // Run 1: the send is refused, so the flag must NOT be written.
        $sink = $this->redirectMessages();
        $events = $this->redirectEvents();
        (new \mod_selfselectadvanced\task\deadline_reminder())->execute();
        $first = $sink->get_messages();
        $refusals = array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\notification_refused
        );
        $events->close();
        $this->assertDebuggingCalledCount(2);

        $this->assertCount(0, $first);
        $this->assertCount(1, $refusals, 'the task\'s refused reminder left no durable record');
        $this->assertSame(
            0,
            (int) get_user_preferences($prefkey, 0, (int) $student->id),
            'a refused reminder was flagged as sent - a permanent silence'
        );

        // Run 2, provider restored: the retry delivers and flags.
        unset($provider->id);
        $DB->insert_record('message_providers', $provider);
        $sink->clear();
        (new \mod_selfselectadvanced\task\deadline_reminder())->execute();
        $second = $sink->get_messages();
        $sink->clear();

        $reminders = array_values(array_filter(
            $second,
            static fn($m) => $m->eventtype === 'deadlinereminder'
        ));
        $this->assertCount(1, $reminders);
        $this->assertSame((int) $student->id, (int) $reminders[0]->useridto);
        $this->assertSame(1, (int) get_user_preferences($prefkey, 0, (int) $student->id));

        // Run 3: flagged means once only, exactly as before.
        (new \mod_selfselectadvanced\task\deadline_reminder())->execute();
        $third = $sink->get_messages();
        $sink->close();
        $this->assertCount(0, $third);
    }
}
