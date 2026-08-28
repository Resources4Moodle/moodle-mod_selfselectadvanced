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
 * What I asked for: a requester's own tickets, and their outcomes.
 *
 * The queue page (tickets.php) belongs to the staff who work it and
 * requires manage or coordinate. This page is the other side of that
 * door: it shows one person the requests THEY filed, what was said back,
 * and lets them take one back while it is still open.
 *
 * NO CAPABILITY GATES IT, deliberately, and that matches the service.
 * tickets::file() contains no capability check either - the authority to
 * ask is relational (the team's guide, its leader, a confirmed member),
 * and the authority to see the answer is simply having asked. The scope
 * is enforced where it belongs, in tickets::mine(), by requestedby.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\tickets;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
// 1.20.57 deliverable A: state and type filters using the SAME
// vocabulary tickets.php's own queue page uses (known_types()/
// filterable_statuses() are the queue's own lists, shared rather than
// duplicated, so the two pages cannot drift apart).
$typefilter = optional_param('type', '', PARAM_ALPHA);
if (!in_array($typefilter, tickets::known_types(), true)) {
    $typefilter = '';
}
$statusfilter = optional_param('status', '', PARAM_ALPHA);
if (!in_array($statusfilter, tickets::filterable_statuses(), true)) {
    $statusfilter = '';
}
// The free-text search: the reference or the request text, trimmed so
// pure whitespace reads as "no filter" (matches the service layer's own
// definition of empty).
$search = trim(optional_param('search', '', PARAM_TEXT));

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();

// The filter travels on $baseurl itself - every paging link and the
// withdraw form's own redirect are built from it (the same reasoning
// tickets.php's own $baseurl states: without this a filtered page two
// would lose its filter the moment a page number or a withdraw
// round-trip touched the URL).
$baseurl = new moodle_url('/mod/selfselectadvanced/myrequests.php', ['id' => $cm->id]);
if ($typefilter !== '') {
    $baseurl->param('type', $typefilter);
}
if ($statusfilter !== '') {
    $baseurl->param('status', $statusfilter);
}
if ($search !== '') {
    $baseurl->param('search', $search);
}
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Taking a request back before anybody has picked it up. The gate is
// tickets::withdraw() - requester-owned and open-only - not this page:
// until 1.20.39 that gate existed with no caller a requester could
// reach, which is the same as not existing.
if ($action === 'withdraw' && data_submitted() && confirm_sesskey()) {
    $ticketid = required_param('t', PARAM_INT);
    try {
        tickets::withdraw($activity, $ticketid, (int) $USER->id);
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

// 1.20.59 deliverable A: "did this help?" - offered on this list too
// (spec: "on the thread and on their own list"), the same
// tickets::give_feedback() door ticket.php's own arm calls, so the two
// surfaces can never disagree about who may answer, when, or how many
// times.
if ($action === 'feedback' && data_submitted() && confirm_sesskey()) {
    $ticketid = required_param('t', PARAM_INT);
    $note = optional_param('note', '', PARAM_RAW);
    // 1.20.60 (D-108): the same two arms the thread offers, through the
    // same two service doors, so the two surfaces cannot disagree about
    // what the second button does any more than they could about who may
    // answer the first.
    $wantsreopen = (bool) optional_param('reopen', 0, PARAM_BOOL);
    try {
        if ($wantsreopen) {
            tickets::reopen($activity, $ticketid, $note, FORMAT_MOODLE, (int) $USER->id);
            redirect(
                $baseurl,
                get_string('ticketreopenednotice', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        $verdict = required_param('verdict', PARAM_INT);
        tickets::give_feedback($activity, $ticketid, $verdict, $note, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketfeedbackthanks', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$perpage = \mod_selfselectadvanced\local\perpage::current(25);
// The UNFILTERED total decides whether there is anything to filter or
// search at all (1.20.57 deliverable A): a requester who has never filed
// a ticket gets the plain "you have not sent any requests" message
// regardless of what a hand-edited querystring asks for, and is never
// shown a filter form with nothing behind it. $total (below) is the
// FILTERED count, and it is what the paging bar and the "N match" line
// use - the same split tickets.php's own $totaltickets keeps.
$totalunfiltered = tickets::mine_count($activity, (int) $USER->id);
$total = tickets::mine_count($activity, (int) $USER->id, $typefilter, $statusfilter, $search);
$mine = tickets::mine($activity, (int) $USER->id, $page * $perpage, $perpage, $typefilter, $statusfilter, $search);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('myrequests', 'mod_selfselectadvanced'));

if ($totalunfiltered === 0) {
    // Not a refusal. Somebody who has asked for nothing has nothing
    // here, and saying so plainly beats an empty table.
    echo html_writer::div(get_string('myrequestsnone', 'mod_selfselectadvanced'), 'alert alert-info');
} else {
    echo html_writer::tag('p', get_string('myrequestsintro', 'mod_selfselectadvanced'), ['class' => 'text-muted']);

    // 1.20.57 deliverable A: search + state/type filters, modelled on
    // tickets.php's own GET filter form so the two behave identically -
    // changing any control drops the page number, since the old one
    // belongs to a different, unfiltered list.
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
        'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::label(get_string('search'), 'ssa-myrequests-search', true, ['class' => 'me-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'ssa-myrequests-search',
        'name' => 'search',
        'value' => $search,
        'placeholder' => get_string('myrequestssearchplaceholder', 'mod_selfselectadvanced'),
        'class' => 'form-control form-control-sm w-auto me-2',
    ]);
    echo html_writer::label(get_string('tickettype', 'mod_selfselectadvanced'), 'ssa-myrequests-type', true, ['class' => 'me-2']);
    $typeoptions = ['' => get_string('ticketfilterall', 'mod_selfselectadvanced')];
    foreach (tickets::known_types() as $knowntype) {
        $typeoptions[$knowntype] = get_string('tickettype' . $knowntype, 'mod_selfselectadvanced');
    }
    echo html_writer::select(
        $typeoptions,
        'type',
        $typefilter,
        false,
        ['id' => 'ssa-myrequests-type', 'class' => 'form-select form-select-sm w-auto me-2']
    );
    echo html_writer::label(
        get_string('ticketstatus', 'mod_selfselectadvanced'),
        'ssa-myrequests-status',
        true,
        ['class' => 'me-2']
    );
    $statusoptions = ['' => get_string('ticketfilterallstatuses', 'mod_selfselectadvanced')];
    foreach (tickets::filterable_statuses() as $knownstatus) {
        $statusoptions[$knownstatus] = get_string('ticketstatus' . $knownstatus, 'mod_selfselectadvanced');
    }
    echo html_writer::select(
        $statusoptions,
        'status',
        $statusfilter,
        false,
        ['id' => 'ssa-myrequests-status', 'class' => 'form-select form-select-sm w-auto me-2']
    );
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('ticketfilterapply', 'mod_selfselectadvanced'),
        'class' => 'btn btn-secondary btn-sm']);
    echo html_writer::end_tag('form');

    // Same "state the total once a filter narrows the list" rule
    // tickets.php's own queue page follows.
    if ($typefilter !== '' || $statusfilter !== '' || $search !== '') {
        echo html_writer::div(
            get_string('ticketfiltermatches', 'mod_selfselectadvanced', $total),
            'small text-muted mb-2'
        );
    }

    if (!$mine) {
        // The requester DOES have tickets ($totalunfiltered > 0) - this
        // filter or search simply matched none of them, which is a
        // different, true statement from "you have not sent any
        // requests" and must not be told the false one.
        echo html_writer::div(get_string('ticketfilternomatches', 'mod_selfselectadvanced'), 'alert alert-info');
        echo html_writer::link(
            new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]),
            get_string('back'),
            ['class' => 'btn btn-secondary mt-3']
        );
        echo $OUTPUT->footer();
        die;
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable selfselectadvanced-myrequests';
    $table->head = [
        // 1.20.56 deliverable A: the quotable reference - t.pluginuid
        // arrives with the row already (mine()'s own "SELECT t.*"), so no
        // query changes for this column.
        get_string('ticketreference', 'mod_selfselectadvanced'),
        get_string('ticketsubject', 'mod_selfselectadvanced'),
        get_string('tickettype', 'mod_selfselectadvanced'),
        get_string('ticketrequest', 'mod_selfselectadvanced'),
        get_string('ticketstatus', 'mod_selfselectadvanced'),
        get_string('date'),
        get_string('actions'),
    ];

    // 1.20.58 deliverables B and C: how long the staff clock has been
    // running for each row on THIS page, one bulk statement for the
    // whole page rather than a query per row, and the activity's own
    // target, read once.
    $waitsincemap = tickets::staff_wait_since_map($activity, $mine);
    $targethours = (int) $activity->settings()->tickettargethours;

    foreach ($mine as $ticket) {
        $subject = $ticket->groupname !== null
            ? format_string($ticket->groupname) . ' (' . s($ticket->grouppluginuid) . ')'
            : get_string('tickethasnoteam', 'mod_selfselectadvanced');

        // The status cell carries the answer. A closed request whose
        // note is empty says so rather than showing a blank, because a
        // blank reads as "nothing was said" when the truth may be
        // "something was said and this page lost it".
        $statuscell = get_string('ticketstatus' . $ticket->status, 'mod_selfselectadvanced');
        // How long this has been waiting on staff (absent while the ball
        // is with the requester themself, or the request is closed), and
        // the acknowledgement - never silence - when it has run past the
        // activity's own target.
        $waitsince = $waitsincemap[(int) $ticket->id] ?? null;
        if ($waitsince !== null) {
            $statuscell .= html_writer::div(
                get_string('ticketwaitingsince', 'mod_selfselectadvanced', format_time(time() - $waitsince)),
                'small text-muted'
            );
            if (tickets::is_overdue($waitsince, $targethours)) {
                $statuscell .= html_writer::div(
                    get_string('ticketoverduenotice', 'mod_selfselectadvanced'),
                    'small text-danger'
                );
            }
        }
        if (in_array($ticket->status, [tickets::STATUS_RESOLVED, tickets::STATUS_DECLINED], true)) {
            $note = trim((string) ($ticket->resolution ?? ''));
            $statuscell .= html_writer::div(
                $note === ''
                    ? get_string('myrequestsnonote', 'mod_selfselectadvanced')
                    : format_text($note, (int) $ticket->resolutionformat, ['context' => $context]),
                'small text-muted selfselectadvanced-myrequests-note'
            );
        }
        // 1.20.59 deliverable A: "did this help?" - RESOLVED only (never
        // declined or withdrawn, which never asked the question), and
        // only while unanswered; once answered this prints the read-only
        // "you said" line instead and never offers the control again -
        // the same VERDICT_UNANSWERED gate tickets::give_feedback() and
        // ticket_page::export_actionbox() both re-check at their own
        // doors, so a stale render here can be resubmitted but never
        // double-recorded.
        if ($ticket->status === tickets::STATUS_RESOLVED) {
            if ((int) $ticket->verdict === tickets::VERDICT_UNANSWERED) {
                $statuscell .= html_writer::start_div('selfselectadvanced-myrequests-feedback mt-1');
                $statuscell .= html_writer::tag(
                    'p',
                    get_string('ticketfeedbackoncenotice', 'mod_selfselectadvanced'),
                    ['class' => 'small text-muted mb-1']
                );
                $statuscell .= html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
                $statuscell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
                $statuscell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'feedback']);
                $statuscell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 't', 'value' => $ticket->id]);
                $statuscell .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                $statuscell .= html_writer::empty_tag('input', [
                    'type' => 'text',
                    'name' => 'note',
                    'placeholder' => get_string('ticketfeedbacknotelabel', 'mod_selfselectadvanced'),
                    'class' => 'form-control form-control-sm d-inline-block w-auto mb-1',
                ]);
                $statuscell .= html_writer::tag('div', '', ['class' => 'w-100']);
                // A button element, not a plain submit input: the visible
                // label and the submitted value need to differ, which
                // only a button element can carry - the same two-button,
                // one-shared-note-field shape ticket_page.mustache's own
                // thread form uses.
                $statuscell .= html_writer::tag(
                    'button',
                    get_string('ticketfeedbackyes', 'mod_selfselectadvanced'),
                    ['type' => 'submit', 'name' => 'verdict', 'value' => tickets::VERDICT_HELPED,
                        'class' => 'btn btn-success btn-sm', ]
                );
                // 1.20.60 (D-108): reply to reopen. Its own submit name,
                // not a verdict value - the two buttons now call two
                // different service methods.
                $statuscell .= html_writer::tag(
                    'button',
                    get_string('ticketfeedbackreopen', 'mod_selfselectadvanced'),
                    ['type' => 'submit', 'name' => 'reopen', 'value' => 1,
                        'class' => 'btn btn-outline-danger btn-sm ms-1', ]
                );
                $statuscell .= html_writer::end_tag('form');
                $statuscell .= html_writer::end_div();
            } else {
                $verdicthelped = (int) $ticket->verdict === tickets::VERDICT_HELPED;
                $statuscell .= html_writer::div(
                    get_string(
                        $verdicthelped ? 'ticketfeedbackyousaidhelped' : 'ticketfeedbackyousaidnothelped',
                        'mod_selfselectadvanced'
                    ),
                    'small ' . ($verdicthelped ? 'text-success' : 'text-danger')
                );
                if (trim((string) $ticket->verdictnote) !== '') {
                    $statuscell .= html_writer::div(
                        format_text((string) $ticket->verdictnote, (int) $ticket->verdictnoteformat, ['context' => $context]),
                        'small text-muted'
                    );
                }
            }
        }

        $threadurl = new moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticket->id]);

        $actions = '';
        if ($ticket->status === tickets::STATUS_OPEN) {
            $actions = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'withdraw'])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 't', 'value' => $ticket->id])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
                    'value' => get_string('myrequestswithdraw', 'mod_selfselectadvanced')])
                . html_writer::end_tag('form');
        } else if ($ticket->status === tickets::STATUS_NEEDSINFO) {
            // Slice B2 (deliverable 2): a requester who never opens the
            // thread must still be led to the question waiting for them
            // - the status label alone ("Waiting on the requester") does
            // not say that the requester is THEM, still less what is
            // being asked.
            $statuscell .= html_writer::div(
                get_string('ticketthreadneedsinfohint', 'mod_selfselectadvanced'),
                'small text-warning-emphasis'
            );
            $actions = html_writer::link($threadurl, get_string('ticketthreadrespond', 'mod_selfselectadvanced'), [
                'class' => 'btn btn-primary btn-sm',
            ]);
        } else if ($ticket->status === tickets::STATUS_CLAIMED) {
            // Why no control here: once somebody has taken it up it is
            // their work in progress. Saying that is better than an
            // empty cell the reader has to interpret.
            $actions = html_writer::span(
                get_string('myrequestsclaimedhint', 'mod_selfselectadvanced'),
                'small text-muted'
            );
        }
        // Every row links to its thread (deliverable 2: "each row links
        // to its thread" - kept alongside Withdraw and the needs-info
        // respond link, not replaced by either).
        if ($ticket->status !== tickets::STATUS_NEEDSINFO) {
            $actions .= ' ' . html_writer::link($threadurl, get_string('ticketthreadview', 'mod_selfselectadvanced'), [
                'class' => 'btn btn-outline-secondary btn-sm',
            ]);
        }

        $table->data[] = [
            s($ticket->pluginuid),
            $subject,
            get_string('tickettype' . $ticket->type, 'mod_selfselectadvanced'),
            format_text((string) $ticket->request, (int) $ticket->requestformat, ['context' => $context]),
            $statuscell,
            userdate((int) $ticket->timecreated, get_string('strftimedatetimeshort')),
            $actions,
        ];
    }

    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
}

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);
echo $OUTPUT->footer();
