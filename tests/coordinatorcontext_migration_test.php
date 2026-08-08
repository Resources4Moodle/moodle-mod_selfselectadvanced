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

use mod_selfselectadvanced\local\coordinatorrole;

/**
 * Moving pre-1.20.0 coordinator appointments to the activities they
 * were always meant for.
 *
 * A course-context appointment reached every selfselectadvanced
 * instance in the course, because that is what capability inheritance
 * does. The upgrade fans each one out to every instance and retires the
 * course row, so an appointment can be taken away from one activity
 * without taking it away from the others - and a course with no
 * instance keeps its row, because there is nowhere to move it to.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\coordinatorrole
 */
final class coordinatorcontext_migration_test extends \advanced_testcase {
    /**
     * A site in the shape 1.19.x left: one course with two instances,
     * one course with none, and a course-context appointment in each.
     *
     * @return array [roleid, user, activity A1, activity A2, ctx of course 1, ctx of course 2]
     */
    private function legacy_site(): array {
        $generator = $this->getDataGenerator();
        $coursea = $generator->create_course(['shortname' => 'MIGA']);
        $courseb = $generator->create_course(['shortname' => 'MIGB']);
        $first = $generator->create_module('selfselectadvanced', ['course' => $coursea->id]);
        $second = $generator->create_module('selfselectadvanced', ['course' => $coursea->id]);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $coursea->id, 'teacher');
        $generator->enrol_user($user->id, $courseb->id, 'teacher');

        $roleid = coordinatorrole::ensure();
        $ctxa = \context_course::instance($coursea->id);
        $ctxb = \context_course::instance($courseb->id);
        role_assign($roleid, (int) $user->id, $ctxa->id);
        role_assign($roleid, (int) $user->id, $ctxb->id);

        return [
            $roleid,
            $user,
            activity::from_instance((int) $first->id),
            activity::from_instance((int) $second->id),
            $ctxa,
            $ctxb,
        ];
    }

    /**
     * Every instance in the course inherits the appointment, the course
     * row is retired, and a course with nothing to migrate into is left
     * exactly as it was.
     */
    public function test_migration_fans_out_to_every_instance(): void {
        global $DB;

        $this->resetAfterTest();
        [$roleid, $user, $activitya, $activityb, $ctxa, $ctxb] = $this->legacy_site();

        coordinatorrole::migrate_to_module_context();

        foreach ([$activitya, $activityb] as $activity) {
            $this->assertTrue($DB->record_exists('role_assignments', [
                'roleid' => $roleid,
                'contextid' => $activity->context()->id,
                'userid' => (int) $user->id,
                'component' => 'mod_selfselectadvanced',
                'itemid' => 0,
            ]), 'The appointment did not reach every instance of the course');
        }
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $ctxa->id,
            'userid' => (int) $user->id,
        ]), 'The course row survived the fan-out');

        // Nothing to migrate into means nothing to migrate.
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $ctxb->id,
            'userid' => (int) $user->id,
        ]), 'A course with no instance lost its appointment');

        accesslib_clear_all_caches_for_unit_testing();
        foreach ([$activitya, $activityb] as $activity) {
            $this->assertTrue(has_capability(
                'mod/selfselectadvanced:coordinate',
                $activity->context(),
                (int) $user->id
            ));
        }
    }

    /**
     * Running it twice changes nothing the second time, and a site
     * where a foreign role blocked us - so this plugin never created a
     * role at all - is left completely alone.
     */
    public function test_migration_is_idempotent_and_collision_safe(): void {
        global $DB;

        $this->resetAfterTest();
        [$roleid, $user, , , $ctxa] = $this->legacy_site();

        coordinatorrole::migrate_to_module_context();
        // Idempotence is only worth asserting once something happened:
        // a method that does nothing is trivially idempotent.
        $this->assertSame(
            2,
            $DB->count_records('role_assignments', [
                'roleid' => $roleid,
                'component' => 'mod_selfselectadvanced',
            ]),
            'the first run migrated nothing, so running it twice proves nothing'
        );
        $after = $DB->count_records('role_assignments', ['roleid' => $roleid]);
        coordinatorrole::migrate_to_module_context();
        $this->assertSame(
            $after,
            $DB->count_records('role_assignments', ['roleid' => $roleid]),
            'A second run duplicated rows'
        );

        // A collision site has no recorded role of its own. Put a fresh
        // legacy row back and prove the method declines to touch it.
        role_assign($roleid, (int) $user->id, $ctxa->id);
        $total = $DB->count_records('role_assignments');
        unset_config(coordinatorrole::CONFIG_ROLEID, 'mod_selfselectadvanced');

        coordinatorrole::migrate_to_module_context();

        $this->assertSame($total, $DB->count_records('role_assignments'));
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $ctxa->id,
            'userid' => (int) $user->id,
        ]));
    }

    /**
     * The 2026073160 upgrade block really calls both halves.
     *
     * Every other test here calls the two methods directly, which says
     * nothing about whether the ladder invokes them. savepoint-tip
     * compares numbers only, and --reinit builds the phpunit site from
     * db/install.xml, so a block whose body was deleted - or a block
     * whose serial was re-used and therefore never runs - leaves this
     * suite entirely green while every real site keeps its
     * course-context appointments and a role it cannot assign at an
     * activity. This runs the LADDER against a legacy-shaped site.
     */
    public function test_the_upgrade_block_migrates_and_restores_assignability(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$roleid, $user, $activitya, $activityb, $ctxa] = $this->legacy_site();

        // A pre-1.20.0 site could not assign the role at an activity:
        // only ensure()'s create branch ever set the levels.
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        $this->assertNotContains(CONTEXT_MODULE, get_role_contextlevels($roleid));

        set_config('version', 2026073150, 'mod_selfselectadvanced');
        xmldb_selfselectadvanced_upgrade(2026073150);
        // The savepoint sets $CFG->upgraderunning, which a real
        // upgrade's own completion clears; leaving it set makes every
        // later call think a site upgrade is in progress.
        $CFG->upgraderunning = 0;
        accesslib_clear_all_caches_for_unit_testing();

        // The ladder TIP, not this test's own savepoint: every later
        // block runs too, so it moves with every version.php serial.
        $this->assertSame('2026080806', get_config('mod_selfselectadvanced', 'version'));
        $this->assertContains(
            CONTEXT_MODULE,
            array_map('intval', array_values(get_role_contextlevels($roleid))),
            'the upgrade block did not call ensure()'
        );
        foreach ([$activitya, $activityb] as $activity) {
            $this->assertTrue($DB->record_exists('role_assignments', [
                'roleid' => $roleid,
                'contextid' => $activity->context()->id,
                'userid' => (int) $user->id,
                'component' => 'mod_selfselectadvanced',
                'itemid' => 0,
            ]), 'the upgrade block did not call migrate_to_module_context()');
            $this->assertTrue(has_capability(
                'mod/selfselectadvanced:coordinate',
                $activity->context(),
                (int) $user->id
            ));
        }
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $ctxa->id,
            'userid' => (int) $user->id,
        ]), 'the course row survived the upgrade');
    }

    /**
     * The role must be assignable at an activity on every branch of
     * ensure(), not only on the one that creates it - and, since 1.20.1
     * (maintainer decision), at an activity and NOWHERE ELSE.
     *
     * This assertion was INVERTED by T-19: until 1.20.1 ensure() only
     * ever ADDED CONTEXT_MODULE, so a course level an administrator or
     * an old install left behind survived and carried :viewall,
     * :overriderules, :managecomposition and :assignguide into every
     * instance in the course at once. The role does work inside one
     * activity, so that is the only shape it is offered in.
     */
    public function test_ensure_makes_the_role_activity_assignable_only(): void {
        $this->resetAfterTest();

        $roleid = coordinatorrole::ensure();
        $this->assertGreaterThan(0, $roleid);

        // The state a pre-1.20.0 site, or an administrator, can be in.
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        $this->assertNotContains(CONTEXT_MODULE, get_role_contextlevels($roleid));

        $this->assertSame($roleid, coordinatorrole::ensure());

        $levels = array_map('intval', array_values(get_role_contextlevels($roleid)));
        $this->assertSame(
            [CONTEXT_MODULE],
            $levels,
            'ensure() must leave the role assignable at activity context and nowhere else'
        );
    }
}
