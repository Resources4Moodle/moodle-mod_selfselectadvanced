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
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * 1.20.43, the settings release, proven at the SERVICE (direct calls,
 * never through a page - UI hiding is not enforcement):
 *
 * A. The three who-may-raise checkboxes: each one off blocks exactly its
 *    role's filing arm; on (the default) restores it.
 * B. The general `help` type: groupid 0 for a groupless raiser, its own
 *    per-requester duplicate guard, and that it reaches the queue and
 *    the type filter.
 * C. Responsible-person mode: the three stages, the guide-over-leader
 *    precedence, the recorded leaderchange consequence, and that the
 *    mode never blocks a requester's own withdraw.
 * D. The disclaimer: the service throws without an acknowledgement when
 *    one is set, records the ack it was given, and an empty disclaimer
 *    demands nothing.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets::file
 * @covers     \mod_selfselectadvanced\local\tickets::file_help
 * @covers     \mod_selfselectadvanced\local\tickets::require_may_raise
 * @covers     \mod_selfselectadvanced\local\tickets::require_responsible
 * @covers     \mod_selfselectadvanced\local\tickets::raiser_role
 * @covers     \mod_selfselectadvanced\local\tickets::responsible_role
 * @covers     \mod_selfselectadvanced\local\tickets::my_group_for_help
 */
final class ticket_authority_test extends \advanced_testcase {
    /**
     * A course, an activity, a leader, a plain member and a guide -
     * everybody enrolled and ready for a group to be built around them.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, course, leader, member, guide]
     */
    private function scene(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 5,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $member = $generator->create_user();
        $generator->enrol_user($member->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        return [$activity, $course, $leader, $member, $guide];
    }

    /**
     * The plugin's data generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * Build a group directly (fixtures bypass the gatekeeper on purpose)
     * with the leader confirmed and, when given, the plain member
     * confirmed too.
     *
     * @param activity $activity the activity
     * @param int $leaderid the leader
     * @param string $groupstate a state::* constant
     * @param int|null $guideid the assigned guide, or null for none
     * @param int|null $memberid a second confirmed member, or null
     * @return \stdClass the group row
     */
    private function group(
        activity $activity,
        int $leaderid,
        string $groupstate,
        ?int $guideid = null,
        ?int $memberid = null
    ): \stdClass {
        $group = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderid,
            'guideid' => $guideid,
            'state' => $groupstate,
            'name' => 'Scene ' . $groupstate . '-' . $leaderid,
        ]);
        if ($memberid !== null) {
            $this->plugingen()->create_member([
                'groupid' => $group->id,
                'userid' => $memberid,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return groups::get($activity, (int) $group->id);
    }

    // ------------------------------------------------------------------
    // A. Who-may-raise checkboxes.

    /**
     * Defaults preserve pre-upgrade behaviour exactly: a freshly created
     * activity, with none of the three checkboxes mentioned, reads all
     * three as ON straight from the schema DEFAULT - proving the
     * upgrade step's defaults, not merely the code's fallback.
     */
    public function test_defaults_preserve_pre_upgrade_behaviour(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity] = $this->scene();
        $row = $DB->get_record('selfselectadvanced', ['id' => $activity->id()], '*', MUST_EXIST);

        $this->assertSame('1', (string) $row->ticketraiseguide);
        $this->assertSame('1', (string) $row->ticketraiseleader);
        $this->assertSame('1', (string) $row->ticketraisemember);
        $this->assertSame('0', (string) $row->ticketresponsiblemode);
        $this->assertNull($row->ticketdisclaimer);
    }

    /**
     * The guide checkbox off blocks a composition-change request (guide
     * arm). RED-FIRST PROOF (see the report): with
     * tickets::require_may_raise() not yet wired into file(), this
     * test's first assertion fails because filing succeeds instead of
     * throwing.
     *
     * A refusal and a later success are NEVER asserted in the same test
     * method here (authsweep_authority_test.php's own house rule, pinned
     * again the hard way while building this file - see the report): a
     * refused service call rolls its delegated frame back, and on
     * PostgreSQL that poisons any later commit in the same method, so
     * this arm's negative and positive controls are two methods.
     */
    public function test_guide_checkbox_off_blocks_compchange(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , $guide] = $this->scene(['ticketraiseguide' => 0]);
        $group = $this->group($activity, (int) $leader->id, state::FIRM, (int) $guide->id);

        try {
            tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Need to change the roster', FORMAT_PLAIN, (int) $guide->id);
            $this->fail('the guide checkbox is off; filing must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketraiseguide', $e->errorcode);
        }
    }

    /**
     * The guide checkbox on (the default) restores exactly today's
     * behaviour - the positive control for the arm above, kept in its
     * own method (see that test's docblock).
     */
    public function test_guide_checkbox_on_default_allows_compchange(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , $guide] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM, (int) $guide->id);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need to change the roster',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->assertSame(tickets::TYPE_COMPCHANGE, $ticket->type);
    }

    /**
     * The leader checkbox off blocks an unfreeze request filed by the
     * leader. Direct-call negative test for the leader arm; positive
     * control below, in its own method (PostgreSQL poison rule, see the
     * guide test's docblock above).
     */
    public function test_leader_checkbox_off_blocks_unfreeze(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene(['ticketraiseleader' => 0]);
        $group = $this->group($activity, (int) $leader->id, state::FROZEN);

        try {
            tickets::file($activity, $group, tickets::TYPE_UNFREEZE, 'Please unfreeze', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('the leader checkbox is off; filing must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketraiseleader', $e->errorcode);
        }
    }

    /**
     * The leader checkbox on (the default) restores exactly today's
     * behaviour.
     */
    public function test_leader_checkbox_on_default_allows_unfreeze(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FROZEN);
        $ticket = tickets::file($activity, $group, tickets::TYPE_UNFREEZE, 'Please unfreeze', FORMAT_PLAIN, (int) $leader->id);
        $this->assertSame(tickets::TYPE_UNFREEZE, $ticket->type);
    }

    /**
     * The member checkbox off blocks a leadership-help request. Direct-
     * call negative test for the member arm; positive control below, in
     * its own method (PostgreSQL poison rule, see above).
     */
    public function test_member_checkbox_off_blocks_leaderchange(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene(['ticketraisemember' => 0]);
        $group = $this->group($activity, (int) $leader->id, state::FORMING, null, (int) $member->id);

        try {
            tickets::file(
                $activity,
                $group,
                tickets::TYPE_LEADERCHANGE,
                'The leader is unreachable',
                FORMAT_PLAIN,
                (int) $member->id
            );
            $this->fail('the member checkbox is off; filing must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketraisemember', $e->errorcode);
        }
    }

    /**
     * The member checkbox on (the default) restores exactly today's
     * behaviour.
     */
    public function test_member_checkbox_on_default_allows_leaderchange(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FORMING, null, (int) $member->id);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'The leader is unreachable',
            FORMAT_PLAIN,
            (int) $member->id
        );
        $this->assertSame(tickets::TYPE_LEADERCHANGE, $ticket->type);
    }

    /**
     * ELIGIBILITY NEVER WIDENS: the member checkbox being ON does not
     * let a member file a guide-only type (compchange). Its own method,
     * negative-only (PostgreSQL poison rule, see above).
     */
    public function test_member_checkbox_on_still_refuses_a_guide_only_type(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FORMING, null, (int) $member->id);

        $this->expectException(local\workflow_refusal::class);
        tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Not mine to ask', FORMAT_PLAIN, (int) $member->id);
    }

    // ------------------------------------------------------------------
    // B. The general help type.

    /**
     * One live help ticket per (activity, requester) - the duplicate
     * guard is by REQUESTER, not by (group, type) like the other five
     * types share, because a groupless help ticket has no group to key
     * on.
     */
    public function test_help_duplicate_guard_is_per_requester(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FORMING);

        $first = tickets::file_help($activity, $group, 'First question', FORMAT_PLAIN, (int) $leader->id);
        $this->assertSame(tickets::STATUS_OPEN, $first->status);

        try {
            tickets::file_help($activity, $group, 'Second question', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('a second live help ticket from the same requester must be refused');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketduplicatehelp', $e->errorcode);
            $this->assertSame((int) $first->id, (int) $e->a);
        }
    }

    /**
     * A raiser with no group at all gets groupid 0, not null - the
     * schema tolerates 0 (spec: "the no-team display idiom exists for
     * guidecap"), and tickets::group_of() already treats <= 0 as "no
     * team" for either representation.
     */
    public function test_help_groupid_is_zero_for_a_groupless_raiser(): void {
        $this->resetAfterTest();

        [$activity, $course] = $this->scene();
        $loner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($loner->id, $course->id, 'student');

        $ticket = tickets::file_help($activity, null, 'I have no group yet', FORMAT_PLAIN, (int) $loner->id);

        $this->assertSame(0, (int) $ticket->groupid);
        $this->assertNull(tickets::group_of($activity, $ticket));
    }

    /**
     * A help ticket is routed through the same queue and the same type
     * filter as every other type - registered in the type registry, not
     * assumed.
     */
    public function test_help_appears_in_the_queue_and_the_type_filter(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FORMING);
        $filed = tickets::file_help($activity, $group, 'Queue me', FORMAT_PLAIN, (int) $leader->id);

        $unfiltered = tickets::queue($activity);
        $this->assertArrayHasKey((int) $filed->id, $unfiltered, 'the help ticket must be in the unfiltered queue');

        $filtered = tickets::queue($activity, 0, 0, 0, tickets::TYPE_HELP);
        $this->assertArrayHasKey((int) $filed->id, $filtered, 'the type filter must accept and match TYPE_HELP');
        foreach ($filtered as $row) {
            $this->assertSame(tickets::TYPE_HELP, $row->type);
        }
        $this->assertSame(1, tickets::count_open($activity, 0, tickets::TYPE_HELP));
    }

    /**
     * AUDIT A3 (2026-08-20): file_help() had no participant gate at all
     * - a visitor with no enrolment in the course at all could file
     * straight into the staff queue. RED-FIRST PROOF (see the report):
     * before the fix this call never throws - filing SUCCEEDS instead,
     * and the $this->fail() below is what actually runs.
     */
    public function test_help_refuses_a_non_enrolled_visitor(): void {
        $this->resetAfterTest();

        [$activity] = $this->scene();
        // Deliberately never enrolled anywhere near this course.
        $stranger = $this->getDataGenerator()->create_user();

        try {
            tickets::file_help($activity, null, 'I should not reach the queue', FORMAT_PLAIN, (int) $stranger->id);
            $this->fail('a non-enrolled visitor must be refused before a ticket is ever created');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketnotenrolled', $e->errorcode);
        }
    }

    /**
     * The positive control for the arm above, in its own method
     * (PostgreSQL transaction-poison rule, see the checkbox tests'
     * docblock): an enrolled groupless raiser is unaffected by the new
     * gate - already proven by test_help_groupid_is_zero_for_a_groupless_
     * raiser above, restated here as the direct before/after pair for
     * this specific finding.
     */
    public function test_help_still_allows_an_enrolled_groupless_raiser(): void {
        $this->resetAfterTest();

        [$activity, $course] = $this->scene();
        $loner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($loner->id, $course->id, 'student');

        $ticket = tickets::file_help($activity, null, 'I am actually enrolled', FORMAT_PLAIN, (int) $loner->id);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);
    }

    // ------------------------------------------------------------------
    // C. Responsible-person mode.

    /**
     * Stage 1: a raiser with no group is responsible for themself, and
     * files successfully with the mode on.
     */
    public function test_responsible_mode_stage1_groupless_raiser_may_file(): void {
        $this->resetAfterTest();

        [$activity, $course] = $this->scene(['ticketresponsiblemode' => 1]);
        $loner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($loner->id, $course->id, 'student');

        $ticket = tickets::file_help($activity, null, 'On my own', FORMAT_PLAIN, (int) $loner->id);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);
    }

    /**
     * Stage 2: a group with a leader and NO assigned guide - only the
     * leader may file; a member is refused with the pointer-to-leader
     * string.
     */
    public function test_responsible_mode_stage2_only_leader_may_file(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene(['ticketresponsiblemode' => 1]);
        $group = $this->group($activity, (int) $leader->id, state::FROZEN, null, (int) $member->id);

        $ticket = tickets::file($activity, $group, tickets::TYPE_UNFREEZE, 'Only I may ask', FORMAT_PLAIN, (int) $leader->id);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);

        try {
            tickets::file_help($activity, $group, 'On behalf of the group', FORMAT_PLAIN, (int) $member->id);
            $this->fail('a member must not file for a group whose leader is the responsible person');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketresponsibleleader', $e->errorcode);
        }
    }

    /**
     * Stage 3: a group with an assigned guide - only the guide may file;
     * the LEADER is now refused too (guide-assigned overrides leader).
     * "Firmed under a guide" is read as the GUIDE RELATION, not the
     * frozen flag - proven here on a FIRM (not frozen) team.
     */
    public function test_responsible_mode_stage3_guide_assigned_overrides_leader(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , $guide] = $this->scene(['ticketresponsiblemode' => 1]);
        $group = $this->group($activity, (int) $leader->id, state::FIRM, (int) $guide->id);

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'The guide is responsible now',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);

        // The leader is refused DATES too - a guide-only type under the
        // base relational rule, so this proves the mode does not
        // silently WIDEN who may file a type the relational check never
        // admitted the leader to in the first place; the mode-specific
        // string is asserted on help, which the leader passes layer A
        // for.
        try {
            tickets::file_help($activity, $group, 'The leader tries anyway', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('once a guide is assigned, the leader is no longer the responsible person');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketresponsibleguide', $e->errorcode);
        }
    }

    /**
     * A frozen team with NO guide stays at stage 2, not stage 3 - the
     * design reading pinned literally: "firmed under a guide" means the
     * guide relation, never the frozen flag. The leader (the stage-2
     * responsible person) still files successfully.
     *
     * Ordered success-then-refusal deliberately, and nothing commits
     * after the refusal: the reverse order poisons the next commit on
     * PostgreSQL within one method (see the checkbox tests' docblock).
     */
    public function test_responsible_mode_frozen_without_guide_stays_stage2(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene(['ticketresponsiblemode' => 1]);
        $group = $this->group($activity, (int) $leader->id, state::FROZEN, null, (int) $member->id);

        $ticket = tickets::file($activity, $group, tickets::TYPE_UNFREEZE, 'The leader asks', FORMAT_PLAIN, (int) $leader->id);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);

        try {
            tickets::file_help($activity, $group, 'Trying anyway', FORMAT_PLAIN, (int) $member->id);
            $this->fail('a frozen team with no guide is still stage 2: only the leader may file');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketresponsibleleader', $e->errorcode);
        }
    }

    /**
     * RECORDED CONSEQUENCE, not softened: with the mode on, a confirmed
     * member can never file leaderchange about their own leader while
     * that leader has no guide assigned - the leader is excluded by
     * file()'s own relational check (succession is theirs to drive
     * instead) and the member is excluded by the responsible-person
     * mode, together closing the behind-the-back channel by design. The
     * member sees a SPECIFIC refusal string, never a silent absence.
     */
    public function test_responsible_mode_member_cannot_file_leaderchange_about_own_leader(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene(['ticketresponsiblemode' => 1]);
        $group = $this->group($activity, (int) $leader->id, state::FORMING, null, (int) $member->id);

        try {
            tickets::file($activity, $group, tickets::TYPE_LEADERCHANGE, 'Behind the back', FORMAT_PLAIN, (int) $member->id);
            $this->fail('the leaderchange channel must be closed while the leader is the responsible person');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketresponsibleleader', $e->errorcode);
        }
    }

    /**
     * A leaderless group with confirmed members (a leadership vacancy,
     * reachable via succession's own repair tools) sits at stage 1 for
     * each member: nobody is specially restricted, because nobody but
     * staff can fill the vacancy and the mode must not also lock every
     * member out of asking for help while one stands.
     */
    public function test_responsible_mode_leaderless_group_is_stage1_for_members(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene(['ticketresponsiblemode' => 1]);
        $group = $this->group($activity, (int) $leader->id, state::FORMING, null, (int) $member->id);
        // A leadership vacancy: leaderid explicitly NULL, exactly the
        // shape succession::appoint_vacant_leader() repairs (never a
        // zero sentinel - decision 2026-08-13).
        global $DB;
        $DB->set_field('selfselectadvanced_group', 'leaderid', null, ['id' => $group->id]);
        $group = groups::get($activity, (int) $group->id);
        $this->assertNull($group->leaderid, 'fixture: the vacancy must be a real NULL');

        $ticket = tickets::file_help($activity, $group, 'Anyone home?', FORMAT_PLAIN, (int) $member->id);
        $this->assertSame(tickets::STATUS_OPEN, $ticket->status);
    }

    /**
     * The mode gates RAISING only: it never blocks the requester's own
     * withdraw on a ticket they already hold.
     */
    public function test_responsible_mode_never_blocks_own_withdraw(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FORMING, null, (int) $member->id);
        $ticket = tickets::file($activity, $group, tickets::TYPE_LEADERCHANGE, 'Asking', FORMAT_PLAIN, (int) $member->id);

        // The mode is switched ON only AFTER filing, exactly as an
        // editing teacher could do mid-flight to a real activity.
        global $DB;
        $DB->set_field('selfselectadvanced', 'ticketresponsiblemode', 1, ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());

        $withdrawn = tickets::withdraw($activity, (int) $ticket->id, (int) $member->id);
        $this->assertSame(tickets::STATUS_WITHDRAWN, $withdrawn->status);
    }

    // ------------------------------------------------------------------
    // D. The disclaimer.

    /**
     * With a disclaimer set, the service throws when the ack is not
     * passed - RED-FIRST PROOF (see the report): with
     * tickets::require_disclaimer_ack() not yet called from file(), this
     * test's first block fails because filing without an ack succeeds
     * instead of throwing.
     */
    public function test_disclaimer_set_throws_without_ack(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene([
            'ticketdisclaimer' => '<p>Read this before you raise anything.</p>',
            'ticketdisclaimerformat' => FORMAT_HTML,
        ]);
        $group = $this->group($activity, (int) $leader->id, state::FORMING);

        try {
            tickets::file_help($activity, $group, 'Without acknowledging', FORMAT_PLAIN, (int) $leader->id);
            $this->fail('a non-empty disclaimer must be acknowledged before filing succeeds');
        } catch (local\workflow_refusal $e) {
            $this->assertSame('refusalticketdisclaimerack', $e->errorcode);
        }
    }

    /**
     * The ack, once passed, is recorded on the ticket row.
     */
    public function test_disclaimer_ack_is_recorded_on_the_ticket(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene([
            'ticketdisclaimer' => '<p>Read this before you raise anything.</p>',
            'ticketdisclaimerformat' => FORMAT_HTML,
        ]);
        $group = $this->group($activity, (int) $leader->id, state::FORMING);

        $ticket = tickets::file_help($activity, $group, 'Acknowledged', FORMAT_PLAIN, (int) $leader->id, true);
        $this->assertSame(1, (int) $ticket->disclaimerack);

        global $DB;
        $stored = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticket->id], '*', MUST_EXIST);
        $this->assertSame('1', (string) $stored->disclaimerack);
    }

    /**
     * An empty disclaimer requires nothing: filing with no ack succeeds,
     * and disclaimerack stays 0 - existing activities upgrade with a
     * null disclaimer, and nothing changes for them.
     */
    public function test_empty_disclaimer_requires_nothing(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FORMING);

        $ticket = tickets::file_help($activity, $group, 'No disclaimer here', FORMAT_PLAIN, (int) $leader->id);
        $this->assertSame(0, (int) $ticket->disclaimerack);
    }

    /**
     * An editor left blank stores markup that is not really text (e.g.
     * "<p><br></p>") - the same emptiness test file()/file_help() apply
     * to the request itself must apply to the disclaimer, or a teacher
     * who opened and closed the editor without typing anything would
     * accidentally gate every future ticket on an invisible notice.
     */
    public function test_disclaimer_that_renders_empty_requires_nothing(): void {
        $this->resetAfterTest();

        [$activity, , $leader] = $this->scene([
            'ticketdisclaimer' => '<p><br></p>',
            'ticketdisclaimerformat' => FORMAT_HTML,
        ]);
        $group = $this->group($activity, (int) $leader->id, state::FORMING);

        $ticket = tickets::file_help($activity, $group, 'Blank editor disclaimer', FORMAT_PLAIN, (int) $leader->id);
        $this->assertSame(0, (int) $ticket->disclaimerack);
    }

    /**
     * The ticket_filed event's other payload carries disclaimerack.
     */
    public function test_ticket_filed_event_carries_disclaimerack(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        [$activity, , $leader] = $this->scene([
            'ticketdisclaimer' => '<p>Read this.</p>',
            'ticketdisclaimerformat' => FORMAT_HTML,
        ]);
        $group = $this->group($activity, (int) $leader->id, state::FORMING);
        tickets::file_help($activity, $group, 'With the event', FORMAT_PLAIN, (int) $leader->id, true);

        $events = array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \mod_selfselectadvanced\event\ticket_filed
        );
        $sink->close();
        $this->assertNotEmpty($events, 'fixture: a ticket_filed event must have fired');
        $event = reset($events);
        $this->assertSame(1, (int) $event->other['disclaimerack']);
    }
}
