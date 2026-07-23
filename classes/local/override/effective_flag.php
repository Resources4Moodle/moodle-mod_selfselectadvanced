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
 * A boolean override outcome (quota exemption, penalty waiver) with provenance.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class effective_flag {
    /**
     * Constructor.
     *
     * @param bool $enabled whether the flag is in force
     * @param int $source one of the effective_value SOURCE_ constants
     * @param int|null $overrideid id of the override row supplying the flag, if any
     */
    public function __construct(
        /** @var bool Whether the flag is in force. */
        public readonly bool $enabled,
        /** @var int One of the effective_value SOURCE_ constants. */
        public readonly int $source = effective_value::SOURCE_ACTIVITY,
        /** @var int|null Id of the override row supplying the flag, if any. */
        public readonly ?int $overrideid = null,
    ) {
    }
}
