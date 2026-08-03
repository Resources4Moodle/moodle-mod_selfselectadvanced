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
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\override\store;
use mod_selfselectadvanced\local\state;

/**
 * THE TWO SEAMS transaction_unwind_test HAD TO LEAVE UNPROVEN, and the
 * structural pin that covers the third.
 *
 * Wave 3E converted sixteen catch arms to roll their own delegated
 * frame back whoever owns the one underneath, and proved thirteen of
 * them by driving a real refusal. Three had none to drive:
 * joinrequests::do_decline() and override\store::delete() contain no
 * refusal inside their transactions at all, and freeze::freeze_group()
 * opens its transaction only after every gate has already passed.
 *
 * Two of the three ARE reachable, without touching the plugin, because
 * both dispatch an event from inside the frame. core\event\manager::
 * dispatch() catches \Exception from an observer and turns it into
 * debugging(); it does NOT catch \Error, so a TypeError - or anything
 * else Throwable and not Exception - raised by a third-party observer
 * escapes trigger() and lands in whatever transaction the trigger was
 * made from. That is a fault a real site can deliver into these exact
 * frames, and it is what the catch arms are for: they catch \Throwable,
 * not \moodle_exception.
 *
 * IT IS NOT A REFUSAL AND IS NOT DESCRIBED AS ONE. What it proves is
 * narrower and is the whole of C1's question: when this frame fails,
 * does the CALLER's own rollback still reach the database?
 *
 * freeze::freeze_group() has no event inside its transaction either, so
 * it remains undriveable single-threaded. It is covered here only by
 * the structural pin below, which is stated as such.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\joinrequests
 * @covers     \mod_selfselectadvanced\local\override\store
 */
final class nestedfaults_test extends \advanced_testcase {
    /** @var int Sequence for marker rows. */
    private int $markerseq = 0;

    /**
     * An observer that fails the way a third-party observer can fail.
     *
     * @param \core\event\base $event the event being dispatched
     * @throws \Error always
     */
    public static function observer_explodes(\core\event\base $event): void {
        unset($event);
        throw new \Error('injected observer failure');
    }

    /**
     * Replace every observer with one that explodes on $eventname.
     *
     * phpunit_replace_observers() marks the registry for reload after
     * the test, so nothing leaks into the next one.
     *
     * @param string $eventname fully qualified event class
     * @return void
     */
    private function make_that_event_fatal(string $eventname): void {
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => $eventname,
            'callback' => '\mod_selfselectadvanced\nestedfaults_test::observer_explodes',
            'internal' => true,
        ]]);
    }

    /**
     * A course, an activity, a FIRM team and a student outside it.
     *
     * @return array [activity, group, leader, outsider, manager]
     */
    private function setup_world(): array {
        $gen = $this->getDataGenerator();
        $plugin = $gen->get_plugin_generator('mod_selfselectadvanced');
        $course = $gen->create_course();
        $instance = $gen->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxmembership' => 3,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $gen->create_user();
        $outsider = $gen->create_user();
        $gen->enrol_user($leader->id, $course->id, 'student');
        $gen->enrol_user($outsider->id, $course->id, 'student');
        $manager = $gen->create_user();
        $gen->enrol_user($manager->id, $course->id, 'editingteacher');

        $group = $plugin->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'state' => state::FIRM,
            'timeapproved' => time(),
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $outsider, $manager];
    }

    /**
     * Drive one call from inside a transaction the TEST owns, and ask
     * whether the CALLER's own rollback reaches the database.
     *
     * The marker row is written by the caller, through the caller's
     * transaction, before the callee is entered. A control row written
     * BEFORE that transaction must survive, so "the marker is gone"
     * cannot be satisfied by a connection that lost everything.
     *
     * Both arms are forced explicitly: the caller frame is opened here,
     * and preventResetByRollback() has already committed the harness's
     * own frame, which exists on PostgreSQL and not on MariaDB.
     *
     * @param string $label the seam, for the failure message
     * @param callable $fn the call that must fail from inside its frame
     * @return void
     */
    private function probe_nested(string $label, callable $fn): void {
        global $DB;

        $this->assertFalse($DB->is_transaction_started(), $label . ': the stack was dirty before the probe');

        $marker = 'nestedfaults-' . (++$this->markerseq);
        $now = time();
        $row = static fn(string $name): \stdClass => (object) [
            'name' => $name,
            'kind' => 'dept',
            'parent' => 0,
            'depth' => 1,
            'path' => '/0',
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('selfselectadvanced_dept', $row($marker . '-before'));

        $caller = $DB->start_delegated_transaction();
        $DB->insert_record('selfselectadvanced_dept', $row($marker . '-inside'));

        $failed = false;
        try {
            $fn();
        } catch (\Throwable $e) {
            $failed = true;
            try {
                $caller->rollback($e);
            } catch (\Throwable $rethrown) {
                unset($rethrown);
            }
        }
        $this->assertTrue($failed, $label . ': the injected fault did not reach the caller');

        // Read BEFORE any cleanup: force_transaction_rollback() would
        // remove the evidence and turn the defect into a pass.
        $open = $DB->is_transaction_started();
        $survived = $DB->record_exists('selfselectadvanced_dept', ['name' => $marker . '-inside']);
        $control = $DB->record_exists('selfselectadvanced_dept', ['name' => $marker . '-before']);
        if ($open) {
            $DB->force_transaction_rollback();
        }
        $DB->delete_records_select('selfselectadvanced_dept', 'name = ? OR name = ?', [
            $marker . '-before',
            $marker . '-inside',
        ]);

        $this->assertTrue($control, $label . ': the row written before the caller transaction did not survive');
        $this->assertFalse($open, $label . ': the transaction stack was still open after the caller rolled back');
        $this->assertFalse($survived, $label . ": the caller's own row survived the caller's own rollback");
    }

    /**
     * joinrequests::do_decline(): its transaction holds an update, the
     * join_decided event and the commit, and no refusal at all.
     *
     * @return void
     */
    public function test_a_fault_inside_do_decline_lets_the_callers_rollback_land(): void {
        $this->resetAfterTest();
        // Required: without it PostgreSQL keeps a harness frame under
        // everything and no rollback in the chain is ever the last one.
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, $group, $leader, $outsider] = $this->setup_world();
        $request = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_joinrequest([
            'activityid' => $activity->id(),
            'userid' => (int) $outsider->id,
            'targetgroupid' => (int) $group->id,
            'additional' => 1,
        ]);
        $this->make_that_event_fatal('\mod_selfselectadvanced\event\join_decided');

        $this->probe_nested(
            'joinrequests::do_decline',
            fn() => joinrequests::respond($activity, (int) $request->id, false, 'No room', (int) $leader->id)
        );
    }

    /**
     * override\store::delete(): its only in-transaction throw is a
     * MUST_EXIST re-read whose criteria are identical to the read taken
     * before the lock, so it cannot fail while the first succeeded. The
     * override_deleted event IS dispatched from inside the frame.
     *
     * @return void
     */
    public function test_a_fault_inside_override_delete_lets_the_callers_rollback_land(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();

        [$activity, , , $outsider, $manager] = $this->setup_world();
        $override = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_override([
            'activityid' => $activity->id(),
            'scope' => 'user',
            'targetid' => (int) $outsider->id,
            'actorid' => (int) $manager->id,
            'maxlead' => 2,
        ]);
        $this->make_that_event_fatal('\mod_selfselectadvanced\event\override_deleted');

        $this->probe_nested(
            'override\store::delete',
            fn() => store::delete($activity, (int) $override->id, (int) $manager->id)
        );
    }

    /**
     * THE STRUCTURAL HALF, and the only cover freeze::freeze_group()
     * has.
     *
     * The defect C1 fixed was one identifier: an `$outermost` flag,
     * computed from $DB->is_transaction_started() before the lock and
     * then used to decide whether to roll back. Sixteen seams carried
     * it. Three of them cannot be driven to a failure by any
     * single-threaded test, so for those the only durable guard is that
     * the flag is not there to be consulted.
     *
     * COMMENTS ARE STRIPPED. Five production files now carry a
     * paragraph explaining why the flag was removed, and every one of
     * those paragraphs contains the word - so a search over raw text
     * would answer "still present" for the explanation of the fix.
     *
     * transaction_unwind_test pins the related predicate
     * ($DB->is_transaction_started(), one permitted reader). This pins
     * the variable the predicate used to be stored in, which is the
     * form the defect actually took.
     *
     * @return void
     */
    public function test_no_production_seam_computes_an_outermost_flag(): void {
        $root = dirname(__DIR__);
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root . '/', '', $file->getPathname());
            if (str_starts_with($path, 'tests/') || str_starts_with($path, '.')) {
                continue;
            }
            if (str_contains(self::executable_source($file->getPathname()), '$outermost')) {
                $found[] = $path;
            }
        }
        sort($found);

        // The walk must have examined something: a check that read no
        // files would report "none found" for ever.
        $this->assertFileExists($root . '/classes/local/tickets.php');
        $this->assertStringContainsString(
            'start_delegated_transaction',
            self::executable_source($root . '/classes/local/tickets.php'),
            'the source reader returned nothing recognisable'
        );
        $this->assertSame(
            [],
            $found,
            'a seam computes an $outermost flag again. Whether to roll a delegated frame back is not a'
                . ' question about who opened the outermost transaction: core rolls back the frame it is'
                . ' given and cascades downwards, and a frame left undisposed on the stack makes the'
                . " CALLER's rollback rethrow without ever reaching the database."
        );
    }

    /**
     * A PHP file's executable source, comments removed by token.
     *
     * @param string $path absolute path to the file
     * @return string the code, comment-free
     */
    private static function executable_source(string $path): string {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new \coding_exception('unreadable: ' . $path);
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
