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
 * Leader/member roster: one row per confirmed membership across the
 * activity, sortable and filterable by group state and role.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);
$fstate = optional_param('fstate', '', PARAM_ALPHAEXT);
$frole = optional_param('frole', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, false, $cm);
$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
require_capability('mod/selfselectadvanced:viewall', $activity->context());

$baseurl = new moodle_url('/mod/selfselectadvanced/roster.php', [
    'id' => $id,
    'fstate' => $fstate,
    'frole' => $frole,
]);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('roster', 'mod_selfselectadvanced'));
$PAGE->set_heading(format_string($course->fullname));

$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$table = new \mod_selfselectadvanced\table\roster_table(
    'ssaroster',
    $activity,
    new moodle_url($baseurl, ['perpage' => $perpage]),
    $fstate,
    $frole
);
$table->is_downloading($download, 'roster');

if (!$table->is_downloading()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('roster', 'mod_selfselectadvanced'));

    $states = ['' => get_string('all')];
    foreach (\mod_selfselectadvanced\local\state::all() as $state) {
        $states[$state] = get_string('state' . str_replace('_', '', $state), 'mod_selfselectadvanced');
    }
    echo html_writer::start_div('d-flex gap-3 mb-3');
    echo $OUTPUT->single_select(
        new moodle_url($baseurl, ['fstate' => null]),
        'fstate',
        $states,
        $fstate,
        null,
        'ssarosterstate',
        ['label' => get_string('state', 'mod_selfselectadvanced')]
    );
    echo $OUTPUT->single_select(
        new moodle_url($baseurl, ['frole' => null]),
        'frole',
        [
            '' => get_string('all'),
            'leader' => get_string('leader', 'mod_selfselectadvanced'),
            'member' => get_string('member', 'mod_selfselectadvanced'),
        ],
        $frole,
        null,
        'ssarosterrole',
        ['label' => get_string('rosterrole', 'mod_selfselectadvanced')]
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
