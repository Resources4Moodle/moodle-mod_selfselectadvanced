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
 * Manager dashboard. This slice ships the A5 guide-assignment queue
 * (groups submitted without a guide, or needing reassignment); the
 * full filterable dashboard with staged moves, overrides, ledger and
 * auto-grouping controls accumulates in later slices.
 *
 * GET renders; assignment is a sesskey-protected POST.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
// This dashboard is the ONLY guide-(re)assignment path, so the narrow
// :assignguide capability has to reach it. The exception names the
// narrow one (least privilege). Everything else on the page keeps its
// own gate: runautogroup re-asserts :manage below, and each tool link
// enforces its own. Nothing here renders a student's email or phone -
// the teams, assign-queue and guide-load tables carry names, ids,
// states, sizes and counts only, including on the CSV/Excel download
// path (contact privacy: no bulk export of contact data, ever).
if (!has_any_capability(['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:assignguide'], $context)) {
    throw new required_capability_exception($context, 'mod/selfselectadvanced:assignguide', 'nopermissions', '');
}

$api = new \mod_selfselectadvanced\local\api($activity);
$baseurl = new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'assignguide' && data_submitted() && confirm_sesskey()) {
    $groupid = required_param('g', PARAM_INT);
    $guideid = required_param('guide', PARAM_INT);
    $group = \mod_selfselectadvanced\local\groups::get($activity, $groupid);
    $api->lifecycle()->assign_guide($group, $guideid, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('guideassigned', 'mod_selfselectadvanced', $group->pluginuid),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'runautogroup' && data_submitted() && confirm_sesskey()) {
    // Auto-grouping rewrites the whole roster in one act, which is the
    // full manage power and not the narrow guide-assignment one the
    // page gate above also admits.
    require_capability('mod/selfselectadvanced:manage', $context);
    if ((int) $activity->settings()->autogroup < 1) {
        redirect(
            $baseurl,
            get_string('autogroupdisabled', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $agrun = \mod_selfselectadvanced\local\autogroup\engine::run($activity, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('autogroupran', 'mod_selfselectadvanced', $agrun),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$statefilter = optional_param('statefilter', '', PARAM_ALPHAEXT);
if (!in_array($statefilter, \mod_selfselectadvanced\local\state::all(), true)) {
    $statefilter = '';
}
$download = optional_param('download', '', PARAM_ALPHA);
$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$tableurl = new moodle_url($baseurl, $statefilter !== '' ? ['statefilter' => $statefilter] : []);
$groupstable = new \mod_selfselectadvanced\table\groups_table(
    'ssagroups',
    $activity,
    $api->gatekeeper(),
    new moodle_url($tableurl, ['perpage' => $perpage]),
    $statefilter,
    $download !== '',
    (int) $USER->id
);
if ($download !== '' && optional_param('assigntab', 'teams', PARAM_ALPHA) === 'teams') {
    $groupstable->is_downloading($download, \mod_selfselectadvanced\local\exporter::stamp('groups'));
    // Download ignores paging and dumps the full recordset; left unchanged.
    $groupstable->out(50, false);
    die;
}

// One tab row for the whole page (strategy 1.18 E). The team table used
// to render in full above the assignment tabs, which put the tab row
// itself below the fold on any real course - a tab nobody scrolls to is
// a tab nobody knows exists. Everything on this page is now a peer.
$assigntab = optional_param('assigntab', 'teams', PARAM_ALPHA);
if (!in_array($assigntab, ['teams', 'unassigned', 'reassign', 'loads'], true)) {
    $assigntab = 'teams';
}
$assignfilter = optional_param('assignfilter', '', PARAM_TEXT);
$loadfilter = optional_param('loadfilter', '', PARAM_TEXT);
$loadroom = optional_param('loadroom', 0, PARAM_BOOL);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managerdashboard', 'mod_selfselectadvanced'));

// Tool links - each drawn only for an actor its TARGET PAGE would
// admit, using that page's own door as the predicate (1.20.3 closure
// evaluation, UI-001: this page's door admits a narrow :assignguide
// holder for the guide-assignment action alone, and rendering the
// whole manager toolbox for them offered thirteen dead ends).
$links = [
    ['quotas.php', 'composition', ['mod/selfselectadvanced:manage']],
    ['moves.php', 'pendingmoves', ['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:managecomposition']],
    // The tickets door is conditional too: :manage enters outright,
    // everyone else needs :coordinate (tickets.php:44-47). Coordinate
    // alone here cost ordinary managers their link (final build
    // review, NEW-002 - the second conditional door this list got
    // wrong; groupedit was the first).
    ['tickets.php', 'tickets', ['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:coordinate']],
    ['coordinators.php', 'coordinators', ['mod/selfselectadvanced:manage']],
    ['moveedit.php', 'movestudents', ['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:managecomposition']],
    // The groupedit door is conditional: students enter under
    // :creategroup, staff under :manage (D6-4 - :creategroup is a
    // student capability an editing teacher never held). Both arms,
    // or the staff-after-cutoff journey loses its entry point.
    ['groupedit.php', 'newteam', ['mod/selfselectadvanced:creategroup', 'mod/selfselectadvanced:manage']],
    ['overrides.php', 'overrides', ['mod/selfselectadvanced:override']],
    ['ledger.php', 'penaltyledger', ['mod/selfselectadvanced:viewall']],
    ['flagged.php', 'flaggedreport', ['mod/selfselectadvanced:viewall']],
    ['templates.php', 'notificationtemplates', ['mod/selfselectadvanced:manage']],
    ['guidelist.php', 'guidelist', ['mod/selfselectadvanced:viewall']],
    ['roster.php', 'roster', ['mod/selfselectadvanced:viewall']],
    ['gridreport.php', 'gridreport', ['mod/selfselectadvanced:viewall']],
];
$linkhtml = '';
foreach ($links as [$file, $stringkey, $caps]) {
    if (!has_any_capability($caps, $context)) {
        continue;
    }
    $linkhtml .= html_writer::link(
        new moodle_url('/mod/selfselectadvanced/' . $file, ['id' => $cm->id]),
        get_string($stringkey, 'mod_selfselectadvanced'),
        ['class' => 'btn btn-outline-primary me-2 mb-2']
    );
}
echo html_writer::div($linkhtml, 'selfselectadvanced-toollinks mb-3');

// Manual auto-grouping trigger with the latest run summary (spec 9.1).
// Rendered for :manage only - the POST it submits requires :manage
// above, and a form whose submission is guaranteed to refuse must not
// be drawn (UI-001; the same rule the group page's controls follow).
if (has_capability('mod/selfselectadvanced:manage', $context)) {
    $lastrun = $DB->get_records('selfselectadvanced_agrun', ['activityid' => $activity->id()], 'id DESC', '*', 0, 1);
    $runsummary = $lastrun
        ? get_string('autogrouplastrun', 'mod_selfselectadvanced', reset($lastrun))
        : get_string('autogroupnorun', 'mod_selfselectadvanced');
    echo html_writer::start_div('selfselectadvanced-autogroup mb-3');
    echo html_writer::span($runsummary, 'me-3');
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'd-inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'runautogroup']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('autogrouprun', 'mod_selfselectadvanced'),
        'class' => 'btn btn-outline-primary btn-sm',
    ]);
    echo html_writer::end_tag('form');
    echo ' ' . html_writer::link(
        new moodle_url('/mod/selfselectadvanced/autogrouphistory.php', ['id' => $cm->id]),
        get_string('agrunhistory', 'mod_selfselectadvanced'),
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
    echo html_writer::end_div();
}

$tabs = [];
foreach (
    [
        'teams' => 'managerteamstab',
        'unassigned' => 'assignqueuetab',
        'reassign' => 'reassigntab',
        'loads' => 'guideloadstab',
    ] as $key => $label
) {
    $tabs[] = new tabobject(
        $key,
        new moodle_url($baseurl, ['assigntab' => $key]),
        get_string($label, 'mod_selfselectadvanced')
    );
}
echo $OUTPUT->tabtree($tabs, $assigntab);

$assignbase = new moodle_url($baseurl, ['assigntab' => $assigntab]);
if ($assignfilter !== '') {
    $assignbase->param('assignfilter', $assignfilter);
}

if ($assigntab === 'teams') {
    // State filter (GET, read-only view change).
    $options = ['' => get_string('all')];
    foreach (\mod_selfselectadvanced\local\state::all() as $stateoption) {
        $options[$stateoption] = get_string('state' . str_replace('_', '', $stateoption), 'mod_selfselectadvanced');
    }
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
        'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'assigntab', 'value' => 'teams']);
    echo html_writer::label(get_string('state', 'mod_selfselectadvanced'), 'ssa-statefilter', true, ['class' => 'me-2']);
    echo html_writer::select($options, 'statefilter', $statefilter, false, ['id' => 'ssa-statefilter', 'class' => 'me-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('filter'),
        'class' => 'btn btn-secondary btn-sm',
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($tableurl), 'mb-3');
    $groupstable->out($perpage, true);
} else if ($assigntab === 'loads') {
    // Informational, so guides who have not volunteered appear too.
    $loadrows = [];
    foreach (
        \mod_selfselectadvanced\local\guides::with_load(
            $activity,
            $api->gatekeeper()->resolver(),
            true,
            $loadfilter
        ) as $guide
    ) {
        if ($loadroom && $guide->remaining < 1) {
            continue;
        }
        $loadrows[] = (object) [
            'fullname' => $guide->fullname,
            'used' => $guide->used,
            'max' => $guide->max,
            'remaining' => $guide->remaining,
        ];
    }
    $loadbase = new moodle_url($baseurl, ['assigntab' => 'loads']);
    if ($loadfilter !== '') {
        $loadbase->param('loadfilter', $loadfilter);
    }
    if ($loadroom) {
        $loadbase->param('loadroom', 1);
    }

    // Filters, which paging and sorting arrived without in 1.17.
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
        'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'assigntab', 'value' => 'loads']);
    echo html_writer::label(
        get_string('guideloadfilter', 'mod_selfselectadvanced'),
        'ssa-loadfilter',
        true,
        ['class' => 'me-2']
    );
    echo html_writer::empty_tag('input', ['type' => 'text', 'id' => 'ssa-loadfilter', 'name' => 'loadfilter',
        'value' => $loadfilter, 'class' => 'form-control form-control-sm me-2']);
    echo html_writer::checkbox('loadroom', 1, (bool) $loadroom, get_string('guideloadroomonly', 'mod_selfselectadvanced'), [
        'id' => 'ssa-loadroom',
        'class' => 'me-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
        'class' => 'btn btn-secondary btn-sm']);
    echo html_writer::end_tag('form');

    if ($loadrows) {
        $loadstable = new \mod_selfselectadvanced\table\guideloads_table('ssaguideloads', $loadbase, $download);
        $loadstable->display_rows($loadrows, $perpage);
    } else {
        echo html_writer::div(get_string('noguidesavailable', 'mod_selfselectadvanced'), 'text-muted');
    }
} else {
    // A name filter, because at this scale scrolling is not a search.
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
        'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'assigntab', 'value' => $assigntab]);
    echo html_writer::label(
        get_string('assignqueuefilter', 'mod_selfselectadvanced'),
        'ssa-assignfilter',
        true,
        ['class' => 'me-2']
    );
    echo html_writer::empty_tag('input', ['type' => 'text', 'id' => 'ssa-assignfilter',
        'name' => 'assignfilter', 'value' => $assignfilter, 'class' => 'form-control form-control-sm me-2']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
        'class' => 'btn btn-secondary btn-sm']);
    echo html_writer::end_tag('form');

    // Whether anybody has room at all, which is a different message
    // from an empty picker. Only the question is asked here - the
    // picker itself searches, and never lists (strategy 1.18 B).
    $hasguides = (bool) \mod_selfselectadvanced\local\guides::selectable($activity, $api->gatekeeper()->resolver());

    $mode = $assigntab === 'reassign'
        ? \mod_selfselectadvanced\table\assignqueue_table::MODE_REASSIGN
        : \mod_selfselectadvanced\table\assignqueue_table::MODE_UNASSIGNED;
    $assigntable = new \mod_selfselectadvanced\table\assignqueue_table(
        'ssaassign' . $assigntab,
        $activity,
        $mode,
        $hasguides,
        $assignbase,
        $assignfilter
    );
    $assigntable->out($perpage, true);
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/selfselectadvanced/overrides.php', ['id' => $cm->id]),
        get_string('overrides', 'mod_selfselectadvanced'),
        ['class' => 'btn btn-secondary me-2']
    )
    . html_writer::link(
        new moodle_url('/mod/selfselectadvanced/guidelist.php', ['id' => $cm->id]),
        get_string('guidelist', 'mod_selfselectadvanced'),
        ['class' => 'btn btn-secondary me-2']
    )
    . html_writer::link(
        new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]),
        get_string('back'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-3'
);
echo $OUTPUT->footer();
