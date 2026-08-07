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
 * composition-change, unfreeze and team-limit requests, first come
 * first served.
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

if ($action === 'grant' && data_submitted() && confirm_sesskey()) {
    $ticketid = required_param('ticket', PARAM_INT);
    $note = optional_param('resolution', '', PARAM_RAW);
    try {
        tickets::grant_guidecap($activity, $ticketid, $note, FORMAT_PLAIN, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('guidecapgranted', 'mod_selfselectadvanced'),
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

// Paged: resolved and declined tickets are never removed, so an
// activity's queue grows all semester and returning the whole of it was
// a page that got slower every week. The page size control is the same
// one every other table on this plugin uses.
$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$page = optional_param('page', 0, PARAM_INT);
$totaltickets = tickets::queue_count($activity, (int) $USER->id);
$queue = tickets::queue($activity, (int) $USER->id, $page * $perpage, $perpage);
if (!$queue) {
    echo html_writer::div(get_string('ticketsempty', 'mod_selfselectadvanced'));
    echo $OUTPUT->footer();
    die;
}

$groupnames = [];
$usernames = [];
$userids = [];
// Requesters of tickets THIS viewer currently holds claimed: the one
// connection that opens a requester's contact details to a coordinator
// (contact-privacy rule (c)). An open ticket sitting in the queue is
// not a connection, and neither is being eligible to decide one.
$claimedmine = [];
foreach ($queue as $ticket) {
    $userids[] = (int) $ticket->requestedby;
    if ($ticket->claimedby) {
        $userids[] = (int) $ticket->claimedby;
    }
    if ($ticket->status === tickets::STATUS_CLAIMED && (int) $ticket->claimedby === (int) $USER->id) {
        $claimedmine[] = (int) $ticket->requestedby;
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
// Mobile only, never an address (decision 17), and only for the
// requesters this viewer is actually holding a claim on. Page-bounded.
$requestercontact = tickets::requester_contact_map($activity, (int) $USER->id, $claimedmine);
$messagemap = \mod_selfselectadvanced\local\staffmessage::may_message_map(
    $activity,
    (int) $USER->id,
    $claimedmine
);
// The team name arrives with the ticket now. This used to load EVERY
// group in the activity - fifteen hundred rows to label one screen.
foreach ($queue as $ticket) {
    if ((int) $ticket->groupid > 0 && $ticket->groupname !== null) {
        $groupnames[(int) $ticket->groupid] =
            format_string($ticket->groupname) . ' (' . $ticket->grouppluginuid . ')';
    }
}

$table = new html_table();
$table->attributes['class'] = 'generaltable selfselectadvanced-tickets';
$table->head = [
    get_string('ticketqueuepos', 'mod_selfselectadvanced'),
    // Not "group name": a team-limit request is about a guide and the
    // number they are asking for, and has no team at all.
    get_string('ticketsubject', 'mod_selfselectadvanced'),
    get_string('tickettype', 'mod_selfselectadvanced'),
    get_string('ticketrequest', 'mod_selfselectadvanced'),
    get_string('ticketstatus', 'mod_selfselectadvanced'),
    get_string('actions'),
];

// What a claimant may see of the person who filed the ticket they are
// holding: a consented mobile, and a way to write to them. Never an
// address, never a mailto:, never a wa.me link - the claimant reaches
// the requester through Moodle messaging like everybody else.
$requesterline = static function (int $requesterid, bool $mine) use ($requestercontact, $messagemap, $activity, $baseurl): string {
    if (!$mine) {
        return '';
    }
    $line = '';
    if (!empty($requestercontact[$requesterid]->mobile)) {
        $line .= ' · ' . s($requestercontact[$requesterid]->mobile);
    }
    if (!empty($messagemap[$requesterid])) {
        $line .= ' · ' . \mod_selfselectadvanced\local\staffmessage::link($activity, $requesterid, $baseurl, '');
    }

    return $line;
};

$position = tickets::open_before($activity, (int) $USER->id, $page * $perpage);
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
        // The Claim control asks the gate claim() enforces (seam audit
        // B6, 1.20.20): an involved narrow-authority coordinator used
        // to be offered a button whose only outcome was the COI
        // refusal. require_uninvolved() embodies decision 65 - :manage
        // holders pass at once, involvement refuses the rest.
        $claimrefusal = '';
        if ((int) $ticket->groupid) {
            $tgroup = $DB->get_record('selfselectadvanced_group', ['id' => (int) $ticket->groupid]);
            if ($tgroup) {
                try {
                    \mod_selfselectadvanced\local\tickets::require_uninvolved($activity, $tgroup, (int) $USER->id);
                } catch (\moodle_exception $e) {
                    $claimrefusal = $e->getMessage();
                }
            }
        }
        if ($claimrefusal !== '') {
            $actions = html_writer::tag('button', get_string('ticketclaim', 'mod_selfselectadvanced'), [
                'type' => 'button',
                'class' => 'btn btn-secondary btn-sm',
                'disabled' => 'disabled',
                'title' => $claimrefusal,
            ]) . html_writer::span(s($claimrefusal), 'small text-muted ms-1');
        } else {
            $actions = html_writer::start_tag('form', ['method' => 'post',
                'action' => new moodle_url($baseurl, ['action' => 'claim'])])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ticket', 'value' => $ticket->id])
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                    'value' => get_string('ticketclaim', 'mod_selfselectadvanced')])
                . html_writer::end_tag('form');
        }
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
        if ($ticket->type === tickets::TYPE_GUIDECAP) {
            $actions = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ticket', 'value' => $ticket->id])
                . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'resolution', 'size' => 24,
                    'placeholder' => get_string('ticketresolutionhint', 'mod_selfselectadvanced')])
                . ' '
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-success btn-sm',
                    'formaction' => (new moodle_url($baseurl, ['action' => 'grant']))->out(false),
                    'value' => get_string('guidecapgrant', 'mod_selfselectadvanced', (int) $ticket->requested)])
                . ' '
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-warning btn-sm',
                    'formaction' => (new moodle_url($baseurl, ['action' => 'decline']))->out(false),
                    'value' => get_string('ticketdecline', 'mod_selfselectadvanced')])
                . ' '
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary btn-sm',
                    'formaction' => (new moodle_url($baseurl, ['action' => 'release']))->out(false),
                    'value' => get_string('ticketrelease', 'mod_selfselectadvanced')])
                . html_writer::end_tag('form');
        } else if ($ticket->groupid) {
            // Group-typed tickets only: a guidereduce ticket is about
            // the GUIDE and carries no group, and a link to
            // group.php?g= (nothing) would be a broken control.
            $actions .= html_writer::link(
                new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $ticket->groupid]),
                get_string('view'),
                ['class' => 'btn btn-secondary btn-sm mt-1']
            );
        }
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

    if ($ticket->type === tickets::TYPE_GUIDECAP) {
        $subject = get_string('guidecapsubject', 'mod_selfselectadvanced', (object) [
            'guide' => $usernames[(int) $ticket->requestedby] ?? '',
            'requested' => (int) $ticket->requested,
        ]);
    } else if ($ticket->type === tickets::TYPE_GUIDEREDUCE) {
        $subject = get_string(
            (int) $ticket->requested === 0 ? 'guidereducesubjectzero' : 'guidereducesubject',
            'mod_selfselectadvanced',
            (object) [
                'guide' => $usernames[(int) $ticket->requestedby] ?? '',
                'requested' => (int) $ticket->requested,
            ]
        );
    } else {
        $subject = $groupnames[(int) $ticket->groupid] ?? $ticket->groupid;
    }

    $row = new html_table_row([
        $isopen ? $position : '',
        $subject,
        get_string('tickettype' . $ticket->type, 'mod_selfselectadvanced')
            . html_writer::div(
                ($usernames[(int) $ticket->requestedby] ?? '') . ' · '
                . userdate($ticket->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                . $requesterline((int) $ticket->requestedby, $mine),
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
echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($baseurl), 'mb-3');
echo html_writer::table($table);
echo $OUTPUT->paging_bar($totaltickets, $page, $perpage, $baseurl);
echo $OUTPUT->footer();
