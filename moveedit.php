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
 * Stage a move (spec 7). The form posts back here; the staged move
 * appears on moves.php for joint validation and commit.
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
require_capability('mod/selfselectadvanced:manage', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$listurl = new moodle_url('/mod/selfselectadvanced/moves.php', ['id' => $cm->id]);

$PAGE->set_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$students = [];
foreach (get_enrolled_users($context, 'mod/selfselectadvanced:respond', 0, 'u.*', 'lastname, firstname') as $user) {
    $students[(int) $user->id] = fullname($user);
}
$groupoptions = [];
foreach ($DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()], 'name ASC') as $group) {
    $groupoptions[(int) $group->id] = format_string($group->name) . ' (' . $group->pluginuid . ', '
        . get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced') . ')';
}
$canbypass = has_capability('mod/selfselectadvanced:override', $context);

$form = new \mod_selfselectadvanced\form\move_form(null, [
    'cmid' => $cm->id,
    'students' => $students,
    'groups' => $groupoptions,
    'canbypass' => $canbypass,
]);

if ($form->is_cancelled()) {
    redirect($listurl);
}
if ($data = $form->get_data()) {
    $move = $api->moves()->stage(
        (int) $data->student,
        empty($data->source) ? null : (int) $data->source,
        (int) $data->target,
        !empty($data->makeleader),
        empty($data->successor) ? null : (int) $data->successor,
        (int) $USER->id
    );
    if ($canbypass && !empty($data->bypass)) {
        \mod_selfselectadvanced\local\override\store::save(
            $activity,
            'move',
            (int) $move->id,
            ['rulesbypassed' => implode(',', array_map('clean_param_alphaext', (array) $data->bypass))],
            (int) $USER->id
        );
    }
    redirect(
        $listurl,
        get_string('movestaged', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('movestage', 'mod_selfselectadvanced'));
$form->display();
echo $OUTPUT->footer();

/**
 * Clean one bypass rule code.
 *
 * @param string $code raw code
 * @return string cleaned code
 */
function clean_param_alphaext(string $code): string {
    return clean_param($code, PARAM_ALPHANUM);
}
