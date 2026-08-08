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
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\override\consistency;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/selfselectadvanced/lib.php');

/**
 * The 4A.7 invariants on the MERGED effective tuple (finding-9).
 *
 * Override precedence resolves each field independently, so a
 * single-field write that is individually valid still produces an
 * effective tuple with timeopen > timedue, timedue > timecutoff,
 * minsize > maxsize or maxlead > maxmembership. Nothing on any write
 * path recomputed that merge: the activity settings form and one
 * override row's simultaneously submitted fields were the only places
 * a whole tuple was ever visible. These tests pin the seam that closes
 * it - the row parks 'pending' and the resolver never serves the
 * inconsistent merge - plus the settings-edit sweep, the form's inline
 * pre-check, and the event that had to move out of the lock.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\override\consistency
 * @covers     \mod_selfselectadvanced\local\override\store
 */
final class override_consistency_test extends \advanced_testcase {
    /**
     * A course, an instance, four enrolled students and one group led
     * by student 0 (whose confirmed member row the generator adds).
     *
     * @param array $settings instance setting overrides
     * @return array [activity, students[], group, plugin generator]
     */
    private function setup_activity(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 2,
            'maxsize' => 4,
            'maxlead' => 1,
            'maxmembership' => 1,
            'maxguided' => 5,
            'timeopen' => 1000,
            'timedue' => 2000,
            'timecutoff' => 3000,
        ], $settings));

        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Ovr',
            'state' => state::FORMING,
        ]);

        return [$activity, $students, groups::get($activity, (int) $group->id), $plugingen];
    }

    /**
     * The guide-cap reduction guard measures the COMMITMENTS basis -
     * guided teams PLUS forming pre-assignments - exactly as the
     * enforcement gate does (seam audit H2, 1.20.19). A guide with one
     * guided and two forming pre-assigned teams is at 3 commitments; a
     * reduction to 2 must PARK with the blocker naming 3, where the
     * old guided-states-only count saw 1 and activated it silently.
     *
     * MUTATION CAUGHT (run): restoring the private guided-states count
     * in guard::blockers() lets this reduction activate.
     */
    public function test_guide_cap_reduction_measures_commitments(): void {
        $this->resetAfterTest();
        [$activity, $students, , $plugingen] = $this->setup_activity();
        $guide = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($guide->id, (int) $activity->cm()->course, 'teacher');
        $mkteam = function (string $name, string $state) use ($activity, $plugingen, $guide, $students) {
            static $i = 1;
            $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $students[$i++]->id,
                'name' => $name,
                'state' => $state,
                'guideid' => (int) $guide->id,
            ]);
        };
        $mkteam('Guided', state::FIRM);
        $mkteam('Forming A', state::FORMING);
        $mkteam('Forming B', state::FORMING);

        $record = store::save($activity, 'guide', (int) $guide->id, ['maxguided' => 2], 2);

        $this->assertSame('pending', $record->status, 'the reduction parks instead of stranding');
        $this->assertCount(1, $record->blockers);
        $this->assertSame('maxguided', $record->blockers[0]->rule);
        $this->assertSame(
            3,
            (int) $record->blockers[0]->current,
            'measured on commitments - guided plus forming pre-assignments - like the gate'
        );
    }

    /**
     * The most common override of all - "extend this one student" -
     * written as a lone timedue, merged against the activity's own
     * timeopen. Nothing validated that merge, so a window that opens
     * after it closes used to become the effective one.
     *
     * Negative control (RUN): revert store::save()'s array_merge to
     * guard::blockers() alone - status is 'active' and the resolver
     * serves 500.
     */
    public function test_lone_due_before_open_parks(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();
        $userid = (int) $students[1]->id;

        $record = store::save($activity, 'user', $userid, ['timedue' => 500], 2);

        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $description = $record->blockers[0]->description;
        $this->assertStringContainsString(
            get_string('overridefieldtimeopen', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridesourceactivity', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridesourcethis', 'mod_selfselectadvanced'),
            $description
        );

        // The inconsistent merge is never served.
        $this->assertSame(2000, (new resolver($activity))->effective_dates($userid)->timedue);
    }

    /**
     * The verified damage from the finding: an extension granted past a
     * cutoff nobody restated, silently amputated at the stale cutoff by
     * gatekeeper::check_window while the penalty calculator used the
     * new deadline.
     *
     * Negative control (RUN): same revert - 'active', and the resolver
     * serves a timedue 500 seconds past its own cutoff.
     */
    public function test_extension_beyond_cutoff_parks(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();
        $userid = (int) $students[1]->id;

        $record = store::save($activity, 'user', $userid, ['timedue' => 3500], 2);

        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $description = $record->blockers[0]->description;
        $this->assertStringContainsString(
            get_string('overridefieldtimedue', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridefieldtimecutoff', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridesourceactivity', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertSame(2000, (new resolver($activity))->effective_dates($userid)->timedue);
    }

    /**
     * The cross-scope merge nobody validated: a group's timeopen and a
     * member's own timedue only ever meet inside
     * resolver::effective_dates($user, $group), and each row was
     * individually valid when written. Group fields win, exactly as the
     * resolver resolves them, so the pair that conflicts is the group's
     * open against this user's due.
     *
     * Negative controls (RUN): revert the store merge - the user row
     * saves 'active'; revert only recheck_pending()'s merge - the row
     * is no longer returned as still-pending by the FIRST recheck.
     */
    public function test_cross_scope_group_open_vs_user_due(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students, $group] = $this->setup_activity([
            'timedue' => 0,
            'timecutoff' => 0,
        ]);
        $leaderid = (int) $students[0]->id;

        $grouprow = store::save($activity, 'group', (int) $group->id, ['timeopen' => 5000], 2);
        $this->assertSame('active', $grouprow->status);

        $userrow = store::save($activity, 'user', $leaderid, ['timedue' => 4000], 2);
        $this->assertSame('pending', $userrow->status);
        $this->assertCount(1, $userrow->blockers);
        $description = $userrow->blockers[0]->description;
        $this->assertStringContainsString(
            get_string('overridesourcegroup', 'mod_selfselectadvanced', format_string($group->name)),
            $description
        );
        $this->assertStringContainsString(
            get_string('overrideblockertuplefor', 'mod_selfselectadvanced', format_string($group->name)),
            $description
        );
        // The fix link points at the counterpart row, not at this one.
        $this->assertStringContainsString(
            'override=' . $grouprow->id,
            $userrow->blockers[0]->fixurl->out(false)
        );

        // Still conflicting: the sweep leaves it parked.
        $stillpending = store::recheck_pending($activity, 2);
        $this->assertCount(1, $stillpending);
        $this->assertSame((int) $userrow->id, (int) $stillpending[0]->id);

        // Clear the group's window: the pairing that conflicted is gone.
        store::save($activity, 'group', (int) $group->id, ['timeopen' => null], 2);
        $this->assertSame([], store::recheck_pending($activity, 2));
        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $userrow->id])
        );
        $this->assertSame(4000, (new resolver($activity))->effective_dates($leaderid)->timedue);
    }

    /**
     * Both directions of the numeric pair, neither of which the
     * occupancy guard sees: guard.php is deliberately reductions-only
     * (a maxsize of 3 over 1 occupied seat passes it), and a minsize
     * INCREASE is not a reduction at all. Tuple order is not exempt
     * from either asymmetry.
     *
     * Negative control (RUN): revert the store merge - both rows save
     * 'active' with no blockers.
     */
    public function test_minsize_maxsize_both_directions(): void {
        $this->resetAfterTest();
        [$activity, $students, $group, $plugingen] = $this->setup_activity([
            'minsize' => 4,
            'maxsize' => 6,
        ]);

        // Case (a): a lone maxsize UNDER the activity's minsize.
        $record = store::save($activity, 'group', (int) $group->id, ['maxsize' => 3], 2);
        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $this->assertSame(4, (int) $record->blockers[0]->current);
        $this->assertSame(3, (int) $record->blockers[0]->limit);
        $description = $record->blockers[0]->description;
        $this->assertStringContainsString(
            get_string('overridefieldminsize', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridesourceactivity', 'mod_selfselectadvanced'),
            $description
        );

        // Case (b): a lone minsize OVER the activity's maxsize, on a
        // team of its own so the two cases cannot lean on each other.
        $second = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[1]->id,
            'name' => 'Second',
            'state' => state::FORMING,
        ]);
        $record = store::save($activity, 'group', (int) $second->id, ['minsize' => 8], 2);
        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $this->assertSame(8, (int) $record->blockers[0]->current);
        $this->assertSame(6, (int) $record->blockers[0]->limit);

        // Neither is resolvable while it is parked.
        $resolver = new resolver($activity);
        $this->assertSame(6, $resolver->effective_maxsize((int) $group->id)->value);
        $this->assertSame(4, $resolver->effective_minsize((int) $second->id)->value);
    }

    /**
     * The user-scope pair, and the consistent case that must keep
     * activating beside it.
     *
     * Negative control (RUN): revert the store merge - the first save
     * comes back 'active'.
     */
    public function test_maxlead_above_maxmembership(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();

        $record = store::save($activity, 'user', (int) $students[1]->id, ['maxlead' => 3], 2);
        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $description = $record->blockers[0]->description;
        $this->assertStringContainsString(
            get_string('overridefieldmaxlead', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridefieldmaxmembership', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridesourcethis', 'mod_selfselectadvanced'),
            $description
        );
        $this->assertStringContainsString(
            get_string('overridesourceactivity', 'mod_selfselectadvanced'),
            $description
        );

        // A consistent pair is untouched.
        $record = store::save(
            $activity,
            'user',
            (int) $students[2]->id,
            ['maxlead' => 2, 'maxmembership' => 2],
            2
        );
        $this->assertSame('active', $record->status);
        $this->assertSame([], $record->blockers);
    }

    /**
     * The checker sees the POST-MERGE record, not the submitted delta:
     * clearing one side of a pair lets it fall through to the activity
     * setting, which is exactly when the pair can start conflicting.
     *
     * Negative control (RUN): revert the store merge - the second save
     * is 'active' and the resolver serves minsize 4 with maxsize 3.
     */
    public function test_clearing_a_field_parks(): void {
        $this->resetAfterTest();
        [$activity, , $group] = $this->setup_activity([
            'minsize' => 4,
            'maxsize' => 6,
        ]);

        $record = store::save($activity, 'group', (int) $group->id, ['minsize' => 2, 'maxsize' => 3], 2);
        $this->assertSame('active', $record->status);

        $record = store::save($activity, 'group', (int) $group->id, ['minsize' => null, 'maxsize' => 3], 2);
        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $this->assertSame(4, (int) $record->blockers[0]->current);
        $this->assertSame(3, (int) $record->blockers[0]->limit);
    }

    /**
     * The formless writers keep working: guide scope has no tuple
     * partner at all (grant_guidecap), and the autoapprove sweep's
     * relief shape is tuple-consistent by construction - its minsize is
     * reduced to at most the confirmed count, which cannot exceed the
     * effective maxsize.
     */
    public function test_formless_writers_unaffected(): void {
        $this->resetAfterTest();
        [$activity, $students, $group] = $this->setup_activity();

        $guiderow = store::save($activity, 'guide', (int) $students[3]->id, ['maxguided' => 0], 2);
        $this->assertSame('active', $guiderow->status);
        $this->assertSame([], $guiderow->blockers);
        $this->assertSame([], consistency::violations($activity, $guiderow));

        $relief = store::save(
            $activity,
            'group',
            (int) $group->id,
            ['minsize' => 1, 'quotaexempt' => 1],
            2
        );
        $this->assertSame('active', $relief->status);
        $this->assertSame([], $relief->blockers);

        // A move-scope row is the staff hatch's own business.
        $moveid = (int) $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')
            ->create_move([
                'activityid' => $activity->id(),
                'userid' => (int) $students[1]->id,
                'targetgroupid' => (int) $group->id,
                'actorid' => 2,
            ])->id;
        $moverow = store::save($activity, 'move', $moveid, ['rulesbypassed' => 'L2'], 2);
        $this->assertSame('active', $moverow->status);
        $this->assertSame([], consistency::violations($activity, $moverow));
    }

    /**
     * The settings-edit hole: a row consistent when it was written
     * becomes inconsistent because the fallthrough underneath it moved.
     * Nobody touched the row, so no write seam could have caught it -
     * the edit itself has to sweep.
     *
     * Negative control (RUN): remove the park_inconsistent() call from
     * lib.php - the row stays 'active' and the resolver serves 3500.
     */
    public function test_settings_edit_parks_active_rows(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity([
            'timedue' => 2000,
            'timecutoff' => 9000,
        ]);
        $first = (int) $students[1]->id;
        $second = (int) $students[2]->id;

        $rowone = store::save($activity, 'user', $first, ['timedue' => 3500], 2);
        $rowtwo = store::save($activity, 'user', $second, ['timedue' => 4500], 2);
        $this->assertSame('active', $rowone->status);
        $this->assertSame('active', $rowtwo->status);

        // The teacher pulls the cutoff in, restating nothing else.
        $instance = $DB->get_record('selfselectadvanced', ['id' => $activity->id()], '*', MUST_EXIST);
        $data = (object) (array) $instance;
        $data->instance = (int) $instance->id;
        $data->timecutoff = 3000;
        $sink = $this->redirectEvents();
        selfselectadvanced_update_instance($data);
        $parkevents = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\override_updated
        ));
        $sink->close();

        // BOTH rows are parked - the pass keeps going after the first.
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $rowone->id])
        );
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $rowtwo->id])
        );
        $this->assertCount(2, $parkevents);
        $this->assertSame(
            ['status' => 'active'],
            $parkevents[0]->get_data()['other']['oldvalues']
        );
        $this->assertSame(
            ['status' => 'pending'],
            $parkevents[0]->get_data()['other']['newvalues']
        );

        $fresh = activity::from_instance($activity->id());
        $this->assertSame(2000, (new resolver($fresh))->effective_dates($first)->timedue);
    }

    /**
     * The form is courtesy, the seam is law - but the courtesy has to
     * name the effective counterpart and where it came from, or an
     * admin is told only that something is wrong.
     *
     * Negative control (RUN): revert override_form::validation()'s
     * tuple_errors() call - no 'timedue' error key at all.
     */
    public function test_form_validation_names_sources(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$activity, $students] = $this->setup_activity();

        $form = new \mod_selfselectadvanced\form\override_form(null, [
            'cmid' => $activity->cm()->id,
            'mode' => 'user',
            'overrideid' => 0,
            'targetmodule' => 'mod_selfselectadvanced/participantselector',
            'targetid' => 0,
            'targetlabel' => '',
            'activity' => $activity,
        ]);

        $errors = $form->validation([
            'target' => (int) $students[1]->id,
            'timeopen' => 0,
            'timedue' => 500,
            'timecutoff' => 0,
            'maxlead' => '',
            'maxmembership' => '',
        ], []);

        $this->assertArrayHasKey('timedue', $errors);
        $this->assertStringContainsString(
            get_string('overridesourceactivity', 'mod_selfselectadvanced'),
            $errors['timedue']
        );
        $this->assertStringContainsString(
            get_string('overridefieldtimeopen', 'mod_selfselectadvanced'),
            $errors['timedue']
        );

        // A consistent submission raises nothing new.
        $clean = $form->validation([
            'target' => (int) $students[1]->id,
            'timeopen' => 0,
            'timedue' => 2500,
            'timecutoff' => 0,
            'maxlead' => '',
            'maxmembership' => '',
        ], []);
        $this->assertArrayNotHasKey('timedue', $clean);
    }

    /**
     * Requirement 2: the override event now fires AFTER the commit and
     * after the lock release, and exactly once - moving a trigger is
     * how events get doubled or dropped.
     *
     * Probed from inside the observer with locks::held_count(), never
     * with a zero-timeout get_lock(): locks::acquire() builds a new
     * factory per call, so that probe is granted whether or not the
     * lock is held. And never with is_transaction_started(), which is
     * unconditionally true under PHPUnit on PostgreSQL.
     *
     * Negative control (RUN): leave the trigger inside save()'s try -
     * held_count() is 1 and this goes red.
     */
    public function test_save_event_after_commit(): void {
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();

        $sink = $this->redirectEvents();
        store::save($activity, 'user', (int) $students[1]->id, ['maxlead' => 1], 2);
        $created = array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\override_created
        );
        $sink->close();
        $this->assertCount(1, $created);

        $seen = [];
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_selfselectadvanced\event\override_created',
            'callback' => static function ($event) use (&$seen): void {
                $seen[] = ['locks' => locks::held_count(), 'objectid' => (int) $event->objectid];
            },
        ]]);
        $row = store::save($activity, 'user', (int) $students[2]->id, ['maxlead' => 1], 2);
        \core\event\manager::phpunit_reset();

        $this->assertCount(1, $seen);
        $this->assertSame(0, $seen[0]['locks']);
        $this->assertSame((int) $row->id, $seen[0]['objectid']);
    }

    /**
     * The commit hot path must not sweep an activity's whole pending
     * set to re-price rows its move set never touched, so
     * recheck_pending() takes an optional target restriction. The
     * restriction has to be a filter and nothing more: a row inside it
     * heals, a row outside it is left exactly as it was.
     *
     * Negative control (RUN): drop the $restricttargets clause from the
     * query - the row outside the restriction heals too and the
     * "still pending" assertion fails.
     */
    public function test_recheck_pending_restriction_filters_without_dropping(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();
        $inside = (int) $students[1]->id;
        $outside = (int) $students[2]->id;

        $rowin = store::save($activity, 'user', $inside, ['timedue' => 500], 2);
        $rowout = store::save($activity, 'user', $outside, ['timedue' => 500], 2);
        $this->assertSame('pending', $rowin->status);
        $this->assertSame('pending', $rowout->status);

        // Remove the conflict underneath both of them.
        $DB->set_field('selfselectadvanced', 'timeopen', 0, ['id' => $activity->id()]);
        $fresh = activity::from_instance($activity->id());

        $this->assertSame([], store::recheck_pending($fresh, 2, ['user' => [$inside]]));
        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $rowin->id])
        );
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $rowout->id])
        );

        // Unrestricted, the second one heals as well.
        $this->assertSame([], store::recheck_pending($fresh, 2));
        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $rowout->id])
        );
    }

    /**
     * One conflict, one message. A counterparty whose row supplies
     * NEITHER side of the violated pair merges to exactly the base
     * tuple, so reporting it again per counterparty would print the
     * same sentence once per team the student belongs to - noise that
     * grows with the roster.
     *
     * Negative control (RUN): make date_violations() always attach the
     * counterparty - the blocker count becomes 2 and this goes red.
     */
    public function test_a_base_conflict_is_reported_once_per_pair(): void {
        $this->resetAfterTest();
        [$activity, $students, $group] = $this->setup_activity();
        $leaderid = (int) $students[0]->id;

        // A dated group row that does not touch timeopen or timedue.
        $grouprow = store::save($activity, 'group', (int) $group->id, ['timecutoff' => 5000], 2);
        $this->assertSame('active', $grouprow->status);

        // The leader's own conflict is with the ACTIVITY's timeopen.
        $record = store::save($activity, 'user', $leaderid, ['timedue' => 500], 2);
        $this->assertSame('pending', $record->status);
        $this->assertCount(1, $record->blockers);
        $this->assertStringNotContainsString(
            get_string('overrideblockertuplefor', 'mod_selfselectadvanced', format_string($group->name)),
            $record->blockers[0]->description
        );
    }

    /**
     * The documented residual, and the sweep that catches up with it.
     *
     * Two rows can each be consistent when written and only ever meet
     * because of a membership recorded LATER - a write seam cannot see
     * future joins. The settings-edit sweep re-examines the merge with
     * the roster as it now is, which is where the pairing is finally
     * judged; and because it re-reads the ACTIVE rows per row, the
     * subsequent heal is deterministic: the lower id activates and the
     * other stays parked, rather than both activating into a merge that
     * still contradicts itself.
     *
     * Negative control (RUN): drop the cross-scope block from
     * consistency::violations() - neither row parks and both
     * assertions fail.
     */
    public function test_membership_drift_is_caught_by_the_settings_sweep(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students, $group, $plugingen] = $this->setup_activity([
            'timeopen' => 0,
            'timedue' => 0,
            'timecutoff' => 0,
        ]);
        $joiner = (int) $students[1]->id;

        $grouprow = store::save($activity, 'group', (int) $group->id, ['timeopen' => 5000], 2);
        $userrow = store::save($activity, 'user', $joiner, ['timedue' => 4000], 2);
        // Both are consistent: the student is in no team of this group's.
        $this->assertSame('active', $grouprow->status);
        $this->assertSame('active', $userrow->status);

        // The membership that first pairs them, recorded afterwards.
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => $joiner,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        // Any tuple-field settings edit re-examines the merges.
        $instance = $DB->get_record('selfselectadvanced', ['id' => $activity->id()], '*', MUST_EXIST);
        $data = (object) (array) $instance;
        $data->instance = (int) $instance->id;
        $data->minsize = 3;
        selfselectadvanced_update_instance($data);

        $status = fn(int $id): string => (string) $DB->get_field(
            'selfselectadvanced_override',
            'status',
            ['id' => $id]
        );
        $this->assertSame('pending', $status((int) $grouprow->id));
        $this->assertSame('pending', $status((int) $userrow->id));

        // The heal is deterministic: the lower id wins the pairing.
        $fresh = activity::from_instance($activity->id());
        $stillpending = store::recheck_pending($fresh, 2);
        $this->assertCount(1, $stillpending);
        $this->assertSame((int) $userrow->id, (int) $stillpending[0]->id);
        $this->assertSame('active', $status((int) $grouprow->id));
        $this->assertSame('pending', $status((int) $userrow->id));
    }

    /**
     * The chunk boundary, and why the cursor is a KEYSET.
     *
     * park_inconsistent() walks the ACTIVE rows in chunks so a settings
     * edit on a large activity cannot become one unbounded sweep. The
     * walk is over a set the walk itself SHRINKS: parking a row removes
     * it from status='active'. An offset window therefore steps over
     * exactly as many unexamined rows as the pass has parked, and the
     * rows past the boundary are never judged at all - they keep
     * resolving an effective tuple that contradicts itself, and the gate
     * still reads green because no fixture ever reached row 501.
     *
     * The fixture is built from consistency::CHUNK rather than the
     * literal 500, so it moves with the constant: ten rows that must
     * park inside the first chunk (shrinking the set under the cursor),
     * a consistent body filling that chunk out, and ONE more row past
     * the boundary that must still be found and parked.
     *
     * Negative control (RUN): swap the keyset predicate for $limitfrom
     * (`id > :lastid` -> offset $lastid) - the second pass reads past
     * the end of the shrunken set, returns nothing, and the row beyond
     * the boundary stays active. What this fixture does NOT pin is the
     * sibling rule "loop until a pass returns NO ROWS, not until a pass
     * parks nothing": distinguishing those needs a clean chunk followed
     * by a dirty one, i.e. more than 2 x CHUNK rows.
     */
    public function test_park_inconsistent_walks_past_the_chunk_boundary(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();

        // Activity window is 1000..2000..3000: a timedue of 2500 sits
        // inside it, a timedue of 500 falls before its own timeopen.
        $now = time();
        $rows = [];
        for ($i = 0; $i <= consistency::CHUNK; $i++) {
            $rows[] = (object) [
                'activityid' => $activity->id(),
                'scope' => 'user',
                'userid' => (int) $students[$i % 4]->id,
                'timedue' => ($i < 10 || $i === consistency::CHUNK) ? 500 : 2500,
                'status' => 'active',
                'usermodified' => 2,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }
        $DB->insert_records('selfselectadvanced_override', $rows);
        $ids = array_map('intval', $DB->get_fieldset_sql(
            'SELECT id FROM {selfselectadvanced_override} WHERE activityid = ? ORDER BY id ASC',
            [$activity->id()]
        ));
        $this->assertCount(consistency::CHUNK + 1, $ids);

        $parked = store::park_inconsistent($activity, 2);

        // Ten from inside the first chunk, and the one beyond it.
        $this->assertCount(11, $parked);
        $this->assertSame(
            array_merge(array_slice($ids, 0, 10), [$ids[consistency::CHUNK]]),
            array_map(static fn($row) => (int) $row->id, $parked)
        );
        $this->assertSame(11, $DB->count_records('selfselectadvanced_override', [
            'activityid' => $activity->id(),
            'status' => 'pending',
        ]));
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $ids[consistency::CHUNK]])
        );
    }

    /**
     * A pair NEITHER of whose sides the candidate supplied is somebody
     * else's problem, and blocking on it would be a false accusation.
     *
     * The invariant this class restores is "no row may make the merge
     * contradict itself". It is not "no row may be saved while any
     * contradiction exists anywhere": an activity whose own settings
     * arrived inconsistent - through a restore, a direct write, or any
     * route that does not pass mod_form's settings_validator - would
     * otherwise refuse EVERY override anybody tried to record, naming a
     * pair the submitter neither set nor can reach from that form, and
     * the only cure would be an activity edit the override's author may
     * not be allowed to make.
     *
     * Negative control (RUN): drop the "at least one side came from the
     * candidate" clause from consistency::date_violations() - this row
     * parks with a blocker about two dates it never touched.
     */
    public function test_a_conflict_the_candidate_is_no_part_of_is_not_charged_to_it(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity();

        // The activity's own window is back to front, and no override
        // put it that way.
        $DB->set_field('selfselectadvanced', 'timeopen', 5000, ['id' => $activity->id()]);
        $DB->set_field('selfselectadvanced', 'timedue', 1000, ['id' => $activity->id()]);
        $broken = activity::from_instance($activity->id());

        // A cut-off that sits after both of them: this row takes part
        // in no violated pair, so it is not the one to hold back.
        $record = store::save($broken, 'user', (int) $students[1]->id, ['timecutoff' => 9000], 2);

        $this->assertSame('active', $record->status);
        $this->assertSame([], $record->blockers);
        $this->assertSame([], consistency::violations($broken, $record));

        // The same activity still stops a row that DOES join the bad
        // pair - the clause narrows the accusation, it does not lift it.
        $joins = store::save($broken, 'user', (int) $students[2]->id, ['timedue' => 2000], 2);
        $this->assertSame('pending', $joins->status);
        $this->assertCount(1, $joins->blockers);
        $this->assertStringContainsString(
            get_string('overridesourcethis', 'mod_selfselectadvanced'),
            $joins->blockers[0]->description
        );
    }

    /**
     * Requirement 2 for the two SWEEPS, which is where the risk moved.
     *
     * recheck_pending() had no lock at all before this ticket and fired
     * its activation event inline; park_inconsistent() did not exist.
     * Both now take the rank-5 `override:` resource per row, so both
     * acquired the ability to fire an event while holding one - and a
     * NEW event inside a lock is a defect, not a grandfathered one.
     * Each collects its payloads in the loop and triggers them after
     * the release, and this is what says so.
     *
     * Probed with locks::held_count() from inside the observer, for the
     * reasons test_save_event_after_commit gives: a zero-timeout
     * get_lock() probe is granted regardless, and is_transaction_started()
     * is unconditionally true under PHPUnit on PostgreSQL.
     *
     * Negative controls (RUN, one each): trigger park_inconsistent()'s
     * event inside its per-row try, and trigger recheck_pending()'s
     * inside its own - held_count() is 1 in each case and this goes red.
     */
    public function test_sweep_events_fire_outside_the_row_lock(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity(['timecutoff' => 9000]);

        $row = store::save($activity, 'user', (int) $students[1]->id, ['timedue' => 3500], 2);
        $this->assertSame('active', $row->status);

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

        // The cut-off comes in underneath the row: park_inconsistent().
        $DB->set_field('selfselectadvanced', 'timecutoff', 3000, ['id' => $activity->id()]);
        $parked = store::park_inconsistent(activity::from_instance($activity->id()), 2);

        // The cut-off goes back out again: recheck_pending() heals it.
        $DB->set_field('selfselectadvanced', 'timecutoff', 9000, ['id' => $activity->id()]);
        $stillpending = store::recheck_pending(activity::from_instance($activity->id()), 2);
        \core\event\manager::phpunit_reset();

        $this->assertCount(1, $parked);
        $this->assertSame([], $stillpending);
        $this->assertSame(
            [
                ['locks' => 0, 'to' => 'pending'],
                ['locks' => 0, 'to' => 'active'],
            ],
            $seen
        );
    }

    /**
     * A DATE pair submitted whole is reported ONCE.
     *
     * The same-submission checks write their message to whichever field
     * of the pair they choose - open > due lands on `timedue` - while
     * the tuple check attaches its own to the side the submitter can
     * change, `timeopen`. The de-duplication only ever tested the field
     * the tuple check picked, so every date pair was reported twice, on
     * two different fields, saying the same thing. The numeric pairs hid
     * it because both of their messages land on `minsize`.
     *
     * Negative control (RUN): restore the single-field guard
     * (`isset($errors[$field])` alone) in override_form::tuple_errors()
     * - `timeopen` comes back and the count assertion goes red.
     */
    public function test_a_submitted_date_pair_is_reported_once_not_twice(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // No activity cut-off, so the pair under test is the only pair
        // in play: a submission that opens after the ACTIVITY's cut-off
        // is a second, genuine conflict and not a duplicate of this one.
        [$activity, $students] = $this->setup_activity(['timecutoff' => 0]);

        $form = new \mod_selfselectadvanced\form\override_form(null, [
            'cmid' => $activity->cm()->id,
            'mode' => 'user',
            'overrideid' => 0,
            'targetmodule' => 'mod_selfselectadvanced/participantselector',
            'targetid' => 0,
            'targetlabel' => '',
            'activity' => $activity,
        ]);

        // Both ends of the pair in ONE submit, back to front.
        $errors = $form->validation([
            'target' => (int) $students[1]->id,
            'timeopen' => 9000,
            'timedue' => 5000,
            'timecutoff' => 0,
            'maxlead' => '',
            'maxmembership' => '',
        ], []);

        $this->assertSame(
            ['timedue'],
            array_keys($errors),
            'the same conflict was reported on two fields'
        );
        $this->assertSame(get_string('errdatesorder', 'mod_selfselectadvanced'), $errors['timedue']);

        // The matched partner, so this is not a test that validation
        // stopped working: a LONE date still gets the tuple sentence,
        // because no same-submission check can see that merge.
        $lone = $form->validation([
            'target' => (int) $students[1]->id,
            'timeopen' => 0,
            'timedue' => 500,
            'timecutoff' => 0,
            'maxlead' => '',
            'maxmembership' => '',
        ], []);
        $this->assertSame(['timedue'], array_keys($lone));
        $this->assertStringContainsString(
            get_string('overridesourceactivity', 'mod_selfselectadvanced'),
            $lone['timedue']
        );
        // And the numeric pair, which deduped correctly all along, is
        // still reported exactly once.
        $numeric = $form->validation([
            'target' => (int) $students[1]->id,
            'timeopen' => 0,
            'timedue' => 0,
            'timecutoff' => 0,
            'maxlead' => 5,
            'maxmembership' => 3,
        ], []);
        $this->assertSame(['maxlead'], array_keys($numeric));
    }

    /**
     * The counterparty NAMES are resolved for a whole batch, not once
     * per row.
     *
     * consistency::blockers() calls describe_all(), which costs up to
     * one {user} and one {selfselectadvanced_group} query - and
     * store::park_inconsistent() called it per PARKED ROW, so a
     * settings edit that parked five hundred cross-scope rows made five
     * hundred extra queries while two docblocks said it made none. The
     * read counts are measured here rather than reasoned about.
     *
     * Negative control (RUN): make blockers_many() loop over blockers()
     * - the batch count rises to one per row and the assertion goes red.
     */
    public function test_counterparty_names_are_resolved_for_the_whole_batch(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students, $group, $plugingen] = $this->setup_activity([
            'timeopen' => 0,
            'timedue' => 0,
            'timecutoff' => 0,
            'maxsize' => 10,
        ]);

        // One team with a dated group row, and four members each
        // carrying a user row that conflicts with it: every violation
        // names the GROUP as its counterparty, so every describe costs
        // a lookup.
        store::save($activity, 'group', (int) $group->id, ['timeopen' => 5000], 2);
        $rows = [];
        foreach ($students as $i => $student) {
            if ($i > 0) {
                // The leader's own confirmed row is the generator's.
                $plugingen->create_member([
                    'groupid' => $group->id,
                    'userid' => (int) $student->id,
                    'status' => groups::STATUS_CONFIRMED,
                ]);
            }
            $rows[] = store::save($activity, 'user', (int) $student->id, ['timedue' => 4000], 2);
        }
        $this->assertCount(4, $rows);

        $fresh = activity::from_instance($activity->id());
        $preload = consistency::preload($fresh, $rows);

        $before = $DB->perf_get_reads();
        foreach ($rows as $row) {
            $this->assertNotEmpty(consistency::blockers($fresh, $row, $preload));
        }
        $perrow = $DB->perf_get_reads() - $before;

        $before = $DB->perf_get_reads();
        $batched = consistency::blockers_many($fresh, $rows, $preload);
        $batch = $DB->perf_get_reads() - $before;

        // Same answers, one lookup instead of one per row.
        foreach ($rows as $row) {
            $this->assertSame(
                array_map(
                    static fn($blocker) => $blocker->description,
                    consistency::blockers($fresh, $row, $preload)
                ),
                array_map(static fn($blocker) => $blocker->description, $batched[(int) $row->id]),
                'the batch path describes a row differently from the single path'
            );
        }
        $this->assertSame(4, $perrow, 'one name lookup per row is what the batch has to beat');
        $this->assertSame(1, $batch, 'the batch resolved its names more than once');
    }

    /**
     * The cross-scope index is asked for the COUNTERPARTIES, not for
     * the activity.
     *
     * active_index() used to fetch every active row of the activity and
     * throw away everything that was not the opposite scope and not
     * date-bearing: at 802 active rows, one dated save materialised all
     * 802 to build a twelve-entry index. It ran once per save AND once
     * per pending row swept. The membership read is the bounded one, so
     * it now comes first and decides whether the override read happens
     * at all.
     *
     * Negative control (RUN): restore the unfiltered
     * `get_records('selfselectadvanced_override', [activityid, active])`
     * and the old ordering - the candidate with no teams pays for the
     * index read and the first assertion goes red.
     */
    public function test_the_cross_scope_index_is_bounded_by_the_counterparties(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students, $group, $plugingen] = $this->setup_activity([
            'timeopen' => 0,
            'timedue' => 0,
            'timecutoff' => 0,
            'maxsize' => 10,
        ]);

        // Twenty unrelated ACTIVE dated group rows, none of which any
        // candidate below is a member of.
        $now = time();
        $noise = [];
        for ($i = 0; $i < 20; $i++) {
            $other = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $students[0]->id,
                'name' => 'Noise ' . $i,
                'state' => state::FORMING,
            ]);
            $noise[] = (object) [
                'activityid' => $activity->id(),
                'scope' => 'group',
                'groupid' => (int) $other->id,
                'timeopen' => 5000,
                'status' => 'active',
                'usermodified' => 2,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }
        $DB->insert_records('selfselectadvanced_override', $noise);

        // A candidate in NO team: one membership read, and no index
        // read at all, because there is nothing it could be paired with.
        $loner = (object) [
            'scope' => 'user',
            'userid' => (int) $students[1]->id,
            'timedue' => 4000,
        ];
        $before = $DB->perf_get_reads();
        $this->assertSame([], consistency::violations($activity, $loner));
        $this->assertSame(
            1,
            $DB->perf_get_reads() - $before,
            'a candidate with no counterparties still paid for the activity index'
        );

        // A candidate in ONE team, whose own dated row is the only one
        // that can matter: the membership read and one index read.
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[2]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        store::save($activity, 'group', (int) $group->id, ['timeopen' => 9000], 2);
        $fresh = activity::from_instance($activity->id());
        $paired = (object) [
            'scope' => 'user',
            'userid' => (int) $students[2]->id,
            'timedue' => 4000,
        ];
        $before = $DB->perf_get_reads();
        $violations = consistency::violations($fresh, $paired);
        $this->assertSame(2, $DB->perf_get_reads() - $before);
        $this->assertCount(1, $violations);
        $this->assertSame((int) $group->id, (int) $violations[0]->counterpartyid);
    }

    /**
     * A CONTENDED row is skipped, never thrown out of the sweep.
     *
     * recheck_pending() acquires a lock per row, and locks::acquire()
     * throws errlocktimeout after its wait. Both sweeping callers run
     * AFTER work that has already committed - moves::commit_set() calls
     * it past its own commit and release, and the nightly task calls it
     * once per activity outside the loop's try/catch - so one busy row
     * turned a committed move set into a visible error for the manager
     * and aborted the reconcile for every activity after it.
     *
     * The contention is injected through locks' own test hook, which
     * runs inside acquire() and therefore fails exactly where a real
     * timeout fails. A second acquire in this process would not
     * contend: PostgreSQL advisory locks are re-entrant per session.
     *
     * Negative control (RUN): remove the try/catch around the acquire in
     * recheck_pending() - the exception escapes and this goes red.
     */
    public function test_a_contended_row_is_skipped_rather_than_thrown(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity(['timecutoff' => 9000]);

        $busy = store::save($activity, 'user', (int) $students[1]->id, ['timedue' => 3500], 2);
        $free = store::save($activity, 'user', (int) $students[2]->id, ['timedue' => 3600], 2);
        $DB->set_field('selfselectadvanced', 'timecutoff', 3000, ['id' => $activity->id()]);
        $parked = store::park_inconsistent(activity::from_instance($activity->id()), 2);
        $this->assertCount(2, $parked);

        // Both rows are healable again; the first one's lock is busy.
        $DB->set_field('selfselectadvanced', 'timecutoff', 9000, ['id' => $activity->id()]);
        $contended = 'override:user:' . (int) $students[1]->id;
        locks::set_test_hook(static function (string $resource) use ($contended): void {
            if ($resource === $contended) {
                throw new \mod_selfselectadvanced\local\workflow_refusal('errlocktimeout', 'mod_selfselectadvanced');
            }
        });
        try {
            $stillpending = store::recheck_pending(activity::from_instance($activity->id()), 2);
        } finally {
            locks::set_test_hook(null);
        }

        $this->assertSame([], $stillpending, 'a skipped row must not be reported as blocked either');
        $this->assertSame(
            'pending',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $busy->id]),
            'the contended row was swept anyway'
        );
        $this->assertSame(
            'active',
            $DB->get_field('selfselectadvanced_override', 'status', ['id' => $free->id]),
            'the sweep stopped at the contended row instead of carrying on'
        );
    }

    /**
     * The pending sweep and its list are WINDOWED.
     *
     * Before T-08 a large pending set was hard to make - only a cap
     * reduced below occupancy produced one. park_inconsistent() can now
     * park an activity's whole override set from a single settings
     * edit, so the overrides page's every-visit sweep and its unpaged
     * list became reachable at exactly the scale house rule 3 names.
     * The window is a KEYSET, for the same reason park_inconsistent()'s
     * is: healing a row removes it from status='pending', so an offset
     * would step over rows it never examined.
     *
     * Negative control (RUN): drop the $limitnum argument from the
     * get_records_select() call - all four rows heal in the first pass
     * and the "untouched" assertions go red.
     */
    public function test_the_pending_sweep_is_windowed(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity(['timecutoff' => 9000]);

        $ids = [];
        foreach ([1, 2, 3] as $i) {
            $ids[] = (int) store::save($activity, 'user', (int) $students[$i]->id, ['timedue' => 3500], 2)->id;
        }
        $DB->set_field('selfselectadvanced', 'timecutoff', 3000, ['id' => $activity->id()]);
        $this->assertCount(3, store::park_inconsistent(activity::from_instance($activity->id()), 2));
        $DB->set_field('selfselectadvanced', 'timecutoff', 9000, ['id' => $activity->id()]);

        $status = fn(int $id): string => (string) $DB->get_field(
            'selfselectadvanced_override',
            'status',
            ['id' => $id]
        );

        $lastexamined = 0;
        $fresh = activity::from_instance($activity->id());
        $this->assertSame([], store::recheck_pending($fresh, 2, null, 0, 2, $lastexamined));
        $this->assertSame($ids[1], $lastexamined, 'the cursor did not follow the window');
        $this->assertSame('active', $status($ids[0]));
        $this->assertSame('active', $status($ids[1]));
        $this->assertSame('pending', $status($ids[2]), 'the window did not bound the sweep');

        // The next window picks up exactly where the last one stopped.
        $this->assertSame([], store::recheck_pending($fresh, 2, null, $lastexamined, 2, $lastexamined));
        $this->assertSame($ids[2], $lastexamined);
        $this->assertSame('active', $status($ids[2]));

        // An exhausted window reports no cursor at all.
        $this->assertSame([], store::recheck_pending($fresh, 2, null, $lastexamined, 2, $lastexamined));
        $this->assertSame(0, $lastexamined);
    }

    /**
     * The caller whose job IS the whole set walks the windows, and
     * arrives at the last row.
     *
     * The nightly reconcile sweeps every activity on the site, and the
     * overrides page now sweeps only the window it renders - which
     * makes the task the safety net for every row beyond that window.
     * So it must reach the last row, and it must not ask for all of
     * them in one query: a single settings edit can park an activity's
     * entire override set, which is the unbounded read house rule 3
     * names.
     *
     * Negative controls (RUN): (a) drop the loop and make one windowed
     * call - the third row stays pending; (b) ignore the window and
     * make one unbounded call - one pass, and the pass count goes red.
     */
    public function test_the_nightly_sweep_walks_every_window_to_the_last_row(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity(['timecutoff' => 9000]);

        $ids = [];
        foreach ([1, 2, 3] as $i) {
            $ids[] = (int) store::save($activity, 'user', (int) $students[$i]->id, ['timedue' => 3500], 2)->id;
        }
        $DB->set_field('selfselectadvanced', 'timecutoff', 3000, ['id' => $activity->id()]);
        $this->assertCount(3, store::park_inconsistent(activity::from_instance($activity->id()), 2));
        $DB->set_field('selfselectadvanced', 'timecutoff', 9000, ['id' => $activity->id()]);

        // Three healable rows, two per window: two passes that examine
        // something, and a third that finds nothing and stops.
        $passes = store::recheck_all_pending(activity::from_instance($activity->id()), 2, 2);
        $this->assertSame(2, $passes, 'the sweep did not walk the windows it was given');
        foreach ($ids as $id) {
            $this->assertSame(
                'active',
                (string) $DB->get_field('selfselectadvanced_override', 'status', ['id' => $id]),
                'a pending row beyond the first window was never examined'
            );
        }

        // Nothing left to do, and the walk still terminates.
        $this->assertSame(0, store::recheck_all_pending(activity::from_instance($activity->id()), 2, 2));
    }

    /**
     * An actorless settings edit stamps the ADMIN, not user 0.
     *
     * lib.php read `(int) ($USER->id ?? get_admin()->id)`, and $USER->id
     * is always SET in Moodle - it is 0 for a session with nobody in it
     * - so the fallback was unreachable and every row parked by a CLI
     * or task-driven settings edit was stamped usermodified = 0.
     *
     * Negative control (RUN): restore the `??` - usermodified is 0 and
     * this goes red.
     */
    public function test_an_actorless_settings_edit_stamps_the_admin(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $students] = $this->setup_activity(['timecutoff' => 9000]);

        $row = store::save($activity, 'user', (int) $students[1]->id, ['timedue' => 3500], 2);
        $this->assertSame('active', $row->status);

        // Nobody logged in - a scheduled task or a CLI edit.
        $this->setUser(null);
        $instance = $DB->get_record('selfselectadvanced', ['id' => $activity->id()], '*', MUST_EXIST);
        $data = (object) (array) $instance;
        $data->instance = (int) $instance->id;
        $data->timecutoff = 3000;
        selfselectadvanced_update_instance($data);

        $parked = $DB->get_record('selfselectadvanced_override', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame('pending', $parked->status);
        $this->assertSame((int) get_admin()->id, (int) $parked->usermodified);
        $this->assertGreaterThan(0, (int) $parked->usermodified);
    }
}
