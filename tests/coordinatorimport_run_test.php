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
 * The bulk coordinator upload (strategy 1.17 B3).
 *
 * The single-appoint path has its own tests. What matters here is that
 * the file path applies the SAME eligibility rule, sees holders
 * wherever an older release or an administrator left them, writes the
 * appointment at the activity, and asks its questions once for the
 * whole file rather than once per line.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\coordinatorimport
 */
final class coordinatorimport_run_test extends \advanced_testcase {
    /**
     * A course with one activity.
     *
     * @return array [activity, course, coursecontext]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'CI1']);
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);

        return [
            activity::from_instance((int) $instance->id),
            $course,
            \context_course::instance($course->id),
        ];
    }

    /**
     * A reader over a string, the way the page builds one over an
     * uploaded file.
     *
     * The leading comment line is not decoration: csv_import_reader
     * takes the first row of any file as its header and init() skips
     * it, which is why the sample file this plugin hands out opens with
     * a comment. Fixtures have to be the same shape as the real thing.
     *
     * @param string $content the file, one name per line
     * @return \csv_import_reader
     */
    private function reader(string $content): \csv_import_reader {
        global $CFG;

        require_once($CFG->libdir . '/csvlib.class.php');
        $iid = \csv_import_reader::get_new_iid('mod_selfselectadvanced_coord');
        $reader = new \csv_import_reader($iid, 'mod_selfselectadvanced_coord');
        $this->assertNotFalse($reader->load_csv_content("# people\n" . $content, 'UTF-8', 'comma'));

        return $reader;
    }

    /**
     * The outcomes a report recorded, as lang strings.
     *
     * @param \stdClass $report what run() gave back
     * @return string[]
     */
    private function outcomes(\stdClass $report): array {
        return array_map(static fn($line) => $line->outcome, $report->lines);
    }

    /**
     * A file naming somebody who may not hold the role reports them as
     * such and appoints nobody - on the preview and on the commit.
     */
    public function test_run_refuses_ineligible_lines(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $coursecontext] = $this->setup_world();
        $generator = $this->getDataGenerator();
        $roleid = coordinatorrole::ensure();

        $student = $generator->create_user(['username' => 'sam.student']);
        $generator->enrol_user($student->id, $course->id, 'student');
        $teacher = $generator->create_user(['username' => 'tina.teach']);
        $generator->enrol_user($teacher->id, $course->id, 'teacher');

        $file = "sam.student\ntina.teach\n";
        $ineligible = get_string('coordinatorimportineligible', 'mod_selfselectadvanced');

        $preview = coordinatorimport::run(
            $activity,
            $this->reader($file),
            coordinatorimport::MODE_ADD_REMOVE,
            false,
            2
        );
        $this->assertSame(1, $preview->added);
        $this->assertSame(1, $preview->skipped);
        $this->assertContains($ineligible, $this->outcomes($preview));

        $report = coordinatorimport::run(
            $activity,
            $this->reader($file),
            coordinatorimport::MODE_ADD_REMOVE,
            true,
            2
        );
        $this->assertSame(1, $report->added);
        $this->assertSame(1, $report->skipped);
        $this->assertContains($ineligible, $this->outcomes($report));

        foreach ([$activity->context()->id, $coursecontext->id] as $contextid) {
            $this->assertFalse($DB->record_exists('role_assignments', [
                'roleid' => $roleid,
                'contextid' => $contextid,
                'userid' => (int) $student->id,
            ]));
        }
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $activity->context()->id,
            'userid' => (int) $teacher->id,
            'component' => 'mod_selfselectadvanced',
            'itemid' => 0,
        ]));
        $sink->close();
    }

    /**
     * A holder left at the course by an older release is still a
     * holder: OVERWRITE must neither appoint them a second time nor
     * leave them holding the role when the file drops them.
     */
    public function test_overwrite_sees_legacy_course_holders(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $course, $coursecontext] = $this->setup_world();
        $generator = $this->getDataGenerator();
        $roleid = coordinatorrole::ensure();

        $teacher = $generator->create_user(['username' => 'legacy.holder']);
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $other = $generator->create_user(['username' => 'other.teach']);
        $generator->enrol_user($other->id, $course->id, 'teacher');

        // The shape a pre-1.20.0 site is in.
        role_assign($roleid, (int) $teacher->id, $coursecontext->id);

        $named = coordinatorimport::run(
            $activity,
            $this->reader("legacy.holder\n"),
            coordinatorimport::MODE_OVERWRITE,
            true,
            2
        );
        $this->assertSame(1, $named->unchanged);
        $this->assertSame(0, $named->added);
        $this->assertSame(1, $DB->count_records('role_assignments', [
            'roleid' => $roleid,
            'userid' => (int) $teacher->id,
        ]));

        $dropped = coordinatorimport::run(
            $activity,
            $this->reader("other.teach\n"),
            coordinatorimport::MODE_OVERWRITE,
            true,
            2
        );
        $this->assertSame(1, $dropped->removed);
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'contextid' => $coursecontext->id,
            'userid' => (int) $teacher->id,
        ]));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'userid' => (int) $teacher->id,
        ]));
        $sink->close();
    }

    /**
     * Enrolment and eligibility are asked once for the file, not once
     * per line. A cohort upload on a 10,000-student course used to pay
     * an is_enrolled() query per name.
     */
    public function test_run_is_bulk_not_per_line(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $course] = $this->setup_world();
        $generator = $this->getDataGenerator();
        coordinatorrole::ensure();

        $names = [];
        for ($i = 0; $i < 20; $i++) {
            $user = $generator->create_user(['username' => 'bulkteach' . $i]);
            $generator->enrol_user($user->id, $course->id, 'teacher');
            $names[] = 'bulkteach' . $i;
        }
        $five = implode("\n", array_slice($names, 0, 5)) . "\n";
        $twenty = implode("\n", $names) . "\n";

        // Warm whatever the first run populates once.
        coordinatorimport::run($activity, $this->reader($five), coordinatorimport::MODE_ADD_REMOVE, false, 2);

        $before = $DB->perf_get_reads();
        coordinatorimport::run($activity, $this->reader($five), coordinatorimport::MODE_ADD_REMOVE, false, 2);
        $reads5 = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        coordinatorimport::run($activity, $this->reader($twenty), coordinatorimport::MODE_ADD_REMOVE, false, 2);
        $reads20 = $DB->perf_get_reads() - $before;

        // Each extra line may cost its one find_user() lookup and no
        // more: enrolment and eligibility are per RUN.
        $this->assertLessThanOrEqual(
            (20 - 5) + 2,
            $reads20 - $reads5,
            "Twenty lines cost $reads20 reads against $reads5 for five: the run is scaling per line"
        );
    }
}
