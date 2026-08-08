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
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\proposal;
use mod_selfselectadvanced\local\state;

/**
 * THE LEADER OF RECORD IS NOT AN AUTHORISED LEADER.
 *
 * Four sites, one root cause (external review AUTH-001..004). Decision
 * 38 says a team always has exactly one leader and that leadership is
 * TRANSFERRED, never removed - so a student whose :lead an
 * administrator has PROHIBITED stays in group.leaderid. Every one of
 * the four sites below authorised on that raw identity, so every one of
 * them went on admitting exactly the person the administrator had just
 * refused:
 *
 * - AUTH-001 the EOI listing toggle. No service existed at all: four
 *   inline tests and an update_record() on group.php, gated by
 *   leaderid + FORMING, with $maylead computed six lines above and
 *   deliberately not used. classes/output/group_page.php drew the
 *   buttons from the same raw test, so no crafted POST was needed.
 * - AUTH-002 the proposal upload. leaderid OR :manage, then
 *   file_save_draft_area_files() inline. No service, and - a fact the
 *   finding did not carry - NO LIFECYCLE TEST EITHER, so a leader could
 *   replace the document on a team that had already been submitted,
 *   approved or frozen.
 * - AUTH-003 groupedit.php's edit branch. The capability check had been
 *   moved BELOW the branch in D6-4 to unbreak the staff path, which
 *   left the edit branch asking leaderid-or-staff and nothing else.
 * - AUTH-004 eoi::respond(). $isleader short-circuited every capability
 *   test in the method, on the line that INSTALLS a guide on the team
 *   and auto-declines every rival interest.
 *
 * Two of the four verbs are gated on ONE HALF ONLY, per the project's
 * F3 invariant that an actor is never blocked from making themselves
 * LESS visible: UNLISTING a team and REMOVING one's own proposal stay
 * open to a prohibited leader, and both of those are pinned here as
 * hard as the refusals are. A fix that closed them would have been a
 * different defect.
 *
 * Shape of every test, because it is the only shape that proves
 * anything: establish the verb WORKS for the actor, prohibit the
 * capability at the activity context - the override an administrator
 * actually makes on the Permissions page - call the SAME production
 * function the page calls, watch it refuse, and READ THE ROW BACK WITH
 * $DB. Nothing here restates has_capability().
 *
 * ON THE ROLLBACK CAVEAT: advanced_testcase holds a delegated
 * transaction open on PostgreSQL only, so an assertion that rests on a
 * rollback can read differently on the two engines. No assertion here
 * rests on one. Every refusal in these services is raised BEFORE its
 * write - authority::require_lead() precedes update_record() in
 * eoi::set_listed() and api::update_group_details(), and precedes
 * file_save_draft_area_files() in proposal::save() - so "the row is
 * unchanged" is a statement about a write that never happened, which
 * reads the same on m5pg and m5my.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\eoi::set_listed
 * @covers     \mod_selfselectadvanced\local\eoi::respond
 * @covers     \mod_selfselectadvanced\local\proposal
 * @covers     \mod_selfselectadvanced\local\api::update_group_details
 * @covers     \mod_selfselectadvanced\output\group_page
 */
final class leaderofrecord_authority_test extends \advanced_testcase {
    /**
     * A course, an EOI-enabled activity, two students, two guides and
     * an editing teacher.
     *
     * @param array $settings instance setting overrides
     * @return array{0: activity, 1: api, 2: \stdClass[], 3: \stdClass[], 4: \stdClass}
     *         activity, api, students, guides, staff
     */
    private function fixture(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
            'eoienabled' => 1,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 2; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guides = [];
        for ($i = 0; $i < 2; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'teacher');
            $guides[] = $user;
        }
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        return [$activity, new api($activity), $students, $guides, $staff];
    }

    /**
     * Prohibit a capability for a role at the ACTIVITY context.
     *
     * @param string $capability the capability to prohibit
     * @param \context $context the context to prohibit it in
     * @param string $shortname the role's shortname
     */
    private function prohibit(string $capability, \context $context, string $shortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_change_permission($roleid, $context, $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The plugin generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * A forming team led by the given student.
     *
     * @param activity $activity the activity
     * @param int $leaderid the leader
     * @param string $name the team name
     * @param string $groupstate lifecycle state
     * @return \stdClass the group row
     */
    private function team(activity $activity, int $leaderid, string $name, string $groupstate = state::FORMING): \stdClass {
        $group = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderid,
            'name' => $name,
            'state' => $groupstate,
        ]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * The exported group page for one viewer - the same
     * export_for_template() call group.php makes.
     *
     * @param activity $activity the activity
     * @param api $api the facade
     * @param int $groupid the team
     * @param int $userid the viewer
     * @return \stdClass the template context
     */
    private function grouppage(activity $activity, api $api, int $groupid, int $userid): \stdClass {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);

        return (new \mod_selfselectadvanced\output\group_page(
            $api,
            groups::get($activity, $groupid),
            $userid
        ))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Put one file into a team's proposal area.
     *
     * @param activity $activity the activity
     * @param int $groupid the team
     * @param string $filename the file name
     */
    private function seed_proposal(activity $activity, int $groupid, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $activity->context()->id,
            'component' => 'mod_selfselectadvanced',
            'filearea' => proposal::FILEAREA,
            'itemid' => $groupid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'seeded proposal');
    }

    /**
     * A draft area belonging to one user, holding either one file or
     * nothing at all - the two things a file manager can submit, and
     * therefore the two halves of the proposal verb.
     *
     * @param int $userid the draft area's owner
     * @param string|null $filename a file to put in it, or null for empty
     * @return int the draft item id
     */
    private function draft(int $userid, ?string $filename): int {
        $itemid = file_get_unused_draft_itemid();
        if ($filename !== null) {
            get_file_storage()->create_file_from_string([
                'contextid' => \context_user::instance($userid)->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $itemid,
                'filepath' => '/',
                'filename' => $filename,
            ], 'uploaded proposal');
        }

        return $itemid;
    }

    /**
     * The file options group.php prepares the proposal filemanager with.
     *
     * @return array file options
     */
    private function fileoptions(): array {
        return ['maxfiles' => 1, 'subdirs' => 0, 'accepted_types' => ['document', '.pdf']];
    }

    // AUTH-001 - listing a team for guides.

    /**
     * AUTH-001, the positive control the refusal needs to mean
     * anything: with the capability in force the leader lists the team,
     * and the first listing stamps timelisted.
     */
    public function test_a_leader_with_the_capability_lists_the_team(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Listable');

        eoi::set_listed($activity, (int) $group->id, true, $leader);

        $row = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->listed, 'a permitted leader failed to list their own team');
        $this->assertNotEmpty($row->timelisted, 'the first listing did not stamp timelisted');
    }

    /**
     * AUTH-001: LISTING is a publication - it puts the team in front of
     * every guide in the activity - so a leader whose :lead has
     * been prohibited is refused, and the field is not written.
     *
     * Mutation this catches: delete the `if ($listed) { require_lead
     * }` from eoi::set_listed() (equivalently, revert group.php to its
     * inline update_record()) and the listed field goes to 1.
     */
    public function test_listing_refuses_a_prohibited_leader(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Prohibited listing');

        $this->assertTrue(authority::may_lead($activity, $leader), 'fixture: the capability must start live');
        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertFalse(authority::may_lead($activity, $leader));

        // Ownership and lifecycle are both untouched: the only thing
        // that changed is the administrator's decision (decision 38).
        $row = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame($leader, (int) $row->leaderid, 'the actor stopped leading, so this proves nothing');
        $this->assertSame(state::FORMING, $row->state, 'the team left FORMING, so this proves nothing');

        try {
            eoi::set_listed($activity, (int) $group->id, true, $leader);
            $this->fail('set_listed() listed a team for a leader whose capability is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::LEAD), $e->a);
        }

        $after = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $after->listed, 'a refused listing still wrote the field');
        $this->assertEmpty($after->timelisted, 'a refused listing still stamped timelisted');
    }

    /**
     * AUTH-001, THE HALF THAT MUST STAY OPEN. Unlisting is a
     * RETRACTION, and F3 says an actor is never blocked from making
     * themselves less visible. A leader whose capability is taken away
     * while the team is listed must still be able to take it down;
     * otherwise a published team sits in front of every guide with
     * nobody but staff able to withdraw it.
     *
     * Mutation this catches: gate set_listed() unconditionally - the
     * blanket require_lead() the external reviewer recommended - and
     * this call throws instead of clearing the field.
     */
    public function test_unlisting_stays_open_to_a_prohibited_leader(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Retractable');

        eoi::set_listed($activity, (int) $group->id, true, $leader);
        $this->assertSame(
            1,
            (int) $DB->get_field('selfselectadvanced_group', 'listed', ['id' => (int) $group->id]),
            'fixture: the team must be listed before the retraction can mean anything'
        );

        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertFalse(authority::may_lead($activity, $leader));

        eoi::set_listed($activity, (int) $group->id, false, $leader);

        $this->assertSame(
            0,
            (int) $DB->get_field('selfselectadvanced_group', 'listed', ['id' => (int) $group->id]),
            'a prohibited leader was blocked from taking their own team OFF the board (F3)'
        );
    }

    /**
     * AUTH-001: whoever is not the leader of record is refused both
     * halves, prohibition or no prohibition. The retraction exception
     * is for the LEADER; it is not an open door.
     */
    public function test_neither_half_is_open_to_a_non_leader(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $other = (int) $students[1]->id;
        $group = $this->team($activity, $leader, 'Not yours');
        eoi::set_listed($activity, (int) $group->id, true, $leader);

        foreach ([true, false] as $wanted) {
            try {
                eoi::set_listed($activity, (int) $group->id, $wanted, $other);
                $this->fail('set_listed() accepted an actor who does not lead the team');
            } catch (\moodle_exception $e) {
                $this->assertSame('refusalnotleader', $e->errorcode);
            }
        }
        $this->assertSame(
            1,
            (int) $DB->get_field('selfselectadvanced_group', 'listed', ['id' => (int) $group->id]),
            'a stranger changed the listing anyway'
        );
    }

    /**
     * AUTH-001, the CONTROL half: group_page draws the two buttons from
     * two flags now, and the prohibited leader keeps exactly the one
     * that still works. Before this wave one flag drew both, so the
     * "List this group for guides" button was live for an actor the
     * service refuses - no crafted POST required.
     *
     * Mutation this catches: put $showeoilist back to `$isleader &&
     * $isforming && $eoienabled` and the first assertion after the
     * prohibit fails.
     */
    public function test_the_listing_control_splits_where_the_service_splits(): void {
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Two buttons');

        $before = $this->grouppage($activity, $api, (int) $group->id, $leader);
        $this->assertTrue($before->showeoilist, 'fixture: the list button must start live');
        $this->assertTrue($before->showeoiunlist, 'fixture: the unlist button must start live');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        $after = $this->grouppage($activity, $api, (int) $group->id, $leader);
        $this->assertFalse($after->showeoilist, 'the LIST button was still drawn for a prohibited leader');
        $this->assertTrue(
            $after->showeoiunlist,
            'the UNLIST button was taken away from a prohibited leader, which F3 forbids'
        );
    }

    // AUTH-002 - the written proposal.

    /**
     * AUTH-002, the positive control: a permitted leader of a forming
     * team saves a proposal, and the file lands.
     */
    public function test_a_leader_with_the_capability_saves_a_proposal(): void {
        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $this->setUser($students[0]);
        $group = $this->team($activity, $leader, 'Papered');

        $kept = proposal::save(
            $activity,
            (int) $group->id,
            $this->draft($leader, 'plan.pdf'),
            $this->fileoptions(),
            $leader
        );

        $this->assertSame(1, $kept);
        $this->assertSame(1, proposal::count_files($activity, (int) $group->id));
    }

    /**
     * AUTH-002: uploading or REPLACING is a publication and a
     * prohibited leader is refused it - with the file area read back,
     * because a service that throws after writing has still written.
     *
     * Mutation this catches: drop the may_publish() branch from
     * proposal::save() (or restore group.php's raw leaderid test) and
     * the area ends up holding the new file.
     */
    public function test_proposal_upload_refuses_a_prohibited_leader(): void {
        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $this->setUser($students[0]);
        $group = $this->team($activity, $leader, 'Refused paper');
        $this->seed_proposal($activity, (int) $group->id, 'original.pdf');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertFalse(proposal::may_publish($activity, $group, $leader));

        try {
            proposal::save(
                $activity,
                (int) $group->id,
                $this->draft($leader, 'replacement.pdf'),
                $this->fileoptions(),
                $leader
            );
            $this->fail('proposal::save() accepted an upload from a prohibited leader');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::LEAD), $e->a);
        }

        $names = array_map(
            static fn(\stored_file $f): string => $f->get_filename(),
            array_values(get_file_storage()->get_area_files(
                $activity->context()->id,
                'mod_selfselectadvanced',
                proposal::FILEAREA,
                (int) $group->id,
                'id',
                false
            ))
        );
        $this->assertSame(['original.pdf'], $names, 'a refused upload replaced the proposal anyway');
    }

    /**
     * AUTH-002, THE HALF THAT MUST STAY OPEN. Saving an EMPTY draft
     * area is how a file manager says "delete my proposal", and F3
     * classes that as a retraction. A prohibited leader keeps it.
     *
     * Mutation this catches: gate proposal::save() unconditionally and
     * this call throws with the file still in the area.
     */
    public function test_proposal_removal_stays_open_to_a_prohibited_leader(): void {
        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $this->setUser($students[0]);
        $group = $this->team($activity, $leader, 'Retract paper');
        $this->seed_proposal($activity, (int) $group->id, 'original.pdf');
        $this->assertSame(1, proposal::count_files($activity, (int) $group->id), 'fixture: seed the file first');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertFalse(proposal::may_publish($activity, $group, $leader));
        $this->assertTrue(proposal::may_retract($activity, $group, $leader));

        $kept = proposal::save(
            $activity,
            (int) $group->id,
            $this->draft($leader, null),
            $this->fileoptions(),
            $leader
        );

        $this->assertSame(0, $kept);
        $this->assertSame(
            0,
            proposal::count_files($activity, (int) $group->id),
            'a prohibited leader was blocked from removing their OWN proposal (F3)'
        );
    }

    /**
     * AUTH-002, THE ADJACENT FACT THE FINDING MISSED, and the answer
     * chosen for it: the branch had no lifecycle test at all, so the
     * proposal could be replaced on a team that had been submitted,
     * approved or frozen. THE LEADER'S WINDOW IS FORMING. The document
     * is what the guide judges, and decisions 39/40 already say nothing
     * about a PENDING_GUIDE team is the students' to change while the
     * guide decides.
     *
     * Every state after FORMING is exercised, because a fix that
     * happened to catch only one of them is not the fix.
     *
     * Mutation this catches: remove the state test from
     * proposal::may_publish() and all three refusals become saves.
     */
    public function test_the_leaders_proposal_window_closes_at_submission(): void {
        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $this->setUser($students[0]);

        foreach ([state::PENDING_GUIDE, state::FIRM, state::FROZEN] as $index => $moved) {
            $group = $this->team($activity, $leader, 'Moved on ' . $index, $moved);
            $this->assertTrue(
                authority::may_lead($activity, $leader),
                'fixture: the capability is live, so only the state can be doing the refusing'
            );
            $this->assertFalse(proposal::may_publish($activity, $group, $leader));
            $this->assertFalse(proposal::may_retract($activity, $group, $leader));

            try {
                proposal::save(
                    $activity,
                    (int) $group->id,
                    $this->draft($leader, 'late.pdf'),
                    $this->fileoptions(),
                    $leader
                );
                $this->fail('proposal::save() let the leader rewrite the proposal of a ' . $moved . ' team');
            } catch (\moodle_exception $e) {
                $this->assertSame('refusalwrongstate', $e->errorcode);
            }
            $this->assertSame(0, proposal::count_files($activity, (int) $group->id));
        }
    }

    /**
     * AUTH-002, the other side of that answer, stated because it is a
     * decision and not an accident: a :manage holder may still write
     * the proposal in ANY state. Replacing a wrong or corrupt file
     * after approval is a staff repair, and it is the only route left
     * once the leader's window has shut. Staff behaviour is unchanged
     * by this wave, deliberately.
     */
    public function test_staff_may_still_repair_a_proposal_after_approval(): void {
        $this->resetAfterTest();
        [$activity, , $students, , $staff] = $this->fixture();
        $leader = (int) $students[0]->id;
        $this->setUser($staff);
        $group = $this->team($activity, $leader, 'Approved paper', state::FIRM);

        $this->assertFalse(
            authority::may_lead($activity, (int) $staff->id),
            'fixture: an editing teacher does not hold the STUDENT capability (D6-4)'
        );
        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        $kept = proposal::save(
            $activity,
            (int) $group->id,
            $this->draft((int) $staff->id, 'repaired.pdf'),
            $this->fileoptions(),
            (int) $staff->id
        );

        $this->assertSame(1, $kept, 'the staff repair path was caught by the leader gate');
    }

    /**
     * AUTH-002: a member who does not lead the team is refused, whether
     * they bring a file or an empty draft.
     */
    public function test_the_proposal_is_closed_to_a_non_leader(): void {
        $this->resetAfterTest();
        [$activity, , $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $other = (int) $students[1]->id;
        $this->setUser($students[1]);
        $group = $this->team($activity, $leader, 'Somebody elses paper');
        $this->seed_proposal($activity, (int) $group->id, 'original.pdf');

        foreach (['intruder.pdf', null] as $filename) {
            try {
                proposal::save(
                    $activity,
                    (int) $group->id,
                    $this->draft($other, $filename),
                    $this->fileoptions(),
                    $other
                );
                $this->fail('proposal::save() accepted an actor who does not lead the team');
            } catch (\moodle_exception $e) {
                $this->assertSame('refusalnotleader', $e->errorcode);
            }
        }
        $this->assertSame(1, proposal::count_files($activity, (int) $group->id));
    }

    // AUTH-003 - the title and brief.

    /**
     * AUTH-003, the positive control: a permitted leader revises the
     * title and brief of their forming team.
     */
    public function test_a_leader_with_the_capability_revises_the_details(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Editable');

        $api->update_group_details($group, 'Revised title', '<p>Revised brief</p>', FORMAT_HTML, $leader);

        $row = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame('Revised title', $row->title);
        $this->assertSame('<p>Revised brief</p>', $row->brief);
        $this->assertSame($leader, (int) $row->usermodified);
    }

    /**
     * AUTH-003: the leader of record is refused once :lead is
     * prohibited, and the two texts every browsing guide reads are
     * unchanged.
     *
     * Mutation this catches: remove authority::require_lead() from
     * api::update_group_details() (or from groupedit.php's edit branch)
     * and the title is rewritten.
     */
    public function test_details_edit_refuses_a_prohibited_leader(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Locked details');
        $original = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);

        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertSame(
            $leader,
            (int) $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => (int) $group->id]),
            'the actor stopped leading, so this proves nothing'
        );

        try {
            $api->update_group_details($group, 'Hijacked', '<p>Hijacked</p>', FORMAT_HTML, $leader);
            $this->fail('update_group_details() accepted a leader whose capability is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::LEAD), $e->a);
        }

        $after = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame($original->title, $after->title, 'a refused edit rewrote the title anyway');
        $this->assertSame($original->brief, $after->brief, 'a refused edit rewrote the brief anyway');
    }

    /**
     * AUTH-003, THE REGRESSION THIS FIX MUST NOT CAUSE. D6-4 moved the
     * capability check below the branch precisely because :lead
     * is a STUDENT capability an editing teacher does not hold, and
     * demanding it of everybody made the staff repair path unreachable.
     * Restoring the gate for the leader must leave staff exactly where
     * D6-4 left them - with the student capability PROHIBITED, which is
     * the state that would catch a fix reaching for the wrong gate.
     *
     * Mutation this catches: move require_lead() above the staff
     * branch in api::update_group_details() and this edit throws.
     */
    public function test_staff_editing_is_not_caught_by_the_leader_gate(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students, , $staff] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Staff repair');

        $this->assertFalse(authority::may_lead($activity, (int) $staff->id));
        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        $api->update_group_details($group, 'Repaired', '<p>Repaired</p>', FORMAT_HTML, (int) $staff->id);

        $this->assertSame(
            'Repaired',
            $DB->get_field('selfselectadvanced_group', 'title', ['id' => (int) $group->id]),
            'the staff repair path was broken by the leader gate'
        );
    }

    /**
     * AUTH-003: the edit is refused once the team has left FORMING -
     * the check the page applied and the service now owns, so a direct
     * POST meets it too.
     */
    public function test_details_edit_refuses_a_team_that_has_moved_on(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $api, $students] = $this->fixture();
        $leader = (int) $students[0]->id;
        $group = $this->team($activity, $leader, 'Submitted details', state::PENDING_GUIDE);

        try {
            $api->update_group_details($group, 'Too late', '<p>Too late</p>', FORMAT_HTML, $leader);
            $this->fail('update_group_details() edited a team that had left FORMING');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalwrongstate', $e->errorcode);
        }
        $this->assertNotSame(
            'Too late',
            $DB->get_field('selfselectadvanced_group', 'title', ['id' => (int) $group->id])
        );
    }

    // AUTH-004 - deciding an expression of interest.

    /**
     * A listed forming team with one pending interest on it.
     *
     * @param activity $activity the activity
     * @param int $leaderid the leader
     * @param int $guideid the interested guide
     * @param string $name the team name
     * @return array{0: \stdClass, 1: \stdClass} group row, eoi row
     */
    private function interested(activity $activity, int $leaderid, int $guideid, string $name): array {
        global $DB;

        $group = $this->team($activity, $leaderid, $name);
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => (int) $group->id]);
        $eoirow = $this->plugingen()->create_eoi([
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'guideid' => $guideid,
        ]);

        return [groups::get($activity, (int) $group->id), $eoirow];
    }

    /**
     * AUTH-004, the positive control: a permitted leader accepts an
     * interest and the guide is pre-assigned.
     */
    public function test_a_leader_with_the_capability_accepts_an_interest(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students, $guides] = $this->fixture();
        $leader = (int) $students[0]->id;
        [$group, $eoirow] = $this->interested($activity, $leader, (int) $guides[0]->id, 'Willing');

        eoi::respond($activity, (int) $eoirow->id, true, $leader);

        $this->assertSame(
            (int) $guides[0]->id,
            (int) $DB->get_field('selfselectadvanced_group', 'guideid', ['id' => (int) $group->id])
        );
        $this->assertSame(
            eoi::STATUS_ACCEPTED,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => (int) $eoirow->id])
        );
    }

    /**
     * AUTH-004: $isleader short-circuited every capability test in
     * respond(), on the line that INSTALLS a guide. A prohibited leader
     * is refused, the guide slot stays empty and the interest stays
     * pending.
     *
     * Mutation this catches: change `$leadermayact` back to `$isleader`
     * in eoi::respond() and the acceptance goes through.
     */
    public function test_eoi_acceptance_refuses_a_prohibited_leader(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students, $guides] = $this->fixture();
        $leader = (int) $students[0]->id;
        [$group, $eoirow] = $this->interested($activity, $leader, (int) $guides[0]->id, 'Unwilling');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertSame(
            $leader,
            (int) $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => (int) $group->id]),
            'the actor stopped leading, so this proves nothing'
        );

        try {
            eoi::respond($activity, (int) $eoirow->id, true, $leader);
            $this->fail('eoi::respond() accepted an interest for a leader whose capability is prohibited');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotleader', $e->errorcode);
        }

        $this->assertEmpty(
            $DB->get_field('selfselectadvanced_group', 'guideid', ['id' => (int) $group->id]),
            'a refused acceptance installed the guide anyway'
        );
        $this->assertSame(
            eoi::STATUS_PENDING,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => (int) $eoirow->id]),
            'a refused acceptance still moved the interest'
        );
    }

    /**
     * AUTH-004: REJECTING is gated too. It is not a retraction - it
     * spends another guide's offer and, under eoisequential, promotes
     * the next in the queue - so F3 does not reach it and the finding's
     * own instruction was to change only the authority test.
     */
    public function test_eoi_rejection_refuses_a_prohibited_leader(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students, $guides] = $this->fixture();
        $leader = (int) $students[0]->id;
        [, $eoirow] = $this->interested($activity, $leader, (int) $guides[0]->id, 'Unwilling too');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        try {
            eoi::respond($activity, (int) $eoirow->id, false, $leader);
            $this->fail('eoi::respond() rejected an interest for a leader whose capability is prohibited');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotleader', $e->errorcode);
        }

        $this->assertSame(
            eoi::STATUS_PENDING,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => (int) $eoirow->id]),
            'a refused rejection still moved the interest'
        );
    }

    /**
     * AUTH-004, the staff half left alone: a :manage holder decides
     * interests on the same team while the STUDENT capability is
     * prohibited. The narrowing is aimed at the leader arm only.
     */
    public function test_a_manager_still_decides_an_interest(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, , $students, $guides, $staff] = $this->fixture();
        $leader = (int) $students[0]->id;
        [$group, $eoirow] = $this->interested($activity, $leader, (int) $guides[0]->id, 'Staff decides');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        eoi::respond($activity, (int) $eoirow->id, true, (int) $staff->id);

        $this->assertSame(
            (int) $guides[0]->id,
            (int) $DB->get_field('selfselectadvanced_group', 'guideid', ['id' => (int) $group->id]),
            'the manager path was caught by the leader gate'
        );
    }

    /**
     * AUTH-004, the CONTROL half: Accept and Decline are not drawn for
     * a leader the service will refuse. The panel and the interest
     * itself stay - the leader still has to be able to see who is
     * waiting on them, exactly as the invitation list does when
     * :respond is prohibited - and it is the two buttons that go.
     *
     * Mutation this catches: drop `&& $maylead` from $caneoirespond in
     * group_page and the flag stays true after the prohibit.
     */
    public function test_the_decision_control_follows_the_service(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, $guides] = $this->fixture();
        $leader = (int) $students[0]->id;
        [$group] = $this->interested($activity, $leader, (int) $guides[0]->id, 'Decide me');

        $before = $this->grouppage($activity, $api, (int) $group->id, $leader);
        $this->assertTrue($before->caneoirespond, 'fixture: the decision buttons must start live');
        $this->assertTrue($before->haseoirows, 'fixture: there must be an interest to decide');

        $this->prohibit(authority::LEAD, $activity->context(), 'student');

        $after = $this->grouppage($activity, $api, (int) $group->id, $leader);
        $this->assertFalse($after->caneoirespond, 'Accept/Decline were still drawn for a prohibited leader');
        $this->assertTrue(
            $after->haseoirows,
            'the pending interest vanished - a leader must still see who is waiting on them'
        );
    }
}
