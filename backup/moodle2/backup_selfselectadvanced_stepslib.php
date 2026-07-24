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

/**
 * Backup structure for mod_selfselectadvanced (spec 14.11).
 *
 * Included: instance settings, quota rules; with userinfo also groups,
 * members, snapshots, user/group/guide-scope overrides and penalties.
 * EXCLUDED by design and documented (review item M2): agrun logs
 * (operational) and staged moves (transient manager state - a restore
 * must never replay half-staged edits). Site-wide participant
 * attributes are not course data and are never in course backups.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The single structure step.
 */
class backup_selfselectadvanced_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the backup tree.
     *
     * @return backup_nested_element the wrapped structure
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $activity = new backup_nested_element('selfselectadvanced', ['id'], [
            'name', 'intro', 'introformat', 'grade', 'minsize', 'maxsize', 'maxlead',
            'maxmembership', 'maxguided', 'timeopen', 'timedue', 'timecutoff',
            'penaltytype', 'penaltyperday', 'guidemode', 'inviteexpiry', 'autogroup',
            'timecreated', 'timemodified',
        ]);
        $quotas = new backup_nested_element('quotas');
        $quota = new backup_nested_element('quota', ['id'], [
            'dimension', 'rtype', 'value', 'mincount', 'maxcount', 'priority', 'timecreated', 'timemodified',
        ]);
        $groups = new backup_nested_element('groups');
        $group = new backup_nested_element('group', ['id'], [
            'pluginuid', 'name', 'title', 'brief', 'briefformat', 'leaderid', 'guideid',
            'state', 'autoformed', 'successorid', 'successortype', 'timenominated',
            'returncomment', 'timesubmitted', 'timeapproved', 'timefrozen', 'coregroupid',
            'timecreated', 'timemodified',
        ]);
        $members = new backup_nested_element('members');
        $member = new backup_nested_element('member', ['id'], [
            'userid', 'status', 'isleader', 'invitedby', 'timeinvited', 'timeresponded',
            'leaverequested', 'timecreated', 'timemodified',
        ]);
        $snapshots = new backup_nested_element('snapshots');
        $snapshot = new backup_nested_element('snapshot', ['id'], [
            'coregroupid', 'roster', 'takenby', 'timecreated',
        ]);
        $penalty = new backup_nested_element('penalty', ['id'], [
            'dayslate', 'penaltyvalue', 'waived', 'waivereason', 'basis', 'timecomputed',
        ]);
        $overrides = new backup_nested_element('overrides');
        $override = new backup_nested_element('override', ['id'], [
            'scope', 'userid', 'groupid', 'timeopen', 'timedue', 'timecutoff', 'maxlead',
            'maxmembership', 'maxguided', 'minsize', 'maxsize', 'quotaexempt',
            'penaltywaived', 'timecreated', 'timemodified',
        ]);

        $activity->add_child($quotas);
        $quotas->add_child($quota);
        $activity->add_child($groups);
        $groups->add_child($group);
        $group->add_child($members);
        $members->add_child($member);
        $group->add_child($snapshots);
        $snapshots->add_child($snapshot);
        $group->add_child($penalty);
        $activity->add_child($overrides);
        $overrides->add_child($override);

        $activity->set_source_table('selfselectadvanced', ['id' => backup::VAR_ACTIVITYID]);
        $quota->set_source_table('selfselectadvanced_quota', ['activityid' => backup::VAR_PARENTID]);
        if ($userinfo) {
            $group->set_source_table('selfselectadvanced_group', ['activityid' => backup::VAR_PARENTID]);
            $member->set_source_table('selfselectadvanced_member', ['groupid' => backup::VAR_PARENTID]);
            $snapshot->set_source_table('selfselectadvanced_snapshot', ['groupid' => backup::VAR_PARENTID]);
            $penalty->set_source_table('selfselectadvanced_penalty', ['groupid' => backup::VAR_PARENTID]);
            // Move-scope override rows are skipped with their moves (M2).
            $override->set_source_sql(
                "SELECT * FROM {selfselectadvanced_override}
                  WHERE activityid = ? AND scope IN ('user', 'group', 'guide')",
                [backup::VAR_PARENTID]
            );
        }

        $member->annotate_ids('user', 'userid');
        $member->annotate_ids('user', 'invitedby');
        $group->annotate_ids('user', 'leaderid');
        $group->annotate_ids('user', 'guideid');
        $group->annotate_ids('user', 'successorid');
        $group->annotate_ids('group', 'coregroupid');
        $snapshot->annotate_ids('group', 'coregroupid');
        $snapshot->annotate_ids('user', 'takenby');
        $override->annotate_ids('user', 'userid');

        return $this->prepare_activity_structure($activity);
    }
}
