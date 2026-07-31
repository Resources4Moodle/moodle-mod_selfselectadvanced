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

namespace mod_selfselectadvanced\local;

use core\lock\lock_config;

/**
 * Named locks for race-sensitive gates (decision A7).
 *
 * Every mutation that enforces a counted limit acquires its locks here,
 * then re-validates inside its transaction. Identical behaviour on
 * MySQL/MariaDB and PostgreSQL because the core lock API abstracts the
 * backend.
 *
 * Two independent lock families used to grow side by side - the
 * fine-grained student/guide family on 'group:{id}' and the coarse move
 * and auto-grouping family on 'activity:{id}' - and Moodle's named
 * locks have no hierarchy, so 'activity:7' and 'group:42' never
 * excluded one another. ORDER below is the one global reconciliation:
 * every path acquires in ascending rank and releases in reverse, so no
 * two paths can hold locks in opposite order.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class locks {
    /**
     * The ONE global lock order. Acquire in ascending rank; release
     * in reverse. Two locks of the same rank may only be held together
     * for 'group:', and then only in ascending numeric group id.
     *
     * 1 joinrequest:user:{userid}   one student's request stream
     * 2 joinrequest:{requestid}     one request
     * 3 ticket:{ticketid}           one queue ticket
     * 4 guidecap:{userid}           one guide's cap request stream
     * 5 override:{scope}:{targetid} one override row
     * 6 activity:{activityid}       activity-wide counts (L3/L4), move sets, autogroup runs
     * 7 eoiguide:{guideid}          one guide's capacity (L5)
     * 8 group:{groupid}             one team's roster/state  <-- innermost, ascending id
     *
     * @var array<string, int> resource prefix => rank
     */
    public const ORDER = [
        'joinrequest:user:' => 1,
        'joinrequest:' => 2,
        'ticket:' => 3,
        'guidecap:' => 4,
        'override:' => 5,
        'activity:' => 6,
        'eoiguide:' => 7,
        'group:' => 8,
    ];

    /** @var int The rank of 'group:', the only rank that may stack. */
    private const GROUPRANK = 8;

    /** @var int Seconds to wait for a contended lock before giving up. */
    private const TIMEOUT = 10;

    /** @var int|null Test-only timeout override. */
    private static ?int $testtimeout = null;

    /** @var array<int, string> Currently held locks: handle id => resource. */
    private static array $held = [];

    /** @var int Monotonic handle sequence. */
    private static int $seq = 0;

    /** @var \Closure|null Test-only hook run immediately before each acquire. */
    private static ?\Closure $testhook = null;

    /** @var string[]|null Test-only acquisition log, null when not recording. */
    private static ?array $log = null;

    /**
     * Acquire a named lock, throwing when it cannot be obtained in time.
     *
     * @param string $resource resource key, e.g. "group:42" or "activity:7"
     * @return lockhandle the held lock; callers release() in a finally block
     * @throws \moodle_exception when the lock cannot be acquired
     */
    public static function acquire(string $resource): lockhandle {
        self::check_order($resource);

        $timeout = self::$testtimeout ?? self::TIMEOUT;
        if (self::$testhook !== null) {
            // Deliberately BEFORE the lock is taken: a test drives a
            // racing writer's committed work into exactly the window
            // between the caller's pre-lock read and its lock.
            $hook = self::$testhook;
            $hook($resource);
        }

        $factory = lock_config::get_lock_factory('mod_selfselectadvanced');
        $lock = $factory->get_lock($resource, $timeout);
        if (!$lock) {
            throw new \moodle_exception('errlocktimeout', 'mod_selfselectadvanced');
        }

        $handle = new lockhandle(++self::$seq, $resource, $lock);
        self::$held[$handle->id()] = $resource;
        if (self::$log !== null) {
            self::$log[] = 'acquire ' . $resource;
        }

        return $handle;
    }

    /**
     * Acquire a set of locks in the one global order.
     *
     * Duplicates collapse; the set is sorted by rank and then by
     * numeric tail, so two paths naming the same groups in different
     * orders still take them in the same order.
     *
     * @param string[] $resources the resources needed
     * @return lockhandle[] handles in acquisition order
     * @throws \moodle_exception when any lock cannot be acquired
     */
    public static function acquire_all(array $resources): array {
        $resources = array_values(array_unique($resources));
        usort($resources, static function (string $a, string $b): int {
            return [self::rank($a), self::tail($a)] <=> [self::rank($b), self::tail($b)];
        });

        $handles = [];
        try {
            foreach ($resources as $resource) {
                $handles[] = self::acquire($resource);
            }
        } catch (\Throwable $e) {
            self::release_all($handles);
            throw $e;
        }

        return $handles;
    }

    /**
     * Release a set of handles in reverse acquisition order.
     *
     * @param lockhandle[] $handles what acquire_all() returned
     */
    public static function release_all(array $handles): void {
        foreach (array_reverse($handles) as $handle) {
            $handle->release();
        }
    }

    /**
     * How many plugin locks this request currently holds.
     *
     * This is the question notifier::send() asks - "am I inside a
     * lock?" - because the transaction state cannot answer it: under
     * PHPUnit on PostgreSQL a delegated transaction is open for the
     * whole of every test.
     *
     * @return int
     */
    public static function held_count(): int {
        return count(self::$held);
    }

    /**
     * Forget a released handle. Called by lockhandle::release() only.
     *
     * @param lockhandle $handle the handle being released
     */
    public static function forget(lockhandle $handle): void {
        unset(self::$held[$handle->id()]);
        if (self::$log !== null) {
            self::$log[] = 'release ' . $handle->resource();
        }
    }

    /**
     * The rank of a resource in the one global order.
     *
     * @param string $resource the resource key
     * @return int rank, 1 (outermost) to 8 (innermost)
     * @throws \coding_exception when the prefix is not in ORDER
     */
    private static function rank(string $resource): int {
        foreach (self::ORDER as $prefix => $rank) {
            if (str_starts_with($resource, $prefix)) {
                return $rank;
            }
        }

        throw new \coding_exception('Unranked lock resource: ' . $resource);
    }

    /**
     * The numeric tail of a resource key, used to order same-rank locks.
     *
     * @param string $resource the resource key
     * @return int the trailing id, 0 when there is none
     */
    private static function tail(string $resource): int {
        $pos = strrpos($resource, ':');

        return $pos === false ? 0 : (int) substr($resource, $pos + 1);
    }

    /**
     * Report an acquisition that breaks the global order.
     *
     * Report only, never throw: a production request must not die on a
     * lock-order mistake.
     *
     * What that report is worth, measured rather than assumed (this
     * docblock used to claim "PHPUnit turns an unexpected debugging()
     * into a failure", which is false as written). Moodle turns an
     * unconsumed debugging() into an E_USER_NOTICE; PHPUnit 11 reports
     * it as a Notice, not a Warning, and a run that is not given
     * --fail-on-notice still exits 0. That flag now travels WITH THE
     * REPOSITORY - .github/workflows/moodle-ci.yml runs phpunit with
     * --fail-on-warning --fail-on-notice, and the maintainer's gate
     * passes the same pair - so an inversion reddens a run wherever
     * the suite is driven from this repo's own configuration. In
     * production debugging() is still a no-op below
     * DEBUG_DEVELOPER. Any test that means to pin an ordering property
     * must say so with an explicit assertDebuggingCalled() /
     * assertDebuggingNotCalled(); see docs/architecture.md A7.
     *
     * @param string $resource the resource about to be acquired
     */
    private static function check_order(string $resource): void {
        $rank = self::rank($resource);
        if (!self::$held) {
            return;
        }

        $max = 0;
        $maxgroupid = 0;
        foreach (self::$held as $heldresource) {
            $heldrank = self::rank($heldresource);
            $max = max($max, $heldrank);
            if ($heldrank === self::GROUPRANK) {
                $maxgroupid = max($maxgroupid, self::tail($heldresource));
            }
        }

        $violation = false;
        if ($rank < $max) {
            $violation = true;
        } else if ($rank === $max && $rank !== self::GROUPRANK) {
            $violation = true;
        } else if ($rank === self::GROUPRANK && $maxgroupid >= self::tail($resource)) {
            $violation = true;
        }

        if ($violation) {
            debugging(
                'Lock order violation: acquiring ' . $resource . ' while holding '
                    . implode(', ', array_values(self::$held)),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Test-only: shorten the acquire timeout.
     *
     * @param int|null $seconds the timeout, null to restore the default
     * @throws \coding_exception outside PHPUnit
     */
    public static function set_test_timeout(?int $seconds): void {
        self::assert_test_only();
        self::$testtimeout = $seconds;
    }

    /**
     * Test-only: run a callback immediately before each acquire, with
     * the resource as its only argument. This is the seam the race
     * tests use to commit a racing writer's work in the exact window
     * between a caller's pre-lock read and its lock.
     *
     * @param callable|null $fn the hook, null to clear
     * @throws \coding_exception outside PHPUnit
     */
    public static function set_test_hook(?callable $fn): void {
        self::assert_test_only();
        self::$testhook = $fn === null ? null : \Closure::fromCallable($fn);
    }

    /**
     * Test-only: start recording acquisitions and releases.
     *
     * @throws \coding_exception outside PHPUnit
     */
    public static function start_recording(): void {
        self::assert_test_only();
        self::$log = [];
    }

    /**
     * Test-only: stop recording and return the log.
     *
     * @return string[] entries of the form "acquire group:42" / "release group:42"
     * @throws \coding_exception outside PHPUnit
     */
    public static function stop_recording(): array {
        self::assert_test_only();
        $log = self::$log ?? [];
        self::$log = null;

        return $log;
    }

    /**
     * Test-only: forget every held lock, hook and log.
     *
     * @throws \coding_exception outside PHPUnit
     */
    public static function reset_state(): void {
        self::assert_test_only();
        self::$held = [];
        self::$seq = 0;
        self::$testhook = null;
        self::$testtimeout = null;
        self::$log = null;
    }

    /**
     * Refuse a test-only seam outside PHPUnit.
     *
     * @throws \coding_exception outside PHPUnit
     */
    private static function assert_test_only(): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('test-only');
        }
    }
}
