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

// Disclosure is decided here, not at the bottom of the page. The gate
// above is :guide on the ACTIVITY and the team arrives in the URL, so
// until 1.20.1 any :guide holder - which is every non-editing teacher
// on a stock site, and the Group Coordinator role - could read any
// team's roster, its members' composition attributes and the assigned
// guide's private notes by editing one number. Writing was already
// refused; reading was the breach. Managers and broad-read holders keep
// their access unchanged.
// The predicate is teamaccess::may_review_team() and is not
// transcribed here, so a unit test of that function is a test of this
// page's gate.
if (!\mod_selfselectadvanced\local\teamaccess::may_review_team($activity, $group, (int) $USER->id)) {
    throw new required_capability_exception(
        $context,
        'mod/selfselectadvanced:viewassignedteams',
        'nopermissions',
        ''
    );
}

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

// Guide notes and the award belong to the guide ASSIGNED to this team.
//
// The page gate above is require_capability(':guide') on the ACTIVITY,
// and the team comes straight from the 'g' URL parameter - so until
// 1.19.1 any holder of :guide could post to this page naming any team
// in the activity and rewrite another guide's notes or set that team's
// gradebook award. Every non-editing teacher holds :guide, and so does
// the Group Coordinator role this plugin creates, which made it a
// grade-tampering path rather than a theoretical one.
//
// The 'approve' handler immediately above already gated on
// can_approve(), which checks the assignment. These two did not. The
// manager keeps administrative access, because correcting an award is
// their job and this page is the only place it can be done.
$gradingrefusal = $api->gatekeeper()->can_grade_team($group, (int) $USER->id);
$maygradeteam = $gradingrefusal === null;

if ($action === 'savenotes' && data_submitted() && confirm_sesskey()) {
    if (!$maygradeteam) {
        redirect($baseurl, $gradingrefusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
    }
    // Guide notes: rich text the guide keeps before accepting (1.3.0).
    $notes = optional_param('guidenotes', '', PARAM_RAW);
    $notesformat = optional_param('guidenotesformat', FORMAT_HTML, PARAM_INT);
    $DB->update_record('selfselectadvanced_group', (object) [
        'id' => $group->id,
        'guidenotes' => $notes,
        'guidenotesformat' => $notesformat,
        'timemodified' => time(),
    ]);
    redirect(
        $baseurl,
        get_string('guidenotessaved', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'saveaward' && data_submitted() && confirm_sesskey()) {
    if (!$maygradeteam) {
        redirect($baseurl, $gradingrefusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $award = optional_param('award', '', PARAM_RAW_TRIMMED);
    // The actor travels WITH the write. The page's own $maygradeteam
    // check above is defence in depth and no longer the only thing
    // standing between a POST and a gradebook row: the service locks
    // the team, re-reads it through this activity, and asks
    // can_grade_team() about the actor itself (A-06).
    try {
        \mod_selfselectadvanced\local\penalty\ledger::set_award(
            $activity,
            $group,
            $award === '' ? null : unformat_float($award),
            (int) $USER->id
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(
        $baseurl,
        get_string('awardsaved', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'returngroup' && data_submitted() && confirm_sesskey()) {
    // Rich-text return comment (editor element, maxfiles=0): the comment
    // itself still goes through the core lifecycle gate in state.php, and
    // its text format is saved as a companion field here, the same
    // two-step pattern already used for guide notes above.
    $comment = required_param('comment', PARAM_RAW);
    $commentformat = optional_param('commentformat', FORMAT_HTML, PARAM_INT);
    try {
        $api->lifecycle()->return_group($group, $comment, (int) $USER->id);
    } catch (\moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $DB->update_record('selfselectadvanced_group', (object) [
        'id' => $group->id,
        'returncommentformat' => $commentformat,
    ]);
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

// Upgrade the return-comment textarea rendered by the template above into
// a rich-text editor. This is a no-op when the assigned-guide markup is
// absent (the template only renders it for the assigned guide), so it is
// safe to call unconditionally.
editors_head_setup();
$returneditor = editors_get_preferred_editor(FORMAT_HTML);
$returneditor->set_text('');
$returneditor->use_editor(
    'selfselectadvanced-returncomment-' . $group->id,
    ['context' => $context, 'autosave' => false]
);

// Proposal (read) + guide notes (rich text, guide/manager only).
$fs = get_file_storage();
$proposalhtml = '';
foreach ($fs->get_area_files($context->id, 'mod_selfselectadvanced', 'proposal', (int) $group->id, 'id', false) as $file) {
    $url = moodle_url::make_pluginfile_url(
        $context->id,
        'mod_selfselectadvanced',
        'proposal',
        (int) $group->id,
        $file->get_filepath(),
        $file->get_filename(),
        true
    );
    $inlineurl = moodle_url::make_pluginfile_url(
        $context->id,
        'mod_selfselectadvanced',
        'proposal',
        (int) $group->id,
        $file->get_filepath(),
        $file->get_filename(),
        false
    );
    $proposalhtml .= html_writer::div(html_writer::link($url, $file->get_filename()));
    // The shared proposal is visible immediately, not one click away:
    // PDFs embed in place, images render in place, anything else keeps
    // the download link above as its only handle.
    $mimetype = $file->get_mimetype();
    if ($mimetype === 'application/pdf') {
        $proposalhtml .= html_writer::tag(
            'object',
            html_writer::link($url, $file->get_filename()),
            [
                'data' => $inlineurl->out(false),
                'type' => 'application/pdf',
                'class' => 'w-100 border mt-2',
                'style' => 'height: 32rem;',
            ]
        );
    } else if (strpos($mimetype, 'image/') === 0) {
        $proposalhtml .= html_writer::empty_tag('img', [
            'src' => $inlineurl->out(false),
            'alt' => $file->get_filename(),
            'class' => 'img-fluid border mt-2',
        ]);
    }
}
echo html_writer::div(
    $OUTPUT->heading(get_string('proposal', 'mod_selfselectadvanced'), 4)
    . ($proposalhtml ?: html_writer::div(get_string('proposalmissing', 'mod_selfselectadvanced'))),
    'selfselectadvanced-proposal mt-3'
);

// Group mark (award): linked to this group in every member's
// sequence-of-joining grade breakdown.
if (
    $maygradeteam
    && in_array($group->state, [\mod_selfselectadvanced\local\state::FIRM,
        \mod_selfselectadvanced\local\state::FROZEN], true)
) {
    $currentaward = $DB->get_field('selfselectadvanced_penalty', 'award', [
        'activityid' => $activity->id(),
        'groupid' => $group->id,
    ]);
    echo $OUTPUT->heading(get_string('award', 'mod_selfselectadvanced'), 4);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'd-flex gap-2 mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'g', 'value' => $group->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'saveaward']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'award', 'class' => 'form-control w-auto',
        'value' => $currentaward !== null && $currentaward !== false ? format_float((float) $currentaward, 2, true, true) : '',
        'placeholder' => get_string('awardhint', 'mod_selfselectadvanced'), ]);
    echo html_writer::empty_tag('input', ['type' => 'submit',
        'value' => get_string('awardsave', 'mod_selfselectadvanced'), 'class' => 'btn btn-secondary', ]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(get_string('guidenotes', 'mod_selfselectadvanced'), 4);
echo $OUTPUT->notification(get_string('guidenotesintro', 'mod_selfselectadvanced'), 'info', false);
if (trim((string) $group->guidenotes) !== '') {
    echo html_writer::div(
        format_text($group->guidenotes, $group->guidenotesformat, ['context' => $context]),
        'selfselectadvanced-guidenotes border rounded p-3 mb-3'
    );
}
if (!$maygradeteam) {
    // The notes are readable above; only writing them is restricted. A
    // form that always refuses on submit is worse than no form.
    echo html_writer::div(
        get_string('refusalnotassignedguide', 'mod_selfselectadvanced'),
        'alert alert-info'
    );
    echo $OUTPUT->footer();
    die;
}
$editorid = 'ssa-guidenotes-' . $group->id;
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'g', 'value' => $group->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'savenotes']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'guidenotesformat', 'value' => FORMAT_HTML]);
echo html_writer::tag(
    'textarea',
    s($group->guidenotes ?? ''),
    ['name' => 'guidenotes', 'id' => $editorid, 'rows' => 6, 'class' => 'form-control mb-2 w-100']
);
editors_head_setup();
$editor = editors_get_preferred_editor(FORMAT_HTML);
$editor->set_text($group->guidenotes ?? '');
$editor->use_editor($editorid, ['context' => $context, 'autosave' => false]);
echo html_writer::empty_tag('input', ['type' => 'submit',
    'value' => get_string('guidenotessave', 'mod_selfselectadvanced'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
