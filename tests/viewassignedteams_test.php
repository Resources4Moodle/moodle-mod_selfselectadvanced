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
use mod_selfselectadvanced\local\coordinatorimport;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\teamaccess;

/**
 * Least privilege: an assigned guide reaches their own team without :viewall.
 *
 * Until 1.20.1 group.php's entry gate asked "are you a member, or may
 * you see EVERYTHING?", and a guide is never a member of the team they
 * guide - so withdrawing :viewall from the non-editing teacher role
 * refused every guide the page carrying Freeze, Release, the roster and
 * the proposal. This file pins the split: :viewall answers "how many
 * teams", :viewassignedteams answers "which team", and the migration
 * never takes a permission away from a site that recorded one.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\teamaccess
 */
final class viewassignedteams_test extends \advanced_testcase {
    /** @var \stdClass|null Course. */
    private ?\stdClass $course = null;

    /** @var activity|null The activity under test. */
    private ?activity $activity = null;

    /** @var array<string, \stdClass> The cast, by handle. */
    private array $users = [];

    /** @var \stdClass|null Team Alpha, guided by guide_alpha. */
    private ?\stdClass $alpha = null;

    /** @var \stdClass|null Team Beta, guided by guide_beta. */
    private ?\stdClass $beta = null;

    /** @var int The role holding only :manage at the module context. */
    private int $narrowmanagerrole = 0;

    /**
     * The permission recorded for one role and capability at system context.
     *
     * @param int $roleid the role
     * @param string $capability the capability name
     * @return int|null the stored permission, or null when nothing is recorded
     */
    private function permission_of(int $roleid, string $capability): ?int {
        global $DB;

        $permission = $DB->get_field('role_capabilities', 'permission', [
            'contextid' => \context_system::instance()->id,
            'roleid' => $roleid,
            'capability' => $capability,
        ]);

        return $permission === false ? null : (int) $permission;
    }

    /**
     * A core role id by shortname.
     *
     * @param string $shortname the role shortname
     * @return int the role id
     */
    private function role_id(string $shortname): int {
        global $DB;

        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Remove every trace of the new capability, so the next
     * update_capabilities() call treats it as NEW and runs the
     * clonepermissionsfrom pass - which is the only thing this design
     * rests on and the only thing a rebuilt site never exercises.
     */
    private function make_it_a_pre_1201_site(): void {
        global $DB;

        $DB->delete_records('role_capabilities', [
            'capability' => 'mod/selfselectadvanced:viewassignedteams',
        ]);
        $DB->delete_records('capabilities', [
            'name' => 'mod/selfselectadvanced:viewassignedteams',
        ]);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Course, activity, two guides, two teams, a member, an invitee, an
     * outsider and a role holding ONLY :manage.
     */
    private function build_world(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->course = $generator->create_course(['shortname' => 'VAT1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 5,
            'eoienabled' => 1,
            'eoiwindow' => 3600,
        ]);
        $this->activity = activity::from_instance((int) $instance->id);

        foreach (['guidealpha', 'guidebeta'] as $handle) {
            $this->users[$handle] = $generator->create_user(['username' => $handle]);
            $generator->enrol_user($this->users[$handle]->id, $this->course->id, 'teacher');
        }
        foreach (['leader', 'member', 'invitee', 'outsider', 'betalead'] as $handle) {
            $this->users[$handle] = $generator->create_user(['username' => $handle]);
            $generator->enrol_user($this->users[$handle]->id, $this->course->id, 'student');
        }

        // A person whose only plugin authority is :manage - the actor
        // group.php's gate refused along with eight manager-only
        // actions. A role of its own, because no shipped role holds
        // :manage without also holding :viewall.
        $this->users['narrowmanager'] = $generator->create_user(['username' => 'narrowmanager']);
        $generator->enrol_user($this->users['narrowmanager']->id, $this->course->id, 'student');
        $this->narrowmanagerrole = $generator->create_role();
        assign_capability(
            'mod/selfselectadvanced:manage',
            CAP_ALLOW,
            $this->narrowmanagerrole,
            $this->activity->context()->id
        );
        role_assign($this->narrowmanagerrole, $this->users['narrowmanager']->id, $this->activity->context());

        $this->alpha = $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => (int) $this->users['leader']->id,
            'name' => 'Alpha',
            'guideid' => (int) $this->users['guidealpha']->id,
            'state' => state::FIRM,
            'timeapproved' => time() - DAYSECS,
        ]);
        $plugingen->create_member([
            'groupid' => $this->alpha->id,
            'userid' => (int) $this->users['member']->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $plugingen->create_member([
            'groupid' => $this->alpha->id,
            'userid' => (int) $this->users['invitee']->id,
            'status' => groups::STATUS_INVITED,
        ]);
        $this->beta = $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => (int) $this->users['betalead']->id,
            'name' => 'Beta',
            'guideid' => (int) $this->users['guidebeta']->id,
            'state' => state::FIRM,
            'timeapproved' => time() - DAYSECS,
        ]);

        // The maintainer's site: the broad capability withdrawn from
        // every non-editing teacher at the course. Recorded explicitly
        // rather than relied on, because after this release the
        // archetype does not grant it either and a fixture that only
        // works by accident is a fixture that stops working.
        assign_capability(
            'mod/selfselectadvanced:viewall',
            CAP_PREVENT,
            $this->role_id('teacher'),
            \context_course::instance($this->course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        unset($DB);
    }

    /**
     * 1. The clone SOURCE is :guide and not :viewall, and that decides
     * the design: core copies the source role's permission VERBATIM,
     * CAP_PREVENT included, so cloning from :viewall would hand the new
     * capability's PREVENT to exactly the sites it exists for.
     */
    public function test_the_clone_source_is_guide_and_not_viewall(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $allowrole = $generator->create_role(['shortname' => 'guideallow']);
        $preventrole = $generator->create_role(['shortname' => 'guideprevent']);
        $withdrawnrole = $generator->create_role(['shortname' => 'guidewithdrawn']);
        $neitherrole = $generator->create_role(['shortname' => 'guideneither']);
        $syscontextid = \context_system::instance()->id;

        assign_capability('mod/selfselectadvanced:guide', CAP_ALLOW, $allowrole, $syscontextid, true);
        assign_capability('mod/selfselectadvanced:guide', CAP_PREVENT, $preventrole, $syscontextid, true);
        // The maintainer's site: guiding allowed, seeing everything
        // withdrawn. The whole point of the new capability.
        assign_capability('mod/selfselectadvanced:guide', CAP_ALLOW, $withdrawnrole, $syscontextid, true);
        assign_capability('mod/selfselectadvanced:viewall', CAP_PREVENT, $withdrawnrole, $syscontextid, true);

        $this->make_it_a_pre_1201_site();
        $this->assertNull(
            $this->permission_of($allowrole, 'mod/selfselectadvanced:viewassignedteams'),
            'the pre-upgrade state was not reached'
        );

        update_capabilities('mod_selfselectadvanced');
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame(
            CAP_ALLOW,
            $this->permission_of($allowrole, 'mod/selfselectadvanced:viewassignedteams')
        );
        $this->assertSame(
            CAP_PREVENT,
            $this->permission_of($preventrole, 'mod/selfselectadvanced:viewassignedteams')
        );
        $this->assertSame(
            CAP_ALLOW,
            $this->permission_of($withdrawnrole, 'mod/selfselectadvanced:viewassignedteams'),
            'a site that already withdrew :viewall must NOT upgrade into the same lockout'
        );
        $this->assertNull(
            $this->permission_of($neitherrole, 'mod/selfselectadvanced:viewassignedteams'),
            'a role with no :guide row must gain no row'
        );
    }

    /**
     * 2. Taking the archetype off an EXISTING capability is inert: core
     * applies an archetype list only to capabilities new to the
     * capabilities table, so no site loses a recorded permission.
     */
    public function test_removing_the_archetype_never_touches_an_existing_site(): void {
        $this->resetAfterTest();

        $teacherrole = $this->role_id('teacher');
        $syscontextid = \context_system::instance()->id;
        // The row a pre-1.20.1 install wrote from the archetype list.
        assign_capability('mod/selfselectadvanced:viewall', CAP_ALLOW, $teacherrole, $syscontextid, true);
        $this->make_it_a_pre_1201_site();
        $this->assertSame(CAP_ALLOW, $this->permission_of($teacherrole, 'mod/selfselectadvanced:viewall'));

        update_capabilities('mod_selfselectadvanced');
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame(
            CAP_ALLOW,
            $this->permission_of($teacherrole, 'mod/selfselectadvanced:viewall'),
            'the archetype edit reached an existing site and took a permission away'
        );
        // Vacuity control in the same method: the call that left the
        // old row alone DID do something.
        $this->assertSame(
            CAP_ALLOW,
            $this->permission_of($teacherrole, 'mod/selfselectadvanced:viewassignedteams'),
            'update_capabilities() granted nothing, so the assertion above examined nothing'
        );
    }

    /**
     * 3. A fresh install does not make a non-editing teacher an
     * unrestricted viewer - the phpunit site IS a fresh install.
     *
     * Needs --reinit to move: the capabilities table is built by
     * db/install.php, so restoring 'teacher' => CAP_ALLOW in
     * db/access.php's :viewall block only shows up on a rebuilt site.
     */
    public function test_a_fresh_install_does_not_make_a_non_editing_teacher_an_unrestricted_viewer(): void {
        $this->resetAfterTest();

        $teacherrole = $this->role_id('teacher');
        $this->assertNull(
            $this->permission_of($teacherrole, 'mod/selfselectadvanced:viewall'),
            'the non-editing teacher archetype is still an unrestricted viewer on a fresh install'
        );
        $this->assertSame(
            CAP_ALLOW,
            $this->permission_of($teacherrole, 'mod/selfselectadvanced:viewassignedteams')
        );
        // What a guide actually needs is still there.
        $this->assertSame(CAP_ALLOW, $this->permission_of($teacherrole, 'mod/selfselectadvanced:guide'));
        $this->assertSame(CAP_ALLOW, $this->permission_of($teacherrole, 'mod/selfselectadvanced:freeze'));
    }

    /**
     * 4. The generated coordinator role carries it on a fresh install -
     * and still carries :viewall, which is a recorded decision and not
     * an oversight (T-19 decision 5).
     */
    public function test_the_coordinator_role_gains_it_on_a_fresh_install(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        // A STUDENT, deliberately. The ticket's fixture appointed a
        // non-editing teacher, who already holds :viewassignedteams by
        // archetype - so the assertion below passed with the capability
        // deleted from capabilities() (negative control NC4, run
        // 2026-08-01, came back GREEN). The coordinator role has to be
        // the only possible source of the capability or this test
        // examines nothing.
        $appointee = (int) $this->users['outsider']->id;
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:viewassignedteams', $this->activity->context(), $appointee),
            'the appointee must not already hold it, or the appointment proves nothing'
        );

        $roleid = coordinatorrole::ensure();
        $this->assertGreaterThan(0, $roleid);
        // The role already exists on the phpunit site, created by
        // db/install.php, so its capability rows were written when the
        // site was BUILT. Strip them and let ensure() write them again,
        // or capabilities() is never consulted and the assertion below
        // is answered by install-time state (negative control NC4, run
        // 2026-08-01, came back GREEN on that shape too).
        $DB->delete_records('role_capabilities', ['roleid' => $roleid]);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame(
            0,
            $DB->count_records('role_capabilities', ['roleid' => $roleid]),
            'the pre-ensure() state was not reached'
        );

        coordinatorrole::ensure();
        role_assign($roleid, $appointee, $this->activity->context());
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:viewassignedteams',
            $this->activity->context(),
            $appointee
        ));
        $this->assertContains(
            'mod/selfselectadvanced:viewall',
            coordinatorrole::capabilities(),
            'decision 5 kept :viewall on the coordinator role - a queue that spans teams needs it'
        );
    }

    /**
     * 5. ensure() still respects a recorded PREVENT: an upgrade must
     * never quietly restore a permission an administrator removed.
     */
    public function test_ensure_still_respects_a_recorded_prevent(): void {
        $this->resetAfterTest();

        $roleid = coordinatorrole::ensure();
        assign_capability(
            'mod/selfselectadvanced:viewassignedteams',
            CAP_PREVENT,
            $roleid,
            \context_system::instance()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        coordinatorrole::ensure();

        $this->assertSame(
            CAP_PREVENT,
            $this->permission_of($roleid, 'mod/selfselectadvanced:viewassignedteams'),
            'ensure() overruled an administrator'
        );
    }

    /**
     * 6. may_open_team() admits exactly four kinds of person, with
     * :viewall withdrawn from the non-editing teacher role.
     */
    public function test_may_open_team_admits_exactly_four_kinds_of_person(): void {
        $this->resetAfterTest();
        $this->build_world();

        $alpha = groups::get($this->activity, (int) $this->alpha->id);

        $this->assertTrue(
            teamaccess::may_open_team($this->activity, $alpha, (int) $this->users['guidealpha']->id),
            'the assigned guide'
        );
        $this->assertTrue(
            teamaccess::may_open_team($this->activity, $alpha, (int) $this->users['member']->id),
            'a confirmed member'
        );
        $this->assertTrue(
            teamaccess::may_open_team($this->activity, $alpha, (int) $this->users['invitee']->id),
            'an invited but unanswered member'
        );
        $this->assertTrue(
            teamaccess::may_open_team($this->activity, $alpha, (int) $this->users['narrowmanager']->id),
            'a :manage holder without :viewall'
        );

        $this->assertFalse(
            teamaccess::may_open_team($this->activity, $alpha, (int) $this->users['guidebeta']->id),
            'a guide of a DIFFERENT team'
        );
        $this->assertFalse(
            teamaccess::may_open_team($this->activity, $alpha, (int) $this->users['outsider']->id),
            'an enrolled student in no team'
        );

        // The capability really is the key: take it away from the one
        // person it admits and the door shuts again.
        assign_capability(
            'mod/selfselectadvanced:viewassignedteams',
            CAP_PREVENT,
            $this->role_id('teacher'),
            \context_course::instance($this->course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(
            teamaccess::may_open_team($this->activity, $alpha, (int) $this->users['guidealpha']->id),
            'the assigned guide with :viewassignedteams PREVENTed'
        );
    }

    /**
     * 7. The three pages DELEGATE their gate to this class, and none of
     * them carries a copy of the predicate.
     *
     * The first cut of T-19 shipped the predicate inline on each page
     * and pinned it with a test that compared one transcription against
     * another - both in the test file. Deleting review.php's gate
     * entirely left that test green, because nothing in it ever read a
     * page. So the duplication is gone and the delegation itself is
     * asserted here, on the production source: re-inline any of the
     * three and this goes red before the behaviour tests are even
     * reached. The behaviour of each gate is exercised by the
     * may_open_team / may_review_team / may_drill_down tests below and,
     * through the real pages, by tests/behat/viewassignedteams.feature.
     */
    public function test_the_pages_delegate_their_gate_to_this_class(): void {
        $expected = [
            'group.php' => 'teamaccess::may_open_team(',
            'review.php' => 'teamaccess::may_review_team(',
            'eoilist.php' => 'teamaccess::may_drill_down(',
        ];
        foreach ($expected as $page => $call) {
            $this->assertStringContainsString(
                $call,
                $this->code_of($page),
                $page . ' no longer asks teamaccess - a copy of a predicate is a second answer to it'
            );
        }

        // The transcriptions that used to live here are gone: the
        // membership+capability composite exists in exactly one place.
        $this->assertStringContainsString('STATUS_INVITED', $this->code_of('classes/local/teamaccess.php'));
        $this->assertStringNotContainsString(
            'STATUS_INVITED',
            $this->code_of('group.php'),
            'group.php re-implements the membership half of its own gate'
        );
    }

    /**
     * One plugin file's source with every comment removed.
     *
     * Comments are stripped because they are not the code: the first
     * draft of the test above searched the raw file, and deleting
     * review.php's whole gate left it GREEN - the comment above the
     * gate still named the call. A check that a sentence about the code
     * exists is not a check that the code exists.
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

    /**
     * 8. A FORMING team carrying a guideid through an accepted interest
     * is reachable by that guide: the predicate tests the assignment,
     * never the state.
     */
    public function test_a_forming_team_with_a_preassigned_guide_is_reachable(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();
        $sink = $this->redirectMessages();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $forming = $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => (int) $this->users['outsider']->id,
            'name' => 'Gamma',
            'state' => state::FORMING,
        ]);
        // The generator's create_group() does not carry `listed`, and
        // eoi::express() refuses an unlisted team.
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $forming->id]);
        $eoiid = eoi::express(
            $this->activity,
            (int) $forming->id,
            (int) $this->users['guidealpha']->id,
            'I would like this one'
        );
        eoi::respond($this->activity, $eoiid, true, (int) $this->users['outsider']->id);

        $fresh = groups::get($this->activity, (int) $forming->id);
        $this->assertSame(state::FORMING, $fresh->state, 'accepting an interest must not move the state');
        $this->assertSame((int) $this->users['guidealpha']->id, (int) $fresh->guideid);
        $this->assertTrue(
            teamaccess::may_open_team($this->activity, $fresh, (int) $this->users['guidealpha']->id)
        );
        $sink->close();
    }

    /**
     * 9. The defect, named in one method: the freeze SERVICE would have
     * allowed exactly what the page refused.
     */
    public function test_the_freeze_service_would_have_allowed_what_the_page_refused(): void {
        $this->resetAfterTest();
        $this->build_world();
        $sink = $this->redirectMessages();

        $guidealpha = (int) $this->users['guidealpha']->id;
        $alpha = groups::get($this->activity, (int) $this->alpha->id);
        $context = $this->activity->context();

        // Today's predicate, transcribed: member OR :viewall.
        $todaysgate = has_capability('mod/selfselectadvanced:viewall', $context, $guidealpha);
        $this->assertFalse($todaysgate, 'the old gate would have refused the assigned guide');
        $this->assertTrue(teamaccess::may_open_team($this->activity, $alpha, $guidealpha));

        $frozen = freeze::freeze_group($this->activity, $alpha, $guidealpha);
        $this->assertSame(state::FROZEN, $frozen->state);
        $sink->close();
    }

    /**
     * 10. The group page's columns follow ASSIGNMENT, not the bare
     * :guide capability. Asserted on the exported structure, because a
     * has_capability() assertion here would examine nothing.
     */
    public function test_group_page_columns_follow_assignment_not_the_capability(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->build_world();

        $guidealpha = (int) $this->users['guidealpha']->id;
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:guide', $this->activity->context(), $guidealpha),
            'the bare capability really is held, so the assertions below are about assignment'
        );

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $this->activity->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $mobile = get_string('attrmobile', 'mod_selfselectadvanced');
        $department = get_string('attrdepartment', 'mod_selfselectadvanced');

        $betapage = new \mod_selfselectadvanced\output\group_page(
            new api($this->activity),
            groups::get($this->activity, (int) $this->beta->id),
            $guidealpha
        );
        $betahead = $this->head_labels($betapage->export_for_template($output));
        $this->assertNotContains($mobile, $betahead, 'another team\'s mobile column');
        $this->assertNotContains($department, $betahead, 'another team\'s composition dimensions');
        $this->assertSame(
            [get_string('firstname'), get_string('lastname')],
            $betahead,
            'a name is all an unassigned guide gets'
        );

        $alphapage = new \mod_selfselectadvanced\output\group_page(
            new api($this->activity),
            groups::get($this->activity, (int) $this->alpha->id),
            $guidealpha
        );
        $alphahead = $this->head_labels($alphapage->export_for_template($output));
        $this->assertContains($mobile, $alphahead, 'the guide\'s own team\'s mobile column');
        $this->assertContains($department, $alphahead, 'the guide\'s own team\'s dimensions');
    }

    /**
     * The roster column labels the group page exported.
     *
     * @param \stdClass $exported the export_for_template() result
     * @return string[] the header labels, in order
     */
    private function head_labels(\stdClass $exported): array {
        return array_map(static fn($head) => (string) $head['label'], $exported->rosterhead);
    }

    /**
     * 11. review.php refuses BEFORE it renders - and the disclosure pin:
     * the renderable still returns the other team's roster, so the
     * refusal is the only thing standing between them and the data.
     */
    public function test_review_page_is_refused_before_it_renders(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->build_world();

        $guidealpha = (int) $this->users['guidealpha']->id;
        $alpha = groups::get($this->activity, (int) $this->alpha->id);
        $beta = groups::get($this->activity, (int) $this->beta->id);

        $this->assertFalse(
            teamaccess::may_review_team($this->activity, $beta, $guidealpha),
            'a team they do not guide'
        );
        $this->assertTrue(
            teamaccess::may_review_team($this->activity, $alpha, $guidealpha),
            'their own team'
        );
        $this->assertTrue(
            teamaccess::may_review_team($this->activity, $beta, (int) $this->users['narrowmanager']->id),
            'a :manage holder without :viewall'
        );

        // The disclosure pin. Without the gate above, this is what the
        // unassigned guide read.
        $PAGE->set_url('/mod/selfselectadvanced/review.php', ['id' => $this->activity->cm()->id]);
        $output = $PAGE->get_renderer('core');
        $page = new \mod_selfselectadvanced\output\review_page(
            new api($this->activity),
            $beta,
            $guidealpha
        );
        $exported = $page->export_for_template($output);
        $this->assertNotEmpty($exported->roster, 'the renderable itself never refused anybody');
    }

    /**
     * 12. The coordinator role is assignable at ACTIVITY context only -
     * and a course-context row an administrator recorded BEFORE the
     * change still grants, is still listed and is still removable.
     */
    public function test_the_coordinator_role_is_assignable_at_activity_context_only(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();
        $sink = $this->redirectMessages();

        $roleid = coordinatorrole::ensure();
        // The shape every pre-1.20.1 site is in. Without it the role
        // already carries the right levels from db/install.php and
        // ensure() is never asked the question (negative control NC12,
        // run 2026-08-01, came back GREEN on the shape without this).
        set_role_contextlevels($roleid, [CONTEXT_COURSE, CONTEXT_MODULE]);
        $this->assertContains(
            CONTEXT_COURSE,
            array_map('intval', array_values(get_role_contextlevels($roleid))),
            'the pre-1.20.1 state was not reached'
        );

        coordinatorrole::ensure();
        $this->assertSame(
            [CONTEXT_MODULE],
            array_map('intval', array_values(get_role_contextlevels($roleid))),
            'the role is offered somewhere other than an activity'
        );

        $coursecontext = \context_course::instance($this->course->id);
        $this->setAdminUser();
        $this->assertArrayNotHasKey(
            $roleid,
            get_assignable_roles($coursecontext),
            'the course role-assign screen still offers it'
        );
        $this->assertArrayHasKey($roleid, get_assignable_roles($this->activity->context()));

        // The LEGACY half. A row recorded before the change, written
        // directly the way an administrator's own screen wrote it.
        $legacy = (int) $this->users['guidebeta']->id;
        role_assign($roleid, $legacy, $coursecontext->id);
        coordinatorrole::ensure();
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'userid' => $legacy,
            'contextid' => $coursecontext->id,
        ]), 'the upgrade deleted an assignment an administrator recorded');
        $this->assertTrue(has_capability(
            'mod/selfselectadvanced:coordinate',
            $this->activity->context(),
            $legacy
        ), 'a legacy course row stopped granting at the activity');
        // Still listed by the coordinators screen's own read.
        $listed = array_map('intval', array_keys(
            get_role_users($roleid, $this->activity->context(), true, 'u.id, u.firstname, u.lastname')
        ));
        $this->assertContains($legacy, $listed, 'the coordinators screen lost sight of a legacy holder');
        // Still removable from it.
        coordinatorimport::remove($this->activity, $legacy, (int) $this->users['narrowmanager']->id);
        $this->assertFalse($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'userid' => $legacy,
            'contextid' => $coursecontext->id,
        ]), 'a legacy holder could not be stood down');
        $sink->close();
    }

    /**
     * 13. Decision 19: a rejected or withdrawn applicant loses the team.
     * All five statuses plus the no-row case, driven through the
     * services that write them wherever one exists, so this also pins
     * that those paths write the statuses the predicate reads.
     */
    public function test_a_rejected_interest_loses_the_team(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();
        $sink = $this->redirectMessages();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leaderid = (int) $this->users['outsider']->id;
        $guideid = (int) $this->users['guidebeta']->id;

        // The generator's create_group() does not carry `listed`, and
        // eoi::express() refuses an unlisted team.
        $listed = function (string $name) use ($plugingen, $leaderid, $DB): \stdClass {
            $group = $plugingen->create_group([
                'activityid' => $this->activity->id(),
                'leaderid' => $leaderid,
                'name' => $name,
                'state' => state::FORMING,
            ]);
            $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $group->id]);

            return $group;
        };

        // No row at all.
        $none = $listed('NoInterest');
        $this->assertFalse(
            teamaccess::may_drill_down($this->activity, (int) $none->id, $guideid),
            'no interest'
        );

        // PENDING - the decision still being made.
        $pendinggroup = $listed('Pending');
        eoi::express($this->activity, (int) $pendinggroup->id, $guideid);
        $this->assertTrue(
            teamaccess::may_drill_down($this->activity, (int) $pendinggroup->id, $guideid),
            'pending'
        );

        // ACCEPTED - through the service, which is also what sets guideid.
        $acceptedgroup = $listed('Accepted');
        $acceptedid = eoi::express($this->activity, (int) $acceptedgroup->id, $guideid);
        eoi::respond($this->activity, $acceptedid, true, $leaderid);
        $this->assertSame(
            eoi::STATUS_ACCEPTED,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $acceptedid])
        );
        $this->assertTrue(
            teamaccess::may_drill_down($this->activity, (int) $acceptedgroup->id, $guideid),
            'accepted, and still the assigned guide'
        );

        // REJECTED - through the service.
        $rejectedgroup = $listed('Rejected');
        $rejectedid = eoi::express($this->activity, (int) $rejectedgroup->id, $guideid);
        eoi::respond($this->activity, $rejectedid, false, $leaderid);
        $this->assertSame(
            eoi::STATUS_REJECTED,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $rejectedid])
        );
        $this->assertFalse(
            teamaccess::may_drill_down($this->activity, (int) $rejectedgroup->id, $guideid),
            'rejected'
        );

        // WITHDRAWN - through the service. It sets a status and does not
        // delete the row, so the status test is the whole mechanism.
        $withdrawngroup = $listed('Withdrawn');
        $withdrawnid = eoi::express($this->activity, (int) $withdrawngroup->id, $guideid);
        eoi::withdraw($this->activity, $withdrawnid, $guideid);
        $this->assertTrue($DB->record_exists('selfselectadvanced_eoi', ['id' => $withdrawnid]));
        $this->assertSame(
            eoi::STATUS_WITHDRAWN,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $withdrawnid])
        );
        $this->assertFalse(
            teamaccess::may_drill_down($this->activity, (int) $withdrawngroup->id, $guideid),
            'withdrawn'
        );

        // EXPIRED - through the sweep. Excluded by INFERENCE (T-19 step
        // 9): decision 19 names rejected and withdrawn, and an interest
        // nobody answered is by construction not live. If the maintainer
        // reverses that, this is the assertion that moves.
        $expiredgroup = $listed('Expired');
        $expiredid = eoi::express($this->activity, (int) $expiredgroup->id, $guideid);
        $DB->set_field(
            'selfselectadvanced_eoi',
            'timecreated',
            time() - (2 * (int) $this->activity->settings()->eoiwindow),
            ['id' => $expiredid]
        );
        $this->assertSame(1, eoi::expire_due($this->activity));
        $this->assertSame(
            eoi::STATUS_EXPIRED,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $expiredid])
        );
        $this->assertFalse(
            teamaccess::may_drill_down($this->activity, (int) $expiredgroup->id, $guideid),
            'expired'
        );
        $sink->close();
    }

    /**
     * A FIRM team this guide reached through an ACCEPTED interest.
     *
     * The interest is expressed and answered through the services that
     * write those statuses, because the predicate under test reads them;
     * only the state is set directly, since eoi::respond() leaves a team
     * FORMING and handover applies from PENDING_GUIDE onwards.
     *
     * @param string $name the team name
     * @param string $guidehandle the guide who expresses the interest
     * @return \stdClass the fresh group row
     */
    private function team_with_an_accepted_interest(string $name, string $guidehandle): \stdClass {
        global $DB;

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leaderid = (int) $this->users['outsider']->id;
        $group = $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => $leaderid,
            'name' => $name,
            'state' => state::FORMING,
        ]);
        // The generator's create_group() does not carry `listed`, and
        // eoi::express() refuses an unlisted team.
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $group->id]);
        $eoiid = eoi::express($this->activity, (int) $group->id, (int) $this->users[$guidehandle]->id);
        eoi::respond($this->activity, $eoiid, true, $leaderid);
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time() - DAYSECS, ['id' => $group->id]);

        $fresh = groups::get($this->activity, (int) $group->id);
        $this->assertSame((int) $this->users[$guidehandle]->id, (int) $fresh->guideid);
        $this->assertTrue($DB->record_exists('selfselectadvanced_eoi', [
            'groupid' => (int) $group->id,
            'guideid' => (int) $this->users[$guidehandle]->id,
            'status' => eoi::STATUS_ACCEPTED,
        ]));

        return $fresh;
    }

    /**
     * 14. DECISION 20, state one: while a handover is PENDING, the
     * outgoing guide still sees the team's member names.
     *
     * The handover workflow is the only definition of "in progress"
     * this predicate uses - handover::propose() leaves guideid on the
     * proposer, so "still the assigned guide" IS "the handover has not
     * completed", and no second notion can drift away from the first.
     *
     * Negative control (RUN): drop the `g.guideid = :assigned` clause
     * and this test still passes while 15 and 16 go green wrongly -
     * which is why all three states are asserted, and why the Behat
     * feature drives the same three through the page.
     */
    public function test_decision_20_a_pending_handover_keeps_the_outgoing_guide_looking(): void {
        $this->resetAfterTest();
        $this->build_world();
        $sink = $this->redirectMessages();

        $outgoing = (int) $this->users['guidealpha']->id;
        $team = $this->team_with_an_accepted_interest('Handover', 'guidealpha');
        $this->assertTrue(
            teamaccess::may_drill_down($this->activity, (int) $team->id, $outgoing),
            'the assigned guide, before anything is proposed'
        );

        (new api($this->activity))->handover()->propose(
            (int) $team->id,
            (int) $this->users['guidebeta']->id,
            $outgoing
        );

        $pending = groups::get($this->activity, (int) $team->id);
        $this->assertSame((int) $this->users['guidebeta']->id, (int) $pending->guidesuccessorid);
        $this->assertSame($outgoing, (int) $pending->guideid, 'propose() must not move the team yet');
        $this->assertTrue(
            teamaccess::may_drill_down($this->activity, (int) $team->id, $outgoing),
            'the outgoing guide lost the team before the handover completed'
        );
        $sink->close();
    }

    /**
     * 15. DECISION 20, state two: acceptance completes the handover, and
     * the outgoing guide loses the drill-down in the same instant - with
     * their EOI row still reading 'accepted', which is exactly why the
     * status alone was never enough.
     *
     * Negative control (RUN): drop the `g.guideid = :assigned` clause
     * from teamaccess::may_drill_down() - the outgoing guide keeps the
     * roster for good and this goes red.
     */
    public function test_decision_20_an_accepted_handover_ends_the_outgoing_guides_sight(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();
        $sink = $this->redirectMessages();

        $outgoing = (int) $this->users['guidealpha']->id;
        $incoming = (int) $this->users['guidebeta']->id;
        $team = $this->team_with_an_accepted_interest('Handover', 'guidealpha');
        $api = new api($this->activity);
        $api->handover()->propose((int) $team->id, $incoming, $outgoing);
        $api->handover()->accept((int) $team->id, $incoming);

        $after = groups::get($this->activity, (int) $team->id);
        $this->assertSame($incoming, (int) $after->guideid);
        $this->assertEmpty($after->guidesuccessorid);
        // The interest is untouched by the handover: 'accepted' still.
        $this->assertTrue($DB->record_exists('selfselectadvanced_eoi', [
            'groupid' => (int) $team->id,
            'guideid' => $outgoing,
            'status' => eoi::STATUS_ACCEPTED,
        ]), 'the fixture stopped testing what it was built to test');
        $this->assertFalse(
            teamaccess::may_drill_down($this->activity, (int) $team->id, $outgoing),
            'the outgoing guide kept the roster after the handover completed'
        );
        // And the incoming guide has no interest of their own, so this
        // door stays shut for them too - they reach the team through
        // group.php and review.php instead.
        $this->assertFalse(teamaccess::may_drill_down($this->activity, (int) $team->id, $incoming));
        $this->assertTrue(teamaccess::may_open_team($this->activity, $after, $incoming));
        $sink->close();
    }

    /**
     * 16. DECISION 20, state three - the one most easily missed: staff
     * REASSIGN the team, and there is no handover record at all.
     *
     * state::assign_guide() writes the same guideid column, so the
     * access ends the moment the reassignment commits. Nothing here
     * depends on a handover row existing, which is the point: a second
     * "in progress" flag would have had nothing to say about this path.
     *
     * Negative control (RUN): drop the `g.guideid = :assigned` clause -
     * the displaced guide keeps the drill-down and this goes red.
     */
    public function test_decision_20_a_reassignment_with_no_handover_record_ends_it_at_once(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();
        $sink = $this->redirectMessages();

        $displaced = (int) $this->users['guidealpha']->id;
        $team = $this->team_with_an_accepted_interest('Reassigned', 'guidealpha');
        $this->assertTrue(teamaccess::may_drill_down($this->activity, (int) $team->id, $displaced));
        $this->assertEmpty(
            $DB->get_field('selfselectadvanced_group', 'guidesuccessorid', ['id' => (int) $team->id]),
            'this path must carry NO handover record'
        );

        (new api($this->activity))->lifecycle()->assign_guide(
            $team,
            (int) $this->users['guidebeta']->id,
            (int) $this->users['narrowmanager']->id
        );

        $after = groups::get($this->activity, (int) $team->id);
        $this->assertSame((int) $this->users['guidebeta']->id, (int) $after->guideid);
        $this->assertTrue($DB->record_exists('selfselectadvanced_eoi', [
            'groupid' => (int) $team->id,
            'guideid' => $displaced,
            'status' => eoi::STATUS_ACCEPTED,
        ]), 'a reassignment leaves the old interest exactly where it was');
        $this->assertFalse(
            teamaccess::may_drill_down($this->activity, (int) $team->id, $displaced),
            'a guide the staff displaced kept the team roster'
        );
        $sink->close();
    }

    /**
     * 17. The :manage-only viewer is admitted to the team page and gets
     * NAMES - a recorded decision, not an oversight.
     *
     * Step 4 widened group.php's door so the eight manager-only actions
     * on that page are reachable without :viewall. It did not widen the
     * window: $showmobilecol still asks :viewall, the assigned guide or
     * confirmed membership, so a viewer whose only authority is :manage
     * sees the roster's names and neither the composition dimensions
     * nor the mobile column. The cardinal rule narrows; a manager who
     * needs those columns holds :viewall on every shipped role that has
     * :manage. Asserted on the exported renderable - the real page's
     * own data - because a has_capability() assertion would examine
     * nothing.
     */
    public function test_a_manage_only_viewer_is_admitted_and_gets_names_only(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->build_world();

        $narrow = (int) $this->users['narrowmanager']->id;
        $alpha = groups::get($this->activity, (int) $this->alpha->id);
        $this->assertTrue(
            teamaccess::may_open_team($this->activity, $alpha, $narrow),
            'the door this decision is about is shut, so the assertion below examines nothing'
        );
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:viewall', $this->activity->context(), $narrow),
            'the fixture stopped being a :manage-ONLY viewer'
        );

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $this->activity->cm()->id]);
        $output = $PAGE->get_renderer('core');
        $page = new \mod_selfselectadvanced\output\group_page(
            new api($this->activity),
            $alpha,
            $narrow
        );
        $this->assertSame(
            [get_string('firstname'), get_string('lastname')],
            $this->head_labels($page->export_for_template($output)),
            'a manage-only viewer was given more of the roster than names'
        );
    }
}
