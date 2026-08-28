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
 * Request limits: what staff use to slow ONE flooding requester down
 * (1.20.60, maintainer instruction 2026-08-27 - "we should have a
 * mechanism where the agent (Teacher or group coordinator) can initiate
 * a throttle (number of tickets per + wait till before next ticket)").
 *
 * With no `user` on the URL this is the list of everybody currently
 * limited in this activity, each row with the one action that undoes it.
 * With a `user` it is the form for that person, prefilled with whatever
 * limit they are already under.
 *
 * Every decision is throttle's, not this page's: who may set a limit,
 * whether the target may be limited at all, and whether the numbers mean
 * anything are all settled in throttle::set()/clear(), which is what the
 * tests drive.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\throttle;
use mod_selfselectadvanced\local\workflow_refusal;

$id = required_param('id', PARAM_INT);
$userid = optional_param('user', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
// The queue's own door, asked the same way tickets.php asks it: a
// manager, or the coordinator role. throttle::set() asks it again for
// real - this is only what decides whether the page renders.
if (!has_capability('mod/selfselectadvanced:manage', $context)) {
    require_capability('mod/selfselectadvanced:coordinate', $context);
}

$baseurl = new moodle_url('/mod/selfselectadvanced/ticketthrottle.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'clear' && $userid > 0 && data_submitted() && confirm_sesskey()) {
    try {
        throttle::clear($activity, $userid, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('ticketthrottlecleared', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$form = null;
if ($userid > 0) {
    $target = \core_user::get_user($userid, '*', MUST_EXIST);
    $existing = throttle::get($activity, $userid);
    $form = new \mod_selfselectadvanced\form\ticketthrottle_form(
        new moodle_url('/mod/selfselectadvanced/ticketthrottle.php'),
        [
            'cmid' => $cm->id,
            'userid' => $userid,
            'targetname' => fullname($target),
        ]
    );
    if ($existing !== null) {
        $form->set_data([
            'maxtickets' => (int) $existing->maxtickets,
            'windowhours' => (int) $existing->windowhours,
            // The selector's own "disabled" state is what a null wait
            // looks like: 0 leaves the optional checkbox unticked.
            'nextallowed' => (int) ($existing->nextallowed ?? 0),
            'reason' => $existing->reason,
        ]);
    }

    if ($form->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $form->get_data()) {
        try {
            throttle::set(
                $activity,
                $userid,
                (int) $data->maxtickets,
                (int) $data->windowhours,
                // An untouched optional date_time_selector submits 0,
                // and 0 is 1970 - which throttle::set() would refuse as
                // a wait in the past. Null is what "no wait" means.
                empty($data->nextallowed) ? null : (int) $data->nextallowed,
                (string) $data->reason,
                (int) $USER->id
            );
            redirect(
                $baseurl,
                get_string('ticketthrottlesetnotice', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (workflow_refusal $e) {
            redirect(
                new moodle_url($baseurl, ['user' => $userid]),
                $e->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ticketthrottles', 'mod_selfselectadvanced'));
echo html_writer::div(get_string('ticketthrottleintro', 'mod_selfselectadvanced'), 'alert alert-info');

if ($form !== null) {
    $current = throttle::get($activity, $userid);
    echo html_writer::div(
        $current === null
            ? get_string('ticketthrottlecurrentnone', 'mod_selfselectadvanced')
            : get_string('ticketthrottlecurrent', 'mod_selfselectadvanced', (object) [
                'max' => (int) $current->maxtickets,
                'hours' => (int) $current->windowhours,
                'wait' => $current->nextallowed
                    ? get_string('ticketthrottlewait', 'mod_selfselectadvanced', userdate((int) $current->nextallowed))
                    : '',
            ]),
        'mb-3'
    );
    $form->display();
}

$rows = throttle::all($activity);
// Not the page's own title a second time: with the form above it, two
// identical headings would read as two copies of one section.
echo $OUTPUT->heading(get_string('ticketthrottleslistheading', 'mod_selfselectadvanced'), 3);
if (!$rows) {
    echo html_writer::div(get_string('ticketthrottlesnone', 'mod_selfselectadvanced'));
    echo $OUTPUT->footer();
    die;
}
echo html_writer::div(get_string('ticketthrottleslistintro', 'mod_selfselectadvanced'), 'small text-muted mb-2');

// One query for every name this table needs - the requester and whoever
// set the limit - rather than two \core_user::get_user() calls per row
// (the N+1 shape audit L-11/L-20 removed from the queue itself).
$needed = [];
foreach ($rows as $row) {
    $needed[(int) $row->userid] = true;
    $needed[(int) $row->setby] = true;
}
$names = [];
foreach ($DB->get_records_list('user', 'id', array_keys($needed)) as $u) {
    $names[(int) $u->id] = fullname($u);
}

$table = new html_table();
$table->attributes['class'] = 'generaltable selfselectadvanced-throttles';
$table->head = [
    get_string('user'),
    get_string('ticketthrottleslimitcolumn', 'mod_selfselectadvanced'),
    get_string('ticketthrottlereason', 'mod_selfselectadvanced'),
    get_string('ticketthrottlessetbycolumn', 'mod_selfselectadvanced'),
    get_string('actions'),
];
foreach ($rows as $row) {
    $limit = get_string('ticketthrottlecurrent', 'mod_selfselectadvanced', (object) [
        'max' => (int) $row->maxtickets,
        'hours' => (int) $row->windowhours,
        'wait' => $row->nextallowed
            ? get_string('ticketthrottlewait', 'mod_selfselectadvanced', userdate((int) $row->nextallowed))
            : '',
    ]);

    $edit = html_writer::link(
        new moodle_url($baseurl, ['user' => (int) $row->userid]),
        get_string('edit'),
        ['class' => 'btn btn-secondary btn-sm me-1']
    );
    $clear = html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $baseurl->out(false),
        'class' => 'd-inline',
    ]);
    $clear .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $clear .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'clear']);
    $clear .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'user', 'value' => (int) $row->userid]);
    $clear .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('ticketthrottleclear', 'mod_selfselectadvanced'),
        'class' => 'btn btn-outline-secondary btn-sm',
    ]);
    $clear .= html_writer::end_tag('form');

    $table->data[] = [
        $names[(int) $row->userid] ?? (int) $row->userid,
        $limit,
        format_text((string) $row->reason, FORMAT_PLAIN),
        $names[(int) $row->setby] ?? (int) $row->setby,
        $edit . $clear,
    ];
}
echo html_writer::table($table);

echo $OUTPUT->footer();
