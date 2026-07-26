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
use mod_selfselectadvanced\local\guides;
use mod_selfselectadvanced\local\override\effective_value;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\volunteering;

/**
 * Guide volunteering (1.7.0): the resolver's consultation of a guide's
 * own declared capacity, the manager-override precedence exception,
 * write-time validation boundaries, with_load() picker filtering, and
 * the grandfathering guarantee that a reduced number never unassigns
 * existing groups.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\volunteering
 * @covers     \mod_selfselectadvanced\local\override\resolver
 * @covers     \mod_selfselectadvanced\local\guides
 */
final class volunteer_test extends \advanced_testcase {
    /**
     * Create a course, instance and an enrolled guide.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, guide user]
     */
    private function setup_activity(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'maxguided' => 5,
        ], $settings));
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        return [activity::from_instance((int) $instance->id), $guide];
    }

    /**
     * (a) Feature off: effective_maxguided is unchanged, whether or
     * not a volunteer row exists.
     */
    public function test_feature_off_unaffected(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 0]);

        $resolver = new resolver($activity);
        $this->assertSame(5, $resolver->effective_maxguided((int) $guide->id)->value);
        $this->assertSame(effective_value::SOURCE_ACTIVITY, $resolver->effective_maxguided((int) $guide->id)->source);

        volunteering::set($activity, (int) $guide->id, 2);
        $resolver = new resolver($activity);
        $this->assertSame(5, $resolver->effective_maxguided((int) $guide->id)->value);
        $this->assertSame(effective_value::SOURCE_ACTIVITY, $resolver->effective_maxguided((int) $guide->id)->source);
    }

    /**
     * (b) Feature on, no volunteer row: effective cap is 0.
     */
    public function test_feature_on_no_row_is_zero(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 1]);

        $this->assertNull(volunteering::get($activity, (int) $guide->id));
        $value = (new resolver($activity))->effective_maxguided((int) $guide->id);
        $this->assertSame(0, $value->value);
        $this->assertSame(effective_value::SOURCE_VOLUNTEER, $value->source);
    }

    /**
     * (c) Feature on, volunteered n: effective cap is min(n, N),
     * whether n sits below or exactly at N.
     */
    public function test_feature_on_row_caps_at_min(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 1, 'maxguided' => 5]);

        volunteering::set($activity, (int) $guide->id, 3);
        $this->assertSame(3, (new resolver($activity))->effective_maxguided((int) $guide->id)->value);

        volunteering::set($activity, (int) $guide->id, 5);
        $this->assertSame(5, (new resolver($activity))->effective_maxguided((int) $guide->id)->value);
    }

    /**
     * (d) An active manager guide-scope maxguided override always
     * wins over the volunteered number, in both directions (lower and
     * higher than what was volunteered).
     */
    public function test_manager_override_wins_over_volunteer(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 1, 'maxguided' => 5]);

        volunteering::set($activity, (int) $guide->id, 4);
        store::save($activity, 'guide', (int) $guide->id, ['maxguided' => 2], 0);

        $value = (new resolver($activity))->effective_maxguided((int) $guide->id);
        $this->assertSame(2, $value->value);
        $this->assertSame(effective_value::SOURCE_GUIDE, $value->source);

        // The override is authoritative, not merely a ceiling: a
        // manager override HIGHER than the volunteered number wins too.
        store::save($activity, 'guide', (int) $guide->id, ['maxguided' => 9], 0);
        $this->assertSame(9, (new resolver($activity))->effective_maxguided((int) $guide->id)->value);
    }

    /**
     * (e) set() validates 0 <= capacity <= N: the boundaries (0 and N)
     * are accepted, one below (-1) and one above (N + 1) are refused.
     */
    public function test_set_validates_boundaries(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 1, 'maxguided' => 5]);

        volunteering::set($activity, (int) $guide->id, 0);
        $this->assertSame(0, (int) volunteering::get($activity, (int) $guide->id)->capacity);

        volunteering::set($activity, (int) $guide->id, 5);
        $this->assertSame(5, (int) volunteering::get($activity, (int) $guide->id)->capacity);

        try {
            volunteering::set($activity, (int) $guide->id, 6);
            $this->fail('Expected a moodle_exception for capacity above N.');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalvolunteercapacity', $e->errorcode);
        }

        try {
            volunteering::set($activity, (int) $guide->id, -1);
            $this->fail('Expected a moodle_exception for a negative capacity.');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalvolunteercapacity', $e->errorcode);
        }

        // The rejected attempts left the last valid value untouched.
        $this->assertSame(5, (int) volunteering::get($activity, (int) $guide->id)->capacity);
    }

    /**
     * set() validates against the manager-override-aware ceiling, not
     * the raw activity setting: an override of 2 rejects a volunteered
     * 3 even though the activity default is 5.
     */
    public function test_set_validates_against_override_ceiling(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 1, 'maxguided' => 5]);

        store::save($activity, 'guide', (int) $guide->id, ['maxguided' => 2], 0);

        volunteering::set($activity, (int) $guide->id, 2);
        $this->assertSame(2, (int) volunteering::get($activity, (int) $guide->id)->capacity);

        $this->expectException(\moodle_exception::class);
        volunteering::set($activity, (int) $guide->id, 3);
    }

    /**
     * (f) with_load() excludes non-volunteers when the feature is on,
     * and offers every guide again once the feature is switched off.
     */
    public function test_with_load_excludes_non_volunteers_when_on(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $guide1] = $this->setup_activity(['guidevolunteer' => 1, 'maxguided' => 5]);
        $generator = $this->getDataGenerator();
        $guide2 = $generator->create_user();
        $generator->enrol_user($guide2->id, $activity->courseid(), 'teacher');

        volunteering::set($activity, (int) $guide1->id, 2);
        // Guide2 never volunteers.

        $loads = guides::with_load($activity, new resolver($activity));
        $this->assertArrayHasKey((int) $guide1->id, $loads);
        $this->assertArrayNotHasKey((int) $guide2->id, $loads);

        // Manager target pickers ask for the unavailable guides too,
        // otherwise a non-volunteer could never be granted capacity.
        $managerview = guides::with_load($activity, new resolver($activity), true);
        $this->assertArrayHasKey((int) $guide1->id, $managerview);
        $this->assertArrayHasKey((int) $guide2->id, $managerview);

        // With the feature off, both guides are offered again.
        $DB->set_field('selfselectadvanced', 'guidevolunteer', 0, ['id' => $activity->id()]);
        $activityoff = activity::from_instance($activity->id());
        $loadsoff = guides::with_load($activityoff, new resolver($activityoff));
        $this->assertArrayHasKey((int) $guide1->id, $loadsoff);
        $this->assertArrayHasKey((int) $guide2->id, $loadsoff);
    }

    /**
     * A manager-overridden guide stays visible in with_load() even at
     * an explicit always-full 0, per precedence: only volunteer-driven
     * zeros are filtered out.
     */
    public function test_with_load_keeps_manager_overridden_guide_visible(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 1, 'maxguided' => 5]);

        store::save($activity, 'guide', (int) $guide->id, ['maxguided' => 0], 0);
        // The guide has not volunteered either; the override still wins.

        $loads = guides::with_load($activity, new resolver($activity));
        $this->assertArrayHasKey((int) $guide->id, $loads);
        $this->assertSame(0, $loads[(int) $guide->id]->remaining);
    }

    /**
     * Grandfathering: withdrawing (capacity 0) never unassigns an
     * existing guided group - it only blocks new assignments.
     */
    public function test_grandfathering_never_unassigns(): void {
        $this->resetAfterTest();
        [$activity, $guide] = $this->setup_activity(['guidevolunteer' => 1, 'maxguided' => 5]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leader = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($leader->id, $activity->courseid(), 'student');

        volunteering::set($activity, (int) $guide->id, 3);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Existing',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
        ]);
        $this->assertSame(1, groups::count_guiding($activity, (int) $guide->id));

        // Withdraw entirely: the existing group stays assigned.
        volunteering::set($activity, (int) $guide->id, 0);
        $this->assertSame(1, groups::count_guiding($activity, (int) $guide->id));

        // But the guide is unavailable for a NEW assignment.
        $api = new api($activity);
        $this->assertSame(
            'refusalguidecap',
            $api->gatekeeper()->can_take_guide((int) $guide->id)?->stringkey
        );
    }
}
