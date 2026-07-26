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
 * Manager-facing auto-grouping run history (1.8.0): every stored run
 * for the activity, newest first, with multi-format export of the run
 * summary and a flattened per-group decision-log export, plus an
 * inline expand of one run's full decision log.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:manage', $context);

// The tab param picks which of the two export datasets a download
// request is for, following the same convention as flagged.php; the
// page itself has no visible tabs.
$tab = optional_param('tab', 'summary', PARAM_ALPHA);
if (!in_array($tab, ['summary', 'log'], true)) {
    $tab = 'summary';
}
$run = optional_param('run', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

$baseurl = new moodle_url('/mod/selfselectadvanced/autogrouphistory.php', ['id' => $cm->id]);

$PAGE->set_url($run ? new moodle_url($baseurl, ['run' => $run]) : $baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Multi-format export (audit round 6 item 1: raw values throughout,
// never s()/format_string-wrapped, so the dataformat writer's own
// escaping is never doubled).
if ($download !== '') {
    if ($tab === 'log') {
        \mod_selfselectadvanced\local\exporter::download(
            'autogroup-history-log',
            [
                get_string('agrunrunid', 'mod_selfselectadvanced'),
                get_string('agrunstarted', 'mod_selfselectadvanced'),
                get_string('pluginid', 'mod_selfselectadvanced'),
                get_string('leader', 'mod_selfselectadvanced'),
                get_string('roster', 'mod_selfselectadvanced'),
                get_string('agrunbypassed', 'mod_selfselectadvanced'),
                get_string('agrunresidue', 'mod_selfselectadvanced'),
            ],
            array_map(
                static fn($r) => [$r->runid, $r->timestarted, $r->pluginuid, $r->leader,
                    $r->membercount, $r->bypassed, $r->residue],
                \mod_selfselectadvanced\table\agrun_history_table::export_log_rows($activity)
            ),
            $download
        );
    } else {
        \mod_selfselectadvanced\local\exporter::download(
            'autogroup-history-summary',
            [
                get_string('agrunrunid', 'mod_selfselectadvanced'),
                get_string('agrunstarted', 'mod_selfselectadvanced'),
                get_string('agrunfinished', 'mod_selfselectadvanced'),
                get_string('agruntriggeredby', 'mod_selfselectadvanced'),
                get_string('agrungroupsformed', 'mod_selfselectadvanced'),
                get_string('agrunplaced', 'mod_selfselectadvanced'),
                get_string('agrununplaced', 'mod_selfselectadvanced'),
            ],
            array_map(
                static fn($r) => [$r->id, $r->timestarted, $r->timefinished, $r->triggeredby,
                    $r->groupsformed, $r->placed, $r->unplaced],
                \mod_selfselectadvanced\table\agrun_history_table::export_rows($activity)
            ),
            $download
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('agrunhistory', 'mod_selfselectadvanced'));

$table = new \mod_selfselectadvanced\table\agrun_history_table('ssaagrunhistory', $activity, $baseurl);
$table->out(50, true);

echo html_writer::start_div('d-flex flex-wrap gap-4 mb-3');
echo html_writer::div(
    html_writer::tag('div', get_string('agrunexportsummary', 'mod_selfselectadvanced'), ['class' => 'fw-bold mb-1'])
    . \mod_selfselectadvanced\local\exporter::controls($baseurl, 'summary')
);
echo html_writer::div(
    html_writer::tag('div', get_string('agrunexportlog', 'mod_selfselectadvanced'), ['class' => 'fw-bold mb-1'])
    . \mod_selfselectadvanced\local\exporter::controls($baseurl, 'log')
);
echo html_writer::end_div();

// Inline expand of one run's decision log.
if ($run) {
    $agrun = $DB->get_record('selfselectadvanced_agrun', ['id' => $run, 'activityid' => $activity->id()]);
    if ($agrun) {
        $log = json_decode((string) $agrun->log, true) ?: [];
        $groups = $log['groups'] ?? [];
        $bypassed = $log['bypassedrules'] ?? [];

        $userids = [];
        foreach ($groups as $formed) {
            foreach ($formed['members'] ?? [] as $memberid) {
                $userids[(int) $memberid] = true;
            }
        }
        $users = $userids ? $DB->get_records_list('user', 'id', array_keys($userids)) : [];

        echo $OUTPUT->heading(get_string('agrunlogheading', 'mod_selfselectadvanced', $agrun->id), 3);
        echo html_writer::tag('p', get_string('agrunbypassed', 'mod_selfselectadvanced') . ': '
            . ($bypassed ? implode(', ', $bypassed) : '-'));
        echo html_writer::tag('p', get_string('agrunresidue', 'mod_selfselectadvanced') . ': ' . (int) $agrun->unplaced);

        if ($groups) {
            echo html_writer::start_tag('table', ['class' => 'table generaltable']);
            echo html_writer::start_tag('thead');
            echo html_writer::start_tag('tr');
            echo html_writer::tag('th', get_string('pluginid', 'mod_selfselectadvanced'), ['scope' => 'col']);
            echo html_writer::tag('th', get_string('leader', 'mod_selfselectadvanced'), ['scope' => 'col']);
            echo html_writer::tag('th', get_string('roster', 'mod_selfselectadvanced'), ['scope' => 'col']);
            echo html_writer::end_tag('tr');
            echo html_writer::end_tag('thead');
            echo html_writer::start_tag('tbody');
            foreach ($groups as $formed) {
                $leaderid = (int) ($formed['leaderid'] ?? 0);
                $membernames = [];
                foreach ($formed['members'] ?? [] as $memberid) {
                    $memberid = (int) $memberid;
                    if (isset($users[$memberid])) {
                        $membernames[] = fullname($users[$memberid]);
                    }
                }
                echo html_writer::start_tag('tr');
                echo html_writer::tag('td', s($formed['pluginuid'] ?? ''));
                echo html_writer::tag('td', ($leaderid && isset($users[$leaderid])) ? fullname($users[$leaderid]) : '-');
                echo html_writer::tag('td', implode(', ', array_map('s', $membernames)));
                echo html_writer::end_tag('tr');
            }
            echo html_writer::end_tag('tbody');
            echo html_writer::end_tag('table');
        } else {
            echo html_writer::tag('p', get_string('agrunlognogroups', 'mod_selfselectadvanced'), ['class' => 'text-muted']);
        }

        echo html_writer::link($baseurl, get_string('back'), ['class' => 'btn btn-secondary']);
    }
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
        get_string('back'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-3'
);
echo $OUTPUT->footer();
