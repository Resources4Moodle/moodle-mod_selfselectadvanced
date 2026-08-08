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
// Staging a move is composition work, so the narrow capability reaches
// it as well as the full manage power. The exception names the NARROW
// one: a refusal should tell an administrator the least privilege that
// would have opened the page, not the largest.
if (!has_any_capability(['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:managecomposition'], $context)) {
    throw new required_capability_exception($context, 'mod/selfselectadvanced:managecomposition', 'nopermissions', '');
}

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
/**
 * One team as an id => label pair for a picker's initial value.
 *
 * @param \mod_selfselectadvanced\activity $activity the activity
 * @param int $groupid the team, or 0 for none
 * @return array empty, or [id => label]
 */
function selfselectadvanced_move_group_label($activity, int $groupid): array {
    global $DB;

    if ($groupid <= 0) {
        return [];
    }
    $group = $DB->get_record('selfselectadvanced_group', ['id' => $groupid, 'activityid' => $activity->id()]);
    if (!$group) {
        return [];
    }

    return [$groupid => format_string($group->name) . ' (' . $group->pluginuid . ')'];
}

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
// A park has no target, so nothing else in this prefill set records
// that the pending row it restages was one (D6-2).
$prefillpark = optional_param('park', false, PARAM_BOOL);
$prefillmakeleader = optional_param('makeleader', false, PARAM_BOOL);
$prefillreplaceleader = optional_param('replaceleader', false, PARAM_BOOL);
$replaces = optional_param('replaces', 0, PARAM_INT);

// Only the teams already chosen are loaded, so the picker has
// something to show before anybody searches. The full list is never
// built: at fifteen hundred teams it was the page's whole cost
// (strategy 1.18 B).
$selectedsource = selfselectadvanced_move_group_label($activity, $prefillsource);
$selectedtarget = selfselectadvanced_move_group_label($activity, $prefilltarget);
// Decision 6: the hatch is a NAMED authority now, not a side effect of
// "may edit the override table". clonepermissionsfrom in db/access.php
// gives every role that held :override the new capability on upgrade,
// so no site loses bypass authority and no OR-check is needed here.
$canbypass = has_capability('mod/selfselectadvanced:overriderules', $context);

// Prefill from a "Override this rule…" link beside a red chip on
// moves.php: the codes are intersected with the legal five, so a
// crafted URL cannot pre-tick anything else (and the checkbox is only
// rendered for holders anyway).
$prefillbypass = array_values(array_intersect(
    optional_param_array('bypass', [], PARAM_ALPHANUM),
    \mod_selfselectadvanced\local\moves::BYPASSABLE
));

$form = new \mod_selfselectadvanced\form\move_form(null, [
    'cmid' => $cm->id,
    'selectedstudent' => $selectedstudent,
    'selectedsuccessor' => $selectedsuccessor,
    'selectedsource' => $selectedsource,
    'selectedtarget' => $selectedtarget,
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
if ($canbypass && $prefillpark) {
    // The checkbox only exists for holders, so a crafted park=1 from
    // anybody else prefills nothing - and stage() refuses a park from
    // a non-holder in any case.
    $setdata['park'] = 1;
}
if ($canbypass && $prefillbypass) {
    // Matches the group element names: move_form builds
    // bypassgroup[{CODE}].
    $setdata['bypassgroup'] = array_fill_keys($prefillbypass, 1);
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
            // A park is a removal with no destination team (D6-2).
            empty($data->park) ? (int) $data->target : null,
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
                ['rulesbypassed' => implode(',', array_map('selfselectadvanced_clean_param_alphaext', (array) $data->bypass))],
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
            } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
                // Another manager already committed or cancelled it: the
                // replacement move staged above still stands regardless.
                // cancel() answers that race with a typed refusal now, so
                // a catch pinned to the missing-record exception matched
                // nothing and the refusal fell through to the outer arm,
                // which painted "the stage failed" on a field of a stage
                // that had in fact succeeded - and the manager, believing
                // it, submitted a second identical move. A foreign or
                // unknown id still raises MUST_EXIST loudly and is NOT
                // caught here, because that is a crafted request rather
                // than a race.
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
        // other TYPED refusal (including ones added later) lands on
        // 'target', the one field that is always present and always
        // required. An untyped code outside this validation map is a
        // genuine failure and stays loud - the rethrow below.
        $fieldbyerror = [
            'errmovenotmember' => 'source',
            'errmovesuccessorrequired' => 'successor',
            'errmovebadsuccessor' => 'successor',
            'errmovesololeader' => 'successor',
            'errmovenotparticipant' => 'student',
            'errmoveparkcapability' => 'target',
            'errmoveparknolead' => 'makeleader',
            'errmoveparkandtarget' => 'target',
            'refusalmovesourcerequired' => 'source',
            // Both are target-side refusals and both are actionable on
            // the target: the student is already in the team named
            // there. Mapped rather than left to the fallback, and on
            // the same field move_form's own same-group check uses, so
            // the two seams cannot contradict each other. stage()
            // raises errmovesamegroup for a source the form never sees,
            // because a BLANK source is inferred to the target.
            'errmovesamegroup' => 'target',
            'refusalmovetargetalready' => 'target',
        ];
        if (
            !($e instanceof \mod_selfselectadvanced\local\workflow_refusal)
            && !isset($fieldbyerror[$e->errorcode])
        ) {
            throw $e;
        }
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
 * Frankenstyle-prefixed deliberately. The former name, clean_param_alphaext(),
 * was a global function sitting in core's own clean_param_* naming space, so
 * any core or plugin declaring that name would have collided fatally with it.
 * Reported by the plugins directory review (issue 2, 2026-07-30).
 *
 * @param string $code raw code
 * @return string cleaned code
 */
function selfselectadvanced_clean_param_alphaext(string $code): string {
    return clean_param($code, PARAM_ALPHANUM);
}
