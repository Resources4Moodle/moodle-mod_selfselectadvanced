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
 * Landing page for mod_selfselectadvanced, routed by capability.
 *
 * Read-only (GET): student panels with limit counters, my groups and my
 * invitations; staff see the all-groups list. State changes happen on
 * the action pages via POST.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$instance = $activity->settings();
$context = $activity->context();

// Self-service mobile-sharing consent toggle (spec 3b, mobile consent
// surfaces): a single sesskey-protected POST, no separate confirm step.
// Any logged-in viewer may toggle their own consent; the widget itself
// only appears on the landing page when the viewer holds a userattr
// record with a non-empty mobile.
$consentaction = optional_param('consentaction', '', PARAM_ALPHA);
if (in_array($consentaction, ['grant', 'revoke'], true) && data_submitted() && confirm_sesskey()) {
    try {
        \mod_selfselectadvanced\local\attributes\manager::set_consent(
            (int) $USER->id,
            $consentaction === 'grant',
            (int) $USER->id
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        // Same contract as group.php's arms (1.20.19): a refusal is an
        // answer, delivered as a notice, never the raw error page.
        redirect(
            new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    redirect(new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]));
}

$event = \mod_selfselectadvanced\event\course_module_viewed::create([
    'objectid' => $instance->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('selfselectadvanced', $instance);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

$api = new \mod_selfselectadvanced\local\api($activity);
$landing = new \mod_selfselectadvanced\output\landing($api, (int) $USER->id);

// The "All groups" listing (1.20.47): the SAME groups_table manage.php's
// Teams tab drives, reused here so every :viewall holder - not only a
// :manage holder - reaches a sortable, filterable, paginated listing
// instead of the old hand-rolled panel hard-capped at 20 rows with no
// route onward. Built BEFORE the header is sent, because a download
// takes over the whole response (table_sql::is_downloading()) the same
// way manage.php's does.
$canviewall = has_capability('mod/selfselectadvanced:viewall', $context, $USER->id, false);
$groupstable = null;
$statefilter = '';
$perpage = 0;
$allgroupsbaseurl = null;
$tableurl = null;
if ($canviewall) {
    $statefilter = optional_param('statefilter', '', PARAM_ALPHAEXT);
    if (!in_array($statefilter, \mod_selfselectadvanced\local\state::all(), true)) {
        $statefilter = '';
    }
    $download = optional_param('download', '', PARAM_ALPHA);
    $perpage = \mod_selfselectadvanced\local\perpage::current(50);
    $allgroupsbaseurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);
    $tableurl = new moodle_url($allgroupsbaseurl, $statefilter !== '' ? ['statefilter' => $statefilter] : []);
    $groupstable = new \mod_selfselectadvanced\table\groups_table(
        'ssaallgroups',
        $activity,
        $api->gatekeeper(),
        new moodle_url($tableurl, ['perpage' => $perpage]),
        $statefilter,
        $download !== '',
        (int) $USER->id
    );
    if ($download !== '') {
        $groupstable->is_downloading($download, \mod_selfselectadvanced\local\exporter::stamp('groups'));
        // Download ignores paging and dumps the full recordset, same as manage.php.
        $groupstable->out(50, false);
        die;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/landing', $landing->export_for_template($OUTPUT));

if ($canviewall) {
    // Rendered last, exactly where the old capped panel sat, so the page
    // layout is unchanged (confirmed by the paging/sorting Behat scenarios,
    // which drive the real rendered page).
    echo html_writer::start_div('selfselectadvanced-allgroups mt-4');
    echo html_writer::tag('h3', get_string('allgroups', 'mod_selfselectadvanced'));

    // State filter (GET, read-only view change) - the exact idiom
    // manage.php's Teams tab uses at :243-261, param names unchanged so a
    // bookmarked/shared link behaves the same on either page.
    $options = ['' => get_string('all')];
    foreach (\mod_selfselectadvanced\local\state::all() as $stateoption) {
        $options[$stateoption] = get_string('state' . str_replace('_', '', $stateoption), 'mod_selfselectadvanced');
    }
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $allgroupsbaseurl->out_omit_querystring(),
        'class' => 'd-inline-flex gap-2 align-items-center flex-wrap mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::label(
        get_string('state', 'mod_selfselectadvanced'),
        'ssa-allgroups-statefilter',
        true,
        ['class' => 'me-2']
    );
    echo html_writer::select(
        $options,
        'statefilter',
        $statefilter,
        false,
        ['id' => 'ssa-allgroups-statefilter', 'class' => 'me-2']
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('filter'),
        'class' => 'btn btn-secondary btn-sm',
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($tableurl), 'mb-3');
    $groupstable->out($perpage, true);
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
