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
 * The group page. GET renders (including confirmation pages); every
 * state change arrives as a sesskey-protected POST.
 *
 * Actions: invite (leader), withdraw (leader), accept/decline (the
 * invitee acting on their own invitation), delete (leader).
 *
 * Access: confirmed or invited members of the group, and viewall
 * holders. Ownership of every id is verified server-side (IDOR rule,
 * spec section 14.12).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$groupid = required_param('g', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'selfselectadvanced');
require_login($course, true, $cm);

$activity = \mod_selfselectadvanced\activity::from_cmid($cm->id);
$context = $activity->context();
$api = new \mod_selfselectadvanced\local\api($activity);

// Ownership check: the group must belong to this activity.
$group = \mod_selfselectadvanced\local\groups::get($activity, $groupid);

// Access: group members (any live membership row) or viewall holders.
$membership = $DB->get_record('selfselectadvanced_member', [
    'groupid' => $group->id,
    'userid' => $USER->id,
]);
$ismember = $membership && in_array($membership->status, [
    \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
    \mod_selfselectadvanced\local\groups::STATUS_INVITED,
], true);
if (!$ismember) {
    require_capability('mod/selfselectadvanced:viewall', $context);
}

$baseurl = new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id]);
$viewurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$isleaderforming = (int) $group->leaderid === (int) $USER->id
    && $group->state === \mod_selfselectadvanced\local\state::FORMING;

$inviteform = null;
if ($isleaderforming) {
    // Each of the forms that can share this page names its own action
    // button, so the ids stay unique and stable whichever forms the
    // page happens to show.
    $inviteform = new \mod_selfselectadvanced\form\invite_form($baseurl->out(false), [
        'cmid' => $cm->id,
        'groupid' => (int) $group->id,
    ]);
}

$nominateform = null;
if ($isleaderforming && empty($group->successorid)) {
    // Roster-scoped nominee list: eligible members, and cap-excluded
    // members shown with the reason (spec 6.4).
    $eligible = [];
    $excluded = [];
    foreach (\mod_selfselectadvanced\local\groups::get_roster((int) $group->id) as $member) {
        if ((int) $member->userid === (int) $group->leaderid) {
            continue;
        }
        if ($refusal = $api->gatekeeper()->check_nominee_leadslot((int) $member->userid)) {
            $excluded[] = [
                'userid' => (int) $member->userid,
                'name' => fullname($member),
                'reason' => $refusal->get_message(),
            ];
        } else {
            $eligible[(int) $member->userid] = fullname($member);
        }
    }
    if ($eligible || $excluded) {
        $nominateform = new \mod_selfselectadvanced\form\nominate_form($baseurl->out(false), [
            'cmid' => $cm->id,
            'groupid' => (int) $group->id,
            'eligible' => $eligible,
            'excluded' => $excluded,
        ]);
    }
}

$submitform = null;
if ($isleaderforming) {
    // The section always renders for the leader of a forming group:
    // while any blocker stands the button is disabled with the reason
    // beside it, never hidden (a control that may or may not exist is
    // not a state a leader can reason about).
    $submitrefusal = $api->gatekeeper()->can_submit($group, (int) $USER->id);
    // A guide already accepted through an expression of interest wins
    // over the picker: the group goes straight to them on submit, so
    // the form must not ask the leader to choose one (spec: EOI).
    $eoipreassigned = !empty($group->guideid);
    $leaderselects = !$eoipreassigned && (int) $activity->settings()->guidemode === 0;
    // Whether ANY guide can be chosen, which is all the page needs to
    // know: the picker itself searches rather than listing (strategy
    // 1.18 B), so the full roster is never built here.
    $anyguide = !$leaderselects || (bool) \mod_selfselectadvanced\local\guides::selectable(
        $activity,
        $api->gatekeeper()->resolver()
    );
    $submitform = new \mod_selfselectadvanced\form\submit_form($baseurl->out(false), [
        'cmid' => $cm->id,
        'groupid' => (int) $group->id,
        'leaderselects' => $leaderselects,
        'studentapproach' => !empty($activity->settings()->studentapproach),
        'disabled' => $submitrefusal !== null || !$anyguide,
    ]);
}

if ($action === 'submit' && $submitform && ($data = $submitform->get_data())) {
    $api->lifecycle()->submit($group, isset($data->guide) ? (int) $data->guide : null, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('groupsubmitted', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'nominate' && $nominateform && ($data = $nominateform->get_data())) {
    $api->succession()->nominate($group, (int) $data->nominee, $data->stype, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('nominationsent', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'confirmnomination' && data_submitted() && confirm_sesskey()) {
    $type = $api->succession()->confirm($group, (int) $USER->id);
    $notice = $type === 'stepout'
        ? get_string('successionstepoutdone', 'mod_selfselectadvanced')
        : get_string('successiontransferdone', 'mod_selfselectadvanced');
    redirect($baseurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'declinenomination' && data_submitted() && confirm_sesskey()) {
    $api->succession()->decline($group, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('nominationdeclined', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'cancelnomination' && data_submitted() && confirm_sesskey()) {
    $api->succession()->cancel($group, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('nominationcancelled', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'invite' && $inviteform && ($data = $inviteform->get_data())) {
    $picked = array_filter(array_map('intval', (array) ($data->invitees ?? [])));
    if (count($picked) !== count((array) ($data->invitees ?? []))) {
        redirect(
            $baseurl,
            get_string('errineligiblepick', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $sent = 0;
    $problems = [];
    foreach (array_filter(array_map('intval', (array) $data->invitees)) as $inviteeid) {
        try {
            $api->invitations()->send($group, $inviteeid, (int) $USER->id);
            $sent++;
        } catch (moodle_exception $e) {
            $problems[] = $e->getMessage();
        }
    }
    $notice = get_string('invitationssent', 'mod_selfselectadvanced', $sent);
    if ($problems) {
        $notice .= ' ' . implode(' ', $problems);
    }
    redirect(
        $baseurl,
        $notice,
        null,
        $problems
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'withdraw' && data_submitted() && confirm_sesskey()) {
    $memberid = required_param('m', PARAM_INT);
    $api->invitations()->withdraw($group, $memberid, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('invitationwithdrawn', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'accept' && data_submitted() && confirm_sesskey()) {
    $api->invitations()->accept($group, (int) $USER->id);
    redirect(
        $baseurl,
        get_string('invitationaccepted', 'mod_selfselectadvanced', format_string($group->name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'decline' && data_submitted() && confirm_sesskey()) {
    $api->invitations()->decline($group, (int) $USER->id);
    redirect(
        $viewurl,
        get_string('invitationdeclined', 'mod_selfselectadvanced', format_string($group->name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'requestleave' && data_submitted() && confirm_sesskey()) {
    // Member files a leave request (forming only, not the leader).
    if (
        $group->state !== \mod_selfselectadvanced\local\state::FORMING
        || (int) $group->leaderid === (int) $USER->id
        || !$membership
        || $membership->status !== \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED
    ) {
        redirect(
            $baseurl,
            get_string('refusalwrongstate', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $DB->set_field('selfselectadvanced_member', 'leaverequested', time(), ['id' => $membership->id]);
    \mod_selfselectadvanced\local\notifier::send(
        $activity,
        'leaverequest',
        (int) $group->leaderid,
        'msgleaverequestsubject',
        'msgleaverequestbody',
        (object) ['user' => fullname($USER), 'group' => format_string($group->name)],
        $baseurl,
        format_string($group->name)
    );
    redirect(
        $baseurl,
        get_string('leaverequested', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'confirmleave' && data_submitted() && confirm_sesskey()) {
    // Leader confirms a member's leave (L1-gated, spec 6.3/4A.1).
    $memberid = required_param('m', PARAM_INT);
    $leaving = $DB->get_record('selfselectadvanced_member', [
        'id' => $memberid,
        'groupid' => $group->id,
    ], '*', MUST_EXIST);
    if ($refusal = $api->gatekeeper()->can_confirm_leave($group, $leaving, (int) $USER->id)) {
        redirect($baseurl, $refusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $DB->update_record('selfselectadvanced_member', (object) [
        'id' => $leaving->id,
        'status' => \mod_selfselectadvanced\local\groups::STATUS_REMOVED,
        'leaverequested' => null,
        'timemodified' => time(),
    ]);
    \mod_selfselectadvanced\local\notifier::send(
        $activity,
        'leaveresult',
        (int) $leaving->userid,
        'msgleaveconfirmedsubject',
        'msgleaveconfirmedbody',
        (object) ['group' => format_string($group->name)],
        $viewurl,
        $activity->name()
    );
    redirect(
        $baseurl,
        get_string('leaveconfirmed', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if (($action === 'eoilist' || $action === 'eoiunlist') && data_submitted() && confirm_sesskey()) {
    // Leader's listing toggle (spec: EOI). A plain field update: no
    // events, no cache to purge, listing survives a guide stepping out.
    if (!$isleaderforming) {
        redirect(
            $baseurl,
            get_string('refusalwrongstate', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (!empty($activity->settings()->studentapproach)) {
        redirect(
            $baseurl,
            get_string('refusalstudentapproach', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (empty($activity->settings()->eoienabled)) {
        redirect(
            $baseurl,
            get_string('refusaleoidisabled', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $listed = $action === 'eoilist' ? 1 : 0;
    $update = (object) ['id' => $group->id, 'listed' => $listed];
    if ($listed && empty($group->timelisted)) {
        // Timelisted records only the FIRST time the team was listed.
        $update->timelisted = time();
    }
    $DB->update_record('selfselectadvanced_group', $update);
    redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'eoirespond') {
    // Leader (or a manage-capability holder) accepts or rejects one
    // pending expression of interest. GET renders the confirmation
    // page only; the decision itself arrives as the POST below.
    $eoiid = required_param('eoiid', PARAM_INT);
    $decision = required_param('decision', PARAM_ALPHA);
    if (!in_array($decision, ['accept', 'reject'], true)) {
        redirect($baseurl);
    }
    $ismanager = has_capability('mod/selfselectadvanced:manage', $context);
    if ((int) $group->leaderid !== (int) $USER->id && !$ismanager) {
        redirect($baseurl, get_string('refusalnotleader', 'mod_selfselectadvanced'), null, \core\output\notification::NOTIFY_ERROR);
    }
    // Ownership: the interest must belong to this group (IDOR rule, spec section 14.12).
    $eoirow = \mod_selfselectadvanced\local\eoi::get($activity, $eoiid);
    if ((int) $eoirow->groupid !== (int) $group->id) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    if (data_submitted() && confirm_sesskey()) {
        try {
            \mod_selfselectadvanced\local\eoi::respond($activity, $eoiid, $decision === 'accept', (int) $USER->id);
            $notice = $decision === 'accept'
                ? get_string('eoistatusaccepted', 'mod_selfselectadvanced')
                : get_string('eoistatusrejected', 'mod_selfselectadvanced');
            redirect($baseurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    $guidename = fullname(\core_user::get_user((int) $eoirow->guideid));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('guidelabel', 'mod_selfselectadvanced', $guidename), 4);
    echo $OUTPUT->confirm(
        get_string('areyousure'),
        new single_button(
            new moodle_url($baseurl, ['action' => 'eoirespond', 'eoiid' => $eoiid, 'decision' => $decision]),
            get_string($decision === 'accept' ? 'accept' : 'decline', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

if ($action === 'freeze') {
    require_capability('mod/selfselectadvanced:freeze', $context);
    if (data_submitted() && confirm_sesskey()) {
        \mod_selfselectadvanced\local\freeze::freeze_group($activity, $group, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('groupfrozennotice', 'mod_selfselectadvanced', $group->pluginuid),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('freezeconfirm', 'mod_selfselectadvanced', format_string($group->name)),
        new single_button(
            new moodle_url($baseurl, ['action' => 'freeze']),
            get_string('freeze', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

if ($action === 'ticket' && data_submitted() && confirm_sesskey()) {
    // File a queue ticket (strategy 1.16 B); the service enforces who
    // may file which type on which state.
    $tickettype = required_param('tickettype', PARAM_ALPHA);
    $reason = optional_param('reason', '', PARAM_TEXT);
    try {
        \mod_selfselectadvanced\local\tickets::file(
            $activity,
            $group,
            $tickettype,
            $reason,
            FORMAT_PLAIN,
            (int) $USER->id
        );
        redirect(
            $baseurl,
            get_string('ticketfilednotice', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'unfreeze') {
    require_capability('mod/selfselectadvanced:unfreeze', $context);
    if (data_submitted() && confirm_sesskey()) {
        $result = \mod_selfselectadvanced\local\freeze::unfreeze($activity, $group, (int) $USER->id);
        $notice = get_string('groupunfrozennotice', 'mod_selfselectadvanced', $group->pluginuid);
        if (!empty($result->drift['extra']) || !empty($result->drift['missing'])) {
            $notice .= ' ' . get_string('driftdiscarded', 'mod_selfselectadvanced', (object) [
                'extra' => count($result->drift['extra']),
                'missing' => count($result->drift['missing']),
            ]);
        }
        redirect($baseurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    // Confirmation page: restriction references and drift are shown first.
    $warnings = \mod_selfselectadvanced\local\freeze::check_restrictions($activity, $group);
    $drift = \mod_selfselectadvanced\local\freeze::drift($group);
    echo $OUTPUT->header();
    foreach ($warnings as $warning) {
        echo $OUTPUT->notification(
            get_string('restrictionwarning', 'mod_selfselectadvanced', $warning),
            'warning',
            false
        );
    }
    if (!empty($drift['extra']) || !empty($drift['missing'])) {
        echo $OUTPUT->notification(
            get_string('driftwarning', 'mod_selfselectadvanced', (object) [
                'extra' => count($drift['extra']),
                'missing' => count($drift['missing']),
            ]),
            'warning',
            false
        );
    }
    echo $OUTPUT->confirm(
        get_string('unfreezeconfirm', 'mod_selfselectadvanced', format_string($group->name)),
        new single_button(
            new moodle_url($baseurl, ['action' => 'unfreeze']),
            get_string('unfreeze', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

if ($action === 'proposal') {
    // Leader (or manager) uploads the written proposal for the group.
    $ismanager = has_capability('mod/selfselectadvanced:manage', $context);
    if ((int) $group->leaderid !== (int) $USER->id && !$ismanager) {
        throw new moodle_exception('refusalnotleader', 'mod_selfselectadvanced');
    }
    $fileoptions = [
        'maxfiles' => 1,
        'subdirs' => 0,
        'accepted_types' => ['document', '.pdf'],
    ];
    $form = new \mod_selfselectadvanced\form\proposal_form(
        new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id, 'action' => 'proposal']),
        ['fileoptions' => $fileoptions]
    );
    $draftid = file_get_submitted_draft_itemid('proposal');
    file_prepare_draft_area($draftid, $context->id, 'mod_selfselectadvanced', 'proposal', (int) $group->id, $fileoptions);
    $form->set_data(['proposal' => $draftid]);
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        file_save_draft_area_files(
            $data->proposal,
            $context->id,
            'mod_selfselectadvanced',
            'proposal',
            (int) $group->id,
            $fileoptions
        );
        redirect(
            $baseurl,
            get_string('proposalsaved', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('proposal', 'mod_selfselectadvanced'));
    $form->display();
    echo $OUTPUT->footer();
    die;
}

if ($action === 'delete') {
    // Leader-only, forming-only; the gatekeeper repeats this server-side on POST.
    if ($refusal = $api->gatekeeper()->can_delete_group($group, (int) $USER->id)) {
        redirect($baseurl, $refusal->get_message(), null, \core\output\notification::NOTIFY_ERROR);
    }
    if (data_submitted() && confirm_sesskey()) {
        $api->delete_group($group, (int) $USER->id);
        redirect(
            $viewurl,
            get_string('groupdeleted', 'mod_selfselectadvanced', $group->pluginuid),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // GET: render the confirmation page only; the destructive step is the POST above.
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('deletegroupconfirm', 'mod_selfselectadvanced', format_string($group->name)),
        new single_button(
            new moodle_url($baseurl, ['action' => 'delete']),
            get_string('deletegroup', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

$page = new \mod_selfselectadvanced\output\group_page(
    $api,
    $group,
    (int) $USER->id,
    $inviteform,
    $nominateform,
    $submitform
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_selfselectadvanced/group_page', $page->export_for_template($OUTPUT));

// Proposal section (1.3.0): current file + upload control.
$fs = get_file_storage();
$proposalfiles = $fs->get_area_files($context->id, 'mod_selfselectadvanced', 'proposal', (int) $group->id, 'id', false);
$proposalhtml = '';
foreach ($proposalfiles as $file) {
    $url = moodle_url::make_pluginfile_url(
        $context->id,
        'mod_selfselectadvanced',
        'proposal',
        (int) $group->id,
        $file->get_filepath(),
        $file->get_filename(),
        true
    );
    $proposalhtml .= html_writer::div(html_writer::link($url, $file->get_filename()));
}
if (!$proposalhtml) {
    $proposalhtml = html_writer::div(!empty($activity->settings()->proposalrequired)
        ? get_string('proposalmissingrequired', 'mod_selfselectadvanced')
        : get_string('proposalmissing', 'mod_selfselectadvanced'));
}
if ((int) $group->leaderid === (int) $USER->id || has_capability('mod/selfselectadvanced:manage', $context)) {
    if ($group->state === \mod_selfselectadvanced\local\state::FORMING) {
        $proposalhtml .= $OUTPUT->single_button(
            new moodle_url('/mod/selfselectadvanced/groupedit.php', ['id' => $cm->id, 'g' => $group->id]),
            get_string('editgroup', 'mod_selfselectadvanced'),
            'get'
        );
    }
    $proposalhtml .= $OUTPUT->single_button(
        new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id, 'action' => 'proposal']),
        get_string('proposalupload', 'mod_selfselectadvanced'),
        'get'
    );
}
echo html_writer::div(
    $OUTPUT->heading(get_string('proposal', 'mod_selfselectadvanced'), 4) . $proposalhtml,
    'selfselectadvanced-proposal mt-3'
);

// The request queue (strategy 1.16 B): the assigned guide of a firm or
// frozen team may request a composition change; the guide or leader of
// a frozen team may request an unfreeze. Both go to the sequential
// ticket queue that managers and Group Coordinators work exclusively.
$ticketforms = '';
$isassignedguide = (int) $group->guideid === (int) $USER->id && (int) $group->guideid > 0;
$isgroupleader = (int) $group->leaderid === (int) $USER->id;
$statefirmish = in_array($group->state, [
    \mod_selfselectadvanced\local\state::FIRM,
    \mod_selfselectadvanced\local\state::FROZEN,
], true);
$requestable = [];
if ($isassignedguide && $statefirmish) {
    $requestable[] = \mod_selfselectadvanced\local\tickets::TYPE_COMPCHANGE;
}
if (
    ($isassignedguide || $isgroupleader)
        && $group->state === \mod_selfselectadvanced\local\state::FROZEN
) {
    $requestable[] = \mod_selfselectadvanced\local\tickets::TYPE_UNFREEZE;
}
foreach ($requestable as $tickettype) {
    $ticketforms .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-2',
        'action' => (new moodle_url($baseurl, ['action' => 'ticket']))->out(false)])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tickettype', 'value' => $tickettype])
        . html_writer::label(
            get_string('ticketfile' . $tickettype, 'mod_selfselectadvanced'),
            'ticketreason-' . $tickettype
        )
        . ' '
        . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason', 'size' => 40,
            'id' => 'ticketreason-' . $tickettype,
            'placeholder' => get_string('ticketreasonhint', 'mod_selfselectadvanced')])
        . ' '
        . html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary btn-sm',
            'value' => get_string('ticketfilebutton', 'mod_selfselectadvanced')])
        . html_writer::end_tag('form');
}
if ($ticketforms !== '') {
    echo html_writer::div(
        $OUTPUT->heading(get_string('tickets', 'mod_selfselectadvanced'), 4) . $ticketforms,
        'selfselectadvanced-ticketrequests mt-3'
    );
}

// Approaching a guide (strategy 1.17 E): the leader of a forming team
// with no guide yet, where the activity allows it. Its own page, so
// nothing here changes for anyone else.
if (
    $isgroupleader
    && $group->state === \mod_selfselectadvanced\local\state::FORMING
    && empty($group->guideid)
    && (int) ($activity->settings()->contactmax ?? 0) > 0
) {
    $left = \mod_selfselectadvanced\local\contacts::remaining($activity, (int) $group->id);
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/selfselectadvanced/contact.php', ['id' => $cm->id, 'g' => $group->id]),
            get_string('contactteamlink', 'mod_selfselectadvanced'),
            ['class' => 'btn btn-outline-primary']
        )
        . ' ' . html_writer::span(
            get_string('contactintro', 'mod_selfselectadvanced', $left),
            'text-muted small ms-2'
        ),
        'selfselectadvanced-contactlink mt-3'
    );
}
echo $OUTPUT->footer();
