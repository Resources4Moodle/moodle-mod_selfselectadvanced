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
 * Access: confirmed or invited members of the group, the team's own
 * assigned guide, manage holders and viewall holders - the four doors
 * teamaccess::may_open_team() names, which is the predicate this page
 * calls. Ownership of every id is verified server-side (IDOR rule,
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

// Access: group members (any live membership row), the team's own
// assigned guide, a manager, or viewall holders.
//
// A guide is never a MEMBER of the team they guide, so until 1.20.1
// this door refused them their own team - and Freeze, Release, the
// ticket forms, the roster and the proposal all live behind it. A
// :manage holder without :viewall was refused too, along with eight
// manager-only actions. The per-action checks below are unchanged;
// this gate decides who may LOOK. The predicate is NOT transcribed
// here: teamaccess::may_open_team() is the one place it lives, so a
// unit test of that function is a test of this page's gate.
//
// This page used to read the membership row again here, for the one
// question further down that needed CONFIRMED rather than any live
// row - who may download the proposal. That question moved into
// teamaccess::may_read_proposal() with the rest of the file policy
// (audit A-05), and the row went with it, so the page no longer keeps
// a second copy of a membership to answer a question it no longer asks.
if (!\mod_selfselectadvanced\local\teamaccess::may_open_team($activity, $group, (int) $USER->id)) {
    require_capability('mod/selfselectadvanced:viewall', $context);
}

$baseurl = new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id]);
$viewurl = new moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title($activity->name());
$PAGE->set_heading(format_string($course->fullname));

$isleaderforming = (int) $group->leaderid === (int) $USER->id
    && $group->state === \mod_selfselectadvanced\local\state::FORMING;
// AUTHORITY, asked once and CALLED rather than transcribed: owning the
// leaderid is a fact about the row, and an administrator's PROHIBIT is
// a decision about the person. The forms below are the leader's, and a
// form that always refuses on submit is worse than no form.
$maylead = \mod_selfselectadvanced\local\authority::may_lead($activity, (int) $USER->id);

$inviteform = null;
if ($isleaderforming) {
    // Each of the forms that can share this page names its own action
    // button, so the ids stay unique and stable whichever forms the
    // page happens to show.
    // No emailmatch flag any more (1.20.1 wave 3D). This page used to
    // compute a three-armed predicate here - switch off, OR an
    // unrestricted viewer, OR :viewparticipantidentity - and hand it to
    // the form so the placeholder could promise an address search to
    // the viewers who got one. candidates::search() no longer matches
    // an address for ANY viewer in EITHER switch state, so every arm of
    // that predicate was answering a question the query stopped asking,
    // and the two arms that returned true made the box promise a search
    // it would not make. The predicate is not narrowed here, it is
    // gone: a flag whose only correct value is a constant is a flag
    // that will be widened again by the next reader who finds it.
    $inviteform = new \mod_selfselectadvanced\form\invite_form($baseurl->out(false), [
        'cmid' => $cm->id,
        'groupid' => (int) $group->id,
    ]);
}

$nominateform = null;
if ($isleaderforming && $maylead && empty($group->successorid)) {
    // Roster-scoped nominee list: eligible members, and cap-excluded
    // members shown with the reason (spec 6.4). $maylead is part of the
    // condition because succession::nominate() now requires the leader
    // authority (F-1) and because building this form walks the whole
    // roster through check_nominee_leadslot() - work done for a
    // prohibited leader would be work done to draw a form that cannot
    // be submitted.
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
if ($isleaderforming && $maylead) {
    // AUTHORITY, added in 1.20.1 (audit D2): submitting to a guide is a
    // LEADER verb - "Create groups and act as leader" - and it is the
    // one this page drew from $isleaderforming alone while the invite,
    // nominate and delete controls beside it had already been gated.
    // state::submit() now refuses the same actor, so leaving the form
    // here would be exactly the thing review.php's rule forbids: a form
    // that always refuses on submit is worse than no form.
    //
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
    try {
        $api->lifecycle()->submit($group, isset($data->guide) ? (int) $data->guide : null, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // The refusalguidechanged sentence exists solely for the race between the
        // page load and this click - it must land as a sentence, not a
        // stack trace. Same contract as the accept arm (1.20.18): a refusal is an
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
        get_string('groupsubmitted', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'nominate' && $nominateform && ($data = $nominateform->get_data())) {
    try {
        $api->succession()->nominate($group, (int) $data->nominee, $data->stype, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // Same contract as the accept arm (1.20.18): a refusal is an
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
        get_string('nominationsent', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'confirmnomination' && data_submitted() && confirm_sesskey()) {
    try {
        $type = $api->succession()->confirm($group, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // Same contract as the accept arm (1.20.18): a refusal is an
        // answer, delivered as a notice, never the raw error page.
        redirect(
            $baseurl,
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $notice = $type === 'stepout'
        ? get_string('successionstepoutdone', 'mod_selfselectadvanced')
        : get_string('successiontransferdone', 'mod_selfselectadvanced');
    redirect($baseurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'declinenomination' && data_submitted() && confirm_sesskey()) {
    try {
        $api->succession()->decline($group, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // Same contract as the accept arm (1.20.18): a refusal is an
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
        get_string('nominationdeclined', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'cancelnomination' && data_submitted() && confirm_sesskey()) {
    try {
        $api->succession()->cancel($group, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // Same contract as the accept arm (1.20.18): a refusal is an
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
        get_string('nominationcancelled', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'invite' && $inviteform && ($data = $inviteform->get_data())) {
    // Three kinds of entry arrive here, and until 2026-08-06 all three
    // collapsed into one anonymous banner that pointed back at a search
    // list which may have scrolled away or been re-queried (maintainer's
    // live report: "the message says the reason is given against the
    // name, but it is not so"):
    // A POSITIVE id is a candidate the list showed eligible. A NEGATIVE
    // id is one the list annotated ineligible - the selector keeps
    // their identity as -id precisely so the refusal can be resolved to
    // a NAME and the CURRENT sentence here. A zero or empty entry is a
    // widget artefact (typed text never committed to a pick), ignored
    // when anything real was picked.
    $raw = array_map('intval', (array) ($data->invitees ?? []));
    $picked = array_filter($raw, static fn(int $id): bool => $id > 0);
    $flagged = array_filter($raw, static fn(int $id): bool => $id < 0);
    if (!$picked && !$flagged) {
        redirect(
            $baseurl,
            get_string('errnopickselected', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $sent = 0;
    $problems = [];
    foreach ($flagged as $flaggedid) {
        // Re-asked NOW rather than replayed from the list: the roster
        // may have moved since the search rendered, and the sentence
        // the leader reads must be the one the gate would use.
        $ineligible = \core_user::get_user(-$flaggedid);
        $refusal = $ineligible ? $api->gatekeeper()->can_invite($group, -$flaggedid) : null;
        $problems[] = get_string('errineligiblepick', 'mod_selfselectadvanced', (object) [
            'name' => $ineligible ? fullname($ineligible) : -$flaggedid,
            'reason' => $refusal?->get_message()
                ?? get_string('refusalgone', 'mod_selfselectadvanced'),
        ]);
    }
    foreach ($picked as $inviteeid) {
        try {
            $api->invitations()->send($group, $inviteeid, (int) $USER->id);
            $sent++;
        } catch (moodle_exception $e) {
            // The combination case (maintainer, 2026-08-06): a candidate
            // eligible ALONE can be refused once an earlier pick in this
            // same batch consumed the seats or the rule capacity their
            // eligibility depended on. The sentence alone did not say
            // WHO it was about, so it is prefixed with the name.
            $refused = \core_user::get_user($inviteeid);
            $problems[] = get_string('errineligiblepick', 'mod_selfselectadvanced', (object) [
                'name' => $refused ? fullname($refused) : $inviteeid,
                'reason' => $e->getMessage(),
            ]);
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
    try {
        $api->invitations()->withdraw($group, $memberid, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // The invitee accepting first is the documented ordinary race. Same contract as the accept arm (1.20.18): a refusal is an
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
        get_string('invitationwithdrawn', 'mod_selfselectadvanced'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'accept' && data_submitted() && confirm_sesskey()) {
    try {
        $api->invitations()->accept($group, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // A refused acceptance is an ANSWER, not an accident
        // (maintainer, 2026-08-07): back to the invitation list with
        // the reason as a notice, never the raw error page with its
        // dead documentation link. The landing page disables Accept
        // for a refusal it can see coming; this catches the race
        // where the roster moved between the page load and the click.
        redirect(
            $viewurl,
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    redirect(
        $baseurl,
        get_string('invitationaccepted', 'mod_selfselectadvanced', format_string($group->name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'decline' && data_submitted() && confirm_sesskey()) {
    try {
        $api->invitations()->decline($group, (int) $USER->id);
    } catch (\moodle_exception $e) {
        if ($e instanceof \coding_exception) {
            throw $e;
        }
        // The unfixed sibling of the accept arm: a withdrawn or
        // expired invitation declined a moment too late. Same contract as the accept arm (1.20.18): a refusal is an
        // answer, delivered as a notice, never the raw error page.
        redirect(
            $viewurl,
            $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    redirect(
        $viewurl,
        get_string('invitationdeclined', 'mod_selfselectadvanced', format_string($group->name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'requestleave' && data_submitted() && confirm_sesskey()) {
    // Member files a leave request (forming only, not the leader). The
    // gate and the write both live in the service, under the group
    // lock and on a row read inside it - a submit landing between this
    // page load and the click used to be invisible here (T-02 R2).
    try {
        $api->invitations()->request_leave($group, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('leaverequested', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'confirmleave' && data_submitted() && confirm_sesskey()) {
    // Leader confirms a member's leave (L1-gated, spec 6.3/4A.1).
    $memberid = required_param('m', PARAM_INT);
    try {
        $api->invitations()->confirm_leave($group, $memberid, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('leaveconfirmed', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if (($action === 'eoilist' || $action === 'eoiunlist') && data_submitted() && confirm_sesskey()) {
    // Leader's listing toggle (spec: EOI). CALLED, not transcribed
    // (AUTH-001). This branch used to be the whole thing: four inline
    // tests, an inline update_record() and no service to POST at
    // directly or to test. Its only authority test was
    // $isleaderforming - the raw leaderid and the state - which under
    // decision 38 is exactly the test a PROHIBITED leader still
    // passes, because leadership is transferred and never removed.
    //
    // eoi::set_listed() now owns all of it, and gates the two halves
    // differently: listing PUBLISHES the team to every guide and asks
    // for leader authority; unlisting RETRACTS it and does not, per
    // F3. That asymmetry is the reason there is no require_lead() on
    // this line - putting one here would close the retraction too.
    try {
        \mod_selfselectadvanced\local\eoi::set_listed(
            $activity,
            (int) $group->id,
            $action === 'eoilist',
            (int) $USER->id
        );
        redirect($baseurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'eoirespond') {
    // Leader (or a holder of :manage or :assignguide) accepts or
    // rejects one pending expression of interest. GET renders the
    // confirmation page only; the decision itself arrives as the POST
    // below.
    $eoiid = required_param('eoiid', PARAM_INT);
    $decision = required_param('decision', PARAM_ALPHA);
    if (!in_array($decision, ['accept', 'reject'], true)) {
        redirect($baseurl);
    }
    // The :assignguide capability joined this door in 1.20.0 - on the
    // SERVICE side only. eoi::respond() has admitted it ever since and
    // narrowcaps_test pins that, while this page went on asking :manage
    // by itself: so the one capability whose own description says
    // "decide expressions of interest" could not decide one through the
    // only screen that offers the choice. The Group Coordinator role
    // carries :assignguide and not :manage, which made the role this
    // plugin creates the population the omission fell on (ACT-004).
    // AUTHORITY, NOT OWNERSHIP, on the leader arm (AUTH-004) - and the
    // SERVICE'S OWN LADDER for the rest (blind audit 1.20.3, finding
    // 1): capability alone admitted a narrow-authority coordinator to
    // a decision eoi::respond() refuses as self-dealing, an interest
    // of their own pending on this team or an involvement with it.
    // One predicate, owned by the service; this door and the renderer
    // that draws the buttons both consume it.
    $deciderefusal = \mod_selfselectadvanced\local\eoi::decide_refusal($activity, $group, (int) $USER->id);
    if ($deciderefusal !== null) {
        redirect(
            $baseurl,
            get_string($deciderefusal->stringkey, 'mod_selfselectadvanced', $deciderefusal->a),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
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

if ($action === 'joinrespond' && data_submitted() && confirm_sesskey()) {
    // Decision 53: the leader answers a request to join THIS team from
    // the page the team already lives on, without having to discover
    // joinrequest.php. Nothing about the workflow is duplicated - the
    // decision goes to joinrequests::respond(), which is the same call
    // the "Asked of my team" tab makes, so both surfaces run one move
    // engine, one lock order, one audit trail.
    $requestid = required_param('r', PARAM_INT);
    $decision = required_param('decision', PARAM_ALPHA);
    if (!in_array($decision, ['accept', 'decline'], true)) {
        redirect($baseurl);
    }
    $joinnote = trim(optional_param('note', '', PARAM_TEXT));
    $confirmaccept = optional_param('confirmaccept', 0, PARAM_BOOL);
    // Ownership: the request must be one asked OF THIS TEAM (IDOR rule,
    // spec section 14.12). respond() would refuse a request belonging to
    // another team anyway - it re-reads the row and asks
    // require_decider() about ITS target - but a leader of one team
    // posting another team's request id is a crafted id and is answered
    // as one, not with a workflow refusal that reads like bad luck.
    $joinrequest = \mod_selfselectadvanced\local\joinrequests::get($activity, $requestid);
    if ((int) $joinrequest->targetgroupid !== (int) $group->id) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    try {
        // DEFENCE IN DEPTH, not the gate. The gate is the identical
        // call inside respond(), made under the joinrequest:{id} lock
        // on the row read there - this one is made on a copy that can
        // be minutes old. Asking it here as well means a crafted POST
        // from somebody with no authority at all never reaches the
        // service, and the panel that draws the buttons consumes the
        // same predicate, so the control and the door admit one set of
        // people: the target team's leader, and a coordinator or
        // manager acting for an absent leader.
        \mod_selfselectadvanced\local\joinrequests::require_decider($activity, $group, (int) $USER->id);
        \mod_selfselectadvanced\local\joinrequests::respond(
            $activity,
            $requestid,
            $decision === 'accept',
            $joinnote,
            (int) $USER->id,
            [],
            (bool) $confirmaccept
        );
        redirect(
            $baseurl,
            get_string($decision === 'accept' ? 'joinaccepted' : 'joindeclined', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'freeze') {
    // CALLED, not transcribed (audit F-6): the freeze predicate has a
    // home and this page used to carry a fourth copy of it.
    //
    // The call it makes changed this wave (ACT-002). It asked
    // authority::require_freeze() - the bare :freeze capability, which
    // db/access.php grants to the non-editing teacher archetype ALONE -
    // while freeze_group() admits a :manage or :coordinate holder on
    // its on-behalf branch. So the door was narrower than the room: a
    // manager, holding :manage and :viewall, was named in
    // teamaccess::may_open_team() as one of the audiences this page
    // exists for, reached the team page, and was refused the moment
    // they pressed Freeze by a capability nobody had told them they
    // needed. freeze::require_freeze_team() IS the service's own gate,
    // extracted so the page cannot ask a different question.
    \mod_selfselectadvanced\local\freeze::require_freeze_team($activity, $group, (int) $USER->id);
    if (data_submitted() && confirm_sesskey()) {
        try {
            $frozen = \mod_selfselectadvanced\local\freeze::freeze_group($activity, $group, (int) $USER->id);
        } catch (\moodle_exception $e) {
            if ($e instanceof \coding_exception) {
                throw $e;
            }
            // The membership-audit refusals are designed sentences
            // (freeze.php: "STATE IS NOT ASKED HERE") - deliver them
            // as the notice they were written to be.
            redirect(
                $baseurl,
                $e->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $notice = get_string('groupfrozennotice', 'mod_selfselectadvanced', $group->pluginuid);
        $level = \core\output\notification::NOTIFY_SUCCESS;
        // Core refuses to put a deleted or non-enrolled person in a
        // course group; the guide is told here, by name only, and every
        // manager is told by message.
        if (!empty($frozen->sync->refused)) {
            $notice .= ' ' . get_string(
                'coregroupsyncrefused',
                'mod_selfselectadvanced',
                selfselectadvanced_refused_names($frozen->sync->refused)
            );
            $level = \core\output\notification::NOTIFY_WARNING;
        }
        redirect($baseurl, $notice, null, $level);
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
    // The service's own door, asked at the page (UX-001). It used to be
    // TRANSCRIBED here - capability, or the guide of a team no member of
    // staff froze - and the transcription was missing the conflict of
    // interest, so a coordinator who guides this very team reached the
    // confirmation page, read the restore preview, typed a reason and
    // was refused on submit. The service re-checks under its lock and
    // stays authoritative; this call is the page saying the SAME thing
    // so nobody is sent to a door that refuses them.
    \mod_selfselectadvanced\local\freeze::require_unfreeze_team($activity, $group, (int) $USER->id);
    if (data_submitted() && confirm_sesskey()) {
        $unfreezereason = trim(optional_param('reason', '', PARAM_TEXT));
        try {
            $result = \mod_selfselectadvanced\local\freeze::unfreeze(
                $activity,
                $group,
                (int) $USER->id,
                $unfreezereason
            );
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        $notice = get_string('groupunfrozennotice', 'mod_selfselectadvanced', $group->pluginuid);
        // Nothing is discarded any more: the course group is kept, and
        // the sync leaves it holding the restored roster plus the guide.
        //
        // ONLY 'synced' is success. sync_core_group() sets that status
        // after the last core write returns, so anything else - a
        // failure mid-loop, no mirror to write to - means the course
        // group is NOT known to match the restored roster, and saying
        // so is the whole point: the manager used to be shown an
        // unqualified green notice either way.
        $level = \core\output\notification::NOTIFY_SUCCESS;
        if (!empty($result->sync) && $result->sync->status === 'synced') {
            $notice .= ' ' . get_string('coregroupkept', 'mod_selfselectadvanced', (object) [
                'added' => count($result->sync->added),
                'removed' => count($result->sync->removed),
            ]);
        } else {
            $notice .= ' ' . get_string('coregroupnotinstep', 'mod_selfselectadvanced');
            $level = \core\output\notification::NOTIFY_WARNING;
        }
        redirect($baseurl, $notice, null, $level);
    }
    // Confirmation page: restriction references and drift are shown first.
    $warnings = \mod_selfselectadvanced\local\freeze::check_restrictions($activity, $group);
    $drift = \mod_selfselectadvanced\local\freeze::drift($group);
    // What the RESTORE would actually do to the roster - the same
    // quantity the service enforces its reason gate on (D6-9).
    // Deliberately not drift(), which is the core-MIRROR health report
    // and is normally zero on a healthy frozen team: keying the reason
    // field on it would make the field optional exactly when the
    // service is about to demand it, and every ordinary unfreeze would
    // dead-end on an error page.
    $preview = \mod_selfselectadvanced\local\freeze::unfreeze_preview($activity, $group);
    $previewnames = selfselectadvanced_user_names(array_merge($preview['removed'], $preview['added']));
    $reasonrequired = $preview['removed'] || $preview['added'];
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
    echo html_writer::tag('p', get_string('unfreezeconfirm', 'mod_selfselectadvanced', format_string($group->name)));
    foreach (['removed', 'added'] as $side) {
        if (!$preview[$side]) {
            continue;
        }
        // Names only - never an email address or a phone number.
        echo html_writer::div(
            html_writer::tag('strong', get_string('unfreezepreview' . $side, 'mod_selfselectadvanced'))
            . ' ' . s(implode(', ', array_map(
                static fn($uid) => $previewnames[(int) $uid] ?? (string) $uid,
                $preview[$side]
            ))),
            'selfselectadvanced-unfreezepreview-' . $side
        );
    }
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url($baseurl, ['action' => 'unfreeze']))->out(false),
        'class' => 'selfselectadvanced-unfreezeform mt-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::div(
        html_writer::label(
            get_string('unfreezereason', 'mod_selfselectadvanced'),
            'ssa-unfreezereason',
            true,
            ['class' => 'form-label']
        )
        . html_writer::tag('textarea', '', [
            'class' => 'form-control',
            'id' => 'ssa-unfreezereason',
            'name' => 'reason',
            'rows' => 3,
        ] + ($reasonrequired ? ['required' => 'required'] : [])),
        'mb-2'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('unfreeze', 'mod_selfselectadvanced'),
    ]);
    echo html_writer::link($baseurl, get_string('cancel'), ['class' => 'btn btn-secondary ms-2']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    die;
}

if ($action === 'dissolve') {
    // Decision 6, D6-3: the exit from a team that can be neither
    // repaired nor deleted. GET renders the confirmation; the
    // destructive step is the sesskey-protected POST. Both capabilities
    // are required here AND again in the service, which is authoritative.
    require_capability('mod/selfselectadvanced:manage', $context);
    require_capability('mod/selfselectadvanced:overriderules', $context);
    if (data_submitted() && confirm_sesskey()) {
        $dissolvereason = trim(optional_param('reason', '', PARAM_TEXT));
        if ($dissolvereason === '') {
            redirect(
                new moodle_url($baseurl, ['action' => 'dissolve']),
                get_string('errdissolvereasonrequired', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        try {
            $api->dissolve_group($group, $dissolvereason, (int) $USER->id);
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect(
            $viewurl,
            get_string('groupdissolved', 'mod_selfselectadvanced', $group->pluginuid),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // GET: the confirmation page names every member that will be
    // parked. Names only - never an email address or a phone number.
    $tobeparked = $DB->get_fieldset_select(
        'selfselectadvanced_member',
        'userid',
        'groupid = ? AND status = ?',
        [(int) $group->id, \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED]
    );
    $parkednames = selfselectadvanced_user_names($tobeparked);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('dissolvegroup', 'mod_selfselectadvanced'));
    echo html_writer::tag(
        'p',
        get_string('dissolvegroupconfirm', 'mod_selfselectadvanced', format_string($group->name))
    );
    if ($parkednames) {
        echo html_writer::div(
            html_writer::tag('strong', get_string('dissolveparking', 'mod_selfselectadvanced'))
            . ' ' . s(implode(', ', $parkednames)),
            'selfselectadvanced-dissolveparking'
        );
    }
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url($baseurl, ['action' => 'dissolve']))->out(false),
        'class' => 'selfselectadvanced-dissolveform mt-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::div(
        html_writer::label(
            get_string('moveoverridereason', 'mod_selfselectadvanced'),
            'ssa-dissolvereason',
            true,
            ['class' => 'form-label']
        )
        . html_writer::tag('textarea', '', [
            'class' => 'form-control',
            'id' => 'ssa-dissolvereason',
            'name' => 'reason',
            'rows' => 3,
            'required' => 'required',
        ]),
        'mb-2'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-danger',
        'value' => get_string('dissolvegroup', 'mod_selfselectadvanced'),
    ]);
    echo html_writer::link($baseurl, get_string('cancel'), ['class' => 'btn btn-secondary ms-2']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    die;
}

if ($action === 'disbandrequest') {
    // Decision 63: the leader asks the members to wind the team up.
    // GET renders the confirmation with the required reason; the POST
    // files it. The SERVICE is the authority (leader, forming, peopled,
    // no live request) - this page draws the form.
    if (data_submitted() && confirm_sesskey()) {
        $disbandreason = trim(optional_param('reason', '', PARAM_TEXT));
        if ($disbandreason === '') {
            redirect(
                new moodle_url($baseurl, ['action' => 'disbandrequest']),
                get_string('errcommentrequired', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        try {
            $api->request_disband($group, $disbandreason, FORMAT_PLAIN, (int) $USER->id);
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect(
            $baseurl,
            get_string('disbandrequestedok', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('disbandrequest', 'mod_selfselectadvanced'));
    echo html_writer::tag(
        'p',
        get_string('disbandrequestconfirm', 'mod_selfselectadvanced', format_string($group->name))
    );
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url($baseurl, ['action' => 'disbandrequest']))->out(false),
        'class' => 'selfselectadvanced-disbandform mt-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::div(
        html_writer::label(
            get_string('disbandreasonlabel', 'mod_selfselectadvanced'),
            'ssa-disbandreason',
            true,
            ['class' => 'form-label']
        )
        . html_writer::tag('textarea', '', [
            'class' => 'form-control',
            'id' => 'ssa-disbandreason',
            'name' => 'reason',
            'rows' => 4,
            'required' => 'required',
        ]),
        'mb-2'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-warning',
        'value' => get_string('disbandrequest', 'mod_selfselectadvanced'),
    ]);
    echo html_writer::link($baseurl, get_string('cancel'), ['class' => 'btn btn-secondary ms-2']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    die;
}

if ($action === 'canceldisband' && data_submitted() && confirm_sesskey()) {
    try {
        $api->cancel_disband($group, (int) $USER->id);
        redirect(
            $baseurl,
            get_string('disbandcancelled', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'selfleave' && data_submitted() && confirm_sesskey()) {
    // Decision 63: one click, own row only, only while the leader's
    // disband request stands - the service is the authority.
    try {
        $api->invitations()->self_leave($group, (int) $USER->id);
        redirect(
            $viewurl,
            get_string('disbandleft', 'mod_selfselectadvanced'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'returnforming') {
    // Decision 62 (2026-08-06): the coordinator's half of ruling 51-A2.
    // Granting a guide's relief returns the FIRM team to the state
    // before a guide was chosen. GET renders the confirmation with the
    // required reason; the POST performs it. The SERVICE is the
    // authority - queue-worker capability AND the standing
    // conflict-of-interest guard are re-asked there - this page gate
    // only refuses the obviously unauthorised early.
    \mod_selfselectadvanced\local\tickets::require_queue_authority($activity, (int) $USER->id);
    if (data_submitted() && confirm_sesskey()) {
        $returnreason = trim(optional_param('reason', '', PARAM_TEXT));
        if ($returnreason === '') {
            redirect(
                new moodle_url($baseurl, ['action' => 'returnforming']),
                get_string('errcommentrequired', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        try {
            $api->lifecycle()->return_group($group, $returnreason, (int) $USER->id);
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect(
            $baseurl,
            get_string('groupreturnedforming', 'mod_selfselectadvanced', format_string($group->name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('returnforming', 'mod_selfselectadvanced'));
    echo html_writer::tag(
        'p',
        get_string('returnformingconfirm', 'mod_selfselectadvanced', format_string($group->name))
    );
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url($baseurl, ['action' => 'returnforming']))->out(false),
        'class' => 'selfselectadvanced-returnformingform mt-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::div(
        html_writer::label(
            get_string('moveoverridereason', 'mod_selfselectadvanced'),
            'ssa-returnreason',
            true,
            ['class' => 'form-label']
        )
        . html_writer::tag('textarea', '', [
            'class' => 'form-control',
            'id' => 'ssa-returnreason',
            'name' => 'reason',
            'rows' => 3,
            'required' => 'required',
        ]),
        'mb-2'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-warning',
        'value' => get_string('returnforming', 'mod_selfselectadvanced'),
    ]);
    echo html_writer::link($baseurl, get_string('cancel'), ['class' => 'btn btn-secondary ms-2']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    die;
}

if ($action === 'resynccore' && data_submitted() && confirm_sesskey()) {
    // The manager entry point for "make the course group match the
    // team": it repairs a missing mirror (state frozen, no core group -
    // the restore hole), puts back members somebody removed by hand,
    // takes out members who have left, and reports strangers without
    // touching them. POST + sesskey; nothing mutating on a GET.
    require_capability('mod/selfselectadvanced:manage', $context);
    $sync = \mod_selfselectadvanced\local\freeze::sync_core_group($activity, (int) $group->id, (int) $USER->id);
    // ONLY 'synced' is success, and it is now set after the last core
    // write RETURNS. A run that threw halfway used to report 'synced'
    // with zero counts, which selected the green "already in step"
    // branch below for a mirror that was missing members - the single
    // symptom being a debugging() nobody sees in production.
    if ($sync->status === 'failed') {
        $notice = get_string('coregroupsyncfailed', 'mod_selfselectadvanced');
        $level = \core\output\notification::NOTIFY_WARNING;
    } else if ($sync->status !== 'synced') {
        $notice = get_string('coregroupmissing', 'mod_selfselectadvanced');
        $level = \core\output\notification::NOTIFY_WARNING;
    } else if (!$sync->added && !$sync->removed && !$sync->refused) {
        $notice = get_string('coregroupresyncinstep', 'mod_selfselectadvanced');
        $level = \core\output\notification::NOTIFY_SUCCESS;
    } else {
        $notice = get_string('coregroupresyncdone', 'mod_selfselectadvanced', (object) [
            'added' => count($sync->added),
            'removed' => count($sync->removed),
            'refused' => count($sync->refused),
        ]);
        $level = $sync->refused
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS;
    }
    // Names only: a refusal notice never carries an email address or a
    // phone number (cardinal contact-privacy rule).
    if ($sync->refused) {
        $notice .= ' ' . get_string(
            'coregroupsyncrefused',
            'mod_selfselectadvanced',
            selfselectadvanced_refused_names($sync->refused)
        );
    }
    redirect($baseurl, $notice, null, $level);
}

if ($action === 'discardcoregroup') {
    require_capability('mod/selfselectadvanced:manage', $context);
    if (data_submitted() && confirm_sesskey()) {
        try {
            \mod_selfselectadvanced\local\freeze::discard_core_group($activity, $group, (int) $USER->id);
            redirect(
                $baseurl,
                get_string('coregroupdiscarded', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    // Confirmation page: what the course group is still being used for
    // is the one thing a manager cannot see from here.
    $warnings = \mod_selfselectadvanced\local\freeze::check_restrictions($activity, $group);
    echo $OUTPUT->header();
    foreach ($warnings as $warning) {
        echo $OUTPUT->notification(
            get_string('restrictionwarning', 'mod_selfselectadvanced', $warning),
            'warning',
            false
        );
    }
    echo $OUTPUT->confirm(
        get_string('coregroupdiscardconfirm', 'mod_selfselectadvanced', format_string($group->name)),
        new single_button(
            new moodle_url($baseurl, ['action' => 'discardcoregroup']),
            get_string('discardcoregroup', 'mod_selfselectadvanced'),
            'post'
        ),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

if ($action === 'proposal') {
    // Leader (or manager) uploads, replaces or removes the written
    // proposal. CALLED, not transcribed (AUTH-002): the branch used to
    // test the raw leaderid - which decision 38 leaves in place for a
    // PROHIBITED leader - and then run file_save_draft_area_files()
    // inline, with no service for a direct POST to be refused by and
    // nothing a unit test could drive.
    //
    // The two doors below are the SAME predicates proposal::save()
    // applies, so the form is offered exactly when the save will be
    // accepted. They differ by one case on purpose: the leader of a
    // forming team whose capability was withdrawn may still REMOVE
    // their own proposal (F3), and may not upload a new one.
    $maypublishproposal = \mod_selfselectadvanced\local\proposal::may_publish($activity, $group, (int) $USER->id);
    $mayretractproposal = \mod_selfselectadvanced\local\proposal::may_retract($activity, $group, (int) $USER->id);
    if (
        (int) $group->leaderid !== (int) $USER->id
        && !has_capability('mod/selfselectadvanced:manage', $context)
    ) {
        throw new moodle_exception('refusalnotleader', 'mod_selfselectadvanced');
    }
    if (!$maypublishproposal && !$mayretractproposal) {
        // The leader of a team that has moved past forming. Refused for
        // WHEN they asked, not for who they are - see proposal::save()
        // for why the leader's window closes at submission and staff's
        // does not.
        throw new moodle_exception('refusalwrongstate', 'mod_selfselectadvanced');
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
        try {
            $kept = \mod_selfselectadvanced\local\proposal::save(
                $activity,
                (int) $group->id,
                (int) $data->proposal,
                $fileoptions,
                (int) $USER->id
            );
            redirect(
                $baseurl,
                get_string($kept > 0 ? 'proposalsaved' : 'proposalremoved', 'mod_selfselectadvanced'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            redirect($baseurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
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
// After the header, deliberately: before it $OUTPUT is still the
// bootstrap placeholder and the exporter's renderer_base parameter is
// typed.
$pagedata = $page->export_for_template($OUTPUT);
echo $OUTPUT->render_from_template('mod_selfselectadvanced/group_page', $pagedata);

// Proposal section (1.3.0): current file + upload control.
$fs = get_file_storage();
$proposalfiles = $fs->get_area_files($context->id, 'mod_selfselectadvanced', 'proposal', (int) $group->id, 'id', false);
// Who may actually DOWNLOAD it. This page admits invited-but-unconfirmed
// people, and it used to hand them a live link to a file the file server
// then refused - so an invitee clicked the team's proposal and got "file
// not found". The filename is still shown, because it was already on
// their screen and hiding it now would say less than the page said
// before; what changes is that it is no longer a link that cannot work.
//
// The answer comes off the page's own export, which calls
// teamaccess::may_read_proposal() once (audit F-4). Until 1.20.1 this
// script asked the predicate itself, in a script no unit test can
// execute - so the test that guarded it could only grep for the call,
// the file carries that same literal twice in prose, and the audit
// replaced the real call with has_capability(':viewall') and stayed
// green. A value on the exported context is a value a test can compare
// against the predicate for a named actor, which is the invariant A-05
// is actually about.
$maydownloadproposal = (bool) $pagedata->mayreadproposal;

$proposalhtml = '';
foreach ($proposalfiles as $file) {
    if (!$maydownloadproposal) {
        $proposalhtml .= html_writer::div(
            html_writer::span(s($file->get_filename()), 'text-muted')
            . ' ' . html_writer::span(
                get_string('proposalmemberonly', 'mod_selfselectadvanced'),
                'badge bg-secondary'
            )
        );
        continue;
    }
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
// THE CONTROL AND THE SERVICE AGREE. Both buttons below used to hang
// off one raw-leaderid test, so a PROHIBITED leader - still the leader
// of record under decision 38 - was offered an Edit form that
// api::update_group_details() now refuses and an Upload form that
// proposal::save() now refuses. A button that always errors is its own
// defect, so each is drawn from the predicate its own service applies.
$isleaderofrecord = (int) $group->leaderid === (int) $USER->id;
$mayeditdetails = ($isleaderofrecord && $maylead)
    || has_capability('mod/selfselectadvanced:manage', $context);
if ($mayeditdetails && $group->state === \mod_selfselectadvanced\local\state::FORMING) {
    $proposalhtml .= $OUTPUT->single_button(
        new moodle_url('/mod/selfselectadvanced/groupedit.php', ['id' => $cm->id, 'g' => $group->id]),
        get_string('editgroup', 'mod_selfselectadvanced'),
        'get'
    );
}
// The one place the two halves of the proposal verb are visibly
// different. A prohibited leader keeps the control only while there is
// something of their own to take down (F3), and it says so.
$maypublishproposal = \mod_selfselectadvanced\local\proposal::may_publish($activity, $group, (int) $USER->id);
$mayretractproposal = \mod_selfselectadvanced\local\proposal::may_retract($activity, $group, (int) $USER->id)
    && !empty($proposalfiles);
if ($maypublishproposal || $mayretractproposal) {
    $proposalhtml .= $OUTPUT->single_button(
        new moodle_url('/mod/selfselectadvanced/group.php', ['id' => $cm->id, 'g' => $group->id, 'action' => 'proposal']),
        get_string($maypublishproposal ? 'proposalupload' : 'proposalretract', 'mod_selfselectadvanced'),
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
// Flows (e), 2026-08-06: the assigned guide of a submitted, firm or
// frozen team may ask for a date-window extension or a penalty waiver.
// The service enforces the same predicate; this only decides whether
// the form is drawn.
$stateguided = $isassignedguide && in_array($group->state, [
    \mod_selfselectadvanced\local\state::PENDING_GUIDE,
    \mod_selfselectadvanced\local\state::FIRM,
    \mod_selfselectadvanced\local\state::FROZEN,
], true);
if ($stateguided) {
    $requestable[] = \mod_selfselectadvanced\local\tickets::TYPE_DATES;
    $requestable[] = \mod_selfselectadvanced\local\tickets::TYPE_PENALTY;
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
