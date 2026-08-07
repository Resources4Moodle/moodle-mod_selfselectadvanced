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

$api = new \mod_selfselectadvanced\local\api($activity);

$PAGE->set_url('/mod/selfselectadvanced/groupedit.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$viewurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);

// Edit mode (audit item 21): leader revises title/brief while forming.
$gid = optional_param('g', 0, PARAM_INT);
// The capability gate used to sit ABOVE this branch, so :creategroup -
// a STUDENT capability - was demanded of everybody, including the
// manager the edit branch's own code goes on to admit; the manager path
// was unreachable (D6-4). Only the student CREATE path needs it now.
$isstaff = has_capability('mod/selfselectadvanced:manage', $context);
if (!$gid && !$isstaff) {
    require_capability('mod/selfselectadvanced:creategroup', $context);
}
$editgroup = null;
if ($gid) {
    $editgroup = \mod_selfselectadvanced\local\groups::get($activity, $gid);
    if ((int) $editgroup->leaderid !== (int) $USER->id && !$isstaff) {
        // Changed leadership between pages is a workflow fact, not a
        // software failure (MKT-05).
        redirect(
            new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $gid]),
            get_string('refusalnotleader', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    // AUTHORITY, RESTORED WITHOUT RE-BREAKING STAFF (AUTH-003). The
    // capability gate above answers the CREATE branch only, and moving
    // it there in D6-4 was deliberate: :creategroup is a STUDENT
    // capability an editing teacher does not hold, so demanding it of
    // everybody made the manager repair path unreachable. The side
    // effect nobody wrote down was that the EDIT branch then asked no
    // capability at all - and under decision 38 the raw leaderid it
    // asks instead is exactly what a PROHIBITED leader still owns.
    //
    // So the question is asked HERE, of the leader path only, leaving
    // the staff path exactly as D6-4 left it.
    // api::update_group_details() asks the same pair again at the
    // write, because a page gate is not a gate: a direct POST skips it.
    if (!$isstaff) {
        \mod_selfselectadvanced\local\authority::require_lead($activity, (int) $USER->id);
    }
    if ($editgroup->state !== \mod_selfselectadvanced\local\state::FORMING) {
        redirect(
            new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $gid]),
            get_string('refusalwrongstate', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
} else if (!$isstaff && ($refusal = $api->gatekeeper()->can_create_group((int) $USER->id))) {
    // Refusals surface before the form: quota exhausted or window
    // closed. Staff creation is a repair and does not meet the window
    // gate - which exists to stop STUDENTS forming teams late (D6-4).
    redirect($viewurl, $refusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
}

$staffmode = $isstaff && !$gid;
$form = new \mod_selfselectadvanced\form\group_form(null, [
    'cmid' => $cm->id,
    'activity' => $activity,
    'editgroup' => $editgroup,
    'staffmode' => $staffmode,
    'selectedleader' => [],
]);
if ($editgroup) {
    $form->set_data([
        'title' => $editgroup->title,
        'brief' => ['text' => $editgroup->brief, 'format' => (int) $editgroup->briefformat],
    ]);
}

if ($form->is_cancelled()) {
    redirect($viewurl);
}

if ($data = $form->get_data()) {
    if ($editgroup) {
        // The write moved into api::update_group_details(), which asks
        // the authority, takes the group lock, re-reads the row inside
        // it and fires an event (AUTH-003). It used to be an inline
        // update_record() here, reachable by a POST that never met the
        // checks above.
        try {
            $api->update_group_details(
                $editgroup,
                $data->title,
                $data->brief['text'],
                (int) $data->brief['format'],
                (int) $USER->id
            );
        } catch (moodle_exception $e) {
            redirect(
                new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $editgroup->id]),
                $e->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        redirect(
            new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $editgroup->id]),
            get_string('groupupdated', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    try {
        $group = $api->create_group(
            (int) $USER->id,
            $data->name,
            $data->title,
            $data->brief['text'],
            (int) $data->brief['format'],
            $staffmode ? (int) $data->leader : null,
            $staffmode
        );
    } catch (moodle_exception $e) {
        // The nominated leader's own caps refuse here, and the manager
        // must be able to pick somebody else without losing the form.
        $form->set_element_error($staffmode ? 'leader' : 'name', $e->getMessage());
        $group = null;
    }
    if ($group) {
        redirect(
            new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id]),
            get_string('groupcreated', 'mod_selfselectadvanced', $group->pluginuid),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($editgroup ? 'editgroup' : 'creategroup', 'mod_selfselectadvanced'));
$form->display();
echo $OUTPUT->footer();
