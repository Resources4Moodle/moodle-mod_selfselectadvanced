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

use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\flagged_anomalies_table;

/**
 * The three anomaly kinds added to the flagged report's group-anomalies
 * section: full-but-guideless groups (item 5c), groups whose leader is
 * no longer an active participant (item 2f), and the confirmed member
 * names now appended to every flagged row (item 5b). Each test seeds
 * exactly the condition it targets through the plugin generator (which
 * writes directly, bypassing the gatekeeper, so states the rules would
 * refuse can still be arranged) and asserts on build_rows(), the exact
 * method flagged.php calls - no page execution needed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\flagged_anomalies_table
 */
final class anomalies_test extends \advanced_testcase {
    /**
     * A course and activity with a configurable minsize/maxsize.
     *
     * @param array $settings instance setting overrides
     * @return array{0: activity, 1: resolver, 2: \stdClass} activity, resolver, course
     */
    private function setup_activity(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);
        $resolver = new resolver($activity);

        return [$activity, $resolver, $course];
    }

    /**
     * A forming group, full by its confirmed headcount, with no guide
     * assigned, is flagged full-but-guideless (item 5c).
     */
    public function test_full_but_guideless_flagged_when_forming(): void {
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity(['maxsize' => 1]);
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('flagfullnoguide', 'mod_selfselectadvanced'),
            $rows[0]->issues
        );
    }

    /**
     * The same full-but-guideless flag applies to a pending_guide group
     * (manager-assigns mode, spec A5, still carries no guideid).
     */
    public function test_full_but_guideless_flagged_when_pending_guide(): void {
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity(['maxsize' => 1]);
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time(),
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('flagfullnoguide', 'mod_selfselectadvanced'),
            $rows[0]->issues
        );
    }

    /**
     * A firm group that is full and guideless is NOT flagged: once a
     * group is past guide review it cannot legitimately regain a
     * missing guide, so the check is scoped to forming/pending_guide.
     */
    public function test_full_but_guideless_not_flagged_once_firm(): void {
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity(['maxsize' => 1]);
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FIRM,
            'timeapproved' => time(),
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(0, $rows);
    }

    /**
     * A leader who was never enrolled with the respond capability
     * flags the group as leader-gone (item 2f).
     */
    public function test_leader_gone_when_not_actively_enrolled(): void {
        $this->resetAfterTest();
        [$activity, $resolver] = $this->setup_activity();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        // Never enrolled in the course at all: not in the active,
        // respond-capable participant set.
        $leader = $generator->create_user();
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('flagleadergone', 'mod_selfselectadvanced'),
            $rows[0]->issues
        );
    }

    /**
     * A leader whose enrolment is live but whose ACCOUNT was
     * subsequently suspended also flags leader-gone (item 2f): the
     * enrolment-active test and the account check are independent, as
     * required (get_enrolled_sql() alone does not look at the account's
     * own deleted/suspended flags).
     */
    public function test_leader_gone_when_account_suspended(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $DB->set_field('user', 'suspended', 1, ['id' => $leader->id]);

        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('flagleadergone', 'mod_selfselectadvanced'),
            $rows[0]->issues
        );
    }

    /**
     * A leader whose account was deleted (rather than merely
     * suspended) is caught by the same check.
     */
    public function test_leader_gone_when_account_deleted(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $DB->set_field('user', 'deleted', 1, ['id' => $leader->id]);

        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('flagleadergone', 'mod_selfselectadvanced'),
            $rows[0]->issues
        );
    }

    /**
     * An actively-enrolled, non-deleted, non-suspended leader of an
     * otherwise healthy group is not flagged at all (negative control).
     */
    public function test_healthy_group_not_flagged(): void {
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'guideid' => $guide->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time(),
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(0, $rows);
    }

    /**
     * Every confirmed member's fullname appears, comma-separated, in
     * the issues cell of a flagged row (item 5b) - here the flag is the
     * pre-existing out-of-limit check (4A.8), so this also proves the
     * name lookup is wired into every anomaly kind, not just the new
     * ones.
     */
    public function test_member_names_appear_on_flagged_row(): void {
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity(['minsize' => 3, 'maxsize' => 6]);
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user(['firstname' => 'Lena', 'lastname' => 'Ledperson']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $mate = $generator->create_user(['firstname' => 'Mira', 'lastname' => 'Matename']);
        $generator->enrol_user($mate->id, $course->id, 'student');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);
        $plugingen->create_member(['groupid' => $group->id, 'userid' => $mate->id]);

        // Two confirmed members, below the minsize of 3: flagged
        // out-of-limit (4A.8), independently of the new checks.
        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('flagoutoflimit', 'mod_selfselectadvanced', (object) [
                'confirmed' => 2, 'seats' => 2, 'min' => 3, 'max' => 6,
            ]),
            $rows[0]->issues
        );
        $this->assertStringContainsString('Ledperson', $rows[0]->issues);
        $this->assertStringContainsString('Matename', $rows[0]->issues);
    }

    /**
     * A group with no anomaly at all contributes no member names
     * either - the batched lookup only ever runs for the flagged set.
     */
    public function test_no_member_names_when_not_flagged(): void {
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity(['minsize' => 1, 'maxsize' => 6]);
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user(['lastname' => 'Notflagged']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'state' => state::FORMING,
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(0, $rows);
    }

    /**
     * D7-D2: a group that arrives FROZEN with no mirrored course group
     * - the shape a backup restore produces, and the shape a hand
     * edit produces - is reported, so the restore hole stops being
     * invisible. The row's link is the page where the resync button is.
     */
    public function test_frozen_group_without_a_mirror_is_flagged(): void {
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'guideid' => $guide->id,
            'state' => state::FROZEN,
            'timeapproved' => time(),
            'timefrozen' => time(),
        ]);

        $rows = flagged_anomalies_table::build_rows($activity, $resolver);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('coregroupmissing', 'mod_selfselectadvanced'),
            $rows[0]->issues
        );
    }

    /**
     * A frozen team whose mirror holds a row nobody here wrote is
     * reported as carrying strangers - reported, never removed.
     */
    public function test_stranger_in_the_mirror_is_flagged(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();
        [$activity, $resolver, $course] = $this->setup_activity();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'guideid' => $guide->id,
            'state' => state::FIRM,
            'timeapproved' => time(),
        ]);
        $frozen = \mod_selfselectadvanced\local\freeze::freeze_group(
            $activity,
            \mod_selfselectadvanced\local\groups::get($activity, (int) $group->id),
            (int) $guide->id
        );
        $stranger = $generator->create_user();
        $generator->enrol_user($stranger->id, $course->id, 'student');
        groups_add_member((int) $frozen->coregroupid, (int) $stranger->id);

        $rows = flagged_anomalies_table::build_rows(
            activity::from_instance($activity->id()),
            new resolver($activity)
        );

        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('coregroupstranger', 'mod_selfselectadvanced', 1),
            $rows[0]->issues
        );
    }
}
