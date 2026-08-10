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

use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\override\store;

/**
 * Accepting a join request sweeps parked overrides OUTSIDE every lock
 * (audit O-3).
 *
 * T-08 windowed that sweep so a join accept stopped examining every
 * pending row of the activity, but left it where it was: called from
 * do_accept(), which runs inside respond()'s joinrequest:{id} lock. So
 * every override_updated the sweep fired travelled under a lock, and it
 * took override: locks of its own underneath that one. Exactly three
 * events in this plugin are grandfathered inside a lock -
 * move_committed, leadership_transferred and join_decided - and a NEW
 * event inside a lock is a defect.
 *
 * Probed with locks::held_count() from INSIDE the observer, never with
 * $DB->is_transaction_started(): advanced_testcase holds a transaction
 * open for the whole test on PostgreSQL, so that flag says "true"
 * forever there and "false" on MariaDB, and a test built on it would
 * assert nothing on one engine and the wrong thing on the other.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\joinrequests::respond
 */
final class joinrequest_sweep_lock_test extends \advanced_testcase {
    /**
     * Two teams, a wanderer confirmed in the first, and the second's
     * leader to answer.
     *
     * @return array [activity, alpha, beta, wanderer]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'JRSWEEP']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 1,
            // Two since decision 77: this file is about the SWEEP that runs
            // after an acceptance, and the wanderer already belongs to Alpha.
            // At a cap of one the join is refused before any sweep happens and
            // every test here would measure nothing.
            'maxmembership' => 2,
            // A window that OPENS after the override below is due, so
            // the override parks the moment it is written.
            'timeopen' => 1000,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        coordinatorrole::ensure();

        $make = function () use ($generator, $course) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');

            return $user;
        };
        $alphalead = $make();
        $betalead = $make();
        $wanderer = $make();

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
        $plugingen->create_member([
            'groupid' => $alpha->id,
            'userid' => (int) $wanderer->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [
            $activity,
            groups::get($activity, (int) $alpha->id),
            groups::get($activity, (int) $beta->id),
            $wanderer,
        ];
    }

    /**
     * The sweep's activation event fires with no lock held.
     *
     * The scenario is the real one: a student carries a parked
     * override, the conflict that parked it goes away, and the next
     * thing that happens to them is a join request being ACCEPTED -
     * the production entry point, joinrequests::respond(), which is
     * where the sweep lives. The row heals, and the event announcing
     * it is dispatched with locks::held_count() === 0.
     */
    public function test_the_sweep_fires_its_event_outside_every_lock(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        // Parked: due before the activity opens.
        $row = store::save($activity, 'user', (int) $wanderer->id, ['timedue' => 500], 2);
        $this->assertSame('pending', $row->status, 'the fixture did not park the override');

        // The conflict goes away underneath it. Nothing has swept yet,
        // so the row is still pending and still healable - which is
        // precisely the state the accept path inherits.
        $DB->set_field('selfselectadvanced', 'timeopen', 0, ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $row->id])
        );

        $seen = [];
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_selfselectadvanced\event\override_updated',
            'callback' => static function ($event) use (&$seen): void {
                $data = $event->get_data();
                $seen[] = [
                    'locks' => locks::held_count(),
                    'to' => $data['other']['newvalues']['status'] ?? '',
                ];
            },
        ]]);

        $request = joinrequests::request($activity, (int) $beta->id, 'Closer to my programme', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);

        \core\event\manager::phpunit_reset();
        $sink->close();

        // The move really happened, so the sweep really ran on the
        // accept path and not on some earlier write.
        $joined = array_map('intval', array_keys(joinrequests::current_groups($activity, (int) $wanderer->id)));
        sort($joined);
        $expected = [(int) $alpha->id, (int) $beta->id];
        sort($expected);
        $this->assertSame($expected, $joined);
        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $row->id]),
            'the sweep did not run at all, so the lock assertion below would prove nothing'
        );
        $this->assertSame([['locks' => 0, 'to' => 'active']], $seen);
        $this->assertSame(0, locks::held_count(), 'respond() left a lock behind');
        // The request names no team to leave (decision 77), and the fixture
        // keeps $alpha only so the membership assertions above can name it.
        $this->assertNull($request->sourcegroupid);
    }

    /**
     * A DECLINE sweeps nothing.
     *
     * The sweep exists because a committed move can clear a blocker.
     * Turning a request down moves nobody, so running it would be a
     * query - and a lock per pending row - bought for a state change
     * that did not happen.
     */
    public function test_a_decline_does_not_sweep(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $row = store::save($activity, 'user', (int) $wanderer->id, ['timedue' => 500], 2);
        $this->assertSame('pending', $row->status);
        $DB->set_field('selfselectadvanced', 'timeopen', 0, ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());

        $request = joinrequests::request($activity, (int) $beta->id, 'Please', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, false, 'Not this time', (int) $beta->leaderid);
        $sink->close();

        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $row->id]),
            'a decline swept the activity for no reason'
        );
        // The matched partner: the row is healable, so what left it
        // pending is the decline and not an unclearable blocker.
        $this->assertSame([], store::recheck_pending($activity, 2, ['user' => [(int) $wanderer->id]]));
        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $row->id])
        );
        // The request names no team to leave (decision 77), and the fixture
        // keeps $alpha only so the membership assertions above can name it.
        $this->assertNull($request->sourcegroupid);
    }

    /**
     * The sweep is still WINDOWED (T-08): accepting one request must
     * not re-price a stranger's parked override.
     *
     * Moving the call out of the lock is a change of PLACE, and this
     * says the restriction travelled with it - a regression that would
     * otherwise only show up as a slow commit on a busy activity.
     */
    public function test_the_sweep_still_only_touches_what_moved(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $alpha, $beta, $wanderer] = $this->setup_world();

        $stranger = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user(
            $stranger->id,
            $DB->get_field('course_modules', 'course', ['id' => $activity->cm()->id]),
            'student'
        );

        $mine = store::save($activity, 'user', (int) $wanderer->id, ['timedue' => 500], 2);
        $theirs = store::save($activity, 'user', (int) $stranger->id, ['timedue' => 500], 2);
        $this->assertSame('pending', $mine->status);
        $this->assertSame('pending', $theirs->status);

        $DB->set_field('selfselectadvanced', 'timeopen', 0, ['id' => $activity->id()]);
        $activity = activity::from_instance($activity->id());

        $request = joinrequests::request($activity, (int) $beta->id, 'Closer', (int) $wanderer->id);
        joinrequests::respond($activity, (int) $request->id, true, 'Welcome', (int) $beta->leaderid);
        $sink->close();

        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $mine->id])
        );
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $theirs->id]),
            'the accept swept a row its move set never touched'
        );
        // The request names no team to leave (decision 77), and the fixture
        // keeps $alpha only so the membership assertions above can name it.
        $this->assertNull($request->sourcegroupid);
    }
}
