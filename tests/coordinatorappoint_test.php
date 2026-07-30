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
 * single-person path enforces the SAME rule - they must already be
 * enrolled - and emits the same event and message, so the audit trail
 * does not record two kinds of appointment depending on which control
 * somebody happened to use.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\coordinatorimport
 */
final class coordinatorappoint_test extends \advanced_testcase {
    /**
     * An activity with an enrolled teacher and a stranger.
     *
     * @return array [activity, teacher, stranger, coursecontext]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'CO1']);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $stranger = $generator->create_user();

        return [
            activity::from_instance((int) $instance->id),
            $teacher,
            $stranger,
            \context_course::instance($course->id),
        ];
    }

    /**
     * Appointing somebody enrolled works, is idempotent, and records
     * the event that the audit trail is built from.
     */
    public function test_appointing_an_enrolled_person(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $teacher, , $coursecontext] = $this->setup_world();
        $roleid = coordinatorrole::ensure();

        $events = $this->redirectEvents();
        coordinatorimport::appoint($activity, (int) $teacher->id);
        $this->assertTrue(user_has_role_assignment((int) $teacher->id, $roleid, $coursecontext->id));

        $filed = array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\coordinator_assigned
        );
        $this->assertCount(1, $filed);
        $events->close();

        // Twice is not an error and does not assign the role twice.
        coordinatorimport::appoint($activity, (int) $teacher->id);
        $this->assertCount(
            1,
            get_role_users($roleid, $coursecontext, false, 'u.id, u.firstname, u.lastname')
        );
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
     * Removing takes the role away and records it, and never touches
     * enrolment - a button in a table is not the place to remove
     * somebody from a course as a side effect.
     */
    public function test_removing_leaves_enrolment_alone(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $teacher, , $coursecontext] = $this->setup_world();
        $roleid = coordinatorrole::ensure();

        coordinatorimport::appoint($activity, (int) $teacher->id);

        $events = $this->redirectEvents();
        coordinatorimport::remove($activity, (int) $teacher->id);
        $removed = array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\coordinator_removed
        );
        $this->assertCount(1, $removed);
        $events->close();

        $this->assertFalse(user_has_role_assignment((int) $teacher->id, $roleid, $coursecontext->id));
        $this->assertTrue(is_enrolled($coursecontext, (int) $teacher->id));

        // Removing somebody who does not hold it is a no-op, not a crash.
        coordinatorimport::remove($activity, (int) $teacher->id);
        $sink->close();
    }
}
