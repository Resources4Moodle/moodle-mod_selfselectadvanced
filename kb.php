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
 * The knowledgebank (1.20.45): a staff list (add/edit/unpublish/delete)
 * for queue authority, a browse+search view (published only, grouped by
 * type) for anyone else who can view the activity.
 *
 * action=form is the one shared write surface: a resolved ticket's
 * pre-filled draft (t=), an existing entry's edit (e=), or a brand new
 * direct-add article (neither) - kb_form.php's own docblock explains
 * why one form serves all three. Every other action (unpublish,
 * republish, delete) is a sesskey-protected POST back to this page.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\local\workflow_refusal;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = activity::from_cmid($cm->id);
$context = $activity->context();

// Access (spec deliverable): the student browse+search view is open to
// anyone who can view the activity (require_login above already proved
// that); management - add/edit/unpublish/delete - needs queue authority.
$isstaff = has_capability('mod/selfselectadvanced:manage', $context)
    || has_capability('mod/selfselectadvanced:coordinate', $context);

$listurl = new moodle_url('/mod/selfselectadvanced/kb.php', ['id' => $cm->id]);
$PAGE->set_url($listurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if (in_array($action, ['unpublish', 'republish', 'delete'], true) && data_submitted() && confirm_sesskey()) {
    if (!$isstaff) {
        throw new required_capability_exception($context, 'mod/selfselectadvanced:coordinate', 'nopermissions', '');
    }
    $entryid = required_param('e', PARAM_INT);
    try {
        if ($action === 'unpublish') {
            kb::unpublish($activity, $entryid, (int) $USER->id);
            $notice = get_string('kbunpublishednotice', 'mod_selfselectadvanced');
        } else if ($action === 'republish') {
            // A bare republish: the PARTIAL-update contract (kb::update()'s
            // own docblock) means only 'published' changes here - every
            // other column keeps whatever the entry already held.
            kb::update($activity, $entryid, ['published' => 1], (int) $USER->id);
            $notice = get_string('kbrepublishednotice', 'mod_selfselectadvanced');
        } else {
            kb::delete($activity, $entryid, (int) $USER->id);
            $notice = get_string('kbdeletednotice', 'mod_selfselectadvanced');
        }
        redirect($listurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (workflow_refusal $e) {
        redirect($listurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'form') {
    if (!$isstaff) {
        throw new required_capability_exception($context, 'mod/selfselectadvanced:coordinate', 'nopermissions', '');
    }
    // Read straight off the request (not $data->ticketid/entryid) so
    // the pre-fill branch below has them before any form data exists -
    // Moodle's optional_param() answers identically whether they
    // arrived on the query string (a fresh GET) or as POST fields
    // (kb_form's own hidden elements, carrying the same values back).
    $ticketid = optional_param('t', 0, PARAM_INT);
    $entryid = optional_param('e', 0, PARAM_INT);

    $formurl = new moodle_url('/mod/selfselectadvanced/kb.php', array_filter([
        'id' => $cm->id,
        'action' => 'form',
        't' => $ticketid ?: null,
        'e' => $entryid ?: null,
    ], static fn($v) => $v !== null));
    $mform = new \mod_selfselectadvanced\form\kb_form($formurl->out(false), [
        'ticketid' => $ticketid,
        'entryid' => $entryid,
    ]);

    if ($mform->is_cancelled()) {
        redirect($listurl);
    } else if ($data = $mform->get_data()) {
        // PARAM_RAW + explicit format for rich text: these are plain
        // textareas (kb_form.php's own docblock), so the format is
        // hardcoded here rather than read from the submission, the same
        // convention ticket.php's own resolve/decline/reply arms use for
        // theirs.
        $draft = [
            'title' => $data->title,
            'question' => $data->question,
            'questionformat' => FORMAT_MOODLE,
            'answer' => $data->answer,
            'answerformat' => FORMAT_MOODLE,
            'tickettype' => $data->tickettype,
            'keywords' => $data->keywords,
            'published' => !empty($data->published),
        ];
        try {
            if (!empty($data->ticketid)) {
                kb::publish_from_ticket($activity, (int) $data->ticketid, (int) $USER->id, $draft);
            } else if (!empty($data->entryid)) {
                kb::update($activity, (int) $data->entryid, $draft, (int) $USER->id);
            } else {
                kb::create($activity, (int) $USER->id, $draft);
            }
            redirect(
                $listurl,
                get_string('kbsavednotice', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (workflow_refusal $e) {
            // Back to the SAME form (t=/e= preserved in $formurl) rather
            // than the list: the anonymisation guard or the resolved-only
            // gate is exactly the kind of refusal a second look at the
            // wording can fix. The typed edits themselves are lost - the
            // same trade-off every redirect-plus-notice refusal in this
            // plugin already makes.
            redirect($formurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    } else {
        if ($entryid > 0) {
            $entry = kb::get($activity, $entryid);
            $mform->set_data([
                'title' => $entry->title,
                'tickettype' => $entry->tickettype,
                'question' => $entry->question,
                'answer' => $entry->answer,
                'keywords' => $entry->keywords,
                'published' => (int) $entry->published,
            ]);
        } else if ($ticketid > 0) {
            // The pre-filled draft (spec: "title from type label,
            // question from request text, answer from resolution") - the
            // ticket's OWN raw text, cleaned to plain text so it does not
            // paste raw HTML into a plain textarea. The staff member EDITS
            // this before saving; kb::publish_from_ticket() never stores
            // it unedited even if they do not.
            $ticket = tickets::get($activity, $ticketid);
            if ($ticket->status !== tickets::STATUS_RESOLVED) {
                redirect(
                    $listurl,
                    get_string('refusalkbnotresolved', 'mod_selfselectadvanced'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $mform->set_data([
                'title' => get_string('tickettype' . $ticket->type, 'mod_selfselectadvanced'),
                'tickettype' => $ticket->type,
                'question' => trim(html_to_text((string) $ticket->request)),
                'answer' => trim(html_to_text((string) ($ticket->resolution ?? ''))),
                'keywords' => '',
                'published' => 1,
            ]);
        }

        // The draft-from-ticket screen gets its own heading (reusing the
        // checkbox's own words - it names the same action) rather than
        // reading as an ordinary "Add an article": staff arriving here
        // just resolved a ticket and ticked the box, and the form in
        // front of them is that ticket's wording, not a blank one.
        if ($entryid > 0) {
            $heading = get_string('kbeditarticle', 'mod_selfselectadvanced');
        } else if ($ticketid > 0) {
            $heading = get_string('kbpublishfaqcheckbox', 'mod_selfselectadvanced');
        } else {
            $heading = get_string('kbaddarticle', 'mod_selfselectadvanced');
        }

        echo $OUTPUT->header();
        echo $OUTPUT->heading($heading);
        $mform->display();
        echo html_writer::link($listurl, get_string('back'), ['class' => 'btn btn-secondary mt-2']);
        echo $OUTPUT->footer();
        die;
    }
}

// GET, or a POST that fell through every action arm above: the list -
// staff or student, kb_page.php itself decides which.
$q = optional_param('q', '', PARAM_TEXT);
$page = new \mod_selfselectadvanced\output\kb_page($activity, $isstaff, $q);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/kb_page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
