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
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;

/**
 * Incoming join requests, surfaced on the leader's own team page
 * (maintainer decision 53).
 *
 * The maintainer's complaint was that a forming leader had to discover
 * joinrequest.php to learn that anybody had asked to join. The panel
 * this pins puts the queue on group.php, with the requester's
 * DEPARTMENT and SUB-DEPARTMENT beside it - composition attributes,
 * which is what a leader decides with, and not contact details.
 *
 * What these check, in order of what would break first:
 *
 *  - the panel is drawn for whoever joinrequests::require_decider()
 *    admits and for nobody else, BOTH ARMS of it: the leader, and a
 *    coordinator or manager acting for an absent leader. The wave-1
 *    lesson was two doors keyed on one arm, so the flag is compared
 *    against the service's own predicate for every actor in the world;
 *  - the panel exists only when somebody has actually asked;
 *  - accepting through it makes the same state change the tab makes,
 *    because it is the same service call;
 *  - and the row carries no contact detail for any viewer, which is the
 *    cardinal rule's side of the same panel.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\output\group_page
 */
final class leaderjoinpanel_test extends \advanced_testcase {
    /**
     * Two teams, a wanderer confirmed in the first who has asked for
     * the second, a plain member of the second, a coordinator and a
     * manager.
     *
     * @return array [activity, api, alpha, beta, wanderer, betamember, coordinator, manager, request]
     */
    private function fixture(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'LJP1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            // Two since decision 77: the panel's subject is what a leader
            // sees and can act on, and its wanderer already has a team. At a
            // cap of one the request is refused at the door and the panel is
            // empty for a reason that has nothing to do with the panel.
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $mk = function (string $role) use ($generator, $course) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, $role);

            return $user;
        };
        $alphalead = $mk('student');
        $betalead = $mk('student');
        $betamember = $mk('student');
        $wanderer = $mk('student');
        $coordinator = $mk('teacher');
        $manager = $mk('editingteacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, $activity->context());

        $alpha = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $alphalead->id,
            'name' => 'Alpha',
        ]);
        $beta = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $betalead->id,
            'name' => 'Beta',
        ]);
        // The generator already gives each leader their member row.
        $plugingen->create_member([
            'groupid' => $alpha->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $plugingen->create_member([
            'groupid' => $beta->id,
            'userid' => (int) $betamember->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        // COMPOSITION attributes, and a mobile number that must never
        // reach the panel whoever is looking at it.
        $plugingen->create_userattr([
            'userid' => (int) $wanderer->id,
            'department' => 'Science',
            'subdepartment' => 'Physics',
            'mobile' => '9000000001',
            'shareconsent' => 1,
        ]);
        $request = $plugingen->create_joinrequest([
            'activityid' => $activity->id(),
            'userid' => (int) $wanderer->id,
            'targetgroupid' => (int) $beta->id,
            'reason' => 'Closer to my programme',
        ]);

        return [
            $activity,
            new api($activity),
            groups::get($activity, (int) $alpha->id),
            groups::get($activity, (int) $beta->id),
            $wanderer,
            $betamember,
            $coordinator,
            $manager,
            $request,
        ];
    }

    /**
     * Export the real team page for one viewer.
     *
     * @param activity $activity the activity
     * @param api $apifacade the facade
     * @param \stdClass $group the team
     * @param int $userid the viewer
     * @return \stdClass the template context
     */
    private function grouppage(activity $activity, api $apifacade, \stdClass $group, int $userid): \stdClass {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id]);

        return (new \mod_selfselectadvanced\output\group_page(
            $apifacade,
            groups::get($activity, (int) $group->id),
            $userid
        ))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * The leader of the team that was asked sees the request, the
     * reason, and the two composition attributes - and no contact
     * detail of any kind.
     */
    public function test_the_leader_sees_the_request_with_its_composition_attributes(): void {
        $this->resetAfterTest();
        [$activity, $apifacade, , $beta, $wanderer, , , , $request] = $this->fixture();

        $exported = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);

        $this->assertTrue($exported->showjoinpanel, 'the leader was not shown the request asked of their team');
        $this->assertCount(1, $exported->joinrows);
        $row = $exported->joinrows[0];
        $this->assertSame((int) $request->id, $row->requestid, 'the panel named the wrong request');
        $this->assertSame(fullname($wanderer), $row->fullname);
        $this->assertSame('Closer to my programme', $row->reason);
        $this->assertSame('Science', $row->department);
        $this->assertSame('Physics', $row->subdepartment);
        $this->assertTrue($row->hasdepartment);
        $this->assertTrue($row->hassubdepartment);
        $this->assertFalse($row->noattributes);
        // What the acceptance costs elsewhere is on the row too: the
        // wanderer is confirmed in Alpha and the cap is one.
        $this->assertStringContainsString('Alpha', $row->leavesline);

        // THE CARDINAL RULE'S SIDE OF THE SAME PANEL. The fixture gives
        // the wanderer a consented mobile number, so a panel that
        // carried contact details would carry THIS one; the assertion
        // is over the row's whole shape rather than one named field, so
        // an address or an email added later fails here too.
        $fields = array_keys((array) $row);
        foreach (['mobile', 'email', 'phone', 'phone1', 'phone2', 'address'] as $contact) {
            $this->assertNotContains($contact, $fields, 'the join panel carried a contact detail');
        }
        $serialised = json_encode($row);
        $this->assertStringNotContainsString('9000000001', $serialised, 'the mobile number reached the join panel');
        $this->assertStringNotContainsString($wanderer->email, $serialised, 'the email address reached the join panel');
    }

    /**
     * A plain confirmed member of the team is not a decider, and
     * neither is the leader of the team the student would LEAVE.
     */
    public function test_a_plain_member_and_the_source_leader_are_offered_nothing(): void {
        $this->resetAfterTest();
        [$activity, $apifacade, $alpha, $beta, , $betamember] = $this->fixture();

        $member = $this->grouppage($activity, $apifacade, $beta, (int) $betamember->id);
        $this->assertFalse($member->showjoinpanel, 'a plain member was shown the leader panel');
        $this->assertSame([], $member->joinrows, 'a plain member was handed the request rows');

        // The source team's leader answers nothing: the request was
        // made OF Beta. Asked on Beta's page, which they can open as
        // nobody special, and on their own page, which carries no
        // request at all.
        $sourceleader = $this->grouppage($activity, $apifacade, $beta, (int) $alpha->leaderid);
        $this->assertFalse($sourceleader->showjoinpanel, 'the source team leader was shown another team\'s queue');
        $this->assertSame([], $sourceleader->joinrows);
        $ownpage = $this->grouppage($activity, $apifacade, $alpha, (int) $alpha->leaderid);
        $this->assertFalse($ownpage->showjoinpanel, 'a team nobody asked for drew the panel anyway');
    }

    /**
     * The maintainer's escape hatch, on this page too: a coordinator -
     * and a manager - answering for an absent leader.
     */
    public function test_a_coordinator_or_manager_acting_for_an_absent_leader_sees_the_panel(): void {
        $this->resetAfterTest();
        [$activity, $apifacade, , $beta, , , $coordinator, $manager] = $this->fixture();

        $coordinatorpage = $this->grouppage($activity, $apifacade, $beta, (int) $coordinator->id);
        $this->assertTrue($coordinatorpage->showjoinpanel, 'a coordinator was shut out of the panel');
        $this->assertCount(1, $coordinatorpage->joinrows);

        $managerpage = $this->grouppage($activity, $apifacade, $beta, (int) $manager->id);
        $this->assertTrue($managerpage->showjoinpanel, 'a manager was shut out of the panel');
        $this->assertCount(1, $managerpage->joinrows);
    }

    /**
     * THE FLAG IS THE SERVICE'S OWN DOOR, for every actor in the world
     * at once.
     *
     * This is the assertion the wave-1 lesson asks for: a panel drawn
     * from a local $isleader test would pass every other test in this
     * file that names the leader and still shut the coordinator out, so
     * the flag is compared against joinrequests::require_decider()
     * itself - person by person, both arms, both verdicts.
     *
     * require_decider() takes no lock and opens no transaction (it is
     * two has_capability() calls and an identity test), so refusing it
     * repeatedly here cannot poison the delegated transaction the test
     * runs inside on PostgreSQL. Nothing in this method commits.
     */
    public function test_the_panel_flag_is_the_services_own_door_for_every_actor(): void {
        $this->resetAfterTest();
        [$activity, $apifacade, $alpha, $beta, $wanderer, $betamember, $coordinator, $manager] = $this->fixture();

        $actors = [
            'target leader' => (int) $beta->leaderid,
            'source leader' => (int) $alpha->leaderid,
            'plain member' => (int) $betamember->id,
            'the asker' => (int) $wanderer->id,
            'coordinator' => (int) $coordinator->id,
            'manager' => (int) $manager->id,
        ];
        $admitted = 0;
        $refused = 0;
        foreach ($actors as $who => $userid) {
            $servicesays = true;
            try {
                joinrequests::require_decider($activity, $beta, $userid);
            } catch (\moodle_exception $e) {
                $servicesays = false;
            }
            $servicesays ? $admitted++ : $refused++;
            $this->assertSame(
                $servicesays,
                (bool) $this->grouppage($activity, $apifacade, $beta, $userid)->showjoinpanel,
                'the panel and require_decider() disagreed about the ' . $who
            );
        }
        // A comparison where every actor fell on one side would pass
        // whatever the flag was wired to.
        $this->assertGreaterThan(1, $admitted, 'no arm of the door was exercised on the admitting side');
        $this->assertGreaterThan(1, $refused, 'no arm of the door was exercised on the refusing side');
    }

    /**
     * Accepting through the panel is the state change the tab makes,
     * because group.php routes the panel's POST to the same service
     * call - and once the queue is empty the panel goes with it.
     */
    public function test_accepting_from_the_panel_admits_the_student_and_empties_the_panel(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $apifacade, $alpha, $beta, $wanderer] = $this->fixture();

        $before = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);
        $this->assertTrue($before->showjoinpanel, 'fixture: the leader must have a request waiting');
        $requestid = $before->joinrows[0]->requestid;

        // The call group.php's joinrespond branch makes, with the id
        // the panel put on the button.
        joinrequests::respond($activity, $requestid, true, 'Glad to have you', (int) $beta->leaderid);

        $roster = array_map(
            static fn(\stdClass $member): int => (int) $member->userid,
            groups::get_roster((int) $beta->id)
        );
        $this->assertContains((int) $wanderer->id, $roster, 'the accepted student is not on the roster');
        // Alpha keeps them. Until decision 77 this asserted the opposite, and
        // the assertion message read "the student was not moved out of Alpha" -
        // a move Alpha's leader never agreed to and could not see coming.
        $memberships = array_map(
            'intval',
            array_keys(joinrequests::current_groups($activity, (int) $wanderer->id))
        );
        sort($memberships);
        $expected = [(int) $alpha->id, (int) $beta->id];
        sort($expected);
        $this->assertSame($expected, $memberships, 'accepting into Beta emptied the student out of Alpha');
        $this->assertContains(
            (int) $wanderer->id,
            array_map(
                static fn(\stdClass $member): int => (int) $member->userid,
                groups::get_roster((int) $alpha->id)
            ),
            'Alpha\'s roster lost a member to a decision taken on Beta\'s page'
        );

        $after = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);
        $this->assertFalse($after->showjoinpanel, 'the panel outlived the queue it exists to show');
        $this->assertSame([], $after->joinrows);
        $sink->close();
    }

    /**
     * Decision 55, hard side. A team with no free seat cannot accept
     * from the inline panel, and the standalone inbox is wired through
     * the same accept_decision() object rather than a local copy.
     *
     * MUTATION CAUGHT (run): ignoring refusalnoseats in accept_decision()
     * made this test fail because the full-team row exported canaccept=true.
     */
    public function test_a_hard_accept_refusal_disables_the_inline_accept_control(): void {
        $this->resetAfterTest();
        [$activity, $apifacade, , $beta] = $this->fixture();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $courseid = $activity->courseid();

        for ($i = 0; $i < 2; $i++) {
            $member = $generator->create_user();
            $generator->enrol_user($member->id, $courseid, 'student');
            $plugingen->create_member([
                'groupid' => (int) $beta->id,
                'userid' => (int) $member->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $exported = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);
        $this->assertTrue($exported->showjoinpanel);
        $this->assertCount(1, $exported->joinrows);
        $row = $exported->joinrows[0];
        $this->assertFalse($row->canaccept, 'a full team still exported a live accept control');
        $this->assertTrue($row->cannotaccept);
        $this->assertNotSame('', $row->hardreason, 'the disabled control had no reason to show');
        $this->assertFalse($row->confirmationrequired, 'a hard stop was treated as a confirmation warning');

        $script = file_get_contents(__DIR__ . '/../joinrequest.php');
        $this->assertStringContainsString('joinrequests::accept_decision', $script);
        $this->assertStringContainsString("\$acceptattrs['disabled'] = 'disabled';", $script);
    }

    /**
     * Decision 55's advisory arm, SUPERSEDED by decision 64 (2026-08-07,
     * the g=44 breach): a quota mismatch used to keep Accept live
     * behind a confirmation whose OK click committed through the
     * move-scope bypass - an override written in the LEADER'S name.
     * Rules are the staff's to declare breakable, never the accepting
     * leader's, so the same mismatch now DISABLES the leader's accept
     * with the reason in plain words, and the confirmed service call
     * REFUSES. The property this method has always pinned survives
     * intact: the panel and the service answer with one voice.
     */
    public function test_a_rule_based_refusal_disables_accept_for_the_leader(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $apifacade, , $beta, $wanderer, $betamember] = $this->fixture();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'rtype' => 'value',
            'value' => 'Scope',
            'mincount' => 2,
        ]);
        foreach ([(int) $beta->leaderid, (int) $betamember->id, (int) $wanderer->id] as $userid) {
            if ($DB->record_exists('selfselectadvanced_userattr', ['userid' => $userid])) {
                $DB->set_field('selfselectadvanced_userattr', 'department', 'Elsewhere', ['userid' => $userid]);
                $DB->set_field('selfselectadvanced_userattr', 'subdepartment', 'Physics', ['userid' => $userid]);
            } else {
                $plugingen->create_userattr([
                    'userid' => $userid,
                    'department' => 'Elsewhere',
                    'subdepartment' => 'Physics',
                ]);
            }
        }
        \mod_selfselectadvanced\local\attributes\manager::purge_value_cache();

        $exported = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);
        $row = $exported->joinrows[0];
        $this->assertFalse($row->canaccept, 'a rule refusal is not the leader\'s to confirm away');
        $this->assertTrue($row->cannotaccept, 'the accept control renders disabled');
        $this->assertNotSame('', $row->hardreason, 'with the reason beside it in plain words');
        $this->assertFalse($row->confirmationrequired, 'and no bypass confirmation is offered to a student');

        try {
            joinrequests::respond(
                $activity,
                (int) $row->requestid,
                true,
                '',
                (int) $beta->leaderid,
                [],
                true
            );
            $this->fail('The service must answer with the same voice as the disabled panel');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusaljoinrules', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $beta->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]), 'and the roster did not move');
        $sink->close();
    }

    /**
     * A leader asking to join elsewhere keeps the team they lead.
     *
     * DECISION 55 PUT A HARD STOP HERE, and decision 77 removed the thing it
     * was stopping. When a join was a swap, a source team's LEADER asking to
     * join another team was asking to vacate a leadership the workflow cannot
     * fill on its own - so the accept control was disabled with
     * `errmovesuccessorrequired`, a refusal the target leader could not resolve.
     *
     * A join adds a membership now. Alpha's leader stays Alpha's leader, so
     * there is no succession to arrange and nothing for Beta's leader to be
     * refused by. What must NOT happen is the old outcome arriving by accident:
     * a leadership quietly emptied by somebody else's Accept click.
     *
     * MUTATION CAUGHT (run 2026-08-10): restoring the whole swap - request()
     * storing the student's other team as the source, do_accept() handing it to
     * the engine - makes respond() throw errmovesuccessorrequired from
     * moves.php:256, because the engine will not move a leader out without a
     * successor. The test goes red at the respond() call rather than at the two
     * assertions below it, which is worth saying precisely: what it detects is
     * the swap coming back, and the engine's own guard is what it runs into
     * first. The assertions still stand as the statement of the property, for a
     * restoration that got past that guard.
     */
    public function test_a_leader_may_join_elsewhere_and_keeps_their_own_team(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $apifacade, $alpha, $beta] = $this->fixture();

        $request = joinrequests::request(
            $activity,
            (int) $beta->id,
            'I need this team',
            (int) $alpha->leaderid
        );
        $exported = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);
        $rows = array_filter(
            $exported->joinrows,
            static fn(\stdClass $row): bool => (int) $row->requestid === (int) $request->id
        );
        $this->assertCount(1, $rows);
        $row = reset($rows);

        $this->assertTrue($row->canaccept, 'the accept control is refused for a reason that no longer exists');
        $this->assertSame('', $row->hardreason);

        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);

        $freshalpha = groups::get($activity, (int) $alpha->id);
        $this->assertSame(
            (int) $alpha->leaderid,
            (int) $freshalpha->leaderid,
            'Beta\'s leader clicked Accept and Alpha lost its leader'
        );
        $this->assertContains(
            (int) $alpha->leaderid,
            array_map(static fn(\stdClass $m): int => (int) $m->userid, groups::get_roster((int) $alpha->id)),
            'Alpha\'s leader is no longer on Alpha\'s roster'
        );
        $sink->close();
    }

    /**
     * No empty scaffolding: a leader nobody has asked gets the page
     * they had before this wave.
     */
    public function test_a_leader_with_an_empty_queue_gets_no_panel(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $apifacade, , $beta, , , , , $request] = $this->fixture();

        $this->assertTrue(
            $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid)->showjoinpanel,
            'fixture: the panel must be drawable before the queue is emptied'
        );
        $DB->set_field('selfselectadvanced_move', 'status', joinrequests::STATUS_DECLINED, ['id' => $request->id]);

        $exported = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);
        $this->assertFalse($exported->showjoinpanel, 'an answered request still drew the panel');
        $this->assertSame([], $exported->joinrows);
    }

    /**
     * A requester with no attributes on file is still listed - with the
     * gap named, never dropped from the queue.
     */
    public function test_a_requester_without_attributes_is_still_listed(): void {
        global $DB;

        $this->resetAfterTest();
        [$activity, $apifacade, , $beta, $wanderer] = $this->fixture();

        $DB->delete_records('selfselectadvanced_userattr', ['userid' => (int) $wanderer->id]);
        \mod_selfselectadvanced\local\attributes\manager::purge_value_cache();

        $exported = $this->grouppage($activity, $apifacade, $beta, (int) $beta->leaderid);
        $this->assertTrue($exported->showjoinpanel);
        $this->assertCount(1, $exported->joinrows);
        $row = $exported->joinrows[0];
        $this->assertSame('', $row->department);
        $this->assertSame('', $row->subdepartment);
        $this->assertTrue($row->noattributes, 'the missing-attributes note was not raised');
    }
}
