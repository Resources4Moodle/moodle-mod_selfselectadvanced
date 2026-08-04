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
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\proposal;

/**
 * The three 1.20.3 services dispatch their events AFTER their critical
 * section: with every plugin lock released, and with the written state
 * already in place where the observer reads (the binding rule -
 * docs/architecture.md, "Events under a lock"; EVT-001).
 *
 * Probed with locks::held_count() from INSIDE the observer, never with
 * $DB->is_transaction_started(): advanced_testcase holds a transaction
 * open for the whole test on PostgreSQL, so that flag says "true"
 * forever there and "false" on MariaDB, and a test built on it would
 * assert nothing on one engine and the wrong thing on the other. The
 * after-commit half is asserted by what IS observable under a test
 * transaction - the observer re-reads the row and finds the write, and
 * held_count() === 0 places the dispatch after the release, which the
 * services sequence after allow_commit(). Move any trigger() back
 * inside the lock and the held_count probe goes red on both engines.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\api::update_group_details
 * @covers     \mod_selfselectadvanced\local\eoi::set_listed
 * @covers     \mod_selfselectadvanced\local\proposal::save
 */
final class service_event_dispatch_test extends \advanced_testcase {
    /**
     * A clean held-lock set per test.
     */
    protected function setUp(): void {
        parent::setUp();
        locks::reset_state();
    }

    /**
     * Release anything a failed test left behind.
     */
    protected function tearDown(): void {
        locks::reset_state();
        parent::tearDown();
    }

    /**
     * A course, an EOI-enabled activity, a staff member and a forming
     * team led by a student.
     *
     * @return array [activity, group row, teacher, leader]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'eoienabled' => 1,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
        ]);

        return [$activity, $group, $teacher, $leader];
    }

    /**
     * Each of the three events arrives with no plugin lock held and
     * with its service's write already readable.
     */
    public function test_the_three_events_fire_outside_locks_with_the_write_visible(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        [$activity, $group, $teacher, $leader] = $this->world();

        $seen = [];
        $probe = static function (\core\event\base $event) use (&$seen): void {
            global $DB;
            $data = $event->get_data();
            $name = substr((string) strrchr($data['eventname'], '\\'), 1);
            // APPENDED, never keyed: a service that dispatched twice -
            // once inside the lock and once after it - would overwrite
            // the incriminating record if the latest occurrence won.
            $seen[$name][] = [
                'locks' => locks::held_count(),
                'row' => $DB->get_record('selfselectadvanced_group', ['id' => $data['objectid']]),
            ];
        };
        \core\event\manager::phpunit_replace_observers([
            [
                'eventname' => '\mod_selfselectadvanced\event\group_details_updated',
                'callback' => $probe,
            ],
            [
                'eventname' => '\mod_selfselectadvanced\event\group_listing_changed',
                'callback' => $probe,
            ],
            [
                'eventname' => '\mod_selfselectadvanced\event\proposal_updated',
                'callback' => $probe,
            ],
        ]);

        // 1. update_group_details, on the staff branch.
        (new api($activity))->update_group_details($group, 'Revised title', 'Revised brief', FORMAT_HTML, (int) $teacher->id);

        // 2. set_listed, on the leader branch (the only one it has).
        eoi::set_listed($activity, (int) $group->id, true, (int) $leader->id);

        // 3. proposal::save on the retraction branch: an empty draft
        // area is a deletion, and staff may always retract.
        $this->setUser($teacher);
        $draftid = file_get_unused_draft_itemid();
        $fileoptions = ['subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 5];
        proposal::save($activity, (int) $group->id, $draftid, $fileoptions, (int) $teacher->id);

        \core\event\manager::phpunit_reset();
        $sink->close();

        foreach (
            [
                'group_details_updated',
                'group_listing_changed',
                'proposal_updated',
            ] as $name
        ) {
            $this->assertArrayHasKey($name, $seen, "$name never fired, so nothing below asserts anything about it");
            $this->assertCount(1, $seen[$name], "$name was dispatched more than once for one service call");
            $this->assertSame(0, $seen[$name][0]['locks'], "$name was dispatched while a plugin lock was held");
        }
        // The write each event announces was in place when its
        // observer read the row.
        $this->assertSame('Revised title', $seen['group_details_updated'][0]['row']->title);
        $this->assertSame(1, (int) $seen['group_listing_changed'][0]['row']->listed);
        $this->assertSame(0, locks::held_count(), 'a service left a lock behind');
    }
}
