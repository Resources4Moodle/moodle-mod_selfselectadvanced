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
        $this->assertNotEmpty(array_filter($labels, static fn($l) => str_contains($l, 'no group yet')));

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
     *
     * AND ON THE CONSTRUCTION, not only on the word (1.20.1 wave 3F).
     * The word check is necessary and not sufficient, because the SELECT
     * list is built by a core helper and there is a way to widen that
     * helper without the six characters 'email' appearing anywhere in
     * this file. Measured 2026-08-03, m5pg: appending
     * `->with_identity($context, false)` to the for_name() call put
     * `SELECT u.id, u.email, ...` into the query the endpoint actually
     * ran, and this test still reported "OK, Assertions: 3" - every
     * 'email' in search_participants.php is inside a comment, and
     * comments are exactly what the stripper above removes. So the
     * identity-field API surface is named and refused here, and
     * test_the_executed_query_never_touches_the_address_column() below
     * asks the same question of the executed SQL, where no spelling and
     * no helper can walk around it.
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

        // The columns come from the NAME fields, and the endpoint never
        // reaches for the identity set. Identity fields are chosen by a
        // SITE setting (showuseridentity), so a plugin that expands them
        // has handed a cardinal-rule guarantee to a checkbox in another
        // part of the admin tree - which is the reason this is refused
        // outright rather than gated.
        // assertTrue/assertFalse on str_contains rather than the string
        // assertions: the haystack is a whole class, and a failure
        // message that prints it fills a gate log with the file the
        // reader already has.
        $this->assertTrue(
            str_contains($code, 'fields::for_name()'),
            'the participant search no longer builds its columns from the name fields'
        );
        foreach (['with_identity', 'for_identity', 'get_identity_fields', 'showuseridentity'] as $widener) {
            $this->assertFalse(
                str_contains($code, $widener),
                "the participant search reached for the identity field set via {$widener}(): on a site whose "
                    . 'showuseridentity includes the address that fetches it, and a column that is fetched is a '
                    . 'column a later edit can print'
            );
        }
    }

    /**
     * The same question asked of the SQL THE ENDPOINT ACTUALLY RUNS.
     *
     * Every check above this one reads source text, and source text is
     * one indirection away from being wrong about itself: the columns
     * are assembled by \core_user\fields, so what is fetched is decided
     * by a helper call and a site setting rather than by anything a
     * reader of this file can see. This test does not read the file. It
     * turns on the DML layer's own debug hook, runs the endpoint, and
     * examines every statement that reached the database.
     *
     * That form survives all four ways the older pin could be walked
     * around at once - a different alias (`usr.email`, `u2.email`), a
     * bare `email` in a field list, a comment that happens to contain
     * the fragment, and a widening helper call that never spells the
     * word - because it looks at the finished statement.
     *
     * Measured 2026-08-03 on both engines: zero occurrences of 'email'
     * across the WHOLE capture, core's enrolled-users subquery
     * included, so the count below is not absorbing a background of
     * unrelated matches.
     */
    public function test_the_executed_query_never_touches_the_address_column(): void {
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();

        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id, 'minsize' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $cmid = (int) $activity->cm()->id;

        // The address is deliberately one that does not contain the
        // string being counted, so searching FOR it cannot make the
        // assertion below fail on its own parameter.
        $target = $generator->create_user([
            'firstname' => 'Tara', 'lastname' => 'Gett', 'email' => 'tara.gett@example.com',
        ]);
        $generator->enrol_user($target->id, $course->id, 'student');

        // The most privileged viewer there is. If the column is fetched
        // for anybody, it is fetched for this one.
        $this->setAdminUser();

        // Warm the context and capability caches first, so the captured
        // run is the endpoint's own work and not a cold context build.
        $this->assertCount(1, search_participants::execute($cmid, 'Gett'));

        $byname = self::captured_sql(static function () use ($cmid): void {
            search_participants::execute($cmid, 'Gett');
        });
        $byaddress = self::captured_sql(static function () use ($cmid): void {
            search_participants::execute($cmid, 'tara.gett@example.com');
        });

        // POSITIVE CONTROLS. A capture that caught nothing would report
        // "0 occurrences" for ever and look identical to a pass.
        foreach (['by name' => $byname, 'by address' => $byaddress] as $how => $captured) {
            $this->assertNotSame('', trim($captured), "no SQL at all was captured for the search $how");
            $this->assertTrue(
                str_contains($captured, 'firstname'),
                "the capture for the search $how did not contain the participant query"
            );
        }

        foreach (['by name' => $byname, 'by address' => $byaddress] as $how => $captured) {
            $this->assertSame(
                0,
                substr_count(strtolower($captured), 'email'),
                "the participant search $how sent the address column to the database - it must be neither "
                    . 'matched nor selected, for any role, in either state of the contact-privacy switch'
            );
        }
    }

    /**
     * Run something with DML debugging on and return everything the
     * database layer printed: each statement, and its parameters.
     *
     * @param callable $run the work whose queries are wanted
     * @return string the captured statements, empty if nothing ran
     */
    private static function captured_sql(callable $run): string {
        global $DB;

        ob_start();
        $DB->set_debug(true);
        try {
            $run();
        } finally {
            $DB->set_debug(false);
            $captured = ob_get_clean();
        }

        return is_string($captured) ? $captured : '';
    }
}
