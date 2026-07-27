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
 * Formation analytics (1.11.0): median timings from group creation
 * through submission, approval and (for listed teams) guide interest,
 * the formation funnel and expression-of-interest outcome totals.
 * Every figure is set-based, one query per measure, computed in PHP -
 * no per-row queries. Read-only GET, manager-only.
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
require_capability('mod/selfselectadvanced:manage', $context);

$baseurl = new moodle_url('/mod/selfselectadvanced/analytics.php', ['id' => $cm->id]);
$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$analytics = \mod_selfselectadvanced\table\analytics_stats::class;

if ($download !== '') {
    \mod_selfselectadvanced\local\exporter::download(
        'formation-analytics-' . $activity->id(),
        [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('timecreated'),
            get_string('eoilistedbadge', 'mod_selfselectadvanced'),
            get_string('analyticsmedianinterest', 'mod_selfselectadvanced'),
            get_string('flaggedsubmitted', 'mod_selfselectadvanced'),
            get_string('filterapproved', 'mod_selfselectadvanced'),
            get_string('statefrozen', 'mod_selfselectadvanced'),
        ],
        array_map(
            static fn($r) => [$r->rawname, $r->pluginuid, $r->state, $r->timecreated, $r->timelisted,
                $r->firstinterest, $r->timesubmitted, $r->timeapproved, $r->timefrozen],
            $analytics::export_rows($activity)
        ),
        $download
    );
}

$funnel = $analytics::funnel($activity);
$eoicounts = $analytics::eoi_status_counts($activity);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('analyticspage', 'mod_selfselectadvanced'));

echo html_writer::start_div('ssa-cards mb-3');
echo $analytics::card_html(
    $analytics::median_creation_to_submission($activity),
    get_string('analyticsmediantoform', 'mod_selfselectadvanced')
);
echo $analytics::card_html(
    $analytics::median_creation_to_firm($activity),
    get_string('analyticsmediantofirm', 'mod_selfselectadvanced')
);
echo $analytics::card_html(
    $analytics::median_listing_to_interest($activity),
    get_string('analyticsmedianinterest', 'mod_selfselectadvanced')
);
echo $analytics::card_html(
    $analytics::median_interest_to_response($activity),
    get_string('analyticsmedianresponse', 'mod_selfselectadvanced')
);
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('analyticsfunnel', 'mod_selfselectadvanced'), 3);
$funneltable = new html_table();
$funneltable->head = [get_string('analyticsfunnel', 'mod_selfselectadvanced'), get_string('total')];
$funneltable->attributes['class'] = 'generaltable selfselectadvanced-analyticsfunnel';
$funneltable->data[] = [get_string('analyticscreated', 'mod_selfselectadvanced'), $funnel->created];
$funneltable->data[] = [get_string('analyticssubmitted', 'mod_selfselectadvanced'), $funnel->submitted];
$funneltable->data[] = [get_string('analyticsfirm', 'mod_selfselectadvanced'), $funnel->firm];
$funneltable->data[] = [get_string('analyticsfrozen', 'mod_selfselectadvanced'), $funnel->frozen];
echo html_writer::table($funneltable);

echo $OUTPUT->heading(get_string('analyticseoistatus', 'mod_selfselectadvanced'), 3);
$eoitable = new html_table();
$eoitable->head = [get_string('analyticseoistatus', 'mod_selfselectadvanced'), get_string('total')];
$eoitable->attributes['class'] = 'generaltable selfselectadvanced-analyticseoi';
$eoitable->data[] = [
    get_string('eoistatuspending', 'mod_selfselectadvanced'),
    $eoicounts[\mod_selfselectadvanced\local\eoi::STATUS_PENDING],
];
$eoitable->data[] = [
    get_string('eoistatusaccepted', 'mod_selfselectadvanced'),
    $eoicounts[\mod_selfselectadvanced\local\eoi::STATUS_ACCEPTED],
];
$eoitable->data[] = [
    get_string('eoistatusrejected', 'mod_selfselectadvanced'),
    $eoicounts[\mod_selfselectadvanced\local\eoi::STATUS_REJECTED],
];
$eoitable->data[] = [
    get_string('eoistatusexpired', 'mod_selfselectadvanced'),
    $eoicounts[\mod_selfselectadvanced\local\eoi::STATUS_EXPIRED],
];
$eoitable->data[] = [
    get_string('eoistatuswithdrawn', 'mod_selfselectadvanced'),
    $eoicounts[\mod_selfselectadvanced\local\eoi::STATUS_WITHDRAWN],
];
echo html_writer::table($eoitable);

echo html_writer::div(
    \mod_selfselectadvanced\local\exporter::controls($baseurl)
    . html_writer::link(
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
        get_string('back'),
        ['class' => 'btn btn-secondary ms-2']
    ),
    'd-flex flex-wrap align-items-center gap-2 mt-2'
);
echo $OUTPUT->footer();
