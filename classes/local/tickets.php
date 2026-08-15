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
     * @return stdClass the ticket row
     * @throws \moodle_exception when a gate refuses
     */
    public static function file(
        activity $activity,
        stdClass $group,
        string $type,
        string $request,
        int $requestformat,
        int $userid
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
                'groupid' => (int) $group->id,
                'type' => $type,
                'status' => self::STATUS_OPEN,
                'requestedby' => $userid,
                'request' => $request,
                'requestformat' => $requestformat,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $ticket->id = $DB->insert_record('selfselectadvanced_ticket', $ticket);

            // The request text already lives on the ticket row, so the
            // trail's own note is null - the filed action itself is
            // what the trail records, not a second copy of the words.
            // Logged BEFORE the event (B2, addendum item 1): the
            // event's other.ticketlogid names this very row, so the row
            // has to exist first.
            $ticketlogid = self::log($ticket->id, $userid, self::ACTION_FILED, null, FORMAT_PLAIN);

            \mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => $type,
                    'pluginuid' => $group->pluginuid,
                    'action' => self::ACTION_FILED,
                    'groupid' => (int) $group->id,
                    'ticketlogid' => $ticketlogid,
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

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

            $ticketlogid = self::log($ticket->id, $userid, self::ACTION_FILED, null, FORMAT_PLAIN);

            \mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => self::TYPE_GUIDECAP,
                    'pluginuid' => '',
                    'action' => self::ACTION_FILED,
                    'groupid' => 0,
                    'ticketlogid' => $ticketlogid,
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

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

            $ticketlogid = self::log($ticket->id, $userid, self::ACTION_FILED, null, FORMAT_PLAIN);

            \mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => [
                    'type' => self::TYPE_GUIDEREDUCE,
                    'pluginuid' => '',
                    'action' => self::ACTION_FILED,
                    'groupid' => 0,
                    'ticketlogid' => $ticketlogid,
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        self::notify_workers($activity, $ticket, null);

        return $ticket;
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
        if ($ticket->status !== self::STATUS_CLAIMED) {
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
            \mod_selfselectadvanced\event\ticket_closed::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'other' => [
                    'type' => $fresh->type,
                    'outcome' => self::STATUS_WITHDRAWN,
                    'action' => self::ACTION_WITHDRAWN,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        return $fresh;
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

            \mod_selfselectadvanced\event\ticket_claimed::create([
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
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        self::notify($activity, (int) $claimed->requestedby, 'msgticketclaimedsubject', 'msgticketclaimedbody', $claimed, $group);

        return $claimed;
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
            \mod_selfselectadvanced\event\ticket_closed::create([
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
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

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

        // The emptiness idiom file() and close() both use: a question
        // that says nothing is not a question a requester could answer.
        if (trim(html_to_text($question)) === '') {
            throw new workflow_refusal('refusalticketreason', 'mod_selfselectadvanced');
        }

        // Lock + transaction + re-read + rollback discipline copied
        // from claim(): the authority question here is entirely about
        // RECORD OWNERSHIP (this ticket's claimant, whoever that is
        // right now), so unlike close() there is no separate queue-
        // authority re-ask before the lock - the claim itself already
        // proved that once, and nothing here grants a NEW authority a
        // stale read could get wrong.
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

            \mod_selfselectadvanced\event\ticket_info_requested::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'relateduserid' => (int) $fresh->requestedby,
                'other' => [
                    'type' => $fresh->type,
                    'action' => self::ACTION_NEEDSINFO,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

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

            \mod_selfselectadvanced\event\ticket_info_provided::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'relateduserid' => (int) $fresh->claimedby,
                'other' => [
                    'type' => $fresh->type,
                    'action' => self::ACTION_INFOREPLY,
                    'groupid' => (int) ($fresh->groupid ?? 0),
                    'ticketlogid' => $ticketlogid,
                ],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

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
                'reply' => trim(html_to_text($reply)),
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
            return $DB->get_records_sql(
                "SELECT l.id, l.action, l.note, l.noteformat, l.timecreated
                   FROM {selfselectadvanced_ticketlog} l
                  WHERE l.ticketid = :ticketid
               ORDER BY l.timecreated, l.id",
                ['ticketid' => $ticketid]
            );
        }

        // The name fields fullname() needs, joined in rather than
        // resolved with a get_user() call per row - the trail of a
        // long-handled ticket can hold a dozen rows, and this plugin's
        // house rule against a query per row applies here exactly as it
        // does to notify_workers()'s recipient loop.
        $rows = $DB->get_records_sql(
            "SELECT l.id, l.action, l.note, l.noteformat, l.timecreated, l.actorid,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename
               FROM {selfselectadvanced_ticketlog} l
               JOIN {user} u ON u.id = l.actorid
              WHERE l.ticketid = :ticketid
           ORDER BY l.timecreated, l.id",
            ['ticketid' => $ticketid]
        );
        foreach ($rows as $row) {
            $row->actorname = fullname($row);
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
        $known = [
            self::TYPE_COMPCHANGE,
            self::TYPE_UNFREEZE,
            self::TYPE_GUIDECAP,
            self::TYPE_GUIDEGONE,
            self::TYPE_GUIDEREDUCE,
            self::TYPE_DATES,
            self::TYPE_PENALTY,
            self::TYPE_LEADERCHANGE,
        ];
        if (!in_array($type, $known, true)) {
            throw new \coding_exception('Unknown ticket type filter ' . $type);
        }
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
     * @return stdClass[] ticket rows
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function queue(
        activity $activity,
        int $viewerid = 0,
        int $limitfrom = 0,
        int $limitnum = 0,
        string $type = '',
        string $status = ''
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
              WHERE t.activityid = :activityid" . $mine . $typesql . $statussql . "
           ORDER BY CASE t.status
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
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function queue_count(activity $activity, int $viewerid = 0, string $type = '', string $status = ''): int {
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

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid" . $mine . $typesql . $statussql,
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
     * @return array<int, stdClass> ticket rows, each with groupname and grouppluginuid
     */
    public static function mine(activity $activity, int $userid, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;

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
              WHERE t.activityid = :activityid AND t.requestedby = :userid
           ORDER BY CASE t.status
                        WHEN 'needsinfo' THEN 0
                        WHEN 'open' THEN 1
                        WHEN 'claimed' THEN 2
                        ELSE 3
                    END,
                    t.timecreated DESC,
                    t.id DESC",
            ['activityid' => $activity->id(), 'userid' => $userid],
            $limitfrom,
            $limitnum
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
     * @return int
     */
    public static function mine_count(activity $activity, int $userid): int {
        global $DB;

        return $DB->count_records('selfselectadvanced_ticket', [
            'activityid' => $activity->id(),
            'requestedby' => $userid,
        ]);
    }

    /**
     * How many OPEN tickets precede a given offset in the queue.
     *
     * The queue numbers open tickets 1, 2, 3 for the people waiting in
     * it, and that numbering has to stay true on page two. Open tickets
     * sort first, so the count of open ones before an offset is simply
     * the smaller of the offset and the total number open.
     *
     * Slice C2: $type/$status are the page's ACTIVE filter, not a
     * separate query - a filter is a WHERE clause, it does not touch the
     * ordering, so "open still sorts first" stays true under it and
     * min(offset, count_open(same filter)) stays the right position
     * count for that filtered page too. Passed straight through to
     * count_open(); left at '' this is exactly the old unfiltered call.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, 0 for no filtering
     * @param int $limitfrom the page offset
     * @param string $type self::TYPE_*, or '' for every type (slice C2 triage filter)
     * @param string $status self::STATUS_*, or '' for every status (slice C2 triage filter)
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function open_before(
        activity $activity,
        int $viewerid,
        int $limitfrom,
        string $type = '',
        string $status = ''
    ): int {
        return min($limitfrom, self::count_open($activity, $viewerid, $type, $status));
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
     * @return int
     * @throws \coding_exception if $type or $status is not empty and not a known constant
     */
    public static function count_open(activity $activity, int $viewerid = 0, string $type = '', string $status = ''): int {
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

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid AND t.status = :open" . $mine . $typesql . $statussql,
            $params
        );
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
