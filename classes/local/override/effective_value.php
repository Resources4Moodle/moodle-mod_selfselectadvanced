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
 * An effective numeric limit with its provenance.
 *
 * Every consumer of the five limits L1-L5 receives one of these from the
 * resolver, never a raw setting, so the UI can badge overridden values
 * and audits can trace where a number came from.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class effective_value {
    /** @var int Value comes from the activity settings. */
    public const SOURCE_ACTIVITY = 0;

    /** @var int Value comes from a user-scope override. */
    public const SOURCE_USER = 1;

    /** @var int Value comes from a group-scope override. */
    public const SOURCE_GROUP = 2;

    /** @var int Value comes from a guide-scope override. */
    public const SOURCE_GUIDE = 3;

    /**
     * Constructor.
     *
     * @param int $value the effective value
     * @param int $source one of the SOURCE_ constants
     * @param int|null $overrideid id of the override row supplying the value, if any
     */
    public function __construct(
        /** @var int The effective value. */
        public readonly int $value,
        /** @var int One of the SOURCE_ constants. */
        public readonly int $source = self::SOURCE_ACTIVITY,
        /** @var int|null Id of the override row supplying the value, if any. */
        public readonly ?int $overrideid = null,
    ) {
    }

    /**
     * Whether this value comes from an override rather than the activity settings.
     *
     * @return bool true when overridden
     */
    public function is_overridden(): bool {
        return $this->source !== self::SOURCE_ACTIVITY;
    }
}
