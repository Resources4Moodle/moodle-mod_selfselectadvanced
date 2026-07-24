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
 * Site administration page: participant attributes (spec 8.1, U4, A9).
 *
 * CSV upload with a dry-run validation report before commit, an
 * add/edit form per user, and a core table_sql listing with sorting,
 * paging and download. Guarded by the system-context
 * ingestattributes capability (site administrators in practice); the
 * page never creates user accounts (C11).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');

admin_externalpage_setup('modselfselectadvancedattributes');

$action = optional_param('action', '', PARAM_ALPHA);

// Blank CSV templates: the exact header plus one scaffold row per
// department / sub-department pair of the drilled vocabulary, stamped
// with the chosen programme - so every campus office fills the same
// shape (2026-07-24 request).
if ($action === 'template') {
    $program = optional_param('program', '', PARAM_TEXT);
    $rows = [['Username', 'First name', 'Last Name', 'Gender', 'Department', 'Sub-Department',
        'Mobile Number', 'Seat Location', 'Type of Program', 'Email']];
    $tree = \mod_selfselectadvanced\local\attributes\depts::get_all();
    $pairs = [];
    foreach ($tree as $node) {
        if ((int) $node->depth === 1) {
            $haschild = false;
            foreach ($tree as $child) {
                if ((int) $child->parent === (int) $node->id) {
                    $haschild = true;
                    $pairs[] = [$node->name, $child->name];
                }
            }
            if (!$haschild) {
                $pairs[] = [$node->name, ''];
            }
        }
    }
    if (!$pairs) {
        $pairs[] = ['', ''];
    }
    foreach ($pairs as [$dept, $sub]) {
        $rows[] = ['', '', '', '', $dept, $sub, '', '', $program, ''];
    }
    $filename = 'attributes-template' . ($program !== '' ? '-' . clean_filename($program) : '');
    $writer = new \csv_export_writer('comma');
    $writer->set_filename($filename);
    foreach ($rows as $row) {
        $writer->add_data($row);
    }
    $writer->download_file();
    die;
}
$baseurl = new moodle_url('/mod/selfselectadvanced/attributes.php');
$download = optional_param('download', '', PARAM_ALPHA);

// Per-user add/edit.
if ($action === 'edit') {
    $userid = optional_param('u', 0, PARAM_INT);
    $existing = $userid ? \mod_selfselectadvanced\local\attributes\manager::get($userid) : null;
    $username = '';
    if ($userid) {
        $user = core_user::get_user($userid, '*', MUST_EXIST);
        $username = fullname($user) . ' (' . $user->username . ')';
    }
    $form = new \mod_selfselectadvanced\form\attredit_form(new moodle_url($baseurl, [
        'action' => 'edit',
        'u' => $userid,
    ]), ['userid' => $userid, 'username' => $username]);
    if ($existing) {
        $form->set_data($existing);
    }

    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $target = $userid ?: (int) ($data->targetuser ?? 0);
        \mod_selfselectadvanced\local\attributes\manager::set($target, [
            'gender' => $data->gender,
            'department' => $data->department,
            'subdepartment' => $data->subdepartment,
            'mobile' => $data->mobile,
        ], (int) $USER->id);
        redirect(
            $baseurl,
            get_string('attrsaved', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('participantattributes', 'mod_selfselectadvanced'));
    $form->display();
    echo $OUTPUT->footer();
    die;
}

// Commit of a previously previewed import.
if ($action === 'confirmimport' && data_submitted() && confirm_sesskey()) {
    $importid = required_param('importid', PARAM_INT);
    $options = (object) [
        'mode' => optional_param('updatemode', 'override', PARAM_ALPHA),
        'defaults' => (array) json_decode(optional_param('defaultsjson', '{}', PARAM_RAW), true),
    ];
    $reader = new csv_import_reader($importid, 'mod_selfselectadvanced_attr');
    $report = \mod_selfselectadvanced\local\attributes\csv_importer::run($reader, (int) $USER->id, true, $options);
    $reader->cleanup();

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('participantattributes', 'mod_selfselectadvanced'));
    echo $OUTPUT->render_from_template('mod_selfselectadvanced/attr_import_report', (object) [
        'committed' => true,
        'report' => $report,
        'haswarnings' => !empty($report->warnings),
        'hasrejected' => !empty($report->rejected),
        'backurl' => $baseurl->out(false),
    ]);
    echo $OUTPUT->footer();
    die;
}

$uploadform = new \mod_selfselectadvanced\form\attributes_upload_form($baseurl->out(false));

// Dry-run preview of an uploaded CSV.
if ($data = $uploadform->get_data()) {
    $content = $uploadform->get_file_content('csvfile');
    $importid = csv_import_reader::get_new_iid('mod_selfselectadvanced_attr');
    $reader = new csv_import_reader($importid, 'mod_selfselectadvanced_attr');
    $reader->load_csv_content($content, $data->encoding, $data->delimiter);
    $defaults = [];
    foreach (['gender', 'department', 'subdepartment', 'program', 'seatlocation'] as $dfield) {
        $dvalue = trim((string) ($data->{'default' . $dfield} ?? ''));
        if ($dvalue !== '') {
            $defaults[$dfield] = $dvalue;
        }
    }
    $options = (object) ['mode' => $data->updatemode, 'defaults' => $defaults];
    $report = \mod_selfselectadvanced\local\attributes\csv_importer::run($reader, (int) $USER->id, false, $options);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('participantattributes', 'mod_selfselectadvanced'));
    echo $OUTPUT->render_from_template('mod_selfselectadvanced/attr_import_report', (object) [
        'committed' => false,
        'updatemode' => $options->mode,
        'defaultsjson' => s(json_encode($options->defaults)),
        'modesummary' => get_string('csvmode' . $options->mode, 'mod_selfselectadvanced')
            . ($options->defaults
                ? ' + ' . get_string('csvdefaults', 'mod_selfselectadvanced') . ': '
                    . s(implode(', ', array_map(
                        static fn($k, $v) => $k . '=' . $v,
                        array_keys($options->defaults),
                        $options->defaults
                    )))
                : ''),
        'report' => $report,
        'haswarnings' => !empty($report->warnings),
        'hasrejected' => !empty($report->rejected),
        'headererror' => $report->headererror,
        'canconfirm' => $report->ok && ($report->created + $report->updated) > 0,
        'importid' => $importid,
        'sesskey' => sesskey(),
        'actionurl' => $baseurl->out(false),
        'backurl' => $baseurl->out(false),
    ]);
    echo $OUTPUT->footer();
    die;
}

// Default view: upload form, add link, listing table.
$table = new \mod_selfselectadvanced\table\attributes_table('ssaattributes', $baseurl, $download !== '');
if ($download !== '') {
    $table->is_downloading($download, 'participant-attributes');
    $table->out(50, false);
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('participantattributes', 'mod_selfselectadvanced'));
echo $OUTPUT->notification(get_string('attrpageintro', 'mod_selfselectadvanced'), 'info', false);
$uploadform->display();
echo html_writer::div(
    html_writer::link(
        new moodle_url($baseurl, ['action' => 'edit', 'u' => 0]),
        get_string('attradduser', 'mod_selfselectadvanced'),
        ['class' => 'btn btn-secondary']
    ),
    'mb-3'
);
$table->out(50, true);
// Blank template downloads (generic + one per programme).
$tplbuttons = html_writer::div(get_string('templatedownloadintro', 'mod_selfselectadvanced'), 'mt-3 mb-1');
$tplbuttons .= $OUTPUT->single_button(
    new moodle_url($baseurl, ['action' => 'template']),
    get_string('templatedownload', 'mod_selfselectadvanced'),
    'get'
);
foreach (\mod_selfselectadvanced\local\attributes\depts::programs_menu() as $programname) {
    $tplbuttons .= $OUTPUT->single_button(
        new moodle_url($baseurl, ['action' => 'template', 'program' => $programname]),
        get_string('templatedownloadfor', 'mod_selfselectadvanced', $programname),
        'get'
    );
}
echo html_writer::div($tplbuttons, 'd-flex flex-wrap gap-2 align-items-center');

echo $OUTPUT->footer();
