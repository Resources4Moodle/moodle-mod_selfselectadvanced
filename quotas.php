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
 * Quota rule management (spec 8.2, decision A8): prioritised rule list
 * with reorder/delete, and the add/edit form whose value picker offers
 * the ingested attribute values (spec 4.7).
 *
 * GET renders; every mutation is a sesskey-protected POST.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:manage', $context);

$baseurl = new moodle_url('/mod/selfselectadvanced/quotas.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if (($action === 'moveup' || $action === 'movedown') && data_submitted() && confirm_sesskey()) {
    $ruleid = required_param('rule', PARAM_INT);
    \mod_selfselectadvanced\local\quota\store::move($activity, $ruleid, $action === 'moveup' ? -1 : 1);
    redirect($baseurl);
}

if ($action === 'delete' && data_submitted() && confirm_sesskey()) {
    $ruleid = required_param('rule', PARAM_INT);
    \mod_selfselectadvanced\local\quota\store::delete($activity, $ruleid);
    redirect(
        $baseurl,
        get_string('quotaruledeleted', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'edit') {
    $ruleid = optional_param('rule', 0, PARAM_INT);
    $form = new \mod_selfselectadvanced\form\quota_form(
        new moodle_url('/mod/selfselectadvanced/quotas.php', ['id' => $cm->id, 'action' => 'edit']),
        ['cmid' => $cm->id, 'ruleid' => $ruleid]
    );
    if ($ruleid && !$form->is_submitted()) {
        $rule = $DB->get_record('selfselectadvanced_quota', [
            'id' => $ruleid,
            'activityid' => $activity->id(),
        ], '*', MUST_EXIST);
        $form->set_data([
            'rule' => $rule->id,
            'rtype' => $rule->rtype,
            'dimension' => $rule->dimension,
            'dimensionvalue' => $rule->value !== null ? $rule->dimension . '|' . $rule->value : null,
            'mincount' => $rule->mincount,
            'maxcount' => $rule->maxcount,
        ]);
    }

    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $save = (object) [
            'id' => (int) $data->rule ?: null,
            'rtype' => $data->rtype,
            'mincount' => $data->mincount,
            'maxcount' => $data->rtype === 'distinct' ? null : $data->maxcount,
        ];
        if ($data->rtype === 'distinct') {
            $save->dimension = $data->dimension;
            $save->value = null;
        } else {
            [$save->dimension, $save->value] = explode('|', $data->dimensionvalue, 2);
        }
        \mod_selfselectadvanced\local\quota\store::save($activity, $save);
        redirect(
            $baseurl,
            get_string('quotarulesaved', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('quotarules', 'mod_selfselectadvanced'));
    $form->display();
    echo $OUTPUT->footer();
    die;
}

$rules = \mod_selfselectadvanced\local\quota\store::get_all($activity);
$rows = [];
$last = count($rules);
foreach ($rules as $i => $rule) {
    $dimensionname = get_string('attr' . $rule->dimension, 'mod_selfselectadvanced');
    if ($rule->rtype === 'distinct') {
        $label = get_string('quotaruledistinct', 'mod_selfselectadvanced', (object) [
            'k' => $rule->mincount,
            'dimension' => $dimensionname,
        ]);
    } else {
        $a = (object) [
            'value' => $rule->value,
            'dimension' => $dimensionname,
            'min' => $rule->mincount,
            'max' => $rule->maxcount,
        ];
        if ($rule->mincount !== null && $rule->maxcount !== null) {
            $label = get_string('quotarulebetween', 'mod_selfselectadvanced', $a);
        } else if ($rule->mincount !== null) {
            $label = get_string('quotarulemin', 'mod_selfselectadvanced', $a);
        } else {
            $label = get_string('quotarulemax', 'mod_selfselectadvanced', $a);
        }
    }
    $rows[] = (object) [
        'ruleid' => (int) $rule->id,
        'priority' => (int) $rule->priority,
        'label' => $label,
        'isfirst' => $i === 0,
        'islast' => $i === $last - 1,
        'editurl' => (new moodle_url($baseurl, ['action' => 'edit', 'rule' => $rule->id]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/quota_rules', (object) [
    'rules' => $rows,
    'hasrules' => !empty($rows),
    'addurl' => (new moodle_url($baseurl, ['action' => 'edit']))->out(false),
    'actionurl' => $baseurl->out(false),
    'cmid' => $cm->id,
    'sesskey' => sesskey(),
    'backurl' => (new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
