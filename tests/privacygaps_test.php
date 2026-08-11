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
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\privacy\provider;

/**
 * The provider gaps the 1.20.4 audit enumerated (PRIV-001/PRIV-002),
 * one test per gap, each on the same template: insert the minimal row
 * that holds the person, then assert the context is discovered, the
 * export contains the row, and both deletion paths remove or de-link
 * it.
 *
 * The template matters because the discovery APIs are load-bearing: a
 * context get_contexts_for_userid() misses is a context the person's
 * OWN export and erasure never reach, however well the scrub itself
 * would have handled it. Every one of these presences was reachable by
 * an administrator's userlist deletion, or by nothing at all, while
 * the subject's own request came back empty.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\privacy\provider
 */
final class privacygaps_test extends \advanced_testcase {
    /**
     * A person whose only trace is having hand-triggered an
     * auto-grouping run: discovered, exported, and de-linked by both
     * deletion paths (gap G1 - the column was declared in the metadata
     * and appeared in no discovery SQL and no export dataset).
     */
    public function test_agrun_trigger_only_presence_is_discovered_exported_and_erased(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $trigger1 = $generator->create_user();
        $trigger2 = $generator->create_user();
        $run1 = $this->insert_agrun((int) $instance->id, (int) $trigger1->id, ['pool' => [999999]]);
        $run2 = $this->insert_agrun((int) $instance->id, (int) $trigger2->id, ['pool' => [999999]]);

        $this->assertContainsEquals(
            $context->id,
            provider::get_contexts_for_userid((int) $trigger1->id)->get_contextids(),
            'A user who only triggered a grouping run got no context from their own request'
        );
        $userlist = new userlist($context, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $trigger1->id, $userlist->get_userids());
        $this->assertContains((int) $trigger2->id, $userlist->get_userids());

        $exported = $this->export_module_data($trigger1, $context);
        $this->assertCount(1, $exported->autogroupruns, 'The run the person triggered is not in their export');
        $this->assertSame(transform::yesno(true), $exported->autogroupruns[0]->triggeredbyyou);

        provider::delete_data_for_user(new approved_contextlist(
            $trigger1,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_agrun', 'triggeredby', ['id' => $run1]));
        $this->assertEquals(
            (int) $trigger2->id,
            $DB->get_field('selfselectadvanced_agrun', 'triggeredby', ['id' => $run2]),
            'The other trigger was scrubbed by the wrong deletion'
        );

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $trigger2->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_agrun', 'triggeredby', ['id' => $run2]));
    }

    /**
     * A person whose only trace is having sent an invitation (gap G2:
     * the userlist covered them, their OWN request found nothing - the
     * reverse of the asymmetry the 1.20.3 comment fixed).
     */
    public function test_an_inviter_only_presence_is_discovered_exported_and_erased(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = $generator->create_user();
        $invitee1 = $generator->create_user();
        $invitee2 = $generator->create_user();
        $inviter1 = $generator->create_user();
        $inviter2 = $generator->create_user();
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Invited Crowd',
        ]);
        $row1 = $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $invitee1->id,
            'status' => groups::STATUS_INVITED,
            'invitedby' => $inviter1->id,
            'timeinvited' => time(),
        ]);
        $row2 = $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $invitee2->id,
            'status' => groups::STATUS_INVITED,
            'invitedby' => $inviter2->id,
        ]);

        $this->assertContainsEquals(
            $context->id,
            provider::get_contexts_for_userid((int) $inviter1->id)->get_contextids(),
            'A user who only sent an invitation got no context from their own request'
        );

        $exported = $this->export_module_data($inviter1, $context);
        $this->assertCount(1, $exported->invitationssent, 'The invitation the person sent is not in their export');
        $this->assertSame('Invited Crowd', $exported->invitationssent[0]->group);
        $this->assertSame(groups::STATUS_INVITED, $exported->invitationssent[0]->status);

        provider::delete_data_for_user(new approved_contextlist(
            $inviter1,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertNull($DB->get_field('selfselectadvanced_member', 'invitedby', ['id' => $row1->id]));

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $inviter2->id]
        ));
        $this->assertNull($DB->get_field('selfselectadvanced_member', 'invitedby', ['id' => $row2->id]));
        // The invitations themselves are course history and stay: the
        // leader's own row plus the two invitations.
        $this->assertEquals(3, $DB->count_records('selfselectadvanced_member', ['groupid' => $group->id]));
    }

    /**
     * A person whose only trace is having taken a roster snapshot
     * (gap G2, second half).
     */
    public function test_a_snapshot_taker_only_presence_is_discovered_exported_and_erased(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = $generator->create_user();
        $taker1 = $generator->create_user();
        $taker2 = $generator->create_user();
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Snapshotted',
        ]);
        $snap1 = $DB->insert_record('selfselectadvanced_snapshot', (object) [
            'groupid' => $group->id,
            'coregroupid' => 0,
            'roster' => json_encode([['userid' => (int) $leader->id, 'isleader' => 1]]),
            'takenby' => $taker1->id,
            'timecreated' => time(),
        ]);
        $snap2 = $DB->insert_record('selfselectadvanced_snapshot', (object) [
            'groupid' => $group->id,
            'coregroupid' => 0,
            'roster' => json_encode([['userid' => (int) $leader->id, 'isleader' => 1]]),
            'takenby' => $taker2->id,
            'timecreated' => time(),
        ]);

        $this->assertContainsEquals(
            $context->id,
            provider::get_contexts_for_userid((int) $taker1->id)->get_contextids(),
            'A user who only took a snapshot got no context from their own request'
        );

        $exported = $this->export_module_data($taker1, $context);
        $this->assertCount(1, $exported->snapshots);
        $this->assertSame(transform::yesno(true), $exported->snapshots[0]->takenbyyou);

        provider::delete_data_for_user(new approved_contextlist(
            $taker1,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_snapshot', 'takenby', ['id' => $snap1]));

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $taker2->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_snapshot', 'takenby', ['id' => $snap2]));
    }

    /**
     * A person recorded only as a team's last modifier (gap G3: the
     * column was declared and de-linked, but never discovered or
     * exported, so the de-link was unreachable from their own request).
     */
    public function test_a_group_modifier_only_presence_is_discovered_exported_and_erased(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = $generator->create_user();
        $modifier1 = $generator->create_user();
        $modifier2 = $generator->create_user();
        $group1 = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Edited Team A',
        ]);
        $group2 = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Edited Team B',
        ]);
        $DB->set_field('selfselectadvanced_group', 'usermodified', $modifier1->id, ['id' => $group1->id]);
        $DB->set_field('selfselectadvanced_group', 'usermodified', $modifier2->id, ['id' => $group2->id]);

        $this->assertContainsEquals(
            $context->id,
            provider::get_contexts_for_userid((int) $modifier1->id)->get_contextids(),
            'A user held only in group.usermodified got no context from their own request'
        );
        $userlist = new userlist($context, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $modifier1->id, $userlist->get_userids());

        $exported = $this->export_module_data($modifier1, $context);
        $this->assertCount(1, $exported->groupsmodified);
        $this->assertSame('Edited Team A', $exported->groupsmodified[0]->group);

        provider::delete_data_for_user(new approved_contextlist(
            $modifier1,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_group', 'usermodified', ['id' => $group1->id]));

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $modifier2->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_group', 'usermodified', ['id' => $group2->id]));
    }

    /**
     * A staff member recorded only as an override's grantor (gap G3,
     * override half). The target's row must survive both erasures with
     * its target intact - the exception is the target's record.
     */
    public function test_an_override_grantor_only_presence_is_discovered_exported_and_erased(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $target = $generator->create_user();
        $grantor1 = $generator->create_user();
        $grantor2 = $generator->create_user();
        $now = time();
        $override1 = $DB->insert_record('selfselectadvanced_override', (object) [
            'activityid' => $instance->id,
            'scope' => 'user',
            'userid' => $target->id,
            'maxlead' => 3,
            'status' => 'active',
            'usermodified' => $grantor1->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $override2 = $DB->insert_record('selfselectadvanced_override', (object) [
            'activityid' => $instance->id,
            'scope' => 'user',
            'userid' => $target->id,
            'maxmembership' => 4,
            'status' => 'active',
            'usermodified' => $grantor2->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $this->assertContainsEquals(
            $context->id,
            provider::get_contexts_for_userid((int) $grantor1->id)->get_contextids(),
            'A user held only in override.usermodified got no context from their own request'
        );

        $exported = $this->export_module_data($grantor1, $context);
        $this->assertCount(1, $exported->overridesgranted);
        $this->assertSame('user', $exported->overridesgranted[0]->scope);

        provider::delete_data_for_user(new approved_contextlist(
            $grantor1,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_override', 'usermodified', ['id' => $override1]));
        $this->assertEquals(
            (int) $target->id,
            $DB->get_field('selfselectadvanced_override', 'userid', ['id' => $override1]),
            "Erasing the grantor must not touch the target's exception"
        );

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $grantor2->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_override', 'usermodified', ['id' => $override2]));
    }

    /**
     * A staff member recorded only as a move's stager (gap G4: the one
     * modifier column that was not even declared, let alone discovered,
     * exported or de-linked - the erased manager's id survived on every
     * move they staged for someone else).
     */
    public function test_a_move_stager_only_presence_is_discovered_exported_and_erased(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = $generator->create_user();
        $student = $generator->create_user();
        $stager1 = $generator->create_user();
        $stager2 = $generator->create_user();
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Move Target',
        ]);
        $move1 = $this->insert_move((int) $instance->id, (int) $student->id, (int) $group->id, (int) $stager1->id);
        $move2 = $this->insert_move((int) $instance->id, (int) $student->id, (int) $group->id, (int) $stager2->id);

        $this->assertContainsEquals(
            $context->id,
            provider::get_contexts_for_userid((int) $stager1->id)->get_contextids(),
            'A user held only in move.usermodified got no context from their own request'
        );
        $userlist = new userlist($context, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $stager1->id, $userlist->get_userids());

        $exported = $this->export_module_data($stager1, $context);
        $this->assertCount(1, $exported->moves);
        $this->assertSame(transform::yesno(true), $exported->moves[0]->stagedbyyou);
        $this->assertSame(transform::yesno(false), $exported->moves[0]->wassubject);

        provider::delete_data_for_user(new approved_contextlist(
            $stager1,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_move', 'usermodified', ['id' => $move1]));
        $this->assertEquals(
            (int) $student->id,
            $DB->get_field('selfselectadvanced_move', 'userid', ['id' => $move1]),
            "Erasing the stager must keep the subject's own move record"
        );

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $stager2->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_move', 'usermodified', ['id' => $move2]));
    }

    /**
     * A staff member recorded only as the editor of someone else's
     * attribute record (gap G5): the system context is theirs too, the
     * export says how many records carry their id, and both deletion
     * paths de-link it while the subject keeps their attributes.
     */
    public function test_an_attribute_editor_is_discovered_exported_and_de_linked(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $student1 = $generator->create_user();
        $student2 = $generator->create_user();
        $editor1 = $generator->create_user();
        $editor2 = $generator->create_user();
        $attr1 = $plugingen->create_userattr(['userid' => $student1->id, 'gender' => 'Female']);
        $attr2 = $plugingen->create_userattr(['userid' => $student2->id, 'gender' => 'Male']);
        $DB->set_field('selfselectadvanced_userattr', 'usermodified', $editor1->id, ['id' => $attr1->id]);
        $DB->set_field('selfselectadvanced_userattr', 'usermodified', $editor2->id, ['id' => $attr2->id]);
        $systemcontext = \context_system::instance();

        $this->assertContainsEquals(
            $systemcontext->id,
            provider::get_contexts_for_userid((int) $editor1->id)->get_contextids(),
            'A user held only in userattr.usermodified got no system context from their own request'
        );
        $userlist = new userlist($systemcontext, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $editor1->id, $userlist->get_userids());

        writer::reset();
        provider::export_user_data(new approved_contextlist(
            $editor1,
            'mod_selfselectadvanced',
            [$systemcontext->id]
        ));
        $exported = writer::with_context($systemcontext)->get_data([
            get_string('pluginname', 'mod_selfselectadvanced'),
            get_string('participantattributes', 'mod_selfselectadvanced'),
        ]);
        $this->assertEquals(1, $exported->attributerecordsyouedited);

        provider::delete_data_for_user(new approved_contextlist(
            $editor1,
            'mod_selfselectadvanced',
            [$systemcontext->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_userattr', 'usermodified', ['id' => $attr1->id]));
        $this->assertSame(
            'Female',
            $DB->get_field('selfselectadvanced_userattr', 'gender', ['id' => $attr1->id]),
            "Erasing the editor must keep the subject's own attributes"
        );

        provider::delete_data_for_users(new approved_userlist(
            $systemcontext,
            'mod_selfselectadvanced',
            [(int) $editor2->id]
        ));
        $this->assertEquals(0, $DB->get_field('selfselectadvanced_userattr', 'usermodified', ['id' => $attr2->id]));
        $this->assertSame('Male', $DB->get_field('selfselectadvanced_userattr', 'gender', ['id' => $attr2->id]));
    }

    /**
     * The override export carries every declared column, with the
     * window stamps as dates (gap G6: seven hand-picked fields went
     * out, the timestamps raw, everything else silently withheld).
     */
    public function test_override_export_carries_the_whole_row(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $target = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('selfselectadvanced_override', (object) [
            'activityid' => $instance->id,
            'scope' => 'user',
            'userid' => $target->id,
            'timeopen' => $now - DAYSECS,
            'timedue' => $now + DAYSECS,
            'timecutoff' => $now + (2 * DAYSECS),
            'minsize' => 2,
            'maxsize' => 9,
            'maxlead' => 3,
            'maxmembership' => 4,
            'maxguided' => 5,
            'quotaexempt' => 1,
            'penaltywaived' => 0,
            'rulesbypassed' => 'L1,L2',
            'status' => 'pending',
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $exported = $this->export_module_data($target, $context);
        $this->assertCount(1, $exported->overrides);
        $override = $exported->overrides[0];
        $this->assertSame('pending', $override->status);
        $this->assertEquals(2, $override->minsize);
        $this->assertEquals(9, $override->maxsize);
        $this->assertEquals(5, $override->maxguided);
        $this->assertSame(transform::yesno(true), $override->quotaexempt);
        $this->assertSame(transform::yesno(false), $override->penaltywaived);
        $this->assertSame('L1,L2', $override->rulesbypassed);
        $this->assertSame(transform::datetime($now + DAYSECS), $override->timedue);
        $this->assertSame(transform::datetime($now), $override->timecreated);
    }

    /**
     * The move export carries reason and responsenote - both declared
     * in the metadata since the columns existed - plus the groups and
     * commit time (gap G7: status and one date was the whole export).
     */
    public function test_move_export_carries_reason_and_responsenote(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = $generator->create_user();
        $student = $generator->create_user();
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Asked-For Team',
        ]);
        $now = time();
        $DB->insert_record('selfselectadvanced_move', (object) [
            'activityid' => $instance->id,
            'userid' => $student->id,
            'sourcegroupid' => null,
            'targetgroupid' => $group->id,
            'makeleader' => 1,
            'replaceleader' => 0,
            'successorid' => null,
            'status' => 'committed',
            'reason' => 'My friends are there.',
            'responsenote' => 'Welcome aboard.',
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timecommitted' => $now,
        ]);

        $exported = $this->export_module_data($student, $context);
        $this->assertCount(1, $exported->moves);
        $move = $exported->moves[0];
        $this->assertSame('My friends are there.', $move->reason);
        $this->assertSame('Welcome aboard.', $move->responsenote);
        $this->assertSame('Asked-For Team', $move->targetgroup);
        $this->assertNull($move->sourcegroup);
        $this->assertSame(transform::yesno(true), $move->wassubject);
        $this->assertSame(transform::yesno(true), $move->makeleader);
        $this->assertSame(transform::datetime($now), $move->timecommitted);
    }

    /**
     * The membership export carries the declared invitedby and the
     * whole penalty row (gaps G8 and G9: both declared, neither
     * exported beyond the bare penalty value).
     */
    public function test_membership_export_carries_invitedby_and_the_penalty_row(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = $generator->create_user();
        $member = $generator->create_user();
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Late Team',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $member->id,
            'status' => groups::STATUS_CONFIRMED,
            'invitedby' => $leader->id,
        ]);
        $DB->insert_record('selfselectadvanced_penalty', (object) [
            'activityid' => $instance->id,
            'groupid' => $group->id,
            'dayslate' => 3,
            'penaltyvalue' => 1.5,
            'award' => 20,
            'waived' => 1,
            'waivereason' => 'waiver',
            'timecomputed' => time(),
        ]);

        $exported = $this->export_module_data($member, $context);
        $this->assertCount(1, $exported->memberships);
        $membership = $exported->memberships[0];
        $this->assertEquals((int) $leader->id, $membership->invitedby);
        $this->assertEquals(3, $membership->penaltydayslate);
        $this->assertEquals(1.5, $membership->grouppenalty);
        $this->assertSame(transform::yesno(true), $membership->penaltywaived);
        $this->assertSame('waiver', $membership->penaltywaivereason);
    }

    /**
     * Gap G10, queue half: a digest queued for ANOTHER recipient that is
     * ABOUT the erased person is deleted by both paths; an unrelated queued
     * row for the same recipient stays.
     *
     * Identity comes from the relation, not from the prose. Before 1.20.35
     * this test proved a substring search over the payload; the names below
     * are still deliberately unusual, but nothing reads them any more, and
     * the same-name test beside this one shows why that matters.
     */
    public function test_digest_rows_about_the_erased_person_are_deleted_for_other_recipients(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $recipient = $generator->create_user();
        $named1 = $generator->create_user(['firstname' => 'Zorunmuth', 'lastname' => 'Quandrelle']);
        $named2 = $generator->create_user(['firstname' => 'Xerivanne', 'lastname' => 'Ostrogoth']);
        $naming1 = $this->insert_digestq((int) $instance->id, (int) $recipient->id, [
            'from' => fullname($named1),
            'group' => 'Some Team',
        ], [(int) $named1->id]);
        $naming2 = $this->insert_digestq((int) $instance->id, (int) $recipient->id, [
            'from' => fullname($named2),
            'group' => 'Some Team',
        ], [(int) $named2->id]);
        $unrelated = $this->insert_digestq((int) $instance->id, (int) $recipient->id, [
            'group' => 'Some Team',
        ]);

        provider::delete_data_for_user(new approved_contextlist(
            $named1,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $this->assertFalse(
            $DB->record_exists('selfselectadvanced_digestq', ['id' => $naming1]),
            "A queued digest naming the erased person survived their erasure in another recipient's queue"
        );
        $this->assertTrue($DB->record_exists('selfselectadvanced_digestq', ['id' => $unrelated]));

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_selfselectadvanced',
            [(int) $named2->id]
        ));
        $this->assertFalse($DB->record_exists('selfselectadvanced_digestq', ['id' => $naming2]));
        $this->assertTrue(
            $DB->record_exists('selfselectadvanced_digestq', ['id' => $unrelated]),
            'An unrelated queued digest was deleted along with the naming ones'
        );
        // No index row may outlive the message it points at.
        foreach ([$naming1, $naming2] as $gone) {
            $this->assertSame(
                0,
                $DB->count_records('selfselectadvanced_dqsubject', ['digestid' => $gone]),
                'a subject index row survived the digest row it belongs to'
            );
        }
        $this->assertSame(
            1,
            $DB->count_records('selfselectadvanced_dqsubject', ['digestid' => $unrelated]),
            "the surviving message lost its own recipient's index row"
        );
    }

    /**
     * Gap G10, purge half: the unconditional context purge blanks the
     * guide prose and the modifier id on the group rows it keeps. The
     * structure survives as course data; the personal content does not.
     */
    public function test_a_context_purge_scrubs_group_prose_and_modifier(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = $generator->create_user();
        $modifier = $generator->create_user();
        $group = $plugingen->create_group([
            'activityid' => $instance->id,
            'leaderid' => $leader->id,
            'name' => 'Prose-Laden Team',
        ]);
        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => $group->id,
            'guidenotes' => '<p>Struggles with deadlines.</p>',
            'returncomment' => '<p>Rework the brief.</p>',
            'usermodified' => (int) $modifier->id,
        ]);

        provider::delete_data_for_all_users_in_context($context);

        $purged = $DB->get_record('selfselectadvanced_group', ['id' => $group->id], '*', MUST_EXIST);
        $this->assertNull($purged->guidenotes, 'A purged context kept the guide notes about its people');
        $this->assertNull($purged->returncomment, 'A purged context kept the return comment');
        $this->assertEquals(0, $purged->usermodified, 'A purged context kept the modifier id');
    }

    /**
     * PRIV-002: pseudonymisation replaces only integer-typed leaves in
     * the log's identity positions. A quota-rule id and a digit-led
     * pluginuid that merely collide numerically with the erased userid
     * pass through untouched, type and value both; the identity leaves
     * (pool, leaderid, members) go to 0; a string that spells the same
     * digits stays a string.
     */
    public function test_pseudonymisation_only_replaces_identity_leaves(): void {
        global $DB;

        $this->resetAfterTest();

        [, $instance, $context] = $this->module_fixture();
        $generator = $this->getDataGenerator();
        $erased = $generator->create_user();
        $other = $generator->create_user();
        $uid = (int) $erased->id;
        $oid = (int) $other->id;
        $colliduid = sprintf('%04d', $uid);
        $runid = $this->insert_agrun((int) $instance->id, 0, [
            'pool' => [$uid, $oid],
            // A quota-rule id numerically equal to the erased userid.
            'bypassedrules' => [$uid],
            'residue' => [$uid],
            'groups' => [
                [
                    // A digit-led uid string that int-casts to the userid.
                    'pluginuid' => $colliduid,
                    'leaderid' => $uid,
                    'members' => [$uid, $oid, (string) $uid],
                ],
            ],
        ]);

        provider::delete_data_for_user(new approved_contextlist(
            $erased,
            'mod_selfselectadvanced',
            [$context->id]
        ));

        $log = json_decode($DB->get_field('selfselectadvanced_agrun', 'log', ['id' => $runid]), true);
        $this->assertSame([$uid], $log['bypassedrules'], 'A colliding quota-rule id was zeroed');
        $this->assertSame($colliduid, $log['groups'][0]['pluginuid'], 'A colliding pluginuid string was corrupted');
        $this->assertSame([0, $oid], $log['pool'], 'The identity leaves in the pool were not pseudonymised');
        $this->assertSame([0], $log['residue']);
        $this->assertSame(0, $log['groups'][0]['leaderid']);
        $this->assertSame(
            [0, $oid, (string) $uid],
            $log['groups'][0]['members'],
            'Members must lose the integer id and keep a string that merely spells the same digits'
        );
    }

    /**
     * A course, an instance and its module context.
     *
     * @return array [course, instance record, module context]
     */
    private function module_fixture(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $course->id, false, MUST_EXIST);

        return [$course, $instance, \context_module::instance($cm->id)];
    }

    /**
     * Export one user's module-context data and hand back the dataset.
     *
     * @param \stdClass $user the subject
     * @param \context_module $context the activity context
     * @return \stdClass the exported plugin dataset
     */
    private function export_module_data(\stdClass $user, \context_module $context): \stdClass {
        writer::reset();
        provider::export_user_data(new approved_contextlist(
            $user,
            'mod_selfselectadvanced',
            [$context->id]
        ));
        $exported = writer::with_context($context)->get_data([get_string('pluginname', 'mod_selfselectadvanced')]);
        $this->assertNotEmpty((array) $exported, 'The export wrote nothing for the context');

        return $exported;
    }

    /**
     * Insert an auto-grouping run row.
     *
     * @param int $activityid the instance
     * @param int $triggeredby the triggering user, 0 for the task
     * @param array $log the decision log to encode
     * @return int the new row id
     */
    private function insert_agrun(int $activityid, int $triggeredby, array $log): int {
        global $DB;

        return (int) $DB->insert_record('selfselectadvanced_agrun', (object) [
            'activityid' => $activityid,
            'seed' => 4242,
            'triggeredby' => $triggeredby,
            'timestarted' => time(),
            'timefinished' => time(),
            'groupsformed' => count($log['groups'] ?? []),
            'placed' => 0,
            'unplaced' => 0,
            'log' => json_encode($log),
        ]);
    }

    /**
     * Insert a staged move row with an explicit stager.
     *
     * @param int $activityid the instance
     * @param int $userid the moved student
     * @param int $targetgroupid the destination team
     * @param int $stagerid the staff member staging it
     * @return int the new row id
     */
    private function insert_move(int $activityid, int $userid, int $targetgroupid, int $stagerid): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('selfselectadvanced_move', (object) [
            'activityid' => $activityid,
            'userid' => $userid,
            'sourcegroupid' => null,
            'targetgroupid' => $targetgroupid,
            'makeleader' => 0,
            'replaceleader' => 0,
            'successorid' => null,
            'status' => 'pending',
            'statusinfo' => null,
            'usermodified' => $stagerid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert a queued digest row with a resolved payload AND its subject
     * index, the way notifier::send() writes the pair.
     *
     * The subject ids are passed rather than inferred from the payload text:
     * inferring them is precisely the practice 1.20.35 removed, and a fixture
     * that inferred them would keep testing the old model.
     *
     * @param int $activityid the instance
     * @param int $userid the recipient
     * @param array $payload the resolved placeholder object
     * @param int[] $subjectuserids people the payload is about, besides the recipient
     * @return int the new row id
     */
    private function insert_digestq(int $activityid, int $userid, array $payload, array $subjectuserids = []): int {
        global $DB;

        $id = (int) $DB->insert_record('selfselectadvanced_digestq', (object) [
            'activityid' => $activityid,
            'userid' => $userid,
            'groupid' => null,
            'provider' => 'guidequeue',
            'subjectkey' => 'msghandoverproposedsubject',
            'bodykey' => 'msghandoverproposedbody',
            'payload' => json_encode($payload),
            'contexturl' => 'https://example.invalid/mod/selfselectadvanced/guide.php?id=1',
            'timecreated' => time(),
        ]);
        foreach (\mod_selfselectadvanced\local\notifier::subject_ids($userid, $subjectuserids) as $subjectid) {
            $DB->insert_record('selfselectadvanced_dqsubject', (object) [
                'digestid' => $id,
                'userid' => $subjectid,
            ]);
        }

        return $id;
    }
}
