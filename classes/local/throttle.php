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
 * A rate limit one requester is under (1.20.60, maintainer instruction
 * 2026-08-27: "To prevent the support ticket system from being flooded,
 * we should have a mechanism where the agent (Teacher or group
 * coordinator) can initiate a throttle (number of tickets per + wait
 * till before next ticket)").
 *
 * WHAT THIS IS, AND WHAT IT IS NOT. It is STAFF-INITIATED and it is
 * PER PERSON: somebody with queue authority is looking at a flood from
 * one requester and decides to slow that requester down. It is not an
 * activity-wide policy and there is no site default - a limit that
 * applied to everybody by default would punish the ordinary user for a
 * problem one person is causing, and this plugin's whole ticket design
 * assumes asking is cheap and welcome.
 *
 * THE TWO NUMBERS the instruction names, and nothing more:
 *   - maxtickets per windowhours - how many requests they may file in a
 *     rolling window. 0 means "not limited by count".
 *   - nextallowed - a time before which the next request is refused
 *     outright, the "wait till before next ticket" half. Null means no
 *     such wait.
 * Either half may stand alone; a row with neither is meaningless and is
 * refused at the door rather than stored.
 *
 * WHAT IT NEVER DOES. It never touches a ticket that has already been
 * filed - a throttle set now cannot retroactively silence a live
 * request - and it never applies to STAFF: the queue authority holder's
 * own filings are their work, and a coordinator who could throttle
 * another coordinator would be a new authority nobody granted.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class throttle {
    /** @var string The table this class owns. */
    private const TABLE = 'selfselectadvanced_ticketthrottle';

    /** @var int The longest window staff may set, in hours (four weeks). */
    public const MAX_WINDOW_HOURS = 672;

    /**
     * The throttle this person is under, or null when they are under
     * none.
     *
     * @param activity $activity the activity
     * @param int $userid the requester
     * @return stdClass|null the row, or null
     */
    public static function get(activity $activity, int $userid): ?stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, [
            'activityid' => $activity->id(),
            'userid' => $userid,
        ]) ?: null;
    }

    /**
     * Every throttle in force in this activity, newest change first.
     *
     * @param activity $activity the activity
     * @return stdClass[] rows keyed by id
     */
    public static function all(activity $activity): array {
        global $DB;

        return $DB->get_records(
            self::TABLE,
            ['activityid' => $activity->id()],
            'timemodified DESC, id DESC'
        );
    }

    /**
     * Set (or replace) the throttle on one requester.
     *
     * Authority is the queue's own: tickets::require_queue_authority(),
     * so the editing teacher and the group coordinator the instruction
     * names are exactly who may do this, and nobody else - the same door
     * request_info(), refer() and comment() were given in 1.20.55.
     *
     * @param activity $activity the activity
     * @param int $userid the requester to throttle
     * @param int $maxtickets requests allowed per window; 0 for no count limit
     * @param int $windowhours the rolling window, in hours
     * @param int|null $nextallowed wait until this time; null for no wait
     * @param string $reason what the requester is told; required
     * @param int $actorid the member of staff setting it
     * @return stdClass the stored row
     * @throws \moodle_exception when refused
     */
    public static function set(
        activity $activity,
        int $userid,
        int $maxtickets,
        int $windowhours,
        ?int $nextallowed,
        string $reason,
        int $actorid
    ): stdClass {
        global $DB;

        tickets::require_queue_authority($activity, $actorid);

        // A throttle is something a person is TOLD about, in the refusal
        // they will read - so it cannot be set silently.
        if (trim($reason) === '') {
            throw new workflow_refusal('refusalthrottlereason', 'mod_selfselectadvanced');
        }
        if ($maxtickets < 0) {
            throw new workflow_refusal('refusalthrottlenegative', 'mod_selfselectadvanced');
        }
        if ($windowhours < 1 || $windowhours > self::MAX_WINDOW_HOURS) {
            throw new workflow_refusal(
                'refusalthrottlewindow',
                'mod_selfselectadvanced',
                '',
                self::MAX_WINDOW_HOURS
            );
        }
        if ($nextallowed !== null && $nextallowed <= time()) {
            // A wait that has already elapsed is not a wait. Refusing it
            // is kinder than storing something that does nothing and
            // reads on the screen as though it does.
            throw new workflow_refusal('refusalthrottlepast', 'mod_selfselectadvanced');
        }
        if ($maxtickets === 0 && $nextallowed === null) {
            throw new workflow_refusal('refusalthrottleempty', 'mod_selfselectadvanced');
        }
        // Staff are not throttled. Their filings are their work, and one
        // coordinator restraining another is an authority nobody granted.
        if (tickets::has_queue_authority($activity, $userid)) {
            throw new workflow_refusal('refusalthrottlestaff', 'mod_selfselectadvanced');
        }

        $now = time();
        $existing = self::get($activity, $userid);
        if ($existing !== null) {
            $existing->maxtickets = $maxtickets;
            $existing->windowhours = $windowhours;
            $existing->nextallowed = $nextallowed;
            $existing->reason = $reason;
            $existing->setby = $actorid;
            $existing->timemodified = $now;
            $DB->update_record(self::TABLE, $existing);
            $row = $existing;
        } else {
            $row = (object) [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'maxtickets' => $maxtickets,
                'windowhours' => $windowhours,
                'nextallowed' => $nextallowed,
                'reason' => $reason,
                'setby' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $row->id = $DB->insert_record(self::TABLE, $row);
        }

        \mod_selfselectadvanced\event\ticket_throttle_set::create([
            'objectid' => (int) $row->id,
            'context' => $activity->context(),
            'relateduserid' => $userid,
            'other' => [
                'maxtickets' => $maxtickets,
                'windowhours' => $windowhours,
                'nextallowed' => (int) ($nextallowed ?? 0),
            ],
        ])->trigger();

        return $row;
    }

    /**
     * Lift the throttle on one requester. A no-op when there is none,
     * so a double-press is harmless.
     *
     * @param activity $activity the activity
     * @param int $userid the requester
     * @param int $actorid the member of staff lifting it
     * @return bool whether a throttle was actually lifted
     */
    public static function clear(activity $activity, int $userid, int $actorid): bool {
        global $DB;

        tickets::require_queue_authority($activity, $actorid);

        $existing = self::get($activity, $userid);
        if ($existing === null) {
            return false;
        }

        $DB->delete_records(self::TABLE, ['id' => $existing->id]);

        \mod_selfselectadvanced\event\ticket_throttle_cleared::create([
            'objectid' => (int) $existing->id,
            'context' => $activity->context(),
            'relateduserid' => $userid,
        ])->trigger();

        return true;
    }

    /**
     * Refuse a filing that would break this person's throttle.
     *
     * Called by every filing door in tickets, so there is ONE answer
     * rather than one per ticket type - the mistake that let the
     * one-live-help-ticket rule be bypassed by belonging to two teams
     * (audit L-9) was exactly this shape.
     *
     * THE ONE DOOR THAT DOES NOT ASK is file_guidegone(): the system
     * files that ticket itself when a guide disappears, so there is no
     * requester to rate-limit and throttling it would suppress the
     * plugin's own alarm rather than slow anybody down. That exemption
     * is named in ticket_throttle_test.php's door scan, so removing it
     * without removing this reasoning fails there.
     *
     * NOT under the filing lock, and deliberately: the window count is a
     * courtesy limit on a person, not an invariant on a row. Two
     * submissions racing each other at the boundary may both pass, which
     * costs one extra ticket and nothing else; taking a second lock to
     * prevent that would buy an off-by-one with a deadlock surface.
     *
     * @param activity $activity the activity
     * @param int $userid the would-be requester
     * @throws \moodle_exception refusalthrottlewait or refusalthrottlecount
     */
    public static function require_within(activity $activity, int $userid): void {
        global $DB;

        $throttle = self::get($activity, $userid);
        if ($throttle === null) {
            return;
        }

        $now = time();
        if ($throttle->nextallowed !== null && (int) $throttle->nextallowed > $now) {
            throw new workflow_refusal(
                'refusalthrottlewait',
                'mod_selfselectadvanced',
                '',
                (object) [
                    'when' => userdate((int) $throttle->nextallowed),
                    'reason' => trim((string) $throttle->reason),
                ]
            );
        }

        $max = (int) $throttle->maxtickets;
        if ($max <= 0) {
            return;
        }

        // EVERY request they filed in the window, whatever became of it.
        // Counting only live ones would let somebody withdraw their way
        // back to an empty allowance, which is the flood this exists to
        // stop.
        $since = $now - ((int) $throttle->windowhours * HOURSECS);
        $filed = $DB->count_records_select(
            'selfselectadvanced_ticket',
            'activityid = :activityid AND requestedby = :userid AND timecreated >= :since',
            [
                'activityid' => $activity->id(),
                'userid' => $userid,
                'since' => $since,
            ]
        );
        if ($filed >= $max) {
            throw new workflow_refusal(
                'refusalthrottlecount',
                'mod_selfselectadvanced',
                '',
                (object) [
                    'max' => $max,
                    'hours' => (int) $throttle->windowhours,
                    'reason' => trim((string) $throttle->reason),
                ]
            );
        }
    }
}
