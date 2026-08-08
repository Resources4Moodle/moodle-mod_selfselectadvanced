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
     * F05 and F10 live in page/template code with no callable seam, so
     * these pin the contract at source; the behavioural proof for both
     * is the live check run against the deployed site at release.
     *
     * F05: the consent message must not be interpolated into a JS
     * string literal. HTML-escaping cannot protect that context - the
     * parser decodes the entity before the JS engine compiles the
     * handler, so one apostrophe in a translation turns the dialog into
     * a SyntaxError, and because the form pre-sets confirmaccept=1 the
     * click then submits with consent asserted and no dialog shown.
     *
     * F10: neither name-resolving branch of the invite arm may reach
     * core_user::get_user() without first proving the id belongs to
     * this activity's candidate pool.
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
        $resolves = preg_match_all('/core_user::get_user\(/', $page);
        $guards = preg_match_all("/is_enrolled\(\\\$context, [^,]+, 'mod\/selfselectadvanced:respond', true\)/", $page);
        $this->assertGreaterThan(0, $resolves, 'scanned the wrong file');
        $this->assertGreaterThanOrEqual(
            2,
            $guards,
            'both invite-arm name lookups must be gated on candidate-pool membership'
        );
    }
}
