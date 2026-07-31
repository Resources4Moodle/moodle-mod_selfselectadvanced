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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\privacy\provider;

/**
 * Breadth of the privacy provider: does it declare what it stores, list
 * everyone it holds, and erase what it says it erases.
 *
 * There were no metadata or userlist tests at all, which is how a
 * declaration could drift from the schema and how get_users_in_context()
 * could omit six roles that get_contexts_for_userid() covered. The two
 * APIs disagreeing is worse than it sounds: a user the userlist omits is
 * never passed to delete_data_for_users(), so an administrator deleting
 * everybody in a context silently skipped them.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\privacy\provider
 */
final class privacybreadth_test extends \advanced_testcase {
    /**
     * Every column the metadata declares must exist in the schema, and
     * every declared field must resolve to a real language string.
     *
     * Catches both directions of drift: a declaration naming a column
     * that was renamed, and a declaration whose string was never written.
     */
    public function test_every_declared_field_is_real(): void {
        global $DB;

        $this->resetAfterTest();

        $collection = provider::get_metadata(new collection('mod_selfselectadvanced'));
        $checked = 0;
        foreach ($collection->get_collection() as $item) {
            if (!$item instanceof \core_privacy\local\metadata\types\database_table) {
                continue;
            }
            $table = $item->get_name();
            $columns = $DB->get_columns($table);
            $this->assertNotEmpty($columns, "Metadata declares unknown table $table");
            foreach ($item->get_privacy_fields() as $field => $stringid) {
                $this->assertArrayHasKey(
                    $field,
                    $columns,
                    "Metadata declares $table.$field, which is not in the schema"
                );
                $this->assertTrue(
                    get_string_manager()->string_exists($stringid, 'mod_selfselectadvanced'),
                    "Metadata string $stringid does not exist"
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(50, $checked, 'Suspiciously few declared fields were checked');
    }

    /**
     * A person whose only footprint is being someone's assigned guide is
     * still listed for the context — and therefore still reachable by an
     * administrator's bulk deletion.
     */
    public function test_a_guide_is_listed_in_their_activity(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $leader = $generator->create_user();
        $guide = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'guideid' => $guide->id,
            'name' => 'Guided Team',
        ]);

        $userlist = new userlist($context, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $found = $userlist->get_userids();

        $this->assertContains((int) $guide->id, $found, 'The assigned guide was not listed for the context');
        $this->assertContains((int) $leader->id, $found);
    }

    /**
     * Whoever sent an invitation is listed too, and once deleted their id
     * no longer sits on the invitation they sent.
     */
    public function test_an_inviter_is_listed_and_then_de_linked(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $leader = $generator->create_user();
        $invitee = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($invitee->id, $course->id, 'student');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Inviting Team',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $invitee->id,
            'status' => groups::STATUS_INVITED,
            'invitedby' => $leader->id,
        ]);

        $userlist = new userlist($context, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $leader->id, $userlist->get_userids());

        // Delete the inviter through the userlist path an administrator
        // would actually use.
        $approved = new approved_userlist($context, 'mod_selfselectadvanced', [$leader->id]);
        provider::delete_data_for_users($approved);

        $this->assertNull(
            $DB->get_field('selfselectadvanced_member', 'invitedby', [
                'groupid' => $group->id,
                'userid' => $invitee->id,
            ]),
            "The deleted user's id survived as invitedby on the invitation they sent"
        );
    }

    /**
     * Purging a whole context leaves no auto-grouping log behind. That
     * path is meant to be unconditional and did not mention the table.
     */
    public function test_purging_a_context_removes_the_grouping_logs(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $manager = $generator->create_user();
        $DB->insert_record('selfselectadvanced_agrun', (object) [
            'activityid' => $instance->id,
            'seed' => 4242,
            'triggeredby' => $manager->id,
            'timestarted' => time(),
            'log' => json_encode(['placed' => [['userid' => $manager->id]]]),
        ]);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_agrun', ['activityid' => $instance->id]));

        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_agrun', ['activityid' => $instance->id]),
            'A purged context kept its auto-grouping logs, raw user ids and all'
        );
    }

    /**
     * D7-F1: a GDPR erasure has to reach the mirrored course group too.
     * The member row goes first, so the ownership discriminator cannot
     * classify a legacy untagged row afterwards - which is exactly what
     * sync_core_group()'s $forceremove parameter exists for.
     *
     * preventResetByRollback() first, and no longer for the reason this
     * docblock used to give: the sync writes to core whether or not a
     * transaction is open - the deferral branch was removed in 1.20
     * (requirement 6). The call is kept so the core-group rows this
     * test reads back are ordinary committed rows.
     */
    public function test_delete_data_for_user_purges_frozen_mirror(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $frozen, $students, $guide, $context] = $this->frozen_fixture();
        $coreid = (int) $frozen->coregroupid;
        $this->assertTrue(groups_is_member($coreid, (int) $students[1]->id));

        provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user((int) $students[1]->id),
            'mod_selfselectadvanced',
            [$context->id]
        ));

        $this->assertFalse(groups_is_member($coreid, (int) $students[1]->id), 'the erased person stayed in the mirror');
        $this->assertTrue(groups_is_member($coreid, (int) $students[0]->id));
        $this->assertTrue(groups_is_member($coreid, (int) $guide->id));
    }

    /**
     * Purging the whole context empties every mirror of every row this
     * plugin owns - and leaves a stranger a teacher added by hand
     * exactly where they are (14.5).
     */
    public function test_delete_all_in_context_empties_mirrors(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $frozen, $students, $guide, $context] = $this->frozen_fixture();
        $coreid = (int) $frozen->coregroupid;
        $stranger = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($stranger->id, $activity->courseid(), 'student');
        groups_add_member($coreid, (int) $stranger->id);

        provider::delete_data_for_all_users_in_context($context);

        foreach ([$students[0], $students[1], $guide] as $gone) {
            $this->assertFalse(
                groups_is_member($coreid, (int) $gone->id),
                'a plugin-owned membership survived the purge'
            );
        }
        $this->assertTrue(groups_is_member($coreid, (int) $stranger->id), 'a stranger was deleted by the purge');
    }

    /**
     * A frozen team of two with a guide, and its mirror.
     *
     * @return array [activity, frozen group row, students[], guide, module context]
     */
    private function frozen_fixture(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
        ], ['idnumber' => 'SSAPRV']);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);

        $students = [];
        for ($i = 0; $i < 2; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Erasable',
            'state' => \mod_selfselectadvanced\local\state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $frozen = \mod_selfselectadvanced\local\freeze::freeze_group(
            $activity,
            \mod_selfselectadvanced\local\groups::get($activity, (int) $group->id),
            (int) $guide->id
        );

        return [$activity, $frozen, $students, $guide, \context_module::instance((int) $cm->id)];
    }
}
