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
use mod_selfselectadvanced\local\state;

/**
 * Guide handover: a held team leaves a guide only through a nominated
 * replacement's acceptance, capacity-gated, and a manager reassignment
 * supersedes any pending nomination.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\handover
 */
final class handover_test extends \advanced_testcase {
    /**
     * One activity, two guides, one pending_guide group held by guide1.
     *
     * @param array $settings instance overrides
     * @return array [activity, api, group, guide1, guide2, leader]
     */
    private function setup_held(array $settings = []): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxguided' => 2,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide1 = $generator->create_user();
        $generator->enrol_user($guide1->id, $course->id, 'teacher');
        $guide2 = $generator->create_user();
        $generator->enrol_user($guide2->id, $course->id, 'teacher');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Held',
            'state' => state::PENDING_GUIDE,
            'guideid' => (int) $guide1->id,
        ]);

        return [$activity, new api($activity), $group, $guide1, $guide2, $leader];
    }

    /**
     * The full happy path: propose notifies the nominee, accept swaps
     * the guide atomically, releases the proposer and notifies both
     * the proposer and the leader.
     */
    public function test_propose_and_accept(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $group, $guide1, $guide2, $leader] = $this->setup_held();
        $sink = $this->redirectMessages();

        $api->handover()->propose((int) $group->id, (int) $guide2->id, (int) $guide1->id);
        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);
        $this->assertEquals((int) $guide2->id, (int) $row->guidesuccessorid);
        $this->assertEquals((int) $guide1->id, (int) $row->guideid);

        $incoming = $api->handover()->incoming((int) $guide2->id);
        $this->assertCount(1, $incoming);

        $events = $this->redirectEvents();
        $api->handover()->accept((int) $group->id, (int) $guide2->id);
        $events->close();

        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);
        $this->assertEquals((int) $guide2->id, (int) $row->guideid);
        $this->assertNull($row->guidesuccessorid);

        $reassigned = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\guide_reassigned
        ));
        $this->assertCount(1, $reassigned);
        $this->assertSame('handover', $reassigned[0]->get_data()['other']['via']);

        $messages = $sink->get_messages();
        $sink->close();
        $to = array_map(static fn($m) => (int) $m->useridto, $messages);
        $this->assertContains((int) $guide2->id, $to);
        $this->assertContains((int) $guide1->id, $to);
        $this->assertContains((int) $leader->id, $to);
    }

    /**
     * Refusals: only the current guide proposes; no self-nomination;
     * one pending handover at a time; a full nominee is refused at
     * proposal AND at acceptance.
     */
    public function test_refusals(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $group, $guide1, $guide2, $leader] = $this->setup_held();

        try {
            $api->handover()->propose((int) $group->id, (int) $guide1->id, (int) $guide2->id);
            $this->fail('Expected not-guide refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalhandovernotguide', $e->errorcode);
        }
        try {
            $api->handover()->propose((int) $group->id, (int) $guide1->id, (int) $guide1->id);
            $this->fail('Expected self refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalhandoverself', $e->errorcode);
        }

        $api->handover()->propose((int) $group->id, (int) $guide2->id, (int) $guide1->id);
        try {
            $api->handover()->propose((int) $group->id, (int) $guide2->id, (int) $guide1->id);
            $this->fail('Expected pending refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalhandoverpending', $e->errorcode);
        }

        // The nominee fills up before accepting: acceptance re-checks
        // capacity under the nominee's own guide lock and refuses.
        $DB->set_field('selfselectadvanced', 'maxguided', 1, ['id' => $activity->id()]);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $activity->courseid(), 'student');
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $other->id,
            'name' => 'Fills',
            'state' => state::PENDING_GUIDE,
            'guideid' => (int) $guide2->id,
        ]);
        $freshactivity = activity::from_instance($activity->id());
        $freshapi = new api($freshactivity);
        try {
            $freshapi->handover()->accept((int) $group->id, (int) $guide2->id);
            $this->fail('Expected capacity refusal');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalguidecap', $e->errorcode);
        }
    }

    /**
     * Decline keeps the proposer as guide and clears the nomination;
     * a manager reassignment supersedes a pending nomination.
     */
    public function test_decline_and_manager_supersede(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $group, $guide1, $guide2, $leader] = $this->setup_held();

        $api->handover()->propose((int) $group->id, (int) $guide2->id, (int) $guide1->id);
        $api->handover()->decline((int) $group->id, (int) $guide2->id);
        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);
        $this->assertNull($row->guidesuccessorid);
        $this->assertEquals((int) $guide1->id, (int) $row->guideid);

        // Re-propose, then a manager reassigns: nomination cleared.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');
        $api->handover()->propose((int) $group->id, (int) $guide2->id, (int) $guide1->id);
        $api->lifecycle()->assign_guide(
            groups::get($activity, (int) $group->id),
            (int) $guide2->id,
            (int) $manager->id
        );
        $row = $DB->get_record('selfselectadvanced_group', ['id' => $group->id]);
        $this->assertEquals((int) $guide2->id, (int) $row->guideid);
        $this->assertNull($row->guidesuccessorid);
    }

    /**
     * A FIRM group's guide can be reassigned by a manager: the event
     * carries via=reassign and the leader and both guides hear of it.
     */
    public function test_manager_reassign_firm(): void {
        global $DB;
        $this->resetAfterTest();

        [$activity, $api, $group, $guide1, $guide2, $leader] = $this->setup_held();
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->courseid(), 'editingteacher');

        $sink = $this->redirectMessages();
        $events = $this->redirectEvents();
        $updated = $api->lifecycle()->assign_guide(
            groups::get($activity, (int) $group->id),
            (int) $guide2->id,
            (int) $manager->id
        );
        $events->close();

        $this->assertEquals((int) $guide2->id, (int) $updated->guideid);
        $reassigned = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\guide_reassigned
        ));
        $this->assertCount(1, $reassigned);
        $this->assertSame('reassign', $reassigned[0]->get_data()['other']['via']);

        $to = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $sink->close();
        $this->assertContains((int) $guide2->id, $to);
        $this->assertContains((int) $guide1->id, $to);
        $this->assertContains((int) $leader->id, $to);
    }

    /**
     * D7-B1: the mirror carries the guide, so a handover on a FROZEN
     * team has to swap it - one sync takes the outgoing guide out (they
     * are in neither the confirmed set nor guideid) and puts the
     * incoming one in.
     *
     * preventResetByRollback() first, and no longer for the reason
     * this docblock used to give: the sync does NOT refuse to write to
     * core while a transaction is open - that branch was removed in
     * 1.20 (requirement 6). The call is kept so the core-group rows
     * this test reads back are ordinary committed rows.
     */
    public function test_accept_on_frozen_group_swaps_core_membership(): void {
        global $DB;
        $this->preventResetByRollback();
        $this->resetAfterTest();

        [$activity, $api, $group, $guide1, $guide2, $leader] = $this->setup_held();
        // A held team becomes firm, then frozen, with guide1 in charge.
        $DB->set_field('selfselectadvanced_group', 'state', state::FIRM, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'timeapproved', time(), ['id' => $group->id]);
        $frozen = \mod_selfselectadvanced\local\freeze::freeze_group(
            $activity,
            groups::get($activity, (int) $group->id),
            (int) $guide1->id
        );
        $coreid = (int) $frozen->coregroupid;
        $this->assertTrue(groups_is_member($coreid, (int) $guide1->id));

        $api->handover()->propose((int) $group->id, (int) $guide2->id, (int) $guide1->id);
        $api->handover()->accept((int) $group->id, (int) $guide2->id);

        $this->assertFalse(groups_is_member($coreid, (int) $guide1->id), 'the old guide lingered');
        $this->assertTrue(groups_is_member($coreid, (int) $guide2->id));
        $this->assertTrue(groups_is_member($coreid, (int) $leader->id));
    }
}
