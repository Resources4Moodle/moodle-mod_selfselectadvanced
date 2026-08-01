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

use mod_selfselectadvanced\local\coordinatorimport;
use mod_selfselectadvanced\local\coordinatorrole;

/**
 * Appointing one coordinator at a time (strategy 1.18 D).
 *
 * The bulk upload has its own tests. What matters here is that the
 * single-person path enforces the SAME rules - they must already be
 * enrolled, and they must be a non-editing teacher - writes the
 * appointment where the capabilities are actually asked for, and emits
 * the same event and message, so the audit trail does not record two
 * kinds of appointment depending on which control somebody happened to
 * use.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\coordinatorimport
 */
final class coordinatorappoint_test extends \advanced_testcase {
    /**
     * An activity with an enrolled non-editing teacher, an enrolled
     * student, an enrolled editing teacher and a stranger.
     *
     * @return array [activity, teacher, stranger, coursecontext, course, student, editingteacher]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'CO1']);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $editingteacher = $generator->create_user();
        $generator->enrol_user($editingteacher->id, $course->id, 'editingteacher');
        $stranger = $generator->create_user();

        return [
            activity::from_instance((int) $instance->id),
            $teacher,
            $stranger,
            \context_course::instance($course->id),
            $course,
            $student,
            $editingteacher,
        ];
    }

    /**
     * Appointing somebody enrolled works, lands at the ACTIVITY with
     * this plugin's provenance on the row, is idempotent, and records
     * the event that the audit trail is built from.
     */
    public function test_appointing_an_enrolled_person(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $teacher, , $coursecontext] = $this->setup_world();
        $roleid = coordinatorrole::ensure();

        $events = $this->redirectEvents();
        coordinatorimport::appoint($activity, (int) $teacher->id);

        // The row is at the activity, and nowhere else.
        $rowparams = [
            'roleid' => $roleid,
            'contextid' => $activity->context()->id,
            'userid' => (int) $teacher->id,
            'component' => 'mod_selfselectadvanced',
            'itemid' => 0,
        ];
        $this->assertTrue($DB->record_exists('role_assignments', $rowparams));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $coursecontext->id,
            'userid' => (int) $teacher->id,
        ]));

        $filed = array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\coordinator_assigned
        );
        $this->assertCount(1, $filed);
        $events->close();

        // Twice is not an error and does not assign the role twice.
        coordinatorimport::appoint($activity, (int) $teacher->id);
        $this->assertSame(1, $DB->count_records('role_assignments', $rowparams));
        $this->assertCount(
            1,
            get_role_users($roleid, $activity->context(), true, 'u.id, u.firstname, u.lastname')
        );
        $sink->close();
    }

    /**
     * The appointment reaches the instance it was made in, and no
     * other. Two selfselectadvanced activities in one course are two
     * separate jobs, and until 1.20.0 one appointment did both.
     */
    public function test_appointment_is_scoped_to_the_instance(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activitya, $teacher, , , $course] = $this->setup_world();
        coordinatorrole::ensure();

        $second = $this->getDataGenerator()->create_module('selfselectadvanced', ['course' => $course->id]);
        $activityb = activity::from_instance((int) $second->id);

        coordinatorimport::appoint($activitya, (int) $teacher->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:coordinate',
            $activitya->context(),
            (int) $teacher->id
        ));
        $this->assertFalse(has_capability(
            'mod/selfselectadvanced:coordinate',
            $activityb->context(),
            (int) $teacher->id
        ));
        $sink->close();
    }

    /**
     * The rule from the order, enforced on this path too: somebody who
     * is not in the course cannot be made a coordinator of it.
     */
    public function test_a_stranger_cannot_be_appointed(): void {
        $this->resetAfterTest();
        [$activity, , $stranger] = $this->setup_world();

        try {
            coordinatorimport::appoint($activity, (int) $stranger->id);
            $this->fail('Expected a refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('coordinatorimportnotenrolled', $e->errorcode);
        }
    }

    /**
     * Eligibility is policy, not presentation: the service refuses a
     * student and an editing teacher, whatever the screen offered.
     */
    public function test_only_non_editing_teachers_may_be_appointed(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $teacher, , $coursecontext, , $student, $editingteacher] = $this->setup_world();
        $roleid = coordinatorrole::ensure();

        foreach ([$student, $editingteacher] as $refused) {
            try {
                coordinatorimport::appoint($activity, (int) $refused->id);
                $this->fail('Expected a refusal for user ' . $refused->id);
            } catch (\moodle_exception $e) {
                $this->assertSame('coordinatorineligible', $e->errorcode);
            }
            foreach ([$activity->context()->id, $coursecontext->id] as $contextid) {
                $this->assertFalse($DB->record_exists('role_assignments', [
                    'roleid' => $roleid,
                    'contextid' => $contextid,
                    'userid' => (int) $refused->id,
                ]));
            }
        }

        // The one group that may hold it still can.
        coordinatorimport::appoint($activity, (int) $teacher->id);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $activity->context()->id,
            'userid' => (int) $teacher->id,
        ]));
        $sink->close();
    }

    /**
     * The predicate is the role ARCHETYPE, never the shortname: sites
     * rename their non-editing-teacher role, and a shortname says
     * nothing about what a role can do.
     */
    public function test_eligibility_keys_on_archetype_not_shortname(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , , $coursecontext, $course, $student] = $this->setup_world();
        $generator = $this->getDataGenerator();
        $roleid = coordinatorrole::ensure();

        // Case (i): a renamed non-editing-teacher role. Archetype teacher,
        // shortname nothing like "teacher".
        $tutorroleid = create_role('Tutor', 'tutor', '', 'teacher');
        $tutor = $generator->create_user();
        $generator->enrol_user($tutor->id, $course->id, 'tutor');
        $this->assertContains((int) $tutor->id, coordinatorimport::eligible_userids($activity));
        coordinatorimport::appoint($activity, (int) $tutor->id);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $activity->context()->id,
            'userid' => (int) $tutor->id,
            'component' => 'mod_selfselectadvanced',
        ]));

        // Case (ii): a decoy - a course role with no archetype at all. It
        // teaches nothing and must not be eligible.
        $impostorroleid = create_role('Impostor', 'impostor', '', '');
        $this->assertGreaterThan(0, $impostorroleid);
        $impostor = $generator->create_user();
        $generator->enrol_user($impostor->id, $course->id, 'impostor');
        $this->assertNotContains((int) $impostor->id, coordinatorimport::eligible_userids($activity));
        try {
            coordinatorimport::appoint($activity, (int) $impostor->id);
            $this->fail('Expected a refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('coordinatorineligible', $e->errorcode);
        }

        // Case (iii): circularity. The coordinator role is itself archetype
        // teacher, so holding it must not be what qualifies somebody
        // for it - here through a legacy course-context grant.
        role_assign($roleid, (int) $student->id, $coursecontext->id);
        $this->assertNotContains((int) $student->id, coordinatorimport::eligible_userids($activity));

        // Case (iv): a teacher-archetype role granted through core's "Assign
        // roles in this activity": the row exists at the MODULE context
        // only, and searching from the course down cannot see it.
        $local = $generator->create_user();
        $generator->enrol_user($local->id, $course->id, 'student');
        role_assign($tutorroleid, (int) $local->id, $activity->context()->id);
        $this->assertContains((int) $local->id, coordinatorimport::eligible_userids($activity));
        coordinatorimport::appoint($activity, (int) $local->id);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $activity->context()->id,
            'userid' => (int) $local->id,
            'component' => 'mod_selfselectadvanced',
            'itemid' => 0,
        ]));
        $sink->close();
    }

    /**
     * Removing takes the role away and records it, and never touches
     * enrolment - a button in a table is not the place to remove
     * somebody from a course as a side effect. A legacy course-context
     * row goes too, or the screen would report somebody stood down
     * while they still held the role.
     */
    public function test_removing_leaves_enrolment_alone(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $teacher, , $coursecontext] = $this->setup_world();
        $roleid = coordinatorrole::ensure();

        coordinatorimport::appoint($activity, (int) $teacher->id);
        // Named, so this test cannot pass by removing a row the
        // appointment should never have written in the first place.
        $modrow = [
            'roleid' => $roleid,
            'contextid' => $activity->context()->id,
            'userid' => (int) $teacher->id,
            'component' => 'mod_selfselectadvanced',
            'itemid' => 0,
        ];
        $this->assertTrue($DB->record_exists('role_assignments', $modrow));

        $events = $this->redirectEvents();
        coordinatorimport::remove($activity, (int) $teacher->id);
        $removed = array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\coordinator_removed
        );
        $this->assertCount(1, $removed);
        $events->close();

        $this->assertFalse($DB->record_exists('role_assignments', $modrow));
        $this->assertFalse(user_has_role_assignment((int) $teacher->id, $roleid, $activity->context()->id));
        $this->assertTrue(is_enrolled($coursecontext, (int) $teacher->id));

        // Removing somebody who does not hold it is a no-op, not a crash.
        coordinatorimport::remove($activity, (int) $teacher->id);

        // A row an older release, or an administrator, left at the
        // course has to go as well.
        role_assign($roleid, (int) $teacher->id, $coursecontext->id);
        coordinatorimport::remove($activity, (int) $teacher->id);
        $this->assertFalse(user_has_role_assignment((int) $teacher->id, $roleid, $coursecontext->id));
        $this->assertFalse(user_has_role_assignment((int) $teacher->id, $roleid, $activity->context()->id));
        $this->assertTrue(is_enrolled($coursecontext, (int) $teacher->id));
        $sink->close();
    }

    /**
     * A grant made ABOVE the course is not this activity's to revoke,
     * and saying it has been is worse than leaving it alone: the event
     * feeds the audit trail and the message tells the person their role
     * has gone while they still hold it. Since the screen now lists
     * holders inherited from a parent context, the button is there.
     */
    public function test_removing_reports_nothing_it_cannot_remove(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $teacher, , $coursecontext, $course] = $this->setup_world();
        $roleid = coordinatorrole::ensure();

        $catcontext = \context_coursecat::instance((int) $course->category);
        role_assign($roleid, (int) $teacher->id, $catcontext->id);
        // The whole point: core says they hold it here.
        $this->assertTrue(user_has_role_assignment((int) $teacher->id, $roleid, $activity->context()->id));

        $events = $this->redirectEvents();
        coordinatorimport::remove($activity, (int) $teacher->id);
        $removed = array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\coordinator_removed
        );
        $this->assertCount(0, $removed, 'a removal that removed nothing was recorded');
        $events->close();

        // And the category grant is untouched.
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $catcontext->id,
            'userid' => (int) $teacher->id,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $coursecontext->id,
            'userid' => (int) $teacher->id,
        ]));
        $sink->close();
    }
}
