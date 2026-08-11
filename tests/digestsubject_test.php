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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_selfselectadvanced\local\notifier;
use mod_selfselectadvanced\privacy\provider;

/**
 * A queued digest records WHO it is about, by id.
 *
 * THE DEFECT THIS FILE PINS. A queued digest row held the recipient's id and
 * an already-resolved payload; everybody else the message was about existed
 * only as a rendered full name inside that JSON. The privacy provider had no
 * choice but to reason from the text: it searched payloads for a name to
 * discover contexts, admitted in a comment that a digest-only subject could
 * not be enumerated at all, and deleted rows on a name match.
 *
 * Every one of those is ambiguous, and each ambiguity has a direction:
 *
 * - two people share a name, so erasing one deletes the other's message;
 * - a person renames after queueing, so their own erasure misses the row;
 * - a subject who is not the recipient has no id anywhere, so a subset-user
 *   deletion cannot reach them;
 * - the payload is json_encode() output, so an accented name is stored
 *   escaped and a raw substring search silently matches nothing.
 *
 * A better matcher fixes none of them, because the fault is in the data
 * model. selfselectadvanced_dqsubject records the ids - and only the ids: no
 * names, no email, no rendered text - of every person a queued payload
 * represents, its recipient included.
 *
 * MUTATIONS CAUGHT (run 2026-08-11), each proved to land before it was run,
 * across this file, privacygaps_test and versionbump_test:
 *
 * - send() queues the row and writes no index      -> 11 failures;
 * - the dqsubject branch of get_contexts_for_userid -> 3;
 * - the dqsubject branch of get_users_in_context    -> 1;
 * - purge_digests() leaves the index behind         -> 8;
 * - erasure stops matching on the subject side      -> 4.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\notifier
 * @covers     \mod_selfselectadvanced\privacy\provider
 */
final class digestsubject_test extends \advanced_testcase {
    /**
     * An activity, its context, and a guide who defers to a weekly digest.
     *
     * @param array $names optional firstname/lastname for the recipient
     * @return array [activity, context, recipient]
     */
    private function world(array $names = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);
        $recipient = $generator->create_user($names);
        $generator->enrol_user($recipient->id, $course->id, 'editingteacher');
        set_user_preference('mod_selfselectadvanced_digest', 'weekly', $recipient->id);

        return [$activity, $activity->context(), $recipient];
    }

    /**
     * Queue one guidequeue notification about somebody.
     *
     * @param activity $activity the activity
     * @param int $touserid the recipient
     * @param \stdClass|null $about the person the payload names, if any
     * @return int the queued row id
     */
    private function queue(activity $activity, int $touserid, ?\stdClass $about = null): int {
        global $DB;

        notifier::send(
            $activity,
            'guidequeue',
            $touserid,
            'msghandoverproposedsubject',
            'msghandoverproposedbody',
            (object) [
                'group' => 'Team Alpha',
                'from' => $about ? fullname($about) : 'nobody in particular',
                'activity' => $activity->name(),
            ],
            new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $activity->cm()->id]),
            'Team Alpha',
            $about ? [(int) $about->id] : []
        );

        return (int) $DB->get_field_sql('SELECT MAX(id) FROM {selfselectadvanced_digestq}');
    }

    /**
     * The subject set is the recipient plus whoever was named, deduplicated,
     * with 0 and negatives dropped - 0 is not a user, it is the absence of
     * one, and a row claiming user zero is a subject is the same class of
     * lie the leaderid sentinel was.
     */
    public function test_the_subject_set_is_the_recipient_plus_the_named(): void {
        $this->assertSame([7], notifier::subject_ids(7, []));
        $this->assertSame([7, 9], notifier::subject_ids(7, [9]));
        $this->assertSame([7, 9], notifier::subject_ids(7, [9, 9, 7]));
        $this->assertSame([7], notifier::subject_ids(7, [0, -1]));
        $this->assertSame([7, 9], notifier::subject_ids(7, ['9']));
    }

    /**
     * Queueing writes the relation; the recipient is in it even though
     * digestq.userid already names them, so the relation is a complete index
     * rather than one that has to be read together with a special case.
     */
    public function test_queueing_records_every_subject_including_the_recipient(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user();

        $digestid = $this->queue($activity, (int) $recipient->id, $about);

        $subjects = $DB->get_fieldset_select(
            'selfselectadvanced_dqsubject',
            'userid',
            'digestid = ?',
            [$digestid]
        );
        sort($subjects);
        $expected = [(int) $recipient->id, (int) $about->id];
        sort($expected);
        $this->assertSame($expected, array_map('intval', $subjects));
    }

    /**
     * An IMMEDIATE message retains no payload, so it is nobody's queued
     * subject: the relation must stay empty rather than record a person
     * against a message the plugin does not keep.
     */
    public function test_an_immediate_message_records_no_subject(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $recipient] = $this->world();
        set_user_preference('mod_selfselectadvanced_digest', 'immediate', $recipient->id);
        $about = $this->getDataGenerator()->create_user();

        $sink = $this->redirectMessages();
        $this->queue($activity, (int) $recipient->id, $about);
        $sink->close();

        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq'));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_dqsubject'));
    }

    /**
     * A subject who is NOT the recipient and has no other row in the
     * activity is found by their own subject-access request. This is the
     * discovery half of the defect: before the relation their only trace was
     * a name in somebody else's JSON.
     */
    public function test_a_subject_only_person_discovers_the_context(): void {
        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user();

        $this->queue($activity, (int) $recipient->id, $about);

        $this->assertSame(
            [(int) $context->id],
            array_map('intval', provider::get_contexts_for_userid((int) $about->id)->get_contextids())
        );
    }

    /**
     * ...and is enumerated by the administrator-facing userlist, which is
     * what makes a subset-user deletion able to reach them. The provider
     * used to state in a comment that this case could not be listed.
     */
    public function test_a_subject_only_person_is_listed_in_the_context(): void {
        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user();

        $this->queue($activity, (int) $recipient->id, $about);

        $userlist = new userlist($context, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $about->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * TWO PEOPLE, ONE NAME. Erasing the first deletes only the message
     * structurally linked to them; the identically-named second person's
     * message survives. Under the name search both went, because the
     * predicate could not tell them apart.
     */
    public function test_a_namesake_keeps_their_message(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $generator = $this->getDataGenerator();
        $twin1 = $generator->create_user(['firstname' => 'Sam', 'lastname' => 'Twin']);
        $twin2 = $generator->create_user(['firstname' => 'Sam', 'lastname' => 'Twin']);
        $this->assertSame(fullname($twin1), fullname($twin2), 'the fixture must really be a namesake pair');

        $first = $this->queue($activity, (int) $recipient->id, $twin1);
        $second = $this->queue($activity, (int) $recipient->id, $twin2);

        provider::delete_data_for_user(new approved_contextlist(
            $twin1,
            'mod_selfselectadvanced',
            [$context->id]
        ));

        $this->assertFalse($DB->record_exists('selfselectadvanced_digestq', ['id' => $first]));
        $this->assertTrue(
            $DB->record_exists('selfselectadvanced_digestq', ['id' => $second]),
            "erasing one person deleted a message about a different person who shares their name"
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_dqsubject', ['digestid' => $first]));
        $this->assertSame(2, $DB->count_records('selfselectadvanced_dqsubject', ['digestid' => $second]));
    }

    /**
     * A RENAME AFTER QUEUEING changes nothing: the id did not move. Under
     * the name search the payload still held the old name and the person's
     * own erasure walked straight past it.
     */
    public function test_a_rename_after_queueing_breaks_neither_discovery_nor_erasure(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user(['firstname' => 'Before', 'lastname' => 'Rename']);

        $digestid = $this->queue($activity, (int) $recipient->id, $about);

        $about->firstname = 'After';
        $about->lastname = 'Rename';
        user_update_user($about, false, false);
        $renamed = \core_user::get_user((int) $about->id);
        $this->assertStringContainsString(
            'Before',
            (string) $DB->get_field('selfselectadvanced_digestq', 'payload', ['id' => $digestid]),
            'the fixture must leave the OLD name in the payload, or it proves nothing'
        );

        $this->assertSame(
            [(int) $context->id],
            array_map('intval', provider::get_contexts_for_userid((int) $renamed->id)->get_contextids())
        );
        provider::delete_data_for_user(new approved_contextlist(
            $renamed,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertFalse($DB->record_exists('selfselectadvanced_digestq', ['id' => $digestid]));
    }

    /**
     * A NON-ASCII NAME needs no special handling, because no text is read.
     * The old pre-filter had to reproduce json_encode()'s \\uXXXX escaping to
     * find such a name at all, and an ASCII-only test never noticed.
     */
    public function test_a_unicode_name_needs_no_escaping_logic(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user(['firstname' => 'Zoë', 'lastname' => 'Ångström']);

        $digestid = $this->queue($activity, (int) $recipient->id, $about);

        $this->assertSame(
            [(int) $context->id],
            array_map('intval', provider::get_contexts_for_userid((int) $about->id)->get_contextids())
        );
        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $about->id]
        ));
        $this->assertFalse($DB->record_exists('selfselectadvanced_digestq', ['id' => $digestid]));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_dqsubject', ['digestid' => $digestid]));
    }

    /**
     * The RECIPIENT's own erasure takes their queued rows and the index
     * entries for them - including the entry naming a third party, which
     * would otherwise point at a message that no longer exists.
     */
    public function test_erasing_the_recipient_takes_the_message_and_its_whole_index(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user();

        $digestid = $this->queue($activity, (int) $recipient->id, $about);
        $this->assertSame(2, $DB->count_records('selfselectadvanced_dqsubject', ['digestid' => $digestid]));

        provider::delete_data_for_user(new approved_contextlist(
            $recipient,
            'mod_selfselectadvanced',
            [$context->id]
        ));

        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq', ['id' => $digestid]));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_dqsubject', ['digestid' => $digestid]));
    }

    /**
     * The subject's export says a message about them is queued, and does NOT
     * hand them its text: that text is another recipient's message and can
     * name third parties. A discovered context with an empty export would be
     * its own kind of wrong answer, so the collection is not simply omitted.
     */
    public function test_the_subject_export_states_the_reference_without_the_payload(): void {
        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user(['firstname' => 'Referenced', 'lastname' => 'Person']);

        $this->queue($activity, (int) $recipient->id, $about);

        writer::reset();
        provider::export_user_data(new approved_contextlist(
            $about,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'mod_selfselectadvanced')]);

        $this->assertCount(1, $data->digestreferences);
        $this->assertSame('guidequeue', $data->digestreferences[0]->provider);
        $this->assertObjectNotHasProperty('payload', $data->digestreferences[0]);
        $this->assertSame([], $data->digestqueue, 'a subject is not a recipient and gets no queued payload');

        // The recipient's own export is unchanged: their queued message is
        // their data, payload included.
        writer::reset();
        provider::export_user_data(new approved_contextlist(
            $recipient,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $recipientdata = writer::with_context($context)
            ->get_data([get_string('pluginname', 'mod_selfselectadvanced')]);
        $this->assertCount(1, $recipientdata->digestqueue);
        $this->assertStringContainsString('Referenced', $recipientdata->digestqueue[0]->payload);
    }

    /**
     * A context purge leaves NO digest row and NO orphan index row. The
     * order is written out in the provider rather than left to the database:
     * whether a foreign key cascades is a per-engine question.
     */
    public function test_a_context_purge_leaves_no_orphan_index_rows(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $context, $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user();
        $this->queue($activity, (int) $recipient->id, $about);
        $this->queue($activity, (int) $recipient->id, null);
        $this->assertSame(2, $DB->count_records('selfselectadvanced_digestq'));
        $this->assertSame(3, $DB->count_records('selfselectadvanced_dqsubject'));

        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq'));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_dqsubject'));
    }

    /**
     * Deleting the activity, and resetting the course, both take the index
     * with the queue. These are not privacy paths, which is exactly why they
     * are tested: they are the two that would quietly leave orphans.
     *
     * @param string $path which lifecycle path to exercise
     * @dataProvider lifecycle_provider
     */
    public function test_the_lifecycle_paths_take_the_index_too(string $path): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/selfselectadvanced/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        [$activity, , $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user();
        $this->queue($activity, (int) $recipient->id, $about);
        $this->assertSame(2, $DB->count_records('selfselectadvanced_dqsubject'));

        if ($path === 'delete') {
            selfselectadvanced_delete_instance($activity->id());
        } else {
            $data = (object) [
                'courseid' => $activity->cm()->course,
                'reset_selfselectadvanced_groups' => 1,
                'timeshift' => 0,
            ];
            selfselectadvanced_reset_userdata($data);
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq'), $path);
        $this->assertSame(0, $DB->count_records('selfselectadvanced_dqsubject'), $path);
    }

    /**
     * The two lifecycle paths.
     *
     * @return array[]
     */
    public static function lifecycle_provider(): array {
        return ['activity deleted' => ['delete'], 'course reset' => ['reset']];
    }

    /**
     * Flushing a digest removes the index with the rows it sent.
     */
    public function test_flushing_the_queue_removes_the_index(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $recipient] = $this->world();
        $about = $this->getDataGenerator()->create_user();
        $digestid = $this->queue($activity, (int) $recipient->id, $about);

        notifier::purge_digests([$digestid]);

        $this->assertSame(0, $DB->count_records('selfselectadvanced_digestq'));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_dqsubject'));
    }

    /**
     * The relation carries IDS AND NOTHING ELSE. Asserted against the live
     * schema rather than trusted from install.xml, because the point of the
     * table is that it holds no personal text - a later column called
     * "name" or "email" would quietly undo that.
     */
    public function test_the_relation_stores_identity_and_no_personal_text(): void {
        global $DB;

        $this->resetAfterTest();
        $columns = array_keys($DB->get_columns('selfselectadvanced_dqsubject'));
        sort($columns);

        $this->assertSame(['digestid', 'id', 'userid'], $columns);
    }
}
