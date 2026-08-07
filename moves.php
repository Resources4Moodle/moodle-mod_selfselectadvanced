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
 * Staged moves (spec 7): the pending list with per-rule validation
 * chips, joint commit of a selected set, and cancel. Staging happens
 * on moveedit.php.
 *
 * The list is PAGED (decision 6, D6-8): it used to load every pending
 * move of the activity, validate the whole set jointly and then call
 * groups::get() twice and core_user::get_user() once per row. One page
 * is now fetched, validated and labelled with two batched queries.
 *
 * Committing a bypassed set interposes a confirmation page that names
 * every overridden rule with its figures and requires a typed reason
 * (decision 6, D6-1/D6-6): the bypass is an authority, not a checkbox.
 *
 * GET renders; commit and cancel are sesskey-protected POSTs.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_selfselectadvanced\local\moves;
use mod_selfselectadvanced\local\perpage;
use mod_selfselectadvanced\table\moves_table;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
// The pending queue, its commit and its cancel are composition work,
// so the narrow capability reaches them as well as the full manage
// power. The exception names the NARROW one (least privilege). Commit
// and cancel need no separate gate: they sit below this one, and the
// conflict-of-interest guard runs in the engine against rows re-read
// inside its locks.
if (!has_any_capability(['mod/selfselectadvanced:manage', 'mod/selfselectadvanced:managecomposition'], $context)) {
    throw new required_capability_exception($context, 'mod/selfselectadvanced:managecomposition', 'nopermissions', '');
}

$api = new \mod_selfselectadvanced\local\api($activity);
$baseurl = new moodle_url('/mod/selfselectadvanced/moves.php', ['id' => $cm->id]);
$canoverride = has_capability('mod/selfselectadvanced:overriderules', $context);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'cancel' && data_submitted() && confirm_sesskey()) {
    $moveid = required_param('move', PARAM_INT);
    try {
        $api->moves()->cancel($moveid, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // Same contract as group.php's arms (1.20.19): a refusal is an
        // answer, delivered as a notice, never the raw error page.
        redirect(
            $baseurl,
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    redirect(
        $baseurl,
        get_string('movecancelled', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'commit' && data_submitted() && confirm_sesskey()) {
    $selected = optional_param_array('selected', [], PARAM_INT);
    if (!$selected) {
        redirect(
            $baseurl,
            get_string('movenoneselected', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    if (count($selected) > moves::MAX_COMMIT) {
        // Checked before the pre-flight validation, so an oversized
        // selection costs one comparison rather than a full validate.
        redirect(
            $baseurl,
            get_string('errmovetoomanyselected', 'mod_selfselectadvanced', moves::MAX_COMMIT),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $confirmed = optional_param('confirmoverrides', 0, PARAM_BOOL);
    $overridereason = trim(optional_param('overridereason', '', PARAM_TEXT));

    // Pre-flight only: commit_set() re-validates the same set inside
    // its locks and that is the authority. This exists so the manager
    // sees WHAT they are about to override before they do it.
    $preflight = $api->moves()->validate_set($selected);
    $overridden = [];
    foreach ($preflight->permove as $preflightmoveid => $moveverdicts) {
        foreach ($moveverdicts as $rulecode => $verdict) {
            if (!empty($verdict['bypassed'])) {
                $overridden[(int) $preflightmoveid][$rulecode] = (string) $verdict['reason'];
            }
        }
    }

    if ($overridden && !$confirmed) {
        // Renders only; nothing mutates until the confirmed POST below.
        $confirmrows = [];
        $moverows = $DB->get_records_list(
            'selfselectadvanced_move',
            'id',
            array_keys($overridden),
            'id ASC'
        );
        $labelgroupids = [];
        foreach ($moverows as $moverow) {
            foreach ([$moverow->sourcegroupid, $moverow->targetgroupid] as $labelid) {
                if ($labelid) {
                    $labelgroupids[(int) $labelid] = true;
                }
            }
        }
        $grouplabels = moves_table::group_labels($activity, array_keys($labelgroupids));
        $userlabels = moves_table::user_labels(array_map(
            static fn($row) => (int) $row->userid,
            $moverows
        ));
        foreach ($moverows as $moverow) {
            $rules = [];
            foreach ($overridden[(int) $moverow->id] as $rulecode => $figures) {
                $rules[] = (object) ['rule' => $rulecode, 'figures' => $figures];
            }
            $confirmrows[] = (object) [
                'user' => $userlabels[(int) $moverow->userid] ?? (string) $moverow->userid,
                'source' => $moverow->sourcegroupid
                    ? ($grouplabels[(int) $moverow->sourcegroupid] ?? '')
                    : get_string('movenosource', 'mod_selfselectadvanced'),
                'target' => $moverow->targetgroupid
                    ? ($grouplabels[(int) $moverow->targetgroupid] ?? '')
                    : get_string('movenotarget', 'mod_selfselectadvanced'),
                'rules' => $rules,
            ];
        }

        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template('mod_selfselectadvanced/moves_confirm', (object) [
            'rows' => $confirmrows,
            'selected' => array_map(static fn($sel) => ['moveid' => (int) $sel], $selected),
            'actionurl' => $baseurl->out(false),
            'cmid' => $cm->id,
            'sesskey' => sesskey(),
            'cancelurl' => $baseurl->out(false),
        ]);
        echo $OUTPUT->footer();
        die;
    }
    if ($overridden && $overridereason === '') {
        redirect(
            $baseurl,
            get_string('errmoveoverridereasonrequired', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    try {
        $unusednotifications = null;
        $unusedsync = null;
        $unusedevents = null;
        $count = $api->moves()->commit_set(
            $selected,
            (int) $USER->id,
            false,
            $unusednotifications,
            $unusedsync,
            $overridereason,
            $unusedevents
        );
        redirect(
            $baseurl,
            get_string('movescommitted', 'mod_selfselectadvanced', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// One page of the queue, chronological, bounded by the user's page size.
$total = $DB->count_records('selfselectadvanced_move', [
    'activityid' => $activity->id(),
    'status' => 'pending',
]);
$perpage = perpage::current();
$pagenum = optional_param('page', 0, PARAM_INT);
$pending = $DB->get_records(
    'selfselectadvanced_move',
    ['activityid' => $activity->id(), 'status' => 'pending'],
    'timecreated ASC, id ASC',
    '*',
    $pagenum * $perpage,
    $perpage
);

// Validating page-by-page narrows the DISPLAYED joint set to the page.
// That is acceptable because the display was always advisory:
// commit_set() re-validates the actual selected set inside its locks,
// and the selection is what the manager committed.
$verdicts = $pending ? $api->moves()->validate_set(array_keys($pending)) : null;

// Two batched lookups for the whole page, never a query per row.
$labelgroupids = [];
foreach ($pending as $move) {
    foreach ([$move->sourcegroupid, $move->targetgroupid] as $labelid) {
        if ($labelid) {
            $labelgroupids[(int) $labelid] = true;
        }
    }
}
$grouplabels = moves_table::group_labels($activity, array_keys($labelgroupids));
$userlabels = moves_table::user_labels(array_map(
    static fn($move) => (int) $move->userid,
    $pending
));

$rows = [];
foreach ($pending as $move) {
    $moveid = (int) $move->id;
    $userlabel = $userlabels[(int) $move->userid] ?? (string) $move->userid;
    $sourcelabel = $move->sourcegroupid
        ? ($grouplabels[(int) $move->sourcegroupid] ?? '')
        : get_string('movenosource', 'mod_selfselectadvanced');
    $targetlabel = $move->targetgroupid
        ? ($grouplabels[(int) $move->targetgroupid] ?? '')
        : get_string('movenotarget', 'mod_selfselectadvanced');

    $chips = '';
    $allok = true;
    foreach ($verdicts->permove[$moveid] ?? [] as $rule => $verdict) {
        if ($verdict['ok']) {
            $badgeclass = 'bg-success';
        } else if ($verdict['bypassed']) {
            $badgeclass = 'bg-warning text-dark';
        } else {
            $badgeclass = 'bg-danger';
        }
        $chip = html_writer::span(
            $rule . ($verdict['bypassed'] ? ' ' . get_string('movebypassed', 'mod_selfselectadvanced') : ''),
            'badge ' . $badgeclass
        );
        $chip .= html_writer::tag('small', $verdict['reason'], [
            'class' => 'ssa-rulechip-reason d-block text-muted',
        ]);
        // The override affordance beside the red chip itself (D6-1):
        // the hatch used to exist only on the staging form, nowhere
        // near the rule that refused. LEADR and SUCC get no link -
        // their reason strings already name their remedy, and
        // errmovesololeader's text names dissolve.
        if (
            $canoverride && !$verdict['ok'] && !$verdict['bypassed']
            && in_array($rule === 'L3S' ? 'L3' : $rule, moves::BYPASSABLE, true)
        ) {
            $overrideurl = new moodle_url('/mod/selfselectadvanced/moveedit.php', [
                'id' => $cm->id,
                'student' => (int) $move->userid,
                'source' => (int) $move->sourcegroupid,
                'target' => (int) $move->targetgroupid,
                // Without this a park restages as an ordinary move with
                // no destination, which the form then refuses as a
                // missing target - the one shape this affordance exists
                // to keep reachable.
                'park' => (int) !$move->targetgroupid,
                'makeleader' => (int) $move->makeleader,
                'replaceleader' => (int) $move->replaceleader,
                'successor' => (int) $move->successorid,
                'replaces' => $moveid,
                'bypass' => [$rule === 'L3S' ? 'L3' : $rule],
            ]);
            $chip .= html_writer::link(
                $overrideurl,
                get_string('moveoverridethisrule', 'mod_selfselectadvanced'),
                ['class' => 'small d-block']
            );
        }
        // The per-rule class makes each chip addressable - by a test,
        // and by any site CSS that wants to style one rule.
        $chips .= html_writer::div($chip, 'ssa-rulechip ssa-rulechip-' . $rule);
        if (!$verdict['ok'] && !$verdict['bypassed']) {
            $allok = false;
        }
    }

    $checkboxattrs = [
        'type' => 'checkbox',
        'name' => 'selected[]',
        'value' => $moveid,
        'id' => 'moveselect-' . $moveid,
    ];
    if ($allok) {
        $checkboxattrs['checked'] = 'checked';
    }
    $select = html_writer::empty_tag('input', $checkboxattrs)
        . html_writer::label(
            get_string('select') . ' ' . $userlabel,
            'moveselect-' . $moveid,
            true,
            ['class' => 'visually-hidden']
        );

    $student = $userlabel;
    if ($move->makeleader) {
        $student .= ' ' . html_writer::span(
            get_string('leader', 'mod_selfselectadvanced'),
            'badge bg-primary'
        );
    }

    $restageurl = new moodle_url('/mod/selfselectadvanced/moveedit.php', [
        'id' => $cm->id,
        'student' => (int) $move->userid,
        'source' => (int) $move->sourcegroupid,
        'target' => (int) $move->targetgroupid,
        'park' => (int) !$move->targetgroupid,
        'makeleader' => (int) $move->makeleader,
        'replaceleader' => (int) $move->replaceleader,
        'successor' => (int) $move->successorid,
        'replaces' => $moveid,
    ]);
    $actions = html_writer::link(
        $restageurl,
        get_string('moveeditrestage', 'mod_selfselectadvanced'),
        ['class' => 'btn btn-outline-primary btn-sm']
    ) . ' ' . html_writer::tag(
        'button',
        get_string('movecancel', 'mod_selfselectadvanced', $userlabel),
        [
            'type' => 'submit',
            'form' => 'cancelmove-' . $moveid,
            'class' => 'btn btn-outline-secondary btn-sm',
        ]
    );

    $rows[] = (object) [
        'moveid' => $moveid,
        'select' => $select,
        'student' => $student,
        'from' => $sourcelabel,
        'to' => $targetlabel,
        'validation' => $chips,
        'actions' => $actions,
    ];
}

echo $OUTPUT->header();
echo html_writer::start_div('selfselectadvanced-moves');
echo $OUTPUT->heading(get_string('pendingmoves', 'mod_selfselectadvanced'), 3);
echo html_writer::tag('p', get_string('movesintro', 'mod_selfselectadvanced'), ['class' => 'text-muted']);

if ($rows) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'commit']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $table = new moves_table('selfselectadvanced-moves', $baseurl);
    $table->display_rows($rows, $perpage, $total);
    echo html_writer::tag(
        'button',
        get_string('movecommitselected', 'mod_selfselectadvanced'),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    );
    echo html_writer::end_tag('form');
    // Outside the commit form: a <form> cannot nest, so each row's
    // cancel button is reconnected to its own empty form by form="".
    foreach ($rows as $row) {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $baseurl->out(false),
            'id' => 'cancelmove-' . $row->moveid,
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'cancel']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'move', 'value' => $row->moveid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::div(perpage::controls($baseurl), 'mt-2');
} else {
    echo html_writer::tag('p', get_string('movesnone', 'mod_selfselectadvanced'), ['class' => 'text-muted']);
}

echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cm->id]),
        get_string('movestage', 'mod_selfselectadvanced'),
        ['class' => 'btn btn-primary']
    )
    . ' ' . html_writer::link(
        new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
        get_string('back'),
        ['class' => 'btn btn-secondary ms-2']
    ),
    'mt-3'
);
echo html_writer::end_div();
echo $OUTPUT->footer();
