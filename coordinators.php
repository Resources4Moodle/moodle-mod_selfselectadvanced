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
 * Appointing Group Coordinators in bulk (strategy 1.17 B3).
 *
 * An editing teacher uploads a list of people. The page shows who holds
 * the role now, then reports exactly what an upload would do before
 * anything is carried out.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

use mod_selfselectadvanced\local\coordinatorimport;
use mod_selfselectadvanced\local\coordinatorrole;

$id = required_param('id', PARAM_INT);
$confirmid = optional_param('confirmid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:manage', $context);

$baseurl = new moodle_url('/mod/selfselectadvanced/coordinators.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$form = new \mod_selfselectadvanced\form\coordinator_import_form($baseurl->out(false), ['cmid' => $cm->id]);

// The commit arrives as a second POST carrying the id of the file the
// preview was built from, so nothing is changed on a first submission.
if ($confirmid && data_submitted() && confirm_sesskey()) {
    $mode = required_param('mode', PARAM_ALPHA);
    $options = (object) [
        'enrol' => optional_param('enrol', 0, PARAM_BOOL),
        'unenrol' => optional_param('unenrol', 0, PARAM_BOOL),
    ];
    $reader = new csv_import_reader($confirmid, 'mod_selfselectadvanced_coord');
    $report = coordinatorimport::run($activity, $reader, $mode, true, (int) $USER->id, $options);
    $reader->cleanup();
    redirect(
        $baseurl,
        get_string('coordinatorimportdone', 'mod_selfselectadvanced', $report),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$preview = null;
if ($data = $form->get_data()) {
    $content = $form->get_file_content('csvfile');
    $importid = csv_import_reader::get_new_iid('mod_selfselectadvanced_coord');
    $reader = new csv_import_reader($importid, 'mod_selfselectadvanced_coord');
    $readcount = $reader->load_csv_content($content, 'UTF-8', 'comma');
    if ($readcount === null || $readcount === false) {
        redirect($baseurl, $reader->get_error(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $options = (object) ['enrol' => !empty($data->enrol), 'unenrol' => !empty($data->unenrol)];
    $preview = (object) [
        'importid' => $importid,
        'mode' => $data->mode,
        'options' => $options,
        'report' => coordinatorimport::run($activity, $reader, $data->mode, false, (int) $USER->id, $options),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coordinators', 'mod_selfselectadvanced'));
echo html_writer::div(get_string('coordinatorsintro', 'mod_selfselectadvanced'), 'alert alert-info');

// Who holds the role today.
$roleid = coordinatorrole::ensure();
$coursecontext = context_course::instance($course->id);
$holders = get_role_users($roleid, $coursecontext, false, 'u.id, u.firstname, u.lastname, u.username');
if ($holders) {
    $table = new html_table();
    $table->head = [
        get_string('fullname'),
        get_string('username'),
    ];
    foreach ($holders as $holder) {
        $table->data[] = [fullname($holder), s($holder->username)];
    }
    echo $OUTPUT->heading(get_string('coordinatorscurrent', 'mod_selfselectadvanced', count($holders)), 4);
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('coordinatorsnone', 'mod_selfselectadvanced'), 'text-muted mb-3');
}

if ($preview) {
    // Nothing has changed yet: this is what would happen.
    $report = $preview->report;
    echo $OUTPUT->heading(get_string('coordinatorimportpreview', 'mod_selfselectadvanced'), 4);
    echo html_writer::div(
        get_string('coordinatorimportsummary', 'mod_selfselectadvanced', $report),
        'alert alert-warning'
    );
    if ($report->lines) {
        $table = new html_table();
        $table->head = [get_string('fullname'), get_string('status')];
        foreach ($report->lines as $line) {
            $table->data[] = [s($line->who), s($line->outcome)];
        }
        echo html_writer::table($table);
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmid', 'value' => $preview->importid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mode', 'value' => $preview->mode]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enrol',
        'value' => (int) $preview->options->enrol]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'unenrol',
        'value' => (int) $preview->options->unenrol]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary',
        'value' => get_string('coordinatorimportcommit', 'mod_selfselectadvanced')]);
    echo ' ' . html_writer::link($baseurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
} else {
    $form->display();
}

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);
echo $OUTPUT->footer();
