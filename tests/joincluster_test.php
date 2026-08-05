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

/**
 * Maintainer decision 53, the join cluster: an invitation must not
 * hard-block, and the Fit column must not contradict the Accept button.
 *
 * The two properties under test, in the maintainer's words:
 *
 *  - "Invitation should not hard-block. When a leader decides that
 *     another member will do the job, why not remove the user" - so a
 *     counting maximum that only PENDING invitations put over is a
 *     WARNING the leader can act on (they already hold
 *     withdraw-invitation), and only CONFIRMED members produce a hard
 *     refusal;
 *  - the photograph: the Fit column said "Meets this team's
 *     requirements" and Accept, on that very row, failed with "QUOTA:
 *     Quota rules on both groups after the move" - on a request that
 *     had no second group at all. Fit and Accept now come from one
 *     predicate, so fit-says-yes implies accept-succeeds and
 *     fit-says-no is refused in the same sentence.
 *
 * NEGATIVE AND POSITIVE CONTROLS LIVE IN SEPARATE METHODS. On
 * PostgreSQL advanced_testcase opens a delegated transaction before
 * every test; a refused service call rolls its frame back and poisons
 * every LATER commit in the same method, so a test that refuses once
 * and then commits would fail on one engine only.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\fit
 * @covers     \mod_selfselectadvanced\local\joinrequests
 */
final class joincluster_test extends \advanced_testcase {
    /**
     * A team under a "at most two from Scope" counting rule, with a
     * groupless Scope candidate waiting outside it.
     *
     * @param int $confirmedscopes further CONFIRMED Scope members beyond the leader
     * @param int $pendingscopes Scope members holding a PENDING invitation
     * @return array [activity, team, candidate, invitedmemberids]
     */
    private function setup_scope_world(int $confirmedscopes, int $pendingscopes): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        // The rule the whole ruling turns on: a MAXIMUM, which adding
        // members can never repair - which is precisely why it used to
        // refuse rather than warn.
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'Scope',
            'maxcount' => 2,
        ]);

        $scope = function () use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => 'Scope', 'subdepartment' => 'Optics'], 2);

            return $user;
        };

        $leader = $scope();
        $team = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team Scope',
        ]);
        for ($i = 0; $i < $confirmedscopes; $i++) {
            $plugingen->create_member([
                'groupid' => $team->id,
                'userid' => (int) $scope()->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        $invitedmemberids = [];
        for ($i = 0; $i < $pendingscopes; $i++) {
            $invitedmemberids[] = (int) $plugingen->create_member([
                'groupid' => $team->id,
                'userid' => (int) $scope()->id,
                'status' => groups::STATUS_INVITED,
                'timeinvited' => time(),
            ])->id;
        }

        return [$activity, groups::get($activity, (int) $team->id), $scope(), $invitedmemberids];
    }

    /**
     * Two teams, a wanderer confirmed in the first, and no composition
     * rules at all - the ordinary shape the accept path is judged on.
     *
     * @param array $settings instance overrides
     * @return array [activity, alpha, beta, wanderer]
     */
    private function setup_plain_world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 1,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $mk = function () use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');

            return $user;
        };
        $alpha = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $mk()->id,
            'name' => 'Alpha',
        ]);
        $beta = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $mk()->id,
            'name' => 'Beta',
        ]);
        $wanderer = $mk();
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
        ];
    }

    /**
     * Decision 53. One confirmed Scope member, one PENDING Scope
     * invitation, a maximum of two: a third Scope student is NOT told
     * the door is shut. The projection warns, names what is confirmed
     * and what is merely pending, and leaves the leader the move they
     * already have - withdraw the invitation.
     *
     * Negative control: restore the single-basis refusal (delete the
     * confirmed/pending split in fit::composition_verdict()) and fits
     * comes back false with an empty warnings list.
     */
    public function test_a_pending_invitation_warns_and_does_not_hard_refuse(): void {
        $this->resetAfterTest();
        [$activity, $team, $candidate] = $this->setup_scope_world(0, 1);

        $verdict = fit::for_person($activity, $team, (int) $candidate->id);

        $this->assertTrue(
            $verdict->fits,
            'A pending invitation hard-blocked a join request the leader could clear with one click'
        );
        $this->assertSame('', $verdict->caution);
        $this->assertCount(1, $verdict->warnings, 'The pending breach was swallowed instead of being reported');
        $warning = $verdict->warnings[0];
        $this->assertSame(
            get_string('cautioncompositionmaxpending', 'mod_selfselectadvanced', (object) [
                'value' => 'Scope',
                'max' => 2,
                'confirmed' => 1,
                'pending' => 1,
                'candidate' => 1,
                'wouldbe' => 3,
            ]),
            $warning,
            'The warning must state what is confirmed, what is pending and what the request would make'
        );
    }

    /**
     * The same team once the invitation is withdrawn: clean, with
     * nothing left to warn about. The withdrawal is made through the
     * service the leader actually uses, so this proves the warning
     * tracks the invitation rather than some cached figure.
     */
    public function test_withdrawing_the_invitation_leaves_the_request_clean(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $team, $candidate, $invitedmemberids] = $this->setup_scope_world(0, 1);

        $before = fit::for_person($activity, $team, (int) $candidate->id);
        $this->assertNotSame([], $before->warnings, 'fixture: the invitation must produce the warning first');

        (new api($activity))->invitations()->withdraw($team, $invitedmemberids[0], (int) $team->leaderid);

        $after = fit::for_person($activity, groups::get($activity, (int) $team->id), (int) $candidate->id);
        $this->assertTrue($after->fits);
        $this->assertSame('', $after->caution);
        $this->assertSame([], $after->warnings, 'A withdrawn invitation still counted against the maximum');
        $sink->close();
    }

    /**
     * The other half of the ruling: CONFIRMED members do produce a hard
     * refusal, because nothing the leader can do today takes a
     * confirmed member's value back out of the count. Two confirmed
     * Scope members under a maximum of two refuse a third, and the
     * refusal says what is confirmed and what admitting this one would
     * make - never the projected figure presented as the current one.
     */
    public function test_a_third_confirmed_member_is_still_hard_refused(): void {
        $this->resetAfterTest();
        [$activity, $team, $candidate] = $this->setup_scope_world(1, 0);

        $verdict = fit::for_person($activity, $team, (int) $candidate->id);

        $this->assertFalse($verdict->fits, 'A confirmed breach of a maximum must still be a wall');
        $this->assertSame(
            get_string('refusalcompositionmaxconfirmed', 'mod_selfselectadvanced', (object) [
                'value' => 'Scope',
                'max' => 2,
                'confirmed' => 2,
                'pending' => 0,
                'candidate' => 1,
                'wouldbe' => 3,
            ]),
            $verdict->caution
        );
        $this->assertStringNotContainsString(
            '3 members',
            $verdict->caution,
            'The refusal presented the projected count as the current roster'
        );
    }

    /**
     * POSITIVE CONTROL, alone in its method. Fit says yes, so the
     * acceptance must go through - including the shape that used to
     * make the two disagree in the other direction: a student at their
     * membership cap whose request LEAVES a team, which costs them no
     * net membership and which the move engine has always allowed.
     */
    public function test_fit_says_yes_and_the_acceptance_succeeds(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer] = $this->setup_plain_world();

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        $verdict = fit::for_person($activity, $beta, (int) $wanderer->id, $request);
        $this->assertTrue($verdict->fits, 'The Fit column refused a request the engine accepts');
        $this->assertSame([], $verdict->warnings);

        $decided = joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);

        $this->assertSame('committed', $decided->status);
        $this->assertSame(
            [(int) $beta->id],
            array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $wanderer->id)))
        );
        $sink->close();
    }

    /**
     * NEGATIVE CONTROL, alone in its method (the PostgreSQL trap: a
     * refused service call poisons every later commit of the same
     * test). The photographed contradiction, reproduced and closed: the
     * team cannot meet its composition rules with this student in it,
     * so the Fit column says so IN THE SENTENCE THE REFUSAL USES,
     * instead of calling the row a fit and letting the button answer
     * with the move engine's vocabulary.
     */
    public function test_fit_says_no_in_the_same_words_the_acceptance_refuses_with(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // Capacity two: after the join Beta is FULL. That matters since
        // 1.20.8, which judges a FORMING team on whether a compliant
        // completion is still REACHABLE rather than on whether it is
        // already complete. Two Scope members are required, neither
        // Beta's leader nor the asker is one, and with no free seat
        // left the two missing members can never arrive - so the
        // shortfall is unreachable and BOTH answers refuse. That is
        // what this test is for: not the verdict, but that the Fit
        // column refuses in the SAME SENTENCE the button uses.
        [$activity, , $beta, $wanderer] = $this->setup_plain_world(['maxsize' => 2]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'Scope',
            'mincount' => 2,
        ]);
        manager::set((int) $beta->leaderid, ['department' => 'Elsewhere'], 2);
        manager::set((int) $wanderer->id, ['department' => 'Elsewhere'], 2);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);
        // Since 1.20.8 both surfaces name the shortfall precisely - how
        // many more suitable members are needed and how many seats are
        // left - instead of the older, vaguer "would not meet its
        // composition rules". ONE sentence, computed once here, so this
        // test fails if the two surfaces ever drift apart again.
        $expected = get_string(
            'refusalcompositionunreachable',
            'mod_selfselectadvanced',
            (object) ['missing' => 2, 'free' => 0]
        );

        $verdict = fit::for_person($activity, $beta, (int) $wanderer->id, $request);
        $this->assertFalse($verdict->fits, 'The Fit column called a request a fit that the button would refuse');
        $this->assertSame($expected, $verdict->caution);

        try {
            joinrequests::respond($activity, (int) $request->id, true, '', (int) $beta->leaderid);
            $this->fail('Expected refusaljoinrules');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
            $this->assertStringContainsString(
                $expected,
                $e->getMessage(),
                'The refusal used different words from the ones the leader had just read'
            );
        }
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            joinrequests::get($activity, (int) $request->id)->status
        );
        $sink->close();
    }

    /**
     * A request that keeps every current team has ONE group, and the
     * refusal must not name two. "Quota rules on both groups after the
     * move" is the move engine's sentence for a manager moving somebody
     * between teams; an extra-membership join has no source at all, and
     * the leader was left to guess which rules on which roster had
     * refused their student.
     */
    public function test_an_extra_membership_refusal_names_no_source_it_does_not_have(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // Capacity two, so the Scope shortfall is unreachable once Beta is
        // full; without that, 1.20.8's reachability rule admits the
        // student and there is no refusal left whose wording to check.
        [$activity, , $beta, $wanderer] = $this->setup_plain_world([
            'maxmembership' => 2,
            'maxsize' => 2,
        ]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'Scope',
            'mincount' => 2,
        ]);
        manager::set((int) $beta->leaderid, ['department' => 'Elsewhere'], 2);
        manager::set((int) $wanderer->id, ['department' => 'Elsewhere'], 2);

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'Both, please',
            (int) $wanderer->id,
            joinrequests::SOURCE_ADDITIONAL
        );
        $this->assertNull($request->sourcegroupid, 'fixture: the request must have no source group');

        try {
            joinrequests::respond($activity, (int) $request->id, true, '', (int) $beta->leaderid);
            $this->fail('Expected refusaljoinrules');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
            $this->assertStringContainsString('Beta', $e->getMessage(), 'The refusal named no team at all');
            $this->assertStringNotContainsString(
                get_string('moveruleQUOTA', 'mod_selfselectadvanced'),
                $e->getMessage(),
                'A request with no source group was refused in the name of a second group'
            );
        }
        $sink->close();
    }

    /**
     * ONE PREDICATE, TWO SURFACES. group_page.php's leader panel calls
     * fit::for_person() without the request row; the "Asked of my team"
     * tab hands it over. Both must answer the same thing about the same
     * request, or the maintainer's contradiction simply moves from one
     * page to the other.
     *
     * Negative control: delete the request lookup in for_person() and
     * the panel goes back to answering the ADMISSION question - "Meets
     * this team's requirements" - for a request the button refuses, and
     * the caution assertions fail.
     *
     * Pinned on BOTH sides of 1.20.8's reachability boundary, by this
     * method and by the one below it. One voice while refusing is half
     * the property; agreeing to refuse everything would satisfy it. The
     * surfaces must also agree when the answer is yes.
     */
    public function test_the_leader_panel_and_the_tab_answer_with_one_voice(): void {
        $this->assert_panel_and_tab_agree(2, false);
    }

    /**
     * The same two surfaces, on the permissive side of the boundary:
     * free seats remain, so the Scope shortfall is still reachable and
     * both call shapes must say yes together.
     */
    public function test_the_panel_and_the_tab_agree_when_completion_is_reachable(): void {
        $this->assert_panel_and_tab_agree(4, true);
    }

    /**
     * The invited-maximum branch judges minimums by REACHABILITY too.
     *
     * FOUND BY MUTATION, 2026-08-06. fit computes "missing vs free" in
     * three places. Two are pinned. This one - reached when a maximum
     * is over only once PENDING INVITATIONS are counted - was pinned by
     * nothing: reverting it to strict compliance left all 838 tests of
     * the suite green, on both engines.
     *
     * Beta's leader and the candidate are both Scope, so the Scope
     * maximum of two is intact on the roster that matters; two Scope
     * INVITATIONS push the projection past it, which is a warning to
     * the leader rather than a refusal of this student. A second rule
     * wants two Elsewhere members and exactly two seats remain for
     * them, so a compliant finish is still reachable and the answer
     * stays yes - with the warning attached.
     *
     * MUTATION CAUGHT (run): changing $hard->missing > $free to
     * > 0 turns this yes into a refusal and this test red.
     */
    public function test_the_invited_maximum_branch_judges_minimums_by_reachability(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // Leader Scope confirmed, two Scope invitations pending, a
        // groupless Scope candidate: the projection is over the maximum
        // of two, the confirmed roster plus the candidate sits on it.
        [$activity, $team, $candidate] = $this->setup_scope_world(0, 2);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        // A second rule with an UNMET minimum, and room left for it.
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'Elsewhere',
            'mincount' => 2,
        ]);

        $verdict = fit::for_person($activity, $team, (int) $candidate->id);

        $this->assertTrue(
            $verdict->fits,
            'Two Elsewhere members are still needed and two seats remain - reachable, not a refusal. caution=['
                . $verdict->caution . ']'
        );
        $this->assertSame('', $verdict->caution);
        $this->assertNotSame(
            [],
            $verdict->warnings,
            'the leader must still be warned that the outstanding invitations breach the Scope maximum'
        );
        $sink->close();
    }

    /**
     * Both call shapes of fit::for_person() answer identically.
     *
     * @param int $maxsize team capacity - 2 leaves no free seat after the
     *                     join (shortfall unreachable), 4 leaves two.
     * @param bool $expected the verdict both surfaces must reach.
     */
    private function assert_panel_and_tab_agree(int $maxsize, bool $expected): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer] = $this->setup_plain_world(['maxsize' => $maxsize]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'Scope',
            'mincount' => 2,
        ]);
        manager::set((int) $beta->leaderid, ['department' => 'Elsewhere'], 2);
        manager::set((int) $wanderer->id, ['department' => 'Elsewhere'], 2);

        $request = joinrequests::request($activity, (int) $beta->id, 'Nearer my lab', (int) $wanderer->id);

        $handedover = fit::for_person($activity, $beta, (int) $wanderer->id, $request);
        $lookedup = fit::for_person($activity, $beta, (int) $wanderer->id);

        $this->assertSame(
            $expected,
            $lookedup->fits,
            'The panel and the tab must reach the same verdict the acceptance does'
        );
        $this->assertSame($handedover->fits, $lookedup->fits);
        $this->assertSame($handedover->caution, $lookedup->caution);
        $this->assertSame($handedover->warnings, $lookedup->warnings);
        $this->assertSame($handedover->seat, $lookedup->seat);
        $sink->close();
    }

    /**
     * What the answering side is offered about the asker, and what it
     * reports about its own seats.
     *
     * The two COMPOSITION attributes come through the shared accessor
     * (maintainer decision 53), and the seat line keeps CONFIRMED and
     * PENDING apart instead of adding them into one number that reads
     * as the current roster. The rendering of both is proved by
     * tests/behat/joinrequest.feature.
     */
    public function test_the_answer_side_reads_composition_and_counts_seats_honestly(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $beta, $wanderer] = $this->setup_plain_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        manager::set((int) $wanderer->id, ['department' => 'Science', 'subdepartment' => 'Physics'], 2);

        // One seat filled by the leader, one reserved by an invitation.
        $guest = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guest->id, $activity->courseid(), 'student');
        $plugingen->create_member([
            'groupid' => $beta->id,
            'userid' => (int) $guest->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time(),
        ]);

        joinrequests::request($activity, (int) $beta->id, 'Closer to my programme', (int) $wanderer->id);
        $waiting = joinrequests::waiting_for_group($activity, (int) $beta->id);
        $this->assertCount(1, $waiting, 'fixture: the leader must have exactly one request waiting');

        $requesterids = array_map(static fn(\stdClass $row): int => (int) $row->userid, array_values($waiting));
        $attrs = manager::get_for_users($requesterids);
        $this->assertArrayHasKey((int) $wanderer->id, $attrs, 'The asker had no attributes to offer the decider');
        $this->assertSame('Science', $attrs[(int) $wanderer->id]->department);
        $this->assertSame('Physics', $attrs[(int) $wanderer->id]->subdepartment);

        $seats = (new api($activity))->gatekeeper()->seat_position($beta);
        $this->assertSame(1, $seats->confirmed, 'The confirmed count swallowed the pending invitation');
        $this->assertSame(1, $seats->invited);
        $this->assertSame(4, $seats->max);
        $line = get_string('seatsummary', 'mod_selfselectadvanced', $seats);
        $this->assertStringContainsString('1 of 4', $line);
        $this->assertStringContainsString('1 invitation', $line);
        $sink->close();
    }
}
