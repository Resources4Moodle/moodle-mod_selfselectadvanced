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

use mod_selfselectadvanced\activity;

/**
 * Capability authority for the student- and guide-facing services: the
 * one home for the question "is this actor ALLOWED to do this at all?"
 *
 * The distinction this class exists to keep is the one the 1.20 audit
 * found collapsed in three places at once:
 *
 * - RECORD OWNERSHIP - "is this person the leader of this team?" -
 *   lives on the group row and is judged by the services.
 * - RULE ELIGIBILITY - windows, seat counts, caps, lifecycle state -
 *   lives in rules\gatekeeper.
 * - CAPABILITY AUTHORITY - what an administrator has granted, prevented
 *   or PROHIBITED - lives here, and nowhere else.
 *
 * A leader who still owns the row and still passes every rule may have
 * had their capability taken away since; owning a record has never been
 * a grant of authority. Before this class the services asked the first
 * two questions and simply never asked the third, so an activity-level
 * Prohibit stopped the page and nothing else: the service was reachable
 * by direct POST, by an adhoc task queued before the revocation, and by
 * any other caller.
 *
 * Every method takes the actor explicitly. Nothing here reads $USER: a
 * queued task runs long after its actor's session is gone, and "the
 * current user" is exactly the wrong answer there.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class authority {
    /** @var string Create a team and act as its leader (spec T1, T7). */
    public const CREATEGROUP = 'mod/selfselectadvanced:creategroup';

    /** @var string Accept or decline invitations and nominations (spec 6.2). */
    public const RESPOND = 'mod/selfselectadvanced:respond';

    /** @var string Freeze a firm team, singly or in bulk (spec T5). */
    public const FREEZE = 'mod/selfselectadvanced:freeze';

    /**
     * May this actor act as a team leader in this activity?
     *
     * Creating a team, inviting into it, withdrawing an invitation and
     * confirming a member's leave are all the same authority - the
     * capability is named "Create groups and act as leader" - so they
     * all ask this one question.
     *
     * @param activity $activity the activity
     * @param int $actorid the person acting
     * @return bool true when the capability is effective for them here
     */
    public static function may_lead(activity $activity, int $actorid): bool {
        return has_capability(self::CREATEGROUP, $activity->context(), $actorid);
    }

    /**
     * Refuse unless this actor may act as a team leader here.
     *
     * @param activity $activity the activity
     * @param int $actorid the person acting
     * @throws \required_capability_exception when the capability is not effective
     */
    public static function require_lead(activity $activity, int $actorid): void {
        require_capability(self::CREATEGROUP, $activity->context(), $actorid);
    }

    /**
     * May this actor respond to an invitation or a nomination here?
     *
     * @param activity $activity the activity
     * @param int $actorid the person acting
     * @return bool true when the capability is effective for them here
     */
    public static function may_respond(activity $activity, int $actorid): bool {
        return has_capability(self::RESPOND, $activity->context(), $actorid);
    }

    /**
     * Refuse unless this actor may respond to an invitation here.
     *
     * Both halves of the response are gated, accept AND decline. The
     * capability is named for both ("Accept or decline invitations and
     * nominations"), and the audit found neither checked. There is no
     * cleanup exception: an invitation a student may no longer answer
     * is withdrawn by its leader or expired by the invitation task,
     * and BOTH of those paths are system or leader authority rather
     * than the invitee's - so nothing is stranded by refusing here.
     *
     * @param activity $activity the activity
     * @param int $actorid the person acting
     * @throws \required_capability_exception when the capability is not effective
     */
    public static function require_respond(activity $activity, int $actorid): void {
        require_capability(self::RESPOND, $activity->context(), $actorid);
    }

    /**
     * May this actor freeze a team in this activity?
     *
     * @param activity $activity the activity
     * @param int $actorid the person acting
     * @return bool true when the capability is effective for them here
     */
    public static function may_freeze(activity $activity, int $actorid): bool {
        return has_capability(self::FREEZE, $activity->context(), $actorid);
    }

    /**
     * Refuse unless this actor may freeze a team here.
     *
     * Asked at QUEUE time by guide.php and again, on the same actor,
     * before every single mutation the queued overflow performs. The
     * queue is the whole point: a bulk freeze of more than
     * freeze::BULK_FREEZE_INLINE_MAX teams finishes on a later cron
     * pass, and the authority that has to hold is the one in force
     * WHEN THE TEAM IS FROZEN, not the one that was in force when the
     * button was pressed.
     *
     * @param activity $activity the activity
     * @param int $actorid the person acting
     * @throws \required_capability_exception when the capability is not effective
     */
    public static function require_freeze(activity $activity, int $actorid): void {
        require_capability(self::FREEZE, $activity->context(), $actorid);
    }
}
