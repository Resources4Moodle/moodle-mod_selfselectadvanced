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
 * GET renders; claim and release (a manager unsticking someone else's
 * claim) are sesskey-protected POSTs here. Resolve, decline, the
 * guidecap grant and everything else that is a CONVERSATION rather than
 * triage moved to the ticket's own thread (ticket.php) in slice B2 -
 * every row below links there.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\tickets;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// The triage filter (slice C2). Whitelisted here, against the page's
// own copy of the known types/statuses, because this IS user input -
// unlike the service layer's validate_type_filter()/validate_status_filter(),
// which exist to catch a CALLER bug and throw for one, an unrecognised
// value arriving on the querystring is just dropped back to "no filter"
// silently, the same way $tab is handled in guidequeue.php.
$typefilter = optional_param('type', '', PARAM_ALPHA);
$knowntypes = [
    tickets::TYPE_COMPCHANGE,
    tickets::TYPE_UNFREEZE,
    tickets::TYPE_GUIDECAP,
    tickets::TYPE_GUIDEGONE,
    tickets::TYPE_GUIDEREDUCE,
    tickets::TYPE_DATES,
    tickets::TYPE_PENALTY,
    tickets::TYPE_LEADERCHANGE,
    tickets::TYPE_HELP,
];
if (!in_array($typefilter, $knowntypes, true)) {
    $typefilter = '';
}
$statusfilter = optional_param('status', '', PARAM_ALPHA);
$knownstatuses = [
    tickets::STATUS_OPEN,
    tickets::STATUS_CLAIMED,
    tickets::STATUS_RESOLVED,
    tickets::STATUS_DECLINED,
    tickets::STATUS_WITHDRAWN,
];
if (!in_array($statusfilter, $knownstatuses, true)) {
    $statusfilter = '';
}

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
$canmanage = has_capability('mod/selfselectadvanced:manage', $context);
if (!$canmanage) {
    require_capability('mod/selfselectadvanced:coordinate', $context);
}

// The filter travels on $baseurl itself, not just the filter form's own
// querystring: every paging link, the "released back to the queue"
// forms and every post-action redirect below are all built from
// $baseurl, so the filter would otherwise vanish the moment the queue
// worker took any action at all.
$baseurl = new moodle_url('/mod/selfselectadvanced/tickets.php', ['id' => $cm->id]);
if ($typefilter !== '') {
    $baseurl->param('type', $typefilter);
}
if ($statusfilter !== '') {
    $baseurl->param('status', $statusfilter);
}
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
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Resolve, decline and the guidecap grant moved to the ticket's own
// thread (ticket.php) in slice B2 - conversation happens there now, and
// the queue keeps only the triage-speed actions: taking a ticket up, and
// (below) a manager putting someone else's stuck claim back in the
// queue. Release is a bare one-click state reset with no note of its
// own (close() only requires a resolution for the resolved/declined
// outcomes), so it stayed a queue-level action rather than moving with
// the two that need a form.
if ($action === 'release' && data_submitted() && confirm_sesskey()) {
    $ticketid = required_param('ticket', PARAM_INT);
    try {
        tickets::close($activity, $ticketid, tickets::STATUS_OPEN, '', FORMAT_PLAIN, (int) $USER->id);
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

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tickets', 'mod_selfselectadvanced'));
echo html_writer::div(get_string('ticketsintro', 'mod_selfselectadvanced'), 'alert alert-info');

// The triage filter form (slice C2), modelled on guidequeue.php's own
// kind filter: GET, a hidden id, one select per axis, and a submit -
// changing either select drops any existing page number, which is the
// right behaviour since the old page number belongs to a different,
// unfiltered list.
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
    'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::label(get_string('tickettype', 'mod_selfselectadvanced'), 'ssa-ticket-type', true, ['class' => 'me-2']);
$typeoptions = ['' => get_string('ticketfilterall', 'mod_selfselectadvanced')];
foreach ($knowntypes as $knowntype) {
    $typeoptions[$knowntype] = get_string('tickettype' . $knowntype, 'mod_selfselectadvanced');
}
echo html_writer::select(
    $typeoptions,
    'type',
    $typefilter,
    false,
    ['id' => 'ssa-ticket-type', 'class' => 'form-select form-select-sm w-auto me-2']
);
echo html_writer::label(get_string('ticketstatus', 'mod_selfselectadvanced'), 'ssa-ticket-status', true, ['class' => 'me-2']);
$statusoptions = ['' => get_string('ticketfilterallstatuses', 'mod_selfselectadvanced')];
foreach ($knownstatuses as $knownstatus) {
    $statusoptions[$knownstatus] = get_string('ticketstatus' . $knownstatus, 'mod_selfselectadvanced');
}
echo html_writer::select(
    $statusoptions,
    'status',
    $statusfilter,
    false,
    ['id' => 'ssa-ticket-status', 'class' => 'form-select form-select-sm w-auto me-2']
);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('ticketfilterapply', 'mod_selfselectadvanced'),
    'class' => 'btn btn-secondary btn-sm']);
echo html_writer::end_tag('form');

// Paged: resolved and declined tickets are never removed, so an
// activity's queue grows all semester and returning the whole of it was
// a page that got slower every week. The page size control is the same
// one every other table on this plugin uses.
$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$page = optional_param('page', 0, PARAM_INT);
$totaltickets = tickets::queue_count($activity, (int) $USER->id, $typefilter, $statusfilter);
$queue = tickets::queue($activity, (int) $USER->id, $page * $perpage, $perpage, $typefilter, $statusfilter);
if (!$queue) {
    echo html_writer::div(get_string('ticketsempty', 'mod_selfselectadvanced'));
    echo $OUTPUT->footer();
    die;
}
// A filtered view that silently showed page 1 of an unstated total
// would invite misreading it as the whole answer - so once a filter is
// narrowing the queue, the total the filter matched is stated plainly.
if ($typefilter !== '' || $statusfilter !== '') {
    echo html_writer::div(
        get_string('ticketfiltermatches', 'mod_selfselectadvanced', $totaltickets),
        'small text-muted mb-2'
    );
}

$groupnames = [];
$usernames = [];
$userids = [];
// Requesters of tickets THIS viewer currently holds claimed: the one
// connection that opens a requester's contact details to a coordinator
// (contact-privacy rule (c)). An open ticket sitting in the queue is
// not a connection, and neither is being eligible to decide one.
// needsinfo counts alongside claimed (decision 2, LIVENESS): the
// claimant is still the same connection while the ticket waits on the
// requester's answer, exactly as tickets.php's own $isworked check
// below treats the two statuses alike.
$claimedmine = [];
foreach ($queue as $ticket) {
    $userids[] = (int) $ticket->requestedby;
    if ($ticket->claimedby) {
        $userids[] = (int) $ticket->claimedby;
    }
    if (
        in_array($ticket->status, [tickets::STATUS_CLAIMED, tickets::STATUS_NEEDSINFO], true)
        && (int) $ticket->claimedby === (int) $USER->id
    ) {
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

$position = tickets::open_before($activity, (int) $USER->id, $page * $perpage, $typefilter, $statusfilter);
foreach ($queue as $ticket) {
    $isopen = $ticket->status === tickets::STATUS_OPEN;
    // Needsinfo counts alongside claimed for "is this viewer working
    // it" purposes (decision 2, LIVENESS): the claimant may still
    // resolve or decline from needsinfo, so the thread link and the
    // dimming below must treat the two statuses the same way.
    $isworked = in_array($ticket->status, [tickets::STATUS_CLAIMED, tickets::STATUS_NEEDSINFO], true);
    $mine = $isworked && (int) $ticket->claimedby === (int) $USER->id;
    $position += $isopen ? 1 : 0;

    $statuscell = get_string('ticketstatus' . $ticket->status, 'mod_selfselectadvanced');
    if ($isworked) {
        $statuscell .= ' — ' . ($usernames[(int) $ticket->claimedby] ?? $ticket->claimedby);
    }
    // 1.20.44: the escalated badge - independent of status, so it is
    // appended rather than folded into the status label above (an
    // escalated ticket can be open, claimed or needsinfo).
    if ((int) $ticket->escalated === 1) {
        $statuscell .= ' ' . html_writer::span(
            get_string('ticketescalatebadge', 'mod_selfselectadvanced'),
            'badge bg-danger'
        );
    }
    if (
        in_array($ticket->status, [tickets::STATUS_RESOLVED, tickets::STATUS_DECLINED], true)
        && trim((string) $ticket->resolution) !== ''
    ) {
        // Rendered via format_text(), not s(): the resolution is stored
        // as FORMAT_MOODLE (a hand-rolled textarea, nl2br + auto-links +
        // filters at render - the form now lives on ticket.php, slice
        // B2), and the stored format travels with the row.
        $statuscell .= html_writer::div(
            format_text((string) $ticket->resolution, (int) $ticket->resolutionformat, ['context' => $context]),
            'small text-muted'
        );
    }

    // Slice B2 (deliverable 2): every row links to its thread now -
    // conversation, and for a claimed/needsinfo ticket the resolve,
    // decline and request-info forms, all live on ticket.php.
    $threadurl = new moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $ticket->id]);

    $actions = '';
    if ($mine) {
        // Resolve/decline/the guidecap grant all live on the thread now
        // - this row just points there, and that IS this row's link to
        // its thread (deliverable 2), not a second one alongside it.
        $actions = html_writer::link($threadurl, get_string('ticketthreadopen', 'mod_selfselectadvanced'), [
            'class' => 'btn btn-primary btn-sm',
        ]);
    } else {
        if ($isopen) {
            // The Claim control asks the gate claim() enforces (seam
            // audit B6, 1.20.20): an involved narrow-authority
            // coordinator used to be offered a button whose only
            // outcome was the COI refusal. require_uninvolved() embodies
            // decision 65 - :manage holders pass at once, involvement
            // refuses the rest.
            // 1.20.44: while escalated, Take up is not a coordinator's -
            // checked first, ahead of the conflict-of-interest arm below,
            // exactly as ticket_page.php's export_actionbox() orders the
            // same two checks. Enforced for real in tickets::claim();
            // this is only the UI hiding what the service would refuse.
            $claimrefusal = '';
            if (
                (int) $ticket->escalated === 1
                && !$canmanage
            ) {
                $claimrefusal = get_string('refusalticketescalated', 'mod_selfselectadvanced');
            } else if ((int) $ticket->groupid) {
                $tgroup = $DB->get_record('selfselectadvanced_group', ['id' => (int) $ticket->groupid]);
                if ($tgroup) {
                    try {
                        \mod_selfselectadvanced\local\tickets::require_uninvolved($activity, $tgroup, (int) $USER->id);
                    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
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
        } else if ($isworked && $canmanage) {
            // Somebody else is holding this one. A manager can put it
            // back in the queue: without this door, a claim by a
            // coordinator who has since left the course would sit there
            // for ever, and the team could never file that kind of
            // request again because the live ticket blocks duplicates.
            $actions = html_writer::start_tag('form', ['method' => 'post',
                'action' => new moodle_url($baseurl, ['action' => 'release'])])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ticket', 'value' => $ticket->id])
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
                    'value' => get_string('ticketforcerelease', 'mod_selfselectadvanced')])
                . html_writer::end_tag('form');
        }
        // Every OTHER row (open, claimed/needsinfo by someone else with
        // no force-release on offer, or closed/withdrawn) still links to
        // its thread (deliverable 2: "each row links to its thread
        // page") - closed tickets are history worth reading, not a dead
        // end.
        $actions .= html_writer::link($threadurl, get_string('ticketthreadview', 'mod_selfselectadvanced'), [
            'class' => 'btn btn-outline-secondary btn-sm ms-1',
        ]);
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
    if ($isworked && !$mine) {
        $row->attributes['class'] = 'dimmed_text';
    }
    $table->data[] = $row;
}
echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($baseurl), 'mb-3');
echo html_writer::table($table);
echo $OUTPUT->paging_bar($totaltickets, $page, $perpage, $baseurl);
echo $OUTPUT->footer();
