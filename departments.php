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
        $report = \mod_selfselectadvanced\local\attributes\depts::bulk_add($data->tree, (int) $USER->id);
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
        // This read runs on the POST as well as on the first draw, so
        // MUST_EXIST here killed the rename BEFORE rename() was ever
        // called: a second manager deleting the category while this form
        // stood open sent the first one to the fatal error page for an
        // ordinary two-manager race, and no catch further down could
        // have seen it. A category that is gone is an answer.
        $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id]);
        if (!$record) {
            redirect(
                $baseurl,
                get_string('refusaldeptgone', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $form->set_data(['name' => $record->name]);
    }
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        try {
            if ($action === 'add') {
                depts::create($data->name, (int) ($data->parent ?? 0), (int) $USER->id);
            } else {
                depts::rename($id, $data->name, (int) $USER->id);
            }
        } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
            // A duplicate name is an answer (errdeptduplicate),
            // delivered as a notice like every sibling arm - never the
            // raw error page.
            redirect(
                $baseurl,
                $e->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        } catch (\dml_missing_record_exception $e) {
            // The category being renamed was deleted by another manager
            // while this one held the form open. rename() re-reads under
            // the vocabulary lock, so the two serialise and whoever gets
            // the lock second reads a row that is no longer there -
            // MUST_EXIST fires and, before this arm, an ordinary race
            // between two people looking at the same list produced the
            // fatal error page. Widening the errorcode allowlist below
            // instead would NOT have worked: core picks 'invalidrecord'
            // when developer debugging is on and 'invalidrecordunknown'
            // when it is off, so the allowlist would have matched on a
            // dev box and rethrown on the live site that reported the
            // problem. Catch the TYPE, which does not move.
            redirect(
                $baseurl,
                get_string('refusaldeptgone', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'errdeptname') {
                // Only the name-validation answer travels untyped;
                // anything else is a genuine failure and stays loud.
                throw $e;
            }
            redirect(
                $baseurl,
                $e->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
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
    try {
        depts::create_program(required_param('progname', PARAM_TEXT), (int) $USER->id);
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'errdeptname') {
            // Only the name-validation answer travels untyped; anything
            // else is a genuine failure and stays loud. Unlike the delete
            // arms below, this one needs no missing-record arm: adding a
            // programme holds the vocabulary lock and does its existence
            // test, its insert and the re-read of an existing row inside
            // ONE transaction, and delete_program() takes the same lock,
            // so no concurrent manager can remove the row in between. A
            // catch here would be dead code, which is the fault this
            // whole change exists to remove.
            throw $e;
        }
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'progdelete' && data_submitted() && confirm_sesskey()) {
    // The delete itself - the guard, the write and the audit event -
    // lives in the service, CALLED here, not transcribed (AUTH-003).
    $pid = required_param('d', PARAM_INT);
    try {
        depts::delete_program($pid, (int) $USER->id);
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    } catch (\dml_missing_record_exception $e) {
        // Two managers both pressing Delete on the same programme is the
        // likeliest race on this page. The service serialises them on the
        // vocabulary lock, so the second one re-reads a row that is
        // already gone and MUST_EXIST fires - for an outcome that is
        // exactly what they asked for. The same arm covers a button that
        // was rendered with d=0 because the programme disappeared between
        // listing the names and looking its id up.
        redirect(
            $baseurl,
            get_string('refusaldeptgone', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    redirect($baseurl);
}

// A sesskey in a link is not protection: a GET still mutates, and any
// page that can make the browser follow it mutates on the reader's
// behalf. Every change below is a POST from a form on this page
// (audit HIGH-SEC-001).
if (
    in_array($action, ['delete', 'up', 'down'], true)
    && data_submitted() && confirm_sesskey()
) {
    $id = required_param('d', PARAM_INT);
    try {
        if ($action === 'delete') {
            depts::delete($id, (int) $USER->id);
        } else {
            depts::move($id, $action === 'up' ? -1 : 1, (int) $USER->id);
        }
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    } catch (\dml_missing_record_exception $e) {
        // Delete, up and down all re-read the row under the vocabulary
        // lock, so whichever of two managers gets the lock second finds
        // the category the other has just deleted and MUST_EXIST fires.
        // Reordering suffered it as well as deleting, and neither had a
        // catch at all: an ordinary race between two people reading the
        // same list ended on the fatal error page. depts::delete()'s
        // coding_exception for a programme row is deliberately NOT caught
        // here - that one is a developer's mistake and stays loud.
        redirect(
            $baseurl,
            get_string('refusaldeptgone', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    redirect($baseurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('departments', 'mod_selfselectadvanced'));
echo $OUTPUT->notification(get_string('departmentsintro', 'mod_selfselectadvanced'), 'info', false);

// The departments tree and the programme vocabulary are two lists, so
// they are two tabs (strategy 1.18 E) rather than one under the other.
$depttab = optional_param('depttab', 'departments', PARAM_ALPHA);
if (!in_array($depttab, ['departments', 'programs'], true)) {
    $depttab = 'departments';
}
$depttabs = [];
foreach (['departments' => 'departments', 'programs' => 'programs'] as $deptkey => $deptlabel) {
    $depttabs[] = new tabobject(
        $deptkey,
        new moodle_url($baseurl, ['depttab' => $deptkey]),
        get_string($deptlabel, 'mod_selfselectadvanced')
    );
}
echo $OUTPUT->tabtree($depttabs, $depttab);

/**
 * A single-button POST form for one mutating department action.
 *
 * Reordering and deletion used to be links carrying a sesskey, which
 * still mutate on a GET - and anything that can make a browser follow a
 * link can therefore make the change on the reader's behalf. Each is
 * now its own small form (audit HIGH-SEC-001).
 *
 * @param moodle_url $baseurl the page url
 * @param string $action delete, up, down or progdelete
 * @param int $id the department or programme
 * @param string $label the button text
 * @param bool $danger render it as a destructive action
 * @return string the form markup
 */
function selfselectadvanced_dept_button(
    moodle_url $baseurl,
    string $action,
    int $id,
    string $label,
    bool $danger = false
): string {
    $class = 'btn btn-link btn-sm p-0 align-baseline' . ($danger ? ' text-danger' : '');

    return html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $baseurl->out(false),
        'class' => 'd-inline',
    ])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'd', 'value' => $id])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'submit', 'class' => $class, 'value' => $label])
        . html_writer::end_tag('form');
}

if ($depttab === 'departments') {
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
        $actions[] = selfselectadvanced_dept_button(
            $baseurl,
            'up',
            (int) $record->id,
            get_string('up')
        );
        $actions[] = selfselectadvanced_dept_button(
            $baseurl,
            'down',
            (int) $record->id,
            get_string('down')
        );
        $actions[] = selfselectadvanced_dept_button(
            $baseurl,
            'delete',
            (int) $record->id,
            get_string('delete'),
            true
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
} else {
    // Programmes (flat vocabulary; auto-grown by admin CSV ingest).
    $progrows = [];
    foreach (\mod_selfselectadvanced\local\attributes\depts::programs_menu() as $progname) {
        $pid = $DB->get_field('selfselectadvanced_dept', 'id', ['kind' => 'program', 'name' => $progname]);
        $progrows[] = [
        format_string($progname),
        selfselectadvanced_dept_button(
            $baseurl,
            'progdelete',
            (int) $pid,
            get_string('delete'),
            true
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
}

echo $OUTPUT->footer();
