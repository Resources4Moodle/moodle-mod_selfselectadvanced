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
 * Manager dashboard. This slice ships the A5 guide-assignment queue
 * (groups submitted without a guide, or needing reassignment); the
 * full filterable dashboard with staged moves, overrides, ledger and
 * auto-grouping controls accumulates in later slices.
 *
 * GET renders; assignment is a sesskey-protected POST.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:manage', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$baseurl = new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'assignguide' && data_submitted() && confirm_sesskey()) {
    $groupid = required_param('g', PARAM_INT);
    $guideid = required_param('guide', PARAM_INT);
    $group = \mod_selfselectadvanced\local\groups::get($activity, $groupid);
    $api->lifecycle()->assign_guide($group, $guideid, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('guideassigned', 'mod_selfselectadvanced', $group->pluginuid),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$guides = \mod_selfselectadvanced\local\guides::with_load($activity, $api->gatekeeper()->resolver());
$guideoptions = [];
foreach ($guides as $guide) {
    if ($guide->remaining > 0) {
        $guideoptions[] = (object) ['id' => $guide->id, 'label' => $guide->fullname . ' — ' . $guide->label];
    }
}

$unassigned = $DB->get_records_select(
    'selfselectadvanced_group',
    'activityid = :activityid AND state = :state AND guideid IS NULL',
    ['activityid' => $activity->id(), 'state' => \mod_selfselectadvanced\local\state::PENDING_GUIDE],
    'timesubmitted ASC'
);
$queue = [];
foreach ($unassigned as $group) {
    $queue[] = (object) [
        'groupid' => (int) $group->id,
        'pluginuid' => $group->pluginuid,
        'name' => format_string($group->name),
        'title' => format_string($group->title),
        'size' => \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/manage_queue', (object) [
    'queue' => $queue,
    'hasqueue' => !empty($queue),
    'guideoptions' => $guideoptions,
    'hasguideoptions' => !empty($guideoptions),
    'guideloads' => array_values($guides),
    'hasguideloads' => !empty($guides),
    'sesskey' => sesskey(),
    'cmid' => $cm->id,
    'actionurl' => $baseurl->out(false),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
