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

        if (empty($activity->settings()->eoienabled)) {
            throw new \moodle_exception('refusaleoidisabled', 'mod_selfselectadvanced');
        }

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
            if (self::remaining_capacity($activity, $guideid) < 1) {
                throw new \moodle_exception('refusaleoifull', 'mod_selfselectadvanced');
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
        } finally {
            $lock->release();
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
        if ((int) $row->guideid !== $guideid || $row->status !== self::STATUS_PENDING) {
            throw new \moodle_exception('refusaleoinotpending', 'mod_selfselectadvanced');
        }
        self::transition($activity, $row, self::STATUS_WITHDRAWN);

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
     * The leader (or a manager) accepts or rejects a pending interest.
     *
     * Accepting pre-assigns the guide on the group row and auto-rejects
     * every other pending interest for the group, notifying each guide.
     *
     * @param activity $activity the activity
     * @param int $eoiid the interest
     * @param bool $accept true to accept, false to reject
     * @param int $actorid the leader, or a manage-capability holder
     * @throws \moodle_exception on any refusal
     */
    public static function respond(activity $activity, int $eoiid, bool $accept, int $actorid): void {
        global $DB;

        $row = self::get($activity, $eoiid);
        $declined = [];

        $lock = locks::acquire('group:' . $row->groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $row = self::get($activity, $eoiid);
            if ($row->status !== self::STATUS_PENDING) {
                throw new \moodle_exception('refusaleoinotpending', 'mod_selfselectadvanced');
            }
            $group = groups::get($activity, (int) $row->groupid);
            $ismanager = has_capability('mod/selfselectadvanced:manage', $activity->context(), $actorid);
            if ((int) $group->leaderid !== $actorid && !$ismanager) {
                throw new \moodle_exception('refusalnotleader', 'mod_selfselectadvanced');
            }

            if ($accept) {
                if ($group->state !== state::FORMING || !empty($group->guideid)) {
                    throw new \moodle_exception('refusaleoinotlisted', 'mod_selfselectadvanced');
                }
                if (self::remaining_capacity($activity, (int) $row->guideid) < 1) {
                    throw new \moodle_exception('refusaleoifull', 'mod_selfselectadvanced');
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
        } finally {
            $lock->release();
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
        foreach ($rows as $row) {
            self::transition($activity, $row, self::STATUS_EXPIRED);
        }
        foreach ($rows as $row) {
            $group = groups::get($activity, (int) $row->groupid);
            notifier::send(
                $activity,
                'eoiresult',
                (int) $row->guideid,
                'msgeoiexpiredsubject',
                'msgeoiexpiredbody',
                (object) [
                    'group' => format_string($group->name),
                    'activity' => $activity->name(),
                ],
                new \moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $activity->cm()->id]),
                format_string($group->name)
            );
        }

        return count($rows);
    }

    /**
     * Groups plus interests the guide is committed to: the guided
     * states plus forming teams that pre-assigned this guide.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide
     * @return int
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
     * @param activity $activity the activity
     * @param stdClass $row the interest row
     * @param string $status the new status
     * @param int $actorid who caused it, 0 for the system
     */
    private static function transition(activity $activity, stdClass $row, string $status, int $actorid = 0): void {
        global $DB;

        $DB->update_record('selfselectadvanced_eoi', (object) [
            'id' => $row->id,
            'status' => $status,
            'timeresponded' => time(),
        ]);
        \mod_selfselectadvanced\event\eoi_updated::create([
            'objectid' => (int) $row->id,
            'context' => $activity->context(),
            'relateduserid' => (int) $row->guideid,
            'other' => ['groupid' => (int) $row->groupid, 'status' => $status],
        ])->trigger();
    }
}
