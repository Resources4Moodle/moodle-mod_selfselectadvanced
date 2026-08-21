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
 * The Group Coordinator's dashboard (strategy 1.17 B2).
 *
 * A coordinator holds the freeze, unfreeze, override and queue powers
 * but not the manager's settings powers, so manage.php is closed to
 * them. This page is the way in to the work that is actually theirs,
 * and it says plainly which teams are not - the ones they guide, are
 * lined up to guide, or belong to.
 *
 * Read-only: every action lives on the page that owns it.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
$canmanage = has_capability('mod/selfselectadvanced:manage', $context);
if (!$canmanage) {
    require_capability('mod/selfselectadvanced:coordinate', $context);
}

$api = new \mod_selfselectadvanced\local\api($activity);
$baseurl = new moodle_url('/mod/selfselectadvanced/coordinator.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// What is waiting, and what is not this person's to touch.
// Only the number is wanted here, so only the number is fetched. This
// used to pull the whole queue back - every ticket the activity had
// ever seen - and count the open ones in PHP.
$open = tickets::count_open($activity, (int) $USER->id);

// The one producer of "which teams are mine to stay away from" (seam
// audit B6, 1.20.20): this page carried a hand-written copy of
// tickets::involvement()'s three arms.
$involved = tickets::involved_group_ids($activity, (int) $USER->id);

$awaitingfreeze = $DB->count_records('selfselectadvanced_group', [
    'activityid' => $activity->id(),
    'state' => state::FIRM,
]);
$frozen = $DB->count_records('selfselectadvanced_group', [
    'activityid' => $activity->id(),
    'state' => state::FROZEN,
]);
// 1.20.59 deliverable B: the requester's own "did this help?" answer,
// surfaced here too, not only on the staff queue (spec names both
// surfaces). Unfiltered by viewer, like $awaitingfreeze/$frozen above -
// a "did not help" answer is a signal about the queue's own outcomes,
// not about which tickets THIS viewer may claim.
$nothelped = tickets::count_feedback_nothelped($activity);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coordinatordashboard', 'mod_selfselectadvanced'));
echo html_writer::div(get_string('coordinatorintro', 'mod_selfselectadvanced'), 'alert alert-info');

// The tools that are theirs - each drawn only for an actor its TARGET
// PAGE would admit, using that page's own door as the predicate (the
// manage.php pattern; UI-001, and NAV-001 for the two workbench cards).
// Manager-only pages are deliberately absent unless :manage is held.
//
// The two intervention cards are the NAV-001 closure: the coordinator
// role carries :assignguide and :managecomposition, manage.php and
// moves.php admit those capabilities at their doors, and nothing on
// this dashboard or in the navigation ever said so - the powers were
// reachable only by typed URL. Every conditional door lists BOTH of
// its arms, because each direction of that mistake has already
// happened once (wave 1: the narrow-only Tickets arm cost managers
// their link; the toolbox rendered whole offered dead ends).
$links = [
    ['tickets.php', 'tickets', ['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:coordinate'], []],
    ['overrides.php', 'overrides', ['mod/selfselectadvanced:override'], []],
    ['flagged.php', 'flaggedreport', ['mod/selfselectadvanced:viewall'], []],
    ['coresync.php', 'coresyncreport', ['mod/selfselectadvanced:manage',
        'mod/selfselectadvanced:coordinate', 'mod/selfselectadvanced:viewall'], []],
    // The manage.php door itself (manage.php:48), straight to the
    // assignment queue tab that is the work this power names.
    ['manage.php', 'coordinatorassignguide',
        ['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:assignguide'],
        ['assigntab' => 'unassigned'], ],
    // The moves.php door itself (moves.php:58); moveedit.php shares it.
    ['moves.php', 'coordinatorcomposition',
        ['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:managecomposition'], [], ],
    ['manage.php', 'managerdashboard', ['mod/selfselectadvanced:manage'], []],
];
$linkhtml = '';
foreach ($links as [$file, $stringkey, $caps, $extraparams]) {
    if (
        $file === 'coresync.php'
            ? !\mod_selfselectadvanced\local\authority::may_core_sync_report($activity, (int) $USER->id)
            : !has_any_capability($caps, $context)
    ) {
        continue;
    }
    $linkhtml .= html_writer::link(
        new moodle_url('/mod/selfselectadvanced/' . $file, ['id' => $cm->id] + $extraparams),
        get_string($stringkey, 'mod_selfselectadvanced'),
        ['class' => 'btn btn-outline-primary me-2 mb-2']
    );
}
echo html_writer::div($linkhtml, 'selfselectadvanced-toollinks mb-3');

// Four plain counts: what is waiting, and what is out of bounds.
$cards = [
    [$open, get_string('coordinatorcardqueue', 'mod_selfselectadvanced')],
    [$awaitingfreeze, get_string('coordinatorcardfirm', 'mod_selfselectadvanced')],
    [$frozen, get_string('coordinatorcardfrozen', 'mod_selfselectadvanced')],
    [count($involved), get_string('coordinatorcardinvolved', 'mod_selfselectadvanced')],
    [$nothelped, get_string('coordinatorcardnothelped', 'mod_selfselectadvanced')],
];
$cardhtml = '';
foreach ($cards as [$value, $label]) {
    $cardhtml .= html_writer::div(
        html_writer::div($value, 'h2 mb-0') . html_writer::div($label, 'small text-muted'),
        'ssa-card border rounded p-3 me-2 mb-2 flex-fill'
    );
}
echo html_writer::div($cardhtml, 'd-flex flex-wrap mb-3 selfselectadvanced-coordinatorcards');

if ($involved) {
    echo html_writer::div(
        get_string('coordinatorinvolvednotice', 'mod_selfselectadvanced', count($involved)),
        'alert alert-warning'
    );
}

// Every team, paged and sorted - at 1500+ groups a plain list is no use
// (strategy 1.17 C1 applies here too).
$statefilter = optional_param('statefilter', '', PARAM_ALPHAEXT);
if (!in_array($statefilter, state::all(), true)) {
    $statefilter = '';
}
$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$tableurl = new moodle_url($baseurl, $statefilter !== '' ? ['statefilter' => $statefilter] : []);

$options = ['' => get_string('all')];
foreach (state::all() as $stateoption) {
    $options[$stateoption] = get_string('state' . str_replace('_', '', $stateoption), 'mod_selfselectadvanced');
}
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
    'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::label(get_string('state', 'mod_selfselectadvanced'), 'ssa-costatefilter', true, ['class' => 'me-2']);
echo html_writer::select($options, 'statefilter', $statefilter, false, ['id' => 'ssa-costatefilter', 'class' => 'me-2']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter'),
    'class' => 'btn btn-secondary btn-sm',
]);
echo html_writer::end_tag('form');

echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($tableurl), 'mb-3');
$groupstable = new \mod_selfselectadvanced\table\groups_table(
    'ssacoordinatorgroups',
    $activity,
    $api->gatekeeper(),
    new moodle_url($tableurl, ['perpage' => $perpage]),
    $statefilter,
    false,
    (int) $USER->id
);
$groupstable->out($perpage, true);

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary']
);
echo $OUTPUT->footer();
