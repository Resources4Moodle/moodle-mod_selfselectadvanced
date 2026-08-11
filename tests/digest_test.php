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

use mod_selfselectadvanced\local\notifier;
use mod_selfselectadvanced\task\send_digests;

/**
 * Opt-in daily or weekly digest for guide-facing notifications
 * (1.8.0): queueing instead of immediate sending, and the scheduled
 * task that flushes elapsed queues.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\notifier
 * @covers     \mod_selfselectadvanced\task\send_digests
 */
final class digest_test extends \advanced_testcase {
    /**
     * Create a course, activity and an enrolled guide user.
     *
     * @return array [activity, guide]
     */
    private function setup_activity(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);

        return [activity::from_instance((int) $instance->id), $guide];
    }

    /**
     * Send a digestible notification through the notifier.
     *
     * @param activity $activity the activity
     * @param int $touserid recipient
     * @return void
     */
    private function send_sample(activity $activity, int $touserid): void {
        notifier::send(
            $activity,
            'guidequeue',
            $touserid,
            'msgqueuedsubject',
            'msgqueuedbody',
            (object) ['group' => 'Team Alpha', 'pluginuid' => 'SSA-C1-0001', 'activity' => $activity->name()],
            new \moodle_url('/mod/selfselectadvanced/review.php', ['id' => $activity->cm()->id, 'g' => 1]),
            'Team Alpha'
        );
    }

    /**
     * The default (immediate) preference sends every digestible
     * notification straight away, exactly as before 1.8.0, and queues
     * nothing.
     */
    public function test_immediate_sends_now(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity();

        $sink = $this->redirectMessages();
        $this->send_sample($activity, (int) $guide->id);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq'));
    }

    /**
     * A daily or weekly preference queues the digestible notification
     * instead of sending it, storing the already-resolved placeholder
     * payload so the digest can render identical text later.
     */
    public function test_daily_and_weekly_queue_instead_of_sending(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity();

        foreach (['daily', 'weekly'] as $period) {
            // Through the helper, not a bare delete of the queue table. A
            // direct delete leaves the subject index behind, and on MariaDB
            // the next queue row can be handed the id the orphan still names
            // - which is a unique-key violation, not a silent mess. That is
            // the sharpest demonstration available that the two tables have
            // to be removed together.
            notifier::purge_digests($DB->get_fieldset_select('selfselectadvanced_digestq', 'id', '1=1', []));
            set_user_preference('mod_selfselectadvanced_digest', $period, $guide->id);

            $sink = $this->redirectMessages();
            $this->send_sample($activity, (int) $guide->id);
            $messages = $sink->get_messages();
            $sink->close();

            $this->assertCount(0, $messages, "period=$period");
            $rows = $DB->get_records('selfselectadvanced_digestq');
            $this->assertCount(1, $rows, "period=$period");
            $row = reset($rows);
            $this->assertSame((int) $guide->id, (int) $row->userid);
            $this->assertSame($activity->id(), (int) $row->activityid);
            $this->assertSame('guidequeue', $row->provider);
            $this->assertSame('msgqueuedsubject', $row->subjectkey);
            $this->assertSame('msgqueuedbody', $row->bodykey);
            $payload = json_decode($row->payload);
            $this->assertSame('Team Alpha', $payload->group);
            $this->assertSame('SSA-C1-0001', $payload->pluginuid);
            $this->assertNotEmpty($payload->fullname);
            $this->assertStringContainsString('/mod/selfselectadvanced/review.php', $row->contexturl);
        }
    }

    /**
     * Only the DIGESTIBLE providers defer to the digest; every other
     * kind sends immediately regardless of the recipient's preference.
     */
    public function test_non_digestible_provider_always_sends_immediately(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity();
        set_user_preference('mod_selfselectadvanced_digest', 'weekly', $guide->id);

        $sink = $this->redirectMessages();
        notifier::send(
            $activity,
            'invitation',
            (int) $guide->id,
            'msginvitationsubject',
            'msginvitationbody',
            (object) ['group' => 'Team Alpha', 'pluginuid' => 'X', 'activity' => $activity->name(), 'expirynote' => ''],
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            'Team Alpha'
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq'));
    }

    /**
     * The send_digests task sends and clears only the rows of a user
     * whose oldest queued item has aged past their period, grouping
     * every queued item of that user into one message.
     */
    public function test_task_sends_and_clears_only_elapsed_rows(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $activity->courseid(), 'teacher');

        // The guide is daily and has two items: one old enough to flush,
        // one fresh; both flush together because grouping is per user.
        set_user_preference('mod_selfselectadvanced_digest', 'daily', $guide->id);
        $this->send_sample($activity, (int) $guide->id);
        $this->send_sample($activity, (int) $guide->id);
        $DB->set_field_select(
            'selfselectadvanced_digestq',
            'timecreated',
            time() - DAYSECS - HOURSECS,
            'userid = ?',
            [$guide->id]
        );

        // The other user is weekly with a single item only 1 day old:
        // not elapsed yet.
        set_user_preference('mod_selfselectadvanced_digest', 'weekly', $other->id);
        $this->send_sample($activity, (int) $other->id);
        $DB->set_field_select(
            'selfselectadvanced_digestq',
            'timecreated',
            time() - DAYSECS,
            'userid = ?',
            [$other->id]
        );

        $this->assertSame(3, $DB->count_records('selfselectadvanced_digestq'));

        $sink = $this->redirectMessages();
        // The verb is "submitted", not "sent": message_send() promises
        // submission to Moodle messaging, never delivery, and the log
        // now says only what it knows (DIGEST-001).
        $this->expectOutputRegex('/submitted a digest/');
        (new send_digests())->execute();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertSame((int) $guide->id, (int) $messages[0]->useridto);
        $this->assertStringContainsString('Team Alpha', $messages[0]->fullmessage);
        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq', ['userid' => $guide->id]));
        $this->assertSame(1, $DB->count_records('selfselectadvanced_digestq', ['userid' => $other->id]));
    }

    /**
     * Make the recipient due: their queued rows aged past the daily
     * period.
     *
     * @param int $userid the recipient
     */
    private function age_queue(int $userid): void {
        global $DB;
        $DB->set_field_select(
            'selfselectadvanced_digestq',
            'timecreated',
            time() - DAYSECS - HOURSECS,
            'userid = ?',
            [$userid]
        );
    }

    /**
     * The seam that forces message_send() to return FALSE under
     * PHPUnit: an unregistered provider. For a notification,
     * message_send() consults {message_providers} BEFORE the PHPUnit
     * sink gets a say, so deleting the row makes the refusal real even
     * with redirectMessages() on - and real refusals debug once at
     * DEBUG_NORMAL, which the caller must consume.
     *
     * @param string $name the provider to unregister
     * @return \stdClass the deleted row, for reinstate()
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
     * A refused submission (message_send() === false) leaves the rows
     * queued, is counted as a failure and NOT logged as submitted, and
     * an all-refused run escalates (NEW-003 regression for DIGEST-001
     * and NEW-001).
     */
    public function test_refused_submission_keeps_rows_and_escalates(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity();
        set_user_preference('mod_selfselectadvanced_digest', 'daily', $guide->id);
        $this->send_sample($activity, (int) $guide->id);
        $this->age_queue((int) $guide->id);
        $this->unregister_provider('digest');

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/messaging refused the digest for user ' . $guide->id . '/');
        try {
            (new send_digests())->execute();
            $this->fail('a run in which every submission was refused must escalate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not deliver any of 1', $e->getMessage());
        }
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertDebuggingCalledCount(1);

        $this->assertCount(0, $messages);
        $this->assertSame(
            1,
            $DB->count_records('selfselectadvanced_digestq', ['userid' => $guide->id]),
            'a refused digest deleted its queue rows'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/submitted a digest/',
            $this->getActualOutputForAssertion(),
            'a refused digest was logged as submitted'
        );
    }

    /**
     * A stale-only batch (every row's activity deleted) is removed
     * from the queue as CLEANUP: not counted as submitted, not logged
     * as submitted, and it does not mask the all-failed escalation of
     * a sibling whose real submission was refused (NEW-001).
     */
    public function test_stale_only_batch_is_cleanup_and_does_not_mask_escalation(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $activity->courseid(), 'teacher');

        // The guide's batch will be STALE: its activity gets deleted.
        set_user_preference('mod_selfselectadvanced_digest', 'daily', $guide->id);
        $this->send_sample($activity, (int) $guide->id);
        $this->age_queue((int) $guide->id);

        // The other user's batch is real - and will be REFUSED.
        $doomed = $this->getDataGenerator()->create_module('selfselectadvanced', [
            'course' => $activity->courseid(),
        ]);
        set_user_preference('mod_selfselectadvanced_digest', 'daily', $other->id);
        $this->send_sample($activity, (int) $other->id);
        $this->age_queue((int) $other->id);

        // The guide's rows point at an activity that no longer exists.
        // activity::from_instance() reads the instance row MUST_EXIST,
        // so deleting it is the whole seam - and it is a different
        // activity from the sibling's, which stays alive.
        $DB->set_field('selfselectadvanced_digestq', 'activityid', (int) $doomed->id, ['userid' => $guide->id]);
        $DB->delete_records('selfselectadvanced', ['id' => (int) $doomed->id]);
        $this->unregister_provider('digest');

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/discarded 1 stale digest row\(s\) for user ' . $guide->id . '/');
        try {
            (new send_digests())->execute();
            $this->fail('stale cleanup masked the all-failed escalation');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not deliver any of 1', $e->getMessage());
        }
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertDebuggingCalledCount(1);

        $this->assertCount(0, $messages);
        $output = $this->getActualOutputForAssertion();
        $this->assertMatchesRegularExpression('/messaging refused the digest for user ' . $other->id . '/', $output);
        $this->assertDoesNotMatchRegularExpression(
            '/submitted a digest/',
            $output,
            'a stale-only cleanup was logged as a submission'
        );
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_digestq', ['userid' => $guide->id]),
            'stale rows must still leave the queue'
        );
        $this->assertSame(
            1,
            $DB->count_records('selfselectadvanced_digestq', ['userid' => $other->id]),
            'the refused sibling\'s rows must stay queued'
        );
    }

    /**
     * A deleted activity's row INSIDE an otherwise sendable digest is
     * dropped with the batch but never silently: it is excluded from
     * the submitted item count and logged as a dropped stale row
     * (MSG-002 residual r2).
     */
    public function test_partial_stale_rows_are_counted_and_the_rest_submitted(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity();
        set_user_preference('mod_selfselectadvanced_digest', 'daily', $guide->id);

        // One live item and one whose activity is about to vanish.
        $this->send_sample($activity, (int) $guide->id);
        $doomed = $this->getDataGenerator()->create_module('selfselectadvanced', [
            'course' => $activity->courseid(),
        ]);
        $doomedactivity = activity::from_instance((int) $doomed->id);
        notifier::send(
            $doomedactivity,
            'guidequeue',
            (int) $guide->id,
            'msgqueuedsubject',
            'msgqueuedbody',
            (object) ['group' => 'Ghost Crew', 'pluginuid' => 'SSA-C1-0002', 'activity' => $doomedactivity->name()],
            new \moodle_url('/mod/selfselectadvanced/review.php', ['id' => $doomedactivity->cm()->id, 'g' => 2]),
            'Ghost Crew'
        );
        $this->age_queue((int) $guide->id);
        $DB->delete_records('selfselectadvanced', ['id' => (int) $doomed->id]);

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/submitted a digest of 1 item\(s\) to user ' . $guide->id . '/');
        (new send_digests())->execute();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Team Alpha', $messages[0]->fullmessage);
        $this->assertStringNotContainsString('Ghost Crew', $messages[0]->fullmessage);
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_digestq', ['userid' => $guide->id]),
            'the stale row must leave the queue with the batch'
        );
        $this->assertMatchesRegularExpression(
            '/dropped 1 stale row\(s\) \(activity gone\) from user ' . $guide->id . '/',
            $this->getActualOutputForAssertion(),
            'the dropped stale row left no trace in the task log'
        );
    }
}
