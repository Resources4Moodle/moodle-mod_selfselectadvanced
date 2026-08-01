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
 * Guide load drill-down (1.8.0): the groups behind one guide's "used
 * of max" figure on the flagged report's guides tab. Read-only GET.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);
$guideid = required_param('guide', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
// Somebody else's workload is a broad read; your own is your own
// dashboard's drill-down (guide.php's "Teams you guide" card links
// straight here). No participant data renders on this page: team name,
// plugin id, state, submitted, deadline and overdue, nothing more.
if ($guideid !== (int) $USER->id) {
    require_capability('mod/selfselectadvanced:viewall', $context);
}

// IDOR guard (spec 14.12): a garbage id fails loudly instead of
// silently rendering an empty page under a blank name.
$guide = \core_user::get_user($guideid, '*', MUST_EXIST);
$guidewindow = (int) $activity->settings()->guidewindow;

// The same "used of max" figure the flagged report linked from, the
// override resolver being the sole source of the effective cap.
$resolver = new \mod_selfselectadvanced\local\override\resolver($activity);
$load = (object) [
    'used' => \mod_selfselectadvanced\local\groups::count_guiding($activity, $guideid),
    'max' => $resolver->effective_maxguided($guideid)->value,
];

$baseurl = new moodle_url('/mod/selfselectadvanced/guideload.php', ['id' => $cm->id, 'guide' => $guideid]);
$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Back has to lead somewhere this viewer may actually go. flagged.php
// requires :viewall, and since 1.20.1 a guide reaching their OWN load
// from the dashboard's "Teams you guide" card need not hold it - on a
// stock install the non-editing teacher no longer does - so an
// unconditional Back button was a dead end in the default
// configuration. The guide dashboard is where they came from; a viewer
// who holds neither capability is sent to the activity page.
if (has_capability('mod/selfselectadvanced:viewall', $context)) {
    $backurl = new moodle_url('/mod/selfselectadvanced/flagged.php', ['id' => $cm->id, 'tab' => 'guides']);
} else if (has_capability('mod/selfselectadvanced:guide', $context)) {
    $backurl = new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);
} else {
    $backurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);
}

if ($download !== '') {
    \mod_selfselectadvanced\local\exporter::download(
        'guide-load-' . $guideid,
        [get_string('groupname', 'mod_selfselectadvanced'), get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'), get_string('flaggedsubmitted', 'mod_selfselectadvanced'),
            get_string('flaggeddecideby', 'mod_selfselectadvanced'), get_string('flaggedoverdue', 'mod_selfselectadvanced')],
        array_map(
            static fn($r) => [$r->rawname, $r->pluginuid, $r->state, $r->submitted, $r->deadline, $r->overdue ? 1 : 0],
            \mod_selfselectadvanced\table\guideload_table::export_rows($activity, $guideid, $guidewindow)
        ),
        $download
    );
}

$rowcount = \mod_selfselectadvanced\table\guideload_table::count_rows($activity, $guideid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('guideloadfor', 'mod_selfselectadvanced', fullname($guide)));
echo $OUTPUT->notification(get_string('guideload', 'mod_selfselectadvanced', $load), 'info', false);

if ($rowcount > 0) {
    $table = new \mod_selfselectadvanced\table\guideload_table(
        'ssaguideload',
        $activity,
        $guideid,
        new moodle_url($baseurl, ['perpage' => $perpage]),
        $guidewindow
    );
    $table->out($perpage, false);
} else {
    echo $OUTPUT->notification(get_string('guideloadnone', 'mod_selfselectadvanced', fullname($guide)), 'info', false);
}

echo html_writer::div(
    \mod_selfselectadvanced\local\exporter::controls($baseurl)
    . \mod_selfselectadvanced\local\perpage::controls($baseurl)
    . html_writer::link($backurl, get_string('back'), ['class' => 'btn btn-secondary ms-2']),
    // Named so a test can reach THIS Back and not the secondary
    // navigation's "Backup", which contains it as a substring.
    'selfselectadvanced-guideloadfooter d-flex flex-wrap align-items-center gap-2 mt-2'
);
echo $OUTPUT->footer();
