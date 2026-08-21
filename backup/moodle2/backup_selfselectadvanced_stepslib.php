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
 * Backup structure for mod_selfselectadvanced (spec 14.11).
 *
 * Included: instance settings (now also guide volunteering and the
 * team-listing/EOI settings, previously missing from this list); with
 * userinfo also groups (now carrying returncommentformat, listed and
 * timelisted), members, snapshots, user/group/guide-scope overrides,
 * volunteered guiding capacity (1.7.0), penalties, guide expressions
 * of interest keyed to their group (1.11.0) and queue tickets
 * (1.16.0). EXCLUDED by design and documented (review item
 * M2): agrun logs (operational) and staged moves (transient manager
 * state - a restore must never replay half-staged edits). Site-wide
 * participant attributes are not course data and are never in course
 * backups.
 *
 * The queued digest notifications (selfselectadvanced_digestq) joined
 * the exclusion list in 1.20.4 (BACKUP-001). They were backed up from
 * 1.8.0 with their contexturl and resolved JSON payload copied
 * verbatim, on the theory that a throwaway queue needed no remapping -
 * but a queue row is a notification IN FLIGHT: its deep link names the
 * ORIGINAL activity (or decodes to a restore token), its payload names
 * people by their old names, and a same-site duplicate doubled every
 * recipient's pending digest. Operational queue state belongs to the
 * running site, not to the course; the restore step ignores any
 * digestitem an old archive still carries.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * The single structure step.
 */
class backup_selfselectadvanced_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the backup tree.
     *
     * @return backup_nested_element the wrapped structure
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $activity = new backup_nested_element('selfselectadvanced', ['id'], [
            'name', 'intro', 'introformat', 'grade', 'minsize', 'maxsize', 'maxlead',
            'maxmembership', 'maxguided', 'uidprefix', 'uiddigits',
            'uidformat', 'contactmax', 'timeopen', 'timedue', 'timecutoff',
            'penaltytype', 'penaltyperday', 'guidemode', 'inviteexpiry', 'autogroup', 'proposalrequired',
            'guidewindow', 'guideautoapprove', 'guidevolunteer', 'studentapproach',
            'eoienabled', 'eoiwindow', 'eoimax', 'eoisequential', 'eoipeers', 'eoigroupmax',
            'minmembership', 'defaulterpenalty', 'incompletepenalty', 'leadershare',
            'contactprivacy',
            // ADDED 2026-08-13 (external audit BAK-001). Both were schema
            // additions that never reached this list, so a restored activity
            // silently changed policy: joinexpiry fell back to 0, turning
            // request expiry OFF, and mirrorat fell back to 0, moving Moodle
            // course-group creation from approval to freeze - which downstream
            // group activities can depend on. schema_backup_contract_test now
            // fails the build when a new activity column has no backup policy,
            // because this is the second time the same class of omission
            // shipped.
            'joinexpiry', 'mirrorat',
            // 1.20.43 (the settings release): the who-may-raise
            // checkboxes, the responsible-person mode, and the optional
            // ticket disclaimer.
            'ticketraiseguide', 'ticketraiseleader', 'ticketraisemember', 'ticketresponsiblemode',
            'ticketdisclaimer', 'ticketdisclaimerformat',
            // 1.20.58 deliverable A: the target first-response time.
            'tickettargethours',
            'timecreated', 'timemodified',
        ]);
        $quotas = new backup_nested_element('quotas');
        $quota = new backup_nested_element('quota', ['id'], [
            'dimension', 'rtype', 'value', 'mincount', 'maxcount', 'priority', 'timecreated', 'timemodified',
        ]);
        $groups = new backup_nested_element('groups');
        $group = new backup_nested_element('group', ['id'], [
            'pluginuid', 'name', 'title', 'brief', 'briefformat', 'leaderid', 'guideid',
            'state', 'autoformed', 'successorid', 'successortype', 'timenominated',
            'guidesuccessorid', 'timeguidenominated',
            'returncomment', 'returncommentformat', 'listed', 'timelisted',
            'guidenotes', 'guidenotesformat', 'timesubmitted', 'timeapproved', 'timefrozen',
            'frozenbystaff', 'releasedbyguide', 'coregroupid',
            'timedisbandrequested', 'disbandreason', 'disbandreasonformat',
            'timecreated', 'timemodified',
        ]);
        $members = new backup_nested_element('members');
        $member = new backup_nested_element('member', ['id'], [
            'userid', 'status', 'isleader', 'invitedby', 'timeinvited', 'timeresponded',
            'leaverequested', 'timecreated', 'timemodified',
        ]);
        $snapshots = new backup_nested_element('snapshots');
        $snapshot = new backup_nested_element('snapshot', ['id'], [
            'coregroupid', 'roster', 'takenby', 'timecreated',
        ]);
        $penalty = new backup_nested_element('penalty', ['id'], [
            'dayslate', 'penaltyvalue', 'award', 'waived', 'waivereason', 'basis', 'timecomputed',
        ]);
        $qslots = new backup_nested_element('qslots');
        $qslot = new backup_nested_element('qslot', ['id'], [
            'slotno', 'mincount', 'dimension', 'matchtype', 'value', 'allowoverlap', 'timecreated', 'timemodified',
        ]);
        $tpls = new backup_nested_element('templates');
        $tpl = new backup_nested_element('template', ['id'], [
            'msgkey', 'subject', 'body', 'timecreated', 'timemodified',
        ]);
        $overrides = new backup_nested_element('overrides');
        $override = new backup_nested_element('override', ['id'], [
            'scope', 'userid', 'groupid', 'timeopen', 'timedue', 'timecutoff', 'maxlead',
            'maxmembership', 'maxguided', 'minsize', 'maxsize', 'quotaexempt',
            'penaltywaived', 'guidehidden', 'status', 'timecreated', 'timemodified',
        ]);
        $volunteers = new backup_nested_element('volunteers');
        $volunteer = new backup_nested_element('volunteer', ['id'], [
            'userid', 'capacity', 'timecreated', 'timemodified',
        ]);
        $eois = new backup_nested_element('eois');
        $eoi = new backup_nested_element('eoi', ['id'], [
            'guideid', 'status', 'remarks', 'remarksformat', 'timecreated', 'timeresponded',
        ]);
        $contacts = new backup_nested_element('contacts');
        $contact = new backup_nested_element('contact', ['id'], [
            'groupid', 'guideid', 'status', 'sentby', 'message', 'messageformat',
            'reason', 'reasonformat', 'timecreated', 'timeresponded',
        ]);
        // Join requests that are still WAITING. Staged moves stay out of
        // the backup as transient manager working state (M2), and that
        // was true of the whole table until 1.19 put join requests in
        // it: an open request is a student waiting on an answer, and it
        // carries the reason they wrote. Answered ones are history and
        // stay out with the rest.
        $joinrequests = new backup_nested_element('joinrequests');
        $joinrequest = new backup_nested_element('joinrequest', ['id'], [
            'userid', 'sourcegroupid', 'targetgroupid', 'status',
            'reason', 'responsenote', 'usermodified', 'timecreated', 'timemodified',
        ]);
        $tickets = new backup_nested_element('tickets');
        $ticket = new backup_nested_element('ticket', ['id'], [
            'pluginuid', 'groupid', 'type', 'status', 'requestedby', 'request', 'requestformat',
            'claimedby', 'timeclaimed', 'resolvedby', 'timeresolved',
            'resolution', 'resolutionformat', 'timecreated', 'timemodified',
            'requested', 'disclaimerack', 'escalated',
            // 1.20.59: the requester's "did this help?" feedback -
            // schema_backup_contract_test's docblock is emphatic that
            // two prior releases forgot this step (joinexpiry, mirrorat).
            'verdict', 'verdictnote', 'timeverdict',
        ]);
        // The history trail (decision 1, 2026-08-15) nests under its own
        // ticket, not under the activity beside $tickets: a trail row is
        // meaningless without the ticket it narrates.
        //
        // ticketid TRAVELS EXPLICITLY, unlike a member's groupid or a
        // snapshot's groupid, which restore recovers from
        // get_new_parentid() because a group row is NEVER dropped by
        // process_ssagroup(). A ticket CAN be dropped by
        // process_ssaticket() (unmappable requestedby, or an unmappable
        // group for a team ticket) - restore still visits every
        // ticketlog nested under a dropped ticket's XML element
        // regardless, and get_new_parentid('ssaticket') would then
        // return the STALE id of whichever OTHER ticket most recently
        // succeeded, silently reparenting the trail row onto the wrong
        // ticket. Carrying the real old id and mapping it with
        // get_mappingid() is what lets restore tell "this ticket was
        // dropped" apart from "some other ticket exists".
        $ticketlogs = new backup_nested_element('ticketlogs');
        $ticketlog = new backup_nested_element('ticketlog', ['id'], [
            'ticketid', 'actorid', 'action', 'note', 'noteformat', 'timecreated',
        ]);
        // The knowledgebank (1.20.45): sourceticketid travels EXPLICITLY,
        // the same reason ticketlog's own ticketid does above (a ticket
        // can be dropped by process_ssaticket() on restore, and the FAQ
        // it grew is not dropped with it - get_mappingid() at restore
        // time is what lets that be told apart from "no source ticket").
        $kbentries = new backup_nested_element('kbentries');
        $kbentry = new backup_nested_element('kbentry', ['id'], [
            'title', 'question', 'questionformat', 'answer', 'answerformat', 'tickettype',
            'keywords', 'published', 'sourceticketid', 'usercreated', 'usermodified',
            'timecreated', 'timemodified',
        ]);

        $activity->add_child($quotas);
        $quotas->add_child($quota);
        $activity->add_child($qslots);
        $qslots->add_child($qslot);
        $activity->add_child($tpls);
        $tpls->add_child($tpl);
        $activity->add_child($groups);
        $groups->add_child($group);
        $group->add_child($members);
        $members->add_child($member);
        $group->add_child($snapshots);
        $snapshots->add_child($snapshot);
        $group->add_child($penalty);
        $group->add_child($eois);
        $eois->add_child($eoi);
        $activity->add_child($overrides);
        $overrides->add_child($override);
        $activity->add_child($volunteers);
        $volunteers->add_child($volunteer);
        // No digestqueue subtree since 1.20.4: the pending-notification
        // queue is operational state of the running site, not course
        // data (BACKUP-001, doc block above).
        // Tickets sit under the activity, after the groups subtree, so
        // a restore already holds the ssagroup mapping their groupid
        // needs.
        $activity->add_child($joinrequests);
        $joinrequests->add_child($joinrequest);
        $activity->add_child($tickets);
        $tickets->add_child($ticket);
        // Under its own ticket, not under the activity: a trail row
        // means nothing without the ticket it narrates.
        $ticket->add_child($ticketlogs);
        $ticketlogs->add_child($ticketlog);
        // After the tickets subtree, so a restore already holds the
        // ssaticket mapping a published entry's sourceticketid needs.
        $activity->add_child($kbentries);
        $kbentries->add_child($kbentry);
        // After the groups subtree, like tickets, so a restore already
        // holds the mapping their groupid needs.
        $activity->add_child($contacts);
        $contacts->add_child($contact);

        $activity->set_source_table('selfselectadvanced', ['id' => backup::VAR_ACTIVITYID]);
        $quota->set_source_table('selfselectadvanced_quota', ['activityid' => backup::VAR_PARENTID]);
        $tpl->set_source_table('selfselectadvanced_template', ['activityid' => backup::VAR_PARENTID]);
        $qslot->set_source_table('selfselectadvanced_qslot', ['activityid' => backup::VAR_PARENTID]);
        // The knowledgebank (1.20.45) is reusable course content, not user
        // data (the same reasoning the privacy provider's context purge
        // already applies to it) - sourced unconditionally, alongside
        // quotas/templates/qslots, or a Duplicate/rollover backup
        // (userinfo off) silently loses every FAQ (audit B8/M-12).
        $kbentry->set_source_table('selfselectadvanced_kb', ['activityid' => backup::VAR_PARENTID]);
        if ($userinfo) {
            $group->set_source_table('selfselectadvanced_group', ['activityid' => backup::VAR_PARENTID]);
            $member->set_source_table('selfselectadvanced_member', ['groupid' => backup::VAR_PARENTID]);
            $snapshot->set_source_table('selfselectadvanced_snapshot', ['groupid' => backup::VAR_PARENTID]);
            $penalty->set_source_table('selfselectadvanced_penalty', ['groupid' => backup::VAR_PARENTID]);
            $eoi->set_source_table('selfselectadvanced_eoi', ['groupid' => backup::VAR_PARENTID]);
            // Move-scope override rows are skipped with their moves (M2).
            $override->set_source_sql(
                "SELECT * FROM {selfselectadvanced_override}
                  WHERE activityid = ? AND scope IN ('user', 'group', 'guide')",
                [backup::VAR_PARENTID]
            );
            $volunteer->set_source_table('selfselectadvanced_volunteer', ['activityid' => backup::VAR_PARENTID]);
            $joinrequest->set_source_sql(
                "SELECT * FROM {selfselectadvanced_move}
                  WHERE activityid = ? AND status = 'requested'",
                [backup::VAR_PARENTID]
            );
            $ticket->set_source_table('selfselectadvanced_ticket', ['activityid' => backup::VAR_PARENTID]);
            $ticketlog->set_source_table('selfselectadvanced_ticketlog', ['ticketid' => backup::VAR_PARENTID]);
            // Kbentry's own source is set unconditionally above (audit B8).
            $contact->set_source_table('selfselectadvanced_contact', ['activityid' => backup::VAR_PARENTID]);
        }

        $member->annotate_ids('user', 'userid');
        $member->annotate_ids('user', 'invitedby');
        $group->annotate_ids('user', 'leaderid');
        $group->annotate_ids('user', 'guideid');
        $group->annotate_ids('user', 'successorid');
        $group->annotate_ids('user', 'guidesuccessorid');
        $group->annotate_ids('group', 'coregroupid');
        $snapshot->annotate_ids('group', 'coregroupid');
        $snapshot->annotate_ids('user', 'takenby');
        $override->annotate_ids('user', 'userid');
        $volunteer->annotate_ids('user', 'userid');
        $eoi->annotate_ids('user', 'guideid');
        $joinrequest->annotate_ids('user', 'userid');
        $joinrequest->annotate_ids('user', 'usermodified');
        $ticket->annotate_ids('user', 'requestedby');
        $ticket->annotate_ids('user', 'claimedby');
        $ticket->annotate_ids('user', 'resolvedby');
        $ticketlog->annotate_ids('user', 'actorid');
        $kbentry->annotate_ids('user', 'usercreated');
        $kbentry->annotate_ids('user', 'usermodified');
        $contact->annotate_ids('user', 'guideid');
        $contact->annotate_ids('user', 'sentby');

        // Proposal documents travel with their group (itemid = group id).
        $group->annotate_files('mod_selfselectadvanced', 'proposal', 'id');
        // 1.20.44 part 2: a ticket's opening request may carry
        // attachments (itemid = ticket id), and so may a needs-info
        // question, an info-reply or a resolution note (itemid = the
        // ticketlog row itself) - never a referral or an escalation
        // note, which offer no filemanager to begin with.
        $ticket->annotate_files('mod_selfselectadvanced', 'ticketrequest', 'id');
        $ticketlog->annotate_files('mod_selfselectadvanced', 'ticketpost', 'id');
        // Files embedded in the activity description. Restore already
        // asks for these; without this annotation they never enter the
        // backup file pool, so a duplicated or imported activity keeps
        // the description text and loses every image in it, leaving
        // dead @@PLUGINFILE@@ tokens behind.
        $activity->annotate_files('mod_selfselectadvanced', 'intro', null);

        return $this->prepare_activity_structure($activity);
    }
}
