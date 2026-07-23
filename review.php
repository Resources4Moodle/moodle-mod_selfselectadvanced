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
 * Guide review page for one submitted group: approve (irreversible,
 * confirm page) or return with a mandatory comment (spec 6.5).
 *
 * GET renders; approve and return are sesskey-protected POSTs.
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
require_capability('mod/selfselectadvanced:guide', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$group = \mod_selfselectadvanced\local\groups::get($activity, $groupid);

$baseurl = new moodle_url('/mod/selfselectadvanced/review.php', ['id' => $cm->id, 'g' => $group->id]);
$queueurl = new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'approve') {
    if ($refusal = $api->gatekeeper()->can_approve($group, (int) $USER->id)) {
        redirect($baseurl, $refusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (data_submitted() && confirm_sesskey()) {
        $api->lifecycle()->approve($group, (int) $USER->id);
        redirect(
            $queueurl,
            get_string('groupapprovednotice', 'mod_selfselectadvanced', $group->pluginuid),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // GET: irreversibility warning; the approval itself is the POST above.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('approveconfirm', 'mod_selfselectadvanced', format_string($group->name)),
        new single_button(
            new moodle_url($baseurl, ['action' => 'approve']),
            get_string('approve', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

if ($action === 'returngroup' && data_submitted() && confirm_sesskey()) {
    $comment = required_param('comment', PARAM_TEXT);
    $api->lifecycle()->return_group($group, $comment, (int) $USER->id);
    redirect(
        $queueurl,
        get_string('groupreturnednotice', 'mod_selfselectadvanced', $group->pluginuid),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$page = new \mod_selfselectadvanced\output\review_page($api, $group, (int) $USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/review_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
