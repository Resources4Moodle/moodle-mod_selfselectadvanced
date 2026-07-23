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

namespace mod_selfselectadvanced\local\override;

use mod_selfselectadvanced\activity;

/**
 * The single override-resolution service (spec section 10).
 *
 * Every "effective value" question anywhere in the plugin is answered
 * here: effective dates, the five numeric limits L1-L5, quota exemptions
 * and penalty waivers. No rule check may consult override data or raw
 * activity settings through any other path; doing so is a
 * review-blocking defect.
 *
 * Precedence (per field): group override > user override > activity
 * setting. Group-level assessments (the penalty ledger) resolve with the
 * group's leader as the user context (precedence row P16).
 *
 * Slices 1-6 run against this API with no override rows in existence;
 * slice 7 supplies the store that find_overrides() reads, which changes
 * no caller.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {
    /** @var array<string, ?\stdClass> Cache of override rows keyed by scope:target. */
    private ?array $overrides = null;

    /**
     * Constructor.
     *
     * @param activity $activity the activity to resolve for
     */
    public function __construct(
        /** @var activity The activity to resolve for. */
        private readonly activity $activity,
    ) {
    }

    /**
     * Effective formation window for a user, optionally in a group context.
     *
     * Per-field resolution: for each of timeopen, timedue and timecutoff
     * independently, a group override field wins over a user override
     * field, which wins over the activity setting (precedence rows P1-P7).
     *
     * @param int $userid the acting user
     * @param int|null $groupid group context, when the action concerns a group
     * @return effective_dates
     */
    public function effective_dates(int $userid, ?int $groupid = null): effective_dates {
        $settings = $this->activity->settings();
        $user = $this->find_override('user', $userid);
        $group = $groupid ? $this->find_override('group', $groupid) : null;

        $fields = [];
        $sources = [];
        foreach (['timeopen', 'timedue', 'timecutoff'] as $field) {
            if ($group !== null && $group->$field !== null) {
                $fields[$field] = (int) $group->$field;
                $sources[$field] = effective_value::SOURCE_GROUP;
            } else if ($user !== null && $user->$field !== null) {
                $fields[$field] = (int) $user->$field;
                $sources[$field] = effective_value::SOURCE_USER;
            } else {
                $fields[$field] = (int) $settings->$field;
                $sources[$field] = effective_value::SOURCE_ACTIVITY;
            }
        }

        return new effective_dates($fields['timeopen'], $fields['timedue'], $fields['timecutoff'], $sources);
    }

    /**
     * Effective dates for a group-level assessment such as the penalty
     * ledger (precedence row P16): the standard chain applied with the
     * group's leader as the user context.
     *
     * @param int $groupid the group being assessed
     * @return effective_dates
     */
    public function assessment_dates(int $groupid): effective_dates {
        global $DB;

        $leaderid = (int) $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => $groupid], MUST_EXIST);

        return $this->effective_dates($leaderid, $groupid);
    }

    /**
     * Effective minimum group size (L1) for a group.
     *
     * @param int $groupid the group
     * @return effective_value
     */
    public function effective_minsize(int $groupid): effective_value {
        return $this->group_limit('minsize', $groupid);
    }

    /**
     * Effective maximum group size (L2) for a group.
     *
     * @param int $groupid the group
     * @return effective_value
     */
    public function effective_maxsize(int $groupid): effective_value {
        return $this->group_limit('maxsize', $groupid);
    }

    /**
     * Effective maximum groups led (L3) for a user.
     *
     * @param int $userid the user
     * @return effective_value
     */
    public function effective_maxlead(int $userid): effective_value {
        return $this->user_limit('maxlead', $userid);
    }

    /**
     * Effective maximum group memberships (L4) for a user.
     *
     * @param int $userid the user
     * @return effective_value
     */
    public function effective_maxmembership(int $userid): effective_value {
        return $this->user_limit('maxmembership', $userid);
    }

    /**
     * Effective maximum groups guided (L5) for a guide.
     *
     * @param int $guideid the guide
     * @return effective_value
     */
    public function effective_maxguided(int $guideid): effective_value {
        $override = $this->find_override('guide', $guideid);
        if ($override !== null && $override->maxguided !== null) {
            return new effective_value((int) $override->maxguided, effective_value::SOURCE_GUIDE, (int) $override->id);
        }

        return new effective_value((int) $this->activity->settings()->maxguided);
    }

    /**
     * Whether a group is exempt from quota rules.
     *
     * @param int $groupid the group
     * @return effective_flag
     */
    public function is_quota_exempt(int $groupid): effective_flag {
        $override = $this->find_override('group', $groupid);
        if ($override !== null && !empty($override->quotaexempt)) {
            return new effective_flag(true, effective_value::SOURCE_GROUP, (int) $override->id);
        }

        return new effective_flag(false);
    }

    /**
     * Whether a group's penalty is waived by override flag.
     *
     * Independently of this flag, a group approved within its overridden
     * window incurs no penalty by arithmetic: the calculator only ever
     * sees effective dates (behavioural guarantee, spec section 10).
     *
     * @param int $groupid the group
     * @return effective_flag
     */
    public function is_penalty_waived(int $groupid): effective_flag {
        $override = $this->find_override('group', $groupid);
        if ($override !== null && !empty($override->penaltywaived)) {
            return new effective_flag(true, effective_value::SOURCE_GROUP, (int) $override->id);
        }

        return new effective_flag(false);
    }

    /**
     * Rule codes bypassed by the override attached to a staged move.
     *
     * Consulted only while validating or committing that move.
     *
     * @param int $moveid the staged move
     * @return string[] rule codes such as L1, L2, QUOTA
     */
    public function move_bypasses(int $moveid): array {
        $override = $this->find_override('move', $moveid);
        if ($override === null || $override->rulesbypassed === null || $override->rulesbypassed === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $override->rulesbypassed))));
    }

    /**
     * Resolve a group-scope numeric limit (L1, L2).
     *
     * @param string $field minsize or maxsize
     * @param int $groupid the group
     * @return effective_value
     */
    private function group_limit(string $field, int $groupid): effective_value {
        $override = $this->find_override('group', $groupid);
        if ($override !== null && $override->$field !== null) {
            return new effective_value((int) $override->$field, effective_value::SOURCE_GROUP, (int) $override->id);
        }

        return new effective_value((int) $this->activity->settings()->$field);
    }

    /**
     * Resolve a user-scope numeric limit (L3, L4).
     *
     * @param string $field maxlead or maxmembership
     * @param int $userid the user
     * @return effective_value
     */
    private function user_limit(string $field, int $userid): effective_value {
        $override = $this->find_override('user', $userid);
        if ($override !== null && $override->$field !== null) {
            return new effective_value((int) $override->$field, effective_value::SOURCE_USER, (int) $override->id);
        }

        return new effective_value((int) $this->activity->settings()->$field);
    }

    /**
     * Find the override row for a scope and target, if any.
     *
     * All rows for the activity are loaded once and cached. Duplicate
     * rows for one target cannot be created through the store; if legacy
     * duplicates exist the highest id (latest) wins deterministically
     * and a debugging notice is raised (precedence row P14).
     *
     * @param string $scope user, group, guide or move
     * @param int $targetid target user, group or move id
     * @return \stdClass|null the override row or null
     */
    protected function find_override(string $scope, int $targetid): ?\stdClass {
        if ($this->overrides === null) {
            $this->load_overrides();
        }

        return $this->overrides[$scope . ':' . $targetid] ?? null;
    }

    /**
     * Load and index all override rows for the activity in one query.
     */
    private function load_overrides(): void {
        global $DB;

        $this->overrides = [];
        $rows = $DB->get_records('selfselectadvanced_override', ['activityid' => $this->activity->id()], 'id ASC');
        foreach ($rows as $row) {
            $targetid = match ($row->scope) {
                'user', 'guide' => (int) $row->userid,
                'group' => (int) $row->groupid,
                'move' => (int) $row->moveid,
                default => 0,
            };
            if (!$targetid) {
                debugging('Override row ' . $row->id . ' has no target for scope ' . $row->scope, DEBUG_DEVELOPER);
                continue;
            }
            $key = $row->scope . ':' . $targetid;
            if (isset($this->overrides[$key])) {
                debugging('Duplicate override rows for ' . $key . '; latest wins', DEBUG_DEVELOPER);
            }
            $this->overrides[$key] = $row;
        }
    }
}
