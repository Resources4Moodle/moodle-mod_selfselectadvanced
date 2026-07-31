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
 * Application facade used by the pages: capability-checked callers pass
 * through here; the gatekeeper decides; the services mutate inside
 * transactions with named locks (decision A7).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     */
    public function __construct(activity $activity) {
        $this->activity = $activity;
        $this->gatekeeper = new gatekeeper($activity, new resolver($activity));
    }

    /**
     * The gatekeeper, for pages that render limit positions and reasons.
     *
     * @return gatekeeper
     */
    public function gatekeeper(): gatekeeper {
        return $this->gatekeeper;
    }

    /**
     * The activity.
     *
     * @return activity
     */
    public function activity(): activity {
        return $this->activity;
    }

    /**
     * The invitation engine.
     *
     * @return invitations
     */
    public function invitations(): invitations {
        return new invitations($this->activity, $this->gatekeeper);
    }

    /**
     * The guide handover engine (nominate, accept, decline, cancel).
     *
     * @return handover
     */
    public function handover(): handover {
        return new handover($this->activity, $this->gatekeeper);
    }

    /**
     * The succession engine (transfer and step-out).
     *
     * @return succession
     */
    public function succession(): succession {
        return new succession($this->activity, $this->gatekeeper);
    }

    /**
     * The lifecycle transition service (T2-T4 and A5 assignment).
     *
     * @return state
     */
    public function lifecycle(): state {
        return new state($this->activity, $this->gatekeeper);
    }

    /**
     * The staged-move engine.
     *
     * @return moves
     */
    public function moves(): moves {
        return new moves($this->activity, $this->gatekeeper);
    }

    /**
     * Create a group with the acting user as leader (transition T1).
     *
     * @param int $userid the leader-to-be
     * @param string $name group name, unique within the activity
     * @param string $title title of work
     * @param string $brief brief of work (HTML from the core editor)
     * @param int $briefformat text format of the brief
     * @return stdClass the created group row
     * @throws \moodle_exception when the gatekeeper refuses
     */
    public function create_group(int $userid, string $name, string $title, string $brief, int $briefformat): stdClass {
        global $DB;

        if ($refusal = $this->gatekeeper->can_create_group($userid)) {
            throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
        }
        if (groups::name_taken($this->activity, $name)) {
            throw new \moodle_exception('errnametaken', 'mod_selfselectadvanced');
        }

        // Activity-scoped, deliberately. Since 1.16.0 a name must be
        // unique across every instance of this activity in the course,
        // which this lock does not span: two instances of the same
        // course could in principle mint the same name in the same
        // instant, and the loser is not refused. Widening the lock to
        // the course was tried and rejected - auto-grouping creates
        // groups under the activity lock too, so a wider lock here
        // would stop the two paths excluding each other and trade a
        // rare duplicate name for a real collision over seats. The
        // residue is a cosmetic duplicate, recorded rather than
        // papered over.
        $lock = locks::acquire('activity:' . $this->activity->id());
        try {
            $transaction = $DB->start_delegated_transaction();

            // Re-check under the lock: a parallel creation may have consumed the slot.
            if ($refusal = $this->gatekeeper->can_create_group($userid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }
            if (groups::name_taken($this->activity, $name)) {
                throw new \moodle_exception('errnametaken', 'mod_selfselectadvanced');
            }

            $now = time();
            $group = (object) [
                'activityid' => $this->activity->id(),
                'pluginuid' => '',
                'name' => trim($name),
                'title' => trim($title),
                'brief' => $brief,
                'briefformat' => $briefformat,
                'leaderid' => $userid,
                'guideid' => null,
                'state' => state::FORMING,
                'autoformed' => 0,
                'usermodified' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $group->id = $DB->insert_record('selfselectadvanced_group', $group);
            $group->pluginuid = groups::build_pluginuid($this->activity, (int) $group->id);
            $DB->set_field('selfselectadvanced_group', 'pluginuid', $group->pluginuid, ['id' => $group->id]);

            $DB->insert_record('selfselectadvanced_member', (object) [
                'groupid' => $group->id,
                'userid' => $userid,
                'status' => groups::STATUS_CONFIRMED,
                'isleader' => 1,
                'invitedby' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            // Leading a new group consumes a membership slot too; when
            // it reaches the leader's cap, any other pending invitations
            // of theirs must cascade the same as an accept would (audit:
            // non-accept paths were leaving rivals pending forever).
            $cascaded = $this->invitations()->cascade_at_cap($userid);

            $event = \mod_selfselectadvanced\event\group_created::create([
                'objectid' => $group->id,
                'context' => $this->activity->context(),
                'other' => ['pluginuid' => $group->pluginuid, 'name' => $group->name],
            ]);
            $event->trigger();

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        $this->invitations()->notify_cascaded($cascaded, $userid);

        return $group;
    }

    /**
     * Delete a forming group (transition T7).
     *
     * Confirmed members are notified (provider 'groupdeleted'), the
     * acting user excepted; in the forming state before invitations
     * exist the leader is typically the only confirmed member.
     *
     * @param stdClass $group group row
     * @param int $userid the acting user (must be the leader)
     * @throws \moodle_exception when the gatekeeper refuses
     */
    public function delete_group(stdClass $group, int $userid): void {
        global $DB;

        if ($refusal = $this->gatekeeper->can_delete_group($group, $userid)) {
            throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
        }

        $lock = locks::acquire('group:' . $group->id);
        try {
            $transaction = $DB->start_delegated_transaction();

            $fresh = groups::get($this->activity, (int) $group->id);
            if ($refusal = $this->gatekeeper->can_delete_group($fresh, $userid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            // Confirmed roster captured before the rows disappear, for
            // the post-commit notification below.
            $confirmed = $DB->get_fieldset_select(
                'selfselectadvanced_member',
                'userid',
                'groupid = ? AND status = ?',
                [$fresh->id, groups::STATUS_CONFIRMED]
            );

            $DB->delete_records('selfselectadvanced_member', ['groupid' => $fresh->id]);
            $DB->delete_records('selfselectadvanced_group', ['id' => $fresh->id]);

            $event = \mod_selfselectadvanced\event\group_deleted::create([
                'objectid' => $fresh->id,
                'context' => $this->activity->context(),
                'other' => ['pluginuid' => $fresh->pluginuid, 'name' => $fresh->name],
            ]);
            $event->trigger();

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        // The proposal attachments go with the group, and only once the
        // deletion has actually committed: file storage is not part of
        // the transaction, so removing them any earlier would destroy
        // the attachments of a group that a rollback then kept alive.
        get_file_storage()->delete_area_files(
            $this->activity->context()->id,
            'mod_selfselectadvanced',
            'proposal',
            (int) $fresh->id
        );

        $url = new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $this->activity->cm()->id]);
        foreach ($confirmed as $memberid) {
            if ((int) $memberid === $userid) {
                continue;
            }
            notifier::send(
                $this->activity,
                'groupdeleted',
                (int) $memberid,
                'msggroupdeletedsubject',
                'msggroupdeletedbody',
                (object) ['group' => format_string($fresh->name), 'activity' => $this->activity->name()],
                $url,
                $this->activity->name()
            );
        }
    }
}
