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
 * The verdict of the approval gate, minus the assigned-guide identity
 * check.
 *
 * Produced by gatekeeper::autoapprove_plan(); consumed by
 * gatekeeper::can_approve() (the manual path) and by
 * state::approve_auto() (the guide-window sweep), so the two paths can
 * never drift apart.
 *
 * $refusal is a HARD refusal - no relief can repair it. $relief is the
 * exact group-scope override field set a forced approval must record to
 * explain itself, empty when the team passes on its own; $reliefreasons
 * carries, per relief field, the refusal the MANUAL path returns for
 * that same shortfall, in the order can_approve reports them.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class autoapprove_plan {
    /**
     * Constructor.
     *
     * @param refusal|null $refusal hard refusal, null when the team may be approved
     * @param array $relief group-scope override field => value the sweep must record
     * @param array $reliefreasons relief field => the refusal the manual path returns
     */
    public function __construct(
        /** @var refusal|null Hard refusal, null when the team may be approved. */
        public readonly ?refusal $refusal = null,
        /** @var array<string, int> Group-scope override field => value the sweep must record. */
        public readonly array $relief = [],
        /** @var array<string, refusal> Relief field => the refusal the manual path returns. */
        public readonly array $reliefreasons = [],
    ) {
    }
}
