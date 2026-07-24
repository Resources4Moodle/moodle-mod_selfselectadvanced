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
 * Override management (spec 10), modelled on mod_assign overrides:
 * per-mode lists (user, group, guide) and the B5-scoped add/edit form.
 * Every mutation is evented with old and new values.
 *
 * GET renders; every mutation is a sesskey-protected POST.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'user', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

if (!in_array($mode, ['user', 'group', 'guide'], true)) {
    $mode = 'user';
}

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:override', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$baseurl = new moodle_url('/mod/selfselectadvanced/overrides.php', ['id' => $cm->id, 'mode' => $mode]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Target labels per mode.
$targets = [];
if ($mode === 'group') {
    foreach (
        $DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()], 'name ASC') as $group
    ) {
        $targets[(int) $group->id] = format_string($group->name) . ' (' . $group->pluginuid . ')';
    }
} else if ($mode === 'guide') {
    foreach (\mod_selfselectadvanced\local\guides::with_load($activity, $api->gatekeeper()->resolver()) as $guide) {
        $targets[$guide->id] = $guide->fullname . ' — ' . $guide->label;
    }
} else {
    foreach (get_enrolled_users($context, 'mod/selfselectadvanced:respond', 0, 'u.*', 'lastname, firstname') as $user) {
        $targets[(int) $user->id] = fullname($user);
    }
}

if ($action === 'delete' && data_submitted() && confirm_sesskey()) {
    $overrideid = required_param('override', PARAM_INT);
    \mod_selfselectadvanced\local\override\store::delete($activity, $overrideid, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('overridedeleted', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'edit') {
    $overrideid = optional_param('override', 0, PARAM_INT);
    $existing = null;
    $targetlabel = '';
    if ($overrideid) {
        $existing = $DB->get_record('selfselectadvanced_override', [
            'id' => $overrideid,
            'activityid' => $activity->id(),
        ], '*', MUST_EXIST);
        $targetid = (int) ($existing->userid ?? 0) ?: (int) ($existing->groupid ?? 0);
        $targetlabel = $targets[$targetid] ?? (string) $targetid;
    }

    $form = new \mod_selfselectadvanced\form\override_form(
        new moodle_url($baseurl, ['action' => 'edit', 'override' => $overrideid]),
        [
            'cmid' => $cm->id,
            'mode' => $mode,
            'overrideid' => $overrideid,
            'targets' => $targets,
            'targetlabel' => $targetlabel,
        ]
    );
    if ($existing && !$form->is_submitted()) {
        $formdata = ['override' => $existing->id];
        foreach (\mod_selfselectadvanced\local\override\store::FIELDS[$mode] as $field) {
            $formdata[$field] = $existing->$field;
        }
        $form->set_data($formdata);
    }

    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $values = [];
        foreach (\mod_selfselectadvanced\local\override\store::FIELDS[$mode] as $field) {
            if (!property_exists($data, $field)) {
                continue;
            }
            $value = $data->$field;
            if (in_array($field, ['quotaexempt', 'penaltywaived', 'guidehidden'], true)) {
                $value = $value ? 1 : null;
            } else if ($field === 'maxguided' && $mode === 'guide' && $value !== '' && $value !== null) {
                // 1.5.0: an EXPLICIT zero is a real guide cap ("always
                // full"), unlike every other limit where 0 means unset.
                $value = (int) $value;
            } else if ($value === '' || $value === null || (int) $value === 0) {
                $value = null;
            } else {
                $value = (int) $value;
            }
            $values[$field] = $value;
        }
        if ($existing) {
            $targetid = (int) ($existing->userid ?? 0) ?: (int) ($existing->groupid ?? 0);
        } else {
            $targetid = (int) $data->target;
        }
        \mod_selfselectadvanced\local\override\store::save($activity, $mode, $targetid, $values, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('overridesaved', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('overrides' . $mode, 'mod_selfselectadvanced'));
    $form->display();
    echo $OUTPUT->footer();
    die;
}

// List view.
$rows = [];
foreach (\mod_selfselectadvanced\local\override\store::get_all($activity, $mode) as $override) {
    $targetid = (int) ($override->userid ?? 0) ?: (int) ($override->groupid ?? 0);
    $parts = [];
    foreach (\mod_selfselectadvanced\local\override\store::FIELDS[$mode] as $field) {
        if ($override->$field === null) {
            continue;
        }
        $label = get_string('overridefield' . $field, 'mod_selfselectadvanced');
        if (in_array($field, ['timeopen', 'timedue', 'timecutoff'], true)) {
            $parts[] = $label . ': ' . userdate($override->$field);
        } else if (in_array($field, ['quotaexempt', 'penaltywaived'], true)) {
            $parts[] = $label;
        } else {
            $parts[] = $label . ': ' . $override->$field;
        }
    }
    $rows[] = (object) [
        'overrideid' => (int) $override->id,
        'target' => $targets[$targetid] ?? get_string('deleted'),
        'summary' => implode(' · ', $parts),
        'editurl' => (new moodle_url($baseurl, ['action' => 'edit', 'override' => $override->id]))->out(false),
    ];
}

$tabs = [];
foreach (['user', 'group', 'guide'] as $tab) {
    $tabs[] = (object) [
        'label' => get_string('overrides' . $tab, 'mod_selfselectadvanced'),
        'url' => (new moodle_url('/mod/selfselectadvanced/overrides.php', [
            'id' => $cm->id,
            'mode' => $tab,
        ]))->out(false),
        'active' => $tab === $mode,
    ];
}

// Guarded reductions: re-check pending rows on every visit (and via
// the explicit button) so cleared blockers activate immediately; the
// remainder are listed with links to the page that resolves each one.
$pending = \mod_selfselectadvanced\local\override\store::recheck_pending($activity, (int) $USER->id);
$pendingout = [];
foreach ($pending as $row) {
    $target = in_array($row->scope, ['user', 'guide'], true)
        ? fullname(\core_user::get_user((int) $row->userid))
        : format_string($DB->get_field('selfselectadvanced_group', 'name', ['id' => (int) $row->groupid]));
    $items = [];
    foreach ($row->blockers as $blocker) {
        $items[] = $OUTPUT->action_link(
            $blocker->fixurl,
            $blocker->description,
            new popup_action(
                'click',
                $blocker->fixurl,
                'ssafix' . $row->id . $blocker->rule,
                ['width' => 1100, 'height' => 750]
            )
        );
    }
    $pendingout[] = html_writer::div(
        html_writer::span(get_string('overridescope' . $row->scope, 'mod_selfselectadvanced') . ': ' . $target, 'fw-bold')
        . html_writer::alist($items),
        'selfselectadvanced-pendingoverride mb-2'
    );
}

echo $OUTPUT->header();
if ($pendingout) {
    echo $OUTPUT->notification(get_string('overridespendingintro', 'mod_selfselectadvanced'), 'warning', false);
    echo html_writer::div(implode('', $pendingout), 'mb-3');
    echo $OUTPUT->single_button(
        new moodle_url('/mod/selfselectadvanced/overrides.php', ['id' => $cm->id]),
        get_string('overridesrecheck', 'mod_selfselectadvanced'),
        'get'
    );
}
echo $OUTPUT->render_from_template('mod_selfselectadvanced/overrides_list', (object) [
    'tabs' => $tabs,
    'rows' => $rows,
    'hasrows' => !empty($rows),
    'addurl' => (new moodle_url($baseurl, ['action' => 'edit']))->out(false),
    'actionurl' => $baseurl->out(false),
    'cmid' => $cm->id,
    'mode' => $mode,
    'sesskey' => sesskey(),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
