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
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\workflow_refusal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/selfselectadvanced/lib.php');

/**
 * Slice 0 of the reconciled plan (audit_state/RECONCILED-PLAN-20260808.md):
 * the four safety findings the two 1.20.22 audits left with a live-exposure
 * clock, plus the invite-tab predicate that has to move with them.
 *
 * F09 the invite door had no candidate-pool arm; F10 the refusal notice
 * resolved any submitted id to a full name; F07 the Invite tab
 * transcribed one arm of the door instead of asking it; F06 a report
 * column emitted fullname() unescaped into raw table HTML.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\rules\gatekeeper
 * @covers     \mod_selfselectadvanced\local\invitations
 * @covers     \mod_selfselectadvanced\table\coresync_report_table
 * @covers     ::selfselectadvanced_candidate_name
 */
final class slice0_safety_test extends \advanced_testcase {
    /**
     * A forming team with a free seat, its leader, and two outsiders.
     *
     * @return array [activity, api, group, leaderid, course]
     */
    private function world(): array {
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
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Slice0',
        ]);

        return [$activity, new api($activity), groups::get($activity, (int) $group->id), (int) $leader->id, $course];
    }

    /**
     * F09: the pool restriction is the DOOR's, not the search box's.
     *
     * The enrolment/:respond filter lived only in the query that feeds
     * the autocomplete, and core's ajax autocomplete submits values
     * verbatim - so a crafted or stale positive id reached send() and
     * put a non-participant on the roster. The door now asks.
     *
     * MUTATION CAUGHT (run): deleting the is_enrolled arm from
     * invite_refusals() makes every assertion below fail - the
     * outsider, the suspended account and the other course's student
     * all become invitable again.
     */
    public function test_invite_door_refuses_anyone_outside_the_candidate_pool(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $group, $leaderid, $course] = $this->world();
        $generator = $this->getDataGenerator();

        // A participant: invitable, as always.
        $insider = $generator->create_user();
        $generator->enrol_user($insider->id, $course->id, 'student');
        $this->assertNull(
            $api->gatekeeper()->can_invite($group, (int) $insider->id),
            'an enrolled responder is still a candidate'
        );

        // Never enrolled anywhere near this course.
        $outsider = $generator->create_user();
        $this->assertSame(
            'refusalnotcandidate',
            $api->gatekeeper()->can_invite($group, (int) $outsider->id)?->stringkey,
            'a site user with no enrolment here is not a candidate'
        );

        // Enrolled in a DIFFERENT course - the shape a crafted id takes.
        $elsewhere = $generator->create_user();
        $generator->enrol_user($elsewhere->id, $generator->create_course()->id, 'student');
        $this->assertSame(
            'refusalnotcandidate',
            $api->gatekeeper()->can_invite($group, (int) $elsewhere->id)?->stringkey
        );

        // Enrolled here but suspended: the pool query excludes them, so
        // the door must too.
        $suspended = $generator->create_user();
        $generator->enrol_user($suspended->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->assertSame(
            'refusalnotcandidate',
            $api->gatekeeper()->can_invite($group, (int) $suspended->id)?->stringkey,
            'a suspended enrolment is not an active candidate'
        );

        // And the service refuses the forged POST the same way - TYPED,
        // so the page answers with a notice.
        try {
            $api->invitations()->send($group, (int) $outsider->id, $leaderid);
            $this->fail('send() must refuse a non-participant');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalnotcandidate', $e->errorcode);
        }
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_member', ['userid' => (int) $outsider->id]),
            'no roster row was created for a non-participant'
        );
    }

    /**
     * F07: the Invite cluster is offered to a leader of a forming team
     * whatever the door says, so the door's own sentence has somewhere
     * to render. Before this, three of the door's four refusal arms
     * hid the cluster AND the reason the exporter had just built.
     *
     * MUTATION CAUGHT (run): restoring the `$seats->free < 1`
     * transcription fails the cutoff case below.
     */
    public function test_invite_tab_survives_every_door_refusal(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $api, $group, $leaderid] = $this->world();
        $this->setUser($leaderid);
        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);

        $export = function () use (&$activity, $group, $leaderid) {
            global $PAGE;

            return (new \mod_selfselectadvanced\output\group_page(
                new api($activity),
                groups::get($activity, (int) $group->id),
                $leaderid
            ))->export_for_template($PAGE->get_renderer('core'));
        };

        // Seats free, window open: enabled control, no reason.
        $open = $export();
        $this->assertTrue((bool) $open->tabinvite, 'the cluster is there when inviting is allowed');
        $this->assertTrue((bool) $open->caninvite);
        $this->assertEmpty($open->invitedisabledreason);

        // The cutoff passes underneath the team - the arm that used to
        // make the whole cluster disappear.
        $DB->set_field('selfselectadvanced', 'timecutoff', time() - HOURSECS, ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());
        $closed = $export();
        $this->assertTrue(
            (bool) $closed->tabinvite,
            'a closed window DISABLES the control - it does not delete the section'
        );
        $this->assertFalse((bool) $closed->caninvite);
        $this->assertNotEmpty(
            $closed->invitedisabledreason,
            'and the door&apos;s own sentence is rendered, not built and discarded'
        );
    }

    /**
     * F06: names reaching a flexible_table cell are escaped at source,
     * because the report prints the table as raw HTML.
     */
    public function test_coresync_guide_column_escapes_the_name(): void {
        $this->resetAfterTest();
        [$activity] = $this->world();
        $table = new \mod_selfselectadvanced\table\coresync_report_table(
            'slice0',
            $activity,
            new \moodle_url('/mod/selfselectadvanced/coresync.php'),
            []
        );
        $row = (object) [
            'guideuserid' => 7,
            'firstname' => '<img src=x onerror=alert(1)>',
            'lastname' => 'Rao',
            'firstnamephonetic' => '', 'lastnamephonetic' => '',
            'middlename' => '', 'alternatename' => '',
        ];
        $cell = $table->col_guide($row);
        $this->assertStringNotContainsString('<img', $cell, 'markup in a name must never reach the raw table HTML');
        $this->assertStringContainsString('&lt;img', $cell);
        $this->assertStringContainsString('Rao', $cell, 'and the name is still shown');
    }

    /**
     * F10: the invite arm names people back to the leader when a pick is
     * refused, and the id it names them from arrived in a form post.
     * Both branches used to resolve it with core_user::get_user() written
     * out inline, which is what made the notice a site-wide
     * userid-to-name oracle AND what left the rule with nothing a test
     * could call. The decision has one name now, so ask it directly.
     *
     * MUTATION CAUGHT (run): dropping the is_enrolled() arm from
     * selfselectadvanced_candidate_name() fails the outsider and the
     * suspended-enrolment assertions below.
     */
    public function test_candidate_name_is_given_only_for_the_pool(): void {
        $this->resetAfterTest();
        [$activity, , , , $course] = $this->world();
        $context = $activity->context();
        $generator = $this->getDataGenerator();

        $insider = $generator->create_user(['firstname' => 'Pooja', 'lastname' => 'Nair']);
        $generator->enrol_user($insider->id, $course->id, 'student');
        $named = selfselectadvanced_candidate_name($context, (int) $insider->id);
        $this->assertSame(fullname($insider), $named, 'someone the leader could have picked is named');
        $this->assertStringContainsString('Nair', (string) $named, 'and it is a name, not an id echoed back');

        // The forged id: a real site account with no business in this course.
        $outsider = $generator->create_user();
        $this->assertNull(
            selfselectadvanced_candidate_name($context, (int) $outsider->id),
            'a site user with no enrolment here must not be named back to the leader'
        );

        // Enrolled here but suspended. The candidate search never showed
        // them, so naming them would disclose an account the leader was
        // never offered.
        $suspended = $generator->create_user();
        $generator->enrol_user($suspended->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->assertNull(
            selfselectadvanced_candidate_name($context, (int) $suspended->id),
            'a suspended enrolment is not an active candidate, so it gets no name'
        );

        // And the sentence the page prints instead has nowhere to put a
        // name. That placeholder-free wording is what makes the refusal
        // neutral rather than merely unnamed today.
        $this->assertStringNotContainsString(
            '{$a',
            get_string('refusalnotcandidate', 'mod_selfselectadvanced'),
            'the sentence a non-candidate gets must carry no placeholder'
        );
    }

    /**
     * F05 lives in template markup with no callable seam. Reading the
     * markup IS its coverage: nothing exercises the rendered dialog, and
     * no live check of it was ever obtained - the invite form would not
     * validate through curl, so that attempt produced no evidence and
     * none is claimed for it here.
     *
     * F05: the consent message must not be interpolated into a JS
     * string literal. HTML-escaping cannot protect that context - the
     * parser decodes the entity before the JS engine compiles the
     * handler, so one apostrophe in a translation turns the dialog into
     * a SyntaxError, and because the form pre-sets confirmaccept=1 the
     * click then submits with consent asserted and no dialog shown.
     *
     * F10 was pinned here the same way, by counting is_enrolled() in the
     * page's source, until the pool decision was given a name;
     * test_candidate_name_is_given_only_for_the_pool() proves the
     * behaviour now. What is left here is the half no unit test can see:
     * that the page keeps no SECOND route from a submitted invite id to a
     * printed name, which is how the oracle got in the first time.
     */
    public function test_page_and_template_contracts(): void {
        $template = file_get_contents(__DIR__ . '/../templates/group_page.mustache');
        $this->assertStringNotContainsString(
            "confirm('{{",
            $template,
            'a translated string interpolated into a JS string literal breaks on any apostrophe'
        );
        $this->assertStringContainsString(
            'this.dataset.ssaconfirm',
            $template,
            'the message travels as an HTML attribute and is read back decoded'
        );

        $page = file_get_contents(__DIR__ . '/../group.php');
        $this->assertStringContainsString("\$action === 'invite'", $page, 'scanned the wrong file');
        $this->assertSame(
            2,
            preg_match_all('/selfselectadvanced_candidate_name\(\$context, /', $page),
            'both invite-arm name lookups must go through the candidate-pool helper'
        );
        $this->assertSame(
            0,
            preg_match_all('/get_user\(-?\$flaggedid\)|get_user\(\$inviteeid\)/', $page),
            'a submitted invite id must never reach core_user::get_user() directly again'
        );
    }
}
