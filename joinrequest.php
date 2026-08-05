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
 * Asking to join another team, and answering those asks
 * (strategy 1.19 B).
 *
 * A page of its own rather than more actions on the team page, which
 * already carries a dozen: the same decision taken for the approach
 * workflow in 1.17, and for the same reason.
 *
 * Two tabs, because there are two sides. A student asks and watches
 * what they asked for; a leader answers what has been asked of their
 * team. A coordinator or manager sees, and may answer, every request in
 * the activity - the escape hatch for an absent leader.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'ask', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
$isstaff = has_capability('mod/selfselectadvanced:manage', $context)
    || has_capability('mod/selfselectadvanced:coordinate', $context);

// Three audiences reach this page: a student asking, a leader
// answering, and a coordinator answering for an absent leader. The
// first two hold :respond; a coordinator does not - it is the students'
// capability - so gating on it alone shut the very people the design
// names as the escape hatch out of their own page.
//
// The door is authority::require_join_requests() and not a copy of it:
// the landing page's "Joining another team" button asks the may_ half
// of that same function (1.20.6 item A, review finding NAV-02), and the
// whole point of moving the question into authority is that the button
// and this door can no longer be changed apart.
\mod_selfselectadvanced\local\authority::require_join_requests($activity, (int) $USER->id);

// WHAT EACH ENTRANT CAN ACTUALLY DO HERE (review finding NAV-03).
// Until 1.20.6 this page defaulted every entrant to the Ask tab and
// built the Ask form for all of them, including the manager and the
// coordinator it had just deliberately let in without :respond - and
// joinrequests::request() requires :respond unconditionally, so their
// default view was a form whose only possible outcome was a refusal.
//
// Asking is :respond, exactly as the service demands it. Answering is
// joinrequests::require_decider()'s question - the target team's own
// leader, or staff acting for an absent one - so a viewer who leads no
// team here and holds no staff capability has nothing to answer.
// Everyone admitted by the door above satisfies at least one of the
// two, so the tab strip below is never empty.
$canask = has_capability('mod/selfselectadvanced:respond', $context);
$cananswer = $isstaff || $DB->record_exists('selfselectadvanced_group', [
    'activityid' => $activity->id(),
    'leaderid' => (int) $USER->id,
]);

$availabletabs = array_values(array_filter([
    $canask ? 'ask' : null,
    $cananswer ? 'answer' : null,
]));
if (!in_array($tab, $availabletabs, true)) {
    // Staff-only entrants land on Answer, which is the side of the
    // page they were admitted for.
    $tab = reset($availabletabs);
}
$baseurl = new moodle_url('/mod/selfselectadvanced/joinrequest.php', ['id' => $cm->id]);
$PAGE->set_url(new moodle_url($baseurl, ['tab' => $tab]));
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$mycurrent = groups::get_groups_of_user($activity, (int) $USER->id);
// The Ask form is not built for an audience the service refuses: a
// form that can only ever error is worse than no form, and it also
// spared this page a resolver read and a moodleform construction for
// every staff viewer.
$askform = null;
if ($canask) {
    $mycap = (new \mod_selfselectadvanced\local\override\resolver($activity))
        ->effective_maxmembership((int) $USER->id)->value;
    $askform = new \mod_selfselectadvanced\form\joinrequest_form($baseurl->out(false), [
        'cmid' => $cm->id,
        'sources' => $mycurrent,
        'headroom' => count($mycurrent) < $mycap,
    ]);
}

if ($action === 'ask' && $askform !== null && ($data = $askform->get_data())) {
    // Zero is the placeholder and "no element rendered"; both mean the
    // student stated nothing, which the service resolves or refuses.
    $chosen = (int) ($data->source ?? 0);
    try {
        joinrequests::request(
            $activity,
            (int) $data->target,
            (string) $data->reason,
            (int) $USER->id,
            $chosen === 0 ? null : $chosen
        );
        redirect(
            new moodle_url($baseurl, ['tab' => 'ask']),
            get_string('joinsent', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'ask']),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

if ($action === 'withdraw' && data_submitted() && confirm_sesskey()) {
    $requestid = required_param('r', PARAM_INT);
    try {
        joinrequests::withdraw($activity, $requestid, (int) $USER->id);
        redirect(
            new moodle_url($baseurl, ['tab' => 'ask']),
            get_string('joinwithdrawn', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'ask']),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

if (in_array($action, ['accept', 'decline'], true) && data_submitted() && confirm_sesskey()) {
    $requestid = required_param('r', PARAM_INT);
    $note = trim(optional_param('note', '', PARAM_TEXT));
    // Decision 6: the codes are re-checked against the legal five AND
    // against the ACTOR's capability inside respond(), so a crafted
    // post from the team's own student leader is refused server-side.
    $bypass = optional_param_array('bypass', [], PARAM_ALPHANUM);
    $confirmaccept = optional_param('confirmaccept', 0, PARAM_BOOL);
    try {
        joinrequests::respond(
            $activity,
            $requestid,
            $action === 'accept',
            $note,
            (int) $USER->id,
            $bypass,
            (bool) $confirmaccept
        );
        redirect(
            new moodle_url($baseurl, ['tab' => 'answer']),
            get_string($action === 'accept' ? 'joinaccepted' : 'joindeclined', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'answer']),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('joinheading', 'mod_selfselectadvanced'));

$tabs = [];
foreach (['ask' => 'jointabask', 'answer' => 'jointabanswer'] as $key => $label) {
    if (!in_array($key, $availabletabs, true)) {
        continue;
    }
    $tabs[] = new tabobject(
        $key,
        new moodle_url($baseurl, ['tab' => $key]),
        get_string($label, 'mod_selfselectadvanced')
    );
}
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'ask') {
    $mine = joinrequests::mine($activity, (int) $USER->id);
    $live = null;
    foreach ($mine as $request) {
        if ($request->status === joinrequests::STATUS_REQUESTED) {
            $live = $request;
            break;
        }
    }

    if (!$mycurrent) {
        $bannertext = get_string('joinnoteam', 'mod_selfselectadvanced');
    } else if (count($mycurrent) === 1) {
        $bannertext = get_string('joincurrent', 'mod_selfselectadvanced', format_string(reset($mycurrent)->name));
    } else {
        $bannertext = get_string('joincurrentmany', 'mod_selfselectadvanced', implode(', ', array_map(
            static fn($group) => format_string($group->name),
            $mycurrent
        )));
    }
    echo html_writer::div($bannertext, 'alert alert-info');

    // WHAT THE LEADER WILL SEE OF YOU (maintainer decision 53: "Will
    // adding the student's department and sub-department be a major
    // change? This will be your design fix"). The same two COMPOSITION
    // attributes the answering side is shown, read through the same
    // accessor the leader panel on group.php uses, so the asker is told
    // exactly what the decider reads about them and nothing is
    // disclosed here that is not disclosed there. No contact field is
    // read on this page, for any viewer, in either state of the
    // contact-privacy switch.
    $myattr = \mod_selfselectadvanced\local\attributes\manager::get_for_users([(int) $USER->id])[(int) $USER->id]
        ?? null;
    $mydept = trim((string) ($myattr->department ?? ''));
    $mysubdept = trim((string) ($myattr->subdepartment ?? ''));
    $mylines = [html_writer::tag('p', get_string('joinyourcomposition', 'mod_selfselectadvanced'), [
        'class' => 'text-muted small mb-1',
    ])];
    if ($mydept === '' && $mysubdept === '') {
        $mylines[] = html_writer::div(get_string('attrsmissing', 'mod_selfselectadvanced'), 'small text-muted');
    } else {
        $parts = [];
        if ($mydept !== '') {
            $parts[] = get_string('attrdepartment', 'mod_selfselectadvanced') . ': ' . s($mydept);
        }
        if ($mysubdept !== '') {
            $parts[] = get_string('attrsubdepartment', 'mod_selfselectadvanced') . ': ' . s($mysubdept);
        }
        $mylines[] = html_writer::div(implode(' &middot; ', $parts), 'small');
    }
    echo html_writer::div(implode('', $mylines), 'selfselectadvanced-joinmycomposition mb-3');

    $targetnames = [];
    if ($mine) {
        // One batched lookup for the source names of the rows already
        // loaded: no groups::get() inside the loop, no N+1.
        $sourceids = [];
        $targetids = [];
        foreach ($mine as $request) {
            if ($request->sourcegroupid) {
                $sourceids[(int) $request->sourcegroupid] = true;
            }
            $targetids[(int) $request->targetgroupid] = true;
        }
        $sourcenames = $sourceids
            ? $DB->get_records_list('selfselectadvanced_group', 'id', array_keys($sourceids), '', 'id, name')
            : [];
        // The TARGET names are batched for the same reason, and read
        // WITHOUT groups::get(): this list is history, and a team it
        // names can have been dissolved or deleted since. MUST_EXIST
        // here made a student's own request list throw the moment any
        // team they had ever asked to join went away.
        $targetnames = $targetids
            ? $DB->get_records_list(
                'selfselectadvanced_group',
                'id',
                array_keys($targetids),
                '',
                'id, name, pluginuid, activityid'
            )
            : [];

        $table = new html_table();
        $table->attributes['class'] = 'generaltable selfselectadvanced-joinrequests';
        $table->head = [
            get_string('jointarget', 'mod_selfselectadvanced'),
            get_string('joinleavescolumn', 'mod_selfselectadvanced'),
            get_string('jointreason', 'mod_selfselectadvanced'),
            get_string('status'),
            get_string('joinanswer', 'mod_selfselectadvanced'),
            get_string('date'),
        ];
        foreach ($mine as $request) {
            $target = $targetnames[(int) $request->targetgroupid] ?? null;
            if ($target && (int) $target->activityid !== $activity->id()) {
                $target = null;
            }
            $table->data[] = [
                $target
                    ? format_string($target->name)
                        . ' ' . html_writer::span($target->pluginuid, 'text-muted small')
                    : get_string('groupdeletedlabel', 'mod_selfselectadvanced'),
                isset($sourcenames[(int) $request->sourcegroupid])
                    ? format_string($sourcenames[(int) $request->sourcegroupid]->name)
                    : get_string('joinleavesextra', 'mod_selfselectadvanced'),
                s(shorten_text((string) $request->reason, 90)),
                get_string('joinstatus' . $request->status, 'mod_selfselectadvanced'),
                s(shorten_text((string) ($request->responsenote ?? ''), 90)),
                userdate((int) $request->timecreated, get_string('strftimedatetimeshort')),
            ];
        }
        echo html_writer::table($table);
    }

    if ($live) {
        // Same reason as the history table above: never MUST_EXIST on a
        // team id that came off a request row.
        $livetarget = $targetnames[(int) $live->targetgroupid] ?? null;
        $livename = $livetarget && (int) $livetarget->activityid === $activity->id()
            ? format_string($livetarget->name)
            : get_string('groupdeletedlabel', 'mod_selfselectadvanced');
        echo html_writer::start_div('alert alert-warning d-flex flex-wrap gap-2 align-items-center');
        echo html_writer::span(get_string('joinpending', 'mod_selfselectadvanced', $livename));
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'd-inline']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'withdraw']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'r', 'value' => $live->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
            'value' => get_string('joinwithdraw', 'mod_selfselectadvanced')]);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
    } else {
        echo html_writer::tag('p', get_string('joinexplain', 'mod_selfselectadvanced'), ['class' => 'text-muted']);
        $askform->display();
    }
} else {
    // What has been asked of the teams this person answers for.
    $myteams = $DB->get_records('selfselectadvanced_group', [
        'activityid' => $activity->id(),
        'leaderid' => (int) $USER->id,
    ], 'name');
    if ($isstaff) {
        $myteams = $DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()], 'name');
    }

    $rows = [];
    foreach ($myteams as $team) {
        foreach (joinrequests::waiting_for_group($activity, (int) $team->id) as $request) {
            $rows[] = [$team, $request];
        }
    }

    // One batched lookup over the rows already loaded, before the loop.
    $sourceids = [];
    foreach ($rows as [, $request]) {
        if ($request->sourcegroupid) {
            $sourceids[(int) $request->sourcegroupid] = true;
        }
    }
    $sourcenames = $sourceids
        ? $DB->get_records_list('selfselectadvanced_group', 'id', array_keys($sourceids), '', 'id, name')
        : [];

    // The requesters' COMPOSITION attributes, one bulk read before the
    // loop and never a get_for_users() inside it - the same accessor,
    // and the same two dimensions, the leader panel on group.php
    // renders (maintainer decision 53). Department and sub-department
    // are what a team is composed by; no contact field is read here.
    $requesterids = [];
    foreach ($rows as [, $request]) {
        $requesterids[(int) $request->userid] = true;
    }
    $requesterattrs = [];
    foreach (array_chunk(array_keys($requesterids), 1000) as $chunk) {
        $requesterattrs += \mod_selfselectadvanced\local\attributes\manager::get_for_users($chunk);
    }

    // Seats, told as they are: CONFIRMED and PENDING kept apart, never
    // added up into one number that reads as the current roster. Held
    // per TEAM, because a team with five requests waiting has one seat
    // position and not five.
    $gatekeeper = (new \mod_selfselectadvanced\local\api($activity))->gatekeeper();
    $seatlines = [];
    foreach ($rows as [$team, ]) {
        if (!isset($seatlines[(int) $team->id])) {
            $seatlines[(int) $team->id] = get_string(
                'seatsummary',
                'mod_selfselectadvanced',
                $gatekeeper->seat_position($team)
            );
        }
    }

    // Decision 6, D6-5: the staff override reaches the acceptance too.
    // Collapsed by default, so a leader answering an ordinary request
    // never sees it; rendered only for holders, and the server checks
    // the capability again on the actor regardless of what was posted.
    $canoverriderules = has_capability('mod/selfselectadvanced:overriderules', $context);
    $overridedisclosure = function (\stdClass $request) use ($canoverriderules): string {
        if (!$canoverriderules) {
            return '';
        }
        $boxes = '';
        foreach (\mod_selfselectadvanced\local\moves::BYPASSABLE as $code) {
            $inputid = 'ssabypass-' . (int) $request->id . '-' . $code;
            $boxes .= html_writer::div(
                html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'bypass[]',
                    'value' => $code,
                    'id' => $inputid,
                    'class' => 'form-check-input me-1',
                ])
                . html_writer::label(
                    get_string('movebypass' . strtolower($code), 'mod_selfselectadvanced'),
                    $inputid,
                    true,
                    ['class' => 'form-check-label']
                ),
                'form-check'
            );
        }

        return html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('joinoverridedisclosure', 'mod_selfselectadvanced'))
            . $boxes
            . html_writer::div(
                get_string('joinoverridenote', 'mod_selfselectadvanced'),
                'text-muted small'
            ),
            ['class' => 'selfselectadvanced-joinoverride w-100']
        );
    };

    if (!$rows) {
        echo html_writer::div(get_string('joinnonewaiting', 'mod_selfselectadvanced'), 'alert alert-info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable selfselectadvanced-joininbox';
        $table->head = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('fullname'),
            get_string('composition', 'mod_selfselectadvanced'),
            get_string('jointreason', 'mod_selfselectadvanced'),
            get_string('joinfitcolumn', 'mod_selfselectadvanced'),
            get_string('actions'),
        ];
        foreach ($rows as [$team, $request]) {
            $student = \core_user::get_user((int) $request->userid);
            $decision = joinrequests::accept_decision($activity, $request, (int) $USER->id, $team);
            $acceptattrs = [
                'type' => 'submit',
                'class' => 'btn btn-primary btn-sm',
                'formaction' => (new moodle_url($baseurl, ['action' => 'accept']))->out(false),
                'value' => get_string('joinaccept', 'mod_selfselectadvanced'),
            ];
            if (!$decision->canaccept) {
                $acceptattrs['disabled'] = 'disabled';
                $acceptattrs['title'] = $decision->hardreason;
            } else if ($decision->confirmacceptrequired) {
                $acceptattrs['onclick'] = 'return confirm(' . json_encode(
                    get_string('joinacceptconfirm', 'mod_selfselectadvanced')
                ) . ');';
            }
            $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false),
                'class' => 'd-flex flex-wrap gap-1 align-items-center'])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'r', 'value' => $request->id])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . ($decision->confirmacceptrequired
                    ? html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmaccept', 'value' => 1])
                    : '')
                . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'note', 'size' => 20,
                    'class' => 'form-control form-control-sm',
                    'placeholder' => get_string('joinnotehint', 'mod_selfselectadvanced')])
                . html_writer::empty_tag('input', $acceptattrs)
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-warning btn-sm',
                    'formaction' => (new moodle_url($baseurl, ['action' => 'decline']))->out(false),
                    'value' => get_string('joindecline', 'mod_selfselectadvanced')])
                . $overridedisclosure($request)
                . html_writer::end_tag('form');
            // What the leader needs to decide with: whether this
            // student fits the team's requirements, and which seat they
            // would take. Shown, never used to hide the request - the
            // leader is entitled to accept somebody the rules would
            // refuse today and sort the composition out afterwards.
            // The REQUEST is handed over, so this verdict is the answer
            // to "what would accepting this do" - the same object, from
            // the same call, that the leader panel on group.php builds
            // its row from.
            $verdict = \mod_selfselectadvanced\local\fit::for_person(
                $activity,
                $team,
                (int) $request->userid,
                $request
            );
            $fitcell = [];
            if (!$verdict->fits) {
                $fitcell[] = html_writer::div(
                    html_writer::tag('strong', get_string('joinfitcaution', 'mod_selfselectadvanced'))
                        . ' ' . s($verdict->caution),
                    'text-warning small'
                );
            } else {
                $fitcell[] = html_writer::div(
                    get_string('joinfitok', 'mod_selfselectadvanced'),
                    'text-success small'
                );
            }
            // Advisory, not a wall: a maximum that only PENDING
            // invitations put over is something the leader can clear by
            // withdrawing one, and decision 53 says so rather than
            // refusing on their behalf.
            foreach ($verdict->warnings as $warning) {
                $fitcell[] = html_writer::div(
                    html_writer::tag('strong', get_string('joinfitnote', 'mod_selfselectadvanced'))
                        . ' ' . s($warning),
                    'text-muted small'
                );
            }
            if (!$decision->canaccept) {
                $fitcell[] = html_writer::div(s($decision->hardreason), 'text-danger small');
            } else if ($decision->confirmationrequired) {
                foreach ($decision->warnings as $warning) {
                    $fitcell[] = html_writer::div(
                        html_writer::tag('strong', get_string('joinfitnote', 'mod_selfselectadvanced'))
                            . ' ' . s($warning),
                        'text-warning small'
                    );
                }
            }
            if ($verdict->seat !== null) {
                $fitcell[] = html_writer::div(
                    get_string('joinfitseat', 'mod_selfselectadvanced', s($verdict->seat)),
                    'small'
                );
            }
            // The decider is entitled to know what the acceptance costs
            // elsewhere: which team, if any, the student would leave.
            $fitcell[] = html_writer::div(
                isset($sourcenames[(int) $request->sourcegroupid])
                    ? get_string(
                        'joinleaves',
                        'mod_selfselectadvanced',
                        format_string($sourcenames[(int) $request->sourcegroupid]->name)
                    )
                    : get_string('joinleavesnone', 'mod_selfselectadvanced'),
                'small text-muted'
            );

            $attr = $requesterattrs[(int) $request->userid] ?? null;
            $department = trim((string) ($attr->department ?? ''));
            $subdepartment = trim((string) ($attr->subdepartment ?? ''));
            $composition = [];
            if ($department !== '') {
                $composition[] = html_writer::div(
                    get_string('attrdepartment', 'mod_selfselectadvanced') . ': ' . s($department),
                    'small'
                );
            }
            if ($subdepartment !== '') {
                $composition[] = html_writer::div(
                    get_string('attrsubdepartment', 'mod_selfselectadvanced') . ': ' . s($subdepartment),
                    'small'
                );
            }
            if (!$composition) {
                $composition[] = html_writer::div(
                    get_string('attrsmissing', 'mod_selfselectadvanced'),
                    'small text-muted'
                );
            }

            $table->data[] = [
                format_string($team->name) . ' ' . html_writer::span($team->pluginuid, 'text-muted small')
                    . html_writer::div($seatlines[(int) $team->id] ?? '', 'small text-muted'),
                $student ? fullname($student) : '',
                implode('', $composition),
                s(shorten_text((string) $request->reason, 110)),
                implode('', $fitcell),
                $form,
            ];
        }
        echo html_writer::table($table);
    }
}

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);
echo $OUTPUT->footer();
