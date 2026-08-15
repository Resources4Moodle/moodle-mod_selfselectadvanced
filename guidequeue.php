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
 * A guide's request queue (strategy 1.18 C).
 *
 * Two tabs, because there are two directions. What is waiting for this
 * guide to answer - teams that have approached them, handovers proposed
 * to them - and what this guide has asked the coordinators for, which
 * before this release they had no way to ask at all.
 *
 * The queue merges two services into one sortable, filterable, paged and
 * downloadable table: a guide carrying forty teams should not have to
 * read three separate lists to find out what is outstanding.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\tickets;

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'waiting', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:guide', $context);

if (!in_array($tab, ['waiting', 'mine'], true)) {
    $tab = 'waiting';
}
$baseurl = new moodle_url('/mod/selfselectadvanced/guidequeue.php', ['id' => $cm->id]);
$taburl = new moodle_url($baseurl, ['tab' => $tab]);
$PAGE->set_url($taburl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$api = new \mod_selfselectadvanced\local\api($activity);
$resolver = $api->gatekeeper()->resolver();

// Asking the coordinators for a LOWER team limit, or to be relieved
// entirely (flow d, 2026-08-06). Suggested replacement guides travel
// in the reason text; the coordinators rehome teams deliberately.
if ($action === 'askreduce' && data_submitted() && confirm_sesskey()) {
    $requested = required_param('requested', PARAM_INT);
    $reason = required_param('reason', PARAM_RAW);
    try {
        tickets::file_guidereduce($activity, $requested, $reason, FORMAT_MOODLE, (int) $USER->id);
        redirect(
            new moodle_url($baseurl, ['tab' => 'mine']),
            get_string('guidereducefiled', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'mine']),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// Asking the coordinators for a higher team limit.
if ($action === 'askcap' && data_submitted() && confirm_sesskey()) {
    $requested = required_param('requested', PARAM_INT);
    $reason = required_param('reason', PARAM_RAW);
    try {
        tickets::file_guidecap($activity, $requested, $reason, FORMAT_MOODLE, (int) $USER->id);
        redirect(
            new moodle_url($baseurl, ['tab' => 'mine']),
            get_string('guidecapfiled', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'mine']),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// Withdrawing one while it is still open.
if ($action === 'withdrawcap' && data_submitted() && confirm_sesskey()) {
    $ticketid = required_param('t', PARAM_INT);
    try {
        tickets::withdraw($activity, $ticketid, (int) $USER->id);
        redirect(
            new moodle_url($baseurl, ['tab' => 'mine']),
            get_string('guidecapwithdrawn', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'mine']),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$download = optional_param('download', '', PARAM_ALPHA);
$perpage = \mod_selfselectadvanced\local\perpage::current(25);
$kindfilter = optional_param('kind', '', PARAM_ALPHA);
if (!in_array($kindfilter, ['contact', 'handover'], true)) {
    $kindfilter = '';
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('guiderequestqueue', 'mod_selfselectadvanced'));

$tabs = [];
foreach (['waiting' => 'guidequeuewaitingtab', 'mine' => 'guidequeueminetab'] as $key => $label) {
    $tabs[] = new tabobject(
        $key,
        new moodle_url($baseurl, ['tab' => $key]),
        get_string($label, 'mod_selfselectadvanced')
    );
}
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'waiting') {
    $filterurl = new moodle_url($baseurl, ['tab' => 'waiting']);
    if ($kindfilter !== '') {
        $filterurl->param('kind', $kindfilter);
    }

    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
        'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'waiting']);
    echo html_writer::label(get_string('requestkind', 'mod_selfselectadvanced'), 'ssa-kind', true, ['class' => 'me-2']);
    echo html_writer::select(
        [
            '' => get_string('all'),
            'contact' => get_string('requestkindcontact', 'mod_selfselectadvanced'),
            'handover' => get_string('requestkindhandover', 'mod_selfselectadvanced'),
        ],
        'kind',
        $kindfilter,
        false,
        ['id' => 'ssa-kind', 'class' => 'form-select form-select-sm w-auto me-2']
    );
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
        'class' => 'btn btn-secondary btn-sm']);
    echo html_writer::end_tag('form');

    $rows = \mod_selfselectadvanced\table\guiderequests_table::rows_for($activity, $api, (int) $USER->id, $kindfilter);
    if ($rows) {
        $table = new \mod_selfselectadvanced\table\guiderequests_table('ssaguiderequests', $filterurl, $download);
        $table->display_rows($rows, $perpage);
    } else {
        echo html_writer::div(get_string('guidequeueempty', 'mod_selfselectadvanced'), 'alert alert-info');
    }
} else {
    // What this guide has asked for, and the form to ask for more.
    $load = \mod_selfselectadvanced\local\guides::with_load($activity, $resolver, true)[(int) $USER->id] ?? null;
    $ceiling = $resolver->guide_capacity_ceiling((int) $USER->id);
    echo html_writer::div(
        get_string('guidecapcurrent', 'mod_selfselectadvanced', (object) [
            'used' => $load ? (int) $load->used : 0,
            'max' => $ceiling->value,
        ]),
        'alert alert-info'
    );

    // BOTH capacity types: the raise and the reduction share one live
    // slot (the service's duplicate guard spans them), so the history
    // and the pending banner must see them as one family.
    $mine = $DB->get_records_select(
        'selfselectadvanced_ticket',
        'activityid = :activityid AND requestedby = :requestedby AND type IN (:cap, :reduce)',
        [
            'activityid' => $activity->id(),
            'requestedby' => (int) $USER->id,
            'cap' => tickets::TYPE_GUIDECAP,
            'reduce' => tickets::TYPE_GUIDEREDUCE,
        ],
        'timecreated DESC'
    );
    $live = null;
    foreach ($mine as $ticket) {
        if (in_array($ticket->status, [tickets::STATUS_OPEN, tickets::STATUS_CLAIMED], true)) {
            $live = $ticket;
            break;
        }
    }

    if ($mine) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable selfselectadvanced-guidecaps';
        $table->head = [
            get_string('guidecapasked', 'mod_selfselectadvanced'),
            get_string('reason', 'mod_selfselectadvanced'),
            get_string('status'),
            get_string('guidecapoutcome', 'mod_selfselectadvanced'),
            get_string('date'),
            get_string('actions'),
        ];
        foreach ($mine as $ticket) {
            $table->data[] = [
                (int) $ticket->requested,
                s(shorten_text((string) $ticket->request, 120)),
                get_string('ticketstatus' . $ticket->status, 'mod_selfselectadvanced'),
                s(shorten_text(trim(html_to_text((string) ($ticket->resolution ?? ''))), 120)),
                userdate((int) $ticket->timecreated, get_string('strftimedatetimeshort')),
                // Slice B2 (deliverable 2): every row links to its
                // thread - the conversation (the eventual grant/decline
                // note, any needs-info question) lives there now.
                html_writer::link(
                    new moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticket->id]),
                    get_string('ticketthreadview', 'mod_selfselectadvanced'),
                    ['class' => 'btn btn-outline-secondary btn-sm']
                ),
            ];
        }
        echo html_writer::table($table);
    }

    if ($live) {
        echo html_writer::start_div('alert alert-warning d-flex flex-wrap gap-2 align-items-center');
        echo html_writer::span(get_string(
            $live->type === tickets::TYPE_GUIDEREDUCE ? 'guidereducepending' : 'guidecappending',
            'mod_selfselectadvanced',
            (int) $live->requested
        ));
        if ($live->status === tickets::STATUS_OPEN) {
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'd-inline']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'withdrawcap']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 't', 'value' => $live->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
                'value' => get_string('guidecapwithdraw', 'mod_selfselectadvanced')]);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_div();
    } else {
        echo $OUTPUT->heading(get_string('guidecapask', 'mod_selfselectadvanced'), 4);
        echo html_writer::tag('p', get_string('guidecapexplain', 'mod_selfselectadvanced'), ['class' => 'text-muted']);
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'askcap']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::start_div('mb-2');
        echo html_writer::label(
            get_string('guidecapasked', 'mod_selfselectadvanced'),
            'ssa-requested',
            true,
            ['class' => 'd-block']
        );
        echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'requested', 'id' => 'ssa-requested',
            'min' => $ceiling->value + 1, 'value' => $ceiling->value + 1, 'class' => 'form-control w-auto']);
        echo html_writer::end_div();
        echo html_writer::start_div('mb-2');
        echo html_writer::label(get_string('reason', 'mod_selfselectadvanced'), 'ssa-reason', true, ['class' => 'd-block']);
        // A <textarea>, not <input type=text>: slice A (multi-line
        // rich-ish requests, FORMAT_MOODLE storage). html_writer::tag()
        // rather than empty_tag() - a textarea needs a closing tag.
        echo html_writer::tag('textarea', '', ['name' => 'reason', 'id' => 'ssa-reason',
            'rows' => 3, 'class' => 'form-control']);
        echo html_writer::end_div();
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
            'value' => get_string('guidecapsend', 'mod_selfselectadvanced')]);
        echo html_writer::end_tag('form');

        // Flow (d): the downward ask. Only drawn when there is room to
        // go down at all, and 0 is a legal ask - it means "relieve me
        // once my teams are rehomed".
        if ($ceiling->value > 0) {
            echo $OUTPUT->heading(get_string('guidereduceask', 'mod_selfselectadvanced'), 4, 'mt-4');
            echo html_writer::tag('p', get_string('guidereduceexplain', 'mod_selfselectadvanced'), ['class' => 'text-muted']);
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'askreduce']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::start_div('mb-2');
            echo html_writer::label(
                get_string('guidereduceasked', 'mod_selfselectadvanced'),
                'ssa-reduce-requested',
                true,
                ['class' => 'd-block']
            );
            echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'requested',
                'id' => 'ssa-reduce-requested', 'min' => 0, 'max' => $ceiling->value - 1,
                'value' => max(0, $ceiling->value - 1), 'class' => 'form-control w-auto']);
            echo html_writer::end_div();
            echo html_writer::start_div('mb-2');
            echo html_writer::label(
                get_string('guidereducereason', 'mod_selfselectadvanced'),
                'ssa-reduce-reason',
                true,
                ['class' => 'd-block']
            );
            // A <textarea>, not <input type=text>: slice A (multi-line
            // rich-ish requests, FORMAT_MOODLE storage). html_writer::tag()
            // rather than empty_tag() - a textarea needs a closing tag.
            echo html_writer::tag('textarea', '', ['name' => 'reason', 'id' => 'ssa-reduce-reason',
                'rows' => 3, 'class' => 'form-control',
                'placeholder' => get_string('guidereducereasonhint', 'mod_selfselectadvanced')]);
            echo html_writer::end_div();
            echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-primary',
                'value' => get_string('guidereducesend', 'mod_selfselectadvanced')]);
            echo html_writer::end_tag('form');
        }
    }
}

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);
echo $OUTPUT->footer();
