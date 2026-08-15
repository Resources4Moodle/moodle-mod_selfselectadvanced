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
use mod_selfselectadvanced\local\tickets;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The ticket thread (slice B2): one ticket's whole conversation, modelled
 * on mod_forum's post rendering - author column, content column, one post
 * after another, a reply affordance at the bottom - copying the SHAPE
 * only; the data comes from tickets::trail(), never from forum tables.
 *
 * Two audiences see two different trails (maintainer decision 3, carried
 * from B1): staff get every row with the actor named; the requester gets
 * every row too (every row here IS a state change) but with staff actors
 * anonymised, reusing the "Somebody is handling this." idiom's tone
 * rather than its exact string, because a trail line names an ACTION a
 * standalone hint sentence does not.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_page implements renderable, templatable {
    /**
     * Trail actions the REQUESTER themself performed - everything else
     * in tickets::ACTION_* is a staff action, and the split is what lets
     * the anonymised requester view print "You" for their own rows and
     * "Somebody" for everyone else's without ever being handed an actor
     * id to strip (trail($withactors=false) never carries one at all).
     */
    private const REQUESTER_ACTIONS = [
        tickets::ACTION_FILED,
        tickets::ACTION_INFOREPLY,
        tickets::ACTION_WITHDRAWN,
    ];

    /**
     * Constructor.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @param stdClass|null $group the team it is about, or null
     * @param int $viewerid the person viewing
     * @param bool $isrequester whether the viewer filed this ticket
     * @param bool $isstaff whether the viewer holds queue authority
     */
    public function __construct(
        /** @var activity The activity. */
        private readonly activity $activity,
        /** @var stdClass The ticket row. */
        private readonly stdClass $ticket,
        /** @var stdClass|null The team the ticket is about, or null. */
        private readonly ?stdClass $group,
        /** @var int The viewer. */
        private readonly int $viewerid,
        /** @var bool Whether the viewer filed this ticket. */
        private readonly bool $isrequester,
        /** @var bool Whether the viewer holds queue authority. */
        private readonly bool $isstaff,
    ) {
    }

    /**
     * Export for the thread template.
     *
     * @param renderer_base $output the renderer
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $context = $this->activity->context();
        $cmid = $this->activity->cm()->id;
        $ticket = $this->ticket;
        $isclaimant = $this->isstaff && (int) $ticket->claimedby === $this->viewerid;

        $subject = $this->group !== null
            ? format_string($this->group->name)
            : get_string('tickethasnoteam', 'mod_selfselectadvanced');

        $requesterlabel = $this->isrequester
            ? get_string('threadactorself', 'mod_selfselectadvanced')
            : $this->requester_fullname();
        $contactline = null;
        if ($isclaimant) {
            // The one connection contact privacy opens to a coordinator
            // (rule (c)): an ACTIVE CLAIM, exactly what tickets.php's
            // queue already gates the same mobile line on - so the map
            // is only worth asking for when the viewer actually holds
            // this ticket's claim. Routed through requester_contact_map()
            // rather than the raw user row - the cardinal rule (never
            // email, mobile only when consented and connected).
            $map = tickets::requester_contact_map($this->activity, $this->viewerid, [(int) $ticket->requestedby]);
            $mobile = $map[(int) $ticket->requestedby]->mobile ?? '';
            if ($mobile !== '') {
                $contactline = $mobile;
            }
        }

        $entries = [];
        $withactors = $this->isstaff;
        foreach (tickets::trail($this->activity, (int) $ticket->id, $withactors) as $row) {
            $entries[] = $this->export_entry($row, $withactors, $requesterlabel);
        }

        $data = [
            'cmid' => $cmid,
            'ticketid' => (int) $ticket->id,
            'typelabel' => get_string('tickettype' . $ticket->type, 'mod_selfselectadvanced'),
            'subject' => $subject,
            'statuslabel' => get_string('ticketstatus' . $ticket->status, 'mod_selfselectadvanced'),
            'statusclass' => $this->status_badge_class($ticket->status),
            'raisedtime' => userdate((int) $ticket->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'requesterlabel' => $requesterlabel,
            'contactline' => $contactline,
            'openingpost' => format_text((string) $ticket->request, (int) $ticket->requestformat, ['context' => $context]),
            'entries' => $entries,
            'hasentries' => !empty($entries),
            'actionurl' => (new \moodle_url('/mod/selfselectadvanced/ticket.php'))->out(false),
            'sesskey' => sesskey(),
            'formatmoodle' => FORMAT_MOODLE,
            'backurl' => $this->backurl(),
            'backlabel' => get_string('back'),
        ];
        $data += (array) $this->export_actionbox($isclaimant);
        $data += (array) $this->export_history();

        return (object) $data;
    }

    /**
     * The requester's full name, shown to staff only - the requester
     * themself always reads as "You" (export_for_template above), and
     * name display carries no contact-privacy restriction of its own:
     * the cardinal rule protects email and phone, not identity, and
     * this page's door already requires queue authority to reach a
     * ticket that is not the viewer's own.
     *
     * @return string
     */
    private function requester_fullname(): string {
        $requester = \core_user::get_user((int) $this->ticket->requestedby);

        return $requester ? fullname($requester) : (string) $this->ticket->requestedby;
    }

    /**
     * One trail row, rendered for either audience.
     *
     * @param stdClass $row a tickets::trail() row
     * @param bool $withactors whether this is the staff (named-actor) trail
     * @param string $requesterlabel "You" or the requester's name, for filed/inforeply/withdrawn rows
     * @return stdClass
     */
    private function export_entry(stdClass $row, bool $withactors, string $requesterlabel): stdClass {
        $context = $this->activity->context();
        $isrequesteraction = in_array($row->action, self::REQUESTER_ACTIONS, true);

        if ($withactors) {
            $actorlabel = $isrequesteraction && (int) $row->actorid === (int) $this->ticket->requestedby
                ? $requesterlabel
                : $row->actorname;
        } else {
            // The anonymised requester trail: an action the REQUESTER
            // performed is their own and reads as "You" (their own
            // reply is never hidden from them); every staff action reads
            // as "Somebody" - same tone as myrequestsclaimedhint's
            // "Somebody is handling this.", never a name, never an id.
            $actorlabel = $isrequesteraction
                ? get_string('threadactorself', 'mod_selfselectadvanced')
                : get_string('threadactoranon', 'mod_selfselectadvanced');
        }

        return (object) [
            'actiontext' => get_string('threadentry' . $row->action, 'mod_selfselectadvanced', $actorlabel),
            'hasnote' => $row->note !== null && trim((string) $row->note) !== '',
            'note' => $row->note !== null
                ? format_text((string) $row->note, (int) $row->noteformat, ['context' => $context])
                : '',
            'time' => userdate((int) $row->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        ];
    }

    /**
     * The action box at the foot of the thread: which control set this
     * viewer gets, keyed by their relationship to the ticket and its
     * status (spec deliverable 1's exhaustive table).
     *
     * @param bool $isclaimant whether the viewer is THIS ticket's claimant
     * @return stdClass
     */
    private function export_actionbox(bool $isclaimant): stdClass {
        $ticket = $this->ticket;

        $box = (object) [
            'showtakeup' => false,
            'takeupdisabled' => false,
            'takeupreason' => '',
            'showclaimantforms' => false,
            'showrequestinfo' => false,
            'showrelease' => false,
            'isguidecap' => $ticket->type === tickets::TYPE_GUIDECAP,
            'guidecaprequested' => (int) ($ticket->requested ?? 0),
            'showclaimedbyline' => false,
            'claimedbyline' => '',
            'showprovideinfo' => false,
            'showwithdraw' => false,
        ];

        if ($this->isstaff && $ticket->status === tickets::STATUS_OPEN) {
            $box->showtakeup = true;
            if ($this->group !== null) {
                try {
                    tickets::require_uninvolved($this->activity, $this->group, $this->viewerid);
                } catch (\mod_selfselectadvanced\local\workflow_refusal $e) {
                    $box->takeupdisabled = true;
                    $box->takeupreason = $e->getMessage();
                }
            } else if ((int) $ticket->requestedby === $this->viewerid) {
                $box->takeupdisabled = true;
                $box->takeupreason = get_string('refusalcoiself', 'mod_selfselectadvanced');
            }
        }

        if ($isclaimant && in_array($ticket->status, [tickets::STATUS_CLAIMED, tickets::STATUS_NEEDSINFO], true)) {
            $box->showclaimantforms = true;
            $box->showrequestinfo = $ticket->status === tickets::STATUS_CLAIMED;
            // Restored per orchestrator review (2026-08-15): the
            // claimant's hand-back-to-the-queue affordance, alongside
            // the three note-carrying forms rather than replacing any of
            // them. close() itself already permits the open outcome
            // from BOTH claimed and needsinfo (decision 2, LIVENESS -
            // "release from needsinfo is allowed by the same widening
            // rather than singled out"), so the button appears in both
            // states; nothing here widens what the service accepts.
            $box->showrelease = true;
        } else if (
            $this->isstaff && !$isclaimant
            && in_array($ticket->status, [tickets::STATUS_CLAIMED, tickets::STATUS_NEEDSINFO], true)
        ) {
            $box->showclaimedbyline = true;
            $claimant = $ticket->claimedby ? \core_user::get_user((int) $ticket->claimedby) : null;
            $box->claimedbyline = get_string(
                'ticketthreadclaimedby',
                'mod_selfselectadvanced',
                $claimant ? fullname($claimant) : (string) $ticket->claimedby
            );
        }

        if ($this->isrequester && $ticket->status === tickets::STATUS_NEEDSINFO) {
            $box->showprovideinfo = true;
        }
        if ($this->isrequester && $ticket->status === tickets::STATUS_OPEN) {
            $box->showwithdraw = true;
        }

        return $box;
    }

    /**
     * "Previous tickets from this requester" (deliverable 1's repeated-
     * request blocker) - staff only, never the requester (they have
     * myrequests.php already).
     *
     * @return stdClass
     */
    private function export_history(): stdClass {
        if (!$this->isstaff) {
            return (object) ['showhistory' => false, 'history' => []];
        }

        $rows = tickets::history(
            $this->activity,
            (int) $this->ticket->requestedby,
            $this->viewerid,
            (int) $this->ticket->id
        );
        $history = [];
        foreach ($rows as $row) {
            $subject = $row->groupname !== null
                ? format_string($row->groupname) . ' (' . s($row->grouppluginuid) . ')'
                : get_string('tickethasnoteam', 'mod_selfselectadvanced');
            $history[] = (object) [
                'subject' => $subject,
                'typelabel' => get_string('tickettype' . $row->type, 'mod_selfselectadvanced'),
                'statuslabel' => get_string('ticketstatus' . $row->status, 'mod_selfselectadvanced'),
                'date' => userdate((int) $row->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'url' => (new \moodle_url('/mod/selfselectadvanced/ticket.php', ['t' => $row->id]))->out(false),
            ];
        }

        return (object) ['showhistory' => !empty($history), 'history' => $history];
    }

    /**
     * A status badge's Bootstrap colour class. Cosmetic only - a wrong
     * value here mislabels no capability decision.
     *
     * @param string $status tickets::STATUS_*
     * @return string
     */
    private function status_badge_class(string $status): string {
        return match ($status) {
            tickets::STATUS_OPEN => 'bg-secondary',
            tickets::STATUS_CLAIMED => 'bg-info text-dark',
            tickets::STATUS_NEEDSINFO => 'bg-warning text-dark',
            tickets::STATUS_RESOLVED => 'bg-success',
            tickets::STATUS_DECLINED => 'bg-danger',
            tickets::STATUS_WITHDRAWN => 'bg-secondary',
            default => 'bg-secondary',
        };
    }

    /**
     * Where "back" goes: the queue for staff, the requester's own list
     * for the requester - the same split notify() used to make for the
     * message link, now made once, here, for the page itself.
     *
     * @return string
     */
    private function backurl(): string {
        $cmid = $this->activity->cm()->id;
        $url = $this->isrequester
            ? new \moodle_url('/mod/selfselectadvanced/myrequests.php', ['id' => $cmid])
            : new \moodle_url('/mod/selfselectadvanced/tickets.php', ['id' => $cmid]);

        return $url->out(false);
    }
}
