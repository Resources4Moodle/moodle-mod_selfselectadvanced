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
    public function test_seat_named_is_the_least_restrictive_available(): void {
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
        $this->assertSame((int) $computer->slotno, $verdict->seatno, 'The roomier Computer seat is the one named');
        $this->assertNotSame((int) $female->slotno, $verdict->seatno);
        $this->assertStringContainsStringIgnoringCase('computer', (string) $verdict->seat);
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

        // And this really is the divergence case: the shortfall that
        // falls belongs to slot 2, the Female seat, which is the seat
        // the old algorithm would have named. If a future engine change
        // stops re-seating here, this assertion fails and says so
        // rather than letting the test quietly stop testing anything.
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
        $this->assertGreaterThan($after->totalfilled - 1, $after->totalfilled);
        $this->assertSame($before->totalfilled + 1, $after->totalfilled, 'The candidate must raise the fill');
        $this->assertSame(1, $shortfall($before, 2), 'The Female seat is short before');
        $this->assertSame(0, $shortfall($after, 2), 'and full after - so shortfall-diff would name it');
        $this->assertSame(
            $shortfall($before, 3),
            $shortfall($after, 3),
            'while the Science seat the candidate actually takes shows no change in shortfall'
        );
    }
}
