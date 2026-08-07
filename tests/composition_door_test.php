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
 * Decision 60: a decision may leave a team unfinished - it must never
 * leave it in violation.
 *
 * From the maintainer's live breach of 2026-08-06, group 42: a leader
 * accepted a walk-up join request FOUR SECONDS after an invitation
 * acceptance had filled the last SCOPE seat, and "Accept anyway?"
 * bypassed the engine's correct refusal. Three confirmed SCOPE members
 * sat in a team whose rule said between 2 and 2. The engine refused;
 * the door called the refusal overridable; the door was wrong.
 *
 * The ruling this file pins, in the maintainer's own frame:
 *
 *  - a maximum measured over CONFIRMED members plus the person entering
 *    is a hard refusal at EVERY door - the walk-up accept and the
 *    invitation accept alike - which only the deliberate staff override
 *    (decision 6: capability, written reason, logged event) may pass;
 *  - a maximum exceeded only when PENDING INVITATIONS are counted
 *    blocks nothing and bypasses nothing: "the composition rule is not
 *    exactly broken. It is just that one or more of the invited people
 *    will not be able to accept and join" - so the decider proceeds
 *    informed, and no override row is written for an engine verdict
 *    that never refused;
 *  - the mirror: one confirmed SCOPE member, two pending SCOPE
 *    invitations, a cap of two - the FIRST acceptance is legitimate and
 *    must not be blocked by the other invitee's unanswered row.
 *
 * NEGATIVE AND POSITIVE CONTROLS LIVE IN SEPARATE METHODS where a
 * refusal precedes a commit (the PostgreSQL delegated-transaction
 * poisoning trap); a method may commit and THEN refuse.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\fit
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 */
final class composition_door_test extends \advanced_testcase {
    /**
     * The live activity's shape: five seats, SCOPE between 2 and 2.
     *
     * @param int $confirmedscopes further confirmed SCOPE members beyond the leader
     * @param int $pendingscopes SCOPE members holding a pending invitation
     * @return array [activity, api, team, scope-student-maker]
     */
    private function scope_world(int $confirmedscopes = 0, int $pendingscopes = 0): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 5,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 1,
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

        $scope = function (string $dept = 'SCOPE') use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => 'BAI'], 2);

            return $user;
        };

        $leader = $scope();
        $team = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Alpha',
        ]);
        for ($i = 0; $i < $confirmedscopes; $i++) {
            $plugingen->create_member([
                'groupid' => $team->id,
                'userid' => (int) $scope()->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }
        for ($i = 0; $i < $pendingscopes; $i++) {
            $plugingen->create_member([
                'groupid' => $team->id,
                'userid' => (int) $scope()->id,
                'status' => groups::STATUS_INVITED,
                'timeinvited' => time(),
            ]);
        }

        return [$activity, new api($activity), groups::get($activity, (int) $team->id), $scope];
    }

    /**
     * The live timeline of 2026-08-06, replayed to the second gate.
     *
     * Diya leads, Ishaan is invited, Ananya asks to join - all SCOPE,
     * cap 2. Ishaan accepts: two confirmed, the cap is FULL. The leader
     * then accepts Ananya exactly as the browser did that night, with
     * the confirmation flag the "Accept anyway?" dialog sets - and
     * meets a hard refusal in the present tense, because a confirmation
     * is not an override and a maximum on confirmed members has no
     * override at this door.
     *
     * The one-voice property is asserted in the same breath: the Fit
     * column's caution for this request IS the refusal's sentence.
     */
    public function test_the_live_breach_replayed_is_now_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $scope] = $this->scope_world();
        $ishaan = $scope();
        $ananya = $scope();

        $api->invitations()->send($team, (int) $ishaan->id, (int) $team->leaderid);
        $request = joinrequests::request($activity, (int) $team->id, 'Bad decision to leave', (int) $ananya->id);
        $api->invitations()->accept($team, (int) $ishaan->id);
        $this->assertSame(2, $DB->count_records('selfselectadvanced_member', [
            'groupid' => $team->id, 'status' => groups::STATUS_CONFIRMED,
        ]), 'fixture: Ishaan\'s acceptance fills the SCOPE cap');

        $verdict = fit::for_person($activity, $team, (int) $ananya->id, $request);
        $this->assertFalse($verdict->fits, 'the Fit column must refuse what the button refuses');

        try {
            joinrequests::respond($activity, (int) $request->id, true, '', (int) $team->leaderid, [], true);
            $this->fail('The 05:13:42 acceptance must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
            $this->assertStringContainsString(
                get_string('refusalcompositionmaxconfirmed', 'mod_selfselectadvanced', (object) [
                    'value' => 'SCOPE', 'max' => 2, 'confirmed' => 2, 'pending' => 0,
                    'candidate' => 1, 'wouldbe' => 3,
                ]),
                $e->getMessage(),
                'the refusal must report the CONFIRMED count, in the present tense'
            );
            $this->assertStringContainsString($verdict->caution, $e->getMessage(), 'one voice');
        }
        $this->assertSame(2, $DB->count_records('selfselectadvanced_member', [
            'groupid' => $team->id, 'status' => groups::STATUS_CONFIRMED,
        ]), 'the roster must not have grown past its rule');
        $this->assertSame(
            joinrequests::STATUS_REQUESTED,
            joinrequests::get($activity, (int) $request->id)->status,
            'the request stays open for a decline with a note'
        );
        $sink->close();
    }

    /**
     * The decision object for the pending-only case: acceptable, with
     * consent notes and NOTHING to bypass.
     *
     * One confirmed SCOPE (the leader), one pending SCOPE invitation,
     * a SCOPE walk-up: confirmed plus the walk-up is exactly 2 - no
     * violation. Only the projection with the invitation counted is
     * over. Decision 60 says that blocks nothing: canaccept, consent
     * notes present, autobypassrules EMPTY - the pre-fix door put
     * QUOTA here and wrote an override row for an engine verdict that
     * never refused. Unconfirmed, the accept still stops, so a stale
     * form cannot skip the reading.
     */
    public function test_pending_only_maximum_is_consent_not_bypass(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $scope] = $this->scope_world(0, 1);
        $ananya = $scope();

        $request = joinrequests::request($activity, (int) $team->id, 'Room for me?', (int) $ananya->id);
        $decision = joinrequests::accept_decision($activity, $request, (int) $team->leaderid, $team);

        $this->assertTrue($decision->canaccept, 'no rule is broken - the maintainer\'s ruling verbatim');
        $this->assertNotSame([], $decision->consentnotes, 'the leader must be told invitations are affected');
        $this->assertSame([], $decision->bypassrules, 'nothing needs bypassing - the engine will commit');
        $this->assertObjectNotHasProperty(
            'autobypassrules',
            $decision,
            'decision 64: the auto-bypass surface no longer exists - a confirm click cannot carry a rule code'
        );
        $this->assertTrue($decision->confirmacceptrequired, 'but the leader must confirm they read it');
        $this->assertStringContainsString(
            get_string('consentinvitationsblocked', 'mod_selfselectadvanced', 1),
            implode(' ', $decision->consentnotes),
            'the consequence is named: one pending invitation can no longer be accepted'
        );

        try {
            joinrequests::respond($activity, (int) $request->id, true, '', (int) $team->leaderid);
            $this->fail('An unconfirmed accept must stop at the consent gate');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * The pending-only acceptance commits with consent, writes NO
     * override row, and leaves the invitation alive but visibly dead.
     *
     * MUTATION CAUGHT (run): reverting the consent tier to
     * warn('QUOTA') makes the override-row count 1 and this test red -
     * the record that claimed a bypass that bypassed nothing.
     */
    public function test_pending_only_acceptance_commits_without_an_override_row(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $scope] = $this->scope_world(0, 1);
        $ananya = $scope();
        $invitee = $DB->get_field('selfselectadvanced_member', 'userid', [
            'groupid' => $team->id, 'status' => groups::STATUS_INVITED,
        ], MUST_EXIST);

        $request = joinrequests::request($activity, (int) $team->id, 'Room for me?', (int) $ananya->id);
        joinrequests::respond($activity, (int) $request->id, true, '', (int) $team->leaderid, [], true);

        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field('selfselectadvanced_member', 'status', [
                'groupid' => $team->id, 'userid' => (int) $ananya->id,
            ]),
            'the walk-up is admitted - no rule is broken'
        );
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_override'),
            'no override row may exist for an engine verdict that never refused'
        );
        $this->assertSame(
            groups::STATUS_INVITED,
            $DB->get_field('selfselectadvanced_member', 'status', [
                'groupid' => $team->id, 'userid' => (int) $invitee,
            ]),
            'the invitation is NOT auto-declined - a departure can revive it'
        );
        $this->assertNotNull(
            fit::door_verdict($activity, groups::get($activity, (int) $team->id), (int) $invitee)->hardmax,
            'but the annotation predicate must now call it unacceptable'
        );
        $sink->close();
    }

    /**
     * The mirror defect: one confirmed SCOPE, two pending SCOPE, cap 2.
     *
     * The FIRST invitee to accept is entitled to the seat. Before
     * decision 60 the acceptance gate counted every pending invitee -
     * one confirmed plus two invited made three - and refused BOTH
     * invitations, each blocked by the other's unanswered row.
     *
     * MUTATION CAUGHT (run): restoring the confirmed-plus-all-invited
     * basis in can_accept() turns this acceptance back into a refusal.
     */
    public function test_the_first_of_two_pending_invitees_can_accept(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team] = $this->scope_world(0, 2);
        $first = (int) $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$team->id, groups::STATUS_INVITED]
        )[0];

        $api->invitations()->accept($team, $first);

        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field('selfselectadvanced_member', 'status', [
                'groupid' => $team->id, 'userid' => $first,
            ]),
            'another invitee\'s unanswered row must not block a legitimate acceptance'
        );
        $sink->close();
    }

    /**
     * The SECOND invitee meets the same hard answer every door gives,
     * in the present tense - and the landing page told them first.
     */
    public function test_the_second_pending_invitee_is_refused_in_the_present_tense(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team] = $this->scope_world(0, 2);
        [$first, $second] = array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$team->id, groups::STATUS_INVITED]
        ));
        $api->invitations()->accept($team, $first);

        $fresh = groups::get($activity, (int) $team->id);
        $this->assertNotNull(
            fit::door_verdict($activity, $fresh, $second)->hardmax,
            'the annotation predicate warns before the click'
        );
        try {
            $api->invitations()->accept($fresh, $second);
            $this->fail('The cap is full of confirmed members now');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalcompositionmaxconfirmed', $e->errorcode);
        }
        $this->assertSame(
            groups::STATUS_INVITED,
            $DB->get_field('selfselectadvanced_member', 'status', [
                'groupid' => $team->id, 'userid' => $second,
            ]),
            'the refused invitation survives - withdrawal is the leader\'s move, not the gate\'s'
        );
        $sink->close();
    }

    /**
     * Composition is per-team: leading one team does not poison an
     * acceptance into another (multiple-membership permutation).
     */
    public function test_a_leader_elsewhere_accepts_under_their_membership_cap(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'maxcount' => 2,
        ]);
        $mk = function (string $dept) use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => 'BAI'], 2);

            return $user;
        };
        $p = $mk('SCOPE');
        $plugingen->create_group([
            'activityid' => $activity->id(), 'leaderid' => (int) $p->id, 'name' => 'Mine',
        ]);
        $other = $plugingen->create_group([
            'activityid' => $activity->id(), 'leaderid' => (int) $mk('Elsewhere')->id, 'name' => 'Theirs',
        ]);
        $api = new api($activity);
        $othergroup = groups::get($activity, (int) $other->id);
        $api->invitations()->send($othergroup, (int) $p->id, (int) $othergroup->leaderid);

        $api->invitations()->accept($othergroup, (int) $p->id);

        $this->assertSame(2, $DB->count_records_select(
            'selfselectadvanced_member',
            'userid = ? AND status = ?',
            [(int) $p->id, groups::STATUS_CONFIRMED]
        ), 'leader of one team, member of another: two memberships under a cap of two');
        $sink->close();
    }

    /**
     * When a value-maximum and a distinct-minimum both apply, the
     * refusal is the maximum's - the actionable, present-tense one -
     * not the reachability sentence for a rule the admission does not
     * worsen.
     */
    public function test_the_hard_maximum_outranks_an_unmet_distinct_minimum(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $team, $scope] = $this->scope_world(1);
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'distinct',
            'mincount' => 4,
        ]);
        $third = $scope();

        $door = fit::door_verdict($activity, $team, (int) $third->id);

        $this->assertNotNull($door->hardmax, 'two confirmed SCOPE + a third is a present violation');
        $this->assertSame('refusalcompositionmaxconfirmed', $door->hardmaxkey);
        $this->assertStringContainsString('SCOPE', $door->hardmax);
        $sink->close();
    }

    /**
     * Maintainer point (b), 2026-08-06: staff may declare the rules
     * breakable for one group. A quota-exempt group's doors are simply
     * open - no hard stop, no consent notes, nothing to confirm - and
     * the third SCOPE member walks in.
     */
    public function test_a_quota_exempt_group_admits_past_every_composition_answer(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, , $team, $scope] = $this->scope_world(1);
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $team->id,
            ['quotaexempt' => 1],
            (int) get_admin()->id
        );
        $third = $scope();

        $request = joinrequests::request($activity, (int) $team->id, 'Rules waived for us', (int) $third->id);
        $decision = joinrequests::accept_decision($activity, $request, (int) $team->leaderid, $team);
        $this->assertTrue($decision->canaccept);
        $this->assertSame('', $decision->hardreason);
        $this->assertSame([], $decision->consentnotes);

        joinrequests::respond($activity, (int) $request->id, true, '', (int) $team->leaderid);
        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field('selfselectadvanced_member', 'status', [
                'groupid' => $team->id, 'userid' => (int) $third->id,
            ]),
            'an exempt group admits without confirmation - the staff already decided'
        );
        $sink->close();
    }

    /**
     * Maintainer point (a), 2026-08-06: overrides can change a group's
     * numbers, and the doors must judge on the EFFECTIVE numbers. A
     * maxsize override turns an unreachable shortfall into a reachable
     * one, so the same request flips from refused to consented.
     */
    public function test_the_door_judges_on_the_effective_maxsize(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        // Capacity two at the activity: leader + this candidate fill
        // the team while the SCOPE minimum of two still wants somebody
        // the roster has no seat left for - unreachable, engine
        // refusal.
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 2,
            'maxlead' => 1,
            'maxmembership' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'SCOPE',
            'mincount' => 2,
        ]);
        $mk = function (string $dept) use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            manager::set((int) $user->id, ['department' => $dept, 'subdepartment' => 'BAI'], 2);

            return $user;
        };
        $team = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $mk('Elsewhere')->id,
            'name' => 'Alpha',
        ]);
        $team = groups::get($activity, (int) $team->id);
        $candidate = $mk('Elsewhere');

        $this->assertNotNull(
            fit::door_verdict($activity, $team, (int) $candidate->id)->engine,
            'fixture: at the activity maxsize the SCOPE minimum is unreachable'
        );

        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'group',
            (int) $team->id,
            ['maxsize' => 4],
            (int) get_admin()->id
        );

        $door = fit::door_verdict($activity, $team, (int) $candidate->id);
        $this->assertNull($door->hardmax);
        $this->assertNull(
            $door->engine,
            'the override raised the effective maxsize, and the door must judge on THAT number'
        );
        $sink->close();
    }

    /**
     * Decision 6 survives decision 60: staff holding :overriderules,
     * with a written reason, pass the hard maximum through the move
     * engine's own logged override - the deliberate door, not the
     * casual one.
     */
    public function test_staff_override_still_passes_with_a_written_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $team, $scope] = $this->scope_world(1);
        $ananya = $scope();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user(
            $teacher->id,
            (int) $activity->cm()->course,
            'editingteacher'
        );

        $request = joinrequests::request($activity, (int) $team->id, 'Third SCOPE, deliberately', (int) $ananya->id);
        joinrequests::respond(
            $activity,
            (int) $request->id,
            true,
            'Programme head approved the exception',
            (int) $teacher->id,
            ['QUOTA']
        );

        $this->assertSame(
            groups::STATUS_CONFIRMED,
            $DB->get_field('selfselectadvanced_member', 'status', [
                'groupid' => $team->id, 'userid' => (int) $ananya->id,
            ]),
            'the trusted, logged staff override is decision 6, deliberately preserved'
        );
        $this->assertGreaterThan(
            0,
            $DB->count_records('selfselectadvanced_override'),
            'and unlike the consent path it leaves its override row as the record'
        );
        $sink->close();
    }
}
