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

use mod_selfselectadvanced\local\fit;
use mod_selfselectadvanced\local\joinrequests;

/**
 * Setting one refusal aside must not answer the questions it hid.
 *
 * fit::for_person() legitimately sets the membership-cap refusal aside
 * for a request that LEAVES a team: the move engine nets the swap, so
 * carrying the cap refusal would make the Fit column disagree with the
 * Accept button. The bug this file pins is what the set-aside used to
 * do NEXT. can_invite() answers with its FIRST refusal, and the cap
 * check runs BEFORE the seat check, so a cap refusal means the seat
 * question was never asked. The branch re-asked only the composition
 * question while its comment claimed it re-asked the seat question too.
 *
 * The visible consequence: a student at their cap requesting a team
 * that is COMPLETELY FULL was shown a green "fits", and the Accept
 * button then threw refusaljoinrules. Change maxmembership so the cap
 * refusal does not fire first and the same student, same team, is
 * correctly told there are no free seats - opposite verdicts on
 * identical facts, decided only by which refusal came first.
 *
 * Found by the 1.20.5 independent-review verification sweep on
 * 2026-08-05, in a fix this project had shipped four days earlier with
 * a comment asserting the completeness it did not have.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\fit
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class fit_setaside_completeness_test extends \advanced_testcase {
    /**
     * A capped student asking to move into a FULL team is told the team
     * is full - not that they fit.
     *
     * MUTATION CAUGHT (run against the pre-fix tree, 3580e56): with the
     * set-aside branch calling only composition_verdict_for_group(),
     * $verdict->fits comes back TRUE and the first assertion fails.
     *
     * DISCRIMINATING: the second half of the method proves the fixture
     * can produce a POSITIVE verdict, so a mutation that made
     * for_person() answer "does not fit" to everything would fail here
     * rather than passing this test by accident.
     */
    public function test_a_capped_requester_is_refused_by_a_full_target(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // A maxsize of 1 makes a team full the moment it has its leader;
        // maxmembership 1 makes the wanderer capped by their own team.
        // Both are needed: the defect only appears when the CAP refusal
        // pre-empts the SEAT refusal.
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $plugin = $generator->get_plugin_generator('mod_selfselectadvanced');

        $wanderer = $generator->create_user();
        $betalead = $generator->create_user();
        $generator->enrol_user($wanderer->id, $course->id, 'student');
        $generator->enrol_user($betalead->id, $course->id, 'student');

        $alpha = $plugin->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $wanderer->id,
            'name' => 'Alpha',
        ]);
        $beta = $plugin->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $betalead->id,
            'name' => 'Beta',
        ]);

        // The request carries the source team, which is what turns on
        // the set-aside branch at all.
        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Please let me in',
            (int) $wanderer->id,
            (int) $alpha->id
        );

        $verdict = fit::for_person($activity, $beta, (int) $wanderer->id, $request);

        $this->assertFalse(
            $verdict->fits,
            'a full team must not report that a capped requester fits: the seat question was '
                . 'pre-empted by the cap refusal and has to be re-asked, not assumed'
        );
        $this->assertNotSame(
            '',
            $verdict->caution,
            'the refusal must carry a reason the leader can read'
        );

        // POSITIVE CONTROL, in the same method deliberately: widen the
        // team by one seat and the very same request now fits. Without
        // this a verdict that had become "nothing ever fits" would
        // satisfy the assertions above perfectly - the vacuity this
        // project refuses.
        global $DB;
        $DB->set_field('selfselectadvanced', 'maxsize', 2, ['id' => $activity->id()]);
        $fresh = activity::from_instance($activity->id());

        $this->assertTrue(
            fit::for_person($fresh, $beta, (int) $wanderer->id, $request)->fits,
            'with a free seat the same capped requester must fit - the cap alone is netted by the '
                . 'move engine and must still be set aside'
        );
    }
}
