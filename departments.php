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
 * Site administration page: the department / sub-department vocabulary.
 *
 * A site-wide category tree in the course-categories format (multiple
 * levels possible); the participant attribute editor and the CSV
 * importer only accept values from this tree once it is non-empty.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('modselfselectadvanceddepartments');

use mod_selfselectadvanced\local\attributes\depts;

$action = optional_param('action', '', PARAM_ALPHA);
$baseurl = new moodle_url('/mod/selfselectadvanced/departments.php');

if ($action === 'bulk') {
    $form = new \mod_selfselectadvanced\form\dept_bulk_form(new moodle_url($baseurl, ['action' => 'bulk']));
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $report = \mod_selfselectadvanced\local\attributes\depts::bulk_add($data->tree);
        redirect(
            $baseurl,
            get_string('deptbulkresult', 'mod_selfselectadvanced', $report),
            null,
            $report->errors
                ? \core\output\notification::NOTIFY_WARNING
                : \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('departments', 'mod_selfselectadvanced'));
    $form->display();
    echo $OUTPUT->footer();
    die;
}

if ($action === 'add' || $action === 'rename') {
    $id = optional_param('d', 0, PARAM_INT);
    $form = new \mod_selfselectadvanced\form\dept_form(new moodle_url($baseurl, [
        'action' => $action,
        'd' => $id,
    ]), ['action' => $action, 'id' => $id]);
    if ($action === 'rename' && $id) {
        $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id], '*', MUST_EXIST);
        $form->set_data(['name' => $record->name]);
    }
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        if ($action === 'add') {
            depts::create($data->name, (int) ($data->parent ?? 0));
        } else {
            depts::rename($id, $data->name);
        }
        redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('departments', 'mod_selfselectadvanced'));
    $form->display();
    echo $OUTPUT->footer();
    die;
}

if ($action === 'progadd' && data_submitted() && confirm_sesskey()) {
    \mod_selfselectadvanced\local\attributes\depts::ensure_program(required_param('progname', PARAM_TEXT));
    redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'progdelete' && confirm_sesskey()) {
    $pid = required_param('d', PARAM_INT);
    $prog = $DB->get_record('selfselectadvanced_dept', ['id' => $pid, 'kind' => 'program'], '*', MUST_EXIST);
    $inuse = $DB->record_exists_select(
        'selfselectadvanced_userattr',
        $DB->sql_equal('program', ':name', false),
        ['name' => $prog->name]
    );
    if ($inuse) {
        redirect($baseurl, get_string('errdeptinuse', 'mod_selfselectadvanced', $prog->name), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $DB->delete_records('selfselectadvanced_dept', ['id' => $pid]);
    redirect($baseurl);
}

if (($action === 'delete' || $action === 'up' || $action === 'down') && confirm_sesskey()) {
    $id = required_param('d', PARAM_INT);
    if ($action === 'delete') {
        try {
            depts::delete($id);
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    } else {
        depts::move($id, $action === 'up' ? -1 : 1);
    }
    redirect($baseurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('departments', 'mod_selfselectadvanced'));
echo $OUTPUT->notification(get_string('departmentsintro', 'mod_selfselectadvanced'), 'info', false);

$rows = [];
foreach (depts::get_all() as $record) {
    $actions = [];
    $actions[] = html_writer::link(
        new moodle_url($baseurl, ['action' => 'add', 'd' => $record->id]),
        get_string('deptaddchild', 'mod_selfselectadvanced')
    );
    $actions[] = html_writer::link(
        new moodle_url($baseurl, ['action' => 'rename', 'd' => $record->id]),
        get_string('rename')
    );
    $actions[] = html_writer::link(
        new moodle_url($baseurl, ['action' => 'up', 'd' => $record->id, 'sesskey' => sesskey()]),
        get_string('up')
    );
    $actions[] = html_writer::link(
        new moodle_url($baseurl, ['action' => 'down', 'd' => $record->id, 'sesskey' => sesskey()]),
        get_string('down')
    );
    $actions[] = html_writer::link(
        new moodle_url($baseurl, ['action' => 'delete', 'd' => $record->id, 'sesskey' => sesskey()]),
        get_string('delete')
    );
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int) $record->depth - 1);
    $rows[] = [
        $indent . format_string($record->name),
        (int) $record->depth,
        implode(' | ', $actions),
    ];
}
if ($rows) {
    $table = new html_table();
    $table->head = [
        get_string('name'),
        get_string('deptlevel', 'mod_selfselectadvanced'),
        get_string('actions'),
    ];
    $table->data = $rows;
    $table->attributes['class'] = 'generaltable selfselectadvanced-departments';
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('departmentsnone', 'mod_selfselectadvanced'), 'warning', false);
}

echo html_writer::start_div('d-flex gap-2');
echo $OUTPUT->single_button(
    new moodle_url($baseurl, ['action' => 'add']),
    get_string('deptadd', 'mod_selfselectadvanced'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url($baseurl, ['action' => 'bulk']),
    get_string('deptbulk', 'mod_selfselectadvanced'),
    'get'
);
echo html_writer::end_div();
// Programmes (flat vocabulary; auto-grown by admin CSV ingest).
echo $OUTPUT->heading(get_string('programs', 'mod_selfselectadvanced'), 3);
$progrows = [];
foreach (\mod_selfselectadvanced\local\attributes\depts::programs_menu() as $progname) {
    $pid = $DB->get_field('selfselectadvanced_dept', 'id', ['kind' => 'program', 'name' => $progname]);
    $progrows[] = [
        format_string($progname),
        html_writer::link(
            new moodle_url($baseurl, ['action' => 'progdelete', 'd' => $pid, 'sesskey' => sesskey()]),
            get_string('delete')
        ),
    ];
}
if ($progrows) {
    $progtable = new html_table();
    $progtable->head = [get_string('name'), get_string('actions')];
    $progtable->data = $progrows;
    $progtable->attributes['class'] = 'generaltable selfselectadvanced-programs';
    echo html_writer::table($progtable);
} else {
    echo $OUTPUT->notification(get_string('programsnone', 'mod_selfselectadvanced'), 'info', false);
}
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'd-flex gap-2 mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'progadd']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'progname', 'class' => 'form-control w-auto',
    'placeholder' => get_string('programname', 'mod_selfselectadvanced'), ]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('programadd', 'mod_selfselectadvanced'),
    'class' => 'btn btn-secondary', ]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
