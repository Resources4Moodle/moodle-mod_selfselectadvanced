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
 * A refusal the Fit column shows must be one the Accept button would give.
 *
 * WHAT THIS FILE USED TO PIN, and why the shape of it survives the ruling
 * that removed its subject.
 *
 * fit::for_person() used to set the membership-cap refusal aside for a request
 * that LEAVED a team - the move engine netted the swap, so carrying the cap
 * refusal made the Fit column disagree with the Accept button. The defect was
 * what the set-aside did NEXT: can_invite() answers with its FIRST refusal and
 * the cap check runs before the seat check, so a cap refusal meant the seat
 * question was never asked. The branch re-asked only the composition question
 * while its comment claimed the seat question too, and a capped student asking
 * to join a COMPLETELY FULL team was shown a green "fits" beside an Accept that
 * could only throw. Found by the 1.20.5 verification sweep on 2026-08-05, in a
 * fix shipped four days earlier with a comment asserting a completeness it did
 * not have.
 *
 * DECISION 77 (2026-08-09) deleted the set-aside outright: a join adds a
 * membership rather than trading one, so a capped student is capped and the
 * refusal is simply true. The property the file exists for is unchanged and is
 * what it now tests - the column and the button answer the same question - and
 * the one shape that could still break it is an old request row, filed before
 * the ruling, that still names a team to leave. The accept path ignores that
 * source. The Fit column must ignore it too, or the disagreement comes back on
 * exactly the sites that upgraded.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\fit
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class fit_setaside_completeness_test extends \advanced_testcase {
    /**
     * An old request naming a team to leave does not buy a green verdict.
     *
     * MUTATION CAUGHT (run 2026-08-10): restoring the set-aside - reading
     * $request->sourcegroupid in for_person() and skipping the cap refusal when
     * it is set - makes $verdict->fits come back TRUE for a request the Accept
     * button refuses, which is the 2026-08-05 defect returning through the
     * upgrade path.
     *
     * DISCRIMINATING: the second half proves the fixture can produce a POSITIVE
     * verdict, so a for_person() that had become "nothing ever fits" would fail
     * here rather than pass by accident.
     */
    public function test_a_capped_requester_is_refused_however_the_row_is_shaped(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // Room for two memberships, so the student can FILE the request that
        // this test then rewrites into the legacy shape. The cap is closed
        // below, once the row exists.
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxmembership' => 2,
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

        $request = joinrequests::request($activity, (int) $beta->id, 'Please let me in', (int) $wanderer->id);

        // Now make it exactly what an upgraded site holds: a waiting request
        // that names a team to leave, on an activity whose cap is one. Neither
        // half can be made through the service any more, which is the point.
        $DB->set_field('selfselectadvanced_move', 'sourcegroupid', (int) $alpha->id, ['id' => (int) $request->id]);
        $DB->set_field('selfselectadvanced', 'maxmembership', 1, ['id' => $activity->id()]);
        $capped = activity::from_instance($activity->id());
        $request = $DB->get_record('selfselectadvanced_move', ['id' => (int) $request->id]);

        $verdict = fit::for_person($capped, $beta, (int) $wanderer->id, $request);

        $this->assertFalse(
            $verdict->fits,
            'the Fit column netted the cap against a team acceptance will not take the student out '
                . 'of, so it says yes to a request the Accept button can only refuse'
        );
        $this->assertNotSame('', $verdict->caution, 'the refusal must carry a reason the leader can read');

        // POSITIVE CONTROL, in the same method deliberately: give the cap room
        // and the very same row fits. Without this a verdict that had become
        // "nothing ever fits" would satisfy the assertions above perfectly -
        // the vacuity this project refuses.
        $DB->set_field('selfselectadvanced', 'maxmembership', 2, ['id' => $activity->id()]);
        $roomy = activity::from_instance($activity->id());

        $this->assertTrue(
            fit::for_person($roomy, $beta, (int) $wanderer->id, $request)->fits,
            'with room for a second membership the same request must fit'
        );
    }
}
