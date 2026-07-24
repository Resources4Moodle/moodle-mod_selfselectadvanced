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
 * The flagged report (spec 9.4, 11, 8.1, 4A.8, M1): groupless
 * students awaiting placement, students with missing attributes,
 * leaderless groups, and grandfathered out-of-limit groups.
 * Read-only GET; placement happens via staged moves.
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
require_capability('mod/selfselectadvanced:viewall', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$resolver = $api->gatekeeper()->resolver();

$tab = optional_param('tab', 'students', PARAM_ALPHA);
$pagenum = optional_param('page', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$perpage = 20;
$PAGE->set_url('/mod/selfselectadvanced/flagged.php', ['id' => $cm->id, 'tab' => $tab]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Groupless students: enrolled respond-holders with no confirmed row.
$enrolled = get_enrolled_users($context, 'mod/selfselectadvanced:respond', 0, 'u.*', 'lastname, firstname');
$confirmedids = $DB->get_fieldset_sql(
    "SELECT DISTINCT m.userid
       FROM {selfselectadvanced_member} m
       JOIN {selfselectadvanced_group} g ON g.id = m.groupid
      WHERE g.activityid = ? AND m.status = ?",
    [$activity->id(), \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
);
$attrs = \mod_selfselectadvanced\local\attributes\manager::get_for_users(array_keys($enrolled));
$groupless = [];
$missingattrs = [];
foreach ($enrolled as $user) {
    $attrline = \mod_selfselectadvanced\local\attributes\manager::display_line(
        $attrs[(int) $user->id] ?? null,
        true
    );
    if (!in_array((int) $user->id, array_map('intval', $confirmedids), true)) {
        $groupless[] = (object) [
            'fullname' => fullname($user),
            'attrline' => $attrline,
            'placeurl' => (new moodle_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cm->id]))->out(false),
        ];
    }
    if (!isset($attrs[(int) $user->id])) {
        $missingattrs[] = (object) ['fullname' => fullname($user)];
    }
}

// Group anomalies: leaderless (M1) and out-of-limit grandfathered (4A.8).
$anomalies = [];
foreach ($DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()]) as $group) {
    $issues = [];
    if (empty($group->leaderid)) {
        $issues[] = get_string('flagleaderless', 'mod_selfselectadvanced');
    }
    $confirmed = \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id);
    $seats = \mod_selfselectadvanced\local\groups::count_seats_taken((int) $group->id);
    $min = $resolver->effective_minsize((int) $group->id)->value;
    $max = $resolver->effective_maxsize((int) $group->id)->value;
    if ($confirmed < $min || $seats > $max) {
        $issues[] = get_string('flagoutoflimit', 'mod_selfselectadvanced', (object) [
            'confirmed' => $confirmed,
            'seats' => $seats,
            'min' => $min,
            'max' => $max,
        ]);
    }
    if ($issues) {
        $anomalies[] = (object) [
            'name' => format_string($group->name),
            'pluginuid' => $group->pluginuid,
            'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced'),
            'issues' => implode(' ', $issues),
            'url' => (new moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cm->id,
                'g' => $group->id,
            ]))->out(false),
        ];
    }
}

// Guides with pending decisions (tab), quota-failing groups (tab).
$guidespending = [];
$quotafail = [];
$windowsecs = (int) $activity->settings()->guidewindow;
foreach ($DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()]) as $fgroup) {
    if ($fgroup->state === \mod_selfselectadvanced\local\state::PENDING_GUIDE) {
        $deadline = $windowsecs > 0 && $fgroup->timesubmitted ? (int) $fgroup->timesubmitted + $windowsecs : 0;
        $guidespending[] = (object) [
            'name' => format_string($fgroup->name),
            'pluginuid' => $fgroup->pluginuid,
            'guidename' => $fgroup->guideid ? fullname(\core_user::get_user((int) $fgroup->guideid)) : '-',
            'since' => userdate((int) $fgroup->timesubmitted),
            'deadline' => $deadline ? userdate($deadline) : '-',
            'overdue' => $deadline && $deadline < time(),
        ];
    }
    if (in_array($fgroup->state, [\mod_selfselectadvanced\local\state::FORMING,
            \mod_selfselectadvanced\local\state::PENDING_GUIDE], true)
            && !\mod_selfselectadvanced\local\quota\evaluator::is_compliant($activity, (int) $fgroup->id)) {
        $quotafail[] = (object) [
            'name' => format_string($fgroup->name),
            'pluginuid' => $fgroup->pluginuid,
            'statelabel' => get_string('state' . str_replace('_', '', $fgroup->state), 'mod_selfselectadvanced'),
        ];
    }
}
$minmembership = (int) $activity->settings()->minmembership;
$defaulterrows = [];
if ($minmembership > 0) {
    $counts = $DB->get_records_sql_menu(
        "SELECT m.userid, COUNT(1)
           FROM {selfselectadvanced_member} m
           JOIN {selfselectadvanced_group} g ON g.id = m.groupid
          WHERE g.activityid = ? AND m.status = ?
       GROUP BY m.userid",
        [$activity->id(), \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
    );
    foreach ($enrolled as $student) {
        $have = (int) ($counts[$student->id] ?? 0);
        if ($have < $minmembership) {
            $defaulterrows[] = [fullname($student), $have, $minmembership - $have];
        }
    }
}

// Per-tab CSV downloads (item 02): every defaulter class exportable.
if ($download === 'csv') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $writer = new csv_export_writer('comma');
    $writer->set_filename('flagged-' . $tab);
    if ($tab === 'defaulters') {
        $writer->add_data(['Student', 'Groups joined', 'Missing']);
        foreach ($defaulterrows as $row) {
            $writer->add_data($row);
        }
    } else if ($tab === 'guides') {
        $writer->add_data(['Group', 'ID', 'Guide', 'Submitted', 'Decide by', 'Overdue']);
        foreach ($guidespending as $row) {
            $writer->add_data([$row->name, $row->pluginuid, $row->guidename, $row->since,
                $row->deadline, $row->overdue ? 'yes' : 'no']);
        }
    } else if ($tab === 'quota') {
        $writer->add_data(['Group', 'ID', 'State']);
        foreach ($quotafail as $row) {
            $writer->add_data([$row->name, $row->pluginuid, $row->statelabel]);
        }
    } else {
        $writer->add_data(['Student', 'Attributes']);
        foreach ($groupless as $row) {
            $writer->add_data([$row->fullname, $row->attrline]);
        }
    }
    $writer->download_file();
    die;
}

echo $OUTPUT->header();
// Tabs keep each list on its own page with fixed-size pagination
// (item 04: less scrolling, options visible).
$tabbase = new moodle_url('/mod/selfselectadvanced/flagged.php', ['id' => $cm->id]);
$tabs = [
    new tabobject('students', new moodle_url($tabbase, ['tab' => 'students']),
        get_string('flaggedtabstudents', 'mod_selfselectadvanced', count($groupless))),
    new tabobject('defaulters', new moodle_url($tabbase, ['tab' => 'defaulters']),
        get_string('flaggedtabdefaulters', 'mod_selfselectadvanced', count($defaulterrows))),
    new tabobject('guides', new moodle_url($tabbase, ['tab' => 'guides']),
        get_string('flaggedtabguides', 'mod_selfselectadvanced', count($guidespending))),
    new tabobject('quota', new moodle_url($tabbase, ['tab' => 'quota']),
        get_string('flaggedtabquota', 'mod_selfselectadvanced', count($quotafail))),
];
echo $OUTPUT->tabtree($tabs, $tab);
echo $OUTPUT->notification(get_string('flaggedexplain', 'mod_selfselectadvanced'), 'info', false);
$downloadbtn = $OUTPUT->single_button(
    new moodle_url($tabbase, ['tab' => $tab, 'download' => 'csv']),
    get_string('downloadtext'),
    'get'
);

if ($tab === 'defaulters') {
    echo $OUTPUT->heading(get_string('defaulters', 'mod_selfselectadvanced'), 3);
    $pageslice = array_slice($defaulterrows, $pagenum * $perpage, $perpage);
    if ($pageslice) {
        $dtable = new html_table();
        $dtable->head = [
            get_string('member', 'mod_selfselectadvanced'),
            get_string('defaultershas', 'mod_selfselectadvanced'),
            get_string('defaultersmissing', 'mod_selfselectadvanced'),
        ];
        $dtable->data = $pageslice;
        $dtable->attributes['class'] = 'generaltable selfselectadvanced-defaulters';
        echo html_writer::table($dtable);
        echo $OUTPUT->paging_bar(count($defaulterrows), $pagenum, $perpage,
            new moodle_url($tabbase, ['tab' => $tab]));
        echo $OUTPUT->notification(get_string('defaultersintro', 'mod_selfselectadvanced'), 'info', false);
    } else {
        echo $OUTPUT->notification(get_string('defaultersnone', 'mod_selfselectadvanced'), 'success', false);
    }
    echo $downloadbtn;
    echo $OUTPUT->footer();
    die;
}
if ($tab === 'guides') {
    echo $OUTPUT->heading(get_string('flaggedguidesheading', 'mod_selfselectadvanced'), 3);
    $pageslice = array_slice($guidespending, $pagenum * $perpage, $perpage);
    if ($pageslice) {
        $gtable = new html_table();
        $gtable->head = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('guide', 'mod_selfselectadvanced'),
            get_string('flaggedsubmitted', 'mod_selfselectadvanced'),
            get_string('flaggeddecideby', 'mod_selfselectadvanced'),
        ];
        foreach ($pageslice as $row) {
            $gtable->data[] = [
                $row->name,
                $row->pluginuid,
                $row->guidename,
                $row->since,
                $row->overdue
                    ? html_writer::span($row->deadline . ' ' .
                        get_string('flaggedoverdue', 'mod_selfselectadvanced'), 'text-danger fw-bold')
                    : $row->deadline,
            ];
        }
        $gtable->attributes['class'] = 'generaltable selfselectadvanced-guidespending';
        echo html_writer::table($gtable);
        echo $OUTPUT->paging_bar(count($guidespending), $pagenum, $perpage,
            new moodle_url($tabbase, ['tab' => $tab]));
    } else {
        echo $OUTPUT->notification(get_string('flaggedguidesnone', 'mod_selfselectadvanced'), 'success', false);
    }
    echo $downloadbtn;
    echo $OUTPUT->footer();
    die;
}
if ($tab === 'quota') {
    echo $OUTPUT->heading(get_string('flaggedtabquotaheading', 'mod_selfselectadvanced'), 3);
    $pageslice = array_slice($quotafail, $pagenum * $perpage, $perpage);
    if ($pageslice) {
        $qtable = new html_table();
        $qtable->head = [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
        ];
        foreach ($pageslice as $row) {
            $qtable->data[] = [$row->name, $row->pluginuid, $row->statelabel];
        }
        $qtable->attributes['class'] = 'generaltable selfselectadvanced-quotafail';
        echo html_writer::table($qtable);
        echo $OUTPUT->paging_bar(count($quotafail), $pagenum, $perpage,
            new moodle_url($tabbase, ['tab' => $tab]));
    } else {
        echo $OUTPUT->notification(get_string('flaggedquotanone', 'mod_selfselectadvanced'), 'success', false);
    }
    echo $downloadbtn;
    echo $OUTPUT->footer();
    die;
}

// Default tab: groupless (paginated), missing attributes, anomalies.
$grouplesspage = array_slice($groupless, $pagenum * $perpage, $perpage);
echo $OUTPUT->render_from_template('mod_selfselectadvanced/flagged_report', (object) [
    'groupless' => $grouplesspage,
    'hasgroupless' => !empty($groupless),
    'grouplesscount' => count($groupless),
    'missingattrs' => $missingattrs,
    'hasmissingattrs' => !empty($missingattrs),
    'anomalies' => $anomalies,
    'hasanomalies' => !empty($anomalies),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->paging_bar(count($groupless), $pagenum, $perpage, new moodle_url($tabbase, ['tab' => 'students']));
echo $downloadbtn;
echo $OUTPUT->footer();
