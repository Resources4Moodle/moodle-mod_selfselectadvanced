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
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\fit;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\state;

/**
 * Acceptance asks whether composition remains reachable while a team is forming.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\fit
 * @covers     \mod_selfselectadvanced\local\moves
 * @covers     \mod_selfselectadvanced\local\joinrequests
 */
final class acceptance_reachability_test extends \advanced_testcase {
    /**
     * Create the maintainer's rule set: exactly two SCOPE members and
     * at least four distinct departments.
     *
     * @param int $maxsize group maximum size
     * @return array{activity: activity, api: api, course: \stdClass}
     */
    private function activity_with_scope_rules(int $maxsize): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => $maxsize,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
            'maxcount' => 2,
        ]);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'distinct',
            'mincount' => 4,
        ]);

        return [
            'activity' => $activity,
            'api' => new api($activity),
            'course' => $course,
        ];
    }

    /**
     * Create an enrolled student carrying one department value.
     *
     * @param \stdClass $course course
     * @param string $department department value
     * @return \stdClass user
     */
    private function student(\stdClass $course, string $department): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');
        manager::set((int) $user->id, ['department' => $department], (int) get_admin()->id);

        return $user;
    }

    /**
     * Create a team and return its fresh group row.
     *
     * @param activity $activity activity
     * @param \stdClass $leader leader user
     * @param string $name team name
     * @param string $state team lifecycle state
     * @param bool $released whether a firm team is guide-released
     * @return \stdClass group row
     */
    private function team(
        activity $activity,
        \stdClass $leader,
        string $name,
        string $state = state::FORMING,
        bool $released = false
    ): \stdClass {
        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => $name,
            'state' => $state,
            'releasedbyguide' => $released ? 1 : 0,
            'timeapproved' => $state === state::FIRM ? time() - DAYSECS : null,
        ]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * Add a confirmed member to a team.
     *
     * @param \stdClass $group group row
     * @param \stdClass $user user
     */
    private function confirm(\stdClass $group, \stdClass $user): void {
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => (int) $group->id,
            'userid' => (int) $user->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
    }

    /**
     * The move engine's QUOTA verdict for one move.
     *
     * @param activity $activity activity
     * @param api $api facade
     * @param int $userid moved user
     * @param \stdClass|null $source source team, or null for an additional membership
     * @param \stdClass $target target team
     * @return bool the QUOTA verdict's ok flag
     */
    private function engine_quota_ok(
        activity $activity,
        api $api,
        int $userid,
        ?\stdClass $source,
        \stdClass $target
    ): bool {
        $move = $api->moves()->stage(
            $userid,
            $source !== null ? (int) $source->id : null,
            (int) $target->id,
            false,
            null,
            (int) get_admin()->id,
            false,
            $source === null
        );
        $verdicts = $api->moves()->validate_set([(int) $move->id]);

        return (bool) ($verdicts->permove[(int) $move->id]['QUOTA']['ok'] ?? false);
    }

    /**
     * Build the live maintainer case: one SCOPE leader, one SCOPE requester,
     * five seats, and four distinct departments still reachable.
     *
     * @return array{activity: activity, api: api, target: \stdClass, joiner: \stdClass}
     */
    private function live_case(): array {
        $world = $this->activity_with_scope_rules(5);
        $leader = $this->student($world['course'], 'SCOPE');
        $joiner = $this->student($world['course'], 'SCOPE');
        $target = $this->team($world['activity'], $leader, 'Alpha');

        return [
            'activity' => $world['activity'],
            'api' => $world['api'],
            'target' => $target,
            'joiner' => $joiner,
        ];
    }

    /**
     * MUTATION CAUGHT (run): making quota_ok_after() use full compliance
     * for FORMING teams again put a QUOTA warning on the fit row, required
     * confirmation in accept_decision(), and made respond() refuse.
     */
    public function test_forming_team_accepts_second_scope_when_completion_is_reachable(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $case = $this->live_case();

        $request = joinrequests::request(
            $case['activity'],
            (int) $case['target']->id,
            'I fit the SCOPE seats',
            (int) $case['joiner']->id
        );

        $fit = fit::for_person($case['activity'], $case['target'], (int) $case['joiner']->id, $request);
        $this->assertTrue($fit->fits);
        $this->assertSame('', $fit->caution);
        $this->assertSame([], $fit->warnings);

        $decision = joinrequests::accept_decision(
            $case['activity'],
            $request,
            (int) $case['target']->leaderid,
            $case['target']
        );
        $this->assertTrue($decision->canaccept);
        $this->assertFalse($decision->confirmationrequired);
        $this->assertFalse($decision->confirmacceptrequired);
        $this->assertSame([], $decision->warnings);

        joinrequests::respond(
            $case['activity'],
            (int) $request->id,
            true,
            'Welcome',
            (int) $case['target']->leaderid
        );
        $this->assertSame(groups::STATUS_CONFIRMED, $DB->get_field('selfselectadvanced_member', 'status', [
            'groupid' => (int) $case['target']->id,
            'userid' => (int) $case['joiner']->id,
        ]));
        $sink->close();
    }

    /**
     * MUTATION CAUGHT (run): ignoring maxexceeded for a FORMING team
     * made the shared fit/engine quota predicate accept a third SCOPE
     * member against a rule whose maximum is two.
     */
    public function test_forming_team_still_refuses_a_member_who_exceeds_a_maximum(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $world = $this->activity_with_scope_rules(5);
        $leader = $this->student($world['course'], 'SCOPE');
        $second = $this->student($world['course'], 'SCOPE');
        $third = $this->student($world['course'], 'SCOPE');
        $target = $this->team($world['activity'], $leader, 'Alpha');
        $this->confirm($target, $second);

        $this->assertNotNull(fit::accept_composition_refusal(
            $world['activity'],
            $target,
            (int) $third->id
        ));
        $this->assertFalse($this->engine_quota_ok(
            $world['activity'],
            $world['api'],
            (int) $third->id,
            null,
            $target
        ));

        $request = joinrequests::request($world['activity'], (int) $target->id, 'Third SCOPE', (int) $third->id);
        $this->assert_refused('refusaljoinrules', fn() => joinrequests::respond(
            $world['activity'],
            (int) $request->id,
            true,
            '',
            (int) $target->leaderid
        ));
        $sink->close();
    }

    /**
     * MUTATION CAUGHT (run): ignoring the feasibility missing/free bound
     * let a team accept a member even though too few seats remained for
     * any compliant completion.
     */
    public function test_forming_team_refuses_when_compliant_completion_is_unreachable(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $world = $this->activity_with_scope_rules(3);
        $leader = $this->student($world['course'], 'SCOPE');
        $joiner = $this->student($world['course'], 'SCOPE');
        $target = $this->team($world['activity'], $leader, 'Alpha');

        $this->assertNotNull(fit::accept_composition_refusal(
            $world['activity'],
            $target,
            (int) $joiner->id
        ));
        $this->assertFalse($this->engine_quota_ok(
            $world['activity'],
            $world['api'],
            (int) $joiner->id,
            null,
            $target
        ));

        $request = joinrequests::request($world['activity'], (int) $target->id, 'Still too few seats', (int) $joiner->id);
        $this->assert_refused('refusaljoinrules', fn() => joinrequests::respond(
            $world['activity'],
            (int) $request->id,
            true,
            '',
            (int) $target->leaderid
        ));
        $sink->close();
    }

    /**
     * MUTATION CAUGHT (run): treating FIRM like FORMING let a guide-released
     * approved source team lose a member and become only reachable, not
     * fully compliant.
     */
    public function test_firm_team_still_requires_full_compliance_after_acceptance(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $world = $this->activity_with_scope_rules(6);
        $sourceleader = $this->student($world['course'], 'SCOPE');
        $secondscope = $this->student($world['course'], 'SCOPE');
        $one = $this->student($world['course'], 'ONE');
        $two = $this->student($world['course'], 'TWO');
        $mover = $this->student($world['course'], 'THREE');
        $targetleader = $this->student($world['course'], 'SCOPE');
        $source = $this->team($world['activity'], $sourceleader, 'Source', state::FIRM, true);
        $target = $this->team($world['activity'], $targetleader, 'Target');
        foreach ([$secondscope, $one, $two, $mover] as $member) {
            $this->confirm($source, $member);
        }

        // THE ENGINE IS THE ONLY WITNESS LEFT, and it is the right one. This
        // used to open with fit::accept_composition_refusal(..., $source) and
        // assert it was non-null - but that answer came entirely from the
        // source arm decision 77 deleted, and with the argument gone the call
        // judges the TARGET, which is a forming team with a free seat and
        // nothing wrong with it. Keeping it would have asserted a refusal that
        // cannot happen; the honest measurement of "a firm source must stay
        // fully compliant" is the engine's own verdict on the staff move.
        $this->assertFalse($this->engine_quota_ok(
            $world['activity'],
            $world['api'],
            (int) $mover->id,
            $source,
            $target
        ));

        // THE CONTRAST THAT MAKES IT MEAN SOMETHING, and the one the test's
        // name promises: identical shape, FORMING source. A forming team only
        // has to remain REACHABLE when it loses a member, so the very same move
        // is allowed. Without this an engine_quota_ok() that had become "no to
        // everything" would satisfy the assertion above perfectly.
        $loose = $this->activity_with_scope_rules(6);
        $looseleader = $this->student($loose['course'], 'SCOPE');
        $loosemover = $this->student($loose['course'], 'THREE');
        $loosesource = $this->team($loose['activity'], $looseleader, 'Forming source');
        $loosetarget = $this->team(
            $loose['activity'],
            $this->student($loose['course'], 'SCOPE'),
            'Loose target'
        );
        $loosemembers = [
            $this->student($loose['course'], 'SCOPE'),
            $this->student($loose['course'], 'ONE'),
            $this->student($loose['course'], 'TWO'),
            $loosemover,
        ];
        foreach ($loosemembers as $member) {
            $this->confirm($loosesource, $member);
        }
        $this->assertTrue(
            $this->engine_quota_ok(
                $loose['activity'],
                $loose['api'],
                (int) $loosemover->id,
                $loosesource,
                $loosetarget
            ),
            'a FORMING source refused a departure it only has to stay reachable after - the '
                . 'firm/forming distinction this test exists for has collapsed'
        );

        // THE JOIN-REQUEST HALF OF THIS TEST WENT ON 2026-08-10. It filed a
        // request naming Source as the team to leave and asserted the target
        // leader was refused - but decision 77 abolished that request shape, so
        // accepting a join can no longer drain Source at all. The rule under
        // test is unchanged and is what the three assertions above measure
        // directly: a guide-released FIRM team must remain FULLY COMPLIANT, not
        // merely reachable, after somebody is taken out of it. The path that
        // can still take somebody out of it is a STAFF move, and engine_quota_ok
        // above is the engine's own verdict on exactly that move.
        $sink->close();
    }

    /**
     * The retirement above is accounted for, not merely described.
     *
     * Every other decision-77 retirement in this suite carries a guard that
     * goes red if the removed concept returns. This one did not, which made it
     * the only record in the set resting on prose alone - and prose is what
     * stops being true first.
     *
     * MUTATION CAUGHT (run 2026-08-10): re-introducing the deleted arm in
     * fit.php - one live statement setting enginekey to refusaljoinquotasource
     * - fails the first assertion. The comment stripper matters: this file
     * still NAMES the string in the explanatory comment that replaced the
     * branch, so a naive contains() check would have passed against the
     * mutation and been worthless.
     *
     * @return void
     */
    public function test_the_retired_join_half_cannot_come_back_unnoticed(): void {
        $fit = file_get_contents(__DIR__ . '/../classes/local/fit.php');
        $this->assertNotFalse($fit);
        $this->assertStringNotContainsString(
            'refusaljoinquotasource',
            preg_replace('~//[^\n]*~', '', $fit) ?? '',
            'the join door is judging the roster a departure would empty again; the join half of '
                . 'test_firm_team_still_requires_full_compliance_after_acceptance was retired on the '
                . 'basis that it cannot'
        );
        $service = file_get_contents(__DIR__ . '/../classes/local/joinrequests.php');
        $this->assertNotFalse($service);
        $this->assertStringNotContainsString(
            'fit::accept_composition_refusal($activity, $target, $userid, ',
            $service,
            'the accept path is passing a source to the composition door again'
        );
    }

    /**
     * MUTATION CAUGHT (run): changing only fit::accept_composition_refusal()
     * or only moves::quota_after() made the fit projection and the engine's
     * QUOTA verdict disagree on at least one case below.
     */
    public function test_fit_projection_and_engine_quota_verdict_agree(): void {
        $this->resetAfterTest();

        foreach ($this->agreement_cases() as $name => $case) {
            $fitok = fit::accept_composition_refusal(
                $case['activity'],
                $case['target'],
                (int) $case['user']->id
            ) === null;
            // Null source on both sides: every remaining case is a JOIN, and a
            // join empties nothing. engine_quota_ok keeps its source parameter
            // because the staff move path still uses it - the three tests above
            // pass a real team to it.
            $engineok = $this->engine_quota_ok(
                $case['activity'],
                $case['api'],
                (int) $case['user']->id,
                null,
                $case['target']
            );

            $this->assertSame($fitok, $engineok, $name . ': fit and engine disagreed');
            $this->assertSame($case['expected'], $fitok, $name . ': unexpected shared verdict');
        }
    }

    /**
     * Cases for the projection/engine agreement test.
     *
     * @return array<string, array{activity: activity, api: api, target: \stdClass,
     *                             user: \stdClass, expected: bool}>
     */
    private function agreement_cases(): array {
        $cases = [];

        $live = $this->live_case();
        $cases['forming target reachable'] = [
            'activity' => $live['activity'],
            'api' => $live['api'],
            'target' => $live['target'],
            'user' => $live['joiner'],
            'expected' => true,
        ];

        $world = $this->activity_with_scope_rules(5);
        $leader = $this->student($world['course'], 'SCOPE');
        $second = $this->student($world['course'], 'SCOPE');
        $third = $this->student($world['course'], 'SCOPE');
        $target = $this->team($world['activity'], $leader, 'Max target');
        $this->confirm($target, $second);
        $cases['forming target maximum exceeded'] = [
            'activity' => $world['activity'],
            'api' => $world['api'],
            'target' => $target,
            'user' => $third,
            'expected' => false,
        ];

        $world = $this->activity_with_scope_rules(3);
        $leader = $this->student($world['course'], 'SCOPE');
        $joiner = $this->student($world['course'], 'SCOPE');
        $target = $this->team($world['activity'], $leader, 'Unreachable target');
        $cases['forming target unreachable'] = [
            'activity' => $world['activity'],
            'api' => $world['api'],
            'target' => $target,
            'user' => $joiner,
            'expected' => false,
        ];

        // TWO CASES WERE RETIRED FROM THIS SET ON 2026-08-10, and this is the
        // record. 'firm source requires compliance' and 'forming source still
        // reachable after departure' each named a SOURCE team and asked whether
        // the fit projection agreed with the engine about the roster that
        // acceptance would empty.
        //
        // Decision 77 removed the question. accept_composition_refusal() no
        // longer takes a source, because no door passes one: a join adds a
        // membership and empties nothing. A parity case for a shape the product
        // cannot produce measures agreement about nothing.
        //
        // THE ENGINE SIDE OF BOTH IS STILL MEASURED. A staff move does still
        // empty a roster, and moves.php judges it through fit::quota_ok_after()
        // - a lower-level helper the ruling did not touch. The firm-source rule
        // in particular is asserted directly, with no join request involved, in
        // test_firm_team_still_requires_full_compliance_after_acceptance above.

        return $cases;
    }

    /**
     * Expect one Moodle refusal from an action.
     *
     * @param string $stringkey expected error code
     * @param callable $fn action
     */
    private function assert_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }
}
