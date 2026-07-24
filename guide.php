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

// Filters (spec 12): state, quota compliance, approved before/after, department.
$fstate = optional_param('fstate', '', PARAM_ALPHAEXT);
$fquota = optional_param('fquota', '', PARAM_ALPHA);
$fdept = optional_param('fdept', '', PARAM_TEXT);
$fapprovedop = optional_param('fapprovedop', '', PARAM_ALPHA);
$fapproved = optional_param('fapproved', '', PARAM_RAW_TRIMMED);
$fapprovedts = 0;
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
if (optional_param('download', '', PARAM_ALPHA) === 'csv') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $writer = new csv_export_writer('comma');
    $writer->set_filename('guide-groups');
    $writer->add_data([
        get_string('groupname', 'mod_selfselectadvanced'),
        get_string('pluginid', 'mod_selfselectadvanced'),
        get_string('state', 'mod_selfselectadvanced'),
        get_string('size', 'mod_selfselectadvanced'),
    ]);
    foreach (array_merge($queue, $guided) as $card) {
        $writer->add_data([$card->name, $card->pluginuid, $card->statelabel, $card->size]);
    }
    $writer->download_file();
    die;
}

echo $OUTPUT->header();
$departments = \mod_selfselectadvanced\local\attributes\manager::distinct_values('department');
echo $OUTPUT->render_from_template('mod_selfselectadvanced/guide_dashboard', (object) [
    'loadline' => get_string('guideloadheader', 'mod_selfselectadvanced', $load),
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
