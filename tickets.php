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
 * The sequential request queue (strategy 1.16 B): guide
 * composition-change and unfreeze requests, first come first served.
 * One worker claims a ticket exclusively; a racing second claimant is
 * refused and told who holds it.
 *
 * GET renders; claim, release, resolve and decline are
 * sesskey-protected POSTs.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\tickets;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
$canmanage = has_capability('mod/selfselectadvanced:manage', $context);
if (!$canmanage) {
    require_capability('mod/selfselectadvanced:coordinate', $context);
}

$baseurl = new moodle_url('/mod/selfselectadvanced/tickets.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'claim' && data_submitted() && confirm_sesskey()) {
    $ticketid = required_param('ticket', PARAM_INT);
    try {
        tickets::claim($activity, $ticketid, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketclaimednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if (in_array($action, ['resolve', 'decline', 'release'], true) && data_submitted() && confirm_sesskey()) {
    $ticketid = required_param('ticket', PARAM_INT);
    $note = optional_param('resolution', '', PARAM_RAW);
    $outcome = [
        'resolve' => tickets::STATUS_RESOLVED,
        'decline' => tickets::STATUS_DECLINED,
        'release' => tickets::STATUS_OPEN,
    ][$action];
    try {
        tickets::close($activity, $ticketid, $outcome, $note, FORMAT_PLAIN, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketclosednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tickets', 'mod_selfselectadvanced'));
echo html_writer::div(get_string('ticketsintro', 'mod_selfselectadvanced'), 'alert alert-info');

$queue = tickets::queue($activity);
if (!$queue) {
    echo html_writer::div(get_string('ticketsempty', 'mod_selfselectadvanced'));
    echo $OUTPUT->footer();
    die;
}

$groupnames = [];
$usernames = [];
$userids = [];
foreach ($queue as $ticket) {
    $userids[] = (int) $ticket->requestedby;
    if ($ticket->claimedby) {
        $userids[] = (int) $ticket->claimedby;
    }
}
// Ask get_sql() for a leading comma of its own: without it the last
// selected column and the first name field fuse into one identifier.
$namefields = \core_user\fields::for_name()->get_sql('', false, '', '', true)->selects;
foreach (array_chunk(array_unique($userids), 1000) as $chunk) {
    [$insql, $params] = $DB->get_in_or_equal($chunk);
    foreach ($DB->get_records_sql("SELECT id{$namefields} FROM {user} WHERE id $insql", $params) as $u) {
        $usernames[(int) $u->id] = fullname($u);
    }
}
foreach ($DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()], '', 'id, name, pluginuid') as $g) {
    $groupnames[(int) $g->id] = format_string($g->name) . ' (' . $g->pluginuid . ')';
}

$table = new html_table();
$table->attributes['class'] = 'generaltable selfselectadvanced-tickets';
$table->head = [
    get_string('ticketqueuepos', 'mod_selfselectadvanced'),
    get_string('groupname', 'mod_selfselectadvanced'),
    get_string('tickettype', 'mod_selfselectadvanced'),
    get_string('ticketrequest', 'mod_selfselectadvanced'),
    get_string('ticketstatus', 'mod_selfselectadvanced'),
    get_string('actions'),
];

$position = 0;
foreach ($queue as $ticket) {
    $isopen = $ticket->status === tickets::STATUS_OPEN;
    $isclaimed = $ticket->status === tickets::STATUS_CLAIMED;
    $mine = $isclaimed && (int) $ticket->claimedby === (int) $USER->id;
    $position += $isopen ? 1 : 0;

    $statuscell = get_string('ticketstatus' . $ticket->status, 'mod_selfselectadvanced');
    if ($isclaimed) {
        $statuscell .= ' — ' . ($usernames[(int) $ticket->claimedby] ?? $ticket->claimedby);
    }
    if (
        in_array($ticket->status, [tickets::STATUS_RESOLVED, tickets::STATUS_DECLINED], true)
        && trim((string) $ticket->resolution) !== ''
    ) {
        $statuscell .= html_writer::div(s($ticket->resolution), 'small text-muted');
    }

    $actions = '';
    if ($isopen) {
        $actions = html_writer::start_tag('form', ['method' => 'post',
            'action' => new moodle_url($baseurl, ['action' => 'claim'])])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ticket', 'value' => $ticket->id])
            . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                'value' => get_string('ticketclaim', 'mod_selfselectadvanced')])
            . html_writer::end_tag('form');
    } else if ($mine) {
        $actions = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ticket', 'value' => $ticket->id])
            . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'resolution', 'size' => 24,
                'placeholder' => get_string('ticketresolutionhint', 'mod_selfselectadvanced')])
            . ' '
            . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-success btn-sm',
                'formaction' => (new moodle_url($baseurl, ['action' => 'resolve']))->out(false),
                'value' => get_string('ticketresolve', 'mod_selfselectadvanced')])
            . ' '
            . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-warning btn-sm',
                'formaction' => (new moodle_url($baseurl, ['action' => 'decline']))->out(false),
                'value' => get_string('ticketdecline', 'mod_selfselectadvanced')])
            . ' '
            . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary btn-sm',
                'formaction' => (new moodle_url($baseurl, ['action' => 'release']))->out(false),
                'value' => get_string('ticketrelease', 'mod_selfselectadvanced')])
            . html_writer::end_tag('form');
        $actions .= html_writer::link(
            new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $ticket->groupid]),
            get_string('view'),
            ['class' => 'btn btn-secondary btn-sm mt-1']
        );
    } else if ($isclaimed && $canmanage) {
        // Somebody else is holding this one. A manager can put it back
        // in the queue: without this door, a claim by a coordinator who
        // has since left the course would sit there for ever, and the
        // team could never file that kind of request again because the
        // live ticket blocks duplicates.
        $actions = html_writer::start_tag('form', ['method' => 'post',
            'action' => new moodle_url($baseurl, ['action' => 'release'])])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ticket', 'value' => $ticket->id])
            . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
                'value' => get_string('ticketforcerelease', 'mod_selfselectadvanced')])
            . html_writer::end_tag('form');
    }

    $row = new html_table_row([
        $isopen ? $position : '',
        $groupnames[(int) $ticket->groupid] ?? $ticket->groupid,
        get_string('tickettype' . $ticket->type, 'mod_selfselectadvanced')
            . html_writer::div(
                ($usernames[(int) $ticket->requestedby] ?? '') . ' · '
                . userdate($ticket->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'small text-muted'
            ),
        format_text($ticket->request, $ticket->requestformat, ['context' => $context]),
        $statuscell,
        $actions,
    ]);
    if ($isclaimed && !$mine) {
        $row->attributes['class'] = 'dimmed_text';
    }
    $table->data[] = $row;
}
echo html_writer::table($table);
echo $OUTPUT->footer();
