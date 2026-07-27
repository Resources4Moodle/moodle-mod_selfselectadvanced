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
 * Pick a team (EOI 1.11.0): guides browse listed, still-forming,
 * guideless teams as cards and express interest with optional rich-text
 * remarks to the leader. The leader alone decides who guides the team
 * (fairness invariant); this page only ever raises or re-raises an
 * interest, never assigns one.
 *
 * GET renders the browse page, or (action=express) the one-team
 * express-interest view; the express POST is sesskey-protected.
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

// Express interest in one team: GET shows the confirm text and a
// rich-text remarks editor (existing 'eoiremarks' area, not
// file-backed - a plain textarea promoted to the core editor with no
// draft file area, equivalent to an editor element with maxfiles = 0);
// POST saves through eoi::express, the sole source of truth for every
// refusal (cap, duplicate, capacity, listing state).
if ($action === 'express') {
    $groupid = required_param('g', PARAM_INT);
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

    if (!$stilllistable) {
        // The team stopped being pickable between the browse page and
        // this view (unlisted, guided, or no longer forming): explain
        // why and send the guide back, with nothing that invites a
        // submission that eoi::express() would only have to refuse.
        echo $OUTPUT->notification(get_string('refusaleoinotlisted', 'mod_selfselectadvanced'), 'warning', false);
        echo html_writer::link($baseurl, get_string('back'), ['class' => 'btn btn-secondary']);
        echo $OUTPUT->footer();
        die;
    }

    echo html_writer::tag('p', get_string('eoipickconfirm', 'mod_selfselectadvanced', format_string($group->name)));

    $expressurl = new moodle_url('/mod/selfselectadvanced/pickteam.php', [
        'id' => $cm->id, 'action' => 'express', 'g' => $group->id,
    ]);
    $editorid = 'ssa-eoiremarks-' . $group->id;
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $expressurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'remarksformat', 'value' => FORMAT_HTML]);
    echo html_writer::label(get_string('eoiremarks', 'mod_selfselectadvanced'), $editorid);
    echo html_writer::tag(
        'textarea',
        s($prefillremarks),
        ['name' => 'remarks', 'id' => $editorid, 'rows' => 6, 'class' => 'form-control mb-2 w-100']
    );
    editors_head_setup();
    $editor = editors_get_preferred_editor(FORMAT_HTML);
    $editor->set_text($prefillremarks);
    $editor->use_editor($editorid, ['context' => $context, 'maxfiles' => 0, 'autosave' => false]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('eoipickteam', 'mod_selfselectadvanced'),
        'class' => 'btn btn-primary',
    ]);
    echo ' ' . html_writer::link($baseurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    die;
}

// Browse listed teams: most-interested first after listing order
// (eoi::listed_groups is the sole authority on that ordering).
$listedgroups = \mod_selfselectadvanced\local\eoi::listed_groups($activity);
$groupids = array_map(static fn($group) => (int) $group->id, $listedgroups);

$leaders = [];
if ($groupids) {
    $leaderids = array_values(array_unique(array_map(static fn($group) => (int) $group->leaderid, $listedgroups)));
    $namefields = implode(', ', \core_user\fields::for_name()->get_required_fields());
    $leaders = $DB->get_records_list('user', 'id', $leaderids, '', "id, $namefields");
}

$membercounts = \mod_selfselectadvanced\local\groups::count_confirmed_bulk($groupids);

// Guide peer names (eoipeers): one bulk query for every listed team's
// pending interests instead of one query per card.
$peersbygroup = [];
if (!empty($activity->settings()->eoipeers) && $groupids) {
    [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'gp');
    $params['pending'] = \mod_selfselectadvanced\local\eoi::STATUS_PENDING;
    $namefields = implode(', ', array_map(
        static fn(string $field) => 'u.' . $field,
        \core_user\fields::for_name()->get_required_fields()
    ));
    $peerrows = $DB->get_records_sql(
        "SELECT e.id, e.groupid, $namefields
           FROM {selfselectadvanced_eoi} e
           JOIN {user} u ON u.id = e.guideid
          WHERE e.groupid $insql AND e.status = :pending
       ORDER BY e.timecreated ASC",
        $params
    );
    foreach ($peerrows as $peerrow) {
        $peersbygroup[(int) $peerrow->groupid][] = fullname($peerrow);
    }
}

$cards = [];
foreach ($listedgroups as $group) {
    $groupid = (int) $group->id;
    $peernames = $peersbygroup[$groupid] ?? [];
    $cards[] = (object) [
        'groupid' => $groupid,
        'name' => format_string($group->name),
        'topic' => format_string($group->title),
        'leadername' => isset($leaders[(int) $group->leaderid]) ? fullname($leaders[(int) $group->leaderid]) : '',
        'membercount' => $membercounts[$groupid] ?? 0,
        'interestline' => get_string('eoiinterestcount', 'mod_selfselectadvanced', (int) $group->interestcount),
        'showpeers' => !empty($activity->settings()->eoipeers) && !empty($peernames),
        'peernames' => implode(', ', $peernames),
        'pickurl' => (new moodle_url('/mod/selfselectadvanced/pickteam.php', [
            'id' => $cm->id, 'action' => 'express', 'g' => $groupid,
        ]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/pickteam', (object) [
    'heading' => get_string('eoisettings', 'mod_selfselectadvanced'),
    'hascards' => !empty($cards),
    'cards' => $cards,
    'backurl' => (new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
