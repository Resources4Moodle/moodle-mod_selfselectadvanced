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
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
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

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/landing', $landing->export_for_template($OUTPUT));
echo $OUTPUT->footer();
