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
 * Appointing Group Coordinators (strategy 1.17 B3, 1.18 D).
 *
 * Two tabs, because there are two jobs. Appointing one or two people is
 * done from a participants table modelled on Moodle's own role screens -
 * paged, filtered, sortable, downloadable - and a whole cohort is done
 * from a file, which still reports exactly what it would do before
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
$action = optional_param('action', '', PARAM_ALPHA);
$tab = optional_param('tab', 'appoint', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:manage', $context);

if (!in_array($tab, ['appoint', 'upload'], true)) {
    $tab = 'appoint';
}
$baseurl = new moodle_url('/mod/selfselectadvanced/coordinators.php', ['id' => $cm->id]);
$PAGE->set_url(new moodle_url($baseurl, ['tab' => $tab]));
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$coursecontext = context_course::instance($course->id);
$roleid = coordinatorrole::ensure();

// A sample file, so nobody has to guess the column names.
if ($action === 'sample') {
    $format = optional_param('format', 'csv', PARAM_ALPHA);
    \mod_selfselectadvanced\local\coordinatorimport::send_sample($format === 'xlsx' ? 'xlsx' : 'csv');
    die;
}

// One person at a time, from the participants table.
if (in_array($action, ['appoint', 'remove'], true) && data_submitted() && confirm_sesskey()) {
    $userid = required_param('u', PARAM_INT);
    $returnurl = new moodle_url($baseurl, ['tab' => 'appoint']);
    try {
        if ($action === 'appoint') {
            coordinatorimport::appoint($activity, $userid);
            $notice = get_string('coordinatorappointed', 'mod_selfselectadvanced');
        } else {
            coordinatorimport::remove($activity, $userid);
            $notice = get_string('coordinatorremoved', 'mod_selfselectadvanced');
        }
        redirect($returnurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($returnurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$form = new \mod_selfselectadvanced\form\coordinator_import_form(
    (new moodle_url($baseurl, ['tab' => 'upload']))->out(false),
    ['cmid' => $cm->id]
);

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
        new moodle_url($baseurl, ['tab' => 'appoint']),
        get_string('coordinatorimportdone', 'mod_selfselectadvanced', $report),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$preview = null;
if ($data = $form->get_data()) {
    $tab = 'upload';
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

$tabs = [];
foreach (['appoint' => 'coordinatorappointtab', 'upload' => 'coordinatoruploadtab'] as $key => $label) {
    $tabs[] = new tabobject(
        $key,
        new moodle_url($baseurl, ['tab' => $key]),
        get_string($label, 'mod_selfselectadvanced')
    );
}
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'appoint') {
    $namefilter = optional_param('namefilter', '', PARAM_TEXT);
    $rolefilter = optional_param('rolefilter', 'teacher', PARAM_ALPHA);
    if (!in_array($rolefilter, ['teacher', 'all', 'coordinators'], true)) {
        $rolefilter = 'teacher';
    }
    $download = optional_param('download', '', PARAM_ALPHA);
    $perpage = \mod_selfselectadvanced\local\perpage::current(25);

    $holders = array_map('intval', array_keys(
        get_role_users($roleid, $coursecontext, false, 'u.id, u.firstname, u.lastname')
    ));

    $tableurl = new moodle_url($baseurl, ['tab' => 'appoint', 'rolefilter' => $rolefilter]);
    if ($namefilter !== '') {
        $tableurl->param('namefilter', $namefilter);
    }

    if ($download === '') {
        echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
            'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'appoint']);
        echo html_writer::label(get_string('name'), 'ssa-conamefilter', true, ['class' => 'me-2']);
        echo html_writer::empty_tag('input', ['type' => 'text', 'id' => 'ssa-conamefilter', 'name' => 'namefilter',
            'value' => $namefilter, 'class' => 'form-control form-control-sm me-2']);
        echo html_writer::label(
            get_string('courserolescolumn', 'mod_selfselectadvanced'),
            'ssa-corolefilter',
            true,
            ['class' => 'me-2']
        );
        echo html_writer::select(
            [
                'teacher' => get_string('coordinatorfilterteachers', 'mod_selfselectadvanced'),
                'all' => get_string('coordinatorfilterall', 'mod_selfselectadvanced'),
                'coordinators' => get_string('coordinatorfilterholders', 'mod_selfselectadvanced'),
            ],
            'rolefilter',
            $rolefilter,
            false,
            ['id' => 'ssa-corolefilter', 'class' => 'form-select form-select-sm w-auto me-2']
        );
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
            'class' => 'btn btn-secondary btn-sm']);
        echo html_writer::end_tag('form');
    }

    // Their other course roles, the way Moodle's own screens show them:
    // one query for the page rather than one per person.
    $courseroles = [];
    $rolenames = role_get_names($coursecontext, ROLENAME_ALIAS, true);
    foreach (
        $DB->get_records_sql(
            "SELECT ra.id, ra.userid, ra.roleid
           FROM {role_assignments} ra
          WHERE ra.contextid = :ctx",
            ['ctx' => $coursecontext->id]
        ) as $assignment
    ) {
        $name = $rolenames[(int) $assignment->roleid] ?? '';
        if ($name === '') {
            continue;
        }
        $courseroles[(int) $assignment->userid][] = $name;
    }
    foreach ($courseroles as $userid => $names) {
        $courseroles[$userid] = implode(', ', array_unique($names));
    }

    $table = new \mod_selfselectadvanced\table\coordinatorcandidates_table(
        'ssacoordcands',
        $activity,
        $roleid,
        $holders,
        $courseroles,
        $tableurl,
        $namefilter,
        $rolefilter,
        $download
    );
    if ($download !== '') {
        $table->out($perpage, false);
        die;
    }
    echo html_writer::div(
        get_string('coordinatorscurrent', 'mod_selfselectadvanced', count($holders)),
        'fw-bold mb-2'
    );
    $table->out($perpage, true);
} else if ($preview) {
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
    echo html_writer::div(
        get_string('coordinatorsampleexplain', 'mod_selfselectadvanced')
        . ' '
        . html_writer::link(
            new moodle_url($baseurl, ['action' => 'sample', 'format' => 'csv']),
            get_string('coordinatorsamplecsv', 'mod_selfselectadvanced'),
            ['class' => 'btn btn-outline-secondary btn-sm ms-2']
        )
        . ' '
        . html_writer::link(
            new moodle_url($baseurl, ['action' => 'sample', 'format' => 'xlsx']),
            get_string('coordinatorsamplexlsx', 'mod_selfselectadvanced'),
            ['class' => 'btn btn-outline-secondary btn-sm']
        ),
        'alert alert-secondary'
    );
    $form->display();
}

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);
echo $OUTPUT->footer();
