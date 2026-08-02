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

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\penalty\gradebook;
use mod_selfselectadvanced\local\penalty\ledger;
use mod_selfselectadvanced\local\state;

/**
 * Sequence-of-joining grade decomposition (1.4.0): awards and
 * penalties bind to groups in joining order with stepwise clamping;
 * leader-majority incomplete shares; defaulter steps; auto-approval.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\penalty\gradebook
 * @covers     \mod_selfselectadvanced\task\guide_autoapprove
 */
final class gradebook_sequence_test extends \advanced_testcase {
    /**
     * Two groups joined in order, awards + incomplete leader share +
     * defaulter shortfall, stepwise clamp; feedback carries the steps.
     */
    public function test_sequence_awards_shares_defaulters(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'grade' => 100,
            'minsize' => 3,
            'maxmembership' => 3,
            'minmembership' => 3,
            'defaulterpenalty' => 7,
            'incompletepenalty' => 10,
            'leadershare' => 60,
            'timedue' => time() - DAYSECS,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $student = $generator->create_user();
        $mate = $generator->create_user();
        foreach ([$student, $mate] as $user) {
            $generator->enrol_user($user->id, $course->id, 'student');
        }
        // Awards are an authorised mutation since 1.20 (A-06), so the
        // fixture needs somebody entitled to make one: an editing
        // teacher holds :manage, which review.php's grading gate
        // (gatekeeper::can_grade_team) admits for exactly this reason.
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        // Group A: joined FIRST (leader), incomplete (2 < minsize 3).
        $a = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $student->id,
            'name' => 'Alpha',
            'state' => state::FIRM,
        ]);
        $DB->set_field('selfselectadvanced_member', 'timecreated', 1000, [
            'groupid' => $a->id, 'userid' => (int) $student->id,
        ]);
        $plugingen->create_member([
            'groupid' => $a->id,
            'userid' => (int) $mate->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        // Group B: joined SECOND (ordinary member), complete enough? 2
        // members < 3 minsize: also incomplete; member share applies.
        $b = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $mate->id,
            'name' => 'Beta',
            'state' => state::FIRM,
        ]);
        $bm = $plugingen->create_member([
            'groupid' => $b->id,
            'userid' => (int) $student->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $DB->set_field('selfselectadvanced_member', 'timecreated', 2000, ['id' => $bm->id]);
        $DB->set_field('selfselectadvanced_member', 'timeresponded', 0, ['id' => $bm->id]);

        // Both groups approved on time (no late penalty in the mix).
        foreach ([$a->id, $b->id] as $gid) {
            $DB->set_field('selfselectadvanced_group', 'timeapproved', time() - (2 * DAYSECS), ['id' => $gid]);
        }

        // Awards: Alpha 40, Beta 30.
        ledger::set_award($activity, groups::get($activity, (int) $a->id), 40.0, (int) $staff->id);
        ledger::set_award($activity, groups::get($activity, (int) $b->id), 30.0, (int) $staff->id);

        $computed = gradebook::compute_user($activity, (int) $student->id);
        // Step 1 Alpha (leader): +40 − 6 (60% of 10) = 34.
        // Step 2 Beta (member): +30 − 4 (40% of 10 / 1 other) = 60.
        // Step 3 defaulter (has 2 of 3): −7 = 53.
        $this->assertSame(53.0, $computed->grade);
        $this->assertCount(3, $computed->steps);
        $this->assertStringContainsString('Alpha', $computed->steps[0]);
        $this->assertStringContainsString('leader share', $computed->steps[0]);
        $this->assertStringContainsString('Beta', $computed->steps[1]);
        $this->assertStringContainsString('defaulter', strtolower($computed->steps[2]));

        // Joining order is the attribution target: flip the join times
        // and the FIRST step becomes Beta.
        $DB->set_field('selfselectadvanced_member', 'timecreated', 500, ['id' => $bm->id]);
        $flipped = gradebook::compute_user($activity, (int) $student->id);
        $this->assertStringContainsString('Beta', $flipped->steps[0]);
        $this->assertSame(53.0, $flipped->grade);

        // Clamp: an oversized award never exceeds grademax mid-run.
        ledger::set_award($activity, groups::get($activity, (int) $b->id), 500.0, (int) $staff->id);
        $clamped = gradebook::compute_user($activity, (int) $student->id);
        $this->assertLessThanOrEqual(100.0, $clamped->grade);

        // Classic model preserved: with NO awards anywhere the first
        // step starts from the base grade.
        ledger::set_award($activity, groups::get($activity, (int) $a->id), null, (int) $staff->id);
        ledger::set_award($activity, groups::get($activity, (int) $b->id), null, (int) $staff->id);
        $classic = gradebook::compute_user($activity, (int) $student->id);
        $this->assertStringContainsString('base', $classic->steps[0]);
        // 100 − 4 (member share in first-joined Beta) − 6 (leader share
        // Alpha) − 7 defaulter = 83.
        $this->assertSame(83.0, $classic->grade);
    }

    /**
     * The sweep auto-approves only enabled, overdue submissions.
     */
    public function test_guide_autoapprove_task(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'guidewindow' => DAYSECS,
            'guideautoapprove' => 1,
            'minsize' => 3,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        // The sweep stands in for a guide who failed to decide, so the
        // fixtures must carry one — guideless queue groups are skipped.
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');

        $overdue = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Overdue',
            'state' => state::PENDING_GUIDE,
        ]);
        $DB->set_field('selfselectadvanced_group', 'timesubmitted', time() - (2 * DAYSECS), ['id' => $overdue->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guide->id, ['id' => $overdue->id]);
        $fresh = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Fresh',
            'state' => state::PENDING_GUIDE,
        ]);
        $DB->set_field('selfselectadvanced_group', 'timesubmitted', time() - HOURSECS, ['id' => $fresh->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guide->id, ['id' => $fresh->id]);

        $task = new \mod_selfselectadvanced\task\guide_autoapprove();
        $this->expectOutputRegex('/auto-approved group/');
        $task->execute();

        $this->assertSame(state::FIRM, $DB->get_field('selfselectadvanced_group', 'state', ['id' => $overdue->id]));
        // Audit round 3 item 1: the forced approval of a below-minimum
        // group is EXPLAINED by a recorded group-scope override.
        $relief = $DB->get_record('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'group',
            'groupid' => $overdue->id,
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $relief->minsize);
        $this->assertSame(
            state::PENDING_GUIDE,
            $DB->get_field('selfselectadvanced_group', 'state', ['id' => $fresh->id])
        );
        $this->assertGreaterThan(0, (int) $DB->get_field('selfselectadvanced_group', 'timeapproved', ['id' => $overdue->id]));
    }
}
