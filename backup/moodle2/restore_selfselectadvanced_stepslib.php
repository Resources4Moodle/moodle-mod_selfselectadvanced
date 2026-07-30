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
 * Restore structure for mod_selfselectadvanced (spec 14.11): user ids
 * remapped throughout, coregroupid remapped to the restored core
 * group, snapshot rosters remapped in code, guide expressions of
 * interest (1.11.0) keyed to their restored group with guideid
 * remapped.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * The single restore structure step.
 */
class restore_selfselectadvanced_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the paths to process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $paths = [
            new restore_path_element('selfselectadvanced', '/activity/selfselectadvanced'),
            new restore_path_element('ssaquota', '/activity/selfselectadvanced/quotas/quota'),
            new restore_path_element('ssatemplate', '/activity/selfselectadvanced/templates/template'),
            new restore_path_element('ssaqslot', '/activity/selfselectadvanced/qslots/qslot'),
        ];
        if ($userinfo) {
            $paths[] = new restore_path_element('ssagroup', '/activity/selfselectadvanced/groups/group');
            $paths[] = new restore_path_element(
                'ssamember',
                '/activity/selfselectadvanced/groups/group/members/member'
            );
            $paths[] = new restore_path_element(
                'ssasnapshot',
                '/activity/selfselectadvanced/groups/group/snapshots/snapshot'
            );
            $paths[] = new restore_path_element(
                'ssapenalty',
                '/activity/selfselectadvanced/groups/group/penalty'
            );
            $paths[] = new restore_path_element(
                'ssaeoi',
                '/activity/selfselectadvanced/groups/group/eois/eoi'
            );
            $paths[] = new restore_path_element(
                'ssaoverride',
                '/activity/selfselectadvanced/overrides/override'
            );
            $paths[] = new restore_path_element(
                'ssavolunteer',
                '/activity/selfselectadvanced/volunteers/volunteer'
            );
            $paths[] = new restore_path_element(
                'ssadigestitem',
                '/activity/selfselectadvanced/digestqueue/digestitem'
            );
            $paths[] = new restore_path_element(
                'ssaticket',
                '/activity/selfselectadvanced/tickets/ticket'
            );
            $paths[] = new restore_path_element(
                'ssacontact',
                '/activity/selfselectadvanced/contacts/contact'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the instance row.
     *
     * @param array $data the row
     */
    protected function process_selfselectadvanced($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $newid = $DB->insert_record('selfselectadvanced', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restore a quota rule.
     *
     * @param array $data the row
     */
    protected function process_ssaquota($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $DB->insert_record('selfselectadvanced_quota', $data);
    }

    /**
     * Restore a notification template override.
     *
     * @param array $data the row
     */
    protected function process_ssatemplate($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $DB->insert_record('selfselectadvanced_template', $data);
    }

    /**
     * Restore a composition-template slot.
     *
     * @param array $data the row
     */
    protected function process_ssaqslot($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $DB->insert_record('selfselectadvanced_qslot', $data);
    }

    /**
     * Restore a group with remapped people; the plugin uid keeps its
     * original value (unique plugin-wide by construction).
     *
     * @param array $data the row
     */
    protected function process_ssagroup($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->leaderid = $data->leaderid ? ($this->get_mappingid('user', $data->leaderid) ?: 0) : 0;
        $data->guideid = $data->guideid ? ($this->get_mappingid('user', $data->guideid) ?: null) : null;
        $data->successorid = $data->successorid
            ? ($this->get_mappingid('user', $data->successorid) ?: null)
            : null;
        $data->guidesuccessorid = !empty($data->guidesuccessorid)
            ? ($this->get_mappingid('user', $data->guidesuccessorid) ?: null)
            : null;
        $data->coregroupid = $data->coregroupid
            ? ($this->get_mappingid('group', $data->coregroupid) ?: null)
            : null;
        $data->usermodified = 0;
        // The plugin uid is unique plugin-wide: a same-site restore
        // would collide with the original, so regenerate on collision
        // from the new row id (decision D3: the uid's uniqueness rides
        // on the database id).
        if ($DB->record_exists('selfselectadvanced_group', ['pluginuid' => $data->pluginuid])) {
            $data->pluginuid = '';
        }
        $newid = $DB->insert_record('selfselectadvanced_group', $data);
        if ($data->pluginuid === '') {
            $activity = \mod_selfselectadvanced\activity::from_instance((int) $data->activityid);
            $DB->set_field(
                'selfselectadvanced_group',
                'pluginuid',
                \mod_selfselectadvanced\local\groups::build_pluginuid($activity, (int) $newid),
                ['id' => $newid]
            );
        }
        $this->set_mapping('ssagroup', $oldid, $newid);
    }

    /**
     * Restore a member row.
     *
     * @param array $data the row
     */
    protected function process_ssamember($data) {
        global $DB;

        $data = (object) $data;
        $data->groupid = $this->get_new_parentid('ssagroup');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->invitedby = $data->invitedby ? ($this->get_mappingid('user', $data->invitedby) ?: null) : null;
        if ($data->userid) {
            $DB->insert_record('selfselectadvanced_member', $data);
        }
    }

    /**
     * Restore a snapshot with a remapped roster.
     *
     * @param array $data the row
     */
    protected function process_ssasnapshot($data) {
        global $DB;

        $data = (object) $data;
        $data->groupid = $this->get_new_parentid('ssagroup');
        $data->coregroupid = $data->coregroupid ? ($this->get_mappingid('group', $data->coregroupid) ?: 0) : 0;
        $data->takenby = $data->takenby ? ($this->get_mappingid('user', $data->takenby) ?: 0) : 0;
        $roster = json_decode($data->roster, true) ?: [];
        $remapped = [];
        foreach ($roster as $entry) {
            $newuser = $this->get_mappingid('user', (int) $entry['userid']);
            if ($newuser) {
                $remapped[] = ['userid' => (int) $newuser, 'isleader' => (int) ($entry['isleader'] ?? 0)];
            }
        }
        $data->roster = json_encode($remapped);
        $DB->insert_record('selfselectadvanced_snapshot', $data);
    }

    /**
     * Restore a penalty row.
     *
     * @param array $data the row
     */
    protected function process_ssapenalty($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->groupid = $this->get_new_parentid('ssagroup');
        $DB->insert_record('selfselectadvanced_penalty', $data);
    }

    /**
     * Restore an expression of interest, keyed to its restored group.
     * A guide that could not be mapped (removed from the site since the
     * backup) drops the row rather than insert a not-null guideid as 0,
     * exactly like a member row with no mappable user.
     *
     * @param array $data the row
     */
    protected function process_ssaeoi($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->groupid = $this->get_new_parentid('ssagroup');
        $data->guideid = $this->get_mappingid('user', $data->guideid);
        if (!$data->guideid) {
            return;
        }
        $DB->insert_record('selfselectadvanced_eoi', $data);
    }

    /**
     * Restore a user/group/guide-scope override.
     *
     * @param array $data the row
     */
    protected function process_ssaoverride($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->moveid = null;
        $data->userid = $data->userid ? $this->get_mappingid('user', $data->userid) : null;
        $data->groupid = $data->groupid ? ($this->get_mappingid('ssagroup', $data->groupid) ?: null) : null;
        if (in_array($data->scope, ['user', 'guide'], true) && !$data->userid) {
            return;
        }
        if ($data->scope === 'group' && !$data->groupid) {
            return;
        }
        $data->usermodified = 0;
        $DB->insert_record('selfselectadvanced_override', $data);
    }

    /**
     * Restore a guide's volunteered capacity row.
     *
     * @param array $data the row
     */
    protected function process_ssavolunteer($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $newid = $DB->insert_record('selfselectadvanced_volunteer', $data);
        $this->set_mapping('selfselectadvanced_volunteer', $oldid, $newid);
    }

    /**
     * Restore a queued digest notification. Transient per-user data
     * (spec 14.11): the stored contexturl and JSON payload are carried
     * over as backed up, without deep-remapping any ids they embed,
     * exactly like the volunteer table's own restore.
     *
     * @param array $data the row
     */
    protected function process_ssadigestitem($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $data->groupid = $data->groupid ? ($this->get_mappingid('ssagroup', $data->groupid) ?: null) : null;
        $DB->insert_record('selfselectadvanced_digestq', $data);
    }

    /**
     * Restore a queue ticket. The group and the requester are both
     * NOT NULL: a ticket whose group or requester cannot be mapped is
     * dropped, like a member row with no mappable user. The claimant
     * and resolver are nullable and degrade to null individually.
     *
     * @param array $data the row
     */
    protected function process_ssaticket($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->groupid = $this->get_mappingid('ssagroup', $data->groupid);
        $data->requestedby = $this->get_mappingid('user', $data->requestedby);
        if (!$data->groupid || !$data->requestedby) {
            return;
        }
        $data->claimedby = $data->claimedby ? ($this->get_mappingid('user', $data->claimedby) ?: null) : null;
        $data->resolvedby = $data->resolvedby ? ($this->get_mappingid('user', $data->resolvedby) ?: null) : null;
        // A claimed ticket whose claimant did not survive the restore
        // would be stuck (nobody could resolve or release it): release
        // it back to the queue instead.
        if ($data->status === \mod_selfselectadvanced\local\tickets::STATUS_CLAIMED && $data->claimedby === null) {
            $data->status = \mod_selfselectadvanced\local\tickets::STATUS_OPEN;
            $data->timeclaimed = null;
        }
        $DB->insert_record('selfselectadvanced_ticket', $data);
    }

    /**
     * Restore a team's approach to a guide. Both the team and the two
     * people are NOT NULL, so an approach that cannot be mapped is
     * dropped rather than restored pointing at nothing.
     *
     * @param array $data the row
     */
    protected function process_ssacontact($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->groupid = $this->get_mappingid('ssagroup', $data->groupid);
        $data->guideid = $this->get_mappingid('user', $data->guideid);
        $data->sentby = $this->get_mappingid('user', $data->sentby);
        if (!$data->groupid || !$data->guideid || !$data->sentby) {
            return;
        }
        $DB->insert_record('selfselectadvanced_contact', $data);
    }

    /**
     * Bring the group-level file areas back after the id mappings exist.
     */
    protected function after_execute() {
        $this->add_related_files('mod_selfselectadvanced', 'proposal', 'ssagroup');
        $this->add_related_files('mod_selfselectadvanced', 'intro', null);
    }
}
