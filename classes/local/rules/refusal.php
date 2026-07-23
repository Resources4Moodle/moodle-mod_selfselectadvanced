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

namespace mod_selfselectadvanced\local\rules;

/**
 * A structured refusal from the gatekeeper.
 *
 * The string key doubles as the machine-readable reason code; the UI
 * renders get_message() beside every disabled control (spec section
 * 4A.6) so the reason is always shown.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class refusal {
    /**
     * Constructor.
     *
     * @param string $stringkey lang string key in mod_selfselectadvanced
     * @param mixed $a optional string parameter object
     */
    public function __construct(
        /** @var string Lang string key in mod_selfselectadvanced. */
        public readonly string $stringkey,
        /** @var mixed Optional string parameter object. */
        public readonly mixed $a = null,
    ) {
    }

    /**
     * The localised, user-facing reason.
     *
     * @return string
     */
    public function get_message(): string {
        return get_string($this->stringkey, 'mod_selfselectadvanced', $this->a);
    }
}
