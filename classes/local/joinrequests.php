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
 * A student asking to join another team (strategy 1.19 B).
 *
 * The maintainer's rule, kept whole: self-service until the leader
 * accepts; the target team's LEADER approves while the team is still
 * forming; once the team is settled its GUIDE releases it first and the
 * leader still approves; the guide stays with the re-composed team; and
 * a coordinator may approve any of them at any point.
 *
 * A request is a row in {selfselectadvanced_move} carrying the new
 * status 'requested', so accepting one runs the move engine already in
 * place - the composition rules, the seat plan, the locks and the audit
 * trail are all the ones a coordinator's move goes through. Nothing
 * about committing a move is duplicated here.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class joinrequests {
    /** @var string Waiting for the target team's leader (or a coordinator). */
    public const STATUS_REQUESTED = 'requested';

    /** @var string Turned down, with the reason. */
    public const STATUS_DECLINED = 'declined';

    /**
     * Ask to join a team.
     *
     * @param activity $activity the activity
     * @param int $targetgroupid the team the student wants to join
     * @param string $reason why, from the student
     * @param int $userid the student asking
     * @return stdClass the request row
     * @throws \moodle_exception when a gate refuses
     */
    public static function request(
        activity $activity,
        int $targetgroupid,
        string $reason,
        int $userid
    ): stdClass {
        global $DB;

        require_capability('mod/selfselectadvanced:respond', $activity->context(), $userid);
        if (trim($reason) === '') {
            throw new \moodle_exception('refusaljoinreason', 'mod_selfselectadvanced');
        }

        $target = groups::get($activity, $targetgroupid);
        $source = self::current_group($activity, $userid);

        // Leadership first: a leader is also a confirmed member of their
        // own team, so the general "already in it" answer would fire
        // and tell them less than the truth.
        if ((int) $target->leaderid === $userid) {
            throw new \moodle_exception('refusaljoinownteam', 'mod_selfselectadvanced');
        }
        if ($source !== null && (int) $source->id === $targetgroupid) {
            throw new \moodle_exception('refusaljoinalready', 'mod_selfselectadvanced');
        }
        // A frozen team cannot take anybody until it is released, and a
        // student cannot leave one either. Saying so here is kinder
        // than accepting a request that acceptance would refuse.
        if ($target->state === state::FROZEN) {
            throw new \moodle_exception('refusaljointargetfrozen', 'mod_selfselectadvanced');
        }
        if ($source !== null && $source->state === state::FROZEN) {
            throw new \moodle_exception('refusaljoinsourcefrozen', 'mod_selfselectadvanced');
        }
        // One live request at a time, as with every other queue here.
        $live = $DB->get_record_select(
            'selfselectadvanced_move',
            'activityid = :activityid AND userid = :userid AND status = :requested',
            [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'requested' => self::STATUS_REQUESTED,
            ]
        );
        if ($live) {
            throw new \moodle_exception('refusaljoinduplicate', 'mod_selfselectadvanced', '', (int) $live->id);
        }

        $now = time();
        $request = (object) [
            'activityid' => $activity->id(),
            'userid' => $userid,
            'sourcegroupid' => $source !== null ? (int) $source->id : null,
            'targetgroupid' => $targetgroupid,
            'makeleader' => 0,
            'replaceleader' => 0,
            'successorid' => null,
            'status' => self::STATUS_REQUESTED,
            'statusinfo' => null,
            'reason' => $reason,
            'responsenote' => null,
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $request->id = $DB->insert_record('selfselectadvanced_move', $request);

        \mod_selfselectadvanced\event\join_requested::create([
            'objectid' => $request->id,
            'context' => $activity->context(),
            'relateduserid' => $userid,
            'other' => ['targetgroupid' => $targetgroupid],
        ])->trigger();

        // The leader hears about it; sends never happen under a lock.
        self::notify(
            $activity,
            (int) $target->leaderid,
            'msgjoinrequestedsubject',
            'msgjoinrequestedbody',
            $target,
            $userid,
            $reason
        );

        return $request;
    }

    /**
     * Accept or decline a request.
     *
     * Accepting runs the move engine: the same validation, the same
     * locks, the same commit a coordinator's move goes through. A
     * request that would break the target team is refused HERE, with
     * the rule that refused it, and the request stays open.
     *
     * @param activity $activity the activity
     * @param int $requestid the request
     * @param bool $accept true to admit them
     * @param string $note what the decider said
     * @param int $actorid the leader, coordinator or manager deciding
     * @return stdClass the decided request
     * @throws \moodle_exception when refused
     */
    public static function respond(
        activity $activity,
        int $requestid,
        bool $accept,
        string $note,
        int $actorid
    ): stdClass {
        global $DB;

        $request = self::get($activity, $requestid);
        if ($request->status !== self::STATUS_REQUESTED) {
            throw new \moodle_exception('refusaljoinnotopen', 'mod_selfselectadvanced');
        }
        $target = groups::get($activity, (int) $request->targetgroupid);
        self::require_decider($activity, $target, $actorid);

        if (!$accept) {
            $DB->update_record('selfselectadvanced_move', (object) [
                'id' => $request->id,
                'status' => self::STATUS_DECLINED,
                'responsenote' => $note,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);
            $fresh = self::get($activity, $requestid);
            \mod_selfselectadvanced\event\join_decided::create([
                'objectid' => $requestid,
                'context' => $activity->context(),
                'relateduserid' => (int) $request->userid,
                'other' => ['accepted' => false],
            ])->trigger();
            self::notify(
                $activity,
                (int) $request->userid,
                'msgjoindeclinedsubject',
                'msgjoindeclinedbody',
                $target,
                (int) $request->userid,
                $note
            );

            return $fresh;
        }

        // A settled team has to be released by its guide before it can
        // take anybody (strategy 1.19 B step 3). Saying which team, and
        // who releases it, is the whole of the message.
        if ($target->state === state::FROZEN) {
            throw new \moodle_exception('refusaljointargetfrozen', 'mod_selfselectadvanced');
        }
        $source = $request->sourcegroupid ? groups::get($activity, (int) $request->sourcegroupid) : null;
        if ($source !== null && $source->state === state::FROZEN) {
            throw new \moodle_exception('refusaljoinsourcefrozen', 'mod_selfselectadvanced');
        }

        // Through the engine, exactly as a coordinator's move goes.
        $moves = (new api($activity))->moves();
        $staged = $moves->stage(
            (int) $request->userid,
            $source !== null ? (int) $source->id : null,
            (int) $target->id,
            false,
            null,
            $actorid
        );
        $verdicts = $moves->validate_set([(int) $staged->id]);
        if (empty($verdicts->valid)) {
            // Undo the staging; the request stays open so the leader can
            // see why and the student can try elsewhere.
            $moves->cancel((int) $staged->id, $actorid);
            throw new \moodle_exception(
                'refusaljoinrules',
                'mod_selfselectadvanced',
                '',
                self::first_reason($verdicts, (int) $staged->id)
            );
        }
        $moves->commit_set([(int) $staged->id], $actorid);

        $DB->update_record('selfselectadvanced_move', (object) [
            'id' => $request->id,
            'status' => 'committed',
            'responsenote' => $note,
            'usermodified' => $actorid,
            'timemodified' => time(),
        ]);

        \mod_selfselectadvanced\event\join_decided::create([
            'objectid' => $requestid,
            'context' => $activity->context(),
            'relateduserid' => (int) $request->userid,
            'other' => ['accepted' => true],
        ])->trigger();
        self::notify(
            $activity,
            (int) $request->userid,
            'msgjoinacceptedsubject',
            'msgjoinacceptedbody',
            $target,
            (int) $request->userid,
            $note
        );

        return self::get($activity, $requestid);
    }

    /**
     * Take back one's own request while nobody has answered it.
     *
     * @param activity $activity the activity
     * @param int $requestid the request
     * @param int $userid the student who made it
     * @return stdClass the withdrawn request
     * @throws \moodle_exception when refused
     */
    public static function withdraw(activity $activity, int $requestid, int $userid): stdClass {
        global $DB;

        $request = self::get($activity, $requestid);
        if ((int) $request->userid !== $userid) {
            throw new \moodle_exception('refusaljoinnotyours', 'mod_selfselectadvanced');
        }
        if ($request->status !== self::STATUS_REQUESTED) {
            throw new \moodle_exception('refusaljoinnotopen', 'mod_selfselectadvanced');
        }
        $DB->update_record('selfselectadvanced_move', (object) [
            'id' => $requestid,
            'status' => 'cancelled',
            'usermodified' => $userid,
            'timemodified' => time(),
        ]);

        return self::get($activity, $requestid);
    }

    /**
     * The requests waiting for one team's leader to answer.
     *
     * @param activity $activity the activity
     * @param int $groupid the target team
     * @return stdClass[] request rows, oldest first
     */
    public static function waiting_for_group(activity $activity, int $groupid): array {
        global $DB;

        return $DB->get_records(
            'selfselectadvanced_move',
            [
                'activityid' => $activity->id(),
                'targetgroupid' => $groupid,
                'status' => self::STATUS_REQUESTED,
            ],
            'timecreated ASC'
        );
    }

    /**
     * One student's own requests, newest first.
     *
     * @param activity $activity the activity
     * @param int $userid the student
     * @return stdClass[] request rows
     */
    public static function mine(activity $activity, int $userid): array {
        global $DB;

        return $DB->get_records_select(
            'selfselectadvanced_move',
            'activityid = :activityid AND userid = :userid AND reason IS NOT NULL',
            ['activityid' => $activity->id(), 'userid' => $userid],
            'timecreated DESC'
        );
    }

    /**
     * One request, asserted to belong to the activity.
     *
     * @param activity $activity the activity
     * @param int $requestid the request
     * @return stdClass the row
     */
    public static function get(activity $activity, int $requestid): stdClass {
        global $DB;

        $request = $DB->get_record('selfselectadvanced_move', ['id' => $requestid], '*', MUST_EXIST);
        if ((int) $request->activityid !== $activity->id()) {
            throw new \moodle_exception('errmovenotfound', 'mod_selfselectadvanced');
        }

        return $request;
    }

    /**
     * Who may answer a request for this team.
     *
     * The target team's leader, and - the maintainer's escape hatch for
     * an absent leader or a contested case - any coordinator or
     * manager.
     *
     * @param activity $activity the activity
     * @param stdClass $target the target team
     * @param int $actorid the actor
     * @throws \moodle_exception when they may not
     */
    public static function require_decider(activity $activity, stdClass $target, int $actorid): void {
        if ((int) $target->leaderid === $actorid) {
            return;
        }
        $context = $activity->context();
        if (
            has_capability('mod/selfselectadvanced:manage', $context, $actorid)
            || has_capability('mod/selfselectadvanced:coordinate', $context, $actorid)
        ) {
            return;
        }

        throw new \moodle_exception('refusaljoinnotleader', 'mod_selfselectadvanced');
    }

    /**
     * The team a student is confirmed in, if any.
     *
     * @param activity $activity the activity
     * @param int $userid the student
     * @return stdClass|null the group row, or null when unassigned
     */
    public static function current_group(activity $activity, int $userid): ?stdClass {
        global $DB;

        $row = $DB->get_record_sql(
            "SELECT g.*
               FROM {selfselectadvanced_group} g
               JOIN {selfselectadvanced_member} m ON m.groupid = g.id
              WHERE g.activityid = :activityid AND m.userid = :userid AND m.status = :confirmed",
            [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'confirmed' => groups::STATUS_CONFIRMED,
            ]
        );

        return $row ?: null;
    }

    /**
     * The first rule that refused a staged move, for the message.
     *
     * @param stdClass $verdicts what validate_set() returned
     * @param int $moveid the staged move
     * @return string a localised reason, or a general one
     */
    private static function first_reason(stdClass $verdicts, int $moveid): string {
        foreach ($verdicts->permove[$moveid] ?? [] as $verdict) {
            if (empty($verdict->ok) && !empty($verdict->reason)) {
                return (string) $verdict->reason;
            }
        }

        return get_string('refusaljoinrulesgeneral', 'mod_selfselectadvanced');
    }

    /**
     * Tell somebody what happened, outside every lock.
     *
     * @param activity $activity the activity
     * @param int $touserid recipient
     * @param string $subjectkey subject string key
     * @param string $bodykey body string key
     * @param stdClass $target the team in question
     * @param int $studentid the student who asked
     * @param string $note the reason or the answer
     */
    private static function notify(
        activity $activity,
        int $touserid,
        string $subjectkey,
        string $bodykey,
        stdClass $target,
        int $studentid,
        string $note
    ): void {
        $student = \core_user::get_user($studentid);
        notifier::send(
            $activity,
            'joinrequests',
            $touserid,
            $subjectkey,
            $bodykey,
            (object) [
                'group' => format_string($target->name),
                'student' => $student ? fullname($student) : '',
                'note' => trim($note),
            ],
            new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $activity->cm()->id,
                'g' => $target->id,
            ]),
            format_string($target->name)
        );
    }
}
