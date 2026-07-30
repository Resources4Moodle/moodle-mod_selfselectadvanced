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
 * A guide answers a team's approach (strategy 1.17 E).
 *
 * Deliberately a page of its own rather than a change to the existing
 * review page: the guide reads the team's proposal here and either
 * takes the team on or says no, with or without a reason. Accepting
 * pre-assigns them to the team, with their capacity checked under the
 * guide lock, so a guide who has filled up while the approach waited
 * is refused rather than quietly overloaded.
 *
 * With no approach named, the page lists everything waiting for this
 * guide to answer.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\contacts;
use mod_selfselectadvanced\local\groups;

$id = required_param('id', PARAM_INT);
$contactid = optional_param('c', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:guide', $context);

$baseurl = new moodle_url('/mod/selfselectadvanced/contactreview.php', ['id' => $cm->id]);
$PAGE->set_url($contactid ? new moodle_url($baseurl, ['c' => $contactid]) : $baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if (in_array($action, ['accept', 'decline'], true) && data_submitted() && confirm_sesskey()) {
    $contactid = required_param('c', PARAM_INT);
    $reason = optional_param('reason', '', PARAM_TEXT);
    try {
        contacts::respond(
            $activity,
            $contactid,
            $action === 'accept',
            $reason,
            FORMAT_PLAIN,
            (int) $USER->id
        );
        redirect(
            $baseurl,
            get_string(
                $action === 'accept' ? 'contactacceptednotice' : 'contactdeclinednotice',
                'mod_selfselectadvanced'
            ),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('contactreview', 'mod_selfselectadvanced'));

if ($contactid) {
    // One approach in full: who is asking, what they said, and their
    // proposal, so the guide can decide on something real.
    $contact = contacts::get($activity, $contactid);
    if ((int) $contact->guideid !== (int) $USER->id) {
        throw new moodle_exception('refusalcontactnotyours', 'mod_selfselectadvanced');
    }
    $group = groups::get($activity, (int) $contact->groupid);

    echo $OUTPUT->heading(format_string($group->name) . ' (' . $group->pluginuid . ')', 4);
    echo html_writer::tag('p', format_string($group->title));
    echo html_writer::div(format_text($group->brief, $group->briefformat, ['context' => $context]), 'mb-3');

    if (trim((string) $contact->message) !== '') {
        echo html_writer::div(
            html_writer::tag('strong', get_string('contactwhattheysaid', 'mod_selfselectadvanced'))
            . html_writer::div(s($contact->message)),
            'alert alert-light border mb-3'
        );
    }

    // The proposal itself, where one has been uploaded.
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_selfselectadvanced', 'proposal', $group->id, 'filename', false);
    if ($files) {
        echo $OUTPUT->heading(get_string('proposal', 'mod_selfselectadvanced'), 5);
        foreach ($files as $file) {
            $url = \moodle_url::make_pluginfile_url(
                $context->id,
                'mod_selfselectadvanced',
                'proposal',
                $group->id,
                $file->get_filepath(),
                $file->get_filename()
            );
            echo html_writer::div(html_writer::link($url, s($file->get_filename())));
        }
    } else {
        echo html_writer::div(get_string('proposalnone', 'mod_selfselectadvanced'), 'text-muted mb-3');
    }

    if ($contact->status !== contacts::STATUS_SENT) {
        echo html_writer::div(
            get_string('contactstatus' . $contact->status, 'mod_selfselectadvanced'),
            'alert alert-secondary'
        );
    } else {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'c', 'value' => $contact->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::label(
            get_string('contactreasonhint', 'mod_selfselectadvanced'),
            'ssa-contactreason',
            true,
            ['class' => 'd-block']
        );
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason', 'id' => 'ssa-contactreason',
            'size' => 50, 'class' => 'form-control mb-2']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-success me-2',
            'formaction' => (new moodle_url($baseurl, ['action' => 'accept']))->out(false),
            'value' => get_string('contactaccept', 'mod_selfselectadvanced')]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-warning',
            'formaction' => (new moodle_url($baseurl, ['action' => 'decline']))->out(false),
            'value' => get_string('contactdecline', 'mod_selfselectadvanced')]);
        echo html_writer::end_tag('form');
    }

    echo html_writer::link($baseurl, get_string('back'), ['class' => 'btn btn-secondary mt-3']);
    echo $OUTPUT->footer();
    die;
}

// Everything waiting for this guide.
$waiting = contacts::waiting_for($activity, (int) $USER->id);
if (!$waiting) {
    echo html_writer::div(get_string('contactnonewaiting', 'mod_selfselectadvanced'), 'text-muted');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable selfselectadvanced-contactqueue';
    $table->head = [
        get_string('groupname', 'mod_selfselectadvanced'),
        get_string('worktitle', 'mod_selfselectadvanced'),
        get_string('contactwhattheysaid', 'mod_selfselectadvanced'),
        get_string('actions'),
    ];
    foreach ($waiting as $contact) {
        $group = groups::get($activity, (int) $contact->groupid);
        $table->data[] = [
            format_string($group->name) . ' ' . html_writer::span($group->pluginuid, 'text-muted small'),
            format_string($group->title),
            s(shorten_text((string) $contact->message, 120)),
            html_writer::link(
                new moodle_url($baseurl, ['c' => $contact->id]),
                get_string('contactopen', 'mod_selfselectadvanced'),
                ['class' => 'btn btn-primary btn-sm']
            ),
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);
echo $OUTPUT->footer();
