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
 * Who may open ONE team, as opposed to who may see everything.
 *
 * Until 1.20.1 the plugin had no way to say "the team I am responsible
 * for", so the activity-wide :viewall stood in for it at group.php's
 * entry gate - and a guide is never a MEMBER of the team they guide, so
 * a site that withdrew :viewall from its non-editing teachers refused
 * every guide their own team's page. :viewassignedteams answers the
 * narrow question; this class is the single place that asks it, because
 * four copies of a predicate is how this plugin acquired four different
 * answers to it.
 *
 * Each of the three team-scoped doors is ONE method here, CALLED by the
 * page: group.php uses may_open_team(), review.php may_review_team() and
 * eoilist.php's drill-down may_drill_down(). Nothing transcribes them.
 * The first cut of this class shipped with the predicates duplicated
 * inline on the pages and a unit test comparing one copy against
 * another, which is a test of the copy: reverting a page's gate left it
 * green. A helper nothing calls is not a single source of truth, so the
 * calls are the fix and the tests here exercise the same functions the
 * pages run.
 *
 * Read-time only: no lock, no transaction, no write, no event. Never
 * call from db/upgrade.php - it queries plugin tables.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teamaccess {
    /**
     * Whether this person is the guide the team names, with the
     * capability to act on that.
     *
     * The state is deliberately NOT tested: guideid can be set on a
     * FORMING team through an accepted expression of interest, and that
     * guide has a decision to make on the page.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group row (must carry guideid)
     * @param int $userid the viewer
     * @return bool
     */
    public static function is_assigned_guide(activity $activity, \stdClass $group, int $userid): bool {
        return (int) ($group->guideid ?? 0) > 0
            && (int) $group->guideid === $userid
            && has_capability('mod/selfselectadvanced:viewassignedteams', $activity->context(), $userid);
    }

    /**
     * Whether this person may open this team's page at all.
     *
     * Member (confirmed or invited - the audience group.php has always
     * admitted), or the assigned guide, or a holder of the broad read
     * capability, or a manager. Managers are named explicitly because
     * eight manager-only actions live on that page (dissolve, resync,
     * discard, EOI decision, ticket, proposal upload and both freeze
     * directions) and :manage has never implied :viewall.
     *
     * What the page then RENDERS about participants is decided
     * per-viewer, by this same predicate on group_page.php and by
     * contactprivacy for identity fields - so widening the door never
     * widens the window.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group row
     * @param int $userid the viewer
     * @return bool
     */
    public static function may_open_team(activity $activity, \stdClass $group, int $userid): bool {
        global $DB;

        $context = $activity->context();
        $membership = $DB->get_record('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'userid' => $userid,
        ]);
        $ismember = $membership && in_array($membership->status, [
            groups::STATUS_CONFIRMED,
            groups::STATUS_INVITED,
        ], true);

        return $ismember
            || self::is_assigned_guide($activity, $group, $userid)
            || has_capability('mod/selfselectadvanced:viewall', $context, $userid)
            || has_capability('mod/selfselectadvanced:manage', $context, $userid);
    }

    /**
     * Whether this person may open ONE team's review page.
     *
     * review.php's own gate is :guide on the ACTIVITY and the team
     * arrives in the URL, so the team-scoped question has to be asked
     * separately or any :guide holder reads any team's roster, its
     * members' composition attributes and the assigned guide's private
     * notes by editing one number. Membership is deliberately NOT a
     * door here: the review page is the guide's decision surface, not
     * the team's.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group row (must carry guideid)
     * @param int $userid the viewer
     * @return bool
     */
    public static function may_review_team(activity $activity, \stdClass $group, int $userid): bool {
        return self::is_assigned_guide($activity, $group, $userid)
            || has_any_capability([
                'mod/selfselectadvanced:manage',
                'mod/selfselectadvanced:viewall',
            ], $activity->context(), $userid);
    }

    /**
     * Whether this guide may drill into ONE team's member list from the
     * expression-of-interest screen.
     *
     * The drill-down's audience is the guide's own LIVE interest in that
     * team - the per-request IDOR guard (spec 14.12), so a guide cannot
     * browse every team's roster by guessing group ids.
     *
     * DECISION 19 (maintainer, 2026-08-01): a REJECTED or WITHDRAWN
     * applicant loses the team at once - the team said no, and "no" has
     * to mean the roster closes. EXPIRED goes with them: an interest
     * nobody answered is by construction not live. Stated as an ALLOW
     * list so a future sixth status is excluded by default rather than
     * admitted by oversight; a withdrawal sets a status and does not
     * delete the row, so the status test is the whole mechanism.
     *
     * DECISION 20 (maintainer, 2026-08-01): an ACCEPTED interest is how
     * a guide becomes the assigned guide, and it keeps the drill-down
     * only while they still ARE that guide - g.guideid, read live. That
     * single condition is the whole of "the outgoing guide keeps sight
     * until the handover completes", because the handover workflow
     * already defines completion: handover::propose() leaves guideid on
     * the proposer and only handover::accept() moves it (the team is
     * never left guideless), so a PENDING handover still matches and an
     * ACCEPTED one stops matching in the same instant the team changes
     * hands. A staff reassignment through state::assign_guide() writes
     * the same column and therefore ends the access immediately, which
     * is the case with no handover record at all. No second notion of
     * "in progress" is introduced here, because two definitions drift.
     *
     * @param activity $activity the activity
     * @param int $groupid the team the drill-down names
     * @param int $userid the interested guide
     * @return bool
     */
    public static function may_drill_down(activity $activity, int $groupid, int $userid): bool {
        global $DB;

        if ($groupid <= 0 || $userid <= 0) {
            return false;
        }

        // One indexed read: the interest and the team it names.
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {selfselectadvanced_eoi} e
               JOIN {selfselectadvanced_group} g ON g.id = e.groupid
              WHERE e.activityid = :aid AND e.groupid = :gid AND e.guideid = :uid
                AND (e.status = :pending
                     OR (e.status = :accepted AND g.guideid = :assigned))",
            [
                'aid' => $activity->id(),
                'gid' => $groupid,
                'uid' => $userid,
                'pending' => eoi::STATUS_PENDING,
                'accepted' => eoi::STATUS_ACCEPTED,
                'assigned' => $userid,
            ]
        );
    }
}
