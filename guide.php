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
 * Guide dashboard: the guide's own load figure (spec 4A.6) and their
 * queue of submitted groups plus their firm and frozen groups.
 * Read-only GET; filters and bulk freeze arrive in slice 11.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:guide', $context);

$api = new \mod_selfselectadvanced\local\api($activity);

// Bulk freeze of selected firm groups (spec 12).
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'bulkfreeze' && data_submitted() && confirm_sesskey()) {
    require_capability('mod/selfselectadvanced:freeze', $context);
    $selected = optional_param_array('selected', [], PARAM_INT);
    $done = 0;
    $skipped = [];
    foreach ($selected as $sgid) {
        try {
            $sgroup = \mod_selfselectadvanced\local\groups::get($activity, $sgid);
            \mod_selfselectadvanced\local\freeze::freeze_group($activity, $sgroup, (int) $USER->id);
            $done++;
        } catch (moodle_exception $e) {
            $skipped[] = format_string($sgroup->name ?? (string) $sgid) . ': ' . $e->getMessage();
        }
    }
    $notice = get_string('bulkfrozen', 'mod_selfselectadvanced', $done);
    if ($skipped) {
        $notice .= ' ' . get_string('bulkskipped', 'mod_selfselectadvanced', implode(' ', $skipped));
    }
    redirect(
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
        $notice,
        null,
        $skipped ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
    );
}

// Guide volunteering (1.7.0): the guide declares or updates their own
// capacity, up to the manager-override-aware effective maximum.
if (
    $action === 'volunteer' && data_submitted() && confirm_sesskey()
    && !empty($activity->settings()->guidevolunteer)
) {
    $capacity = optional_param('capacity', -1, PARAM_INT);
    try {
        \mod_selfselectadvanced\local\volunteering::set($activity, (int) $USER->id, $capacity);
        $notice = get_string('volunteersaved', 'mod_selfselectadvanced');
        $notifytype = \core\output\notification::NOTIFY_SUCCESS;
    } catch (moodle_exception $e) {
        $notice = $e->getMessage();
        $notifytype = \core\output\notification::NOTIFY_ERROR;
    }
    redirect(
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
        $notice,
        null,
        $notifytype
    );
}

// Digest preference (1.8.0): site-wide, not per-activity, so a guide
// working across many activities can opt into one rollup message
// instead of one per event (spec 14.8 addendum). Stored with
// set_user_preference/get_user_preferences under
// 'mod_selfselectadvanced_digest'; the notifier consults it directly.
if ($action === 'digest' && data_submitted() && confirm_sesskey()) {
    $digestperiod = optional_param('digestperiod', 'immediate', PARAM_ALPHA);
    if (!in_array($digestperiod, ['immediate', 'daily', 'weekly'], true)) {
        $digestperiod = 'immediate';
    }
    set_user_preference('mod_selfselectadvanced_digest', $digestperiod, $USER->id);
    redirect(
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
        get_string('digestsaved', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
$digestperiod = get_user_preferences('mod_selfselectadvanced_digest', 'immediate', $USER->id);

// Filters (spec 12): state, quota compliance, approved before/after, department.
$fstate = optional_param('fstate', '', PARAM_ALPHAEXT);
$fquota = optional_param('fquota', '', PARAM_ALPHA);
$fdept = optional_param('fdept', '', PARAM_TEXT);
$fapprovedop = optional_param('fapprovedop', '', PARAM_ALPHA);
$fapproved = optional_param('fapproved', '', PARAM_RAW_TRIMMED);
$fapprovedts = 0;
if ($fapproved !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fapproved)) {
    $fapproved = '';
}
if ($fapproved !== '') {
    // Reject calendar rollovers (a typed 2026-02-31 must not silently
    // become the 3rd of March).
    [$fy, $fm, $fd] = array_map('intval', explode('-', $fapproved));
    if (!checkdate($fm, $fd, $fy)) {
        $fapproved = '';
    }
}
if ($fapproved !== '') {
    // Parse in the GUIDE's timezone, not the server's (audit item 27).
    try {
        $fapprovedts = (new DateTime($fapproved, core_date::get_user_timezone_object()))->getTimestamp();
    } catch (Exception $e) {
        $fapprovedts = 0;
    }
}

$PAGE->set_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$mygroups = $DB->get_records_select(
    'selfselectadvanced_group',
    'activityid = :activityid AND guideid = :guideid',
    ['activityid' => $activity->id(), 'guideid' => $USER->id],
    'timesubmitted ASC'
);

$resolver = $api->gatekeeper()->resolver();
$load = (object) [
    'used' => \mod_selfselectadvanced\local\groups::count_guiding($activity, (int) $USER->id),
    'max' => $resolver->effective_maxguided((int) $USER->id)->value,
];

// Guide volunteering (1.7.0): own status line, call-to-action when
// never volunteered, and the grandfathered note when the declared
// number now sits below the current guiding load.
$showvolunteer = !empty($activity->settings()->guidevolunteer);
$volunteer = (object) [
    'hasvolunteered' => false,
    'statusline' => '',
    'callline' => '',
    'showgrandfathered' => false,
    'grandfatherline' => '',
    'options' => [],
];
if ($showvolunteer) {
    $volunteerrow = \mod_selfselectadvanced\local\volunteering::get($activity, (int) $USER->id);
    $n = $volunteerrow !== null ? (int) $volunteerrow->capacity : 0;
    $ceiling = $resolver->guide_capacity_ceiling((int) $USER->id)->value;
    $options = [];
    for ($i = 0; $i <= $ceiling; $i++) {
        $options[] = (object) [
            'value' => $i,
            'label' => $i === 0 ? get_string('volunteerwithdrawoption', 'mod_selfselectadvanced') : (string) $i,
            'selected' => $i === $n,
        ];
    }
    $volunteer = (object) [
        'hasvolunteered' => $volunteerrow !== null,
        'statusline' => get_string('volunteerstatus', 'mod_selfselectadvanced', (object) ['n' => $n, 'max' => $ceiling]),
        'callline' => get_string('volunteernone', 'mod_selfselectadvanced'),
        'showgrandfathered' => $load->used > $n,
        'grandfatherline' => get_string('volunteergrandfathered', 'mod_selfselectadvanced', (object) [
            'used' => $load->used,
            'n' => $n,
        ]),
        'options' => $options,
    ];
}

$queue = [];
$guided = [];
// Department filter: ONE query for every roster's departments
// instead of a per-group roster+attribute fetch (audit item 27).
$deptmap = [];
if ($fdept !== '' && $mygroups) {
    [$gsql, $gparams] = $DB->get_in_or_equal(array_keys($mygroups));
    $rows = $DB->get_records_sql(
        "SELECT m.id, m.groupid, a.department
           FROM {selfselectadvanced_member} m
           JOIN {selfselectadvanced_userattr} a ON a.userid = m.userid
          WHERE m.groupid $gsql AND m.status = 'confirmed'",
        $gparams
    );
    foreach ($rows as $row) {
        $deptmap[(int) $row->groupid][] = \core_text::strtolower((string) $row->department);
    }
}
foreach ($mygroups as $group) {
    $row = (object) [
        'pluginuid' => $group->pluginuid,
        'rawname' => $group->name,
        'name' => format_string($group->name),
        'title' => format_string($group->title),
        'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced')
            . (($group->state === \mod_selfselectadvanced\local\state::PENDING_GUIDE
                && (int) $activity->settings()->guidewindow > 0 && !empty($group->timesubmitted))
                ? '; ' . get_string(
                    ((int) $activity->settings()->guideautoapprove) ? 'decidebyauto' : 'decideby',
                    'mod_selfselectadvanced',
                    userdate((int) $group->timesubmitted + (int) $activity->settings()->guidewindow)
                )
                : ''),
        'size' => \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id),
        'reviewurl' => (new moodle_url('/mod/selfselectadvanced/review.php', [
            'id' => $cm->id,
            'g' => $group->id,
        ]))->out(false),
    ];
    // Apply the guide filters to the guided (non-queue) list.
    $matchesfilters = true;
    if ($group->state !== \mod_selfselectadvanced\local\state::PENDING_GUIDE) {
        if ($fstate !== '' && $group->state !== $fstate) {
            $matchesfilters = false;
        }
        if ($matchesfilters && $fquota !== '') {
            $compliant = \mod_selfselectadvanced\local\quota\evaluator::is_compliant($activity, (int) $group->id);
            if (($fquota === 'yes') !== $compliant) {
                $matchesfilters = false;
            }
        }
        if ($matchesfilters && $fapprovedts && $fapprovedop !== '' && !empty($group->timeapproved)) {
            if ($fapprovedop === 'before' && $group->timeapproved >= $fapprovedts) {
                $matchesfilters = false;
            }
            if ($fapprovedop === 'after' && $group->timeapproved <= $fapprovedts) {
                $matchesfilters = false;
            }
        }
        if ($matchesfilters && $fdept !== '') {
            $matchesfilters = in_array(\core_text::strtolower($fdept), $deptmap[(int) $group->id] ?? [], true);
        }
    }

    if ($group->state === \mod_selfselectadvanced\local\state::PENDING_GUIDE) {
        $queue[] = $row;
    } else if ($matchesfilters) {
        $row->groupid = (int) $group->id;
        $row->canfreeze = $group->state === \mod_selfselectadvanced\local\state::FIRM
            && has_capability('mod/selfselectadvanced:freeze', $context);
        $row->freezeurl = (new moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $cm->id,
            'g' => $group->id,
            'action' => 'freeze',
        ]))->out(false);
        $guided[] = $row;
    }
}

// Native download of the filtered list (audit item 27); the
// bulk-freeze SELECTION FORM itself stays a template - a table_sql
// cannot host form controls, a position recorded since C12.
$guidedownload = optional_param('download', '', PARAM_ALPHA);
if ($guidedownload !== '') {
    \mod_selfselectadvanced\local\exporter::download(
        'guide-groups',
        [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('size', 'mod_selfselectadvanced'),
        ],
        array_map(
            static fn($card) => [$card->rawname, $card->pluginuid, $card->statelabel, $card->size],
            array_merge($queue, $guided)
        ),
        $guidedownload
    );
}

echo $OUTPUT->header();

// Digest preference form (1.8.0): kept as its own block, deliberately
// separate from the mustache-rendered dashboard below.
echo html_writer::start_div('selfselectadvanced-digest mb-3');
echo html_writer::tag('h3', get_string('digestheading', 'mod_selfselectadvanced'));
echo html_writer::tag('p', get_string('digestexplain', 'mod_selfselectadvanced'), ['class' => 'text-muted']);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]))->out(false),
    'class' => 'd-flex flex-wrap gap-2 align-items-end',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'digest']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::label(get_string('digestlabel', 'mod_selfselectadvanced'), 'ssa-digestperiod', true, ['class' => 'me-2']);
echo html_writer::select(
    [
        'immediate' => get_string('digestimmediate', 'mod_selfselectadvanced'),
        'daily' => get_string('digestdaily', 'mod_selfselectadvanced'),
        'weekly' => get_string('digestweekly', 'mod_selfselectadvanced'),
    ],
    'digestperiod',
    $digestperiod,
    false,
    ['id' => 'ssa-digestperiod', 'class' => 'form-select form-select-sm w-auto me-2']
);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('digestsave', 'mod_selfselectadvanced'),
    'class' => 'btn btn-secondary btn-sm',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();

$departments = \mod_selfselectadvanced\local\attributes\manager::distinct_values('department');
echo $OUTPUT->render_from_template('mod_selfselectadvanced/guide_dashboard', (object) [
    'loadline' => get_string('guideloadheader', 'mod_selfselectadvanced', $load),
    'showvolunteer' => $showvolunteer,
    'volunteer' => $volunteer,
    'queue' => $queue,
    'hasqueue' => !empty($queue),
    'guided' => $guided,
    'hasguided' => !empty($guided),
    'canbulkfreeze' => has_capability('mod/selfselectadvanced:freeze', $context)
        && !empty(array_filter($guided, static fn($g) => !empty($g->canfreeze))),
    'sesskey' => sesskey(),
    'cmid' => $cm->id,
    'actionurl' => (new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]))->out(false),
    'filters' => (object) [
        'fstate' => $fstate,
        'fquota' => $fquota,
        'fquotayes' => $fquota === 'yes',
        'fquotano' => $fquota === 'no',
        'fbefore' => $fapprovedop === 'before',
        'fafter' => $fapprovedop === 'after',
        'fdept' => $fdept,
        'fapprovedop' => $fapprovedop,
        'fapproved' => $fapproved,
        'stateoptions' => array_map(static fn($st) => (object) [
            'value' => $st,
            'label' => get_string('state' . str_replace('_', '', $st), 'mod_selfselectadvanced'),
            'selected' => $st === $fstate,
        ], \mod_selfselectadvanced\local\state::all()),
        'deptoptions' => array_map(static fn($d) => (object) [
            'value' => $d,
            'selected' => $d === $fdept,
        ], $departments),
    ],
    'backurl' => (new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
