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
use mod_selfselectadvanced\local\rules\gatekeeper;
use stdClass;

/**
 * Guide handover: an assigned guide may leave a submitted, firm or
 * frozen team ONLY by nominating another guide with free capacity, and
 * only that guide's acceptance completes the exit — the team is never
 * left guideless. One pending handover per group; the proposer remains
 * the guide (and keeps carrying the commitment) until acceptance.
 *
 * Locks follow the plugin-wide ordering: the NOMINEE's guide lock
 * before the group lock, so acceptance and every other capacity commit
 * serialise on the guide whose cap is being consumed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handover {
    /** @var string[] States in which a handover applies. */
    private const STATES = [state::PENDING_GUIDE, state::FIRM, state::FROZEN];

    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param gatekeeper $gatekeeper the gatekeeper (capacity source)
     */
    public function __construct(activity $activity, gatekeeper $gatekeeper) {
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;
    }

    /**
     * The current guide proposes handing the team to another guide.
     *
     * @param int $groupid the group
     * @param int $nomineeid the proposed replacement guide
     * @param int $actorid the acting (current) guide
     * @throws \moodle_exception on any refusal
     */
    public function propose(int $groupid, int $nomineeid, int $actorid): void {
        global $DB;

        $guidelock = locks::acquire('eoiguide:' . $nomineeid);
        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            if (!in_array($group->state, self::STATES, true)) {
                throw new workflow_refusal('refusalhandoverstate', 'mod_selfselectadvanced');
            }
            $this->require_proposing_guide($group, $actorid);
            if ($nomineeid === $actorid) {
                throw new workflow_refusal('refusalhandoverself', 'mod_selfselectadvanced');
            }
            if (!empty($group->guidesuccessorid)) {
                throw new workflow_refusal('refusalhandoverpending', 'mod_selfselectadvanced');
            }
            if ($refusal = $this->gatekeeper->can_take_guide($nomineeid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $group->id,
                'guidesuccessorid' => $nomineeid,
                'timeguidenominated' => time(),
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Five refusals - state, not-the-guide, self-nomination,
            // already-pending and the nominee's capacity - all throw
            // from INSIDE the transaction, and guide.php catches
            // moodle_exception to redraw with the refusal. A caught
            // throw never reaches Moodle's exception handler, so
            // without this arm the delegated transaction stayed open
            // for the rest of the request and everything written after
            // it was discarded when the connection closed.
            //
            // Unconditional, never gated on
            // $DB->is_transaction_started(): under PHPUnit that
            // predicate answers for advanced_testcase (true on m5pg,
            // false on m5my) rather than for this method, and the
            // nested arm it selects is wrong anyway - an undisposed
            // frame left on the stack makes the caller's own rollback()
            // rethrow without issuing the physical ROLLBACK. See
            // state::submit() and penalty\ledger::set_award().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $guidelock->release();
        }

        notifier::send(
            $this->activity,
            'guidequeue',
            $nomineeid,
            'msghandoverproposedsubject',
            'msghandoverproposedbody',
            (object) [
                'group' => format_string($group->name),
                'from' => fullname(\core_user::get_user($actorid)),
                'activity' => $this->activity->name(),
            ],
            $this->guide_url(),
            format_string($group->name)
        );
    }

    /**
     * The nominated guide accepts: the team changes hands atomically,
     * the capacity re-checked under the nominee's own guide lock.
     *
     * @param int $groupid the group
     * @param int $actorid the acting (nominated) guide
     * @throws \moodle_exception on any refusal
     */
    public function accept(int $groupid, int $actorid): void {
        global $DB;

        $guidelock = locks::acquire('eoiguide:' . $actorid);
        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            if ((int) ($group->guidesuccessorid ?? 0) !== $actorid) {
                throw new workflow_refusal('refusalhandovernonominee', 'mod_selfselectadvanced');
            }
            if (!in_array($group->state, self::STATES, true)) {
                throw new workflow_refusal('refusalhandoverstate', 'mod_selfselectadvanced');
            }
            if ($refusal = $this->gatekeeper->can_take_guide($actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $oldguide = (int) $group->guideid;
            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $group->id,
                'guideid' => $actorid,
                'guidesuccessorid' => null,
                'timeguidenominated' => null,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);

            \mod_selfselectadvanced\event\guide_reassigned::create([
                'objectid' => $group->id,
                'context' => $this->activity->context(),
                'relateduserid' => $actorid,
                'other' => [
                    'pluginuid' => $group->pluginuid,
                    'fromguideid' => $oldguide,
                    'via' => 'handover',
                ],
            ])->trigger();

            // The mirror carries the guide (decision 7), and this path
            // is reachable while FROZEN. $group was read before the
            // guideid change, so the clone carries the new one; the
            // request only needs id, state and coregroupid.
            $requested = clone $group;
            $requested->guideid = $actorid;
            freeze::request_sync($this->activity, $requested);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The nominee, state and capacity refusals all throw from
            // inside the transaction - a handover cancelled between the
            // guide's page load and the click is the ordinary race.
            // Unconditional - see propose().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $guidelock->release();
        }

        // One sync swaps the old guide out and the new guide in: the
        // old guide is in neither the confirmed set nor guideid, so
        // they land in the owned-removal set. Outside every lock and
        // transaction (requirement 2).
        freeze::sync_core_group($this->activity, (int) $group->id, $actorid);

        $a = (object) [
            'group' => format_string($group->name),
            'to' => fullname(\core_user::get_user($actorid)),
            'newguide' => fullname(\core_user::get_user($actorid)),
            'activity' => $this->activity->name(),
        ];
        notifier::send(
            $this->activity,
            'guidequeue',
            $oldguide,
            'msghandoveracceptedsubject',
            'msghandoveracceptedbody',
            $a,
            $this->guide_url(),
            format_string($group->name)
        );
        notifier::send(
            $this->activity,
            'guidechanged',
            (int) $group->leaderid,
            'msgguidechangedsubject',
            'msgguidechangedbody',
            $a,
            $this->group_url((int) $group->id),
            format_string($group->name)
        );
    }

    /**
     * The nominated guide declines; the proposer stays the guide.
     *
     * @param int $groupid the group
     * @param int $actorid the acting (nominated) guide
     * @throws \moodle_exception when no handover awaits this actor
     */
    public function decline(int $groupid, int $actorid): void {
        global $DB;

        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            if ((int) ($group->guidesuccessorid ?? 0) !== $actorid) {
                throw new workflow_refusal('refusalhandovernonominee', 'mod_selfselectadvanced');
            }
            $this->clear($group, $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The refusalhandovernonominee guard is judged on the row read INSIDE
            // the lock and throws from inside the transaction.
            // Unconditional - see propose().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        notifier::send(
            $this->activity,
            'guidequeue',
            (int) $group->guideid,
            'msghandoverdeclinedsubject',
            'msghandoverdeclinedbody',
            (object) [
                'group' => format_string($group->name),
                'to' => fullname(\core_user::get_user($actorid)),
                'activity' => $this->activity->name(),
            ],
            $this->guide_url(),
            format_string($group->name)
        );
    }

    /**
     * The proposing guide withdraws the pending handover.
     *
     * @param int $groupid the group
     * @param int $actorid the acting (current) guide
     * @throws \moodle_exception when the actor is not the guide or nothing is pending
     */
    public function cancel(int $groupid, int $actorid): void {
        global $DB;

        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            // The same predicate propose() asks, for the same reason
            // (audit D5): cancelling is the proposer taking their own
            // proposal back, so it answers to the same question.
            $this->require_proposing_guide($group, $actorid);
            if (empty($group->guidesuccessorid)) {
                throw new workflow_refusal('refusalhandovernonominee', 'mod_selfselectadvanced');
            }
            $this->clear($group, $actorid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The not-the-guide and nothing-pending refusals are judged
            // on the row read INSIDE the lock and throw from inside the
            // transaction. Unconditional - see propose().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Groups awaiting THIS guide's handover decision.
     *
     * @param int $guideid the nominated guide
     * @return stdClass[] group rows
     */
    public function incoming(int $guideid): array {
        global $DB;

        return $DB->get_records('selfselectadvanced_group', [
            'activityid' => $this->activity->id(),
            'guidesuccessorid' => $guideid,
        ], 'timeguidenominated ASC');
    }

    /**
     * Refuse unless this actor may act as the team's PROPOSING guide.
     *
     * Two questions, both asked here so propose() and cancel() cannot
     * drift apart from each other or from the page (M-01, 1.20.5):
     *
     *  - "is this THEIR team?" is teamaccess::is_assigned_guide() and
     *    nothing else (audit D5). Its capability is
     *    :viewassignedteams, the narrow "the team I am responsible
     *    for" authority every other team-scoped door of this plugin
     *    asks;
     *  - "may they work as a guide at all?" is :guide, which is what
     *    guide.php requires of everybody who reaches the handover
     *    block. The service asked only the first question, so a site
     *    that had withdrawn :guide from a guide - leaving them
     *    :viewassignedteams for the teams already on their name -
     *    still let them nominate a successor and withdraw the
     *    nomination through any direct caller, while the page that
     *    renders those two buttons refused them at its own door. The
     *    service is the authority (AUTH-001), so the service asks
     *    both.
     *
     * accept() and decline() are deliberately NOT routed through here.
     * accept() runs can_take_guide(), which already requires :guide of
     * the accepting nominee - the stricter test, since it also checks
     * their capacity. decline() is a RELEASE: it clears a nomination
     * and hands the team back to the guide who already has it, so
     * refusing it on a lapsed capability would strand the handover in
     * exactly the state H-06 was raised about.
     *
     * Read-time predicate: no write, no event. It is called on the row
     * both callers read INSIDE the lock and INSIDE the transaction,
     * alongside their other refusals, so its throw unwinds through the
     * same rollback arm they already carry - and, like theirs, it
     * disposes the caller's delegated frame, so a test must never put
     * a refused call and a later committing call in one method.
     *
     * @param stdClass $group the fresh group row, read under the lock
     * @param int $actorid the acting user
     * @throws \moodle_exception when the actor is not this team's guide
     */
    private function require_proposing_guide(stdClass $group, int $actorid): void {
        if (
            !teamaccess::is_assigned_guide($this->activity, $group, $actorid)
            || !has_capability('mod/selfselectadvanced:guide', $this->activity->context(), $actorid)
        ) {
            throw new workflow_refusal('refusalhandovernotguide', 'mod_selfselectadvanced');
        }
    }

    /**
     * Blank the nominee fields on a group row.
     *
     * @param stdClass $group the fresh group row
     * @param int $actorid the acting user
     */
    private function clear(stdClass $group, int $actorid): void {
        global $DB;

        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => $group->id,
            'guidesuccessorid' => null,
            'timeguidenominated' => null,
            'usermodified' => $actorid,
            'timemodified' => time(),
        ]);
    }

    /**
     * The guide dashboard URL.
     *
     * @return \moodle_url
     */
    private function guide_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $this->activity->cm()->id]);
    }

    /**
     * A group page URL.
     *
     * @param int $groupid the group
     * @return \moodle_url
     */
    private function group_url(int $groupid): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $this->activity->cm()->id,
            'g' => $groupid,
        ]);
    }
}
