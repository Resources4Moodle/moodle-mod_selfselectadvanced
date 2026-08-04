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
use mod_selfselectadvanced\local\rules\gatekeeper;
use stdClass;

/**
 * The private guide-notes service: the ACTOR-AWARE mutation seam for
 * the rich text a guide keeps about a team before accepting it (1.3.0).
 *
 * Until 1.20.4 the notes half of review.php was the page's last direct
 * table write (AUTH-002): the award half moved into
 * penalty\ledger::set_award() in A-06, and the notes stayed behind as
 * a bare $DB->update_record() authorised by a predicate computed on
 * the row the page had loaded. That admits exactly the race the award
 * fix closed one branch away - guide A loads the page, the team is
 * reassigned, and A's stale POST overwrites the new guide's notes with
 * no lock, no re-read, no transaction and no trace in the log.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guidenotes {
    /**
     * Save a team's guide notes, text and format in one write.
     *
     * The envelope is set_award()'s, because the two are the same
     * screen and the same authority: acquire the group lock; re-read
     * the team THROUGH THE ACTIVITY (groups::get is activity-scoped
     * and MUST_EXIST, so a foreign team is a missing record, not a
     * cross-activity write); ask can_grade_team() about the actor on
     * the row as it IS, not as the page remembers it; write both note
     * fields together; and dispatch the event only after the commit
     * AND the release (the binding rule for new code -
     * docs/architecture.md, "Events under a lock"; store::save() is
     * the worked example).
     *
     * Unlike can_approve() this asks nothing about lifecycle state:
     * notes are kept while the review is in progress and reread long
     * after it, which is the behaviour the page has always had.
     *
     * @param activity $activity the activity that must own the team
     * @param stdClass $group the team; re-read under the lock, so a
     *        stale copy is safe and a foreign one is refused
     * @param string $notes the notes text, may be empty to clear
     * @param int $notesformat text format of the notes
     * @param int $actorid the guide or manager writing them
     * @throws \dml_missing_record_exception when the team is not this
     *         activity's
     * @throws \moodle_exception when the actor may not grade this team
     */
    public static function save(
        activity $activity,
        stdClass $group,
        string $notes,
        int $notesformat,
        int $actorid
    ): void {
        global $DB;

        $gatekeeper = new gatekeeper($activity, new resolver($activity));

        $lock = locks::acquire('group:' . (int) $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Activity scope and freshness in one read. Every check
            // below judges $fresh; $group is never trusted again.
            $fresh = groups::get($activity, (int) $group->id);

            // The SAME predicate review.php renders its form from, so
            // the page cannot grant more than the service allows - and
            // asked about the row under the lock, so a reassignment
            // that lands between the page load and this POST refuses
            // the stale author instead of letting them overwrite the
            // new guide's notes.
            if ($refusal = $gatekeeper->can_grade_team($fresh, $actorid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => (int) $fresh->id,
                'guidenotes' => $notes,
                'guidenotesformat' => $notesformat,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);

            // Payload built INSIDE the critical section, dispatched
            // after the commit AND the release below. The notes text
            // itself deliberately stays out of the payload: they are
            // the guide's private working notes, and the log records
            // that they changed, not what they say.
            $event = \mod_selfselectadvanced\event\guide_notes_updated::create([
                'objectid' => (int) $fresh->id,
                'context' => $activity->context(),
                'userid' => $actorid,
                'other' => ['pluginuid' => $fresh->pluginuid],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The authority refusal throws from INSIDE the transaction
            // on a row read INSIDE the lock, and review.php catches
            // what this throws to redirect with a notification, so the
            // unwound half-write is undone here while there is still a
            // transaction object to undo it with. Unconditional - see
            // penalty\ledger::set_award().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $event->trigger();
    }
}
