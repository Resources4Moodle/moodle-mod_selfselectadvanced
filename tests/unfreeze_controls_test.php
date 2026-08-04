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
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;

/**
 * UX-001: the unfreeze CONTROL and the unfreeze SERVICE admitted
 * different people, in both directions, on three different surfaces.
 *
 * freeze::unfreeze() admits two populations - a holder of :unfreeze who
 * is not a coordinator involved with this very team, and the team's own
 * assigned guide on a freeze no member of staff enforced (strategy
 * 1.19 C). Each surface described that rule for itself and each got it
 * wrong somewhere:
 *
 *  - output\group_page asked the CAPABILITY alone, so the guide was
 *    never offered the release on their own team's page, and an
 *    involved coordinator was offered one that refuses;
 *  - table\groups_table asked the STATE alone, so every frozen row on
 *    the coordinator dashboard carried a Release link - including the
 *    coordinator's own team;
 *  - group.php's action gate carried capability-or-guide but not the
 *    conflict of interest, so the involved coordinator reached the
 *    confirmation page and was refused only after typing a reason.
 *
 * All three now call freeze::may_unfreeze_team(), which IS
 * unfreeze()'s door answered without an exception. The tests below pin
 * BOTH ARMS on each surface: an actor who may release is offered it,
 * and an actor who may not is not - and the matrix asserts the offer
 * against what the service actually did, on rows read back with $DB.
 *
 * Nothing here rests on a rollback being visible, so both engines
 * discriminate identically: every fixture row is written and then read
 * back inside the same test.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\freeze::release_refusal
 * @covers     \mod_selfselectadvanced\local\freeze::may_unfreeze_team
 * @covers     \mod_selfselectadvanced\local\freeze::require_unfreeze_team
 * @covers     \mod_selfselectadvanced\local\tickets::involvement
 * @covers     \mod_selfselectadvanced\output\group_page
 * @covers     \mod_selfselectadvanced\table\groups_table::col_actions
 */
final class unfreeze_controls_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private $course;

    /** @var activity The activity. */
    private $activity;

    /** @var api The facade. */
    private $api;

    /**
     * A course, an activity and the students every team is built from.
     */
    private function world(): void {
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'minsize' => 1,
            'maxsize' => 4,
        ]);
        $this->activity = activity::from_instance((int) $instance->id);
        $this->api = new api($this->activity);
    }

    /**
     * One enrolled user.
     *
     * @param string $shortname the enrolment role
     * @param string $lastname a recognisable name
     * @return \stdClass the user
     */
    private function user_in(string $shortname, string $lastname): \stdClass {
        $user = $this->getDataGenerator()->create_user(['lastname' => $lastname]);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $shortname);

        return $user;
    }

    /**
     * A Group Coordinator, appointed the way the plugin appoints one.
     *
     * @param string $lastname a recognisable name
     * @return \stdClass the user
     */
    private function coordinator(string $lastname): \stdClass {
        $user = $this->user_in('teacher', $lastname);
        role_assign(coordinatorrole::ensure(), $user->id, $this->activity->context()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * A FROZEN team with two confirmed members, frozen by the actor
     * named - which is what decides group.frozenbystaff.
     *
     * @param int $guideid the assigned guide
     * @param int $freezerid who presses Freeze
     * @param string $name the team name
     * @return \stdClass the frozen group row
     */
    private function frozen_team(int $guideid, int $freezerid, string $name): \stdClass {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leader = $this->user_in('student', 'Leader');
        $member = $this->user_in('student', 'Member');
        $group = $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => $name,
            'state' => state::FIRM,
            'guideid' => $guideid,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        freeze::freeze_group($this->activity, groups::get($this->activity, (int) $group->id), $freezerid);

        return groups::get($this->activity, (int) $group->id);
    }

    /**
     * THE MATRIX. For every shape of actor this plugin has, the offer
     * and the door are compared against each other AND against what
     * the service actually did to the row.
     *
     * Each actor gets their own team, because a release is destructive
     * of the state it is judged on. Eight actors, eight teams, and the
     * expectation for each is stated in the fixture rather than derived
     * from the code under test.
     */
    public function test_the_offer_equals_the_door_for_every_actor(): void {
        global $DB;
        $this->resetAfterTest();
        $this->world();

        $guide = $this->user_in('teacher', 'Guide');
        $staff = $this->user_in('editingteacher', 'Staff');
        $bystander = $this->user_in('teacher', 'Bystander');
        $student = $this->user_in('student', 'Outsider');
        $coordinator = $this->coordinator('Coordinator');
        $guidingcoordinator = $this->coordinator('CoordinatorGuide');

        // The premises this finding rests on, measured rather than
        // assumed: the guide holds no :unfreeze, and the coordinator
        // holds it but not :manage (whose holders are exempt from the
        // conflict-of-interest rule).
        $context = $this->activity->context();
        $this->assertFalse(has_capability('mod/selfselectadvanced:unfreeze', $context, (int) $guide->id));
        $this->assertTrue(has_capability('mod/selfselectadvanced:unfreeze', $context, (int) $coordinator->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:manage', $context, (int) $coordinator->id));

        // Actor => [the team, may they release it, and if not, why not].
        $cases = [
            'editing teacher' => [
                'actor' => (int) $staff->id,
                'team' => $this->frozen_team((int) $guide->id, (int) $guide->id, 'T staff'),
                'may' => true,
                'refusal' => null,
            ],
            'uninvolved coordinator' => [
                'actor' => (int) $coordinator->id,
                'team' => $this->frozen_team((int) $guide->id, (int) $guide->id, 'T coord'),
                'may' => true,
                'refusal' => null,
            ],
            'guide of a team they froze themselves' => [
                'actor' => (int) $guide->id,
                'team' => $this->frozen_team((int) $guide->id, (int) $guide->id, 'T guideown'),
                'may' => true,
                'refusal' => null,
            ],
            'guide of a team staff froze' => [
                'actor' => (int) $guide->id,
                'team' => $this->frozen_team((int) $guide->id, (int) $staff->id, 'T stafffroze'),
                'may' => false,
                'refusal' => freeze::RELEASE_STAFFFROZE,
            ],
            'coordinator who guides this very team' => [
                'actor' => (int) $guidingcoordinator->id,
                'team' => $this->frozen_team((int) $guidingcoordinator->id, (int) $staff->id, 'T coordguide'),
                'may' => false,
                'refusal' => freeze::RELEASE_CONFLICT,
            ],
            'non-editing teacher who guides nothing' => [
                'actor' => (int) $bystander->id,
                'team' => $this->frozen_team((int) $guide->id, (int) $guide->id, 'T bystander'),
                'may' => false,
                'refusal' => freeze::RELEASE_CAPABILITY,
            ],
            'a student' => [
                'actor' => (int) $student->id,
                'team' => $this->frozen_team((int) $guide->id, (int) $guide->id, 'T student'),
                'may' => false,
                'refusal' => freeze::RELEASE_CAPABILITY,
            ],
        ];

        $examined = 0;
        foreach ($cases as $label => $case) {
            $examined++;
            $group = $case['team'];
            $this->assertSame(state::FROZEN, $group->state, "fixture for '$label' is not frozen");

            $this->assertSame(
                $case['refusal'],
                freeze::release_refusal($this->activity, $group, $case['actor']),
                "release_refusal() disagrees with the fixture for '$label'"
            );
            $this->assertSame(
                $case['may'],
                freeze::may_unfreeze_team($this->activity, $group, $case['actor']),
                "the offer is wrong for '$label'"
            );

            // AND THE DOOR ITSELF. The offer is only worth anything if
            // the service agrees, so the service is asked - and the row
            // is read back from the database either way.
            $released = true;
            try {
                freeze::unfreeze($this->activity, $group, $case['actor']);
            } catch (\moodle_exception $e) {
                $released = false;
            }
            $this->assertSame(
                $case['may'],
                $released,
                "the service and the offer disagree for '$label'"
            );
            $this->assertSame(
                $case['may'] ? state::FIRM : state::FROZEN,
                $DB->get_field('selfselectadvanced_group', 'state', ['id' => $group->id]),
                "the row does not show what '$label' was told"
            );
        }
        $this->assertSame(7, $examined, 'the matrix did not examine every actor shape');
    }

    /**
     * ARM ONE on the team page: the assigned guide who MAY release
     * their own team is offered the control there.
     *
     * This is the arm the old predicate failed. The guide holds no
     * :unfreeze - asserted above - so a capability-only test hides the
     * button from the one person strategy 1.19 C gave the release to,
     * on the page the release lives on.
     */
    public function test_the_team_page_offers_the_release_to_the_guide_who_may_take_it(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->world();

        $guide = $this->user_in('teacher', 'Guide');
        $group = $this->frozen_team((int) $guide->id, (int) $guide->id, 'Guide releases');

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $this->activity->cm()->id]);
        $exported = (new \mod_selfselectadvanced\output\group_page(
            $this->api,
            $group,
            (int) $guide->id
        ))->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue(
            (bool) $exported->canunfreeze,
            'the guide the service admits is not offered the release on their own team page'
        );
        $this->assertStringContainsString('action=unfreeze', (string) $exported->unfreezeurl);
    }

    /**
     * ARM TWO on the team page: a Group Coordinator involved with this
     * very team is NOT offered a control that refuses them.
     *
     * They hold :unfreeze, so the old capability-only predicate drew
     * the button; tickets::involvement() is what withholds it now, and
     * the second half of the test proves the same viewer IS offered it
     * on a team they are not involved in - so the withholding is the
     * conflict rule and not a viewer who can never see anything.
     */
    public function test_the_team_page_withholds_the_release_from_an_involved_coordinator(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->world();

        $guide = $this->user_in('teacher', 'Guide');
        $staff = $this->user_in('editingteacher', 'Staff');
        $coordinator = $this->coordinator('Coordinator');
        // The coordinator's OWN team, frozen on their behalf by staff -
        // a guide cannot freeze a team they do not guide.
        $own = $this->frozen_team((int) $coordinator->id, (int) $staff->id, 'Their own team');
        $other = $this->frozen_team((int) $guide->id, (int) $guide->id, 'Somebody else');

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $this->activity->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $onown = (new \mod_selfselectadvanced\output\group_page(
            $this->api,
            $own,
            (int) $coordinator->id
        ))->export_for_template($output);
        $this->assertFalse(
            (bool) $onown->canunfreeze,
            'the coordinator is offered a release of their own team that unfreeze() refuses'
        );

        $onother = (new \mod_selfselectadvanced\output\group_page(
            $this->api,
            $other,
            (int) $coordinator->id
        ))->export_for_template($output);
        $this->assertTrue(
            (bool) $onother->canunfreeze,
            'the conflict rule withheld the control from a team the coordinator is not involved in'
        );
    }

    /**
     * The staff dashboards' table, both arms, on the rows the table's
     * OWN query returns - so a SELECT that dropped frozenbystaff or
     * guidesuccessorid is a failure here rather than a silent widening.
     */
    public function test_the_dashboard_table_offers_the_release_only_where_the_service_agrees(): void {
        global $DB;
        $this->resetAfterTest();
        $this->world();

        $guide = $this->user_in('teacher', 'Guide');
        $staff = $this->user_in('editingteacher', 'Staff');
        $coordinator = $this->coordinator('Coordinator');
        $own = $this->frozen_team((int) $coordinator->id, (int) $staff->id, 'Coordinator own');
        $other = $this->frozen_team((int) $guide->id, (int) $guide->id, 'Not theirs');

        $table = new \mod_selfselectadvanced\table\groups_table(
            'ux001',
            $this->activity,
            $this->api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/coordinator.php', ['id' => $this->activity->cm()->id]),
            '',
            false,
            (int) $coordinator->id
        );
        $rows = $DB->get_records_sql(
            "SELECT {$table->sql->fields} FROM {$table->sql->from} WHERE {$table->sql->where}",
            $table->sql->params
        );
        $this->assertCount(2, $rows, 'the fixture did not produce two rows');

        // The columns the predicate needs are really in the SELECT.
        $sample = reset($rows);
        $this->assertObjectHasProperty('frozenbystaff', $sample);
        $this->assertObjectHasProperty('guidesuccessorid', $sample);

        $this->assertStringNotContainsString(
            'action=unfreeze',
            $table->col_actions($rows[$own->id]),
            'the coordinator dashboard offers a release of the coordinator own team'
        );
        $this->assertStringContainsString(
            'action=unfreeze',
            $table->col_actions($rows[$other->id]),
            'the release vanished from a row the service would accept'
        );
    }

    /**
     * The table's other arm, the one that is not about conflict: a
     * :manage holder on a site that has withdrawn :unfreeze from them.
     *
     * unfreeze() refuses them for want of the capability, and the
     * dashboard used to offer the link anyway because it asked only
     * whether the row was frozen.
     */
    public function test_the_dashboard_table_withholds_the_release_when_the_capability_is_prohibited(): void {
        global $DB;
        $this->resetAfterTest();
        $this->world();

        $guide = $this->user_in('teacher', 'Guide');
        $manager = $this->user_in('editingteacher', 'Manager');
        $group = $this->frozen_team((int) $guide->id, (int) $guide->id, 'Prohibited');
        $context = $this->activity->context();

        $build = fn(): \mod_selfselectadvanced\table\groups_table
            => new \mod_selfselectadvanced\table\groups_table(
                'ux001b',
                $this->activity,
                $this->api->gatekeeper(),
                new \moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $this->activity->cm()->id]),
                '',
                false,
                (int) $manager->id
            );
        $fetch = function (\mod_selfselectadvanced\table\groups_table $table) use ($DB): array {
            return $DB->get_records_sql(
                "SELECT {$table->sql->fields} FROM {$table->sql->from} WHERE {$table->sql->where}",
                $table->sql->params
            );
        };

        // The negative control first: while they hold it, the link is
        // there - so the absence below is the prohibition and not the
        // fixture.
        $before = $build();
        $rows = $fetch($before);
        $this->assertStringContainsString('action=unfreeze', $before->col_actions($rows[$group->id]));

        $prohibit = $this->getDataGenerator()->create_role();
        assign_capability('mod/selfselectadvanced:unfreeze', CAP_PROHIBIT, $prohibit, $context->id, true);
        role_assign($prohibit, $manager->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('mod/selfselectadvanced:unfreeze', $context, (int) $manager->id));

        $after = $build();
        $rows = $fetch($after);
        $this->assertStringNotContainsString(
            'action=unfreeze',
            $after->col_actions($rows[$group->id]),
            'the release is still offered after :unfreeze was prohibited'
        );
    }

    /**
     * A row that arrived without the columns the rule reads is REFUSED,
     * not judged.
     *
     * Every field release_refusal() reads answers permissively when it
     * is absent - no conflict, no staff freeze - so a partial row would
     * widen the offer silently, which is the failure mode this whole
     * finding is made of.
     */
    public function test_a_partial_row_is_refused_rather_than_judged(): void {
        $this->resetAfterTest();
        $this->world();

        $guide = $this->user_in('teacher', 'Guide');
        $group = $this->frozen_team((int) $guide->id, (int) $guide->id, 'Partial');

        $examined = 0;
        foreach (['guideid', 'guidesuccessorid', 'frozenbystaff'] as $field) {
            $partial = clone $group;
            unset($partial->$field);
            $examined++;
            try {
                freeze::release_refusal($this->activity, $partial, (int) $guide->id);
                $this->fail("a row missing $field was judged instead of refused");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
        $this->assertSame(3, $examined, 'the guard was not asked about every field it reads');
    }
}
