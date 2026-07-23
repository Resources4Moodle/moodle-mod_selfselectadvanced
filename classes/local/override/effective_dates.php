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

namespace mod_selfselectadvanced\local\override;

/**
 * The effective formation window with per-field provenance.
 *
 * Each field resolves independently (per-field fallthrough, precedence
 * rows P1-P7 of the architecture plan): an unset field in a
 * higher-precedence override falls through to the next source.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class effective_dates {
    /**
     * Constructor.
     *
     * @param int $timeopen effective open timestamp, 0 when not set
     * @param int $timedue effective penalty-free deadline, 0 when not set
     * @param int $timecutoff effective hard stop, 0 when not set
     * @param int[] $sources map of field name to effective_value SOURCE_ constant
     */
    public function __construct(
        /** @var int Effective open timestamp, 0 when not set. */
        public readonly int $timeopen,
        /** @var int Effective penalty-free deadline, 0 when not set. */
        public readonly int $timedue,
        /** @var int Effective hard stop, 0 when not set. */
        public readonly int $timecutoff,
        /** @var int[] Map of field name to effective_value SOURCE_ constant. */
        public readonly array $sources = [],
    ) {
    }

    /**
     * Whether the formation window is open at the given time.
     *
     * An unset boundary (0) does not constrain.
     *
     * @param int $time timestamp to test, usually now
     * @return bool true when inside the effective window
     */
    public function is_open(int $time): bool {
        if ($this->timeopen && $time < $this->timeopen) {
            return false;
        }
        if ($this->timecutoff && $time > $this->timecutoff) {
            return false;
        }
        return true;
    }

    /**
     * Whether any field of this window comes from an override.
     *
     * @return bool true when overridden
     */
    public function is_overridden(): bool {
        foreach ($this->sources as $source) {
            if ($source !== effective_value::SOURCE_ACTIVITY) {
                return true;
            }
        }
        return false;
    }
}
