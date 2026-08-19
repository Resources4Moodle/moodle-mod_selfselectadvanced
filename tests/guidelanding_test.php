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
use mod_selfselectadvanced\local\state;

/**
 * The guide's own landing page (1.20.6 item A).
 *
 * The maintainer's finding: a non-editing teacher - the guide role -
 * landing on the activity saw a student-shaped page and none of their
 * own work. The 1.20.5 independent review found the same screen broken
 * from the other side (NAV-02): the "Joining another group" button was
 * drawn for every viewer, while joinrequest.php admits only :respond,
 * :manage or :coordinate, so every stock guide on the live site was
 * offered a button that could only end at a permission exception.
 *
 * These tests drive the real exporters - the same export_for_template()
 * calls view.php makes - and, where the point is what a human sees,
 * render the real templates. Nothing here restates has_capability():
 * the join predicate comes out of the production export, which calls
 * authority::may_join_requests(), and the panel's figures come out of
 * the production seams (guideload_table::export_rows(),
 * eoi::guide_commitments(), resolver::effective_maxguided()).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\output\guide_panel
 * @covers     \mod_selfselectadvanced\output\landing
 * @covers     \mod_selfselectadvanced\local\authority::may_join_requests
 * @covers     \mod_selfselectadvanced\local\authority::require_join_requests
 */
final class guidelanding_test extends \advanced_testcase {
    /** @var \stdClass The course every fixture actor is enrolled in. */
    private \stdClass $course;

    /** @var activity The activity under test. */
    private activity $activity;

    /** @var api The facade for that activity. */
    private api $api;

    /** @var \stdClass The guide whose work the panel is about. */
    private \stdClass $guide;

    /** @var \stdClass A second guide, who guides nothing. */
    private \stdClass $otherguide;

    /** @var \stdClass A student. */
    private \stdClass $student;

    /** @var \stdClass An editing teacher: trusted staff, holds :manage. */
    private \stdClass $editingteacher;

    /** @var \stdClass A coordinator: a non-editing teacher who also holds :coordinate. */
    private \stdClass $coordinator;

    /** @var \stdClass Team A - pending, its deadline already passed. */
    private \stdClass $teama;

    /** @var \stdClass Team B - pending, its deadline still to come. */
    private \stdClass $teamb;

    /** @var \stdClass The confirmed member of Team A, whose contact details nobody may read here. */
    private \stdClass $member;

    /** @var int The activity's guide decision window, in seconds. */
    private const WINDOW = 7200;

    /**
     * One activity, one guide, and four teams whose figures are all
     * different from each other on purpose.
     *
     * used = 4 (every commitment), awaiting = 2 (only pending_guide),
     * overdue = 1 (only Team A), next = Team B's deadline. No single
     * wrong source can satisfy all four assertions at once.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 2,
            'maxmembership' => 2,
            'maxguided' => 5,
            'guidewindow' => self::WINDOW,
            'guideautoapprove' => 0,
            'studentapproach' => 1,
        ]);
        $this->activity = activity::from_instance((int) $instance->id);
        $this->api = new api($this->activity);

        $this->guide = $this->person('teacher');
        $this->otherguide = $this->person('teacher');
        $this->student = $this->person('student');
        $this->editingteacher = $this->person('editingteacher');

        // A coordinator is a non-editing teacher with :coordinate
        // granted at the activity context - the only eligible role
        // (coordinator eligibility is keyed on the archetype, never on
        // a shortname a site may have renamed).
        $this->coordinator = $this->person('teacher');
        $roleid = $generator->create_role();
        assign_capability(
            'mod/selfselectadvanced:coordinate',
            CAP_ALLOW,
            $roleid,
            $this->activity->context()->id,
            true
        );
        role_assign($roleid, $this->coordinator->id, $this->activity->context()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->member = $this->person('student', 'privateprobe@example.com');
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $plugingen->create_userattr([
            'userid' => $this->member->id,
            'mobile' => '9876500011',
        ]);

        $now = time();
        $this->teama = $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => $this->student->id,
            'name' => 'Team A',
            'guideid' => $this->guide->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => $now - 3 * HOURSECS,
        ]);
        $plugingen->create_member([
            'groupid' => $this->teama->id,
            'userid' => $this->member->id,
            'status' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
        ]);
        $this->teamb = $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => $this->student->id,
            'name' => 'Team B',
            'guideid' => $this->guide->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => $now - 600,
        ]);
        $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => $this->student->id,
            'name' => 'Team C',
            'guideid' => $this->guide->id,
            'state' => state::FIRM,
        ]);
        $plugingen->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => $this->student->id,
            'name' => 'Team D',
            'guideid' => $this->guide->id,
            'state' => state::FORMING,
        ]);
    }

    /**
     * An enrolled person.
     *
     * @param string $shortname the enrolment role shortname
     * @param string|null $email an explicit email address
     * @return \stdClass the user
     */
    private function person(string $shortname, ?string $email = null): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user($email === null ? [] : ['email' => $email]);
        $generator->enrol_user($user->id, $this->course->id, $shortname);

        return $user;
    }

    /**
     * Export the real guide panel for one viewer.
     *
     * @param int $userid the viewer
     * @param api|null $api the facade, defaulting to the fixture's
     * @return \stdClass the template context
     */
    private function panel(int $userid, ?api $api = null): \stdClass {
        global $PAGE;

        $api = $api ?? $this->api;
        $PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $api->activity()->cm()->id]);

        return (new \mod_selfselectadvanced\output\guide_panel($api, $userid))
            ->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Export the real landing page for one viewer.
     *
     * @param int $userid the viewer
     * @return \stdClass the template context
     */
    private function landing(int $userid): \stdClass {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $this->activity->cm()->id]);

        return (new \mod_selfselectadvanced\output\landing($this->api, $userid))
            ->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Render the real landing template for one viewer.
     *
     * @param int $userid the viewer
     * @return string the HTML
     */
    private function landinghtml(int $userid): string {
        global $PAGE;

        $data = $this->landing($userid);

        return $PAGE->get_renderer('core')->render_from_template('mod_selfselectadvanced/landing', $data);
    }

    /**
     * The panel counts the guide's OWN work, and counts each thing from
     * the source that thing actually has.
     *
     * The four figures are deliberately all different: load counts every
     * commitment (4), only pending_guide teams are decisions (2), only
     * one of those is overdue, and "next" is the next deadline STILL TO
     * COME, so an already-passed deadline can never answer it.
     *
     * MUTATION CAUGHT (run): dropping the `state !== PENDING_GUIDE`
     * continue in guide_panel::export_for_template() so every
     * commitment counts as a decision gives awaitingcount 4 and
     * overduecount 1 with nextdeadline unchanged - "Failed asserting
     * that 4 is identical to 2".
     * MUTATION CAUGHT (run): letting overdue rows compete for $next
     * (removing the `continue` after $overdue++) makes nextdeadline
     * Team A's passed deadline - "Failed asserting that 1754384400 is
     * identical to 1754393000" (the two timestamps of the fixture).
     */
    public function test_the_panel_counts_the_guides_own_work(): void {
        $panel = $this->panel((int) $this->guide->id);

        $this->assertSame(4, $panel->used, 'the load figure must count every commitment, not just decisions');
        $this->assertSame(5, $panel->max, 'the cap must be the effective maxguided');
        $this->assertSame(2, $panel->awaitingcount, 'only pending_guide teams are decisions waiting on the guide');
        $this->assertSame(1, $panel->overduecount);
        $this->assertSame(
            (int) $this->teamb->timesubmitted + self::WINDOW,
            $panel->nextdeadline,
            'the next deadline must be the next one still to come, never the one already passed'
        );
        $this->assertTrue($panel->hasawaiting);
        $this->assertTrue($panel->hasoverdue);
        $this->assertSame(
            get_string('guideloadheader', 'mod_selfselectadvanced', (object) ['used' => 4, 'max' => 5]),
            $panel->loadline,
            'the landing load line and the dashboard load line must be one line'
        );

        // The teams named are the two decisions, in name order, and
        // Team A is the one flagged.
        $this->assertSame(['Team A', 'Team B'], array_column($panel->awaiting, 'name'));
        $this->assertTrue($panel->awaiting[0]->isoverdue);
        $this->assertFalse($panel->awaiting[1]->isoverdue);
    }

    /**
     * The window is stated as a RULE, so the panel is never empty.
     *
     * guidewindow exists only as a derived per-team deadline
     * (timesubmitted + guidewindow), so in a brand-new activity there is
     * no deadline in existence to draw. The panel must still say what
     * the rule is, and the three modes of the setting must read
     * differently from one another - "you have 2 hours", "you have 2
     * hours or it is counted as accepted", and "there is no time
     * limit" are three different promises to a guide.
     *
     * MUTATION CAUGHT (run): returning '' from
     * guide_panel::window_policy() when nothing is pending - the
     * "an empty panel is fine when there is nothing to show" answer -
     * gives "Failed asserting that two strings are not identical"
     * against the empty string, and the three-mode comparison collapses
     * as well.
     * MUTATION CAUGHT (run): returning the same string for all three
     * modes (ignoring $autoapprove and the zero window) gives "Failed
     * asserting that two strings are not identical" on the auto pair.
     */
    public function test_the_panel_states_the_window_before_any_team_submits(): void {
        global $DB;

        $second = $this->getDataGenerator()->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'maxguided' => 5,
            'guidewindow' => self::WINDOW,
            'guideautoapprove' => 0,
        ]);
        $fresh = activity::from_instance((int) $second->id);
        $api = new api($fresh);

        $panel = $this->panel((int) $this->guide->id, $api);
        $this->assertFalse($panel->hasawaiting, 'fixture: the second activity must have no teams at all');
        $this->assertSame(0, $panel->awaitingcount);
        $this->assertSame(0, $panel->nextdeadline);
        $this->assertSame(0, $panel->used);
        $this->assertNotSame('', $panel->nothingline);

        $plain = $panel->windowpolicy;
        $this->assertNotSame('', $plain, 'the panel must state the rule even with nothing submitted');
        $this->assertSame(
            get_string('guidepanelwindow', 'mod_selfselectadvanced', format_time(self::WINDOW)),
            $plain
        );

        $DB->set_field('selfselectadvanced', 'guideautoapprove', 1, ['id' => (int) $second->id]);
        $auto = $this->panel((int) $this->guide->id, new api(activity::from_instance((int) $second->id)))
            ->windowpolicy;
        $this->assertSame(
            get_string('guidepanelwindowauto', 'mod_selfselectadvanced', format_time(self::WINDOW)),
            $auto
        );

        $DB->set_field('selfselectadvanced', 'guidewindow', 0, ['id' => (int) $second->id]);
        $none = $this->panel((int) $this->guide->id, new api(activity::from_instance((int) $second->id)))
            ->windowpolicy;
        $this->assertSame(get_string('guidepanelwindownone', 'mod_selfselectadvanced'), $none);

        $this->assertNotSame($plain, $auto);
        $this->assertNotSame($plain, $none);
        $this->assertNotSame($auto, $none);
    }

    /**
     * CONTACT PRIVACY: the panel names teams and reads no person, and
     * it names only the teams that are this guide's own.
     *
     * Every value the panel prints is a team name, a plugin uid, a
     * state, a date or a count - it calls no identity function at all -
     * so no member's email address or mobile number can reach it.
     * Connections are not a special case here because nothing about a
     * person is shown to anybody.
     *
     * Both guides are given a pending team of their own, so both
     * panels really render a row list and neither half of this test is
     * satisfied by an empty panel.
     *
     * MUTATION CAUGHT (run): adding the awaiting team's leader as
     * `fullname($leader) . ' ' . $leader->email` to each row in
     * guide_panel::export_for_template() and printing it in
     * guide_panel.mustache gives "Failed asserting that '<the rendered
     * landing>' does not contain" the fixture member's email address,
     * for the guide who guides that team.
     * MUTATION CAUGHT (run): dropping `g.guideid = :guideid` from
     * guideload_table::sql_parts(), so the panel lists every guide's
     * teams, gives "Team Z, which this guide does not guide, appeared
     * on their landing page".
     */
    public function test_the_panel_shows_a_guide_no_participant_field(): void {
        global $DB;

        // The person whose details must not leak is made the LEADER of
        // the team the guide is shown, which is the row the panel
        // describes - so a leak of any identity field on that row is a
        // leak of theirs.
        $DB->set_field('selfselectadvanced_group', 'leaderid', (int) $this->member->id, ['id' => $this->teama->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $this->activity->id(),
            'leaderid' => $this->student->id,
            'name' => 'Team Z',
            'guideid' => $this->otherguide->id,
            'state' => state::PENDING_GUIDE,
            'timesubmitted' => time() - 600,
        ]);

        $guidehtml = $this->landinghtml((int) $this->guide->id);
        $this->assertStringContainsString(
            'Team A',
            $guidehtml,
            'fixture: the guide must actually be shown the team, or this proves nothing'
        );
        $this->assertStringNotContainsString(
            'Team Z',
            $guidehtml,
            "Team Z, which this guide does not guide, appeared on their landing page"
        );

        $otherhtml = $this->landinghtml((int) $this->otherguide->id);
        $this->assertStringContainsString(
            'Team Z',
            $otherhtml,
            'fixture: the second guide must be shown their own team, or their half proves nothing'
        );
        $this->assertStringNotContainsString(
            'Team A',
            $otherhtml,
            'Team A, which this guide does not guide, appeared on their landing page'
        );

        foreach (['guide' => $guidehtml, 'other guide' => $otherhtml] as $who => $html) {
            $this->assertStringContainsString('selfselectadvanced-guidepanel', $html);
            $this->assertStringNotContainsString(
                'privateprobe@example.com',
                $html,
                'the ' . $who . ' was shown a participant email address on the landing page'
            );
            $this->assertStringNotContainsString(
                '9876500011',
                $html,
                'the ' . $who . ' was shown a participant mobile number on the landing page'
            );
        }
    }

    /**
     * The student-addressed notice goes only where the guide-addressed
     * statement takes its place.
     *
     * The predicate is "is this viewer being given the guide's own
     * decision rule instead?", not "is this viewer a student". An
     * editing teacher and a manager hold no :guide, get no panel, and
     * therefore keep the notice they have today - nothing is subtracted
     * from them. A coordinator holds :guide, so per the maintainer they
     * are guide-side: panel, window policy, and their own coordinator
     * button untouched.
     *
     * MUTATION CAUGHT (run): gating on $data->isstudent instead of
     * !$isguide in landing::export_for_template() takes the notice away
     * from the editing teacher, who is given nothing in its place -
     * "the notice was taken from an editing teacher, who gets no guide
     * panel in its place".
     */
    public function test_the_student_notice_is_replaced_only_for_the_guide(): void {
        $student = $this->landing((int) $this->student->id);
        $this->assertNotSame('', $student->studentapproachnotice, 'a student must still be told the ground rules');
        $this->assertFalse($student->showguidepanel);

        $teacher = $this->landing((int) $this->editingteacher->id);
        $this->assertNotSame(
            '',
            $teacher->studentapproachnotice,
            'the notice was taken from an editing teacher, who gets no guide panel in its place'
        );
        $this->assertFalse($teacher->showguidepanel);

        $guide = $this->landing((int) $this->guide->id);
        $this->assertSame('', $guide->studentapproachnotice, 'the guide is still addressed as a student');
        $this->assertTrue($guide->showguidepanel);
        $this->assertNotNull($guide->guidepanel);
        $this->assertNotSame('', $guide->guidepanel->windowpolicy);

        $coordinator = $this->landing((int) $this->coordinator->id);
        $this->assertSame('', $coordinator->studentapproachnotice);
        $this->assertTrue($coordinator->showguidepanel);
        $this->assertTrue($coordinator->iscoordinator, 'the coordinator lost their own button');
    }

    /**
     * The Join button and the join page's door are one predicate.
     *
     * NAV-02: a stock guide holds :guide, :freeze and
     * :viewassignedteams and no :respond, so the button the landing
     * drew for them could only ever end at a required_capability
     * exception. Both halves of the one function are asserted here -
     * the offer the button asks, and the refusal the page performs -
     * because that pairing is the whole point of moving the question
     * into authority.
     *
     * MUTATION CAUGHT (run): dropping the COORDINATE clause from
     * authority::may_join_requests() gives "the coordinator, who
     * answers for absent leaders, lost the join page" - and the same
     * mutation transcribed into the landing as !$isguide fails on the
     * same viewer, because a coordinator IS a guide.
     */
    public function test_the_join_button_asks_the_join_page_gate(): void {
        $expected = [
            'student' => [(int) $this->student->id, true],
            'editing teacher' => [(int) $this->editingteacher->id, true],
            'coordinator' => [(int) $this->coordinator->id, true],
            'guide' => [(int) $this->guide->id, false],
        ];
        foreach ($expected as $who => [$userid, $may]) {
            $this->assertSame(
                $may,
                authority::may_join_requests($this->activity, $userid),
                'may_join_requests() disagreed for the ' . $who
            );
            $this->assertSame(
                $may,
                $this->landing($userid)->showjoinlink,
                'the landing button disagreed with may_join_requests() for the ' . $who
            );
        }

        $this->assertStringContainsString(
            'joinrequest.php',
            $this->landinghtml((int) $this->student->id),
            'fixture: a student must be offered the join page'
        );
        $this->assertStringNotContainsString(
            'joinrequest.php',
            $this->landinghtml((int) $this->guide->id),
            'a stock guide was still offered the join page, which refuses them'
        );

        // The other half of the same function: what the page itself
        // does to a viewer the button is no longer offered to.
        $this->prohibit('mod/selfselectadvanced:respond', 'student');
        $this->assertFalse(authority::may_join_requests($this->activity, (int) $this->student->id));
        $this->assertFalse($this->landing((int) $this->student->id)->showjoinlink);
        try {
            authority::require_join_requests($this->activity, (int) $this->student->id);
            $this->fail('the join page admitted a student whose :respond is prohibited');
        } catch (\required_capability_exception $e) {
            $this->assertSame(
                get_capability_string('mod/selfselectadvanced:respond'),
                $e->a,
                'the refusal must still name :respond, as it did before 1.20.6'
            );
        }
    }

    /**
     * Exactly one "Guide dashboard" link on the landing page.
     *
     * Six Behat steps follow that link from here
     * (pickteam.feature:34,53,73,84 and guide_review.feature:53,73) and
     * "I follow" takes the FIRST match, so a duplicate would not fail
     * loudly - it would silently pass while the panel's own button was
     * never exercised. This is that mistake failing in two seconds
     * instead of in a seven-minute Behat run.
     *
     * MUTATION CAUGHT (run): restoring the old {{#isguide}} dashboard
     * link block in landing.mustache alongside the new partial gives
     * "Failed asserting that 2 is identical to 1".
     */
    public function test_the_landing_offers_exactly_one_guide_dashboard_link(): void {
        $html = $this->landinghtml((int) $this->guide->id);
        $needle = '>' . get_string('guidedashboard', 'mod_selfselectadvanced') . '<';

        $this->assertSame(
            1,
            substr_count($html, $needle),
            'the landing page must carry exactly one "Guide dashboard" anchor'
        );
        $this->assertStringContainsString('guide.php', $html);
    }

    /**
     * The words the two new Behat scenarios look for are really on the
     * guide's rendered page.
     *
     * guide_review.feature's "A guide lands on their own work" and
     * studentapproach.feature's "The guide's landing states the
     * decision rule" assert these exact texts. Pinning them here means
     * a renamed lang key or a partial that stopped being included fails
     * in two seconds rather than in a seven-minute Behat run - the same
     * bargain the dashboard-link count above makes.
     *
     * MUTATION CAUGHT (run): changing $string['guidepanelheading'] to
     * 'Guide work summary' gives "Failed asserting that '<the rendered
     * landing>' contains "Your guide work"".
     */
    public function test_the_guides_rendered_landing_carries_the_panel_texts(): void {
        $html = $this->landinghtml((int) $this->guide->id);

        $this->assertStringContainsString('Your guide work', $html);
        $this->assertStringContainsString('You are guiding 4 of 5 groups', $html);
        $this->assertStringContainsString('(overdue)', $html, 'the overdue team must be marked as such');
        $this->assertStringNotContainsString(
            'Joining another group',
            $html,
            'a stock guide was still offered the join page by name'
        );
        $this->assertStringNotContainsString(
            'Guides do not advertise availability here',
            $html,
            'the guide is still addressed as a student'
        );
        $this->assertStringContainsString(
            'Once a group submits to you, you have 2 hours to approve or return it.',
            $html
        );

        // And the student, on the same activity, still reads the notice
        // that this panel replaces - the other half of the pair the two
        // Behat scenarios make between them.
        $this->assertStringContainsString(
            'Guides do not advertise availability here',
            $this->landinghtml((int) $this->student->id)
        );
    }

    /**
     * 1.20.53 deliverable B, the "guide" bullet: a guide's own filed
     * tickets reach them through the SAME "My requests" panel every
     * other requester gets - hasmyrequests is drawn unconditionally on
     * the landing page, not only inside the student area, so bullet 1
     * ("requester with any ticket") already covers a guide without a
     * second mechanism built inside the guide panel itself (spec:
     * "nothing more").
     */
    public function test_the_guide_sees_their_own_requests_through_the_shared_panel(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();

        $this->redirectMessages();
        // Team A is PENDING_GUIDE, which TYPE_DATES admits (guide,
        // pending_guide/firm/frozen) - filed by the same guide this
        // whole fixture is built around.
        \mod_selfselectadvanced\local\tickets::file(
            $this->activity,
            $this->teama,
            \mod_selfselectadvanced\local\tickets::TYPE_DATES,
            'Need two more days',
            FORMAT_PLAIN,
            (int) $this->guide->id
        );

        $data = $this->landing((int) $this->guide->id);
        $this->assertTrue($data->hasmyrequests, 'the guide filed a ticket, so the shared panel must offer it');
        $this->assertSame(
            get_string('myrequestscount', 'mod_selfselectadvanced', 1),
            $data->myrequestslabel
        );

        // A guide who filed nothing gets no panel - the button follows
        // the row, not the role, exactly as it does for a student.
        $otherdata = $this->landing((int) $this->otherguide->id);
        $this->assertFalse($otherdata->hasmyrequests);
    }

    /**
     * Prohibit a capability for a role at the activity context.
     *
     * @param string $capability the capability
     * @param string $shortname the role shortname
     */
    private function prohibit(string $capability, string $shortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        role_change_permission($roleid, $this->activity->context(), $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }
}
