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

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
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

        // Staff see participant attributes on the roster (spec 8.1 read
        // access). The mobile column is broader than the attribute
        // dimensions: viewall holders always see it; a guide (without
        // viewall) and the group's own confirmed members — the leader
        // and teammates the consent strings promise — see it gated per
        // member on that member's own consent (manager::mobile_visible;
        // no viewall bypass for any of them). Outsider students never
        // see the column.
        $canviewall = has_capability('mod/selfselectadvanced:viewall', $context, $this->userid);
        $isguide = has_capability('mod/selfselectadvanced:guide', $context, $this->userid, false);
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
        // The roster is a real table (2026-07-27 request): first and
        // last name as separate sortable columns plus one column per
        // composition dimension the activity uses, with a text filter.
        // Attribute (dimension) columns stay staff-only, matching the
        // old attrline privacy split; only the mobile column widens.
        $useddims = $canviewall
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
                $mobilevisible = \mod_selfselectadvanced\local\attributes\manager::mobile_visible($attr, $canviewall);
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

        // Pending invitations, visible to the leader and staff.
        $pendinginvites = [];
        if ($isleader || has_capability('mod/selfselectadvanced:viewall', $context, $this->userid)) {
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
        $caninvite = $isleader && $isforming && $seats->free > 0;

        // Succession (spec 6.4, A3): active nomination banner for the
        // nominee, status plus cancel for the leader.
        $nomineename = '';
        if (!empty($this->group->successorid)) {
            $nomineename = fullname(\core_user::get_user((int) $this->group->successorid));
        }
        $isnominee = !empty($this->group->successorid) && (int) $this->group->successorid === $this->userid;
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
        if ($isleader && $isforming) {
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

        $canfreeze = $this->group->state === state::FIRM
            && (int) ($this->group->guideid ?? 0) === $this->userid
            && has_capability('mod/selfselectadvanced:freeze', $context, $this->userid);
        $canunfreeze = $this->group->state === state::FROZEN
            && has_capability('mod/selfselectadvanced:unfreeze', $context, $this->userid);

        // Expressions of interest (spec: EOI). The leader (and staff)
        // see the full panel; other members see only the count line.
        // Acceptance pre-assigns the guide while the team is still
        // forming, ahead of the usual submit-time assignment.
        $canmanage = has_capability('mod/selfselectadvanced:manage', $context, $this->userid);
        $eoienabled = !empty($activity->settings()->eoienabled);
        $listed = !empty($this->group->listed);
        $showeoitoggle = $isleader && $isforming && $eoienabled;
        $showeoipanel = $eoienabled && ($isleader || $canviewall || $canmanage);
        $caneoirespond = $isleader || $canmanage;
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

        return (object) [
            'eoienabled' => $eoienabled,
            'listed' => $listed,
            'showeoitoggle' => $showeoitoggle,
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
            'quota' => $quota,
            'showsubmit' => $this->submitform !== null,
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
            'isnominee' => $isnominee,
            'nomineeblocked' => $isnominee && $nomineerefusal !== null,
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
            'isleader' => $isleader,
            'candelete' => $isleader && $isforming,
            'caninvite' => $caninvite,
            'inviteformhtml' => $caninvite && $this->inviteform ? $this->inviteform->render() : '',
            'invitedisabledreason' => $isleader && $isforming && $seats->free < 1
                ? get_string('refusalnoseats', 'mod_selfselectadvanced')
                : '',
            'pendinginvites' => $pendinginvites,
            'haspendinginvites' => !empty($pendinginvites),
            'showrespond' => $ownrow && $ownrow->status === groups::STATUS_INVITED,
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
}
