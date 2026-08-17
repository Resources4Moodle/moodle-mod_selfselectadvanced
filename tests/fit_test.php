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

use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\fit;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\quota\slots;

/**
 * The selection rules a student sees when choosing a team to join.
 *
 * The maintainer's requirement, in their words: a student who does not
 * fit the group-formation logic is "listed with caution that the
 * student will not fit the requirements", and a team holding "the
 * particular seat that the student will fit if filled" says which seat
 * that is. So the two properties under test are that a misfit is
 * REPORTED rather than hidden, and that the named seat is the seat the
 * booking algorithm would actually give them.
 *
 * The plan is the live one: five members, two from department Computer
 * and three distinct sub-departments.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\fit
 */
final class fit_test extends \advanced_testcase {
    /**
     * An exactly-five activity with the seat plan and an attributed cast.
     *
     * @return array [activity, students keyed by shorthand, course]
     */
    private function setup_plan(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 5,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $cast = [
            'c1' => ['Computer', 'AI'],
            'c2' => ['Computer', 'ML'],
            'c3' => ['Computer', 'Hardware'],
            'p1' => ['Science', 'Physics'],
            'b1' => ['Science', 'Biology'],
            'k1' => ['Science', 'Chemistry'],
            'p2' => ['Science', 'Physics'],
        ];
        $students = [];
        foreach ($cast as $key => [$dept, $subdept]) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => $subdept], 2);
            $students[$key] = $user;
        }

        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        slots::create($activity, (object) [
            'mincount' => 3, 'dimension' => 'subdepartment', 'matchtype' => 'distinct', 'allowoverlap' => 0,
        ], (int) get_admin()->id);

        return [$activity, $students, $course];
    }

    /**
     * A team short of its second Computer member names that seat to the
     * Computer student who would fill it.
     */
    public function test_seat_waiting_is_named(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Seated: one Computer, two distinct Science sub-departments.
        // The plan still wants a second Computer.
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Alpha',
        ]);
        foreach (['p1', 'b1'] as $key) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => $students[$key]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $verdict = fit::for_person($activity, $group, (int) $students['c2']->id);

        $this->assertTrue($verdict->fits, 'A second Computer student fits a team wanting one');
        $this->assertSame('', $verdict->caution);
        $this->assertNotNull($verdict->seat, 'The waiting Computer seat must be named');
        $this->assertStringContainsStringIgnoringCase('computer', $verdict->seat);
    }

    /**
     * A student the composition rules cannot accommodate is REPORTED
     * with the reason, not silently dropped: the leader keeps the
     * choice, and the student is told why it is a hard sell.
     */
    public function test_a_misfit_is_reported_not_hidden(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // Seated: one Computer plus three distinct Science
        // sub-departments = four of five. The last seat MUST be the
        // second Computer, so a fourth Science student cannot be
        // accommodated.
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Beta',
        ]);
        foreach (['p1', 'b1', 'k1'] as $key) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => $students[$key]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $verdict = fit::for_person($activity, $group, (int) $students['p2']->id);

        $this->assertFalse($verdict->fits, 'A duplicate Science sub-department cannot take the Computer seat');
        $this->assertNotSame('', $verdict->caution, 'The reason must be stated, not left blank');
    }

    /**
     * The bulk path the picker uses must agree with the authoritative
     * per-person path, team by team. If these two ever drifted apart a
     * student would be shown one answer and given another.
     */
    public function test_bulk_agrees_with_the_gate(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $alpha = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Alpha',
        ]);
        $plugingen->create_member([
            'groupid' => $alpha->id,
            'userid' => $students['p1']->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $beta = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c3']->id,
            'name' => 'Team Beta',
        ]);
        foreach (['b1', 'k1'] as $key) {
            $plugingen->create_member([
                'groupid' => $beta->id,
                'userid' => $students[$key]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $candidate = (int) $students['c2']->id;
        $groups = [$alpha, $beta];
        $bulk = fit::for_groups($activity, $groups, $candidate);

        foreach ($groups as $group) {
            $single = fit::for_person($activity, $group, $candidate);
            $this->assertSame(
                $single->fits,
                $bulk[(int) $group->id]->fits,
                'Picker and gate disagree on whether ' . $group->name . ' fits'
            );
            $this->assertSame(
                $single->seat,
                $bulk[(int) $group->id]->seat,
                'Picker and gate name different seats for ' . $group->name
            );
        }
    }

    /**
     * The picker's cost must not grow with the number of teams it
     * annotates: this plugin is built for fifteen hundred teams and the
     * control fires on every keystroke. Judging four teams must cost no
     * more queries than judging one, plus a small fixed margin.
     */
    public function test_bulk_cost_does_not_grow_with_team_count(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $leaders = ['c1', 'c3', 'p1', 'b1'];
        $groups = [];
        foreach ($leaders as $index => $key) {
            $groups[] = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => $students[$key]->id,
                'name' => 'Team ' . $index,
            ]);
        }
        $candidate = (int) $students['c2']->id;

        // Warm anything the first call would populate once, so what is
        // measured is the marginal cost of the teams themselves.
        fit::for_groups($activity, [$groups[0]], $candidate);

        $before = $DB->perf_get_reads();
        fit::for_groups($activity, [$groups[0]], $candidate);
        $one = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        fit::for_groups($activity, $groups, $candidate);
        $four = $DB->perf_get_reads() - $before;

        $this->assertLessThanOrEqual(
            $one + 2,
            $four,
            "Judging four teams cost $four reads against $one for a single team: the picker is scaling per team"
        );
    }

    /**
     * A team that is not forming is reported as unavailable rather than
     * silently offered, and no seat is invented for it.
     */
    public function test_a_settled_team_reports_its_state(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_plan();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $students['c1']->id,
            'name' => 'Team Firm',
            'state' => \mod_selfselectadvanced\local\state::FIRM,
        ]);

        $bulk = fit::for_groups($activity, [$group], (int) $students['c2']->id);
        $verdict = $bulk[(int) $group->id];

        $this->assertFalse($verdict->fits);
        $this->assertNotSame('', $verdict->caution);
        $this->assertNull($verdict->seat, 'A team that is not forming has no seat to offer');
    }

    /**
     * When a candidate could complete either of two seats, the seat
     * they are told about is the LEAST restrictive one - the one more
     * people could fill - because the shortfall belongs on the seat
     * that is genuinely hard to fill.
     *
     * The team wants one Female and two Computer members and has a
     * single Computer male. The arriving Computer female completes the
     * Female seat OR the second Computer seat; both leave two seats
     * filled, and the Computer pair is the roomier of the two.
     */
    public function test_seat_named_is_the_most_constrained_available(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        manager::set((int) $leader->id, ['department' => 'Computer', 'gender' => 'Male'], 2);
        $candidate = $generator->create_user();
        $generator->enrol_user($candidate->id, $course->id, 'student');
        manager::set((int) $candidate->id, ['department' => 'Computer', 'gender' => 'Female'], 2);

        $female = slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'gender', 'matchtype' => 'value',
            'value' => 'Female', 'allowoverlap' => 1,
        ], (int) get_admin()->id);
        $computer = slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 1,
        ], (int) get_admin()->id);

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leader->id,
            'name' => 'Team Choice',
        ]);

        $verdict = fit::for_person($activity, $group, (int) $candidate->id);

        $this->assertTrue($verdict->fits);
        // REVERSED 2026-08-13 with the allocator's tie-break. This asserted the
        // roomier Computer seat. The candidate is the group's only Female, so
        // the Female seat is the one only they can fill; naming the roomier
        // seat told a leader the candidate would fill a seat two people could,
        // while the seat that needed them specifically stayed empty.
        $this->assertSame((int) $female->slotno, $verdict->seatno, 'The seat only this candidate can fill is named');
        $this->assertNotSame((int) $computer->slotno, $verdict->seatno);
        $this->assertStringContainsStringIgnoringCase('female', (string) $verdict->seat);
    }

    /**
     * The picker and the gate must reach the same verdict on combined
     * deficits. Two minima on one dimension need four further members;
     * two free seats cannot carry them, so both the row's caution and
     * the gate's refusal say so, with the same figures.
     */
    public function test_picker_agrees_with_the_gate_on_combined_deficits(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 7,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        foreach (['DeptA', 'DeptB'] as $value) {
            $plugingen->create_quota([
                'activityid' => $activity->id(),
                'dimension' => 'department',
                'rtype' => 'value',
                'value' => $value,
                'mincount' => 2,
            ]);
        }

        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => 'Elsewhere', 'gender' => 'Male'], 2);
            $students[] = $user;
        }

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Team Deficit',
        ]);
        for ($i = 1; $i < 4; $i++) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $students[$i]->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $candidate = (int) $students[4]->id;
        $dimension = get_string('attrdepartment', 'mod_selfselectadvanced');
        $expected = get_string(
            'refusalcompositionunreachable',
            'mod_selfselectadvanced',
            (object) [
                'missing' => 4,
                'free' => 2,
                // 1.20.18 (maintainer, "the message feels cryptic"):
                // the sentence names the CONCRETE unmet needs, in rule
                // priority order.
                'needed' => "2 more from {$dimension} DeptA; 2 more from {$dimension} DeptB",
            ]
        );

        $row = fit::for_groups($activity, [$group], $candidate)[(int) $group->id];
        $this->assertFalse($row->fits, 'A team whose composition is already out of reach cannot fit anybody');
        $this->assertSame($expected, $row->caution);

        $single = fit::for_person($activity, $group, $candidate);
        $this->assertFalse($single->fits, 'Picker and gate must not disagree');
        $this->assertSame($expected, $single->caution);
    }

    /**
     * The case that separates the two seat-naming algorithms: adding
     * the candidate RE-SEATS an incumbent, so the slot whose shortfall
     * drops is not the slot the candidate takes.
     *
     * The plan is three value rows - one Male seat, one Female seat,
     * three Science seats - and the team is three Science students, two
     * Male and one Female. With three of them and three Science seats
     * the best seating puts all three in Science and leaves the gender
     * rows empty. Add a fourth Science student who is Male and the best
     * seating changes shape: the Female incumbent moves OUT of Science
     * into the Female seat, and the candidate takes the Science seat
     * she vacated. Total fill rises by one, but the seat whose
     * SHORTFALL fell is the Female one - which this candidate cannot
     * occupy at all.
     *
     * The pre-1.20 algorithm named a seat by diffing shortfalls, so on
     * this shape it told a Male student he would fill the Female seat.
     * The current one reads the seat out of the canonical assignment,
     * which can only ever name a seat the candidate is actually in. The
     * whole pre-1.20 body could be restored with the suite green before
     * this test existed; the audit measured the two disagreeing on 466
     * of 2018 seat-naming cases.
     *
     * Negative control: restore the shortfall-diff body of
     * seat_from_data() - seatno comes back 2 (the Female seat) from
     * both entry points and the first two assertions fail.
     */
    public function test_a_re_seated_incumbent_does_not_move_the_candidates_seat(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $make = function (string $gender) use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['gender' => $gender, 'department' => 'Science'], 2);

            return $user;
        };
        $m1 = $make('Male');
        $m2 = $make('Male');
        $f1 = $make('Female');
        $candidate = $make('Male');

        // Slot numbers are assigned in creation order: 1, 2, 3.
        slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'gender', 'matchtype' => 'value',
            'value' => 'Male', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'gender', 'matchtype' => 'value',
            'value' => 'Female', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        slots::create($activity, (object) [
            'mincount' => 3, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Science', 'allowoverlap' => 0,
        ], (int) get_admin()->id);

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $m1->id,
            'name' => 'Re-seaters',
        ]);
        foreach ([$m2, $f1] as $member) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $member->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        $group = groups::get($activity, (int) $group->id);

        $row = fit::for_groups($activity, [$group], (int) $candidate->id)[(int) $group->id];
        $this->assertTrue($row->fits);
        $this->assertSame(3, $row->seatno, 'The candidate takes the Science seat the Female incumbent vacates');
        $this->assertStringContainsStringIgnoringCase('science', (string) $row->seat);

        // The gate's own answer must be the same answer.
        $single = fit::for_person($activity, $group, (int) $candidate->id);
        $this->assertSame($row->seatno, $single->seatno, 'Picker and gate must not disagree');
        $this->assertSame($row->seat, $single->seat);

        // THIS SHAPE IS NO LONGER A DIVERGENCE CASE, and the assertion below
        // that used to prove it was is gone rather than rewritten to match.
        //
        // The author of this test wrote: "If a future engine change stops
        // re-seating here, this assertion fails and says so rather than
        // letting the test quietly stop testing anything." On 2026-08-13 the
        // allocator's tie-break was reversed to most-constrained-first, and
        // that assertion failed exactly as promised. The reason is that the
        // new rule seats the Female incumbent in the Female seat from the
        // start, so the candidate's arrival re-seats nobody: measured here,
        // the Female seat's shortfall is now 0 both before and after, and the
        // Science seat's falls 1 -> 0.
        //
        // Updating the numbers would have left a test that passes while
        // demonstrating nothing, which is the failure its own comment was
        // written to prevent. What survives above is the assertion that
        // matters - fit names a seat the candidate is ACTUALLY in - and that
        // still fails if the shortfall-diff body is restored, because a Male
        // candidate cannot be in the Female seat under any seating.
        //
        // OWED, paid below by
        // test_a_second_incumbent_is_re_seated_by_a_stronger_specialist(): a
        // new fixture that re-seats an incumbent under the current tie-break,
        // to restore the divergence half of this guard.
        $template = slots::get_all($activity);
        $attrs = manager::get_for_users([(int) $m1->id, (int) $m2->id, (int) $f1->id, (int) $candidate->id]);
        $roster = [(int) $m1->id, (int) $m2->id, (int) $f1->id];
        $before = slots::evaluate_from_data($template, $roster, $attrs);
        $after = slots::evaluate_from_data($template, array_merge($roster, [(int) $candidate->id]), $attrs);
        $shortfall = static function (\stdClass $result, int $slotno): int {
            foreach ($result->slots as $entry) {
                if ((int) $entry->slot->slotno === $slotno) {
                    return (int) $entry->missing;
                }
            }

            return -1;
        };
        $this->assertSame($before->totalfilled + 1, $after->totalfilled, 'The candidate must raise the fill');
        $this->assertSame(
            0,
            $shortfall($before, 2),
            'the Female incumbent is seated in the Female seat from the start now, so nothing re-seats'
        );
    }

    /**
     * The divergence case owed above: a shape that genuinely re-seats an
     * incumbent under the CURRENT most-constrained-first rule (ledger
     * decision 101), where the shape above stops doing that.
     *
     * The plan is two seats: one wants 2 members who share any ONE
     * department (a generic "pair" seat, satisfiable by any department so
     * long as both agree); the other wants 2 members with sub-department AI
     * specifically. Seated: a Math/AI member and a Computer/AI member -
     * different departments, so the generic seat cannot use them together,
     * but both share sub-department AI, which is the more constrained seat
     * on a supply tie (a named value beats "any shared value"). Both are
     * seated in the AI seat; the generic seat sits empty, since a single
     * leftover person would only "share" a department with themselves.
     *
     * The candidate is a SECOND Computer/AI member. Filling the AI seat to
     * its capacity of two now has a genuine choice: keep the two incumbents
     * where they are and leave the candidate unseated, or seat the two
     * Computer/AI members (who actually share a department, so the generic
     * seat can also use whichever one of them is left over) and move the
     * Math incumbent into the generic seat, where she sits alone. The
     * second option fills three seats where the first fills only two, so it
     * is what the exact search finds - and it MOVES the Math incumbent, who
     * has held the AI seat since before the candidate existed, into the
     * seat that needed nobody in particular.
     *
     * PROOF IT BITES, measured 2026-08-17 on a copy with the constraint
     * ordering in allocator::build_ranks() inverted (restoring the pre-1.20
     * least-constrained-first tie-break): this test fails, and earlier than
     * the re-seat assertions below even get to run. With two members the
     * generic department seat and the AI seat tie on supply exactly as they
     * do today, but the inverted ordering now prefers the GENERIC seat on
     * that tie, so it is filled first - and it can only take ONE of the two
     * (Math, Computer do not share a department), leaving the AI seat's own
     * two AI-holders split one-and-one instead of both seated together.
     * `test_a_second_incumbent...` asserts the STARTING seating (both
     * incumbents in the AI seat, the generic seat empty) fails first:
     * "fixture: the department seat starts empty / Failed asserting that 1
     * is identical to 0." Adding the candidate then fills the generic seat
     * to capacity with the two Computer members and leaves the AI seat at
     * one instead of two - a seating that fills the same three seats as the
     * current engine but never lets the AI seat's own specialists fill it,
     * which is exactly the shape of defect decision 101 exists to prevent.
     */
    public function test_a_second_incumbent_is_re_seated_by_a_stronger_specialist(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $make = function (string $department) use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $department, 'subdepartment' => 'AI'], 2);

            return $user;
        };
        $math = $make('Math');
        $computer1 = $make('Computer');
        $candidate = $make('Computer');

        // Slot numbers assigned in creation order: 1 (department pair), 2 (AI).
        slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'department', 'matchtype' => 'value', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        $ai = slots::create($activity, (object) [
            'mincount' => 2, 'dimension' => 'subdepartment', 'matchtype' => 'value',
            'value' => 'AI', 'allowoverlap' => 0,
        ], (int) get_admin()->id);

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $math->id,
            'name' => 'Specialists',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $computer1->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $group = groups::get($activity, (int) $group->id);

        $template = slots::get_all($activity);
        $attrs = manager::get_for_users([(int) $math->id, (int) $computer1->id, (int) $candidate->id]);
        $roster = [(int) $math->id, (int) $computer1->id];

        // Fixture check (N > 0): both incumbents actually got seated, and
        // both landed in the AI seat, before the candidate is considered.
        $before = slots::evaluate_from_data($template, $roster, $attrs);
        $this->assertSame(2, $before->totalfilled, 'fixture: both incumbents must start seated');
        $this->assertSame(0, $before->slots[0]->filled, 'fixture: the department seat starts empty');
        $this->assertSame(2, $before->slots[1]->filled, 'fixture: both incumbents start in the AI seat');
        $this->assertSame(
            1,
            $before->assignment[(int) $math->id],
            'fixture: the Math incumbent starts in the AI seat (index 1)'
        );

        // The picker's own answer: the candidate is named into the AI seat.
        $row = fit::for_groups($activity, [$group], (int) $candidate->id)[(int) $group->id];
        $this->assertTrue($row->fits);
        $this->assertSame(
            (int) $ai->slotno,
            $row->seatno,
            'The candidate takes the AI seat alongside the other Computer/AI member'
        );
        $this->assertStringContainsStringIgnoringCase('ai', (string) $row->seat);

        // The gate's own answer must be the same answer.
        $single = fit::for_person($activity, $group, (int) $candidate->id);
        $this->assertSame($row->seatno, $single->seatno, 'Picker and gate must not disagree');
        $this->assertSame($row->seat, $single->seat);

        // The re-seat itself: the Math incumbent's own assignment moves.
        $after = slots::evaluate_from_data($template, array_merge($roster, [(int) $candidate->id]), $attrs);
        $this->assertSame(3, $after->totalfilled, 'the candidate must raise the fill');
        $this->assertSame(1, $after->slots[0]->filled, 'the department seat now carries the displaced incumbent');
        $this->assertSame(2, $after->slots[1]->filled, 'the AI seat stays at its capacity of two');
        $this->assertSame(
            0,
            $after->assignment[(int) $math->id],
            'the Math incumbent is RE-SEATED out of the AI seat into the department seat'
        );
        $this->assertSame(
            1,
            $after->assignment[(int) $computer1->id],
            'the Computer incumbent already in the AI seat stays put'
        );
        $this->assertSame(
            1,
            $after->assignment[(int) $candidate->id],
            'the candidate takes the AI seat the Math incumbent vacated'
        );
    }
}
