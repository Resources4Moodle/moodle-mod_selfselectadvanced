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

namespace mod_selfselectadvanced\local\penalty;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\rules\gatekeeper;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\workflow_refusal;
use stdClass;

/**
 * The authoritative per-group penalty ledger (spec 11, D5, A12): one
 * current row per approved group, recomputed in place on approval,
 * settings changes and the nightly reconciliation; explicit zero rows
 * are stored for on-time groups. The gradebook deducts each group's
 * penalty cumulatively per confirmed member, floored at zero; students
 * in no firm or frozen group keep a null grade until placed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ledger {
    /**
     * Compute and store the current penalty of one approved group,
     * firing penalty_recomputed when the value changes.
     *
     * @param activity $activity the activity
     * @param stdClass $group the approved group row
     * @param resolver|null $resolver reuse a resolver, or build one
     * @param bool $callerserialises true when the caller already holds
     *        a lock covering this group (recompute_all's activity lock)
     * @param array|null $deferred when an array is passed, the
     *        penalty_recomputed payload is COLLECTED into it instead of
     *        being triggered, for a caller that will fire it after
     *        releasing its own lock. MANDATORY when $callerserialises
     *        is true, because then the release is the caller's to make
     *        and this method has no lock-free moment of its own
     * @return stdClass the ledger row
     * @throws \coding_exception when a serialising caller does not
     *         collect the events
     */
    public static function upsert_for_group(
        activity $activity,
        stdClass $group,
        ?resolver $resolver = null,
        bool $callerserialises = false,
        ?array &$deferred = null
    ): stdClass {
        global $DB;

        if ($callerserialises && $deferred === null) {
            // Requirement 2 made structural rather than remembered. A
            // caller holding its own lock cannot let this method
            // dispatch: the event would fire under that lock, which is
            // exactly the defect this signature exists to prevent.
            throw new \coding_exception(
                'ledger::upsert_for_group: a caller that serialises must collect the deferred events'
            );
        }

        $resolver = $resolver ?? new resolver($activity);
        $penalty = calculator::compute($activity, $group, $resolver);

        // The ledger's one-row-per-group invariant is backed by a
        // foreign-unique key (db/install.xml, fk_groupid), so a first
        // approval racing the nightly recompute_all did not corrupt -
        // it threw, on the approving guide's request, after the
        // approval had already committed (T-02 R8). Serialise instead
        // of catching: on PostgreSQL a constraint violation aborts the
        // whole transaction, so a catch-and-retry-as-update is not
        // available to callers who hold one. state::approve() has
        // already released its own group lock by the time it calls us,
        // so this is a fresh acquire, not a nesting.
        $lock = $callerserialises ? null : locks::acquire('group:' . (int) $group->id);
        try {
            $row = $DB->get_record('selfselectadvanced_penalty', ['groupid' => $group->id]);
            $oldvalue = $row ? (float) $row->penaltyvalue : null;
            $isnew = !$row;
            if ($isnew) {
                $row = (object) ['activityid' => $activity->id(), 'groupid' => (int) $group->id];
            }
            $row->dayslate = $penalty->dayslate;
            $row->penaltyvalue = $penalty->penaltyvalue;
            $row->waived = $penalty->waived ? 1 : 0;
            $row->waivereason = $penalty->waivereason;
            $row->basis = $penalty->basis;
            $row->timecomputed = time();
            if ($isnew) {
                $row->id = $DB->insert_record('selfselectadvanced_penalty', $row);
            } else {
                $DB->update_record('selfselectadvanced_penalty', $row);
            }
        } finally {
            if ($lock) {
                $lock->release();
            }
        }

        // The event stays OUTSIDE every lock - which, when the caller
        // is the one holding it, means handing the payload back rather
        // than dispatching here. Before 1.20 this branch simply
        // triggered, and it was only lock-free on the $callerserialises
        // = false path: driven from recompute_all() the observer
        // recorded locks::held_count() = 1 on every dispatch, up to one
        // logstore write per approved group - about 1500 on the target
        // site - inside one activity-wide lock.
        if ($oldvalue === null || abs($oldvalue - (float) $row->penaltyvalue) > 0.000001) {
            $payload = [
                'objectid' => $row->id,
                'context' => $activity->context(),
                'other' => [
                    'groupid' => (int) $group->id,
                    'oldvalue' => $oldvalue,
                    'newvalue' => (float) $row->penaltyvalue,
                ],
            ];
            if ($deferred === null) {
                \mod_selfselectadvanced\event\penalty_recomputed::create($payload)->trigger();
            } else {
                $deferred[] = $payload;
            }
        }

        return $row;
    }

    /**
     * Recompute every approved group of the activity (settings edits,
     * override changes, the reconciliation task) and push grades.
     *
     * @param activity $activity the activity
     * @return int number of groups recomputed
     */
    public static function recompute_all(activity $activity): int {
        global $DB;

        $resolver = new resolver($activity);
        $groups = $DB->get_records_select(
            'selfselectadvanced_group',
            'activityid = ? AND timeapproved IS NOT NULL',
            [$activity->id()]
        );

        // ONE lock for the whole sweep, not one per group. This runs
        // synchronously from selfselectadvanced_update_instance() over
        // every approved group - ~1500 of them on the target site - so
        // a per-group acquire would put 1500 lock round trips inside a
        // teacher's Save and display, and locks::acquire() throws
        // errlocktimeout after 10s with no per-group catch: one
        // contended group would abort the sweep part-way, leaving some
        // groups recomputed, the rest not, and push_grades() never run
        // at all. Rank 6 with nothing else held, so the order is legal.
        $deferred = [];
        $lock = locks::acquire('activity:' . $activity->id());
        try {
            foreach ($groups as $group) {
                self::upsert_for_group($activity, $group, $resolver, true, $deferred);
            }
        } finally {
            $lock->release();
        }

        // Requirement 2: dispatched with nothing held. One event per
        // group whose penalty MOVED, so on the target site's ~1500
        // approved groups a settings edit that changes every penalty
        // used to put 1500 logstore writes - plus whatever any site
        // observer does - inside the lock above, with every student
        // write on the activity queued behind them.
        foreach ($deferred as $payload) {
            \mod_selfselectadvanced\event\penalty_recomputed::create($payload)->trigger();
        }

        // A grade-API write, and never under the lock.
        self::push_grades($activity);

        return count($groups);
    }

    /**
     * Set or clear the guide-awarded group mark and republish grades:
     * the ACTOR-AWARE mutation seam for the one number in this plugin
     * that lands directly in a student's gradebook.
     *
     * Before 1.20 this method was a bare writer. It took no actor, so
     * it asked for no authority; it took a group ROW, so it never
     * checked that the row belonged to the activity it was handed; and
     * it took the group lock only on the path that had to CREATE the
     * ledger row, so the far commoner path - correcting an existing
     * award - wrote unserialised. Measured (A-06): an award written
     * while $USER was an unrelated student, and activity A accepted
     * together with a team belonging to activity B, producing a penalty
     * row owned by A for B's group. review.php's own gate stayed green
     * while its binding was mutated to grade unconditionally, because
     * the gate the test exercised was in the gatekeeper and the page
     * was the only thing that called it.
     *
     * The envelope, in order: acquire the group lock; re-read the team
     * THROUGH THE ACTIVITY (groups::get is activity-scoped and
     * MUST_EXIST, so a foreign team is a missing record, not a
     * cross-activity write); authorise the actor against the team as it
     * IS, not as the caller remembers it; write; release; and only then
     * publish the deferred events and push the grades - neither of
     * which may travel under a lock.
     *
     * @param activity $activity the activity that must own the team
     * @param stdClass $group the group; re-read under the lock, so a
     *        stale copy is safe and a foreign one is refused
     * @param float|null $award the mark, null clears
     * @param int $actorid the person setting the award
     * @throws \dml_missing_record_exception when the team is not this
     *         activity's
     * @throws \moodle_exception when the actor may not grade this team,
     *         or the team is not firm or frozen
     */
    public static function set_award(
        activity $activity,
        stdClass $group,
        ?float $award,
        int $actorid
    ): void {
        global $DB;

        $gatekeeper = new gatekeeper($activity, new resolver($activity));
        $deferred = [];

        $lock = locks::acquire('group:' . (int) $group->id);
        try {
            // Activity scope and freshness in one read. Every check
            // below judges $fresh; $group is never trusted again.
            $fresh = groups::get($activity, (int) $group->id);

            // The SAME predicate review.php renders its form from, so
            // the page cannot grant more than the service allows: a
            // binding mutated to grade unconditionally now meets this.
            if ($refusal = $gatekeeper->can_grade_team($fresh, $actorid)) {
                throw new workflow_refusal($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
            // An award belongs to a team that has been approved. The
            // page has always drawn the field for firm and frozen teams
            // only; saying so here is what makes that a rule rather
            // than a rendering habit - and an award on a forming team
            // would also wedge it, since dissolve_group() refuses to
            // close any team carrying one.
            if (!in_array($fresh->state, [state::FIRM, state::FROZEN], true)) {
                throw new workflow_refusal('refusalwrongstate', 'mod_selfselectadvanced');
            }

            $transaction = $DB->start_delegated_transaction();
            $row = $DB->get_record('selfselectadvanced_penalty', [
                'activityid' => $activity->id(),
                'groupid' => (int) $fresh->id,
            ]);
            if (!$row) {
                // The $callerserialises flag: we hold group:{id}
                // already and locks are not re-entrant, so letting
                // upsert acquire it again would self-deadlock to
                // errlocktimeout. The deferred array is mandatory with
                // it, and is drained after the release below.
                $row = self::upsert_for_group($activity, $fresh, null, true, $deferred);
            }
            $DB->set_field('selfselectadvanced_penalty', 'award', $award, ['id' => $row->id]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The envelope's last clause, and the one the first cut of
            // this method left out: a throw between the open and the
            // commit used to walk out of here leaving the transaction
            // on the stack for the request to dispose of. That matters
            // more now than it did, because review.php CATCHES what
            // this throws and redirects with a notification instead of
            // fataling - so the unwound half-write has to be undone
            // HERE, while there is still a transaction object to undo
            // it with. calculator::compute() throwing on a team with no
            // timeapproved, and any deadlock or constraint failure on
            // either write below the open, are the reachable ones.
            //
            // Unconditional rather than gated on an "outermost" flag:
            // this seam's callers are review.php, the seed tool and the
            // tests, none of which opens a transaction, so it is always
            // the outermost one. NOT decided with
            // $DB->is_transaction_started(), which is unconditionally
            // true under PHPUnit on PostgreSQL and false on MariaDB.
            // Should a caller ever nest it, Moodle rolls the whole
            // stack back and rethrows, and their own is_disposed()
            // guard - the idiom invitations::confirm_leave() uses - is
            // what keeps that honest.
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        foreach ($deferred as $payload) {
            \mod_selfselectadvanced\event\penalty_recomputed::create($payload)->trigger();
        }

        // A grade-API write, and never under the lock.
        self::push_grades($activity);
    }

    /**
     * Publish every affected member's grade with its
     * sequence-of-joining breakdown as feedback.
     *
     * @param activity $activity the activity
     * @param int $userid one user, or 0 for everyone
     */
    public static function push_grades(activity $activity, int $userid = 0): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $settings = $activity->settings();

        $params = [
            'activityid' => $activity->id(),
            'confirmed' => groups::STATUS_CONFIRMED,
            'firm' => state::FIRM,
            'frozen' => state::FROZEN,
        ];
        $usersql = '';
        if ($userid) {
            $usersql = ' AND m.userid = :userid';
            $params['userid'] = $userid;
        }
        // Decision 59 (2026-08-05): grade the CURRENT confirmed roster
        // of firm/frozen teams, not the roster present at approval. A
        // student joining a released late team inherits its penalty; a
        // student leaving stops carrying it. An exception for a person
        // belongs in the gradebook, where the teacher can judge it.
        $sql = "SELECT DISTINCT m.userid
                  FROM {selfselectadvanced_member} m
                  JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                 WHERE g.activityid = :activityid
                   AND m.status = :confirmed
                   AND g.state IN (:firm, :frozen)
                   $usersql";
        $userids = array_map('intval', $DB->get_fieldset_sql($sql, $params));

        // 1.4.0: sequence-of-joining decomposition; the per-step
        // breakdown travels as gradebook feedback. Computed for every
        // listed student in one batched pass (gradebook::compute_activity())
        // rather than one gradebook query per student, since this runs
        // for every confirmed member of the activity on every settings
        // change and in the nightly reconciliation task.
        $grades = [];
        $computedall = gradebook::compute_activity($activity, $userids);
        foreach ($userids as $graded) {
            $computed = $computedall[$graded];
            $grades[$graded] = (object) [
                'userid' => $graded,
                'rawgrade' => $computed->grade,
                'feedback' => \html_writer::alist($computed->steps, ['class' => 'selfselectadvanced-gradesteps']),
                'feedbackformat' => FORMAT_HTML,
            ];
        }

        // Members who lost their last firm/frozen membership revert to
        // a null grade (not zero) until placed again: null out any user
        // holding a final grade who no longer appears in the totals.
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'selfselectadvanced',
            'iteminstance' => $activity->id(),
            'courseid' => $activity->courseid(),
        ]);
        if ($item) {
            foreach ($item->get_final() as $gradeduser => $final) {
                $gradeduser = (int) $gradeduser;
                if ($userid && $gradeduser !== $userid) {
                    continue;
                }
                if (!isset($grades[$gradeduser]) && $final->finalgrade !== null) {
                    $grades[$gradeduser] = (object) ['userid' => $gradeduser, 'rawgrade' => null];
                }
            }
        }

        if ($grades) {
            grade_update(
                'mod/selfselectadvanced',
                $activity->courseid(),
                'mod',
                'selfselectadvanced',
                $activity->id(),
                0,
                $grades,
                ['itemname' => $settings->name, 'grademax' => (float) $settings->grade, 'grademin' => 0]
            );
        }
    }
}
