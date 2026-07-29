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

namespace mod_selfselectadvanced\local\rules;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\resolver;
use mod_selfselectadvanced\local\state;
use stdClass;

/**
 * The single choke point for every limit, quota and window check
 * (architecture plan section 7).
 *
 * Pure decision logic: methods return null when the action is allowed
 * or a refusal describing why not; they never mutate state. All
 * effective values come from the override resolver - a raw settings
 * read here is a review-blocking defect. Every method checks its state
 * precondition first (review item S2).
 *
 * On grandfathering (spec section 4A.8): checks compare the CURRENT
 * count against the CURRENT effective limit, so an existing over-limit
 * situation created by tightening a setting is never made worse - any
 * increasing action is refused (count >= limit already) while existing
 * groups stay valid. This is the violation-increase block of review
 * item B3, inherent in every >=-check below.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gatekeeper {
    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param resolver $resolver the override resolver (sole source of effective values)
     */
    public function __construct(
        /** @var activity The activity. */
        private readonly activity $activity,
        /** @var resolver The override resolver. */
        private readonly resolver $resolver,
    ) {
    }

    /**
     * The resolver in use, for callers that need effective values for display.
     *
     * @return resolver
     */
    public function resolver(): resolver {
        return $this->resolver;
    }

    /**
     * May this user create (and thereby lead) a new group?
     *
     * State precondition: none (no group exists yet). Checks: formation
     * window (user-effective dates), L3 lead cap, L4 membership cap.
     *
     * @param int $userid the student
     * @param int|null $now time of the action, defaults to now
     * @return refusal|null null when allowed
     */
    public function can_create_group(int $userid, ?int $now = null): ?refusal {
        $now = $now ?? time();

        if ($refusal = $this->check_window($userid, null, $now)) {
            return $refusal;
        }

        $maxlead = $this->resolver->effective_maxlead($userid);
        $leading = groups::count_leading($this->activity, $userid);
        if ($leading >= $maxlead->value) {
            return new refusal('refusalleadcap', (object) ['current' => $leading, 'max' => $maxlead->value]);
        }

        $maxmembership = $this->resolver->effective_maxmembership($userid);
        $memberships = groups::count_memberships($this->activity, $userid);
        if ($memberships >= $maxmembership->value) {
            return new refusal('refusalmembershipcap', (object) [
                'current' => $memberships,
                'max' => $maxmembership->value,
            ]);
        }

        return null;
    }

    /**
     * May the leader delete this group?
     *
     * State precondition: forming only (spec section 6.3).
     *
     * @param stdClass $group group row
     * @param int $userid the acting user
     * @return refusal|null null when allowed
     */
    public function can_delete_group(stdClass $group, int $userid): ?refusal {
        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate', $group->state);
        }
        if ((int) $group->leaderid !== $userid) {
            return new refusal('refusalnotleader');
        }

        return null;
    }

    /**
     * May the leader invite this user to the group? (Spec section 6.2.)
     *
     * State precondition: forming (S2). Blocks, in order: window closed
     * for the inviter's group context; (c) invitee already confirmed;
     * (b) invitee already has a pending invitation; (a) invitee at
     * their effective membership cap n (L4); (d) no free seat -
     * confirmed plus pending equal the effective max_size (L2, reserved
     * seats). With n = 1, (a) is exactly "a confirmed student of
     * another group cannot be invited" (decision D2).
     *
     * Counts compare against current effective limits, so an over-limit
     * grandfathered state can never grow (review item B3).
     *
     * @param \stdClass $group group row
     * @param int $inviteeid the candidate
     * @param int|null $now time of the action, defaults to now
     * @return refusal|null null when allowed
     */
    public function can_invite(stdClass $group, int $inviteeid, ?int $now = null): ?refusal {
        global $DB;
        $now = $now ?? time();

        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate');
        }
        if ($refusal = $this->check_window((int) $group->leaderid, (int) $group->id, $now)) {
            return $refusal;
        }

        $existing = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $inviteeid,
        ]);
        if ($existing && $existing->status === groups::STATUS_CONFIRMED) {
            return new refusal('refusalalreadymember');
        }
        if ($existing && $existing->status === groups::STATUS_INVITED) {
            return new refusal('refusalalreadyinvited');
        }

        $cap = $this->resolver->effective_maxmembership($inviteeid);
        $memberships = groups::count_memberships($this->activity, $inviteeid);
        if ($memberships >= $cap->value) {
            return new refusal('refusalinviteecap', (object) ['current' => $memberships, 'max' => $cap->value]);
        }

        $seats = $this->seat_position($group);
        if ($seats->free < 1) {
            return new refusal('refusalnoseats');
        }

        return $this->check_composition_feasibility($group, $inviteeid);
    }

    /**
     * Whether admitting a member leaves the composition reachable: an
     * exceeded counting-rule maximum can never self-heal by adding
     * members, and a seat-plan deficiency larger than the free seats
     * below the effective maximum can never be filled. Quota-exempt
     * groups skip the gate, exactly as the submit gate does.
     *
     * @param \stdClass $group group row
     * @param int|null $candidateid prospective member, null when already seated
     * @return refusal|null null when compliance stays reachable
     */
    protected function check_composition_feasibility(stdClass $group, ?int $candidateid): ?refusal {
        if ($this->resolver->is_quota_exempt((int) $group->id)->enabled) {
            return null;
        }
        $feasibility = \mod_selfselectadvanced\local\quota\evaluator::feasibility(
            $this->activity,
            (int) $group->id,
            $candidateid
        );
        if ($feasibility->maxexceeded !== null) {
            return new refusal('refusalcompositionmax', $feasibility->maxexceeded);
        }
        $max = $this->resolver->effective_maxsize((int) $group->id);
        $free = max(0, $max->value - $feasibility->seated);
        if ($feasibility->missing > $free) {
            return new refusal('refusalcompositionunreachable', (object) [
                'missing' => $feasibility->missing,
                'free' => $free,
            ]);
        }

        return null;
    }

    /**
     * May this invitee accept their pending invitation?
     *
     * State precondition: forming (S2). Re-checks atomically inside the
     * acceptance transaction: the invitee's own effective window, the
     * group's seat count (L2) and the invitee's membership cap (L4) -
     * two simultaneous acceptances must overshoot neither (spec 6.2).
     *
     * @param \stdClass $group group row
     * @param \stdClass $member the invited member row
     * @param int|null $now time of the action, defaults to now
     * @return refusal|null null when allowed
     */
    public function can_accept(stdClass $group, stdClass $member, ?int $now = null): ?refusal {
        $now = $now ?? time();

        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate');
        }
        if ($member->status !== groups::STATUS_INVITED) {
            return new refusal('refusalnotinvited');
        }
        if ($refusal = $this->check_window((int) $member->userid, (int) $group->id, $now)) {
            return $refusal;
        }

        $cap = $this->resolver->effective_maxmembership((int) $member->userid);
        $memberships = groups::count_memberships($this->activity, (int) $member->userid);
        if ($memberships >= $cap->value) {
            return new refusal('refusalmembershipcap', (object) ['current' => $memberships, 'max' => $cap->value]);
        }

        // Seat re-check: the invitee already holds a reserved seat, so the
        // group is over only if confirmed-plus-invited exceeds the maximum.
        $seats = $this->seat_position($group);
        if ($seats->taken > $seats->max) {
            return new refusal('refusalnoseats');
        }

        // The roster may have changed since the invitation: re-check
        // that this acceptance keeps the composition reachable (the
        // acceptor already sits in the invited basis, so no candidate).
        return $this->check_composition_feasibility($group, null);
    }

    /**
     * May the leader nominate this member as successor? (Spec 6.4, A3.)
     *
     * State precondition: forming (S2). The nominee must be a confirmed
     * member other than the leader, no other nomination may be active,
     * and the nominee needs a free lead slot under their effective
     * max_lead (L3) - members at their cap are excluded from the
     * nomination list with this reason shown.
     *
     * @param \stdClass $group group row
     * @param int $nomineeid the proposed successor
     * @param string $type 'transfer' or 'stepout'
     * @param int $actorid the acting user
     * @return refusal|null null when allowed
     */
    public function can_nominate(stdClass $group, int $nomineeid, string $type, int $actorid): ?refusal {
        global $DB;

        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate');
        }
        if ((int) $group->leaderid !== $actorid) {
            return new refusal('refusalnotleader');
        }
        if (!in_array($type, ['transfer', 'stepout'], true)) {
            return new refusal('refusalwrongstate');
        }
        if (!empty($group->successorid)) {
            return new refusal('refusalnominationactive');
        }
        if ($nomineeid === (int) $group->leaderid) {
            return new refusal('refusalnomineeisleader');
        }
        $member = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $nomineeid,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        if (!$member) {
            return new refusal('refusalnomineenotmember');
        }

        return $this->check_nominee_leadslot($nomineeid);
    }

    /**
     * May this user confirm the active succession nomination?
     *
     * State precondition: forming (S2). Re-checked atomically inside
     * the confirmation transaction: the nominee's L3 slot (both types),
     * and for step-out the post-departure minimum size (L1) - the
     * leader cannot step out until a replacement member is confirmed
     * (spec 6.4).
     *
     * @param \stdClass $group group row
     * @param int $userid the confirming user
     * @return refusal|null null when allowed
     */
    public function can_confirm_succession(stdClass $group, int $userid): ?refusal {
        global $DB;

        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate');
        }
        if (empty($group->successorid) || (int) $group->successorid !== $userid) {
            return new refusal('refusalnotnominee');
        }
        $member = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $userid,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        if (!$member) {
            return new refusal('refusalnomineenotmember');
        }
        if ($refusal = $this->check_nominee_leadslot($userid)) {
            return $refusal;
        }
        if ($group->successortype === 'stepout') {
            $minsize = $this->resolver->effective_minsize((int) $group->id);
            $after = groups::count_confirmed((int) $group->id) - 1;
            if ($after < $minsize->value) {
                return new refusal('refusalreplacementneeded', (object) [
                    'after' => $after,
                    'min' => $minsize->value,
                ]);
            }
        }

        return null;
    }

    /**
     * L3 check for a succession nominee: a free lead slot under their
     * effective max_lead.
     *
     * @param int $nomineeid the nominee
     * @return refusal|null null when a slot is free
     */
    public function check_nominee_leadslot(int $nomineeid): ?refusal {
        $maxlead = $this->resolver->effective_maxlead($nomineeid);
        $leading = groups::count_leading($this->activity, $nomineeid);
        if ($leading >= $maxlead->value) {
            return new refusal('refusalnomineeleadcap', (object) [
                'current' => $leading,
                'max' => $maxlead->value,
            ]);
        }

        return null;
    }

    /**
     * May the leader submit this group to a guide? (T2, spec 6.5.)
     *
     * State precondition: forming (S2). Checks: window (leader-effective
     * dates), minimum size L1 against effective minsize, quota
     * compliance or exemption. In leader-selects mode the chosen guide
     * is validated separately by can_take_guide().
     *
     * @param \stdClass $group group row
     * @param int $actorid the acting user
     * @param int|null $now time of the action, defaults to now
     * @return refusal|null null when allowed
     */
    public function can_submit(stdClass $group, int $actorid, ?int $now = null): ?refusal {
        $now = $now ?? time();

        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate');
        }
        if ((int) $group->leaderid !== $actorid) {
            return new refusal('refusalnotleader');
        }
        if ($refusal = $this->check_window($actorid, (int) $group->id, $now)) {
            return $refusal;
        }

        $minsize = $this->resolver->effective_minsize((int) $group->id);
        $confirmed = groups::count_confirmed((int) $group->id);
        if ($confirmed < $minsize->value) {
            return new refusal('refusalbelowminsize', (object) [
                'current' => $confirmed,
                'min' => $minsize->value,
            ]);
        }

        // Proposal mandate (1.3.0): when the activity requires a
        // written proposal, submission waits for the upload.
        if (!empty($this->activity->settings()->proposalrequired)) {
            $fs = get_file_storage();
            $hasproposal = !empty($fs->get_area_files(
                $this->activity->context()->id,
                'mod_selfselectadvanced',
                'proposal',
                (int) $group->id,
                'id',
                false
            ));
            if (!$hasproposal) {
                return new refusal('refusalproposalrequired');
            }
        }

        if (
            !\mod_selfselectadvanced\local\quota\evaluator::is_compliant($this->activity, (int) $group->id)
            && !$this->resolver->is_quota_exempt((int) $group->id)->enabled
        ) {
            return new refusal('refusalquota');
        }

        return null;
    }

    /**
     * May this guide take on one more group? (L5, spec 4A.5.)
     *
     * Used at submission (leader-selects) and at manager assignment
     * (A5): the guide's load must leave a free slot.
     *
     * Load counts COMMITMENTS: the guided states plus forming teams the
     * guide is already pre-assigned to through an accepted expression of
     * interest. Without the latter, a guide at capacity through
     * acceptances would still look free to every other leader and to
     * the manager queue (audit 1.11.0). The group currently
     * transitioning is excluded so its own submission is not counted
     * against the guide twice.
     *
     * @param int $guideid the guide
     * @param int $excludegroupid group mid-transition to leave out of the count
     * @return refusal|null null when a slot is free
     */
    public function can_take_guide(int $guideid, int $excludegroupid = 0): ?refusal {
        global $DB;

        if (!has_capability('mod/selfselectadvanced:guide', $this->activity->context(), $guideid)) {
            return new refusal('refusalnotaguide');
        }
        $max = $this->resolver->effective_maxguided($guideid);
        $used = groups::count_guiding($this->activity, $guideid);
        $formingsql = 'activityid = :activityid AND guideid = :guideid AND state = :forming AND id <> :exclude';
        $used += $DB->count_records_select('selfselectadvanced_group', $formingsql, [
            'activityid' => $this->activity->id(),
            'guideid' => $guideid,
            'forming' => \mod_selfselectadvanced\local\state::FORMING,
            'exclude' => $excludegroupid,
        ]);
        if ($used >= $max->value) {
            // Students-approach mode exists to keep guide loads out of
            // the students' view, and the leader is the one who reads
            // this refusal. Naming the numbers here would hand back
            // exactly the figure the chooser was careful not to show.
            if (!empty($this->activity->settings()->studentapproach)) {
                return new refusal('refusalguideunavailable');
            }

            return new refusal('refusalguidecap', (object) ['current' => $used, 'max' => $max->value]);
        }

        return null;
    }

    /**
     * May this guide approve the group? (T4, spec 6.5.)
     *
     * State precondition: pending_guide (S2); the assigned guide only.
     * Atomic re-checks: the guide's load within their effective
     * max_guided (the group already occupies its slot, so the test is
     * load exceeding the cap), minimum size L1 and quota compliance -
     * each bypassable only through an override resolved upstream.
     *
     * @param \stdClass $group group row
     * @param int $actorid the acting user
     * @return refusal|null null when allowed
     */
    public function can_approve(stdClass $group, int $actorid): ?refusal {
        if ($group->state !== state::PENDING_GUIDE) {
            return new refusal('refusalwrongstate');
        }
        if (empty($group->guideid) || (int) $group->guideid !== $actorid) {
            return new refusal('refusalnotassignedguide');
        }

        $max = $this->resolver->effective_maxguided($actorid);
        $used = groups::count_guiding($this->activity, $actorid);
        if ($used > $max->value) {
            return new refusal('refusalguidecap', (object) ['current' => $used, 'max' => $max->value]);
        }

        $minsize = $this->resolver->effective_minsize((int) $group->id);
        $confirmed = groups::count_confirmed((int) $group->id);
        if ($confirmed < $minsize->value) {
            return new refusal('refusalbelowminsize', (object) [
                'current' => $confirmed,
                'min' => $minsize->value,
            ]);
        }

        if (
            !\mod_selfselectadvanced\local\quota\evaluator::is_compliant($this->activity, (int) $group->id)
            && !$this->resolver->is_quota_exempt((int) $group->id)->enabled
        ) {
            return new refusal('refusalquota');
        }

        return null;
    }

    /**
     * May this group be frozen? (T5, spec 12 - defence in depth.)
     *
     * State precondition: firm (S2). Re-checks L1, L2 and quota
     * compliance against effective values; the assigned-guide rule is
     * enforced by the freeze service.
     *
     * @param \stdClass $group group row
     * @return refusal|null null when allowed
     */
    public function can_freeze(stdClass $group): ?refusal {
        if ($group->state !== state::FIRM) {
            return new refusal('refusalwrongstate');
        }

        $minsize = $this->resolver->effective_minsize((int) $group->id);
        $confirmed = groups::count_confirmed((int) $group->id);
        if ($confirmed < $minsize->value) {
            return new refusal('refusalbelowminsize', (object) [
                'current' => $confirmed,
                'min' => $minsize->value,
            ]);
        }
        $maxsize = $this->resolver->effective_maxsize((int) $group->id);
        if ($confirmed > $maxsize->value) {
            return new refusal('refusalnoseats');
        }
        if (
            !\mod_selfselectadvanced\local\quota\evaluator::is_compliant($this->activity, (int) $group->id)
            && !$this->resolver->is_quota_exempt((int) $group->id)->enabled
        ) {
            return new refusal('refusalquota');
        }

        return null;
    }

    /**
     * May this guide return the group to forming? (T3, spec 6.5.)
     *
     * State precondition: pending_guide (S2); the assigned guide only.
     * The mandatory comment is enforced by the return service.
     *
     * @param \stdClass $group group row
     * @param int $actorid the acting user
     * @return refusal|null null when allowed
     */
    public function can_return(stdClass $group, int $actorid): ?refusal {
        if ($group->state !== state::PENDING_GUIDE) {
            return new refusal('refusalwrongstate');
        }
        if (empty($group->guideid) || (int) $group->guideid !== $actorid) {
            return new refusal('refusalnotassignedguide');
        }

        return null;
    }

    /**
     * May the leader confirm this member's leave request? (Spec 6.3.
     * Limit 4A.1 applies to manager moves, not here: leaving a FORMING
     * group is always possible, since the minimum size gates submission.)
     *
     * @param \stdClass $group group row
     * @param \stdClass $member the leaving member row
     * @param int $actorid the acting user
     * @return refusal|null null when allowed
     */
    public function can_confirm_leave(stdClass $group, stdClass $member, int $actorid): ?refusal {
        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate');
        }
        if ((int) $group->leaderid !== $actorid) {
            return new refusal('refusalnotleader');
        }
        if ($member->status !== groups::STATUS_CONFIRMED || empty($member->leaverequested)) {
            return new refusal('refusalnoleaverequest');
        }

        // No minimum-size floor here: forming groups are created below
        // the minimum, so the minimum is a SUBMIT precondition, never a
        // membership invariant — a group must always be able to shrink
        // while forming (firm/frozen membership only moves via manager
        // staged moves, where L1 is a per-move verdict).
        return null;
    }

    /**
     * May the leader withdraw this pending invitation?
     *
     * State precondition: forming (S2); leader only; invited rows only.
     *
     * @param \stdClass $group group row
     * @param \stdClass $member the invited member row
     * @param int $userid the acting user
     * @return refusal|null null when allowed
     */
    public function can_withdraw(stdClass $group, stdClass $member, int $userid): ?refusal {
        if ($group->state !== state::FORMING) {
            return new refusal('refusalwrongstate');
        }
        if ((int) $group->leaderid !== $userid) {
            return new refusal('refusalnotleader');
        }
        if ($member->status !== groups::STATUS_INVITED) {
            return new refusal('refusalnotinvited');
        }

        return null;
    }

    /**
     * Formation-window check against the user's effective dates.
     *
     * @param int $userid the acting user
     * @param int|null $groupid group context, when the action concerns a group
     * @param int $now time of the action
     * @return refusal|null null when inside the window
     */
    public function check_window(int $userid, ?int $groupid, int $now): ?refusal {
        $dates = $this->resolver->effective_dates($userid, $groupid);
        if ($dates->timeopen && $now < $dates->timeopen) {
            return new refusal('refusalnotopen', userdate($dates->timeopen));
        }
        if ($dates->timecutoff && $now > $dates->timecutoff) {
            return new refusal('refusalcutoffpassed', userdate($dates->timecutoff));
        }

        return null;
    }

    /**
     * Position of a user against their lead and membership caps, for the
     * section 4A.6 displays ("You lead 1 of 2 groups").
     *
     * @param int $userid the user
     * @return stdClass with lead {current,max} and membership {current,max}
     */
    public function limit_position(int $userid): stdClass {
        return (object) [
            'lead' => (object) [
                'current' => groups::count_leading($this->activity, $userid),
                'max' => $this->resolver->effective_maxlead($userid)->value,
            ],
            'membership' => (object) [
                'current' => groups::count_memberships($this->activity, $userid),
                'max' => $this->resolver->effective_maxmembership($userid)->value,
            ],
        ];
    }

    /**
     * Seat position of a group against its effective maximum size, for
     * the section 4A.6 displays ("4 of 6 seats filled, 1 invitation pending").
     *
     * @param stdClass $group group row
     * @param int|null $confirmed preloaded confirmed count, null to count
     * @param int|null $invited preloaded invited count, null to count
     * @return stdClass with confirmed, invited, taken, max, free
     */
    public function seat_position(stdClass $group, ?int $confirmed = null, ?int $invited = null): stdClass {
        // Callers rendering many rows pass preloaded counts (RCA-1);
        // everyone else keeps the counted path unchanged.
        $confirmed = $confirmed ?? groups::count_confirmed((int) $group->id);
        $invited = $invited ?? groups::count_invited((int) $group->id);
        $max = $this->resolver->effective_maxsize((int) $group->id)->value;

        return (object) [
            'confirmed' => $confirmed,
            'invited' => $invited,
            'taken' => $confirmed + $invited,
            'max' => $max,
            'free' => max(0, $max - $confirmed - $invited),
            'min' => $this->resolver->effective_minsize((int) $group->id)->value,
        ];
    }
}
