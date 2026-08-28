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

use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\throttle;
use mod_selfselectadvanced\local\tickets;

/**
 * 1.20.60: the selfselectadvanced_ticketthrottle table.
 *
 * The same discipline ticket_feedback_upgrade_test.php's docblock
 * states: fresh-install-only coverage hides upgrade defects, so this
 * file drives the actual UPGRADE function on a site whose ticket table
 * already has rows, after DROPPING the table the upgrade is meant to
 * create. The PHPUnit site is installed fresh from db/install.xml, which
 * already declares the table - dropping it here is what actually reaches
 * "a site that has never had this table", not merely an empty one.
 *
 * What is asserted beyond "it ran": the UNIQUE index on
 * (activityid, userid). It is the whole reason "one limit per person per
 * activity" is a fact rather than a promise - throttle::set() upserts on
 * it - and an upgrade that created the table without it would leave
 * upgraded sites able to hold two contradictory limits for one person
 * while fresh installs could not.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class ticket_throttle_upgrade_test extends \advanced_testcase {
    /**
     * Put the site back to its pre-1.20.60 shape: no throttle table at
     * all.
     */
    private function drop_the_table_to_simulate_the_pre_1_20_60_shape(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('selfselectadvanced_ticketthrottle');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $this->assertFalse(
            $dbman->table_exists(new \xmldb_table('selfselectadvanced_ticketthrottle')),
            'the fixture must actually have removed the table, or every test below proves nothing'
        );
    }

    /**
     * A course, an activity, a requester with a firm group, a staff
     * member, and one real ticket - so the upgrade runs on a POPULATED
     * site rather than an empty one.
     *
     * @return array [activity, leader, staff]
     */
    private function scene_with_a_ticket(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'THR1']);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Legacy Team',
            'state' => state::FIRM,
        ]);
        tickets::file_help($activity, $group, 'Please help.', FORMAT_PLAIN, (int) $leader->id);

        return [$activity, $leader, $staff];
    }

    /**
     * The step creates the table on a populated site, and the service
     * works against what it created - not merely against install.xml.
     */
    public function test_the_step_creates_a_working_table_on_a_populated_site(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        $this->redirectMessages();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity, $leader, $staff] = $this->scene_with_a_ticket();
        $this->drop_the_table_to_simulate_the_pre_1_20_60_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_throttle($DB, $dbman);

        $this->assertTrue($dbman->table_exists(new \xmldb_table('selfselectadvanced_ticketthrottle')));

        // The service, end to end, on the upgraded table.
        $row = throttle::set($activity, (int) $leader->id, 2, 24, null, 'Two a day.', (int) $staff->id);
        $this->assertSame(2, (int) $row->maxtickets);
        $this->assertNotNull(throttle::get($activity, (int) $leader->id));
    }

    /**
     * Nobody is limited by the upgrade itself. A rate limit applied
     * silently to every existing user by an upgrade would be the worst
     * possible reading of this feature.
     */
    public function test_the_upgrade_limits_nobody(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        $this->redirectMessages();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity, $leader] = $this->scene_with_a_ticket();
        $this->drop_the_table_to_simulate_the_pre_1_20_60_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_throttle($DB, $dbman);

        $this->assertSame(0, $DB->count_records('selfselectadvanced_ticketthrottle'));
        $this->assertNull(throttle::get($activity, (int) $leader->id));
        // And filing still works, because nothing limits it.
        throttle::require_within($activity, (int) $leader->id);
    }

    /**
     * The UNIQUE index on (activityid, userid) exists after the upgrade,
     * asserted against the live schema rather than inferred from
     * behaviour: a second insert for the same pair must be impossible at
     * the database level, not merely avoided by the service.
     */
    public function test_the_unique_index_exists_after_the_upgrade(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        // The second insert below is MEANT to fail, and on PostgreSQL a
        // failed statement poisons the enclosing transaction - which,
        // under the default reset-by-rollback, is the one wrapping this
        // whole test. Every assertion after the failure would then die
        // with "current transaction is aborted" instead of reporting
        // what it found. Resetting by truncation instead is what lets a
        // test deliberately provoke a database error and then look at
        // the damage.
        $this->preventResetByRollback();
        $this->redirectMessages();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity, $leader, $staff] = $this->scene_with_a_ticket();
        $this->drop_the_table_to_simulate_the_pre_1_20_60_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_throttle($DB, $dbman);

        $index = new \xmldb_index('activity_user', XMLDB_INDEX_UNIQUE, ['activityid', 'userid']);
        $this->assertTrue(
            $dbman->index_exists(new \xmldb_table('selfselectadvanced_ticketthrottle'), $index),
            'without the unique index, two contradictory limits can exist for one person'
        );

        // Proven at the database, by trying it: a raw second insert for
        // the same pair must be refused.
        throttle::set($activity, (int) $leader->id, 1, 24, null, 'One a day.', (int) $staff->id);
        $now = time();
        try {
            $DB->insert_record('selfselectadvanced_ticketthrottle', (object) [
                'activityid' => $activity->id(),
                'userid' => (int) $leader->id,
                'maxtickets' => 9,
                'windowhours' => 1,
                'nextallowed' => null,
                'reason' => 'A second, contradictory limit.',
                'setby' => (int) $staff->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $this->fail('a second limit for the same person in the same activity must be impossible');
        } catch (\dml_exception $e) {
            $this->assertSame(1, $DB->count_records('selfselectadvanced_ticketthrottle'));
        }
    }

    /**
     * Idempotency: the table_exists() guard means running the step twice
     * neither errors nor disturbs a row already stored - the same
     * discipline every other step in db/upgrade.php keeps.
     */
    public function test_running_the_step_twice_is_harmless(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        $this->redirectMessages();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity, $leader, $staff] = $this->scene_with_a_ticket();
        $this->drop_the_table_to_simulate_the_pre_1_20_60_shape();

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_add_ticket_throttle($DB, $dbman);
        throttle::set($activity, (int) $leader->id, 5, 12, null, 'Five every twelve hours.', (int) $staff->id);

        selfselectadvanced_upgrade_add_ticket_throttle($DB, $dbman);

        $stored = throttle::get($activity, (int) $leader->id);
        $this->assertNotNull($stored, 'a second run must not drop the table it already created');
        $this->assertSame(5, (int) $stored->maxtickets);
        $this->assertSame(12, (int) $stored->windowhours);
    }
}
