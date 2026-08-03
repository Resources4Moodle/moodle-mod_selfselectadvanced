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

use mod_selfselectadvanced\external\search_participants;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;

/**
 * The move form's participant search: authorised by the plugin's own
 * manage capability in the module context, so a coordinator who holds
 * their role only inside the course can use the form (core's site-wide
 * user selector demanded a system capability they never have).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\external\search_participants
 */
final class search_participants_test extends \advanced_testcase {
    /**
     * A course-level coordinator can search, results are scoped to the
     * activity's participants, and each carries their current team.
     */
    public function test_course_coordinator_can_search_participants(): void {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->resetAfterTest();

        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'minsize' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user(['firstname' => 'Student SCOPE', 'lastname' => '26BCE0001']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $loner = $generator->create_user(['firstname' => 'Student SENSE', 'lastname' => '26BEC0002']);
        $generator->enrol_user($loner->id, $course->id, 'student');
        // Enrolled on another course only: must never be offered here.
        $othercourse = $generator->create_course();
        $outsider = $generator->create_user(['firstname' => 'Student SMEC', 'lastname' => '26BME0003']);
        $generator->enrol_user($outsider->id, $othercourse->id, 'student');

        // The generator gives the group its leader's membership row.
        $plugingen->create_group(['activityid' => $activity->id(),
            'leaderid' => (int) $leader->id, 'name' => 'Alpha', 'state' => state::FORMING]);

        // The coordinator holds their role in the COURSE only - exactly
        // the case core's selector could not serve.
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'editingteacher');
        $this->setUser($coordinator);

        $results = search_participants::execute((int) $activity->cm()->id, '26B');
        $labels = array_column($results, 'label');
        $ids = array_column($results, 'id');

        $this->assertContains((int) $leader->id, $ids);
        $this->assertContains((int) $loner->id, $ids);
        $this->assertNotContains((int) $outsider->id, $ids, 'a non-participant was offered');

        $inteam = array_values(array_filter($labels, static fn($l) => str_contains($l, 'Alpha')));
        $this->assertCount(1, $inteam, 'the team a person belongs to must be shown');
        $this->assertNotEmpty(array_filter($labels, static fn($l) => str_contains($l, 'no team yet')));

        // Searching by register number finds exactly that student.
        $byregno = search_participants::execute((int) $activity->cm()->id, '26BEC0002');
        $this->assertCount(1, $byregno);
        $this->assertSame((int) $loner->id, $byregno[0]['id']);

        // An empty query returns nothing rather than the whole cohort
        // (a whitespace-only one never reaches us: the external layer
        // refuses it as untrimmed).
        $this->assertSame([], search_participants::execute((int) $activity->cm()->id, ''));
    }

    /**
     * A student cannot use the coordinator's search.
     */
    public function test_a_student_is_refused(): void {
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();

        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        search_participants::execute((int) $activity->cm()->id, 'Student');
    }

    /**
     * 13. MAINTAINER DECISION 24: nobody gets the address match.
     *
     * This endpoint used to accept a full address from a :manage holder
     * and answer with the name of the person who owns it, on the
     * argument that :manage is the contact-privacy switch's own exempt
     * viewer. eoilist.php had already answered the same question the
     * other way for every role. The strict answer won, so the actors
     * here are ranked by authority - a site administrator, an editing
     * teacher who holds :manage, a coordinator who holds only
     * :managecomposition - and NONE of them gets a row back for an
     * address that unquestionably belongs to somebody in the activity.
     *
     * The switch is deliberately left at its default in one activity
     * and turned OFF in another, because the removal is unconditional:
     * an editing teacher turning protection off somewhere else in the
     * activity must not grow the picker an oracle.
     */
    public function test_no_role_can_use_the_picker_as_an_address_oracle(): void {
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();

        $course = $generator->create_course();
        $protected = activity::from_instance((int) $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'contactprivacy' => 1,
        ])->id);
        $legacy = activity::from_instance((int) $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'contactprivacy' => 0,
        ])->id);

        $target = $generator->create_user([
            'firstname' => 'Tara', 'lastname' => 'Gett', 'email' => 'target@example.com',
        ]);
        $generator->enrol_user($target->id, $course->id, 'student');

        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        $mover = $generator->create_user();
        $generator->enrol_user($mover->id, $course->id, 'teacher');
        $moverrole = $generator->create_role();
        foreach ([$protected, $legacy] as $activity) {
            assign_capability(
                'mod/selfselectadvanced:managecomposition',
                CAP_ALLOW,
                $moverrole,
                \context_module::instance($activity->cm()->id)
            );
            role_assign($moverrole, $mover->id, \context_module::instance($activity->cm()->id));
        }
        accesslib_clear_all_caches_for_unit_testing();

        foreach (['protected' => $protected, 'legacy' => $legacy] as $label => $activity) {
            $cmid = (int) $activity->cm()->id;
            foreach (['administrator' => null, 'editing teacher' => $manager, 'coordinator' => $mover] as $who => $user) {
                if ($user === null) {
                    $this->setAdminUser();
                } else {
                    $this->setUser($user);
                }
                $this->assertSame(
                    [],
                    search_participants::execute($cmid, 'target@example.com'),
                    "an $who used the $label picker as an address oracle"
                );
                // The positive control, in the same breath: the picker
                // is not simply broken, it still finds people by name.
                $found = search_participants::execute($cmid, 'Gett');
                $this->assertCount(1, $found);
                $this->assertSame((int) $target->id, $found[0]['id']);
                $this->assertStringNotContainsString('@', $found[0]['label'], 'the label never carries an address');
            }
        }
    }

    /**
     * The address column is neither matched nor selected here, so no
     * later edit can print - or confirm - what was never fetched. Zero
     * occurrences, not one: the gated LIKE that used to justify the
     * single occurrence is gone (maintainer decision 24).
     *
     * ASSERTED ON EXECUTABLE SOURCE, and on the WORD rather than on one
     * alias (1.20.1 wave 3E). Searching the raw file for 'u.email' was
     * wrong in both directions at once. It was too NARROW - a re-added
     * match written as `usr.email`, `u2.email` or a bare 'email' in a
     * field list satisfied it - and it was brittle in the other
     * direction, because the class's own comments discuss the address
     * at length and one of them acquiring the two characters `u.` would
     * have turned a documentation edit into a red gate. Comments are not
     * the code: strip them, then require that the executable text does
     * not mention an address at all.
     */
    public function test_the_address_column_is_never_touched(): void {
        $source = file_get_contents(__DIR__ . '/../classes/external/search_participants.php');
        $this->assertIsString($source, 'search_participants.php could not be read');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }
        // The stripper must have left something to examine: a check that
        // examined nothing would report "0 occurrences" for ever.
        $this->assertStringContainsString('function execute', $code, 'the comment stripper ate the class');
        $this->assertSame(0, substr_count($code, 'email'), 'the participant search touched the address column');
    }
}
