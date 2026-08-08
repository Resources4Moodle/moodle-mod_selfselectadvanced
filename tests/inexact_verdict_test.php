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
use mod_selfselectadvanced\local\quota\allocator;
use mod_selfselectadvanced\local\quota\evaluator;
use mod_selfselectadvanced\local\state;

/**
 * Decision 79: a machine may not quietly rewrite a group's rules on a
 * verdict it cannot prove.
 *
 * The seat solver falls back to a heuristic once a seat plan leaves its
 * envelope, and that heuristic can only UNDER-report how many seats a
 * roster fills - so an inexact shortfall may be no shortfall at all.
 * The guide auto-approval sweep used to answer such a shortfall by
 * writing a permanent quota exemption onto the group: a lasting
 * loosening of that group's own composition rules, authored by cron, to
 * excuse something possibly never true, indistinguishable afterwards
 * from an exception a human deliberately granted.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper::autoapprove_plan
 * @covers     \mod_selfselectadvanced\local\quota\evaluator
 */
final class inexact_verdict_test extends \advanced_testcase {
    /**
     * A submitted group with a guide, and a seat plan whose size decides
     * whether the solver can prove its answer.
     *
     * @param int $slots how many seats to demand across the plan
     * @return array [activity, api, group row]
     */
    private function world(int $slots): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 20,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        for ($i = 1; $i <= $slots; $i++) {
            $plugingen->create_slot([
                'activityid' => $activity->id(),
                'dimension' => 'department',
                'matchtype' => 'any',
                'mincount' => 5,
            ]);
        }

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Inexact',
            'state' => state::PENDING_GUIDE,
            'guideid' => (int) $guide->id,
            'timesubmitted' => time(),
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id)];
    }

    /**
     * A seat plan inside the solver's envelope is answered exactly, so a
     * genuine shortfall still gets the relief the deadline is for: the
     * group is approved and the exemption is recorded, as before.
     */
    public function test_a_proven_shortfall_still_gets_its_recorded_relief(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $group] = $this->world(2);

        $report = evaluator::evaluate($activity, (int) $group->id);
        $this->assertTrue($report->exact, 'fixture: this plan is inside the exact envelope');
        $this->assertFalse($report->compliant, 'fixture: and the group does not satisfy it');

        $plan = $api->gatekeeper()->autoapprove_plan($group);
        $this->assertNull($plan->refusal, 'a shortfall the engine can prove is still forced through');
        $this->assertSame(1, $plan->relief['quotaexempt'] ?? null, 'and the relief is written down');
    }

    /**
     * The same shortfall, on a plan the solver cannot answer exactly.
     * Nothing permanent is written: the sweep refuses, and the group is
     * left for a person, which is recoverable where a wrong exemption
     * is invisible and forever.
     *
     * MUTATION CAUGHT (run): removing the `!$report->exact` branch from
     * autoapprove_plan() mints the exemption again and fails both
     * assertions below.
     */
    public function test_an_unproven_shortfall_mints_nothing_and_refuses(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        // Past MAX_SLOTS, so the solver declines the exact search.
        [$activity, $api, $group] = $this->world(allocator::MAX_SLOTS + 1);

        $report = evaluator::evaluate($activity, (int) $group->id);
        $this->assertFalse($report->exact, 'fixture: this plan is outside the exact envelope');

        $plan = $api->gatekeeper()->autoapprove_plan($group);
        $this->assertSame(
            'refusalquotainexact',
            $plan->refusal?->stringkey,
            'an unproven shortfall must not be forced through the deadline'
        );
        $this->assertArrayNotHasKey(
            'quotaexempt',
            $plan->relief,
            'and above all it must not leave a permanent exemption behind it'
        );
    }

    /**
     * The flag the whole ruling rests on used to stop at the solver.
     * Counting rules are always exact; only the seat plan can be
     * uncertain, and the report must say so rather than presenting a
     * heuristic as a proof.
     */
    public function test_the_report_carries_the_solvers_certainty(): void {
        $this->resetAfterTest();
        [$inside, , $insidegroup] = $this->world(2);
        [$outside, , $outsidegroup] = $this->world(allocator::MAX_SLOTS + 1);

        $this->assertTrue(
            evaluator::evaluate($inside, (int) $insidegroup->id)->exact,
            'a plan inside the envelope is a proof'
        );
        $this->assertFalse(
            evaluator::evaluate($outside, (int) $outsidegroup->id)->exact,
            'and one outside it is a lower bound wearing the same shape'
        );
    }
}
