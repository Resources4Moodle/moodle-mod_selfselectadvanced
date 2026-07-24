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
if ($isleaderforming && $api->gatekeeper()->can_submit($group, (int) $USER->id) === null) {
    $leaderselects = (int) $activity->settings()->guidemode === 0;
    $guideoptions = [];
    if ($leaderselects) {
        foreach (
            \mod_selfselectadvanced\local\guides::selectable(
                $activity,
                $api->gatekeeper()->resolver()
            ) as $guide
        ) {
            $guideoptions[$guide->id] = $guide->fullname . ' — ' . $guide->label;
        }
    }
    if (!$leaderselects || $guideoptions) {
        $submitform = new \mod_selfselectadvanced\form\submit_form($baseurl->out(false), [
            'cmid' => $cm->id,
            'groupid' => (int) $group->id,
            'leaderselects' => $leaderselects,
            'guides' => $guideoptions,
        ]);
    }
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
echo $OUTPUT->footer();
