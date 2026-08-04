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

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\guidenotes;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\state;

/**
 * The guide-notes service (AUTH-002): the last direct table write on
 * review.php, moved behind the same envelope as the award - lock,
 * re-read through the activity, can_grade_team() on the actor, both
 * note fields in one transaction, event after commit and release.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\guidenotes
 */
final class guidenotes_test extends \advanced_testcase {
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
     * A course with two guides and a team assigned to the first,
     * carrying pre-existing notes to be preserved or overwritten.
     *
     * @return array [activity, group row, assigned guide, other guide, course]
     */
    private function world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $mine = $generator->create_user();
        $other = $generator->create_user();
        $generator->enrol_user($mine->id, $course->id, 'teacher');
        $generator->enrol_user($other->id, $course->id, 'teacher');
        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        $group = $generator->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Notes Team',
            'guideid' => (int) $mine->id,
            'state' => state::PENDING_GUIDE,
        ]);
        global $DB;
        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => (int) $group->id,
            'guidenotes' => '<p>original</p>',
            'guidenotesformat' => FORMAT_HTML,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $mine, $other, $course];
    }

    /**
     * The assigned guide saves text and format in one act, and the
     * event arrives after the release with the write already readable
     * and no notes text in its payload.
     */
    public function test_the_assigned_guide_saves_both_fields_atomically(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, $mine] = $this->world();

        $seen = [];
        $probe = static function (\core\event\base $event) use (&$seen): void {
            global $DB;
            $seen[] = [
                'locks' => locks::held_count(),
                'row' => $DB->get_record('selfselectadvanced_group', ['id' => $event->objectid]),
                'data' => $event->get_data(),
            ];
        };
        \core\event\manager::phpunit_replace_observers([
            ['eventname' => '\mod_selfselectadvanced\event\guide_notes_updated', 'callback' => $probe],
        ]);

        guidenotes::save($activity, $group, 'plain replacement', FORMAT_PLAIN, (int) $mine->id);
        \core\event\manager::phpunit_reset();

        $row = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame('plain replacement', $row->guidenotes);
        // Cast both sides: Moodle's FORMAT_* constants are strings.
        $this->assertSame((int) FORMAT_PLAIN, (int) $row->guidenotesformat);
        $this->assertSame((int) $mine->id, (int) $row->usermodified);

        $this->assertCount(1, $seen, 'guide_notes_updated must fire exactly once per save');
        $this->assertSame(0, $seen[0]['locks'], 'the event was dispatched while a plugin lock was held');
        $this->assertSame('plain replacement', $seen[0]['row']->guidenotes, 'the write must precede the dispatch');
        $this->assertSame((int) $mine->id, (int) $seen[0]['data']['userid']);
        $this->assertSame($group->pluginuid, $seen[0]['data']['other']['pluginuid']);
        // Private working notes never travel into the log.
        $this->assertStringNotContainsString('plain replacement', json_encode($seen[0]['data']));
        $this->assertSame(0, locks::held_count(), 'the service left a lock behind');
    }

    /**
     * A guide who is not assigned to the team is refused inside the
     * service, and the row is untouched.
     */
    public function test_a_non_assigned_guide_is_refused_and_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, , $other] = $this->world();

        try {
            guidenotes::save($activity, $group, 'tampered', FORMAT_HTML, (int) $other->id);
            $this->fail('Expected the not-assigned-guide refusal');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('assigned', $e->getMessage());
        }

        $row = $DB->get_record('selfselectadvanced_group', ['id' => (int) $group->id], '*', MUST_EXIST);
        $this->assertSame('<p>original</p>', $row->guidenotes);
        $this->assertSame((int) FORMAT_HTML, (int) $row->guidenotesformat);
        $this->assertSame(0, locks::held_count());
    }

    /**
     * The AUTH-002 race pinned: the page's predicate passed on a row
     * that has since been reassigned, and the service - judging the
     * row re-read under the lock, not the caller's stale copy -
     * refuses the former guide instead of letting them overwrite the
     * new guide's notes.
     */
    public function test_a_stale_page_cannot_outlive_a_reassignment(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, $mine, $other] = $this->world();

        // The $group copy still names $mine as the guide - the row a
        // loaded review page holds. The reassignment lands "in another
        // session" before the POST arrives.
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $other->id, ['id' => (int) $group->id]);

        try {
            guidenotes::save($activity, $group, 'stale overwrite', FORMAT_HTML, (int) $mine->id);
            $this->fail('Expected the stale author to be refused on the re-read row');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('assigned', $e->getMessage());
        }

        $this->assertSame(
            '<p>original</p>',
            $DB->get_field('selfselectadvanced_group', 'guidenotes', ['id' => (int) $group->id]),
            'the new guide\'s notes must survive the stale POST'
        );
    }

    /**
     * A manager keeps administrative access, the same grant the award
     * half has always had.
     */
    public function test_a_manager_may_save_notes(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, , , $course] = $this->world();

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'editingteacher');

        guidenotes::save($activity, $group, 'manager correction', FORMAT_PLAIN, (int) $manager->id);

        $this->assertSame(
            'manager correction',
            $DB->get_field('selfselectadvanced_group', 'guidenotes', ['id' => (int) $group->id])
        );
    }

    /**
     * A team belonging to another activity is a missing record, not a
     * cross-activity write - groups::get() is activity-scoped.
     */
    public function test_a_foreign_team_is_a_missing_record(): void {
        global $DB;
        $this->resetAfterTest();
        [, $group, $mine, , $course] = $this->world();

        $otherinstance = $this->getDataGenerator()->create_module('selfselectadvanced', [
            'course' => $course->id,
        ]);
        $otheractivity = activity::from_instance((int) $otherinstance->id);

        $this->expectException(\dml_missing_record_exception::class);
        guidenotes::save($otheractivity, $group, 'foreign', FORMAT_HTML, (int) $mine->id);
    }
}
