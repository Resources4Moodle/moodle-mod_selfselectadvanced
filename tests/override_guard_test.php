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
}
