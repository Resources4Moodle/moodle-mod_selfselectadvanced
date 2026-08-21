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
use stdClass;

/**
 * The sequential request queue (strategy 1.16 B): guide
 * composition-change requests and unfreeze requests, listed first come
 * first served and claimed EXCLUSIVELY - the claim runs under a
 * per-ticket lock in a transaction, re-reads the row, and updates with
 * WHERE status='open', so of two managers taking the same ticket
 * exactly one wins and the other is told who holds it.
 *
 * Resolving never mutates the team here: the claimant performs the
 * actual change with the existing, already-locked tools, then closes
 * the ticket with a note. A direct unfreeze auto-resolves the group's
 * open unfreeze ticket so the queue cannot go stale.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tickets {
    /** @var string A guide asks the staff to change their team's composition. */
    public const TYPE_COMPCHANGE = 'compchange';

    /** @var string The guide or leader of a frozen team asks for an unfreeze. */
    public const TYPE_UNFREEZE = 'unfreeze';

    /**
     * @var string A guide asks the coordinators to raise their team limit
     *      (strategy 1.18 C). Alone among the types this one is not about
     *      a team: its groupid is null and it carries the number asked for.
     */
    public const TYPE_GUIDECAP = 'guidecap';

    /**
     * @var string A firm or frozen team's guide was deleted or lost their
     *      last enrolment (OBS-001): the team keeps its state, and a
     *      coordinator resolves the succession deliberately. Filed by the
     *      observers, never by a person, so requestedby records the actor
     *      whose deletion or unenrolment caused it.
     */
    public const TYPE_GUIDEGONE = 'guidegone';

    /**
     * @var string A guide asks the coordinators to LOWER their team limit,
     *      or with `requested` 0 to be relieved entirely (maintainer flow
     *      d, 2026-08-06). Suggested replacement guides travel in the
     *      request text; the coordinator rehomes teams deliberately with
     *      the handover and assignment tools, never by side effect of the
     *      grant.
     */
    public const TYPE_GUIDEREDUCE = 'guidereduce';

    /**
     * @var string A guide asks for a date-window extension for their team
     *      (maintainer flow e). The coordinator grants it through the
     *      existing group-scope override form; the ticket carries the ask.
     */
    public const TYPE_DATES = 'dates';

    /**
     * @var string A guide asks the plugin's lateness penalty be waived for
     *      their team (maintainer flow e). Plugin-level relief only - the
     *      gradebook remains the editing teacher's, deliberately.
     */
    public const TYPE_PENALTY = 'penalty';

    /**
     * @var string A confirmed MEMBER asks the coordinator queue for a
     *      leadership change - "Leadership help" (maintainer decision 71).
     *
     * This is the one ticket type filed by a member rather than a guide or a
     * leader, and it exists because every other leader-only action already has
     * a staff route for an absent or unresponsive leader while this one did
     * not. It is deliberately NOT a member-controlled transfer: the ticket is
     * an ASK, decided by a coordinator under the same conflict-of-interest rule
     * as every other type, and any actual change goes through the existing
     * succession and move machinery.
     *
     * Prerequisite, now met: decision 81 split :creategroup from :lead, so a
     * successor cannot be installed without the authority to lead (1.20.26).
     */
    public const TYPE_LEADERCHANGE = 'leaderchange';

    /**
     * @var string A free-form request from any eligible raiser (1.20.43,
     *      maintainer decision): the general channel a group leader did
     *      not have before this - until now a leader's only ticket was
     *      unfreeze-on-frozen. groupid is the raiser's own group, or 0
     *      when they have none (the display idiom already used for
     *      guidecap's groupless requests). Filed through file_help(),
     *      never through file() - the (group, type) duplicate guard the
     *      other types share does not fit a ticket that can carry no
     *      group at all, so this type keeps its own per-requester guard.
     */
    public const TYPE_HELP = 'help';

    /** @var string Waiting in the queue. */
    public const STATUS_OPEN = 'open';

    /** @var string One manager or coordinator is working it. */
    public const STATUS_CLAIMED = 'claimed';

    /**
     * @var string The claimant asked the requester a question and the
     *      ticket is waiting for the answer (maintainer decision 2,
     *      2026-08-15). Counts as LIVE everywhere open and claimed
     *      already did - the point of the state is that the ticket is
     *      still somebody's active work, not that it has stopped being
     *      anybody's.
     */
    public const STATUS_NEEDSINFO = 'needsinfo';

    /** @var string Done, with a resolution note. */
    public const STATUS_RESOLVED = 'resolved';

    /** @var string Refused, with the reason. */
    public const STATUS_DECLINED = 'declined';

    /** @var string Taken back by the requester while still open. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @var string ACTION_* below name every row selfselectadvanced_ticketlog
     *      can hold (decision 1, 2026-08-15: the history trail). Several
     *      share their string with a STATUS_* constant above by
     *      coincidence, not by reuse - a status is what the TICKET is now;
     *      an action is what HAPPENED to it, and 'filed', 'released' and
     *      'inforeply' have no status of their own at all.
     */
    public const ACTION_FILED = 'filed';

    /** @var string A worker took the ticket out of the open queue. */
    public const ACTION_CLAIMED = 'claimed';

    /** @var string The claimant let go of it without deciding it. */
    public const ACTION_RELEASED = 'released';

    /** @var string The claimant asked the requester a question. */
    public const ACTION_NEEDSINFO = 'needsinfo';

    /** @var string The requester answered a needs-info question. */
    public const ACTION_INFOREPLY = 'inforeply';

    /** @var string Closed with an outcome the requester gets. */
    public const ACTION_RESOLVED = 'resolved';

    /** @var string Refused, with the reason. */
    public const ACTION_DECLINED = 'declined';

    /** @var string The requester took it back while still open. */
    public const ACTION_WITHDRAWN = 'withdrawn';

    /**
     * @var string The claimant handed the ticket to another coordinator
     *      (1.20.44, the handling ladder's "refer" rung): claimedby
     *      changes, status does not, and the requester's own trail is
     *      unaffected - "Somebody is handling this." stays true, so this
     *      action is one of STAFF_INTERNAL_ACTIONS below and never
     *      reaches tickets::trail($withactors = false).
     */
    public const ACTION_REFERRED = 'referred';

    /**
     * @var string A claimant or a manage-level holder raised the ticket
     *      to the editing-teacher/manager tier (1.20.44, the handling
     *      ladder's "escalate" rung). Like ACTION_REFERRED, this is
     *      staff-internal ladder machinery: the requester's status badge
     *      already reflects any resulting change (a released claim shows
     *      as the ticket going back to Open), so the trail row itself is
     *      never shown to them - see STAFF_INTERNAL_ACTIONS.
     */
    public const ACTION_ESCALATED = 'escalated';

    /**
     * @var string A claimant published this resolved ticket to the
     *      knowledgebank (1.20.45, kb::publish_from_ticket()). Staff-
     *      internal by design - see STAFF_INTERNAL_ACTIONS below - the
     *      maintainer's own words are "no public link back": the
     *      published article is anonymised (requester and group
     *      stripped), and this trail row exists so STAFF can see it was
     *      done, never so the requester can recognise their own ticket
     *      reflected back at them.
     */
    public const ACTION_PUBLISHED_FAQ = 'published_faq';

    /**
     * @var string A THREAD POST that does not close the ticket (1.20.46,
     *      the LLM API's "respond" half of read+respond): the claimant
     *      replies, the ticket stays exactly where it was. Requester-
     *      visible like inforeply/resolved - never staff-internal - so it
     *      is deliberately absent from STAFF_INTERNAL_ACTIONS below.
     */
    public const ACTION_COMMENTED = 'commented';

    /**
     * @var string The REQUESTER answered "did this help?" with yes
     *      (1.20.59, give_feedback()). Requester-visible like filed/
     *      inforeply/withdrawn - never staff-internal, and deliberately
     *      absent from STAFF_INTERNAL_ACTIONS below, because it is the
     *      requester's own action about their own request.
     */
    public const ACTION_FEEDBACK_HELPED = 'feedbackhelped';

    /**
     * @var string The REQUESTER answered "did this help?" with no
     *      (1.20.59, give_feedback()). The one verdict staff most need
     *      to see without opening the ticket (deliverable B) - split
     *      from ACTION_FEEDBACK_HELPED as its own action, the same way
     *      ACTION_RESOLVED and ACTION_DECLINED are two actions rather
     *      than one "closed" action with the outcome elsewhere, so the
     *      trail's own wording (threadentryfeedbacknothelped) never
     *      depends on a second field.
     */
    public const ACTION_FEEDBACK_NOTHELPED = 'feedbacknothelped';

    /** @var int No feedback given yet - the column's own default. */
    public const VERDICT_UNANSWERED = 0;

    /** @var int The requester said the resolution helped. */
    public const VERDICT_HELPED = 1;

    /** @var int The requester said the resolution did not help. */
    public const VERDICT_NOTHELPED = 2;

    /**
     * @var string[] Trail actions that are ladder machinery between
     *      staff, never narrated to the REQUESTER's anonymised view
     *      (1.20.44). Part 2 of the same release calls a referral's or
     *      an escalation's note "staff-internal" in so many words when it
     *      excludes both from the ticketpost filearea; this is that same
     *      call applied to the trail text itself. The requester still
     *      sees every STATUS change that results (an escalation that
     *      releases a coordinator's claim shows as the ticket badge
     *      going back to "Open") - what is withheld is the narration of
     *      WHY, and to whom.
     *
     * ACTION_PUBLISHED_FAQ joins this list in 1.20.45 for the same
     * reason: it changes nothing about the ticket's own status, so
     * hiding it from the requester's trail costs them no information
     * about their request - only the (deliberately anonymised) fact
     * that their words informed an FAQ elsewhere.
     */
    public const STAFF_INTERNAL_ACTIONS = [
        self::ACTION_REFERRED,
        self::ACTION_ESCALATED,
        self::ACTION_PUBLISHED_FAQ,
    ];

    /**
     * Build the plugin-scoped human-readable ticket reference (1.20.56
     * deliverable A): prefix-course-T-number, e.g. SSA-PHYS101-T0042.
     * Mirrors groups::build_pluginuid()'s shape - the SAME manager-set
     * `uidprefix` activity setting and course-name derivation (no new
     * setting is added for tickets), so the two reference families read
     * as one - with the ticket's own database id supplying the
     * uniqueness exactly as the group's id does for its project id:
     * unique plugin-wide forever, with no search or retry, because a
     * ticket id is. The literal T marker is the one difference from a
     * group's own shape: a ticket and a group are independent
     * autoincrement sequences that can coincide numerically, and without
     * it a ticket could read identically to a group's own project id for
     * the same course.
     *
     * Called ONCE per ticket, right after its row is inserted (the id the
     * reference embeds does not exist before that), from every filer
     * inside the SAME lock the insert itself runs under - so two
     * concurrent filings can never mint the same reference - and never
     * again afterwards: minted once, never rewritten, the same rule
     * groups::build_pluginuid() states for the id this mirrors.
     *
     * The actual shape is ticketrefshape::build() - shared with
     * db/upgrade.php's backfill and the restore step's own regenerate-
     * on-collision path, neither of which can reach an activity object
     * the way every caller here always has one in hand (see that class's
     * own docblock for why); this is simply the ordinary path calling it
     * with an activity object's own values instead of duplicating the
     * shape a third time.
     *
     * @param activity $activity the activity
     * @param int $ticketid the ticket's DB id
     * @return string
     */
    public static function build_pluginuid(activity $activity, int $ticketid): string {
        $course = get_course($activity->courseid());

        return ticketrefshape::build(
            (string) ($activity->settings()->uidprefix ?? ''),
            (string) $course->shortname,
            (string) $course->fullname,
            $activity->courseid(),
            $ticketid
        );
    }

    /**
     * File a ticket.
     *
     * Who may file what: the group's assigned guide files either type;
     * the group's leader files unfreeze requests only. Unfreeze
     * requests need a FROZEN group; composition-change requests a
     * firm or frozen one. One OPEN or CLAIMED ticket per (group,
     * type) - a duplicate is refused pointing at the live one.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @param string $type self::TYPE_*
     * @param string $request why, from the requester
     * @param int $requestformat text format of the request
     * @param int $userid the requester
     * @param bool $disclaimerack whether the requester passed the
     *        activity's disclaimer acknowledgement (1.20.43); ignored
     *        when the activity has no disclaimer set
     * @return stdClass the ticket row
     * @throws \moodle_exception when a gate refuses
     */
    public static function file(
        activity $activity,
        stdClass $group,
        string $type,
        string $request,
        int $requestformat,
        int $userid,
        bool $disclaimerack = false
    ): stdClass {
        global $DB;

        $knowntypes = [
            self::TYPE_COMPCHANGE,
            self::TYPE_UNFREEZE,
            self::TYPE_DATES,
            self::TYPE_PENALTY,
            self::TYPE_LEADERCHANGE,
        ];
        if (!in_array($type, $knowntypes, true)) {
            throw new \coding_exception('Unknown ticket type ' . $type);
        }
        if (trim(html_to_text($request)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }
        self::require_disclaimer_ack($activity, $disclaimerack);

        $events = new eventqueue();
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Who may file, and in which state, is judged on a row read
            // INSIDE the lock (house rule A7), never on the one the
            // caller happened to load. The caller's copy can be minutes
            // old by the time the lock is granted - waiting behind a
            // manager's unfreeze is exactly how that happens - and a
            // stale copy would file an unfreeze request for a team that
            // is no longer frozen, or let a guide replaced by a
            // completed handover file against a team that is no longer
            // theirs. Either would sit in the queue holding the one
            // live slot that team's real requests need.
            $group = groups::get($activity, (int) $group->id);
            $isguide = (int) $group->guideid === $userid && $userid > 0;
            $isleader = (int) $group->leaderid === $userid;
            if ($type === self::TYPE_COMPCHANGE && !$isguide) {
                throw new workflow_refusal('refusalticketnotguide', 'mod_selfselectadvanced');
            }
            if ($type === self::TYPE_UNFREEZE && !$isguide && !$isleader) {
                throw new workflow_refusal('refusalticketnotparty', 'mod_selfselectadvanced');
            }
            if ($type === self::TYPE_UNFREEZE && $group->state !== state::FROZEN) {
                throw new workflow_refusal('refusalwrongstate', 'mod_selfselectadvanced');
            }
            if ($type === self::TYPE_COMPCHANGE && !in_array($group->state, [state::FIRM, state::FROZEN], true)) {
                throw new workflow_refusal('refusalwrongstate', 'mod_selfselectadvanced');
            }
            // Flow (e): only the team's OWN assigned guide may ask, and
            // only once there IS a guide relationship - a submitted,
            // firm or frozen team. Forming teams have no guide to ask.
            if (in_array($type, [self::TYPE_DATES, self::TYPE_PENALTY], true)) {
                if (!$isguide) {
                    throw new workflow_refusal('refusalticketnotguide', 'mod_selfselectadvanced');
                }
                if (!in_array($group->state, [state::PENDING_GUIDE, state::FIRM, state::FROZEN], true)) {
                    throw new workflow_refusal('refusalwrongstate', 'mod_selfselectadvanced');
                }
            }
            // DECISION 71. The only type a MEMBER files, so the authority test
            // is membership rather than the guide or leader relationship - and
            // it is re-read inside the lock like every other arm here, because
            // somebody who left the team between page render and POST must not
            // be able to ask for its leadership to change.
            //
            // The LEADER is excluded deliberately. A leader who wants out has
            // succession, which is theirs to drive; this ticket exists for the
            // other direction, when the leader is absent or is the problem.
            if ($type === self::TYPE_LEADERCHANGE) {
                if ($isleader) {
                    throw new workflow_refusal('refusalleaderchangeisleader', 'mod_selfselectadvanced');
                }
                $confirmed = $DB->record_exists('selfselectadvanced_member', [
                    'groupid' => (int) $group->id,
                    'userid' => $userid,
                    'status' => groups::STATUS_CONFIRMED,
                ]);
                if (!$confirmed) {
                    throw new workflow_refusal('refusalticketnotparty', 'mod_selfselectadvanced');
                }
            }

            // 1.20.43 deliverable A, ON TOP of the relational checks
            // above: a member with the member checkbox unticked cannot
            // file anything, and a member with it ticked still cannot
            // file a guide-only type - the checks above already decided
            // WHO may file this type at all, and this only asks whether
            // the activity still allows that role to raise tickets.
            // UNFREEZE is the one type two roles can file, so its role
            // follows whichever relational arm actually admitted this
            // actor above.
            $role = match (true) {
                $type === self::TYPE_UNFREEZE && $isguide => 'guide',
                $type === self::TYPE_UNFREEZE => 'leader',
                $type === self::TYPE_LEADERCHANGE => 'member',
                default => 'guide',
            };
            self::require_may_raise($activity, $role, $userid);
            // Deliverable C, ON TOP of deliverable A: the responsible-
            // person mode, when on, narrows raising further to the one
            // person responsible for this SPECIFIC group at its current
            // stage. Re-asked here, after the group re-read inside the
            // lock above, for the same reason every other gate in this
            // method is.
            self::require_responsible($activity, $group, $userid);

            $live = $DB->get_record_select(
                'selfselectadvanced_ticket',
                "groupid = :groupid AND type = :type AND status IN (:open, :claimed, :needsinfo)",
                [
                    'groupid' => $group->id,
                    'type' => $type,
                    'open' => self::STATUS_OPEN,
                    'claimed' => self::STATUS_CLAIMED,
                    'needsinfo' => self::STATUS_NEEDSINFO,
                ]
            );
            if ($live) {
                throw new workflow_refusal('refusalticketduplicate', 'mod_selfselectadvanced', '', (int) $live->id);
            }

            $now = time();
            $ticket = (object) [
                'activityid' => $activity->id(),
                'pluginuid' => '',
                'groupid' => (int) $group->id,
                'type' => $type,
                'status' => self::STATUS_OPEN,
                'requestedby' => $userid,
                'request' => $request,
                'requestformat' => $requestformat,
                'disclaimerack' => $disclaimerack ? 1 : 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $ticket->id = $DB->insert_record('selfselectadvanced_ticket', $ticket);
            // 1.20.56 deliverable A: minted INSIDE this lock, exactly like
            // groups::build_pluginuid()'s own two-step insert-then-set (the
            // id the reference embeds does not exist until the insert
            // above returns), so two concurrent filings can never mint the
            // same reference.
            $ticket->pluginuid = self::build_pluginuid($activity, (int) $ticket->id);
            $DB->set_field('selfselectadvanced_ticket', 'pluginuid', $ticket->pluginuid, ['id' => $ticket->id]);

            // The request text already lives on the ticket row, so the
            // trail's own note is null - the filed action itself is
            // what the trail records, not a second copy of the words.
            // Logged BEFORE the event (B2, addendum item 1): the
            // event's other.ticketlogid names this very row, so the row
            // has to exist first.
            $ticketlogid = self::log($ticket->id, $userid, self::ACTION_FILED, null, FORMAT_PLAIN);

            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => $type,
                    'pluginuid' => $group->pluginuid,
                    'action' => self::ACTION_FILED,
                    'groupid' => (int) $group->id,
                    'ticketlogid' => $ticketlogid,
                    'disclaimerack' => $disclaimerack ? 1 : 0,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        self::notify_workers($activity, $ticket, $group);
        // Decision 71: the current leader is told, always. The ruling is
        // explicit about it, and the reason is not courtesy - a leadership ask
        // decided behind the leader's back is exactly the "member-controlled
        // transfer" the ruling forbids. The body carries the member's words so
        // the leader can respond before a coordinator picks it up; it does NOT
        // name who filed it, because the queue decides this, not the team.
        //
        // Sent after the lock is released, like notify_workers above: a message
        // send inside a plugin lock is a reported defect in this codebase.
        if ($type === self::TYPE_LEADERCHANGE && (int) $group->leaderid > 0) {
            notifier::send(
                $activity,
                'tickets',
                (int) $group->leaderid,
                'msgleaderchangefiledsubject',
                'msgleaderchangefiledbody',
                (object) [
                    'group' => format_string($group->name),
                    'reason' => trim(html_to_text($request)),
                ],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => (int) $group->id,
                ]),
                format_string($group->name)
            );
        }

        return $ticket;
    }

    /**
     * A guide asks the coordinators to raise their team limit
     * (strategy 1.18 C).
     *
     * Unlike the other two types this request is about the guide, not a
     * team, so it takes no group lock and stores no groupid. The number
     * asked for travels on the ticket, which is what lets a coordinator
     * grant it in one action rather than transcribing it into an
     * override by hand.
     *
     * One live request at a time, as with every other type: a second
     * one while the first is open or claimed is refused pointing at it.
     *
     * @param activity $activity the activity
     * @param int $requested how many teams the guide is asking to hold
     * @param string $request why, from the guide
     * @param int $requestformat text format of the request
     * @param int $userid the guide asking
     * @return stdClass the ticket row
     * @throws \moodle_exception when a gate refuses
     */
    public static function file_guidecap(
        activity $activity,
        int $requested,
        string $request,
        int $requestformat,
        int $userid
    ): stdClass {
        global $DB;

        require_capability('mod/selfselectadvanced:guide', $activity->context(), $userid);
        if (trim(html_to_text($request)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }
        // Deliverable A: the guide checkbox, on top of the :guide
        // capability just asked above. Not gated on the disclaimer
        // (deliverable D) - this method has no UI of its own that could
        // ever pass an ack, and gating it here without one would make a
        // capacity request permanently unfileable the moment an activity
        // set a disclaimer.
        self::require_may_raise($activity, 'guide', $userid);

        // Asking for nothing, or for less than the activity's own
        // ceiling allows, is not a request anybody can act on.
        $ceiling = (new api($activity))->gatekeeper()->resolver()->guide_capacity_ceiling($userid);
        if ($requested < 1) {
            throw new workflow_refusal('refusalguidecapzero', 'mod_selfselectadvanced');
        }
        if ($requested <= $ceiling->value) {
            throw new workflow_refusal('refusalguidecapnotmore', 'mod_selfselectadvanced', '', $ceiling->value);
        }

        // Serialised on the guide, not on a team: two requests from the
        // same guide race each other and nothing else.
        $events = new eventqueue();
        $lock = locks::acquire('guidecap:' . $userid);
        try {
            $transaction = $DB->start_delegated_transaction();

            // The guard spans BOTH capacity types (2026-08-06): an open
            // raise and an open reduction from one guide would be two
            // contradictory instructions in one queue.
            $live = $DB->get_record_select(
                'selfselectadvanced_ticket',
                "activityid = :activityid AND type IN (:type, :reduce) AND requestedby = :userid"
                    . " AND status IN (:open, :claimed, :needsinfo)",
                [
                    'activityid' => $activity->id(),
                    'type' => self::TYPE_GUIDECAP,
                    'reduce' => self::TYPE_GUIDEREDUCE,
                    'userid' => $userid,
                    'open' => self::STATUS_OPEN,
                    'claimed' => self::STATUS_CLAIMED,
                    'needsinfo' => self::STATUS_NEEDSINFO,
                ]
            );
            if ($live) {
                throw new workflow_refusal('refusalticketduplicate', 'mod_selfselectadvanced', '', (int) $live->id);
            }

            $now = time();
            $ticket = (object) [
                'activityid' => $activity->id(),
                'pluginuid' => '',
                'groupid' => null,
                'type' => self::TYPE_GUIDECAP,
                'status' => self::STATUS_OPEN,
                'requestedby' => $userid,
                'request' => $request,
                'requestformat' => $requestformat,
                'requested' => $requested,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $ticket->id = $DB->insert_record('selfselectadvanced_ticket', $ticket);
            // 1.20.56 deliverable A: minted inside this lock (see file()'s
            // own comment on the same two-step pattern).
            $ticket->pluginuid = self::build_pluginuid($activity, (int) $ticket->id);
            $DB->set_field('selfselectadvanced_ticket', 'pluginuid', $ticket->pluginuid, ['id' => $ticket->id]);

            $ticketlogid = self::log($ticket->id, $userid, self::ACTION_FILED, null, FORMAT_PLAIN);

            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => self::TYPE_GUIDECAP,
                    'pluginuid' => '',
                    'action' => self::ACTION_FILED,
                    'groupid' => 0,
                    'ticketlogid' => $ticketlogid,
                    'disclaimerack' => 0,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        self::notify_workers($activity, $ticket, null);

        return $ticket;
    }

    /**
     * A guide asks the coordinators to LOWER their team limit, or to be
     * relieved entirely (maintainer flow d, 2026-08-06).
     *
     * The mirror of file_guidecap(), with the bound inverted: the ask
     * must be BELOW the current effective ceiling, and zero means
     * complete removal. Suggested replacement guides travel in the
     * request text - the coordinator rehomes each team deliberately
     * with the handover and assignment tools, and the grant itself
     * never moves a team by side effect. One live request per guide,
     * shared with the raise type: a guide asking up and down at once
     * is two contradictory instructions in one queue.
     *
     * @param activity $activity the activity
     * @param int $requested the new, lower limit the guide is asking for (0 = relieve me)
     * @param string $request why, and any suggested replacement guides
     * @param int $requestformat text format of the request
     * @param int $userid the guide asking
     * @return stdClass the ticket row
     * @throws \moodle_exception when a gate refuses
     */
    public static function file_guidereduce(
        activity $activity,
        int $requested,
        string $request,
        int $requestformat,
        int $userid
    ): stdClass {
        global $DB;

        require_capability('mod/selfselectadvanced:guide', $activity->context(), $userid);
        if (trim(html_to_text($request)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }
        // Deliverable A, same scope note as file_guidecap() above.
        self::require_may_raise($activity, 'guide', $userid);

        $ceiling = (new api($activity))->gatekeeper()->resolver()->guide_capacity_ceiling($userid);
        if ($requested < 0) {
            throw new workflow_refusal('refusalguidereducenegative', 'mod_selfselectadvanced');
        }
        if ($requested >= $ceiling->value) {
            throw new workflow_refusal('refusalguidereducenotless', 'mod_selfselectadvanced', '', $ceiling->value);
        }

        // Serialised on the guide, and the duplicate guard spans BOTH
        // capacity types: an open raise and an open reduction would be
        // two contradictory instructions in one queue.
        $events = new eventqueue();
        $lock = locks::acquire('guidecap:' . $userid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $live = $DB->get_record_select(
                'selfselectadvanced_ticket',
                "activityid = :activityid AND type IN (:cap, :reduce) AND requestedby = :userid"
                    . " AND status IN (:open, :claimed, :needsinfo)",
                [
                    'activityid' => $activity->id(),
                    'cap' => self::TYPE_GUIDECAP,
                    'reduce' => self::TYPE_GUIDEREDUCE,
                    'userid' => $userid,
                    'open' => self::STATUS_OPEN,
                    'claimed' => self::STATUS_CLAIMED,
                    'needsinfo' => self::STATUS_NEEDSINFO,
                ]
            );
            if ($live) {
                throw new workflow_refusal('refusalticketduplicate', 'mod_selfselectadvanced', '', (int) $live->id);
            }

            $now = time();
            $ticket = (object) [
                'activityid' => $activity->id(),
                'pluginuid' => '',
                'groupid' => null,
                'type' => self::TYPE_GUIDEREDUCE,
                'status' => self::STATUS_OPEN,
                'requestedby' => $userid,
                'request' => $request,
                'requestformat' => $requestformat,
                'requested' => $requested,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $ticket->id = $DB->insert_record('selfselectadvanced_ticket', $ticket);
            // 1.20.56 deliverable A: minted inside this lock (see file()'s
            // own comment on the same two-step pattern).
            $ticket->pluginuid = self::build_pluginuid($activity, (int) $ticket->id);
            $DB->set_field('selfselectadvanced_ticket', 'pluginuid', $ticket->pluginuid, ['id' => $ticket->id]);

            $ticketlogid = self::log($ticket->id, $userid, self::ACTION_FILED, null, FORMAT_PLAIN);

            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => self::TYPE_GUIDEREDUCE,
                    'pluginuid' => '',
                    'action' => self::ACTION_FILED,
                    'groupid' => 0,
                    'ticketlogid' => $ticketlogid,
                    'disclaimerack' => 0,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        self::notify_workers($activity, $ticket, null);

        return $ticket;
    }

    /**
     * A guide's own LIVE capacity request right now, across BOTH
     * capacity types (audit A6, 2026-08-20): open, claimed OR needsinfo
     * (LIVENESS, decision 2) - the identical trio file_guidecap()'s and
     * file_guidereduce()'s own duplicate guard already treat as one live
     * slot.
     *
     * Extracted from guidequeue.php's own inline copy of this predicate,
     * the same "testable without executing the page script PHPUnit
     * cannot run end-to-end" reasoning may_view_thread() above is built
     * on: that inline copy had drifted (needsinfo omitted), so the page
     * offered ask-more/ask-less forms the service could only refuse, and
     * hid the one banner that would have told the guide their earlier
     * request was in fact waiting on THEM.
     *
     * @param activity $activity the activity
     * @param int $userid the guide
     * @return stdClass|null the live ticket (newest first, matching the
     *         page's own tie-break), or null when there is none
     */
    public static function guide_live_capacity_request(activity $activity, int $userid): ?stdClass {
        global $DB;

        $rows = $DB->get_records_select(
            'selfselectadvanced_ticket',
            "activityid = :activityid AND requestedby = :userid AND type IN (:cap, :reduce)"
                . " AND status IN (:open, :claimed, :needsinfo)",
            [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'cap' => self::TYPE_GUIDECAP,
                'reduce' => self::TYPE_GUIDEREDUCE,
                'open' => self::STATUS_OPEN,
                'claimed' => self::STATUS_CLAIMED,
                'needsinfo' => self::STATUS_NEEDSINFO,
            ],
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );

        return $rows ? reset($rows) : null;
    }

    /**
     * File a guide-succession ticket for a firm or frozen team whose
     * guide was deleted or fully unenrolled (OBS-001).
     *
     * Filed by the observers, not by a person: the team keeps its
     * state and its guideid - a frozen roster is never mutated behind
     * the coordinators' backs - and this ticket is what makes the
     * succession a deliberate act instead of a stale row nobody owns.
     * The claimant resolves it with the existing assign-guide tool and
     * closes it with a note, like every other ticket.
     *
     * Idempotent where file() refuses: both observers can fire for one
     * removal (core unenrols before it deletes), so a live guidegone
     * ticket for the team is returned as-is rather than thrown at the
     * observer as a duplicate. And it declines to file at all when the
     * team re-read under the lock no longer names the gone guide, or
     * has left the firm/frozen states - the succession already
     * happened, and a ticket would be work already done.
     *
     * @param activity $activity the activity
     * @param int $groupid the firm or frozen team
     * @param int $goneguideid the guide who no longer exists here
     * @param string $reason 'deleted' or 'unenrolled'
     * @param int $actorid whose act removed the guide; recorded as the
     *        requester, which every notification path expects to be a
     *        real user
     * @return stdClass|null the open (or already live) ticket, or null
     *         when the fresh row no longer warrants one
     * @throws \coding_exception on an unknown reason
     */
    public static function file_guidegone(
        activity $activity,
        int $groupid,
        int $goneguideid,
        string $reason,
        int $actorid
    ): ?stdClass {
        global $DB;

        if (!in_array($reason, ['deleted', 'unenrolled'], true)) {
            throw new \coding_exception('Unknown guide-gone reason ' . $reason);
        }

        $ticket = null;
        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Judged on the row read INSIDE the lock (house rule A7):
            // a coordinator's reassignment or an unfreeze landing
            // before this lock was granted decides the question.
            // IGNORE_MISSING rather than groups::get(), because the
            // caller is a system observer and a team dissolved while
            // we waited is a reason to file nothing, not to abort the
            // user deletion that brought us here.
            $group = $DB->get_record('selfselectadvanced_group', [
                'id' => $groupid,
                'activityid' => $activity->id(),
            ]);
            if (
                !$group
                || (int) $group->guideid !== $goneguideid
                || !in_array($group->state, [state::FIRM, state::FROZEN], true)
            ) {
                $transaction->allow_commit();
                return null;
            }

            // Includes needsinfo alongside open and claimed (LIVENESS,
            // decision 2): without it, a coordinator's question left
            // outstanding on the first observer's ticket would not read
            // as live to the SECOND observer racing the same removal
            // (core unenrols before it deletes), and this idempotent
            // path would file a genuine duplicate rather than returning
            // the one already being triaged.
            $live = $DB->get_record_select(
                'selfselectadvanced_ticket',
                "groupid = :groupid AND type = :type AND status IN (:open, :claimed, :needsinfo)",
                [
                    'groupid' => (int) $group->id,
                    'type' => self::TYPE_GUIDEGONE,
                    'open' => self::STATUS_OPEN,
                    'claimed' => self::STATUS_CLAIMED,
                    'needsinfo' => self::STATUS_NEEDSINFO,
                ]
            );
            if ($live) {
                $transaction->allow_commit();
                return $live;
            }

            $gone = \core_user::get_user($goneguideid);
            $now = time();
            $ticket = (object) [
                'activityid' => $activity->id(),
                'pluginuid' => '',
                'groupid' => (int) $group->id,
                'type' => self::TYPE_GUIDEGONE,
                'status' => self::STATUS_OPEN,
                'requestedby' => $actorid,
                'request' => get_string(
                    'ticketguidegone' . $reason,
                    'mod_selfselectadvanced',
                    $gone ? fullname($gone) : $goneguideid
                ),
                'requestformat' => FORMAT_PLAIN,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $ticket->id = $DB->insert_record('selfselectadvanced_ticket', $ticket);
            // 1.20.56 deliverable A: minted inside this lock (see file()'s
            // own comment on the same two-step pattern).
            $ticket->pluginuid = self::build_pluginuid($activity, (int) $ticket->id);
            $DB->set_field('selfselectadvanced_ticket', 'pluginuid', $ticket->pluginuid, ['id' => $ticket->id]);

            // The trail row is a DATABASE WRITE - it belongs inside
            // this transaction and before the commit, exactly where
            // every other transition logs its own row, not deferred
            // alongside the event dispatch. Written before the event
            // below is built (B2, addendum item 1) so ticketlogid names
            // a row that actually exists.
            $ticketlogid = self::log($ticket->id, $actorid, self::ACTION_FILED, null, FORMAT_PLAIN);

            // Payload built INSIDE the critical section, dispatched
            // after the commit AND the release below - the binding
            // rule for new code (docs/architecture.md, "Events under a
            // lock"; store::save() is the worked example).
            $event = \mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => self::TYPE_GUIDEGONE,
                    'pluginuid' => $group->pluginuid,
                    'action' => self::ACTION_FILED,
                    'groupid' => (int) $group->id,
                    'ticketlogid' => $ticketlogid,
                    'disclaimerack' => 0,
                ],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $event->trigger();

        self::notify_workers($activity, $ticket, $group);

        return $ticket;
    }

    /**
     * File the general `help` type (1.20.43, maintainer decision): a
     * free-form request from any eligible raiser, about their own group
     * or about nothing in particular.
     *
     * Unlike file(), $group is OPTIONAL: a raiser with no group at all
     * may still ask, and the ticket's groupid is stored as 0 rather than
     * the group's own id - the same "not about a team" idiom guidecap
     * already uses, but as a literal 0 here (spec: "schema already
     * tolerates 0") rather than guidecap's null, because unlike a
     * guidecap request this ticket DOES have a specific requester's
     * group when they have one, and 0 is what marks the case where they
     * do not.
     *
     * The duplicate guard is its OWN, by requester rather than by
     * (group, type): the group-and-type guard file() shares across its
     * five types does not fit a ticket that can carry no group, and
     * this must not disturb that guard for the other types.
     *
     * @param activity $activity the activity
     * @param stdClass|null $group the raiser's own group, or null when
     *        they have none
     * @param string $request the request, in the raiser's own words
     * @param int $requestformat text format of the request
     * @param int $userid the raiser
     * @param bool $disclaimerack whether the raiser passed the
     *        activity's disclaimer acknowledgement; ignored when the
     *        activity has no disclaimer set
     * @return stdClass the ticket row
     * @throws \moodle_exception when a gate refuses
     */
    public static function file_help(
        activity $activity,
        ?stdClass $group,
        string $request,
        int $requestformat,
        int $userid,
        bool $disclaimerack = false
    ): stdClass {
        global $DB;

        if (trim(html_to_text($request)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }
        self::require_disclaimer_ack($activity, $disclaimerack);
        self::require_may_raise($activity, self::raiser_role($group, $userid), $userid);

        // Locked on the group when there is one (the same lock every
        // other filing arm takes, so a concurrent leadership or guide
        // change cannot land between the responsible-mode read and the
        // insert below); on the REQUESTER when there is not, because a
        // groupless raiser has no group row to serialise on and the
        // duplicate guard below still needs to be race-safe against a
        // second submission from the same person.
        $lockkey = $group !== null ? ('group:' . $group->id) : ('helpticketraiser:' . $userid);
        $events = new eventqueue();
        $lock = locks::acquire($lockkey);
        try {
            $transaction = $DB->start_delegated_transaction();

            if ($group !== null) {
                // Re-read inside the lock (house rule A7): a leadership
                // or guide change landing between page render and this
                // call decides the responsible-mode question, not the
                // caller's possibly-stale copy.
                $group = groups::get($activity, (int) $group->id);
                self::require_responsible($activity, $group, $userid);
            }

            $live = $DB->get_record_select(
                'selfselectadvanced_ticket',
                "activityid = :activityid AND type = :type AND requestedby = :userid"
                    . " AND status IN (:open, :claimed, :needsinfo)",
                [
                    'activityid' => $activity->id(),
                    'type' => self::TYPE_HELP,
                    'userid' => $userid,
                    'open' => self::STATUS_OPEN,
                    'claimed' => self::STATUS_CLAIMED,
                    'needsinfo' => self::STATUS_NEEDSINFO,
                ]
            );
            if ($live) {
                throw new workflow_refusal('refusalticketduplicatehelp', 'mod_selfselectadvanced', '', (int) $live->id);
            }

            $now = time();
            $ticket = (object) [
                'activityid' => $activity->id(),
                'pluginuid' => '',
                'groupid' => $group !== null ? (int) $group->id : 0,
                'type' => self::TYPE_HELP,
                'status' => self::STATUS_OPEN,
                'requestedby' => $userid,
                'request' => $request,
                'requestformat' => $requestformat,
                'disclaimerack' => $disclaimerack ? 1 : 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $ticket->id = $DB->insert_record('selfselectadvanced_ticket', $ticket);
            // 1.20.56 deliverable A: minted inside this lock (see file()'s
            // own comment on the same two-step pattern).
            $ticket->pluginuid = self::build_pluginuid($activity, (int) $ticket->id);
            $DB->set_field('selfselectadvanced_ticket', 'pluginuid', $ticket->pluginuid, ['id' => $ticket->id]);

            $ticketlogid = self::log($ticket->id, $userid, self::ACTION_FILED, null, FORMAT_PLAIN);

            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => self::TYPE_HELP,
                    'pluginuid' => $group->pluginuid ?? '',
                    'action' => self::ACTION_FILED,
                    'groupid' => $group !== null ? (int) $group->id : 0,
                    'ticketlogid' => $ticketlogid,
                    'disclaimerack' => $disclaimerack ? 1 : 0,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        self::notify_workers($activity, $ticket, $group);

        return $ticket;
    }

    /**
     * A raiser's group for a groupless-context filing surface (the
     * landing page, B2's "entry point that does not require a group
     * page"): the group they LEAD, if any, else the first group they are
     * a CONFIRMED member of, else null.
     *
     * DISCRETIONARY CALL (flagged for the orchestrator): the spec does
     * not say which group to prefer when a raiser holds more than one
     * (maxmembership > 1). Leadership is preferred here because the
     * maintainer's stated gap is specifically a LEADER'S missing filing
     * route ("today a leader's only ticket is unfreeze-on-frozen") - a
     * leader filing help from the landing page should get the group they
     * lead, not an arbitrary other one. A group in view already (the
     * group page's own filing form) never calls this; it always knows
     * its own group.
     *
     * @param activity $activity the activity
     * @param int $userid the raiser
     * @return stdClass|null the group row, or null when they lead and
     *         belong to none
     */
    public static function my_group_for_help(activity $activity, int $userid): ?stdClass {
        global $DB;

        $led = $DB->get_record_sql(
            "SELECT * FROM {selfselectadvanced_group} WHERE activityid = :activityid AND leaderid = :userid ORDER BY id",
            ['activityid' => $activity->id(), 'userid' => $userid],
            IGNORE_MULTIPLE
        );
        if ($led) {
            return $led;
        }

        $memberof = $DB->get_record_sql(
            "SELECT g.*
               FROM {selfselectadvanced_group} g
               JOIN {selfselectadvanced_member} m ON m.groupid = g.id
              WHERE g.activityid = :activityid AND m.userid = :userid AND m.status = :confirmed
           ORDER BY g.id",
            [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'confirmed' => groups::STATUS_CONFIRMED,
            ],
            IGNORE_MULTIPLE
        );

        return $memberof ?: null;
    }

    /**
     * The raiser's role for the who-may-raise checkboxes and the
     * responsible-person mode (1.20.43 deliverable A): the activity's
     * guide relation, or a group's leader, or the catch-all "member" -
     * "any enrolled student participant in the activity (including one
     * not yet in a group)" per the spec.
     *
     * CALLED, not transcribed, from both the UI (group.php, landing.php,
     * filehelp.php decide what to draw) and the service gate below, so
     * the two cannot drift into disagreeing about who somebody is.
     *
     * @param stdClass|null $group the group the ticket is about, or null
     *        for a groupless raiser
     * @param int $userid the raiser
     * @return string 'guide', 'leader' or 'member'
     */
    public static function raiser_role(?stdClass $group, int $userid): string {
        if ($group !== null && $userid > 0) {
            if ((int) ($group->guideid ?? 0) === $userid) {
                return 'guide';
            }
            if ((int) ($group->leaderid ?? 0) === $userid) {
                return 'leader';
            }
        }

        return 'member';
    }

    /**
     * Whether the activity's who-may-raise checkbox for this role is on.
     *
     * @param activity $activity the activity
     * @param string $role 'guide', 'leader' or 'member'
     * @param int $userid the raiser, 0 when the caller has none to give
     *        (a page deciding only whether to SHOW a control - filing
     *        itself always passes a real one, see may_raise_refusalkey())
     * @return bool
     */
    public static function may_raise(activity $activity, string $role, int $userid = 0): bool {
        return self::may_raise_refusalkey($activity, $role, $userid) === null;
    }

    /**
     * Refuse unless the activity's who-may-raise checkbox for this role
     * is on (1.20.43 deliverable A), and (for 'member') unless the
     * raiser is actually enrolled here (audit A3, 2026-08-20).
     *
     * This is ELIGIBILITY on top of the existing per-type relational
     * gates each file_* entry point already applies - it never widens
     * them, only narrows: a member with the member box unticked cannot
     * file anything, and a member with it ticked still cannot file a
     * guide-only type, because the relational check that established
     * "this actor may file THIS type" already ran before this one does.
     *
     * @param activity $activity the activity
     * @param string $role 'guide', 'leader' or 'member'
     * @param int $userid the raiser, 0 when the caller has none to give
     * @throws \moodle_exception refusalticketraise{role} when the
     *         checkbox is off, or refusalticketnotenrolled for an
     *         unenrolled 'member' raiser
     */
    public static function require_may_raise(activity $activity, string $role, int $userid = 0): void {
        if ($key = self::may_raise_refusalkey($activity, $role, $userid)) {
            throw new workflow_refusal($key, 'mod_selfselectadvanced');
        }
    }

    /**
     * The refusal key for require_may_raise(), or null when the raiser
     * may proceed. Extracted so may_raise() and require_may_raise() ask
     * exactly one question between them.
     *
     * $userid defaults to 0 so the existing UI-hint callers (group.php,
     * filehelp.php, landing.php - none of them owned by this fix, and
     * none of them the actual filing door) keep compiling and keep
     * their exact old behaviour unchanged; every genuine filing arm in
     * THIS class (file(), file_guidecap(), file_guidereduce(),
     * file_help()) passes its real actor.
     *
     * The enrolment test applies to 'member' only (audit A3,
     * 2026-08-20): file_help() had NO participant gate at all, so a
     * guest auto-logged in by require_login() could file straight into
     * the staff queue merely by holding a session - may_raise() being
     * true by default is an activity-level ON/OFF SWITCH, never a test
     * of the person. 'guide' and 'leader' need no equivalent check here
     * - each is already proven by a group relation (guideid/leaderid) a
     * caller established before ever reaching this gate. The idiom is
     * the one lib.php's candidate_name() and staffmessage::may_message_
     * map() already use for "may take part in this activity".
     *
     * @param activity $activity the activity
     * @param string $role 'guide', 'leader' or 'member'
     * @param int $userid the raiser, 0 to skip the enrolment test
     * @return string|null
     */
    private static function may_raise_refusalkey(activity $activity, string $role, int $userid = 0): ?string {
        $settings = $activity->settings();
        $flag = match ($role) {
            'guide' => (int) ($settings->ticketraiseguide ?? 1),
            'leader' => (int) ($settings->ticketraiseleader ?? 1),
            'member' => (int) ($settings->ticketraisemember ?? 1),
            default => throw new \coding_exception('Unknown raiser role ' . $role),
        };
        if (!$flag) {
            return 'refusalticketraise' . $role;
        }
        if (
            $role === 'member'
            && $userid > 0
            && !is_enrolled($activity->context(), $userid, 'mod/selfselectadvanced:respond', true)
        ) {
            return 'refusalticketnotenrolled';
        }

        return null;
    }

    /**
     * The role responsible-person mode restricts raising to for this
     * group at its current stage (1.20.43 deliverable C), or null when
     * nobody is specially restricted (stage 1: no group at all, or a
     * group with neither a leader nor a guide - a leadership vacancy
     * sits here too, deliberately: nobody but staff can fix a vacancy,
     * so the mode must not also lock every member out of asking for
     * help while one stands).
     *
     * Design reading, exactly as specified: "firmed under a guide" means
     * the group HAS AN ASSIGNED GUIDE - the guide relation, not the
     * frozen flag - so a frozen team with no guide stays at stage 2, not
     * stage 3.
     *
     * @param stdClass|null $group the group, or null for a groupless raiser
     * @return string|null 'guide', 'leader' or null
     */
    public static function responsible_role(?stdClass $group): ?string {
        if ($group === null) {
            return null;
        }
        if (!empty($group->guideid)) {
            return 'guide';
        }
        if ($group->leaderid !== null) {
            return 'leader';
        }

        return null;
    }

    /**
     * Refuse unless this actor is the person responsible, when
     * responsible-person mode is on (1.20.43 deliverable C). ON TOP of
     * require_may_raise() above - the mode gates RAISING only, never a
     * requester's own withdraw or provide-info on a ticket they already
     * hold, and never staff handling.
     *
     * RECORDED CONSEQUENCE (maintainer's stated intent, not softened):
     * with the mode on, a confirmed member can never file leaderchange
     * about their own leader while that leader stands unassigned-to-a-
     * guide - file()'s own relational check already refuses the LEADER
     * for that type (succession is theirs to drive instead), and this
     * refuses everyone else who is not the responsible person, which at
     * that stage is the leader. The two refusals together close the
     * behind-the-back channel by design: the member still sees a
     * specific refusal string, never a silent absence.
     *
     * @param activity $activity the activity
     * @param stdClass|null $group the group the ticket is about, or null
     * @param int $userid the raiser
     * @throws \moodle_exception refusalticketresponsibleguide or
     *         refusalticketresponsibleleader
     */
    public static function require_responsible(activity $activity, ?stdClass $group, int $userid): void {
        if (empty($activity->settings()->ticketresponsiblemode)) {
            return;
        }
        $required = self::responsible_role($group);
        if ($required === 'guide' && (int) $group->guideid !== $userid) {
            throw new workflow_refusal('refusalticketresponsibleguide', 'mod_selfselectadvanced');
        }
        if ($required === 'leader' && (int) $group->leaderid !== $userid) {
            throw new workflow_refusal('refusalticketresponsibleleader', 'mod_selfselectadvanced');
        }
    }

    /**
     * The boolean twin of require_responsible(), for a page deciding
     * whether to OFFER a control rather than refusing a submission - the
     * same UX-001 reasoning tickets::involvement() is built on.
     *
     * @param activity $activity the activity
     * @param stdClass|null $group the group the ticket is about, or null
     * @param int $userid the raiser
     * @return bool
     */
    public static function may_be_responsible(activity $activity, ?stdClass $group, int $userid): bool {
        if (empty($activity->settings()->ticketresponsiblemode)) {
            return true;
        }

        return match (self::responsible_role($group)) {
            'guide' => (int) $group->guideid === $userid,
            'leader' => (int) $group->leaderid === $userid,
            default => true,
        };
    }

    /**
     * Refuse unless the raiser acknowledged the activity's disclaimer,
     * when one is set (1.20.43 deliverable D). Empty disclaimer means
     * nothing to acknowledge - the gate never fires, and disclaimerack
     * stays 0 on the ticket row.
     *
     * The emptiness test mirrors the one file()/file_help() already
     * apply to the request itself: a disclaimer that renders to nothing
     * (an editor left blank, which stores markup like "<p><br></p>"
     * rather than an empty string) is not a disclaimer to gate on.
     *
     * @param activity $activity the activity
     * @param bool $disclaimerack whether the caller passed an ack
     * @throws \moodle_exception refusalticketdisclaimerack
     */
    private static function require_disclaimer_ack(activity $activity, bool $disclaimerack): void {
        $disclaimer = (string) ($activity->settings()->ticketdisclaimer ?? '');
        if (trim(html_to_text($disclaimer)) === '') {
            return;
        }
        if (!$disclaimerack) {
            throw new workflow_refusal('refusalticketdisclaimerack', 'mod_selfselectadvanced');
        }
    }

    /**
     * Grant a claimed team-limit request: write the override and close
     * the ticket together (strategy 1.18 C).
     *
     * The two halves belong to one another. A coordinator who raised
     * the limit but left the ticket open would have the guide asking
     * again; one who closed it without writing the override would have
     * told the guide yes and changed nothing. Doing both here, in the
     * claimant's single action, removes the chance of either.
     *
     * @param activity $activity the activity
     * @param int $ticketid the claimed guidecap ticket
     * @param string $resolution the note to the guide
     * @param int $resolutionformat text format
     * @param int $userid the claimant
     * @return stdClass the closed ticket
     * @throws \moodle_exception when refused
     */
    public static function grant_guidecap(
        activity $activity,
        int $ticketid,
        string $resolution,
        int $resolutionformat,
        int $userid
    ): stdClass {
        $ticket = self::get($activity, $ticketid);
        if ($ticket->type !== self::TYPE_GUIDECAP) {
            throw new \coding_exception('grant_guidecap called on a ' . $ticket->type . ' ticket');
        }
        // NEEDSINFO ALLOWED HERE TOO (audit A4, 2026-08-20 - the same
        // widening close() already carries, decision 2 LIVENESS): the
        // thread offers the Grant button in both CLAIMED and NEEDSINFO,
        // and this delegates to close() below, which already accepts
        // both - so the offered control and the service must agree, or
        // a coordinator who has enough to say yes without the answer
        // they asked for has no way to grant at all until the guide
        // happens to reply.
        if (!in_array($ticket->status, [self::STATUS_CLAIMED, self::STATUS_NEEDSINFO], true)) {
            throw new workflow_refusal('refusalticketnotclaimed', 'mod_selfselectadvanced');
        }

        // Granting IS setting an exception, so it is gated on the
        // capability for setting one - checked at the seam rather than
        // on the page, because working the queue and granting an
        // exception are two different authorities and a site is free to
        // separate them.
        require_capability('mod/selfselectadvanced:override', $activity->context(), $userid);

        // Everything close() would refuse for is checked HERE, before
        // the override is written.
        //
        // store::save() commits its own outermost transaction, so until
        // 1.19.1 a coordinator who pressed Grant with an empty note
        // raised the guide's cap permanently and only then hit close()'s
        // refusal: the override was durable, the ticket stayed CLAIMED,
        // and the guide was never told. The docblock above promises
        // these two halves belong to one another; this is what makes
        // that true for the case that actually happened.
        //
        // They are not wrapped in one transaction, deliberately:
        // close() sends its notification after committing and outside
        // its lock, and an outer transaction here would drag that
        // notification back inside one - the mistake of 1.15.0. What
        // remains is a genuine concurrency window: if another manager
        // releases the claim between this check and close(), the
        // override stands while the ticket reopens. That is a
        // reversible over-grant rather than the silent, deterministic
        // one this replaces, and it is stated here rather than papered
        // over.
        if (trim(html_to_text($resolution)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }
        if ((int) $ticket->claimedby !== $userid) {
            throw new workflow_refusal(
                'refusalticketnotclaimant',
                'mod_selfselectadvanced',
                '',
                fullname(\core_user::get_user((int) $ticket->claimedby))
            );
        }

        // The override first: if writing it is refused - the actor may
        // not set overrides, or may not set this one - the ticket stays
        // claimed and open to be declined or released instead of
        // reading as granted with nothing behind it.
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'guide',
            (int) $ticket->requestedby,
            ['maxguided' => (int) $ticket->requested],
            $userid
        );

        return self::close($activity, $ticketid, self::STATUS_RESOLVED, $resolution, $resolutionformat, $userid);
    }

    /**
     * Take back one's own request while it is still open.
     *
     * The withdrawn status has existed since the queue was built but
     * nothing could reach it, so a request filed by mistake sat in the
     * queue until somebody else declined it. Only the requester may,
     * and only before a worker has claimed it - once claimed it is
     * someone's work in progress, and theirs to close.
     *
     * @param activity $activity the activity
     * @param int $ticketid the ticket
     * @param int $userid the requester
     * @return stdClass the withdrawn ticket
     * @throws \moodle_exception when refused
     */
    public static function withdraw(activity $activity, int $ticketid, int $userid): stdClass {
        global $DB;

        $events = new eventqueue();
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->requestedby !== $userid) {
                throw new workflow_refusal('refusalticketnotyours', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== self::STATUS_OPEN) {
                throw new workflow_refusal('refusalticketclaimed', 'mod_selfselectadvanced', '', $fresh->status);
            }

            $fresh->status = self::STATUS_WITHDRAWN;
            $fresh->timemodified = time();
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            $ticketlogid = self::log($ticketid, $userid, self::ACTION_WITHDRAWN, null, FORMAT_PLAIN);

            // No relateduserid: withdraw() only ever runs from an OPEN
            // ticket (never claimed - see the status guard above), so
            // there is no claimant yet to name as the other party, and
            // the actor IS the requester already named by userid.
            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_closed::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'other' => [
                    'type' => $fresh->type,
                    'outcome' => self::STATUS_WITHDRAWN,
                    'action' => self::ACTION_WITHDRAWN,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        return $fresh;
    }

    /**
     * "Did this help?" - the requester's own answer to a resolved
     * ticket (1.20.59 deliverable A), offered ONCE.
     *
     * D-108 is decided as RECORD, NEVER REOPEN (see the ticket spec's
     * own section): the escalation ladder goes up only (decision 115)
     * and the machine may never close a ticket, so a "no" here is
     * recorded and surfaced, never acted on. THE HARDEST CONSTRAINT this
     * method exists to keep: status, claimedby, timeclaimed, resolvedby
     * and timeresolved are never read for a WRITE decision here and
     * never assigned - the only columns this method ever sets on the
     * ticket row are verdict, verdictnote, timeverdict and timemodified
     * (every other transition in this class also advances timemodified
     * on its own write; see comment()'s identical note).
     *
     * Authority is ownership alone, exactly like withdraw() above: the
     * requester of THIS ticket, re-read inside the lock, and nobody
     * else - no capability check, because the authority to answer is
     * having asked, the same "record ownership" idiom close()'s own
     * docblock states for claimedby. Offered only while the ticket is
     * RESOLVED (not declined, not withdrawn: those never asked "did
     * this help?") and only while verdict is still VERDICT_UNANSWERED -
     * the "offered once, never revised" rule is enforced HERE, at the
     * single door every caller (ticket.php, myrequests.php) goes
     * through, not merely hidden in the UI that offers the control.
     *
     * @param activity $activity the activity
     * @param int $ticketid the resolved ticket
     * @param int $verdict self::VERDICT_HELPED or self::VERDICT_NOTHELPED
     * @param string $note an optional note explaining the verdict; hand-
     *        rolled plain textarea, stored and rendered FORMAT_MOODLE
     *        exactly like declinereason (no separate format column - see
     *        db/install.xml's own comment on verdictnote)
     * @param int $userid the requester
     * @return stdClass the updated ticket
     * @throws \moodle_exception when refused
     */
    public static function give_feedback(
        activity $activity,
        int $ticketid,
        int $verdict,
        string $note,
        int $userid
    ): stdClass {
        global $DB;

        if (!in_array($verdict, [self::VERDICT_HELPED, self::VERDICT_NOTHELPED], true)) {
            // Not a workflow_refusal (contrast the emptiness/duplicate
            // checks elsewhere in this class): no legitimate control
            // this plugin ever renders can produce a verdict outside
            // this pair, exactly the same "caller bug, not something a
            // person typed" reasoning validate_type_filter() states for
            // an unknown queue-filter type.
            throw new \coding_exception('Unknown ticket verdict ' . $verdict);
        }
        $note = trim($note);

        $events = new eventqueue();
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->requestedby !== $userid) {
                throw new workflow_refusal('refusalticketnotyours', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== self::STATUS_RESOLVED) {
                throw new workflow_refusal('refusalticketfeedbacknotresolved', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->verdict !== self::VERDICT_UNANSWERED) {
                throw new workflow_refusal('refusalticketfeedbackalreadygiven', 'mod_selfselectadvanced');
            }

            // NO STATUS TRANSITION. NONE. $fresh->status, ->claimedby,
            // ->timeclaimed, ->resolvedby and ->timeresolved are never
            // assigned below - only the three feedback columns and
            // timemodified, which every other transition in this class
            // advances on its own write too (comment()'s own comment
            // states the same rule for its own no-status-change update).
            $now = time();
            $fresh->verdict = $verdict;
            $fresh->verdictnote = $note !== '' ? $note : null;
            // STORED, not guessed at render (1.20.52's rule). The
            // constant lives here rather than on the page because the
            // control that writes this note is fixed - one hand-rolled
            // textarea, the same choice declinereason made - so there is
            // no caller-supplied format to honour and every page that
            // renders the note reads this column instead of repeating a
            // constant of its own.
            $fresh->verdictnoteformat = FORMAT_MOODLE;
            $fresh->timeverdict = $now;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            $action = $verdict === self::VERDICT_HELPED ? self::ACTION_FEEDBACK_HELPED : self::ACTION_FEEDBACK_NOTHELPED;
            // FORMAT_MOODLE, hardcoded - the same convention
            // declinereason's own trail row uses (ticket.php's decline
            // arm), for the identical reason: a hand-rolled textarea
            // with no editor and no stored format column of its own.
            $ticketlogid = self::log($ticketid, $userid, $action, $note !== '' ? $note : null, FORMAT_MOODLE);

            $event = \mod_selfselectadvanced\event\ticket_feedback_given::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'other' => [
                    'type' => $fresh->type,
                    'action' => $action,
                    'verdict' => $verdict,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $event->trigger();

        return $fresh;
    }

    /**
     * How many RESOLVED tickets in this activity the requester said did
     * NOT help (1.20.59 deliverable B) - the coordinator dashboard's own
     * card. Unfiltered by viewer, like the dashboard's own
     * $awaitingfreeze/$frozen counts beside it: a "did not help" answer
     * is a signal about the QUEUE's own outcomes, not about which
     * tickets this particular viewer may claim, so it carries none of
     * count_open()'s conflict-of-interest narrowing.
     *
     * @param activity $activity the activity
     * @return int
     */
    public static function count_feedback_nothelped(activity $activity): int {
        global $DB;

        return $DB->count_records('selfselectadvanced_ticket', [
            'activityid' => $activity->id(),
            'status' => self::STATUS_RESOLVED,
            'verdict' => self::VERDICT_NOTHELPED,
        ]);
    }

    /**
     * The team a ticket is about, or null when it is not about a team.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @return stdClass|null the group row, or null for a guidecap request
     */
    public static function group_of(activity $activity, stdClass $ticket): ?stdClass {
        if ($ticket->groupid === null || (int) $ticket->groupid <= 0) {
            return null;
        }

        return groups::get($activity, (int) $ticket->groupid);
    }

    /**
     * Tell the people who work the queue that there is new work.
     *
     * Sends happen outside every lock - mail must never hold one
     * (1.15.0 lesson) - and a worker holding both capabilities is
     * told once.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the new ticket
     * @param stdClass|null $group the team it is about, if any
     */
    private static function notify_workers(activity $activity, stdClass $ticket, ?stdClass $group): void {
        $workerids = [];
        foreach (['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:coordinate'] as $capability) {
            foreach (get_users_by_capability($activity->context(), $capability, 'u.id') as $worker) {
                $workerids[(int) $worker->id] = true;
            }
        }
        // Nobody is told about their own request.
        unset($workerids[(int) $ticket->requestedby]);
        foreach (array_keys($workerids) as $workerid) {
            self::notify($activity, $workerid, 'msgticketfiledsubject', 'msgticketfiledbody', $ticket, $group);
        }
    }

    /**
     * Claim a ticket exclusively.
     *
     * Under the per-ticket lock, in a transaction, the row is re-read:
     * only an OPEN ticket can be claimed, and the UPDATE carries
     * WHERE status='open' with its affected-row count checked, so a
     * racing second claimant is refused and told who won.
     *
     * The conflict-of-interest guard runs first: an actor without
     * manage is refused when involved in the group.
     *
     * @param activity $activity the activity
     * @param int $ticketid the ticket
     * @param int $userid the claimant
     * @return stdClass the claimed ticket row
     * @throws \moodle_exception when the claim is refused
     */
    public static function claim(activity $activity, int $ticketid, int $userid): stdClass {
        global $DB;

        // Same authority close() re-asks, asked here first: taking a
        // ticket out of the queue is working the queue. The
        // conflict-of-interest guard below RESTRAINS an actor who has
        // this authority; it never granted it, and on its own it
        // admitted anybody the restraint did not happen to name.
        self::require_queue_authority($activity, $userid);

        $ticket = self::get($activity, $ticketid);
        // A team-limit request is about a guide, not a team, so there is
        // no team to be involved in; the conflict rule that applies to
        // it is simply that nobody works their own request, which
        // queue() and the claim gate below already enforce.
        $group = self::group_of($activity, $ticket);
        if ($group !== null) {
            self::require_uninvolved($activity, $group, $userid);
        } else if ((int) $ticket->requestedby === $userid) {
            throw new workflow_refusal('refusalcoiself', 'mod_selfselectadvanced');
        }

        $events = new eventqueue();
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ($fresh->status !== self::STATUS_OPEN) {
                throw new workflow_refusal(
                    'refusalticketclaimed',
                    'mod_selfselectadvanced',
                    '',
                    $fresh->claimedby ? fullname(\core_user::get_user((int) $fresh->claimedby)) : $fresh->status
                );
            }
            // 1.20.44: while escalated, only a manage-level holder may
            // take the ticket up - a mere :coordinate holder is refused
            // here, in the service, no matter what the UI happens to
            // offer (the queue and the thread both hide the control too,
            // but this is the door that actually matters). Judged on the
            // ROW READ INSIDE THE LOCK, like every other gate in this
            // method: an escalation landing between the pre-lock checks
            // above and this line must decide the question, not a stale
            // copy that read as merely open.
            if (
                (int) $fresh->escalated === 1
                && !has_capability('mod/selfselectadvanced:manage', $activity->context(), $userid)
            ) {
                throw new workflow_refusal('refusalticketescalated', 'mod_selfselectadvanced');
            }

            $updated = $DB->execute(
                "UPDATE {selfselectadvanced_ticket}
                    SET status = :claimed, claimedby = :userid, timeclaimed = :now, timemodified = :now2
                  WHERE id = :id AND status = :open",
                [
                    'claimed' => self::STATUS_CLAIMED,
                    'userid' => $userid,
                    'now' => time(),
                    'now2' => time(),
                    'id' => $ticketid,
                    'open' => self::STATUS_OPEN,
                ]
            );
            unset($updated);

            $claimed = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ($claimed->status !== self::STATUS_CLAIMED || (int) $claimed->claimedby !== $userid) {
                // Belt and braces: someone slipped between read and
                // write despite the lock - refuse rather than share.
                throw new workflow_refusal(
                    'refusalticketclaimed',
                    'mod_selfselectadvanced',
                    '',
                    $claimed->claimedby ? fullname(\core_user::get_user((int) $claimed->claimedby)) : $claimed->status
                );
            }

            $ticketlogid = self::log($ticketid, $userid, self::ACTION_CLAIMED, null, FORMAT_PLAIN);

            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_claimed::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'relateduserid' => (int) $claimed->requestedby,
                'other' => [
                    'type' => $claimed->type,
                    'pluginuid' => $group->pluginuid ?? '',
                    'action' => self::ACTION_CLAIMED,
                    'groupid' => (int) ($claimed->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        self::notify($activity, (int) $claimed->requestedby, 'msgticketclaimedsubject', 'msgticketclaimedbody', $claimed, $group);

        return $claimed;
    }

    /**
     * Refer a ticket to another coordinator (1.20.44, the handling
     * ladder's first rung, maintainer intent: "a group coordinator can
     * request another group coordinator to respond").
     *
     * Authority mirrors request_info() exactly (same spec instruction):
     * this is a question of RECORD OWNERSHIP - the ticket's own current
     * claimant, whoever that is right now. require_queue_authority() is
     * still re-asked first, exactly as close() asks it (audit A1,
     * 2026-08-20): without it, a REQUESTER with no queue authority at
     * all reaches the not-claimant check below and is handed the
     * claimant's fullname by workflow_refusal - the "record ownership"
     * question only makes sense once the actor is proven to be a queue
     * worker at all. The TARGET is a different question, asked fresh on the
     * row read inside the lock: they must hold queue authority (manage
     * or coordinate) and pass the same conflict-of-interest rule a
     * claimant needs (require_uninvolved(), or the groupless self-check
     * claim() uses for a team-limit request). While the ticket is
     * escalated the target must ALSO hold manage-level authority -
     * without this a refer would be a back door around the very door
     * escalate() and claim() both enforce, simply by moving claimedby
     * directly rather than going through claim().
     *
     * Effect: claimedby moves to the target, status is UNCHANGED (D-105:
     * human authority does not narrow just because it changed hands),
     * a ticketlog ACTION_REFERRED row records the note, a ticket_referred
     * event fires (relateduserid = target) and the target alone is
     * notified. The requester's own trail is unaffected -
     * ACTION_REFERRED is one of STAFF_INTERNAL_ACTIONS, so
     * tickets::trail($withactors = false) never returns this row and
     * "Somebody is handling this." stays true for them, exactly as it
     * read before the referral.
     *
     * @param activity $activity the activity
     * @param int $ticketid the claimed or needs-info ticket
     * @param int $targetid the coordinator being referred to
     * @param string $note why, for the target
     * @param int $noteformat text format of the note
     * @param int $actorid the current claimant
     * @return stdClass the updated ticket
     * @throws \moodle_exception when refused
     */
    public static function refer(
        activity $activity,
        int $ticketid,
        int $targetid,
        string $note,
        int $noteformat,
        int $actorid
    ): stdClass {
        global $DB;

        // The queue-worker authority, asked FIRST (audit A1, 2026-08-20),
        // before the note is even validated - see this method's own
        // docblock above.
        self::require_queue_authority($activity, $actorid);

        if (trim(html_to_text($note)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }

        $context = $activity->context();
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if (!in_array($fresh->status, [self::STATUS_CLAIMED, self::STATUS_NEEDSINFO], true)) {
                throw new workflow_refusal('refusalticketnotclaimed', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->claimedby !== $actorid) {
                throw new workflow_refusal(
                    'refusalticketnotclaimant',
                    'mod_selfselectadvanced',
                    '',
                    fullname(\core_user::get_user((int) $fresh->claimedby))
                );
            }
            if ($targetid === $actorid) {
                throw new workflow_refusal('refusalticketrefertargetself', 'mod_selfselectadvanced');
            }

            // The target's authority, re-asked fresh on THIS row: a
            // second capability check, a second involvement check - a
            // stale render must never be trusted for who is about to
            // become the claimant. A plain has_capability() check rather
            // than require_queue_authority($activity, $targetid): that
            // helper throws core's required_capability_exception worded
            // around the CURRENT user ("you need the capability..."),
            // which would misname the problem here - it is the TARGET,
            // not the actor submitting this form, whose authority is in
            // question.
            if (
                !has_capability('mod/selfselectadvanced:manage', $context, $targetid)
                && !has_capability('mod/selfselectadvanced:coordinate', $context, $targetid)
            ) {
                throw new workflow_refusal('refusalticketrefertargetauthority', 'mod_selfselectadvanced');
            }
            if (
                (int) $fresh->escalated === 1
                && !has_capability('mod/selfselectadvanced:manage', $context, $targetid)
            ) {
                throw new workflow_refusal('refusalticketescalated', 'mod_selfselectadvanced');
            }
            $group = self::group_of($activity, $fresh);
            if ($group !== null) {
                self::require_uninvolved($activity, $group, $targetid);
            } else if ((int) $fresh->requestedby === $targetid) {
                throw new workflow_refusal('refusalcoiself', 'mod_selfselectadvanced');
            }

            $fresh->claimedby = $targetid;
            $fresh->timeclaimed = time();
            $fresh->timemodified = time();
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            $ticketlogid = self::log($ticketid, $actorid, self::ACTION_REFERRED, $note, $noteformat);

            // Payload built INSIDE the critical section, dispatched
            // after the commit AND the release below (docs/architecture.md,
            // "Events under a lock" - binding for new code; store::save()
            // is the worked example).
            $event = \mod_selfselectadvanced\event\ticket_referred::create([
                'objectid' => $ticketid,
                'context' => $context,
                'relateduserid' => $targetid,
                'other' => [
                    'type' => $fresh->type,
                    'action' => self::ACTION_REFERRED,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $event->trigger();

        $groupname = self::subject_name($activity, $fresh);
        notifier::send(
            $activity,
            'tickets',
            $targetid,
            'msgticketreferredsubject',
            'msgticketreferredbody',
            (object) [
                'group' => $groupname,
                'type' => get_string('tickettype' . $fresh->type, 'mod_selfselectadvanced'),
                // 1.20.56 deliverable B: staff-to-staff, like the
                // escalated message above - the referral note may say
                // anything the referring actor wrote.
                'pluginuid' => (string) ($fresh->pluginuid ?? ''),
                'note' => trim(html_to_text($note)),
            ],
            new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticketid]),
            $groupname
        );

        return $fresh;
    }

    /**
     * The bounded, server-built list of coordinators a ticket may be
     * referred to (spec: "do NOT introduce an autocomplete" - the
     * transport-contract debt T-18 left behind is not to be copied).
     * Every id this returns is exactly what refer() itself would accept
     * as $targetid for THIS ticket and THIS actor right now - built from
     * the identical predicates (require_queue_authority's pair, the
     * escalated-ticket manage-only narrowing, require_uninvolved()/the
     * groupless self-check) so the offered control and the service can
     * never disagree. A stale render (someone joins the team, or loses
     * the capability, between page load and submit) is still caught by
     * refer()'s own re-check inside its lock - this is the UI's list,
     * not a second source of truth.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @param int $actorid the claimant who would be referring it (excluded)
     * @return array<int, string> userid => full name, sorted by name
     */
    public static function eligible_referral_targets(activity $activity, stdClass $ticket, int $actorid): array {
        $context = $activity->context();
        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', true)->selects;

        $candidates = [];
        foreach (['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:coordinate'] as $capability) {
            foreach (get_users_by_capability($context, $capability, 'u.id' . $namefields) as $user) {
                $candidates[(int) $user->id] = $user;
            }
        }
        unset($candidates[$actorid]);

        $escalated = (int) ($ticket->escalated ?? 0) === 1;
        $group = self::group_of($activity, $ticket);

        $eligible = [];
        foreach ($candidates as $id => $user) {
            if ($escalated && !has_capability('mod/selfselectadvanced:manage', $context, $id)) {
                continue;
            }
            if ($group !== null) {
                if (self::involvement($activity, $group, $id) !== null) {
                    continue;
                }
            } else if ((int) $ticket->requestedby === $id) {
                continue;
            }
            $eligible[$id] = fullname($user);
        }
        asort($eligible);

        return $eligible;
    }

    /**
     * Escalate a ticket to the editing-teacher/manager tier (1.20.44,
     * the handling ladder's second rung, maintainer intent: "raise it to
     * someone above them"). No down-ladder: D-107 (de-escalation) is
     * still open, and this method builds exactly what the spec asks for
     * and nothing past it.
     *
     * Authority: the CLAIMANT, or ANY manage-level holder - even on a
     * ticket nobody has claimed yet ("even when unclaimed"). Mirrors the
     * naming split require_queue_authority() already uses (:coordinate
     * vs :manage) rather than inventing a third name for the same pair.
     * A manage holder's authority here is not confined to unclaimed
     * tickets: they may escalate one already claimed by somebody else
     * too, which is the same "human authority does not narrow" reading
     * D-105 states for close()'s force-release arm.
     *
     * Effect: escalated is set; if the ticket is currently claimed by
     * someone who does NOT hold manage-level authority (a mere
     * coordinator), that claim is RELEASED - status back to open,
     * claimedby/timeclaimed cleared - so someone above can pick it up.
     * A claim already held by a manage-level holder is left exactly as
     * it is: they already qualify to keep handling an escalated ticket,
     * and bouncing their own claim would serve nobody. One ticketlog
     * ACTION_ESCALATED row represents the whole transition, the same way
     * close() logs one row for a combined status-and-claim change. The
     * requester is not notified and their trail is unaffected -
     * ACTION_ESCALATED is staff-internal (STAFF_INTERNAL_ACTIONS) - but
     * their STATUS BADGE still reflects a resulting release, because
     * that read comes from the ticket row itself, not from the trail.
     *
     * @param activity $activity the activity
     * @param int $ticketid the live (open, claimed or needs-info) ticket
     * @param string $note why, for the record
     * @param int $noteformat text format of the note
     * @param int $actorid the claimant, or a manage-level holder
     * @return stdClass the updated ticket
     * @throws \moodle_exception when refused
     */
    public static function escalate(
        activity $activity,
        int $ticketid,
        string $note,
        int $noteformat,
        int $actorid
    ): stdClass {
        global $DB;

        if (trim(html_to_text($note)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }

        $context = $activity->context();
        $released = false;
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if (!in_array($fresh->status, [self::STATUS_OPEN, self::STATUS_CLAIMED, self::STATUS_NEEDSINFO], true)) {
                throw new workflow_refusal('refusalticketclosed', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->escalated === 1) {
                throw new workflow_refusal('refusalticketalreadyescalated', 'mod_selfselectadvanced');
            }
            $ismanager = has_capability('mod/selfselectadvanced:manage', $context, $actorid);
            if ((int) $fresh->claimedby !== $actorid && !$ismanager) {
                throw new workflow_refusal('refusalticketescalateauthority', 'mod_selfselectadvanced');
            }

            $now = time();
            $fresh->escalated = 1;
            $fresh->timemodified = $now;
            if ($fresh->claimedby && !has_capability('mod/selfselectadvanced:manage', $context, (int) $fresh->claimedby)) {
                $fresh->status = self::STATUS_OPEN;
                $fresh->claimedby = null;
                $fresh->timeclaimed = null;
                $released = true;
            }
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            $ticketlogid = self::log($ticketid, $actorid, self::ACTION_ESCALATED, $note, $noteformat);

            $event = \mod_selfselectadvanced\event\ticket_escalated::create([
                'objectid' => $ticketid,
                'context' => $context,
                'relateduserid' => (int) $fresh->requestedby,
                'other' => [
                    'type' => $fresh->type,
                    'action' => self::ACTION_ESCALATED,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                    'released' => $released ? 1 : 0,
                ],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $event->trigger();

        // Requester unaffected (spec, verbatim): no notification to
        // them here, matching their trail staying silent on this action
        // too. Only the manage-level tier is fanned out to - the
        // per-filing idiom notify_workers() uses, restricted to :manage,
        // and the escalating actor (who may themselves hold :manage) is
        // never told about their own action.
        self::notify_manage_holders($activity, $fresh, $note, $actorid);

        return $fresh;
    }

    /**
     * Tell the manage-level tier a ticket needs them (1.20.44 escalate()
     * only). The per-filing fan-out idiom notify_workers() uses, cut
     * down to :manage alone - a mere :coordinate holder is exactly who
     * this ticket was just taken away from, so they are not among the
     * recipients either.
     *
     * Bypasses the shared notify() helper deliberately (the same reason
     * request_info()/provide_info() call notifier::send() directly
     * instead of it): notify()'s payload shape is fixed to
     * {group, type, status, resolution}, and this message needs to carry
     * the escalation NOTE, which is not any of those.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the escalated ticket
     * @param string $note why, from the escalating actor
     * @param int $actorid the escalating actor, never notified about their own action
     */
    private static function notify_manage_holders(activity $activity, stdClass $ticket, string $note, int $actorid): void {
        $recipients = [];
        foreach (get_users_by_capability($activity->context(), 'mod/selfselectadvanced:manage', 'u.id') as $worker) {
            $recipients[(int) $worker->id] = true;
        }
        unset($recipients[$actorid]);
        if (!$recipients) {
            return;
        }

        $groupname = self::subject_name($activity, $ticket);
        $a = (object) [
            'group' => $groupname,
            'type' => get_string('tickettype' . $ticket->type, 'mod_selfselectadvanced'),
            // 1.20.56 deliverable B: the quotable reference, in the
            // subject. Staff-to-staff (this message never reaches a
            // requester), so the escalation note itself may say anything
            // the escalating actor wrote - it already does, unchanged.
            'pluginuid' => (string) ($ticket->pluginuid ?? ''),
            'note' => trim(html_to_text($note)),
        ];
        $url = new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticket->id]);
        foreach (array_keys($recipients) as $workerid) {
            notifier::send(
                $activity,
                'tickets',
                $workerid,
                'msgticketescalatedsubject',
                'msgticketescalatedbody',
                $a,
                $url,
                $groupname
            );
        }
    }

    /**
     * Close a claimed ticket as resolved or declined, or release it
     * back to the queue.
     *
     * Only the claimant closes or releases their ticket; a manage
     * holder may force-release a stuck one (claimant left). Closing
     * requires a resolution note.
     *
     * @param activity $activity the activity
     * @param int $ticketid the ticket
     * @param string $outcome resolved, declined or open (= release)
     * @param string $resolution what was done, or why declined
     * @param int $resolutionformat text format
     * @param int $userid the actor
     * @return stdClass the updated ticket
     * @throws \moodle_exception when refused
     */
    public static function close(
        activity $activity,
        int $ticketid,
        string $outcome,
        string $resolution,
        int $resolutionformat,
        int $userid
    ): stdClass {
        global $DB;

        if (!in_array($outcome, [self::STATUS_RESOLVED, self::STATUS_DECLINED, self::STATUS_OPEN], true)) {
            throw new \coding_exception('Unknown ticket outcome ' . $outcome);
        }
        if ($outcome !== self::STATUS_OPEN && trim(html_to_text($resolution)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }

        // The queue-worker authority, RE-ASKED (audit A-5), before the
        // lock and the transaction.
        //
        // `claimedby === $userid` is the only question this method used
        // to ask about the actor, and it is record ownership: the claim
        // is a row written the moment the ticket was picked up, and it
        // survives everything that happens to the person afterwards.
        // Take :coordinate away from a worker mid-shift - the whole
        // reason an administrator would prohibit it is that this person
        // should stop deciding queue outcomes - and every ticket
        // already in their name stayed theirs to resolve, decline or
        // release. Whether they may still WORK the queue is a question
        // with an answer, and nothing asked it after the claim.
        //
        // The same pair tickets.php asks at its door, in the same
        // order, so this is that door moved to the seam rather than a
        // new rule: a manage holder passes outright (they are the
        // force-release path for a stuck ticket too), and everyone else
        // must still hold :coordinate.
        self::require_queue_authority($activity, $userid);

        $events = new eventqueue();
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            // NEEDSINFO ALLOWED HERE TOO (decision 2, LIVENESS): the
            // claimant may decide a ticket without the answer they
            // asked for, exactly as they could before it was ever
            // asked. Release from needsinfo is allowed by the same
            // widening rather than singled out, because this one gate
            // guards all three outcomes and a ticket "not currently
            // claimed" only when it is truly neither claimed nor
            // waiting on an answer.
            if (!in_array($fresh->status, [self::STATUS_CLAIMED, self::STATUS_NEEDSINFO], true)) {
                throw new workflow_refusal('refusalticketnotclaimed', 'mod_selfselectadvanced');
            }
            $ismanager = has_capability('mod/selfselectadvanced:manage', $activity->context(), $userid);
            if ((int) $fresh->claimedby !== $userid && !($outcome === self::STATUS_OPEN && $ismanager)) {
                throw new workflow_refusal(
                    'refusalticketnotclaimant',
                    'mod_selfselectadvanced',
                    '',
                    fullname(\core_user::get_user((int) $fresh->claimedby))
                );
            }

            $now = time();
            $fresh->status = $outcome;
            $fresh->timemodified = $now;
            if ($outcome === self::STATUS_OPEN) {
                $fresh->claimedby = null;
                $fresh->timeclaimed = null;
            } else {
                $fresh->resolvedby = $userid;
                $fresh->timeresolved = $now;
                $fresh->resolution = $resolution;
                $fresh->resolutionformat = $resolutionformat;
            }
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            // The trail's action names what HAPPENED - released, for a
            // release, which carries no note of its own - rather than
            // reusing $outcome's 'open' verbatim, which would misname
            // the row (nothing about a released ticket's trail entry is
            // "open").
            $action = $outcome === self::STATUS_OPEN ? self::ACTION_RELEASED
                : ($outcome === self::STATUS_RESOLVED ? self::ACTION_RESOLVED : self::ACTION_DECLINED);
            $ticketlogid = self::log(
                $ticketid,
                $userid,
                $action,
                $outcome === self::STATUS_OPEN ? null : $resolution,
                $outcome === self::STATUS_OPEN ? FORMAT_PLAIN : $resolutionformat
            );

            // Relateduserid is the requester: every outcome close()
            // reaches (resolved, declined, a claimant's own release, or
            // a manager's force-release) is a STAFF action about this
            // person's request.
            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_closed::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'relateduserid' => (int) $fresh->requestedby,
                'other' => [
                    'type' => $fresh->type,
                    'outcome' => $outcome,
                    'action' => $action,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        if ($outcome !== self::STATUS_OPEN) {
            self::notify(
                $activity,
                (int) $fresh->requestedby,
                'msgticketclosedsubject',
                'msgticketclosedbody',
                $fresh,
                self::group_of($activity, $fresh)
            );
        }

        return $fresh;
    }

    /**
     * The claimant asks the requester a question; the ticket waits.
     *
     * Maintainer decision 2 (2026-08-15): until now a claimant with a
     * genuine question had exactly two ways to answer it - resolve the
     * ticket on a guess, or decline a request that might be perfectly
     * good - because closing was the only door out of CLAIMED. This is
     * a third one that keeps the ticket alive: NEEDSINFO is LIVE
     * everywhere OPEN and CLAIMED already are (the duplicate guards, the
     * guidecap/guidereduce single-slot rule, the auto-resolve
     * candidates), so a requester cannot file a second, contradictory
     * request just because the first is waiting on their own answer.
     *
     * Only the ticket's OWN claimant may ask - asking is working the
     * ticket, the same authority close() checks, in the same order:
     * status first, then identity - and only while it is CLAIMED. A
     * second question cannot be asked while the first is still
     * unanswered (there is nowhere on the row to put it, and the trail
     * would not know which question the eventual reply was to), and an
     * open ticket has no claimant to be asking one.
     *
     * @param activity $activity the activity
     * @param int $ticketid the claimed ticket
     * @param string $question what the claimant needs to know
     * @param int $questionformat text format of the question
     * @param int $actorid the claimant
     * @return stdClass the updated ticket
     * @throws \moodle_exception when refused
     */
    public static function request_info(
        activity $activity,
        int $ticketid,
        string $question,
        int $questionformat,
        int $actorid
    ): stdClass {
        global $DB;

        // The queue-worker authority, asked FIRST (audit A1, 2026-08-20),
        // exactly as close() asks it, before even the emptiness check
        // below: without this, a REQUESTER with no queue authority at
        // all reaches the not-claimant check further down and is handed
        // the claimant's fullname by workflow_refusal - the cardinal
        // "Somebody is handling this" rule broken by a redirect notice.
        self::require_queue_authority($activity, $actorid);

        // The emptiness idiom file() and close() both use: a question
        // that says nothing is not a question a requester could answer.
        if (trim(html_to_text($question)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }

        // Lock + transaction + re-read + rollback discipline copied
        // from claim(): the RECORD OWNERSHIP question below (this
        // ticket's claimant, whoever that is right now) only makes
        // sense once the actor is proven to hold queue authority at
        // all, which the re-ask above just did.
        $events = new eventqueue();
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== self::STATUS_CLAIMED) {
                throw new workflow_refusal('refusalticketnotclaimed', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->claimedby !== $actorid) {
                throw new workflow_refusal(
                    'refusalticketnotclaimant',
                    'mod_selfselectadvanced',
                    '',
                    fullname(\core_user::get_user((int) $fresh->claimedby))
                );
            }

            $fresh->status = self::STATUS_NEEDSINFO;
            $fresh->timemodified = time();
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            $ticketlogid = self::log($ticketid, $actorid, self::ACTION_NEEDSINFO, $question, $questionformat);

            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_info_requested::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'relateduserid' => (int) $fresh->requestedby,
                'other' => [
                    'type' => $fresh->type,
                    'action' => self::ACTION_NEEDSINFO,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        // The requester, always - there is no staff-vs-requester branch
        // to copy here the way notify() has one, because this message
        // has exactly one possible recipient. B2 (deliverable 3): the
        // question they need to answer is ON the thread now, so the
        // link goes straight there rather than to myrequests.php, which
        // only says the ticket is waiting and makes them find it again.
        $groupname = self::subject_name($activity, $fresh);
        notifier::send(
            $activity,
            'tickets',
            (int) $fresh->requestedby,
            'msgticketneedsinfosubject',
            'msgticketneedsinfobody',
            (object) [
                'group' => $groupname,
                'type' => get_string('tickettype' . $fresh->type, 'mod_selfselectadvanced'),
                // 1.20.56 deliverable B: this goes to the REQUESTER, so
                // no staff identity travels with it - the contact-privacy
                // rule applies to a notification exactly as it does to
                // the screen, and this object never carries one. The
                // claimant's own question is already the "actual text
                // that was written" this deliverable asks for.
                'pluginuid' => (string) ($fresh->pluginuid ?? ''),
                'question' => trim(html_to_text($question)),
            ],
            new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticketid]),
            $groupname
        );

        return $fresh;
    }

    /**
     * The requester answers a needs-info question; handling resumes
     * with the SAME claimant.
     *
     * Decision 2's other half. Only the requester may answer - the same
     * ownership withdraw() checks, ('errticketnotfound' for the wrong
     * activity, then 'refusalticketnotyours' for the wrong person) -
     * and only while the ticket is actually waiting on them. The
     * claimant who asked is never displaced: this is a reply to their
     * question, not a reopening of the queue, so status returns
     * straight to CLAIMED naming the same claimedby, rather than to
     * OPEN where a second claimant could intervene.
     *
     * @param activity $activity the activity
     * @param int $ticketid the needs-info ticket
     * @param string $reply the answer
     * @param int $replyformat text format of the reply
     * @param int $userid the requester
     * @return stdClass the updated ticket
     * @throws \moodle_exception when refused
     */
    public static function provide_info(
        activity $activity,
        int $ticketid,
        string $reply,
        int $replyformat,
        int $userid
    ): stdClass {
        global $DB;

        if (trim(html_to_text($reply)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }

        $events = new eventqueue();
        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->requestedby !== $userid) {
                throw new workflow_refusal('refusalticketnotyours', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== self::STATUS_NEEDSINFO) {
                throw new workflow_refusal('refusalticketnotneedsinfo', 'mod_selfselectadvanced');
            }

            $fresh->status = self::STATUS_CLAIMED;
            $fresh->timemodified = time();
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            $ticketlogid = self::log($ticketid, $userid, self::ACTION_INFOREPLY, $reply, $replyformat);

            // Queued, not triggered here: CONC-001 requirement 2.
            $events->push(\mod_selfselectadvanced\event\ticket_info_provided::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'relateduserid' => (int) $fresh->claimedby,
                'other' => [
                    'type' => $fresh->type,
                    'action' => self::ACTION_INFOREPLY,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]));

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $events->flush();

        // The claimant, always - the mirror of request_info()'s single
        // fixed recipient above. B2 (deliverable 3): the reply is ON
        // the thread, so that is where the link now goes rather than to
        // the queue, which would show the ticket but not the answer.
        $groupname = self::subject_name($activity, $fresh);
        notifier::send(
            $activity,
            'tickets',
            (int) $fresh->claimedby,
            'msgticketinforeplysubject',
            'msgticketinforeplybody',
            (object) [
                'group' => $groupname,
                'type' => get_string('tickettype' . $fresh->type, 'mod_selfselectadvanced'),
                // 1.20.56 deliverable B: this goes to the CLAIMANT
                // (staff), so a name may appear - it just never does here
                // either, since a requester's reply carries no staff
                // identity to begin with.
                'pluginuid' => (string) ($fresh->pluginuid ?? ''),
                'reply' => trim(html_to_text($reply)),
            ],
            new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticketid]),
            $groupname
        );

        return $fresh;
    }

    /**
     * Post a thread reply that does NOT close the ticket (1.20.46: the
     * LLM API's "respond" half of read+respond, BUILD spec section C -
     * "implemented as the trail 'comment' action ... claimant-only, logs
     * a note-bearing trail row visible to the requester, fires
     * ticket_commented with the full payload bar, notifies the
     * requester"). No 1.20.44 trail action fit this shape: filed/
     * inforeply/withdrawn are the requester's own; claimed/released/
     * needsinfo/resolved/declined all carry a status change; referred/
     * escalated/published_faq are staff-internal. A reply that changes
     * nothing about the ticket's status or claim, and that the requester
     * DOES see, needed its own action - ACTION_COMMENTED.
     *
     * Authority mirrors request_info() exactly: require_queue_authority()
     * re-asked first (audit A1, 2026-08-20 - this method was reachable
     * only through api_respond, which checks authority itself, so the
     * missing gate was latent rather than live; fixed anyway, the same
     * door as request_info()/refer()), then record ownership (the
     * ticket's own current claimant, whoever that is right now), only
     * while CLAIMED - the same shape a genuine "I'm working on it, here's
     * an update" reply has for a human coordinator, and the one that
     * keeps a machine caller from posting into a ticket mid-handoff
     * (NEEDSINFO, waiting on the requester) or one nobody has taken up
     * yet.
     *
     * @param activity $activity the activity
     * @param int $ticketid the claimed ticket
     * @param string $note the reply
     * @param int $noteformat text format of the note
     * @param int $actorid the claimant
     * @return stdClass the ticket row (unchanged but for timemodified)
     * @throws \moodle_exception when refused
     */
    public static function comment(
        activity $activity,
        int $ticketid,
        string $note,
        int $noteformat,
        int $actorid
    ): stdClass {
        global $DB;

        // The queue-worker authority, asked FIRST (audit A1, 2026-08-20),
        // exactly as request_info()/refer() ask it.
        self::require_queue_authority($activity, $actorid);

        if (trim(html_to_text($note)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }

        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== self::STATUS_CLAIMED) {
                throw new workflow_refusal('refusalticketnotclaimed', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->claimedby !== $actorid) {
                throw new workflow_refusal(
                    'refusalticketnotclaimant',
                    'mod_selfselectadvanced',
                    '',
                    fullname(\core_user::get_user((int) $fresh->claimedby))
                );
            }

            // No status/claim change - only the trail grows. timemodified
            // still advances (every other transition in this class does
            // the same on its own write), so "last activity" stays true
            // for a ticket that just gained a reply.
            $fresh->timemodified = time();
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            $ticketlogid = self::log($ticketid, $actorid, self::ACTION_COMMENTED, $note, $noteformat);

            $event = \mod_selfselectadvanced\event\ticket_commented::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'relateduserid' => (int) $fresh->requestedby,
                'other' => [
                    'type' => $fresh->type,
                    'action' => self::ACTION_COMMENTED,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $event->trigger();

        $groupname = self::subject_name($activity, $fresh);
        notifier::send(
            $activity,
            'tickets',
            (int) $fresh->requestedby,
            'msgticketcommentedsubject',
            'msgticketcommentedbody',
            (object) [
                'group' => $groupname,
                'type' => get_string('tickettype' . $fresh->type, 'mod_selfselectadvanced'),
                // 1.20.56 deliverable B: this goes to the REQUESTER - no
                // staff identity travels with it, same as needsinfo above.
                'pluginuid' => (string) ($fresh->pluginuid ?? ''),
                'note' => trim(html_to_text($note)),
            ],
            new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticketid]),
            $groupname
        );

        return $fresh;
    }

    /**
     * A direct unfreeze resolves the group's open or claimed unfreeze
     * ticket, so the queue never lists work already done.
     *
     * @param activity $activity the activity
     * @param int $groupid the group just unfrozen
     * @param int $userid who unfroze it
     */
    public static function autoresolve_unfreeze(activity $activity, int $groupid, int $userid): void {
        global $DB;

        // Includes needsinfo alongside open and claimed (LIVENESS,
        // decision 2): an unfreeze ticket left waiting on the
        // requester's answer is still the queue's record of that
        // request, and a direct unfreeze must close it exactly as it
        // would an open or claimed one, or it sits in the queue as work
        // already done.
        $candidate = $DB->get_record_select(
            'selfselectadvanced_ticket',
            "groupid = :groupid AND type = :type AND status IN (:open, :claimed, :needsinfo)",
            [
                'groupid' => $groupid,
                'type' => self::TYPE_UNFREEZE,
                'open' => self::STATUS_OPEN,
                'claimed' => self::STATUS_CLAIMED,
                'needsinfo' => self::STATUS_NEEDSINFO,
            ]
        );
        if (!$candidate) {
            return;
        }

        // Under the same per-ticket lock the claim uses, and re-read
        // inside it: without this a claim landing between the read and
        // the write would be silently overwritten by a whole-row
        // update carrying the stale claimant.
        //
        // A DELEGATED TRANSACTION, added alongside the trail (decision
        // 1): this method used to run its single UPDATE with no
        // transaction of its own, which was safe only because it wrote
        // one row. It now also writes a log row, and the two must
        // commit or roll back together - the same reason every other
        // transition here already opens one before its first write.
        $lock = locks::acquire('ticket:' . $candidate->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $live = $DB->get_record('selfselectadvanced_ticket', ['id' => $candidate->id]);
            if (!$live || !in_array($live->status, [self::STATUS_OPEN, self::STATUS_CLAIMED, self::STATUS_NEEDSINFO], true)) {
                // Someone closed it while we waited - their outcome stands.
                $transaction->allow_commit();
                return;
            }
            $now = time();
            $live->status = self::STATUS_RESOLVED;
            $live->claimedby = $live->claimedby ?: $userid;
            $live->timeclaimed = $live->timeclaimed ?: $now;
            $live->resolvedby = $userid;
            $live->timeresolved = $now;
            $live->resolution = get_string('ticketautoresolved', 'mod_selfselectadvanced');
            $live->resolutionformat = FORMAT_PLAIN;
            $live->timemodified = $now;
            $DB->update_record('selfselectadvanced_ticket', $live);

            self::log($live->id, $userid, self::ACTION_RESOLVED, $live->resolution, $live->resolutionformat);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }
    }

    /**
     * Refuse unless this actor may work the request queue at all.
     *
     * ONE home for the pair tickets.php and coordinator.php ask at
     * their doors - hold :manage, or hold :coordinate - so claim() and
     * close() cannot drift apart from each other or from the pages.
     * Deliberately not has_any_capability(): asking :coordinate LAST
     * and by name is what makes the refusal a required_capability
     * exception naming the capability an administrator actually took
     * away, which is the message the pages already produce.
     *
     * This is authority, not the conflict-of-interest guard below.
     * require_uninvolved() takes authority AWAY from an actor who has
     * it; it has never granted any, and an actor it does not happen to
     * name walks straight past it.
     *
     * @param activity $activity the activity
     * @param int $userid the actor
     * @throws \required_capability_exception when neither is effective
     */
    public static function require_queue_authority(activity $activity, int $userid): void {
        $context = $activity->context();
        if (has_capability('mod/selfselectadvanced:manage', $context, $userid)) {
            return;
        }
        require_capability('mod/selfselectadvanced:coordinate', $context, $userid);
    }

    /**
     * The boolean twin of require_queue_authority(), for a page (or an
     * exporter, which cannot throw mid-render) deciding whether to OFFER
     * a direct route to the queue rather than refusing one (1.20.53
     * deliverable B: "give staff a direct route rather than a dashboard
     * detour"). Exactly the same two capabilities, in the same order, so
     * this and require_queue_authority() can never answer differently.
     *
     * @param activity $activity the activity
     * @param int $userid the actor
     * @return bool
     */
    public static function has_queue_authority(activity $activity, int $userid): bool {
        $context = $activity->context();

        return has_capability('mod/selfselectadvanced:manage', $context, $userid)
            || has_capability('mod/selfselectadvanced:coordinate', $context, $userid);
    }

    /**
     * Whether this viewer may open a ticket's thread (ticket.php, slice
     * B2): the ticket's own requester, OR anyone who passes
     * require_queue_authority() (manage or coordinate). A group LEADER
     * is NOT granted access by leadership alone - filing-authority
     * changes are 1.20.43's, not this one.
     *
     * Extracted so the page's door has exactly one predicate to call, in
     * the same spirit as teamaccess::may_review_team() for review.php: a
     * test can drive every arm of the rule without executing the page
     * script PHPUnit cannot run end-to-end (require_login(), redirect(),
     * echo $OUTPUT->header()).
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @param int $userid the viewer
     * @return bool
     */
    public static function may_view_thread(activity $activity, stdClass $ticket, int $userid): bool {
        if ((int) $ticket->requestedby === $userid) {
            return true;
        }

        $context = $activity->context();

        return has_capability('mod/selfselectadvanced:manage', $context, $userid)
            || has_capability('mod/selfselectadvanced:coordinate', $context, $userid);
    }

    /** @var string The opening request's attachments (itemid = ticket id). */
    public const FILEAREA_REQUEST = 'ticketrequest';

    /** @var string A thread post's attachments (itemid = ticketlog row id). */
    public const FILEAREA_POST = 'ticketpost';

    /**
     * The filemanager options every ticket attachment field shares
     * (spec: "maxfiles 5, site default maxbytes").
     *
     * @return array
     */
    public static function file_options(): array {
        return [
            'maxfiles' => 5,
            'subdirs' => 0,
        ];
    }

    /**
     * Save a submitted draft area into the MOST RECENT ticketpost row
     * (1.20.44 part 2): the second half of the two-step sequence
     * request_info()/provide_info()/close()'s resolve outcome/
     * grant_guidecap() all follow from their page - the log row those
     * calls just wrote does not exist until they return, so the draft
     * area a form prepared before the request cannot be keyed on it in
     * advance (the exact reason group.php's own filing forms mint a
     * fresh draft and save it only once the real id exists).
     *
     * "Most recent" is safe here specifically because the caller is a
     * single HTTP request: it just wrote exactly one new trail row a
     * moment ago (under the service call's own lock) and is now the
     * only actor that could possibly be racing to add another.
     *
     * A zero draftitemid (no filemanager submission at all, or nothing
     * chosen) is a deliberate no-op - there is nothing to save, and
     * asking the file API to act on a non-existent draft area would be
     * make-work at best.
     *
     * @param activity $activity the activity
     * @param int $ticketid the ticket just acted on
     * @param int $draftitemid the submitted draft area, or 0 for none
     */
    public static function save_post_attachments(activity $activity, int $ticketid, int $draftitemid): void {
        if ($draftitemid <= 0) {
            return;
        }
        $trail = self::trail($activity, $ticketid, true);
        if (!$trail) {
            return;
        }
        $last = end($trail);
        file_save_draft_area_files(
            $draftitemid,
            $activity->context()->id,
            'mod_selfselectadvanced',
            self::FILEAREA_POST,
            (int) $last->id,
            self::file_options()
        );
    }

    /**
     * THE ONE access rule for both ticket file areas (1.20.44 part 2) -
     * lib.php's pluginfile callback calls this and nothing else, so
     * there is exactly one implementation of "who may download this" to
     * ever drift out of step with the thread page that offers the link.
     *
     * ticketrequest is exactly ticket.php's own door
     * (may_view_thread(): the requester, or queue authority). ticketpost
     * narrows that further for a NON-staff viewer (the requester, and
     * only the requester - may_view_thread() admits nobody else without
     * queue authority) to whatever that specific trail row's content is
     * visible under: STAFF_INTERNAL_ACTIONS (referred, escalated) are
     * never visible to them, on the thread OR here - a requester who
     * somehow knew a staff-internal log id could not fetch its file by
     * guessing the URL either. A queue-authority holder (isstaff) always
     * sees every ticketpost file, exactly as they see every trail row.
     *
     * @param activity $activity the activity
     * @param string $filearea self::FILEAREA_REQUEST or self::FILEAREA_POST
     * @param int $itemid the ticket id (request) or ticketlog row id (post)
     * @param int $userid the viewer
     * @return bool
     */
    public static function may_access_ticket_file(activity $activity, string $filearea, int $itemid, int $userid): bool {
        global $DB;

        if ($filearea === self::FILEAREA_REQUEST) {
            try {
                $ticket = self::get($activity, $itemid);
            } catch (\moodle_exception $e) {
                return false;
            }

            return self::may_view_thread($activity, $ticket, $userid);
        }

        if ($filearea === self::FILEAREA_POST) {
            $logrow = $DB->get_record('selfselectadvanced_ticketlog', ['id' => $itemid]);
            if (!$logrow) {
                return false;
            }
            try {
                $ticket = self::get($activity, (int) $logrow->ticketid);
            } catch (\moodle_exception $e) {
                return false;
            }
            if (!self::may_view_thread($activity, $ticket, $userid)) {
                return false;
            }
            $context = $activity->context();
            $isstaff = has_capability('mod/selfselectadvanced:manage', $context, $userid)
                || has_capability('mod/selfselectadvanced:coordinate', $context, $userid);
            if ($isstaff) {
                return true;
            }

            // The pure requester: never a staff-internal action's note,
            // and by construction that note is the only thing a
            // staff-internal row could ever have attached, since neither
            // refer() nor escalate() ever offers a filemanager.
            return !in_array($logrow->action, self::STAFF_INTERNAL_ACTIONS, true);
        }

        throw new \coding_exception('Unknown ticket file area ' . $filearea);
    }

    /**
     * The conflict-of-interest guard (strategy 1.16 D): an actor whose
     * authority is coordinate-only is refused on any group where they
     * are the assigned guide, the nominated successor guide, or a
     * confirmed member. Manage holders are exempt.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @param int $userid the actor
     * @throws \moodle_exception refusalcoiinvolved when refused
     */
    public static function require_uninvolved(activity $activity, stdClass $group, int $userid): void {
        $involvement = self::involvement($activity, $group, $userid);
        if ($involvement !== null) {
            throw new workflow_refusal('refusalcoiinvolved', 'mod_selfselectadvanced', '', $involvement);
        }
    }

    /**
     * The SAME question require_uninvolved() asks, as a value rather
     * than an exception: how is this actor involved with this group, if
     * they are at all?
     *
     * Extracted (UX-001) because a page deciding whether to OFFER a
     * conflicted action needs the answer without the refusal - and the
     * only alternative on offer was a second copy of the rule in the
     * renderer, which is how two of this plugin's predicates drifted
     * apart already (A-05, F-6). require_uninvolved() is now this
     * method plus a throw, so the offered control and the refusal can
     * no longer disagree.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row; guideid and, where it
     *        exists, guidesuccessorid must be present on it
     * @param int $userid the actor
     * @return string|null the localised involvement, or null when the
     *         actor is uninvolved (which a :manage holder always is,
     *         by exemption)
     */
    public static function involvement(activity $activity, stdClass $group, int $userid): ?string {
        global $DB;

        if (has_capability('mod/selfselectadvanced:manage', $activity->context(), $userid)) {
            return null;
        }
        if ((int) $group->guideid === $userid) {
            return get_string('coiguide', 'mod_selfselectadvanced');
        }
        if (!property_exists($group, 'guidesuccessorid')) {
            // The docblock's contract, enforced the way
            // freeze::release_refusal() enforces its own: a row
            // selected without the column would answer "not the
            // successor" for everybody - the permissive direction, the
            // one a silent default must never take (blind audit
            // 1.20.3, finding 4: may_freeze_team() reached this read
            // with a partial row and was answered quietly).
            throw new \coding_exception(
                'tickets::involvement() needs $group->guidesuccessorid; the caller selected a partial row'
            );
        }
        if ((int) ($group->guidesuccessorid ?? 0) === $userid) {
            return get_string('coisuccessor', 'mod_selfselectadvanced');
        }
        if (
            $DB->record_exists('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $userid,
            'status' => groups::STATUS_CONFIRMED,
            ])
        ) {
            return get_string('coimember', 'mod_selfselectadvanced');
        }

        return null;
    }

    /**
     * Every group in the activity this person is INVOLVED with, as
     * ids: the bulk form of involvement(), for dashboards that need
     * the whole set rather than one team's answer (seam audit B6,
     * 1.20.20 - coordinator.php carried a hand-written copy of the
     * three arms). The SQL below restates involvement()'s arms - guide
     * of, nominated successor guide of, confirmed member of - and a
     * test pins the two producers to the same answers; the :manage
     * exemption is the same trusted arm involvement() opens with
     * (decision 65).
     *
     * @param activity $activity the activity
     * @param int $userid the person
     * @return int[] group ids, empty for a :manage holder
     */
    public static function involved_group_ids(activity $activity, int $userid): array {
        global $DB;

        if (has_capability('mod/selfselectadvanced:manage', $activity->context(), $userid)) {
            return [];
        }

        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT g.id
               FROM {selfselectadvanced_group} g
              WHERE g.activityid = :activityid
                AND (g.guideid = :guide OR g.guidesuccessorid = :successor
                     OR EXISTS (SELECT 1 FROM {selfselectadvanced_member} m
                                 WHERE m.groupid = g.id AND m.userid = :member AND m.status = :confirmed))",
            [
                'activityid' => $activity->id(),
                'guide' => $userid,
                'successor' => $userid,
                'member' => $userid,
                'confirmed' => groups::STATUS_CONFIRMED,
            ]
        ));
    }

    /**
     * The conflict-of-interest guard for overrides (strategy 1.17 B1).
     *
     * A coordinator may grant exceptions, but not to themselves and not
     * on a team they are part of: an exception is exactly the kind of
     * decision that has to be seen to be disinterested. Managers are
     * exempt, as with every other conflict rule here - their authority
     * is accountable by role.
     *
     * @param activity $activity the activity
     * @param string $scope user, group, guide or move
     * @param int $targetid the user or group the exception is for
     * @param int $userid the actor
     * @throws \moodle_exception refusalcoiself or refusalcoiinvolved
     */
    public static function require_uninvolved_override(
        activity $activity,
        string $scope,
        int $targetid,
        int $userid
    ): void {
        global $DB;

        // The rule restrains the NEW coordinate authority and nothing
        // else. A manager is exempt by role, and anybody who could set
        // an override before this release - an editing teacher, a guide
        // holding the capability - keeps exactly what they had. Adding
        // a role to a site must never quietly take authority away; that
        // lesson cost a release already.
        $context = $activity->context();
        if (has_capability('mod/selfselectadvanced:manage', $context, $userid)) {
            return;
        }
        if (!has_capability('mod/selfselectadvanced:coordinate', $context, $userid)) {
            return;
        }
        if (in_array($scope, ['user', 'guide'], true) && $targetid === $userid) {
            throw new workflow_refusal('refusalcoiself', 'mod_selfselectadvanced');
        }
        if ($scope === 'group') {
            self::require_uninvolved($activity, groups::get($activity, $targetid), $userid);
        }
        if ($scope === 'move') {
            // The one scope this guard used to fall through (D6-11),
            // and the one that moves rosters. A move-scope override is
            // an exception granted about a PERSON and up to TWO teams,
            // so all three are judged: never for oneself, and never on
            // a team the actor is part of, guides or is the successor
            // guide of. Latent while only :manage holders could reach
            // moveedit.php - armed the moment override authority
            // reaches anyone coordinate-shaped.
            $move = $DB->get_record(
                'selfselectadvanced_move',
                ['id' => $targetid, 'activityid' => $activity->id()],
                '*',
                MUST_EXIST
            );
            if ((int) $move->userid === $userid) {
                throw new workflow_refusal('refusalcoiself', 'mod_selfselectadvanced');
            }
            if ($move->sourcegroupid) {
                self::require_uninvolved($activity, groups::get($activity, (int) $move->sourcegroupid), $userid);
            }
            if ($move->targetgroupid) {
                self::require_uninvolved($activity, groups::get($activity, (int) $move->targetgroupid), $userid);
            }
        }
    }

    /**
     * One ticket, asserted to belong to the activity.
     *
     * @param activity $activity the activity
     * @param int $ticketid the ticket
     * @return stdClass the row
     */
    public static function get(activity $activity, int $ticketid): stdClass {
        global $DB;

        $ticket = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        if ((int) $ticket->activityid !== $activity->id()) {
            throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
        }

        return $ticket;
    }

    /**
     * The history trail of one ticket, oldest first.
     *
     * Two readers, one query (maintainer decision 3, for the UI agent
     * building on this): a REQUESTER is meant to see STATE CHANGES ONLY
     * - no staff identity at all, ever - while STAFF see the full trail,
     * actor included. $withactors is what tells the two views apart,
     * and there is exactly one SQL statement per branch rather than one
     * query filtered afterwards in PHP, so the requester-facing rows
     * never carry actor identity to strip - the column is never
     * fetched, not merely hidden.
     *
     * @param activity $activity the activity
     * @param int $ticketid the ticket
     * @param bool $withactors true for the staff view (adds actorid and
     *        actorname to every row); false for the requester view,
     *        where NEITHER key is present on the returned objects at all
     * @return stdClass[] log rows oldest-first, keyed by id: action,
     *         note, noteformat, timecreated always; actorid and
     *         actorname only when $withactors
     */
    public static function trail(activity $activity, int $ticketid, bool $withactors): array {
        global $DB;

        // Ownership, exactly like get() above: a ticket belongs to the
        // activity asking about it, or the caller is asking about the
        // wrong thing entirely - this throws errticketnotfound rather
        // than silently returning another activity's trail.
        self::get($activity, $ticketid);

        if (!$withactors) {
            // 1.20.44: STAFF_INTERNAL_ACTIONS (referred, escalated) never
            // reach the anonymised requester view - the query excludes
            // them outright rather than fetching and filtering, the same
            // "never selected" discipline requester_contact_map()'s email
            // rule and search_guides()'s address column already keep
            // (docs/architecture.md A14): a row this branch never
            // returns cannot be printed by a later edit either.
            [$hidesql, $hideparams] = $DB->get_in_or_equal(
                self::STAFF_INTERNAL_ACTIONS,
                SQL_PARAMS_NAMED,
                'hide',
                false
            );
            return $DB->get_records_sql(
                "SELECT l.id, l.action, l.note, l.noteformat, l.timecreated
                   FROM {selfselectadvanced_ticketlog} l
                  WHERE l.ticketid = :ticketid AND l.action $hidesql
               ORDER BY l.timecreated, l.id",
                array_merge(['ticketid' => $ticketid], $hideparams)
            );
        }

        // The name fields fullname() needs, joined in rather than
        // resolved with a get_user() call per row - the trail of a
        // long-handled ticket can hold a dozen rows, and this plugin's
        // house rule against a query per row applies here exactly as it
        // does to notify_workers()'s recipient loop.
        //
        // LEFT JOIN, not INNER (audit A5, 2026-08-20): a privacy erasure
        // de-links a row's actorid to the 0 sentinel rather than
        // deleting the row (classes/privacy/provider.php's
        // scrub_user_in_activity() - "de-linked to the same 0 sentinel"
        // as every other actor column it touches). No {user} row has id
        // 0, so an INNER JOIN silently turned that de-link into a
        // deletion for the STAFF view alone - the anonymised requester
        // branch above has no join at all and kept the row - making the
        // append-only audit trail LOSE rows exactly where it matters
        // most. A missing name now renders as the de-linked placeholder
        // instead of vanishing the row.
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.action, l.note, l.noteformat, l.timecreated, l.actorid,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename
               FROM {selfselectadvanced_ticketlog} l
          LEFT JOIN {user} u ON u.id = l.actorid
              WHERE l.ticketid = :ticketid
           ORDER BY l.timecreated, l.id",
            ['ticketid' => $ticketid]
        );
        foreach ($rows as $row) {
            $row->actorname = $row->actorid
                ? fullname($row)
                : get_string('threadactordeleted', 'mod_selfselectadvanced');
            unset(
                $row->firstname,
                $row->lastname,
                $row->firstnamephonetic,
                $row->lastnamephonetic,
                $row->middlename,
                $row->alternatename
            );
        }

        return $rows;
    }

    /**
     * Contact details of ticket requesters the viewer is connected to
     * via an ACTIVE CLAIM (contact-privacy rule (c)).
     *
     * NO EMAIL, EVER (maintainer decision 17, 2026-08-01): staff reach a
     * requester with the Send a message action, which is a Moodle
     * message and shows nobody an address. Do not add an 'email' key
     * back - "the claimant is a connection" was written before the
     * maintainer said the plugin regresses to Moodle messaging, and a
     * coordinator is precisely the non-editing-teacher audience the
     * cardinal rule names.
     *
     * The mobile is consent-gated with a literal false bypass, and that
     * literal is correct here and is the only sanctioned one: consent
     * path only, no bypass inside a privacy feature.
     *
     * Empty when the activity does not protect contact details. That
     * inversion - protection ON gives a claimant MORE than OFF - is
     * deliberate: routing rule (c) through can_see_map() in both modes
     * would hit that helper's all-true OFF shortcut and leak requester
     * contact to every queue viewer, not just the claimant. If the
     * claimant should have contact in both modes, the fix is a small
     * rule-(c)-alone helper (the shape of
     * contactprivacy::guided_subjects()), NOT relaxing the shortcut.
     *
     * Render-time read: no lock, no transaction, no write. The map
     * re-reads claimedby/status, so a claim released between page load
     * and render drops the row by itself.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, presumed claimant
     * @param int[] $requesterids requester user ids from the page's rows
     * @return stdClass[] requesterid => (object) ['mobile' => string]
     */
    public static function requester_contact_map(activity $activity, int $viewerid, array $requesterids): array {
        $requesterids = array_values(array_unique(array_map('intval', $requesterids)));
        if (!$requesterids || !contactprivacy::enabled($activity)) {
            return [];
        }

        $map = contactprivacy::can_see_map($activity, $viewerid, $requesterids);
        $ids = array_keys(array_filter($map));
        if (!$ids) {
            return [];
        }

        $records = \mod_selfselectadvanced\local\attributes\manager::get_for_users($ids);
        $out = [];
        foreach ($ids as $id) {
            $record = $records[$id] ?? null;
            $out[$id] = (object) [
                'mobile' => \mod_selfselectadvanced\local\attributes\manager::mobile_visible($record, false)
                    ? (string) $record->mobile
                    : '',
            ];
        }

        return $out;
    }

    /**
     * Refuse a queue filter value that is not really one of ours.
     *
     * Slice C2: tickets.php whitelists the GET params it reads against
     * these same TYPE_* constants BEFORE ever calling queue(),
     * queue_count() or count_open() - so a value that gets here and is
     * not empty and not a known type is a caller bug, not something a
     * person typed. That is why this throws coding_exception rather
     * than a workflow_refusal (contrast file(), which validates a type
     * a PERSON supplied and is right to keep that narrower list rather
     * than this one - guidecap/guidereduce/guidegone are filed through
     * their own dedicated methods, but a queue filter has to be able to
     * SHOW every type a ticket can hold, including those three).
     *
     * @param string $type '' (no filter) or one of self::TYPE_*
     * @throws \coding_exception if $type is neither
     */
    private static function validate_type_filter(string $type): void {
        if ($type === '') {
            return;
        }
        if (!in_array($type, self::known_types(), true)) {
            throw new \coding_exception('Unknown ticket type filter ' . $type);
        }
    }

    /**
     * Every ticket type this plugin knows (the "type registry" other
     * callers outside this class validate against without duplicating
     * the list - 1.20.45's knowledgebank keys its tickettype column on
     * exactly this set, since 'compchange' is a string, not a row this
     * database could enforce a real foreign key against).
     *
     * Extracted from validate_type_filter()'s own literal, which now
     * calls this rather than keeping a second copy that could drift.
     *
     * @return string[]
     */
    public static function known_types(): array {
        return [
            self::TYPE_COMPCHANGE,
            self::TYPE_UNFREEZE,
            self::TYPE_GUIDECAP,
            self::TYPE_GUIDEGONE,
            self::TYPE_GUIDEREDUCE,
            self::TYPE_DATES,
            self::TYPE_PENALTY,
            self::TYPE_LEADERCHANGE,
            self::TYPE_HELP,
        ];
    }

    /**
     * The ticket statuses offered as a queue/my-requests FILTER value
     * (1.20.57 deliverables A and B): the same five tickets.php's own
     * queue page has whitelisted since slice C2 - open, claimed,
     * resolved, declined, withdrawn.
     *
     * DELIBERATELY not every self::STATUS_* constant: STATUS_NEEDSINFO
     * is missing, exactly as it always has been in the queue page's own
     * whitelist. A status filter is UI vocabulary the two pages must
     * share (spec: "the same vocabulary the queue already uses, so the
     * two pages cannot drift apart") - widening it for one page alone
     * would be the very drift the spec forbids, so this is extracted
     * from tickets.php's own literal rather than left as two copies
     * that could disagree. validate_status_filter() below still accepts
     * the full six-status set for any OTHER caller, because that guards
     * the SERVICE layer against a coding bug, not this UI whitelist.
     *
     * @return string[] self::STATUS_* values offered as a filter
     */
    public static function filterable_statuses(): array {
        return [
            self::STATUS_OPEN,
            self::STATUS_CLAIMED,
            self::STATUS_RESOLVED,
            self::STATUS_DECLINED,
            self::STATUS_WITHDRAWN,
        ];
    }

    /**
     * The free-text search condition shared by the staff queue's
     * filtering methods - queue(), queue_count(), count_open() and
     * escalated_live_nonopen_count() (1.20.57 deliverable B): the
     * reference, the request text, AND the trail's own notes, which is
     * where the memorable phrase usually lives (spec).
     *
     * An EXISTS subquery against selfselectadvanced_ticketlog, never a
     * JOIN: the queue page can hold a full page of tickets, and a JOIN
     * against a one-to-many child table would return one row per
     * MATCHING LOG ENTRY rather than one per ticket, which is exactly
     * the "query multiplies rows" shape this plugin's house rule against
     * a query inside a render loop exists to keep out of a listing in
     * the first place. EXISTS keeps one row per ticket, the same promise
     * every other queue method here already makes, and the search still
     * runs as ONE statement rather than one query fired per row
     * afterwards.
     *
     * Every fragment this returns is ANDed onto the caller's existing
     * WHERE by the caller - never ORed in a way that could ADD rows -
     * so a search can only narrow whatever set of tickets was already
     * visible to the viewer, never widen it (spec: "search must narrow
     * those sets, never widen them").
     *
     * Empty $search means no filtering (spec: "empty search = no
     * filtering, exactly as today") - an empty fragment appended to
     * nothing.
     *
     * @param string $search the typed text, '' for no filter
     * @return array{0: string, 1: array<string, string>} [sql fragment
     *         (a leading " AND (...)", or '' when unfiltered), params
     *         for the caller to merge into its own params array]
     */
    private static function queue_search_condition(string $search): array {
        global $DB;

        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }
        // Escape FIRST: a percent or underscore the searcher TYPED is
        // data, not a wildcard, and the raw '%...%' wrapping below is
        // the only wildcarding this search performs (spec: "a student
        // typing a percent sign must not match everything").
        $like = '%' . $DB->sql_like_escape($search) . '%';

        $sql = ' AND (' . $DB->sql_like('t.pluginuid', ':searchref', false, false)
            . ' OR ' . $DB->sql_like('t.request', ':searchreq', false, false)
            . ' OR EXISTS (SELECT 1 FROM {selfselectadvanced_ticketlog} l'
            . ' WHERE l.ticketid = t.id AND ' . $DB->sql_like('l.note', ':searchnote', false, false) . ')'
            . ')';

        return [$sql, ['searchref' => $like, 'searchreq' => $like, 'searchnote' => $like]];
    }

    /**
     * The free-text search condition for a requester's own list - mine()
     * and mine_count() (1.20.57 deliverable A): the reference and the
     * request text.
     *
     * No trail here, unlike queue_search_condition() above: myrequests.php
     * never shows a requester the staff trail at all (the requester's own
     * thread view is trail($withactors = false), narrated state changes
     * only), so matching against text this page never displays would
     * search the invisible rather than help find anything.
     *
     * Same narrow-only and empty-means-unfiltered guarantees as
     * queue_search_condition() above - see that docblock.
     *
     * @param string $search the typed text, '' for no filter
     * @return array{0: string, 1: array<string, string>} [sql fragment, params to merge]
     */
    private static function mine_search_condition(string $search): array {
        global $DB;

        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }
        $like = '%' . $DB->sql_like_escape($search) . '%';

        $sql = ' AND (' . $DB->sql_like('t.pluginuid', ':searchref', false, false)
            . ' OR ' . $DB->sql_like('t.request', ':searchreq', false, false) . ')';

        return [$sql, ['searchref' => $like, 'searchreq' => $like]];
    }

    /**
     * Refuse a queue filter value that is not really one of ours.
     *
     * The status twin of validate_type_filter() above - same reasoning,
     * same reason it is a coding_exception and not a workflow_refusal.
     *
     * @param string $status '' (no filter) or one of self::STATUS_*
     * @throws \coding_exception if $status is neither
     */
    private static function validate_status_filter(string $status): void {
        if ($status === '') {
            return;
        }
        $known = [
            self::STATUS_OPEN,
            self::STATUS_CLAIMED,
            self::STATUS_NEEDSINFO,
            self::STATUS_RESOLVED,
            self::STATUS_DECLINED,
            self::STATUS_WITHDRAWN,
        ];
        if (!in_array($status, $known, true)) {
            throw new \coding_exception('Unknown ticket status filter ' . $status);
        }
    }

    /**
     * The queue: open tickets first come first served, then claimed,
     * then closed newest first.
     *
     * @param activity $activity the activity
     * @param int $viewerid the person looking, whose own requests are
     *                      left out unless they hold the manage capability;
     *                      0 for the whole queue
     * @param int $limitfrom first row to return, 0 for the start
     * @param int $limitnum how many rows to return, 0 for all of them
     * @param string $type self::TYPE_*, or '' for every type (slice C2 triage filter)
     * @param string $status self::STATUS_*, or '' for every status (slice C2 triage filter)
     * @param string $search free text to match against the reference, the
     *        request, or a trail note (1.20.57 deliverable B); '' for no
     *        filter
     * @return stdClass[] ticket rows
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function queue(
        activity $activity,
        int $viewerid = 0,
        int $limitfrom = 0,
        int $limitnum = 0,
        string $type = '',
        string $status = '',
        string $search = ''
    ): array {
        global $DB;

        self::validate_type_filter($type);
        self::validate_status_filter($status);

        // A worker is not shown the requests they filed themselves
        // (strategy 1.17 A3). They are refused if they try to take one
        // up anyway, so hiding it removes both the invitation to try and
        // the impression that it is theirs to work. Managers keep the
        // whole queue: somebody has to be able to answer those requests.
        $params = ['activityid' => $activity->id()];
        $mine = '';
        if ($viewerid > 0 && !has_capability('mod/selfselectadvanced:manage', $activity->context(), $viewerid)) {
            $mine = ' AND t.requestedby <> :viewerid';
            $params['viewerid'] = $viewerid;
        }
        // The triage filter (slice C2): a busy queue can be narrowed to
        // one type and/or one status before it is paged. Both are plain
        // AND clauses - empty means unfiltered, exactly like $mine above.
        $typesql = '';
        if ($type !== '') {
            $typesql = ' AND t.type = :type';
            $params['type'] = $type;
        }
        $statussql = '';
        if ($status !== '') {
            $statussql = ' AND t.status = :status';
            $params['status'] = $status;
        }
        // The free-text search (1.20.57 deliverable B), ANDed on top of
        // every clause above - see queue_search_condition()'s own
        // docblock for why this can only narrow the set $mine already
        // scoped, never widen it.
        [$searchsql, $searchparams] = self::queue_search_condition($search);
        $params = array_merge($params, $searchparams);

        // The team's name comes back with the row rather than being
        // looked up afterwards. The page used to resolve names by
        // loading EVERY group in the activity - fifteen hundred rows to
        // label a screenful of tickets - and this plugin is built for
        // that many teams. A LEFT JOIN because a team-limit request
        // carries no groupid and is about no team at all.
        return $DB->get_records_sql(
            "SELECT t.*, g.name AS groupname, g.pluginuid AS grouppluginuid
               FROM {selfselectadvanced_ticket} t
          LEFT JOIN {selfselectadvanced_group} g ON g.id = t.groupid
              WHERE t.activityid = :activityid" . $mine . $typesql . $statussql . $searchsql . "
           ORDER BY CASE WHEN t.status IN ('open','claimed','needsinfo') THEN 0 ELSE 1 END,
                    CASE WHEN t.status IN ('open','claimed','needsinfo') AND t.escalated = 1 THEN 0 ELSE 1 END,
                    CASE t.status
                        WHEN 'open' THEN 0
                        WHEN 'claimed' THEN 1
                        WHEN 'needsinfo' THEN 2
                        ELSE 3
                    END,
                    CASE WHEN t.status IN ('open','claimed','needsinfo') THEN t.timecreated ELSE -t.timemodified END,
                    t.id",
            $params,
            $limitfrom,
            $limitnum
        );
    }

    /**
     * How many tickets the queue holds for this viewer.
     *
     * Needed because the queue is now paged: resolved and declined
     * tickets are never removed, so over a semester the queue grows
     * without bound and returning all of it was a page that got slower
     * every week.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, 0 for no filtering
     * @param string $type self::TYPE_*, or '' for every type (slice C2 triage filter)
     * @param string $status self::STATUS_*, or '' for every status (slice C2 triage filter)
     * @param string $search free text, same as queue()'s own parameter
     *        (1.20.57 deliverable C: called with the SAME criteria as
     *        queue() itself, so a paging bar built from this total is
     *        never wrong about a filtered list's page count)
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function queue_count(
        activity $activity,
        int $viewerid = 0,
        string $type = '',
        string $status = '',
        string $search = ''
    ): int {
        global $DB;

        self::validate_type_filter($type);
        self::validate_status_filter($status);

        $params = ['activityid' => $activity->id()];
        $mine = '';
        if ($viewerid > 0 && !has_capability('mod/selfselectadvanced:manage', $activity->context(), $viewerid)) {
            $mine = ' AND t.requestedby <> :viewerid';
            $params['viewerid'] = $viewerid;
        }
        $typesql = '';
        if ($type !== '') {
            $typesql = ' AND t.type = :type';
            $params['type'] = $type;
        }
        $statussql = '';
        if ($status !== '') {
            $statussql = ' AND t.status = :status';
            $params['status'] = $status;
        }
        // Mirrors queue()'s own WHERE fragment exactly (deliverable C):
        // the same helper, called the same way, so the two can never
        // disagree about what "matches" means.
        [$searchsql, $searchparams] = self::queue_search_condition($search);
        $params = array_merge($params, $searchparams);

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid" . $mine . $typesql . $statussql . $searchsql,
            $params
        );
    }

    /**
     * The tickets one person filed, newest first.
     *
     * WHY THIS EXISTS. Until 1.20.39 nothing in the plugin showed a
     * requester their own request. The queue belongs to the staff who
     * work it, and the design put the outcome in the closing message
     * instead - see notify() at the foot of this class. That was a
     * decision, but it left three real gaps: a message can be missed or
     * undelivered (on the dev site every notification email was being
     * refused by the relay, silently); a claimed request produces a
     * message that says "somebody has this" and then nothing until it
     * closes; and withdraw() below implemented requester ownership that
     * no requester could reach, because its only caller sat behind the
     * guide capability.
     *
     * The scope is the filer and nobody else. There is no capability
     * check here on purpose, matching file(): the authority to see a
     * request is having made it, which is a fact about the row, not a
     * role. A viewer with no tickets gets an empty list, which is the
     * correct answer rather than a refusal.
     *
     * @param activity $activity the activity
     * @param int $userid the requester
     * @param int $limitfrom paging offset
     * @param int $limitnum page size, 0 for all
     * @param string $type self::TYPE_*, or '' for every type (1.20.57
     *        deliverable A, same vocabulary as the queue's own filter)
     * @param string $status self::STATUS_*, or '' for every status
     *        (1.20.57 deliverable A)
     * @param string $search free text to match against the reference or
     *        the request (1.20.57 deliverable A); '' for no filter
     * @return array<int, stdClass> ticket rows, each with groupname and grouppluginuid
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function mine(
        activity $activity,
        int $userid,
        int $limitfrom = 0,
        int $limitnum = 0,
        string $type = '',
        string $status = '',
        string $search = ''
    ): array {
        global $DB;

        self::validate_type_filter($type);
        self::validate_status_filter($status);

        $params = ['activityid' => $activity->id(), 'userid' => $userid];
        $typesql = '';
        if ($type !== '') {
            $typesql = ' AND t.type = :type';
            $params['type'] = $type;
        }
        $statussql = '';
        if ($status !== '') {
            $statussql = ' AND t.status = :status';
            $params['status'] = $status;
        }
        // ANDed onto the requestedby scope already in the WHERE below -
        // see mine_search_condition()'s own docblock for why a search
        // can only narrow this requester's own rows, never reach anybody
        // else's.
        [$searchsql, $searchparams] = self::mine_search_condition($search);
        $params = array_merge($params, $searchparams);

        // LEFT JOIN for the same reason queue() uses one: a team-limit
        // request carries no groupid and is about no team at all.
        //
        // NEEDSINFO ranks FIRST (B2, addendum item 2), ahead of open and
        // claimed rather than merely alongside them: it is the one
        // status here that demands the REQUESTER's own action - their
        // question is unanswered, not merely unclaimed or being worked -
        // so it must not sink to the closed/withdrawn tier the way B1
        // left it (uncovered by the CASE below, falling into ELSE and
        // sorting with resolved/declined/withdrawn history).
        return $DB->get_records_sql(
            "SELECT t.*, g.name AS groupname, g.pluginuid AS grouppluginuid
               FROM {selfselectadvanced_ticket} t
          LEFT JOIN {selfselectadvanced_group} g ON g.id = t.groupid
              WHERE t.activityid = :activityid AND t.requestedby = :userid" . $typesql . $statussql . $searchsql . "
           ORDER BY CASE t.status
                        WHEN 'needsinfo' THEN 0
                        WHEN 'open' THEN 1
                        WHEN 'claimed' THEN 2
                        ELSE 3
                    END,
                    t.timecreated DESC,
                    t.id DESC",
            $params,
            $limitfrom,
            $limitnum
        );
    }

    /**
     * The group's own live requests (1.20.53 deliverable A): what the
     * GROUP PAGE itself forgot the moment the filing notice scrolled
     * away - a student filed from this very page and it never mentioned
     * the ticket again, which the maintainer called the sharpest gap in
     * the report.
     *
     * Who sees which is the EXISTING authority, never a new one: the
     * requester sees their own rows, always; anyone who passes
     * require_queue_authority() sees the group's whole live set;
     * everybody else sees nothing, which this returns as an empty array
     * rather than refusing - the page draws no section at all for them,
     * the same "no empty heading" rule this deliverable itself states.
     *
     * LIVE means open, claimed or needsinfo - the same trio file()'s own
     * duplicate guard treats as one live ticket per (group, type) - so a
     * resolved, declined or withdrawn row never appears here; that
     * history stays on myrequests.php and the queue for whoever already
     * has authority to read it.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @param int $viewerid the viewer
     * @param bool $isstaff whether the viewer passes
     *        require_queue_authority(); CALLED by the caller and passed
     *        in, not re-derived here, so a page that already asked the
     *        question once never asks it twice
     * @return stdClass[] ticket rows, needsinfo first then open then
     *         claimed, newest first within each
     */
    public static function group_live(activity $activity, int $groupid, int $viewerid, bool $isstaff): array {
        global $DB;

        $params = [
            'activityid' => $activity->id(),
            'groupid' => $groupid,
            'open' => self::STATUS_OPEN,
            'claimed' => self::STATUS_CLAIMED,
            'needsinfo' => self::STATUS_NEEDSINFO,
        ];
        $viewersql = '';
        if (!$isstaff) {
            $viewersql = ' AND t.requestedby = :viewerid';
            $params['viewerid'] = $viewerid;
        }

        return $DB->get_records_sql(
            "SELECT t.*
               FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid AND t.groupid = :groupid
                    AND t.status IN (:open, :claimed, :needsinfo)" . $viewersql . "
           ORDER BY CASE t.status
                        WHEN 'needsinfo' THEN 0
                        WHEN 'open' THEN 1
                        WHEN 'claimed' THEN 2
                        ELSE 3
                    END,
                    t.timecreated DESC,
                    t.id DESC",
            $params
        );
    }

    /**
     * Every OTHER ticket one requester has filed in this activity, newest
     * first - the maintainer's repeated-request blocker (B2, deliverable
     * 1): a staff member deciding a live ticket can see whether this
     * requester has a pattern before they decide it.
     *
     * The scope is by REQUESTER, exactly like mine() above, but the
     * viewer is not the requester here - it is staff, so unlike mine()
     * this DOES gate on queue authority, checked on the viewer argument
     * (never the requester, who is never asked for one): a requester
     * must never reach another requester's history through this door,
     * and only mine()/myrequests.php is theirs.
     *
     * SPEC NOTE (B2): the ticket named `tickets::history(activity
     * $activity, int $requesterid, int $excludeticketid = 0): array` -
     * three parameters, no viewer. That signature cannot implement the
     * very next sentence of the same spec ("enforce inside the method:
     * throw if the VIEWER argument lacks queue authority") because no
     * viewer argument exists to check. Treated as a drafting omission
     * and implemented with the viewer this method's own contract
     * requires; $viewerid precedes $excludeticketid so the optional
     * parameter can still default (PHP requires defaulted parameters to
     * trail, and $viewerid must never default to anything).
     *
     * @param activity $activity the activity
     * @param int $requesterid whose other tickets
     * @param int $viewerid the staff member asking; must pass
     *        require_queue_authority()
     * @param int $excludeticketid the ticket already on screen, left out;
     *        0 to exclude nothing
     * @return stdClass[] ticket rows, each with groupname and
     *         grouppluginuid, newest first
     * @throws \required_capability_exception when the viewer lacks
     *         queue authority
     */
    public static function history(
        activity $activity,
        int $requesterid,
        int $viewerid,
        int $excludeticketid = 0
    ): array {
        global $DB;

        self::require_queue_authority($activity, $viewerid);

        return $DB->get_records_sql(
            "SELECT t.*, g.name AS groupname, g.pluginuid AS grouppluginuid
               FROM {selfselectadvanced_ticket} t
          LEFT JOIN {selfselectadvanced_group} g ON g.id = t.groupid
              WHERE t.activityid = :activityid AND t.requestedby = :userid AND t.id <> :excludeid
           ORDER BY t.timecreated DESC, t.id DESC",
            [
                'activityid' => $activity->id(),
                'userid' => $requesterid,
                'excludeid' => $excludeticketid,
            ]
        );
    }

    /**
     * How many tickets one person has filed.
     *
     * @param activity $activity the activity
     * @param int $userid the requester
     * @param string $type self::TYPE_*, or '' for every type (1.20.57
     *        deliverable A/C: same criteria as mine() itself, called
     *        with them, so myrequests.php's paging bar is never wrong
     *        about a filtered list's page count)
     * @param string $status self::STATUS_*, or '' for every status
     * @param string $search free text, same as mine()'s own parameter
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function mine_count(
        activity $activity,
        int $userid,
        string $type = '',
        string $status = '',
        string $search = ''
    ): int {
        global $DB;

        self::validate_type_filter($type);
        self::validate_status_filter($status);

        $params = ['activityid' => $activity->id(), 'userid' => $userid];
        $typesql = '';
        if ($type !== '') {
            $typesql = ' AND t.type = :type';
            $params['type'] = $type;
        }
        $statussql = '';
        if ($status !== '') {
            $statussql = ' AND t.status = :status';
            $params['status'] = $status;
        }
        // Mirrors mine()'s own WHERE fragment exactly (deliverable C).
        [$searchsql, $searchparams] = self::mine_search_condition($search);
        $params = array_merge($params, $searchparams);

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid AND t.requestedby = :userid" . $typesql . $statussql . $searchsql,
            $params
        );
    }

    /**
     * How many of one person's tickets are in needsinfo - waiting on
     * THEIR reply, not merely on the queue (1.20.53 deliverable B): the
     * landing page's highlighted "N need your reply" line, so a
     * requester who never opens myrequests.php still learns a question
     * is waiting for them.
     *
     * @param activity $activity the activity
     * @param int $userid the requester
     * @return int
     */
    public static function mine_needsinfo_count(activity $activity, int $userid): int {
        global $DB;

        return $DB->count_records('selfselectadvanced_ticket', [
            'activityid' => $activity->id(),
            'requestedby' => $userid,
            'status' => self::STATUS_NEEDSINFO,
        ]);
    }

    /**
     * How many OPEN tickets precede a given offset in the queue.
     *
     * The queue numbers open tickets 1, 2, 3 for the people waiting in
     * it, and that numbering has to stay true on page two.
     *
     * UNTIL audit A7 (2026-08-20) this assumed open tickets sort first,
     * so the count of open ones before an offset was simply the smaller
     * of the offset and the total number open. 1.20.44 falsified that
     * premise: queue()'s ORDER BY sorts a LIVE ESCALATED ticket that is
     * NOT open (claimed or needsinfo - escalate() only leaves a claim in
     * place for a manage-level holder) ahead of every ordinary open one.
     * A run of those before the offset displaces opens the old formula
     * still counted as if they were open, inflating the position on
     * every page after the one holding them.
     *
     * Corrected by counting what actually precedes the offset instead of
     * assuming: of the first $limitfrom physical rows, at most
     * escalated_live_nonopen_count() of them are the escalated non-open
     * tickets queue() sorts ahead of the opens, so that many fewer of
     * the offset's own rows can be open ones - and the total can never
     * exceed count_open() regardless, exactly as before.
     *
     * Slice C2: $type/$status are the page's ACTIVE filter, not a
     * separate query - a filter is a WHERE clause, it does not touch the
     * ordering, so min(offset, count_open(same filter)) - escalated
     * count (same filter) stays the right position count for that
     * filtered page too. Passed straight through to count_open() and
     * escalated_live_nonopen_count(); left at '' this is exactly the old
     * unfiltered call.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, 0 for no filtering
     * @param int $limitfrom the page offset
     * @param string $type self::TYPE_*, or '' for every type (slice C2 triage filter)
     * @param string $status self::STATUS_*, or '' for every status (slice C2 triage filter)
     * @param string $search free text, same as queue()'s own parameter
     *        (1.20.57 deliverable B: a search is a WHERE clause too, so
     *        it must narrow the position count exactly like $type/$status
     *        already do, or the Position column would lie on page two of
     *        a searched queue)
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function open_before(
        activity $activity,
        int $viewerid,
        int $limitfrom,
        string $type = '',
        string $status = '',
        string $search = ''
    ): int {
        $open = self::count_open($activity, $viewerid, $type, $status, $search);
        $escalatednonopen = self::escalated_live_nonopen_count($activity, $viewerid, $type, $status, $search);

        return min($open, max(0, $limitfrom - $escalatednonopen));
    }

    /**
     * How many LIVE tickets are both escalated AND not open - claimed or
     * needsinfo, escalated = 1 (audit A7, 2026-08-20).
     *
     * queue()'s ORDER BY sorts every one of these ahead of every
     * ordinary (non-escalated) open ticket, so open_before() subtracts
     * this count from the offset before asking how many opens it could
     * possibly hold - correcting exactly the audited scenario (a live
     * escalated ticket that is CLAIMED or NEEDSINFO displacing opens on
     * page two).
     *
     * KNOWN NARROW GAP, left open rather than papered over: escalate()
     * also permits a manage-level holder to escalate a ticket that is
     * still OPEN (unclaimed), and that row sorts ahead of the ordinary
     * opens too while itself counting as one of count_open()'s opens.
     * This method deliberately does not add it to the subtraction (doing
     * so would over-subtract once the offset runs past that ticket), but
     * a page whose offset lands INSIDE a run of escalated-open tickets
     * can still under-count by a small, self-correcting amount until the
     * offset clears that run. Out of scope for audit A7, whose fixture
     * and fix both concern an escalated ticket that is NOT open.
     *
     * Same $mine/$type/$status filters as count_open(), so the two stay
     * comparable under the same page filter.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, 0 for no filtering
     * @param string $type self::TYPE_*, or '' for every type (slice C2 triage filter)
     * @param string $status self::STATUS_*, or '' for every status (slice C2 triage filter)
     * @param string $search free text, same as queue()'s own parameter (1.20.57 deliverable B)
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    private static function escalated_live_nonopen_count(
        activity $activity,
        int $viewerid = 0,
        string $type = '',
        string $status = '',
        string $search = ''
    ): int {
        global $DB;

        self::validate_type_filter($type);
        self::validate_status_filter($status);

        $params = ['activityid' => $activity->id()];
        $mine = '';
        if ($viewerid > 0 && !has_capability('mod/selfselectadvanced:manage', $activity->context(), $viewerid)) {
            $mine = ' AND t.requestedby <> :viewerid';
            $params['viewerid'] = $viewerid;
        }
        $typesql = '';
        if ($type !== '') {
            $typesql = ' AND t.type = :type';
            $params['type'] = $type;
        }
        $statussql = '';
        if ($status !== '') {
            $statussql = ' AND t.status = :status';
            $params['status'] = $status;
        }
        [$searchsql, $searchparams] = self::queue_search_condition($search);
        $params = array_merge($params, $searchparams);

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid AND t.escalated = 1
                    AND t.status IN ('claimed', 'needsinfo')" . $mine . $typesql . $statussql . $searchsql,
            $params
        );
    }

    /**
     * How many tickets are still waiting for somebody to take them up.
     *
     * A count, because the pages that want this number wanted only this
     * number and were fetching the entire queue to arrive at it.
     *
     * Slice C2: $status is ANDed with the existing status = open
     * restriction below, not substituted for it - so a $status other
     * than '' or self::STATUS_OPEN can only ever match zero rows here.
     * That is correct, not a bug: on a page filtered to, say, resolved
     * tickets, nothing is ever "open" to number, so open_before() above
     * must return 0 for it, and it does without any special case.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, 0 for no filtering
     * @param string $type self::TYPE_*, or '' for every type (slice C2 triage filter)
     * @param string $status self::STATUS_*, or '' for every status (slice C2 triage filter)
     * @param string $search free text, same as queue()'s own parameter (1.20.57 deliverable B)
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function count_open(
        activity $activity,
        int $viewerid = 0,
        string $type = '',
        string $status = '',
        string $search = ''
    ): int {
        global $DB;

        self::validate_type_filter($type);
        self::validate_status_filter($status);

        $params = ['activityid' => $activity->id(), 'open' => self::STATUS_OPEN];
        $mine = '';
        if ($viewerid > 0 && !has_capability('mod/selfselectadvanced:manage', $activity->context(), $viewerid)) {
            $mine = ' AND t.requestedby <> :viewerid';
            $params['viewerid'] = $viewerid;
        }
        $typesql = '';
        if ($type !== '') {
            $typesql = ' AND t.type = :type';
            $params['type'] = $type;
        }
        $statussql = '';
        if ($status !== '') {
            $statussql = ' AND t.status = :status';
            $params['status'] = $status;
        }
        [$searchsql, $searchparams] = self::queue_search_condition($search);
        $params = array_merge($params, $searchparams);

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid AND t.status = :open" . $mine . $typesql . $statussql . $searchsql,
            $params
        );
    }

    /**
     * How many tickets this person is CURRENTLY the claimant of
     * (1.20.53 deliverable B): the landing page's direct route states
     * this beside the queue's own waiting count, so a coordinator or
     * manager reads their whole position - what is waiting, and what is
     * theirs - without a dashboard detour.
     *
     * CLAIMED and NEEDSINFO both count, the same LIVENESS pairing
     * decision 2 established everywhere else in this class: the
     * claimant does not stop being the claimant while a question sits
     * with the requester, so tickets.php's own $isworked/$mine test and
     * this count must never disagree about which tickets are "theirs".
     *
     * @param activity $activity the activity
     * @param int $userid the claimant
     * @return int
     */
    public static function handling_count(activity $activity, int $userid): int {
        global $DB;

        [$statussql, $statusparams] = $DB->get_in_or_equal(
            [self::STATUS_CLAIMED, self::STATUS_NEEDSINFO],
            SQL_PARAMS_NAMED,
            'st'
        );

        return $DB->count_records_select(
            'selfselectadvanced_ticket',
            "activityid = :activityid AND claimedby = :userid AND status $statussql",
            array_merge(['activityid' => $activity->id(), 'userid' => $userid], $statusparams)
        );
    }

    /**
     * The JOIN every "what did the trail last say" query in this class
     * shares: the single most recent {selfselectadvanced_ticketlog} row
     * for the ticket aliased $ticketalias, by id.
     *
     * MAX(id) agrees with trail()'s own tie-break order (timecreated,
     * then id) because ids only ever increase - this only ever needs the
     * LAST row, never the whole ordered trail trail() builds, so it
     * stays its own small query rather than a trail() call per ticket,
     * exactly the query-per-row shape this file avoids everywhere else.
     *
     * @param string $ticketalias the ticket table's alias in the caller's FROM clause
     * @return string
     */
    private static function last_log_join(string $ticketalias): string {
        return "JOIN {selfselectadvanced_ticketlog} l ON l.id = (
                    SELECT MAX(l2.id) FROM {selfselectadvanced_ticketlog} l2
                     WHERE l2.ticketid = $ticketalias.id
                )";
    }

    /**
     * How many tickets this claimant is handling where the ball is
     * actually back in THEIR court (1.20.53 deliverable C): still
     * claimed, but the last trail row is the requester's own inforeply -
     * they answered the question and nobody has acted since.
     *
     * Derived from the trail, never a new column: trail() already exists
     * for reading one ticket's history; this asks the identical question
     * in bulk, across a claimant's whole handling list, with one SQL
     * statement rather than a fetch-and-filter (coordinator.php's own
     * comment on count_open() is the rule this follows: only the number
     * is wanted, so only the number is fetched).
     *
     * NO READ/UNREAD TRACKING. This is not "has the claimant looked at
     * the reply" - this plugin has no per-user read state and this
     * release is not buying one - it is "did the requester speak last on
     * a ticket that is still claimed", which the trail already records.
     *
     * @param activity $activity the activity
     * @param int $userid the claimant
     * @return int
     */
    public static function handling_awaiting_reply_count(activity $activity, int $userid): int {
        global $DB;

        return $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {selfselectadvanced_ticket} t
               " . self::last_log_join('t') . "
              WHERE t.activityid = :activityid AND t.claimedby = :userid
                    AND t.status = :claimed AND l.action = :inforeply",
            [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'claimed' => self::STATUS_CLAIMED,
                'inforeply' => self::ACTION_INFOREPLY,
            ]
        );
    }

    /**
     * The bulk form of handling_awaiting_reply_count()'s own condition,
     * for a PAGE marking rows rather than counting them (1.20.53
     * deliverable C: "must say so ... in the staff queue"): which of
     * these ticket ids are claimed with the requester's inforeply as the
     * last trail row, in one query for the whole page rather than one
     * trail() call per row.
     *
     * @param activity $activity the activity
     * @param int[] $ticketids candidate ticket ids
     * @return int[] the subset that is awaiting its claimant, as ids
     */
    public static function awaiting_claimant_ids(activity $activity, array $ticketids): array {
        global $DB;

        $ticketids = array_values(array_unique(array_map('intval', $ticketids)));
        if (!$ticketids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ticketids, SQL_PARAMS_NAMED, 'tid');
        $params['activityid'] = $activity->id();
        $params['claimed'] = self::STATUS_CLAIMED;
        $params['inforeply'] = self::ACTION_INFOREPLY;

        $rows = $DB->get_records_sql(
            "SELECT t.id
               FROM {selfselectadvanced_ticket} t
               " . self::last_log_join('t') . "
              WHERE t.id $insql AND t.activityid = :activityid
                    AND t.status = :claimed AND l.action = :inforeply",
            $params
        );

        return array_map('intval', array_keys($rows));
    }

    /**
     * Whether a CLAIMED ticket's trail's last row is the requester's own
     * inforeply - the ball is back in the claimant's court, rather than
     * merely "being handled" (1.20.53's own question). THE ONE PLACE
     * this predicate is stated in PHP: 1.20.54's whose_move_claimed_line()
     * (classes/output/ticket_page.php) and 1.20.58's staff_wait_since()
     * below both call this rather than re-deriving it (spec: "the
     * whose-move derivation is REUSED from the existing helper, not
     * copied"). awaiting_claimant_ids() above and staff_wait_since_map()
     * below still state the identical condition a second time, in SQL -
     * a JOIN cannot call back into a PHP method - but that is the one
     * boundary this cannot be reused across; PHP callers all funnel
     * through here.
     *
     * @param string $status self::STATUS_* - callers pass the ticket's
     *        own status rather than this method re-reading it off
     *        $lastrow, which carries no ticket id at all; anything other
     *        than STATUS_CLAIMED is always false by definition (the ball
     *        is never "back with the claimant" on an open, needsinfo or
     *        closed ticket)
     * @param stdClass|null $lastrow the ticket's last trail row (only
     *        ->action is read - present on both trail() branches), or
     *        null for a ticket logged with no rows at all
     * @return bool
     */
    public static function is_awaiting_claimant_reply(string $status, ?stdClass $lastrow): bool {
        return $status === self::STATUS_CLAIMED && $lastrow !== null && $lastrow->action === self::ACTION_INFOREPLY;
    }

    /**
     * The staff clock's start time for ONE ticket (1.20.58 deliverable
     * B) - the 1.20.54 whose-move rule, reused rather than re-derived a
     * second time (spec, verbatim: "the whose-move derivation is REUSED
     * from the existing helper, not copied"):
     *
     * - open: since it was filed;
     * - claimed, with the requester's own inforeply as the trail's last
     *   row (is_awaiting_claimant_reply() above): since that reply;
     * - claimed otherwise: since it was claimed;
     * - needsinfo: the ball is with the REQUESTER, so no staff clock runs;
     * - resolved/declined/withdrawn: closed, no clock.
     *
     * Takes data the caller already has in hand - never a query here -
     * so ticket_page.php's thread (one ticket, its trail already
     * fetched by export_for_template()'s own loop) can call this
     * directly. A page holding many tickets on one screen (the staff
     * queue, a requester's own list) must call staff_wait_since_map()
     * below instead, which asks the identical question in bulk rather
     * than once per row.
     *
     * @param stdClass $ticket the ticket row (status, timecreated,
     *        timeclaimed read)
     * @param stdClass|null $lastrow the ticket's last trail row (only
     *        ->action and ->timecreated are read), or null
     * @return int|null unix timestamp the staff clock started, or null
     *         when no staff clock is running for this ticket right now
     */
    public static function staff_wait_since(stdClass $ticket, ?stdClass $lastrow): ?int {
        if ($ticket->status === self::STATUS_OPEN) {
            return (int) $ticket->timecreated;
        }
        if ($ticket->status === self::STATUS_CLAIMED) {
            return self::is_awaiting_claimant_reply($ticket->status, $lastrow)
                ? (int) $lastrow->timecreated
                : (int) $ticket->timeclaimed;
        }

        // Needsinfo, resolved, declined, withdrawn: no staff clock at all.
        return null;
    }

    /**
     * The bulk form of staff_wait_since() above, for a PAGE showing many
     * tickets at once (1.20.58 deliverable B: "the age must be computed
     * from rows already fetched ... do not add a query per row"). One
     * SQL statement joins each CLAIMED ticket's own last trail row -
     * last_log_join(), the identical join awaiting_claimant_ids() above
     * already uses for the same shape of question - rather than a
     * trail() or staff_wait_since() call per row.
     *
     * open tickets need no join at all: the answer is timecreated, which
     * the caller's own $tickets rows already carry. needsinfo and closed
     * tickets need no join either: the answer is the constant null. Only
     * the CLAIMED subset of the page ever reaches the database here, and
     * that subset is fetched in exactly one query regardless of how many
     * of them there are.
     *
     * @param activity $activity the activity
     * @param stdClass[] $tickets ticket rows already fetched by the
     *        caller (queue()/mine()) - only ->id, ->status, ->timecreated
     *        and ->timeclaimed are read
     * @return array<int, int|null> ticketid => unix timestamp the staff
     *         clock started, or null when no staff clock runs for that ticket
     */
    public static function staff_wait_since_map(activity $activity, array $tickets): array {
        global $DB;

        $result = [];
        $claimed = [];
        foreach ($tickets as $ticket) {
            $id = (int) $ticket->id;
            if ($ticket->status === self::STATUS_OPEN) {
                $result[$id] = (int) $ticket->timecreated;
            } else if ($ticket->status === self::STATUS_CLAIMED) {
                $claimed[$id] = $ticket;
            } else {
                // Needsinfo, resolved, declined, withdrawn: no staff clock.
                $result[$id] = null;
            }
        }
        if (!$claimed) {
            return $result;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($claimed), SQL_PARAMS_NAMED, 'tid');
        $params['activityid'] = $activity->id();
        $rows = $DB->get_records_sql(
            "SELECT t.id, l.action AS lastaction, l.timecreated AS lasttime
               FROM {selfselectadvanced_ticket} t
               " . self::last_log_join('t') . "
              WHERE t.id $insql AND t.activityid = :activityid",
            $params
        );
        foreach ($claimed as $id => $ticket) {
            $row = $rows[$id] ?? null;
            // The SAME condition is_awaiting_claimant_reply() states in
            // PHP (see that method's own docblock for why this is
            // expressed a second time, in SQL, rather than reused).
            $result[$id] = ($row !== null && $row->lastaction === self::ACTION_INFOREPLY)
                ? (int) $row->lasttime
                : (int) $ticket->timeclaimed;
        }

        return $result;
    }

    /**
     * Deliverable C: overdue means the activity SET a target AND the
     * staff clock (staff_wait_since()/staff_wait_since_map() above) has
     * run past it.
     *
     * A target of 0 ("no target set") must change nothing anywhere - the
     * deliverable's own stated requirement, since every activity reads 0
     * until this is deliberately changed - so a non-positive target
     * always answers false, checked BEFORE $waitsince is even read: a
     * ticket with no staff clock running (null) can never be overdue
     * either way, but the target is checked first so the two negatives
     * (no target, no clock) are never conflated into one code path that
     * could accidentally start reading $waitsince before confirming a
     * target exists at all.
     *
     * @param int|null $waitsince staff_wait_since()'s own return, or
     *        null when no staff clock is running (never overdue)
     * @param int $targethours the activity's tickettargethours setting
     * @return bool
     */
    public static function is_overdue(?int $waitsince, int $targethours): bool {
        if ($targethours <= 0 || $waitsince === null) {
            return false;
        }

        return (time() - $waitsince) > $targethours * HOURSECS;
    }

    /**
     * Unwind a transaction that an exception is escaping from.
     *
     * Moodle expects the exception to be handed to rollback(), which
     * re-throws it. Skipping that leaves the transaction open for the
     * rest of the request - Moodle then reports "active database
     * transaction detected during request shutdown" and force-rolls it
     * back, discarding any buffered events and messages with it. The
     * refusals in this class are thrown from inside their transactions
     * by design, so they all leave through here.
     *
     * EVERY frame this class opens is rolled back, whoever owns the one
     * underneath. Until 1.20 wave 3E an $outermost flag - read from
     * $DB->is_transaction_started() before the lock - skipped the
     * rollback whenever a caller already held a transaction, and the
     * paragraph that stood here said that was "exactly as Moodle
     * intends". It is the opposite of what core does.
     *
     * rollback_delegated_transaction() (lib/dml/moodle_database.php)
     * disposes the frame it is given, sets force_rollback, and issues
     * the physical ROLLBACK only for the frame on TOP of $DB's stack;
     * an inner frame simply pops and lets the cascade continue
     * downwards. Abandoning our frame instead leaves it on top and
     * undisposed, so the CALLER's rollback() fails that identity check,
     * takes the "better just rethrow" branch, and never rolls anything
     * back: the caller's writes survive a refusal it believed it had
     * unwound, and commit_delegated_transaction() then throws for every
     * later commit in the request. The "poisons the connection" fear
     * was force_rollback doing its documented job.
     *
     * The flag was also not a fact about this class:
     * advanced_testcase opens a transaction before every test on
     * PostgreSQL and none on MariaDB, so it read false on m5pg and true
     * on m5my and neither engine ever tried the other arm.
     *
     * @param \moodle_transaction|null $transaction the transaction, if it was reached
     * @param \Throwable $e the exception on its way out
     * @throws \Throwable always - $e, after any rollback
     */
    private static function rollback(?\moodle_transaction $transaction, \Throwable $e): void {
        if ($transaction !== null && !$transaction->is_disposed()) {
            // Re-throws $e itself.
            $transaction->rollback($e);
        }

        throw $e;
    }

    /**
     * Append one row to a ticket's history trail (maintainer decision 1,
     * 2026-08-15).
     *
     * ONE PLACE THIS TABLE IS WRITTEN. Every transition above calls this
     * INSIDE its own transaction and BEFORE allow_commit() - the same
     * discipline the ticket row's own write already keeps - so the
     * trail can never record a step whose ticket-row write did not also
     * commit, and can never be missing one that did.
     *
     * @param int $ticketid the ticket
     * @param int $actorid who performed the action
     * @param string $action one of self::ACTION_*
     * @param string|null $note the question, the reply, or the closing
     *        note; null for a bare transition (filed, claimed, released,
     *        withdrawn)
     * @param int $noteformat text format of $note - stored even when
     *        $note is null, exactly as resolutionformat is on the
     *        ticket row itself
     * @return int the inserted row's id (B2, decision addendum item 1):
     *         every event this class fires now carries `ticketlogid` in
     *         its `other` payload, so a logged event can be joined back
     *         to the exact stored text - which needs this method to
     *         hand the id back rather than write and forget it.
     */
    private static function log(int $ticketid, int $actorid, string $action, ?string $note, int $noteformat): int {
        global $DB;

        return $DB->insert_record('selfselectadvanced_ticketlog', (object) [
            'ticketid' => $ticketid,
            'actorid' => $actorid,
            'action' => $action,
            'note' => $note,
            'noteformat' => $noteformat,
            'timecreated' => time(),
        ]);
    }

    /**
     * Record that a ticket was published to the knowledgebank (1.20.45),
     * for kb::publish_from_ticket() - log() itself stays private (the
     * docblock above it is emphatic: "ONE PLACE THIS TABLE IS WRITTEN"),
     * so a caller outside this class gets this one narrow door rather
     * than a second insert path. No note: the maintainer's own words are
     * "no public link back", and ACTION_PUBLISHED_FAQ is staff-internal
     * (STAFF_INTERNAL_ACTIONS) precisely so this row never reaches the
     * requester's anonymised trail regardless.
     *
     * @param int $ticketid the resolved ticket that was published
     * @param int $actorid who published it
     * @return int the inserted ticketlog row id
     */
    public static function note_published_faq(int $ticketid, int $actorid): int {
        return self::log($ticketid, $actorid, self::ACTION_PUBLISHED_FAQ, null, FORMAT_PLAIN);
    }

    /**
     * The team a ticket names in its notifications, or the "no team"
     * placeholder for a guidecap/guidereduce request.
     *
     * NOT used by notify() below: every caller of notify() already
     * holds the group row it would otherwise re-fetch here (notify()
     * takes $group as a parameter for exactly that reason - repeating
     * this lookup once per recipient in notify_workers()'s loop would
     * be a query per worker for a value the caller already has). This
     * exists for request_info() and provide_info(), which notify
     * exactly one fixed recipient each and have no group already in
     * hand at the point they need this string.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @return string
     */
    private static function subject_name(activity $activity, stdClass $ticket): string {
        $group = self::group_of($activity, $ticket);

        return $group !== null
            ? format_string($group->name)
            : get_string('tickethasnoteam', 'mod_selfselectadvanced');
    }

    /**
     * Send one queue notification (provider tickets).
     *
     * @param activity $activity the activity
     * @param int $touserid recipient
     * @param string $subjectkey subject string key
     * @param string $bodykey body string key
     * @param stdClass $ticket the ticket
     * @param stdClass|null $group its team, or null for a request that is not about one
     */
    private static function notify(
        activity $activity,
        int $touserid,
        string $subjectkey,
        string $bodykey,
        stdClass $ticket,
        ?stdClass $group
    ): void {
        $subject = $group !== null
            ? format_string($group->name)
            : get_string('tickethasnoteam', 'mod_selfselectadvanced');

        // WHERE THE LINK GOES used to depend on who was reading: the
        // queue is staff-only (tickets.php requires manage or
        // coordinate), and for a while this method sent its URL to
        // everyone - including the requester, who is refused at that
        // door. So the message that exists BECAUSE the requester cannot
        // open the queue linked them to the queue: a student was told
        // their request had been picked up, followed the link, and was
        // refused. That was the whole of "a response cannot be viewed".
        //
        // B2 (deliverable 3): every recipient now goes to the ticket's
        // OWN thread (ticket.php), staff and requester alike. It admits
        // both (the access rule is "the requester, OR queue authority"),
        // it carries the whole conversation rather than a status line,
        // and it replaces the requester-vs-staff branch this method used
        // to need: nobody is sent to a door that refuses them, because
        // there is only the one door now.
        notifier::send(
            $activity,
            'tickets',
            $touserid,
            $subjectkey,
            $bodykey,
            (object) [
                'group' => $subject,
                'type' => get_string('tickettype' . $ticket->type, 'mod_selfselectadvanced'),
                'status' => get_string('ticketstatus' . $ticket->status, 'mod_selfselectadvanced'),
                // 1.20.56 deliverable B: the quotable reference, in every
                // msgticket* subject - the ticket's own pluginuid column,
                // never re-derived. No actor identity travels alongside
                // it here (the contact-privacy rule applies to a
                // notification exactly as it does to the screen): this
                // object carries a group name, a type/status label and
                // the WORDS somebody wrote, never a fullname().
                'pluginuid' => (string) ($ticket->pluginuid ?? ''),
                // Only msgticketfiledbody references this placeholder -
                // every other subject/body pair here already carries its
                // own written text (resolution/note/question/reply) from
                // its own call site - but it costs nothing to resolve for
                // the others too, and a site's Language customisation
                // override could always choose to use it.
                'request' => trim(html_to_text((string) ($ticket->request ?? ''))),
                // Kept even though the thread now shows the resolution
                // too: a message may be read on a device with no
                // browser session at hand, and the outcome should not
                // depend on following the link.
                'resolution' => trim(html_to_text((string) ($ticket->resolution ?? ''))),
            ],
            new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticket->id]),
            $subject
        );
    }
}
