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
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_course::instance($course->id));

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

        $now = joinrequests::current_group($activity, (int) $wanderer->id);
        $this->assertNotNull($now);
        $this->assertSame((int) $beta->id, (int) $now->id);
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

        $this->assertSame((int) $beta->id, (int) joinrequests::current_group($activity, (int) $wanderer->id)->id);
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
        $this->assertSame((int) $beta->id, (int) joinrequests::current_group($activity, (int) $wanderer->id)->id);
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
        ]);
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
            (int) $beta->id,
            (int) joinrequests::current_group($activity, (int) $wanderer->id)->id
        );
        $sink->close();
    }
}
