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
 * Staff reach-out (maintainer decision 18, 2026-08-01): a member of
 * staff writes to one participant and the note travels as a MOODLE
 * MESSAGE. The sender never sees an address, the recipient never sees
 * the sender's, and delivery follows the recipient's own notification
 * preferences.
 *
 * This is the replacement for the mailto: links 1.20 removed, built on
 * the pattern contact.php already uses in the other direction: no
 * plugin-owned SMTP, no email_to_user(), no reply-to header.
 *
 * GET renders the form; only a sesskey-checked POST sends (no
 * state-mutating GET).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\staffmessage;

$id = required_param('id', PARAM_INT);
$to = required_param('to', PARAM_INT);
$returnurlparam = optional_param('returnurl', '', PARAM_LOCALURL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();

$baseurl = new moodle_url('/mod/selfselectadvanced/message.php', ['id' => $cm->id, 'to' => $to]);
$returnurl = $returnurlparam !== ''
    ? new moodle_url($returnurlparam)
    : new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// The service is the authority; this refusal only saves the sender a
// wasted form. staffmessage::send() re-checks the same gate.
if (!staffmessage::may_message($activity, (int) $USER->id, $to)) {
    // The relationship changed between pages (MKT-05).
    redirect(
        new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]),
        get_string('refusalcannotmessage', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Name fields only. This page exists BECAUSE nobody here is shown an
// address, so it does not fetch one either: a column that is loaded is
// a column a later edit can print (the same rule eoilist.php's SELECT
// follows).
$recipient = \core_user::get_user(
    $to,
    implode(',', array_merge(['id'], \core_user\fields::for_name()->get_required_fields())),
    MUST_EXIST
);

$form = new \mod_selfselectadvanced\form\staffmessage_form($baseurl->out(false), [
    'cmid' => $cm->id,
    'to' => $to,
    'returnurl' => $returnurlparam,
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

$data = $form->get_data();
if ($data) {
    require_sesskey();
    try {
        staffmessage::send(
            $activity,
            (int) $USER->id,
            $to,
            (string) $data->subject,
            (string) $data->body
        );
        redirect(
            $returnurl,
            get_string('messagesendconfirm', 'mod_selfselectadvanced', fullname($recipient)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($returnurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('messagesendheading', 'mod_selfselectadvanced', fullname($recipient)));
echo html_writer::div(get_string('messagesendintro', 'mod_selfselectadvanced'), 'alert alert-info');
$form->display();
echo $OUTPUT->footer();
