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
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\teamaccess;

/**
 * REACHABILITY: is there an interface for every action this plugin
 * authorises, and is the screen behind it any use to the role admitted?
 *
 * Four findings, one shape. Each is a capability the SERVICE honours
 * and the interface does not offer - authority granted at one end of
 * the plugin and unspendable at the other:
 *
 *  - ACT-001 review.php required :guide on the ACTIVITY before asking
 *    its own team-scoped predicate. db/access.php grants :guide to the
 *    non-editing teacher archetype ALONE, so the manager - named in
 *    that predicate, linked to the page from two reports - was refused
 *    at the door of a page documented as theirs.
 *  - ACT-002 the freeze CONTROL and the freeze SERVICE admitted
 *    different people. :freeze is a non-editing-teacher capability;
 *    freeze_group()'s on-behalf branch admits :manage and :coordinate.
 *    Every surface asked the capability, so the two populations the
 *    branch exists for could not press the button that reaches it.
 *  - ACT-003 the coordinator dashboard's team table offered View and
 *    Unfreeze. It never offered Freeze - on the dashboard of the role
 *    that was created to freeze on a guide's behalf, beside a card
 *    counting the teams waiting for one.
 *  - ACT-004 :assignguide is described as "assign or reassign a team's
 *    guide AND DECIDE EXPRESSIONS OF INTEREST". eoi::respond() has
 *    admitted it since 1.20.0; the page that offers the choice asked
 *    :manage by itself, so the holder could decide an interest from a
 *    test and from nowhere a person could click.
 *
 * Every assertion below states how many things it examined, and each
 * one was run against the unrepaired tree first.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\freeze::require_freeze_team
 * @covers     \mod_selfselectadvanced\local\freeze::may_freeze_team
 * @covers     \mod_selfselectadvanced\table\groups_table::col_actions
 */
final class actionreach_test extends \advanced_testcase {
    /**
     * ACT-001. The premise, from the REAL default role map, and then
     * the page.
     *
     * The finding rests on a claim about db/access.php - that the
     * archetype holding :viewall is not the archetype holding :guide -
     * so the claim is measured here rather than assumed. If a later
     * release grants :guide to the editing teacher the first two
     * assertions go red, which is the correct outcome: the finding
     * would no longer be true and this test would be testing nothing.
     *
     * The page gate itself is asserted on the production source,
     * comments stripped. A behavioural pin is not available in a unit
     * test - review.php is a script, not a function - and the shape of
     * the defect is a LINE THAT MUST NOT COME BACK, which is exactly
     * what a source assertion can hold. tests/behat/actionreach.feature
     * drives the real page for both verdicts.
     */
    public function test_the_review_page_no_longer_demands_guide_of_a_manager(): void {
        $this->resetAfterTest();

        [$activity, , $group, $course] = $this->world();
        $manager = $this->user_in($course, 'editingteacher');
        $context = $activity->context();

        // The premise: two capabilities, one holder, and they do not
        // overlap on the archetype this page is documented for.
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:viewall', $context, (int) $manager->id),
            'the editing teacher archetype no longer holds :viewall'
        );
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:guide', $context, (int) $manager->id),
            'the editing teacher archetype now holds :guide - ACT-001 would be moot'
        );

        // The predicate admits them, and always did. The page did not.
        $this->assertTrue(
            teamaccess::may_review_team($activity, $group, (int) $manager->id),
            'may_review_team() stopped admitting a :viewall holder'
        );

        // THE OTHER HALF, and it is not optional. Deleting the page's
        // :guide check outright would pass everything above and quietly
        // reopen the review page to a guide whose :guide has been
        // PROHIBITED - the actor audit D1 closed the dashboard, the
        // approval and the return against. The capability moved into
        // the assigned-guide ARM of the predicate; these two assertions
        // are what stop a future reader trading one finding for the
        // other. Two viewers examined, one arm each.
        $guideid = (int) $group->guideid;
        $this->assertTrue(
            teamaccess::may_review_team($activity, $group, $guideid),
            'the team\'s own guide is refused its review page'
        );
        $prohibit = $this->getDataGenerator()->create_role();
        assign_capability('mod/selfselectadvanced:guide', CAP_PROHIBIT, $prohibit, $context->id, true);
        role_assign($prohibit, $guideid, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(
            teamaccess::is_assigned_guide($activity, $group, $guideid),
            'the fixture stopped being the assigned guide - the case below would prove nothing'
        );
        $this->assertFalse(
            teamaccess::may_review_team($activity, $group, $guideid),
            'a guide PROHIBITED from guiding still reaches the review page'
        );
        $this->assertTrue(
            teamaccess::may_review_team($activity, $group, (int) $manager->id),
            'the manager lost the page when :guide was prohibited for somebody else'
        );

        // The line that must not come back. One file examined; the
        // capability is searched for in CODE, because the comments on
        // that page discuss it at length and a check that a sentence
        // exists is not a check that a gate does.
        $code = $this->code_of('review.php');
        $this->assertStringNotContainsString(
            'mod/selfselectadvanced:guide',
            $code,
            'review.php requires :guide again - a manager holding :viewall is refused at its door'
        );
        // The matched half: the gate it DOES keep is the team-scoped
        // one, so removing both would not pass this test.
        $this->assertStringContainsString(
            'teamaccess::may_review_team(',
            $code,
            'review.php no longer asks the team-scoped predicate at all'
        );
    }

    /**
     * ACT-002. THE PAGE AND THE SERVICE ADMIT THE SAME SET.
     *
     * Six actors, six firm teams of their own, one comparison each:
     * what freeze::may_freeze_team() answers - the predicate every
     * control now consults - against what freeze::freeze_group()
     * actually does when that same actor freezes that same team. The
     * teams are separate so that a freeze which succeeds cannot change
     * the state the next actor is judged on.
     *
     * The actor list is chosen so that each arm of the service's gate
     * is exercised in both directions:
     *   1 assigned guide holding :freeze                     -> admits
     *   2 assigned guide with :freeze PROHIBITED              -> refuses
     *   3 editing teacher: :manage, NO :freeze                -> admits
     *   4 Group Coordinator, uninvolved                       -> admits
     *   5 Group Coordinator nominated as successor guide      -> refuses
     *   6 plain non-editing teacher, guides nothing here      -> refuses
     * Rows 3 and 4 are the finding: both were refused by every control
     * in the plugin and admitted by the service.
     *
     * Engine note: nothing here rests on a rollback being visible.
     * Every verdict is read from the return value of a call made in
     * this process, and the one state read ($DB->get_field) is of a row
     * this test's own COMMITTED freeze wrote. preventResetByRollback()
     * is set because freeze_group() drives the core groups API.
     */
    public function test_the_freeze_control_and_the_freeze_service_admit_the_same_set(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, , , $course, $leader] = $this->world();
        $generator = $this->getDataGenerator();
        $context = $activity->context();

        $ownguide = $this->user_in($course, 'teacher');
        $prohibitedguide = $this->user_in($course, 'teacher');
        $manager = $this->user_in($course, 'editingteacher');
        $coordinator = $this->coordinator($activity, $course);
        $successor = $this->coordinator($activity, $course);
        $outsider = $this->user_in($course, 'teacher');

        // The one administrator's decision in the fixture: this guide
        // may no longer freeze anything, including their own team.
        $prohibitrole = $generator->create_role();
        assign_capability('mod/selfselectadvanced:freeze', CAP_PROHIBIT, $prohibitrole, $context->id, true);
        role_assign($prohibitrole, $prohibitedguide->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        // The premise the whole finding rests on, measured rather than
        // assumed: the manager holds the power to freeze on a guide's
        // behalf and does NOT hold the capability every control asked
        // for. If db/access.php ever grants it, this goes red and the
        // case below stops meaning anything.
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:manage', $context, (int) $manager->id),
            'the editing teacher archetype no longer holds :manage'
        );
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:freeze', $context, (int) $manager->id),
            'the editing teacher archetype now holds :freeze - ACT-002 would be moot'
        );

        $cases = [
            'assigned guide holding :freeze' => [$ownguide, ['guideid' => (int) $ownguide->id], true],
            'assigned guide with :freeze prohibited' => [
                $prohibitedguide,
                ['guideid' => (int) $prohibitedguide->id],
                false,
            ],
            'editing teacher without :freeze' => [$manager, [], true],
            'uninvolved coordinator' => [$coordinator, [], true],
            'coordinator nominated as successor guide' => [
                $successor,
                ['guidesuccessorid' => (int) $successor->id],
                false,
            ],
            'non-editing teacher who guides nothing here' => [$outsider, [], false],
        ];

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $examined = 0;
        foreach ($cases as $label => [$actor, $extra, $expected]) {
            $team = groups::get($activity, (int) $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $leader->id,
                'name' => 'Team for ' . $label,
                'state' => state::FIRM,
                'timeapproved' => time(),
            ] + $extra)->id);
            // One confirmed member of its own, so the team clears
            // gatekeeper::can_freeze()'s minimum size. Without it every
            // actor would be refused for a reason that has nothing to
            // do with authority, and the comparison below would be
            // between two constants.
            $plugingen->create_member([
                'groupid' => (int) $team->id,
                'userid' => (int) $this->user_in($course, 'student')->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);

            // What every control in the plugin now asks.
            $predicate = freeze::may_freeze_team($activity, $team, (int) $actor->id);
            $this->assertSame($expected, $predicate, $label . ': the control predicate');

            // What the service does when the button is pressed.
            $serviceadmits = true;
            try {
                freeze::freeze_group($activity, $team, (int) $actor->id);
            } catch (\moodle_exception $e) {
                $serviceadmits = false;
            }
            $this->assertSame(
                $predicate,
                $serviceadmits,
                $label . ': the control and the service disagree - one of them is lying to somebody'
            );
            // And the verdict is real, read back from the row rather
            // than from the absence of an exception.
            $this->assertSame(
                $expected ? state::FROZEN : state::FIRM,
                $DB->get_field('selfselectadvanced_group', 'state', ['id' => (int) $team->id], MUST_EXIST),
                $label . ': the stored state does not match the verdict'
            );
            $examined++;
        }
        $this->assertSame(6, $examined, 'six actors were meant to be compared');
    }

    /**
     * ACT-003. The coordinator dashboard offers the Freeze it is for -
     * on the rows the service would accept, and on no others.
     *
     * Driven through the REAL table: its own SQL supplies the rows, so
     * a column dropped from the SELECT is a column missing here too.
     * That is deliberate and load-bearing for the last case - the
     * conflict-of-interest guard reads guidesuccessorid, and a row
     * fetched without it would answer "not involved" and offer a
     * coordinator a Freeze the service refuses.
     *
     * Four rows examined, one per verdict.
     */
    public function test_the_coordinator_dashboard_offers_freeze_exactly_where_it_works(): void {
        $this->resetAfterTest();

        [$activity, $api, $baseline, $course, $leader] = $this->world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $coordinator = $this->coordinator($activity, $course);

        $expected = [
            'firm and uninvolved' => [['state' => state::FIRM], true],
            'firm but they are its successor guide' => [
                ['state' => state::FIRM, 'guidesuccessorid' => (int) $coordinator->id],
                false,
            ],
            'firm but they guide it' => [
                ['state' => state::FIRM, 'guideid' => (int) $coordinator->id],
                true,
            ],
            'not firm yet' => [['state' => state::FORMING], false],
        ];
        $wanted = [];
        foreach ($expected as $label => [$fields, $offered]) {
            $group = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $leader->id,
                'name' => $label,
                'timeapproved' => time(),
            ] + $fields);
            $wanted[(int) $group->id] = [$label, $offered];
        }
        // The fixture's own firm team, guided by somebody else: the
        // fifth row on the dashboard and the plainest positive case.
        $wanted[(int) $baseline->id] = ['the baseline firm team', true];

        $table = new \mod_selfselectadvanced\table\groups_table(
            'actionreachtable',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/coordinator.php', ['id' => $activity->cm()->id]),
            '',
            false,
            (int) $coordinator->id
        );
        $rows = $this->rows_of($table);
        $this->assertCount(5, $rows, 'the table did not return the five teams this test built');

        $examined = 0;
        foreach ($rows as $row) {
            [$label, $offered] = $wanted[(int) $row->id];
            $actions = $table->col_actions($row);
            $this->assertSame(
                $offered,
                str_contains($actions, 'action=freeze'),
                $label . ': the Freeze link does not match what the service would do'
            );
            // The offer and the service, on the same row.
            $this->assertSame(
                $offered,
                $row->state === state::FIRM
                    && freeze::may_freeze_team($activity, $row, (int) $coordinator->id),
                $label . ': the predicate itself disagrees with the expectation'
            );
            $examined++;
        }
        $this->assertSame(5, $examined, 'five rows were meant to be examined');
    }

    /**
     * ACT-004, UI half. An :assignguide holder is offered the decision
     * the capability is named for.
     *
     * The service half is narrowcaps_test::test_eoi_respond_via_assignguide;
     * this is the control. The actor is the Group Coordinator role,
     * because that is the holder the omission actually fell on: it
     * carries :assignguide and :viewall and NOT :manage, so it was
     * shown the panel, shown the pending interest, and shown no way to
     * answer it.
     *
     * Two viewers examined on the same team and the same interest -
     * the coordinator, and the same coordinator with :assignguide
     * PROHIBITED, which is the discriminating half: without it a test
     * that simply rendered the panel would pass on the broken code too.
     */
    public function test_an_assignguide_holder_is_offered_the_eoi_decision(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();

        [$activity, $api, , $course, $leader] = $this->world(['eoienabled' => 1]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $coordinator = $this->coordinator($activity, $course);
        $wantedguide = $this->user_in($course, 'teacher');
        $context = $activity->context();

        $listed = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Listed for interest',
            'state' => state::FORMING,
        ]);
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $listed->id]);
        $DB->set_field('selfselectadvanced_group', 'timelisted', time(), ['id' => $listed->id]);
        eoi::express($activity, (int) $listed->id, (int) $wantedguide->id, '', FORMAT_HTML);
        $listed = groups::get($activity, (int) $listed->id);

        // The premise: this holder is NOT a manager, and the panel is
        // reachable to them, so the only thing that could withhold the
        // decision is the decision predicate itself.
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:manage', $context, (int) $coordinator->id),
            'the coordinator role gained :manage - ACT-004 would be moot'
        );
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:assignguide', $context, (int) $coordinator->id),
            'the coordinator role no longer carries :assignguide'
        );

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);
        $output = $PAGE->get_renderer('core');
        $exported = (new \mod_selfselectadvanced\output\group_page(
            $api,
            $listed,
            (int) $coordinator->id
        ))->export_for_template($output);
        $this->assertTrue((bool) $exported->showeoipanel, 'the panel itself is not reachable - wrong fixture');
        $this->assertTrue(
            (bool) $exported->caneoirespond,
            'an :assignguide holder is shown the interests and no way to decide them'
        );
        // The row really does carry the two links, so the flag is not
        // being asserted about a panel with nothing in it.
        $pending = array_values(array_filter($exported->eoirows, static fn($r) => !empty($r->ispending)));
        $this->assertCount(1, $pending, 'one pending interest was built');
        $this->assertNotEmpty($pending[0]->accepturl);

        // The discriminating half: take the capability away and the
        // control goes with it. Same viewer, same team, same interest.
        $prohibit = $this->getDataGenerator()->create_role();
        assign_capability('mod/selfselectadvanced:assignguide', CAP_PROHIBIT, $prohibit, $context->id, true);
        role_assign($prohibit, $coordinator->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $after = (new \mod_selfselectadvanced\output\group_page(
            $api,
            $listed,
            (int) $coordinator->id
        ))->export_for_template($output);
        $this->assertTrue((bool) $after->showeoipanel, 'the panel closed for the wrong reason');
        $this->assertFalse(
            (bool) $after->caneoirespond,
            'the decision is still offered after :assignguide was prohibited'
        );

        // And the PAGE that receives the click asks the service's own
        // ladder (1.20.3, blind-audit finding 1: decide_refusal() is
        // THE ONE COPY - group.php's door, this renderer and
        // respond() itself all consume it, and the :assignguide arm
        // lives inside it). One file examined, in code rather than in
        // its comments.
        $this->assertStringContainsString(
            'eoi::decide_refusal(',
            $this->code_of('group.php'),
            'group.php eoirespond no longer asks the service ladder'
        );
    }

    /**
     * A course with one activity, one leader and one firm team.
     *
     * @param array $settings instance overrides
     * @return array [activity, api, firm group, course, leader]
     */
    private function world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 6,
            'maxmembership' => 6,
            'maxguided' => 20,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $leader = $this->user_in($course, 'student');
        $guide = $this->user_in($course, 'teacher');
        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Baseline',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), $course, $leader];
    }

    /**
     * One enrolled user.
     *
     * @param \stdClass $course the course
     * @param string $archetype the enrolment role shortname
     * @return \stdClass the user
     */
    private function user_in(\stdClass $course, string $archetype): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $archetype);

        return $user;
    }

    /**
     * A Group Coordinator, appointed the way the plugin appoints one:
     * the role it creates, assigned at the ACTIVITY's context.
     *
     * @param activity $activity the activity
     * @param \stdClass $course the course
     * @return \stdClass the user
     */
    private function coordinator(activity $activity, \stdClass $course): \stdClass {
        $user = $this->user_in($course, 'teacher');
        role_assign(coordinatorrole::ensure(), $user->id, $activity->context()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * Every row the table's OWN query returns.
     *
     * Fetched through $table->sql so that the columns under test are
     * the columns the page renders from - a test that hand-built the
     * rows would keep passing after a SELECT dropped one.
     *
     * @param \mod_selfselectadvanced\table\groups_table $table the table
     * @return \stdClass[]
     */
    private function rows_of(\mod_selfselectadvanced\table\groups_table $table): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT {$table->sql->fields} FROM {$table->sql->from} WHERE {$table->sql->where}",
            $table->sql->params
        );
    }

    /**
     * One plugin file's source with every comment removed.
     *
     * Comments are stripped because they are not the code: a check that
     * a sentence about a gate exists is not a check that the gate does.
     *
     * @param string $relative the path under the plugin root
     * @return string the file's code, comments removed
     */
    private function code_of(string $relative): string {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/mod/selfselectadvanced/' . $relative);
        $this->assertIsString($source, $relative . ' could not be read');

        $code = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $code .= $token->text;
        }

        return $code;
    }
}
