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
 * Guide dashboard: the guide's own load figure (spec 4A.6) and their
 * queue of submitted groups plus their firm and frozen groups.
 * Read-only GET; filters and bulk freeze arrive in slice 11.
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
require_capability('mod/selfselectadvanced:guide', $context);

$api = new \mod_selfselectadvanced\local\api($activity);

// Bulk freeze of selected firm groups (spec 12).
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'bulkfreeze' && data_submitted() && confirm_sesskey()) {
    // The same predicate the queued overflow re-asks before every
    // freeze it performs (A-01), so the page and the task can never
    // drift into disagreeing about who may freeze.
    \mod_selfselectadvanced\local\authority::require_freeze($activity, (int) $USER->id);
    // One request freezes a bounded number of teams; the remainder
    // goes to cron, where the same work is legal and nothing times out.
    // The loop itself lives on the freeze class so a test can pin the
    // cap to the path this page actually takes.
    $bulk = \mod_selfselectadvanced\local\freeze::bulk_freeze(
        $activity,
        optional_param_array('selected', [], PARAM_INT),
        (int) $USER->id
    );
    $notice = get_string('bulkfrozen', 'mod_selfselectadvanced', $bulk->done);
    if ($bulk->queued) {
        $notice .= ' ' . get_string('bulkfreezequeued', 'mod_selfselectadvanced', $bulk->queued);
    }
    if ($bulk->skipped) {
        $notice .= ' ' . get_string('bulkskipped', 'mod_selfselectadvanced', implode(' ', $bulk->skipped));
    }
    redirect(
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
        $notice,
        null,
        ($bulk->skipped || $bulk->queued)
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS
    );
}

// Accepting or returning straight from the queue (strategy 1.19 A).
// The heavy lifting stays in the lifecycle service, so this is exactly
// the decision the review page makes - the guide simply does not have
// to open it first. A return still requires its comment.
if (in_array($action, ['queueaccept', 'queuereturn'], true) && data_submitted() && confirm_sesskey()) {
    $groupid = required_param('g', PARAM_INT);
    $group = \mod_selfselectadvanced\local\groups::get($activity, $groupid);
    // The team-scoped door, asked BEFORE the decision (audit D1). This
    // page's own gate is require_capability(':guide') over the ACTIVITY
    // and the team arrives in 'g' - the identical shape review.php
    // closed at its door in 1.20.1, left open here. The two buttons
    // this handler serves ARE the review page's Accept and Return, so
    // they answer to the review page's predicate: teamaccess::
    // may_review_team(), CALLED and not transcribed, so a unit test of
    // that function is a test of this gate too. The service refuses the
    // same actor on its own since this wave; this is the door, and a
    // door that is only ever locked from the inside is not a door.
    if (!\mod_selfselectadvanced\local\teamaccess::may_review_team($activity, $group, (int) $USER->id)) {
        throw new required_capability_exception(
            $context,
            'mod/selfselectadvanced:viewassignedteams',
            'nopermissions',
            ''
        );
    }
    $back = new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id, 'guidetab' => 'awaiting']);
    try {
        if ($action === 'queueaccept') {
            $api->lifecycle()->approve($group, (int) $USER->id);
            $back->params(['decided' => $groupid, 'decidedas' => 'accepted']);
            $notice = get_string('groupapprovednotice', 'mod_selfselectadvanced', $group->pluginuid);
        } else {
            $comment = trim(required_param('comment', PARAM_TEXT));
            if ($comment === '') {
                // Validation, answered before the service is asked - the
                // typed catch below is for workflow refusals only.
                redirect(
                    $back,
                    get_string('returncommentrequired', 'mod_selfselectadvanced'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $api->lifecycle()->return_group($group, $comment, (int) $USER->id);
            $back->params(['decided' => $groupid, 'decidedas' => 'returned']);
            $notice = get_string('groupreturnednotice', 'mod_selfselectadvanced', $group->pluginuid);
        }
        redirect($back, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect($back, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Guide volunteering (1.7.0): the guide declares or updates their own
// capacity, up to the manager-override-aware effective maximum.
if (
    $action === 'volunteer' && data_submitted() && confirm_sesskey()
    && !empty($activity->settings()->guidevolunteer)
) {
    $capacity = optional_param('capacity', -1, PARAM_INT);
    try {
        \mod_selfselectadvanced\local\volunteering::set($activity, (int) $USER->id, $capacity);
        $notice = get_string('volunteersaved', 'mod_selfselectadvanced');
        $notifytype = \core\output\notification::NOTIFY_SUCCESS;
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        $notice = $e->getMessage();
        $notifytype = \core\output\notification::NOTIFY_ERROR;
    }
    redirect(
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
        $notice,
        null,
        $notifytype
    );
}

// Digest preference (1.8.0): site-wide, not per-activity, so a guide
// working across many activities can opt into one rollup message
// instead of one per event (spec 14.8 addendum). Stored with
// set_user_preference/get_user_preferences under
// 'mod_selfselectadvanced_digest'; the notifier consults it directly.
if ($action === 'digest' && data_submitted() && confirm_sesskey()) {
    $digestperiod = optional_param('digestperiod', 'immediate', PARAM_ALPHA);
    if (!in_array($digestperiod, ['immediate', 'daily', 'weekly'], true)) {
        $digestperiod = 'immediate';
    }
    set_user_preference('mod_selfselectadvanced_digest', $digestperiod, $USER->id);
    redirect(
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
        get_string('digestsaved', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
$digestperiod = get_user_preferences('mod_selfselectadvanced_digest', 'immediate', $USER->id);

// Filters (spec 12): state, quota compliance, approved before/after, department.
$fstate = optional_param('fstate', '', PARAM_ALPHAEXT);
$fquota = optional_param('fquota', '', PARAM_ALPHA);
$fdept = optional_param('fdept', '', PARAM_TEXT);
$fapprovedop = optional_param('fapprovedop', '', PARAM_ALPHA);
$fapproved = optional_param('fapproved', '', PARAM_RAW_TRIMMED);
$fapprovedts = 0;
if ($fapproved !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fapproved)) {
    $fapproved = '';
}
if ($fapproved !== '') {
    // Reject calendar rollovers (a typed 2026-02-31 must not silently
    // become the 3rd of March).
    [$fy, $fm, $fd] = array_map('intval', explode('-', $fapproved));
    if (!checkdate($fm, $fd, $fy)) {
        $fapproved = '';
    }
}
if ($fapproved !== '') {
    // Parse in the GUIDE's timezone, not the server's (audit item 27).
    try {
        $fapprovedts = (new DateTime($fapproved, core_date::get_user_timezone_object()))->getTimestamp();
    } catch (Exception $e) {
        $fapprovedts = 0;
    }
}

$PAGE->set_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

// Step out of a pre-assigned forming team (EOI 1.11.0): GET shows the
// confirm step naming the team, POST performs the step-out. Available
// whenever a forming team happens to carry this guide's pre-assignment,
// independent of the eoienabled switch - eoi::stepout() itself carries
// no such gate, so a guide already committed can always release a team
// even after the feature is later turned off.
if ($action === 'stepout') {
    $stepoutgroupid = required_param('g', PARAM_INT);
    $stepoutgroup = \mod_selfselectadvanced\local\groups::get($activity, $stepoutgroupid);

    if (
        (int) $stepoutgroup->guideid !== (int) $USER->id
        || $stepoutgroup->state !== \mod_selfselectadvanced\local\state::FORMING
    ) {
        redirect(
            new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
            get_string('refusaleoinotassigned', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if (data_submitted() && confirm_sesskey()) {
        try {
            \mod_selfselectadvanced\local\eoi::stepout($activity, $stepoutgroup->id, (int) $USER->id);
            redirect(
                new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
                get_string('changessaved'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
            redirect(
                new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
                $e->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('eoistepoutconfirm', 'mod_selfselectadvanced', format_string($stepoutgroup->name)),
        new single_button(
            new moodle_url('/mod/selfselectadvanced/guide.php', [
                'id' => $cm->id, 'action' => 'stepout', 'g' => $stepoutgroup->id,
            ]),
            get_string('eoistepout', 'mod_selfselectadvanced'),
            'post'
        ),
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id])
    );
    echo $OUTPUT->footer();
    die;
}

if (
    in_array($action, ['handoverpropose', 'handoveraccept', 'handoverdecline', 'handovercancel'], true)
    && data_submitted() && confirm_sesskey()
) {
    $hgroupid = required_param('g', PARAM_INT);
    $handover = $api->handover();
    try {
        switch ($action) {
            case 'handoverpropose':
                $handover->propose($hgroupid, required_param('nominee', PARAM_INT), (int) $USER->id);
                $notice = get_string('guidehandoverproposed', 'mod_selfselectadvanced');
                break;
            case 'handoveraccept':
                $handover->accept($hgroupid, (int) $USER->id);
                $notice = get_string('changessaved');
                break;
            case 'handoverdecline':
                $handover->decline($hgroupid, (int) $USER->id);
                $notice = get_string('changessaved');
                break;
            default:
                $handover->cancel($hgroupid, (int) $USER->id);
                $notice = get_string('changessaved');
        }
        redirect(
            new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
            $notice,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
        redirect(
            new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$mygroups = $DB->get_records_select(
    'selfselectadvanced_group',
    'activityid = :activityid AND guideid = :guideid',
    ['activityid' => $activity->id(), 'guideid' => $USER->id],
    'timesubmitted ASC'
);

$resolver = $api->gatekeeper()->resolver();
$load = (object) [
    // Commitments, not just guided states: a forming team whose leader
    // accepted this guide is real load, so both the load line and the
    // stat card quote the same figure.
    'used' => \mod_selfselectadvanced\local\eoi::guide_commitments($activity, (int) $USER->id),
    'max' => $resolver->effective_maxguided((int) $USER->id)->value,
];

// Pick-a-team stat cards (EOI 1.11.0): guiding links to the existing
// guideload.php drill-down, the other three to eoilist.php filtered to
// exactly the status the card names.
$eoienabled = !empty($activity->settings()->eoienabled);
$eoicards = [];
if ($eoienabled) {
    $eoicounts = \mod_selfselectadvanced\local\eoi::counts($activity, (int) $USER->id);
    $eoicards = [
        (object) [
            'label' => get_string('eoicardguiding', 'mod_selfselectadvanced'),
            'number' => $eoicounts->guiding,
            'url' => (new moodle_url('/mod/selfselectadvanced/guideload.php', [
                'id' => $cm->id, 'guide' => $USER->id,
            ]))->out(false),
        ],
        (object) [
            'label' => get_string('eoicardpending', 'mod_selfselectadvanced'),
            'number' => $eoicounts->pending,
            'url' => (new moodle_url('/mod/selfselectadvanced/eoilist.php', [
                'id' => $cm->id, 'status' => 'pending',
            ]))->out(false),
        ],
        (object) [
            'label' => get_string('eoicardexpired', 'mod_selfselectadvanced'),
            'number' => $eoicounts->expired,
            'url' => (new moodle_url('/mod/selfselectadvanced/eoilist.php', [
                'id' => $cm->id, 'status' => 'expired',
            ]))->out(false),
        ],
        (object) [
            'label' => get_string('eoicardrejected', 'mod_selfselectadvanced'),
            'number' => $eoicounts->rejected,
            'url' => (new moodle_url('/mod/selfselectadvanced/eoilist.php', [
                'id' => $cm->id, 'status' => 'rejected',
            ]))->out(false),
        ],
    ];
}

// Guide volunteering (1.7.0): own status line, call-to-action when
// never volunteered, and the grandfathered note when the declared
// number now sits below the current guiding load.
$showvolunteer = !empty($activity->settings()->guidevolunteer);
$volunteer = (object) [
    'hasvolunteered' => false,
    'statusline' => '',
    'callline' => '',
    'showgrandfathered' => false,
    'grandfatherline' => '',
    'options' => [],
];
if ($showvolunteer) {
    $volunteerrow = \mod_selfselectadvanced\local\volunteering::get($activity, (int) $USER->id);
    $n = $volunteerrow !== null ? (int) $volunteerrow->capacity : 0;
    $ceiling = $resolver->guide_capacity_ceiling((int) $USER->id)->value;
    $options = [];
    for ($i = 0; $i <= $ceiling; $i++) {
        $options[] = (object) [
            'value' => $i,
            'label' => $i === 0 ? get_string('volunteerwithdrawoption', 'mod_selfselectadvanced') : (string) $i,
            'selected' => $i === $n,
        ];
    }
    $volunteer = (object) [
        'hasvolunteered' => $volunteerrow !== null,
        'statusline' => get_string('volunteerstatus', 'mod_selfselectadvanced', (object) ['n' => $n, 'max' => $ceiling]),
        'callline' => get_string('volunteernone', 'mod_selfselectadvanced'),
        'showgrandfathered' => $load->used > $n,
        'grandfatherline' => get_string('volunteergrandfathered', 'mod_selfselectadvanced', (object) [
            'used' => $load->used,
            'n' => $n,
        ]),
        'options' => $options,
    ];
}

$queue = [];
$guided = [];
// Whether the "Team page" link below leads anywhere for THIS viewer.
// Every row of $mygroups is a team this person is the assigned guide
// of, so group.php's own gate (teamaccess::may_open_team) reduces to a
// context-wide capability question here - asked ONCE, never per row. A
// site that PREVENTs :viewassignedteams gets no link rather than a
// visible link to a refusal.
$canopenteam = has_any_capability([
    'mod/selfselectadvanced:viewassignedteams',
    'mod/selfselectadvanced:viewall',
    'mod/selfselectadvanced:manage',
], $context);
// Department filter: ONE query for every roster's departments
// instead of a per-group roster+attribute fetch (audit item 27).
$deptmap = [];
if ($fdept !== '' && $mygroups) {
    [$gsql, $gparams] = $DB->get_in_or_equal(array_keys($mygroups));
    $rows = $DB->get_records_sql(
        "SELECT m.id, m.groupid, a.department
           FROM {selfselectadvanced_member} m
           JOIN {selfselectadvanced_userattr} a ON a.userid = m.userid
          WHERE m.groupid $gsql AND m.status = 'confirmed'",
        $gparams
    );
    foreach ($rows as $row) {
        $deptmap[(int) $row->groupid][] = \core_text::strtolower((string) $row->department);
    }
}
foreach ($mygroups as $group) {
    $row = (object) [
        'pluginuid' => $group->pluginuid,
        'rawname' => $group->name,
        'name' => format_string($group->name),
        'title' => format_string($group->title),
        'statelabel' => get_string('state' . str_replace('_', '', $group->state), 'mod_selfselectadvanced')
            . (($group->state === \mod_selfselectadvanced\local\state::PENDING_GUIDE
                && (int) $activity->settings()->guidewindow > 0 && !empty($group->timesubmitted))
                ? '; ' . get_string(
                    ((int) $activity->settings()->guideautoapprove) ? 'decidebyauto' : 'decideby',
                    'mod_selfselectadvanced',
                    userdate((int) $group->timesubmitted + (int) $activity->settings()->guidewindow)
                )
                : ''),
        'size' => \mod_selfselectadvanced\local\groups::count_confirmed((int) $group->id),
        'reviewurl' => (new moodle_url('/mod/selfselectadvanced/review.php', [
            'id' => $cm->id,
            'g' => $group->id,
        ]))->out(false),
        'groupid' => (int) $group->id,
    ];
    // The decision belongs in the queue, not only behind the Review
    // link (strategy 1.19 A). Accept is offered when the gate allows
    // it, and carries the gate's own reason when it does not, so the
    // guide learns why here rather than after a click.
    if ($group->state === \mod_selfselectadvanced\local\state::PENDING_GUIDE) {
        $refusal = $api->gatekeeper()->can_approve($group, (int) $USER->id);
        $row->canaccept = $refusal === null;
        $row->acceptblocked = $refusal !== null
            ? get_string($refusal->stringkey, 'mod_selfselectadvanced', $refusal->a ?? null)
            : '';
        // Return is ITS OWN verb with ITS OWN gate (seam audit B2,
        // 1.20.20): can_return() asks only the state and the guide's
        // identity, none of can_approve()'s minsize/quota/capacity
        // tiers. The template used to nest the Return form inside
        // canaccept, so the one action an approve refusal calls for
        // vanished exactly when it was needed - an over-guided or
        // quota-blocked queue row offered no Return at all.
        $row->canreturn = $api->gatekeeper()->can_return($group, (int) $USER->id) === null;
    }
    // Apply the guide filters to the guided (non-queue) list.
    $matchesfilters = true;
    if ($group->state !== \mod_selfselectadvanced\local\state::PENDING_GUIDE) {
        if ($fstate !== '' && $group->state !== $fstate) {
            $matchesfilters = false;
        }
        if ($matchesfilters && $fquota !== '') {
            $compliant = \mod_selfselectadvanced\local\quota\evaluator::is_compliant($activity, (int) $group->id);
            if (($fquota === 'yes') !== $compliant) {
                $matchesfilters = false;
            }
        }
        if ($matchesfilters && $fapprovedts && $fapprovedop !== '' && !empty($group->timeapproved)) {
            if ($fapprovedop === 'before' && $group->timeapproved >= $fapprovedts) {
                $matchesfilters = false;
            }
            if ($fapprovedop === 'after' && $group->timeapproved <= $fapprovedts) {
                $matchesfilters = false;
            }
        }
        if ($matchesfilters && $fdept !== '') {
            $matchesfilters = in_array(\core_text::strtolower($fdept), $deptmap[(int) $group->id] ?? [], true);
        }
    }

    if ($group->state === \mod_selfselectadvanced\local\state::PENDING_GUIDE) {
        $queue[] = $row;
    } else if ($matchesfilters) {
        $row->groupid = (int) $group->id;
        // The service's own gate, asked about THIS team (ACT-002). It
        // asked authority::may_freeze() - the capability alone - which
        // is the answer to a different question: a guide for whom
        // :viewassignedteams has been prohibited still holds :freeze
        // and is refused by freeze_group()'s branch, so this dashboard
        // drew them a button that could only ever produce a refusal.
        $row->canfreeze = $group->state === \mod_selfselectadvanced\local\state::FIRM
            && \mod_selfselectadvanced\local\freeze::may_freeze_team($activity, $group, (int) $USER->id);
        // Releasing a team this guide froze (strategy 1.19 C). A team
        // an editing teacher or coordinator froze is theirs to release,
        // and the guide is offered the unfreeze REQUEST instead - the
        // difference is stated here rather than discovered on refusal.
        //
        // ASKED, not described (UX-001). This was one of four
        // descriptions of the unfreeze door and it was narrow in one
        // case the service admits: a guide who ALSO holds :unfreeze
        // releases their own team whoever froze it, and the dashboard
        // hid the button from them. The note below still names the ONE
        // refusal it is worded for; a guide refused for a conflict of
        // interest (a coordinator guiding this very team) is offered
        // neither, and works it through the queue their own dashboard
        // shows them.
        $releaserefusal = $group->state === \mod_selfselectadvanced\local\state::FROZEN
            ? \mod_selfselectadvanced\local\freeze::release_refusal($activity, $group, (int) $USER->id)
            : \mod_selfselectadvanced\local\freeze::RELEASE_CAPABILITY;
        $row->canrelease = $group->state === \mod_selfselectadvanced\local\state::FROZEN
            && $releaserefusal === null;
        $row->stafffroze = $releaserefusal === \mod_selfselectadvanced\local\freeze::RELEASE_STAFFFROZE;
        $row->releaseurl = (new moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $cm->id,
            'g' => $group->id,
            'action' => 'unfreeze',
        ]))->out(false);
        $row->freezeurl = (new moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $cm->id,
            'g' => $group->id,
            'action' => 'freeze',
        ]))->out(false);
        // The team page itself. Until 1.20.1 the dashboard offered
        // Review, Freeze, Release and Step out but no route to the page
        // those actions live on, because group.php's entry gate refused
        // a guide who did not also hold :viewall - so the link would
        // have led straight to a permissions error on any site that had
        // withdrawn it. It is offered only when this viewer's own
        // capabilities open that page, for the same reason.
        $row->canopenteam = $canopenteam;
        $row->teamurl = (new moodle_url('/mod/selfselectadvanced/group.php', [
            'id' => $cm->id,
            'g' => $group->id,
        ]))->out(false);
        // EOI pre-assignment (1.11.0): a forming team can now carry a
        // guideid before it is ever submitted, via an accepted interest.
        // It has no review to do yet, only the option to step out.
        $row->isformingassigned = $group->state === \mod_selfselectadvanced\local\state::FORMING;
        $row->stepouturl = (new moodle_url('/mod/selfselectadvanced/guide.php', [
            'id' => $cm->id,
            'action' => 'stepout',
            'g' => $group->id,
        ]))->out(false);
        $guided[] = $row;
    }
}

// Native download of the filtered list (audit item 27); the
// bulk-freeze SELECTION FORM itself stays a template - a table_sql
// cannot host form controls, a position recorded since C12.
$guidedownload = optional_param('download', '', PARAM_ALPHA);
if ($guidedownload !== '') {
    \mod_selfselectadvanced\local\exporter::download(
        'guide-groups',
        [
            get_string('groupname', 'mod_selfselectadvanced'),
            get_string('pluginid', 'mod_selfselectadvanced'),
            get_string('state', 'mod_selfselectadvanced'),
            get_string('size', 'mod_selfselectadvanced'),
        ],
        array_map(
            static fn($card) => [$card->rawname, $card->pluginuid, $card->statelabel, $card->size],
            array_merge($queue, $guided)
        ),
        $guidedownload
    );
}

// One tab row for the guide's page (strategy 1.18 E). It used to run
// the approach link, the digest form, the handover block, the review
// queue and the guided-team table one under another, so a guide with
// forty teams scrolled past everything to reach anything.
$guidetab = optional_param('guidetab', 'awaiting', PARAM_ALPHA);
if (!in_array($guidetab, ['awaiting', 'guided', 'handover', 'settings'], true)) {
    $guidetab = 'awaiting';
}

echo $OUTPUT->header();

// Everything waiting for this guide now has one queue of its own
// (strategy 1.18 C); this is the way in to it.
$waitingcount = count(\mod_selfselectadvanced\local\contacts::waiting_for($activity, (int) $USER->id));
echo html_writer::div(
    html_writer::link(
        new moodle_url('/mod/selfselectadvanced/guidequeue.php', ['id' => $cm->id]),
        $waitingcount > 0
            ? get_string('contactreviewwaiting', 'mod_selfselectadvanced', $waitingcount)
            : get_string('guiderequestqueue', 'mod_selfselectadvanced'),
        ['class' => $waitingcount > 0 ? 'btn btn-primary' : 'btn btn-outline-primary']
    ),
    'selfselectadvanced-contactreviewlink mb-3'
);

$guidetabs = [];
foreach (
    [
        'awaiting' => 'reviewqueue',
        'guided' => 'guidedgroups',
        'handover' => 'guidehandover',
        'settings' => 'guidesettingstab',
    ] as $key => $label
) {
    $guidetabs[] = new tabobject(
        $key,
        new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id, 'guidetab' => $key]),
        get_string($label, 'mod_selfselectadvanced')
    );
}
echo $OUTPUT->tabtree($guidetabs, $guidetab);

if ($guidetab === 'settings') {
    // Digest preference (1.8.0): site-wide, not per-activity.
    echo html_writer::start_div('selfselectadvanced-digest mb-3');
    echo html_writer::tag('h3', get_string('digestheading', 'mod_selfselectadvanced'));
    echo html_writer::tag('p', get_string('digestexplain', 'mod_selfselectadvanced'), ['class' => 'text-muted']);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]))->out(false),
        'class' => 'd-flex flex-wrap gap-2 align-items-end',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'digest']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::label(
        get_string('digestlabel', 'mod_selfselectadvanced'),
        'ssa-digestperiod',
        true,
        ['class' => 'me-2']
    );
    echo html_writer::select(
        [
            'immediate' => get_string('digestimmediate', 'mod_selfselectadvanced'),
            'daily' => get_string('digestdaily', 'mod_selfselectadvanced'),
            'weekly' => get_string('digestweekly', 'mod_selfselectadvanced'),
        ],
        'digestperiod',
        $digestperiod,
        false,
        ['id' => 'ssa-digestperiod', 'class' => 'form-select form-select-sm w-auto me-2']
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('digestsave', 'mod_selfselectadvanced'),
        'class' => 'btn btn-secondary btn-sm',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}

if ($guidetab === 'handover') {
    // Guide handover (1.14.0): incoming proposals to decide, and a
    // nomination control for every team this guide currently holds in a
    // submitted-or-later state - the only self-service way out of one.
    $handoverurl = new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]);
    $incoming = $api->handover()->incoming((int) $USER->id);
    $held = $DB->get_records_select(
        'selfselectadvanced_group',
        "activityid = :activityid AND guideid = :guideid AND state IN ('pending_guide', 'firm', 'frozen')",
        ['activityid' => $activity->id(), 'guideid' => (int) $USER->id],
        'name ASC'
    );
    if (!$incoming && !$held) {
        echo html_writer::div(get_string('guidehandovernone', 'mod_selfselectadvanced'), 'text-muted');
    } else {
        echo html_writer::start_div('selfselectadvanced-handover mb-3');
        foreach ($incoming as $hgroup) {
            $proposer = fullname(\core_user::get_user((int) $hgroup->guideid));
            echo html_writer::start_div('alert alert-info d-flex flex-wrap gap-2 align-items-center');
            echo html_writer::span(get_string('guidehandoverpending', 'mod_selfselectadvanced', (object) [
                'from' => $proposer,
                'to' => fullname($USER),
            ]) . ' (' . format_string($hgroup->name) . ')');
            foreach (['handoveraccept' => 'guidehandoveraccept', 'handoverdecline' => 'guidehandoverdecline'] as $act => $label) {
                echo html_writer::start_tag('form', [
                    'method' => 'post',
                    'action' => $handoverurl->out(false),
                    'class' => 'd-inline',
                ]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $act]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'g', 'value' => $hgroup->id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string($label, 'mod_selfselectadvanced'),
                    'class' => $act === 'handoveraccept' ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm',
                ]);
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_div();
        }
        if ($held) {
            // Whether anybody else could take one on, which is all that
            // decides whether the control appears. Who, specifically, is
            // a question the searchable picker answers (strategy 1.18 B).
            $selectable = \mod_selfselectadvanced\local\guides::selectable($activity, $resolver);
            unset($selectable[(int) $USER->id]);
            $options = (bool) $selectable;
            foreach ($held as $hgroup) {
                echo html_writer::start_div('d-flex flex-wrap gap-2 align-items-center mb-2');
                echo html_writer::span(format_string($hgroup->name), 'fw-bold me-2');
                if (!empty($hgroup->guidesuccessorid)) {
                    echo html_writer::span(get_string('guidehandoverpending', 'mod_selfselectadvanced', (object) [
                        'from' => fullname($USER),
                        'to' => fullname(\core_user::get_user((int) $hgroup->guidesuccessorid)),
                    ]), 'text-muted');
                    echo html_writer::start_tag('form', [
                        'method' => 'post',
                        'action' => $handoverurl->out(false),
                        'class' => 'd-inline',
                    ]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'handovercancel']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'g', 'value' => $hgroup->id]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                    echo html_writer::empty_tag('input', [
                        'type' => 'submit',
                        'value' => get_string('guidehandovercancel', 'mod_selfselectadvanced'),
                        'class' => 'btn btn-outline-secondary btn-sm',
                    ]);
                    echo html_writer::end_tag('form');
                } else if ($options) {
                    echo html_writer::start_tag('form', [
                        'method' => 'post',
                        'action' => $handoverurl->out(false),
                        'class' => 'd-inline-flex gap-2 align-items-center',
                    ]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'handoverpropose']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'g', 'value' => $hgroup->id]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                    echo \mod_selfselectadvanced\local\guidepicker::render(
                        'nominee',
                        (int) $cm->id,
                        0,
                        '',
                        true,
                        'ssa-nominee-' . (int) $hgroup->id
                    );
                    echo html_writer::empty_tag('input', [
                        'type' => 'submit',
                        'value' => get_string('guidehandovernominate', 'mod_selfselectadvanced'),
                        'class' => 'btn btn-secondary btn-sm',
                    ]);
                    echo html_writer::end_tag('form');
                }
                echo html_writer::end_div();
            }
        }
        echo html_writer::end_div();
    }
}

// The team just decided, shown greyed at the head of the queue rather
// than vanishing the instant it is answered (strategy 1.19 A). It is
// fetched directly because accepting moves it out of the queue and
// returning takes this guide off it altogether.
$decidedid = optional_param('decided', 0, PARAM_INT);
$decidedas = optional_param('decidedas', '', PARAM_ALPHA);
if ($guidetab === 'awaiting' && $decidedid && in_array($decidedas, ['accepted', 'returned'], true)) {
    $decidedgroup = $DB->get_record('selfselectadvanced_group', [
        'id' => $decidedid,
        'activityid' => $activity->id(),
    ]);
    if ($decidedgroup) {
        array_unshift($queue, (object) [
            'pluginuid' => $decidedgroup->pluginuid,
            'rawname' => $decidedgroup->name,
            'name' => format_string($decidedgroup->name),
            'title' => format_string($decidedgroup->title),
            'statelabel' => get_string(
                'state' . str_replace('_', '', $decidedgroup->state),
                'mod_selfselectadvanced'
            ),
            'size' => \mod_selfselectadvanced\local\groups::count_confirmed((int) $decidedgroup->id),
            'reviewurl' => (new moodle_url('/mod/selfselectadvanced/review.php', [
                'id' => $cm->id,
                'g' => $decidedgroup->id,
            ]))->out(false),
            'groupid' => (int) $decidedgroup->id,
            'canaccept' => false,
            'acceptblocked' => '',
            'decided' => get_string('queuedecided' . $decidedas, 'mod_selfselectadvanced'),
        ]);
    }
}

$departments = \mod_selfselectadvanced\local\attributes\manager::distinct_values('department');
echo $OUTPUT->render_from_template('mod_selfselectadvanced/guide_dashboard', (object) [
    'loadline' => get_string('guideloadheader', 'mod_selfselectadvanced', $load),
    // Each list is a tab now, so the template renders the one asked for
    // rather than every one of them stacked (strategy 1.18 E).
    'showqueue' => $guidetab === 'awaiting',
    'showguided' => $guidetab === 'guided',
    'showvolunteer' => $showvolunteer && $guidetab === 'settings',
    'volunteer' => $volunteer,
    'eoienabled' => $eoienabled && $guidetab === 'awaiting',
    'eoicards' => $eoicards,
    'haseoicards' => !empty($eoicards) && $guidetab === 'awaiting',
    'pickteamurl' => (new moodle_url('/mod/selfselectadvanced/pickteam.php', ['id' => $cm->id]))->out(false),
    'queue' => $queue,
    'hasqueue' => !empty($queue),
    'guided' => $guided,
    'hasguided' => !empty($guided),
    'canbulkfreeze' => \mod_selfselectadvanced\local\authority::may_freeze($activity, (int) $USER->id)
        && !empty(array_filter($guided, static fn($g) => !empty($g->canfreeze))),
    'sesskey' => sesskey(),
    'cmid' => $cm->id,
    'actionurl' => (new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]))->out(false),
    'filters' => (object) [
        'fstate' => $fstate,
        'fquota' => $fquota,
        'fquotayes' => $fquota === 'yes',
        'fquotano' => $fquota === 'no',
        'fbefore' => $fapprovedop === 'before',
        'fafter' => $fapprovedop === 'after',
        'fdept' => $fdept,
        'fapprovedop' => $fapprovedop,
        'fapproved' => $fapproved,
        'stateoptions' => array_map(static fn($st) => (object) [
            'value' => $st,
            'label' => get_string('state' . str_replace('_', '', $st), 'mod_selfselectadvanced'),
            'selected' => $st === $fstate,
        ], \mod_selfselectadvanced\local\state::all()),
        'deptoptions' => array_map(static fn($d) => (object) [
            'value' => $d,
            'selected' => $d === $fdept,
        ], $departments),
    ],
    'backurl' => (new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]))->out(false),
]);
echo $OUTPUT->footer();
