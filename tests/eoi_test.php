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
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;

/**
 * Expressions of interest: a leader lists a forming team, guides
 * express interest and the leader (or a manager) decides in first
 * come, first served order. Covers express() and its refusals,
 * respond() acceptance (pre-assignment plus the auto-decline cascade)
 * and rejection (guide may express again afterwards), the leader or
 * manager actor guard, stepout(), expire_due(), counts(), the
 * FCFS ordering for_group() leaves to its callers, and the submit-path
 * hookup in local\state that honours a pre-assigned guide without a
 * picker id.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\eoi
 * @covers     \mod_selfselectadvanced\local\state
 */
final class eoi_test extends \advanced_testcase {
    /**
     * Create a course, an activity instance, enrolled students and
     * enrolled guides. Expressions of interest are enabled by default;
     * pass eoienabled => 0 in overrides to test the disabled path.
     *
     * @param array $overrides instance setting overrides
     * @param int $students number of enrolled students
     * @param int $guidecount number of enrolled guides (teacher role)
     * @return array{0: activity, 1: \stdClass[], 2: \stdClass[]} activity, students, guides
     */
    private function setup_activity(array $overrides = [], int $students = 2, int $guidecount = 2): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'eoienabled' => 1,
        ], $overrides));
        $activity = activity::from_instance((int) $instance->id);

        $studentusers = [];
        for ($i = 0; $i < $students; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $studentusers[] = $student;
        }
        $guideusers = [];
        for ($i = 0; $i < $guidecount; $i++) {
            $guide = $generator->create_user();
            $generator->enrol_user($guide->id, $course->id, 'teacher');
            $guideusers[] = $guide;
        }

        return [$activity, $studentusers, $guideusers];
    }

    /**
     * The plugin generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * Create a forming group and list it for guide interest, bypassing
     * the leader-facing toggle since that surface belongs to another
     * agent's owned files.
     *
     * @param activity $activity the activity
     * @param int $leaderid the leader
     * @param string $name group name
     * @return \stdClass the fresh, listed group row
     */
    private function listed_group(activity $activity, int $leaderid, string $name): \stdClass {
        global $DB;

        $group = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderid,
            'name' => $name,
        ]);
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'timelisted', time(), ['id' => $group->id]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * Assert that a callback throws a moodle_exception with the given
     * error code.
     *
     * @param string $errorcode expected error code
     * @param callable $callback the call expected to refuse
     */
    private function assert_refusal(string $errorcode, callable $callback): void {
        try {
            $callback();
            $this->fail('Expected a moodle_exception with error code ' . $errorcode);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * (1) Expressing interest on a listed forming team succeeds: a
     * pending row is written with the remarks and format given, and the
     * leader is notified.
     */
    public function test_express_on_listed_group_succeeds_and_notifies(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Alpha');

        $messagesink = $this->redirectMessages();
        $id = eoi::express($activity, (int) $group->id, $guide, '<p>We would love to guide this team.</p>', FORMAT_HTML);
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $row = $DB->get_record('selfselectadvanced_eoi', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(eoi::STATUS_PENDING, $row->status);
        $this->assertSame((int) $group->id, (int) $row->groupid);
        $this->assertSame($guide, (int) $row->guideid);
        $this->assertSame('<p>We would love to guide this team.</p>', $row->remarks);
        $this->assertSame((int) FORMAT_HTML, (int) $row->remarksformat);
        $this->assertNull($row->timeresponded);

        $leadermsgs = array_values(array_filter(
            $messages,
            fn($m) => (int) $m->useridto === $leader && $m->eventtype === 'eoireceived'
        ));
        $this->assertNotEmpty($leadermsgs);
        $this->assertStringContainsString('Alpha', $leadermsgs[0]->fullmessage);
    }

    /**
     * (2) Every express() refusal: the feature switched off, the
     * target group not listed, a duplicate pending interest for the
     * same pair, the guide's open-interest cap (eoimax) and the
     * guide's commitment capacity (eoifull) with maxguided 1 and one
     * already-guided group.
     */
    public function test_express_refusals(): void {
        $this->resetAfterTest();

        // The eoienabled setting is off.
        [$activity, $students, $guides] = $this->setup_activity(['eoienabled' => 0]);
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Off');
        $this->assert_refusal(
            'refusaleoidisabled',
            fn() => eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML)
        );

        // Unlisted (never listed) forming group.
        [$activity2, $students2, $guides2] = $this->setup_activity();
        $leader2 = (int) $students2[0]->id;
        $guide2 = (int) $guides2[0]->id;
        $unlisted = $this->plugingen()->create_group([
            'activityid' => $activity2->id(),
            'leaderid' => $leader2,
            'name' => 'Unlisted',
        ]);
        $this->assert_refusal(
            'refusaleoinotlisted',
            fn() => eoi::express($activity2, (int) $unlisted->id, $guide2, '', FORMAT_HTML)
        );

        // Duplicate pending interest for the same group and guide.
        $groupdup = $this->listed_group($activity2, $leader2, 'Dup');
        eoi::express($activity2, (int) $groupdup->id, $guide2, '', FORMAT_HTML);
        $this->assert_refusal(
            'refusaleoidup',
            fn() => eoi::express($activity2, (int) $groupdup->id, $guide2, '', FORMAT_HTML)
        );

        // The eoimax cap is reached: a single open interest fills a cap of 1.
        [$activity3, $students3, $guides3] = $this->setup_activity(['eoimax' => 1]);
        $guide3 = (int) $guides3[0]->id;
        $groupa = $this->listed_group($activity3, (int) $students3[0]->id, 'MaxA');
        $groupb = $this->listed_group($activity3, (int) $students3[1]->id, 'MaxB');
        eoi::express($activity3, (int) $groupa->id, $guide3, '', FORMAT_HTML);
        $this->assert_refusal(
            'refusaleoimax',
            fn() => eoi::express($activity3, (int) $groupb->id, $guide3, '', FORMAT_HTML)
        );

        // Zero remaining commitment capacity: maxguided 1, one guided group.
        [$activity4, $students4, $guides4] = $this->setup_activity(['maxguided' => 1]);
        $guide4 = (int) $guides4[0]->id;
        $this->plugingen()->create_group([
            'activityid' => $activity4->id(),
            'leaderid' => (int) $students4[0]->id,
            'name' => 'Occupies',
            'state' => state::PENDING_GUIDE,
            'guideid' => $guide4,
        ]);
        $groupfull = $this->listed_group($activity4, (int) $students4[1]->id, 'Full');
        $this->assert_refusal(
            'refusaleoifull',
            fn() => eoi::express($activity4, (int) $groupfull->id, $guide4, '', FORMAT_HTML)
        );
    }

    /**
     * (3) Accepting pre-assigns the guide on the group row, marks the
     * accepted row accepted, and auto-rejects every other pending
     * interest for the group, freeing the other guide's open slot.
     */
    public function test_respond_accept_preassigns_and_autorejects_others(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guidea = (int) $guides[0]->id;
        $guideb = (int) $guides[1]->id;
        $group = $this->listed_group($activity, $leader, 'Contested');

        $ida = eoi::express($activity, (int) $group->id, $guidea, '', FORMAT_HTML);
        $idb = eoi::express($activity, (int) $group->id, $guideb, '', FORMAT_HTML);

        $messagesink = $this->redirectMessages();
        eoi::respond($activity, $ida, true, $leader);
        $messages = $messagesink->get_messages();
        $messagesink->close();

        $fresh = groups::get($activity, (int) $group->id);
        $this->assertSame($guidea, (int) $fresh->guideid);

        $rowa = $DB->get_record('selfselectadvanced_eoi', ['id' => $ida], '*', MUST_EXIST);
        $rowb = $DB->get_record('selfselectadvanced_eoi', ['id' => $idb], '*', MUST_EXIST);
        $this->assertSame(eoi::STATUS_ACCEPTED, $rowa->status);
        $this->assertSame(eoi::STATUS_REJECTED, $rowb->status);
        $this->assertNotEmpty($rowa->timeresponded);
        $this->assertNotEmpty($rowb->timeresponded);

        // Guide B's open-interest slot is free again.
        $this->assertSame(0, $DB->count_records('selfselectadvanced_eoi', [
            'guideid' => $guideb,
            'status' => eoi::STATUS_PENDING,
        ]));

        $amsgs = array_values(array_filter($messages, fn($m) => (int) $m->useridto === $guidea));
        $bmsgs = array_values(array_filter($messages, fn($m) => (int) $m->useridto === $guideb));
        $this->assertNotEmpty($amsgs);
        $this->assertNotEmpty($bmsgs);
        $this->assertSame('eoiresult', $amsgs[0]->eventtype);
        $this->assertSame('eoiresult', $bmsgs[0]->eventtype);
    }

    /**
     * (4) Rejecting leaves the group guideless, and the guide may
     * express interest again afterwards without hitting the duplicate
     * refusal, since the prior row is no longer pending.
     */
    public function test_respond_reject_keeps_guideless_and_allows_re_express(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Rejected');
        $id = eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML);

        $messagesink = $this->redirectMessages();
        eoi::respond($activity, $id, false, $leader);
        $messagesink->close();

        $this->assertSame(eoi::STATUS_REJECTED, $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $id]));
        $this->assertNull(groups::get($activity, (int) $group->id)->guideid);

        $again = eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML);
        $this->assertNotSame($id, $again);
        $this->assertSame(eoi::STATUS_PENDING, $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $again]));
    }

    /**
     * (5) Only the group leader or a manage-capability holder may
     * respond; a bystander is refused and a manager succeeds.
     */
    public function test_respond_requires_leader_or_manager(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $bystander = (int) $students[1]->id;
        $guide = (int) $guides[0]->id;
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');

        $group = $this->listed_group($activity, $leader, 'Guarded');
        $id = eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML);

        $this->assert_refusal('refusalnotleader', fn() => eoi::respond($activity, $id, true, $bystander));

        $messagesink = $this->redirectMessages();
        eoi::respond($activity, $id, true, (int) $manager->id);
        $messagesink->close();

        $this->assertSame(eoi::STATUS_ACCEPTED, $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $id]));
        $this->assertSame($guide, (int) groups::get($activity, (int) $group->id)->guideid);
    }

    /**
     * (6) Stepping out clears the pre-assigned guide, returns the
     * accepted interest to history as withdrawn, and the team stays
     * listed so it is offered to guides again.
     */
    public function test_stepout_clears_guide_and_keeps_listing(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Steppedout');
        $id = eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML);
        eoi::respond($activity, $id, true, $leader);

        $messagesink = $this->redirectMessages();
        eoi::stepout($activity, (int) $group->id, $guide);
        $messagesink->close();

        $fresh = groups::get($activity, (int) $group->id);
        $this->assertNull($fresh->guideid);
        $this->assertSame(1, (int) $fresh->listed);
        $this->assertSame(eoi::STATUS_WITHDRAWN, $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $id]));

        $listedids = array_map(fn($g) => (int) $g->id, eoi::listed_groups($activity));
        $this->assertContains((int) $group->id, $listedids);
    }

    /**
     * (7) The expiry sweep expires only pending rows older than the
     * activity's window and reports exactly how many it moved.
     */
    public function test_expire_due_expires_only_overdue_rows(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity(['eoiwindow' => HOURSECS]);
        $groupold = $this->listed_group($activity, (int) $students[0]->id, 'Old');
        $groupnew = $this->listed_group($activity, (int) $students[1]->id, 'New');
        $idold = eoi::express($activity, (int) $groupold->id, (int) $guides[0]->id, '', FORMAT_HTML);
        $idnew = eoi::express($activity, (int) $groupnew->id, (int) $guides[1]->id, '', FORMAT_HTML);

        $DB->set_field('selfselectadvanced_eoi', 'timecreated', time() - HOURSECS - 10, ['id' => $idold]);

        $messagesink = $this->redirectMessages();
        $count = eoi::expire_due($activity);
        $messagesink->close();

        $this->assertSame(1, $count);
        $this->assertSame(eoi::STATUS_EXPIRED, $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $idold]));
        $this->assertSame(eoi::STATUS_PENDING, $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $idnew]));
    }

    /**
     * (8) counts() tallies guiding, pending, expired and rejected
     * correctly for one guide across the activity's history.
     */
    public function test_counts_tallies_by_status(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity(['eoiwindow' => HOURSECS]);
        $generator = $this->getDataGenerator();
        $guide = (int) $guides[0]->id;

        // Guiding: an already-assigned pending_guide group.
        $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Guiding',
            'state' => state::PENDING_GUIDE,
            'guideid' => $guide,
        ]);

        // Pending: one open interest, left undecided.
        $grouppending = $this->listed_group($activity, (int) $students[1]->id, 'Pending');
        eoi::express($activity, (int) $grouppending->id, $guide, '', FORMAT_HTML);

        // Rejected: the leader declines.
        $studentr = $generator->create_user();
        $generator->enrol_user($studentr->id, $activity->courseid(), 'student');
        $grouprejected = $this->listed_group($activity, (int) $studentr->id, 'Rejected');
        $idrejected = eoi::express($activity, (int) $grouprejected->id, $guide, '', FORMAT_HTML);
        eoi::respond($activity, $idrejected, false, (int) $studentr->id);

        // Expired: aged past the response window and swept.
        $studente = $generator->create_user();
        $generator->enrol_user($studente->id, $activity->courseid(), 'student');
        $groupexpired = $this->listed_group($activity, (int) $studente->id, 'Expired');
        $idexpired = eoi::express($activity, (int) $groupexpired->id, $guide, '', FORMAT_HTML);
        $DB->set_field('selfselectadvanced_eoi', 'timecreated', time() - HOURSECS - 10, ['id' => $idexpired]);

        $messagesink = $this->redirectMessages();
        eoi::expire_due($activity);
        $messagesink->close();

        $counts = eoi::counts($activity, $guide);
        $this->assertSame(1, $counts->guiding);
        $this->assertSame(1, $counts->pending);
        $this->assertSame(1, $counts->expired);
        $this->assertSame(1, $counts->rejected);
    }

    /**
     * (9) Sequential display is a caller rule, not something for_group()
     * enforces itself: this asserts the underlying guarantee callers
     * rely on to slice the list - first come, first served ordering by
     * timecreated, not by row id or insertion order.
     */
    public function test_for_group_orders_by_timecreated_fcfs(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $group = $this->listed_group($activity, $leader, 'Fcfs');

        // Guide B expresses first (lower id) but is backdated later;
        // guide A expresses second (higher id) but is backdated
        // earlier. The order returned must follow timecreated only.
        $idb = eoi::express($activity, (int) $group->id, (int) $guides[1]->id, '', FORMAT_HTML);
        $ida = eoi::express($activity, (int) $group->id, (int) $guides[0]->id, '', FORMAT_HTML);
        $DB->set_field('selfselectadvanced_eoi', 'timecreated', time() + 1000, ['id' => $idb]);
        $DB->set_field('selfselectadvanced_eoi', 'timecreated', time() - 1000, ['id' => $ida]);

        $rows = eoi::for_group($activity, (int) $group->id);
        $this->assertCount(2, $rows);
        $this->assertSame($ida, (int) $rows[0]->id);
        $this->assertSame($idb, (int) $rows[1]->id);
    }

    /**
     * (10) A forming group whose guide was pre-assigned through
     * acceptance submits without a picker guide id and lands
     * pending_guide with that pre-assigned guide, per the submit-path
     * hookup in local\state.
     */
    public function test_submit_uses_preassigned_guide_without_picker(): void {
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Assigned');
        $id = eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML);

        $messagesink = $this->redirectMessages();
        eoi::respond($activity, $id, true, $leader);
        $messagesink->close();

        $preassigned = groups::get($activity, (int) $group->id);
        $this->assertSame($guide, (int) $preassigned->guideid);

        $api = new api($activity);
        $messagesink = $this->redirectMessages();
        $submitted = $api->lifecycle()->submit($preassigned, null, $leader);
        $messagesink->close();

        $this->assertSame(state::PENDING_GUIDE, $submitted->state);
        $this->assertSame($guide, (int) $submitted->guideid);
        $this->assertNotEmpty($submitted->timesubmitted);
    }

    /**
     * A guide filled to capacity through an accepted interest must not
     * be offered to, or accepted for, any other group; the pre-assigned
     * team itself still submits, because its own row is excluded from
     * the count. Regression cover for the capacity audit finding.
     */
    public function test_accepted_interest_counts_against_capacity_everywhere(): void {
        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity(['maxguided' => 1], 2, 1);
        $leadera = (int) $students[0]->id;
        $leaderb = (int) $students[1]->id;
        $guide = (int) $guides[0]->id;

        $groupa = $this->listed_group($activity, $leadera, 'Team A');
        $groupb = $this->listed_group($activity, $leaderb, 'Team B');

        $messagesink = $this->redirectMessages();
        $id = eoi::express($activity, (int) $groupa->id, $guide, '', FORMAT_HTML);
        eoi::respond($activity, $id, true, $leadera);
        $messagesink->close();

        // The gatekeeper now counts the forming pre-assignment, so the
        // other leader cannot submit to this guide.
        $api = new api($activity);
        $refusal = $api->gatekeeper()->can_take_guide($guide);
        $this->assertNotNull($refusal);
        $this->assertSame('refusalguidecap', $refusal->stringkey);

        // The picker agrees: the guide has no remaining slot.
        $loads = \mod_selfselectadvanced\local\guides::with_load($activity, $api->gatekeeper()->resolver());
        $this->assertSame(0, $loads[$guide]->remaining);

        // Submitting team B with the guide refuses outright.
        try {
            $api->lifecycle()->submit($groupb, $guide, $leaderb);
            $this->fail('Expected refusalguidecap');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalguidecap', $e->errorcode);
        }

        // The pre-assigned team itself is excluded from its own count
        // and submits cleanly.
        $messagesink = $this->redirectMessages();
        $submitted = $api->lifecycle()->submit(groups::get($activity, (int) $groupa->id), null, $leadera);
        $messagesink->close();
        $this->assertSame(state::PENDING_GUIDE, $submitted->state);
        $this->assertSame($guide, (int) $submitted->guideid);
    }
}
