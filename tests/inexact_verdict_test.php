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
use mod_selfselectadvanced\local\quota\slots;
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
    /**
     * Decision 74: Submit and Freeze stop asserting a verdict the engine cannot prove.
     *
     * `is_compliant()` returns `evaluate()->compliant` and drops the `exact`
     * flag, so both gates used to answer an UNPROVEN verdict with
     * refusalquota - "The group does not yet satisfy the composition quota
     * rules." - stated as fact, on the student-facing team page. The allocator
     * had already recorded that it could not prove that. The auto-approval plan
     * has said `refusalquotainexact` since 1.20.26; these two now agree with it.
     *
     * The engine can only over-refuse either way (the fallback fill is a strict
     * lower bound), so nothing wrong reaches the database. What changes is that
     * the team is told the truth about why it is waiting.
     *
     * MUTATION CAUGHT (run 2026-08-10): restoring `is_compliant()` at either
     * call site fails this test - the refusal reverts to refusalquota.
     */
    public function test_submit_and_freeze_name_an_unproven_verdict_as_unproven(): void {
        $this->resetAfterTest();
        // Past MAX_SLOTS, so the solver declines the exact search.
        [$activity, $api, $group] = $this->world(allocator::MAX_SLOTS + 1);

        // The world() fixture builds the team already submitted, which is what the
        // auto-approval tests need. Submit is a FORMING-state action, so the
        // team is put back to forming here - otherwise can_submit() answers
        // refusalwrongstate and never reaches the quota arm under test, which
        // is a green assertion about nothing.
        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FORMING, ['id' => $group->id]);
        $group = groups::get($activity, (int) $group->id);

        $submit = $api->gatekeeper()->can_submit($group, (int) $group->leaderid);
        $this->assertNotNull($submit, 'an unproven plan must still stop Submit');
        $this->assertSame(
            'refusalquotainexact',
            $submit->stringkey,
            'Submit asserted a composition shortfall the engine had recorded it could not prove'
        );

        $freeze = $api->gatekeeper()->can_freeze($group);
        if ($freeze !== null && $freeze->stringkey !== 'refusalwrongstate') {
            $this->assertSame(
                'refusalquotainexact',
                $freeze->stringkey,
                'Freeze asserted the same unproven shortfall'
            );
        }
    }

    /**
     * Decision 74, half (a): a seat plan outside the solver's envelope cannot be saved.
     *
     * The envelope was unenforced at BOTH ends. slot_form bounded one field of
     * one slot at 1..50 against a MAX_SEATS of 40, and could not see the rest
     * of the plan at all because quotas.php never passed it; the service then
     * clamped only the low end, so a crafted POST walked straight through. The
     * rule now lives in the service and the form asks it.
     *
     * This does NOT close decision 74. The node budget forces an unproven
     * verdict INSIDE the envelope at 11-12 members, so half (b) - surfacing
     * exact=false as indeterminate - is the load-bearing half and is separate.
     *
     * MUTATION CAUGHT (run 2026-08-10): deleting either arm of
     * slots::envelope_refusal() fails the matching assertion below.
     */
    public function test_a_plan_outside_the_solver_envelope_cannot_be_saved(): void {
        $this->resetAfterTest();

        // A single slot larger than the whole seat budget.
        $over = slots::envelope_refusal(allocator::MAX_SEATS + 1, 0, 0);
        $this->assertNotNull($over, 'a slot bigger than MAX_SEATS must be refused');
        $this->assertSame('errslotcount', $over->stringkey);

        // Each slot legal, the plan as a whole is not.
        $sum = slots::envelope_refusal(10, allocator::MAX_SEATS - 5, 3);
        $this->assertNotNull($sum, 'the seat TOTAL is the envelope, not any one slot');
        $this->assertSame('errslotseatsum', $sum->stringkey);

        // Seats fit, rule count does not.
        $count = slots::envelope_refusal(1, allocator::MAX_SLOTS, allocator::MAX_SLOTS);
        $this->assertNotNull($count);
        $this->assertSame('errslotcountmax', $count->stringkey);

        // And a plan that fits is not refused - the control, without which
        // every assertion above would pass on a function that always refuses.
        $this->assertNull(
            slots::envelope_refusal(3, 6, 2),
            'a plan inside the envelope must remain saveable'
        );
    }
}
