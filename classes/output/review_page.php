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
 * The guide review page: group identity, brief, roster and the
 * approve/return controls (spec 6.5). The quota bucket panel joins in
 * slice 6.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param api $api the application facade
     * @param \stdClass $group the group row
     * @param int $userid the viewing guide
     */
    public function __construct(
        /** @var api The application facade. */
        private readonly api $api,
        /** @var \stdClass The group row. */
        private readonly \stdClass $group,
        /** @var int The viewing guide. */
        private readonly int $userid,
    ) {
    }

    /**
     * Export for the review page template.
     *
     * @param renderer_base $output the renderer
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $activity = $this->api->activity();
        $context = $activity->context();
        $cmid = $activity->cm()->id;
        $seats = $this->api->gatekeeper()->seat_position($this->group);

        // Guides read participant attributes on the roster (spec 8.1).
        $rostermembers = groups::get_roster((int) $this->group->id);
        $attrs = \mod_selfselectadvanced\local\attributes\manager::get_for_users(
            array_map(static fn($m) => (int) $m->userid, $rostermembers)
        );
        $canseemobile = has_capability('mod/selfselectadvanced:viewall', $context, $this->userid);
        $roster = [];
        foreach ($rostermembers as $member) {
            $roster[] = (object) [
                'fullname' => fullname($member),
                'isleader' => (bool) $member->isleader,
                'attrline' => \mod_selfselectadvanced\local\attributes\manager::display_line(
                    $attrs[(int) $member->userid] ?? null,
                    $canseemobile
                ),
            ];
        }

        $isassigned = (int) ($this->group->guideid ?? 0) === $this->userid
            && $this->group->state === state::PENDING_GUIDE;
        $approverefusal = $isassigned
            ? $this->api->gatekeeper()->can_approve($this->group, $this->userid)
            : null;

        return (object) [
            'pluginuid' => $this->group->pluginuid,
            'name' => format_string($this->group->name),
            'title' => format_string($this->group->title),
            'brief' => format_text($this->group->brief, $this->group->briefformat, ['context' => $context]),
            'statelabel' => get_string('state' . str_replace('_', '', $this->group->state), 'mod_selfselectadvanced'),
            'seatsummary' => get_string('seatsummary', 'mod_selfselectadvanced', $seats),
            'roster' => $roster,
            'hasroster' => !empty($roster),
            'isassigned' => $isassigned,
            'canapprove' => $isassigned && $approverefusal === null,
            'approveblockedreason' => $approverefusal?->get_message(),
            'approveurl' => (new \moodle_url('/mod/selfselectadvanced/review.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'approve',
            ]))->out(false),
            'actionurl' => (new \moodle_url('/mod/selfselectadvanced/review.php'))->out(false),
            'sesskey' => sesskey(),
            'cmid' => $cmid,
            'groupid' => (int) $this->group->id,
            'backurl' => (new \moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cmid]))->out(false),
        ];
    }
}
