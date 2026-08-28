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
 * File a general `help` ticket without a group page (1.20.43 deliverable
 * B, maintainer's stated gap: "today a leader's only ticket is
 * unfreeze-on-frozen"). The other filing surface, on the group page
 * itself, is group.php's own ticket section; this one exists precisely
 * because the landing page has no group in view.
 *
 * "Their group" (tickets::my_group_for_help()) is resolved the same way
 * for the link that offers this page and for the filing here, so the
 * two cannot disagree about whose group a raiser without one in view is
 * asking on behalf of.
 *
 * GET renders (including the disclaimer gate screen, deliverable D); the
 * filing itself is a single sesskey-protected POST.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\tickets;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$ticketack = optional_param('ticketack', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();

$baseurl = new moodle_url('/mod/selfselectadvanced/filehelp.php', ['id' => $cm->id]);
$viewurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// The raiser's own group, resolved once and reused for both the
// eligibility check below and the filing itself - the same call
// landing.php makes to decide whether to offer the link that brought
// the viewer here.
$group = tickets::my_group_for_help($activity, (int) $USER->id);

if ($action === 'filehelp' && data_submitted() && confirm_sesskey()) {
    // 1.20.44 part 2: same type-qualified field names ticketfile_form
    // always uses (classes/form/ticketfile_form.php's docblock), even
    // though this page only ever renders the one 'help' instance.
    // 1.20.52: 'reason' is now an editor element, so it POSTs an ARRAY
    // (['text' => ..., 'format' => ...]) rather than a scalar -
    // optional_param_array() reads it, and the stored format is
    // whatever the editor actually returned, never a hardcoded constant.
    $reasoneditor = optional_param_array(
        \mod_selfselectadvanced\form\ticketfile_form::reason_field(tickets::TYPE_HELP),
        [],
        PARAM_RAW
    );
    $reason = (string) ($reasoneditor['text'] ?? '');
    $reasonformat = (int) ($reasoneditor['format'] ?? FORMAT_MOODLE);
    $draftitemid = optional_param(
        \mod_selfselectadvanced\form\ticketfile_form::attachments_field(tickets::TYPE_HELP),
        0,
        PARAM_INT
    );
    $ack = (bool) optional_param('disclaimerack', 0, PARAM_BOOL);
    try {
        // 1.20.60 (audit L-16): the documented "maxfiles 5" checked on
        // the SERVER, before anything is filed. Until now it lived only
        // in the rendered filemanager, so the limit bound the honest and
        // nobody else.
        tickets::require_within_file_limits($draftitemid);
        $filedticket = tickets::file_help($activity, $group, $reason, $reasonformat, (int) $USER->id, $ack);
        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_selfselectadvanced',
            tickets::FILEAREA_REQUEST,
            (int) $filedticket->id,
            tickets::file_options()
        );
        redirect(
            $viewurl,
            get_string('ticketfilednotice', 'mod_selfselectadvanced') . ' ' . html_writer::link(
                new moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $filedticket->id]),
                get_string('ticketthreadopen', 'mod_selfselectadvanced')
            ),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal | \required_capability_exception $e) {
        // 1.20.60 (audit L-17): KEEP THE FILES. A refusal used to send
        // the person back to an empty form, silently discarding an
        // attachment they had already uploaded - and the refusals here
        // are the ordinary ones (a duplicate live request, a disclaimer
        // not acknowledged, a race with a leadership change), not
        // exceptional ones. The draft area is real storage keyed on the
        // itemid and the uploader, so it survives the redirect; carrying
        // its id lets the re-rendered form adopt the same area instead
        // of minting an empty one.
        redirect(
            new moodle_url($baseurl, $draftitemid > 0 ? ['tdraft' => $draftitemid] : []),
            selfselectadvanced_refusal_notice($e),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// UI HIDES WHAT THE SERVICE FORBIDS, not the other way round: this
// screen asks the exact predicates file_help() enforces so a refused
// viewer is told why rather than shown a form that can only fail.
$role = tickets::raiser_role($group, (int) $USER->id);
$refusal = null;
if (!tickets::may_raise($activity, $role)) {
    $refusal = get_string('refusalticketraise' . $role, 'mod_selfselectadvanced');
} else if (!tickets::may_be_responsible($activity, $group, (int) $USER->id)) {
    $refusal = get_string(
        'refusalticketresponsible' . tickets::responsible_role($group),
        'mod_selfselectadvanced'
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ticketfilehelp', 'mod_selfselectadvanced'));

if ($refusal !== null) {
    echo $OUTPUT->notification($refusal, 'info', false);
} else {
    $settings = $activity->settings();
    $disclaimertext = trim(html_to_text((string) ($settings->ticketdisclaimer ?? '')));
    // 1.20.45: the filing deflection's own "continue" url, built before
    // the branching below so both the disclaimer gate and the deflection
    // screen read from the one computation.
    $kbcontinueurl = new moodle_url($baseurl, ['ticketack' => $ticketack ? 1 : 0, 'showform' => 1]);
    $kbdeflectionhtml = selfselectadvanced_kb_deflection_screen($activity, tickets::TYPE_HELP, $kbcontinueurl);
    if ($disclaimertext !== '' && !$ticketack) {
        // Deliverable D: the gate screen precedes the form - it only
        // renders after this "I acknowledge" link is followed.
        echo html_writer::div(
            format_text((string) $settings->ticketdisclaimer, (int) $settings->ticketdisclaimerformat, ['context' => $context]),
            'selfselectadvanced-ticketdisclaimer alert alert-info'
        );
        echo $OUTPUT->single_button(
            new moodle_url($baseurl, ['ticketack' => 1]),
            get_string('ticketdisclaimeracknowledge', 'mod_selfselectadvanced'),
            'get'
        );
    } else if ($kbdeflectionhtml !== '') {
        // Deflection shown, the form withheld until "continue" - NO
        // forced block (spec), so nothing here stops the requester
        // reaching the form; it is only ever one click further away.
        echo $kbdeflectionhtml;
    } else {
        echo html_writer::tag('p', get_string('tickethelpintro', 'mod_selfselectadvanced'));
        // 1.20.44 part 2: a real moodleform, purely for
        // file_save_draft_area_files() draft-area handling on the new
        // optional attachment (classes/form/ticketfile_form.php).
        $ticketfileoptions = tickets::file_options();
        $ticketform = new \mod_selfselectadvanced\form\ticketfile_form(
            (new moodle_url($baseurl, ['action' => 'filehelp']))->out(false),
            [
                'tickettype' => tickets::TYPE_HELP,
                'disclaimerack' => $ticketack ? 1 : 0,
                'fileoptions' => $ticketfileoptions,
            ]
        );
        // A brand new ticket has no id yet, so a fresh draft area is
        // minted (itemid null) - the two-step sequence the 'filehelp'
        // action above completes once the real ticket id exists.
        // 1.20.60 (audit L-17): adopt the draft area a refused submission
        // left behind, when the redirect above named one, so the files
        // are still attached to the form the person is looking at. Zero
        // otherwise, which mints a fresh one exactly as before.
        $ticketdraftid = optional_param('tdraft', 0, PARAM_INT);
        file_prepare_draft_area(
            $ticketdraftid,
            $context->id,
            'mod_selfselectadvanced',
            tickets::FILEAREA_REQUEST,
            null,
            $ticketfileoptions
        );
        $ticketform->set_data([
            \mod_selfselectadvanced\form\ticketfile_form::attachments_field(tickets::TYPE_HELP) => $ticketdraftid,
        ]);
        $ticketform->display();
    }
}
echo html_writer::link($viewurl, get_string('back'), ['class' => 'btn btn-secondary ms-2 mt-2']);
echo $OUTPUT->footer();
