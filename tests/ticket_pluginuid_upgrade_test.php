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

/**
 * 1.20.56 deliverable A, THE TRAP: a NOT NULL UNIQUE column added
 * straight onto a POPULATED selfselectadvanced_ticket fails on the very
 * first existing row unless every row is given a distinct value BEFORE
 * the unique index goes on. The gate's fresh-install check exercises the
 * empty path only (db/install.xml has no rows to violate), so this file
 * drives the actual UPGRADE path: legacy rows inserted at the pre-1.20.56
 * shape (no pluginuid column at all), then
 * selfselectadvanced_upgrade_ticket_pluginuid() - the exact function
 * db/upgrade.php's own PENDING_SERIAL-guarded step calls - run directly
 * against them.
 *
 * NOT run through xmldb_selfselectadvanced_upgrade() itself, deliberately:
 * that dispatcher's new step is guarded by the literal placeholder token
 * PENDING_SERIAL (the maintainer's own instruction - a real serial is not
 * this repo's to invent, and the savepoint-tip gate check requires the
 * final savepoint to equal version.php exactly), which is not a condition
 * PHP can evaluate. selfselectadvanced_upgrade_ticket_pluginuid() is
 * declared as its own standalone function in db/upgrade.php for exactly
 * this reason - callable directly, with the same body the guarded step
 * calls, so there is one implementation, not two that could drift.
 *
 * RED-FIRST (run 2026-08-20, PHPUnit on m5pg against this same tree, with
 * the real selfselectadvanced_upgrade_ticket_pluginuid() in db/upgrade.php
 * temporarily reordered - its add_index() call moved to right after
 * add_field(), before the backfill loop instead of after):
 *
 *   1) test_backfill_gives_every_existing_row_a_distinct_reference_then_the_index_goes_on:
 *      ddl_change_structure_exception: DDL sql execution error (ERROR:
 *      could not create unique index "phpu_selftick_plu_uix" DETAIL:
 *      Key (pluginuid)=(0) is duplicated. CREATE UNIQUE INDEX
 *      phpu_selftick_plu_uix ON phpu_selfselectadvanced_ticket (pluginuid))
 *   2) test_an_orphaned_ticket_still_gets_a_distinct_reference: the same
 *      ddl_change_structure_exception, same DETAIL.
 *   3) test_the_right_order_lets_the_index_go_on: the same
 *      ddl_change_structure_exception, same DETAIL.
 *
 *   Tests: 4, Assertions: 1, Errors: 3.
 *
 * (test_the_wrong_order_violates_uniqueness_on_a_populated_table itself
 * stayed green throughout - it reproduces the wrong order independently
 * of the production function, by design.) Reverting the mutation
 * restored a full pass (4 tests, 19 assertions) with no other change.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class ticket_pluginuid_upgrade_test extends \advanced_testcase {
    /**
     * A course, activity and one firm group - just enough for a ticket
     * row's own foreign keys (activityid, groupid, requestedby) to name
     * something real.
     *
     * @param string $shortname distinct per test that builds more than
     *        one world, so the course-derived segment of a backfilled
     *        reference differs too
     * @return array [activity, group, user]
     */
    private function world(string $shortname): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => $shortname]);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Legacy',
            'state' => state::FIRM,
            'timeapproved' => time(),
        ]);

        return [$activity, $group, $leader];
    }

    /**
     * Put selfselectadvanced_ticket back to its pre-1.20.56 shape: no
     * pluginuid column, no unique index. The PHPUnit site is installed
     * FRESH from the current db/install.xml (which already declares the
     * column), so this is what actually reaches "an existing row with no
     * pluginuid column at all" rather than merely emptying a value.
     */
    private function drop_pluginuid_to_simulate_the_pre_1_20_56_shape(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('selfselectadvanced_ticket');
        $index = new \xmldb_index('pluginuid', XMLDB_INDEX_UNIQUE, ['pluginuid']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new \xmldb_field('pluginuid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '0', 'activityid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
    }

    /**
     * Insert one ticket row shaped exactly like a row a pre-1.20.56 site
     * would have - no pluginuid key at all, because the column does not
     * exist on the table at the point this runs.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @param int $userid the requester
     * @return int the new row's id
     */
    private function insert_legacy_ticket(activity $activity, int $groupid, int $userid): int {
        global $DB;

        $now = time();

        return $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'groupid' => $groupid,
            'type' => 'compchange',
            'status' => 'open',
            'requestedby' => $userid,
            'request' => 'A legacy row filed before the reference column existed.',
            'requestformat' => FORMAT_PLAIN,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * The whole point of the trap: three legacy rows across two
     * activities upgrade cleanly. Every one ends up with a non-empty,
     * DISTINCT reference, and the unique index exists afterwards - so a
     * real site's populated table upgrades exactly as cleanly as the
     * gate's empty fresh-install path already does.
     */
    public function test_backfill_gives_every_existing_row_a_distinct_reference_then_the_index_goes_on(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity1, $group1, $user1] = $this->world('BACK1');
        [$activity2, $group2, $user2] = $this->world('BACK2');

        $this->drop_pluginuid_to_simulate_the_pre_1_20_56_shape();

        $id1 = $this->insert_legacy_ticket($activity1, (int) $group1->id, (int) $user1->id);
        // A second row on the SAME activity - the reference format's
        // course-derived segment alone cannot tell two rows apart; only
        // the ticket's own id may.
        $id2 = $this->insert_legacy_ticket($activity1, (int) $group1->id, (int) $user1->id);
        $id3 = $this->insert_legacy_ticket($activity2, (int) $group2->id, (int) $user2->id);

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_ticket_pluginuid($DB, $dbman);

        $table = new \xmldb_table('selfselectadvanced_ticket');
        $index = new \xmldb_index('pluginuid', XMLDB_INDEX_UNIQUE, ['pluginuid']);
        $this->assertTrue($dbman->index_exists($table, $index), 'the unique index must exist once the step has run');

        $refs = [];
        foreach ([$id1, $id2, $id3] as $id) {
            $row = $DB->get_record('selfselectadvanced_ticket', ['id' => $id], '*', MUST_EXIST);
            $this->assertNotEmpty($row->pluginuid, 'ticket ' . $id . ' was left with an empty reference');
            $this->assertNotSame('0', $row->pluginuid, 'ticket ' . $id . ' was left at the temporary add_field() default');
            $this->assertMatchesRegularExpression(
                '/^[A-Z0-9]{1,8}-[A-Z0-9]{1,12}-T\d{4,}$/',
                $row->pluginuid,
                'ticket ' . $id . ' got "' . $row->pluginuid . '", not the PREFIX-COURSE-Tnumber shape'
            );
            $refs[] = $row->pluginuid;
        }
        $this->assertCount(3, array_unique($refs), 'the three backfilled references must all be distinct');
    }

    /**
     * An orphan - its activity row already gone, a state a real site
     * should not have but which the 1.20.55 audit (deleting an activity
     * left the ticket trail orphaned) shows this codebase already treats
     * as real - still gets a distinct reference rather than being left at
     * the temporary default the unique index would then refuse.
     */
    public function test_an_orphaned_ticket_still_gets_a_distinct_reference(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity, $group, $user] = $this->world('ORPH1');
        $this->drop_pluginuid_to_simulate_the_pre_1_20_56_shape();

        $goodid = $this->insert_legacy_ticket($activity, (int) $group->id, (int) $user->id);
        $orphanid = $this->insert_legacy_ticket($activity, (int) $group->id, (int) $user->id);
        // The activity row is now gone, but its ticket rows remain -
        // exactly the shape the 1.20.55 changelog names ("deleting an
        // activity ... left the whole ticket trail ... orphaned in the
        // database"). Only the SECOND ticket is orphaned this way; the
        // first stays a normal row so the JOIN path and the fallback
        // path are both exercised in one test.
        $DB->delete_records('selfselectadvanced', ['id' => $activity->id()]);
        $DB->set_field('selfselectadvanced_ticket', 'activityid', $activity->id() + 999000, ['id' => $orphanid]);

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_ticket_pluginuid($DB, $dbman);

        $good = $DB->get_record('selfselectadvanced_ticket', ['id' => $goodid], '*', MUST_EXIST);
        $orphan = $DB->get_record('selfselectadvanced_ticket', ['id' => $orphanid], '*', MUST_EXIST);
        $this->assertNotEmpty($good->pluginuid);
        $this->assertNotEmpty($orphan->pluginuid);
        $this->assertNotSame('0', $orphan->pluginuid, 'the orphan was left at the temporary add_field() default');
        $this->assertNotSame($good->pluginuid, $orphan->pluginuid, 'the orphan must not collide with a real row');
        $this->assertStringContainsString('ORPHAN', $orphan->pluginuid, 'the fallback shape names itself as an orphan');

        $table = new \xmldb_table('selfselectadvanced_ticket');
        $index = new \xmldb_index('pluginuid', XMLDB_INDEX_UNIQUE, ['pluginuid']);
        $this->assertTrue($dbman->index_exists($table, $index), 'the unique index must still go on with an orphan present');
    }

    /**
     * NEGATIVE CONTROL, THE MUTATION ITSELF, kept in its own method
     * (PostgreSQL transaction poisoning: the expected ddl_exception
     * aborts the open PHPUnit transaction, so no further query in the
     * same method could be trusted afterwards).
     *
     * This is "prove the ORDER in a test", stated as directly as
     * possible: add_field() alone leaves every existing row reading the
     * SAME temporary default ('0') - that is the whole reason the trap
     * exists - and adding the unique index at that point, before any
     * backfill has run, must fail exactly the way a naive
     * add_field-then-add_index step would on a real populated site.
     * selfselectadvanced_upgrade_ticket_pluginuid() never reaches this
     * state (backfill runs before its own add_index call); this test
     * reproduces the WRONG order by hand to show why that ordering is
     * load-bearing rather than cosmetic - see the release report for the
     * literal PHPUnit output this produced when run.
     */
    public function test_the_wrong_order_violates_uniqueness_on_a_populated_table(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $group, $user] = $this->world('ORDR1');
        $this->drop_pluginuid_to_simulate_the_pre_1_20_56_shape();

        $this->insert_legacy_ticket($activity, (int) $group->id, (int) $user->id);
        $this->insert_legacy_ticket($activity, (int) $group->id, (int) $user->id);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('selfselectadvanced_ticket');
        $field = new \xmldb_field('pluginuid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '0', 'activityid');
        $dbman->add_field($table, $field);
        // Both legacy rows now read the identical temporary default '0' -
        // the exact state selfselectadvanced_upgrade_ticket_pluginuid()
        // is in for one instant, before ITS OWN backfill loop runs. Unlike
        // that function, this test adds the unique index right here,
        // BEFORE any backfill - the wrong order, on purpose.
        $index = new \xmldb_index('pluginuid', XMLDB_INDEX_UNIQUE, ['pluginuid']);

        $this->expectException(\ddl_exception::class);
        $dbman->add_index($table, $index);
    }

    /**
     * POSITIVE half of the same fact, kept separate from the negative
     * control above for the same PostgreSQL transaction-poisoning
     * reason: backfilling FIRST, on the identical two-row fixture, lets
     * the same add_index() call that just failed above succeed.
     */
    public function test_the_right_order_lets_the_index_go_on(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        [$activity, $group, $user] = $this->world('ORDR2');
        $this->drop_pluginuid_to_simulate_the_pre_1_20_56_shape();

        $this->insert_legacy_ticket($activity, (int) $group->id, (int) $user->id);
        $this->insert_legacy_ticket($activity, (int) $group->id, (int) $user->id);

        $dbman = $DB->get_manager();
        selfselectadvanced_upgrade_ticket_pluginuid($DB, $dbman);

        $table = new \xmldb_table('selfselectadvanced_ticket');
        $index = new \xmldb_index('pluginuid', XMLDB_INDEX_UNIQUE, ['pluginuid']);
        $this->assertTrue($dbman->index_exists($table, $index), 'backfilling first must let the unique index go on cleanly');
    }
}
