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

use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;

/**
 * Asking to join another team, and who says yes (strategy 1.19 B and C).
 *
 * The maintainer's rule in one sentence: self-service until the leader
 * accepts, the guide releases a settled team before it can change, and
 * a coordinator may answer anything. These check each clause, and that
 * acceptance really does go through the move engine rather than around
 * it - a request that would break the target team is refused.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\freeze
 */
final class joinrequests_test extends \advanced_testcase {
    /**
     * Two teams with room, a wanderer, a guide, a coordinator.
     *
     * @param array $settings instance overrides
     * @return array [activity, alpha, beta, wanderer, guide, coordinator, manager, course]
     */
    private function setup_world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'JR1']);
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 1,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $mk = function (string $role) use ($generator, $course) {
            $u = $generator->create_user();
            $generator->enrol_user($u->id, $course->id, $role);

            return $u;
        };
        $alphalead = $mk('student');
        $betalead = $mk('student');
        $wanderer = $mk('student');
        $guide = $mk('teacher');
        $manager = $mk('editingteacher');
        $coordinator = $mk('teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, $activity->context());

        $alpha = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $alphalead->id,
            'name' => 'Alpha',
        ]);
        $beta = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $betalead->id,
            'name' => 'Beta',
        ]);
        // The generator already gives the leader their member row.
        $plugingen->create_member([
            'groupid' => $alpha->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            groups::get($activity, (int) $alpha->id),
            groups::get($activity, (int) $beta->id),
            $wanderer,
            $guide,
            $coordinator,
            $manager,
            $course,
        ];
    }

    /**
     * A world where two teams each are legal: the wanderer is
     * confirmed in Alpha AND in Gamma, and Beta is what they ask for.
     *
     * @param int $cap the activity's maxmembership
     * @return array [activity, alpha, beta, gamma, wanderer, guide, coordinator, manager]
     */
    private function setup_multi_world(int $cap = 2): array {
        global $DB;

        [$activity, $alpha, $beta, $wanderer, $guide, $coordinator, $manager, $course]
            = $this->setup_world(['maxmembership' => $cap]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $gammalead = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($gammalead->id, $course->id, 'student');
        $gamma = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $gammalead->id,
            'name' => 'Gamma',
        ]);
        $plugingen->create_member([
            'groupid' => $gamma->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        // The three teams are made in the same second, and
        // "timecreated ASC" over a tie is no order at all. Back-dating
        // Alpha pins the sequence the ordering assertions rely on
        // without weakening them.
        $DB->set_field('selfselectadvanced_group', 'timecreated', time() - 5, ['id' => $alpha->id]);

        return [$activity, $alpha, $beta, groups::get($activity, (int) $gamma->id),
            $wanderer, $guide, $coordinator, $manager];
    }

    /**
     * Expect one refusal string key from a callable.
     *
     * @param string $stringkey the expected errorcode
     * @param callable $fn the action
     */
    private function assert_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }

    /**
     * The ordinary case: a student asks, the TARGET team's leader
     * accepts, and the move happens through the engine.
     */
    public function test_leader_accepts_and_the_student_moves(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Closer to my programme', (int) $wanderer->id);
        $this->assertSame(joinrequests::STATUS_REQUESTED, $request->status);
        $this->assertSame((int) $alpha->id, (int) $request->sourcegroupid);

        joinrequests::respond($activity, (int) $request->id, true, 'Glad to have you', (int) $beta->leaderid);

        $now = joinrequests::current_groups($activity, (int) $wanderer->id);
        $this->assertSame([(int) $beta->id], array_map('intval', array_keys($now)));
        $sink->close();
    }

    /**
     * Only the target team's leader answers - not the source team's,
     * and not another student.
     */
    public function test_only_the_target_leader_answers(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);

        $this->assert_refused('refusaljoinnotleader', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $alpha->leaderid
        ));
        $this->assert_refused('refusaljoinnotleader', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $wanderer->id
        ));
        $sink->close();
    }

    /**
     * The maintainer's escape hatch: a coordinator may answer any
     * request, for an absent leader or a contested case.
     */
    public function test_a_coordinator_may_answer_anything(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer, , $coordinator] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, true, 'Approved centrally', (int) $coordinator->id);

        $this->assertSame(
            [(int) $beta->id],
            array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $wanderer->id)))
        );
        $sink->close();
    }

    /**
     * Asking twice, asking to join one's own team, and asking with no
     * reason are all refused.
     */
    public function test_the_asking_gates(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $this->assert_refused('refusaljoinreason', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            '   ',
            (int) $wanderer->id
        ));
        $this->assert_refused('refusaljoinalready', fn() => joinrequests::request(
            $activity,
            (int) $alpha->id,
            'Staying put',
            (int) $wanderer->id
        ));
        $this->assert_refused('refusaljoinownteam', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'It is mine',
            (int) $beta->leaderid
        ));

        joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $this->assert_refused('refusaljoinduplicate', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Again',
            (int) $wanderer->id
        ));
        $sink->close();
    }

    /**
     * A request that would break the target team's composition is
     * refused AT ACCEPTANCE, naming the rule - and the request stays
     * open so the leader can see why.
     */
    public function test_acceptance_runs_the_composition_rules(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // Teams of one: admitting anybody exceeds the maximum.
        [$activity, , $beta, $wanderer] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $this->assert_refused('refusaljoinrules', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $beta->leaderid
        ));

        $fresh = joinrequests::get($activity, (int) $request->id);
        $this->assertSame(joinrequests::STATUS_REQUESTED, $fresh->status);
        $sink->close();
    }

    /**
     * A frozen team neither takes anybody nor lets anybody go until it
     * is released.
     */
    public function test_a_frozen_team_is_closed_until_released(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer, $guide] = $this->setup_world();

        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $beta->id]);
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $guide->id);

        $this->assert_refused('refusaljointargetfrozen', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Please',
            (int) $wanderer->id
        ));

        // The guide froze it, so the guide may release it - and then it
        // takes people again.
        freeze::unfreeze($activity, groups::get($activity, (int) $beta->id), (int) $guide->id);
        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, true, '', (int) $beta->leaderid);
        $this->assertSame(
            [(int) $beta->id],
            array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $wanderer->id)))
        );
        $sink->close();
    }

    /**
     * The limit the maintainer set on releasing: a guide releases until
     * staff enforce a freeze, and not afterwards.
     */
    public function test_a_guide_cannot_release_what_staff_froze(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, , $guide, $coordinator, $manager] = $this->setup_world();

        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $beta->id]);

        // A manager's freeze is staff-enforced and holds.
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $manager->id);
        $frozen = groups::get($activity, (int) $beta->id);
        $this->assertSame(1, (int) $frozen->frozenbystaff);
        $this->assert_refused(
            'refusalreleasestafffroze',
            fn() => freeze::unfreeze($activity, $frozen, (int) $guide->id)
        );

        // The staff who froze it can still release it.
        freeze::unfreeze($activity, $frozen, (int) $manager->id);

        // A coordinator's freeze holds against the guide too.
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $coordinator->id);
        $frozen = groups::get($activity, (int) $beta->id);
        $this->assertSame(1, (int) $frozen->frozenbystaff);
        $this->assert_refused(
            'refusalreleasestafffroze',
            fn() => freeze::unfreeze($activity, $frozen, (int) $guide->id)
        );
        $sink->close();
    }

    /**
     * A guide's own freeze is not staff-enforced, so the guide may
     * release it - which is the whole of what 1.19 C adds.
     *
     * Who ELSE may call the service is deliberately unchanged: it has
     * always trusted its callers on the capability and left the pages
     * to enforce it. Widening a service guard would have taken
     * authority from actors who already had it, the mistake 1.16 and
     * 1.17 each made once.
     */
    public function test_a_guide_releases_their_own_freeze(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, , $guide] = $this->setup_world();

        global $DB;
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $beta->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $beta->id]);
        freeze::freeze_group($activity, groups::get($activity, (int) $beta->id), (int) $guide->id);

        $frozen = groups::get($activity, (int) $beta->id);
        $this->assertSame(0, (int) $frozen->frozenbystaff);

        freeze::unfreeze($activity, $frozen, (int) $guide->id);
        $this->assertSame(state::FIRM, groups::get($activity, (int) $beta->id)->state);
        $sink->close();
    }

    /**
     * A request can be taken back while nobody has answered it, by its
     * author and nobody else.
     */
    public function test_withdrawing_ones_own_request(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer] = $this->setup_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $this->assert_refused('refusaljoinnotyours', fn() => joinrequests::withdraw(
            $activity,
            (int) $request->id,
            (int) $beta->leaderid
        ));

        $withdrawn = joinrequests::withdraw($activity, (int) $request->id, (int) $wanderer->id);
        $this->assertSame('cancelled', $withdrawn->status);

        // Withdrawn frees the slot, so a fresh request is accepted.
        joinrequests::request($activity, (int) $beta->id, 'Asking properly', (int) $wanderer->id);
        $sink->close();
    }

    /**
     * Acceptance funnels through the move engine, so the move engine's
     * per-group quota exemption reaches it: a request into a team that
     * is EXEMPT from the composition rules is accepted even though the
     * team does not satisfy them, as long as the team the student
     * leaves still does.
     *
     * The set-level reading of exemption used to refuse this, and the
     * student saw only "refusaljoinrules" with no way to act on it.
     */
    public function test_accept_into_exempt_group_commits(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        // One Computer seat. Alpha's leader fills it and stays; Beta
        // can never fill it, so Beta is exempted instead.
        \mod_selfselectadvanced\local\quota\slots::create($activity, (object) [
            'mincount' => 1, 'dimension' => 'department', 'matchtype' => 'value',
            'value' => 'Computer', 'allowoverlap' => 0,
        ], (int) get_admin()->id);
        \mod_selfselectadvanced\local\attributes\manager::set((int) $alpha->leaderid, ['department' => 'Computer'], 2);
        \mod_selfselectadvanced\local\attributes\manager::set((int) $beta->leaderid, ['department' => 'Elsewhere'], 2);
        \mod_selfselectadvanced\local\attributes\manager::set((int) $wanderer->id, ['department' => 'Elsewhere'], 2);
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $beta->id,
            ['quotaexempt' => 1],
            0
        );

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        $decided = joinrequests::respond($activity, (int) $request->id, true, 'ok', (int) $beta->leaderid);

        $this->assertSame('committed', $decided->status);
        $this->assertSame(
            [(int) $beta->id],
            array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $wanderer->id)))
        );
        $sink->close();
    }

    /**
     * Multi-membership is supported, so "the team a student is in" is a
     * set: a student in two teams is asked which one they would leave,
     * and refused - by name - rather than chosen for.
     */
    public function test_a_two_team_student_must_say_which_team_they_leave(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, , $wanderer] = $this->setup_multi_world();

        $this->assert_refused('refusaljoinsourcerequired', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'Please',
            (int) $wanderer->id
        ));

        // The refusal names both teams, so the student can act on it.
        try {
            joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
            $this->fail('Expected refusal refusaljoinsourcerequired');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Alpha', (string) $e->a);
            $this->assertStringContainsString('Gamma', (string) $e->a);
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_move', ['activityid' => $activity->id()]));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The team the student named is the team that is recorded, and the
     * team that is left - the other membership is untouched.
     */
    public function test_the_chosen_source_is_the_one_recorded_and_the_one_left(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $gamma, $wanderer] = $this->setup_multi_world();

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Nearer my lab',
            (int) $wanderer->id,
            (int) $gamma->id
        );
        $this->assertSame((int) $gamma->id, (int) $request->sourcegroupid);

        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);

        $status = fn(int $groupid): string => (string) $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['groupid' => $groupid, 'userid' => (int) $wanderer->id]
        );
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $alpha->id));
        $this->assertSame(groups::STATUS_REMOVED, $status((int) $gamma->id));
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $beta->id));

        $this->assertSame(
            [(int) $alpha->id, (int) $beta->id],
            array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $wanderer->id)))
        );
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * "Already in that team" is judged across every membership, not
     * against whichever row an unordered fetch returned first - and it
     * is judged BEFORE the source question, so a student in the target
     * is told the useful thing.
     */
    public function test_a_member_of_the_target_is_refused_whichever_row_comes_back_first(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['maxmembership' => 2]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_member([
            'groupid' => $beta->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        // No source stated: already-in-target must beat source-required.
        $this->assert_refused('refusaljoinalready', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'x',
            (int) $wanderer->id
        ));
        // And with a source stated, the same answer.
        $this->assert_refused('refusaljoinalready', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'x',
            (int) $wanderer->id,
            (int) $alpha->id
        ));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The frozen-source refusal follows the team the student SELECTED,
     * not whichever of their teams came back first.
     */
    public function test_the_frozen_refusal_follows_the_selected_source(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $gamma, $wanderer, $guide] = $this->setup_multi_world();

        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $gamma->id]);
        $DB->set_field('selfselectadvanced_group', 'guideid', $guide->id, ['id' => $gamma->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $gamma->id]);
        freeze::freeze_group($activity, groups::get($activity, (int) $gamma->id), (int) $guide->id);

        $this->assert_refused('refusaljoinsourcefrozen', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'x',
            (int) $wanderer->id,
            (int) $gamma->id
        ));

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'x',
            (int) $wanderer->id,
            (int) $alpha->id
        );
        $this->assertSame((int) $alpha->id, (int) $request->sourcegroupid);
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * "Keep every team and add this one" is a choice the student can
     * state, and it survives acceptance: nothing is removed.
     */
    public function test_an_extra_membership_is_a_choice_the_student_can_make(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['maxmembership' => 2]);

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Both, please',
            (int) $wanderer->id,
            joinrequests::SOURCE_ADDITIONAL
        );
        $this->assertNull($request->sourcegroupid);

        joinrequests::respond($activity, (int) $request->id, true, 'ok', (int) $beta->leaderid);

        $status = fn(int $groupid): string => (string) $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['groupid' => $groupid, 'userid' => (int) $wanderer->id]
        );
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $alpha->id));
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $beta->id));
        $this->assertSame(2, groups::count_memberships($activity, (int) $wanderer->id));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * An extra membership the cap has no room for is refused when it is
     * asked for, naming the numbers.
     */
    public function test_an_extra_membership_at_the_cap_is_refused_when_asked(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer] = $this->setup_world();

        try {
            joinrequests::request(
                $activity,
                (int) $beta->id,
                'x',
                (int) $wanderer->id,
                joinrequests::SOURCE_ADDITIONAL
            );
            $this->fail('Expected refusal refusaljoinnoheadroom');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinnoheadroom', $e->errorcode);
            $this->assertSame(1, (int) $e->a->current);
            $this->assertSame(1, (int) $e->a->max);
        }
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The dead end of finding 2d, dissolved: a request carrying a
     * deliberate NULL source whose author has since filled their cap is
     * judged by the composition rules - a refusal the leader can act on
     * - and never silently swapped for a membership the student did not
     * offer.
     */
    public function test_an_extra_membership_over_cap_is_refused_at_acceptance_not_silently_swapped(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer, , , , $course] = $this->setup_world(['maxmembership' => 2]);

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Both, please',
            (int) $wanderer->id,
            joinrequests::SOURCE_ADDITIONAL
        );
        $this->assertNull($request->sourcegroupid);

        // MEANWHILE: the student fills their cap elsewhere.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $gammalead = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($gammalead->id, $course->id, 'student');
        $gamma = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $gammalead->id,
            'name' => 'Gamma',
        ]);
        $plugingen->create_member([
            'groupid' => $gamma->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $this->assert_refused('refusaljoinrules', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $beta->leaderid
        ));

        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            joinrequests::get($activity, (int) $request->id)->status
        );
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
        ]));
        $status = fn(int $groupid): string => (string) $DB->get_field(
            'selfselectadvanced_member',
            'status',
            ['groupid' => $groupid, 'userid' => (int) $wanderer->id]
        );
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $alpha->id));
        $this->assertSame(groups::STATUS_CONFIRMED, $status((int) $gamma->id));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The concrete interleaving: ask naming Gamma, leave Gamma, then
     * answer. The engine error the leader could not read becomes a
     * refusal in the workflow's own words, and the request stays open.
     */
    public function test_a_source_left_between_asking_and_answering_is_refused_readably(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $gamma, $wanderer] = $this->setup_multi_world();

        // 1. ASK.
        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'x',
            (int) $wanderer->id,
            (int) $gamma->id
        );

        // 2. MEANWHILE: the student leaves the team they offered.
        $DB->set_field(
            'selfselectadvanced_member',
            'status',
            groups::STATUS_REMOVED,
            ['groupid' => (int) $gamma->id, 'userid' => (int) $wanderer->id]
        );

        // 3. ANSWER.
        $this->assert_refused('refusaljoinsourcegone', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $beta->leaderid
        ));

        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            joinrequests::get($activity, (int) $request->id)->status
        );
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
        ]));

        // The student can act on it: withdraw, then re-file correctly.
        joinrequests::withdraw($activity, (int) $request->id, (int) $wanderer->id);
        $corrected = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Alpha this time',
            (int) $wanderer->id,
            (int) $alpha->id
        );
        $this->assertSame((int) $alpha->id, (int) $corrected->sourcegroupid);
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The interleaving that used to destroy a membership in silence.
     *
     * resolve_source() refuses at ASK time when the student is already
     * confirmed in the target (refusaljoinalready). Nothing re-checked
     * it at ANSWER time, and the check matters most there: between the
     * two the student can be admitted to the target by a different
     * route entirely - an invitation they accept, a manager's move.
     *
     * The move engine then sees gain=0 (they are already in the
     * target) and loss=1, a NET -1, so the L4 cap check waves it
     * through, the source membership is set to removed, and respond()
     * mails the student that their request succeeded. They lose a team
     * and are told they gained one.
     *
     * The guard sits on the same in-lock re-read as
     * refusaljoinsourcegone and keeps the request OPEN, so the decider
     * can decline it with a note and nothing is destroyed.
     */
    public function test_a_target_joined_between_asking_and_answering_cannot_cost_the_source(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // Cap 2: Alpha AND Beta together is a legal end state, which is
        // what makes the loss silent rather than a cap refusal.
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world(['maxmembership' => 2]);

        // 1. ASK: join Beta, offering to leave Alpha.
        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'please',
            (int) $wanderer->id,
            (int) $alpha->id
        );

        // 2. MEANWHILE: they get into Beta by the other supported
        // route - Beta's leader invites them and they accept.
        $invitations = (new local\api($activity))->invitations();
        $invitations->send($beta, (int) $wanderer->id, (int) $beta->leaderid);
        $invitations->accept($beta, (int) $wanderer->id);

        $confirmed = function (int $groupid) use ($DB, $wanderer): bool {
            return $DB->record_exists('selfselectadvanced_member', [
                'groupid' => $groupid,
                'userid' => (int) $wanderer->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        };
        $this->assertTrue($confirmed((int) $alpha->id));
        $this->assertTrue($confirmed((int) $beta->id));

        // 3. ANSWER: Beta's leader presses Accept on the stale request.
        $this->assert_refused('refusaljointargetalready', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '',
            (int) $beta->leaderid
        ));

        // Nothing was destroyed: both memberships survive.
        $this->assertTrue($confirmed((int) $alpha->id));
        $this->assertTrue($confirmed((int) $beta->id));

        // Same contract as refusaljoinsourcegone: the request is still
        // open, so the decider can decline it with a note.
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            joinrequests::get($activity, (int) $request->id)->status
        );
        $decided = joinrequests::respond(
            $activity,
            (int) $request->id,
            false,
            'You are already in Beta.',
            (int) $beta->leaderid
        );
        $this->assertSame(joinrequests::STATUS_DECLINED, $decided->status);
        $this->assertTrue($confirmed((int) $alpha->id));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The plural lookup lists every team in a defined order and warns
     * about nothing - the single-row fetch it replaced did neither.
     */
    public function test_current_groups_lists_every_team_in_order_without_a_warning(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, , $gamma, $wanderer] = $this->setup_multi_world();

        $all = joinrequests::current_groups($activity, (int) $wanderer->id);
        $this->assertCount(2, $all);
        $this->assertSame([(int) $alpha->id, (int) $gamma->id], array_map('intval', array_keys($all)));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * A stated source is only ever a team this student is confirmed in.
     *
     * The picker offers their own teams and nothing else, so an id
     * naming somebody else's is a forged post rather than a mistake -
     * and the SERVER is what refuses it, not the form. Nothing is
     * recorded.
     */
    public function test_a_source_that_is_not_theirs_is_refused_by_the_server(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer, , , , $course] = $this->setup_world(['maxmembership' => 2]);

        // A team the wanderer has nothing to do with.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $strangerlead = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($strangerlead->id, $course->id, 'student');
        $stranger = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $strangerlead->id,
            'name' => 'Delta',
        ]);

        $this->assert_refused('refusaljoinsourcenotyours', fn() => joinrequests::request(
            $activity,
            (int) $beta->id,
            'x',
            (int) $wanderer->id,
            (int) $stranger->id
        ));

        $this->assertSame(0, $DB->count_records('selfselectadvanced_move', ['activityid' => $activity->id()]));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * The roster read INSIDE the lock is the one that decides.
     *
     * The concrete interleaving, driven through locks::set_test_hook():
     * the form was rendered while the student was still in Gamma, and
     * they leave Gamma in the exact window between this request's
     * pre-lock read and its lock. Storing the pre-lock answer would
     * record a source they are no longer in - the stale datum finding
     * 2b is about - so the second read has to be the authoritative one.
     */
    public function test_the_source_is_decided_by_the_read_inside_the_lock(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $gamma, $wanderer] = $this->setup_multi_world();

        $fired = false;
        locks::set_test_hook(function (string $resource) use (&$fired, $DB, $gamma, $wanderer): void {
            if ($resource !== 'joinrequest:user:' . (int) $wanderer->id || $fired) {
                return;
            }
            $fired = true;
            locks::set_test_hook(null);
            // The racing writer: the student leaves the team they named
            // while this request is on its way to the lock.
            $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
                'groupid' => (int) $gamma->id,
                'userid' => (int) $wanderer->id,
            ]);
        });

        try {
            $this->assert_refused('refusaljoinsourcenotyours', fn() => joinrequests::request(
                $activity,
                (int) $beta->id,
                'Nearer my lab',
                (int) $wanderer->id,
                (int) $gamma->id
            ));
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired);
        $this->assertSame(0, $DB->count_records('selfselectadvanced_move', ['activityid' => $activity->id()]));
        $sink->close();
        $this->assertDebuggingNotCalled();
    }

    /**
     * D6-5: the refusal used to say nothing. validate_set() returns
     * each verdict as an ARRAY and first_reason() read it with OBJECT
     * syntax, so every branch was empty and the message always fell
     * through to the general string - the staff member never saw which
     * rule refused, or by how much.
     */
    public function test_first_reason_names_rule_regression(): void {
        $this->resetAfterTest();
        // With maxsize 1 Beta's leader alone already fills it, so an
        // acceptance breaks L2 with real figures.
        [$activity, , $beta, $wanderer, , , $manager] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        try {
            joinrequests::respond($activity, (int) $request->id, true, 'ok', (int) $manager->id);
            $this->fail('Expected refusaljoinrules');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
            $this->assertStringContainsString('L2', $e->getMessage());
            $this->assertStringContainsString(
                get_string('moveruleL2', 'mod_selfselectadvanced', (object) ['after' => 2, 'max' => 1]),
                $e->getMessage()
            );
            $this->assertStringNotContainsString(
                get_string('refusaljoinrulesgeneral', 'mod_selfselectadvanced'),
                $e->getMessage()
            );
        }
    }

    /**
     * Decision 6: staff may accept over a failing rule, with a note,
     * through the SAME move-scope override the staging form uses.
     */
    public function test_staff_accept_with_bypass(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, , , $manager] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $sink = $this->redirectEvents();
        joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            'Guide agreed: one over on Beta',
            (int) $manager->id,
            ['L2']
        );
        $overridden = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\move_rules_overridden
        ));
        $sink->close();

        $this->assertSame('committed', $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id]));
        $this->assertTrue($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]));
        $this->assertCount(1, $overridden);
        $this->assertSame(['L2'], $overridden[0]->other['rules']);
        $this->assertSame('Guide agreed: one over on Beta', $overridden[0]->other['reason']);
        $this->assertSame((int) $wanderer->id, (int) $overridden[0]->relateduserid);
    }

    /**
     * T-10 boundary: the staff override never leaks into the
     * participant matrix. The target team's own student leader posting
     * a crafted bypass[] is refused on the ACTOR's capability, whatever
     * the form rendered.
     */
    public function test_student_leader_crafted_bypass_refused(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer] = $this->setup_world(['maxsize' => 1]);
        $betaleader = (int) $beta->leaderid;

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $this->assert_refused('refusaljoinbypasscap', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            'I say so',
            $betaleader,
            ['L2']
        ));

        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            $DB->get_field('selfselectadvanced_move', 'status', ['id' => $request->id])
        );
        $this->assertSame(0, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'scope' => 'move',
        ]));
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]));
    }

    /**
     * The note IS the reason: a bypass with an empty one is refused at
     * the same seam a staged commit is.
     */
    public function test_bypass_requires_note(): void {
        $this->resetAfterTest();
        [$activity, , $beta, $wanderer, , , $manager] = $this->setup_world(['maxsize' => 1]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $this->assert_refused('errmoveoverridereasonrequired', fn() => joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            '   ',
            (int) $manager->id,
            ['L2']
        ));
    }
}
