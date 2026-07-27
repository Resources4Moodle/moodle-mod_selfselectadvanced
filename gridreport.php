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
 * The group grid report (item 5d): one row per group, the guide, and
 * every confirmed member's last name in its own column - a compact
 * roll call view for printing or a quick whole-activity scan.
 *
 * Read-only GET, viewall-gated like flagged.php, whose boilerplate
 * this page copies. The column count is fixed at the activity's own
 * maxsize setting; a group whose own effective maximum was raised by
 * an override wraps its extra members into the last column as a
 * comma-separated list instead of growing the table (see
 * gridreport_table::build_rows() for the full reasoning).
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
require_capability('mod/selfselectadvanced:viewall', $context);

// Name filter follows the same GET-param convention as the confirmed
// roster filter on the individual group page (rq, PARAM_RAW_TRIMMED):
// this report has its own tsort/tdir/page namespace (flexible_table
// defaults), so it needs no remapping the way the flagged report's
// students-tab tables do.
$rq = optional_param('rq', '', PARAM_RAW_TRIMMED);
$download = optional_param('download', '', PARAM_ALPHA);
$perpage = \mod_selfselectadvanced\local\perpage::current(20);

$PAGE->set_url('/mod/selfselectadvanced/gridreport.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Column count = the ACTIVITY's own maxsize setting, not any per-group
// effective_maxsize() (an override can raise one group's cap without
// growing every row's column count - see gridreport_table's docblock).
$membercols = max(1, (int) $activity->settings()->maxsize);

$rows = \mod_selfselectadvanced\table\gridreport_table::build_rows($activity, $membercols, $rq);

$baseurl = new moodle_url('/mod/selfselectadvanced/gridreport.php', ['id' => $cm->id] + ($rq !== '' ? ['rq' => $rq] : []));

// Multi-format export (ODS / Excel / CSV / TXT, admin default), the
// same wiring as flagged.php: the full filtered dataset, raw values,
// independent of the paginated display table.
if ($download !== '') {
    $columns = [
        get_string('groupname', 'mod_selfselectadvanced'),
        get_string('state', 'mod_selfselectadvanced'),
        get_string('gridguidecol', 'mod_selfselectadvanced'),
    ];
    for ($i = 1; $i <= $membercols; $i++) {
        $columns[] = get_string('gridmembercol', 'mod_selfselectadvanced', $i);
    }
    \mod_selfselectadvanced\local\exporter::download(
        'gridreport',
        $columns,
        array_map(
            static fn($row) => array_merge([$row->rawname, $row->statelabel, $row->guidename], $row->membercells),
            $rows
        ),
        $download
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('gridreport', 'mod_selfselectadvanced'));
echo $OUTPUT->notification(get_string('gridreportintro', 'mod_selfselectadvanced'), 'info', false);

$filterform = html_writer::start_tag('form', ['method' => 'get',
        'action' => $baseurl->out_omit_querystring(), 'class' => 'd-inline-flex gap-2 me-3 mb-2'])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
    . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'rq', 'value' => $rq,
        'class' => 'form-control w-auto', 'placeholder' => get_string('flaggedfilter', 'mod_selfselectadvanced')])
    . html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
        'class' => 'btn btn-secondary'])
    . html_writer::end_tag('form');
$controlsbar = html_writer::div(
    $filterform
    . \mod_selfselectadvanced\local\exporter::controls(new moodle_url($baseurl, ['id' => $cm->id]))
    . \mod_selfselectadvanced\local\perpage::controls($baseurl),
    'd-flex flex-wrap align-items-center mb-2'
);

$table = new \mod_selfselectadvanced\table\gridreport_table(
    'ssagridreport',
    new moodle_url($baseurl, ['perpage' => $perpage]),
    $membercols
);
$table->display_rows($rows, $perpage);

echo html_writer::tag(
    'p',
    '* = ' . get_string('leader', 'mod_selfselectadvanced'),
    ['class' => 'text-muted small mt-2']
);
echo $controlsbar;

echo $OUTPUT->single_button(
    new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
    get_string('back'),
    'get'
);
echo $OUTPUT->footer();
