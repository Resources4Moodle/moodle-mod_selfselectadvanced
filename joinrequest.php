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
if (!$isstaff) {
    require_capability('mod/selfselectadvanced:respond', $context);
}

if (!in_array($tab, ['ask', 'answer'], true)) {
    $tab = 'ask';
}
$baseurl = new moodle_url('/mod/selfselectadvanced/joinrequest.php', ['id' => $cm->id]);
$PAGE->set_url(new moodle_url($baseurl, ['tab' => $tab]));
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$mycurrent = groups::get_groups_of_user($activity, (int) $USER->id);
$mycap = (new \mod_selfselectadvanced\local\override\resolver($activity))
    ->effective_maxmembership((int) $USER->id)->value;
$askform = new \mod_selfselectadvanced\form\joinrequest_form($baseurl->out(false), [
    'cmid' => $cm->id,
    'sources' => $mycurrent,
    'headroom' => count($mycurrent) < $mycap,
]);

if ($action === 'ask' && ($data = $askform->get_data())) {
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
    try {
        joinrequests::respond($activity, $requestid, $action === 'accept', $note, (int) $USER->id);
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

    if ($mine) {
        // One batched lookup for the source names of the rows already
        // loaded: no groups::get() inside the loop, no N+1.
        $sourceids = [];
        foreach ($mine as $request) {
            if ($request->sourcegroupid) {
                $sourceids[(int) $request->sourcegroupid] = true;
            }
        }
        $sourcenames = $sourceids
            ? $DB->get_records_list('selfselectadvanced_group', 'id', array_keys($sourceids), '', 'id, name')
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
            $target = groups::get($activity, (int) $request->targetgroupid);
            $table->data[] = [
                format_string($target->name) . ' ' . html_writer::span($target->pluginuid, 'text-muted small'),
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
        $target = groups::get($activity, (int) $live->targetgroupid);
        echo html_writer::start_div('alert alert-warning d-flex flex-wrap gap-2 align-items-center');
        echo html_writer::span(get_string('joinpending', 'mod_selfselectadvanced', format_string($target->name)));
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

    if (!$rows) {
        echo html_writer::div(get_string('joinnonewaiting', 'mod_selfselectadvanced'), 'alert alert-info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable selfselectadvanced-joininbox';
        $table->head = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('fullname'),
            get_string('jointreason', 'mod_selfselectadvanced'),
            get_string('joinfitcolumn', 'mod_selfselectadvanced'),
            get_string('actions'),
        ];
        foreach ($rows as [$team, $request]) {
            $student = \core_user::get_user((int) $request->userid);
            $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false),
                'class' => 'd-flex flex-wrap gap-1 align-items-center'])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'r', 'value' => $request->id])
                . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'note', 'size' => 20,
                    'class' => 'form-control form-control-sm',
                    'placeholder' => get_string('joinnotehint', 'mod_selfselectadvanced')])
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary btn-sm',
                    'formaction' => (new moodle_url($baseurl, ['action' => 'accept']))->out(false),
                    'value' => get_string('joinaccept', 'mod_selfselectadvanced')])
                . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-warning btn-sm',
                    'formaction' => (new moodle_url($baseurl, ['action' => 'decline']))->out(false),
                    'value' => get_string('joindecline', 'mod_selfselectadvanced')])
                . html_writer::end_tag('form');
            // What the leader needs to decide with: whether this
            // student fits the team's requirements, and which seat they
            // would take. Shown, never used to hide the request - the
            // leader is entitled to accept somebody the rules would
            // refuse today and sort the composition out afterwards.
            $verdict = \mod_selfselectadvanced\local\fit::for_person($activity, $team, (int) $request->userid);
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

            $table->data[] = [
                format_string($team->name) . ' ' . html_writer::span($team->pluginuid, 'text-muted small'),
                $student ? fullname($student) : '',
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
