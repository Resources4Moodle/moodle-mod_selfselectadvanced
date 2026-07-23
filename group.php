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
 * The group page. GET renders (including the delete confirmation page);
 * every state change arrives as a sesskey-protected POST.
 *
 * Access: confirmed or invited members of the group, and viewall
 * holders. Ownership of every id is verified server-side (IDOR rule,
 * spec section 14.12).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$groupid = required_param('g', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
$api = new \mod_selfselectadvanced\local\api($activity);

// Ownership check: the group must belong to this activity.
$group = \mod_selfselectadvanced\local\groups::get($activity, $groupid);

// Access: group members (any live membership row) or viewall holders.
$membership = $DB->get_record('selfselectadvanced_member', [
    'groupid' => $group->id,
    'userid' => $USER->id,
]);
$ismember = $membership && in_array($membership->status, [
    \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
    \mod_selfselectadvanced\local\groups::STATUS_INVITED,
], true);
if (!$ismember) {
    require_capability('mod/selfselectadvanced:viewall', $context);
}

$baseurl = new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id]);
$viewurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'delete') {
    // Leader-only, forming-only; the gatekeeper repeats this server-side on POST.
    if ($refusal = $api->gatekeeper()->can_delete_group($group, (int) $USER->id)) {
        redirect($baseurl, $refusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (data_submitted() && confirm_sesskey()) {
        $api->delete_group($group, (int) $USER->id);
        redirect(
            $viewurl,
            get_string('groupdeleted', 'mod_selfselectadvanced', $group->pluginuid),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // GET: render the confirmation page only; the destructive step is the POST above.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('deletegroupconfirm', 'mod_selfselectadvanced', format_string($group->name)),
        new single_button(
            new moodle_url($baseurl, ['action' => 'delete']),
            get_string('deletegroup', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

$page = new \mod_selfselectadvanced\output\group_page($api, $group, (int) $USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/group_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
