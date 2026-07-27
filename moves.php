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
 * Staged moves (spec 7): the pending list with per-rule validation
 * chips, joint commit of a selected set, and cancel. Staging happens
 * on moveedit.php.
 *
 * GET renders; commit and cancel are sesskey-protected POSTs.
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
$baseurl = new moodle_url('/mod/selfselectadvanced/moves.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'cancel' && data_submitted() && confirm_sesskey()) {
    $moveid = required_param('move', PARAM_INT);
    $api->moves()->cancel($moveid, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('movecancelled', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'commit' && data_submitted() && confirm_sesskey()) {
    $selected = optional_param_array('selected', [], PARAM_INT);
    if (!$selected) {
        redirect(
            $baseurl,
            get_string('movenoneselected', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    try {
        $count = $api->moves()->commit_set($selected, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('movescommitted', 'mod_selfselectadvanced', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$pending = $DB->get_records('selfselectadvanced_move', [
    'activityid' => $activity->id(),
    'status' => 'pending',
], 'timecreated ASC');

$verdicts = $pending ? $api->moves()->validate_set(array_keys($pending)) : null;
$rows = [];
foreach ($pending as $move) {
    $userlabel = fullname(core_user::get_user((int) $move->userid));
    $sourcelabel = get_string('movenosource', 'mod_selfselectadvanced');
    if ($move->sourcegroupid) {
        $source = \mod_selfselectadvanced\local\groups::get($activity, (int) $move->sourcegroupid);
        $sourcelabel = format_string($source->name);
    }
    $target = \mod_selfselectadvanced\local\groups::get($activity, (int) $move->targetgroupid);
    $chips = [];
    $allok = true;
    foreach ($verdicts->permove[(int) $move->id] ?? [] as $rule => $verdict) {
        $chips[] = (object) [
            'rule' => $rule,
            'ok' => $verdict['ok'],
            'bypassed' => $verdict['bypassed'],
            'reason' => $verdict['reason'],
        ];
        if (!$verdict['ok'] && !$verdict['bypassed']) {
            $allok = false;
        }
    }
    $restageurl = new moodle_url('/mod/selfselectadvanced/moveedit.php', [
        'id' => $cm->id,
        'student' => (int) $move->userid,
        'source' => (int) $move->sourcegroupid,
        'target' => (int) $move->targetgroupid,
        'makeleader' => (int) $move->makeleader,
        'replaceleader' => (int) $move->replaceleader,
        'successor' => (int) $move->successorid,
        'replaces' => (int) $move->id,
    ]);
    $rows[] = (object) [
        'moveid' => (int) $move->id,
        'user' => $userlabel,
        'source' => $sourcelabel,
        'target' => format_string($target->name),
        'makeleader' => (bool) $move->makeleader,
        'chips' => $chips,
        'allok' => $allok,
        'restageurl' => $restageurl->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/moves_list', (object) [
    'rows' => $rows,
    'hasrows' => !empty($rows),
    'stageurl' => (new moodle_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cm->id]))->out(false),
    'actionurl' => $baseurl->out(false),
    'cmid' => $cm->id,
    'sesskey' => sesskey(),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
