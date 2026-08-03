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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\candidates;
use mod_selfselectadvanced\local\guides;
use mod_selfselectadvanced\local\override;
use mod_selfselectadvanced\local\volunteering;
use mod_selfselectadvanced\external\search_guides;
use mod_selfselectadvanced\external\search_participants;

/**
 * The guide picker: matching on an address typed WITH its '@', and reaching a
 * guide who is full (maintainer decisions 32 and 41, 2026-08-03/04).
 *
 * TWO DEFECTS, ONE FILE, AND THE BOUNDARY BETWEEN THEM.
 *
 * 1. A student at VIT approaches a faculty member in person and comes away
 *    with an employee id or an email address. The id is recorded as the
 *    surname, so it already matched; the address did not, and the journey
 *    stopped there. guides::with_load() now tests the query against the
 *    address as well as the name - but ONLY when the typed text contains
 *    '@' (decision 41), because a blind audit measured what an unconditional
 *    substring match costs: a plain enrolled student holding nothing but
 *    :respond reconstructed a whole guide address in 453 calls to
 *    search_guides, extending a found substring one character at a time.
 *    Requiring '@' does not close that oracle and is not claimed to - it
 *    removes the free sweep over name-shaped fragments, and the maintainer
 *    accepted the residue explicitly. Exact equality was recommended and NOT
 *    taken.
 *
 * 2. The override target picker inherited the assignment pickers' "only
 *    guides with free slots" rule, so the two guides it could never offer
 *    were a guide who is FULL and a guide who has NOT VOLUNTEERED - exactly
 *    the two a coordinator opens that page to help. Two independent legs:
 *    override_form emitted no data-withroom, and guides::search() hard-coded
 *    with_load()'s $includeunavailable to false.
 *
 * WHY THE NEGATIVE HALF LIVES IN THIS FILE AND NOT IN ANOTHER ONE. The
 * dangerous failure mode of this change is not that it fails; it is that
 * somebody later "factors the new matcher into a shared helper" and calls it
 * from the candidate pool too. That is an address ORACLE over students, which
 * maintainer decision 24 forbids and A14 records. So the assertion that the
 * PARTICIPANT pickers still match names only - in both states of the
 * contact-privacy switch - is made a few lines away from the assertion that
 * the GUIDE picker matches an address, where nobody can bring the two into
 * line without reading the reason they differ.
 *
 * MATCHING IS NOT DISPLAYING, and that half of the rule is absolute rather
 * than a slowdown. Every test here that reaches a guide BY ADDRESS through the
 * web service also asserts the returned payload carries no '@' at all, and T4
 * asserts the same of the row guides::search() hands the service layer - which
 * is the field a template or a debugger could reach even where the endpoint
 * declines to print it.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\guides
 * @covers     \mod_selfselectadvanced\external\search_guides
 * @covers     \mod_selfselectadvanced\form\override_form
 */
final class guidepickeraddress_test extends \externallib_advanced_testcase {
    /** @var string Guide A's address, stored in lower case. Its local part is in NEITHER guide's name. */
    private const ADDRESS_A = 'anita.raman@guidemail.invalid';

    /**
     * @var string Guide B's address: a DIFFERENT domain, so a domain query still
     * discriminates, and deliberately MIXED CASE, so that a lower-case query for it
     * can only succeed if the STORED side is folded too. Every fixture address here
     * was lower case until 2026-08-04, and on an all-lower-case fixture folding the
     * stored side is a no-op - so no test in this file could detect its removal,
     * which is what a reviewer and a prover found independently.
     */
    private const ADDRESS_B = 'Bala.K@OtherMail.invalid';

    /** @var string The student's address, the needle for the negative half. */
    private const ADDRESS_STUDENT = 'zenobia.quill@studentmail.invalid';

    /**
     * An activity with two guides, a student and a manager.
     *
     * Guide A is "Anita 21BCE1234": the surname is an employee id, which is
     * how VIT records it, and 'anita.raman' is therefore a substring of the
     * ADDRESS and of neither guide's name. That is what makes an address
     * query here discriminating rather than incidentally satisfied.
     *
     * @param array $settings instance settings to override
     * @return array [activity, guide A, guide B, student, manager, course]
     */
    private function world(array $settings = []): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'GPA1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'maxguided' => 3,
        ], $settings));

        $guidea = $generator->create_user([
            'firstname' => 'Anita',
            'lastname' => '21BCE1234',
            'email' => self::ADDRESS_A,
        ]);
        $generator->enrol_user($guidea->id, $course->id, 'teacher');
        $guideb = $generator->create_user([
            'firstname' => 'Bala',
            'lastname' => 'Krishnan',
            'email' => self::ADDRESS_B,
        ]);
        $generator->enrol_user($guideb->id, $course->id, 'teacher');
        $student = $generator->create_user([
            'firstname' => 'Zenobia',
            'lastname' => 'Quill',
            'email' => self::ADDRESS_STUDENT,
        ]);
        $generator->enrol_user($student->id, $course->id, 'student');
        $manager = $generator->create_user(['firstname' => 'Mira', 'lastname' => 'Manager']);
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        // READ THE FIXTURE BACK OFF THE TABLE rather than trusting the
        // generator. Guide B's mixed case is the whole discriminating power of
        // the stored-side case-folding assertions; a core helper that lowered
        // the address on the way in would turn those into a check that
        // examined nothing, and it would do so silently.
        $storedb = $DB->get_field('user', 'email', ['id' => $guideb->id]);
        $this->assertSame(self::ADDRESS_B, $storedb, 'the mixed-case address was not stored as written');
        $this->assertNotSame(
            \core_text::strtolower(self::ADDRESS_B),
            $storedb,
            'the stored address has no case left to fold, so nothing here can pin the stored-side fold'
        );

        return [
            activity::from_instance((int) $instance->id),
            $guidea,
            $guideb,
            $student,
            $manager,
            $course,
        ];
    }

    /**
     * The override resolver for an activity.
     *
     * @param activity $activity the activity
     * @return override\resolver the resolver
     */
    private function resolver(activity $activity): override\resolver {
        return (new api($activity))->gatekeeper()->resolver();
    }

    /**
     * T1. An address finds the guide - and only that guide.
     *
     * DISCRIMINATING: 'anita.raman@' is a substring of neither "Anita
     * 21BCE1234" nor "Bala Krishnan", so the first assertion cannot be
     * satisfied by the name arm. assertSame() on array_keys() is an EXACT
     * list, so the test fails both when the address arm is missing (empty
     * result) and when the filter has been short-circuited to match
     * everybody (two results). The last assertion means it cannot pass
     * merely because the filter became a no-op on a small fixture.
     *
     * EVERY QUERY HERE CARRIES ITS '@', which is decision 41 and is pinned in
     * its own right by test_the_address_arm_engages_only_on_a_query_with_an_at().
     *
     * MUTATIONS CAUGHT: removing the address strpos(); dropping u.email from
     * the field list in with_load() (the property is then unset and nothing
     * matches); replacing the array_filter with `return $users`.
     */
    public function test_an_address_finds_the_guide_and_only_that_guide(): void {
        $this->resetAfterTest();
        [$activity, $guidea, $guideb] = $this->world();
        $resolver = $this->resolver($activity);

        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, 'anita.raman@')),
            'the local part of a guide address finds nobody'
        );
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, self::ADDRESS_A)),
            'a whole guide address finds nobody'
        );
        $this->assertSame(
            [(int) $guideb->id],
            array_keys(guides::search($activity, $resolver, '@othermail.invalid')),
            'a domain matched the wrong guide, or every guide'
        );
        $this->assertSame(
            [],
            guides::search($activity, $resolver, 'nobody-at-all@nowhere.invalid'),
            'an address belonging to nobody returned somebody'
        );
    }

    /**
     * THE RULING (maintainer decision 41, 2026-08-04). The address arm engages
     * only when the typed text contains '@'; without one, this is the name
     * matcher it was before decision 32 and nothing else.
     *
     * WHY THE RULE EXISTS, measured rather than supposed: a plain enrolled
     * student holding only mod/selfselectadvanced:respond recovered a whole
     * guide address - local part unrelated to the guide's name - in 453 calls
     * to search_guides, extending a matched substring one character at a time
     * on found/not-found alone. Substring matching leaks the string it
     * matches.
     *
     * WHAT THIS TEST DOES NOT CLAIM. It does not pin an oracle shut, because
     * the rule does not shut one: a prober can anchor on the '@' and grow the
     * substring in both directions from there. The maintainer took that trade
     * knowingly ("staff directory is available to anyone who opens picker, but
     * @ slows deliberate probe"), and exact equality - which would have shut
     * it - was recommended and refused. What is pinned is the rule as ruled.
     *
     * DISCRIMINATING: the two positive controls sit in the same method as the
     * two empties. Without them a filter that had become "match nobody" would
     * satisfy the ruling's assertions perfectly.
     *
     * MUTATIONS CAUGHT: reverting to the unconditional address arm (the first
     * two assertions fail); deriving $matchaddress from anything other than
     * the query, e.g. hard-coding it true or false.
     */
    public function test_the_address_arm_engages_only_on_a_query_with_an_at(): void {
        $this->resetAfterTest();
        [$activity, $guidea] = $this->world();
        $resolver = $this->resolver($activity);

        // THE RULING. 'anita.raman' is a local part and nothing else; before
        // decision 41 it returned guide A, and that is the shape the 453-call
        // reconstruction was built out of.
        $this->assertSame(
            [],
            guides::search($activity, $resolver, 'anita.raman'),
            'a query with no @ still matched an address; the reconstruction oracle is open again'
        );
        $this->assertSame(
            [],
            guides::search($activity, $resolver, 'guidemail.invalid'),
            'a bare domain with no @ still matched an address'
        );

        // POSITIVE CONTROL ONE: with the '@', the same person is found.
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, 'anita.raman@')),
            'the address arm no longer works at all, so the two empties above prove nothing'
        );
        // POSITIVE CONTROL TWO: without an '@' the name arm is untouched. A
        // query with no '@' means "names only", never "nobody".
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, 'Anita')),
            'the @ rule broke the name arm'
        );
    }

    /**
     * The address column is FETCHED only on the calls that can use it.
     *
     * This is the rule {@see \mod_selfselectadvanced\local\candidates} states
     * in its own words - an address that is never selected "cannot be printed
     * by a later edit, dumped by a debugger or iterated out of the record by a
     * template" - and until 2026-08-04 guides::with_load() broke it: u.email
     * was in the field list of every call, including guidequeue.php, the
     * unfiltered Loads tab and all four selectable() sites, none of which
     * passes a query at all.
     *
     * NOT A SOURCE PIN. It turns on the DML layer's own debug hook and reads
     * the statements that reached the database, the same mechanism
     * search_participants_test uses, so no alias, no bare column name and no
     * widening helper walks around it.
     *
     * DISCRIMINATING: the by-address capture is asserted to CONTAIN the
     * column. A capture that caught nothing, or a counter looking for the
     * wrong word, would otherwise report "0 occurrences" for ever and look
     * exactly like a pass.
     *
     * MUTATIONS CAUGHT: putting u.email back in the unconditional field list;
     * making the condition "non-empty query" rather than "contains '@'"
     * (the by-name capture then carries the column).
     */
    public function test_the_address_column_is_fetched_only_when_it_can_be_used(): void {
        $this->resetAfterTest();
        [$activity] = $this->world();
        $resolver = $this->resolver($activity);

        // Warm the context, capability and attribute caches first, so each
        // capture below is the search's own work and not a cold context build.
        $this->assertNotEmpty(guides::with_load($activity, $resolver, true));
        $this->assertNotEmpty(guides::search($activity, $resolver, 'Anita'));

        $unfiltered = self::captured_sql(static function () use ($activity, $resolver): void {
            guides::with_load($activity, $resolver, true);
        });
        $byname = self::captured_sql(static function () use ($activity, $resolver): void {
            guides::search($activity, $resolver, 'Anita');
        });
        $byaddress = self::captured_sql(static function () use ($activity, $resolver): void {
            guides::search($activity, $resolver, 'anita.raman@');
        });

        // POSITIVE CONTROLS on the capture itself.
        foreach (['unfiltered' => $unfiltered, 'by name' => $byname, 'by address' => $byaddress] as $how => $sql) {
            $this->assertNotSame('', trim($sql), "no SQL at all was captured for the $how call");
            $this->assertTrue(
                str_contains(strtolower($sql), 'firstname'),
                "the capture for the $how call did not contain the guide query"
            );
        }

        foreach (['unfiltered' => $unfiltered, 'by name' => $byname] as $how => $sql) {
            $this->assertSame(
                0,
                substr_count(strtolower($sql), 'email'),
                "the $how guide search sent the address column to the database, where nothing could use it"
            );
        }
        $this->assertGreaterThan(
            0,
            substr_count(strtolower($byaddress), 'email'),
            'the address column is not fetched even when the query needs it, so the two counts above '
                . 'are counting nothing'
        );
    }

    /**
     * T3, folded in as the refactor's negative control: the employee-id
     * journey passed BEFORE this change and must keep passing.
     *
     * DISCRIMINATING: nothing here is new behaviour. It is the tripwire for
     * an implementer who "tidies" the matcher into users_search_sql() or any
     * other rule that stops going through fullname() - middlename and
     * alternatename participate in fullname() whenever the site's
     * fullnamedisplay includes them, and core's helper does not test them.
     *
     * MUTATIONS CAUGHT: swapping the fullname() substring test for a
     * firstname-only or firstname-plus-lastname-concat condition.
     */
    public function test_the_employee_id_in_the_surname_still_finds_the_guide(): void {
        $this->resetAfterTest();
        [$activity, $guidea] = $this->world();
        $resolver = $this->resolver($activity);

        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, '21BCE1234')),
            'the employee id stopped matching; the name arm has been rewritten'
        );
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, 'Anita')),
            'a first name stopped matching'
        );
    }

    /**
     * T2. The address arm folds case on BOTH sides, and both sides are pinned
     * by a fixture that can tell them apart.
     *
     * THE HOLE THIS CLOSES (2026-08-04, found independently by two reviewers).
     * Every fixture address in this file was lower case, and lowering an
     * already-lower-case string is a no-op - so removing
     * \core_text::strtolower() from the STORED side could not change any
     * outcome, and the file claimed "MUTATIONS CAUGHT: comparing $user->email
     * raw" while being unable to notice it. Guide B's stored address
     * is now mixed case - Bala.K@OtherMail.invalid, read back off the table in
     * world() so the fixture cannot quietly stop being mixed case - and the
     * queries for it here are lower case, which no raw comparison can satisfy.
     *
     * DISCRIMINATING in both directions at once:
     *   - stored lower + query UPPER  => the QUERY side must be folded;
     *   - stored MIXED + query lower  => the STORED side must be folded;
     * and each assertion is an exact one-element list, so neither can be
     * satisfied by a filter that matched everybody.
     *
     * MUTATIONS CAUGHT: comparing $user->email raw; lowering the query but
     * not the address (or the reverse); lowering only inside the name branch.
     */
    public function test_the_address_arm_folds_case_on_both_sides(): void {
        $this->resetAfterTest();
        [$activity, $guidea, $guideb] = $this->world();
        $resolver = $this->resolver($activity);

        // Stored lower case, typed in capitals: the QUERY side.
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, '@GUIDEMAIL.INVALID')),
            'an uppercase domain query found the wrong set'
        );
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, 'ANITA.RAMAN@GUIDEMAIL.INVALID')),
            'a whole uppercase address found the wrong set'
        );

        // Stored MIXED case, typed in lower case: the STORED side. This is the
        // pair that fails when \core_text::strtolower() is taken off
        // $user->email, and the only pair in this file that can.
        $this->assertSame(
            [(int) $guideb->id],
            array_keys(guides::search($activity, $resolver, 'bala.k@')),
            'a lower-case query did not reach a mixed-case stored address: the stored side is compared raw'
        );
        $this->assertSame(
            [(int) $guideb->id],
            array_keys(guides::search($activity, $resolver, \core_text::strtolower(self::ADDRESS_B))),
            'a whole lower-case address did not reach the mixed-case address it names'
        );

        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, 'ANITA')),
            'the name arm lost its case folding'
        );
    }

    /**
     * T4. Matched by address, returned without one.
     *
     * DISCRIMINATING: the row can ONLY have been reached through the
     * address, because 'anita.raman@' appears in no name. So the payload
     * assertions land on exactly the case where an implementer is most
     * tempted to echo the key back ("show what matched").
     * tests/external_guidesearch_test.php makes the same no-address
     * assertions on a NAME query, which cannot catch that; this can.
     *
     * MUTATIONS CAUGHT: appending the address in search_guides::label();
     * adding an 'email' key to execute_returns(); decorating the row
     * guides::with_load() builds with the user record it filtered.
     */
    public function test_a_guide_matched_by_address_is_returned_without_one(): void {
        $this->resetAfterTest();
        [$activity, $guidea, , $student] = $this->world();

        $this->setUser($student);
        $result = search_guides::execute($activity->cm()->id, 'anita.raman@');

        $this->assertCount(1, $result, 'the student could not reach the guide by address');
        $this->assertSame((int) $guidea->id, (int) $result[0]['id']);

        $payload = json_encode($result);
        $this->assertStringNotContainsString('@', $payload, 'the payload carries an address');
        $this->assertStringNotContainsString('guidemail.invalid', $payload, 'the payload carries a domain');
        $this->assertStringNotContainsString('anita.raman', $payload, 'the payload echoes the matched key');

        // The row the service layer hands over has no address FIELD either,
        // which is the thing a template or a debugger could otherwise reach
        // even when this endpoint declines to print it.
        $rows = guides::search($activity, $this->resolver($activity), 'anita.raman@');
        $this->assertArrayNotHasKey(
            'email',
            (array) $rows[(int) $guidea->id],
            'guides::search() now carries an address on the row'
        );
    }

    /**
     * T5. The participant pickers still match NAMES ONLY - in both states of
     * the contact-privacy switch - while the guide picker matches an address
     * in the same breath.
     *
     * DISCRIMINATING: the two positive controls are the whole point. Without
     * "a name finds this student", a fixture in which no candidate existed at
     * all would make every address assertion below pass forever, and this
     * project has already shipped one test that passed for exactly that
     * reason. The guide assertion at the end is what makes the file a
     * BOUNDARY rather than two unrelated opinions: the same run proves the
     * arm exists on one pool and does not exist on the other.
     *
     * MUTATIONS CAUGHT: the address arm leaking out of guides into
     * candidates or search_participants - which is what happens the moment
     * somebody factors the new matcher into a shared helper. This is the
     * single most dangerous failure mode of the change.
     */
    public function test_the_participant_pickers_still_match_names_only(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$activity, $guidea, , $student, $manager, $course] = $this->world();
        // The same course, the same people, the switch the other way.
        $legacyinstance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxguided' => 3,
            'contactprivacy' => 0,
        ]);
        $legacy = activity::from_instance((int) $legacyinstance->id);

        $leader = $generator->create_user(['firstname' => 'Lea', 'lastname' => 'Der']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $this->setUser($leader);
        $protectedgroup = (new api($activity))->create_group((int) $leader->id, 'Protected', 'P', '<p>b</p>', FORMAT_HTML);
        $legacygroup = (new api($legacy))->create_group((int) $leader->id, 'Legacy', 'L', '<p>b</p>', FORMAT_HTML);

        foreach ([[$activity, $protectedgroup, 'switch ON'], [$legacy, $legacygroup, 'switch OFF']] as [$act, $grp, $state]) {
            $gatekeeper = (new api($act))->gatekeeper();

            // Positive control: the fixture is real, so the negative below
            // means something.
            $this->assertNotEmpty(
                candidates::search($act, $grp, $gatekeeper, 'Zenobia', (int) $leader->id),
                'the candidate fixture is unreachable by name (' . $state . ')'
            );
            $this->assertSame(
                [],
                candidates::search($act, $grp, $gatekeeper, self::ADDRESS_STUDENT, (int) $leader->id),
                'the candidate picker became an address oracle over a student (' . $state . ')'
            );
            $this->assertSame(
                [],
                candidates::search($act, $grp, $gatekeeper, 'zenobia.quill', (int) $leader->id),
                'the candidate picker matched an address local part (' . $state . ')'
            );
        }

        // The staff move form's participant picker, same question, staff
        // viewer - the one this project reopened once before.
        $this->setUser($manager);
        $this->assertNotEmpty(
            search_participants::execute($activity->cm()->id, 'Zenobia'),
            'the participant picker cannot find the student by name'
        );
        $this->assertSame(
            [],
            search_participants::execute($activity->cm()->id, self::ADDRESS_STUDENT),
            'the participant picker became an address oracle over a student'
        );

        // And, in the same run, the arm that DOES exist. The two pools are
        // different pools and the difference is a maintainer ruling.
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $this->resolver($activity), 'anita.raman@')),
            'the guide address arm is gone; this file no longer pins a boundary, only a rule'
        );
    }

    /**
     * THE MISSING PIN (added 2026-08-04). A STUDENT's own address reaches no
     * guide through the guide endpoint - not the whole address, not their
     * domain.
     *
     * The prover checked this by hand and found it correct; nothing asserted
     * it. It is the pool question rather than the field question: everything
     * above proves the guide picker matches a GUIDE's address and returns
     * none, and this proves the set it searches is the holders of
     * mod/selfselectadvanced:guide in this module context and not the
     * enrolment. Widening get_users_by_capability() to any enrolled user - or
     * dropping the capability argument - would turn the guide picker into
     * exactly the address oracle over students that A14 and decision 24
     * forbid, and every other test in this file would still pass.
     *
     * DISCRIMINATING: two positive controls in the SAME method, one at the
     * service and one at the endpoint, and the student's own domain is probed
     * as well as their whole address, because a pool widened by one query
     * would answer the domain first.
     *
     * MUTATIONS CAUGHT: searching enrolled users instead of guide-capability
     * holders; matching the address against a user record fetched from
     * anywhere other than that pool.
     */
    public function test_a_student_address_reaches_no_guide_through_the_guide_endpoint(): void {
        $this->resetAfterTest();
        [$activity, $guidea, , $student] = $this->world();
        $resolver = $this->resolver($activity);
        $cmid = $activity->cm()->id;

        // POSITIVE CONTROL, service level: an address query does reach a
        // guide, so the empties below are about WHOSE address it is.
        $this->assertSame(
            [(int) $guidea->id],
            array_keys(guides::search($activity, $resolver, '@guidemail.invalid')),
            'no address query reaches anybody at all, so this test proves nothing'
        );

        $this->assertSame(
            [],
            guides::search($activity, $resolver, self::ADDRESS_STUDENT),
            'a student was found through the guide picker by their whole address'
        );
        $this->assertSame(
            [],
            guides::search($activity, $resolver, '@studentmail.invalid'),
            'a student domain reached somebody through the guide picker'
        );

        // And at the endpoint, asked by the student who owns the address -
        // the person best placed to notice, and the one holding :respond.
        $this->setUser($student);
        $reached = search_guides::execute($cmid, '@guidemail.invalid');
        $this->assertSame(
            [(int) $guidea->id],
            array_column($reached, 'id'),
            'the endpoint reaches no guide by address at all, so the empties below prove nothing'
        );
        $this->assertStringNotContainsString(
            '@',
            json_encode($reached),
            'the payload carries the address the query matched'
        );
        $this->assertSame(
            [],
            search_guides::execute($cmid, self::ADDRESS_STUDENT),
            'the guide endpoint answered for a student address'
        );
        $this->assertSame(
            [],
            search_guides::execute($cmid, '@studentmail.invalid'),
            'the guide endpoint answered for a student domain'
        );
    }

    /**
     * T6+T7 leg one. A FULL guide: absent from the assignment pickers,
     * present for the picker that exists to help them.
     *
     * The override page's picker asks with withroom = false (it now sends
     * data-withroom="0"); every assignment picker asks with withroom = true.
     * Both directions are asserted on the SAME guide with the SAME query, so
     * an absence cannot be explained by a guide who was never there.
     *
     * DISCRIMINATING at the endpoint too, because the endpoint is where the
     * browser meets this: a manager asking the way the override page asks
     * gets the full guide; a student asking the way a submission picker asks
     * does not.
     *
     * MUTATIONS CAUGHT: `remaining > 0` becoming `remaining >= 0`; dropping
     * the $onlyselectable branch. NOT a change of DEFAULT anywhere - every
     * call below passes withroom explicitly, which is deliberate (the two
     * directions have to be asked of the same guide with the same query) and
     * means a default that moved would sail past this test. guidepicker's own
     * default is pinned in test_the_override_form_asks_for_the_unfiltered_guide_picker().
     */
    public function test_a_full_guide_is_reachable_only_where_the_filter_is_off(): void {
        $this->resetAfterTest();
        [$activity, , $guideb, $student, $manager] = $this->world();
        $resolver = $this->resolver($activity);

        // An explicit cap of zero is the documented "visible but always
        // full" device, and being a manager override it survives the
        // volunteering rules either way.
        override\store::save($activity, 'guide', (int) $guideb->id, ['maxguided' => 0], (int) $manager->id);
        $this->assertSame(
            0,
            (int) guides::with_load($activity, $resolver, true)[(int) $guideb->id]->remaining,
            'the fixture did not make the guide full'
        );

        $this->assertArrayNotHasKey(
            (int) $guideb->id,
            guides::search($activity, $resolver, 'Krishnan', 50, true),
            'an assignment picker offered a guide with no room'
        );
        $this->assertArrayHasKey(
            (int) $guideb->id,
            guides::search($activity, $resolver, 'Krishnan', 50, false),
            'the override picker cannot reach a full guide'
        );

        // The same pair through the endpoint the browser calls, and the same
        // pair reached BY ADDRESS - the two halves of this wave meeting.
        $this->setUser($manager);
        $reached = search_guides::execute($activity->cm()->id, 'bala.k@', false);
        $this->assertSame([(int) $guideb->id], array_column($reached, 'id'));
        $this->assertStringNotContainsString('@', json_encode($reached));

        $this->setUser($student);
        $this->assertSame(
            [],
            search_guides::execute($activity->cm()->id, 'bala.k@', true),
            'the assignment-shaped request offered a full guide'
        );
    }

    /**
     * T7 leg two. A guide who has NOT VOLUNTEERED is unavailable to a
     * student and reachable by an :override holder.
     *
     * Three states of the same guide and the same query:
     *   - a student asking gets nothing, today and after;
     *   - a manager, who holds mod/selfselectadvanced:override, gets the
     *     guide. This assertion FAILS against the unfixed tree, because
     *     guides::search() hard-coded with_load()'s $includeunavailable to
     *     false and nobody could pass it;
     *   - once the guide volunteers, the student sees them too, which is
     *     what proves the first empty answer was about VOLUNTEERING rather
     *     than about a broken fixture.
     *
     * MUTATIONS CAUGHT: deriving $includeunavailable from the withroom
     * PARAMETER instead of from the capability (the student's withroom=false
     * call would then leak the non-volunteer); hard-coding it true (the
     * student's first assertion fails); reverting guides::search()'s
     * pass-through (the manager's assertion fails).
     */
    public function test_a_guide_who_has_not_volunteered_is_reachable_only_by_an_override_holder(): void {
        $this->resetAfterTest();
        [$activity, , $guideb, $student, $manager] = $this->world(['guidevolunteer' => 1]);
        $cmid = $activity->cm()->id;

        // Service level first, so the capability question and the
        // pass-through question are separated.
        $resolver = $this->resolver($activity);
        $this->assertArrayNotHasKey(
            (int) $guideb->id,
            guides::search($activity, $resolver, 'Krishnan', 50, false, false),
            'a non-volunteering guide is being offered to the ordinary pickers'
        );
        $this->assertArrayHasKey(
            (int) $guideb->id,
            guides::search($activity, $resolver, 'Krishnan', 50, false, true),
            'guides::search() still cannot pass $includeunavailable through'
        );

        $this->setUser($student);
        $this->assertSame(
            [],
            search_guides::execute($cmid, 'Krishnan', false),
            'a student was offered a guide who has not volunteered'
        );
        // The same is true of an address query: the arm added by this wave
        // does not resurrect an unavailable guide for a student.
        $this->assertSame(
            [],
            search_guides::execute($cmid, 'bala.k@', false),
            'the address arm reached a guide the volunteering rule excludes'
        );

        $this->setUser($manager);
        $this->assertSame(
            [(int) $guideb->id],
            array_column(search_guides::execute($cmid, 'Krishnan', false), 'id'),
            'an :override holder still cannot reach a guide who has not volunteered'
        );
        $byaddress = search_guides::execute($cmid, 'bala.k@', false);
        $this->assertSame(
            [(int) $guideb->id],
            array_column($byaddress, 'id'),
            'an :override holder cannot reach that guide by address'
        );
        $this->assertStringNotContainsString(
            '@',
            json_encode($byaddress),
            'the unfiltered picker returned the address it matched on'
        );

        // Now the guide volunteers. The student's empty answers above were
        // about volunteering, not about the guide not existing.
        volunteering::set($activity, (int) $guideb->id, 1);
        $this->setUser($student);
        $this->assertSame(
            [(int) $guideb->id],
            array_column(search_guides::execute($cmid, 'Krishnan', false), 'id'),
            'volunteering did not make the guide visible; the first assertions proved nothing'
        );
    }

    /**
     * T8. The override form asks for the unfiltered picker, and nothing else
     * does.
     *
     * BEHAVIOURAL, not a source pin: the attribute is read back off the
     * element the form actually built, which is what
     * templatable_form_element::export_for_template() turns into markup, and
     * off guidepicker::render()'s real output.
     *
     * DISCRIMINATING: the pairing. A mutation that removed data-withroom from
     * EVERY picker would satisfy the first assertion's intent (full guides
     * appear on the override page) while destroying the feature; the
     * assignment-side assertions fail it. And a mutation that set the
     * attribute on the wrong mode is caught by the group/user assertions.
     *
     * MUTATIONS CAUGHT: reverting override_form; setting data-withroom on the
     * group or user branch; changing guidepicker::render()'s own $withroom
     * default away from filtering, or making it ignore the argument. NOT a
     * default changed in guideselector.js or in
     * search_guides::execute_parameters(): no assertion here runs the
     * JavaScript or omits the web-service parameter, and claiming otherwise
     * would be describing a check this file does not make.
     */
    public function test_the_override_form_asks_for_the_unfiltered_guide_picker(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$activity] = $this->world();
        $cmid = (int) $activity->cm()->id;

        $this->assertSame('0', $this->target_attribute($activity, 'guide', 'data-withroom'));

        // The other two modes must NOT carry it - they are not guide
        // pickers at all, and an attribute set there would be a lie about
        // which control it configures.
        $this->assertNull($this->target_attribute($activity, 'group', 'data-withroom'));
        $this->assertNull($this->target_attribute($activity, 'user', 'data-withroom'));

        // And the placeholder each mode promises, since the guide branch now
        // says "any guide" and the user branch stopped saying "guide" at
        // all. A box must not promise a match the query will not make, and
        // the user-scope picker searches enrolled participants while asking
        // for a guide.
        $this->assertSame(
            get_string('guidepickerplaceholderany', 'mod_selfselectadvanced'),
            $this->target_placeholder($activity, 'guide')
        );
        $this->assertSame(
            get_string('participantpickerplaceholder', 'mod_selfselectadvanced'),
            $this->target_placeholder($activity, 'user')
        );
        $this->assertSame(
            get_string('grouppickerplaceholder', 'mod_selfselectadvanced'),
            $this->target_placeholder($activity, 'group')
        );
        // The wrong answer this replaced, named so a revert is loud.
        $this->assertNotSame(
            get_string('guidepickerplaceholder', 'mod_selfselectadvanced'),
            $this->target_placeholder($activity, 'user'),
            'the user-scope override picker is asking for a guide again'
        );

        // The assignment-side control still asks for the FILTERED picker, so
        // none of the above can be satisfied by turning the filter off
        // everywhere.
        $this->assertStringContainsString(
            'data-withroom="1"',
            \mod_selfselectadvanced\local\guidepicker::render('guideid', $cmid),
            'the assign-queue picker stopped filtering to guides with room'
        );
        $this->assertStringContainsString(
            'data-withroom="0"',
            \mod_selfselectadvanced\local\guidepicker::render('guideid', $cmid, 0, '', false),
            'guidepicker::render() no longer honours $withroom = false'
        );
    }

    /**
     * The override form's target picker element, as the form built it.
     *
     * The form is constructed exactly as overrides.php constructs it
     * (tests/override_consistency_test.php uses the same shape), and the
     * element is read back rather than the markup: no test in this repo
     * renders a moodleform, and the element is where the answer is - core's
     * MoodleQuickForm_autocomplete strips the keys it knows and hands the
     * rest to MoodleQuickForm_select, which is the mechanism that puts
     * data-cmid on the rendered select today and puts data-withroom there
     * now.
     *
     * @param activity $activity the activity
     * @param string $mode user, group or guide
     * @return \MoodleQuickForm_autocomplete the target element
     */
    private function target_element(activity $activity, string $mode): \MoodleQuickForm_autocomplete {
        $targetmodule = [
            'group' => 'mod_selfselectadvanced/groupselector',
            'guide' => 'mod_selfselectadvanced/guideselector',
        ][$mode] ?? 'mod_selfselectadvanced/participantselector';

        $form = new \mod_selfselectadvanced\form\override_form(null, [
            'cmid' => $activity->cm()->id,
            'mode' => $mode,
            'overrideid' => 0,
            'targetmodule' => $targetmodule,
            'targetid' => 0,
            'targetlabel' => '',
            'activity' => $activity,
        ]);

        $property = new \ReflectionProperty(\moodleform::class, '_form');
        /** @var \MoodleQuickForm $mform */
        $mform = $property->getValue($form);

        return $mform->getElement('target');
    }

    /**
     * One HTML attribute of the override form's target picker.
     *
     * @param activity $activity the activity
     * @param string $mode user, group or guide
     * @param string $attribute the attribute name
     * @return string|null the value, or null when the element does not carry it
     */
    private function target_attribute(activity $activity, string $mode, string $attribute): ?string {
        $value = $this->target_element($activity, $mode)->getAttribute($attribute);

        return $value === null ? null : (string) $value;
    }

    /**
     * The placeholder the target picker will show.
     *
     * Not an HTML attribute: the autocomplete element consumes 'placeholder'
     * in its constructor and passes it to core/form-autocomplete's enhance()
     * from toHtml(), so the element's own property is where the promise the
     * box makes actually lives.
     *
     * @param activity $activity the activity
     * @param string $mode user, group or guide
     * @return string the placeholder text
     */
    private function target_placeholder(activity $activity, string $mode): string {
        $property = new \ReflectionProperty(\MoodleQuickForm_autocomplete::class, 'placeholder');

        return (string) $property->getValue($this->target_element($activity, $mode));
    }

    /**
     * Run something with DML debugging on and return everything the database
     * layer printed: each statement, and its parameters.
     *
     * The same helper search_participants_test uses, and for the same reason:
     * what a query SELECTs is decided by helper calls and not by anything a
     * reader of the caller can see, so the finished statement is the only
     * honest place to ask.
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
