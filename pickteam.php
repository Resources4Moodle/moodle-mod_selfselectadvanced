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
 * Pick a team (EOI, scalability rework): guides browse listed,
 * still-forming teams as a native table_sql listing (sort, page,
 * keyword filter) instead of one card per team - infeasible once an
 * activity has thousands of listed teams. The leader alone decides
 * who guides the team (fairness invariant); this page only ever
 * raises or re-raises an interest, never assigns one.
 *
 * No 'g' param: the browse listing. With 'g': the single-team pick
 * view, GET showing the team card and a rich-text remarks editor,
 * POST (sesskey-protected) submitting through eoi::express().
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$groupid = optional_param('g', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:guide', $context);

$baseurl = new moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $cm->id]);

// The master switch gates every surface of the EOI feature (spec).
if (empty($activity->settings()->eoienabled)) {
    redirect(
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
        get_string('refusaleoidisabled', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($groupid > 0) {
    // Express interest in one team: POST saves through eoi::express,
    // the sole source of truth for every refusal (cap, duplicate,
    // capacity, listing state); GET only ever shows the form.
    $group = \mod_selfselectadvanced\local\groups::get($activity, $groupid);
    $expresserror = '';
    $prefillremarks = '';

    if (data_submitted() && confirm_sesskey()) {
        $remarks = optional_param('remarks', '', PARAM_RAW);
        $remarksformat = optional_param('remarksformat', FORMAT_HTML, PARAM_INT);
        try {
            \mod_selfselectadvanced\local\eoi::express($activity, $group->id, (int) $USER->id, $remarks, $remarksformat);
            redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            $expresserror = $e->getMessage();
            $prefillremarks = $remarks;
        }
    }

    $stilllistable = !empty($group->listed)
        && $group->state === \mod_selfselectadvanced\local\state::FORMING
        && empty($group->guideid);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('eoipickteam', 'mod_selfselectadvanced'));
    if ($expresserror !== '') {
        echo $OUTPUT->notification($expresserror, 'error', false);
    }

    $templatecontext = (object) ['pickable' => $stilllistable];
    if (!$stilllistable) {
        // The team stopped being pickable between the browse page and
        // this view (unlisted, guided, or no longer forming): explain
        // why and send the guide back, with nothing that invites a
        // submission that eoi::express() would only have to refuse.
        $templatecontext->notlistablemessage = get_string('refusaleoinotlisted', 'mod_selfselectadvanced');
        $templatecontext->backurl = $baseurl->out(false);
    } else {
        // Sequential rule (spec): a browsing guide never sees the
        // pending-interest count, or peer names, while eoisequential is
        // on. Off, today's behaviour is unchanged: count always shown,
        // names only when eoipeers is also on.
        $sequential = !empty($activity->settings()->eoisequential);
        $pendingcount = 0;
        $peernames = [];
        if (!$sequential) {
            $pendingcount = $DB->count_records('selfselectadvanced_eoi', [
                'groupid' => $group->id,
                'status' => \mod_selfselectadvanced\local\eoi::STATUS_PENDING,
            ]);
            if (!empty($activity->settings()->eoipeers) && $pendingcount) {
                $namefields = implode(', ', array_map(
                    static fn(string $field) => 'u.' . $field,
                    \core_user\fields::for_name()->get_required_fields()
                ));
                $peerrows = $DB->get_records_sql(
                    "SELECT e.id, $namefields
                       FROM {selfselectadvanced_eoi} e
                       JOIN {user} u ON u.id = e.guideid
                      WHERE e.groupid = :groupid AND e.status = :pending
                   ORDER BY e.timecreated ASC",
                    ['groupid' => $group->id, 'pending' => \mod_selfselectadvanced\local\eoi::STATUS_PENDING]
                );
                foreach ($peerrows as $peerrow) {
                    $peernames[] = fullname($peerrow);
                }
            }
        }

        $editorid = 'ssa-eoiremarks-' . $group->id;
        $expressurl = new moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $cm->id, 'g' => $group->id]);

        $templatecontext->name = format_string($group->name);
        $templatecontext->topic = format_string($group->title);
        $templatecontext->leadername = fullname(\core_user::get_user((int) $group->leaderid));
        $templatecontext->membercount = \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id);
        $templatecontext->showinterest = !$sequential;
        $templatecontext->interestline = get_string('eoiinterestcount', 'mod_selfselectadvanced', $pendingcount);
        $templatecontext->showpeers = !$sequential && !empty($activity->settings()->eoipeers) && !empty($peernames);
        $templatecontext->peernames = implode(', ', $peernames);
        $templatecontext->confirmtext = get_string('eoipickconfirm', 'mod_selfselectadvanced', format_string($group->name));
        $templatecontext->editorid = $editorid;
        $templatecontext->remarksvalue = $prefillremarks;
        $templatecontext->remarksformat = FORMAT_HTML;
        $templatecontext->expressurl = $expressurl->out(false);
        $templatecontext->sesskey = sesskey();
        $templatecontext->cancelurl = $baseurl->out(false);
    }

    echo $OUTPUT->render_from_template('mod_selfselectadvanced/pickteam', $templatecontext);

    if ($stilllistable) {
        editors_head_setup();
        $editor = editors_get_preferred_editor(FORMAT_HTML);
        $editor->set_text($prefillremarks);
        $editor->use_editor($editorid, ['context' => $context, 'maxfiles' => 0, 'autosave' => false]);
    }

    echo $OUTPUT->footer();
    die;
}

// Browse listed teams: a native table_sql listing (scalability rework)
// replaces the old unbounded card-per-team fetch. eoi::listed_groups()
// and its bulk leader/peer queries are no longer used here.
$rq = optional_param('rq', '', PARAM_RAW_TRIMMED);
$perpage = \mod_selfselectadvanced\local\perpage::current(50);
$tableurl = new moodle_url($baseurl, $rq !== '' ? ['rq' => $rq] : []);
$table = new \mod_selfselectadvanced\table\pickteam_table(
    'ssapickteam',
    $activity,
    new moodle_url($tableurl, ['perpage' => $perpage]),
    $rq,
    !empty($activity->settings()->eoisequential)
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('eoisettings', 'mod_selfselectadvanced'));
echo html_writer::tag('p', get_string('pickteamintro', 'mod_selfselectadvanced'), ['class' => 'text-muted']);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out_omit_querystring(),
    'class' => 'd-inline-flex gap-2 align-items-center mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'rq', 'value' => $rq,
    'class' => 'form-control w-auto', 'placeholder' => get_string('pickteamnamefilter', 'mod_selfselectadvanced')]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::div(\mod_selfselectadvanced\local\perpage::controls($tableurl), 'mb-3');
$table->out($perpage, true);

echo html_writer::link(
    new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
    get_string('back'),
    ['class' => 'btn btn-secondary mt-3']
);
echo $OUTPUT->footer();
