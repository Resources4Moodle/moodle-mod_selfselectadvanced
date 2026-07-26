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
 * The penalty ledger (spec 11): read-only core table with download,
 * visible to managers and guides.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:viewall', $context);

$baseurl = new moodle_url('/mod/selfselectadvanced/ledger.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$table = new \mod_selfselectadvanced\table\ledger_table(
    'ssaledger',
    $activity->id(),
    new moodle_url($baseurl, ['perpage' => $perpage]),
    $download !== ''
);
if ($download !== '') {
    $table->is_downloading($download, 'penalty-ledger');
    // Download ignores paging and dumps the full recordset; left unchanged.
    $table->out(50, false);
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('penaltyledger', 'mod_selfselectadvanced'));
echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($baseurl), 'mb-3');
$table->out($perpage, true);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
        get_string('back'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-3'
);
echo $OUTPUT->footer();
