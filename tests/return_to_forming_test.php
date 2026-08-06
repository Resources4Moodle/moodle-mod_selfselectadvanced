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
use mod_selfselectadvanced\local\state;

/**
 * Decision 62: the coordinator's half of ruling 51-A2, and the honest
 * end of a deleted team (maintainer's disband-workflow reasoning,
 * 2026-08-06).
 *
 * 51-A2 ruled that a guide cannot un-approve - it needs a coordinator -
 * and until now the coordinator had no verb either: the state machine's
 * edges were FORMING -> PENDING_GUIDE -> {FORMING, FIRM} and
 * FIRM <-> FROZEN, so "return the team to the state before a guide was
 * chosen" had no path at all. return_group() now carries a staff arm:
 * a queue worker (coordinator or manager), NOT involved with the team,
 * returns a FIRM team to FORMING with a reason - approval undone, guide
 * relieved and notified, pending handover lapsed.
 *
 * And the sibling repair: delete_group() now runs the same orphan sweep
 * dissolve_group() always ran, because a live join request targeting a
 * leader-deleted team held its asker's one-request slot against a team
 * that no longer existed, and the history table read "Accepted" beside
 * "Team no longer exists".
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\state
 * @covers     \mod_selfselectadvanced\local\api
 */
final class return_to_forming_test extends \advanced_testcase {
    /**
     * A FIRM guided team, its guide, a manager-shaped worker and a
     * coordinator, plus a bystander student.
     *
     * @return array [activity, api, group row, guide, coordinator, leader, bystander]
     */
    private function world(): array {
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

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $bystander = $generator->create_user();
        $generator->enrol_user($bystander->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(
            coordinatorrole::ensure(),
            $coordinator->id,
            \context_module::instance((int) $instance->cmid)
        );

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Firmed',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);

        return [
            $activity,
            new api($activity),
            groups::get($activity, (int) $group->id),
            $guide,
            $coordinator,
            $leader,
            $bystander,
        ];
    }

    /**
     * The coordinator returns a FIRM team to forming: approval undone,
     * guide relieved, both the leader and the relieved guide told why.
     *
     * MUTATION CAUGHT (run): removing the FIRM arm from return_group()
     * makes this refuse with refusalwrongstate.
     */
    public function test_the_coordinator_returns_a_firm_team_to_forming(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, $guide, $coordinator, $leader] = $this->world();

        $fresh = $api->lifecycle()->return_group($group, 'Guide relieved at their own request.', (int) $coordinator->id);

        $this->assertSame(state::FORMING, $fresh->state);
        $this->assertNull($fresh->guideid, 'the guide is relieved');
        $this->assertNull($fresh->timeapproved, 'an approval must not outlive the guide who gave it');
        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);
        $this->assertSame(state::FORMING, $row->state);

        $tolds = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $this->assertContains((int) $leader->id, $tolds, 'the leader is told');
        $this->assertContains((int) $guide->id, $tolds, 'the relieved guide is told');
        $sink->close();
    }

    /**
     * Ruling 51-A2 itself stays intact: the team's own guide cannot
     * un-approve through the new arm - a guide is not a queue worker.
     */
    public function test_the_guide_still_cannot_unapprove(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [, $api, $group, $guide] = $this->world();

        $this->expectException(\required_capability_exception::class);
        $api->lifecycle()->return_group($group, 'Let me go', (int) $guide->id);
        $sink->close();
    }

    /**
     * The standing conflict rule: a coordinator who IS the team's guide
     * is involved and refused (traceability - they do not act on their
     * own teams).
     */
    public function test_an_involved_coordinator_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, , $coordinator] = $this->world();
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $coordinator->id, ['id' => $group->id]);
        $group = groups::get($activity, (int) $group->id);

        try {
            $api->lifecycle()->return_group($group, 'My own team', (int) $coordinator->id);
            $this->fail('An involved coordinator must be refused');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('coi', $e->errorcode, 'the refusal is the conflict rule, not a capability gap');
        }
        $sink->close();
    }

    /**
     * A FROZEN team is not returnable: the freeze must be lifted first,
     * through the freeze workflow that owns it.
     */
    public function test_a_frozen_team_must_be_unfrozen_first(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, $group, , $coordinator] = $this->world();
        $DB->set_field('selfselectadvanced_group', 'state', state::FROZEN, ['id' => $group->id]);
        $group = groups::get($activity, (int) $group->id);

        try {
            $api->lifecycle()->return_group($group, 'Straight from frozen', (int) $coordinator->id);
            $this->fail('A frozen team is the freeze workflow\'s to unfreeze first');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalwrongstate', $e->errorcode);
        }
        $sink->close();
    }

    /**
     * The sibling repair (the maintainer's screenshot): deleting a
     * forming team closes the live join requests that target it, in the
     * same transaction, with the reason recorded - the asker's
     * one-request slot comes free and the history stops lying.
     *
     * MUTATION CAUGHT (run): removing the orphan sweep from
     * delete_group() leaves the request REQUESTED and the second
     * request refused as a duplicate.
     */
    public function test_deleting_a_team_closes_the_requests_that_target_it(): void {
        global $DB;
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $api, , , , , $bystander] = $this->world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $leader2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($leader2->id, (int) $activity->cm()->course, 'student');
        $doomed = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader2->id,
            'name' => 'Doomed',
        ]);
        $doomed = groups::get($activity, (int) $doomed->id);
        $request = joinrequests::request($activity, (int) $doomed->id, 'Let me in', (int) $bystander->id);

        $api->delete_group($doomed, (int) $leader2->id);

        $row = $DB->get_record('selfselectadvanced_move', ['id' => (int) $request->id], '*', MUST_EXIST);
        $this->assertSame(joinrequests::STATUS_DECLINED, $row->status, 'the orphan request is closed');
        $this->assertSame(
            get_string('joindeclinedteamdeleted', 'mod_selfselectadvanced'),
            $row->responsenote,
            'and the record says why'
        );
        $sink->close();
    }
}
