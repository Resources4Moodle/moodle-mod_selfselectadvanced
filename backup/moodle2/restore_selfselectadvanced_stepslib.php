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
 * Restore structure for mod_selfselectadvanced (spec 14.11): user ids
 * remapped throughout, coregroupid remapped to the restored core
 * group, snapshot rosters remapped in code, guide expressions of
 * interest (1.11.0) keyed to their restored group with guideid
 * remapped.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * The single restore structure step.
 */
class restore_selfselectadvanced_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the paths to process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $paths = [
            new restore_path_element('selfselectadvanced', '/activity/selfselectadvanced'),
            new restore_path_element('ssaquota', '/activity/selfselectadvanced/quotas/quota'),
            new restore_path_element('ssatemplate', '/activity/selfselectadvanced/templates/template'),
            new restore_path_element('ssaqslot', '/activity/selfselectadvanced/qslots/qslot'),
            // The knowledgebank (1.20.45) is reusable course content, not
            // user data - registered here, unconditionally, alongside
            // quotas/templates/qslots, the same way its backup source is
            // set outside `if ($userinfo)` below, or a Duplicate/rollover
            // backup (userinfo off) silently loses every FAQ (audit
            // B8/M-12).
            new restore_path_element(
                'ssakbentry',
                '/activity/selfselectadvanced/kbentries/kbentry'
            ),
        ];
        if ($userinfo) {
            $paths[] = new restore_path_element('ssagroup', '/activity/selfselectadvanced/groups/group');
            $paths[] = new restore_path_element(
                'ssamember',
                '/activity/selfselectadvanced/groups/group/members/member'
            );
            $paths[] = new restore_path_element(
                'ssasnapshot',
                '/activity/selfselectadvanced/groups/group/snapshots/snapshot'
            );
            $paths[] = new restore_path_element(
                'ssapenalty',
                '/activity/selfselectadvanced/groups/group/penalty'
            );
            $paths[] = new restore_path_element(
                'ssaeoi',
                '/activity/selfselectadvanced/groups/group/eois/eoi'
            );
            $paths[] = new restore_path_element(
                'ssaoverride',
                '/activity/selfselectadvanced/overrides/override'
            );
            $paths[] = new restore_path_element(
                'ssavolunteer',
                '/activity/selfselectadvanced/volunteers/volunteer'
            );
            // Registered although 1.20.4 backups no longer write it:
            // archives made by 1.8.0-1.20.3 carry a digestqueue subtree,
            // and the path element is what routes those rows to the
            // processor that explicitly drops them (BACKUP-001).
            $paths[] = new restore_path_element(
                'ssadigestitem',
                '/activity/selfselectadvanced/digestqueue/digestitem'
            );
            $paths[] = new restore_path_element(
                'ssaticket',
                '/activity/selfselectadvanced/tickets/ticket'
            );
            // Nested under its own ticket, so process_ssaticket() has
            // already set the ssaticket mapping this row's ticketid
            // needs by the time this path is reached.
            $paths[] = new restore_path_element(
                'ssaticketlog',
                '/activity/selfselectadvanced/tickets/ticket/ticketlogs/ticketlog'
            );
            // Ssakbentry is registered unconditionally above (audit
            // B8/M-12): an entry authored directly (sourceticketid 0) is
            // not "about" any ticket, and one published FROM a ticket
            // still stands as its own row once restored -
            // process_ssakbentry() maps sourceticketid with
            // get_mappingid() against the OLD id, the same "was THIS
            // ticket ever mapped at all" question process_ssaticketlog()
            // asks of ITS ticketid, degrading to 0 when there is no
            // mapping (no source ticket, or no userinfo so ssaticket
            // carries no mapping at all).
            $paths[] = new restore_path_element(
                'ssacontact',
                '/activity/selfselectadvanced/contacts/contact'
            );
            $paths[] = new restore_path_element(
                'ssajoinrequest',
                '/activity/selfselectadvanced/joinrequests/joinrequest'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the instance row.
     *
     * @param array $data the row
     */
    protected function process_selfselectadvanced($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        // The schedule shifts with the restore (seam audit, 1.20.19):
        // a term-rollover restore carried last term's window verbatim,
        // so the whole new cohort landed after timecutoff and the
        // window gate refused everything. Same idiom as core's
        // mod_choice/mod_assign; apply_date_offset() is the identity
        // when no date shift was requested, and 0 ("not set") maps to 0.
        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timedue = $this->apply_date_offset($data->timedue);
        $data->timecutoff = $this->apply_date_offset($data->timecutoff);
        $newid = $DB->insert_record('selfselectadvanced', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restore a quota rule.
     *
     * @param array $data the row
     */
    protected function process_ssaquota($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $DB->insert_record('selfselectadvanced_quota', $data);
    }

    /**
     * Restore a notification template override.
     *
     * @param array $data the row
     */
    protected function process_ssatemplate($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $newid = $DB->insert_record('selfselectadvanced_template', $data);
        $this->set_mapping('ssatemplate', $oldid, $newid);
    }

    /**
     * Restore a composition-template slot.
     *
     * @param array $data the row
     */
    protected function process_ssaqslot($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $DB->insert_record('selfselectadvanced_qslot', $data);
    }

    /**
     * Restore a group with remapped people; the plugin uid keeps its
     * original value (unique plugin-wide by construction).
     *
     * @param array $data the row
     */
    protected function process_ssagroup($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        // NULL, NEVER 0 (external audit BAK-002, 2026-08-13). 1.20.35 made a
        // leadership vacancy an explicit NULL and spent a whole release
        // removing the id-zero sentinel that names user zero; this line put it
        // straight back on every restore, for both a backed-up vacancy and a
        // leader who could not be mapped. No replacement leader is invented
        // either: staff appoint one with Assign leader, exactly as they would
        // after a deletion.
        $mappedleader = $data->leaderid ? $this->get_mappingid('user', $data->leaderid) : 0;
        $data->leaderid = $mappedleader ?: null;
        $data->guideid = $data->guideid ? ($this->get_mappingid('user', $data->guideid) ?: null) : null;
        $data->successorid = $data->successorid
            ? ($this->get_mappingid('user', $data->successorid) ?: null)
            : null;
        $data->guidesuccessorid = !empty($data->guidesuccessorid)
            ? ($this->get_mappingid('user', $data->guidesuccessorid) ?: null)
            : null;
        $data->coregroupid = $data->coregroupid
            ? ($this->get_mappingid('group', $data->coregroupid) ?: null)
            : null;
        $data->usermodified = 0;
        // The plugin uid is unique plugin-wide: a same-site restore
        // would collide with the original, so regenerate on collision
        // from the new row id (decision D3: the uid's uniqueness rides
        // on the database id).
        if ($DB->record_exists('selfselectadvanced_group', ['pluginuid' => $data->pluginuid])) {
            $data->pluginuid = '';
        }
        $newid = $DB->insert_record('selfselectadvanced_group', $data);
        if ($data->pluginuid === '') {
            // NOT \mod_selfselectadvanced\activity::from_instance() +
            // groups::build_pluginuid(): that path resolves the course
            // MODULE via get_course_and_cm_from_instance(), which depends
            // on the course's modinfo cache - a real dependency this
            // restore step cannot rely on being warm for a course_module
            // its own restore may only just have created. Discovered live
            // by ticket_ladder_test.php's
            // test_a_colliding_reference_is_regenerated_not_left_blank_on_restore
            // (a same-course restore, TARGET_CURRENT_ADDING) throwing
            // "Invalid module ID" here - the identical latent defect
            // process_ssaticket() below is written around from the start,
            // for the same reason. Built from raw table reads instead,
            // replicating groups::build_pluginuid()'s own algorithm
            // exactly (its own constants reused; its method, and
            // uid_template(), not called - both need an activity object).
            $groupactivityrow = $DB->get_record(
                'selfselectadvanced',
                ['id' => (int) $data->activityid],
                'course, uidprefix, uiddigits, uidformat'
            );
            $groupcourserow = $groupactivityrow
                ? $DB->get_record('course', ['id' => (int) $groupactivityrow->course], 'id, shortname, fullname')
                : false;
            $groupprefix = preg_replace(
                '/[^A-Z0-9]/',
                '',
                strtoupper($groupactivityrow ? (string) $groupactivityrow->uidprefix : '')
            );
            if ($groupprefix === '') {
                $groupprefix = 'SSA';
            }
            $groupshort = $groupcourserow ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $groupcourserow->shortname)) : '';
            if ($groupshort === '' && $groupcourserow) {
                $groupshort = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $groupcourserow->fullname));
            }
            if ($groupshort === '') {
                $groupshort = 'C' . ($groupcourserow ? (int) $groupcourserow->id : (int) ($groupactivityrow->course ?? 0));
            }
            $groupshort = substr($groupshort, 0, 12);
            $groupdigits = $groupactivityrow
                ? (int) $groupactivityrow->uiddigits
                : \mod_selfselectadvanced\local\groups::UID_DIGITS_DEFAULT;
            if (
                $groupdigits < \mod_selfselectadvanced\local\groups::UID_DIGITS_MIN
                || $groupdigits > \mod_selfselectadvanced\local\groups::UID_DIGITS_MAX
            ) {
                $groupdigits = \mod_selfselectadvanced\local\groups::UID_DIGITS_DEFAULT;
            }
            $grouptemplate = $groupactivityrow ? trim((string) $groupactivityrow->uidformat) : '';
            if ($grouptemplate === '' || strpos($grouptemplate, '{number}') === false) {
                $grouptemplate = \mod_selfselectadvanced\local\groups::UID_TEMPLATE_DEFAULT;
            }
            $DB->set_field(
                'selfselectadvanced_group',
                'pluginuid',
                strtr($grouptemplate, [
                    '{prefix}' => substr($groupprefix, 0, 8),
                    '{course}' => $groupshort,
                    '{number}' => sprintf('%0' . $groupdigits . 'd', $newid),
                ]),
                ['id' => $newid]
            );
        }
        // The third argument is restorefiles=true, and it is not
        // optional here: proposal attachments are stored with the
        // plugin group id as their itemid, and core only links a
        // backed-up file back to its new item when the mapping that
        // created that item was recorded as owning files. Without it
        // add_related_files('proposal', 'ssagroup') below matches
        // nothing and every attachment is dropped - silently, because
        // a restore that finds no files to move reports success.
        $this->set_mapping('ssagroup', $oldid, $newid, true);
    }

    /**
     * Restore a member row.
     *
     * @param array $data the row
     */
    protected function process_ssamember($data) {
        global $DB;

        $data = (object) $data;
        $data->groupid = $this->get_new_parentid('ssagroup');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->invitedby = $data->invitedby ? ($this->get_mappingid('user', $data->invitedby) ?: null) : null;
        if ($data->userid) {
            $DB->insert_record('selfselectadvanced_member', $data);
        }
    }

    /**
     * Restore a snapshot with a remapped roster.
     *
     * @param array $data the row
     */
    protected function process_ssasnapshot($data) {
        global $DB;

        $data = (object) $data;
        $data->groupid = $this->get_new_parentid('ssagroup');
        // A SNAPSHOT WITH UNMAPPABLE PROVENANCE IS NOT RESTORED (external audit
        // BAK-003, 2026-08-13). Both columns are NOT NULL and takenby names a
        // real user, so the old `?: 0` did not record "unknown" - it
        // manufactured an identifier, and a user-excluded or cross-site
        // restore ended up with snapshots claiming to have been taken by user
        // zero of a course group that does not exist.
        //
        // Skipping is chosen over widening the schema because a snapshot is
        // EVIDENCE: it records who froze which roster and when, and evidence
        // whose subject cannot be identified is not evidence. A restore that
        // silently drops it is honest; one that invents a taker is not. The
        // count is reported in the restore log rather than passing in silence.
        $data->coregroupid = $data->coregroupid ? (int) $this->get_mappingid('group', $data->coregroupid) : 0;
        $data->takenby = $data->takenby ? (int) $this->get_mappingid('user', $data->takenby) : 0;
        if (!$data->coregroupid || !$data->takenby) {
            $this->log(
                'selfselectadvanced: skipped a roster snapshot whose core group or taker could not be '
                    . 'mapped into this site; its provenance cannot be restored truthfully',
                backup::LOG_WARNING
            );

            return;
        }
        $roster = json_decode($data->roster, true) ?: [];
        $remapped = [];
        foreach ($roster as $entry) {
            $newuser = $this->get_mappingid('user', (int) $entry['userid']);
            if ($newuser) {
                $remapped[] = ['userid' => (int) $newuser, 'isleader' => (int) ($entry['isleader'] ?? 0)];
            }
        }
        $data->roster = json_encode($remapped);
        $DB->insert_record('selfselectadvanced_snapshot', $data);
    }

    /**
     * Restore a penalty row.
     *
     * @param array $data the row
     */
    protected function process_ssapenalty($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->groupid = $this->get_new_parentid('ssagroup');
        $newid = $DB->insert_record('selfselectadvanced_penalty', $data);
        $this->set_mapping('ssapenalty', $oldid, $newid);
    }

    /**
     * Restore an expression of interest, keyed to its restored group.
     * A guide that could not be mapped (removed from the site since the
     * backup) drops the row rather than insert a not-null guideid as 0,
     * exactly like a member row with no mappable user.
     *
     * @param array $data the row
     */
    protected function process_ssaeoi($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->groupid = $this->get_new_parentid('ssagroup');
        $data->guideid = $this->get_mappingid('user', $data->guideid);
        if (!$data->guideid) {
            return;
        }
        $newid = $DB->insert_record('selfselectadvanced_eoi', $data);
        $this->set_mapping('ssaeoi', $oldid, $newid);
    }

    /**
     * Restore a user/group/guide-scope override.
     *
     * @param array $data the row
     */
    protected function process_ssaoverride($data) {
        global $DB;

        $data = (object) $data;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->moveid = null;
        $data->userid = $data->userid ? $this->get_mappingid('user', $data->userid) : null;
        $data->groupid = $data->groupid ? ($this->get_mappingid('ssagroup', $data->groupid) ?: null) : null;
        if (in_array($data->scope, ['user', 'guide'], true) && !$data->userid) {
            return;
        }
        if ($data->scope === 'group' && !$data->groupid) {
            return;
        }
        // Per-target schedule overrides shift with the restore exactly
        // as the instance dates do (nulls pass through untouched).
        $data->timeopen = $this->apply_date_offset($data->timeopen ?? null);
        $data->timedue = $this->apply_date_offset($data->timedue ?? null);
        $data->timecutoff = $this->apply_date_offset($data->timecutoff ?? null);
        $data->usermodified = 0;
        $DB->insert_record('selfselectadvanced_override', $data);
    }

    /**
     * Restore a guide's volunteered capacity row.
     *
     * @param array $data the row
     */
    protected function process_ssavolunteer($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $newid = $DB->insert_record('selfselectadvanced_volunteer', $data);
        $this->set_mapping('selfselectadvanced_volunteer', $oldid, $newid);
    }

    /**
     * A queued digest notification from a pre-1.20.4 archive: dropped,
     * deliberately and in the open (BACKUP-001). The queue is
     * operational state of the site that wrote the backup, not course
     * data. Restoring a row used to plant a notification whose deep
     * link pointed at the ORIGINAL activity - or, after core's link
     * encoder had been at the archive, at a literal
     * $@SELFSELECTADVANCEDVIEWBYID*n@$ token this plugin never
     * registered a decoder for - and whose payload text named people
     * by their backed-up names. A duplicate on the same site also
     * doubled every recipient's pending digest. Nothing a restored
     * course needs lives in this table: the running site's own events
     * queue fresh rows the moment something digest-worthy happens.
     *
     * @param array $data the row
     */
    protected function process_ssadigestitem($data) {
        // Intentionally empty: the row is consumed and discarded.
    }

    /**
     * Restore a join request that was still waiting for an answer.
     *
     * The asker and the target team must both survive the restore: a
     * request pointing at a team that is not here cannot be answered,
     * and one from a user who is not here has nobody to answer for.
     *
     * THE SOURCE IS MAPPED, NOT REQUIRED, and since decision 77 a request
     * created on this site never has one at all - a join adds a membership
     * rather than trading one. A backup taken from a site running an older
     * release can still carry a waiting request that names a team the student
     * offered to leave, so the mapping stays: the row is restored faithfully,
     * as the record of what was asked, and the accept path ignores the source
     * exactly as it does for a row that was already here.
     *
     * @param array $data the row
     */
    protected function process_ssajoinrequest($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->targetgroupid = $this->get_mappingid('ssagroup', $data->targetgroupid);
        if (!$data->userid || !$data->targetgroupid) {
            return;
        }
        $data->sourcegroupid = $data->sourcegroupid
            ? ($this->get_mappingid('ssagroup', $data->sourcegroupid) ?: null)
            : null;
        $data->usermodified = $data->usermodified
            ? ($this->get_mappingid('user', $data->usermodified) ?: $data->userid)
            : $data->userid;
        // Fields the move engine expects on every row of this table.
        $data->makeleader = 0;
        $data->replaceleader = 0;
        $data->successorid = null;
        $data->statusinfo = null;
        $data->timecommitted = null;
        $newid = $DB->insert_record('selfselectadvanced_move', $data);
        $this->set_mapping('ssajoinrequest', $oldid, $newid);
    }

    /**
     * Restore a queue ticket. A team ticket needs a mapped group and every
     * ticket needs a mapped requester; a ticket missing either required
     * relation is dropped, like a member row with no mappable user. The
     * claimant and resolver are nullable and degrade to null individually.
     *
     * @param array $data the row
     */
    protected function process_ssaticket($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        // A team-limit ticket is about the guide, not a team, and carries
        // NULL. Older backups may still carry the former 0 sentinel, so only
        // a ticket that CLAIMS a real team needs one mapped. Requiring it
        // dropped every team-limit request on restore (audit HIGH-BACKUP-003).
        $isaboutteam = $data->groupid !== null && (int) $data->groupid > 0;
        $data->groupid = $isaboutteam ? (int) $this->get_mappingid('ssagroup', $data->groupid) : null;
        $data->requestedby = $this->get_mappingid('user', $data->requestedby);
        if (!$data->requestedby || ($isaboutteam && !$data->groupid)) {
            return;
        }
        $data->claimedby = $data->claimedby ? ($this->get_mappingid('user', $data->claimedby) ?: null) : null;
        $data->resolvedby = $data->resolvedby ? ($this->get_mappingid('user', $data->resolvedby) ?: null) : null;
        // 1.20.56: the ticket's own reference is unique plugin-wide, same
        // as the group's - a same-site restore would collide with the
        // original, so regenerate on collision from the new row id
        // (the same rule process_ssagroup() applies to pluginuid above).
        // A backup taken before 1.20.56 carries no pluginuid element at
        // all, which reaches here as PHP null - treated the same as an
        // empty string, since either way nothing usable travelled.
        $data->pluginuid = (string) ($data->pluginuid ?? '');
        if ($data->pluginuid === '' || $DB->record_exists('selfselectadvanced_ticket', ['pluginuid' => $data->pluginuid])) {
            $data->pluginuid = '';
        }
        // A claimed ticket whose claimant did not survive the restore
        // would be stuck (nobody could resolve or release it): release
        // it back to the queue instead. NEEDSINFO is the other live
        // claimed state (decision 2's LIVENESS) and has the identical
        // failure mode - a restored needs-info ticket with no claimant
        // has no exit, since the requester's reply would go to nobody
        // (audit B7/M-11).
        if (
            in_array(
                $data->status,
                [
                    \mod_selfselectadvanced\local\tickets::STATUS_CLAIMED,
                    \mod_selfselectadvanced\local\tickets::STATUS_NEEDSINFO,
                ],
                true
            )
            && $data->claimedby === null
        ) {
            $data->status = \mod_selfselectadvanced\local\tickets::STATUS_OPEN;
            $data->timeclaimed = null;
        }
        $newid = $DB->insert_record('selfselectadvanced_ticket', $data);
        if ($data->pluginuid === '') {
            // NOT \mod_selfselectadvanced\activity::from_instance() +
            // tickets::build_pluginuid(), unlike process_ssagroup()'s
            // identical-in-spirit regeneration above: that path resolves
            // the course MODULE via get_course_and_cm_from_instance(),
            // which depends on the course's modinfo cache - live-tested
            // here (test_a_colliding_reference_is_regenerated_not_left_blank_on_restore,
            // a same-course restore, TARGET_CURRENT_ADDING) to throw
            // "Invalid module ID" because the second copy's course_module
            // row is not yet visible to a modinfo cache built before this
            // restore step ran. Built from raw table reads and
            // \mod_selfselectadvanced\local\ticketrefshape::build()
            // instead - the same pure-string shape db/upgrade.php's own
            // backfill uses, for the same reason: neither can depend on a
            // course module being resolvable.
            $activityrow = $DB->get_record('selfselectadvanced', ['id' => (int) $data->activityid], 'course, uidprefix');
            $courserow = $activityrow
                ? $DB->get_record('course', ['id' => (int) $activityrow->course], 'id, shortname, fullname')
                : false;
            $DB->set_field(
                'selfselectadvanced_ticket',
                'pluginuid',
                \mod_selfselectadvanced\local\ticketrefshape::build(
                    $activityrow ? (string) $activityrow->uidprefix : '',
                    $courserow ? (string) $courserow->shortname : '',
                    $courserow ? (string) $courserow->fullname : '',
                    $courserow ? (int) $courserow->id : (int) $data->activityid,
                    (int) $newid
                ),
                ['id' => $newid]
            );
        }
        // RESTOREFILES=TRUE (1.20.44 part 2), not optional here: the
        // opening request's attachments are stored with the ticket id
        // as their itemid, and core only links a backed-up file back to
        // its new item when the mapping that created that item was
        // recorded as owning files - the same discipline process_ssagroup()
        // documents above for 'ssagroup'/proposal.
        $this->set_mapping('ssaticket', $oldid, $newid, true);
    }

    /**
     * Restore one row of a ticket's history trail (decision 1,
     * 2026-08-15). Both relations are REQUIRED - ticketid and actorid
     * are NOT NULL, actorid names a real person and ticketid is
     * meaningless pointing at nothing - so a row that cannot map either
     * one is dropped outright, the same unmappable-provenance policy
     * process_ssasnapshot() applies to takenby (external audit
     * BAK-003): both those columns are NOT NULL too, and there is no
     * null or zero either could degrade to without manufacturing an
     * identifier nobody holds. This differs from process_ssaticket()'s
     * OWN claimedby/resolvedby, which degrade to null individually
     * because those two columns actually allow it.
     *
     * ticketid is mapped with get_mappingid() against the OLD id the
     * archive carries, NOT get_new_parentid('ssaticket') - a ticket can
     * be dropped by process_ssaticket() above, and this element is
     * still visited when that happens (the XML parser walks every
     * nested path regardless of what the ancestor's own processor did
     * with its row), so get_new_parentid() would return whichever OTHER
     * ticket most recently succeeded rather than "none". get_mappingid()
     * answers the right question: was THIS ticket, by its own old id,
     * ever mapped at all.
     *
     * @param array $data the row
     */
    protected function process_ssaticketlog($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->ticketid = $this->get_mappingid('ssaticket', $data->ticketid);
        $data->actorid = $this->get_mappingid('user', $data->actorid);
        if (!$data->ticketid || !$data->actorid) {
            return;
        }
        $newid = $DB->insert_record('selfselectadvanced_ticketlog', $data);
        // 1.20.44 part 2: a needs-info question, an info-reply or a
        // resolution note may carry attachments keyed on THIS row's own
        // id (referred/escalated rows never do - no filemanager was
        // ever offered on those two forms) - restorefiles=true so
        // add_related_files('ticketpost', 'ssaticketlog') in
        // after_execute() below can find them.
        $this->set_mapping('ssaticketlog', $oldid, $newid, true);
    }

    /**
     * Restore a knowledgebank entry (1.20.45). The content itself
     * (title/question/answer) is reusable course data and is never
     * dropped for an unmappable relation - unlike process_ssaticket()'s
     * requestedby, this row names no one who MUST exist for it to mean
     * anything. usercreated and usermodified degrade to 0 individually
     * on an unmappable user, the same "de-link, do not destroy" policy
     * privacy erasure already applies to these two exact columns
     * (classes/privacy/provider.php's own annotated-columns idiom).
     * sourceticketid degrades to 0 ("authored directly") when the
     * source ticket was dropped or is not being restored (no userinfo,
     * so ssaticket carries no mapping at all) - the article stands on
     * its own either way.
     *
     * @param array $data the row
     */
    protected function process_ssakbentry($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->usercreated = $this->get_mappingid('user', $data->usercreated) ?: 0;
        $data->usermodified = $this->get_mappingid('user', $data->usermodified) ?: 0;
        $data->sourceticketid = !empty($data->sourceticketid)
            ? ($this->get_mappingid('ssaticket', $data->sourceticketid) ?: 0)
            : 0;
        $newid = $DB->insert_record('selfselectadvanced_kb', $data);
        // Needed for restore_decode_content('selfselectadvanced_kb', [...],
        // 'ssakbentry') to find this row at all (audit B10/M-15) - a rule
        // whose item name was never mapped decodes nothing and fails
        // silently (the file's own warning, restore_selfselectadvanced_
        // activity_task.class.php).
        $this->set_mapping('ssakbentry', $oldid, $newid);
    }

    /**
     * Restore a team's approach to a guide. Both the team and the two
     * people are NOT NULL, so an approach that cannot be mapped is
     * dropped rather than restored pointing at nothing.
     *
     * @param array $data the row
     */
    protected function process_ssacontact($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->activityid = $this->get_new_parentid('selfselectadvanced');
        $data->groupid = $this->get_mappingid('ssagroup', $data->groupid);
        $data->guideid = $this->get_mappingid('user', $data->guideid);
        $data->sentby = $this->get_mappingid('user', $data->sentby);
        if (!$data->groupid || !$data->guideid || !$data->sentby) {
            return;
        }
        $newid = $DB->insert_record('selfselectadvanced_contact', $data);
        $this->set_mapping('ssacontact', $oldid, $newid);
    }

    /**
     * Bring the group-level file areas back after the id mappings exist.
     */
    protected function after_execute() {
        $this->add_related_files('mod_selfselectadvanced', 'proposal', 'ssagroup');
        $this->add_related_files('mod_selfselectadvanced', 'intro', null);
        // 1.20.44 part 2.
        $this->add_related_files('mod_selfselectadvanced', 'ticketrequest', 'ssaticket');
        $this->add_related_files('mod_selfselectadvanced', 'ticketpost', 'ssaticketlog');
    }
}
