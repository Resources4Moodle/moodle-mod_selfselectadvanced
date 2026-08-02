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

use mod_selfselectadvanced\local\contacts;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\teamaccess;

/**
 * Authorisation for proposal DELIVERY (audit A-05 / O-1).
 *
 * A team's proposal is the one piece of student work this plugin
 * stores, and until 1.20.1 nothing tested who could fetch it. The file
 * server carried its own transcription of the access rule - ":viewall
 * OR confirmed member OR (guideid AND :guide)" - which had drifted from
 * the pages in both directions: an assigned guide on a site that
 * withdrew :viewassignedteams was refused every other door on their own
 * team and still passed the file server, while a :manage-only reviewer,
 * admitted by may_review_team() to a page that EMBEDS the proposal, was
 * refused the file that page had just linked.
 *
 * Every case here goes through selfselectadvanced_pluginfile() - the
 * production entry point Moodle itself calls, sesskey-less and
 * reachable by pasting a URL - and not through the predicate alone. A
 * refusal returns false, which is how Moodle's file router renders
 * "file not found"; an admission runs the gate, the activity
 * cross-check, the file lookup and the real send_stored_file() and
 * comes back true. See fetch() for how a real send is made to return.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\teamaccess::may_read_proposal
 * @covers     ::selfselectadvanced_pluginfile
 */
final class proposalaccess_test extends \advanced_testcase {
    /** @var string What the uploaded proposal contains. */
    private const BODY = 'our approach, in full';

    /** @var \stdClass|null The course. */
    private ?\stdClass $course = null;

    /** @var activity|null The activity under test. */
    private ?activity $activity = null;

    /** @var array<string, \stdClass> The cast, by handle. */
    private array $users = [];

    /** @var \stdClass|null Team Alpha, guided by guidealpha. */
    private ?\stdClass $alpha = null;

    /** @var string The stored proposal's content hash, for the ETag. */
    private string $contenthash = '';

    /**
     * Course, activity, a team with a confirmed member and an invitee,
     * its assigned guide, an unrelated guide, an approached guide, a
     * :manage-only actor, a :viewall-only actor and an outsider - plus
     * a real file in the proposal filearea.
     */
    private function build_world(): void {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->course = $generator->create_course(['shortname' => 'PROP1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'minsize' => 1,
            'maxsize' => 5,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 5,
            'contactmax' => 3,
        ]);
        $this->activity = activity::from_instance((int) $instance->id);
        $context = $this->activity->context();

        foreach (['guidealpha', 'guideother', 'guideasked'] as $handle) {
            $this->users[$handle] = $generator->create_user(['username' => $handle]);
            $generator->enrol_user($this->users[$handle]->id, $this->course->id, 'teacher');
        }
        foreach (['leader', 'member', 'invitee', 'outsider'] as $handle) {
            $this->users[$handle] = $generator->create_user(['username' => $handle]);
            $generator->enrol_user($this->users[$handle]->id, $this->course->id, 'student');
        }

        // Two actors carrying ONE plugin capability each, because no
        // shipped role holds :manage without :viewall and the whole
        // point of this file is that the two are different questions.
        foreach (['narrowmanager' => 'manage', 'narrowviewer' => 'viewall'] as $handle => $capability) {
            $this->users[$handle] = $generator->create_user(['username' => $handle]);
            $generator->enrol_user($this->users[$handle]->id, $this->course->id, 'student');
            $role = $generator->create_role();
            assign_capability('mod/selfselectadvanced:' . $capability, CAP_ALLOW, $role, $context->id);
            role_assign($role, $this->users[$handle]->id, $context);
        }

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

        $this->contenthash = get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_selfselectadvanced',
            'filearea' => 'proposal',
            'itemid' => (int) $this->alpha->id,
            'filepath' => '/',
            'filename' => 'approach.txt',
        ], self::BODY)->get_contenthash();

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Ask the real file server for Alpha's proposal AS somebody.
     *
     * Two harness accommodations, and neither of them touches the gate:
     *
     *  - 'dontdie', so send_stored_file() hands control back instead of
     *    ending the process at its die;
     *  - a conditional request carrying the file's own ETag, so the
     *    real send path answers "304 Not Modified" and returns rather
     *    than unwinding the test runner's output buffers and streaming
     *    a body into its report. Everything this file is about - the
     *    require_login, the activity cross-check, the authorisation
     *    predicate and the file lookup - runs first and runs unchanged.
     *
     * The @ suppresses PHP's "headers already sent", which the real
     * send path raises only because PHPUnit has already printed a
     * progress dot; it is an artefact of running a web function on a
     * command line and not a signal from the code under test.
     *
     * @param string $handle who is asking, by cast handle
     * @return bool whether the file server served them
     */
    private function fetch(string $handle): bool {
        global $DB;

        $this->setUser($this->users[$handle]);
        $cm = $this->activity->cm();
        $course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);

        $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $this->contenthash . '"';
        try {
            return (bool) @selfselectadvanced_pluginfile(
                $course,
                $cm,
                $this->activity->context(),
                'proposal',
                [(string) $this->alpha->id, 'approach.txt'],
                true,
                ['dontdie' => true]
            );
        } finally {
            unset($_SERVER['HTTP_IF_NONE_MATCH']);
        }
    }

    /**
     * The audience, decided at the file server and not at the page.
     *
     * Both directions in one place, because the two halves of A-05
     * pulled in opposite directions and a test of only the refusals
     * would have passed on a gate that refused everybody.
     */
    public function test_who_the_file_server_serves(): void {
        $this->resetAfterTest();
        $this->build_world();

        // Admitted.
        $this->assertTrue($this->fetch('member'), 'a confirmed member reads their team proposal');
        $this->assertTrue($this->fetch('guidealpha'), 'the assigned guide reads the team they judge');
        $this->assertTrue($this->fetch('narrowviewer'), ':viewall is the broad staff read');
        $this->assertTrue(
            $this->fetch('narrowmanager'),
            'A-05: :manage opens the review page, which EMBEDS this file, and used to be refused it'
        );

        // Refused.
        $this->assertFalse($this->fetch('outsider'), 'a stranger with the URL gets nothing');
        $this->assertFalse(
            $this->fetch('invitee'),
            'an invitation is not a membership: the team page shows the name and withholds the link'
        );
        $this->assertFalse(
            $this->fetch('guideother'),
            'a guide who guides another team is a stranger to this one'
        );
        $this->assertFalse(
            $this->fetch('guideasked'),
            'a guide nobody approached is a stranger too'
        );
    }

    /**
     * A PROHIBITED capability stops the fetch.
     *
     * The audit's one-line problem, on this surface: :viewassignedteams
     * is what makes somebody "the guide of THIS team" everywhere else in
     * the plugin, and the file server used to ask :guide instead - so an
     * administrator who prohibited the narrow capability closed every
     * door on the team except the one that hands out its file.
     */
    public function test_prohibiting_viewassignedteams_closes_the_file(): void {
        $this->resetAfterTest();
        $this->build_world();

        $this->assertTrue($this->fetch('guidealpha'), 'positive control');

        $prohibited = $this->getDataGenerator()->create_role();
        assign_capability(
            'mod/selfselectadvanced:viewassignedteams',
            CAP_PROHIBIT,
            $prohibited,
            $this->activity->context()->id
        );
        role_assign($prohibited, $this->users['guidealpha']->id, $this->activity->context());
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(
            has_capability(
                'mod/selfselectadvanced:viewassignedteams',
                $this->activity->context(),
                $this->users['guidealpha']->id
            ),
            'the fixture did not actually withdraw the capability'
        );
        $this->assertTrue(
            has_capability(
                'mod/selfselectadvanced:guide',
                $this->activity->context(),
                $this->users['guidealpha']->id
            ),
            ':guide is still held, so only the capability under test can be refusing'
        );
        $this->assertFalse(
            $this->fetch('guidealpha'),
            'the administrator prohibited it and the file server served the file anyway'
        );
    }

    /**
     * The guide a team is CURRENTLY asking to take it on reads the
     * proposal, and stops when the approach stops being live.
     *
     * contacts::send() refuses a team that already has a guide, so an
     * approached guide is by construction not the assigned one:
     * contactreview.php - the page whose whole purpose is "read their
     * approach and decide" - linked a file the server refused.
     */
    public function test_a_live_approach_opens_it_and_a_dead_one_does_not(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $this->assertFalse($this->fetch('guideasked'), 'nothing has been sent yet');

        $contact = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_contact([
            'activityid' => $this->activity->id(),
            'groupid' => (int) $this->alpha->id,
            'guideid' => (int) $this->users['guideasked']->id,
            'sentby' => (int) $this->users['leader']->id,
            'status' => contacts::STATUS_SENT,
        ]);
        $this->assertTrue($this->fetch('guideasked'), 'the team asked them to read it');

        // Declined: the approach is over and so is the access. Written
        // straight to the column the predicate reads, so this asserts
        // the STATUS is the whole mechanism and not some side effect of
        // the responding workflow.
        $DB->set_field('selfselectadvanced_contact', 'status', contacts::STATUS_DECLINED, ['id' => $contact->id]);
        $this->assertFalse($this->fetch('guideasked'), 'a declined approach keeps nothing');

        $DB->set_field('selfselectadvanced_contact', 'status', contacts::STATUS_ACCEPTED, ['id' => $contact->id]);
        $this->assertFalse(
            $this->fetch('guideasked'),
            'accepting does not admit them: being the assigned guide does, and that is a different column'
        );
        $DB->set_field('selfselectadvanced_group', 'guideid', $this->users['guideasked']->id, [
            'id' => $this->alpha->id,
        ]);
        $this->alpha = $DB->get_record('selfselectadvanced_group', ['id' => $this->alpha->id], '*', MUST_EXIST);
        $this->assertTrue(
            $this->fetch('guideasked'),
            'once the team is theirs the assigned-guide clause carries them'
        );
    }

    /**
     * A proposal belonging to ANOTHER activity is not served through
     * this one's context, whoever asks.
     *
     * The itemid is a plugin group id and arrives in the URL, so the
     * activity cross-check is the only thing standing between a
     * :viewall holder in course A and a team in course B.
     */
    public function test_the_itemid_cannot_reach_another_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $this->assertTrue($this->fetch('narrowviewer'), 'positive control');

        // Re-home the team on a different activity id and ask again.
        $DB->set_field('selfselectadvanced_group', 'activityid', $this->activity->id() + 1000, [
            'id' => $this->alpha->id,
        ]);
        $this->assertFalse($this->fetch('narrowviewer'), 'the itemid escaped its activity');
    }

    /**
     * The page and the file server ask the SAME function.
     *
     * The first cut of teamaccess shipped with the predicates
     * duplicated inline on the pages and a unit test comparing one copy
     * against another, which is a test of the copy. This asserts the
     * CALL, so reverting either caller to its own transcription goes
     * red here even if the transcription happens to agree today.
     */
    public function test_both_callers_call_the_predicate(): void {
        $this->resetAfterTest();

        $root = dirname(__DIR__);
        foreach (['lib.php', 'group.php'] as $file) {
            $source = file_get_contents($root . '/' . $file);
            $this->assertIsString($source);
            $this->assertStringContainsString(
                'teamaccess::may_read_proposal(',
                $source,
                $file . ' no longer calls the one proposal policy'
            );
        }
        $this->assertStringNotContainsString(
            "'status' => 'confirmed'",
            file_get_contents($root . '/lib.php'),
            'lib.php has grown its own membership test again'
        );
    }

    /**
     * The predicate is not a page gate wearing a different name: it
     * REFUSES an audience may_open_team() admits.
     *
     * Stated as an assertion because the difference is a decision -
     * "not every page audience may read the proposal" - and a decision
     * with no test is a comment.
     */
    public function test_the_proposal_audience_is_narrower_than_the_page(): void {
        $this->resetAfterTest();
        $this->build_world();

        $invitee = (int) $this->users['invitee']->id;
        $this->assertTrue(
            teamaccess::may_open_team($this->activity, $this->alpha, $invitee),
            'an invitee may still open the team page'
        );
        $this->assertFalse(
            teamaccess::may_read_proposal($this->activity, $this->alpha, $invitee),
            'and may still not read the file'
        );
    }
}
