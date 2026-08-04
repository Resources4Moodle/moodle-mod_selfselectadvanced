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

    /**
     * Constructor.
     *
     * @param api $api the application facade
     * @param \stdClass $group the group row
     * @param int $userid the viewing user
     * @param \mod_selfselectadvanced\form\invite_form|null $inviteform leader's invite form, when applicable
     * @param \mod_selfselectadvanced\form\nominate_form|null $nominateform leader's succession form
     * @param \mod_selfselectadvanced\form\submit_form|null $submitform leader's submit-to-guide form
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
        $showmobilecol = $canviewall || $isguide || $isconfirmedmember;
        $rostermembers = groups::get_roster((int) $this->group->id);
        $attrs = $showmobilecol
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
        // last name as separate sortable columns plus one column per
        // composition dimension the activity uses, with a text filter.
        // The dimension columns follow the same audience as the mobile
        // column - staff, the guide, and the team's own confirmed
        // members - because a team is assembled BY those values and
        // the composition panel already tells its members which ones
        // the seat plan still needs. Outsider students see neither.
        $useddims = $showmobilecol
            ? \mod_selfselectadvanced\local\attributes\manager::used_dimensions($activity)
            : [];
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
        // assigned guide and staff. The invited-but-unanswered seats
        // are part of the composition the guide judges, and the block
        // renders a name and nothing else. $isguide is assignment-
        // shaped since 1.20.1, so this admits the team's own guide and
        // nobody else's.
        $pendinginvites = [];
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
                          ORDER BY m.timemodified DESC";
            foreach (
                $DB->get_records_sql($declinedsql, [
                    'groupid' => $this->group->id,
                    'status' => groups::STATUS_DECLINED,
                ], 0, 10) as $invite
            ) {
                $pendinginvites[] = (object) [
                    'memberid' => (int) $invite->memberid,
                    'fullname' => fullname($invite),
                    'invitedon' => $invite->timeresponded ? userdate($invite->timeresponded) : '',
                    'declined' => true,
                ];
            }
        }

        // The viewer's own pending invitation, if any.
        $ownrow = $DB->get_record('selfselectadvanced_member', [
            'groupid' => $this->group->id,
            'userid' => $this->userid,
        ]);
        $caninvite = $isleader && $isforming && $seats->free > 0 && $maylead;

        // Succession (spec 6.4, A3): active nomination banner for the
        // nominee, status plus cancel for the leader.
        //
        // AUTHORITY (audit F-1). Leadership can be ACQUIRED as well as
        // created, and this banner is where it is acquired. The three
        // controls it carries are gated on the capability that names
        // the action - :respond for the nominee's Accept/Decline pair,
        // :creategroup for the leader's Cancel - so a PROHIBIT removes
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
        if (!empty($this->group->guideid)) {
            $guidename = fullname(\core_user::get_user((int) $this->group->guideid));
        }

        $quota = \mod_selfselectadvanced\local\quota\evaluator::evaluate($activity, (int) $this->group->id);

        $canrequestleave = $isforming
            && !$isleader
            && $ownrow
            && $ownrow->status === groups::STATUS_CONFIRMED
            && empty($ownrow->leaverequested);
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
        $canunfreeze = $this->group->state === state::FROZEN
            && freeze::may_unfreeze_team($activity, $this->group, $this->userid);
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
        $caneoirespond = eoi::decide_refusal($activity, $this->group, $this->userid) === null;
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
        $canjoindecide = $this->may_decide_joins($activity, $this->userid);
        $joinrows = [];
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
                // What the acceptance costs elsewhere, batched before
                // the loop for the same reason the tab batches it.
                $sourceids = [];
                foreach ($waiting as $request) {
                    if ($request->sourcegroupid) {
                        $sourceids[(int) $request->sourcegroupid] = true;
                    }
                }
                $sourcenames = $sourceids
                    ? $DB->get_records_list(
                        'selfselectadvanced_group',
                        'id',
                        array_keys($sourceids),
                        '',
                        'id, name'
                    )
                    : [];

                foreach ($waiting as $request) {
                    $userid = (int) $request->userid;
                    // Shown, never used to hide the request: a leader
                    // is entitled to accept somebody the rules would
                    // refuse today, which is the rule fit's own
                    // docblock is built around. Uncapped, and
                    // deliberately so - this is ONE team's queue, where
                    // the tab already carries every team in the
                    // activity through the same call.
                    $verdict = fit::for_person($activity, $this->group, $userid);
                    $attr = $requesterattrs[$userid] ?? null;
                    $department = (string) ($attr->department ?? '');
                    $subdepartment = (string) ($attr->subdepartment ?? '');
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
                            $verdict->warnings ?? []
                        )),
                        'haswarnings' => !empty($verdict->warnings),
                        'seatline' => $verdict->seat !== null
                            ? get_string('joinfitseat', 'mod_selfselectadvanced', $verdict->seat)
                            : '',
                        'leavesline' => isset($sourcenames[(int) $request->sourcegroupid])
                            ? get_string(
                                'joinleaves',
                                'mod_selfselectadvanced',
                                format_string($sourcenames[(int) $request->sourcegroupid]->name)
                            )
                            : get_string('joinleavesnone', 'mod_selfselectadvanced'),
                        'asked' => userdate((int) $request->timecreated),
                    ];
                }
            }
        }

        return (object) [
            // NO EMPTY SCAFFOLDING: the panel exists when somebody has
            // actually asked, and at no other time. A leader with an
            // empty queue gets the page they had before this wave.
            'showjoinpanel' => !empty($joinrows),
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
            'eoiassigned' => $eoiassigned,
            'eoiinterestline' => $eoiinterestline,
            'eoirows' => $eoirows,
            'haseoirows' => !empty($eoirows),
            'showeoiempty' => $showeoiempty,
            'showeoisequentialnote' => $showeoisequentialnote,
            'canrequestleave' => $canrequestleave,
            'leaverequests' => $leaverequests,
            'hasleaverequests' => !empty($leaverequests),
            'canfreeze' => $canfreeze,
            'freezeurl' => (new \moodle_url('/mod/selfselectadvanced/group.php', [
                'id' => $cmid,
                'g' => $this->group->id,
                'action' => 'freeze',
            ]))->out(false),
            'canunfreeze' => $canunfreeze,
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
            'guidename' => $guidename,
            'hasguide' => $guidename !== '',
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
            'showmobilecaution' => $anymobileshown,
            'rosterhead' => $rosterhead,
            'rosterfilter' => $rq,
            'rosterfilteraction' => $groupurl->out_omit_querystring(false),
            // A RENDER INSTRUCTION, not a fact about the row: the two
            // places the template consults this flag are both leader
            // CONTROLS - Withdraw on a pending invitation, and Cancel
            // nomination - and both of the services behind them require
            // :creategroup. The roster's per-row leader badge is a
            // different variable in a different scope and still says
            // who leads. Ownership itself is unchanged and is asserted
            // on the group row by the tests, not read off here.
            'isleader' => $isleader && $maylead,
            'candelete' => $isleader && $isforming && $maylead,
            'caninvite' => $caninvite,
            'inviteformhtml' => $caninvite && $this->inviteform ? $this->inviteform->render() : '',
            'invitedisabledreason' => $isleader && $isforming && $maylead && $seats->free < 1
                ? get_string('refusalnoseats', 'mod_selfselectadvanced')
                : '',
            'pendinginvites' => $pendinginvites,
            'haspendinginvites' => !empty($pendinginvites),
            'showrespond' => $ownrow && $ownrow->status === groups::STATUS_INVITED && $mayrespond,
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
     * Whether this viewer may answer a request to join this team.
     *
     * The SERVICE'S OWN DOOR, asked without an exception.
     * joinrequests::require_decider() is the gate
     * joinrequests::respond() applies under its lock, and it admits two
     * arms: the target team's leader, and a coordinator or manager
     * acting for an absent one. Transcribing either arm here would
     * produce the defect wave 1 caught - a panel keyed on one arm while
     * the POST behind it admits both - so the predicate is called and
     * its refusal is turned into a boolean.
     *
     * @param activity $activity the activity
     * @param int $userid the viewer
     * @return bool
     */
    private function may_decide_joins(activity $activity, int $userid): bool {
        try {
            joinrequests::require_decider($activity, $this->group, $userid);
        } catch (\moodle_exception $e) {
            return false;
        }

        return true;
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
}
