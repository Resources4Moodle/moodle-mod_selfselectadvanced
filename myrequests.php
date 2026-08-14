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

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();

$baseurl = new moodle_url('/mod/selfselectadvanced/myrequests.php', ['id' => $cm->id]);
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

$perpage = \mod_selfselectadvanced\local\perpage::current(25);
$total = tickets::mine_count($activity, (int) $USER->id);
$mine = tickets::mine($activity, (int) $USER->id, $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('myrequests', 'mod_selfselectadvanced'));

if (!$mine) {
    // Not a refusal. Somebody who has asked for nothing has nothing
    // here, and saying so plainly beats an empty table.
    echo html_writer::div(get_string('myrequestsnone', 'mod_selfselectadvanced'), 'alert alert-info');
} else {
    echo html_writer::tag('p', get_string('myrequestsintro', 'mod_selfselectadvanced'), ['class' => 'text-muted']);

    $table = new html_table();
    $table->attributes['class'] = 'generaltable selfselectadvanced-myrequests';
    $table->head = [
        get_string('ticketsubject', 'mod_selfselectadvanced'),
        get_string('tickettype', 'mod_selfselectadvanced'),
        get_string('ticketrequest', 'mod_selfselectadvanced'),
        get_string('ticketstatus', 'mod_selfselectadvanced'),
        get_string('date'),
        get_string('actions'),
    ];

    foreach ($mine as $ticket) {
        $subject = $ticket->groupname !== null
            ? format_string($ticket->groupname) . ' (' . s($ticket->grouppluginuid) . ')'
            : get_string('tickethasnoteam', 'mod_selfselectadvanced');

        // The status cell carries the answer. A closed request whose
        // note is empty says so rather than showing a blank, because a
        // blank reads as "nothing was said" when the truth may be
        // "something was said and this page lost it".
        $statuscell = get_string('ticketstatus' . $ticket->status, 'mod_selfselectadvanced');
        if (in_array($ticket->status, [tickets::STATUS_RESOLVED, tickets::STATUS_DECLINED], true)) {
            $note = trim((string) ($ticket->resolution ?? ''));
            $statuscell .= html_writer::div(
                $note === ''
                    ? get_string('myrequestsnonote', 'mod_selfselectadvanced')
                    : format_text($note, (int) $ticket->resolutionformat, ['context' => $context]),
                'small text-muted selfselectadvanced-myrequests-note'
            );
        }

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
        } else if ($ticket->status === tickets::STATUS_CLAIMED) {
            // Why no control here: once somebody has taken it up it is
            // their work in progress. Saying that is better than an
            // empty cell the reader has to interpret.
            $actions = html_writer::span(
                get_string('myrequestsclaimedhint', 'mod_selfselectadvanced'),
                'small text-muted'
            );
        }

        $table->data[] = [
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
