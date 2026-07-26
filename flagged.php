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
 * The flagged report (spec 9.4, 11, 8.1, 4A.8, M1): groupless
 * students awaiting placement, students with missing attributes,
 * leaderless groups, and grandfathered out-of-limit groups.
 * Read-only GET; placement happens via staged moves.
 *
 * The defaulters, guides and quota tabs (audit round 6 item 6) are
 * table_sql/flexible_table so sorting and paging are native; the
 * students tab keeps its own mustache template and div wrapper (Behat
 * asserts its exact strings and css class), but its missing-attributes
 * and anomalies sections are now flexible_table HTML rendered into
 * that template (audit round 8 item 3), alongside the groupless list,
 * which stays hand-paginated.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
require_capability('mod/selfselectadvanced:viewall', $context);

$api = new \mod_selfselectadvanced\local\api($activity);
$resolver = $api->gatekeeper()->resolver();

$tab = optional_param('tab', 'students', PARAM_ALPHA);
$pagenum = optional_param('page', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$tsort = optional_param('tsort', '', PARAM_ALPHANUMEXT);
$tdir = optional_param('tdir', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$perpage = \mod_selfselectadvanced\local\perpage::current(20);
$canmanage = has_capability('mod/selfselectadvanced:manage', $context);
$PAGE->set_url('/mod/selfselectadvanced/flagged.php', ['id' => $cm->id, 'tab' => $tab]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$minmembership = (int) $activity->settings()->minmembership;
$windowsecs = (int) $activity->settings()->guidewindow;

// Groupless students: enrolled respond-holders with no confirmed row.
// Only the id and the name fields fullname() needs are selected: u.*
// was measured at 76MB for 10,000 students.
$namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$enrolled = get_enrolled_users(
    $context,
    'mod/selfselectadvanced:respond',
    0,
    "u.id, $namefields",
    'lastname, firstname'
);
$confirmedids = $DB->get_fieldset_sql(
    "SELECT DISTINCT m.userid
       FROM {selfselectadvanced_member} m
       JOIN {selfselectadvanced_group} g ON g.id = m.groupid
      WHERE g.activityid = ? AND m.status = ?",
    [$activity->id(), \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
);
$attrs = \mod_selfselectadvanced\local\attributes\manager::get_for_users(array_keys($enrolled));
// Hash set built once: a linear scan rebuilt per iteration costs
// seconds of pure CPU on a course of several thousand students.
$confirmedset = array_flip(array_map('intval', $confirmedids));
$groupless = [];
$missingattrs = [];
foreach ($enrolled as $user) {
    $attrline = \mod_selfselectadvanced\local\attributes\manager::display_line(
        $attrs[(int) $user->id] ?? null,
        true
    );
    if (!isset($confirmedset[(int) $user->id])) {
        $groupless[] = (object) [
            'fullname' => fullname($user),
            'attrline' => $attrline,
            'attrplain' => \mod_selfselectadvanced\local\attributes\manager::plain_line(
                $attrs[(int) $user->id] ?? null,
                true
            ),
            'placeurl' => (new moodle_url('/mod/selfselectadvanced/moveedit.php', ['id' => $cm->id]))->out(false),
        ];
    }
    if (!isset($attrs[(int) $user->id])) {
        $missingattrs[] = (object) ['fullname' => fullname($user)];
    }
}

// Group anomalies (leaderless, M1; out-of-limit grandfathered, 4A.8)
// and quota-failing groups (tab) share one fetch of the activity's
// groups. The per-group counts and the quota verdict, formerly a
// query (or five) per group, are now each loaded in bulk once for the
// whole set (see groups::count_confirmed_bulk(), count_seats_taken_bulk()
// and evaluator::compliance_for_activity()); effective_minsize() and
// effective_maxsize() stay per-group calls because the shared resolver
// caches every override row after its first query, so they cost
// nothing extra here.
$allgroups = $DB->get_records('selfselectadvanced_group', ['activityid' => $activity->id()]);
$allgroupids = array_map(static fn($group) => (int) $group->id, $allgroups);
$confirmedcounts = \mod_selfselectadvanced\local\groups::count_confirmed_bulk($allgroupids);
$seatstakencounts = \mod_selfselectadvanced\local\groups::count_seats_taken_bulk($allgroupids);

$anomalies = [];
foreach ($allgroups as $group) {
    $issues = [];
    if (empty($group->leaderid)) {
        $issues[] = get_string('flagleaderless', 'mod_selfselectadvanced');
    }
    $confirmed = $confirmedcounts[(int) $group->id];
    $seats = $seatstakencounts[(int) $group->id];
    $min = $resolver->effective_minsize((int) $group->id)->value;
    $max = $resolver->effective_maxsize((int) $group->id)->value;
    if ($confirmed < $min || $seats > $max) {
        $issues[] = get_string('flagoutoflimit', 'mod_selfselectadvanced', (object) [
            'confirmed' => $confirmed,
            'seats' => $seats,
            'min' => $min,
            'max' => $max,
        ]);
    }
    if ($issues) {
        $anomalies[] = (object) [
            'name' => format_string($group->name),
            'pluginuid' => $group->pluginuid,
            'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced'),
            'issues' => implode(' ', $issues),
            'url' => (new moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cm->id,
                'g' => $group->id,
            ]))->out(false),
        ];
    }
}

// Quota-failing groups (tab): only forming and pending-guide groups
// are candidates. Their compliance is evaluated in one pass over the
// whole candidate set through compliance_for_activity(), instead of
// one is_compliant() call, about five queries, per candidate group.
$quotastates = [\mod_selfselectadvanced\local\state::FORMING, \mod_selfselectadvanced\local\state::PENDING_GUIDE];
$quotacandidates = [];
foreach ($allgroups as $fgroup) {
    if (in_array($fgroup->state, $quotastates, true)) {
        $quotacandidates[] = (int) $fgroup->id;
    }
}
$compliance = \mod_selfselectadvanced\local\quota\evaluator::compliance_for_activity($activity, $quotacandidates);

$quotafail = [];
foreach ($allgroups as $fgroup) {
    $fgroupid = (int) $fgroup->id;
    if (!isset($compliance[$fgroupid]) || $compliance[$fgroupid]) {
        continue;
    }
    $quotafail[] = (object) [
        'name' => format_string($fgroup->name),
        'rawname' => $fgroup->name,
        'pluginuid' => $fgroup->pluginuid,
        'statelabel' => get_string('state' . str_replace('_', '', $fgroup->state), 'mod_selfselectadvanced'),
    ];
}

// Name filter (2026-07-25: usable at hundreds of rows). Defaulters and
// guides filter in SQL (their own table classes); the students tab's
// groupless, missing-attributes and anomalies lists and the PHP-built
// quota list filter here.
if ($q !== '') {
    $needle = \core_text::strtolower($q);
    $match = static fn(string $hay) => \core_text::strpos(\core_text::strtolower($hay), $needle) !== false;
    $groupless = array_values(array_filter($groupless, static fn($r) => $match($r->fullname)));
    $missingattrs = array_values(array_filter($missingattrs, static fn($r) => $match($r->fullname)));
    $anomalies = array_values(array_filter($anomalies, static fn($r) => $match($r->name)));
    $quotafail = array_values(array_filter($quotafail, static fn($r) => $match($r->name)));
}

// Sorting: only the students tab's groupless list is still hand-sorted
// here. The defaulters/guides/quota tabs use flexible_table's native
// tsort/tdir handling instead (see their respective table classes).
if ($tsort === 'fullname') {
    usort($groupless, static fn($a, $b) => \core_collator::compare($a->fullname, $b->fullname));
    if ($tdir) {
        $groupless = array_reverse($groupless);
    }
}

// Multi-format export (ODS / Excel / CSV / TXT, admin default). Every
// tab exports the full filtered dataset, built from raw values
// independently of the paginated display tables.
if ($download !== '') {
    if ($tab === 'defaulters') {
        \mod_selfselectadvanced\local\exporter::download(
            'flagged-defaulters',
            [get_string('member', 'mod_selfselectadvanced'),
                get_string('defaultershas', 'mod_selfselectadvanced'),
                get_string('defaultersmissing', 'mod_selfselectadvanced')],
            \mod_selfselectadvanced\table\flagged_defaulters_table::export_rows($activity, $minmembership, $q),
            $download
        );
    } else if ($tab === 'guides') {
        \mod_selfselectadvanced\local\exporter::download(
            'flagged-guides-pending',
            [get_string('groupname', 'mod_selfselectadvanced'), get_string('pluginid', 'mod_selfselectadvanced'),
                get_string('guide', 'mod_selfselectadvanced'), get_string('flaggedsubmitted', 'mod_selfselectadvanced'),
                get_string('flaggeddecideby', 'mod_selfselectadvanced'), get_string('flaggedoverdue', 'mod_selfselectadvanced'),
                get_string('flaggedguideloadused', 'mod_selfselectadvanced'),
                get_string('flaggedguideloadmax', 'mod_selfselectadvanced')],
            array_map(
                static fn($r) => [$r->rawname, $r->pluginuid, $r->guidename, $r->since,
                $r->deadline,
                $r->overdue ? get_string('yes') : get_string('no'),
                $r->guideloadused, $r->guideloadmax],
                \mod_selfselectadvanced\table\flagged_guides_table::export_rows($activity, $resolver, $windowsecs, $q)
            ),
            $download
        );
    } else if ($tab === 'quota') {
        \mod_selfselectadvanced\local\exporter::download(
            'flagged-quota-failing',
            [get_string('groupname', 'mod_selfselectadvanced'), get_string('pluginid', 'mod_selfselectadvanced'),
                get_string('state', 'mod_selfselectadvanced')],
            array_map(static fn($r) => [$r->rawname, $r->pluginuid, $r->statelabel], $quotafail),
            $download
        );
    } else {
        \mod_selfselectadvanced\local\exporter::download(
            'flagged-students',
            [get_string('member', 'mod_selfselectadvanced'), get_string('participantattributes', 'mod_selfselectadvanced')],
            array_map(static fn($r) => [$r->fullname, $r->attrplain], $groupless),
            $download
        );
    }
}

// Cheap counts for the tab labels: defaulters and guides run a plain
// COUNT(*) over the same FROM/WHERE as their display tables; quota
// reuses the array already built above (its check is PHP-only).
$tabbase = new moodle_url('/mod/selfselectadvanced/flagged.php', ['id' => $cm->id]);
$defaulterscount = \mod_selfselectadvanced\table\flagged_defaulters_table::count_rows($activity, $minmembership, $q);
$guidescount = \mod_selfselectadvanced\table\flagged_guides_table::count_rows($activity, $q);

// Bulk nudge actions (manager-only): message every currently listed
// defaulter, or nudge the guide of every currently listed overdue
// group, one message per guide even when they hold several overdue
// groups. POST + sesskey + confirmation step; messages are sent after
// all reads, never inside a transaction (audit round 6 item 4), and
// only ever from a GET confirmation page or its own POST (never a
// bare GET with a leaked sesskey).
if ($tab === 'defaulters' && $action === 'nudgedefaulters') {
    require_capability('mod/selfselectadvanced:manage', $context);
    $backurl = new moodle_url($tabbase, ['tab' => 'defaulters'] + ($q !== '' ? ['q' => $q] : []));
    $confirmurl = new moodle_url(
        $tabbase,
        ['tab' => 'defaulters', 'action' => 'nudgedefaulters'] + ($q !== '' ? ['q' => $q] : [])
    );
    $recipients = \mod_selfselectadvanced\table\flagged_defaulters_table::recipient_ids($activity, $minmembership, $q);
    if (data_submitted()) {
        require_sesskey();
        // Each recipient's due date can differ (an override resolves per
        // user), so recipients are bucketed by that value: one adhoc
        // task is queued per distinct due date, each carrying a single
        // shared $a for every recipient in its bucket. This moves the
        // send out of the request entirely (SCALE); nothing is sent
        // synchronously here any more.
        $buckets = [];
        foreach ($recipients as $userid) {
            $due = (int) $resolver->effective_dates($userid)->timedue;
            $buckets[$due][] = $userid;
        }
        foreach ($buckets as $due => $bucketids) {
            $task = new \mod_selfselectadvanced\task\send_nudges();
            $task->set_custom_data([
                'activityid' => $activity->id(),
                'provider' => 'deadlinereminder',
                'subjectkey' => 'msgremindersubject',
                'bodykey' => 'msgreminderbody',
                'userids' => $bucketids,
                'a' => ['activity' => $activity->name(), 'due' => userdate($due)],
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
        redirect(
            $backurl,
            get_string('nudgedefaultersqueued', 'mod_selfselectadvanced', count($recipients)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    if (!$recipients) {
        echo $OUTPUT->notification(get_string('nudgenonetosend', 'mod_selfselectadvanced'), 'info', false);
        echo $OUTPUT->continue_button($backurl);
    } else {
        echo $OUTPUT->confirm(
            get_string('nudgedefaultersconfirm', 'mod_selfselectadvanced', count($recipients)),
            new single_button($confirmurl, get_string('nudgedefaulters', 'mod_selfselectadvanced'), 'post'),
            $backurl
        );
    }
    echo $OUTPUT->footer();
    die;
}
if ($tab === 'guides' && $action === 'nudgeguides') {
    require_capability('mod/selfselectadvanced:manage', $context);
    $backurl = new moodle_url($tabbase, ['tab' => 'guides'] + ($q !== '' ? ['q' => $q] : []));
    $confirmurl = new moodle_url(
        $tabbase,
        ['tab' => 'guides', 'action' => 'nudgeguides'] + ($q !== '' ? ['q' => $q] : [])
    );
    $overduecounts = \mod_selfselectadvanced\table\flagged_guides_table::overdue_guide_counts($activity, $windowsecs, $q);
    if (data_submitted()) {
        require_sesskey();
        // Each guide's overdue count can differ, so guides are bucketed
        // by that value: one adhoc task is queued per distinct count,
        // each carrying a single shared $a for every guide in its
        // bucket. This moves the send out of the request entirely
        // (SCALE); nothing is sent synchronously here any more. The
        // deep link still points at the guide review queue, not the
        // generic activity view, so send_nudges is given an explicit
        // contexturl/contextname.
        $buckets = [];
        foreach ($overduecounts as $guideid => $overduecount) {
            $buckets[$overduecount][] = $guideid;
        }
        $guidecontexturl = new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);
        foreach ($buckets as $overduecount => $guideids) {
            $task = new \mod_selfselectadvanced\task\send_nudges();
            $task->set_custom_data([
                'activityid' => $activity->id(),
                'provider' => 'guidequeue',
                'subjectkey' => 'msgnudgeguidesubject',
                'bodykey' => 'msgnudgeguidebody',
                'userids' => $guideids,
                'a' => ['activity' => $activity->name(), 'count' => $overduecount],
                'contexturl' => $guidecontexturl->out(false),
                'contextname' => $activity->name(),
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
        redirect(
            $backurl,
            get_string('nudgeguidesqueued', 'mod_selfselectadvanced', count($overduecounts)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    if (!$overduecounts) {
        echo $OUTPUT->notification(get_string('nudgenonetosend', 'mod_selfselectadvanced'), 'info', false);
        echo $OUTPUT->continue_button($backurl);
    } else {
        echo $OUTPUT->confirm(
            get_string('nudgeguidesconfirm', 'mod_selfselectadvanced', count($overduecounts)),
            new single_button($confirmurl, get_string('nudgeguides', 'mod_selfselectadvanced'), 'post'),
            $backurl
        );
    }
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->header();
// Tabs keep each list on its own page with fixed-size pagination
// (item 04: less scrolling, options visible).
$tabs = [
    new tabobject(
        'students',
        new moodle_url($tabbase, ['tab' => 'students']),
        get_string('flaggedtabstudents', 'mod_selfselectadvanced', count($groupless))
    ),
    new tabobject(
        'defaulters',
        new moodle_url($tabbase, ['tab' => 'defaulters']),
        get_string('flaggedtabdefaulters', 'mod_selfselectadvanced', $defaulterscount)
    ),
    new tabobject(
        'guides',
        new moodle_url($tabbase, ['tab' => 'guides']),
        get_string('flaggedtabguides', 'mod_selfselectadvanced', $guidescount)
    ),
    new tabobject(
        'quota',
        new moodle_url($tabbase, ['tab' => 'quota']),
        get_string('flaggedtabquota', 'mod_selfselectadvanced', count($quotafail))
    ),
];
echo $OUTPUT->tabtree($tabs, $tab);
// Each tab explains itself. The anomaly wording only belongs on the
// students tab, where anomalies are actually listed.
$introkey = match ($tab) {
    'defaulters' => 'flaggedintrodefaulters',
    'guides' => 'flaggedintroguides',
    'quota' => 'flaggedintroquota',
    default => 'flaggedintrostudents',
};
echo $OUTPUT->notification(get_string($introkey, 'mod_selfselectadvanced'), 'info', false);
$filterform = html_writer::start_tag('form', ['method' => 'get',
        'action' => $tabbase->out_omit_querystring(), 'class' => 'd-inline-flex gap-2 me-3 mb-2'])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab])
    . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => $q,
        'class' => 'form-control w-auto', 'placeholder' => get_string('flaggedfilter', 'mod_selfselectadvanced')])
    . html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'),
        'class' => 'btn btn-secondary'])
    . html_writer::end_tag('form');
$downloadbtn = html_writer::div(
    $filterform . \mod_selfselectadvanced\local\exporter::controls(
        new moodle_url($tabbase, ['id' => $cm->id] + ($q !== '' ? ['q' => $q] : [])),
        $tab
    ) . \mod_selfselectadvanced\local\perpage::controls(
        new moodle_url($tabbase, ['tab' => $tab] + ($q !== '' ? ['q' => $q] : []))
    ),
    'd-flex flex-wrap align-items-center mb-2'
);

if ($tab === 'defaulters') {
    echo $OUTPUT->heading(get_string('defaulters', 'mod_selfselectadvanced'), 3);
    if ($defaulterscount > 0) {
        $tableurl = new moodle_url($tabbase, ['tab' => $tab, 'perpage' => $perpage] + ($q !== '' ? ['q' => $q] : []));
        $table = new \mod_selfselectadvanced\table\flagged_defaulters_table(
            'ssaflaggeddefaulters',
            $activity,
            $tableurl,
            $minmembership,
            $q,
            $canmanage
        );
        $table->out($perpage, false);
        echo $OUTPUT->notification(get_string('defaultersintro', 'mod_selfselectadvanced'), 'info', false);
        if ($canmanage) {
            echo $OUTPUT->single_button(
                new moodle_url($tabbase, ['tab' => 'defaulters', 'action' => 'nudgedefaulters']
                    + ($q !== '' ? ['q' => $q] : [])),
                get_string('nudgedefaulters', 'mod_selfselectadvanced'),
                'get'
            );
        }
    } else {
        echo $OUTPUT->notification(get_string('defaultersnone', 'mod_selfselectadvanced'), 'success', false);
    }
    echo $downloadbtn;
    echo $OUTPUT->footer();
    die;
}
if ($tab === 'guides') {
    echo $OUTPUT->heading(get_string('flaggedguidesheading', 'mod_selfselectadvanced'), 3);
    if ($guidescount > 0) {
        $tableurl = new moodle_url($tabbase, ['tab' => $tab, 'perpage' => $perpage] + ($q !== '' ? ['q' => $q] : []));
        $table = new \mod_selfselectadvanced\table\flagged_guides_table(
            'ssaflaggedguides',
            $activity,
            $resolver,
            $tableurl,
            $windowsecs,
            $q
        );
        $table->out($perpage, false);
        if ($canmanage) {
            echo $OUTPUT->single_button(
                new moodle_url($tabbase, ['tab' => 'guides', 'action' => 'nudgeguides']
                    + ($q !== '' ? ['q' => $q] : [])),
                get_string('nudgeguides', 'mod_selfselectadvanced'),
                'get'
            );
        }
    } else {
        echo $OUTPUT->notification(get_string('flaggedguidesnone', 'mod_selfselectadvanced'), 'success', false);
    }
    echo $downloadbtn;
    echo $OUTPUT->footer();
    die;
}
if ($tab === 'quota') {
    echo $OUTPUT->heading(get_string('flaggedtabquotaheading', 'mod_selfselectadvanced'), 3);
    if ($quotafail) {
        $tableurl = new moodle_url($tabbase, ['tab' => $tab, 'perpage' => $perpage] + ($q !== '' ? ['q' => $q] : []));
        $table = new \mod_selfselectadvanced\table\flagged_quota_table('ssaflaggedquota', $tableurl);
        $table->display_rows($quotafail, $perpage);
    } else {
        echo $OUTPUT->notification(get_string('flaggedquotanone', 'mod_selfselectadvanced'), 'success', false);
    }
    echo $downloadbtn;
    echo $OUTPUT->footer();
    die;
}

// Default tab: groupless (paginated), missing attributes and anomalies
// (both flexible_table, item 3), each with its own remapped sort/page
// GET params so the three lists sharing this page never collide.
$studentsurl = new moodle_url($tabbase, ['tab' => 'students', 'perpage' => $perpage] + ($q !== '' ? ['q' => $q] : []));

$missingattrshtml = '';
if ($missingattrs) {
    $missingattrstable = new \mod_selfselectadvanced\table\flagged_missingattrs_table(
        'ssaflaggedmissingattrs',
        $studentsurl
    );
    ob_start();
    $missingattrstable->display_rows($missingattrs, $perpage);
    $missingattrshtml = ob_get_clean();
}

$anomalieshtml = '';
if ($anomalies) {
    $anomaliestable = new \mod_selfselectadvanced\table\flagged_anomalies_table('ssaflaggedanomalies', $studentsurl);
    ob_start();
    $anomaliestable->display_rows($anomalies, $perpage);
    $anomalieshtml = ob_get_clean();
}

$grouplesspage = array_slice($groupless, $pagenum * $perpage, $perpage);
echo $OUTPUT->render_from_template('mod_selfselectadvanced/flagged_report', (object) [
    'groupless' => $grouplesspage,
    'hasgroupless' => !empty($groupless),
    'grouplesscount' => count($groupless),
    'hasmissingattrs' => !empty($missingattrs),
    'missingattrstable' => $missingattrshtml,
    'hasanomalies' => !empty($anomalies),
    'anomaliestable' => $anomalieshtml,
    'backurl' => (new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->paging_bar(
    count($groupless),
    $pagenum,
    $perpage,
    new moodle_url($tabbase, ['tab' => 'students', 'perpage' => $perpage])
);
echo $downloadbtn;
echo $OUTPUT->footer();
