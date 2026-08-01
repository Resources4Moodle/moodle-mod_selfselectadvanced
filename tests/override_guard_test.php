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
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;

/**
 * Guarded reductions (2026-07-24): a cap reduced below the target's
 * current position parks as 'pending' (invisible to the resolver, so
 * nobody is stranded over a live limit), lists its blockers, and
 * activates automatically once the excess is cleared and re-checked.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\override\guard
 * @covers     \mod_selfselectadvanced\local\override\store
 */
final class override_guard_test extends \advanced_testcase {
    /**
     * User-scope maxlead reduction below the current led count goes
     * pending with a described blocker; clearing the excess and
     * re-checking activates it; a clean reduction applies immediately.
     */
    public function test_reduction_guard_lifecycle(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxlead' => 3,
            'maxmembership' => 4,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        // The user currently leads TWO groups.
        $g1 = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'One',
            'state' => state::FORMING,
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Two',
            'state' => state::FORMING,
        ]);

        // Reduce maxlead to 1: below the current 2 → pending.
        $record = store::save($activity, 'user', (int) $leader->id, ['maxlead' => 1], 2);
        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $this->assertStringContainsString('leading 2', $record->blockers[0]->description);
        $this->assertStringContainsString('cap of 1', $record->blockers[0]->description);

        // The resolver must NOT see the pending value.
        $resolver = new resolver($activity);
        $this->assertSame(3, $resolver->effective_maxlead((int) $leader->id)->value);

        // Still blocked on re-check.
        $this->assertCount(1, store::recheck_pending($activity, 2));

        // Clear the excess: hand group One to another leader.
        $other = $generator->create_user();
        $generator->enrol_user($other->id, $course->id, 'student');
        $plugingen->create_member([
            'groupid' => $g1->id,
            'userid' => (int) $other->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $DB->set_field('selfselectadvanced_group', 'leaderid', (int) $other->id, ['id' => $g1->id]);

        // Re-check: blocker gone → override activates and resolves.
        $this->assertSame([], store::recheck_pending($activity, 2));
        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $record->id])
        );
        $resolver = new resolver($activity);
        $this->assertSame(1, $resolver->effective_maxlead((int) $leader->id)->value);

        // A reduction that nobody exceeds applies immediately.
        $record = store::save($activity, 'user', (int) $other->id, ['maxlead' => 1], 2);
        $this->assertSame('active', $record->status);
        $this->assertSame([], $record->blockers);
    }

    /**
     * Group-scope maxsize reduction counts reserved seats (confirmed +
     * invited) and parks below them.
     */
    public function test_group_maxsize_guard(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxsize' => 6,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[$i] = $generator->create_user();
            $generator->enrol_user($users[$i]->id, $course->id, 'student');
        }
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $users[0]->id,
            'name' => 'Full',
            'state' => state::FORMING,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users[2]->id,
            'status' => groups::STATUS_INVITED,
        ]);

        // 2 confirmed + 1 reserved = 3 seats; reducing max to 2 parks.
        $record = store::save($activity, 'group', (int) $group->id, ['maxsize' => 2], 2);
        $this->assertSame('pending', $record->status);
        $this->assertStringContainsString('occupies 3 seats', $record->blockers[0]->description);
    }
    /**
     * 1.5.0 guide overrides: an explicit maxguided 0 resolves as a
     * real always-full cap, and guidehidden removes the guide from
     * the load list entirely.
     */
    public function test_guide_zero_and_hidden(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxguided' => 5,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $guide1 = $generator->create_user();
        $guide2 = $generator->create_user();
        foreach ([$guide1, $guide2] as $guide) {
            $generator->enrol_user($guide->id, $course->id, 'teacher');
        }

        store::save($activity, 'guide', (int) $guide1->id, ['maxguided' => 0], 2);
        store::save($activity, 'guide', (int) $guide2->id, ['guidehidden' => 1], 2);

        $resolver = new resolver($activity);
        $this->assertSame(0, $resolver->effective_maxguided((int) $guide1->id)->value);
        $this->assertTrue($resolver->is_guide_hidden((int) $guide2->id));
        $this->assertFalse($resolver->is_guide_hidden((int) $guide1->id));

        $loads = \mod_selfselectadvanced\local\guides::with_load($activity, $resolver);
        $this->assertArrayHasKey((int) $guide1->id, $loads);
        $this->assertSame(0, $loads[(int) $guide1->id]->remaining);
        $this->assertArrayNotHasKey((int) $guide2->id, $loads);
    }

    /**
     * D6-11: the conflict-of-interest guard had branches for user,
     * guide and group and simply RETURNED for scope 'move' - the one
     * scope that moves rosters. Latent while only :manage holders could
     * reach moveedit.php, armed the moment override authority reaches
     * anyone coordinate-shaped.
     */
    public function test_coordinator_coi_move_scope(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $modcontext = $activity->context();

        $mk = function (string $role) use ($generator, $course) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, $role);

            return $user;
        };
        $sourcelead = $mk('student');
        $targetlead = $mk('student');
        $mover = $mk('student');
        $manager = $mk('editingteacher');
        $coordinator = $mk('student');
        role_assign(\mod_selfselectadvanced\local\coordinatorrole::ensure(), $coordinator->id, $modcontext);
        accesslib_clear_all_caches_for_unit_testing();

        $source = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $sourcelead->id,
            'name' => 'Source',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => $source->id,
            'userid' => (int) $mover->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $target = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $targetlead->id,
            'name' => 'Target',
            'state' => state::FIRM,
        ]);

        $api = new \mod_selfselectadvanced\local\api($activity);
        $stage = fn() => $api->moves()->stage(
            (int) $mover->id,
            (int) $source->id,
            (int) $target->id,
            false,
            null,
            (int) $manager->id
        );

        // Case A: a confirmed member of the TARGET team may not grant it.
        $plugingen->create_member([
            'groupid' => $target->id,
            'userid' => (int) $coordinator->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $move = $stage();
        $this->assert_move_refused(
            'refusalcoiinvolved',
            fn() => store::save(
                $activity,
                'move',
                (int) $move->id,
                ['rulesbypassed' => 'L2'],
                (int) $coordinator->id
            )
        );
        // The manager exemption is intact.
        $saved = store::save($activity, 'move', (int) $move->id, ['rulesbypassed' => 'L2'], (int) $manager->id);
        $this->assertNotEmpty($saved->id);

        // Case B: the assigned guide of the SOURCE team may not grant it.
        $guidecoordinator = $mk('teacher');
        role_assign(
            \mod_selfselectadvanced\local\coordinatorrole::ensure(),
            $guidecoordinator->id,
            $modcontext
        );
        accesslib_clear_all_caches_for_unit_testing();
        global $DB;
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guidecoordinator->id, ['id' => $source->id]);
        $move2 = $stage();
        $this->assert_move_refused(
            'refusalcoiinvolved',
            fn() => store::save(
                $activity,
                'move',
                (int) $move2->id,
                ['rulesbypassed' => 'L2'],
                (int) $guidecoordinator->id
            )
        );

        // Case C: never for oneself.
        $selfcoordinator = $mk('student');
        role_assign(
            \mod_selfselectadvanced\local\coordinatorrole::ensure(),
            $selfcoordinator->id,
            $modcontext
        );
        accesslib_clear_all_caches_for_unit_testing();
        $selfmove = $api->moves()->stage(
            (int) $selfcoordinator->id,
            null,
            (int) $target->id,
            false,
            null,
            (int) $manager->id
        );
        $this->assert_move_refused(
            'refusalcoiself',
            fn() => store::save(
                $activity,
                'move',
                (int) $selfmove->id,
                ['rulesbypassed' => 'L2'],
                (int) $selfcoordinator->id
            )
        );
        // The manager passes all three (exemption regression).
        $this->assertNotEmpty(store::save(
            $activity,
            'move',
            (int) $selfmove->id,
            ['rulesbypassed' => 'L2'],
            (int) $manager->id
        )->id);
    }

    /**
     * Expect one refusal string key from a callable.
     *
     * @param string $stringkey the expected errorcode
     * @param callable $fn the action
     */
    private function assert_move_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }
}
