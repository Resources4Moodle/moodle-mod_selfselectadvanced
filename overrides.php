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

// The picker searches instead of listing (strategy 1.18 B). This page
// used to build EVERY possible target before rendering: every team at
// group scope, every guide at guide scope, and at user scope every
// enrolled student - ten thousand of them on the enrolment this plugin
// is built for. A client-side autocomplete does not help with that; it
// still has to render each option before it can filter one.
//
// Only the target already chosen is loaded, so an edit shows what it is
// editing.
$targetmodule = [
    'group' => 'mod_selfselectadvanced/groupselector',
    'guide' => 'mod_selfselectadvanced/guideselector',
][$mode] ?? 'mod_selfselectadvanced/participantselector';

/**
 * Labels for the targets actually referenced, and no others.
 *
 * @param \mod_selfselectadvanced\activity $activity the activity
 * @param string $mode user, group or guide
 * @param int[] $ids the target ids in play
 * @return string[] id => label
 */
function selfselectadvanced_override_labels($activity, string $mode, array $ids): array {
    global $DB;

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'tg');
    $labels = [];
    if ($mode === 'group') {
        $params['activityid'] = $activity->id();
        $groups = $DB->get_records_select(
            'selfselectadvanced_group',
            "id $insql AND activityid = :activityid",
            $params,
            '',
            'id, name, pluginuid'
        );
        foreach ($groups as $group) {
            $labels[(int) $group->id] = format_string($group->name) . ' (' . $group->pluginuid . ')';
        }

        return $labels;
    }

    $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', true)->selects;
    foreach ($DB->get_records_sql("SELECT id{$namefields} FROM {user} WHERE id $insql", $params) as $user) {
        $labels[(int) $user->id] = fullname($user);
    }

    return $labels;
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
        $targetlabel = selfselectadvanced_override_labels($activity, $mode, [$targetid])[$targetid]
            ?? (string) $targetid;
    }

    $form = new \mod_selfselectadvanced\form\override_form(
        new moodle_url($baseurl, ['action' => 'edit', 'override' => $overrideid]),
        [
            'cmid' => $cm->id,
            'mode' => $mode,
            'overrideid' => $overrideid,
            'targetmodule' => $targetmodule,
            'targetid' => $targetid ?? 0,
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

// List view. The labels are fetched for the ids these rows carry and
// for nothing else - the page never builds the full target list.
$overrides = \mod_selfselectadvanced\local\override\store::get_all($activity, $mode);
$targets = selfselectadvanced_override_labels($activity, $mode, array_map(
    static fn($o) => (int) ($o->userid ?? 0) ?: (int) ($o->groupid ?? 0),
    $overrides
));
$rows = [];
foreach ($overrides as $override) {
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
