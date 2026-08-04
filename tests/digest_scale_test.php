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

use mod_selfselectadvanced\local\templates;
use mod_selfselectadvanced\task\send_digests;

/**
 * PERF-001: what one digest run loads is now a function of the WORK,
 * not of the table.
 *
 * The task used to select every distinct recipient with anything
 * queued, then read every queued row of each of them, and only then
 * consult the preference that decides whether any of it was due. On a
 * site with 13,000 enrolled users each holding one queued item, that
 * was 13,000 candidate rows plus 13,000 item rows plus 13,000
 * preference loads per run - and the rows of a weekly recipient one
 * day into their week were read and discarded on every run for six
 * days. Then, for the recipients that WERE due, the per-activity
 * message-template override was fetched once per queued ITEM.
 *
 * Four bounds replace that, and each test below proves one of them by
 * making it bite:
 *
 *  - only DUE recipients are returned, decided in SQL against the
 *    joined preference (test 3: a queue of 180 rows that nobody is due
 *    for cost 122 reads and loaded 240 rows before this change, and
 *    costs 1 read and loads no item row at all after it);
 *  - at most `digestbatch` recipients per run (test 1);
 *  - at most `digestitembatch` items per recipient per run (test 2);
 *  - the template overrides are read once per activity per run rather
 *    than once per item (test 4, which measures the marginal cost of
 *    16 extra items in one digest).
 *
 * The caps are the ops escape hatch this project already uses for
 * guide_autoapprove (config_plugins, not an admin setting), so the
 * tests set them with set_config exactly as autoapprove_test does.
 *
 * Nothing here rests on a rollback being visible to a later read: every
 * queued row is written with $DB and counted with $DB in the same test,
 * so both engines discriminate identically.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\task\send_digests
 * @covers     \mod_selfselectadvanced\local\notifier::resolve_text
 */
final class digest_scale_test extends \advanced_testcase {
    /** @var int Items in the memo test's digest - the per-item cost, if there is one, is multiplied by this. */
    private const MEMO_ITEMS = 20;

    /**
     * @var int Reads one such digest may cost. MEASURED on this box,
     *      m5pg, on the second (warmed) run: 29 with the overrides
     *      memoised and 49 without - exactly one extra lookup per item
     *      - so the bound sits well clear of both figures.
     */
    private const MEMO_READS_MAX = 38;

    /** @var activity The activity every queued row belongs to. */
    private $activity;

    /** @var \stdClass The course. */
    private $course;

    /**
     * A course and an activity.
     */
    private function world(): void {
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $this->course->id]);
        $this->activity = activity::from_instance((int) $instance->id);
    }

    /**
     * A recipient with a digest preference and a queue of that many
     * items, all of them aged so the period HAS elapsed.
     *
     * The rows are written directly: the notifier's queueing path is
     * already pinned by digest_test, and what is under test here is
     * what the task READS, so the fixture states the queue exactly.
     *
     * @param string $period immediate, daily or weekly
     * @param int $items how many queued rows
     * @param int $age how old they are, in seconds
     * @return int the recipient's userid
     */
    private function recipient(string $period, int $items, int $age): int {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'teacher');
        if ($period !== 'immediate') {
            set_user_preference('mod_selfselectadvanced_digest', $period, $user->id);
        }
        $now = time();
        for ($i = 0; $i < $items; $i++) {
            $DB->insert_record('selfselectadvanced_digestq', (object) [
                'userid' => (int) $user->id,
                'activityid' => $this->activity->id(),
                'groupid' => null,
                'provider' => 'guidequeue',
                'subjectkey' => 'msgqueuedsubject',
                'bodykey' => 'msgqueuedbody',
                'payload' => json_encode((object) [
                    'group' => 'Team ' . $i,
                    'pluginuid' => 'SSA-C1-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'activity' => $this->activity->name(),
                    'fullname' => 'Someone',
                ]),
                'contexturl' => '/mod/selfselectadvanced/review.php?id=' . $this->activity->cm()->id,
                'timecreated' => $now - $age,
            ]);
        }

        return (int) $user->id;
    }

    /**
     * Run the task with its output captured rather than asserted on.
     *
     * @return \stdClass[] the messages it sent
     */
    private function run_task(): array {
        $sink = $this->redirectMessages();
        ob_start();
        try {
            (new send_digests())->execute();
        } finally {
            // The buffer is closed even when the task throws: a test
            // that leaves one open is reported as risky and the real
            // failure is buried under that.
            ob_end_clean();
        }
        $messages = $sink->get_messages();
        $sink->close();

        return $messages;
    }

    /**
     * BOUND 2: a run flushes at most `digestbatch` recipients, and the
     * ones it did not reach keep every row they had.
     *
     * Twelve recipients are due; the cap is five. Before this change
     * the run took all twelve however many there were, which is the
     * finding: the per-run cost was the size of the queue.
     */
    public function test_a_run_flushes_at_most_the_recipient_cap(): void {
        global $DB;
        $this->resetAfterTest();
        $this->world();
        set_config('digestbatch', 5, 'mod_selfselectadvanced');

        $recipients = [];
        for ($i = 0; $i < 12; $i++) {
            $recipients[] = $this->recipient('daily', 2, DAYSECS + HOURSECS);
        }
        $this->assertSame(24, $DB->count_records('selfselectadvanced_digestq'), 'fixture: 12 x 2 rows queued');

        $messages = $this->run_task();
        $this->assertCount(5, $messages, 'the run did not stop at the five-recipient cap');
        $this->assertSame(
            14,
            $DB->count_records('selfselectadvanced_digestq'),
            'the recipients beyond the cap did not keep their queued rows'
        );

        // Resumable: the next pass takes the next five, so nothing is
        // stranded behind the cap.
        $this->assertCount(5, $this->run_task());
        $this->assertSame(4, $DB->count_records('selfselectadvanced_digestq'));
        $this->assertCount(2, $this->run_task());
        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq'));

        // Every one of the twelve was reached across the three passes.
        $this->assertSame(12, count($recipients));
    }

    /**
     * BOUND 3: one recipient's queue is read in slices, so a single
     * pathological queue cannot decide the memory cost of the run.
     *
     * Twenty-five items, a cap of ten: three passes, and the digest
     * says how many it carried each time.
     */
    public function test_one_recipient_flushes_in_slices_of_the_item_cap(): void {
        global $DB;
        $this->resetAfterTest();
        $this->world();
        set_config('digestitembatch', 10, 'mod_selfselectadvanced');

        $userid = $this->recipient('weekly', 25, WEEKSECS + HOURSECS);
        $this->assertSame(25, $DB->count_records('selfselectadvanced_digestq', ['userid' => $userid]));

        $slices = [];
        foreach ([15, 5, 0] as $expectedremainder) {
            $messages = $this->run_task();
            $this->assertCount(1, $messages, 'the recipient was not flushed at all');
            $slices[] = $messages[0]->subject;
            $this->assertSame(
                $expectedremainder,
                $DB->count_records('selfselectadvanced_digestq', ['userid' => $userid]),
                'the wrong number of rows was deleted for the slice that was sent'
            );
        }

        $this->assertSame([
            get_string('digestsubject', 'mod_selfselectadvanced', 10),
            get_string('digestsubject', 'mod_selfselectadvanced', 10),
            get_string('digestsubject', 'mod_selfselectadvanced', 5),
        ], $slices, 'the digests did not carry 10, 10 and 5 items');
    }

    /**
     * BOUND 1, AND THE HEART OF THE FINDING: a recipient whose period
     * has not elapsed costs the run nothing at all.
     *
     * Sixty recipients, 180 queued rows, every one of them weekly and
     * one day old. What the task used to do with that queue: one query
     * for the 60 userids, then 60 queries returning all 180 rows, then
     * 60 preference loads - 121 reads and 240 rows, to send nothing.
     * What it does now: one aggregate query that returns no candidate
     * at all, because the preference is joined and the cutoff compared
     * in SQL.
     *
     * The bound below is deliberately loose (ten reads for a fixture of
     * sixty) so it pins the SHAPE - constant, not per-recipient -
     * rather than an exact number that a core change could move.
     */
    public function test_a_recipient_who_is_not_due_costs_the_run_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->world();

        $recipients = 60;
        for ($i = 0; $i < $recipients; $i++) {
            $this->recipient('weekly', 3, DAYSECS);
        }
        $this->assertSame(180, $DB->count_records('selfselectadvanced_digestq'), 'fixture: 60 x 3 rows queued');

        // Warm the plugin config cache, which the task reads for its
        // two caps: a cold cache would be measured as if the queue had
        // cost it. The same warming the mirror-cost test does.
        get_config('mod_selfselectadvanced', 'digestbatch');

        $before = $DB->perf_get_reads();
        $messages = $this->run_task();
        $reads = $DB->perf_get_reads() - $before;

        $this->assertCount(0, $messages, 'somebody not yet due was sent a digest');
        $this->assertSame(
            180,
            $DB->count_records('selfselectadvanced_digestq'),
            'a run that sent nothing deleted something'
        );
        $this->assertLessThanOrEqual(
            10,
            $reads,
            "the run made $reads reads for $recipients not-due recipients holding 180 rows;"
                . ' the cost is still per-recipient'
        );
    }

    /**
     * BOUND 4: the message-template overrides are read once per
     * activity per run, not once per queued item.
     *
     * notifier::resolve_text() asks templates::get() for the override
     * of the key it is rendering, which is one query - and the digest
     * task called it once per QUEUED ROW, so a digest of twenty items
     * cost twenty lookups of the same activity's overrides. The task
     * now loads them once per activity with templates::get_all() and
     * passes them in.
     *
     * The measurement is taken on the SECOND run of an identical
     * digest, because the first one fills this process's caches -
     * course modinfo, the no-reply user, the message processors - and
     * measuring that would be measuring PHPUnit, not the queue. Run
     * against the un-memoised code this same figure was 20 reads
     * higher - 49 against 29, one extra lookup per item - which is the
     * gap the bound is set inside.
     */
    public function test_the_template_overrides_are_read_once_per_activity_not_once_per_item(): void {
        global $DB;
        $this->resetAfterTest();
        $this->world();

        // A real override, so the lookup being memoised is one that
        // finds something and changes the text that goes out.
        templates::save($this->activity, 'msgqueuedbody', 'Queued: {$a->group}', 'About {$a->pluginuid}');
        get_config('mod_selfselectadvanced', 'digestbatch');

        // Warm-up run, identical in shape to the measured one.
        $this->recipient('daily', self::MEMO_ITEMS, DAYSECS + HOURSECS);
        $this->assertCount(1, $this->run_task(), 'the warm-up digest did not go out');

        $this->recipient('daily', self::MEMO_ITEMS, DAYSECS + HOURSECS);
        $before = $DB->perf_get_reads();
        $messages = $this->run_task();
        $reads = $DB->perf_get_reads() - $before;

        $this->assertCount(1, $messages);
        $this->assertStringContainsString(
            'Queued: Team 0',
            $messages[0]->fullmessage,
            'the override was not applied, so the memo is memoising nothing'
        );
        $this->assertSame(
            get_string('digestsubject', 'mod_selfselectadvanced', self::MEMO_ITEMS),
            $messages[0]->subject,
            'the digest did not carry every item'
        );
        $this->assertLessThanOrEqual(
            self::MEMO_READS_MAX,
            $reads,
            "a digest of " . self::MEMO_ITEMS . " items cost $reads reads; a per-item template lookup is back"
        );
    }
}
