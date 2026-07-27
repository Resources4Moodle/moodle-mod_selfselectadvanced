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
 * Guide handover: an assigned guide may leave a submitted, firm or
 * frozen team ONLY by nominating another guide with free capacity, and
 * only that guide's acceptance completes the exit — the team is never
 * left guideless. One pending handover per group; the proposer remains
 * the guide (and keeps carrying the commitment) until acceptance.
 *
 * Locks follow the plugin-wide ordering: the NOMINEE's guide lock
 * before the group lock, so acceptance and every other capacity commit
 * serialise on the guide whose cap is being consumed.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handover {
    /** @var string[] States in which a handover applies. */
    private const STATES = [state::PENDING_GUIDE, state::FIRM, state::FROZEN];

    /** @var activity The activity. */
    private readonly activity $activity;

    /** @var gatekeeper The rule gatekeeper. */
    private readonly gatekeeper $gatekeeper;

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param gatekeeper $gatekeeper the gatekeeper (capacity source)
     */
    public function __construct(activity $activity, gatekeeper $gatekeeper) {
        $this->activity = $activity;
        $this->gatekeeper = $gatekeeper;
    }

    /**
     * The current guide proposes handing the team to another guide.
     *
     * @param int $groupid the group
     * @param int $nomineeid the proposed replacement guide
     * @param int $actorid the acting (current) guide
     * @throws \moodle_exception on any refusal
     */
    public function propose(int $groupid, int $nomineeid, int $actorid): void {
        global $DB;

        $guidelock = locks::acquire('eoiguide:' . $nomineeid);
        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            if (!in_array($group->state, self::STATES, true)) {
                throw new \moodle_exception('refusalhandoverstate', 'mod_selfselectadvanced');
            }
            if ((int) $group->guideid !== $actorid) {
                throw new \moodle_exception('refusalhandovernotguide', 'mod_selfselectadvanced');
            }
            if ($nomineeid === $actorid) {
                throw new \moodle_exception('refusalhandoverself', 'mod_selfselectadvanced');
            }
            if (!empty($group->guidesuccessorid)) {
                throw new \moodle_exception('refusalhandoverpending', 'mod_selfselectadvanced');
            }
            if ($refusal = $this->gatekeeper->can_take_guide($nomineeid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $group->id,
                'guidesuccessorid' => $nomineeid,
                'timeguidenominated' => time(),
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);
            $transaction->allow_commit();
        } finally {
            $lock->release();
            $guidelock->release();
        }

        notifier::send(
            $this->activity,
            'guidequeue',
            $nomineeid,
            'msghandoverproposedsubject',
            'msghandoverproposedbody',
            (object) [
                'group' => format_string($group->name),
                'from' => fullname(\core_user::get_user($actorid)),
                'activity' => $this->activity->name(),
            ],
            $this->guide_url(),
            format_string($group->name)
        );
    }

    /**
     * The nominated guide accepts: the team changes hands atomically,
     * the capacity re-checked under the nominee's own guide lock.
     *
     * @param int $groupid the group
     * @param int $actorid the acting (nominated) guide
     * @throws \moodle_exception on any refusal
     */
    public function accept(int $groupid, int $actorid): void {
        global $DB;

        $guidelock = locks::acquire('eoiguide:' . $actorid);
        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            if ((int) ($group->guidesuccessorid ?? 0) !== $actorid) {
                throw new \moodle_exception('refusalhandovernonominee', 'mod_selfselectadvanced');
            }
            if (!in_array($group->state, self::STATES, true)) {
                throw new \moodle_exception('refusalhandoverstate', 'mod_selfselectadvanced');
            }
            if ($refusal = $this->gatekeeper->can_take_guide($actorid)) {
                throw new \moodle_exception($refusal->stringkey, 'mod_selfselectadvanced', '', $refusal->a);
            }

            $oldguide = (int) $group->guideid;
            $DB->update_record('selfselectadvanced_group', (object) [
                'id' => $group->id,
                'guideid' => $actorid,
                'guidesuccessorid' => null,
                'timeguidenominated' => null,
                'usermodified' => $actorid,
                'timemodified' => time(),
            ]);

            \mod_selfselectadvanced\event\guide_reassigned::create([
                'objectid' => $group->id,
                'context' => $this->activity->context(),
                'relateduserid' => $actorid,
                'other' => [
                    'pluginuid' => $group->pluginuid,
                    'fromguideid' => $oldguide,
                    'via' => 'handover',
                ],
            ])->trigger();

            $transaction->allow_commit();
        } finally {
            $lock->release();
            $guidelock->release();
        }

        $a = (object) [
            'group' => format_string($group->name),
            'to' => fullname(\core_user::get_user($actorid)),
            'newguide' => fullname(\core_user::get_user($actorid)),
            'activity' => $this->activity->name(),
        ];
        notifier::send($this->activity, 'guidequeue', $oldguide, 'msghandoveracceptedsubject',
            'msghandoveracceptedbody', $a, $this->guide_url(), format_string($group->name));
        notifier::send($this->activity, 'guidechanged', (int) $group->leaderid, 'msgguidechangedsubject',
            'msgguidechangedbody', $a, $this->group_url((int) $group->id), format_string($group->name));
    }

    /**
     * The nominated guide declines; the proposer stays the guide.
     *
     * @param int $groupid the group
     * @param int $actorid the acting (nominated) guide
     * @throws \moodle_exception when no handover awaits this actor
     */
    public function decline(int $groupid, int $actorid): void {
        global $DB;

        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            if ((int) ($group->guidesuccessorid ?? 0) !== $actorid) {
                throw new \moodle_exception('refusalhandovernonominee', 'mod_selfselectadvanced');
            }
            $this->clear($group, $actorid);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        notifier::send(
            $this->activity,
            'guidequeue',
            (int) $group->guideid,
            'msghandoverdeclinedsubject',
            'msghandoverdeclinedbody',
            (object) [
                'group' => format_string($group->name),
                'to' => fullname(\core_user::get_user($actorid)),
                'activity' => $this->activity->name(),
            ],
            $this->guide_url(),
            format_string($group->name)
        );
    }

    /**
     * The proposing guide withdraws the pending handover.
     *
     * @param int $groupid the group
     * @param int $actorid the acting (current) guide
     * @throws \moodle_exception when the actor is not the guide or nothing is pending
     */
    public function cancel(int $groupid, int $actorid): void {
        global $DB;

        $lock = locks::acquire('group:' . $groupid);
        try {
            $transaction = $DB->start_delegated_transaction();

            $group = groups::get($this->activity, $groupid);
            if ((int) $group->guideid !== $actorid) {
                throw new \moodle_exception('refusalhandovernotguide', 'mod_selfselectadvanced');
            }
            if (empty($group->guidesuccessorid)) {
                throw new \moodle_exception('refusalhandovernonominee', 'mod_selfselectadvanced');
            }
            $this->clear($group, $actorid);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Groups awaiting THIS guide's handover decision.
     *
     * @param int $guideid the nominated guide
     * @return stdClass[] group rows
     */
    public function incoming(int $guideid): array {
        global $DB;

        return $DB->get_records('selfselectadvanced_group', [
            'activityid' => $this->activity->id(),
            'guidesuccessorid' => $guideid,
        ], 'timeguidenominated ASC');
    }

    /**
     * Blank the nominee fields on a group row.
     *
     * @param stdClass $group the fresh group row
     * @param int $actorid the acting user
     */
    private function clear(stdClass $group, int $actorid): void {
        global $DB;

        $DB->update_record('selfselectadvanced_group', (object) [
            'id' => $group->id,
            'guidesuccessorid' => null,
            'timeguidenominated' => null,
            'usermodified' => $actorid,
            'timemodified' => time(),
        ]);
    }

    /**
     * The guide dashboard URL.
     *
     * @return \moodle_url
     */
    private function guide_url(): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $this->activity->cm()->id]);
    }

    /**
     * A group page URL.
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
