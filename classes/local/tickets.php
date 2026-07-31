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
     *      a team: its groupid is 0 and it carries the number asked for.
     */
    public const TYPE_GUIDECAP = 'guidecap';

    /** @var string Waiting in the queue. */
    public const STATUS_OPEN = 'open';

    /** @var string One manager or coordinator is working it. */
    public const STATUS_CLAIMED = 'claimed';

    /** @var string Done, with a resolution note. */
    public const STATUS_RESOLVED = 'resolved';

    /** @var string Refused, with the reason. */
    public const STATUS_DECLINED = 'declined';

    /** @var string Taken back by the requester while still open. */
    public const STATUS_WITHDRAWN = 'withdrawn';

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

        if (!in_array($type, [self::TYPE_COMPCHANGE, self::TYPE_UNFREEZE], true)) {
            throw new \coding_exception('Unknown ticket type ' . $type);
        }
        if (trim(html_to_text($request)) === '') {
            throw new \moodle_exception('refusalticketreason', 'mod_selfselectadvanced');
        }

        $lock = locks::acquire('group:' . $group->id);
        $outermost = !$DB->is_transaction_started();
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
                throw new \moodle_exception('refusalticketnotguide', 'mod_selfselectadvanced');
            }
            if ($type === self::TYPE_UNFREEZE && !$isguide && !$isleader) {
                throw new \moodle_exception('refusalticketnotparty', 'mod_selfselectadvanced');
            }
            if ($type === self::TYPE_UNFREEZE && $group->state !== state::FROZEN) {
                throw new \moodle_exception('refusalwrongstate', 'mod_selfselectadvanced');
            }
            if ($type === self::TYPE_COMPCHANGE && !in_array($group->state, [state::FIRM, state::FROZEN], true)) {
                throw new \moodle_exception('refusalwrongstate', 'mod_selfselectadvanced');
            }

            $live = $DB->get_record_select(
                'selfselectadvanced_ticket',
                "groupid = :groupid AND type = :type AND status IN (:open, :claimed)",
                [
                    'groupid' => $group->id,
                    'type' => $type,
                    'open' => self::STATUS_OPEN,
                    'claimed' => self::STATUS_CLAIMED,
                ]
            );
            if ($live) {
                throw new \moodle_exception('refusalticketduplicate', 'mod_selfselectadvanced', '', (int) $live->id);
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

            \mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => ['type' => $type, 'pluginuid' => $group->pluginuid],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
        } finally {
            $lock->release();
        }

        self::notify_workers($activity, $ticket, $group);

        return $ticket;
    }

    /**
     * A guide asks the coordinators to raise their team limit
     * (strategy 1.18 C).
     *
     * Unlike the other two types this request is about the guide, not a
     * team, so it takes no group lock and stores groupid 0. The number
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
            throw new \moodle_exception('refusalticketreason', 'mod_selfselectadvanced');
        }

        // Asking for nothing, or for less than the activity's own
        // ceiling allows, is not a request anybody can act on.
        $ceiling = (new api($activity))->gatekeeper()->resolver()->guide_capacity_ceiling($userid);
        if ($requested < 1) {
            throw new \moodle_exception('refusalguidecapzero', 'mod_selfselectadvanced');
        }
        if ($requested <= $ceiling->value) {
            throw new \moodle_exception('refusalguidecapnotmore', 'mod_selfselectadvanced', '', $ceiling->value);
        }

        // Serialised on the guide, not on a team: two requests from the
        // same guide race each other and nothing else.
        $lock = locks::acquire('guidecap:' . $userid);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            $live = $DB->get_record_select(
                'selfselectadvanced_ticket',
                "activityid = :activityid AND type = :type AND requestedby = :userid AND status IN (:open, :claimed)",
                [
                    'activityid' => $activity->id(),
                    'type' => self::TYPE_GUIDECAP,
                    'userid' => $userid,
                    'open' => self::STATUS_OPEN,
                    'claimed' => self::STATUS_CLAIMED,
                ]
            );
            if ($live) {
                throw new \moodle_exception('refusalticketduplicate', 'mod_selfselectadvanced', '', (int) $live->id);
            }

            $now = time();
            $ticket = (object) [
                'activityid' => $activity->id(),
                'groupid' => 0,
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

            \mod_selfselectadvanced\event\ticket_filed::create([
                'objectid' => $ticket->id,
                'context' => $activity->context(),
                'other' => ['type' => self::TYPE_GUIDECAP, 'pluginuid' => ''],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
        } finally {
            $lock->release();
        }

        self::notify_workers($activity, $ticket, null);

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
            throw new \moodle_exception('refusalticketnotclaimed', 'mod_selfselectadvanced');
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
            throw new \moodle_exception('refusalticketreason', 'mod_selfselectadvanced');
        }
        if ((int) $ticket->claimedby !== $userid) {
            throw new \moodle_exception(
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
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ((int) $fresh->requestedby !== $userid) {
                throw new \moodle_exception('refusalticketnotyours', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== self::STATUS_OPEN) {
                throw new \moodle_exception('refusalticketclaimed', 'mod_selfselectadvanced', '', $fresh->status);
            }

            $fresh->status = self::STATUS_WITHDRAWN;
            $fresh->timemodified = time();
            $DB->update_record('selfselectadvanced_ticket', $fresh);

            \mod_selfselectadvanced\event\ticket_closed::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'other' => ['type' => $fresh->type, 'outcome' => self::STATUS_WITHDRAWN],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
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
        if ((int) $ticket->groupid <= 0) {
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

        $ticket = self::get($activity, $ticketid);
        // A team-limit request is about a guide, not a team, so there is
        // no team to be involved in; the conflict rule that applies to
        // it is simply that nobody works their own request, which
        // queue() and the claim gate below already enforce.
        $group = self::group_of($activity, $ticket);
        if ($group !== null) {
            self::require_uninvolved($activity, $group, $userid);
        } else if ((int) $ticket->requestedby === $userid) {
            throw new \moodle_exception('refusalcoiself', 'mod_selfselectadvanced');
        }

        $lock = locks::acquire('ticket:' . $ticketid);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ($fresh->status !== self::STATUS_OPEN) {
                throw new \moodle_exception(
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
                throw new \moodle_exception(
                    'refusalticketclaimed',
                    'mod_selfselectadvanced',
                    '',
                    $claimed->claimedby ? fullname(\core_user::get_user((int) $claimed->claimedby)) : $claimed->status
                );
            }

            \mod_selfselectadvanced\event\ticket_claimed::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'other' => ['type' => $claimed->type, 'pluginuid' => $group->pluginuid ?? ''],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
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
            throw new \moodle_exception('refusalticketreason', 'mod_selfselectadvanced');
        }

        $lock = locks::acquire('ticket:' . $ticketid);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== self::STATUS_CLAIMED) {
                throw new \moodle_exception('refusalticketnotclaimed', 'mod_selfselectadvanced');
            }
            $ismanager = has_capability('mod/selfselectadvanced:manage', $activity->context(), $userid);
            if ((int) $fresh->claimedby !== $userid && !($outcome === self::STATUS_OPEN && $ismanager)) {
                throw new \moodle_exception(
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

            \mod_selfselectadvanced\event\ticket_closed::create([
                'objectid' => $ticketid,
                'context' => $activity->context(),
                'other' => ['type' => $fresh->type, 'outcome' => $outcome],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $outermost, $e);
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
     * A direct unfreeze resolves the group's open or claimed unfreeze
     * ticket, so the queue never lists work already done.
     *
     * @param activity $activity the activity
     * @param int $groupid the group just unfrozen
     * @param int $userid who unfroze it
     */
    public static function autoresolve_unfreeze(activity $activity, int $groupid, int $userid): void {
        global $DB;

        $candidate = $DB->get_record_select(
            'selfselectadvanced_ticket',
            "groupid = :groupid AND type = :type AND status IN (:open, :claimed)",
            [
                'groupid' => $groupid,
                'type' => self::TYPE_UNFREEZE,
                'open' => self::STATUS_OPEN,
                'claimed' => self::STATUS_CLAIMED,
            ]
        );
        if (!$candidate) {
            return;
        }

        // Under the same per-ticket lock the claim uses, and re-read
        // inside it: without this a claim landing between the read and
        // the write would be silently overwritten by a whole-row
        // update carrying the stale claimant.
        $lock = locks::acquire('ticket:' . $candidate->id);
        try {
            $live = $DB->get_record('selfselectadvanced_ticket', ['id' => $candidate->id]);
            if (!$live || !in_array($live->status, [self::STATUS_OPEN, self::STATUS_CLAIMED], true)) {
                // Someone closed it while we waited - their outcome stands.
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
        } finally {
            $lock->release();
        }
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
        global $DB;

        if (has_capability('mod/selfselectadvanced:manage', $activity->context(), $userid)) {
            return;
        }
        $involvement = null;
        if ((int) $group->guideid === $userid) {
            $involvement = get_string('coiguide', 'mod_selfselectadvanced');
        } else if ((int) ($group->guidesuccessorid ?? 0) === $userid) {
            $involvement = get_string('coisuccessor', 'mod_selfselectadvanced');
        } else if (
            $DB->record_exists('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $userid,
            'status' => groups::STATUS_CONFIRMED,
            ])
        ) {
            $involvement = get_string('coimember', 'mod_selfselectadvanced');
        }
        if ($involvement !== null) {
            throw new \moodle_exception('refusalcoiinvolved', 'mod_selfselectadvanced', '', $involvement);
        }
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
            throw new \moodle_exception('refusalcoiself', 'mod_selfselectadvanced');
        }
        if ($scope === 'group') {
            self::require_uninvolved($activity, groups::get($activity, $targetid), $userid);
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
     * The queue: open tickets first come first served, then claimed,
     * then closed newest first.
     *
     * @param activity $activity the activity
     * @param int $viewerid the person looking, whose own requests are
     *                      left out unless they hold the manage capability;
     *                      0 for the whole queue
     * @param int $limitfrom first row to return, 0 for the start
     * @param int $limitnum how many rows to return, 0 for all of them
     * @return stdClass[] ticket rows
     */
    public static function queue(
        activity $activity,
        int $viewerid = 0,
        int $limitfrom = 0,
        int $limitnum = 0
    ): array {
        global $DB;

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

        // The team's name comes back with the row rather than being
        // looked up afterwards. The page used to resolve names by
        // loading EVERY group in the activity - fifteen hundred rows to
        // label a screenful of tickets - and this plugin is built for
        // that many teams. A LEFT JOIN because a team-limit request
        // carries groupid = 0 and is about no team at all.
        return $DB->get_records_sql(
            "SELECT t.*, g.name AS groupname, g.pluginuid AS grouppluginuid
               FROM {selfselectadvanced_ticket} t
          LEFT JOIN {selfselectadvanced_group} g ON g.id = t.groupid
              WHERE t.activityid = :activityid" . $mine . "
           ORDER BY CASE t.status
                        WHEN 'open' THEN 0
                        WHEN 'claimed' THEN 1
                        ELSE 2
                    END,
                    CASE WHEN t.status IN ('open','claimed') THEN t.timecreated ELSE -t.timemodified END,
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
     * @return int
     */
    public static function queue_count(activity $activity, int $viewerid = 0): int {
        global $DB;

        $params = ['activityid' => $activity->id()];
        $mine = '';
        if ($viewerid > 0 && !has_capability('mod/selfselectadvanced:manage', $activity->context(), $viewerid)) {
            $mine = ' AND t.requestedby <> :viewerid';
            $params['viewerid'] = $viewerid;
        }

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid" . $mine,
            $params
        );
    }

    /**
     * How many OPEN tickets precede a given offset in the queue.
     *
     * The queue numbers open tickets 1, 2, 3 for the people waiting in
     * it, and that numbering has to stay true on page two. Open tickets
     * sort first, so the count of open ones before an offset is simply
     * the smaller of the offset and the total number open.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, 0 for no filtering
     * @param int $limitfrom the page offset
     * @return int
     */
    public static function open_before(activity $activity, int $viewerid, int $limitfrom): int {
        return min($limitfrom, self::count_open($activity, $viewerid));
    }

    /**
     * How many tickets are still waiting for somebody to take them up.
     *
     * A count, because the pages that want this number wanted only this
     * number and were fetching the entire queue to arrive at it.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer, 0 for no filtering
     * @return int
     */
    public static function count_open(activity $activity, int $viewerid = 0): int {
        global $DB;

        $params = ['activityid' => $activity->id(), 'open' => self::STATUS_OPEN];
        $mine = '';
        if ($viewerid > 0 && !has_capability('mod/selfselectadvanced:manage', $activity->context(), $viewerid)) {
            $mine = ' AND t.requestedby <> :viewerid';
            $params['viewerid'] = $viewerid;
        }

        return $DB->count_records_sql(
            "SELECT COUNT(1) FROM {selfselectadvanced_ticket} t
              WHERE t.activityid = :activityid AND t.status = :open" . $mine,
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
     * Only the OUTERMOST transaction is rolled back. When a caller has
     * already opened one of its own, rolling back a nested transaction
     * would poison the connection for the rest of that caller's work
     * ("tried to commit after lower level rollback"); the exception
     * still propagates, and closing the transaction is the outer
     * owner's business, exactly as Moodle intends.
     *
     * @param \moodle_transaction|null $transaction the transaction, if it was reached
     * @param bool $outermost whether this class opened the outermost transaction
     * @param \Throwable $e the exception on its way out
     * @throws \Throwable always - $e, after any rollback
     */
    private static function rollback(?\moodle_transaction $transaction, bool $outermost, \Throwable $e): void {
        if ($outermost && $transaction !== null && !$transaction->is_disposed()) {
            // Re-throws $e itself.
            $transaction->rollback($e);
        }

        throw $e;
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
                // The requester cannot open the queue - it belongs to
                // the staff who work it - so the note that explains
                // their outcome has to travel in the message itself.
                'resolution' => trim(html_to_text((string) ($ticket->resolution ?? ''))),
            ],
            new \moodle_url('/mod/selfselectadvanced/tickets.php', ['id' => $activity->cm()->id]),
            $subject
        );
    }
}
