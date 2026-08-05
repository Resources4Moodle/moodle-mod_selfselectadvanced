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
use mod_selfselectadvanced\local\authority;
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

        // Hoisted above the student-approach notice, which is now
        // gated on it (1.20.6 item A). This viewer's guide capability
        // decides two things on this page and they have to be decided
        // in this order: whether the guide panel is drawn at the top,
        // and therefore whether the student-addressed notice is
        // replaced by the guide-addressed decision rule inside it.
        $isguide = has_capability('mod/selfselectadvanced:guide', $context, $this->userid, false);

        $data = (object) [
            'isstudent' => false,
            'isstaff' => false,
            'sesskey' => sesskey(),
            'cmid' => $cmid,
            'actionurl' => (new \moodle_url('/mod/selfselectadvanced/group.php'))->out(false),
        ];

        // Student-approach mode: expectations set plainly - guides
        // advertise nothing here, students approach.
        //
        // The string is student-addressed prose ("Choose a guide, agree
        // with them directly, and submit your group; the guide
        // decides"), and until 1.20.6 it was the FIRST thing a guide
        // read on their own landing page - the maintainer's finding.
        // The predicate is !$isguide and deliberately not isstudent:
        // "is this viewer being given the guide-addressed statement
        // INSTEAD?" Everybody who loses the notice here gains the guide
        // panel's window policy in the same screen position, so nothing
        // is subtracted without a replacement. An editing teacher and a
        // manager hold no :guide, are not being given anything in its
        // place, and therefore keep the notice exactly as before. A
        // coordinator holds :guide, so they are treated as guide-side
        // (maintainer decision on item A) and keep their coordinator
        // button untouched.
        $data->studentapproachnotice = !empty($activity->settings()->studentapproach) && !$isguide
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
            // The line the number's OWNER reads about their own data,
            // so it states what this activity actually does rather than
            // what the plugin used to do. Until 1.20.1 it promised that
            // "staff with full view can still see it" while T-07 was
            // making that false: nobody below a site administrator
            // bypasses consent any more. The shared audience differs
            // between the two modes of the contact-privacy switch, so
            // the granted line follows the switch; the withheld line
            // does not, because a withheld number reaches only a
            // holder of :viewparticipantidentity in either mode.
            $protects = \mod_selfselectadvanced\local\contactprivacy::enabled($activity);
            $data->consentstatus = $data->consentgranted
                ? get_string(
                    $protects ? 'shareconsentgranted' : 'shareconsentgrantedopen',
                    'mod_selfselectadvanced'
                )
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
            // The ACCEPT and DECLINE controls, gated on the capability
            // that now decides both at the service seam (1.20.1 A-03).
            // The invitations themselves are still LISTED when it is
            // prohibited - the student needs to know the team is
            // waiting on them, and their leader can still withdraw it -
            // but a button whose only possible outcome is a Moodle
            // no-permission page is not an offer. The predicate is
            // authority::may_respond(), CALLED and not transcribed, so
            // this control and invitations::accept()/decline() cannot
            // drift into disagreeing.
            $data->mayrespond = authority::may_respond($activity, $this->userid);

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

        // The guide's own work, at the TOP of the page (1.20.6 item A).
        // The panel carries the one and only "Guide dashboard" anchor
        // on this page - the old standalone link below the student
        // panels was removed in the same change, because a duplicate
        // would make Behat's "I follow" resolve to whichever came first
        // and silently stop exercising the other.
        $data->isguide = $isguide;
        $data->showguidepanel = $isguide;
        $data->guidepanel = $isguide
            ? (new guide_panel($this->api, $this->userid))->export_for_template($output)
            : null;

        // Asking to join another team, and answering those asks
        // (strategy 1.19 B). Two sides, one page - but not one
        // audience: the 1.20.5 review (NAV-02) found this button drawn
        // for EVERY viewer while the page itself admits only holders of
        // :respond, :manage or :coordinate, so every ordinary
        // non-editing teacher on the live site was offered a button
        // that could only ever end at a permission exception. The
        // predicate is authority::may_join_requests(), CALLED and not
        // transcribed, and joinrequest.php's own door is the require_
        // half of that same function.
        $data->showjoinlink = authority::may_join_requests($activity, $this->userid);
        $data->joinurl = (new \moodle_url('/mod/selfselectadvanced/joinrequest.php', ['id' => $cmid]))->out(false);
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
