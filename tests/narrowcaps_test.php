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

use mod_selfselectadvanced\external\search_guides;
use mod_selfselectadvanced\external\search_participants;
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\autogroup\engine;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\notifier;
use mod_selfselectadvanced\local\state;

/**
 * The two narrow capabilities 1.20.0 carves out of :manage -
 * :managecomposition (stage, commit and cancel student moves) and
 * :assignguide (assign a team's guide, decide expressions of interest)
 * - the conflict-of-interest guard that restrains them, the operational
 * notifications their holders now receive, and the email-oracle guard
 * on the participant search their arrival widened.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\coordinatorrole
 * @covers     \mod_selfselectadvanced\local\moves
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\eoi
 * @covers     \mod_selfselectadvanced\local\notifier
 * @covers     \mod_selfselectadvanced\local\freeze
 * @covers     \mod_selfselectadvanced\local\autogroup\engine
 * @covers     \mod_selfselectadvanced\external\search_participants
 * @covers     \mod_selfselectadvanced\external\search_guides
 */
final class narrowcaps_test extends \advanced_testcase {
    /**
     * Course, activity, five students and two firm teams A and B, each
     * with a leader and one other confirmed member.
     *
     * @param array $settings instance setting overrides
     * @return array{0: activity, 1: api, 2: \stdClass[], 3: \stdClass, 4: \stdClass, 5: \stdClass}
     *         activity, api, students, group A, group B, course
     */
    private function setup_two_groups(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }

        $a = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Alpha',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => $a->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $b = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'Bravo',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => $b->id,
            'userid' => (int) $students[3]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            new api($activity),
            $students,
            groups::get($activity, (int) $a->id),
            groups::get($activity, (int) $b->id),
            $course,
        ];
    }

    /**
     * Grant one capability to one user in one context, through a role
     * of its own, so the user holds THAT and nothing else.
     *
     * @param string $capability the capability name
     * @param int $userid who gets it
     * @param \context $ctx where
     * @return int the role id created
     */
    private function grant(string $capability, int $userid, \context $ctx): int {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, $ctx->id, true);
        role_assign($roleid, $userid, $ctx->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $roleid;
    }

    /**
     * A staff user enrolled as a non-editing teacher holding exactly
     * one plugin capability in the activity context.
     *
     * @param activity $activity the activity
     * @param \stdClass $course the course
     * @param string $capability the single capability to grant
     * @return \stdClass the user
     */
    private function narrow_staff(activity $activity, \stdClass $course, string $capability): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'teacher');
        $this->grant($capability, (int) $user->id, $activity->context());

        return $user;
    }

    /**
     * An editing teacher: the unrestricted comparison actor.
     *
     * @param \stdClass $course the course
     * @return \stdClass the user
     */
    private function manager(\stdClass $course): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');

        return $user;
    }

    /**
     * Assert a callable refuses with a given error code.
     *
     * @param string $errorcode the expected moodle_exception code
     * @param callable $callback what should refuse
     */
    private function assert_refused(string $errorcode, callable $callback): void {
        try {
            $callback();
            $this->fail('Expected a moodle_exception with error code ' . $errorcode);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The three capabilities this release adds to the coordinator role.
     *
     * @var string[]
     */
    private const NEWCAPS = [
        'mod/selfselectadvanced:managecomposition',
        'mod/selfselectadvanced:assignguide',
        'mod/selfselectadvanced:overriderules',
    ];

    /**
     * Put the site back to how it looked before this release: the role
     * exists, and carries none of the three capabilities.
     *
     * WITHOUT THIS the assertions below cannot fail. The PHPUnit site
     * is INSTALLED, and db/install.php already ran ensure() with the
     * current capabilities() list, so the grants are in the initial
     * state resetAfterTest() restores - and a test that then calls
     * ensure() and asserts them is asserting the install, not the code
     * under test. Measured: with the three names deleted from
     * capabilities() this test still passed.
     *
     * @return int the coordinator role id
     */
    private function role_without_the_new_capabilities(): int {
        $roleid = coordinatorrole::ensure();
        $syscontextid = \context_system::instance()->id;
        foreach (self::NEWCAPS as $capability) {
            unassign_capability($capability, $roleid, $syscontextid);
        }
        accesslib_clear_all_caches_for_unit_testing();

        return $roleid;
    }

    /**
     * (1) The Group Coordinator role carries the two new narrow powers
     * - and :overriderules, which maintainer decision 14 allows it to
     * hold BECAUSE every appointment now lives at CONTEXT_MODULE. It
     * still does not carry :manage: the whole point is a slice, not the
     * whole cake.
     */
    public function test_coordinator_role_gains_new_capabilities(): void {
        $this->resetAfterTest();

        [$activity, , , , , $course] = $this->setup_two_groups();
        $roleid = $this->role_without_the_new_capabilities();
        $this->assertGreaterThan(0, $roleid);

        $coordinator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($coordinator->id, $course->id, 'teacher');
        // At the ACTIVITY's context, which is where T-05 made every
        // appointment this plugin writes live.
        role_assign($roleid, (int) $coordinator->id, $activity->context()->id, 'mod_selfselectadvanced');
        accesslib_clear_all_caches_for_unit_testing();

        $context = $activity->context();
        foreach (self::NEWCAPS as $capability) {
            $this->assertFalse(
                has_capability($capability, $context, (int) $coordinator->id),
                'the pre-release state was not reached, so this test could not fail'
            );
        }

        // THE MEASUREMENT: this call, and nothing else, is what grants.
        coordinatorrole::ensure();
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:managecomposition',
            $context,
            (int) $coordinator->id
        ));
        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:assignguide',
            $context,
            (int) $coordinator->id
        ));
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:overriderules', $context, (int) $coordinator->id),
            'decision 14: a coordinator may hold the staff override hatch where they are appointed'
        );
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:manage', $context, (int) $coordinator->id),
            'the narrow capabilities must not smuggle the full manage power in with them'
        );
    }

    /**
     * (1b) Decision 14's other half, asserted rather than assumed: the
     * capability is only ever HELD where somebody was appointed. A
     * second activity in the same course, with no appointment of its
     * own, grants the same person nothing - which is what makes
     * :overriderules defensible on this role at all.
     */
    public function test_new_capabilities_do_not_leak_to_a_sibling_activity(): void {
        $this->resetAfterTest();

        [$activity, , , , , $course] = $this->setup_two_groups();
        $sibling = activity::from_instance((int) $this->getDataGenerator()->create_module(
            'selfselectadvanced',
            ['course' => $course->id]
        )->id);

        $roleid = $this->role_without_the_new_capabilities();
        $coordinator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign($roleid, (int) $coordinator->id, $activity->context()->id, 'mod_selfselectadvanced');
        coordinatorrole::ensure();
        accesslib_clear_all_caches_for_unit_testing();

        foreach (self::NEWCAPS as $capability) {
            $this->assertTrue(has_capability($capability, $activity->context(), (int) $coordinator->id));
            $this->assertFalse(
                has_capability($capability, $sibling->context(), (int) $coordinator->id),
                $capability . ' reached an activity nobody appointed this coordinator in'
            );
        }
    }

    /**
     * (2) ensure() tops the role up, it does not overrule an
     * administrator. A recorded CAP_PREVENT survives a second run -
     * assign_capability() with overwrite off is the contract, and an
     * upgrade must never quietly restore a permission somebody took
     * away.
     */
    public function test_ensure_respects_admin_prevent(): void {
        global $DB;
        $this->resetAfterTest();

        $roleid = $this->role_without_the_new_capabilities();
        $syscontextid = \context_system::instance()->id;
        assign_capability(
            'mod/selfselectadvanced:managecomposition',
            CAP_PREVENT,
            $roleid,
            $syscontextid,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        coordinatorrole::ensure();

        $this->assertSame(
            (string) CAP_PREVENT,
            (string) $DB->get_field('role_capabilities', 'permission', [
                'roleid' => $roleid,
                'contextid' => $syscontextid,
                'capability' => 'mod/selfselectadvanced:managecomposition',
            ]),
            'ensure() overruled an administrator'
        );

        [$activity, , , , , $course] = $this->setup_two_groups();
        $coordinator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign($roleid, (int) $coordinator->id, $activity->context()->id, 'mod_selfselectadvanced');
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(has_capability(
            'mod/selfselectadvanced:managecomposition',
            $activity->context(),
            (int) $coordinator->id
        ));
        // The prevent is per capability, not per role: the sibling
        // grant is untouched.
        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:assignguide',
            $activity->context(),
            (int) $coordinator->id
        ));
    }

    /**
     * (3) Conflict of interest at the STAGING seam. A narrow-authority
     * actor who guides Alpha may not stage a move out of it, nor into
     * it - and the same move by a :manage holder is fine, so the guard
     * restrains the new authority rather than breaking the seam.
     */
    public function test_stage_refused_for_involved_narrow_actor(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $students, $a, $b, $course] = $this->setup_two_groups();
        $actor = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $actor->id, ['id' => $a->id]);

        // Out of the team they guide.
        $this->assert_refused('refusalcoiinvolved', fn() => $api->moves()->stage(
            (int) $students[1]->id,
            (int) $a->id,
            (int) $b->id,
            false,
            null,
            (int) $actor->id
        ));
        // And into it: both sides are probed.
        $this->assert_refused('refusalcoiinvolved', fn() => $api->moves()->stage(
            (int) $students[3]->id,
            (int) $b->id,
            (int) $a->id,
            false,
            null,
            (int) $actor->id
        ));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_move', ['activityid' => $activity->id()]));

        // The identical move by a manager stages: the guard is about
        // the actor's authority, not about the move.
        $manager = $this->manager($course);
        $staged = $api->moves()->stage(
            (int) $students[1]->id,
            (int) $a->id,
            (int) $b->id,
            false,
            null,
            (int) $manager->id
        );
        $this->assertSame('pending', $staged->status);

        // And by an UNINVOLVED narrow holder: the power works without
        // :manage, which is the whole point of the capability.
        $uninvolved = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $second = $api->moves()->stage(
            (int) $students[3]->id,
            (int) $b->id,
            (int) $a->id,
            false,
            null,
            (int) $uninvolved->id
        );
        $this->assertSame('pending', $second->status);
    }

    /**
     * (4) Conflict of interest at the COMMIT seam, which is the one
     * that matters: staging and committing are separate acts and the
     * roster moves between them. The refusal leaves the row pending -
     * nothing half-applied - and an uninvolved narrow holder can then
     * commit the very same set.
     */
    public function test_commit_refused_for_involved_narrow_actor(): void {
        global $DB;
        $this->resetAfterTest();
        // Wave 3D: a refusal now rolls its OWN delegated transaction
        // back instead of abandoning it, which sets $DB's force_rollback
        // until the transaction stack empties. This test refuses a verb
        // and then commits another one, and on PostgreSQL - and only
        // there - advanced_testcase holds a frame underneath that never
        // lets the stack empty, so the later commit would be refused on
        // one engine and not the other. Committing the harness frame
        // here is what makes the two engines agree; the same line, for
        // the same reason, as in races_locking_test.
        $this->preventResetByRollback();

        [$activity, $api, $students, $a, $b, $course] = $this->setup_two_groups();
        $manager = $this->manager($course);
        $move = $api->moves()->stage(
            (int) $students[1]->id,
            (int) $a->id,
            (int) $b->id,
            false,
            null,
            (int) $manager->id
        );

        // The actor becomes involved AFTER the staging - exactly the
        // shape an in-lock re-read exists for.
        $actor = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $DB->set_field('selfselectadvanced_group', 'guidesuccessorid', (int) $actor->id, ['id' => $b->id]);

        $this->assert_refused(
            'refusalcoiinvolved',
            fn() => $api->moves()->commit_set([(int) $move->id], (int) $actor->id)
        );
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $move->id]),
            'a refused commit must leave the move exactly as it found it'
        );
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $a->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]));

        // An uninvolved narrow holder commits the same set.
        $uninvolved = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $sink = $this->redirectMessages();
        $this->assertSame(1, $api->moves()->commit_set([(int) $move->id], (int) $uninvolved->id));
        $sink->close();
        $this->assertSame(
            'committed',
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $move->id])
        );
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $b->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * (4b) The guard restrains the NEW authority and nothing that could
     * reach this engine before it. A student leader answering a join
     * request is a confirmed member of the team they are admitting
     * somebody to; an unscoped conflict-of-interest probe in the moves
     * engine would refuse every leader-accepted join request on the
     * site, because require_uninvolved() exempts only :manage holders.
     */
    public function test_leader_join_accept_is_not_caught_by_the_guard(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, , $students, $a, $b] = $this->setup_two_groups();
        // Join requests are answered on forming teams.
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $a->id]);
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $b->id]);
        $mover = (int) $students[3]->id;

        $request = joinrequests::request($activity, (int) $a->id, 'Closer to my work', $mover);
        $sink = $this->redirectMessages();
        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $a->leaderid);
        $sink->close();

        $this->assertTrue(
            $DB->record_exists('selfselectadvanced_member', [
                'groupid' => $a->id,
                'userid' => $mover,
                'status' => groups::STATUS_CONFIRMED,
            ]),
            'the leader could not admit a student to their own team'
        );
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $b->id,
            'userid' => $mover,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * (5) Conflict of interest on guide assignment: a narrow holder who
     * is already the team's successor guide may not pick its guide -
     * including picking themselves. An uninvolved holder can, without
     * :manage.
     */
    public function test_assign_guide_coi(): void {
        global $DB;
        $this->resetAfterTest();
        // Wave 3D: a refusal now rolls its OWN delegated transaction
        // back instead of abandoning it, which sets $DB's force_rollback
        // until the transaction stack empties. This test refuses a verb
        // and then commits another one, and on PostgreSQL - and only
        // there - advanced_testcase holds a frame underneath that never
        // lets the stack empty, so the later commit would be refused on
        // one engine and not the other. Committing the harness frame
        // here is what makes the two engines agree; the same line, for
        // the same reason, as in races_locking_test.
        $this->preventResetByRollback();

        [$activity, $api, $students, $a, , $course] = $this->setup_two_groups();
        $DB->set_field('selfselectadvanced_group', 'state', state::PENDING_GUIDE, ['id' => $a->id]);

        $actor = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:assignguide');
        $DB->set_field('selfselectadvanced_group', 'guidesuccessorid', (int) $actor->id, ['id' => $a->id]);

        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $course->id, 'teacher');

        $this->assert_refused('refusalcoiinvolved', fn() => $api->lifecycle()->assign_guide(
            groups::get($activity, (int) $a->id),
            (int) $guide->id,
            (int) $actor->id
        ));
        $this->assertNull($DB->get_field('selfselectadvanced_group', 'guideid', ['id' => $a->id]));

        $uninvolved = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:assignguide');
        $sink = $this->redirectMessages();
        $updated = $api->lifecycle()->assign_guide(
            groups::get($activity, (int) $a->id),
            (int) $guide->id,
            (int) $uninvolved->id
        );
        $sink->close();
        $this->assertEquals((int) $guide->id, (int) $updated->guideid);
        unset($students);
    }

    /**
     * (6) Deciding expressions of interest: :assignguide reaches it,
     * an actor with an interest of their OWN pending on the same team
     * is refused by name, and somebody with none of leader, manage or
     * assignguide is refused as before.
     */
    public function test_eoi_respond_via_assignguide(): void {
        global $DB;
        $this->resetAfterTest();
        // Wave 3D: a refusal now rolls its OWN delegated transaction
        // back instead of abandoning it, which sets $DB's force_rollback
        // until the transaction stack empties. This test refuses a verb
        // and then commits another one, and on PostgreSQL - and only
        // there - advanced_testcase holds a frame underneath that never
        // lets the stack empty, so the later commit would be refused on
        // one engine and not the other. Committing the harness frame
        // here is what makes the two engines agree; the same line, for
        // the same reason, as in races_locking_test.
        $this->preventResetByRollback();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'eoienabled' => 1,
            'minsize' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $wantedguide = $generator->create_user();
        $generator->enrol_user($wantedguide->id, $course->id, 'teacher');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Listed',
        ]);
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'timelisted', time(), ['id' => $group->id]);

        $actor = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:assignguide');
        $eoiid = eoi::express($activity, (int) $group->id, (int) $wantedguide->id, '', FORMAT_HTML);

        // A bystander with none of the three authorities.
        $bystander = $generator->create_user();
        $generator->enrol_user($bystander->id, $course->id, 'student');
        $this->assert_refused(
            'refusalnotleader',
            fn() => eoi::respond($activity, $eoiid, true, (int) $bystander->id)
        );

        // The actor has an interest of their own pending on the SAME
        // team: deciding the rival's is self-dealing.
        $ownid = eoi::express($activity, (int) $group->id, (int) $actor->id, '', FORMAT_HTML);
        $this->assert_refused(
            'refusaleoiselfaccept',
            fn() => eoi::respond($activity, $eoiid, true, (int) $actor->id)
        );
        $this->assert_refused(
            'refusaleoiselfaccept',
            fn() => eoi::respond($activity, $ownid, true, (int) $actor->id)
        );

        // Withdraw their own, and the same actor may now decide.
        $DB->set_field('selfselectadvanced_eoi', 'status', eoi::STATUS_WITHDRAWN, ['id' => $ownid]);
        $sink = $this->redirectMessages();
        eoi::respond($activity, $eoiid, true, (int) $actor->id);
        $sink->close();

        $this->assertSame(
            eoi::STATUS_ACCEPTED,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $eoiid])
        );
        $this->assertEquals(
            (int) $wantedguide->id,
            (int) $DB->get_field('selfselectadvanced_group', 'guideid', ['id' => $group->id])
        );
    }

    /**
     * (7) The move form's picker follows the pages it serves: a
     * :managecomposition-only actor can search, a :coordinate-only one
     * cannot.
     */
    public function test_search_participants_narrow_capability(): void {
        $this->resetAfterTest();

        [$activity, , $students, , , $course] = $this->setup_two_groups();
        $narrow = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $this->setUser($narrow);
        $results = search_participants::execute((int) $activity->cm()->id, $students[0]->lastname);
        $this->assertContains(
            (int) $students[0]->id,
            array_column($results, 'id'),
            'the narrow holder got a dead picker'
        );

        $coordinateonly = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:coordinate');
        $this->setUser($coordinateonly);
        $this->expectException(\required_capability_exception::class);
        search_participants::execute((int) $activity->cm()->id, $students[0]->lastname);
    }

    /**
     * (8) The picker is not an address book run backwards. Searching
     * the exact email of a student finds NOBODY - for the
     * :managecomposition-only actor it was first closed against, and
     * since MAINTAINER DECISION 24 (2026-08-02) for the :manage holder
     * who used to be the switch's own exempt viewer as well. Both can
     * still find that same student by name, so the guard costs them
     * nothing they are entitled to.
     */
    public function test_search_participants_email_oracle_closed(): void {
        $this->resetAfterTest();

        [$activity, , , , , $course] = $this->setup_two_groups();
        $known = $this->getDataGenerator()->create_user([
            'firstname' => 'Zenobia',
            'lastname' => 'Quill',
            'email' => 'zenobia.quill@example.invalid',
        ]);
        $this->getDataGenerator()->enrol_user($known->id, $course->id, 'student');

        $narrow = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $manager = $this->manager($course);

        $labels = [];
        foreach (['coordinator' => $narrow, 'manager' => $manager] as $who => $user) {
            $this->setUser($user);
            $this->assertSame(
                [],
                search_participants::execute((int) $activity->cm()->id, 'zenobia.quill@example.invalid'),
                "the endpoint confirmed an email address belongs to a named student, for a $who"
            );
            $byname = search_participants::execute((int) $activity->cm()->id, 'Zenobia');
            $this->assertSame([(int) $known->id], array_column($byname, 'id'), "the $who lost the picker");
            $labels = array_merge($labels, $byname);
        }

        // Whoever asks, no result ever carries an address or a phone
        // number (cardinal rule).
        foreach ($labels as $row) {
            $this->assertSame(['id', 'label'], array_keys($row));
            $this->assertStringNotContainsString('@', $row['label']);
        }
    }

    /**
     * (9) The guide-assignment queue notice reaches the people whose
     * job it is. A holder of :assignguide alone hears about a group
     * that submitted with no guide; somebody holding BOTH :manage and
     * :assignguide hears about it exactly once.
     */
    public function test_queue_notice_union_dedup(): void {
        $this->resetAfterTest();

        // Guide mode 1 (the manager assigns), so a leader may submit
        // without naming a guide - which is exactly the queue entry
        // this notice announces.
        [$activity, $api, $students, , , $course] = $this->setup_two_groups(['guidemode' => 1]);
        $assignonly = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:assignguide');
        // A dual holder: editing teacher (so :manage) plus an extra
        // role carrying the narrow capability.
        $dual = $this->manager($course);
        $this->grant('mod/selfselectadvanced:assignguide', (int) $dual->id, $activity->context());

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $solo = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[4]->id,
            'name' => 'Charlie',
        ]);

        $sink = $this->redirectMessages();
        $api->lifecycle()->submit(
            groups::get($activity, (int) $solo->id),
            null,
            (int) $students[4]->id
        );
        $messages = $sink->get_messages();
        $sink->close();

        $counts = [];
        foreach ($messages as $message) {
            if ($message->eventtype !== 'guidequeue') {
                continue;
            }
            $counts[(int) $message->useridto] = ($counts[(int) $message->useridto] ?? 0) + 1;
        }

        $this->assertArrayHasKey(
            (int) $assignonly->id,
            $counts,
            'the queue notice never reached the people who work the queue'
        );
        $this->assertSame(1, $counts[(int) $assignonly->id]);
        $this->assertSame(
            1,
            $counts[(int) $dual->id] ?? 0,
            'somebody holding both capabilities was told twice'
        );
    }

    /**
     * (10) The union helper itself: overlapping holders come back once,
     * keyed by id, in one bounded query per capability.
     */
    public function test_recipients_helper_bounded(): void {
        $this->resetAfterTest();

        [$activity, , , , , $course] = $this->setup_two_groups();
        $both = $this->manager($course);
        $this->grant('mod/selfselectadvanced:managecomposition', (int) $both->id, $activity->context());
        $narrowonly = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');

        $recipients = notifier::recipients($activity, [
            'mod/selfselectadvanced:manage',
            'mod/selfselectadvanced:managecomposition',
        ]);

        $this->assertArrayHasKey((int) $both->id, $recipients);
        $this->assertArrayHasKey((int) $narrowonly->id, $recipients);
        $this->assertSame(
            array_keys($recipients),
            array_unique(array_keys($recipients)),
            'the union returned somebody twice'
        );
        foreach ($recipients as $id => $user) {
            $this->assertSame($id, (int) $user->id);
        }
        $this->assertSame([], notifier::recipients($activity, []));
    }

    /**
     * (11) The membership-cap flag reaches the people whose repair it
     * is. Moving somebody out of an over-cap roster is composition
     * work, so a :managecomposition holder is told alongside the
     * managers, once - and the notice still carries names and counts
     * and no address (cardinal rule).
     */
    public function test_capaudit_notice_reaches_composition_holders(): void {
        $this->resetAfterTest();

        [$activity, , $students, $a, , $course] = $this->setup_two_groups();
        $narrow = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $dual = $this->manager($course);
        $this->grant('mod/selfselectadvanced:managecomposition', (int) $dual->id, $activity->context());

        $sink = $this->redirectMessages();
        freeze::flag_membership_audit($activity, $a, [(object) [
            'userid' => (int) $students[1]->id,
            'fullname' => fullname($students[1]),
            'current' => 2,
            'max' => 1,
        ]], (int) $students[0]->id);
        $messages = $sink->get_messages();
        $sink->close();

        $counts = [];
        foreach ($messages as $message) {
            if ($message->eventtype !== 'capaudit') {
                continue;
            }
            $counts[(int) $message->useridto] = ($counts[(int) $message->useridto] ?? 0) + 1;
            $this->assertStringNotContainsString(
                $students[1]->email,
                (string) $message->fullmessage,
                'a membership-cap notice carried an email address'
            );
        }

        $this->assertSame(
            1,
            $counts[(int) $narrow->id] ?? 0,
            'the cap flag never reached the people who repair it by moving somebody'
        );
        $this->assertSame(1, $counts[(int) $dual->id] ?? 0, 'a dual holder was told twice');
    }

    /**
     * (12) The auto-grouping run summary - whose whole point is the
     * UNPLACED students, repaired by moving people - reaches
     * :managecomposition holders too, once each.
     */
    public function test_autogroup_summary_reaches_composition_holders(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $now = time();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 3,
            'maxlead' => 1,
            'maxmembership' => 1,
            'autogroup' => 2,
            'timecutoff' => $now - 100,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        for ($i = 0; $i < 4; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
        }

        $narrow = $this->narrow_staff($activity, $course, 'mod/selfselectadvanced:managecomposition');
        $dual = $this->manager($course);
        $this->grant('mod/selfselectadvanced:managecomposition', (int) $dual->id, $activity->context());

        $sink = $this->redirectMessages();
        engine::run($activity, 0, 777);
        $messages = $sink->get_messages();
        $sink->close();

        $counts = [];
        foreach ($messages as $message) {
            $to = (int) $message->useridto;
            if ($to !== (int) $narrow->id && $to !== (int) $dual->id) {
                continue;
            }
            $counts[$to] = ($counts[$to] ?? 0) + 1;
        }

        $this->assertSame(
            1,
            $counts[(int) $narrow->id] ?? 0,
            'the run summary never reached the people who place the students it could not'
        );
        $this->assertSame(1, $counts[(int) $dual->id] ?? 0, 'a dual holder was told twice');
    }

    /**
     * (13) THE UPGRADE, from the serial immediately before this
     * release's coordinator work.
     *
     * ensure() calls assign_capability() for every name in
     * capabilities(), and assign_capability() raises a coding_exception
     * for a capability core has not registered yet - core registers
     * db/access.php in upgrade_component_updated(), AFTER
     * xmldb_selfselectadvanced_upgrade() returns. Adding
     * :managecomposition and :assignguide to that list therefore armed
     * every earlier block that calls ensure() without first calling
     * update_capabilities(). No rebuilt site can see it: --reinit
     * installs through db/install.php, which calls update_capabilities()
     * for exactly this reason, so PHPUnit, Behat and savepoint-tip all
     * stay green while a real site's upgrade dies outright.
     *
     * Measured, before the fix: starting at 2026073150 the whole site
     * upgrade died with "Capability
     * 'mod/selfselectadvanced:managecomposition' was not found".
     */
    public function test_upgrade_from_the_previous_serial_survives(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        $roleid = coordinatorrole::ensure();
        $this->assertGreaterThan(0, $roleid);

        // A site that has never run this release has never seen the two
        // capabilities: they are absent from {capabilities} exactly as
        // they are on any site at 2026073150.
        $newcaps = ['mod/selfselectadvanced:managecomposition', 'mod/selfselectadvanced:assignguide'];
        foreach ($newcaps as $capability) {
            $DB->delete_records('role_capabilities', ['capability' => $capability]);
            $DB->delete_records('capabilities', ['name' => $capability]);
        }
        \core_cache\cache::make('core', 'capabilities')->delete('core_capabilities');
        accesslib_clear_all_caches_for_unit_testing();
        foreach ($newcaps as $capability) {
            $this->assertFalse(
                $DB->record_exists('capabilities', ['name' => $capability]),
                'the pre-release state was not reached, so this test could not fail'
            );
        }

        set_config('version', 2026073150, 'mod_selfselectadvanced');
        xmldb_selfselectadvanced_upgrade(2026073150);
        // The savepoint sets $CFG->upgraderunning; a real upgrade's own
        // completion clears it.
        $CFG->upgraderunning = 0;
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame('2026080806', get_config('mod_selfselectadvanced', 'version'));
        foreach ($newcaps as $capability) {
            $this->assertTrue(
                $DB->record_exists('capabilities', ['name' => $capability]),
                $capability . ' was never registered by the upgrade'
            );
            $this->assertSame(
                (string) CAP_ALLOW,
                (string) $DB->get_field('role_capabilities', 'permission', [
                    'roleid' => $roleid,
                    'contextid' => \context_system::instance()->id,
                    'capability' => $capability,
                ]),
                $capability . ' did not reach the coordinator role on upgrade'
            );
        }
    }

    /**
     * (14) The guide picker answers the capability that reaches the
     * page it sits on.
     *
     * manage.php's assign and reassign tabs are the only guide
     * (re)assignment path, and their control is a select that starts
     * EMPTY and is filled entirely by search_guides. The actor here
     * holds :assignguide through a role with NO archetype, so nothing
     * else admits them - a user enrolled as a non-editing teacher would
     * pass on :guide alone and this test could not fail.
     */
    public function test_guide_picker_answers_an_assignguide_holder(): void {
        $this->resetAfterTest();

        [$activity, , , , , $course] = $this->setup_two_groups();
        $guide = $this->getDataGenerator()->create_user(['lastname' => 'Marchetti']);
        $this->getDataGenerator()->enrol_user($guide->id, $course->id, 'teacher');

        $actor = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'mod/selfselectadvanced:assignguide',
            CAP_ALLOW,
            $roleid,
            $activity->context()->id,
            true
        );
        $this->getDataGenerator()->enrol_user($actor->id, $course->id, $roleid);
        accesslib_clear_all_caches_for_unit_testing();

        $context = $activity->context();
        foreach (['respond', 'creategroup', 'lead', 'guide', 'manage', 'coordinate'] as $other) {
            $this->assertFalse(
                has_capability('mod/selfselectadvanced:' . $other, $context, (int) $actor->id),
                'the actor holds ' . $other . ' as well, so this test could not fail'
            );
        }

        $this->setUser($actor);
        $results = search_guides::execute((int) $activity->cm()->id, 'Marchetti', true);

        $this->assertContains(
            (int) $guide->id,
            array_column($results, 'id'),
            'the only guide-assignment capability there is opens a picker that answers nothing'
        );
    }

    /**
     * (15) Auto-grouping still demands the FULL manage power, asserted
     * against the EXECUTABLE source of the page that offers it.
     *
     * This one is a source invariant and says so. manage.php is a page
     * script: PHPUnit cannot execute it, and the refusal direction of a
     * capability gate is not assertable in Behat either, because Moodle
     * renders required_capability_exception through
     * core_renderer::fatal_error() and behat_session_trait's
     * look_for_exceptions() fails any step that lands on one. Between
     * them that leaves this line's removal invisible to every test in
     * the suite - measured.
     *
     * COMMENTS ARE STRIPPED FIRST, and that is the whole point of this
     * revision. Until 1.20.1 this test searched the RAW source, so it
     * caught a DELETED line and missed a COMMENTED-OUT one - and
     * commenting out is the edit a developer actually makes. Measured
     * 2026-08-03 (mutation M23): with
     *   // require_capability('mod/selfselectadvanced:manage', $context);
     * in the runautogroup branch, the previous version of this test
     * reported "Tests: 1 ... OK" on m5pg AND m5my, and this version
     * fails on both. The docblock it replaces claimed the test "goes
     * red the moment the line is removed or moved", which was true of
     * one edit and false of the other. code_without_comments() is the
     * same token_get_all() idiom contactreach_test and staffmessage_test
     * use, and for the same reason.
     *
     * WHAT THIS GATE IS FOR: the page gate at the top of manage.php is
     * a has_any_capability() that admits :assignguide as well as
     * :manage, so this narrow re-assertion is the only thing standing
     * between an :assignguide holder and a rewrite of the entire
     * roster.
     *
     * WHY IT IS STILL A SOURCE CHECK. The stronger fix is a seam -
     * autogroup\engine asking :manage itself, the way
     * quota\slots::require_manage() does, which a real test could then
     * drive. That change belongs to manage.php and
     * local\autogroup\engine, neither of which this wave owns, so it is
     * recorded here rather than smuggled in: a grep is a weak check,
     * but a weak check that cannot be satisfied by a comment is worth
     * more than a strong-sounding one that can.
     */
    public function test_autogrouping_still_demands_the_full_manage_power(): void {
        $source = self::code_without_comments(__DIR__ . '/../manage.php');

        $branch = strpos($source, "\$action === 'runautogroup'");
        $this->assertNotFalse($branch, 'the runautogroup branch has moved; re-anchor this test');
        $run = strpos($source, 'autogroup\engine::run(', $branch);
        $this->assertNotFalse($run, 'the runautogroup branch no longer runs the engine');

        $this->assertStringContainsString(
            "require_capability('mod/selfselectadvanced:manage', \$context);",
            substr($source, $branch, $run - $branch),
            'auto-grouping rewrites the whole roster: it must re-assert :manage before it runs, '
                . 'because the page gate above it also admits :assignguide'
        );
    }

    /**
     * A PHP file's EXECUTABLE source, with every comment removed.
     *
     * A guard rail that a comment can satisfy is not a guard rail. The
     * comments in this plugin's page scripts quote the very lines the
     * source checks look for - explaining why they are there - so a
     * search over the raw text answers "yes" to the explanation of a
     * rule as readily as to the rule.
     *
     * @param string $path absolute path to the file
     * @return string the source, comments stripped
     */
    private static function code_without_comments(string $path): string {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new \coding_exception('unreadable: ' . $path);
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
