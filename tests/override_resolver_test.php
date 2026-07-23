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
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\effective_value;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\override\store;

/**
 * The override precedence matrix P1-P16 (architecture plan section
 * 6.2), the B5 store scope validation, CRUD events, and the
 * limit-override boundary re-runs of the section 6.5 test matrix.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\override\resolver
 * @covers     \mod_selfselectadvanced\local\override\store
 */
final class override_resolver_test extends \advanced_testcase {
    /**
     * Create a course, instance, students and a group.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, api, students[], group]
     */
    private function setup_activity(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 1,
            'maxguided' => 5,
            'timeopen' => 1000,
            'timedue' => 2000,
            'timecutoff' => 3000,
        ], $settings));

        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Ovr',
        ]);

        return [$activity, new api($activity), $students, groups::get($activity, (int) $group->id)];
    }

    /**
     * P1-P7: per-field date resolution - group wins over user wins over
     * activity, mixed combinations resolve field by field, and the
     * no-group context skips group rows.
     */
    public function test_date_precedence_matrix(): void {
        $this->resetAfterTest();
        [$activity, , $students, $group] = $this->setup_activity();
        $userid = (int) $students[0]->id;

        // P7 mixed: user overrides timedue, group overrides timecutoff.
        store::save($activity, 'user', $userid, ['timedue' => 2500], 0);
        store::save($activity, 'group', (int) $group->id, ['timecutoff' => 3500], 0);

        $resolver = new resolver($activity);
        $dates = $resolver->effective_dates($userid, (int) $group->id);
        $this->assertSame(1000, $dates->timeopen);   // P4: activity.
        $this->assertSame(2500, $dates->timedue);    // P3: user.
        $this->assertSame(3500, $dates->timecutoff); // P2: group.
        $this->assertSame(effective_value::SOURCE_ACTIVITY, $dates->sources['timeopen']);
        $this->assertSame(effective_value::SOURCE_USER, $dates->sources['timedue']);
        $this->assertSame(effective_value::SOURCE_GROUP, $dates->sources['timecutoff']);

        // P1: group beats user on the same field.
        store::save($activity, 'group', (int) $group->id, ['timedue' => 2800], 0);
        $dates = (new resolver($activity))->effective_dates($userid, (int) $group->id);
        $this->assertSame(2800, $dates->timedue);
        $this->assertSame(effective_value::SOURCE_GROUP, $dates->sources['timedue']);

        // P5/P6: no group context - the user override applies, group rows do not.
        $dates = (new resolver($activity))->effective_dates($userid, null);
        $this->assertSame(2500, $dates->timedue);
        $this->assertSame(3000, $dates->timecutoff);
    }

    /**
     * P8-P11: scope-bound limits and the quota exemption flag.
     */
    public function test_limit_scopes(): void {
        $this->resetAfterTest();
        [$activity, , $students, $group] = $this->setup_activity();
        $userid = (int) $students[0]->id;

        store::save($activity, 'group', (int) $group->id, ['minsize' => 3, 'maxsize' => 6], 0);
        store::save($activity, 'user', $userid, ['maxlead' => 2, 'maxmembership' => 3], 0);
        store::save($activity, 'guide', $userid, ['maxguided' => 9], 0);
        store::save($activity, 'group', (int) $group->id, ['quotaexempt' => 1], 0);

        $resolver = new resolver($activity);
        // P8.
        $this->assertSame(3, $resolver->effective_minsize((int) $group->id)->value);
        $this->assertSame(6, $resolver->effective_maxsize((int) $group->id)->value);
        $this->assertSame(effective_value::SOURCE_GROUP, $resolver->effective_maxsize((int) $group->id)->source);
        // P9.
        $this->assertSame(2, $resolver->effective_maxlead($userid)->value);
        $this->assertSame(3, $resolver->effective_maxmembership($userid)->value);
        // P10: guide scope is independent of user scope for the same user.
        $this->assertSame(9, $resolver->effective_maxguided($userid)->value);
        // P11.
        $this->assertTrue($resolver->is_quota_exempt((int) $group->id)->enabled);
        // Another group falls through.
        $this->assertSame(2, $resolver->effective_minsize(999999)->value);
    }

    /**
     * P12 flag + P16: penalty waiver flag, and assessment dates resolve
     * with the LEADER as user context (group > leader-user > activity).
     */
    public function test_assessment_dates_leader_context(): void {
        $this->resetAfterTest();
        [$activity, , $students, $group] = $this->setup_activity();
        $leaderid = (int) $students[0]->id;
        $otherid = (int) $students[1]->id;

        // The leader holds a personal extension; another member's
        // override must NOT leak into the group assessment.
        store::save($activity, 'user', $leaderid, ['timedue' => 2600], 0);
        store::save($activity, 'user', $otherid, ['timedue' => 9999], 0);

        $resolver = new resolver($activity);
        $dates = $resolver->assessment_dates((int) $group->id);
        $this->assertSame(2600, $dates->timedue);

        // A group date override still beats the leader's (P16 chain).
        store::save($activity, 'group', (int) $group->id, ['timedue' => 2700], 0);
        $this->assertSame(2700, (new resolver($activity))->assessment_dates((int) $group->id)->timedue);

        // P12: waiver flag.
        $this->assertFalse($resolver->is_penalty_waived((int) $group->id)->enabled);
        store::save($activity, 'group', (int) $group->id, ['penaltywaived' => 1], 0);
        $this->assertTrue((new resolver($activity))->is_penalty_waived((int) $group->id)->enabled);
    }

    /**
     * P13-P15 + B5: move bypass parsing, duplicate-target updates (one
     * row per target), orthogonal quantities never interacting, and the
     * store rejecting fields outside the scope's B5 set.
     */
    public function test_store_rules(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $group] = $this->setup_activity();
        $userid = (int) $students[0]->id;

        // P14: saving twice for one target updates the single row.
        $sink = $this->redirectEvents();
        store::save($activity, 'user', $userid, ['maxlead' => 2], 0);
        store::save($activity, 'user', $userid, ['maxlead' => 3], 0);
        $events = $sink->get_events();
        $sink->close();
        $this->assertSame(1, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'user',
            'userid' => $userid,
        ]));
        $created = array_filter($events, fn($e) => $e instanceof \mod_selfselectadvanced\event\override_created);
        $updated = array_values(array_filter(
            $events,
            fn($e) => $e instanceof \mod_selfselectadvanced\event\override_updated
        ));
        $this->assertCount(1, $created);
        $this->assertCount(1, $updated);
        $this->assertEquals(['maxlead' => 2], $updated[0]->get_data()['other']['oldvalues']);
        $this->assertEquals(['maxlead' => 3], $updated[0]->get_data()['other']['newvalues']);

        // P15: quota exemption and date overrides are orthogonal.
        store::save($activity, 'group', (int) $group->id, ['quotaexempt' => 1], 0);
        $resolver = new resolver($activity);
        $this->assertSame(2000, $resolver->effective_dates($userid, (int) $group->id)->timedue);

        // P13: move bypass list parsing.
        $moveid = (int) $DB->insert_record('selfselectadvanced_move', (object) [
            'activityid' => $activity->id(),
            'userid' => $userid,
            'sourcegroupid' => null,
            'targetgroupid' => (int) $group->id,
            'makeleader' => 0,
            'status' => 'pending',
            'usermodified' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        store::save($activity, 'move', $moveid, ['rulesbypassed' => 'L2, QUOTA'], 0);
        $this->assertSame(['L2', 'QUOTA'], (new resolver($activity))->move_bypasses($moveid));

        // B5: invalid scope/field pairs are coding errors.
        $this->expectException(\coding_exception::class);
        store::save($activity, 'user', $userid, ['minsize' => 5], 0);
    }

    /**
     * S3/§6.5: the limit boundaries re-run WITH overrides active -
     * creation against an overridden L3/L4, invitations against an
     * overridden L2, guide capacity against an overridden L5, and the
     * quota gate under a group exemption.
     */
    public function test_boundaries_with_overrides_active(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $group] = $this->setup_activity([
            'timeopen' => 0,
            'timedue' => 0,
            'timecutoff' => 0,
        ]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leader = (int) $students[0]->id;

        // L3/L4 overridden: the leader (at activity cap 1/1) may lead a second group.
        $this->assertSame('refusalleadcap', $api->gatekeeper()->can_create_group($leader)?->stringkey);
        store::save($activity, 'user', $leader, ['maxlead' => 2, 'maxmembership' => 2], 0);
        $api2 = new api($activity);
        $this->assertNull($api2->gatekeeper()->can_create_group($leader));
        $api2->create_group($leader, 'Second', 'T', '<p>b</p>', FORMAT_HTML);
        // At the overridden cap: a third is refused.
        $this->assertSame('refusalleadcap', (new api($activity))->gatekeeper()->can_create_group($leader)?->stringkey);

        // L2 overridden on the group: maxsize 4 -> 2; with the leader
        // confirmed and one invited the group is full at 2 seats.
        store::save($activity, 'group', (int) $group->id, ['maxsize' => 2], 0);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_INVITED,
        ]);
        $api3 = new api($activity);
        $this->assertSame(
            'refusalnoseats',
            $api3->gatekeeper()->can_invite($group, (int) $students[2]->id)?->stringkey
        );

        // L5 overridden per guide: activity cap 5 -> 1 for this guide.
        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, $activity->courseid(), 'teacher');
        store::save($activity, 'guide', (int) $guide->id, ['maxguided' => 1], 0);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[3]->id,
            'name' => 'Occupy',
            'state' => \mod_selfselectadvanced\local\state::PENDING_GUIDE,
            'guideid' => (int) $guide->id,
        ]);
        $this->assertSame(
            'refusalguidecap',
            (new api($activity))->gatekeeper()->can_take_guide((int) $guide->id)?->stringkey
        );

        // Quota exemption unblocks submission (gate a bypass, P11).
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'gender',
            'value' => 'Female',
            'mincount' => 1,
        ]);
        // The invited student from the L2 section accepts their seat.
        global $DB;
        $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_CONFIRMED, [
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
        ]);
        $fresh = groups::get($activity, (int) $group->id);
        $this->assertSame('refusalquota', (new api($activity))->gatekeeper()->can_submit($fresh, $leader)?->stringkey);
        store::save($activity, 'group', (int) $group->id, ['quotaexempt' => 1], 0);
        $this->assertNull((new api($activity))->gatekeeper()->can_submit($fresh, $leader));
    }
}
