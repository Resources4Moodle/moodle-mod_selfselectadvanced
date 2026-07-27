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
 * Guides listing: sortable/filterable/downloadable table with each
 * guide's department, sub-department, seat location and live load.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);
$fdept = optional_param('fdept', '', PARAM_TEXT);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, false, $cm);
$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
require_capability('mod/selfselectadvanced:viewall', $activity->context());

$baseurl = new moodle_url('/mod/selfselectadvanced/guidelist.php', ['id' => $id, 'fdept' => $fdept]);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('guidelist', 'mod_selfselectadvanced'));
$PAGE->set_heading(format_string($course->fullname));

$guideids = array_map(
    static fn($user) => (int) $user->id,
    get_enrolled_users($activity->context(), 'mod/selfselectadvanced:guide', 0, 'u.id')
);
$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$table = new \mod_selfselectadvanced\table\guides_table(
    'ssaguides',
    $activity,
    $guideids,
    new moodle_url($baseurl, ['perpage' => $perpage]),
    $fdept
);
$table->is_downloading($download, \mod_selfselectadvanced\local\exporter::stamp('guides'));

if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('guidelist', 'mod_selfselectadvanced'));

    $options = ['' => get_string('all')];
    foreach (\mod_selfselectadvanced\local\attributes\depts::departments_menu() as $name) {
        $options[$name] = $name;
    }
    echo html_writer::start_div('mb-3');
    echo $OUTPUT->single_select(
        new moodle_url($baseurl, ['fdept' => null]),
        'fdept',
        $options,
        $fdept,
        null,
        'ssaguidefilter',
        ['label' => get_string('attrdepartment', 'mod_selfselectadvanced')]
    );
    echo html_writer::end_div();
    echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($baseurl), 'mb-3');
}

$table->out($perpage, false);

if (!$table->is_downloading()) {
    echo $OUTPUT->single_button(
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
        get_string('back'),
        'get'
    );
    echo $OUTPUT->footer();
}
