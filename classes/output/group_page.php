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
 * The group page: identity, roster, seat position, invitations and
 * leader actions (UI inventory, spec section 14.13; displays, 4A.6).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param api $api the application facade
     * @param \stdClass $group the group row
     * @param int $userid the viewing user
     * @param \mod_selfselectadvanced\form\invite_form|null $inviteform leader's invite form, when applicable
     */
    public function __construct(
        /** @var api The application facade. */
        private readonly api $api,
        /** @var \stdClass The group row. */
        private readonly \stdClass $group,
        /** @var int The viewing user. */
        private readonly int $userid,
        /** @var \mod_selfselectadvanced\form\invite_form|null Leader's invite form. */
        private readonly ?\mod_selfselectadvanced\form\invite_form $inviteform = null,
    ) {
    }

    /**
     * Export for the group page template.
     *
     * @param renderer_base $output the renderer
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        global $DB;

        $activity = $this->api->activity();
        $context = $activity->context();
        $cmid = $activity->cm()->id;
        $seats = $this->api->gatekeeper()->seat_position($this->group);
        $isleader = (int) $this->group->leaderid === $this->userid;
        $isforming = $this->group->state === state::FORMING;

        $roster = [];
        foreach (groups::get_roster((int) $this->group->id) as $member) {
            $roster[] = (object) [
                'fullname' => fullname($member),
                'isleader' => (bool) $member->isleader,
            ];
        }

        // Pending invitations, visible to the leader and staff.
        $pendinginvites = [];
        if ($isleader || has_capability('mod/selfselectadvanced:viewall', $context, $this->userid)) {
            $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
            $sql = "SELECT m.id AS memberid, m.userid, m.timeinvited, $namefields
                      FROM {selfselectadvanced_member} m
                      JOIN {user} u ON u.id = m.userid
                     WHERE m.groupid = :groupid AND m.status = :status
                  ORDER BY m.timeinvited ASC";
            foreach (
                $DB->get_records_sql($sql, [
                    'groupid' => $this->group->id,
                    'status' => groups::STATUS_INVITED,
                ]) as $invite
            ) {
                $pendinginvites[] = (object) [
                    'memberid' => (int) $invite->memberid,
                    'fullname' => fullname($invite),
                    'invitedon' => $invite->timeinvited ? userdate($invite->timeinvited) : '',
                ];
            }
        }

        // The viewer's own pending invitation, if any.
        $ownrow = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $this->group->id,
            'userid' => $this->userid,
        ]);
        $caninvite = $isleader && $isforming && $seats->free > 0;

        return (object) [
            'pluginuid' => $this->group->pluginuid,
            'name' => format_string($this->group->name),
            'title' => format_string($this->group->title),
            'brief' => format_text($this->group->brief, $this->group->briefformat, ['context' => $context]),
            'statelabel' => get_string('state' . str_replace('_', '', $this->group->state), 'mod_selfselectadvanced'),
            'seatsummary' => get_string('seatsummary', 'mod_selfselectadvanced', $seats),
            'minsizenote' => get_string('minsizenote', 'mod_selfselectadvanced', $seats),
            'roster' => $roster,
            'hasroster' => !empty($roster),
            'isleader' => $isleader,
            'candelete' => $isleader && $isforming,
            'caninvite' => $caninvite,
            'inviteformhtml' => $caninvite && $this->inviteform ? $this->inviteform->render() : '',
            'invitedisabledreason' => $isleader && $isforming && $seats->free < 1
                ? get_string('refusalnoseats', 'mod_selfselectadvanced')
                : '',
            'pendinginvites' => $pendinginvites,
            'haspendinginvites' => !empty($pendinginvites),
            'showrespond' => $ownrow && $ownrow->status === groups::STATUS_INVITED,
            'sesskey' => sesskey(),
            'actionurl' => (new \moodle_url('/mod/selfselectadvanced/group.php'))->out(false),
            'cmid' => $cmid,
            'groupid' => (int) $this->group->id,
            'deleteurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'delete',
            ]))->out(false),
            'backurl' => (new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cmid]))->out(false),
        ];
    }
}
