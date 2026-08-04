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
use mod_selfselectadvanced\local\override\resolver;
use stdClass;

/**
 * Expressions of interest: a leader lists their forming team, guides
 * pick it, and the leader accepts or rejects each interest in first
 * come, first served order. Acceptance pre-assigns the guide so the
 * submitted group goes straight to them; every other pending interest
 * is auto-declined. History rows (rejected, expired, withdrawn) are
 * kept for the guide's dashboard and for formation analytics.
 *
 * Fairness invariant: only the LEADER (or a manager) ever converts an
 * interest into an assignment, and the first contact happens entirely
 * inside Moodle notifications.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eoi {
    /** @var string An interest awaiting the leader's decision. */
    public const STATUS_PENDING = 'pending';

    /** @var string The leader accepted; the guide is pre-assigned. */
    public const STATUS_ACCEPTED = 'accepted';

    /** @var string The leader declined, or another guide was accepted. */
    public const STATUS_REJECTED = 'rejected';

    /** @var string The leader did not respond within the window. */
    public const STATUS_EXPIRED = 'expired';

    /** @var string The guide cancelled their own interest. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * The leader lists their forming team for guides to browse, or
     * withdraws it from that listing.
     *
     * AUTHORITY, AND ONLY ON ONE HALF OF THE VERB (AUTH-001).
     *
     * Until this method existed there was no service at all: group.php
     * decided on the raw leaderid, updated the field inline and left no
     * trace. Under decision 38 a leader whose :creategroup has been
     * prohibited REMAINS the leader of record - leadership is
     * transferred, never removed - so owning the row went on answering
     * a question it was never able to answer.
     *
     * The two halves are not the same act and are not gated the same
     * way:
     *
     * - LIST is a PUBLICATION. It puts the team, its title, its brief
     *   and its size in front of every guide in the activity and opens
     *   it to expressions of interest. That is leader authority, so it
     *   asks for it.
     * - UNLIST is a RETRACTION, and the project's F3 invariant is that
     *   an actor is never blocked from making themselves LESS visible.
     *   A leader whose capability was taken away mid-listing must still
     *   be able to take the team back off the board; refusing that
     *   would strand a published team on a page full of guides with
     *   nobody able to withdraw it but staff. So unlisting asks record
     *   ownership and lifecycle state and stops there.
     *
     * The blanket require_lead() the external reviewer recommended
     * would have closed the publication and the retraction together.
     *
     * @param activity $activity the activity
     * @param int $groupid the team
     * @param bool $listed true to list, false to withdraw the listing
     * @param int $actorid the person acting; the leader of record
     * @throws \moodle_exception on any refusal
     * @throws \required_capability_exception when LISTING without :creategroup
     */
    public static function set_listed(activity $activity, int $groupid, bool $listed, int $actorid): void {
        global $DB;

        if (!empty($activity->settings()->studentapproach)) {
            // Belt and braces beside express(): student-approach mode
            // has no guide-initiated interest to be listed for.
            throw new \moodle_exception('refusalstudentapproach', 'mod_selfselectadvanced');
        }
        if (empty($activity->settings()->eoienabled)) {
            throw new \moodle_exception('refusaleoidisabled', 'mod_selfselectadvanced');
        }

        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Re-read inside the lock: leadership can be transferred
            // and the team can be submitted between the click and the
            // write, and both of those decide this question.
            $group = groups::get($activity, $groupid);
            if ((int) $group->leaderid !== $actorid) {
                throw new \moodle_exception('refusalnotleader', 'mod_selfselectadvanced');
            }
            if ($group->state !== state::FORMING) {
                throw new \moodle_exception('refusalwrongstate', 'mod_selfselectadvanced');
            }
            if ($listed) {
                authority::require_lead($activity, $actorid);
            }

            $update = (object) ['id' => $group->id, 'listed' => $listed ? 1 : 0];
            if ($listed && empty($group->timelisted)) {
                // Timelisted records only the FIRST time the team was listed.
                $update->timelisted = time();
            }
            $DB->update_record('selfselectadvanced_group', $update);

            // Payload built INSIDE the critical section, dispatched
            // after the commit AND the release below - the binding
            // rule for new code (docs/architecture.md, "Events under a
            // lock"; store::save() is the worked example). Triggering
            // here would run observers against uncommitted state while
            // this team's lock is held (1.20.3 closure evaluation,
            // EVT-001).
            $event = \mod_selfselectadvanced\event\group_listing_changed::create([
                'objectid' => $group->id,
                'context' => $activity->context(),
                'userid' => $actorid,
                'other' => ['listed' => $listed ? 1 : 0, 'pluginuid' => $group->pluginuid],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Every refusal above - not the leader, wrong state and the
            // capability gate on the listing half - throws from INSIDE
            // the transaction on a row read INSIDE the lock, so the
            // rollback is what stops a refused listing from having
            // written the field anyway. Unconditional - see express().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $event->trigger();
    }

    /**
     * A guide expresses interest in a listed team.
     *
     * @param activity $activity the activity
     * @param int $groupid the listed group
     * @param int $guideid the interested guide
     * @param string $remarks rich-text remarks shown to the leader
     * @param int $remarksformat text format of the remarks
     * @return int the new interest id
     * @throws \moodle_exception on any refusal
     */
    public static function express(
        activity $activity,
        int $groupid,
        int $guideid,
        string $remarks = '',
        int $remarksformat = FORMAT_HTML
    ): int {
        global $DB;

        if (!empty($activity->settings()->studentapproach)) {
            // Belt and braces beside the settings validator: even a
            // directly-flipped eoienabled cannot reopen guide-initiated
            // interest in student-approach mode (strategy 1.16 A).
            throw new \moodle_exception('refusalstudentapproach', 'mod_selfselectadvanced');
        }
        if (empty($activity->settings()->eoienabled)) {
            throw new \moodle_exception('refusaleoidisabled', 'mod_selfselectadvanced');
        }

        // Two locks, always guide before group: the open-interest cap
        // and the capacity ceiling are PER GUIDE, so two simultaneous
        // picks of different teams must serialise on the guide, while
        // the listing state is per group. Same ordering in respond().
        $guidelock = locks::acquire('eoiguide:' . $guideid);
        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($activity, $groupid);
            if (
                empty($group->listed) || $group->state !== state::FORMING
                || !empty($group->guideid)
            ) {
                throw new \moodle_exception('refusaleoinotlisted', 'mod_selfselectadvanced');
            }
            if (self::has_pending($groupid, $guideid)) {
                throw new \moodle_exception('refusaleoidup', 'mod_selfselectadvanced');
            }
            $max = (int) $activity->settings()->eoimax;
            $open = $DB->count_records('selfselectadvanced_eoi', [
                'activityid' => $activity->id(),
                'guideid' => $guideid,
                'status' => self::STATUS_PENDING,
            ]);
            if ($max > 0 && $open >= $max) {
                throw new \moodle_exception('refusaleoimax', 'mod_selfselectadvanced', '', $max);
            }
            // The per-GROUP waitlist cap (1.14.0): under the group
            // lock, so two simultaneous picks cannot both squeeze into
            // the last waitlist place.
            $groupmax = (int) ($activity->settings()->eoigroupmax ?? 0);
            if ($groupmax > 0) {
                $groupopen = $DB->count_records('selfselectadvanced_eoi', [
                    'groupid' => $groupid,
                    'status' => self::STATUS_PENDING,
                ]);
                if ($groupopen >= $groupmax) {
                    throw new \moodle_exception('refusaleoigroupfull', 'mod_selfselectadvanced');
                }
            }
            // AUTHORITY, NOT A NUMBER (audit C5).
            // remaining_capacity() answers "how many more teams could
            // this person hold"; it has never answered "may this person
            // hold a team at all", and an arithmetic answer is not an
            // authority. gatekeeper::can_take_guide() asks both, in that
            // order: mod/selfselectadvanced:guide first, then the same
            // effective-maxguided ceiling against the same commitment
            // count remaining_capacity() computed (count_guiding plus
            // forming pre-assignments), so the ceiling behaves exactly
            // as before and only the capability question is new.
            //
            // It is what state::submit(), handover::propose(),
            // handover::accept() and contacts::respond() already call,
            // so all four "take a team" seams now ask the one authority.
            // Before this, an administrator's CAP_PROHIBIT on :guide was
            // honoured everywhere EXCEPT here - the interest was
            // accepted, the guideid was written, and the team then found
            // every downstream verb refused with no way back that did
            // not need staff.
            if ($refusal = (new api($activity))->gatekeeper()->can_take_guide($guideid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $id = $DB->insert_record('selfselectadvanced_eoi', (object) [
                'activityid' => $activity->id(),
                'groupid' => $groupid,
                'guideid' => $guideid,
                'status' => self::STATUS_PENDING,
                'remarks' => $remarks,
                'remarksformat' => $remarksformat,
                'timecreated' => $now,
                'timeresponded' => null,
            ]);
            $pendingcount = $DB->count_records('selfselectadvanced_eoi', [
                'groupid' => $groupid,
                'status' => self::STATUS_PENDING,
            ]);

            \mod_selfselectadvanced\event\eoi_created::create([
                'objectid' => $id,
                'context' => $activity->context(),
                'relateduserid' => $guideid,
                'other' => ['groupid' => $groupid, 'pluginuid' => $group->pluginuid],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Five refusals - not-listed, duplicate, the per-guide open
            // cap, the per-group waitlist cap and the gatekeeper's
            // verdict (:guide, then the capacity ceiling) - are all
            // judged on rows read INSIDE the locks and throw from
            // INSIDE the transaction. guide.php catches
            // moodle_exception and redirects with a notification, so a
            // caught refusal never reaches Moodle's exception handler
            // and nothing else would roll this back: the delegated
            // transaction stayed open for the rest of the request and
            // everything written after it was discarded when the
            // connection closed.
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

        // Sends happen only after the commit; messages cannot roll back.
        notifier::send(
            $activity,
            'eoireceived',
            (int) $group->leaderid,
            'msgeoireceivedsubject',
            'msgeoireceivedbody',
            (object) [
                'group' => format_string($group->name),
                'guide' => fullname(\core_user::get_user($guideid)),
                'count' => $pendingcount,
                'activity' => $activity->name(),
            ],
            new \moodle_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id, 'g' => $groupid]),
            format_string($group->name)
        );

        return $id;
    }

    /**
     * A guide withdraws their own pending interest.
     *
     * @param activity $activity the activity
     * @param int $eoiid the interest
     * @param int $guideid the acting guide, must own the interest
     * @throws \moodle_exception when the row is not theirs or not pending
     */
    public static function withdraw(activity $activity, int $eoiid, int $guideid): void {
        global $DB;

        $row = self::get($activity, $eoiid);
        $lock = locks::acquire('group:' . $row->groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Revalidate inside the lock: the leader may have accepted
            // or rejected this interest in the meantime, and a stale
            // withdraw must not overwrite that decision.
            $row = self::get($activity, $eoiid);
            if ((int) $row->guideid !== $guideid || $row->status !== self::STATUS_PENDING) {
                throw new \moodle_exception('refusaleoinotpending', 'mod_selfselectadvanced');
            }
            self::transition($activity, $row, self::STATUS_WITHDRAWN, $guideid);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The revalidation inside the lock throws
            // refusaleoinotpending from inside the transaction whenever
            // the leader decided this interest first - exactly the race
            // the re-read exists for. Unconditional - see express().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $group = groups::get($activity, (int) $row->groupid);
        notifier::send(
            $activity,
            'eoireceived',
            (int) $group->leaderid,
            'msgeoiwithdrawnsubject',
            'msgeoiwithdrawnbody',
            (object) [
                'group' => format_string($group->name),
                'guide' => fullname(\core_user::get_user($guideid)),
                'activity' => $activity->name(),
            ],
            new \moodle_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id, 'g' => $group->id]),
            format_string($group->name)
        );
    }

    /**
     * Why this actor may NOT decide this team's pending interests, as a
     * value - or null if they may.
     *
     * THE ONE COPY of respond()'s authority-and-self-dealing ladder,
     * extracted (blind audit 1.20.3, finding 1) because group.php's
     * door and group_page's renderer were offering Accept/Decline to
     * narrow-authority actors the service then refused: a Group
     * Coordinator with a pending interest of their own on the team got
     * live links whose every press could only error. The screens that
     * OFFER the decision and the service that MAKES it now ask this
     * same method.
     *
     * Advisory at render time (unlocked, like every render predicate);
     * respond() asks it again inside the guide + group locks against
     * the re-read row, which is the reading that binds.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row (leaderid required)
     * @param int $actorid who wants to decide
     * @return ?stdClass null if they may decide, else ->stringkey and
     *         ->a for the refusal, the shape gatekeeper refusals use
     */
    public static function decide_refusal(activity $activity, stdClass $group, int $actorid): ?stdClass {
        global $DB;
        $refuse = static fn(string $key, $a = null): stdClass => (object) ['stringkey' => $key, 'a' => $a];

        // AUTHORITY, NOT OWNERSHIP on the leader arm (AUTH-004):
        // decision 38 keeps a prohibited leader in leaderid, so owning
        // the row says who the person is, never what they may do.
        $isleader = (int) $group->leaderid === $actorid;
        $leadermayact = $isleader && authority::may_lead($activity, $actorid);
        $ismanager = has_capability('mod/selfselectadvanced:manage', $activity->context(), $actorid);
        $canassign = $ismanager
            || has_capability('mod/selfselectadvanced:assignguide', $activity->context(), $actorid);
        if (!$leadermayact && !$canassign) {
            return $refuse('refusalnotleader');
        }
        if (!$leadermayact && !$ismanager) {
            // Narrow authority may not decide interests on a team it
            // is involved in, nor while it has an interest of its own
            // pending on the SAME team: accepting your own expression
            // of interest, or declining the rival that stands in its
            // way, is self-dealing either way, and both are one button
            // press from the same screen. A prohibited leader who also
            // holds :assignguide falls into this branch instead of
            // past it, where involvement() names them as the leader of
            // the team they are deciding about - refused either way.
            if (
                $DB->record_exists('selfselectadvanced_eoi', [
                    'groupid' => $group->id,
                    'guideid' => $actorid,
                    'status' => self::STATUS_PENDING,
                ])
            ) {
                return $refuse('refusaleoiselfaccept');
            }
            if (($involvement = tickets::involvement($activity, $group, $actorid)) !== null) {
                return $refuse('refusalcoiinvolved', $involvement);
            }
        }
        return null;
    }

    /**
     * The leader (or an assignguide/manage holder) accepts or rejects a
     * pending interest.
     *
     * Accepting pre-assigns the guide on the group row and auto-rejects
     * every other pending interest for the group, notifying each guide.
     *
     * @param activity $activity the activity
     * @param int $eoiid the interest
     * @param bool $accept true to accept, false to reject
     * @param int $actorid the leader ACTING UNDER :creategroup, or a
     *        holder of :assignguide or :manage - the narrow holder
     *        additionally refused on a team they are involved in, or
     *        one they have an interest of their own pending on
     * @throws \moodle_exception on any refusal
     */
    public static function respond(activity $activity, int $eoiid, bool $accept, int $actorid): void {
        global $DB;

        $row = self::get($activity, $eoiid);
        $declined = [];

        // Same ordering as express(): guide before group.
        $guidelock = locks::acquire('eoiguide:' . $row->guideid);
        $lock = locks::acquire('group:' . $row->groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $row = self::get($activity, $eoiid);
            if ($row->status !== self::STATUS_PENDING) {
                throw new \moodle_exception('refusaleoinotpending', 'mod_selfselectadvanced');
            }
            $group = groups::get($activity, (int) $row->groupid);
            // Accepting an interest IS a guide assignment, so the
            // narrow :assignguide capability reaches it alongside the
            // leader and the manager - with AUTHORITY, NOT OWNERSHIP
            // on the leader arm (AUTH-004), because under decision 38
            // a prohibited leader is still the leader of record.
            //
            // The whole ladder - capability, self-accept, involvement
            // - lives in decide_refusal(), THE ONE COPY, because the
            // screens that OFFER Accept/Decline consult it too (blind
            // audit 1.20.3, finding 1: the buttons were drawn for a
            // coordinator whose every press could only be refused).
            // Asked here inside the guide + group locks against the
            // re-read rows - the reading that binds. It only reads and
            // throws - nothing is written, sent or fired here (house
            // rule 1).
            if ($refusal = self::decide_refusal($activity, $group, $actorid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            if ($accept) {
                if ($group->state !== state::FORMING || !empty($group->guideid)) {
                    throw new \moodle_exception('refusaleoinotlisted', 'mod_selfselectadvanced');
                }
                // Belt and braces, the same pair express() wears (3C
                // audit follow-up B): flipping studentapproach on, or
                // eoienabled off, after interests exist must not leave
                // an ACCEPT alive that completes the guide-initiated
                // assignment those settings forbid. Only the accept
                // half is guarded - a pending interest must always be
                // clearable, so rejecting stays open in every state.
                if (!empty($activity->settings()->studentapproach)) {
                    throw new \moodle_exception('refusalstudentapproach', 'mod_selfselectadvanced');
                }
                if (empty($activity->settings()->eoienabled)) {
                    throw new \moodle_exception('refusaleoidisabled', 'mod_selfselectadvanced');
                }
                // The same authority express() asks, asked again about
                // the SAME guide at the moment the row is written: an
                // interest can sit pending for days, and the guide's
                // capability, their overrides and their load can all
                // have moved since. This is the line that installs a
                // guide, so this is where :guide has to be true.
                // See express() for why a number was never enough.
                if ($refusal = (new api($activity))->gatekeeper()->can_take_guide((int) $row->guideid)) {
                    throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
                }
                $DB->set_field('selfselectadvanced_group', 'guideid', $row->guideid, ['id' => $group->id]);
                $DB->set_field('selfselectadvanced_group', 'timemodified', time(), ['id' => $group->id]);
                self::transition($activity, $row, self::STATUS_ACCEPTED, $actorid);

                // Every other pending interest is declined in the same
                // transaction; the notifications go out after commit.
                $others = $DB->get_records('selfselectadvanced_eoi', [
                    'groupid' => $group->id,
                    'status' => self::STATUS_PENDING,
                ]);
                foreach ($others as $other) {
                    self::transition($activity, $other, self::STATUS_REJECTED, $actorid);
                    $declined[] = (int) $other->guideid;
                }
            } else {
                self::transition($activity, $row, self::STATUS_REJECTED, $actorid);
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Everything this method refuses - not-pending, not-leader,
            // the self-accept guard, require_uninvolved(), not-listed
            // and the gatekeeper's verdict on the guide being installed
            // (:guide, then the capacity ceiling) - throws from INSIDE the
            // transaction, on rows re-read inside the two locks.
            // Unconditional - see express().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $guidelock->release();
        }

        $a = (object) [
            'group' => format_string($group->name),
            'activity' => $activity->name(),
        ];
        $url = new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $activity->cm()->id]);
        if ($accept) {
            notifier::send(
                $activity,
                'eoiresult',
                (int) $row->guideid,
                'msgeoiacceptedsubject',
                'msgeoiacceptedbody',
                $a,
                $url,
                format_string($group->name)
            );
            foreach ($declined as $otherguide) {
                notifier::send(
                    $activity,
                    'eoiresult',
                    $otherguide,
                    'msgeoiautodeclinedsubject',
                    'msgeoiautodeclinedbody',
                    $a,
                    $url,
                    format_string($group->name)
                );
            }
        } else {
            notifier::send(
                $activity,
                'eoiresult',
                (int) $row->guideid,
                'msgeoirejectedsubject',
                'msgeoirejectedbody',
                $a,
                $url,
                format_string($group->name)
            );
        }
    }

    /**
     * A pre-assigned guide steps out of a still-forming team.
     *
     * The team stays listed, so other guides (or this one, later) can
     * express interest again. A submitted group uses the return flow;
     * firm and frozen groups need manager tools.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @param int $guideid the guide stepping out
     * @throws \moodle_exception when the guide is not pre-assigned here
     */
    public static function stepout(activity $activity, int $groupid, int $guideid): void {
        global $DB;

        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($activity, $groupid);
            if ($group->state !== state::FORMING || (int) $group->guideid !== $guideid) {
                throw new \moodle_exception('refusaleoinotassigned', 'mod_selfselectadvanced');
            }
            $DB->set_field('selfselectadvanced_group', 'guideid', null, ['id' => $groupid]);
            $DB->set_field('selfselectadvanced_group', 'timemodified', time(), ['id' => $groupid]);

            // The accepted interest returns to history as withdrawn so
            // the dashboard tallies stay truthful.
            $accepted = $DB->get_records('selfselectadvanced_eoi', [
                'groupid' => $groupid,
                'guideid' => $guideid,
                'status' => self::STATUS_ACCEPTED,
            ]);
            foreach ($accepted as $row) {
                self::transition($activity, $row, self::STATUS_WITHDRAWN, $guideid);
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The refusaleoinotassigned guard is judged on the row read INSIDE
            // the lock and throws from inside the transaction.
            // Unconditional - see express().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        notifier::send(
            $activity,
            'eoireceived',
            (int) $group->leaderid,
            'msgeoisteppedoutsubject',
            'msgeoisteppedoutbody',
            (object) [
                'group' => format_string($group->name),
                'guide' => fullname(\core_user::get_user($guideid)),
                'activity' => $activity->name(),
            ],
            new \moodle_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id, 'g' => $groupid]),
            format_string($group->name)
        );
    }

    /**
     * Expire pending interests older than the activity window.
     *
     * @param activity $activity the activity
     * @return int how many interests expired
     */
    public static function expire_due(activity $activity): int {
        global $DB;

        $window = (int) $activity->settings()->eoiwindow;
        if (empty($activity->settings()->eoienabled) || $window <= 0) {
            return 0;
        }
        $rows = $DB->get_records_select(
            'selfselectadvanced_eoi',
            'activityid = :activityid AND status = :status AND timecreated < :cutoff',
            [
                'activityid' => $activity->id(),
                'status' => self::STATUS_PENDING,
                'cutoff' => time() - $window,
            ]
        );
        if (!$rows) {
            return 0;
        }

        // Group names for the notifications, fetched once, chunked so
        // the id list can never approach a bind-parameter ceiling.
        $groupnames = [];
        $groupids = array_unique(array_map(static fn($row) => (int) $row->groupid, $rows));
        foreach (array_chunk($groupids, 1000) as $chunk) {
            $groupnames += $DB->get_records_list(
                'selfselectadvanced_group',
                'id',
                $chunk,
                '',
                'id, name'
            );
        }

        $expired = [];
        foreach ($rows as $row) {
            // Recheck under the group lock: a leader may have accepted
            // this interest between the sweep's select and now, and an
            // expiry must never overwrite a decision.
            $lock = locks::acquire('group:' . $row->groupid);
            try {
                $fresh = self::get($activity, (int) $row->id);
                if ($fresh->status !== self::STATUS_PENDING) {
                    continue;
                }
                self::transition($activity, $fresh, self::STATUS_EXPIRED, 0);
                $expired[] = $fresh;
            } finally {
                $lock->release();
            }
        }
        foreach ($expired as $row) {
            $name = isset($groupnames[(int) $row->groupid])
                ? format_string($groupnames[(int) $row->groupid]->name)
                : '';
            notifier::send(
                $activity,
                'eoiresult',
                (int) $row->guideid,
                'msgeoiexpiredsubject',
                'msgeoiexpiredbody',
                (object) [
                    'group' => $name,
                    'activity' => $activity->name(),
                ],
                new \moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $activity->cm()->id]),
                $name
            );
        }

        return count($expired);
    }

    /**
     * Groups plus interests the guide is committed to: the guided
     * states plus forming teams that pre-assigned this guide.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide
     * @return int
     */
    /**
     * Commitments for EVERY guide of the activity in two grouped
     * queries (RCA-2, 10k probe): the bulk companion of
     * guide_commitments() with the identical definition — guided
     * states plus forming preassignments. List builders consume this;
     * the per-guide capacity gates keep using the scalar under their
     * locks.
     *
     * @param activity $activity the activity
     * @return int[] commitment counts keyed by guide id
     */
    public static function guide_commitments_all(activity $activity): array {
        global $DB;

        $bystate = $DB->get_records_sql(
            "SELECT guideid, COUNT(1) AS committed
               FROM {selfselectadvanced_group}
              WHERE activityid = :activityid AND guideid IS NOT NULL
                AND state IN (:pending, :firm, :frozen)
           GROUP BY guideid",
            [
                'activityid' => $activity->id(),
                'pending' => state::PENDING_GUIDE,
                'firm' => state::FIRM,
                'frozen' => state::FROZEN,
            ]
        );
        $forming = $DB->get_records_sql(
            "SELECT guideid, COUNT(1) AS committed
               FROM {selfselectadvanced_group}
              WHERE activityid = :activityid AND guideid IS NOT NULL AND state = :forming
           GROUP BY guideid",
            ['activityid' => $activity->id(), 'forming' => state::FORMING]
        );

        $totals = [];
        foreach ($bystate as $row) {
            $totals[(int) $row->guideid] = (int) $row->committed;
        }
        foreach ($forming as $row) {
            $totals[(int) $row->guideid] = ($totals[(int) $row->guideid] ?? 0) + (int) $row->committed;
        }

        return $totals;
    }

    /**
     * How many teams currently consume this guide's capacity: guided
     * pending/firm/frozen teams plus forming pre-assignments.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide
     * @return int committed team count
     */
    public static function guide_commitments(activity $activity, int $guideid): int {
        global $DB;

        $forming = $DB->count_records('selfselectadvanced_group', [
            'activityid' => $activity->id(),
            'guideid' => $guideid,
            'state' => state::FORMING,
        ]);

        return groups::count_guiding($activity, $guideid) + $forming;
    }

    /**
     * Dashboard tallies for one guide.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide
     * @return stdClass guiding, pending, expired and rejected counts
     */
    public static function counts(activity $activity, int $guideid): stdClass {
        global $DB;

        $bystatus = $DB->get_records_sql_menu(
            "SELECT status, COUNT(1)
               FROM {selfselectadvanced_eoi}
              WHERE activityid = :activityid AND guideid = :guideid
           GROUP BY status",
            ['activityid' => $activity->id(), 'guideid' => $guideid]
        );

        return (object) [
            'guiding' => self::guide_commitments($activity, $guideid),
            'pending' => (int) ($bystatus[self::STATUS_PENDING] ?? 0),
            'expired' => (int) ($bystatus[self::STATUS_EXPIRED] ?? 0),
            'rejected' => (int) ($bystatus[self::STATUS_REJECTED] ?? 0),
        ];
    }

    /**
     * Listed, still guideless forming teams with their live interest
     * counts, most-wanted first inside the listing order.
     *
     * @param activity $activity the activity
     * @return stdClass[] group rows with an interestcount property
     */
    public static function listed_groups(activity $activity): array {
        global $DB;

        $groups = $DB->get_records_sql(
            "SELECT g.*, COALESCE(e.interestcount, 0) AS interestcount
               FROM {selfselectadvanced_group} g
          LEFT JOIN (SELECT groupid, COUNT(1) AS interestcount
                       FROM {selfselectadvanced_eoi}
                      WHERE status = :pending
                   GROUP BY groupid) e ON e.groupid = g.id
              WHERE g.activityid = :activityid AND g.listed = 1
                    AND g.state = :forming AND g.guideid IS NULL
           ORDER BY COALESCE(e.interestcount, 0) DESC, g.timelisted ASC",
            [
                'pending' => self::STATUS_PENDING,
                'activityid' => $activity->id(),
                'forming' => state::FORMING,
            ]
        );

        return array_values($groups);
    }

    /**
     * Every interest for one group, first come first served.
     *
     * Callers apply the sequential display rule: when eoisequential is
     * set, show responded rows plus only the EARLIEST pending one.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @return stdClass[]
     */
    public static function for_group(activity $activity, int $groupid): array {
        global $DB;

        return array_values($DB->get_records('selfselectadvanced_eoi', [
            'activityid' => $activity->id(),
            'groupid' => $groupid,
        ], 'timecreated ASC, id ASC'));
    }

    /**
     * One interest row, ownership checked against the activity.
     *
     * @param activity $activity the activity
     * @param int $eoiid the interest
     * @return stdClass
     */
    public static function get(activity $activity, int $eoiid): stdClass {
        global $DB;

        return $DB->get_record('selfselectadvanced_eoi', [
            'id' => $eoiid,
            'activityid' => $activity->id(),
        ], '*', MUST_EXIST);
    }

    /**
     * Whether the guide already has a pending interest in the group.
     *
     * @param int $groupid the group
     * @param int $guideid the guide
     * @return bool
     */
    public static function has_pending(int $groupid, int $guideid): bool {
        global $DB;

        return $DB->record_exists('selfselectadvanced_eoi', [
            'groupid' => $groupid,
            'guideid' => $guideid,
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * How many more teams this guide can commit to, override and
     * volunteering aware through the resolver.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide
     * @return int
     */
    public static function remaining_capacity(activity $activity, int $guideid): int {
        $resolver = new resolver($activity);
        $max = $resolver->effective_maxguided($guideid)->value;

        return max(0, $max - self::guide_commitments($activity, $guideid));
    }

    /**
     * Move one interest to a new status and fire the update event.
     *
     * The actor is REQUIRED, never defaulted (AUTH-001 residual,
     * 1.20.4): the old `= 0` let a forgetful caller book a human's
     * decision as the system's, and the event then dropped the actor
     * entirely, so the log answered "who decided this?" with whoever
     * happened to be in the session. Every caller now states its
     * actor - 0 is the expiry sweep's explicit signature - and a human
     * actor is recorded on the event.
     *
     * @param activity $activity the activity
     * @param stdClass $row the interest row
     * @param string $status the new status
     * @param int $actorid who caused it, 0 for the system
     */
    private static function transition(activity $activity, stdClass $row, string $status, int $actorid): void {
        global $DB;

        $DB->update_record('selfselectadvanced_eoi', (object) [
            'id' => $row->id,
            'status' => $status,
            'timeresponded' => time(),
        ]);
        $data = [
            'objectid' => (int) $row->id,
            'context' => $activity->context(),
            'relateduserid' => (int) $row->guideid,
            'other' => ['groupid' => (int) $row->groupid, 'status' => $status],
        ];
        if ($actorid > 0) {
            $data['userid'] = $actorid;
        }
        \mod_selfselectadvanced\event\eoi_updated::create($data)->trigger();
    }

    /**
     * A pending interest's 1-based first-come-first-served position in
     * its group's queue (the order for_group() lists and sequential
     * reveal walks): null when the row is not pending.
     *
     * @param activity $activity the activity
     * @param int $groupid the group
     * @param int $eoiid the guide's own interest row
     * @return int|null
     */
    public static function queue_position(activity $activity, int $groupid, int $eoiid): ?int {
        global $DB;

        $row = $DB->get_record('selfselectadvanced_eoi', [
            'id' => $eoiid,
            'activityid' => $activity->id(),
            'groupid' => $groupid,
        ], '*', MUST_EXIST);
        if ($row->status !== self::STATUS_PENDING) {
            return null;
        }

        $ahead = $DB->count_records_select(
            'selfselectadvanced_eoi',
            'groupid = :groupid AND status = :status AND '
                . '(timecreated < :t1 OR (timecreated = :t2 AND id < :id))',
            [
                'groupid' => $groupid,
                'status' => self::STATUS_PENDING,
                't1' => (int) $row->timecreated,
                't2' => (int) $row->timecreated,
                'id' => (int) $row->id,
            ]
        );

        return 1 + $ahead;
    }
}
