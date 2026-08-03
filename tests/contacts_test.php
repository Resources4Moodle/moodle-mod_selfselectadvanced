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

/**
 * A team approaching a guide (strategy 1.17 E): who may approach whom
 * and how often, what an acceptance does, what a refusal leaves behind,
 * and - the point of the whole feature - that no address is ever
 * carried anywhere near either party.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\contacts
 */
final class contacts_test extends \advanced_testcase {
    /**
     * An activity with a forming team and two guides.
     *
     * @param array $settings instance overrides
     * @return array [activity, group, leader, guides[]]
     */
    private function setup_world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 3,
            'contactmax' => 2,
        ], $settings));

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guides = [];
        for ($i = 0; $i < 3; $i++) {
            $guide = $generator->create_user();
            $generator->enrol_user($guide->id, $course->id, 'teacher');
            $guides[] = $guide;
        }

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Approaching',
            'state' => state::FORMING,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $guides];
    }

    /**
     * Expect one refusal string key from a callable.
     *
     * @param string $stringkey the expected errorcode
     * @param callable $fn the action
     */
    private function assert_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }

    /**
     * A team may approach up to the activity's limit, never the same
     * guide twice, and only while it is forming and guideless.
     */
    public function test_approach_gates(): void {
        global $DB;
        $this->resetAfterTest();
        // THE preventResetByRollback() BELOW MUST COME FIRST (1.20
        // wave 3E): the refusals driven here leave services that now
        // roll their own delegated frame back UNCONDITIONALLY, and this
        // test carries on committing afterwards. On PostgreSQL
        // advanced_testcase holds a transaction underneath for the
        // whole test, so that rollback is not the top level: it pops,
        // leaves force_rollback set, and the next allow_commit() raises
        // "Tried to commit transaction after lower level rollback". In
        // production nothing is underneath, the rollback empties the
        // stack and force_rollback is cleared - which is the cascade
        // the fix restores.
        $this->preventResetByRollback();
        $this->redirectMessages();
        [$activity, $group, $leader, $guides] = $this->setup_world();

        // Somebody who is not the leader cannot approach for the team.
        $other = $this->getDataGenerator()->create_user();
        $this->assert_refused('refusalnotleader', fn() => contacts::send(
            $activity,
            $group,
            (int) $guides[0]->id,
            'please',
            FORMAT_PLAIN,
            (int) $other->id
        ));

        $first = contacts::send(
            $activity,
            $group,
            (int) $guides[0]->id,
            'We think you suit our work',
            FORMAT_PLAIN,
            (int) $leader->id
        );
        $this->assertSame(contacts::STATUS_SENT, $first->status);
        $this->assertSame(1, contacts::remaining($activity, (int) $group->id));

        // The same guide twice is refused.
        $this->assert_refused('refusalcontactduplicate', fn() => contacts::send(
            $activity,
            $group,
            (int) $guides[0]->id,
            'again',
            FORMAT_PLAIN,
            (int) $leader->id
        ));

        // The second is allowed; the third is over the limit of two.
        contacts::send($activity, $group, (int) $guides[1]->id, 'you too', FORMAT_PLAIN, (int) $leader->id);
        $this->assertSame(0, contacts::remaining($activity, (int) $group->id));
        $this->assert_refused('refusalcontactmax', fn() => contacts::send(
            $activity,
            $group,
            (int) $guides[2]->id,
            'and you',
            FORMAT_PLAIN,
            (int) $leader->id
        ));

        // A team that already has a guide has nobody to approach.
        $DB->set_field('selfselectadvanced_group', 'guideid', $guides[0]->id, ['id' => $group->id]);
        $withguide = groups::get($activity, (int) $group->id);
        $this->assert_refused('refusalcontacthasguide', fn() => contacts::send(
            $activity,
            $withguide,
            (int) $guides[2]->id,
            'hello',
            FORMAT_PLAIN,
            (int) $leader->id
        ));
    }

    /**
     * Accepting makes the guide the team's, through the same service a
     * manager's assignment uses, so every rule still applies.
     */
    public function test_accepting_assigns_the_guide(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, $guides] = $this->setup_world();

        $contact = contacts::send(
            $activity,
            $group,
            (int) $guides[0]->id,
            'please guide us',
            FORMAT_PLAIN,
            (int) $leader->id
        );

        // Only the guide who was approached may answer.
        $this->assert_refused('refusalcontactnotyours', fn() => contacts::respond(
            $activity,
            (int) $contact->id,
            true,
            '',
            FORMAT_PLAIN,
            (int) $guides[1]->id
        ));

        $answered = contacts::respond(
            $activity,
            (int) $contact->id,
            true,
            'Happy to take this on',
            FORMAT_PLAIN,
            (int) $guides[0]->id
        );
        $this->assertSame(contacts::STATUS_ACCEPTED, $answered->status);
        $this->assertSame(
            (int) $guides[0]->id,
            (int) groups::get($activity, (int) $group->id)->guideid
        );

        // Answered once, answered for good.
        $this->assert_refused('refusalcontactanswered', fn() => contacts::respond(
            $activity,
            (int) $contact->id,
            false,
            '',
            FORMAT_PLAIN,
            (int) $guides[0]->id
        ));
    }

    /**
     * Declining leaves the team guideless and free to try somebody
     * else, and the reason - or its absence - reaches them.
     */
    public function test_declining_leaves_the_team_free(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $leader, $guides] = $this->setup_world();

        $contact = contacts::send(
            $activity,
            $group,
            (int) $guides[0]->id,
            'please guide us',
            FORMAT_PLAIN,
            (int) $leader->id
        );
        $answered = contacts::respond(
            $activity,
            (int) $contact->id,
            false,
            'My load is full this term',
            FORMAT_PLAIN,
            (int) $guides[0]->id
        );

        $this->assertSame(contacts::STATUS_DECLINED, $answered->status);
        $this->assertEmpty(groups::get($activity, (int) $group->id)->guideid);

        // The refusal reached the leader, carrying the reason and no
        // address of any kind.
        $messages = array_values(array_filter(
            $sink->get_messages(),
            static fn($m) => (int) $m->useridto === (int) $leader->id
        ));
        $this->assertNotEmpty($messages);
        $body = end($messages)->fullmessage;
        $this->assertStringContainsString('My load is full this term', $body);
        $this->assertStringNotContainsString('@', $body);

        // One approach spent, one left: they may try somebody else.
        $this->assertSame(1, contacts::remaining($activity, (int) $group->id));
        $second = contacts::send(
            $activity,
            groups::get($activity, (int) $group->id),
            (int) $guides[1]->id,
            'and you?',
            FORMAT_PLAIN,
            (int) $leader->id
        );
        $this->assertSame(contacts::STATUS_SENT, $second->status);
    }

    /**
     * With the limit at zero the approach is off altogether.
     */
    public function test_zero_limit_turns_the_approach_off(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, $guides] = $this->setup_world(['contactmax' => 0]);

        $this->assert_refused('refusalcontactdisabled', fn() => contacts::send(
            $activity,
            $group,
            (int) $guides[0]->id,
            'please',
            FORMAT_PLAIN,
            (int) $leader->id
        ));
        $this->assertSame(0, contacts::remaining($activity, (int) $group->id));
    }
}
