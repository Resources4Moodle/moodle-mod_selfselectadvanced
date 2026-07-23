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
     * @return stdClass with confirmed, invited, taken, max, free
     */
    public function seat_position(stdClass $group): stdClass {
        $confirmed = groups::count_confirmed((int) $group->id);
        $invited = groups::count_invited((int) $group->id);
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
