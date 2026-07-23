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
 * Create a group (transition T1). Form display is GET; the mutation is
 * the moodleform POST, sesskey-protected by the form API.
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
require_capability('mod/selfselectadvanced:creategroup', $context);

$api = new \mod_selfselectadvanced\local\api($activity);

$PAGE->set_url('/mod/selfselectadvanced/groupedit.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$viewurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);

// Refusals surface before the form: quota exhausted or window closed.
if ($refusal = $api->gatekeeper()->can_create_group((int) $USER->id)) {
    redirect($viewurl, $refusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
}

$form = new \mod_selfselectadvanced\form\group_form(null, [
    'cmid' => $cm->id,
    'activity' => $activity,
]);

if ($form->is_cancelled()) {
    redirect($viewurl);
}

if ($data = $form->get_data()) {
    $group = $api->create_group(
        (int) $USER->id,
        $data->name,
        $data->title,
        $data->brief['text'],
        (int) $data->brief['format']
    );
    redirect(
        new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id]),
        get_string('groupcreated', 'mod_selfselectadvanced', $group->pluginuid),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('creategroup', 'mod_selfselectadvanced'));
$form->display();
echo $OUTPUT->footer();
