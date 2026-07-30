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
 * A team approaching a guide (strategy 1.17 E).
 *
 * The point of this is that nobody's address is exposed. The team never
 * sees the guide's, the guide never sees the team's, and the plugin
 * never handles either: the approach travels as a Moodle message, built
 * from a template, and the guide answers it on a page of their own
 * rather than by replying to anything.
 *
 * What the team may see about a guide is deliberate and limited: name,
 * department, sub-department and how much they are carrying. That is
 * enough to choose sensibly and nothing more.
 *
 * A team may approach only so many guides, so the choice stays a choice
 * rather than a broadcast; the limit is an activity setting.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contacts {
    /** @var string Sent, and waiting for the guide to answer. */
    public const STATUS_SENT = 'sent';

    /** @var string The guide agreed to take the team. */
    public const STATUS_ACCEPTED = 'accepted';

    /** @var string The guide said no. */
    public const STATUS_DECLINED = 'declined';

    /**
     * Approach a guide.
     *
     * @param activity $activity the activity
     * @param stdClass $group the team
     * @param int $guideid the guide being approached
     * @param string $message what the team wants to say
     * @param int $messageformat text format
     * @param int $userid the acting user (the leader)
     * @return stdClass the contact row
     * @throws \moodle_exception when a gate refuses
     */
    public static function send(
        activity $activity,
        stdClass $group,
        int $guideid,
        string $message,
        int $messageformat,
        int $userid
    ): stdClass {
        global $DB;

        $max = (int) ($activity->settings()->contactmax ?? 0);
        if ($max < 1) {
            throw new \moodle_exception('refusalcontactdisabled', 'mod_selfselectadvanced');
        }
        if (!has_capability('mod/selfselectadvanced:guide', $activity->context(), $guideid)) {
            throw new \moodle_exception('refusalnotaguide', 'mod_selfselectadvanced');
        }

        $lock = locks::acquire('group:' . $group->id);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            // Judged on the team as it is under the lock, never on the
            // caller's copy (the lesson of 1.16.0).
            $group = groups::get($activity, (int) $group->id);
            if ((int) $group->leaderid !== $userid) {
                throw new \moodle_exception('refusalnotleader', 'mod_selfselectadvanced');
            }
            if ($group->state !== state::FORMING) {
                throw new \moodle_exception('refusalwrongstate', 'mod_selfselectadvanced');
            }
            if (!empty($group->guideid)) {
                throw new \moodle_exception('refusalcontacthasguide', 'mod_selfselectadvanced');
            }

            $used = $DB->count_records('selfselectadvanced_contact', ['groupid' => $group->id]);
            if ($used >= $max) {
                throw new \moodle_exception('refusalcontactmax', 'mod_selfselectadvanced', '', $max);
            }
            if ($DB->record_exists('selfselectadvanced_contact', [
                'groupid' => $group->id,
                'guideid' => $guideid,
            ])) {
                throw new \moodle_exception('refusalcontactduplicate', 'mod_selfselectadvanced');
            }

            $now = time();
            $contact = (object) [
                'activityid' => $activity->id(),
                'groupid' => (int) $group->id,
                'guideid' => $guideid,
                'status' => self::STATUS_SENT,
                'sentby' => $userid,
                'message' => $message,
                'messageformat' => $messageformat,
                'timecreated' => $now,
            ];
            $contact->id = $DB->insert_record('selfselectadvanced_contact', $contact);

            \mod_selfselectadvanced\event\contact_sent::create([
                'objectid' => $contact->id,
                'context' => $activity->context(),
                'relateduserid' => $guideid,
                'other' => ['pluginuid' => $group->pluginuid],
            ])->trigger();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        // Outside the lock: mail must never hold one.
        self::notify($activity, $guideid, 'msgcontactsentsubject', 'msgcontactsentbody', $contact, $group);

        return $contact;
    }

    /**
     * The guide answers.
     *
     * Accepting pre-assigns them to the team, which is what an
     * accepted expression of interest does too: the team is still
     * forming, so it submits to that guide when it is ready. Their
     * capacity is checked under the guide lock, so a guide who filled
     * up while the approach waited cannot take another team.
     * Declining closes this approach and leaves the team free to try
     * somebody else, as long as they are under their limit.
     *
     * @param activity $activity the activity
     * @param int $contactid the approach
     * @param bool $accept true to take the team on
     * @param string $reason the guide's reason, may be empty
     * @param int $reasonformat text format
     * @param int $userid the acting guide
     * @return stdClass the updated contact row
     * @throws \moodle_exception when refused
     */
    public static function respond(
        activity $activity,
        int $contactid,
        bool $accept,
        string $reason,
        int $reasonformat,
        int $userid
    ): stdClass {
        global $DB;

        $contact = self::get($activity, $contactid);
        if ((int) $contact->guideid !== $userid) {
            throw new \moodle_exception('refusalcontactnotyours', 'mod_selfselectadvanced');
        }
        if ($contact->status !== self::STATUS_SENT) {
            throw new \moodle_exception('refusalcontactanswered', 'mod_selfselectadvanced');
        }

        // A team that is still forming has its guide PRE-ASSIGNED, the
        // same shape an accepted expression of interest takes: the
        // team then submits to that guide. Reassignment is for teams
        // that have already been submitted, and refuses a forming one.
        //
        // Guide lock before group lock, the ordering the rest of the
        // plugin uses, because capacity is per guide while the team's
        // state is per group.
        $guidelock = locks::acquire('eoiguide:' . $userid);
        $lock = locks::acquire('group:' . $contact->groupid);
        $outermost = !$DB->is_transaction_started();
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($activity, (int) $contact->groupid);
            if ($accept) {
                if ($group->state !== state::FORMING || !empty($group->guideid)) {
                    throw new \moodle_exception('refusalcontacthasguide', 'mod_selfselectadvanced');
                }
                // Judged under the lock: a guide who filled up while
                // this approach was waiting cannot take another team.
                if ((new api($activity))->gatekeeper()->can_take_guide($userid)) {
                    throw new \moodle_exception('refusalcontactfull', 'mod_selfselectadvanced');
                }
                $DB->set_field('selfselectadvanced_group', 'guideid', $userid, ['id' => $group->id]);
                $DB->set_field('selfselectadvanced_group', 'timemodified', time(), ['id' => $group->id]);
            }

            $contact->status = $accept ? self::STATUS_ACCEPTED : self::STATUS_DECLINED;
            $contact->reason = $reason;
            $contact->reasonformat = $reasonformat;
            $contact->timeresponded = time();
            $DB->update_record('selfselectadvanced_contact', $contact);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if ($outermost && isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
            $guidelock->release();
        }

        \mod_selfselectadvanced\event\contact_answered::create([
            'objectid' => $contact->id,
            'context' => $activity->context(),
            'relateduserid' => (int) $contact->sentby,
            'other' => ['accepted' => $accept ? 1 : 0],
        ])->trigger();

        self::notify(
            $activity,
            (int) $contact->sentby,
            $accept ? 'msgcontactacceptedsubject' : 'msgcontactdeclinedsubject',
            $accept ? 'msgcontactacceptedbody' : 'msgcontactdeclinedbody',
            $contact,
            $group
        );

        return $contact;
    }

    /**
     * One approach, asserted to belong to the activity.
     *
     * @param activity $activity the activity
     * @param int $contactid the approach
     * @return stdClass
     */
    public static function get(activity $activity, int $contactid): stdClass {
        global $DB;

        $contact = $DB->get_record('selfselectadvanced_contact', ['id' => $contactid], '*', MUST_EXIST);
        if ((int) $contact->activityid !== $activity->id()) {
            throw new \moodle_exception('errcontactnotfound', 'mod_selfselectadvanced');
        }

        return $contact;
    }

    /**
     * Everything a team has sent.
     *
     * @param activity $activity the activity
     * @param int $groupid the team
     * @return stdClass[]
     */
    public static function for_group(activity $activity, int $groupid): array {
        global $DB;

        return $DB->get_records('selfselectadvanced_contact', [
            'activityid' => $activity->id(),
            'groupid' => $groupid,
        ], 'timecreated ASC');
    }

    /**
     * Everything waiting for one guide to answer.
     *
     * @param activity $activity the activity
     * @param int $guideid the guide
     * @return stdClass[]
     */
    public static function waiting_for(activity $activity, int $guideid): array {
        global $DB;

        return $DB->get_records('selfselectadvanced_contact', [
            'activityid' => $activity->id(),
            'guideid' => $guideid,
            'status' => self::STATUS_SENT,
        ], 'timecreated ASC');
    }

    /**
     * How many more guides this team may approach.
     *
     * @param activity $activity the activity
     * @param int $groupid the team
     * @return int
     */
    public static function remaining(activity $activity, int $groupid): int {
        global $DB;

        $max = (int) ($activity->settings()->contactmax ?? 0);
        $used = $DB->count_records('selfselectadvanced_contact', ['groupid' => $groupid]);

        return max(0, $max - $used);
    }

    /**
     * Send one notification about an approach.
     *
     * @param activity $activity the activity
     * @param int $touserid recipient
     * @param string $subjectkey subject lang key
     * @param string $bodykey body lang key
     * @param stdClass $contact the approach
     * @param stdClass $group the team
     */
    private static function notify(
        activity $activity,
        int $touserid,
        string $subjectkey,
        string $bodykey,
        stdClass $contact,
        stdClass $group
    ): void {
        $reason = trim(html_to_text((string) ($contact->reason ?? '')));

        notifier::send(
            $activity,
            'contact',
            $touserid,
            $subjectkey,
            $bodykey,
            (object) [
                'group' => format_string($group->name),
                'pluginuid' => $group->pluginuid,
                'title' => format_string($group->title),
                'message' => trim(html_to_text((string) ($contact->message ?? ''))),
                'reason' => $reason !== '' ? $reason : get_string('contactnoreason', 'mod_selfselectadvanced'),
            ],
            new \moodle_url('/mod/selfselectadvanced/contactreview.php', [
                'id' => $activity->cm()->id,
                'c' => $contact->id,
            ]),
            get_string('contactreview', 'mod_selfselectadvanced')
        );
    }
}
