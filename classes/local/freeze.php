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
 * Core-group synchronisation (spec 12): freeze, unfreeze and the one
 * authoritative mirror routine.
 *
 * The mirror is a course group whose plugin-owned membership equals
 * "confirmed plugin members UNION the assigned guide" (decision 7).
 * Exactly one routine converges it - sync_core_group() - and it runs
 * OUTSIDE every plugin lock and transaction, because core's groups API
 * fires events, invalidates caches and writes group conversations per
 * member. The in-transaction half is request_sync(), which queues a
 * deduped adhoc task in the same transaction as the plugin write: a
 * crash between the commit and the inline apply is repaired by cron,
 * never silently diverged.
 *
 * Good-neighbour rules (spec 14.5): only the official groups API, only
 * rows this plugin owns. Ownership is machine-readable - the course
 * group carries idnumber = pluginuid and every membership this plugin
 * writes carries component 'mod_selfselectadvanced' and itemid = the
 * plugin group id. Rows we do not own are reported as drift and never
 * touched.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class freeze {
    /** @var string The component every membership this plugin writes carries. */
    public const COMPONENT = 'mod_selfselectadvanced';

    /** @var int How many groups one bulk-freeze request freezes inline before queueing the rest. */
    public const BULK_FREEZE_INLINE_MAX = 20;

    /**
     * The set every core write and every drift check agrees on:
     * confirmed member userids UNION the assigned guide (decision 7).
     *
     * The guide is NEVER written to selfselectadvanced_member or the
     * snapshot roster - unfreeze() replays that roster as CONFIRMED
     * members, which would silently make the guide a student.
     * Recomputed from the row, never cached.
     *
     * Guide-writing paths that can run with a live mirror call
     * request_sync()/sync_core_group(): handover::accept(),
     * state::assign_guide() and succession::confirm(). The remaining
     * guide writers (eoi, contacts, state::submit, state::return_group)
     * all require FORMING or PENDING_GUIDE, which the state machine
     * makes unreachable once a mirror exists, and api::create_group()
     * plus autogroup\engine create rows with no guide and no mirror.
     *
     * @param stdClass $group the plugin group row
     * @return int[] userids
     */
    public static function expected_core_members(stdClass $group): array {
        global $DB;

        $ids = array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$group->id, groups::STATUS_CONFIRMED]
        ));
        if (!empty($group->guideid) && !in_array((int) $group->guideid, $ids, true)) {
            $ids[] = (int) $group->guideid;
        }

        return array_values($ids);
    }

    /**
     * The in-transaction half of the mirror contract: queue the
     * convergence job in the SAME transaction as the plugin write.
     *
     * Called INSIDE the mutating lock and transaction. The task_adhoc
     * insert commits atomically with the plugin state, so the documented
     * failure path - crash after the plugin commit, before the inline
     * sync_core_group() - is repaired by cron. queue_adhoc_task(..., true)
     * dedupes identical pending rows, so repeat mutations of one group do
     * not stack.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row as it stands after the write
     */
    public static function request_sync(activity $activity, stdClass $group): void {
        if (empty($group->coregroupid) && ($group->state ?? '') !== state::FROZEN) {
            // No mirror and none required: nothing to keep in step.
            return;
        }

        $task = new \mod_selfselectadvanced\task\coresync_adhoc();
        $task->set_custom_data(['activityid' => $activity->id(), 'groupid' => (int) $group->id]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Make the course group equal "confirmed members UNION guide".
     *
     * The single entry point for every core-group write. Idempotent,
     * diff-based and convergent: calling it twice in a row is a no-op
     * the second time. It mints the mirror when the group is frozen and
     * has none, clears a dangling pointer when it is not frozen and the
     * mirror is gone, adds what is missing, removes what this plugin
     * owns and should not be there, and REPORTS - never removes - rows
     * somebody else put in the group.
     *
     * @param activity $activity the activity
     * @param int $groupid the plugin group id
     * @param int $actorid the acting user (recorded on the event)
     * @param int[] $forceremove userids to remove even when the ownership
     *        discriminator cannot classify them - the GDPR erasure path,
     *        where the member row is already gone
     * @param bool $rethrow true inside the adhoc task, so core's retry and
     *        backoff engage; inline callers swallow and report, because the
     *        queued adhoc IS their retry
     * @return stdClass status ('synced'|'nomirror'|'failed'), coregroupid,
     *         added, removed, refused, extra, error - 'synced' is set only
     *         once every add, removal and grouping write has RETURNED, so a
     *         caller may read it as "the mirror is in step"
     * @throws \Throwable when $rethrow and a core call fails
     */
    public static function sync_core_group(
        activity $activity,
        int $groupid,
        int $actorid,
        array $forceremove = [],
        bool $rethrow = false
    ): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $result = (object) [
            'status' => 'nomirror',
            'coregroupid' => 0,
            'added' => [],
            'removed' => [],
            'refused' => [],
            'extra' => [],
            'error' => '',
        ];

        // NO AMBIENT-TRANSACTION BRANCH HERE, DELIBERATELY, AND NONE MAY
        // BE ADDED. This routine used to return status='deferred' the
        // moment $DB->is_transaction_started() was true. That is
        // requirement 6's named trap: advanced_testcase opens a
        // delegated transaction before EVERY test on PostgreSQL and
        // none on MariaDB, so the guard made the mirror RUN on one
        // engine and be SKIPPED on the other for identical inputs -
        // measured with the same probe on both: 'family=postgres
        // tx_started=1 sync_status=deferred coregroupid=0 coremembers=0'
        // against 'family=mysql tx_started=0 sync_status=synced
        // coregroupid=SET coremembers=3'. Two engines were
        // reported as one number by the gate and any test that forgot
        // the opt-in preventResetByRollback() passed vacuously on
        // PostgreSQL. What a routine DOES must never depend on ambient
        // transaction state; only how it unwinds may ($outermost).
        //
        // What the removal costs, stated rather than glossed: a caller
        // with a transaction open now performs the core group writes
        // inside that transaction. They are the same DB connection, so
        // they roll back with it; core buffers the events and the
        // messages a rollback must not send (\core\event\manager and
        // \core\message\manager both discard their buffers on
        // database_transaction_rolledback). The convergence contract is
        // unchanged: request_sync() queues the adhoc in the SAME
        // transaction as the plugin write, so a rollback takes the job
        // with it and a commit keeps it.
        $group = $DB->get_record('selfselectadvanced_group', ['id' => $groupid]);
        if (!$group) {
            // Activity or group deleted meanwhile.
            return $result;
        }

        try {
            $coregroupid = (int) ($group->coregroupid ?? 0);
            $frozen = $group->state === state::FROZEN;
            if ($frozen && (!$coregroupid || !groups_group_exists($coregroupid))) {
                // Mint or repair. The ONE exception to "no core API
                // under a plugin lock", and the honest description of
                // it - the previous wording, "a single insert", is
                // false and docs/architecture.md A7 now carries the
                // corrected list. groups_create_group() also invalidates
                // the course-wide 'groupdata' cache definition, rebuilds
                // the course's hidden-groups cache, may create a group
                // conversation, saves group custom fields, triggers
                // \core\event\group_created - measured dispatching at
                // locks::held_count() === 1, the only core group event
                // in this plugin that does - and dispatches
                // \core_group\hook\after_group_created, i.e. arbitrary
                // third-party observer and hook code, per mint, under
                // this per-team lock. It stays because the lock is what
                // closes the double-mint race between an inline caller
                // and the adhoc, and it runs once per team lifetime,
                // with no membership writes and no open transaction of
                // this plugin's own.
                $lock = locks::acquire('group:' . $groupid);
                try {
                    $group = $DB->get_record('selfselectadvanced_group', ['id' => $groupid]);
                    if (!$group) {
                        return $result;
                    }
                    $coregroupid = (int) ($group->coregroupid ?? 0);
                    if ($group->state === state::FROZEN && (!$coregroupid || !groups_group_exists($coregroupid))) {
                        $coregroupid = self::mint_core_group($activity, $group);
                        $DB->set_field('selfselectadvanced_group', 'coregroupid', $coregroupid, ['id' => $groupid]);
                        $group->coregroupid = $coregroupid;
                    }
                } finally {
                    $lock->release();
                }
            } else if (!$frozen && $coregroupid && !groups_group_exists($coregroupid)) {
                // The mirror was deleted out of band while the team was
                // firm. Clear the dangling pointer and say so.
                $lock = locks::acquire('group:' . $groupid);
                try {
                    $recheck = $DB->get_record('selfselectadvanced_group', ['id' => $groupid]);
                    if (
                        $recheck && !empty($recheck->coregroupid)
                        && $recheck->state !== state::FROZEN
                        && !groups_group_exists((int) $recheck->coregroupid)
                    ) {
                        $DB->set_field('selfselectadvanced_group', 'coregroupid', null, ['id' => $groupid]);
                    }
                } finally {
                    $lock->release();
                }

                return $result;
            } else if (!$coregroupid) {
                return $result;
            }

            $result->coregroupid = $coregroupid;

            $expected = self::expected_core_members($group);
            $split = self::classify_mirror($groupid, $coregroupid, $expected, $forceremove);
            $result->extra = $split['extra'];

            foreach ($split['adds'] as $userid) {
                // The return value IS the finding: core refuses deleted
                // and non-enrolled users - including a guide who holds
                // the capability through a category or system role with
                // no course enrolment - and says so by returning false,
                // never by throwing.
                if (groups_add_member($coregroupid, $userid, self::COMPONENT, (int) $group->id)) {
                    $result->added[] = $userid;
                } else {
                    $result->refused[] = $userid;
                }
            }
            foreach ($split['removals'] as $userid) {
                groups_remove_member($coregroupid, $userid);
                $result->removed[] = $userid;
            }

            self::ensure_grouping($activity, $coregroupid);

            // LAST, not first. 'synced' is the caller's licence to tell
            // a manager the course group is in step, and group.php does
            // exactly that; setting it before classify_mirror() and the
            // two loops meant a throw anywhere in them returned
            // status='synced' with added=0/removed=0 and the page chose
            // its green "already in step" branch for a mirror missing
            // the very member the sync had failed to add. The only
            // signal was a debugging(), which is a no-op in production.
            $result->status = 'synced';
        } catch (\Throwable $e) {
            // A refusal, not a whisper: the status carries the failure
            // out to the caller and the message travels with it, so a
            // page can say what went wrong instead of showing green.
            $result->status = 'failed';
            $result->error = $e->getMessage();
            debugging(
                'Core-group sync failed for plugin group ' . $groupid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );

            // Queue the convergence job even here. group.php's manual
            // resync was the ONE sync entry point with no adhoc behind
            // it, so a failure there left the mirror broken with
            // nothing scheduled to try again; every other caller has
            // already queued one inside its own transaction and
            // queue_adhoc_task(..., true) dedupes an identical pending
            // row, so this adds a retry where there was none and
            // nothing where there was one. Inside the adhoc itself
            // ($rethrow) core's own retry and backoff are the
            // mechanism, and re-queueing under a failing task would
            // race its rescheduling.
            if (!$rethrow && $group) {
                self::request_sync($activity, $group);
            }
            if ($rethrow) {
                throw $e;
            }

            return $result;
        }

        if ($result->added || $result->removed || $result->refused || $result->extra) {
            // NO LOCK IS HELD. That half is a measured fact, not a
            // hope: instrumenting this line to record locks::held_count()
            // and running the whole suite gives ZERO calls with a lock
            // held, on PostgreSQL and on MariaDB. Every plugin caller
            // syncs after its own release - moves::commit_set(),
            // state::, succession::, handover::, joinrequests:: and
            // freeze's own freeze_group()/unfreeze() all do - and the
            // nested join-accept path hands the group ids back through
            // $deferredsyncgroupids instead of calling here at all.
            //
            // NO TRANSACTION IS OPEN IS NOT GUARANTEED, and saying it
            // was is what the 1.20 requirement-6 removal cost. The
            // is_transaction_started() deferral used to return before
            // this line, so an ambient transaction was structurally
            // unreachable here; with the deferral gone, a caller that
            // has one open reaches this event and, below,
            // flag_sync_refusals() -> notifier::send(). observer.php's
            // deleted-user path names that possibility in its own
            // comment, and coresync_test's
            // test_sync_does_the_same_work_inside_and_outside_a_transaction
            // reaches this line with one open by construction (measured
            // on MariaDB, where the harness opens none of its own:
            // TX=1 HELD=0, and it is the only such call in 421 tests).
            //
            // What that costs, stated rather than glossed: core buffers
            // both the event and the message and discards the buffers on
            // database_transaction_rolledback, so nothing escapes a
            // rollback - but a message queued under a caller's
            // transaction still travels late, and house rule 1 wants
            // notifier::send() outside one. The clean repair is the
            // explicit-mode split already booked as the T-16 follow-up
            // (sync_now() versus request_sync(), refusing the inline
            // form when a transaction is open); until it lands this
            // comment describes the real state instead of the state the
            // deferral used to enforce.
            $data = [
                'objectid' => (int) $group->id,
                'context' => $activity->context(),
                'other' => [
                    'pluginuid' => $group->pluginuid,
                    'coregroupid' => $result->coregroupid,
                    'added' => count($result->added),
                    'removed' => count($result->removed),
                    'refused' => count($result->refused),
                    'extra' => count($result->extra),
                ],
            ];
            if ($actorid > 0) {
                $data['userid'] = $actorid;
            }
            \mod_selfselectadvanced\event\coregroup_synced::create($data)->trigger();
        }
        if ($result->refused) {
            self::flag_sync_refusals($activity, $group, $result->refused);
        }

        return $result;
    }

    /**
     * Create the owned course group for a frozen team.
     *
     * The name format and the description are unchanged (D7-A1: only
     * the membership basis moves). The idnumber makes the mirror's
     * ownership machine-readable after uninstall, which the uninstall
     * promise needs; core refuses a duplicate idnumber with
     * 'idnumbertaken', and a mirror without an idnumber is better than
     * no mirror at all.
     *
     * @param activity $activity the activity
     * @param stdClass $group the plugin group row, read under the lock
     * @return int the new course group id
     */
    private static function mint_core_group(activity $activity, stdClass $group): int {
        $prefix = trim((string) ($activity->cm()->idnumber ?: $activity->name()));
        $data = (object) [
            'courseid' => $activity->courseid(),
            'name' => \core_text::substr('[' . $prefix . '] ' . $group->name, 0, 254),
            'description' => get_string('coregroupdescription', 'mod_selfselectadvanced', $group->pluginuid),
            'descriptionformat' => FORMAT_HTML,
            'idnumber' => $group->pluginuid,
        ];
        try {
            return (int) groups_create_group($data);
        } catch (\moodle_exception $e) {
            unset($data->idnumber);

            return (int) groups_create_group($data);
        }
    }

    /**
     * Split a mirror's live membership against the expected set.
     *
     * The one ownership rule, shared by sync_core_group() and drift():
     * a row may be removed when this plugin tagged it, or when it is
     * untagged but the user has a plugin member row for this team (a
     * legacy export that predates component tagging), or when the
     * caller forces it (GDPR erasure, where the member row is gone).
     * Everything else is a stranger somebody added by hand: reported,
     * never touched.
     *
     * @param int $groupid the plugin group id
     * @param int $coregroupid the mirror
     * @param int[] $expected confirmed members UNION guide
     * @param int[] $forceremove userids the caller forces out
     * @return array{actual: int[], adds: int[], removals: int[], extra: int[]}
     */
    private static function classify_mirror(
        int $groupid,
        int $coregroupid,
        array $expected,
        array $forceremove = []
    ): array {
        global $DB;

        $rows = groups_get_members($coregroupid, 'u.id, gm.component');
        $actual = [];
        $owned = [];
        $untagged = [];
        foreach ($rows as $row) {
            $userid = (int) $row->id;
            $actual[] = $userid;
            if ((string) $row->component === self::COMPONENT) {
                $owned[$userid] = true;
            } else if ((string) $row->component === '') {
                $untagged[$userid] = true;
            }
        }

        $candidates = array_values(array_diff($actual, $expected));
        $reclaim = [];
        $untaggedcandidates = array_values(array_filter(
            $candidates,
            static fn(int $userid): bool => isset($untagged[$userid])
        ));
        if ($untaggedcandidates) {
            // One query for the whole candidate set, never one per row.
            [$insql, $params] = $DB->get_in_or_equal($untaggedcandidates, SQL_PARAMS_NAMED, 'rc');
            $params['groupid'] = $groupid;
            $reclaim = array_flip(array_map('intval', $DB->get_fieldset_select(
                'selfselectadvanced_member',
                'userid',
                "groupid = :groupid AND userid $insql",
                $params
            )));
        }
        $forced = array_flip(array_map('intval', $forceremove));

        $removals = [];
        $extra = [];
        foreach ($candidates as $userid) {
            if (isset($owned[$userid]) || isset($reclaim[$userid]) || isset($forced[$userid])) {
                $removals[] = $userid;
            } else {
                $extra[] = $userid;
            }
        }

        return [
            'actual' => $actual,
            'adds' => array_values(array_diff($expected, $actual)),
            'removals' => $removals,
            'extra' => $extra,
        ];
    }

    /**
     * Ensure the activity grouping exists and holds the mirror.
     *
     * Idempotent and O(1): the membership check is a single record_exists,
     * so a group already in the grouping costs one read. Groupings contain
     * groups, not users, so the guide needs nothing here.
     *
     * @param activity $activity the activity
     * @param int $coregroupid the mirror
     */
    private static function ensure_grouping(activity $activity, int $coregroupid): void {
        global $DB;

        $groupingname = \core_text::substr(
            get_string('groupingname', 'mod_selfselectadvanced', $activity->name()),
            0,
            254
        );
        $grouping = groups_get_grouping_by_name($activity->courseid(), $groupingname);
        $alreadyassigned = $grouping && $DB->record_exists('groupings_groups', [
            'groupingid' => (int) $grouping,
            'groupid' => $coregroupid,
        ]);
        if ($alreadyassigned) {
            return;
        }
        if (!$grouping) {
            $grouping = groups_create_grouping((object) [
                'courseid' => $activity->courseid(),
                'name' => $groupingname,
            ]);
        }
        groups_assign_grouping((int) $grouping, $coregroupid);
    }

    /**
     * Split a bulk-freeze selection into the part one web request
     * handles and the part cron does.
     *
     * Each freeze takes a lock, writes a snapshot and pushes a roster
     * into the course's groups; an unbounded selection put all of that
     * on the web path with no ceiling at all (D7-E1). Kept here rather
     * than in the page so the boundary is testable on its own.
     *
     * @param int[] $groupids the selected plugin group ids
     * @return array{inline: int[], queued: int[]} duplicates collapsed, order preserved
     */
    public static function split_bulk_selection(array $groupids): array {
        $groupids = array_values(array_unique(array_map('intval', $groupids)));

        return [
            'inline' => array_slice($groupids, 0, self::BULK_FREEZE_INLINE_MAX),
            'queued' => array_slice($groupids, self::BULK_FREEZE_INLINE_MAX),
        ];
    }

    /**
     * Freeze a selection of teams: the first BULK_FREEZE_INLINE_MAX in
     * this request, the remainder in one adhoc task.
     *
     * The whole handler lives here, not in guide.php, so that the cap
     * is pinned by a test that fails when the PAGE stops applying it.
     * The defect is an unbounded selection on the web path (D7-E1), and
     * a ceiling the page can silently drop is not a ceiling: a test of
     * the split helper alone stays green while the request goes back to
     * freezing every team a guide holds.
     *
     * A refusal on one team never takes the rest of the batch down with
     * it; the caller reports them.
     *
     * @param activity $activity the activity
     * @param int[] $groupids the selected plugin group ids
     * @param int $actorid the acting guide or manager
     * @return stdClass done (int frozen inline), skipped (string[] label:
     *         reason), queued (int handed to cron)
     */
    public static function bulk_freeze(activity $activity, array $groupids, int $actorid): stdClass {
        $split = self::split_bulk_selection($groupids);
        $done = 0;
        $skipped = [];
        foreach ($split['inline'] as $groupid) {
            // Named before the try: when groups::get() itself throws,
            // the catch used to read an unset variable and label the
            // failure after whichever team the previous iteration had
            // loaded.
            $label = (string) $groupid;
            try {
                $group = groups::get($activity, (int) $groupid);
                $label = format_string($group->name);
                self::freeze_group($activity, $group, $actorid);
                $done++;
            } catch (\moodle_exception $e) {
                $skipped[] = $label . ': ' . $e->getMessage();
            }
        }
        if ($split['queued']) {
            $task = new \mod_selfselectadvanced\task\bulkfreeze_adhoc();
            $task->set_custom_data([
                'activityid' => $activity->id(),
                'groupids' => array_values($split['queued']),
                'actorid' => $actorid,
            ]);
            $task->set_userid($actorid);
            \core\task\manager::queue_adhoc_task($task);
        }

        return (object) [
            'done' => $done,
            'skipped' => $skipped,
            'queued' => count($split['queued']),
        ];
    }

    /**
     * T5: freeze a firm group its assigned guide reviews (spec 12).
     *
     * The envelope, in order: lock, gates, ONE transaction holding only
     * plugin writes (state flip, snapshot, sync request), release, then
     * the core-group sync, the event and the notifications - all three
     * outside every lock and transaction. Re-freezing an already frozen
     * group is a repair: no gates, no state flip, just the sync, whose
     * mint recreates an externally deleted mirror - and NO group_frozen
     * event and NO mail, because the team was frozen once and is
     * announced once.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @param int $actorid the acting guide (must hold the freeze capability)
     * @return stdClass the updated group row with ->sync attached
     * @throws \moodle_exception when a gate refuses
     */
    public static function freeze_group(activity $activity, stdClass $group, int $actorid): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $gatekeeper = new rules\gatekeeper($activity, new override\resolver($activity));

        $outermost = !$DB->is_transaction_started();
        $lock = locks::acquire('group:' . $group->id);
        $violators = [];
        // Declared OUT here so it is still readable after the lock
        // releases: the event and the notifications below are gated on
        // it, and a variable first assigned inside the try would be
        // undefined on every path this method can leave the try by.
        $isrepair = false;
        try {
            $fresh = groups::get($activity, (int) $group->id);
            // An already frozen group is a repair: the mirror is what
            // is broken, not the state, and repairs stay grandfathered
            // past every gate (including the cap audit).
            $isrepair = $fresh->state === state::FROZEN;
            if (!$isrepair) {
                if ($refusal = $gatekeeper->can_freeze($fresh)) {
                    throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
                }
                // WHICH BRANCH, decided by the plugin's one answer to
                // "is this THEIR team?" (audit D5). This line read
                // `(int) $fresh->guideid !== $actorid` until 1.20.1 -
                // the last raw transcription of teamaccess::
                // is_assigned_guide() on a lifecycle write, sitting
                // directly above the comment A-01 wrote about ownership
                // not being a grant.
                //
                // It CHOOSES a branch rather than admitting anybody, so
                // it was never a hole; what it was, was a second answer
                // to a question that has a home, and the one direction
                // it can move an actor is towards the STRICTER branch:
                // a guide for whom :viewassignedteams has been
                // prohibited - who is already refused their own team's
                // page, its review page and its proposal file, and
                // since this wave its approval and its return - now
                // needs :manage or :coordinate to freeze it, instead of
                // being the single surface left that still treated them
                // as its guide.
                if (!teamaccess::is_assigned_guide($activity, $fresh, $actorid)) {
                    // Strategy 1.16 D: a manager or Group Coordinator
                    // may freeze on the guide's behalf - the
                    // coordinator subject to the conflict-of-interest
                    // guard (they must not be involved in the group).
                    $context = $activity->context();
                    $onbehalf = has_capability('mod/selfselectadvanced:manage', $context, $actorid)
                        || has_capability('mod/selfselectadvanced:coordinate', $context, $actorid);
                    if (!$onbehalf) {
                        throw new \moodle_exception('refusalnotassignedguide', 'mod_selfselectadvanced');
                    }
                    tickets::require_uninvolved($activity, $fresh, $actorid);
                } else {
                    // The assigned guide's OWN freeze, and the one branch
                    // of this method that asked for no capability at all
                    // (A-01). Being named in guideid is ownership of the
                    // record; :freeze is the authority to act on it, and
                    // an administrator who prohibits the capability is
                    // saying this person may no longer freeze anything -
                    // including the teams they still guide. The
                    // on-behalf branch above keeps its own authority
                    // (:manage / :coordinate) unchanged: that is a
                    // different grant, documented as such, and not one
                    // this check may quietly widen.
                    authority::require_freeze($activity, $actorid);
                }
                // Good-neighbour membership audit (RCA Q3): freezing
                // is the moment this plugin pushes into the course's
                // groups/grouping mechanism, so a roster carrying a
                // member over their L4 cap (possible only by
                // grandfathering - caps lowered after people joined)
                // skips the push; the flag and the refusal fire below,
                // AFTER the lock releases, because notifier::send
                // drives synchronous mail and must not hold
                // 'group:{id}' hostage to a slow relay.
                $violators = self::membership_cap_violators($activity, $fresh);

                if (!$violators) {
                    // Plugin writes only. The core group is minted by
                    // the sync after the release - and request_sync()
                    // commits the repair job with the state flip, so a
                    // crash in the window between them converges on the
                    // next cron run instead of stranding the team.
                    $transaction = $DB->start_delegated_transaction();
                    $now = time();
                    $fresh->state = state::FROZEN;
                    $fresh->timefrozen = $now;
                    // Whether staff enforced this freeze, recorded now
                    // rather than worked out later (strategy 1.19 C). A
                    // guide may release a team they guide, but not one
                    // an editing teacher or a coordinator froze - and
                    // the question is what was true when the freeze
                    // happened, not who holds what capability today.
                    $fresh->frozenbystaff = self::is_staff($activity, $actorid) ? 1 : 0;
                    $fresh->usermodified = $actorid;
                    $fresh->timemodified = $now;
                    $DB->update_record('selfselectadvanced_group', $fresh);

                    self::append_snapshot($fresh, $actorid);
                    self::request_sync($activity, $fresh);

                    $transaction->allow_commit();
                }
            }
        } catch (\Throwable $e) {
            // Before this the exception escaped with the transaction
            // still open, and guide.php's bulk loop swallowed it and
            // carried on into the next group (D7-E1).
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        if ($violators) {
            self::flag_membership_audit($activity, $fresh, $violators, $actorid);
            throw new \moodle_exception(
                'refusalmembershipaudit',
                'mod_selfselectadvanced',
                '',
                implode(', ', array_map(
                    static fn(stdClass $v) => get_string('membershipauditmember', 'mod_selfselectadvanced', $v),
                    $violators
                ))
            );
        }

        // The sync runs on BOTH paths - on a repair it is the entire
        // point of the call, exactly as group.php's action=resynccore
        // does it.
        $sync = self::sync_core_group($activity, (int) $fresh->id, $actorid);
        $fresh = groups::get($activity, (int) $fresh->id);
        $fresh->sync = $sync;

        if ($isrepair) {
            // A repair is no gates, no state flip, JUST THE SYNC - the
            // docblock above has said so since 1.19, and everything
            // below used to run anyway. Measured before this gate:
            // freezing an already frozen team a second time re-fired
            // group_frozen and re-mailed every confirmed member ('msgs
            // 4->8 group_frozen 1->2'), which two staff clicking Freeze
            // produce through the lock and which
            // task\bulkfreeze_adhoc re-runs on queued overflow. The
            // team was frozen once; it is announced once. The plugin's
            // own repair entry point (group.php action=resynccore)
            // already behaved this way - it calls sync_core_group()
            // alone and notifies nobody.
            return $fresh;
        }

        // Moved out of the transaction (requirement 2). A failed inline
        // sync still leaves the freeze recorded; the adhoc's own
        // coregroup_synced records the mint when it lands.
        \mod_selfselectadvanced\event\group_frozen::create([
            'objectid' => $fresh->id,
            'context' => $activity->context(),
            'other' => [
                'pluginuid' => $fresh->pluginuid,
                'coregroupid' => (int) ($sync->coregroupid ?: 0),
            ],
        ])->trigger();

        $confirmed = $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [$fresh->id, groups::STATUS_CONFIRMED]
        );
        foreach ($confirmed as $userid) {
            notifier::send(
                $activity,
                'groupfrozen',
                (int) $userid,
                'msgfrozensubject',
                'msgfrozenbody',
                (object) ['group' => format_string($fresh->name)],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => $fresh->id,
                ]),
                format_string($fresh->name)
            );
        }

        return $fresh;
    }

    /**
     * T6: unfreeze (manager action): restore the roster to the LATEST
     * snapshot exactly (A6), state back to firm - even if current
     * limits would now reject the roster (grandfathering, spec 4A.8).
     *
     * The mirror and its id are RETAINED (D7-D1). state = FIRM already
     * records that the team is no longer frozen, so nothing needs a
     * schema column to tell the two apart, and a later refreeze reuses
     * the same course group - which means availability conditions,
     * grouping links, group calendar events and the group conversation
     * all survive the freeze -> change -> refreeze workflow. Deleting
     * the mirror is now an explicit manager action, discard_core_group().
     *
     * @param activity $activity the activity
     * @param stdClass $group the frozen group row
     * @param int $actorid the acting manager
     * @param string $reason why the roster is being rewritten; REQUIRED
     *        whenever the restore actually changes a member row, so no
     *        staff roster mutation lands without a per-member record
     *        (decision 6, D6-9). A delta-free release needs none.
     * @return stdClass the updated group row with ->drift and ->sync attached
     * @throws \moodle_exception when the group is not frozen, or when a
     *         restore with a delta carries no reason
     */
    public static function unfreeze(
        activity $activity,
        stdClass $group,
        int $actorid,
        string $reason = ''
    ): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $outermost = !$DB->is_transaction_started();
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($activity, (int) $group->id);
            if ($fresh->state !== state::FROZEN) {
                throw new \moodle_exception('refusalwrongstate', 'mod_selfselectadvanced');
            }

            // Both guards judge the team as it is NOW. Read before the
            // lock, a coordinator's unfreeze/re-freeze in the window
            // between the guide's page load and their click was
            // invisible: frozenbystaff is written from the CURRENT
            // actor at freeze time, so the flag legitimately flips
            // under an open page (T-02 R3).
            //
            // Strategy 1.16 D: the conflict-of-interest rule restrains
            // the NEW coordinate authority only. An actor who could
            // already unfreeze before 1.16.0 - a manager, or a team's
            // own guide holding the unfreeze capability - keeps exactly
            // the authority they had; adding the coordinator role to a
            // site must never quietly take authority away. A
            // coordinator whose own team it is asks through the ticket
            // queue instead.
            if (has_capability('mod/selfselectadvanced:coordinate', $activity->context(), $actorid)) {
                tickets::require_uninvolved($activity, $fresh, $actorid);
            }

            // A guide releasing their own team (strategy 1.19 C). They
            // do not hold the unfreeze capability, so without this they
            // can only ask and wait - and a team cannot be re-composed
            // while it is frozen, which made every ordinary change
            // staff work.
            //
            // The limit is the one the maintainer set: a guide releases
            // until an editing teacher or a coordinator has enforced a
            // freeze. After that the freeze is meant to hold, and the
            // existing unfreeze request is the only way through.
            // The guard restrains the NEW authority and nothing else.
            // This service has always trusted its callers on the
            // capability - the pages enforce it, and every existing
            // caller relies on that. Adding a blanket requirement here
            // took authority away from actors who had it, which is the
            // exact mistake 1.16 and 1.17 each made once and this file
            // records for next time.
            //
            // So only the new case is refused: a guide releasing a team
            // an editing teacher or coordinator froze. That freeze is
            // meant to hold, and the unfreeze request is the way
            // through it.
            //
            // AND THE IDENTITY TEST STAYS RAW HERE, deliberately (audit
            // D5). Everywhere else in the plugin `guideid === actorid`
            // ADMITS, so routing it through teamaccess::
            // is_assigned_guide() can only narrow. This one RESTRAINS:
            // it is the sole clause that refuses. Asking a capability
            // inside it would mean prohibiting :viewassignedteams
            // LIFTED the restraint and handed the guide back a release
            // the maintainer's rule takes away - a predicate whose home
            // is a read gate must never be load-bearing in that
            // direction.
            if (
                (int) $fresh->guideid === $actorid
                && !empty($fresh->frozenbystaff)
                && !authority::may_unfreeze($activity, $actorid)
            ) {
                throw new \moodle_exception('refusalreleasestafffroze', 'mod_selfselectadvanced');
            }

            // THE POSITIVE GATE, and until 1.20.1 there was none (audit
            // A-2). Everything above this line is CONDITIONAL: the
            // frozen-state check, the coordinator's conflict of
            // interest, the one restraint on a guide releasing a
            // staff-enforced freeze. An actor matching no branch -
            // holding :unfreeze no more than they hold :coordinate or
            // :manage, and named in no team's guideid - fell through
            // all three into the roster restore and the state flip. The
            // service's own docblock calls this a "manager action" and
            // the parameter is literally named "the acting manager";
            // the authority behind that sentence was asked by group.php
            // and by nothing else, which made it a property of ONE
            // route rather than of the operation.
            //
            // The gate is the page's door, moved to where the row is
            // read under the lock: hold :unfreeze, or BE the team's
            // assigned guide (whom the clause above has already
            // restrained to teams staff did not freeze). Deliberately
            // no wider - matching group.php exactly is what makes this
            // a MOVE of the check rather than a new rule, so nobody who
            // could release a team before this release cannot now:
            //
            // - editing teacher, manager: :unfreeze by archetype;
            // - Group Coordinator: :unfreeze is in the role this
            // plugin creates (coordinatorrole::capabilities()), and
            // they still pass the conflict-of-interest guard above;
            // - assigned guide, strategy 1.19 C: admitted by identity
            // on their own team, exactly as before.
            //
            // The identity half stays raw for the reason recorded
            // above: routing it through teamaccess::is_assigned_guide()
            // would let a prohibited :viewassignedteams turn an ADMIT
            // into a refusal on the ONE branch where that costs a guide
            // the release the maintainer granted them.
            if ((int) $fresh->guideid !== $actorid) {
                authority::require_unfreeze($activity, $actorid);
            }

            $snapshot = self::latest_snapshot((int) $fresh->id);
            if (!$snapshot) {
                throw new \moodle_exception('errnosnapshot', 'mod_selfselectadvanced');
            }
            $roster = json_decode($snapshot->roster, true) ?: [];
            // Read under the lock so the event payload records the
            // mirror as it stood at the moment of release.
            $drift = self::drift($fresh);

            // Restore the plugin roster to the snapshot exactly.
            $now = time();
            $snapshotids = [];
            $leaderid = (int) $fresh->leaderid;
            foreach ($roster as $entry) {
                $snapshotids[] = (int) $entry['userid'];
                if (!empty($entry['isleader'])) {
                    $leaderid = (int) $entry['userid'];
                }
            }
            $currentconfirmed = array_map('intval', $DB->get_fieldset_select(
                'selfselectadvanced_member',
                'userid',
                'groupid = ? AND status = ?',
                [$fresh->id, groups::STATUS_CONFIRMED]
            ));
            // Both halves of the restore DELTA, computed before any
            // write so the reason gate below judges the same quantity
            // unfreeze_preview() showed on the confirmation page.
            $removedids = array_values(array_diff($currentconfirmed, $snapshotids));
            $addedids = array_values(array_diff($snapshotids, $currentconfirmed));

            // Decision 6, D6-9: unfreeze was the one staff roster
            // rewrite with no per-member record at all - two integers in
            // the event and nothing else. A restore that actually moves
            // somebody now needs a reason, enforced here so the page
            // cannot be the only thing asking. The grandfathering is
            // untouched: nothing is refused because of a LIMIT, and a
            // delta-free release (the ordinary guide-release flow) still
            // needs no reason at all.
            if (($removedids || $addedids) && trim($reason) === '') {
                throw new \moodle_exception('errunfreezereasonrequired', 'mod_selfselectadvanced');
            }

            foreach ($removedids as $userid) {
                $DB->set_field('selfselectadvanced_member', 'status', groups::STATUS_REMOVED, [
                    'groupid' => $fresh->id,
                    'userid' => $userid,
                ]);
            }
            foreach ($roster as $entry) {
                $userid = (int) $entry['userid'];
                $existing = $DB->get_record('selfselectadvanced_member', [
                    'groupid' => $fresh->id,
                    'userid' => $userid,
                ]);
                if ($existing) {
                    $existing->status = groups::STATUS_CONFIRMED;
                    $existing->isleader = (int) !empty($entry['isleader']);
                    $existing->timemodified = $now;
                    $DB->update_record('selfselectadvanced_member', $existing);
                } else {
                    $DB->insert_record('selfselectadvanced_member', (object) [
                        'groupid' => $fresh->id,
                        'userid' => $userid,
                        'status' => groups::STATUS_CONFIRMED,
                        'isleader' => (int) !empty($entry['isleader']),
                        'invitedby' => $actorid,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }

            $fresh->state = state::FIRM;
            $fresh->leaderid = $leaderid;
            // The coregroupid is deliberately NOT cleared: the mirror
            // is retained across unfreeze and reused by the next freeze.
            $fresh->timefrozen = null;
            $fresh->frozenbystaff = 0;
            $fresh->usermodified = $actorid;
            $fresh->timemodified = $now;
            $DB->update_record('selfselectadvanced_group', $fresh);

            self::request_sync($activity, $fresh);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Every refusal above - including the two guards just moved
            // in - throws from INSIDE the transaction, so without this
            // a refused release left a dangling delegated transaction
            // (T-02 R3; the refusalwrongstate path had it already).
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        // Outside the lock and the transaction (requirement 2). The
        // payload now names every member row the restore touched and
        // why (D6-9), so the log answers "who was taken out of this
        // team, and on whose say-so" without a second query.
        $other = [
            'pluginuid' => $fresh->pluginuid,
            'drift' => $drift,
            'added' => $addedids,
            'removed' => $removedids,
            'reason' => trim($reason),
        ];
        \mod_selfselectadvanced\event\group_unfrozen::create([
            'objectid' => $fresh->id,
            'context' => $activity->context(),
            'other' => $other,
        ])->trigger();

        // The mirror now follows the restored roster instead of being
        // deleted with the freeze.
        $sync = self::sync_core_group($activity, (int) $fresh->id, $actorid);

        // The queue never lists work already done: a direct unfreeze
        // resolves the group's live unfreeze ticket (strategy 1.16 B).
        tickets::autoresolve_unfreeze($activity, (int) $fresh->id, $actorid);

        // Members AND the guide (db/messages.php documents both).
        $recipients = $snapshotids;
        if (!empty($fresh->guideid) && !in_array((int) $fresh->guideid, $recipients, true)) {
            $recipients[] = (int) $fresh->guideid;
        }
        foreach ($recipients as $userid) {
            notifier::send(
                $activity,
                'groupunfrozen',
                $userid,
                'msgunfrozensubject',
                'msgunfrozenbody',
                (object) ['group' => format_string($fresh->name)],
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => $fresh->id,
                ]),
                format_string($fresh->name)
            );
        }

        $fresh->drift = $drift;
        $fresh->sync = $sync;

        return $fresh;
    }

    /**
     * Sever the link to the course group and delete it - the only
     * INTERACTIVE place groups_delete_group() may be called.
     *
     * Refused while frozen: a discard there would be re-minted by the
     * very next sync, so the manager unfreezes first. The other
     * sanctioned deletion is the whole-group dissolve path, which
     * deletes the plugin row too and therefore must NOT be routed
     * through this method - it re-acquires group:{id} (a re-entrant
     * acquire self-deadlocks to errlocktimeout) and its delete would
     * land inside the caller's transaction.
     *
     * Capability enforcement lives in the caller, exactly as unfreeze()
     * documents; the service trusts its callers on capability.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @param int $actorid the acting manager
     * @return stdClass oldcoregroupid and the group row
     * @throws \moodle_exception when frozen, or when there is no mirror
     */
    public static function discard_core_group(activity $activity, stdClass $group, int $actorid): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $fresh = groups::get($activity, (int) $group->id);
        if ($fresh->state === state::FROZEN) {
            throw new \moodle_exception('refusaldiscardfrozen', 'mod_selfselectadvanced');
        }

        $outermost = !$DB->is_transaction_started();
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($activity, (int) $group->id);
            if ($fresh->state === state::FROZEN) {
                throw new \moodle_exception('refusaldiscardfrozen', 'mod_selfselectadvanced');
            }
            if (empty($fresh->coregroupid)) {
                throw new \moodle_exception('refusalnodiscardtarget', 'mod_selfselectadvanced');
            }
            $oldid = (int) $fresh->coregroupid;
            $DB->set_field('selfselectadvanced_group', 'coregroupid', null, ['id' => $fresh->id]);
            $fresh->coregroupid = null;

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The refusalnodiscardtarget throw is user-reachable and
            // comes from INSIDE the transaction, so without this a
            // refused discard leaves the transaction open.
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        // A crash between the commit and here leaves an orphaned course
        // group that still carries the plugin uid as its idnumber -
        // strictly better than a dangling pointer, and invisible to the
        // flagged report because coregroupid is already null.
        if (groups_group_exists($oldid)) {
            groups_delete_group($oldid);
        }

        $data = [
            'objectid' => (int) $fresh->id,
            'context' => $activity->context(),
            'other' => ['pluginuid' => $fresh->pluginuid, 'oldcoregroupid' => $oldid],
        ];
        if ($actorid > 0) {
            $data['userid'] = $actorid;
        }
        \mod_selfselectadvanced\event\coregroup_discarded::create($data)->trigger();

        return (object) ['group' => $fresh, 'oldcoregroupid' => $oldid];
    }

    /**
     * Confirmed members of the group whose plugin membership count
     * exceeds their effective L4 cap (RCA Q3). Violations only arise
     * by grandfathering - the cap was lowered, or an override removed,
     * after people had already joined; every join path enforces L4.
     *
     * Two queries for the whole roster, never one COUNT per member: the
     * roster read, then ONE grouped count over exactly those userids
     * (the same counting basis groups::count_memberships() uses). The
     * resolver loads and caches every override row of the activity on
     * its first call, so the per-member cap lookup issues no queries.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @return stdClass[] objects with userid, fullname, current, max
     */
    public static function membership_cap_violators(activity $activity, stdClass $group): array {
        global $DB;

        $resolver = new override\resolver($activity);
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $members = $DB->get_records_sql(
            "SELECT u.id, $namefields
               FROM {selfselectadvanced_member} m
               JOIN {user} u ON u.id = m.userid
              WHERE m.groupid = :groupid AND m.status = :confirmed
           ORDER BY u.lastname, u.firstname",
            ['groupid' => $group->id, 'confirmed' => groups::STATUS_CONFIRMED]
        );
        if (!$members) {
            return [];
        }

        $counts = [];
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', array_keys($members)), SQL_PARAMS_NAMED, 'cap');
        $params['activityid'] = $activity->id();
        $params['confirmed'] = groups::STATUS_CONFIRMED;
        $rows = $DB->get_records_sql(
            "SELECT m2.userid, COUNT(1) AS cnt
               FROM {selfselectadvanced_member} m2
               JOIN {selfselectadvanced_group} g2 ON g2.id = m2.groupid
              WHERE g2.activityid = :activityid AND m2.status = :confirmed
                AND m2.userid $insql
           GROUP BY m2.userid",
            $params
        );
        foreach ($rows as $row) {
            $counts[(int) $row->userid] = (int) $row->cnt;
        }

        $violators = [];
        foreach ($members as $member) {
            $cap = $resolver->effective_maxmembership((int) $member->id)->value;
            $count = $counts[(int) $member->id] ?? 0;
            if ($count > $cap) {
                $violators[] = (object) [
                    'userid' => (int) $member->id,
                    'fullname' => fullname($member),
                    'current' => $count,
                    'max' => $cap,
                ];
            }
        }

        return $violators;
    }

    /**
     * Flag a refused push to every manager (provider capaudit): the
     * group stays unpushed until they raise the activity cap or grant
     * per-user overrides - the plugin never raises a cap itself.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @param stdClass[] $violators from membership_cap_violators()
     * @param int $actorid the guide whose freeze was refused
     */
    public static function flag_membership_audit(
        activity $activity,
        stdClass $group,
        array $violators,
        int $actorid
    ): void {
        $memberlist = implode(', ', array_map(
            static fn(stdClass $v) => get_string('membershipauditmember', 'mod_selfselectadvanced', $v),
            $violators
        ));
        $guide = \core_user::get_user($actorid);
        // The repair for an over-cap roster is to move somebody, raise
        // the cap or grant an override - so the people who hold the
        // narrow composition capability are told as well as the
        // managers, deduplicated (a manager holding both is one
        // recipient). Names only, never an email or a phone number.
        foreach (
            notifier::recipients($activity, [
                'mod/selfselectadvanced:manage',
                'mod/selfselectadvanced:managecomposition',
            ]) as $manager
        ) {
            notifier::send(
                $activity,
                'capaudit',
                (int) $manager->id,
                'msgcapauditsubject',
                'msgcapauditbody',
                (object) [
                    'group' => format_string($group->name),
                    'members' => $memberlist,
                    'guide' => $guide ? fullname($guide) : '',
                ],
                new \moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $activity->cm()->id]),
                format_string($group->name)
            );
        }
    }

    /**
     * Tell every manager which people core refused to put in the mirror.
     *
     * A refused add is never silent (D7-E2): core returns false for a
     * deleted account and for anyone with no enrolment in the course,
     * including a guide who holds the capability through a category or
     * system role. Names only - no email, no phone.
     *
     * @param activity $activity the activity
     * @param stdClass $group the group row
     * @param int[] $refused the userids core would not add
     */
    private static function flag_sync_refusals(activity $activity, stdClass $group, array $refused): void {
        $names = [];
        foreach ($refused as $userid) {
            $user = \core_user::get_user((int) $userid);
            $names[] = $user ? fullname($user) : (string) $userid;
        }
        $a = (object) [
            'group' => format_string($group->name),
            'members' => implode(', ', $names),
        ];
        foreach (get_users_by_capability($activity->context(), 'mod/selfselectadvanced:manage', 'u.id') as $manager) {
            notifier::send(
                $activity,
                'capaudit',
                (int) $manager->id,
                'msgcoresyncrefusedsubject',
                'msgcoresyncrefusedbody',
                $a,
                new \moodle_url('/mod/selfselectadvanced/group.php', [
                    'id' => $activity->cm()->id,
                    'g' => (int) $group->id,
                ]),
                format_string($group->name)
            );
        }
    }

    /**
     * Course modules and sections whose availability references the
     * mirrored core group (the spec-12 unfreeze warning).
     *
     * @param activity $activity the activity
     * @param stdClass $group the frozen group row
     * @return string[] human-readable references
     */
    public static function check_restrictions(activity $activity, stdClass $group): array {
        global $DB;

        if (empty($group->coregroupid)) {
            return [];
        }
        $needle = '"type":"group","id":' . (int) $group->coregroupid;
        $references = [];
        $cms = $DB->get_records_select(
            'course_modules',
            'course = ? AND ' . $DB->sql_like('availability', '?'),
            [$activity->courseid(), '%' . $DB->sql_like_escape($needle) . '%']
        );
        // Hoisted out of the loop: the course cache is the same object
        // for every row, and building it per module was the D7-D1
        // evidence's one-line cost.
        $modinfo = $cms ? get_fast_modinfo($activity->courseid()) : null;
        foreach ($cms as $cm) {
            $references[] = get_string(
                'restrictionreferencecm',
                'mod_selfselectadvanced',
                $modinfo->cms[$cm->id]->name ?? $cm->id
            );
        }
        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND ' . $DB->sql_like('availability', '?'),
            [$activity->courseid(), '%' . $DB->sql_like_escape($needle) . '%']
        );
        foreach ($sections as $section) {
            $references[] = get_string('restrictionreferencesection', 'mod_selfselectadvanced', $section->section);
        }

        return $references;
    }

    /**
     * How far the mirror has drifted from what it should hold.
     *
     * Expected is expected_core_members() - confirmed members UNION the
     * assigned guide - never the snapshot roster, which does not and
     * must not contain the guide. A pure report: no writes, no
     * re-inserts. 'extra' is STRANGERS ONLY (rows this plugin does not
     * own and may not touch); 'repairable' is what a resync would fix.
     * On a healthy frozen team every list is empty.
     *
     * This is the CORE-MIRROR report. It is not, and must not be
     * re-coupled to, the unfreeze restore delta (snapshot roster vs
     * live confirmed roster), which is a different quantity and is
     * frequently non-zero on a perfectly healthy team.
     *
     * @param stdClass $group the group row
     * @return array{extra: int[], missing: int[], repairable: int[]} userids
     */
    public static function drift(stdClass $group): array {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        if (empty($group->coregroupid) || !groups_group_exists((int) $group->coregroupid)) {
            return ['extra' => [], 'missing' => [], 'repairable' => []];
        }
        $expected = self::expected_core_members($group);
        $split = self::classify_mirror((int) $group->id, (int) $group->coregroupid, $expected);

        return [
            'extra' => array_values($split['extra']),
            'missing' => array_values($split['adds']),
            'repairable' => array_values(array_merge($split['adds'], $split['removals'])),
        ];
    }

    /**
     * What unfreeze() would change: the RESTORE DELTA between the live
     * confirmed roster and the snapshot it would be restored to.
     *
     * The page and the service must compute the SAME quantity or the
     * reason field is optional exactly when the service is about to
     * demand it. This is that one quantity, read-only, reading nothing
     * from core and mutating nothing.
     *
     * Deliberately NOT drift(): drift() is the core-MIRROR health
     * report (expected core members versus the course group), which is
     * normally zero on a healthy frozen team and says nothing about
     * what a restore would do to the plugin roster.
     *
     * @param activity $activity the activity
     * @param stdClass $group the frozen group row
     * @return array{removed: int[], added: int[]} userids the restore would
     *         take out of, and put back into, the confirmed roster
     */
    public static function unfreeze_preview(activity $activity, stdClass $group): array {
        global $DB;

        // Server-side ownership of the id, as every read path here does.
        $group = groups::get($activity, (int) $group->id);
        $snapshot = self::latest_snapshot((int) $group->id);
        if (!$snapshot) {
            return ['removed' => [], 'added' => []];
        }
        $snapshotids = [];
        foreach (json_decode($snapshot->roster, true) ?: [] as $entry) {
            $snapshotids[] = (int) $entry['userid'];
        }
        $currentconfirmed = array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_member',
            'userid',
            'groupid = ? AND status = ?',
            [(int) $group->id, groups::STATUS_CONFIRMED]
        ));

        return [
            'removed' => array_values(array_diff($currentconfirmed, $snapshotids)),
            'added' => array_values(array_diff($snapshotids, $currentconfirmed)),
        ];
    }

    /**
     * The newest snapshot of a group.
     *
     * @param int $groupid the group
     * @return stdClass|null
     */
    public static function latest_snapshot(int $groupid): ?stdClass {
        global $DB;

        $rows = $DB->get_records('selfselectadvanced_snapshot', ['groupid' => $groupid], 'id DESC', '*', 0, 1);

        return $rows ? reset($rows) : null;
    }

    /**
     * Append a roster snapshot for a group (A6, append-only history;
     * the newest row is what unfreeze restores).
     *
     * @param stdClass $group the group row
     * @param int $actorid the acting user
     * @return stdClass the snapshot row
     */
    public static function append_snapshot(stdClass $group, int $actorid): stdClass {
        global $DB;

        $roster = [];
        foreach (
            $DB->get_records('selfselectadvanced_member', [
                'groupid' => $group->id,
                'status' => groups::STATUS_CONFIRMED,
            ], 'id ASC', 'userid, isleader') as $member
        ) {
            $roster[] = ['userid' => (int) $member->userid, 'isleader' => (int) $member->isleader];
        }

        $snapshot = (object) [
            'groupid' => (int) $group->id,
            'coregroupid' => (int) ($group->coregroupid ?? 0),
            'roster' => json_encode($roster),
            'takenby' => $actorid,
            'timecreated' => time(),
        ];
        $snapshot->id = $DB->insert_record('selfselectadvanced_snapshot', $snapshot);

        return $snapshot;
    }

    /**
     * Whether an actor counts as staff for the freeze record.
     *
     * Editing teachers and coordinators are the two roles whose freeze
     * a guide may not undo (strategy 1.19 C).
     *
     * @param activity $activity the activity
     * @param int $actorid the actor
     * @return bool true when they hold manage or coordinate
     */
    private static function is_staff(activity $activity, int $actorid): bool {
        $context = $activity->context();

        return has_capability('mod/selfselectadvanced:manage', $context, $actorid)
            || has_capability('mod/selfselectadvanced:coordinate', $context, $actorid);
    }
}
