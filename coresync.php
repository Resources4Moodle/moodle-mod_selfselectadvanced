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
 * Moodle group mirror status report.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$q = optional_param('q', '', PARAM_TEXT);
$state = optional_param('state', '', PARAM_ALPHAEXT);
$status = optional_param('status', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid((int) $cm->id);
\mod_selfselectadvanced\local\authority::require_core_sync_report($activity, (int) $USER->id);

$filters = [
    'q' => trim($q),
    'state' => in_array($state, [
        \mod_selfselectadvanced\local\state::FORMING,
        \mod_selfselectadvanced\local\state::PENDING_GUIDE,
        \mod_selfselectadvanced\local\state::FIRM,
        \mod_selfselectadvanced\local\state::FROZEN,
    ], true) ? $state : '',
    'status' => in_array($status, ['nomirror', 'synced', 'failed'], true) ? $status : '',
];
$baseparams = ['id' => $cm->id];
foreach ($filters as $key => $value) {
    if ($value !== '') {
        $baseparams[$key] = $value;
    }
}
$baseurl = new moodle_url('/mod/selfselectadvanced/coresync.php', $baseparams);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$form = new \mod_selfselectadvanced\form\coresync_filter_form(null, [
    'cmid' => (int) $cm->id,
    'q' => $filters['q'],
    'state' => $filters['state'],
    'status' => $filters['status'],
]);
ob_start();
$form->display();
$filterhtml = ob_get_clean();

$perpage = \mod_selfselectadvanced\local\perpage::current(20);
$tableurl = new moodle_url($baseurl, ['perpage' => $perpage]);
$table = new \mod_selfselectadvanced\table\coresync_report_table('ssacoresync', $activity, $tableurl, $filters);
ob_start();
echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($baseurl), 'mb-3');
$table->out($perpage, true);
$tablehtml = ob_get_clean();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/coresync_report', (object) [
    'heading' => get_string('coresyncreport', 'mod_selfselectadvanced'),
    'intro' => get_string('coresyncreportintro', 'mod_selfselectadvanced'),
    'filterhtml' => $filterhtml,
    'tablehtml' => $tablehtml,
    'backurl' => \mod_selfselectadvanced\local\coresync_backfill::back_url($activity, (int) $USER->id)->out(false),
    'backlabel' => get_string('back'),
]);
echo $OUTPUT->footer();
