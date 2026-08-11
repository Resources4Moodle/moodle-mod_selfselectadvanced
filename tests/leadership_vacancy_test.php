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
use mod_selfselectadvanced\local\authority;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;

/**
 * A group whose leader disappeared says so, and staff can repair it.
 *
 * THE DEFECT THIS FILE PINS. The schema declared leaderid NOT NULL and a
 * foreign key to a real user, and then two different code paths made that
 * false. The privacy provider wrote the sentinel 0, which names user zero.
 * The deletion and last-unenrolment observers marked the leader's membership
 * removed and deliberately left group.leaderid pointing at them, so the group
 * went on naming somebody who is no longer one of its confirmed members.
 *
 * Both are lies about who leads the group, and the fix is not to invent a
 * replacement. Leadership carries authority, succession rights and grade
 * attribution; promoting an arbitrary student silently would replace a visible
 * lie with an invisible one. So a vacancy is TRUE and explicit - leaderid
 * NULL, no confirmed member flagged as leader - it blocks the transitions that
 * assume a leader exists, and staff fill it deliberately.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\succession
 * @covers     \mod_selfselectadvanced\observer
 */
final class leadership_vacancy_test extends \advanced_testcase {
    /**
     * A forming group with a leader and two other confirmed members.
     *
     * @param array $settings activity overrides
     * @return array [activity, api, course, group, leader, members[]]
     */
    private function world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 2,
            'maxmembership' => 3,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $mk = function () use ($generator, $course): \stdClass {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');

            return $user;
        };
        $leader = $mk();
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Vacancy world',
        ]);
        $members = [$mk(), $mk()];
        foreach ($members as $member) {
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $member->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        return [$activity, new api($activity), $course, groups::get($activity, (int) $group->id), $leader, $members];
    }

    /**
     * Assert the group is a truthful vacancy: NULL leader, no leader flag.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     */
    private function assert_vacant(activity $activity, int $groupid): void {
        global $DB;

        $row = groups::get($activity, $groupid);
        $this->assertNull($row->leaderid, 'the group still names a leader it does not have');
        $this->assertNotSame(0, $row->leaderid, 'leaderid 0 is never valid: it names user zero');
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_member', ['groupid' => $groupid, 'isleader' => 1]),
            'a vacant group still carries a member row claiming to be its leader'
        );
    }

    /**
     * Deleting the leader's account leaves a vacancy, not a stale pointer.
     *
     * MUTATION CAUGHT (run 2026-08-11), and stated precisely because the first
     * attempt was wrong: removing the record_leadership_vacancy() call from
     * user_deleted() ALONE changes nothing here, because delete_user() also
     * unenrols the account and user_enrolment_deleted() then vacates the
     * leadership by the other path. Removing BOTH call sites fails this test
     * and the unenrolment test together, with "the group still names a leader
     * it does not have".
     *
     * The overlap is worth knowing rather than hiding: account deletion is
     * covered twice, so this test asserts the OUTCOME and not which observer
     * produced it. The unenrolment test below is the one that exercises the
     * enrolment path on its own.
     */
    public function test_deleting_the_leader_account_vacates_the_leadership(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $group, $leader, $members] = $this->world();

        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));

        $this->assert_vacant($activity, (int) $group->id);

        // The group and its other members survive: this is a repair state,
        // not a demolition.
        $this->assertSame(
            2,
            $DB->count_records('selfselectadvanced_member', [
                'groupid' => (int) $group->id,
                'status' => groups::STATUS_CONFIRMED,
            ]),
            'the remaining members were removed along with the leader'
        );
        // And nobody was promoted in the deleted leader's place.
        $this->assertNotSame(
            (int) $members[0]->id,
            (int) (groups::get($activity, (int) $group->id)->leaderid ?? 0),
            'a member was silently promoted'
        );
    }

    /**
     * The last unenrolment does the same thing.
     */
    public function test_unenrolling_the_leader_vacates_the_leadership(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $course, $group, $leader] = $this->world();

        $instances = enrol_get_instances((int) $course->id, true);
        $manual = null;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $manual = $instance;
                break;
            }
        }
        $this->assertNotNull($manual, 'fixture: the course needs a manual enrolment instance');
        $plugin = enrol_get_plugin('manual');
        $plugin->unenrol_user($manual, (int) $leader->id);

        $this->assert_vacant($activity, (int) $group->id);
    }

    /**
     * Privacy erasure writes NULL, never the sentinel 0.
     *
     * MUTATION CAUGHT (run 2026-08-11): restoring set_field(..., 'leaderid', 0)
     * in provider::delete_selfselectadvanced_data_for_user() fails the NULL
     * assertion, because 0 is not null and names a user that does not exist.
     */
    public function test_privacy_erasure_leaves_null_not_zero(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $group, $leader] = $this->world();

        $contextlist = new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user((int) $leader->id),
            'mod_selfselectadvanced',
            [$activity->context()->id]
        );
        \mod_selfselectadvanced\privacy\provider::delete_data_for_user($contextlist);

        $this->assert_vacant($activity, (int) $group->id);
    }

    /**
     * A vacancy blocks approval, auto-approval and freeze.
     *
     * Each of these stamps approval state, freeze state or grade attribution
     * against a leader; none of them may proceed while there is nobody to
     * attribute it to.
     *
     * MUTATION CAUGHT (run 2026-08-11): deleting the leaderid === null arm
     * from gatekeeper::autoapprove_plan() lets the plan reach its guide
     * checks, and deleting it from can_freeze() lets a vacant group freeze.
     */
    public function test_a_vacancy_blocks_the_forward_transitions(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader] = $this->world();
        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, (int) $activity->cm()->course, 'teacher');

        // Reach a state where approval and freeze are the next questions.
        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => (int) $group->id,
            'state' => state::PENDING_GUIDE,
            'guideid' => (int) $guide->id,
        ]);
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));
        $vacant = groups::get($activity, (int) $group->id);
        $this->assertNull($vacant->leaderid, 'fixture: the group must be vacant');

        $gatekeeper = $api->gatekeeper();
        $plan = $gatekeeper->autoapprove_plan($vacant);
        $this->assertNotNull($plan->refusal, 'auto-approval proceeded on a group with no leader');
        $this->assertSame('refusalleadervacant', $plan->refusal->stringkey);

        $this->assertNotNull(
            $gatekeeper->can_approve($vacant, (int) $guide->id),
            'manual approval proceeded on a group with no leader'
        );

        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => (int) $group->id]);
        $firm = groups::get($activity, (int) $group->id);
        $refusal = $gatekeeper->can_freeze($firm);
        $this->assertNotNull($refusal, 'a group with no leader was allowed to freeze');
        $this->assertSame('refusalleadervacant', $refusal->stringkey);
    }

    /**
     * A manager appoints an eligible confirmed member, and the group is whole.
     */
    public function test_a_manager_can_appoint_a_confirmed_member(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, (int) $course->id, 'editingteacher');

        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));
        $vacant = groups::get($activity, (int) $group->id);

        $api->succession()->appoint_vacant_leader($vacant, (int) $members[0]->id, (int) $manager->id);

        $repaired = groups::get($activity, (int) $group->id);
        $this->assertSame((int) $members[0]->id, (int) $repaired->leaderid);
        $flags = $DB->get_records('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'isleader' => 1,
        ]);
        $this->assertCount(1, $flags, 'a repair must leave exactly one leader flag');
        $this->assertSame((int) $members[0]->id, (int) reset($flags)->userid);
    }

    /**
     * Every way a candidate can be wrong is refused, and named.
     *
     * @dataProvider bad_candidate_provider
     * @param string $flaw which precondition to break
     * @param string $expected the refusal key
     */
    public function test_an_ineligible_candidate_is_refused(string $flaw, string $expected): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, (int) $course->id, 'editingteacher');
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));

        $candidate = (int) $members[0]->id;
        if ($flaw === 'notmember') {
            $outsider = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($outsider->id, (int) $course->id, 'student');
            $candidate = (int) $outsider->id;
        } else if ($flaw === 'notenrolled') {
            $instances = enrol_get_instances((int) $course->id, true);
            foreach ($instances as $instance) {
                if ($instance->enrol === 'manual') {
                    enrol_get_plugin('manual')->unenrol_user($instance, $candidate);
                    break;
                }
            }
        } else if ($flaw === 'cannotlead') {
            $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
            role_change_permission($roleid, $activity->context(), authority::LEAD, CAP_PROHIBIT);
            accesslib_clear_all_caches_for_unit_testing();
        } else if ($flaw === 'atcap') {
            // Maxlead is 2 in this world; give the candidate two of their own.
            $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
            for ($i = 0; $i < 2; $i++) {
                $plugingen->create_group([
                    'activityid' => $activity->id(),
                    'leaderid' => $candidate,
                    'name' => 'Theirs ' . $i,
                ]);
            }
        }

        try {
            $api->succession()->appoint_vacant_leader(
                groups::get($activity, (int) $group->id),
                $candidate,
                (int) $manager->id
            );
            $this->fail('an ineligible candidate (' . $flaw . ') was appointed');
        } catch (\moodle_exception $e) {
            $this->assertSame($expected, $e->errorcode);
        }

        // The group is left as it was found: still a truthful vacancy.
        $this->assert_vacant($activity, (int) $group->id);
    }

    /**
     * The ways a candidate can be ineligible.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bad_candidate_provider(): array {
        return [
            'not a member of this group' => ['notmember', 'refusalleadervacantnotmember'],
            'no longer enrolled' => ['notenrolled', 'errmovenotparticipant'],
            'not allowed to lead' => ['cannotlead', 'refusalnomineecannotlead'],
            'already at the lead cap' => ['atcap', 'refusalleadervacantatcap'],
        ];
    }

    /**
     * The repair is not a back door: it cannot replace a live leader.
     *
     * Without this the method would be a general "make me the leader" verb for
     * any manager, bypassing succession entirely - and succession is the
     * consensual route that exists precisely because leadership is not staff's
     * to reassign at will.
     */
    public function test_the_repair_cannot_replace_a_valid_leader(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, (int) $course->id, 'editingteacher');

        try {
            $api->succession()->appoint_vacant_leader($group, (int) $members[0]->id, (int) $manager->id);
            $this->fail('the repair overwrote a group that already had a leader');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleadervacantfilled', $e->errorcode);
        }
        $this->assertSame(
            (int) $leader->id,
            (int) groups::get($activity, (int) $group->id)->leaderid,
            'the sitting leader was displaced'
        );
    }

    /**
     * Somebody without staff authority cannot repair at all.
     */
    public function test_a_student_cannot_appoint_a_leader(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, , $group, $leader, $members] = $this->world();
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));

        try {
            $api->succession()->appoint_vacant_leader(
                groups::get($activity, (int) $group->id),
                (int) $members[0]->id,
                (int) $members[1]->id
            );
            $this->fail('a student appointed a group leader');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleadervacantnoauthority', $e->errorcode);
        }
        $this->assert_vacant($activity, (int) $group->id);
    }

    /**
     * TWO STAFF REPAIR AT ONCE: one wins, the other is told the truth.
     *
     * A REAL HANDOFF. locks::set_test_hook() fires in the window between the
     * losing request's pre-lock reads and its acquire, and the winning repair
     * is committed there - which is exactly where a second manager clicking
     * the same button lands. Two sequential calls would prove nothing.
     *
     * MUTATION CAUGHT (run 2026-08-11): removing the leaderid !== null arm
     * from appoint_vacant_leader() lets the loser overwrite the winner and
     * fails both the winner assertion and the single-flag assertion.
     */
    public function test_two_competing_repairs_leave_exactly_one_leader(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world();
        $managera = $this->getDataGenerator()->create_user();
        $managerb = $this->getDataGenerator()->create_user();
        foreach ([$managera, $managerb] as $manager) {
            $this->getDataGenerator()->enrol_user($manager->id, (int) $course->id, 'editingteacher');
        }
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));

        $winner = (int) $members[0]->id;
        $loser = (int) $members[1]->id;
        $fired = false;
        locks::set_test_hook(function (string $resource) use (
            &$fired,
            $api,
            $activity,
            $group,
            $winner,
            $managera
        ): void {
            if ($fired || !str_starts_with($resource, 'activity:')) {
                return;
            }
            $fired = true;
            // Manager A's repair lands and commits while manager B waits.
            $api->succession()->appoint_vacant_leader(
                groups::get($activity, (int) $group->id),
                $winner,
                (int) $managera->id
            );
        });

        try {
            $api->succession()->appoint_vacant_leader(
                groups::get($activity, (int) $group->id),
                $loser,
                (int) $managerb->id
            );
            $this->fail('the second repair overwrote the first');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalleadervacantfilled', $e->errorcode);
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertTrue($fired, 'the lock hook never fired, so nothing was actually raced');
        $this->assertSame(
            $winner,
            (int) groups::get($activity, (int) $group->id)->leaderid,
            'the loser displaced the winner'
        );
        $this->assertCount(
            1,
            $DB->get_records('selfselectadvanced_member', ['groupid' => (int) $group->id, 'isleader' => 1]),
            'a contested repair left more than one leader flag'
        );
    }

    /**
     * A group with no eligible member stays a truthful vacancy.
     *
     * The system cannot guarantee a lawful replacement exists, and inventing
     * one is the thing this whole design refuses to do.
     */
    public function test_with_no_eligible_member_the_group_stays_vacant(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, (int) $course->id, 'editingteacher');
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));

        // Nobody left may lead.
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_change_permission($roleid, $activity->context(), authority::LEAD, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();

        foreach ($members as $member) {
            try {
                $api->succession()->appoint_vacant_leader(
                    groups::get($activity, (int) $group->id),
                    (int) $member->id,
                    (int) $manager->id
                );
                $this->fail('a member who may not lead was appointed');
            } catch (\moodle_exception $e) {
                $this->assertSame('refusalnomineecannotlead', $e->errorcode);
            }
        }
        $this->assert_vacant($activity, (int) $group->id);
    }

    /**
     * The picker offers exactly the members the appointment would accept.
     *
     * WHY THIS TEST EXISTS. The offer was first computed by a loop inside
     * group.php, which no unit test can reach, and the Behat run that drove a
     * real browser at a real vacancy found the control missing with no way to
     * say why. The computation now lives in the service and is asked here
     * directly, so a divergence between "who is offered" and "who is accepted"
     * fails at this level instead of as a blank panel.
     *
     * MUTATION CAUGHT (run 2026-08-11): making the loop skip the gatekeeper
     * (`if (false && ...)`) so every roster member is offered fails this file
     * at the excluded-members assertion.
     */
    public function test_the_picker_offers_every_member_the_appointment_accepts(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world();
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));
        $vacant = groups::get($activity, (int) $group->id);

        $offer = $api->succession()->appointable_members($vacant);

        // Compared as a SET: get_roster orders by surname, and the generator's
        // surnames are not ours to predict. Order is a roster concern, not an
        // eligibility one, and asserting it made this test fail depending on
        // which other tests had run first.
        $expected = array_map(fn($m) => (int) $m->id, $members);
        $offered = array_keys($offer['eligible']);
        sort($expected);
        sort($offered);
        $this->assertSame($expected, $offered, 'both confirmed members should be offered');
        $this->assertSame([], $offer['excluded'], 'nobody should be excluded here');

        // The offer is not merely a list: every name on it is accepted.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, (int) $course->id, 'editingteacher');
        $appointed = (int) array_key_first($offer['eligible']);
        $api->succession()->appoint_vacant_leader($vacant, $appointed, (int) $manager->id);
        $this->assertSame(
            $appointed,
            (int) groups::get($activity, (int) $group->id)->leaderid,
            'the appointment refused a candidate its own picker had offered'
        );
    }

    /**
     * A member who cannot lead is excluded WITH THE REASON, not silently
     * dropped, so staff can see why the obvious candidate is missing.
     */
    public function test_a_member_who_cannot_lead_is_excluded_with_the_reason(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world(['maxlead' => 1]);
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));

        // The first member already leads elsewhere and maxlead is 1.
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $members[0]->id,
            'name' => 'Their other group',
        ]);

        $offer = $api->succession()->appointable_members(groups::get($activity, (int) $group->id));

        $this->assertSame([(int) $members[1]->id], array_keys($offer['eligible']));
        $this->assertSame([(int) $members[0]->id], array_keys($offer['excluded']));
        $excluded = $offer['excluded'][(int) $members[0]->id];
        $this->assertSame('refusalnomineeleadcap', $excluded['refusal']->stringkey);
        $this->assertSame(
            (int) $members[0]->id,
            (int) $excluded['member']->userid,
            'the excluded row must carry the member, so the page can name them'
        );
    }

    /**
     * PRIVACY. A peer is told the group has no leader; a peer is NOT told who
     * among their teammates is barred from leading it, nor that nobody can.
     * Those are staff judgements about other people.
     *
     * MUTATION CAUGHT (run 2026-08-11): dropping $canappoint from
     * appointleadernocandidates - which is exactly how the export was written
     * before this test - fails here with "the peer must not be told nobody can
     * lead", and fails the peer step of the Behat feature as well.
     */
    public function test_a_peer_sees_the_vacancy_but_not_the_staff_repair_detail(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $course, $group, $leader, $members] = $this->world();
        delete_user($DB->get_record('user', ['id' => (int) $leader->id]));
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_change_permission($roleid, $activity->context(), authority::LEAD, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
        $vacant = groups::get($activity, (int) $group->id);
        $excluded = [];
        foreach ($api->succession()->appointable_members($vacant)['excluded'] as $userid => $row) {
            $excluded[] = ['userid' => $userid, 'name' => fullname($row['member']), 'reason' => 'barred'];
        }
        $this->assertNotSame([], $excluded, 'the fixture must actually produce excluded members');

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id, 'g' => $vacant->id]);
        $renderer = $PAGE->get_renderer('core');

        $peer = (new \mod_selfselectadvanced\output\group_page(
            $api,
            $vacant,
            (int) $members[0]->id,
            null,
            null,
            null,
            null,
            $excluded
        ))->export_for_template($renderer);
        $this->assertTrue($peer->leadervacant, 'the peer must be told the group has no leader');
        $this->assertFalse($peer->hasappointexcluded, 'the peer must not receive the excluded list');
        $this->assertSame([], $peer->appointexcluded);
        $this->assertFalse($peer->appointleadernocandidates, 'the peer must not be told nobody can lead');

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, (int) $course->id, 'editingteacher');
        $staff = (new \mod_selfselectadvanced\output\group_page(
            $api,
            $vacant,
            (int) $manager->id,
            null,
            null,
            null,
            null,
            $excluded
        ))->export_for_template($renderer);
        $this->assertTrue($staff->hasappointexcluded, 'staff must see why each member is barred');
        $this->assertCount(count($excluded), $staff->appointexcluded);
        $this->assertTrue($staff->appointleadernocandidates, 'staff must get the honest empty state');
    }
}
