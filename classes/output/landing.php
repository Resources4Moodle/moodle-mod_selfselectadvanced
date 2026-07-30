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

namespace mod_selfselectadvanced\output;

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use renderable;
use renderer_base;
use templatable;

/**
 * The capability-routed landing page (UI inventory, spec section 14.13).
 *
 * Student area: limit counters (section 4A.6), create-group control with
 * its refusal reason when disabled, my groups, my pending invitations.
 * Staff area (viewall): read-only list of groups with state and size,
 * capped at ALLGROUPS_LIMIT with a link through to manage.php's own
 * sortable, filterable, paginated table for the rest (audit round 8
 * item 5: an uncapped list ran to thousands of rows on a large course).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class landing implements renderable, templatable {
    /**
     * Maximum groups shown in the staff "All groups" panel before
     * linking through to manage.php's own sortable, filterable,
     * paginated table instead.
     */
    private const ALLGROUPS_LIMIT = 20;

    /**
     * Constructor.
     *
     * @param api $api the application facade
     * @param int $userid the viewing user
     */
    public function __construct(
        /** @var api The application facade. */
        private readonly api $api,
        /** @var int The viewing user. */
        private readonly int $userid,
    ) {
    }

    /**
     * Export for the landing template.
     *
     * @param renderer_base $output the renderer
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        global $DB;

        $activity = $this->api->activity();
        $context = $activity->context();
        $gatekeeper = $this->api->gatekeeper();
        $cmid = $activity->cm()->id;

        $data = (object) [
            'isstudent' => false,
            'isstaff' => false,
            'sesskey' => sesskey(),
            'cmid' => $cmid,
            'actionurl' => (new \moodle_url('/mod/selfselectadvanced/group.php'))->out(false),
        ];

        // Student-approach mode: expectations set plainly for every
        // viewer - guides advertise nothing here, students approach.
        $data->studentapproachnotice = !empty($activity->settings()->studentapproach)
            ? get_string('studentapproachnotice', 'mod_selfselectadvanced')
            : '';

        // Mobile-sharing consent widget: shown to any viewer who holds a
        // userattr record with a non-empty mobile, regardless of role.
        // No record, or a record with no mobile, renders nothing.
        $data->showconsent = false;
        $attr = \mod_selfselectadvanced\local\attributes\manager::get($this->userid);
        if ($attr !== null && !empty($attr->mobile)) {
            $data->showconsent = true;
            $data->consentgranted = !empty($attr->shareconsent);
            $data->consentstatus = $data->consentgranted
                ? get_string('shareconsentgranted', 'mod_selfselectadvanced')
                : get_string('shareconsentwithheld', 'mod_selfselectadvanced');
            $data->consentbuttonlabel = $data->consentgranted
                ? get_string('consentrevoke', 'mod_selfselectadvanced')
                : get_string('consentgrant', 'mod_selfselectadvanced');
            $data->consentaction = $data->consentgranted ? 'revoke' : 'grant';
            $data->consentactionurl = (new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cmid]))->out(false);
        }

        if (
            has_capability('mod/selfselectadvanced:creategroup', $context, $this->userid, false)
                || has_capability('mod/selfselectadvanced:respond', $context, $this->userid, false)
        ) {
            $data->isstudent = true;

            $position = $gatekeeper->limit_position($this->userid);
            $data->leadcounter = get_string('counterlead', 'mod_selfselectadvanced', $position->lead);
            $data->membercounter = get_string('countermember', 'mod_selfselectadvanced', $position->membership);

            $cancreate = has_capability('mod/selfselectadvanced:creategroup', $context, $this->userid, false);
            $refusal = $cancreate ? $gatekeeper->can_create_group($this->userid) : null;
            $data->showcreate = $cancreate;
            $data->cancreate = $cancreate && $refusal === null;
            $data->createreason = $refusal?->get_message();
            $data->createurl = (new \moodle_url('/mod/selfselectadvanced/groupedit.php', ['id' => $cmid]))->out(false);

            $data->mygroups = [];
            foreach (groups::get_groups_of_user($activity, $this->userid) as $group) {
                $data->mygroups[] = $this->export_group_row($group, $cmid);
            }
            $data->hasmygroups = !empty($data->mygroups);

            // Pending invitations to this user (respond actions arrive in slice 2).
            $sql = "SELECT g.*
                      FROM {selfselectadvanced_member} m
                      JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                     WHERE g.activityid = :activityid
                       AND m.userid = :userid
                       AND m.status = :status
                  ORDER BY m.timecreated ASC";
            $data->myinvitations = [];
            foreach (
                $DB->get_records_sql($sql, [
                    'activityid' => $activity->id(),
                    'userid' => $this->userid,
                    'status' => groups::STATUS_INVITED,
                ]) as $group
            ) {
                $data->myinvitations[] = $this->export_group_row($group, $cmid);
            }
            $data->hasmyinvitations = !empty($data->myinvitations);

            // Pending succession nominations for this user (spec 6.4, A3).
            $data->mynominations = [];
            $nominated = $DB->get_records('selfselectadvanced_group', [
                'activityid' => $activity->id(),
                'successorid' => $this->userid,
            ], 'timenominated ASC');
            foreach ($nominated as $group) {
                $row = $this->export_group_row($group, $cmid);
                $row->isstepout = $group->successortype === 'stepout';
                $data->mynominations[] = $row;
            }
            $data->hasmynominations = !empty($data->mynominations);
        }

        $data->isguide = has_capability('mod/selfselectadvanced:guide', $context, $this->userid, false);
        $data->guideurl = (new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cmid]))->out(false);
        $data->ismanager = has_capability('mod/selfselectadvanced:manage', $context, $this->userid, false);
        $data->manageurl = (new \moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cmid]))->out(false);

        // Group Coordinators reach the queue from here. The manager
        // dashboard carries the same link, but that page needs the
        // manage capability, which a coordinator does not have - without
        // this button the queue, which is their whole job, has no way in.
        $data->iscoordinator = !$data->ismanager
            && has_capability('mod/selfselectadvanced:coordinate', $context, $this->userid, false);
        $data->ticketsurl = (new \moodle_url('/mod/selfselectadvanced/coordinator.php', ['id' => $cmid]))->out(false);

        if (has_capability('mod/selfselectadvanced:viewall', $context, $this->userid, false)) {
            $data->isstaff = true;
            $totalgroups = $DB->count_records('selfselectadvanced_group', ['activityid' => $activity->id()]);
            $groups = $DB->get_records(
                'selfselectadvanced_group',
                ['activityid' => $activity->id()],
                'timecreated ASC',
                '*',
                0,
                self::ALLGROUPS_LIMIT
            );
            $data->allgroups = [];
            foreach ($groups as $group) {
                $data->allgroups[] = $this->export_group_row($group, $cmid);
            }
            $data->hasallgroups = !empty($data->allgroups);
            $data->allgroupstruncated = $totalgroups > self::ALLGROUPS_LIMIT;
            $data->allgroupsshowingtext = $data->allgroupstruncated
                ? get_string('allgroupsshowing', 'mod_selfselectadvanced', (object) [
                    'shown' => count($data->allgroups),
                    'total' => $totalgroups,
                ])
                : '';
            // The full listing lives on the manage page, which needs the
            // manage capability. A viewall holder without it is told the
            // panel is truncated but is not sent to a page they cannot
            // open.
            $data->canseeallgroups = $data->ismanager;
            $data->manageallurl = $data->ismanager
                ? (new \moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cmid]))->out(false)
                : '';
        }

        return $data;
    }

    /**
     * Export one group row for the template.
     *
     * @param \stdClass $group group row
     * @param int $cmid course module id
     * @return \stdClass
     */
    private function export_group_row(\stdClass $group, int $cmid): \stdClass {
        $seats = $this->api->gatekeeper()->seat_position($group);

        return (object) [
            'id' => (int) $group->id,
            'pluginuid' => $group->pluginuid,
            'name' => format_string($group->name),
            'title' => format_string($group->title),
            'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced'),
            'state' => $group->state,
            'isforming' => $group->state === state::FORMING,
            'seatsummary' => get_string('seatsummary', 'mod_selfselectadvanced', $seats),
            'url' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $group->id,
            ]))->out(false),
        ];
    }
}
