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

namespace mod_selfselectadvanced\local\ui;

use mod_selfselectadvanced\local\rules\refusal;

/**
 * Whether a control is hidden, offered, or offered-but-refused-with-a-reason.
 *
 * ONE presentation policy for every action in the plugin (maintainer decision
 * 83, 2026-08-09). The two questions a page can ask about an action are
 * different questions and get different answers:
 *
 * - CAPABILITY asks "is this function for this person at all?" If no, the
 *   control is HIDDEN. Drawing it would leak the shape of the permission model
 *   to somebody with no business seeing it, and a control that can never work
 *   is not information.
 * - STATE, RULE or TIMING asks "why can't I do something that normally belongs
 *   to me?" If no, the control is DISABLED AND CARRIES THE REASON. The
 *   gatekeeper already wrote that sentence; discarding it is the defect this
 *   class exists to end.
 *
 * Why a class rather than nine careful edits. The 2026-08-09 audit found the
 * plugin doing both things in different places, and in one case contradicting
 * itself: the guide dashboard showed a disabled Release with "Frozen by staff -
 * ask through the request queue" while the team's own page showed that same
 * guide, on that same team, nothing at all. Nine independent judgement calls
 * is how that happened, and nine independent fixes would let it happen again.
 * The ruling asked for a convention; this is it, and
 * /srv/ci/ops/control-state.sh fails the build on the patterns that bypass it.
 *
 * SHIELDED REASONS. A refusal may itself be the disclosure. `refusalcoiinvolved`
 * reads "you cannot act because you are THE ASSIGNED GUIDE of it", which tells
 * the reader a relationship they may not be entitled to know. Those keys are
 * replaced with a generic sentence that preserves recoverability - the reader
 * still learns the action is possible for somebody, and who to ask - without
 * naming why. The list is here rather than at each call site because a caller
 * that must remember to shield is a caller that will one day forget.
 * `refusalcoiself` is deliberately NOT shielded: "you cannot grant yourself an
 * exception" discloses only what the actor already knows about themselves.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class control {
    /**
     * Refusal keys whose own text discloses a relationship the reader may not be entitled to.
     *
     * Adding a key here is a privacy decision, not a style one.
     */
    private const SHIELDED = [
        'refusalcoiinvolved',
    ];

    /**
     * Decide how one control is presented.
     *
     * @param bool $permitted the CAPABILITY answer: may this person ever do this?
     * @param refusal|null $refusal the STATE/RULE/TIMING answer, or null when nothing refuses
     * @return object show (bool), enabled (bool), reason (string, empty unless disabled)
     */
    public static function decide(bool $permitted, ?refusal $refusal): object {
        if (!$permitted) {
            // Not this person's function. Nothing is drawn and nothing is said:
            // an explanation here would describe the permission model to
            // somebody outside it.
            return (object) ['show' => false, 'enabled' => false, 'reason' => ''];
        }
        if ($refusal === null) {
            return (object) ['show' => true, 'enabled' => true, 'reason' => ''];
        }

        return (object) [
            'show' => true,
            'enabled' => false,
            'reason' => self::reason_for($refusal),
        ];
    }

    /**
     * Decide a control whose refusal arrives as a ready-made sentence rather than a refusal object.
     *
     * Some services answer with a CODE rather than a refusal - freeze's release
     * door is one, returning RELEASE_STAFFFROZE and friends - and the caller
     * resolves the code to text. They still owe the same policy, so they come
     * through here rather than assembling their own show/enabled/reason triple.
     *
     * @param bool $permitted the CAPABILITY answer
     * @param string $reason the refusal sentence, or an empty string when nothing refuses
     * @return object show (bool), enabled (bool), reason (string)
     */
    public static function decide_with_reason(bool $permitted, string $reason): object {
        if (!$permitted) {
            return (object) ['show' => false, 'enabled' => false, 'reason' => ''];
        }

        return (object) [
            'show' => true,
            'enabled' => $reason === '',
            'reason' => $reason,
        ];
    }

    /**
     * The sentence a refused-but-eligible actor reads.
     *
     * @param refusal $refusal the refusal
     * @return string the message, or a generic one where the real text would disclose a relationship
     */
    public static function reason_for(refusal $refusal): string {
        if (in_array($refusal->stringkey, self::SHIELDED, true)) {
            return get_string('refusalcoishielded', 'mod_selfselectadvanced');
        }

        return $refusal->get_message();
    }

    /**
     * Whether a refusal's own wording would disclose a relationship.
     *
     * Exposed so a test can assert the shield list is not silently emptied.
     *
     * @param string $stringkey the refusal's language key
     * @return bool true when the text is replaced by the generic sentence
     */
    public static function is_shielded(string $stringkey): bool {
        return in_array($stringkey, self::SHIELDED, true);
    }
}
