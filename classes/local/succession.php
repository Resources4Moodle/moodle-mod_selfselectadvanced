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
 * Leadership transfer and leader step-out (spec section 6.4, decision A3).
 *
 * One active nomination per group, held on the group row. The nominee
 * confirms; the transfer executes atomically under the group lock with
 * the L3 slot re-checked, and for step-out the post-departure minimum
 * size (L1) - a replacement member must be confirmed first. The
 * outgoing leader's lead slot is released by the transfer itself
 * (leaderid moves on); after step-out the former leader may hold
 * pending invitations elsewhere (a held place) or remain groupless.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class succession {
    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param gatekeeper $gatekeeper the gatekeeper to consult
     */
    public function __construct(activity $activity, gatekeeper $gatekeeper) {
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;
    }

    /**
     * Nominate a confirmed member as successor (leader action).
     *
     * @param stdClass $group group row
     * @param int $nomineeid the proposed successor
     * @param string $type 'transfer' or 'stepout'
     * @param int $actorid the acting leader
     * @throws \moodle_exception when the gatekeeper refuses
     */
    public function nominate(stdClass $group, int $nomineeid, string $type, int $actorid): void {
        global $DB;

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_nominate($fresh, $nomineeid, $type, $actorid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $fresh->id,
                'successorid' => $nomineeid,
                'successortype' => $type,
                'timenominated' => time(),
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        notifier::send(
            $this->activity,
            'nomination',
            $nomineeid,
            'msgnominationsubject',
            'msgnominationbody' . $type,
            (object) ['group' => format_string($group->name), 'pluginuid' => $group->pluginuid],
            $this->group_url((int) $group->id),
            format_string($group->name)
        );
    }

    /**
     * Confirm the active nomination (nominee action): leadership
     * transfers; for step-out the outgoing leader leaves the group.
     *
     * @param stdClass $group group row
     * @param int $userid the confirming nominee
     * @return string the executed type ('transfer' or 'stepout')
     * @throws \moodle_exception when the gatekeeper refuses
     */
    public function confirm(stdClass $group, int $userid): string {
        global $DB;

        // L3 lead counts span groups: activity lock first (audit item 6).
        $activitylock = locks::acquire('activity:' . $this->activity->id());
        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_confirm_succession($fresh, $userid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $now = time();
            $oldleaderid = (int) $fresh->leaderid;
            $type = $fresh->successortype;

            // The successor becomes leader.
            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $fresh->id,
                'leaderid' => $userid,
                'successorid' => null,
                'successortype' => null,
                'timenominated' => null,
                'usermodified' => $userid,
                'timemodified' => $now,
            ]);
            $DB->set_field('selfselectadvanced_member', 'isleader', 1, [
                'groupid' => $fresh->id,
                'userid' => $userid,
            ]);
            $DB->set_field('selfselectadvanced_member', 'isleader', 0, [
                'groupid' => $fresh->id,
                'userid' => $oldleaderid,
            ]);

            if ($type === 'stepout') {
                // The former leader leaves the group entirely.
                $old = $DB->get_record('selfselectadvanced_member', [
                    'groupid' => $fresh->id,
                    'userid' => $oldleaderid,
                ], '*', MUST_EXIST);
                $old->status = groups::STATUS_REMOVED;
                $old->timeresponded = $now;
                $old->timemodified = $now;
                $DB->update_record('selfselectadvanced_member', $old);
            }

            \mod_selfselectadvanced\event\leadership_transferred::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'relateduserid' => $userid,
                'other' => [
                    'fromuserid' => $oldleaderid,
                    'pluginuid' => $fresh->pluginuid,
                    'type' => $type,
                ],
            ])->trigger();

            // A step-out removes the outgoing leader from the roster,
            // so the mirror has to follow. The gatekeeper limits this
            // path to FORMING today, where no mirror can exist and both
            // calls are no-ops - they are here so a later relaxation of
            // that gate cannot silently strand a mirror.
            freeze::request_sync($this->activity, $fresh);

            $transaction->allow_commit();
        } finally {
            $lock->release();
            $activitylock->release();
        }

        freeze::sync_core_group($this->activity, (int) $fresh->id, $userid);

        notifier::send(
            $this->activity,
            'nominationresult',
            $oldleaderid,
            'msgnominationconfirmedsubject',
            'msgnominationconfirmedbody',
            (object) [
                'user' => fullname(\core_user::get_user($userid)),
                'group' => format_string($group->name),
            ],
            $this->group_url((int) $group->id),
            format_string($group->name)
        );

        return $type;
    }

    /**
     * Decline the active nomination (nominee action).
     *
     * @param stdClass $group group row
     * @param int $userid the declining nominee
     * @throws \moodle_exception when the caller is not the nominee
     */
    public function decline(stdClass $group, int $userid): void {
        $this->clear($group, $userid, true);
    }

    /**
     * Cancel the active nomination (leader action).
     *
     * @param stdClass $group group row
     * @param int $actorid the acting leader
     * @throws \moodle_exception when the caller is not the leader
     */
    public function cancel(stdClass $group, int $actorid): void {
        if ((int) $group->leaderid !== $actorid) {
            throw new \moodle_exception('refusalnotleader', 'mod_selfselectadvanced');
        }
        $this->clear($group, $actorid, false);
    }

    /**
     * Clear the nomination fields and notify the other party.
     *
     * @param stdClass $group group row
     * @param int $actorid acting user (nominee when declining, leader when cancelling)
     * @param bool $declining true when the nominee declines
     */
    private function clear(stdClass $group, int $actorid, bool $declining): void {
        global $DB;

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if (empty($fresh->successorid)) {
                throw new \moodle_exception('refusalnotnominee', 'mod_selfselectadvanced');
            }
            if ($declining && (int) $fresh->successorid !== $actorid) {
                throw new \moodle_exception('refusalnotnominee', 'mod_selfselectadvanced');
            }
            $nomineeid = (int) $fresh->successorid;

            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $fresh->id,
                'successorid' => null,
                'successortype' => null,
                'timenominated' => null,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        $recipient = $declining ? (int) $fresh->leaderid : $nomineeid;
        notifier::send(
            $this->activity,
            'nominationresult',
            $recipient,
            $declining ? 'msgnominationdeclinedsubject' : 'msgnominationcancelledsubject',
            $declining ? 'msgnominationdeclinedbody' : 'msgnominationcancelledbody',
            (object) ['group' => format_string($group->name)],
            $this->group_url((int) $group->id),
            format_string($group->name)
        );
    }

    /**
     * Deep link to a group page.
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
