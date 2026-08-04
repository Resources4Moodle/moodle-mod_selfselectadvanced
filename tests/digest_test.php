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
            $DB->delete_records('selfselectadvanced_digestq');
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
}
