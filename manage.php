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

if ($action === 'runautogroup' && data_submitted() && confirm_sesskey()) {
    if ((int) $activity->settings()->autogroup < 1) {
        redirect(
            $baseurl,
            get_string('autogroupdisabled', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $agrun = \mod_selfselectadvanced\local\autogroup\engine::run($activity, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('autogroupran', 'mod_selfselectadvanced', $agrun),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$statefilter = optional_param('statefilter', '', PARAM_ALPHAEXT);
if (!in_array($statefilter, \mod_selfselectadvanced\local\state::all(), true)) {
    $statefilter = '';
}
$download = optional_param('download', '', PARAM_ALPHA);
$tableurl = new moodle_url($baseurl, $statefilter !== '' ? ['statefilter' => $statefilter] : []);
$groupstable = new \mod_selfselectadvanced\table\groups_table(
    'ssagroups',
    $activity,
    $api->gatekeeper(),
    $tableurl,
    $statefilter,
    $download !== ''
);
if ($download !== '') {
    $groupstable->is_downloading($download, 'groups');
    $groupstable->out(50, false);
    die;
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
echo $OUTPUT->heading(get_string('managerdashboard', 'mod_selfselectadvanced'));

// Tool links.
$links = [
    ['quotas.php', 'quotarules'],
    ['moves.php', 'pendingmoves'],
    ['overrides.php', 'overrides'],
    ['ledger.php', 'penaltyledger'],
    ['flagged.php', 'flaggedreport'],
    ['templates.php', 'notificationtemplates'],
    ['guidelist.php', 'guidelist'],
    ['roster.php', 'roster'],
];
$linkhtml = '';
foreach ($links as [$file, $stringkey]) {
    $linkhtml .= html_writer::link(
        new moodle_url('/mod/selfselectadvanced/' . $file, ['id' => $cm->id]),
        get_string($stringkey, 'mod_selfselectadvanced'),
        ['class' => 'btn btn-outline-primary me-2 mb-2']
    );
}
echo html_writer::div($linkhtml, 'selfselectadvanced-toollinks mb-3');

// Manual auto-grouping trigger with the latest run summary (spec 9.1).
$lastrun = $DB->get_records('selfselectadvanced_agrun', ['activityid' => $activity->id()], 'id DESC', '*', 0, 1);
$runsummary = $lastrun
    ? get_string('autogrouplastrun', 'mod_selfselectadvanced', reset($lastrun))
    : get_string('autogroupnorun', 'mod_selfselectadvanced');
echo html_writer::start_div('selfselectadvanced-autogroup mb-3');
echo html_writer::span($runsummary, 'me-3');
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'd-inline']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'runautogroup']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('autogrouprun', 'mod_selfselectadvanced'),
    'class' => 'btn btn-outline-primary btn-sm',
]);
echo html_writer::end_tag('form');
echo ' ' . html_writer::link(
    new moodle_url('/mod/selfselectadvanced/autogrouphistory.php', ['id' => $cm->id]),
    get_string('agrunhistory', 'mod_selfselectadvanced'),
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::end_div();

// State filter (GET, read-only view change).
$options = ['' => get_string('all')];
foreach (\mod_selfselectadvanced\local\state::all() as $stateoption) {
    $options[$stateoption] = get_string('state' . str_replace('_', '', $stateoption), 'mod_selfselectadvanced');
}
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(), 'class' => 'mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::label(get_string('state', 'mod_selfselectadvanced'), 'ssa-statefilter', true, ['class' => 'me-2']);
echo html_writer::select($options, 'statefilter', $statefilter, false, ['id' => 'ssa-statefilter', 'class' => 'me-2']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter'),
    'class' => 'btn btn-secondary btn-sm',
]);
echo html_writer::end_tag('form');

$groupstable->out(50, true);

echo $OUTPUT->render_from_template('mod_selfselectadvanced/manage_queue', (object) [
    'queue' => $queue,
    'hasqueue' => !empty($queue),
    'guideoptions' => $guideoptions,
    'hasguideoptions' => !empty($guideoptions),
    'guideloads' => [],
    'hasguideloads' => false,
    'sesskey' => sesskey(),
    'cmid' => $cm->id,
    'actionurl' => $baseurl->out(false),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
