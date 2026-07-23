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

use core\lock\lock;
use core\lock\lock_config;

/**
 * Named locks for race-sensitive gates (decision A7).
 *
 * Every mutation that enforces a counted limit acquires the group or
 * activity lock, then re-validates inside its transaction. Identical
 * behaviour on MySQL/MariaDB and PostgreSQL because the core lock API
 * abstracts the backend.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class locks {
    /** @var int Seconds to wait for a contended lock before giving up. */
    private const TIMEOUT = 10;

    /**
     * Acquire a named lock, throwing when it cannot be obtained in time.
     *
     * @param string $resource resource key, e.g. "group:42" or "activity:7"
     * @return lock the held lock; callers release() in a finally block
     * @throws \moodle_exception when the lock cannot be acquired
     */
    public static function acquire(string $resource): lock {
        $factory = lock_config::get_lock_factory('mod_selfselectadvanced');
        $lock = $factory->get_lock($resource, self::TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('errlocktimeout', 'mod_selfselectadvanced');
        }

        return $lock;
    }
}
