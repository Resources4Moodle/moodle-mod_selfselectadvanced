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
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\flagged_anomalies_table;

/**
 * RCA docs/audits/rca-core-sync-caps-prefix.md: the manager-controlled
 * group-id prefix, the good-neighbour membership audit at the freeze
 * gate (with its manager flag and its repair-path grandfathering), and
 * the deleted-account roster cleanup.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\freeze
 */
final class coresync_caps_prefix_test extends \advanced_testcase {
    /**
     * A firm two-member group with a guide, an editing teacher as the
     * manager on the side, and a second group sharing one member.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, group row, second group row, students, guide, manager]
     */
    private function setup_shared_member(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'CAPAUD']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
        ], $settings));

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Audited',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        // The shared member also belongs to a second, forming group -
        // legal while the cap is 2.
        $second = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[2]->id,
            'name' => 'Sibling',
            'state' => state::FORMING,
        ]);
        $plugingen->create_member([
            'groupid' => $second->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            groups::get($activity, (int) $group->id),
            groups::get($activity, (int) $second->id),
            $students,
            $guide,
            $manager,
        ];
    }

    /**
     * The uidprefix setting stamps new group ids: default SSA, a
     * custom value upper-cased, and a value that sanitises to nothing
     * falls back to SSA. Existing ids are never rewritten.
     */
    public function test_uidprefix_controls_new_group_ids(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();

        $course = $generator->create_course(['shortname' => 'PFX-1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'minsize' => 1, 'maxlead' => 3, 'maxmembership' => 3,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $api = new api($activity);
        $default = $api->create_group((int) $student->id, 'Default prefix', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertStringStartsWith('SSA-PFX1-', $default->pluginuid);

        $DB->set_field('selfselectadvanced', 'uidprefix', 'vit26', ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());
        $custom = (new api($activity))->create_group((int) $student->id, 'Custom prefix', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertStringStartsWith('VIT26-PFX1-', $custom->pluginuid);

        $DB->set_field('selfselectadvanced', 'uidprefix', '!!', ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());
        $fallback = (new api($activity))->create_group((int) $student->id, 'Fallback prefix', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertStringStartsWith('SSA-PFX1-', $fallback->pluginuid);

        // The first group's id was minted under the old prefix and
        // must never be rewritten.
        $this->assertStringStartsWith(
            'SSA-PFX1-',
            $DB->get_field('selfselectadvanced_group', 'pluginuid', ['id' => $default->id])
        );
    }

    /**
     * The number width is the manager's choice, an out-of-range value
     * falls back to the default, and a number too large for the width
     * keeps all its digits rather than being cut short.
     */
    public function test_uiddigits_controls_the_number_width(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();

        $course = $generator->create_course(['shortname' => 'DIG']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'minsize' => 1, 'maxlead' => 5, 'maxmembership' => 5,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $narrow = (new api($activity))->create_group((int) $student->id, 'Four', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertMatchesRegularExpression('/^SSA-DIG-\d{4}$/', $narrow->pluginuid);

        $DB->set_field('selfselectadvanced', 'uiddigits', 6, ['id' => $activity->id()]);
        $wide = (new api(activity::from_instance($activity->id())))
            ->create_group((int) $student->id, 'Six', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertMatchesRegularExpression('/^SSA-DIG-\d{6}$/', $wide->pluginuid);

        // Out of range: the default width serves instead.
        $DB->set_field('selfselectadvanced', 'uiddigits', 99, ['id' => $activity->id()]);
        $silly = (new api(activity::from_instance($activity->id())))
            ->create_group((int) $student->id, 'Silly', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertMatchesRegularExpression('/^SSA-DIG-\d{4}$/', $silly->pluginuid);

        // A group id wider than the chosen width is never truncated.
        $DB->set_field('selfselectadvanced', 'uiddigits', 2, ['id' => $activity->id()]);
        $freshactivity = activity::from_instance($activity->id());
        $this->assertSame(
            'SSA-DIG-123456',
            groups::build_pluginuid($freshactivity, 123456)
        );
    }

    /**
     * The middle part of a group id names the course: its short name,
     * or its full name when the short name carries nothing usable.
     */
    public function test_pluginuid_falls_back_to_the_course_name(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();

        $course = $generator->create_course([
            'shortname' => '---',
            'fullname' => 'Design Thinking 2026',
        ]);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'minsize' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $group = (new api($activity))->create_group((int) $student->id, 'Named', 'T', '<p>b</p>', FORMAT_HTML);
        $this->assertStringStartsWith('SSA-DESIGNTHINKIN-', $group->pluginuid);
    }

    /**
     * A grandfathered over-cap member refuses the freeze, flags every
     * manager with the names and counts, and shows in the flagged
     * report; a per-user override then clears the push.
     */
    public function test_freeze_refuses_and_flags_over_cap(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, $second, $students, $guide, $manager] = $this->setup_shared_member();

        // The manager lowers the cap AFTER the shared member joined
        // both groups: grandfathered violation.
        $DB->set_field('selfselectadvanced', 'maxmembership', 1, ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());

        $sink = $this->redirectMessages();
        try {
            freeze::freeze_group($activity, groups::get($activity, (int) $group->id), (int) $guide->id);
            $this->fail('freeze must refuse an over-cap roster');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalmembershipaudit', $e->errorcode);
            $this->assertStringContainsString(fullname($students[1]), $e->getMessage());
        }
        $fresh = groups::get($activity, (int) $group->id);
        $this->assertSame(state::FIRM, $fresh->state);
        $this->assertEmpty($fresh->coregroupid);

        // The good-neighbour flag reached the manager, not silence.
        $tomanager = array_filter(
            $sink->get_messages(),
            static fn(\stdClass $message) => (int) $message->useridto === (int) $manager->id
        );
        $this->assertNotEmpty($tomanager, 'the manager must be flagged');
        $flag = reset($tomanager);
        $this->assertSame('capaudit', $flag->eventtype);
        $this->assertStringContainsString('Audited', $flag->subject);
        $this->assertStringContainsString(fullname($students[1]), $flag->fullmessage);
        $sink->close();

        // The flagged report shows the condition proactively on BOTH
        // groups that carry the member.
        $rows = flagged_anomalies_table::build_rows($activity, new resolver($activity));
        $flagged = array_filter(
            $rows,
            static fn(\stdClass $row) => str_contains($row->issues, fullname($students[1]))
                && str_contains($row->issues, 'Over membership cap')
        );
        $this->assertCount(2, $flagged);

        // The MANAGER raises the cap for that member - the plugin
        // never does - and the push now proceeds.
        store::save($activity, 'user', (int) $students[1]->id, ['maxmembership' => 2], 0);
        $frozen = freeze::freeze_group(
            activity::from_instance($activity->id()),
            groups::get($activity, (int) $group->id),
            (int) $guide->id
        );
        $this->assertSame(state::FROZEN, $frozen->state);
        $this->assertNotEmpty($frozen->coregroupid);
        $this->assertTrue(groups_is_member((int) $frozen->coregroupid, (int) $students[1]->id));
    }

    /**
     * Repairing an already-frozen mirror (core group deleted out of
     * band) stays grandfathered: the audit gates only the first push.
     */
    public function test_freeze_repair_stays_grandfathered(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/group/lib.php');
        [$activity, $group, $second, $students, $guide] = $this->setup_shared_member();

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $this->assertSame(state::FROZEN, $frozen->state);

        // Cap lowered AFTER the push; then the core group vanishes out
        // of band.
        $DB->set_field('selfselectadvanced', 'maxmembership', 1, ['id' => $activity->id()]);
        groups_delete_group((int) $frozen->coregroupid);

        $activity = activity::from_instance($activity->id());
        $repaired = freeze::freeze_group($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $this->assertSame(state::FROZEN, $repaired->state);
        $this->assertNotEmpty($repaired->coregroupid);
        $this->assertNotEquals((int) $frozen->coregroupid, (int) $repaired->coregroupid);
        $this->assertTrue(groups_is_member((int) $repaired->coregroupid, (int) $students[1]->id));
    }

    /**
     * Deleting a user account clears their live memberships and
     * re-snapshots frozen groups, so neither an unfreeze nor a repair
     * can resurrect the ghost.
     */
    public function test_user_deleted_clears_rosters_and_snapshots(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, $second, $students, $guide] = $this->setup_shared_member();

        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $this->assertTrue(groups_is_member((int) $frozen->coregroupid, (int) $students[1]->id));

        // A pending staged move of the soon-deleted student would
        // re-insert the ghost at commit - deletion must cancel it.
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $mover = $generator->create_user();
        $generator->enrol_user($mover->id, $activity->courseid(), 'student');
        $target = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $mover->id,
            'name' => 'Target',
            'state' => state::FORMING,
        ]);
        $move = (new api($activity))->moves()->stage(
            (int) $students[1]->id,
            (int) $second->id,
            (int) $target->id,
            false,
            null,
            2
        );
        $this->assertSame('pending', $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $move->id]));

        delete_user($students[1]);

        $this->assertSame(
            'cancelled',
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => (int) $move->id])
        );

        // Both memberships are gone from the plugin's books.
        $this->assertSame(0, groups::count_memberships($activity, (int) $students[1]->id));
        $this->assertSame(
            groups::STATUS_REMOVED,
            $DB->get_field('selfselectadvanced_member', 'status', [
                'groupid' => (int) $frozen->id,
                'userid' => (int) $students[1]->id,
            ])
        );
        // The frozen group's NEWEST snapshot no longer carries the
        // ghost, so unfreeze restores the true roster.
        $snapshot = freeze::latest_snapshot((int) $frozen->id);
        $rosterids = array_map(
            static fn(array $entry) => (int) $entry['userid'],
            json_decode($snapshot->roster, true)
        );
        $this->assertNotContains((int) $students[1]->id, $rosterids);
        $this->assertContains((int) $students[0]->id, $rosterids);

        $unfrozen = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $this->assertSame(state::FIRM, $unfrozen->state);
        $this->assertSame(1, groups::count_confirmed((int) $frozen->id));
    }
}
