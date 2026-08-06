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
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\fit;
use mod_selfselectadvanced\local\groups;

/**
 * A value-minimum and a distinct-minimum on ONE dimension interact,
 * and taking their max under-counted the seats a compliant completion
 * needs.
 *
 * FOUND IN LIVE USE by the maintainer, 2026-08-06, group 42 of "MDP
 * Teams of Five": rules "between 2 and 2 of department SCOPE" and "at
 * least 4 distinct departments", maxsize 5. A second SCE member was
 * admitted - no maximum caps SCE - because the bound compared
 * max(SCOPE shortfall 2, distinct shortfall 3) = 3 against 3 free
 * seats and called the completion reachable. But the two required
 * SCOPE members introduce only ONE new distinct value between them, so
 * the true need was 2 + 2 = 4 seats into 3: a dead end the engine's
 * own docblock promises to prevent ("under-counting would let a group
 * fill up into a dead end"). The historical tests never combined the
 * two rule kinds on one dimension, which is how the blind spot
 * survived every earlier audit.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\evaluator
 */
final class distinct_interaction_test extends \advanced_testcase {
    /**
     * The live activity's exact shape: five seats, SCOPE between 2 and
     * 2, at least 4 distinct departments, and an SCE leader.
     *
     * @return array [activity, api, team, student-maker]
     */
    private function live_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 5,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
            'maxcount' => 2,
        ]);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'distinct',
            'mincount' => 4,
        ]);

        $mk = function (string $dept) use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => 'BCL'], 2);

            return $user;
        };
        $leader = $mk('SCE');
        $team = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Alpha',
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $team->id), $mk];
    }

    /**
     * The maintainer's exact replay: the SECOND same-department member
     * is refused, because with them aboard the team would need two
     * SCOPE members AND two further distinct departments - four seats -
     * with only three left.
     *
     * MUTATION CAUGHT (run): restoring max(sum, distinctbound) in
     * evaluator::feasibility_from_data() re-admits them and this test
     * goes red.
     */
    public function test_a_second_same_department_member_is_a_dead_end_and_is_refused(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $mk] = $this->live_world();
        $ishaan = $mk('SCE');

        $door = fit::door_verdict($activity, $team, (int) $ishaan->id);
        $this->assertNotNull(
            $door->engine,
            'two SCOPE seats plus two further distinct departments cannot fit in three remaining seats'
        );

        $refusal = $api->gatekeeper()->can_invite($team, (int) $ishaan->id);
        $this->assertNotNull($refusal, 'the invite door must give the same answer');
        $this->assertSame('refusalcompositionunreachable', $refusal->stringkey);
        $sink->close();
    }

    /**
     * The activity's own intended shape STAYS reachable: with only the
     * SCE leader aboard, four seats hold two SCOPE plus two further
     * distinct departments exactly. The fix must refuse the dead end
     * without refusing the design.
     */
    public function test_the_intended_team_of_five_stays_reachable(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $mk] = $this->live_world();
        $scope = $mk('SCOPE');

        $door = fit::door_verdict($activity, $team, (int) $scope->id);
        $this->assertNull($door->hardmax);
        $this->assertNull(
            $door->engine,
            'SCE + 2 SCOPE + 2 further distinct departments is exactly five seats - the design must stay open'
        );
        $this->assertNull($api->gatekeeper()->can_invite($team, (int) $scope->id));
        $sink->close();
    }

    /**
     * And the full intended roster is COMPLIANT end to end: two SCOPE,
     * one SCE, two further departments - five members, four distinct
     * values, the maximum respected.
     */
    public function test_the_full_intended_roster_is_compliant(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $mk] = $this->live_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        foreach (['SCOPE', 'SCOPE', 'SMEC', 'SENSE'] as $dept) {
            $plugingen->create_member([
                'groupid' => (int) $team->id,
                'userid' => (int) $mk($dept)->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $roster = array_map(
            static fn($m) => (int) $m->userid,
            groups::get_roster((int) $team->id)
        );
        $this->assertCount(5, $roster, 'fixture: the full team of five');
        $this->assertTrue(
            \mod_selfselectadvanced\local\quota\evaluator::compliant_for_members($activity, $roster),
            'the roster the activity was designed for must satisfy every rule'
        );
        $sink->close();
    }

    /**
     * A value-minimum for a value ALREADY PRESENT introduces no new
     * distinct value, and the bound must know it: one SCOPE aboard, one
     * SCOPE still required, distinct still needs three NEW departments -
     * three fills plus one repeat is four seats, and only three remain
     * once a second SCE has taken one.
     */
    public function test_a_shortfall_of_an_already_present_value_adds_no_distinct(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $mk] = $this->live_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        // One SCOPE confirmed: SCOPE is present, its remaining shortfall
        // of one can no longer introduce a new value.
        $plugingen->create_member([
            'groupid' => (int) $team->id,
            'userid' => (int) $mk('SCOPE')->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $ishaan = $mk('SCE');

        // Roster would be SCE, SCOPE, SCE: needs 1 more SCOPE (repeat,
        // no new value) and 2 more distinct departments - three seats -
        // with only two left.
        $door = fit::door_verdict($activity, $team, (int) $ishaan->id);
        $this->assertNotNull(
            $door->engine,
            'a repeat fill contributes nothing to the distinct rule and the bound must count it so'
        );
        $sink->close();
    }
}
