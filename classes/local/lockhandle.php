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

/**
 * A held plugin lock.
 *
 * Returned by locks::acquire(); release() is idempotent and always goes
 * back through locks so the request's held-set stays accurate. That set
 * is what the ordering guard consults, and what notifier's
 * no-mail-under-lock guard counts - neither of which core's own
 * \core\lock\lock::release() can feed, because it releases without
 * telling anybody.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lockhandle {
    /** @var bool Whether this handle still holds its lock. */
    private bool $held = true;

    /**
     * Constructor. Only locks::acquire() builds these.
     *
     * @param int $id monotonic handle id, unique per request
     * @param string $resource the resource key, e.g. "group:42"
     * @param \core\lock\lock $lock the core lock underneath
     */
    public function __construct(
        /** @var int Monotonic id, unique per request. */
        private readonly int $id,
        /** @var string The resource key, e.g. "group:42". */
        private readonly string $resource,
        /** @var \core\lock\lock The core lock underneath. */
        private readonly \core\lock\lock $lock,
    ) {
    }

    /**
     * The handle id, which is how locks tracks the held set.
     *
     * @return int
     */
    public function id(): int {
        return $this->id;
    }

    /**
     * The resource key this handle holds.
     *
     * @return string
     */
    public function resource(): string {
        return $this->resource;
    }

    /**
     * Whether the lock is still held by this handle.
     *
     * @return bool
     */
    public function held(): bool {
        return $this->held;
    }

    /**
     * Release once; further calls are no-ops, because the finally
     * blocks that call this are allowed to be generous.
     */
    public function release(): void {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        locks::forget($this);
        $this->lock->release();
    }
}
