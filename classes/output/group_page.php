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

namespace mod_selfselectadvanced\output;

use mod_selfselectadvanced\activity;
use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\authority;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\fit;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\joinrequests;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\teamaccess;
use mod_selfselectadvanced\local\ui\control;
use renderable;
use renderer_base;
use templatable;

/**
 * The group page: identity, roster, seat position, invitations and
 * leader actions (UI inventory, spec section 14.13; displays, 4A.6).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_page implements renderable, templatable {
    /** @var int Cap on responded (non-pending) EOI rows shown in the panel; older rows are left out entirely. */
    private const EOI_HISTORY_LIMIT = 20;

    /** @var int Cap on declined invitations shown; older ones are left out entirely. */
    private const DECLINED_LIMIT = 10;

    /**
     * Constructor.
     *
     * @param api $api the application facade
     * @param \stdClass $group the group row
     * @param int $userid the viewing user
     * @param \mod_selfselectadvanced\form\invite_form|null $inviteform leader's invite form, when applicable
     * @param \mod_selfselectadvanced\form\nominate_form|null $nominateform leader's succession form
     * @param \mod_selfselectadvanced\form\submit_form|null $submitform leader's submit-to-guide form
     * @param \mod_selfselectadvanced\form\appointleader_form|null $appointleaderform staff repair of a vacancy
     * @param array $appointexcluded members who cannot be appointed, each with userid, name and reason
     */
    public function __construct(
        /** @var api The application facade. */
        private readonly api $api,
        /** @var \stdClass The group row. */
        private readonly \stdClass $group,
        /** @var int The viewing user. */
        private readonly int $userid,
        /** @var \mod_selfselectadvanced\form\invite_form|null Leader's invite form. */
        private readonly ?\mod_selfselectadvanced\form\invite_form $inviteform = null,
        /** @var \mod_selfselectadvanced\form\nominate_form|null Leader's succession form. */
        private readonly ?\mod_selfselectadvanced\form\nominate_form $nominateform = null,
        /** @var \mod_selfselectadvanced\form\submit_form|null Leader's submit-to-guide form. */
        private readonly ?\mod_selfselectadvanced\form\submit_form $submitform = null,
        /** @var \mod_selfselectadvanced\form\appointleader_form|null Staff repair of a vacancy. */
        private readonly ?\mod_selfselectadvanced\form\appointleader_form $appointleaderform = null,
        /** @var array<int, array{userid: int, name: string, reason: string}> Members who cannot be appointed. */
        private readonly array $appointexcluded = [],
    ) {
    }

    /**
     * Export for the group page template.
     *
     * @param renderer_base $output the renderer
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        global $DB;

        $activity = $this->api->activity();
        $context = $activity->context();
        $cmid = $activity->cm()->id;
        $seats = $this->api->gatekeeper()->seat_position($this->group);
        $isleader = (int) $this->group->leaderid === $this->userid;
        $isforming = $this->group->state === state::FORMING;
        // AUTHORITY, asked of the page for the same reason the services
        // now ask it of themselves (1.20.1 A-02/A-03): this page's
        // leader and invitee controls were drawn from ownership and
        // lifecycle state alone, so an administrator's PROHIBIT left
        // every button exactly where it was and turned it into a form
        // that always refuses on submit - which review.php's own
        // comment calls worse than no form. The predicate is CALLED,
        // never transcribed, so the control and the service it posts to
        // cannot drift.
        $maylead = authority::may_lead($activity, $this->userid);
        $mayrespond = authority::may_respond($activity, $this->userid);

        // Staff see participant attributes on the roster (spec 8.1 read
        // access). WHO sees the mobile COLUMN is a reach question and
        // still keys on :viewall; WHOSE NUMBER appears in it is an
        // identity question and no longer does. A number renders only
        // when the viewer is connected to its owner (contactprivacy's
        // map) AND the owner consented - and the consent bypass
        // mobile_consent_bypass() describes belongs to the surfaces
        // that ask a reach question (flagged.php, review_page,
        // tickets.php), not to this one.
        //
        // DELIBERATE, and pinned by a test: a viewer admitted to this
        // page by :manage ALONE gets names and nothing else. Step 4 of
        // T-19 widened the door so the eight manager-only actions on
        // this page are reachable without :viewall; adding :manage to
        // $showmobilecol would widen the WINDOW at the same time, and
        // the cardinal rule narrows. A manager who needs the roster's
        // composition columns holds :viewall on every shipped role
        // that has :manage.
        $canviewall = has_capability('mod/selfselectadvanced:viewall', $context, $this->userid);
        // The vacancy-repair power, asked here rather than taken from the
        // caller: whether the repair panel's staff parts are exported must not
        // depend on a page remembering to say so.
        $canappoint = has_capability('mod/selfselectadvanced:manage', $context, $this->userid)
            || has_capability('mod/selfselectadvanced:managecomposition', $context, $this->userid);
        $hasidentitycap = has_capability(
            'mod/selfselectadvanced:viewparticipantidentity',
            $context,
            $this->userid
        );
        $mobilebypass = \mod_selfselectadvanced\local\contactprivacy::mobile_consent_bypass(
            $activity,
            $this->userid,
            $hasidentitycap
        );
        // ASSIGNMENT, not the bare capability. Holding :guide says this
        // person guides teams; it does not say they guide THIS one, and
        // the dimension columns and the mobile column are that team's
        // participant data. Before 1.20.1 the group.php entry gate
        // happened to hide the difference; it no longer does.
        $isguide = teamaccess::is_assigned_guide(
            $activity,
            $this->group,
            $this->userid
        );
        $isconfirmedmember = $DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $this->group->id,
            'userid' => $this->userid,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $isinvitedmember = $DB->record_exists('selfselectadvanced_member', [
            'groupid' => (int) $this->group->id,
            'userid' => $this->userid,
            'status' => groups::STATUS_INVITED,
        ]);
        $showmobilecol = $canviewall || $isguide || $isconfirmedmember;
        $showdimensioncols = $showmobilecol || $isinvitedmember;
        $rostermembers = groups::get_roster((int) $this->group->id);
        $attrs = $showdimensioncols
            ? \mod_selfselectadvanced\local\attributes\manager::get_for_users(
                array_map(static fn($m) => (int) $m->userid, $rostermembers)
            )
            : [];
        // One bulk connection map for the roster, never one per row.
        $privacymap = $showmobilecol
            ? \mod_selfselectadvanced\local\contactprivacy::can_see_map(
                $activity,
                $this->userid,
                array_map(static fn($m) => (int) $m->userid, $rostermembers)
            )
            : [];
        // The roster is a real table (2026-07-27 request): first and
        // last name as separate sortable columns plus composition
        // columns and, for the narrower contact audience, mobile.
        // Mobile is contact data, so only staff, the team's assigned
        // guide and confirmed members see it. Department and
        // sub-department are composition data: a pending invitee needs
        // them to decide what joining would do. A declined invitee has
        // no decision left to make, and outsider students see neither.
        $useddims = $showdimensioncols ? ['department', 'subdepartment'] : [];
        $rsort = optional_param('rsort', '', PARAM_ALPHANUMEXT);
        $rdir = optional_param('rdir', 0, PARAM_INT);
        $rq = optional_param('rq', '', PARAM_RAW_TRIMMED);
        $rostersortable = array_merge(['firstname', 'lastname'], $useddims, $showmobilecol ? ['mobile'] : []);

        $anymobileshown = false;
        $roster = [];
        foreach ($rostermembers as $member) {
            $attr = $attrs[(int) $member->userid] ?? null;
            $row = (object) [
                'fullname' => fullname($member),
                'firstname' => $member->firstname,
                'lastname' => $member->lastname,
                'isleader' => (bool) $member->isleader,
                'dims' => [],
            ];
            foreach ($useddims as $dim) {
                $row->$dim = (string) ($attr->$dim ?? '');
                $row->dims[] = ['value' => $row->$dim];
            }
            if ($showmobilecol) {
                $mobilevisible = !empty($privacymap[(int) $member->userid])
                    && \mod_selfselectadvanced\local\attributes\manager::mobile_visible($attr, $mobilebypass);
                $rawmobile = (string) ($attr->mobile ?? '');
                $row->mobile = $mobilevisible ? $rawmobile : get_string('mobilewithheld', 'mod_selfselectadvanced');
                $row->dims[] = ['value' => $row->mobile];
                if ($mobilevisible && $rawmobile !== '' && (int) $member->userid !== $this->userid) {
                    $anymobileshown = true;
                }
            }
            $roster[] = $row;
        }
        // Counted BEFORE the filter runs. hasroster is computed from the
        // filtered array, so it cannot answer "does this group have members?"
        // - and the template used it to gate the filter form as well as the
        // table. A filter matching nobody therefore removed the box that had
        // just been typed into, leaving a heading over empty space with no way
        // to clear the term in place.
        $rostertotal = count($roster);
        if ($rq !== '') {
            $needle = \core_text::strtolower($rq);
            $roster = array_values(array_filter($roster, static function ($row) use ($needle, $rostersortable) {
                foreach ($rostersortable as $field) {
                    if (\core_text::strpos(\core_text::strtolower((string) $row->$field), $needle) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if ($rsort !== '' && in_array($rsort, $rostersortable, true)) {
            \core_collator::asort_objects_by_property($roster, $rsort);
            $roster = array_values($roster);
            if ($rdir) {
                $roster = array_reverse($roster);
            }
        }

        // Sortable header cells and the filter form target, prepared
        // here so the template stays logic-free.
        $groupurl = new \moodle_url('/mod/selfselectadvanced/group.php', array_filter([
            'id' => $cmid,
            'g' => (int) $this->group->id,
            'rq' => $rq,
        ]));
        $rosterhead = [];
        $headcols = [
            ['col' => 'firstname', 'label' => get_string('firstname')],
            ['col' => 'lastname', 'label' => get_string('lastname')],
        ];
        foreach ($useddims as $dim) {
            $headcols[] = ['col' => $dim, 'label' => get_string('attr' . $dim, 'mod_selfselectadvanced')];
        }
        if ($showmobilecol) {
            $headcols[] = ['col' => 'mobile', 'label' => get_string('attrmobile', 'mod_selfselectadvanced')];
        }
        foreach ($headcols as $headcol) {
            $url = new \moodle_url($groupurl, [
                'rsort' => $headcol['col'],
                'rdir' => ($rsort === $headcol['col'] && !$rdir) ? 1 : 0,
            ]);
            $rosterhead[] = [
                'url' => $url->out(false),
                'label' => $headcol['label'],
                'ascending' => $rsort === $headcol['col'] && !$rdir,
                'descending' => $rsort === $headcol['col'] && $rdir,
            ];
        }

        // Pending invitations, visible to the leader, the team's own
        // assigned guide and staff. The invited and recently declined
        // seats are part of the composition they judge, so the block
        // renders the same department/sub-department shape used by the
        // join-request panel. $isguide is assignment-shaped since
        // 1.20.1, so this admits the team's own guide and nobody else's.
        $pendinginvites = [];
        $declinedtotal = 0;
        $declinedshown = 0;
        if (
            $isleader
            || $isguide
            || has_capability('mod/selfselectadvanced:viewall', $context, $this->userid)
        ) {
            $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
            $sql = "SELECT m.id AS memberid, m.userid, m.timeinvited, $namefields
                      FROM {selfselectadvanced_member} m
                      JOIN {user} u ON u.id = m.userid
                     WHERE m.groupid = :groupid AND m.status = :status
                  ORDER BY m.timeinvited ASC";
            foreach (
                $DB->get_records_sql($sql, [
                    'groupid' => $this->group->id,
                    'status' => groups::STATUS_INVITED,
                ]) as $invite
            ) {
                $pendinginvites[] = (object) [
                    'memberid' => (int) $invite->memberid,
                    'userid' => (int) $invite->userid,
                    'fullname' => fullname($invite),
                    'invitedon' => $invite->timeinvited ? userdate($invite->timeinvited) : '',
                    'declined' => false,
                ];
            }
            // Declined invitations stay visible (capped at ten): an
            // invitation auto-declined at the invitee's membership cap
            // must not simply vanish from the leader's page.
            $declinedsql = "SELECT m.id AS memberid, m.userid, m.timeresponded, $namefields
                              FROM {selfselectadvanced_member} m
                              JOIN {user} u ON u.id = m.userid
                             WHERE m.groupid = :groupid AND m.status = :status
                          ORDER BY m.timemodified DESC, m.id DESC";
            // Decision 90: a cap that nobody is told about reads as the complete
            // record, so a missing decline reads as an invitation never made.
            // The landing page already discloses its own cap; this brings the
            // group page into line rather than inventing a house style.
            $declinedtotal = $DB->count_records('selfselectadvanced_member', [
                'groupid' => (int) $this->group->id,
                'status' => groups::STATUS_DECLINED,
            ]);
            foreach (
                $DB->get_records_sql($declinedsql, [
                    'groupid' => $this->group->id,
                    'status' => groups::STATUS_DECLINED,
                ], 0, self::DECLINED_LIMIT) as $invite
            ) {
                $pendinginvites[] = (object) [
                    'memberid' => (int) $invite->memberid,
                    'userid' => (int) $invite->userid,
                    'fullname' => fullname($invite),
                    'invitedon' => $invite->timeresponded ? userdate($invite->timeresponded) : '',
                    'declined' => true,
                ];
                $declinedshown++;
            }
            $inviteattrs = $pendinginvites
                ? \mod_selfselectadvanced\local\attributes\manager::get_for_users(
                    array_map(static fn($invite) => (int) $invite->userid, $pendinginvites)
                )
                : [];
            foreach ($pendinginvites as $invite) {
                $attr = $inviteattrs[(int) $invite->userid] ?? null;
                $department = (string) ($attr->department ?? '');
                $subdepartment = (string) ($attr->subdepartment ?? '');
                $invite->department = $department;
                $invite->subdepartment = $subdepartment;
                $invite->hasdepartment = $department !== '';
                $invite->hassubdepartment = $subdepartment !== '';
                $invite->noattributes = $department === '' && $subdepartment === '';
                // Decision 60: an invitation the roster has outgrown is
                // not auto-declined - a departure can make it valid
                // again, and the leader holds withdraw - but it must
                // not LOOK acceptable when it is not. The same
                // present-violation question every door asks, asked
                // per pending invitee.
                $invite->blocked = false;
                $invite->blockedreason = '';
                if (!$invite->declined) {
                    // The ACCEPT GATE itself, not a transcription of
                    // its hard-maximum tier (seam audit B4, 1.20.20):
                    // the invitee's own landing page was fixed to ask
                    // gatekeeper::can_accept() in 1.20.18, and this
                    // leader-side panel kept the hardmax-only copy -
                    // so an invitation whose acceptance is unreachable
                    // (or whose team settled or is winding up) looked
                    // acceptable here while the invitee's page said
                    // the truth.
                    $memberrow = $DB->get_record('selfselectadvanced_member', [
                        'id' => (int) $invite->memberid,
                    ], '*', MUST_EXIST);
                    $refusal = $this->api->gatekeeper()->can_accept($this->group, $memberrow);
                    if ($refusal !== null) {
                        $invite->blocked = true;
                        $invite->blockedreason = get_string(
                            'invitationcurrentlyblocked',
                            'mod_selfselectadvanced',
                            $refusal->get_message()
                        );
                    }
                }
            }
        }

        // The viewer's own pending invitation, if any.
        $ownrow = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $this->group->id,
            'userid' => $this->userid,
        ]);
        // THE ACCEPT GATE ITSELF (external audit INV-001, 1.20.37), not a
        // transcription of mayrespond: this page used to compute
        // showrespond from the :respond capability alone, so an invitee
        // whose group filled, started winding up or left Forming after
        // the invitation was sent still saw a live Accept button here -
        // the landing page and the leader's own pending-invites panel
        // below both already ask gatekeeper::can_accept() (decisions 60
        // + 64), and this was the one screen that still told the
        // invitee something the service would refuse under lock.
        // CALLED, not transcribed, so this button and invitations::
        // accept() cannot drift into disagreeing.
        $ownacceptrefusal = ($ownrow && $ownrow->status === groups::STATUS_INVITED)
            ? $this->api->gatekeeper()->can_accept($this->group, $ownrow)
            : null;
        // The invite CONTROL asks the invite DOOR (external audit
        // MKT-03, 1.20.21): eligibility and the disabled reason both
        // come from gatekeeper::invite_door_refusal(), never from a
        // reconstruction of state and seat numbers with a hard-coded
        // sentence - which is how a team full of confirmed members
        // was still told to withdraw an invitation nobody had made.
        $invitedoorrefusal = $isleader && $maylead
            ? $this->api->gatekeeper()->invite_door_refusal($this->group)
            : null;
        $caninvite = $isleader && $maylead && $invitedoorrefusal === null;

        // Succession (spec 6.4, A3): active nomination banner for the
        // nominee, status plus cancel for the leader.
        //
        // AUTHORITY (audit F-1). Leadership can be ACQUIRED as well as
        // created, and this banner is where it is acquired. The three
        // controls it carries are gated on the capability that names
        // the action - :respond for the nominee's Accept/Decline pair,
        // :lead for the leader's Cancel - so a PROHIBIT removes
        // the button rather than leaving a form that ends at a
        // no-permission page.
        //
        // The BANNER itself is deliberately not gated: a nominee whose
        // capability has been withdrawn must still be able to see that
        // their team is waiting on them, exactly as the landing page
        // keeps listing an invitation it will no longer let them
        // answer.
        $nomineename = '';
        if (!empty($this->group->successorid)) {
            $nomineename = fullname(\core_user::get_user((int) $this->group->successorid));
        }
        $isnominee = !empty($this->group->successorid) && (int) $this->group->successorid === $this->userid;
        $mayanswernomination = $isnominee && $mayrespond;
        $nomineerefusal = null;
        if ($isnominee) {
            $nomineerefusal = $this->api->gatekeeper()->can_confirm_succession($this->group, $this->userid);
        }

        // Submission control state (T2) with the 4A.6 reason when blocked.
        $submitrefusal = null;
        if ($isleader && $isforming) {
            $submitrefusal = $this->api->gatekeeper()->can_submit($this->group, $this->userid);
        }
        $guidename = '';
        $guideseat = '';
        if (!empty($this->group->guideid)) {
            $guidename = fullname(\core_user::get_user((int) $this->group->guideid));
            // Decision 88: seat location renders beside the ASSIGNED GUIDE, on
            // the page of the group they guide, and nowhere else. lang:74 and
            // the privacy metadata have both told guides for releases that this
            // is "shown to students so they can find you", and git shows the
            // group-page half was never built - so the statement was owed, not
            // the other way round. Scoped deliberately: it does not become
            // searchable and is not added to any student-facing directory.
            $guideattr = \mod_selfselectadvanced\local\attributes\manager::get((int) $this->group->guideid);
            if ($guideattr !== null && !empty($guideattr->seatlocation)) {
                $guideseat = get_string(
                    'guideseatlabel',
                    'mod_selfselectadvanced',
                    s($guideattr->seatlocation)
                );
            }
        }

        $quota = \mod_selfselectadvanced\local\quota\evaluator::evaluate($activity, (int) $this->group->id);

        // Ask the gate rather than restating it. This used to transcribe
        // can_request_leave()'s three conditions and add a fourth the service
        // does not model - a leave already requested - which cost twice over:
        // the page could drift from the rule it had copied, and the member who
        // had already asked simply lost the button with nothing said, while the
        // leader got a whole panel about the same request. The pending state is
        // now a stated fact rather than an absent control.
        // Decision 83, applied here in 1.20.29. 1.20.28 asked the gate the right
        // question and then used only whether it said no - the sentence it wrote
        // (wrong state, you are the leader, you are not a member) went nowhere,
        // which /srv/ci/ops/control-state.sh now fails the build for.
        //
        // The split follows the ruling. Somebody who is not a confirmed member
        // has no leave to ask for: that is not-applicable, so nothing is drawn.
        // A confirmed member who is refused - the group has moved past forming,
        // or they are the leader and must hand over instead - is eligible in
        // principle and is told which. A request already sent is the same shape
        // of fact, so it arrives the same way rather than as a separate notice.
        $leaverefusal = $ownrow
            ? $this->api->gatekeeper()->can_request_leave($this->group, $ownrow, $this->userid)
            : null;
        $hasleavepending = $ownrow && !empty($ownrow->leaverequested);
        // The LEADER is excluded deliberately, and not by decision 83: leaving
        // is not a thing a leader is refused, it is a thing they do by handing
        // over first, and tests/behat/leave.feature has pinned "a leader is
        // never offered a leave control" since long before this ruling. Showing
        // them a disabled button would overturn a decision nobody made.
        $isconfirmedhere = $ownrow
            && $ownrow->status === groups::STATUS_CONFIRMED
            && !$isleader;
        if ($hasleavepending) {
            $leavereason = get_string('leavependingown', 'mod_selfselectadvanced');
        } else {
            $leavereason = $leaverefusal !== null ? control::reason_for($leaverefusal) : '';
        }
        $leavecontrol = control::decide_with_reason($isconfirmedhere, $leavereason);
        $canrequestleave = $leavecontrol->show && $leavecontrol->enabled;
        // Taking it back (1.20.40). The predicate is CALLED, not
        // transcribed: can_cancel_leave() is the same question the
        // service re-asks under the lock, so the button cannot offer
        // something the service will refuse. Drawn only when there is a
        // request to withdraw, which is exactly when the ask control
        // above is disabled - the member always has one of the two.
        $cancancelleave = $ownrow
            && $this->api->gatekeeper()->can_cancel_leave($this->group, $ownrow, $this->userid) === null;
        $leaverequests = [];
        if ($isleader && $isforming && $maylead) {
            $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
            $sql = "SELECT m.id AS memberid, m.userid, $namefields
                      FROM {selfselectadvanced_member} m
                      JOIN {user} u ON u.id = m.userid
                     WHERE m.groupid = :groupid AND m.status = :status AND m.leaverequested IS NOT NULL";
            foreach (
                $DB->get_records_sql($sql, [
                    'groupid' => $this->group->id,
                    'status' => groups::STATUS_CONFIRMED,
                ]) as $request
            ) {
                $leaverequests[] = (object) [
                    'memberid' => (int) $request->memberid,
                    'fullname' => fullname($request),
                ];
            }
        }

        // CALLED, not transcribed (audit F-6, completed by D5). The
        // freeze predicate has a home; this line and group.php's action
        // gate each used to carry a copy of it, which is the exact
        // shape that produced A-05 - two transcriptions that agreed
        // until one was edited.
        //
        // BOTH halves were calls after D5 - and both were still the
        // WRONG halves (ACT-002/ACT-003). "is this their team AND do
        // they hold :freeze" is one of the two branches the service
        // admits, not the whole of it, so this line drew the button for
        // the assigned guide alone and for nobody else: not the
        // manager, whose page this also is and who does not hold
        // :freeze by archetype at all, and not the coordinator, who
        // freezes on the guide's behalf by :coordinate. The question is
        // now the service's own - freeze::may_freeze_team(), which IS
        // freeze_group()'s gate run without an exception - so the
        // button and the POST it leads to cannot admit different
        // people. State stays here, beside it: authority and
        // eligibility are different questions and only one of them
        // belongs to the actor.
        $canfreeze = $this->group->state === state::FIRM
            && freeze::may_freeze_team($activity, $this->group, $this->userid);
        // The same treatment, for the same reason, on the other
        // direction of the same pair (UX-001). This line asked the
        // CAPABILITY alone, which is neither of the two branches
        // unfreeze() admits: it hid the button from the assigned guide
        // releasing a team no member of staff froze - the release
        // strategy 1.19 C exists to give them, on the page it belongs
        // on - and drew it for a Group Coordinator involved with this
        // very team, whom the service refuses on conflict of interest.
        // freeze::may_unfreeze_team() IS unfreeze()'s door run without
        // an exception, so the button and the POST admit one set of
        // people. State stays here beside it, as above.
        // Decision 83: ask for the PRESENTATION, not the boolean. This used to
        // call may_unfreeze_team(), which is release_refusal() with its sentence
        // thrown away - so a guide refused because staff enforced the freeze saw
        // an empty space here and the very same refusal, spelled out, on their
        // own dashboard.
        $unfreezecontrol = freeze::release_control($activity, $this->group, $this->userid);
        $canunfreeze = $unfreezecontrol->show && $unfreezecontrol->enabled;
        // Mirror maintenance (T-16). Resync is offered whenever there is
        // something to converge - including a frozen team whose course
        // group has gone, which is what the resync mints. Discard is the
        // only interactive delete, and never while frozen: the next sync
        // would just mint it again.
        $canmanagemirror = has_capability('mod/selfselectadvanced:manage', $context, $this->userid);
        $canresynccore = $canmanagemirror
            && (!empty($this->group->coregroupid) || $this->group->state === state::FROZEN);
        $candiscardcore = $canmanagemirror
            && !empty($this->group->coregroupid)
            && $this->group->state !== state::FROZEN;
        // Dissolve (decision 6, D6-3): the exit from a team that can be
        // neither repaired nor deleted. Offered in EVERY state, because
        // the dead end it resolves is a FIRM or FROZEN solo-leader team
        // that delete_group() (leader + forming only) cannot touch.
        // Both capabilities together: it destroys a team and parks its
        // members.
        $candissolve = $canmanagemirror
            && has_capability('mod/selfselectadvanced:overriderules', $context, $this->userid);

        // Decision 63: the consent-first disband surfaces. One live
        // request drives four controls: the leader's cancel, each
        // member's one-click leave, the banner with the composed
        // reason, and the Delete button's disabled state while members
        // remain. The REQUEST control itself appears only for a
        // leader whose forming team has members and no live request.
        $disbandlive = !empty($this->group->timedisbandrequested);
        $othermembers = $DB->count_records_select(
            'selfselectadvanced_member',
            'groupid = ? AND status = ? AND userid <> ?',
            [(int) $this->group->id, groups::STATUS_CONFIRMED, (int) $this->group->leaderid]
        );
        $showdisbandrequest = $isleader && $isforming && $maylead
            && !$disbandlive && $othermembers > 0;
        $showdisbandcancel = $isleader && $isforming && $maylead && $disbandlive;
        // No $mayrespond factor (seam audit B5, 1.20.20): self_leave()
        // deliberately asks NO capability - the disband request IS the
        // leader's standing consent - so gating the button on :respond
        // hid the one-click exit from exactly the member a prohibited
        // :respond leaves stuck. The verb's own gate (forming + live
        // request + own confirmed row + not leader) is what renders it.
        $showselfleave = $disbandlive && $isforming
            && !$isleader && $isconfirmedmember;
        // The DELETE door itself, not a transcription of its roster
        // count (seam audit B6, 1.20.20): can_delete_group() is the
        // producer of both the verdict and the refusal sentence, so
        // the disabled reason here can never drift from the refusal
        // the click would meet.
        $deleterefusal = $isleader && $isforming && $maylead
            ? $this->api->gatekeeper()->can_delete_group($this->group, $this->userid)
            : null;
        $deletedisabledreason = $deleterefusal !== null ? $deleterefusal->get_message() : '';

        // Decision 62: the return-to-forming control for a FIRM team.
        // Drawn only for a queue worker (coordinator or manager) who is
        // NOT involved with this team - the same standing conflict rule
        // the service re-asks. A control the service would refuse is
        // worse than no control.
        // Decision 83. Being a queue worker is the CAPABILITY question, so a
        // person without it never sees the control. Being involved with this
        // very team is a RULE, so a worker who is involved sees the control
        // disabled - and reads the GENERIC sentence, because involvement()
        // returns "the assigned guide" or "a confirmed member" and naming which
        // would disclose a relationship the reader may not be entitled to.
        $returncontrol = control::decide_with_reason(false, '');
        if ($this->group->state === state::FIRM) {
            $isworker = has_capability('mod/selfselectadvanced:manage', $context, $this->userid)
                || has_capability('mod/selfselectadvanced:coordinate', $context, $this->userid);
            $involvement = \mod_selfselectadvanced\local\tickets::involvement(
                $activity,
                $this->group,
                $this->userid
            );
            $returncontrol = control::decide_with_reason(
                $isworker,
                $involvement === null ? '' : get_string('refusalcoishielded', 'mod_selfselectadvanced')
            );
        }
        $canreturnforming = $returncontrol->show && $returncontrol->enabled;

        // Expressions of interest (spec: EOI). The leader (and staff)
        // see the full panel; other members see only the count line.
        // Acceptance pre-assigns the guide while the team is still
        // forming, ahead of the usual submit-time assignment.
        $canmanage = has_capability('mod/selfselectadvanced:manage', $context, $this->userid);
        $eoienabled = !empty($activity->settings()->eoienabled);
        $listed = !empty($this->group->listed);
        // THE TWO HALVES OF THE LISTING TOGGLE ARE NOT THE SAME CONTROL
        // (AUTH-001). One flag used to draw both buttons from the raw
        // leaderid, so a PROHIBITED leader - still the leader of record
        // under decision 38 - was shown a live "List this team for
        // guides" button that no crafted POST was needed to press.
        //
        // eoi::set_listed() asks for leader authority on the LISTING
        // half only, because listing publishes the team to every guide
        // and unlisting takes it back down; F3 says an actor is never
        // blocked from making themselves less visible. The flags mirror
        // that exactly, so the prohibited leader keeps the button that
        // still works and loses the one that would refuse them.
        $showeoilist = $isleader && $isforming && $eoienabled && $maylead;
        $showeoiunlist = $isleader && $isforming && $eoienabled;
        $showeoipanel = $eoienabled && ($isleader || $canviewall || $canmanage);
        // The :assignguide capability decides an interest as well as
        // :manage - the same pair group.php's eoirespond gate and
        // eoi::respond() ask (ACT-004). Until this wave only the
        // service half was written: the capability described as "assign
        // or reassign a team's guide, AND DECIDE EXPRESSIONS OF
        // INTEREST" reached eoi::respond() from a test and from nowhere
        // a person could click. The holder that matters is the Group
        // Coordinator, who carries :assignguide and :viewall and NOT
        // :manage - so they were shown the panel, shown the pending
        // interests, and shown no way to answer them.
        //
        // Drawn from the SERVICE'S refusal ladder, not from a local
        // capability test (blind audit 1.20.3, finding 1): a
        // narrow-authority coordinator with an interest of their own
        // pending here - or an involvement with this team - was
        // offered Accept/Decline that eoi::respond() then refused.
        // The service owns the predicate; this renderer and group.php's
        // door both consume it, so no button is drawn that can only
        // error. The leader/:manage arms and AUTH-004's $maylead all
        // live inside decide_refusal().
        // Decision 83: the refusal ladder already wrote the sentence; using
        // only its existence left a leader with interests waiting and no way
        // to answer them and nothing said about why.
        // eoi::decide_refusal() answers with a plain object carrying stringkey
        // and $a rather than a refusal, so the sentence is resolved here and the
        // decision still goes through the one policy.
        $eoirefusal = eoi::decide_refusal($activity, $this->group, $this->userid);
        $eoicontrol = control::decide_with_reason(
            $isleader || $canviewall,
            $eoirefusal === null
                ? ''
                : (control::is_shielded($eoirefusal->stringkey)
                    ? get_string('refusalcoishielded', 'mod_selfselectadvanced')
                    : get_string($eoirefusal->stringkey, 'mod_selfselectadvanced', $eoirefusal->a))
        );
        $caneoirespond = $eoicontrol->show && $eoicontrol->enabled;
        $eoidropped = 0;
        $eoiassigned = $isforming && !empty($this->group->guideid);
        $eoiinterestline = '';
        $eoirows = [];
        $showeoiempty = false;
        $showeoisequentialnote = false;
        if ($eoienabled) {
            $allinterests = eoi::for_group($activity, (int) $this->group->id);
            $pendingcount = count(array_filter(
                $allinterests,
                static fn(\stdClass $row): bool => $row->status === eoi::STATUS_PENDING
            ));
            if ($pendingcount > 0) {
                $eoiinterestline = get_string('eoiinterestline', 'mod_selfselectadvanced', $pendingcount);
            }
            if ($showeoipanel) {
                $showeoiempty = $listed && empty($allinterests);
                $sequential = !empty($activity->settings()->eoisequential);

                // Every pending row is shown (subject to the sequential
                // one-at-a-time rule below); responded history beyond the
                // most recent EOI_HISTORY_LIMIT rows is left out of the
                // panel rather than growing it without bound.
                $respondedindexes = [];
                foreach ($allinterests as $index => $interest) {
                    if ($interest->status !== eoi::STATUS_PENDING) {
                        $respondedindexes[] = $index;
                    }
                }
                $excludedresponded = count($respondedindexes) > self::EOI_HISTORY_LIMIT
                    ? array_flip(array_slice(
                        $respondedindexes,
                        0,
                        count($respondedindexes) - self::EOI_HISTORY_LIMIT
                    ))
                    : [];
                // Decision 90: the count is already known here, so there is no
                // reason for the reader not to know it too.
                $eoidropped = count($excludedresponded);

                $seenpending = false;
                $pendingshown = 0;
                $displayedinterests = [];
                foreach ($allinterests as $index => $interest) {
                    $ispending = $interest->status === eoi::STATUS_PENDING;
                    if ($ispending) {
                        if ($sequential && $seenpending) {
                            // Later pending interests wait their turn (spec eoisequential).
                            continue;
                        }
                        $seenpending = true;
                        $pendingshown++;
                    } else if (isset($excludedresponded[$index])) {
                        continue;
                    }
                    $displayedinterests[] = $interest;
                }
                $showeoisequentialnote = $sequential && $pendingcount > $pendingshown;

                // One bulk lookup for every guide named in the panel,
                // chunked defensively, instead of one get_user() per row.
                $guideids = array_values(array_unique(array_map(
                    static fn(\stdClass $row): int => (int) $row->guideid,
                    $displayedinterests
                )));
                $guides = [];
                foreach (array_chunk($guideids, 1000) as $chunk) {
                    $guides += $DB->get_records_list('user', 'id', $chunk);
                }

                foreach ($displayedinterests as $interest) {
                    $eoirows[] = (object) [
                        'eoiid' => (int) $interest->id,
                        'guidename' => isset($guides[(int) $interest->guideid])
                            ? fullname($guides[(int) $interest->guideid])
                            : '',
                        'remarks' => $interest->remarks !== null && $interest->remarks !== ''
                            ? format_text($interest->remarks, (int) $interest->remarksformat, ['context' => $context])
                            : '',
                        'timecreated' => userdate($interest->timecreated),
                        'statuslabel' => get_string('eoistatus' . $interest->status, 'mod_selfselectadvanced'),
                        'ispending' => $interest->status === eoi::STATUS_PENDING,
                        'accepturl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                            'id' => $cmid,
                            'g' => $this->group->id,
                            'action' => 'eoirespond',
                            'eoiid' => (int) $interest->id,
                            'decision' => 'accept',
                        ]))->out(false),
                        'rejecturl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                            'id' => $cmid,
                            'g' => $this->group->id,
                            'action' => 'eoirespond',
                            'eoiid' => (int) $interest->id,
                            'decision' => 'reject',
                        ]))->out(false),
                    ];
                }
            }
        }

        // INCOMING JOIN REQUESTS, on the page the team already lives on
        // (maintainer decision 53: "Messages, group count and
        // composition has to be fixed" - a forming leader had to
        // discover joinrequest.php to learn that anybody had asked).
        //
        // Nothing about the workflow is rebuilt here. The rows come
        // from joinrequests::waiting_for_group(), the door is
        // joinrequests::require_decider() - CALLED, never transcribed,
        // so the panel and the POST that group.php routes to
        // joinrequests::respond() admit exactly one set of people - and
        // the verdict is fit::for_person(), the same call the "Asked of
        // my team" tab makes.
        //
        // BOTH ARMS of that door matter and the try/catch is how both
        // are asked at once: the leader, AND the coordinator or manager
        // acting for an absent one. A local $isleader test here would
        // have drawn the panel for one arm and left the other looking
        // at a page that says nothing was asked (the wave-1 lesson).
        //
        // The authority question is asked FIRST because it is answered
        // from the capability cache, so an ordinary member's page load
        // does not pay for the query that follows.
        // Decision 83: ASK for the sentence, do not reduce it to a bit. This
        // called may_decide_joins(), which was decide_refusal() === '' - so a
        // leader refused for a conflict of interest saw a page with no queue
        // on it at all, while the standalone queue page printed that very
        // refusal (decision 65). Same actor, same requests, two answers.
        //
        // The refused panel is shown to the LEADER only. A refusal reaches
        // people who are not the decider too, and telling an ordinary member
        // that requests are waiting would disclose the queue to somebody with
        // no standing to see it. The rows stay hidden either way: a refused
        // decider may not read other students' reasons while they cannot act.
        $joinrefusal = joinrequests::decide_refusal($activity, $this->group, $this->userid);
        $canjoindecide = $joinrefusal === '';
        $joinwaiting = 0;
        $joinrows = [];
        if (!$canjoindecide && $isleader) {
            $joinwaiting = count(joinrequests::waiting_for_group($activity, (int) $this->group->id));
        }
        if ($canjoindecide) {
            $waiting = joinrequests::waiting_for_group($activity, (int) $this->group->id);
            if ($waiting) {
                $requesterids = array_values(array_unique(array_map(
                    static fn(\stdClass $row): int => (int) $row->userid,
                    $waiting
                )));
                // One bulk lookup each for the people and their
                // attributes, chunked defensively - never a get_user()
                // or a get_for_users() inside the loop.
                $requesters = [];
                foreach (array_chunk($requesterids, 1000) as $chunk) {
                    $requesters += $DB->get_records_list('user', 'id', $chunk);
                }
                // The SHARED accessor, the one the roster above already
                // uses. DEPARTMENT and SUB-DEPARTMENT only: they are
                // COMPOSITION attributes, which is exactly what a
                // leader is deciding with, and the cardinal rule is
                // about contact details. No mobile number and no
                // address is read here, for any viewer, in either state
                // of the contact-privacy switch.
                $requesterattrs = \mod_selfselectadvanced\local\attributes\manager::get_for_users($requesterids);
                // THE "WOULD LEAVE" LOOKUP WENT WITH DECISION 77, here and on
                // the Asked-of-my-group tab. Acceptance changes one roster now,
                // and it is the one the leader is looking at.

                foreach ($waiting as $request) {
                    $userid = (int) $request->userid;
                    // Shown, never used to hide the request: a leader
                    // is entitled to accept somebody the rules would
                    // refuse today, which is the rule fit's own
                    // docblock is built around. Uncapped, and
                    // deliberately so - this is ONE team's queue, where
                    // the tab already carries every team in the
                    // activity through the same call.
                    $verdict = fit::for_person($activity, $this->group, $userid, $request);
                    $decision = joinrequests::accept_decision($activity, $request, $this->userid, $this->group);
                    $attr = $requesterattrs[$userid] ?? null;
                    $department = (string) ($attr->department ?? '');
                    $subdepartment = (string) ($attr->subdepartment ?? '');
                    $warnings = array_values(array_unique(array_merge(
                        $verdict->warnings ?? [],
                        $decision->warnings ?? []
                    )));
                    $joinrows[] = (object) [
                        'requestid' => (int) $request->id,
                        'fullname' => isset($requesters[$userid]) ? fullname($requesters[$userid]) : '',
                        'reason' => shorten_text((string) $request->reason, 200),
                        'department' => $department,
                        'subdepartment' => $subdepartment,
                        'hasdepartment' => $department !== '',
                        'hassubdepartment' => $subdepartment !== '',
                        'noattributes' => $department === '' && $subdepartment === '',
                        'fits' => (bool) $verdict->fits,
                        'caution' => (string) $verdict->caution,
                        // The pending-invitation warning is the WHOLE
                        // POINT of the rule that a pending invitation
                        // no longer hard-blocks (decision 53): the
                        // leader is told the maximum is only over
                        // because of an invitation they can withdraw.
                        // Dropping it here left this panel - the
                        // surface that decision was made for - showing
                        // a plain green fit with the reason to think
                        // twice removed (blind audit, 1.20.5).
                        'warnings' => array_values(array_map(
                            static fn(string $w): array => ['text' => $w],
                            $warnings
                        )),
                        'haswarnings' => !empty($warnings),
                        'canaccept' => (bool) $decision->canaccept,
                        'cannotaccept' => !$decision->canaccept,
                        'hardreason' => (string) $decision->hardreason,
                        'confirmationrequired' => (bool) $decision->confirmationrequired,
                        'confirmacceptrequired' => (bool) $decision->confirmacceptrequired,
                        // Decisions 60 + 64: the confirm tier is
                        // consent-only - pending invitations affected,
                        // no rule broken, nothing bypassed - so the
                        // dialog always speaks consent. Rule refusals
                        // never reach this dialog: they disable the
                        // button (leader) or take the staff override
                        // path with its own confirmation.
                        'confirmacceptmessage' => get_string('joinacceptconsent', 'mod_selfselectadvanced'),
                        'seatline' => $verdict->seat !== null
                            ? get_string('joinfitseat', 'mod_selfselectadvanced', $verdict->seat)
                            : '',
                        'asked' => userdate((int) $request->timecreated),
                    ];
                }
            }
        }

        // The tabbed leader panel (maintainer, 2026-08-06): the three
        // action clusters below the roster - invite, succession, submit
        // - become Bootstrap tabs. Each tab exists only when its
        // content would have rendered; the active tab is the FIRST one
        // that exists, and a pending nomination badges the succession
        // tab so a nominee cannot miss the question waiting for them.
        // With JavaScript off the panes all render stacked (noscript
        // rule in the template), which is also why the non-JS Behat
        // drivers see every form exactly as before.
        // The TAB is a question about phase and authority; the CONTROL
        // inside it is the door's question (audit F07, 1.20.23). This
        // used to transcribe ONE arm of the door - "or the seats are
        // full" - so for every other refusal the door gives (cutoff
        // passed, not open yet, winding up with seats free) the whole
        // cluster vanished and took with it the disabled reason this
        // exporter had already built below. That is exactly the drift
        // MKT-03 fixed on this very control in 1.20.21, reintroduced
        // for three of the door's four arms. A leader who may lead a
        // FORMING team always sees the cluster: enabled when the door
        // allows, disabled with the door's own sentence when it does not.
        $tabinvite = $isleader && $isforming && $maylead;
        // Decision 83: the whole Leadership succession tab used to vanish for a
        // leader whose team has no other confirmed member - indistinguishable
        // from the feature not existing. A solo leader is eligible in principle
        // and simply has nobody to nominate yet, which is a sentence, not an
        // absence. The tab stays for any leader who may lead; its pane explains
        // itself when the form could not be built.
        $tabsuccession = !empty($this->group->successorid)
            || $this->nominateform !== null
            || ($isleader && $isforming && $maylead);
        $successionempty = $tabsuccession
            && empty($this->group->successorid)
            && $this->nominateform === null
            ? get_string('successionnobody', 'mod_selfselectadvanced')
            : '';
        $tabsubmit = $this->submitform !== null && $maylead;
        $activetab = $tabinvite ? 'invite' : ($tabsuccession ? 'succession' : 'submit');

        // 1.20.53 deliverable A: the group's own live requests. The
        // maintainer's report named this the sharpest gap - a student
        // files a ticket FROM this very page, and the page never
        // mentions it again once the one-time filing notice has
        // scrolled away. Who sees which is the EXISTING authority, not
        // a new one: tickets::group_live() itself enforces "the
        // requester sees their own; queue authority sees the group's
        // whole live set; everybody else sees nothing" - this exporter
        // only asks the one boolean and draws what comes back.
        $hasqueueauthority = \mod_selfselectadvanced\local\tickets::has_queue_authority($activity, $this->userid);
        $groupticketrows = [];
        foreach (
            \mod_selfselectadvanced\local\tickets::group_live(
                $activity,
                (int) $this->group->id,
                $this->userid,
                $hasqueueauthority
            ) as $groupticket
        ) {
            $isrequester = (int) $groupticket->requestedby === $this->userid;
            $groupticketrows[] = (object) [
                'id' => (int) $groupticket->id,
                // 1.20.56 deliverable A: the ticket's OWN quotable
                // reference - named distinctly from the group's own
                // pluginuid (already exported at the top level of this
                // same template) so the two can never be confused.
                'ticketpluginuid' => (string) $groupticket->pluginuid,
                'typelabel' => get_string('tickettype' . $groupticket->type, 'mod_selfselectadvanced'),
                'statuslabel' => get_string('ticketstatus' . $groupticket->status, 'mod_selfselectadvanced'),
                'raised' => userdate((int) $groupticket->timecreated, get_string('strftimedatetimeshort')),
                'threadurl' => (new \moodle_url('/mod/selfselectadvanced/ticket.php', [
                    't' => (int) $groupticket->id,
                ]))->out(false),
                // Deliverable A: "when the viewer is the requester and
                // the status is needs-info - a clearly marked 'Your
                // reply is needed' control". A staff viewer sees the
                // same row with the status label alone; this is never
                // shown for anybody but the requester themselves.
                'needsyourreply' => $isrequester
                    && $groupticket->status === \mod_selfselectadvanced\local\tickets::STATUS_NEEDSINFO,
            ];
        }

        return (object) [
            'groupticketrows' => $groupticketrows,
            'hasgroupticketrows' => !empty($groupticketrows),
            'showleadertabs' => $tabinvite || $tabsuccession || $tabsubmit,
            'tabinvite' => $tabinvite,
            'tabsuccession' => $tabsuccession,
            'successionempty' => $successionempty,
            'tabsubmit' => $tabsubmit,
            'invitetabactive' => $tabinvite && $activetab === 'invite',
            'successiontabactive' => $tabsuccession && $activetab === 'succession',
            'submittabactive' => $tabsubmit && $activetab === 'submit',
            // NO EMPTY SCAFFOLDING: the panel exists when somebody has
            // actually asked, and at no other time. A leader with an
            // empty queue gets the page they had before this wave.
            'showjoinpanel' => !empty($joinrows),
            'joinblockedreason' => $joinwaiting > 0
                ? get_string('joinpanelblocked', 'mod_selfselectadvanced', (object) [
                    'count' => $joinwaiting,
                    'reason' => $joinrefusal,
                ])
                : '',
            'joinrows' => $joinrows,
            'joinallurl' => (new \moodle_url('/mod/selfselectadvanced/joinrequest.php', [
                'id' => $cmid,
                'tab' => 'answer',
            ]))->out(false),
            'eoienabled' => $eoienabled,
            'listed' => $listed,
            'showeoilist' => $showeoilist,
            'showeoiunlist' => $showeoiunlist,
            'showeoipanel' => $showeoipanel,
            'caneoirespond' => $caneoirespond,
            'eoitruncated' => $eoidropped > 0
                ? get_string('eoitruncated', 'mod_selfselectadvanced', $eoidropped)
                : '',
            'eoiblockedreason' => $eoicontrol->show && !$eoicontrol->enabled
                ? $eoicontrol->reason
                : '',
            'eoiassigned' => $eoiassigned,
            'eoiinterestline' => $eoiinterestline,
            'eoirows' => $eoirows,
            'haseoirows' => !empty($eoirows),
            'showeoiempty' => $showeoiempty,
            'showeoisequentialnote' => $showeoisequentialnote,
            'canrequestleave' => $canrequestleave,
            'cancancelleave' => $cancancelleave,
            'leavereason' => $leavecontrol->show && !$leavecontrol->enabled ? $leavecontrol->reason : '',
            'leaverequests' => $leaverequests,
            'hasleaverequests' => !empty($leaverequests),
            'canfreeze' => $canfreeze,
            'freezeurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'freeze',
            ]))->out(false),
            'canunfreeze' => $canunfreeze,
            'unfreezereason' => $unfreezecontrol->show && !$unfreezecontrol->enabled
                ? $unfreezecontrol->reason
                : '',
            'unfreezeurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'unfreeze',
            ]))->out(false),
            'canresynccore' => $canresynccore,
            'resynccoreurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
            ]))->out(false),
            'candiscardcore' => $candiscardcore,
            'discardcoreurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'discardcoregroup',
            ]))->out(false),
            'candissolve' => $candissolve,
            'canreturnforming' => $canreturnforming,
            'returnformingreason' => $returncontrol->show && !$returncontrol->enabled
                ? $returncontrol->reason
                : '',
            'returnformingurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'returnforming',
            ]))->out(false),
            'dissolveurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'dissolve',
            ]))->out(false),
            // The proposal section is drawn by group.php, below this
            // template, but the QUESTION it asks belongs here with the
            // page's other per-viewer answers (audit F-4). Exported so
            // there is something a unit test can compare against
            // teamaccess::may_read_proposal() for a named actor: while
            // the call lived in the script, the only guard anybody
            // could write was a grep for its own text, and group.php
            // carries that same literal twice in prose.
            'mayreadproposal' => \mod_selfselectadvanced\local\teamaccess::may_read_proposal(
                $activity,
                $this->group,
                $this->userid
            ),
            'quota' => $quota,
            // AUTHORITY is a factor here as well as in group.php's
            // decision to BUILD the form (audit D2). Two reasons, and
            // the second is the one that matters: the flag also draws
            // the section's heading and its blocked-reason line, which
            // a prohibited leader has no business being shown; and this
            // exporter is instantiable directly - the unit tests do it
            // on every page assertion - so a factor that lives only in
            // the calling script is a factor no test can reach.
            'showsubmit' => $this->submitform !== null && $maylead,
            'submitformhtml' => $this->submitform?->render() ?? '',
            'submitblockedreason' => $this->submit_blocked_reason($isleader, $isforming, $submitrefusal),
            // Decision 73, the readiness half. can_submit() returns the FIRST
            // refusal, so a leader with two live sidecars learns them one page
            // load at a time and cannot see how far from ready they are. The
            // panel lists them all at once, each with the action that clears
            // it - the remedies already exist as page actions; what was missing
            // was anywhere to offer them from.
            'hasreadiness' => $isleader && $isforming && $maylead
                && $this->api->gatekeeper()->submit_sidecars($this->group) !== [],
            'readiness' => $isleader && $isforming && $maylead
                ? array_map(
                    fn($refusal) => $this->readiness_item($refusal, $cmid),
                    $this->api->gatekeeper()->submit_sidecars($this->group)
                )
                : [],
            'guidename' => $guidename,
            'hasguide' => $guidename !== '',
            'guideseat' => $guideseat,
            'returncomment' => $isforming && !empty($this->group->returncomment)
                ? format_text($this->group->returncomment, (int) $this->group->returncommentformat, ['context' => $context])
                : '',
            'hasnomination' => !empty($this->group->successorid),
            'nomineename' => $nomineename,
            'nominationisstepout' => ($this->group->successortype ?? '') === 'stepout',
            // The template draws Confirm and Decline inside this
            // section and nothing else, so the flag carries the
            // authority as well as the identity (F-1).
            'isnominee' => $mayanswernomination,
            'nomineeblocked' => $mayanswernomination && $nomineerefusal !== null,
            'nomineeblockedreason' => $nomineerefusal?->get_message(),
            'shownominateform' => $this->nominateform !== null,
            'nominateformhtml' => $this->nominateform?->render() ?? '',
            'pluginuid' => $this->group->pluginuid,
            'name' => format_string($this->group->name),
            'title' => format_string($this->group->title),
            'brief' => format_text($this->group->brief, $this->group->briefformat, ['context' => $context]),
            'statelabel' => get_string('state' . str_replace('_', '', $this->group->state), 'mod_selfselectadvanced'),
            'seatsummary' => get_string('seatsummary', 'mod_selfselectadvanced', $seats),
            'minsizenote' => get_string('minsizenote', 'mod_selfselectadvanced', $seats),
            'roster' => $roster,
            'hasroster' => !empty($roster),
            // The hasanyroster flag gates the filter FORM, hasroster the TABLE.
            // They differ only while a filter is excluding everybody, which is
            // exactly the case the page used to render as blank space.
            'hasanyroster' => $rostertotal > 0,
            'rosternomatch' => $rostertotal > 0 && empty($roster)
                ? get_string('rosternomatch', 'mod_selfselectadvanced', (object) [
                    'needle' => s($rq),
                    'total' => $rostertotal,
                ])
                : '',
            'showmobilecaution' => $anymobileshown,
            'rosterhead' => $rosterhead,
            'rosterfilter' => $rq,
            'rosterfilteraction' => $groupurl->out_omit_querystring(false),
            // A RENDER INSTRUCTION, not a fact about the row: the two
            // places the template consults this flag are both leader
            // CONTROLS - Withdraw on a pending invitation, and Cancel
            // nomination - and both of the services behind them require
            // :lead. The roster's per-row leader badge is a
            // different variable in a different scope and still says
            // who leads. Ownership itself is unchanged and is asserted
            // on the group row by the tests, not read off here.
            'isleader' => $isleader && $maylead,
            'candelete' => $isleader && $isforming && $maylead && $deleterefusal === null,
            'deletedisabledreason' => $deletedisabledreason,
            'disbandlive' => $disbandlive,
            'disbandreason' => $disbandlive
                ? format_text(
                    (string) $this->group->disbandreason,
                    (int) ($this->group->disbandreasonformat ?? FORMAT_PLAIN),
                    ['context' => $context]
                )
                : '',
            'showdisbandrequest' => $showdisbandrequest,
            'showdisbandcancel' => $showdisbandcancel,
            'showselfleave' => $showselfleave,
            'disbandrequesturl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'disbandrequest',
            ]))->out(false),
            'caninvite' => $caninvite,
            'inviteformhtml' => $caninvite && $this->inviteform ? $this->inviteform->render() : '',
            // A LEADERSHIP VACANCY IS SHOWN TO EVERY VIEWER, staff or not.
            // Somebody looking at a group whose leader has gone is entitled to
            // know why nothing can be submitted, and members keep their
            // existing Leadership help route - with no leader they are all
            // non-leaders, which is exactly what that ticket is for.
            'leadervacant' => $this->group->leaderid === null,
            'leadervacantnotice' => get_string('leadervacantnotice', 'mod_selfselectadvanced'),
            // The staff control is separate from the notice: an ordinary
            // member sees the fact, not the repair.
            'appointleaderformhtml' => $this->appointleaderform ? $this->appointleaderform->render() : '',
            // GATED ON THE APPOINTING POWER, not merely on the vacancy. The
            // excluded list names members and says why each cannot lead, and
            // the empty state announces that nobody in the group can - both are
            // staff information about other people, so a peer must not receive
            // them even though the peer is told the vacancy exists.
            'appointexcluded' => $canappoint ? array_values($this->appointexcluded) : [],
            'hasappointexcluded' => $canappoint && $this->appointexcluded !== [],
            // Staff with the power but nobody to appoint need an honest empty
            // state rather than a blank control.
            'appointleadernocandidates' => $canappoint
                && $this->group->leaderid === null
                && $this->appointleaderform === null,
            'invitedisabledreason' => $isleader && $isforming && $maylead && $invitedoorrefusal !== null
                ? $invitedoorrefusal->get_message()
                : '',
            'pendinginvites' => $pendinginvites,
            'haspendinginvites' => !empty($pendinginvites),
            'declinedtruncated' => $declinedtotal > $declinedshown
                ? get_string('declinedtruncated', 'mod_selfselectadvanced', (object) [
                    'shown' => $declinedshown,
                    'total' => $declinedtotal,
                ])
                : '',
            // Decision 83, and the audit's point about this page departing from
            // its own pattern. Having an invitation waiting is a FACT and is
            // shown to the invitee whatever their capability; Accept/Decline
            // are CONTROLS and hide when :respond is prohibited. Before this the
            // two were ANDed into one flag, so a prohibited invitee lost the
            // prompt as well as the buttons and the group page said nothing at
            // all - while the sibling nomination question is exported ungated on
            // purpose, for exactly this reason.
            'hasinvitationhere' => (bool) ($ownrow && $ownrow->status === groups::STATUS_INVITED),
            // INV-001: three flags rather than one, because CAPABILITY and the
            // ACCEPT GATE are different questions with different audiences.
            // 'candecline' is the :respond capability answer alone - cleanup
            // (withdrawing from an offer the group has outgrown) must never be
            // blocked by the same rule that blocks joining, so Decline is drawn
            // whenever this is true, with no further question asked of it.
            // 'showrespond' additionally requires can_accept() to return null -
            // Accept is drawn ONLY then. 'acceptgateblocked' is the remaining
            // case: capability present, invitation present, but the gate
            // refuses - Decline still shows, Accept does not, and the reason
            // the gate gave is exported beside it rather than discarded.
            'candecline' => (bool) ($ownrow && $ownrow->status === groups::STATUS_INVITED && $mayrespond),
            'showrespond' => $ownrow
                && $ownrow->status === groups::STATUS_INVITED
                && $mayrespond
                && $ownacceptrefusal === null,
            'acceptgateblocked' => $ownrow
                && $ownrow->status === groups::STATUS_INVITED
                && $mayrespond
                && $ownacceptrefusal !== null,
            'acceptblockedreason' => $ownacceptrefusal !== null
                ? get_string('invitationcurrentlyblocked', 'mod_selfselectadvanced', $ownacceptrefusal->get_message())
                : '',
            'respondblocked' => $ownrow && $ownrow->status === groups::STATUS_INVITED && !$mayrespond,
            'sesskey' => sesskey(),
            'actionurl' => (new \moodle_url('/mod/selfselectadvanced/group.php'))->out(false),
            'cmid' => $cmid,
            'groupid' => (int) $this->group->id,
            'deleteurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'delete',
            ]))->out(false),
            'backurl' => (new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $cmid]))->out(false),
        ];
    }


    /**
     * The reason the submit button is disabled, or '' when it is live.
     *
     * The gate's refusal wins; with no refusal the remaining reason a
     * leader can face is leader-selects mode with no guide currently
     * holding free capacity.
     *
     * @param bool $isleader the viewer leads the group
     * @param bool $isforming the group is forming
     * @param \mod_selfselectadvanced\local\rules\refusal|null $submitrefusal the submit gate's verdict
     * @return string
     */
    private function submit_blocked_reason(
        bool $isleader,
        bool $isforming,
        ?\mod_selfselectadvanced\local\rules\refusal $submitrefusal
    ): string {
        if (!$isleader || !$isforming) {
            return '';
        }
        if ($submitrefusal !== null) {
            return $submitrefusal->get_message();
        }
        $activity = $this->api->activity();
        $leaderselects = empty($this->group->guideid) && (int) $activity->settings()->guidemode === 0;
        if (
            $leaderselects
            && !\mod_selfselectadvanced\local\guides::selectable($activity, $this->api->gatekeeper()->resolver())
        ) {
            return get_string('submitnoguides', 'mod_selfselectadvanced');
        }

        return '';
    }
    /**
     * One readiness row: what is blocking Submit, and the click that clears it.
     *
     * The remedy is chosen from the refusal's own key rather than recomputed
     * from group state, so a row can never offer a remedy for a condition that
     * is not actually present - which is how the pre-1.20.20 renderer came to
     * hard-code "withdraw an invitation" for teams with nothing to withdraw.
     *
     * @param \mod_selfselectadvanced\local\rules\refusal $refusal one live sidecar
     * @param int $cmid course module id
     * @return array text, and the remedy label and url when one exists
     */
    private function readiness_item(\mod_selfselectadvanced\local\rules\refusal $refusal, int $cmid): array {
        $remedies = [
            'refusalsubmitdisbanding' => ['canceldisband', 'remedycanceldisband'],
            'refusalsubmitnomination' => ['cancelnomination', 'remedycancelnomination'],
            'refusalsubmitinvitespending' => ['withdrawall', 'remedywithdrawall'],
            // A pending leave is answered per member in the panel above, so
            // this row explains and points rather than acting: a single click
            // cannot decide FOR the leader which way to answer.
            'refusalsubmitleavepending' => [null, null],
        ];
        [$action, $labelkey] = $remedies[$refusal->stringkey] ?? [null, null];

        return [
            'text' => $refusal->get_message(),
            'hasremedy' => $action !== null,
            'remedylabel' => $labelkey ? get_string($labelkey, 'mod_selfselectadvanced') : '',
            'remedyaction' => $action ?? '',
            'remedyurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => (int) $this->group->id,
            ]))->out(false),
        ];
    }
}
