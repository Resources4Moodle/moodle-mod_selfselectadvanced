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
 * Guide dashboard: the guide's own load figure (spec 4A.6) and their
 * queue of submitted groups plus their firm and frozen groups.
 * Read-only GET; filters and bulk freeze arrive in slice 11.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:guide', $context);

$api = new \mod_selfselectadvanced\local\api($activity);

$PAGE->set_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$mygroups = $DB->get_records_select(
    'selfselectadvanced_group',
    'activityid = :activityid AND guideid = :guideid',
    ['activityid' => $activity->id(), 'guideid' => $USER->id],
    'timesubmitted ASC'
);

$resolver = $api->gatekeeper()->resolver();
$load = (object) [
    'used' => \mod_selfselectadvanced\local\groups::count_guiding($activity, (int) $USER->id),
    'max' => $resolver->effective_maxguided((int) $USER->id)->value,
];

$queue = [];
$guided = [];
foreach ($mygroups as $group) {
    $row = (object) [
        'pluginuid' => $group->pluginuid,
        'name' => format_string($group->name),
        'title' => format_string($group->title),
        'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced'),
        'size' => \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id),
        'reviewurl' => (new moodle_url('/mod/selfselectadvanced/review.php', [
            'id' => $cm->id,
            'g' => $group->id,
        ]))->out(false),
    ];
    if ($group->state === \mod_selfselectadvanced\local\state::PENDING_GUIDE) {
        $queue[] = $row;
    } else {
        $row->canfreeze = $group->state === \mod_selfselectadvanced\local\state::FIRM
            && has_capability('mod/selfselectadvanced:freeze', $context);
        $row->freezeurl = (new moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $cm->id,
            'g' => $group->id,
            'action' => 'freeze',
        ]))->out(false);
        $guided[] = $row;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/guide_dashboard', (object) [
    'loadline' => get_string('guideloadheader', 'mod_selfselectadvanced', $load),
    'queue' => $queue,
    'hasqueue' => !empty($queue),
    'guided' => $guided,
    'hasguided' => !empty($guided),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
