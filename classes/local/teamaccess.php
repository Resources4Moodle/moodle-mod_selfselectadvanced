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
 * Each team-scoped door is ONE method here, CALLED by the page:
 * group.php uses may_open_team(), review.php may_review_team(),
 * eoilist.php's drill-down may_drill_down(), and - since 1.20.1, audit
 * A-05 - the proposal FILE uses may_read_proposal(), called by
 * selfselectadvanced_pluginfile() and by the page that renders the
 * link. Nothing transcribes them.
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
     * Whether this person may READ the team's proposal file.
     *
     * A-05: until 1.20.1 selfselectadvanced_pluginfile() carried its
     * own copy of this - ":viewall OR confirmed member OR (guideid AND
     * :guide)" - and group.php carried a second copy to decide whether
     * to render a link at all. Two transcriptions of one question, and
     * they had already drifted from the pages: an assigned guide whose
     * site had withdrawn :viewassignedteams was refused every OTHER
     * door on their own team yet still passed the file server, because
     * the file server asked :guide; and a :manage-only reviewer, whom
     * may_review_team() admits to the review page where the proposal is
     * EMBEDDED, was refused the file the page had just linked. This is
     * the one policy both now call, so neither can drift again.
     *
     * DECISION (2026-08-02, this wave): the proposal's audience is NOT
     * every audience a page admits. It is the team's own confirmed
     * people, the team's guide, the staff who oversee the activity, and
     * the guide the team is CURRENTLY asking to take it on. Stated as
     * the four clauses below, and what each one deliberately excludes:
     *
     *  - CONFIRMED membership only. may_open_team() admits an INVITED
     *    person to the team page, and group.php has withheld the
     *    proposal link from them since 1.19.1 on the grounds that an
     *    invitation is not yet a membership. That stays true, and it is
     *    the reason this is a predicate of its own rather than a second
     *    call to may_open_team().
     *  - the ASSIGNED guide, on :viewassignedteams and not on :guide -
     *    the same test is_assigned_guide() applies everywhere else, so
     *    withdrawing that capability now closes every door at once
     *    instead of all but this one.
     *  - :viewall or :manage. :manage is named because eight
     *    manager-only actions live on the team page and because
     *    may_review_team() admits :manage to a page that embeds the
     *    file; :manage has never implied :viewall.
     *  - a LIVE approach. contacts::send() refuses a team that already
     *    has a guide, so a guide who has been approached is by
     *    construction NOT the assigned guide, and until now
     *    contactreview.php - the page whose entire purpose is "read
     *    their approach and decide" - handed them a link the file
     *    server refused. Only status SENT, an ALLOW list in the shape
     *    of DECISION 19: accepting pre-assigns them and the guideid
     *    clause takes over in the same instant, declining ends it, and
     *    a future sixth status is excluded by default.
     *
     * The two capability tests are asked before either query, so the
     * common staff case costs no read at all and the contact lookup is
     * reached only by somebody who has failed everything cheaper.
     *
     * Read-time only: no lock, no transaction, no write, no event.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group row (must carry guideid)
     * @param int $userid the viewer
     * @return bool
     */
    public static function may_read_proposal(activity $activity, \stdClass $group, int $userid): bool {
        global $DB;

        $context = $activity->context();
        if (
            has_capability('mod/selfselectadvanced:viewall', $context, $userid)
            || has_capability('mod/selfselectadvanced:manage', $context, $userid)
            || self::is_assigned_guide($activity, $group, $userid)
        ) {
            return true;
        }
        $confirmed = $DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $group->id,
            'userid' => $userid,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        if ($confirmed) {
            return true;
        }

        return has_capability('mod/selfselectadvanced:guide', $context, $userid)
            && $DB->record_exists('selfselectadvanced_contact', [
                'activityid' => $activity->id(),
                'groupid' => (int) $group->id,
                'guideid' => $userid,
                'status' => contacts::STATUS_SENT,
            ]);
    }

    /**
     * Whether this person may open ONE team's review page.
     *
     * The team arrives in review.php's URL, so the team-scoped question
     * has to be asked or any :guide holder reads any team's roster, its
     * members' composition attributes and the assigned guide's private
     * notes by editing one number. Membership is deliberately NOT a
     * door here: the review page is the guide's decision surface, not
     * the team's.
     *
     * THIS IS NOW THE WHOLE OF THAT PAGE'S GATE (finding ACT-001).
     * review.php also carried require_capability(':guide') on the
     * ACTIVITY, above this call, and that line refused the one audience
     * this predicate admits and the :guide archetype list does not
     * reach: db/access.php grants :guide to the non-editing teacher
     * alone, so a MANAGER - holding :viewall, named in the second arm
     * below, linked here from the flagged report and the team page -
     * was turned away at the door of a page documented as theirs.
     *
     * :guide MOVED INTO THE FIRST ARM rather than being deleted, and
     * the difference matters. The capability's own string is "Appear in
     * the guide list, review and approve groups": prohibit it and the
     * guide dashboard, the approval and the return all close (audit
     * D1), so the review page has to close with them or the plugin
     * offers a judgement surface to somebody it will refuse the
     * judgement. What the page-level check got wrong was its SCOPE, not
     * its subject - it asked :guide of everybody, including the staff
     * who reach this page without guiding anything. Asked here, of the
     * arm it belongs to, both hold at once: the assigned guide needs
     * both the assignment (:viewassignedteams, inside
     * is_assigned_guide) and the authority to review it, and the staff
     * arms need neither.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group row (must carry guideid)
     * @param int $userid the viewer
     * @return bool
     */
    public static function may_review_team(activity $activity, \stdClass $group, int $userid): bool {
        $context = $activity->context();

        return (self::is_assigned_guide($activity, $group, $userid)
                && has_capability('mod/selfselectadvanced:guide', $context, $userid))
            || has_any_capability([
                'mod/selfselectadvanced:manage',
                'mod/selfselectadvanced:viewall',
            ], $context, $userid);
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
