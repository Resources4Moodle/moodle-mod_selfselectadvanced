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
 * The team's written proposal: the one place the proposal file area is
 * WRITTEN, and the one place the question "may this actor write it?"
 * is asked.
 *
 * Reading the file is a different question with a different home -
 * teamaccess::may_read_proposal(), where the whole file policy lives
 * (audit A-05). This class is only about the write.
 *
 * Why it exists (AUTH-002). Until 1.20.3 group.php decided on the raw
 * leaderid, ran file_save_draft_area_files() inline, and there was no
 * service to call and nothing to test but the page. Under decision 38 a
 * leader whose :creategroup has been prohibited is STILL the leader of
 * record, so the raw identity test admitted exactly the actor an
 * administrator had just refused - and a direct POST skipped the page
 * anyway.
 *
 * The finding named the authority hole. Opening the branch showed a
 * second one beside it, which is recorded here because the fix chose an
 * answer for it:
 *
 * THERE WAS NO LIFECYCLE CHECK AT ALL. The branch tested identity and
 * nothing else, so a leader could replace the proposal of a
 * PENDING_GUIDE team while its guide was reading it to decide, of a
 * FIRM team after it had been approved on the strength of it, and of a
 * FROZEN team whose membership is mirrored into a course group. The
 * proposal is the document the guide judges (proposalrequired gates
 * can_submit for exactly that reason), and decisions 39/40 already say
 * that nothing about a PENDING_GUIDE team is the students' to change
 * while the guide decides. So:
 *
 * - THE LEADER may write the proposal while the team is FORMING, and
 *   at no other time. A returned team comes back to FORMING, which is
 *   where a proposal the guide sent back is meant to be fixed.
 * - A :manage HOLDER may write it in any state. Replacing a wrong or
 *   corrupt file after approval is a staff repair, and it is the only
 *   route left once the leader's window has closed. This is unchanged
 *   behaviour for staff, deliberately: the narrowing above is aimed at
 *   the student path the finding is about.
 *
 * And the authority is asked on one half of the verb only, per the
 * project's F3 invariant that an actor is never blocked from making
 * themselves LESS visible:
 *
 * - PUBLISHING - saving a draft area that contains a file, whether the
 *   first upload or a replacement - is leader authority.
 * - RETRACTING - saving an EMPTY draft area, which is how the file
 *   manager expresses "delete my proposal" - is not. A leader whose
 *   capability was withdrawn mid-activity must still be able to take
 *   their own document back off the team page.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class proposal {
    /** @var string The file area the proposal lives in. */
    public const FILEAREA = 'proposal';

    /** @var string Staff authority over the proposal, in any state. */
    public const MANAGE = 'mod/selfselectadvanced:manage';

    /**
     * May this actor upload or REPLACE this team's proposal?
     *
     * The predicate the page draws its control from, so that the
     * control and the service give the same answer.
     *
     * @param activity $activity the activity
     * @param stdClass $group the team
     * @param int $actorid the person acting
     * @return bool true when a file may be saved into the area
     */
    public static function may_publish(activity $activity, stdClass $group, int $actorid): bool {
        if (has_capability(self::MANAGE, $activity->context(), $actorid)) {
            return true;
        }

        return (int) $group->leaderid === $actorid
            && $group->state === state::FORMING
            && authority::may_lead($activity, $actorid);
    }

    /**
     * May this actor REMOVE this team's proposal?
     *
     * Wider than may_publish() by exactly one case, and that case is
     * the point of the F3 invariant: the leader of record of a forming
     * team whose :creategroup has been prohibited. They may not publish
     * and they may still retract.
     *
     * @param activity $activity the activity
     * @param stdClass $group the team
     * @param int $actorid the person acting
     * @return bool true when the area may be emptied
     */
    public static function may_retract(activity $activity, stdClass $group, int $actorid): bool {
        if (has_capability(self::MANAGE, $activity->context(), $actorid)) {
            return true;
        }

        return (int) $group->leaderid === $actorid && $group->state === state::FORMING;
    }

    /**
     * How many files the proposal area currently holds.
     *
     * @param activity $activity the activity
     * @param int $groupid the team
     * @return int file count, directories excluded
     */
    public static function count_files(activity $activity, int $groupid): int {
        return count(get_file_storage()->get_area_files(
            $activity->context()->id,
            'mod_selfselectadvanced',
            self::FILEAREA,
            $groupid,
            'id',
            false
        ));
    }

    /**
     * Save a submitted draft area into the team's proposal area.
     *
     * @param activity $activity the activity
     * @param int $groupid the team
     * @param int $draftitemid the actor's submitted draft area
     * @param array $fileoptions file manager options used to prepare the draft
     * @param int $actorid the person acting; owns the draft area
     * @return int the number of files the proposal area holds afterwards
     * @throws \moodle_exception on any refusal
     * @throws \required_capability_exception when PUBLISHING without authority
     */
    public static function save(
        activity $activity,
        int $groupid,
        int $draftitemid,
        array $fileoptions,
        int $actorid
    ): int {
        global $DB;

        // Which half of the verb this is, decided BEFORE the write from
        // what the actor actually submitted rather than from what the
        // page thought they were doing: an empty draft area is a
        // deletion, anything else is a publication.
        $incoming = get_file_storage()->get_area_files(
            \context_user::instance($actorid)->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false
        );
        $publishing = !empty($incoming);

        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Re-read inside the lock. Leadership can be transferred
            // and the team can be submitted between opening the form
            // and saving it, and both of those decide this question.
            $group = groups::get($activity, $groupid);
            $may = $publishing
                ? self::may_publish($activity, $group, $actorid)
                : self::may_retract($activity, $group, $actorid);
            if (!$may) {
                // Two distinct refusals, told apart so the message is
                // true: an actor who is neither the leader nor staff is
                // refused for WHO they are; the leader of a team that
                // has moved on is refused for WHEN they asked.
                $isowner = (int) $group->leaderid === $actorid
                    || has_capability(self::MANAGE, $activity->context(), $actorid);
                if (!$isowner) {
                    throw new workflow_refusal('refusalnotleader', 'mod_selfselectadvanced');
                }
                if ($group->state !== state::FORMING) {
                    throw new workflow_refusal('refusalwrongstate', 'mod_selfselectadvanced');
                }
                // The leader of a forming team, publishing, without the
                // capability: the one case F3 keeps open for retraction
                // and closes for publication. require_lead() raises the
                // exception an administrator's Prohibit should raise.
                authority::require_lead($activity, $actorid);
                // Unreachable: require_lead() throws whenever may_lead()
                // is false, and may_publish() only returned false here
                // because it was. Stated rather than assumed.
                throw new \coding_exception('proposal::save() computed a refusal it could not name');
            }

            file_save_draft_area_files(
                $draftitemid,
                $activity->context()->id,
                'mod_selfselectadvanced',
                self::FILEAREA,
                $groupid,
                $fileoptions
            );
            $count = self::count_files($activity, $groupid);

            // Payload built INSIDE the critical section, dispatched
            // after the commit AND the release below (the binding rule
            // for new code - docs/architecture.md, "Events under a
            // lock"; EVT-001).
            $event = \mod_selfselectadvanced\event\proposal_updated::create([
                'objectid' => $group->id,
                'context' => $activity->context(),
                'userid' => $actorid,
                'other' => ['filecount' => $count, 'pluginuid' => $group->pluginuid],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Every refusal above throws from INSIDE the transaction on
            // a row read INSIDE the lock, and all of them throw before
            // the file save - so the rollback is what guarantees a
            // refused upload wrote no file row either. Unconditional -
            // see eoi::express() for why this is never gated on
            // $DB->is_transaction_started().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $event->trigger();

        return $count;
    }
}
