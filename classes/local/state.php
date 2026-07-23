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
 * The explicit group lifecycle state machine (spec section 5).
 *
 * forming -> pending_guide -> firm -> frozen, with guide return
 * (pending_guide -> forming) and manager unfreeze (frozen -> firm).
 * Transitions T2-T6 are implemented by their owning slices; this class
 * is the single authority on state names and legal edges from day one,
 * and every gatekeeper method states its state precondition (review
 * item S2).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state {
    /** @var string Leader edits, invites, transfers; members join and leave. */
    public const FORMING = 'forming';

    /** @var string Membership locked to students; guide approves or returns. */
    public const PENDING_GUIDE = 'pending_guide';

    /** @var string Approved; only manager staged moves alter membership. */
    public const FIRM = 'firm';

    /** @var string Mirrored into a core course group and locked. */
    public const FROZEN = 'frozen';

    /** @var string[][] Legal transitions: from-state to list of to-states. */
    private const EDGES = [
        self::FORMING => [self::PENDING_GUIDE],
        self::PENDING_GUIDE => [self::FORMING, self::FIRM],
        self::FIRM => [self::FROZEN],
        self::FROZEN => [self::FIRM],
    ];

    /**
     * All state names.
     *
     * @return string[]
     */
    public static function all(): array {
        return [self::FORMING, self::PENDING_GUIDE, self::FIRM, self::FROZEN];
    }

    /**
     * Whether a transition between two states is legal.
     *
     * @param string $from current state
     * @param string $to proposed state
     * @return bool
     */
    public static function is_legal(string $from, string $to): bool {
        return in_array($to, self::EDGES[$from] ?? [], true);
    }

    /**
     * Assert that a group row is in one of the expected states.
     *
     * Gatekeeper state preconditions (review item S2) funnel through
     * here so a stale POST can never act on a group whose state moved on.
     *
     * @param \stdClass $group group row
     * @param string[] $expected acceptable states
     * @throws \moodle_exception when the state does not match
     */
    public static function require_state(\stdClass $group, array $expected): void {
        if (!in_array($group->state, $expected, true)) {
            throw new \moodle_exception('errwrongstate', 'mod_selfselectadvanced', '', $group->state);
        }
    }
}
