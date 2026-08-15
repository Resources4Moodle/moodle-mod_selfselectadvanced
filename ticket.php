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
 * A ticket's thread (slice B2): the single place a ticket's conversation
 * lives, modelled on mod_forum's post rendering ("repurpose the code,
 * reducing the development time" - maintainer). Requester and staff both
 * land here; tickets::trail() tells the two apart itself.
 *
 * GET renders; claim, release, request-info, resolve/grant, decline,
 * provide-info and withdraw are all sesskey-protected POSTs that
 * redirect back here.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\tickets;

// The single required param is the ticket id (spec deliverable 1) - the
// course module is derived from the ticket's own activity instance, not
// carried on the URL alongside it, so a notification or a queue row only
// ever has to know the ticket.
$t = required_param('t', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$ticketrow = $DB->get_record('selfselectadvanced_ticket', ['id' => $t], '*', MUST_EXIST);
$activity = activity::from_instance((int) $ticketrow->activityid);
$cm = $activity->cm();
$course = get_course($activity->courseid());
require_login($course, true, $cm);

$context = $activity->context();
$ticket = tickets::get($activity, $t);
$group = tickets::group_of($activity, $ticket);

// ACCESS RULE (spec deliverable 1), asked through ONE predicate -
// tickets::may_view_thread() - so a required_capability_exception is
// what everyone else gets (not a blank page), and a test can drive every
// arm of the rule without this page script, which PHPUnit cannot execute
// end-to-end (require_login(), redirect(), echo $OUTPUT->header()).
if (!tickets::may_view_thread($activity, $ticket, (int) $USER->id)) {
    throw new required_capability_exception($context, 'mod/selfselectadvanced:coordinate', 'nopermissions', '');
}
$isrequester = (int) $ticket->requestedby === (int) $USER->id;
// A requester who ALSO happens to hold queue authority (a manager asking
// on their own behalf, say) still reads their own ticket as staff below
// - has_capability() is cheap and correct to ask either way, and doing
// so keeps "isstaff" meaning exactly one thing everywhere on this page.
$isstaff = has_capability('mod/selfselectadvanced:manage', $context)
    || has_capability('mod/selfselectadvanced:coordinate', $context);

$baseurl = new moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $t]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'claim' && data_submitted() && confirm_sesskey()) {
    try {
        tickets::claim($activity, $t, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketclaimednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'release' && data_submitted() && confirm_sesskey()) {
    // The claimant's own hand-back, restored per orchestrator review
    // (2026-08-15): until the 1.20.44 refer/escalate ladder exists, a
    // claimant who cannot handle a ticket needs a way to return it to
    // the queue, or it rots under their claim until a manager happens
    // to notice. Bare button, no note field - the SAME service call
    // tickets.php's queue used for this (close() with the open outcome,
    // empty resolution, which close() never persists for that outcome),
    // and the same call this file already uses nowhere else (only
    // resolve/decline pass a note through). close() itself decides
    // whether release is legal from the ticket's current status - it
    // already allows it from both claimed and needsinfo (decision 2,
    // LIVENESS) - so no new service logic is added here.
    try {
        tickets::close($activity, $t, tickets::STATUS_OPEN, '', FORMAT_PLAIN, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketclosednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'requestinfo' && data_submitted() && confirm_sesskey()) {
    $question = optional_param('question', '', PARAM_RAW);
    $questiondraftid = optional_param('questionattachments', 0, PARAM_INT);
    try {
        tickets::request_info($activity, $t, $question, FORMAT_MOODLE, (int) $USER->id);
        // The needs-info question just became a new ticketlog row - the
        // same two-step sequence group.php's filing forms use for a
        // ticket's own id, completed here now that the row's real id
        // exists.
        tickets::save_post_attachments($activity, $t, $questiondraftid);
        redirect(
            $baseurl,
            get_string('ticketthreadquestionsent', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'refer' && data_submitted() && confirm_sesskey()) {
    // The handling ladder's first rung (1.20.44): the claimant hands the
    // ticket to another coordinator. required_param, not optional - a
    // referral with no chosen target is not a referral.
    $targetid = required_param('target', PARAM_INT);
    $note = optional_param('note', '', PARAM_RAW);
    try {
        tickets::refer($activity, $t, $targetid, $note, FORMAT_MOODLE, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketreferrednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'escalate' && data_submitted() && confirm_sesskey()) {
    // The handling ladder's second rung (1.20.44): raise the ticket to
    // the editing-teacher/manager tier. Open to the claimant AND to any
    // manage-level holder even when unclaimed - escalate() itself
    // enforces exactly that pair, so this arm just calls it.
    $note = optional_param('note', '', PARAM_RAW);
    try {
        tickets::escalate($activity, $t, $note, FORMAT_MOODLE, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketescalatednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'grant' && data_submitted() && confirm_sesskey()) {
    $note = optional_param('resolution', '', PARAM_RAW);
    $resolutiondraftid = optional_param('resolutionattachments', 0, PARAM_INT);
    try {
        tickets::grant_guidecap($activity, $t, $note, FORMAT_MOODLE, (int) $USER->id);
        tickets::save_post_attachments($activity, $t, $resolutiondraftid);
        redirect(
            $baseurl,
            get_string('guidecapgranted', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if (in_array($action, ['resolve', 'decline'], true) && data_submitted() && confirm_sesskey()) {
    // Two distinct fields (resolution / declinereason), never one shared
    // textarea: the queue's old combined form is exactly what forced
    // both buttons to submit the same field, which is fine with one
    // shared form but not with two separate ones on the thread.
    $note = $action === 'resolve'
        ? optional_param('resolution', '', PARAM_RAW)
        : optional_param('declinereason', '', PARAM_RAW);
    // Decline carries no filemanager at all (spec names exactly
    // request-info/info-reply/resolve for the new attachment; a
    // staff-internal-shaped short note stays text-only) - the draft id
    // is read, and the save below runs, ONLY on the resolve arm.
    $resolutiondraftid = $action === 'resolve' ? optional_param('resolutionattachments', 0, PARAM_INT) : 0;
    // 1.20.45: the resolve form's own "Publish as FAQ" checkbox
    // (ticketpost_form.php's showpublishfaq); decline carries no such
    // field at all, so this reads 0 for that arm without even asking.
    $publishfaq = $action === 'resolve' && (bool) optional_param('publishfaq', 0, PARAM_BOOL);
    $outcome = $action === 'resolve' ? tickets::STATUS_RESOLVED : tickets::STATUS_DECLINED;
    try {
        tickets::close($activity, $t, $outcome, $note, FORMAT_MOODLE, (int) $USER->id);
        if ($action === 'resolve') {
            tickets::save_post_attachments($activity, $t, $resolutiondraftid);
        }
        if ($publishfaq) {
            // Publishing is a SECOND deliberate step (maintainer's own
            // words), never a side effect of resolving: this redirects to
            // the knowledgebank's pre-filled DRAFT form - kb.php - which
            // the staff member still has to edit and save. Resolving
            // itself is already committed above regardless of what
            // happens next on that screen.
            redirect(new moodle_url('/mod/selfselectadvanced/kb.php', ['id' => $cm->id, 'action' => 'form', 't' => $t]));
        }
        redirect(
            $baseurl,
            get_string('ticketclosednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'provideinfo' && data_submitted() && confirm_sesskey()) {
    $reply = optional_param('reply', '', PARAM_RAW);
    $replydraftid = optional_param('replyattachments', 0, PARAM_INT);
    try {
        tickets::provide_info($activity, $t, $reply, FORMAT_MOODLE, (int) $USER->id);
        tickets::save_post_attachments($activity, $t, $replydraftid);
        redirect(
            $baseurl,
            get_string('ticketthreadreplysentnotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'withdraw' && data_submitted() && confirm_sesskey()) {
    try {
        tickets::withdraw($activity, $t, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('myrequestswithdrawn', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// GET, or a POST that fell through every action arm above (an unknown
// or missing 'action'): render. The view event fires here, once, only
// on the path that actually reads the thread - every POST arm above
// redirects before reaching this line.
\mod_selfselectadvanced\event\ticket_viewed::create([
    'objectid' => $t,
    'context' => $context,
    'other' => ['type' => $ticket->type],
])->trigger();

$page = new \mod_selfselectadvanced\output\ticket_page(
    $activity,
    $ticket,
    $group,
    (int) $USER->id,
    $isrequester,
    $isstaff
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/ticket_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
