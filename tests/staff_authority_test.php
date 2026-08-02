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

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * Staff authority (decision 6): who holds it by default (D6-7), the
 * dissolve verb (D6-3) and staff team creation (D6-4).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\api
 */
final class staff_authority_test extends \advanced_testcase {
    /**
     * One firm team of two, an editing teacher, spare students.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, api, group, students[], staff, course]
     */
    private function setup_team(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 1,
        ], $settings));

        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Alpha',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $students, $staff, $course];
    }

    /**
     * D6-7: a Moodle Manager could not open manage.php, moveedit.php or
     * moves.php at all - the archetype held none of these capabilities,
     * while db/access.php's own comment claimed it did. Pinned here so
     * a future archetype edit cannot drift back silently.
     */
    public function test_manager_role_default_capabilities_pinned(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , , , , $course] = $this->setup_team();

        $manager = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, (int) $manager->id, \context_course::instance($course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $context = $activity->context();
        foreach (['manage', 'override', 'overriderules', 'viewall', 'unfreeze'] as $cap) {
            $this->assertTrue(
                has_capability('mod/selfselectadvanced:' . $cap, $context, (int) $manager->id),
                'Manager should hold ' . $cap
            );
        }
        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:addinstance',
            \context_course::instance($course->id),
            (int) $manager->id
        ));
        // Deliberately NOT granted: freeze is a guide verb (spec D4),
        // and the rest are student or coordinator capabilities.
        foreach (['freeze', 'creategroup', 'respond', 'guide', 'coordinate'] as $cap) {
            $this->assertFalse(
                has_capability('mod/selfselectadvanced:' . $cap, $context, (int) $manager->id),
                'Manager should NOT hold ' . $cap
            );
        }
    }

    /**
     * Every site that granted :override keeps its bypass authority, and
     * the editing teacher holds the new capability by archetype.
     */
    public function test_editingteacher_gets_overriderules_by_default(): void {
        $this->resetAfterTest();
        [$activity, , , , $staff] = $this->setup_team();

        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:overriderules',
            $activity->context(),
            (int) $staff->id
        ));
    }

    /**
     * D6-3: the dead end. A solo-leader FIRM team cannot be repaired
     * (no successor can exist) and cannot be deleted (leader + forming
     * only). Dissolve is its exit, and it records one committed park
     * move per member.
     */
    public function test_dissolve_solo_firm_team(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, , $students, $staff] = $this->setup_team();

        $solo = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'Husk',
            'state' => state::FIRM,
        ]);
        $solo = groups::get($activity, (int) $solo->id);

        $sink = $this->redirectEvents();
        $api->dissolve_group($solo, 'Only member left the programme', (int) $staff->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($DB->record_exists('selfselectadvanced_group', ['id' => $solo->id]));
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', ['groupid' => $solo->id]));

        $moverows = $DB->get_records('selfselectadvanced_move', [
            'activityid' => $activity->id(),
            'sourcegroupid' => (int) $solo->id,
        ]);
        $this->assertCount(1, $moverows);
        $moverow = reset($moverows);
        $this->assertSame('committed', $moverow->status);
        $this->assertNull($moverow->targetgroupid);
        $this->assertSame('Only member left the programme', $moverow->responsenote);
        $this->assertStringContainsString('DISSOLVE', (string) $moverow->statusinfo);

        $deleted = array_filter($events, static fn($e) => $e instanceof \mod_selfselectadvanced\event\group_deleted);
        $this->assertCount(1, $deleted);
        $overridden = array_values(array_filter(
            $events,
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\move_rules_overridden
        ));
        $this->assertCount(1, $overridden);
        $this->assertSame('dissolve', $overridden[0]->other['kind']);
        $this->assertSame(['DISSOLVE'], $overridden[0]->other['rules']);
        $this->assertSame((int) $students[2]->id, (int) $overridden[0]->relateduserid);

        $this->assertSame(0, groups::count_memberships($activity, (int) $students[2]->id));
        $this->assertSame(0, groups::count_leading($activity, (int) $students[2]->id));
    }

    /**
     * A dissolve leaves NO row pointing at the team it just deleted
     * that a page will later read with MUST_EXIST.
     *
     * The pending-moves page validates its whole page jointly, and
     * moves::validate_set() resolves the source AND the target of every
     * row through groups::get() (MUST_EXIST). So a single staged move
     * naming a dissolved team turned the manager's queue into a
     * dml_missing_record_exception - and the cancel button for that
     * move lives on the page that no longer loads. A live join request
     * stranded its asker the same way, holding the one-at-a-time slot
     * against a team that no longer exists, and the asker's own
     * requests tab threw on the history row.
     *
     * Negative control: drop the orphan close-out from
     * dissolve_group() - validate_set() throws
     * dml_missing_record_exception and the request stays 'requested'.
     */
    public function test_dissolve_closes_moves_and_requests_naming_the_team(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $group, $students, $staff] = $this->setup_team(['maxsize' => 4]);

        $other = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'Beta',
            'state' => state::FIRM,
        ]);

        // One staged move OUT of the doomed team, one INTO it.
        $out = $api->moves()->stage(
            (int) $students[1]->id,
            (int) $group->id,
            (int) $other->id,
            false,
            null,
            (int) $staff->id
        );
        $in = (new api($activity))->moves()->stage(
            (int) $students[3]->id,
            null,
            (int) $group->id,
            false,
            null,
            (int) $staff->id
        );
        // A bypass on one of them, so the override row is closed too.
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'move',
            (int) $in->id,
            ['rulesbypassed' => 'L2'],
            (int) $staff->id
        );
        // And one live join request aimed at the doomed team.
        $request = \mod_selfselectadvanced\local\joinrequests::request(
            $activity,
            (int) $group->id,
            'Nearer my lab',
            (int) $students[3]->id
        );

        (new api($activity))->dissolve_group(
            groups::get($activity, (int) $group->id),
            'Winding the team up',
            (int) $staff->id
        );

        $this->assertSame('cancelled', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $out->id]));
        $this->assertSame('cancelled', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $in->id]));
        $this->assertSame(
            \mod_selfselectadvanced\local\joinrequests::STATUS_DECLINED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id])
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'move',
            'moveid' => (int) $in->id,
        ]));

        // What the manager's queue does next: it loads at all.
        $pending = $DB->get_records(
            'selfselectadvanced_move',
            ['activityid' => $activity->id(), 'status' => 'pending'],
            'timecreated ASC, id ASC',
            '*',
            0,
            50
        );
        $verdicts = $pending
            ? (new api($activity))->moves()->validate_set(array_keys($pending))
            : (object) ['valid' => true, 'permove' => []];
        $this->assertIsObject($verdicts);
    }

    /**
     * Everything that is only meaningful while the team exists goes
     * with it: no orphan snapshot, penalty, expression of interest,
     * approach or group-scope override is left behind for a report to
     * trip over. The per-member MOVE rows stay - they are the audit
     * record this verb just wrote.
     */
    public function test_dissolve_takes_the_group_scoped_rows_with_it(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $group, $students, $staff] = $this->setup_team();

        $DB->insert_record('selfselectadvanced_snapshot', (object) [
            'groupid' => (int) $group->id,
            'coregroupid' => 0,
            'roster' => json_encode([['userid' => (int) $students[0]->id, 'isleader' => 1]]),
            'takenby' => (int) $staff->id,
            'timecreated' => time(),
        ]);
        $DB->insert_record('selfselectadvanced_penalty', (object) [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'dayslate' => 0,
            'penaltyvalue' => 0,
            'award' => null,
            'waived' => 0,
            'timecomputed' => time(),
        ]);
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $group->id,
            ['maxsize' => 9],
            (int) $staff->id
        );

        $api->dissolve_group($group, 'Winding the team up', (int) $staff->id);

        $this->assertSame(0, $DB->count_records('selfselectadvanced_snapshot', ['groupid' => (int) $group->id]));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_penalty', ['groupid' => (int) $group->id]));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'group',
            'groupid' => (int) $group->id,
        ]));
        // The audit record survives: two parked members, leader last.
        $this->assertSame(2, $DB->count_records('selfselectadvanced_move', [
            'activityid' => $activity->id(),
            'sourcegroupid' => (int) $group->id,
        ]));
    }

    /**
     * The mirror dies with the team, and the guard is "has a
     * coregroupid", NOT "is frozen": since T-16 a FIRM team retains its
     * mirror across unfreeze, and a frozen-only delete would strand an
     * orphaned course group nothing can find.
     */
    public function test_dissolve_deletes_the_core_mirror(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        // Kept, but not for the reason this used to give: the core
        // write no longer depends on there being no transaction (the
        // requirement-6 branch went in 1.20). It makes the rows this
        // test reads back ordinary committed rows.
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $api, $group, , $staff] = $this->setup_team();

        // Case A: a FROZEN team - dissolve deletes its live core group.
        $frozen = freeze::freeze_group($activity, $group, (int) $staff->id);
        $frozencoreid = (int) $frozen->coregroupid;
        $this->assertTrue(groups_group_exists($frozencoreid));
        $api->dissolve_group(groups::get($activity, (int) $group->id), 'Team wound up', (int) $staff->id);
        $this->assertFalse(groups_group_exists($frozencoreid));
        try {
            groups::get($activity, (int) $group->id);
            $this->fail('The dissolved group row should be gone.');
        } catch (\dml_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Case B: a FIRM team that RETAINS a coregroupid after unfreeze.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $generator = $this->getDataGenerator();
        $another = $generator->create_user();
        $generator->enrol_user($another->id, $activity->cm()->course, 'student');
        $second = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $another->id,
            'name' => 'Beta',
            'state' => state::FIRM,
        ]);
        $second = groups::get($activity, (int) $second->id);
        $frozensecond = freeze::freeze_group($activity, $second, (int) $staff->id);
        $secondcoreid = (int) $frozensecond->coregroupid;
        freeze::unfreeze($activity, groups::get($activity, (int) $second->id), (int) $staff->id);
        $firm = groups::get($activity, (int) $second->id);
        $this->assertSame(state::FIRM, $firm->state);
        $this->assertSame($secondcoreid, (int) $firm->coregroupid);
        $this->assertTrue(groups_group_exists($secondcoreid));

        $api->dissolve_group($firm, 'No longer needed', (int) $staff->id);
        $this->assertFalse(groups_group_exists($secondcoreid));
    }

    /**
     * The two blockers are refused rather than overridden, and the
     * capability and the reason are both required.
     */
    public function test_dissolve_refusals(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, $group, $students, $staff] = $this->setup_team();

        // No :overriderules -> refused, even holding :manage.
        $plain = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($plain->id, $activity->cm()->course, 'editingteacher');
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, (int) $plain->id, $activity->context()->id);
        assign_capability(
            'mod/selfselectadvanced:overriderules',
            CAP_PROHIBIT,
            $roleid,
            $activity->context()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        try {
            $api->dissolve_group($group, 'because', (int) $plain->id);
            $this->fail('Expected errdissolvecap');
        } catch (\moodle_exception $e) {
            $this->assertSame('errdissolvecap', $e->errorcode);
        }

        // No reason -> refused.
        try {
            $api->dissolve_group($group, '   ', (int) $staff->id);
            $this->fail('Expected errdissolvereasonrequired');
        } catch (\moodle_exception $e) {
            $this->assertSame('errdissolvereasonrequired', $e->errorcode);
        }

        // A gradebook award -> refused, with the group left intact.
        // The ledger only computes for APPROVED groups, so the fixture
        // gains its approval time first.
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $group->id]);
        $group = groups::get($activity, (int) $group->id);
        ledger::set_award($activity, $group, 17.5, (int) $staff->id);
        try {
            $api->dissolve_group($group, 'wind up', (int) $staff->id);
            $this->fail('Expected errdissolveaward');
        } catch (\moodle_exception $e) {
            $this->assertSame('errdissolveaward', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('selfselectadvanced_group', ['id' => $group->id]));
        ledger::set_award($activity, $group, null, (int) $staff->id);

        // An open ticket -> refused.
        $DB->insert_record('selfselectadvanced_ticket', (object) [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_COMPCHANGE,
            'status' => tickets::STATUS_OPEN,
            'requestedby' => (int) $students[0]->id,
            'request' => 'Please change the composition',
            'requestformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        try {
            $api->dissolve_group($group, 'wind up', (int) $staff->id);
            $this->fail('Expected errdissolveticket');
        } catch (\moodle_exception $e) {
            $this->assertSame('errdissolveticket', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('selfselectadvanced_group', ['id' => $group->id]));
    }

    /**
     * D6-4: staff can create a destination team after the cutoff -
     * the window gate is a STUDENT constraint and a repair is exactly
     * the case it must not stop.
     */
    public function test_staff_create_after_cutoff(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $api, , $students, $staff] = $this->setup_team([
            'timecutoff' => time() - DAYSECS,
            'timedue' => time() - (2 * DAYSECS),
        ]);
        $leaderid = (int) $students[2]->id;

        // The student path is refused by the window, as it should be.
        $this->assertNotNull($api->gatekeeper()->can_create_group($leaderid));

        $sink = $this->redirectEvents();
        $group = $api->create_group(
            (int) $staff->id,
            'Repair team',
            'Salvage work',
            '<p>Brief</p>',
            FORMAT_HTML,
            $leaderid,
            true
        );
        $created = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\group_created
        ));
        $sink->close();

        $fresh = groups::get($activity, (int) $group->id);
        $this->assertSame($leaderid, (int) $fresh->leaderid);
        $this->assertSame(state::FORMING, $fresh->state);
        $rows = $DB->get_records('selfselectadvanced_member', ['groupid' => $fresh->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame($leaderid, (int) $row->userid);
        $this->assertSame(1, (int) $row->isleader);
        $this->assertSame(groups::STATUS_CONFIRMED, $row->status);

        $this->assertCount(1, $created);
        $this->assertTrue($created[0]->other['createdbystaff']);

        // The student path's event does NOT carry the key.
        $studentleader = (int) $students[3]->id;
        $DB->set_field('selfselectadvanced', 'timecutoff', 0, ['id' => $activity->id()]);
        $DB->set_field('selfselectadvanced', 'timedue', 0, ['id' => $activity->id()]);
        $freshapi = new api(activity::from_instance($activity->id()));
        $sink = $this->redirectEvents();
        $freshapi->create_group($studentleader, 'Student team', 'Own work', '<p>Brief</p>', FORMAT_HTML);
        $created = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\group_created
        ));
        $sink->close();
        $this->assertCount(1, $created);
        $this->assertArrayNotHasKey('createdbystaff', $created[0]->other);
    }

    /**
     * No bypass on staff creation: the NOMINATED leader's own caps
     * still bind, and the name is still unique.
     */
    public function test_staff_create_enforces_leader_caps_and_duplicate_name(): void {
        $this->resetAfterTest();
        [, $api, $group, $students, $staff] = $this->setup_team();

        // The first student already leads Alpha and maxmembership is 1.
        try {
            $api->create_group(
                (int) $staff->id,
                'Second team',
                'Work',
                '<p>Brief</p>',
                FORMAT_HTML,
                (int) $students[0]->id,
                true
            );
            $this->fail('Expected a leader-capacity refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleadcap', $e->errorcode);
        }

        // The second student is a plain member at the membership cap of 1.
        try {
            $api->create_group(
                (int) $staff->id,
                'Third team',
                'Work',
                '<p>Brief</p>',
                FORMAT_HTML,
                (int) $students[1]->id,
                true
            );
            $this->fail('Expected refusalmembershipcap');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalmembershipcap', $e->errorcode);
        }

        // A duplicate name is refused whoever asks.
        try {
            $api->create_group(
                (int) $staff->id,
                $group->name,
                'Work',
                '<p>Brief</p>',
                FORMAT_HTML,
                (int) $students[2]->id,
                true
            );
            $this->fail('Expected errnametaken');
        } catch (\moodle_exception $e) {
            $this->assertSame('errnametaken', $e->errorcode);
        }

        // A user who is not a participant at all.
        $outsider = $this->getDataGenerator()->create_user();
        try {
            $api->create_group(
                (int) $staff->id,
                'Fourth team',
                'Work',
                '<p>Brief</p>',
                FORMAT_HTML,
                (int) $outsider->id,
                true
            );
            $this->fail('Expected errmovenotparticipant');
        } catch (\moodle_exception $e) {
            $this->assertSame('errmovenotparticipant', $e->errorcode);
        }
    }

    /**
     * The groupedit.php ordering bug (D6-4), from its root: an editing
     * teacher does NOT hold :creategroup - it is a STUDENT capability -
     * yet the page demanded it of everybody BEFORE the edit branch
     * whose own code admits a manager. So the manager path was
     * unreachable for exactly the people it was written for.
     *
     * Pinned here as two facts about real code: staff hold :manage and
     * not :creategroup, and the staff creation service works for such
     * an actor regardless. The page's click path is Behat's.
     */
    public function test_staff_hold_manage_but_not_creategroup(): void {
        $this->resetAfterTest();
        [$activity, $api, , $students, $staff] = $this->setup_team();

        $context = $activity->context();
        $this->assertTrue(has_capability('mod/selfselectadvanced:manage', $context, (int) $staff->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:creategroup', $context, (int) $staff->id));

        $group = $api->create_group(
            (int) $staff->id,
            'Made by staff',
            'Work',
            '<p>Brief</p>',
            FORMAT_HTML,
            (int) $students[2]->id,
            true
        );
        $this->assertSame((int) $students[2]->id, (int) groups::get($activity, (int) $group->id)->leaderid);
    }
}
