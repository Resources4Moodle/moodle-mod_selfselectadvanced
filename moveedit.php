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
 * Stage a move (spec 7). The form posts back here; the staged move
 * appears on moves.php for joint validation and commit.
 *
 * UX audit fix: stage() is called inside a try/catch. A moodle_exception
 * from the moves engine (a bad successor, an unmet membership, or any
 * future refusal) is caught and re-attached to the SAME form instance as
 * a field error, then the form is redisplayed with every submitted value
 * intact - it no longer fatals and destroys the manager's input.
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
$context = $activity->context();
require_capability('mod/selfselectadvanced:manage', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$listurl = new moodle_url('/mod/selfselectadvanced/moves.php', ['id' => $cm->id]);

$PAGE->set_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Optional prefill: used by the override blocker links (student only)
// and by moves.php's per-row "Edit and restage" link, which prefills
// every field from the dead-end move plus its id in 'replaces' so it
// can be cancelled once the replacement stages successfully.
$prefill = optional_param('student', 0, PARAM_INT);
$selectedstudent = [];
if ($prefill && ($prefilluser = core_user::get_user($prefill))) {
    $selectedstudent = [$prefill => fullname($prefilluser)];
}
$prefillsuccessor = optional_param('successor', 0, PARAM_INT);
$selectedsuccessor = [];
if ($prefillsuccessor && ($prefillsuccessoruser = core_user::get_user($prefillsuccessor))) {
    $selectedsuccessor = [$prefillsuccessor => fullname($prefillsuccessoruser)];
}
$prefillsource = optional_param('source', 0, PARAM_INT);
$prefilltarget = optional_param('target', 0, PARAM_INT);
$prefillmakeleader = optional_param('makeleader', false, PARAM_BOOL);
$prefillreplaceleader = optional_param('replaceleader', false, PARAM_BOOL);
$replaces = optional_param('replaces', 0, PARAM_INT);

$groupoptions = [];
foreach ($DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()], 'name ASC') as $group) {
    $groupoptions[(int) $group->id] = format_string($group->name) . ' (' . $group->pluginuid . ', '
        . get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced') . ')';
}
$canbypass = has_capability('mod/selfselectadvanced:override', $context);

$form = new \mod_selfselectadvanced\form\move_form(null, [
    'cmid' => $cm->id,
    'selectedstudent' => $selectedstudent,
    'selectedsuccessor' => $selectedsuccessor,
    'groups' => $groupoptions,
    'canbypass' => $canbypass,
]);

$setdata = ['replaces' => $replaces];
if ($prefill) {
    $setdata['student'] = $prefill;
}
if ($prefillsource) {
    $setdata['source'] = $prefillsource;
}
if ($prefilltarget) {
    $setdata['target'] = $prefilltarget;
}
if ($prefillmakeleader) {
    $setdata['makeleader'] = 1;
}
if ($prefillreplaceleader) {
    $setdata['replaceleader'] = 1;
}
if ($prefillsuccessor) {
    $setdata['successor'] = $prefillsuccessor;
}
$form->set_data($setdata);

if ($form->is_cancelled()) {
    redirect($listurl);
}
if ($data = $form->get_data()) {
    try {
        $move = $api->moves()->stage(
            (int) $data->student,
            empty($data->source) ? null : (int) $data->source,
            (int) $data->target,
            !empty($data->makeleader),
            empty($data->successor) ? null : (int) $data->successor,
            (int) $USER->id,
            !empty($data->replaceleader)
        );
        if ($canbypass && !empty($data->bypass)) {
            \mod_selfselectadvanced\local\override\store::save(
                $activity,
                'move',
                (int) $move->id,
                ['rulesbypassed' => implode(',', array_map('clean_param_alphaext', (array) $data->bypass))],
                (int) $USER->id
            );
        }
        if (!empty($data->replaces)) {
            // Edit-and-restage: the replacement staged successfully, so
            // the dead-end move it was drafted from is retired. A race
            // where another manager already committed or cancelled it
            // meanwhile does not undo the new move that just staged.
            try {
                $api->moves()->cancel((int) $data->replaces, (int) $USER->id);
            } catch (dml_missing_record_exception $e) {
                // Another manager already committed or cancelled it: the
                // replacement move staged above still stands regardless.
                debugging($e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        redirect(
            $listurl,
            get_string('movestaged', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        // Map each known refusal to the field it is actually about; any
        // other refusal (including ones added later) lands on 'target',
        // the one field that is always present and always required.
        $fieldbyerror = [
            'errmovenotmember' => 'source',
            'errmovesuccessorrequired' => 'successor',
            'errmovebadsuccessor' => 'successor',
            'refusalmovesourcerequired' => 'source',
        ];
        $form->set_element_error($fieldbyerror[$e->errorcode] ?? 'target', $e->getMessage());
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('movestage', 'mod_selfselectadvanced'));
$form->display();
echo $OUTPUT->footer();

/**
 * Clean one bypass rule code.
 *
 * @param string $code raw code
 * @return string cleaned code
 */
function clean_param_alphaext(string $code): string {
    return clean_param($code, PARAM_ALPHANUM);
}
