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

namespace mod_selfselectadvanced\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider (spec 14.10): full export and delete of groups
 * led/joined, briefs, invitations, nominations, guide decisions,
 * participant attributes (system-wide), penalties, overrides, staged
 * moves, volunteered guiding capacity (1.7.0), reminder preferences,
 * the queued digest notifications and digest preference (1.8.0), and a
 * guide's expressions of interest in listed teams (1.11.0).
 *
 * Deletion keeps group structure (course data) but removes the user's
 * member rows and de-links ids - including every modifier/grantor
 * column (group, override, move and attribute usermodified, agrun
 * triggeredby); a deletion that blanks a leader routes the group to
 * the flagged report via its empty leaderid (review item M1).
 * Snapshots are scrubbed of the user; agrun logs are pseudonymised
 * type-aware, by the log's own shape. Queued digest rows are deleted
 * outright - the recipient's own, and any other recipient's row whose
 * resolved payload names the erased person: they hold no audit value
 * once either party is gone.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describe every store holding personal data.
     *
     * @param collection $collection the metadata collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('selfselectadvanced_member', [
            'userid' => 'privacy:metadata:member:userid',
            'status' => 'privacy:metadata:member:status',
            'isleader' => 'privacy:metadata:member:isleader',
            'invitedby' => 'privacy:metadata:member:invitedby',
            'timeinvited' => 'privacy:metadata:member:timeinvited',
            'timeresponded' => 'privacy:metadata:member:timeresponded',
            'leaverequested' => 'privacy:metadata:member:leaverequested',
        ], 'privacy:metadata:member');
        $collection->add_database_table('selfselectadvanced_group', [
            'leaderid' => 'privacy:metadata:group:leaderid',
            'guideid' => 'privacy:metadata:group:guideid',
            'successorid' => 'privacy:metadata:group:successorid',
            'guidesuccessorid' => 'privacy:metadata:group:guidesuccessorid',
            'brief' => 'privacy:metadata:group:brief',
            'returncomment' => 'privacy:metadata:group:returncomment',
            'guidenotes' => 'privacy:metadata:group:guidenotes',
            'disbandreason' => 'privacy:metadata:group:disbandreason',
            'usermodified' => 'privacy:metadata:group:usermodified',
        ], 'privacy:metadata:group');
        $collection->add_database_table('selfselectadvanced_userattr', [
            'userid' => 'privacy:metadata:userattr:userid',
            'gender' => 'privacy:metadata:userattr:gender',
            'department' => 'privacy:metadata:userattr:department',
            'subdepartment' => 'privacy:metadata:userattr:subdepartment',
            'mobile' => 'privacy:metadata:userattr:mobile',
            'shareconsent' => 'privacy:metadata:userattr:shareconsent',
            'seatlocation' => 'privacy:metadata:userattr:seatlocation',
            'program' => 'privacy:metadata:userattr:program',
            'usermodified' => 'privacy:metadata:userattr:usermodified',
        ], 'privacy:metadata:userattr');
        $collection->add_database_table('selfselectadvanced_override', [
            'userid' => 'privacy:metadata:override:userid',
            'usermodified' => 'privacy:metadata:override:usermodified',
        ], 'privacy:metadata:override');
        $collection->add_database_table('selfselectadvanced_volunteer', [
            'userid' => 'privacy:metadata:volunteer:userid',
            'capacity' => 'privacy:metadata:volunteer:capacity',
        ], 'privacy:metadata:volunteer');
        $collection->add_database_table('selfselectadvanced_move', [
            'userid' => 'privacy:metadata:move:userid',
            'successorid' => 'privacy:metadata:move:successorid',
            'reason' => 'privacy:metadata:move:reason',
            'responsenote' => 'privacy:metadata:move:responsenote',
            'usermodified' => 'privacy:metadata:move:usermodified',
        ], 'privacy:metadata:move');
        $collection->add_database_table('selfselectadvanced_snapshot', [
            'roster' => 'privacy:metadata:snapshot:roster',
            'takenby' => 'privacy:metadata:snapshot:takenby',
        ], 'privacy:metadata:snapshot');
        $collection->add_database_table('selfselectadvanced_agrun', [
            'log' => 'privacy:metadata:agrun:log',
            'triggeredby' => 'privacy:metadata:agrun:triggeredby',
        ], 'privacy:metadata:agrun');
        $collection->add_database_table('selfselectadvanced_digestq', [
            'userid' => 'privacy:metadata:digestq:userid',
            'payload' => 'privacy:metadata:digestq:payload',
        ], 'privacy:metadata:digestq');
        // A SEPARATE DECLARATION, not a reuse of digestq:userid. That key
        // means "the recipient"; this one means "somebody the message is
        // about", and telling a data subject the wrong one of those is
        // exactly the confusion this table was created to end.
        $collection->add_database_table('selfselectadvanced_dqsubject', [
            'userid' => 'privacy:metadata:dqsubject:userid',
        ], 'privacy:metadata:dqsubject');
        $collection->add_database_table('selfselectadvanced_eoi', [
            'guideid' => 'privacy:metadata:eoi:guideid',
            'remarks' => 'privacy:metadata:eoi:remarks',
            'status' => 'privacy:metadata:eoi:status',
        ], 'privacy:metadata:eoi');
        $collection->add_database_table('selfselectadvanced_ticket', [
            'requestedby' => 'privacy:metadata:ticket:requestedby',
            'claimedby' => 'privacy:metadata:ticket:claimedby',
            'resolvedby' => 'privacy:metadata:ticket:resolvedby',
            'request' => 'privacy:metadata:ticket:request',
            'resolution' => 'privacy:metadata:ticket:resolution',
            'type' => 'privacy:metadata:ticket:type',
            'status' => 'privacy:metadata:ticket:status',
            'requested' => 'privacy:metadata:ticket:requested',
            // 1.20.43: per-ticket, set by the service at file() time, so
            // it is the requester's own data exactly like every other
            // column on this row.
            'disclaimerack' => 'privacy:metadata:ticket:disclaimerack',
        ], 'privacy:metadata:ticket');
        // The history trail (decision 1, 2026-08-15): one row per action
        // taken on a ticket, so the same personal data the ticket row
        // itself already declares - who acted, and any prose they wrote -
        // is declared again here rather than assumed to be covered by the
        // table above.
        $collection->add_database_table('selfselectadvanced_ticketlog', [
            'ticketid' => 'privacy:metadata:ticketlog:ticketid',
            'actorid' => 'privacy:metadata:ticketlog:actorid',
            'action' => 'privacy:metadata:ticketlog:action',
            'note' => 'privacy:metadata:ticketlog:note',
            'timecreated' => 'privacy:metadata:ticketlog:timecreated',
        ], 'privacy:metadata:ticketlog');
        // The knowledgebank (1.20.45). Declared for the two annotated
        // columns only, the same idiom override/group/move already use
        // for THEIR usermodified - the article's own content (title,
        // question, answer) is staff-authored public-facing wording, not
        // the erased person's personal narrative the way a ticket's own
        // request/resolution is.
        $collection->add_database_table('selfselectadvanced_kb', [
            'usercreated' => 'privacy:metadata:kb:usercreated',
            'usermodified' => 'privacy:metadata:kb:usermodified',
        ], 'privacy:metadata:kb');
        $collection->add_database_table('selfselectadvanced_penalty', [
            'groupid' => 'privacy:metadata:penalty:groupid',
            'dayslate' => 'privacy:metadata:penalty:dayslate',
            'penaltyvalue' => 'privacy:metadata:penalty:penaltyvalue',
            'award' => 'privacy:metadata:penalty:award',
            'waived' => 'privacy:metadata:penalty:waived',
            'waivereason' => 'privacy:metadata:penalty:waivereason',
        ], 'privacy:metadata:penalty');
        $collection->add_database_table('selfselectadvanced_contact', [
            'guideid' => 'privacy:metadata:contact:guideid',
            'sentby' => 'privacy:metadata:contact:sentby',
            'message' => 'privacy:metadata:contact:message',
            'reason' => 'privacy:metadata:contact:reason',
            'status' => 'privacy:metadata:contact:status',
        ], 'privacy:metadata:contact');
        $collection->add_user_preference(
            'mod_selfselectadvanced_reminded_',
            'privacy:metadata:preference:reminded'
        );
        $collection->add_user_preference(
            'mod_selfselectadvanced_digest',
            'privacy:metadata:preference:digest'
        );
        $collection->add_user_preference(
            'mod_selfselectadvanced_gremind_',
            'privacy:metadata:preference:gremind'
        );
        // Declared 2026-08-13 (external audit PRIV-001). perpage::resolve()
        // has stored this since the paging work and nothing here said so: a
        // stored user preference is personal data whether or not it is
        // interesting, and an undeclared one is invisible to a subject access
        // request.
        $collection->add_user_preference(
            'mod_selfselectadvanced_perpage',
            'privacy:metadata:preference:perpage'
        );

        return $collection;
    }

    /**
     * Module contexts where the user appears, plus system for attributes.
     *
     * @param int $userid the user
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modlevel
                  JOIN {modules} md ON md.id = cm.module AND md.name = 'selfselectadvanced'
                  JOIN {selfselectadvanced} a ON a.id = cm.instance
                 WHERE EXISTS (
                        SELECT 1 FROM {selfselectadvanced_member} m
                          JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                         WHERE g.activityid = a.id AND m.userid = :userid1)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_group} g2
                         WHERE g2.activityid = a.id
                           AND (g2.leaderid = :userid2 OR g2.guideid = :userid3 OR g2.successorid = :userid4
                                OR g2.guidesuccessorid = :userid11))
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_override} o
                         WHERE o.activityid = a.id AND o.userid = :userid5)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_move} mv
                         WHERE mv.activityid = a.id AND (mv.userid = :userid6 OR mv.successorid = :userid7))
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_volunteer} v
                         WHERE v.activityid = a.id AND v.userid = :userid8)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_digestq} dq
                         WHERE dq.activityid = a.id AND dq.userid = :userid9)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_dqsubject} dqs
                          JOIN {selfselectadvanced_digestq} dqq ON dqq.id = dqs.digestid
                         WHERE dqq.activityid = a.id AND dqs.userid = :userid23)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_eoi} eo
                         WHERE eo.activityid = a.id AND eo.guideid = :userid10)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_ticket} t
                         WHERE t.activityid = a.id
                           AND (t.requestedby = :userid12 OR t.claimedby = :userid13 OR t.resolvedby = :userid14))
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_ticketlog} tl
                          JOIN {selfselectadvanced_ticket} tlt ON tlt.id = tl.ticketid
                         WHERE tlt.activityid = a.id AND tl.actorid = :userid24)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_contact} ct
                         WHERE ct.activityid = a.id
                           AND (ct.guideid = :userid15 OR ct.sentby = :userid16))
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_agrun} ag
                         WHERE ag.activityid = a.id AND ag.triggeredby = :userid17)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_member} mi
                          JOIN {selfselectadvanced_group} gi ON gi.id = mi.groupid
                         WHERE gi.activityid = a.id AND mi.invitedby = :userid18)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_snapshot} sn
                          JOIN {selfselectadvanced_group} gs ON gs.id = sn.groupid
                         WHERE gs.activityid = a.id AND sn.takenby = :userid19)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_group} gm
                         WHERE gm.activityid = a.id AND gm.usermodified = :userid20)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_override} om
                         WHERE om.activityid = a.id AND om.usermodified = :userid21)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_move} mm
                         WHERE mm.activityid = a.id AND mm.usermodified = :userid22)
                    OR EXISTS (
                        SELECT 1 FROM {selfselectadvanced_kb} kb
                         WHERE kb.activityid = a.id
                           AND (kb.usercreated = :userid25 OR kb.usermodified = :userid26))";
        // The last six clauses close the reverse asymmetry the
        // get_users_in_context() comment describes: whoever triggered a
        // grouping run, sent an invitation, took a roster snapshot or is
        // recorded as a modifier/grantor was erasable through an
        // administrator's userlist deletion, while the SAME person's own
        // request found no context at all.
        //
        // THE DIGEST SUBJECT CLAUSE IS RELATIONAL (1.20.35). It used to be
        // a name search over the queued JSON, run in PHP below, because the
        // queue recorded no identity but the recipient's. It now joins
        // selfselectadvanced_dqsubject, which records every person a payload
        // is about by id, so two people with one name are two rows and a
        // rename changes nothing.
        //
        // ONE OPAQUE STORE REMAINS - a userid buried in an agrun log body -
        // and it is not in this statement; it is closed below (H-05). The
        // earlier reasoning was that a digits-substring match "would list
        // near-every context for near-every user", which is true of a
        // substring match used as the ANSWER and false of one used as a
        // pre-filter: the SQL narrows, and PHP decides on the decoded
        // structure. See related_opaque_contexts().
        $contextlist->add_from_sql($sql, [
            'modlevel' => CONTEXT_MODULE,
            'userid1' => $userid, 'userid2' => $userid, 'userid3' => $userid, 'userid4' => $userid,
            'userid5' => $userid, 'userid6' => $userid, 'userid7' => $userid, 'userid8' => $userid,
            'userid9' => $userid, 'userid10' => $userid, 'userid11' => $userid,
            'userid12' => $userid, 'userid13' => $userid, 'userid14' => $userid,
            'userid15' => $userid, 'userid16' => $userid, 'userid17' => $userid,
            'userid18' => $userid, 'userid19' => $userid, 'userid20' => $userid,
            'userid21' => $userid, 'userid22' => $userid, 'userid23' => $userid,
            'userid24' => $userid, 'userid25' => $userid, 'userid26' => $userid,
        ]);

        global $DB;
        // The remaining opaque store (H-05): an auto-grouping log that names
        // them as a participant.
        $opaque = self::related_opaque_contexts($userid);
        if ($opaque) {
            [$insql, $inparams] = $DB->get_in_or_equal($opaque, SQL_PARAMS_NAMED, 'opaquectx');
            $contextlist->add_from_sql("SELECT c.id FROM {context} c WHERE c.id $insql", $inparams);
        }

        // Their own attribute row, or attribute rows they are recorded
        // as having edited: either one makes the system context theirs.
        if (
            $DB->record_exists_select(
                'selfselectadvanced_userattr',
                'userid = ? OR usermodified = ?',
                [$userid, $userid]
            )
        ) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * The participants an auto-grouping log NAMES, read out of the
     * log's own shape.
     *
     * The shape is the engine's, and this is the READING half of the
     * knowledge scrub_user_in_activity() already carries in its
     * pseudonymiser: 'pool' and 'residue' are arrays of userids, and
     * each 'groups' entry has a 'leaderid' and a 'members' array.
     * Nothing else in the log is one - 'bypassedrules' holds quota-rule
     * ids and 'pluginuid' is a string - so a run that merely relaxed
     * rule 7 does not name user 7, and a team whose uid is "0042" does
     * not name user 42. Integer-typed leaves only, for exactly that
     * reason; a string that spells the same digits is not an identity
     * here any more than it is there.
     *
     * @param string|null $log the stored log column
     * @return int[] distinct userids the log names, [] when it names none
     */
    private static function agrun_participants(?string $log): array {
        if ($log === null || $log === '') {
            return [];
        }
        $decoded = json_decode($log, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        $collect = static function ($list) use (&$ids): void {
            if (!is_array($list)) {
                return;
            }
            foreach ($list as $value) {
                if (is_int($value) && $value > 0) {
                    $ids[$value] = $value;
                }
            }
        };
        $collect($decoded['pool'] ?? null);
        $collect($decoded['residue'] ?? null);
        foreach ($decoded['groups'] ?? [] as $grouplog) {
            if (!is_array($grouplog)) {
                continue;
            }
            if (is_int($grouplog['leaderid'] ?? null) && $grouplog['leaderid'] > 0) {
                $ids[$grouplog['leaderid']] = $grouplog['leaderid'];
            }
            $collect($grouplog['members'] ?? null);
        }

        return array_values($ids);
    }

    /**
     * Module contexts holding this person inside an OPAQUE column: an
     * auto-grouping log body (H-05).
     *
     * The store keeps participant identity inside TEXT that no foreign key
     * describes - a userid as a JSON integer - so it appears in no discovery
     * SQL, and a person whose only trace is one of them would have their own
     * subject-access request come back empty while the row sat there. A
     * context this method misses is a context their erasure never reaches
     * either, which is the worse half.
     *
     * TWO STAGES, and the split is the whole design. The SQL is a PRE-FILTER
     * whose only guaranteed property is that it never misses: any log holding
     * userid N as a JSON integer contains the decimal digits of N somewhere.
     * It is allowed to be generous - user 7 pre-matches a log that merely
     * mentions rule 7 or team 17. PHP then DECIDES, on the decoded structure,
     * where a userid is only a userid in an identity position. So the
     * cheap-and-wrong test never answers anything; it only limits what the
     * exact test has to read.
     *
     * THE DIGEST HALF WAS REMOVED IN 1.20.35 and is not coming back. It
     * searched queued payloads for a rendered full name, which cannot tell
     * two people with the same name apart, breaks when somebody renames, and
     * can never enumerate a subject by id. selfselectadvanced_dqsubject
     * records those people relationally, so the digest case is now answered
     * by ordinary SQL in get_contexts_for_userid() - a better matcher was
     * never the fix.
     *
     * The table is small by construction - one row per grouping run - and
     * this runs once per privacy request, off the web path.
     *
     * @param int $userid the person
     * @return int[] distinct module context ids
     */
    private static function related_opaque_contexts(int $userid): array {
        global $DB;

        $contexts = [];
        $joins = "JOIN {selfselectadvanced} a ON a.id = t.activityid
                  JOIN {modules} md ON md.name = 'selfselectadvanced'
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = md.id
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel";

        $sql = "SELECT t.id, t.log AS opaque, ctx.id AS contextid
                  FROM {selfselectadvanced_agrun} t
                  $joins
                 WHERE " . $DB->sql_like('t.log', ':needle', false);
        $rows = $DB->get_records_sql($sql, [
            'modlevel' => CONTEXT_MODULE,
            'needle' => '%' . $DB->sql_like_escape((string) $userid) . '%',
        ]);
        foreach ($rows as $row) {
            if (in_array($userid, self::agrun_participants($row->opaque), true)) {
                $contexts[(int) $row->contextid] = (int) $row->contextid;
            }
        }

        return array_values($contexts);
    }

    /**
     * Users present in one context.
     *
     * @param userlist $userlist the userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {selfselectadvanced_userattr}', []);
            // The staff who edited attribute records are held here too.
            $userlist->add_from_sql(
                'userid',
                'SELECT usermodified AS userid FROM {selfselectadvanced_userattr} WHERE usermodified > 0',
                []
            );

            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid];
        $userlist->add_from_sql(
            'userid',
            "SELECT m.userid
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'leaderid',
            "SELECT g.leaderid AS leaderid
               FROM {selfselectadvanced_group} g
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'guidesuccessorid',
            "SELECT g.guidesuccessorid AS guidesuccessorid
               FROM {selfselectadvanced_group} g
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid
              WHERE g.guidesuccessorid IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT v.userid
               FROM {selfselectadvanced_volunteer} v
               JOIN {course_modules} cm ON cm.instance = v.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT dq.userid
               FROM {selfselectadvanced_digestq} dq
               JOIN {course_modules} cm ON cm.instance = dq.activityid AND cm.id = :cmid",
            $params
        );
        // Everybody a queued payload is ABOUT, not just its recipient
        // (1.20.35). Until dqsubject existed this was the one class of data
        // subject the plugin knew it could not enumerate: their identity was
        // a rendered name inside somebody else's JSON, and a name is not an
        // identity.
        $userlist->add_from_sql(
            'userid',
            "SELECT dqs.userid
               FROM {selfselectadvanced_dqsubject} dqs
               JOIN {selfselectadvanced_digestq} dq ON dq.id = dqs.digestid
               JOIN {course_modules} cm ON cm.instance = dq.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT eo.guideid AS userid
               FROM {selfselectadvanced_eoi} eo
               JOIN {course_modules} cm ON cm.instance = eo.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT t.requestedby AS userid
               FROM {selfselectadvanced_ticket} t
               JOIN {course_modules} cm ON cm.instance = t.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT t.claimedby AS userid
               FROM {selfselectadvanced_ticket} t
               JOIN {course_modules} cm ON cm.instance = t.activityid AND cm.id = :cmid
              WHERE t.claimedby IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT t.resolvedby AS userid
               FROM {selfselectadvanced_ticket} t
               JOIN {course_modules} cm ON cm.instance = t.activityid AND cm.id = :cmid
              WHERE t.resolvedby IS NOT NULL",
            $params
        );
        // The history trail's own actor - a SEPARATE relation from the
        // three above, not implied by them: a manage holder who force-
        // released someone else's claimed ticket (close(), outcome=open,
        // actor need not be the claimant) is recorded here and nowhere
        // on the ticket row itself.
        $userlist->add_from_sql(
            'userid',
            "SELECT tl.actorid AS userid
               FROM {selfselectadvanced_ticketlog} tl
               JOIN {selfselectadvanced_ticket} t ON t.id = tl.ticketid
               JOIN {course_modules} cm ON cm.instance = t.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT ct.guideid AS userid
               FROM {selfselectadvanced_contact} ct
               JOIN {course_modules} cm ON cm.instance = ct.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT ct.sentby AS userid
               FROM {selfselectadvanced_contact} ct
               JOIN {course_modules} cm ON cm.instance = ct.activityid AND cm.id = :cmid",
            $params
        );

        // The roles below were listed by get_contexts_for_userid() but
        // NOT here, and the two APIs have to agree. A user this method
        // omits is never passed to delete_data_for_users(), so an
        // administrator deleting everybody in a context silently skipped
        // guides, successors, the subjects of staged moves, the targets
        // of overrides, whoever sent an invitation and whoever took a
        // roster snapshot - while the same user's own request would have
        // found the context perfectly well. A broken erasure is worse
        // than a broken disclosure, which is why these are here.
        //
        // NULLs are excluded because add_from_sql feeds an IN () list.
        $userlist->add_from_sql(
            'userid',
            "SELECT g.guideid AS userid
               FROM {selfselectadvanced_group} g
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid
              WHERE g.guideid IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT g.successorid AS userid
               FROM {selfselectadvanced_group} g
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid
              WHERE g.successorid IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT mv.userid AS userid
               FROM {selfselectadvanced_move} mv
               JOIN {course_modules} cm ON cm.instance = mv.activityid AND cm.id = :cmid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT mv.successorid AS userid
               FROM {selfselectadvanced_move} mv
               JOIN {course_modules} cm ON cm.instance = mv.activityid AND cm.id = :cmid
              WHERE mv.successorid IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT o.userid AS userid
               FROM {selfselectadvanced_override} o
               JOIN {course_modules} cm ON cm.instance = o.activityid AND cm.id = :cmid
              WHERE o.userid IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT m.invitedby AS userid
               FROM {selfselectadvanced_member} m
               JOIN {selfselectadvanced_group} g ON g.id = m.groupid
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid
              WHERE m.invitedby IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT s.takenby AS userid
               FROM {selfselectadvanced_snapshot} s
               JOIN {selfselectadvanced_group} g ON g.id = s.groupid
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid
              WHERE s.takenby IS NOT NULL",
            $params
        );

        // Whoever hand-triggered a grouping run, and everyone recorded
        // as a modifier or grantor. All four columns default to 0 for
        // "nobody", which the > 0 guards keep out of the IN () list.
        $userlist->add_from_sql(
            'userid',
            "SELECT ag.triggeredby AS userid
               FROM {selfselectadvanced_agrun} ag
               JOIN {course_modules} cm ON cm.instance = ag.activityid AND cm.id = :cmid
              WHERE ag.triggeredby > 0",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT g.usermodified AS userid
               FROM {selfselectadvanced_group} g
               JOIN {course_modules} cm ON cm.instance = g.activityid AND cm.id = :cmid
              WHERE g.usermodified > 0",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT o.usermodified AS userid
               FROM {selfselectadvanced_override} o
               JOIN {course_modules} cm ON cm.instance = o.activityid AND cm.id = :cmid
              WHERE o.usermodified > 0",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT mv.usermodified AS userid
               FROM {selfselectadvanced_move} mv
               JOIN {course_modules} cm ON cm.instance = mv.activityid AND cm.id = :cmid
              WHERE mv.usermodified > 0",
            $params
        );
        // The knowledgebank (1.20.45): the same annotated-columns idiom
        // as override/group/move's own usermodified above, plus the
        // author (usercreated has no vacancy-style 0-for-nobody meaning
        // the way group.leaderid does, but it IS a modifier-style column
        // that degrades to 0 on erasure exactly like usermodified does,
        // so both are guarded the same way here).
        $userlist->add_from_sql(
            'userid',
            "SELECT kb.usercreated AS userid
               FROM {selfselectadvanced_kb} kb
               JOIN {course_modules} cm ON cm.instance = kb.activityid AND cm.id = :cmid
              WHERE kb.usercreated > 0",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT kb.usermodified AS userid
               FROM {selfselectadvanced_kb} kb
               JOIN {course_modules} cm ON cm.instance = kb.activityid AND cm.id = :cmid
              WHERE kb.usermodified > 0",
            $params
        );

        // The participants an auto-grouping log NAMES, not merely the
        // manager who started the run (H-05). The log body is the
        // authoritative record of who was placed where, and it was
        // reachable from no direction at all: this method listed only
        // triggeredby, so an administrator deleting everybody they
        // could see in this context left the placements of everyone
        // else intact.
        //
        // Read in PHP because the answer is inside a TEXT column: one
        // pass over this ONE activity's runs, decoding each log by its
        // own shape. No cross-activity scan and no substring guessing
        // here - the context is already known, so the pre-filter
        // related_opaque_contexts() needs is not wanted.
        $participants = [];
        $logs = $DB->get_recordset_sql(
            "SELECT ag.id, ag.log
               FROM {selfselectadvanced_agrun} ag
               JOIN {course_modules} cm ON cm.instance = ag.activityid AND cm.id = :cmid",
            $params
        );
        foreach ($logs as $log) {
            foreach (self::agrun_participants($log->log) as $participant) {
                $participants[$participant] = $participant;
            }
        }
        $logs->close();
        if ($participants) {
            [$insql, $inparams] = $DB->get_in_or_equal($participants, SQL_PARAMS_NAMED, 'agrunuser');
            $userlist->add_from_sql('id', "SELECT u.id FROM {user} u WHERE u.id $insql", $inparams);
        }

        // The gap this method used to declare - a person whose only trace was
        // their NAME inside another recipient's queued payload, unlistable
        // because a rendered name has no reverse to an account - is CLOSED as
        // of 1.20.35 by the dqsubject clause above. The narrow case it left
        // open (an administrator erasing a subset of a context's users misses
        // a digest-only subject) is closed with it: that subject is now
        // enumerated by id like anybody else.
    }

    /**
     * Export the user's data in each approved context.
     *
     * @param approved_contextlist $contextlist approved contexts
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $attr = $DB->get_record('selfselectadvanced_userattr', ['userid' => $userid]);
                // A staff member who edited OTHER people's attribute
                // records is held in this context through usermodified
                // alone; their export says so without naming whose rows
                // those were (the subjects' identities are the subjects'
                // data, not the editor's).
                $edited = $DB->count_records_select(
                    'selfselectadvanced_userattr',
                    'usermodified = ? AND userid <> ?',
                    [$userid, $userid]
                );
                if ($attr || $edited) {
                    $export = (object) [
                        'attributerecordsyouedited' => $edited,
                    ];
                    if ($attr) {
                        $export->gender = $attr->gender;
                        $export->department = $attr->department;
                        $export->subdepartment = $attr->subdepartment;
                        $export->mobile = $attr->mobile;
                        $export->shareconsent = (int) ($attr->shareconsent ?? 0);
                        // Both are written by the attribute importer
                        // and one of them is a physical location, yet
                        // neither was declared or exported. They are
                        // correctly erased, so this was a disclosure
                        // gap rather than a retention one.
                        $export->seatlocation = $attr->seatlocation;
                        $export->program = $attr->program;
                    }
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'mod_selfselectadvanced'),
                            get_string('participantattributes', 'mod_selfselectadvanced')],
                        $export
                    );
                }
                continue;
            }
            if (!$context instanceof \context_module) {
                continue;
            }
            $cmrec = get_coursemodule_from_id('selfselectadvanced', $context->instanceid);
            if ($cmrec) {
                $membergroups = $DB->get_fieldset_sql(
                    "SELECT m.groupid
                       FROM {selfselectadvanced_member} m
                       JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                      WHERE g.activityid = ? AND m.userid = ? AND m.status = 'confirmed'",
                    [$cmrec->instance, $userid]
                );
                foreach ($membergroups as $membergroupid) {
                    writer::with_context($context)->export_area_files(
                        [get_string('pluginname', 'mod_selfselectadvanced'),
                            get_string('proposal', 'mod_selfselectadvanced')],
                        'mod_selfselectadvanced',
                        'proposal',
                        (int) $membergroupid
                    );
                }
            }
            $cm = get_coursemodule_from_id('selfselectadvanced', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $rows = $DB->get_records_sql(
                "SELECT m.id, g.name, g.pluginuid, g.title, g.brief, g.briefformat, g.state,
                        g.returncomment, g.disbandreason, m.status, m.isleader, m.invitedby,
                        m.timeinvited, m.timeresponded, m.leaverequested, p.penaltyvalue,
                        p.dayslate, p.award, p.waived, p.waivereason
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
              LEFT JOIN {selfselectadvanced_penalty} p ON p.groupid = g.id
                  WHERE g.activityid = :activityid AND m.userid = :userid",
                ['activityid' => $cm->instance, 'userid' => $userid]
            );
            $memberships = [];
            foreach ($rows as $row) {
                $memberships[] = (object) [
                    'group' => format_string($row->name),
                    'pluginuid' => $row->pluginuid,
                    'title' => format_string($row->title),
                    'state' => $row->state,
                    'status' => $row->status,
                    'isleader' => transform::yesno($row->isleader),
                    'timeinvited' => $row->timeinvited ? transform::datetime($row->timeinvited) : null,
                    'timeresponded' => $row->timeresponded ? transform::datetime($row->timeresponded) : null,
                    'leaverequested' => $row->leaverequested
                        ? transform::datetime($row->leaverequested)
                        : null,
                    // The brief was SELECTed and then dropped on the
                    // floor, so a declared field was never exported.
                    'brief' => $row->brief,
                    'returncomment' => $row->returncomment,
                    // GUIDE NOTES ARE NOT EXPORTED (maintainer decision 84,
                    // 2026-08-09). They used to be, on the argument that a data
                    // subject is entitled to see what is held on them - which is
                    // a real principle, and the wrong one here.
                    //
                    // The field hangs off the GROUP, not off a person. The
                    // software cannot tell which sentence is about the requester
                    // and which is about a teammate, so exporting the whole field
                    // hands one student the guide's evaluative prose about
                    // everybody else, plus staff deliberation, in one block. No
                    // filter on membership fixes that; the ruling considered and
                    // rejected filtering to confirmed members for exactly this
                    // reason. The interface's promise to the guide - "students
                    // never see them" - therefore becomes true again.
                    //
                    // The field stays DECLARED in this provider's metadata,
                    // because the plugin does store it and saying so is the
                    // point of metadata. Where an institution must disclose
                    // something from these notes under a subject-access request,
                    // that is a human deciding what pertains to the requester and
                    // redacting the rest, not an automatic dump.
                    'disbandreason' => $row->disbandreason,
                    // Who invited this person is part of this person's
                    // own membership history, and it was declared in the
                    // metadata without ever being exported.
                    'invitedby' => $row->invitedby !== null ? (int) $row->invitedby : null,
                    'grouppenalty' => $row->penaltyvalue,
                    // The rest of the penalty row: declared since the
                    // ledger existed, exported never.
                    'penaltydayslate' => $row->dayslate !== null ? (int) $row->dayslate : null,
                    'penaltyaward' => $row->award,
                    'penaltywaived' => $row->waived !== null ? transform::yesno($row->waived) : null,
                    'penaltywaivereason' => $row->waivereason,
                ];
            }
            $overrides = $DB->get_records_sql(
                "SELECT o.*, g.name AS groupname
                   FROM {selfselectadvanced_override} o
              LEFT JOIN {selfselectadvanced_group} g ON g.id = o.groupid
                  WHERE o.activityid = :activityid AND o.userid = :userid",
                ['activityid' => $cm->instance, 'userid' => $userid]
            );
            // Moves this person was the subject or successor of, plus
            // the ones they staged or answered for someone else: the
            // stager's id sits on the row too, so the row belongs in
            // their export as much as in the subject's.
            $moves = $DB->get_records_sql(
                "SELECT mv.*, sg.name AS sourcename, tg.name AS targetname
                   FROM {selfselectadvanced_move} mv
              LEFT JOIN {selfselectadvanced_group} sg ON sg.id = mv.sourcegroupid
              LEFT JOIN {selfselectadvanced_group} tg ON tg.id = mv.targetgroupid
                  WHERE mv.activityid = :activityid
                    AND (mv.userid = :u1 OR mv.successorid = :u2 OR mv.usermodified = :u3)",
                ['activityid' => $cm->instance, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid]
            );
            // Invitations this person SENT. The receiving side already
            // sits in the memberships dataset; without this one, a user
            // whose only footprint is inviting others had a discovered
            // context and an empty export.
            $sentinvites = $DB->get_records_sql(
                "SELECT m.id, g.name, g.pluginuid, m.status, m.timeinvited
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                  WHERE g.activityid = :activityid AND m.invitedby = :userid AND m.userid <> :userid2",
                ['activityid' => $cm->instance, 'userid' => $userid, 'userid2' => $userid]
            );
            // Grouping runs this person hand-triggered (agrun was in the
            // metadata and in no export dataset at all).
            $agruns = $DB->get_records('selfselectadvanced_agrun', [
                'activityid' => $cm->instance,
                'triggeredby' => $userid,
            ], 'timestarted ASC');
            // Teams and overrides where this person is recorded as the
            // last modifier or the grantor - the columns are declared as
            // their personal data, so their export names the rows.
            $modifiedgroups = $DB->get_records(
                'selfselectadvanced_group',
                ['activityid' => $cm->instance, 'usermodified' => $userid],
                'id ASC',
                'id, name, pluginuid, timemodified'
            );
            $grantedoverrides = $DB->get_records(
                'selfselectadvanced_override',
                ['activityid' => $cm->instance, 'usermodified' => $userid],
                'id ASC',
                'id, scope, status, timecreated'
            );
            // Knowledgebank entries this person authored or last edited
            // (1.20.45) - the two annotated columns only, the same
            // declared-and-exported pair the two queries above already
            // cover for group/override. A row reached only through
            // usermodified still names the TITLE so the export makes
            // sense on its own, not the whole staff-authored content.
            $kbauthored = $DB->get_records_sql(
                "SELECT kb.id, kb.title, kb.tickettype, kb.published, kb.timecreated, kb.timemodified,
                        kb.usercreated, kb.usermodified
                   FROM {selfselectadvanced_kb} kb
                  WHERE kb.activityid = :activityid AND (kb.usercreated = :u1 OR kb.usermodified = :u2)",
                ['activityid' => $cm->instance, 'u1' => $userid, 'u2' => $userid]
            );
            $volunteer = $DB->get_record('selfselectadvanced_volunteer', [
                'activityid' => $cm->instance,
                'userid' => $userid,
            ]);
            $digestqueue = $DB->get_records('selfselectadvanced_digestq', [
                'activityid' => $cm->instance,
                'userid' => $userid,
            // The id breaks the tie - an export must be reproducible.
            ], 'timecreated ASC, id ASC');
            // QUEUED NOTIFICATIONS THAT REFERENCE THEM but are addressed to
            // somebody else. Deliberately NOT the payload: that text is
            // another recipient's message and can name third parties, so the
            // subject is told a message about them is queued, when, by which
            // provider and about which group - and no more. If per-placeholder
            // detail is ever wanted, the relation grows a field saying which
            // placeholder belongs to whom; it is not recovered by reading the
            // prose.
            $digestreferences = $DB->get_records_sql(
                "SELECT dq.id, dq.provider, dq.timecreated, g.name, g.pluginuid
                   FROM {selfselectadvanced_dqsubject} dqs
                   JOIN {selfselectadvanced_digestq} dq ON dq.id = dqs.digestid
              LEFT JOIN {selfselectadvanced_group} g ON g.id = dq.groupid
                  WHERE dq.activityid = :activityid AND dqs.userid = :userid AND dq.userid <> :recipient
               ORDER BY dq.timecreated ASC, dq.id ASC",
                ['activityid' => $cm->instance, 'userid' => $userid, 'recipient' => $userid]
            );
            $eois = $DB->get_records_sql(
                "SELECT eo.id, g.name, g.pluginuid, eo.status, eo.remarks, eo.remarksformat,
                        eo.timecreated, eo.timeresponded
                   FROM {selfselectadvanced_eoi} eo
                   JOIN {selfselectadvanced_group} g ON g.id = eo.groupid
                  WHERE eo.activityid = :activityid AND eo.guideid = :userid",
                ['activityid' => $cm->instance, 'userid' => $userid]
            );
            // LEFT JOIN deliberately: a team-limit ticket is about the
            // guide and not about a team, so it carries no groupid. An
            // inner join dropped every one of them out of the person's
            // own export.
            //
            // The fourth OR EXISTS clause (audit B9/M-14) is the same
            // relation get_contexts_for_userid() already tests on
            // ticketlog.actorid, "a SEPARATE relation from the three
            // above, not implied by them" (that method's own comment) -
            // a coordinator who referred or escalated a ticket away is
            // recorded ONLY there once claimedby moves on, so without
            // this clause their export said nothing about a context this
            // very method already discovered for them.
            $tickets = $DB->get_records_sql(
                "SELECT t.id, g.name, g.pluginuid, t.type, t.status, t.requestedby, t.claimedby,
                        t.resolvedby, t.request, t.requestformat, t.resolution, t.resolutionformat,
                        t.requested, t.disclaimerack, t.timecreated, t.timeresolved
                   FROM {selfselectadvanced_ticket} t
              LEFT JOIN {selfselectadvanced_group} g ON g.id = t.groupid
                  WHERE t.activityid = :activityid
                    AND (t.requestedby = :u1 OR t.claimedby = :u2 OR t.resolvedby = :u3
                         OR EXISTS (SELECT 1 FROM {selfselectadvanced_ticketlog} tl
                                     WHERE tl.ticketid = t.id AND tl.actorid = :u4))",
                ['activityid' => $cm->instance, 'u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid]
            );
            // The history trail, nested under the ticket export below
            // rather than fetched separately (decision 1, 2026-08-15):
            // whoever is entitled to see the TICKET above - because they
            // filed it, claimed it or resolved it - is entitled to see
            // how it was handled, so no separate actor filter is applied
            // here. Grouped by ticketid in PHP rather than one query per
            // ticket, on the same house rule notify_workers() states.
            $ticketlogs = [];
            if ($tickets) {
                [$ticketidinsql, $ticketidparams] = $DB->get_in_or_equal(
                    array_keys($tickets),
                    SQL_PARAMS_NAMED,
                    'tlt'
                );
                foreach (
                    $DB->get_records_select(
                        'selfselectadvanced_ticketlog',
                        "ticketid $ticketidinsql",
                        $ticketidparams,
                        'ticketid, timecreated, id'
                    ) as $logrow
                ) {
                    $ticketlogs[(int) $logrow->ticketid][] = $logrow;
                }
            }
            // Approaches this person sent or received (declared in the
            // metadata since 1.17, but never actually exported).
            $contacts = $DB->get_records_sql(
                "SELECT c.id, g.name, g.pluginuid, c.status, c.message, c.messageformat,
                        c.reason, c.reasonformat, c.guideid, c.sentby, c.timecreated, c.timeresponded
                   FROM {selfselectadvanced_contact} c
                   JOIN {selfselectadvanced_group} g ON g.id = c.groupid
                  WHERE c.activityid = :activityid AND (c.guideid = :u1 OR c.sentby = :u2)",
                ['activityid' => $cm->instance, 'u1' => $userid, 'u2' => $userid]
            );
            // Roster snapshots are declared in the metadata and were
            // never exported by any path. They are the authoritative
            // record of who was in a team when it was frozen, so a
            // person asking what is held about them was being told
            // nothing about the one record that settles it.
            $snapshots = [];
            $snapshotrows = $DB->get_records_sql(
                "SELECT s.id, s.roster, s.takenby, s.timecreated, g.name, g.pluginuid
                   FROM {selfselectadvanced_snapshot} s
                   JOIN {selfselectadvanced_group} g ON g.id = s.groupid
                  WHERE g.activityid = :activityid
               ORDER BY s.timecreated",
                ['activityid' => $cm->instance]
            );
            foreach ($snapshotrows as $snapshot) {
                $roster = json_decode((string) $snapshot->roster, true) ?: [];
                $mine = array_values(array_filter(
                    $roster,
                    static fn($entry) => (int) ($entry['userid'] ?? 0) === $userid
                ));
                if (!$mine && (int) $snapshot->takenby !== $userid) {
                    continue;
                }
                $snapshots[] = (object) [
                    'group' => format_string($snapshot->name),
                    'pluginuid' => $snapshot->pluginuid,
                    'takenbyyou' => transform::yesno((int) $snapshot->takenby === $userid),
                    'yourentry' => $mine ? reset($mine) : null,
                    'timecreated' => transform::datetime($snapshot->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'mod_selfselectadvanced')],
                (object) [
                    'memberships' => $memberships,
                    'snapshots' => $snapshots,
                    'interests' => array_values(array_map(static fn($eo) => (object) [
                        'group' => format_string($eo->name),
                        'pluginuid' => $eo->pluginuid,
                        'status' => $eo->status,
                        'remarks' => format_text($eo->remarks, $eo->remarksformat, ['context' => $context]),
                        'timecreated' => transform::datetime($eo->timecreated),
                        'timeresponded' => $eo->timeresponded ? transform::datetime($eo->timeresponded) : null,
                    ], $eois)),
                    // The whole override row, not the seven fields an
                    // earlier version happened to pick: every column of
                    // an exception held against this person is held
                    // about this person, and the three window stamps
                    // are dates, so they read as dates.
                    'overrides' => array_values(array_map(static fn($o) => (object) [
                        'scope' => $o->scope,
                        'status' => $o->status,
                        'group' => $o->groupname !== null ? format_string($o->groupname) : null,
                        'timeopen' => $o->timeopen ? transform::datetime($o->timeopen) : null,
                        'timedue' => $o->timedue ? transform::datetime($o->timedue) : null,
                        'timecutoff' => $o->timecutoff ? transform::datetime($o->timecutoff) : null,
                        'minsize' => $o->minsize,
                        'maxsize' => $o->maxsize,
                        'maxlead' => $o->maxlead,
                        'maxmembership' => $o->maxmembership,
                        'maxguided' => $o->maxguided,
                        'quotaexempt' => $o->quotaexempt !== null ? transform::yesno($o->quotaexempt) : null,
                        'penaltywaived' => $o->penaltywaived !== null ? transform::yesno($o->penaltywaived) : null,
                        'guidehidden' => $o->guidehidden !== null ? transform::yesno($o->guidehidden) : null,
                        'rulesbypassed' => $o->rulesbypassed,
                        'timecreated' => transform::datetime($o->timecreated),
                        'timemodified' => transform::datetime($o->timemodified),
                    ], $overrides)),
                    // Reason and responsenote were declared in the
                    // metadata and dropped from the export; the rest of
                    // the row says what the move actually was.
                    'moves' => array_values(array_map(static fn($m) => (object) [
                        'status' => $m->status,
                        'sourcegroup' => $m->sourcename !== null ? format_string($m->sourcename) : null,
                        'targetgroup' => $m->targetname !== null ? format_string($m->targetname) : null,
                        'wassubject' => transform::yesno((int) $m->userid === $userid),
                        'wassuccessor' => transform::yesno((int) ($m->successorid ?? 0) === $userid),
                        'stagedbyyou' => transform::yesno((int) $m->usermodified === $userid),
                        'makeleader' => transform::yesno($m->makeleader),
                        // The subject and the successor get the full
                        // prose, exactly as before the query widened -
                        // the move is ABOUT them. The WIDENED arm is
                        // the one that needs the line (wave-2 blind
                        // audit, low 4): a row reached only through
                        // usermodified exports the stager's OWN words
                        // (reason) but not the subject's response - the
                        // export is the stager's data, not a copy of
                        // somebody else's.
                        'reason' => $m->reason,
                        'responsenote' => ((int) $m->userid === $userid
                            || (int) ($m->successorid ?? 0) === $userid)
                            ? $m->responsenote
                            : null,
                        'timecreated' => transform::datetime($m->timecreated),
                        'timecommitted' => $m->timecommitted ? transform::datetime($m->timecommitted) : null,
                    ], $moves)),
                    'invitationssent' => array_values(array_map(static fn($i) => (object) [
                        'group' => format_string($i->name),
                        'pluginuid' => $i->pluginuid,
                        'status' => $i->status,
                        'timeinvited' => $i->timeinvited ? transform::datetime($i->timeinvited) : null,
                    ], $sentinvites)),
                    'autogroupruns' => array_values(array_map(static fn($r) => (object) [
                        'triggeredbyyou' => transform::yesno(true),
                        'timestarted' => transform::datetime($r->timestarted),
                        'timefinished' => $r->timefinished ? transform::datetime($r->timefinished) : null,
                        'groupsformed' => (int) $r->groupsformed,
                        'placed' => (int) $r->placed,
                        'unplaced' => (int) $r->unplaced,
                    ], $agruns)),
                    'groupsmodified' => array_values(array_map(static fn($g) => (object) [
                        'group' => format_string($g->name),
                        'pluginuid' => $g->pluginuid,
                        'timemodified' => transform::datetime($g->timemodified),
                    ], $modifiedgroups)),
                    // 1.20.45. wasauthor/waseditor rather than a raw
                    // usercreated/usermodified comparison repeated inline -
                    // the same "wasyou"-shaped flag 'moves' above uses for
                    // a third party it must not name by id.
                    'kbarticlesauthored' => array_values(array_map(static fn($kbrow) => (object) [
                        'title' => format_string($kbrow->title),
                        'wasauthor' => transform::yesno((int) $kbrow->usercreated === $userid),
                        'waseditor' => transform::yesno((int) $kbrow->usermodified === $userid),
                        'published' => transform::yesno($kbrow->published),
                        'timecreated' => transform::datetime($kbrow->timecreated),
                        'timemodified' => transform::datetime($kbrow->timemodified),
                    ], $kbauthored)),
                    'overridesgranted' => array_values(array_map(static fn($o) => (object) [
                        'scope' => $o->scope,
                        'status' => $o->status,
                        'timecreated' => transform::datetime($o->timecreated),
                    ], $grantedoverrides)),
                    'approaches' => array_values(array_map(static fn($c) => (object) [
                        'group' => format_string($c->name),
                        'pluginuid' => $c->pluginuid,
                        'status' => $c->status,
                        'wasguide' => transform::yesno((int) $c->guideid === $userid),
                        'wassender' => transform::yesno((int) $c->sentby === $userid),
                        'message' => format_text($c->message, $c->messageformat, ['context' => $context]),
                        // Both parties are entitled to the answer: one
                        // wrote it, the other was told it.
                        'reason' => $c->reason !== null
                            ? format_text($c->reason, $c->reasonformat, ['context' => $context])
                            : null,
                        'timecreated' => transform::datetime($c->timecreated),
                        'timeresponded' => $c->timeresponded ? transform::datetime($c->timeresponded) : null,
                    ], $contacts)),
                    'volunteer' => $volunteer ? (object) [
                        'capacity' => $volunteer->capacity,
                        'timemodified' => transform::datetime($volunteer->timemodified),
                    ] : null,
                    'digestqueue' => array_values(array_map(static fn($dq) => (object) [
                        'provider' => $dq->provider,
                        // The payload is declared in the metadata and was
                        // never exported: it is the notification text
                        // queued about this person, which is exactly the
                        // sort of thing a subject-access request is for.
                        'payload' => $dq->payload,
                        'timecreated' => transform::datetime($dq->timecreated),
                    ], $digestqueue)),
                    'digestreferences' => array_values(array_map(static fn($dr) => (object) [
                        'provider' => $dr->provider,
                        'group' => $dr->name !== null ? format_string($dr->name) : null,
                        'pluginuid' => $dr->pluginuid,
                        'timecreated' => transform::datetime($dr->timecreated),
                        'youarearecordedsubject' => transform::yesno(true),
                    ], $digestreferences)),
                    'tickets' => array_values(array_map(static fn($t) => (object) [
                        'group' => $t->name !== null ? format_string($t->name) : null,
                        'pluginuid' => $t->pluginuid,
                        'requested' => $t->requested !== null ? (int) $t->requested : null,
                        'type' => $t->type,
                        'status' => $t->status,
                        'wasrequester' => transform::yesno((int) $t->requestedby === $userid),
                        'washandler' => transform::yesno(
                            (int) ($t->claimedby ?? 0) === $userid || (int) ($t->resolvedby ?? 0) === $userid
                        ),
                        'request' => (int) $t->requestedby === $userid
                            ? format_text($t->request, $t->requestformat, ['context' => $context])
                            : null,
                        // The requester is told the outcome of their own
                        // request, so the note is theirs to have as much
                        // as it is the handler's who wrote it.
                        'resolution' => $t->resolution !== null
                            && ((int) ($t->resolvedby ?? 0) === $userid || (int) $t->requestedby === $userid)
                            ? format_text($t->resolution, $t->resolutionformat, ['context' => $context])
                            : null,
                        // 1.20.43: whether the requester acknowledged the
                        // activity's disclaimer when they filed this -
                        // always 0/No when the activity had none set.
                        'disclaimerack' => transform::yesno($t->disclaimerack),
                        'timecreated' => transform::datetime($t->timecreated),
                        'timeresolved' => $t->timeresolved ? transform::datetime($t->timeresolved) : null,
                        // The history trail, nested here rather than a
                        // sibling export section: NO raw actor id, the
                        // same "wasyou" shape 'moves' above uses for
                        // stagedbyyou/wassubject - identifying a THIRD
                        // party by id inside THIS person's export is
                        // not this export's business.
                        //
                        // exportable_trail() (audit B3/H-2/H-8) withholds
                        // STAFF_INTERNAL_ACTIONS rows (referred, escalated,
                        // published_faq) from anyone who is not staff on
                        // THIS ticket and not that row's own actor - the
                        // same rows every other requester-facing surface
                        // already withholds (tickets::trail($withactors =
                        // false)'s own SQL exclusion).
                        'trail' => array_values(array_map(static fn($l) => (object) [
                            'action' => $l->action,
                            'note' => $l->note !== null
                                ? format_text($l->note, $l->noteformat, ['context' => $context])
                                : null,
                            'wasyou' => transform::yesno((int) $l->actorid === $userid),
                            'timecreated' => transform::datetime($l->timecreated),
                        ], self::exportable_trail($ticketlogs[(int) $t->id] ?? [], $t, $userid))),
                    ], $tickets)),
                ]
            );
            // 1.20.44: attachments travel alongside each ticket/trail
            // entry just exported above (core export_area_files
            // pattern) - one call per itemid this person is entitled to
            // see the surrounding text of, exactly the set the export
            // above already iterated. STAFF_INTERNAL_ACTIONS rows are
            // skipped explicitly rather than trusted to carry no files
            // by construction: no filemanager was ever offered on
            // refer()/escalate()'s two forms, but a file dropped into
            // that itemid by another route (a stray upload, a bug
            // elsewhere) must still never be handed to a subject-access
            // export just because nothing legitimate put it there.
            //
            // EACH TICKET GETS ITS OWN LEAF (audit B11/M-17). Both areas
            // declare subdirs = 0, so every file's filepath is '/' and
            // the FILENAME is the only discriminator a flat folder has:
            // two tickets whose requesters each attached screenshot.png
            // shared one path, and the second overwrote the first in the
            // export. The itemid is the only thing that tells them
            // apart, so it names the folder - and a post's files sit
            // under their own ticket, which is where a reader looking
            // for them would go.
            foreach ($tickets as $exportedticket) {
                // The opening request's attachment follows the SAME test
                // as its text three lines above (audit B11/M-16): a
                // handler who is not the requester is not shown the
                // request's own wording, and must not be handed the file
                // that belongs to it either.
                $ticketleaf = [
                    get_string('pluginname', 'mod_selfselectadvanced'),
                    get_string('tickets', 'mod_selfselectadvanced'),
                    'ticket-' . (int) $exportedticket->id,
                ];
                if ((int) $exportedticket->requestedby === $userid) {
                    writer::with_context($context)->export_area_files(
                        $ticketleaf,
                        'mod_selfselectadvanced',
                        'ticketrequest',
                        (int) $exportedticket->id
                    );
                }
                foreach ($ticketlogs[(int) $exportedticket->id] ?? [] as $exportedlog) {
                    if (in_array($exportedlog->action, \mod_selfselectadvanced\local\tickets::STAFF_INTERNAL_ACTIONS, true)) {
                        continue;
                    }
                    writer::with_context($context)->export_area_files(
                        array_merge($ticketleaf, ['post-' . (int) $exportedlog->id]),
                        'mod_selfselectadvanced',
                        'ticketpost',
                        (int) $exportedlog->id
                    );
                }
            }
        }
    }

    /**
     * The ticketlog rows a subject-access export may show for one ticket
     * (audit B3/H-2/H-8): every row whose action is not one of
     * tickets::STAFF_INTERNAL_ACTIONS, plus a staff-internal one when the
     * exporting user is staff on THIS ticket (claimant or resolver) or is
     * that row's own actor - a handler still sees their own referral or
     * escalation note even after moving the ticket on (audit B9/M-14's
     * "a handler still sees their own referral note").
     *
     * @param \stdClass[] $rows the ticket's ticketlog rows
     * @param \stdClass $ticket the ticket row (claimedby, resolvedby, requestedby)
     * @param int $userid the exporting user
     * @return \stdClass[] the rows this export may include
     */
    private static function exportable_trail(array $rows, \stdClass $ticket, int $userid): array {
        $isstaffonthisticket = (int) ($ticket->claimedby ?? 0) === $userid
            || (int) ($ticket->resolvedby ?? 0) === $userid;

        return array_values(array_filter($rows, static function ($l) use ($isstaffonthisticket, $userid) {
            if (!in_array($l->action, \mod_selfselectadvanced\local\tickets::STAFF_INTERNAL_ACTIONS, true)) {
                return true;
            }

            return $isstaffonthisticket || (int) $l->actorid === $userid;
        }));
    }

    /**
     * Export reminder preferences and the site-wide notification
     * digest preference.
     *
     * @param int $userid the user
     */
    public static function export_user_preferences(int $userid): void {
        global $DB;

        // Site-wide, so it is exported once and not inside the per-activity
        // loop below: perpage::resolve() stores one value for the person, not
        // one per activity.
        $perpage = get_user_preferences('mod_selfselectadvanced_perpage', null, $userid);
        if ($perpage !== null) {
            writer::export_user_preference(
                'mod_selfselectadvanced',
                'mod_selfselectadvanced_perpage',
                $perpage,
                get_string('privacy:metadata:preference:perpage', 'mod_selfselectadvanced')
            );
        }

        foreach ($DB->get_records('selfselectadvanced', null, 'id ASC', 'id') as $row) {
            $pref = get_user_preferences('mod_selfselectadvanced_reminded_' . $row->id, null, $userid);
            if ($pref !== null) {
                writer::export_user_preference(
                    'mod_selfselectadvanced',
                    'mod_selfselectadvanced_reminded_' . $row->id,
                    $pref,
                    get_string('privacy:metadata:preference:reminded', 'mod_selfselectadvanced')
                );
            }
        }

        $digest = get_user_preferences('mod_selfselectadvanced_digest', null, $userid);
        if ($digest !== null) {
            writer::export_user_preference(
                'mod_selfselectadvanced',
                'mod_selfselectadvanced_digest',
                $digest,
                get_string('privacy:metadata:preference:digest', 'mod_selfselectadvanced')
            );
        }

        // The guide-reminder markers (seam audit, 1.20.19): one per
        // guided group, written by the auto-approve task, keyed by
        // group id - personal data the provider had not declared.
        foreach ($DB->get_records('selfselectadvanced_group', null, 'id ASC', 'id') as $grow) {
            $gremind = get_user_preferences('mod_selfselectadvanced_gremind_' . $grow->id, null, $userid);
            if ($gremind !== null) {
                writer::export_user_preference(
                    'mod_selfselectadvanced',
                    'mod_selfselectadvanced_gremind_' . $grow->id,
                    $gremind,
                    get_string('privacy:metadata:preference:gremind', 'mod_selfselectadvanced')
                );
            }
        }
    }

    /**
     * Delete everyone's data in a context.
     *
     * @param \context $context the context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_system) {
            $DB->delete_records('selfselectadvanced_userattr');
            \mod_selfselectadvanced\local\attributes\manager::purge_value_cache();

            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('selfselectadvanced', $context->instanceid);
        if (!$cm) {
            return;
        }
        // T-16: every mirrored course group of this activity, plus the
        // members it currently holds, read BEFORE the purge. Afterwards
        // the expected set is empty and the guide is null, so every
        // plugin-owned or forced row leaves the mirror; rows a teacher
        // added by hand are not ours and stay.
        $mirroredgroups = $DB->get_records_select(
            'selfselectadvanced_group',
            'activityid = ? AND coregroupid IS NOT NULL',
            [$cm->instance],
            '',
            'id, coregroupid, guideid'
        );
        $priormembers = [];
        foreach ($mirroredgroups as $mirroredgroup) {
            // The set the plugin was responsible for, NOT everyone the
            // course group happens to hold: a stranger a teacher added
            // by hand is not the plugin's row to delete, here or
            // anywhere else (14.5).
            $priormembers[(int) $mirroredgroup->id] =
                \mod_selfselectadvanced\local\freeze::expected_core_members($mirroredgroup);
        }

        $groupids = $DB->get_fieldset_select('selfselectadvanced_group', 'id', 'activityid = ?', [$cm->instance]);
        if ($groupids) {
            [$insql, $params] = $DB->get_in_or_equal($groupids);
            $DB->delete_records_select('selfselectadvanced_member', "groupid $insql", $params);
            $DB->delete_records_select('selfselectadvanced_snapshot', "groupid $insql", $params);
            $fs = get_file_storage();
            foreach ($groupids as $proposalgroupid) {
                $fs->delete_area_files($context->id, 'mod_selfselectadvanced', 'proposal', (int) $proposalgroupid);
            }
        }
        $DB->delete_records('selfselectadvanced_move', ['activityid' => $cm->instance]);
        $DB->delete_records_select(
            'selfselectadvanced_override',
            "activityid = ? AND scope IN ('user', 'guide')",
            [$cm->instance]
        );
        $DB->delete_records('selfselectadvanced_volunteer', ['activityid' => $cm->instance]);
        // Relation first, then the queue rows - through the one helper every
        // digest-removing path uses, so this method cannot be the one that
        // forgets and leaves an orphan naming somebody.
        \mod_selfselectadvanced\local\notifier::purge_activity_digests((int) $cm->instance);
        // Auto-grouping logs hold raw user ids in their JSON body and
        // the triggering manager's id beside it. This method is the one
        // deletion path that is meant to be unconditional, and it did
        // not mention this table at all - so a purged context kept a
        // full record of who was placed where, and by whom.
        $DB->delete_records('selfselectadvanced_agrun', ['activityid' => $cm->instance]);
        $DB->delete_records('selfselectadvanced_eoi', ['activityid' => $cm->instance]);
        // The trail first, while it can still be found by ticketid - a
        // full context purge is unconditional, exactly like the ticket
        // row deletion beside it, so nothing here is left to a cascade
        // the database may or may not enforce.
        $DB->delete_records_select(
            'selfselectadvanced_ticketlog',
            'ticketid IN (SELECT id FROM {selfselectadvanced_ticket} WHERE activityid = ?)',
            [$cm->instance]
        );
        // 1.20.44: both ticket file areas, wholesale - a full context
        // purge is unconditional, and every itemid they could ever carry
        // (ticket ids, ticketlog ids) belongs to THIS context alone, so
        // there is no itemid to enumerate: the whole filearea goes.
        $fs = $fs ?? get_file_storage();
        $fs->delete_area_files($context->id, 'mod_selfselectadvanced', 'ticketrequest');
        $fs->delete_area_files($context->id, 'mod_selfselectadvanced', 'ticketpost');
        $DB->delete_records('selfselectadvanced_ticket', ['activityid' => $cm->instance]);
        $DB->delete_records('selfselectadvanced_contact', ['activityid' => $cm->instance]);
        // NULL, not 0. The schema says leaderid names a real user, so writing
        // 0 asserted that user zero leads every group in the activity. A
        // purged group has a leadership VACANCY, which is a state the plugin
        // now has words for.
        $DB->set_field('selfselectadvanced_group', 'leaderid', null, ['activityid' => $cm->instance]);
        $DB->execute(
            'UPDATE {selfselectadvanced_member} SET isleader = 0
              WHERE groupid IN (SELECT id FROM {selfselectadvanced_group} WHERE activityid = :activityid)',
            ['activityid' => $cm->instance]
        );
        $DB->set_field('selfselectadvanced_group', 'guideid', null, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'successorid', null, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'guidesuccessorid', null, ['activityid' => $cm->instance]);
        // The group structure stays (course data), but the prose a
        // guide wrote about the people being purged is declared
        // personal content, and the modifier column is somebody's id.
        // This unconditional path used to blank the four role ids above
        // and keep all three of these.
        $DB->set_field('selfselectadvanced_group', 'guidenotes', null, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'returncomment', null, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'usermodified', 0, ['activityid' => $cm->instance]);
        // The knowledgebank (1.20.45): the SAME "content stays, modifier
        // de-links" treatment as the group row just above - a published
        // FAQ is reusable course content, not the erased person's own
        // narrative, so a full context purge blanks the two annotated
        // columns rather than deleting every article.
        $DB->set_field('selfselectadvanced_kb', 'usercreated', 0, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_kb', 'usermodified', 0, ['activityid' => $cm->instance]);

        // Empty every mirror of every plugin-owned membership. The cost
        // per group equals the roster being purged - unavoidable, and
        // off the web path (privacy cron context).
        if ($mirroredgroups) {
            $activity = self::activity_or_null((int) $cm->instance);
            if ($activity !== null) {
                foreach ($mirroredgroups as $mirroredgroup) {
                    \mod_selfselectadvanced\local\freeze::sync_core_group(
                        $activity,
                        (int) $mirroredgroup->id,
                        (int) get_admin()->id,
                        $priormembers[(int) $mirroredgroup->id] ?? []
                    );
                }
            }
        }
    }

    /**
     * Delete one user's data in the approved contexts (M1: blanked
     * leaders route the group to the flagged report).
     *
     * @param approved_contextlist $contextlist approved contexts
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                \mod_selfselectadvanced\local\attributes\manager::delete_for_user($userid);
                // Their id also sits on every attribute record they
                // edited for someone else; the record is the subject's
                // data and stays, the editor's id does not.
                $DB->set_field('selfselectadvanced_userattr', 'usermodified', 0, ['usermodified' => $userid]);
                continue;
            }
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('selfselectadvanced', $context->instanceid);
            if (!$cm) {
                continue;
            }
            self::scrub_user_in_activity((int) $cm->instance, $userid);
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist approved users
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            if ($context instanceof \context_system) {
                \mod_selfselectadvanced\local\attributes\manager::delete_for_user((int) $userid);
                // Same de-link as the contextlist path: the records they
                // edited stay with their subjects, minus the editor's id.
                $DB->set_field('selfselectadvanced_userattr', 'usermodified', 0, ['usermodified' => (int) $userid]);
                continue;
            }
            if ($context instanceof \context_module) {
                $cm = get_coursemodule_from_id('selfselectadvanced', $context->instanceid);
                if ($cm) {
                    self::scrub_user_in_activity((int) $cm->instance, (int) $userid);
                }
            }
        }
    }

    /**
     * Remove one user's rows and de-link their ids in one activity.
     *
     * @param int $activityid the instance
     * @param int $userid the user
     */
    private static function scrub_user_in_activity(int $activityid, int $userid): void {
        global $DB;

        // Audit round 3 item 2: the proposal document of a group this
        // user led is their uploaded content - remove it. Groups they
        // merely belonged to keep theirs (shared group data).
        $ledgroups = $DB->get_records('selfselectadvanced_group', [
            'activityid' => $activityid,
            'leaderid' => $userid,
        ], '', 'id');
        if ($ledgroups) {
            [, $cmled] = get_course_and_cm_from_instance($activityid, 'selfselectadvanced');
            $fs = get_file_storage();
            foreach (array_keys($ledgroups) as $ledgroupid) {
                $fs->delete_area_files(
                    \context_module::instance($cmled->id)->id,
                    'mod_selfselectadvanced',
                    'proposal',
                    (int) $ledgroupid
                );
            }
        }

        // T-16: the mirrored course groups this erasure has to reach.
        // Collected BEFORE the member rows go, because afterwards the
        // ownership discriminator has nothing left to classify - which
        // is exactly what sync_core_group()'s $forceremove is for.
        $mirrored = array_map('intval', $DB->get_fieldset_sql(
            "SELECT g.id
               FROM {selfselectadvanced_group} g
               JOIN {selfselectadvanced_member} m ON m.groupid = g.id AND m.userid = :userid
              WHERE g.activityid = :activityid AND g.coregroupid IS NOT NULL",
            ['userid' => $userid, 'activityid' => $activityid]
        ));
        $mirrored = array_merge($mirrored, array_map('intval', $DB->get_fieldset_select(
            'selfselectadvanced_group',
            'id',
            'activityid = ? AND guideid = ? AND coregroupid IS NOT NULL',
            [$activityid, $userid]
        )));
        $mirrored = array_values(array_unique($mirrored));

        $groupids = $DB->get_fieldset_select('selfselectadvanced_group', 'id', 'activityid = ?', [$activityid]);
        if ($groupids) {
            [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $DB->delete_records_select(
                'selfselectadvanced_member',
                "groupid $insql AND userid = :userid",
                $params
            );

            // Their own member rows are gone, but their id survived on
            // every teammate they invited. De-link it: the invitation is
            // course history worth keeping, the erased person's id is
            // not.
            $DB->set_field_select(
                'selfselectadvanced_member',
                'invitedby',
                null,
                "groupid $insql AND invitedby = :userid",
                $params
            );

            // Scrub snapshots of the user.
            foreach ($DB->get_records_select('selfselectadvanced_snapshot', "groupid $insql", $params) as $snapshot) {
                $roster = json_decode($snapshot->roster, true) ?: [];
                $filtered = array_values(array_filter($roster, static fn($e) => (int) $e['userid'] !== $userid));
                if (count($filtered) !== count($roster)) {
                    $DB->set_field(
                        'selfselectadvanced_snapshot',
                        'roster',
                        json_encode($filtered),
                        ['id' => $snapshot->id]
                    );
                }
                if ((int) $snapshot->takenby === $userid) {
                    $DB->set_field('selfselectadvanced_snapshot', 'takenby', 0, ['id' => $snapshot->id]);
                }
            }
        }
        // The staff member who GRANTED an exception is a different
        // person from its target. Target rows are deleted further down;
        // this de-links the grantor, whose id otherwise outlived their
        // own erasure on every override they ever wrote - including the
        // group- and move-scope ones that are never deleted at all.
        $DB->set_field(
            'selfselectadvanced_override',
            'usermodified',
            0,
            ['activityid' => $activityid, 'usermodified' => $userid]
        );
        $DB->set_field(
            'selfselectadvanced_group',
            'usermodified',
            0,
            ['activityid' => $activityid, 'usermodified' => $userid]
        );
        // The knowledgebank (1.20.45): the annotated-columns idiom this
        // whole method is built on, applied to BOTH columns kb declares -
        // usercreated and usermodified individually, since one person can
        // be either, neither or both on a given row.
        $DB->set_field(
            'selfselectadvanced_kb',
            'usercreated',
            0,
            ['activityid' => $activityid, 'usercreated' => $userid]
        );
        $DB->set_field(
            'selfselectadvanced_kb',
            'usermodified',
            0,
            ['activityid' => $activityid, 'usermodified' => $userid]
        );
        // An automatic-grouping run started by hand records who started
        // it. The log body is pseudonymised below; this is the column
        // beside it, which nothing touched.
        $DB->set_field(
            'selfselectadvanced_agrun',
            'triggeredby',
            0,
            ['activityid' => $activityid, 'triggeredby' => $userid]
        );

        // An erased leader leaves a leadership VACANCY - NULL, never 0. The
        // flagged report still surfaces it, and staff repair it explicitly;
        // nobody is promoted in the erased person's place, because leadership
        // carries authority and grade attribution.
        $vacated = $DB->get_fieldset_select(
            'selfselectadvanced_group',
            'id',
            'activityid = :activityid AND leaderid = :leaderid',
            ['activityid' => $activityid, 'leaderid' => $userid]
        );
        if ($vacated !== []) {
            [$insql, $inparams] = $DB->get_in_or_equal($vacated, SQL_PARAMS_NAMED, 'vg');
            $DB->set_field_select('selfselectadvanced_group', 'leaderid', null, 'id ' . $insql, $inparams);
            $DB->set_field_select('selfselectadvanced_group', 'successorid', null, 'id ' . $insql, $inparams);
            $DB->set_field_select('selfselectadvanced_group', 'successortype', null, 'id ' . $insql, $inparams);
            $DB->set_field_select('selfselectadvanced_group', 'timenominated', null, 'id ' . $insql, $inparams);
            $flagparams = $inparams + ['erased' => $userid];
            $DB->set_field_select(
                'selfselectadvanced_member',
                'isleader',
                0,
                'groupid ' . $insql . ' AND userid = :erased',
                $flagparams
            );
        }
        $DB->set_field(
            'selfselectadvanced_group',
            'guideid',
            null,
            ['activityid' => $activityid, 'guideid' => $userid]
        );
        $DB->set_field(
            'selfselectadvanced_group',
            'successorid',
            null,
            ['activityid' => $activityid, 'successorid' => $userid]
        );
        $DB->set_field(
            'selfselectadvanced_group',
            'guidesuccessorid',
            null,
            ['activityid' => $activityid, 'guidesuccessorid' => $userid]
        );
        $DB->delete_records_select(
            'selfselectadvanced_move',
            'activityid = :activityid AND (userid = :u1 OR successorid = :u2)',
            ['activityid' => $activityid, 'u1' => $userid, 'u2' => $userid]
        );
        // Moves they staged or answered for OTHER students are those
        // students' records and stay; the erased stager's id on them
        // does not. Same shape as the override/group grantor de-links
        // above - this was the one modifier column nothing scrubbed.
        $DB->set_field(
            'selfselectadvanced_move',
            'usermodified',
            0,
            ['activityid' => $activityid, 'usermodified' => $userid]
        );
        $DB->delete_records_select(
            'selfselectadvanced_override',
            "activityid = :activityid AND scope IN ('user', 'guide') AND userid = :userid",
            ['activityid' => $activityid, 'userid' => $userid]
        );
        $DB->delete_records('selfselectadvanced_volunteer', ['activityid' => $activityid, 'userid' => $userid]);
        // EVERY QUEUED MESSAGE THIS PERSON IS PART OF, whether addressed to
        // them or merely about them, found by id through the subject relation
        // (1.20.35). The row is deleted WHOLE rather than scrubbed: the
        // payload is one already-resolved sentence narrating what they did,
        // excising a name from it leaves nonsense, and the queue is transient.
        //
        // This replaces a substring match on the rendered full name. That
        // match could not tell two people with one name apart, missed anybody
        // who had renamed since queueing, and had to reproduce the encoder's
        // JSON escaping to find an accented name at all - so it could
        // over-delete a namesake's message and under-delete the subject's own.
        // Identity is an id; it was never the text.
        $digestids = $DB->get_fieldset_sql(
            "SELECT DISTINCT dq.id
               FROM {selfselectadvanced_digestq} dq
          LEFT JOIN {selfselectadvanced_dqsubject} dqs ON dqs.digestid = dq.id
              WHERE dq.activityid = :activityid
                AND (dq.userid = :recipient OR dqs.userid = :subject)",
            ['activityid' => $activityid, 'recipient' => $userid, 'subject' => $userid]
        );
        // The WHOLE message goes, index included - not only this person's
        // index row, which would be left pointing at a deleted message.
        \mod_selfselectadvanced\local\notifier::purge_digests($digestids);
        // The interest history is the guide's own personal content
        // (remarks) and identity; deleted outright, exactly like a
        // member row, rather than de-linked (nothing else references
        // an eoi row by id).
        $DB->delete_records('selfselectadvanced_eoi', ['activityid' => $activityid, 'guideid' => $userid]);

        // Tickets: the request text is the requester's own content, so
        // their tickets go outright, like an eoi row. Where the user
        // only handled a ticket, the row is the requester's record: the
        // handler is de-linked and their resolution prose scrubbed; a
        // half-worked claim is released back to the queue.
        //
        // The trail goes WITH the ticket, and has to be found before the
        // ticket row it points at is deleted out from under it.
        //
        // 1.20.44: the two ticket file areas follow the SAME policy as
        // the text they travel beside - a requester purge deletes files
        // (ticketrequest with the ticket, ticketpost with the trail rows
        // under it), a handler de-link scrubs only the trail rows THIS
        // person authored (needs-info questions, resolutions) and their
        // files with them, never a ticket or its opening attachment that
        // belongs to somebody else's request.
        [, $cmticket] = get_course_and_cm_from_instance($activityid, 'selfselectadvanced');
        $ticketcontextid = \context_module::instance($cmticket->id)->id;
        $fs = $fs ?? get_file_storage();
        $ownticketids = $DB->get_fieldset_select(
            'selfselectadvanced_ticket',
            'id',
            'activityid = ? AND requestedby = ?',
            [$activityid, $userid]
        );
        if ($ownticketids) {
            [$ownticketinsql, $ownticketparams] = $DB->get_in_or_equal($ownticketids, SQL_PARAMS_QM);
            $ownlogids = $DB->get_fieldset_select(
                'selfselectadvanced_ticketlog',
                'id',
                "ticketid $ownticketinsql",
                $ownticketparams
            );
            $DB->delete_records_select('selfselectadvanced_ticketlog', "ticketid $ownticketinsql", $ownticketparams);
            foreach ($ownlogids as $ownlogid) {
                $fs->delete_area_files($ticketcontextid, 'mod_selfselectadvanced', 'ticketpost', (int) $ownlogid);
            }
            foreach ($ownticketids as $ownticketid) {
                $fs->delete_area_files($ticketcontextid, 'mod_selfselectadvanced', 'ticketrequest', (int) $ownticketid);
            }
        }
        $DB->delete_records('selfselectadvanced_ticket', ['activityid' => $activityid, 'requestedby' => $userid]);
        // Both live claimed states (audit B7/M-7/M-10), not CLAIMED alone:
        // NEEDSINFO is a claimed-and-live state everywhere else in the
        // plugin (decision 2, "counts as LIVE everywhere open and claimed
        // already did"), and the scrub's own stated intent is "a half-
        // worked claim is released back to the queue" - leaving a
        // needs-info ticket claimed by nobody is the one state that has
        // no exit: the requester's next reply would go to user 0.
        $DB->execute(
            "UPDATE {selfselectadvanced_ticket}
                SET claimedby = NULL, timeclaimed = NULL,
                    status = CASE WHEN status IN (:claimed, :needsinfo) THEN :open ELSE status END
              WHERE activityid = :activityid AND claimedby = :userid",
            [
                'claimed' => \mod_selfselectadvanced\local\tickets::STATUS_CLAIMED,
                'needsinfo' => \mod_selfselectadvanced\local\tickets::STATUS_NEEDSINFO,
                'open' => \mod_selfselectadvanced\local\tickets::STATUS_OPEN,
                'activityid' => $activityid,
                'userid' => $userid,
            ]
        );
        $DB->execute(
            "UPDATE {selfselectadvanced_ticket}
                SET resolvedby = NULL, resolution = NULL
              WHERE activityid = :activityid AND resolvedby = :userid",
            ['activityid' => $activityid, 'userid' => $userid]
        );
        // The trail entries this person wrote as a HANDLER, on tickets
        // that remain (their own filed tickets and trail were removed
        // above): actorid cannot be nulled - it is NOT NULL by design,
        // exactly like takenby/triggeredby/usermodified elsewhere in
        // this method - so it is de-linked to the same 0 sentinel they
        // use, and the note text they authored (a needs-info question, a
        // resolution note) is scrubbed alongside it, mirroring
        // resolution's own scrub immediately above.
        //
        // The FILE, if that same trail row carries one, is deliberately
        // NOT deleted here (spec, verbatim: "handler de-link does not"
        // delete files) - only a REQUESTER purge deletes ticket files,
        // exactly like $ownlogids above; this is the ticket-attachment
        // reading of the same asymmetry the resolution-text scrub two
        // statements up already draws between "their content" (nulled)
        // and "the requester's ticket" (never touched by a handler's
        // own erasure).
        $DB->execute(
            "UPDATE {selfselectadvanced_ticketlog}
                SET actorid = 0, note = NULL
              WHERE actorid = :userid
                AND ticketid IN (SELECT id FROM {selfselectadvanced_ticket} WHERE activityid = :activityid)",
            ['userid' => $userid, 'activityid' => $activityid]
        );

        // An approach a person sent is their own words, so it goes with
        // them; where they were the guide approached, the row belongs to
        // the team that sent it, so only the answer they wrote is
        // scrubbed and the approach itself stays.
        $DB->delete_records('selfselectadvanced_contact', ['activityid' => $activityid, 'sentby' => $userid]);
        $DB->execute(
            "UPDATE {selfselectadvanced_contact}
                SET reason = NULL
              WHERE activityid = :activityid AND guideid = :userid",
            ['activityid' => $activityid, 'userid' => $userid]
        );

        // Pseudonymise agrun logs, type-aware (PRIV-002). The log's own
        // shape says which leaves are userids: 'pool' and 'residue' are
        // arrays of them, and each 'groups' entry carries a 'leaderid'
        // and a 'members' array. Nothing else is one - 'bypassedrules'
        // holds quota-rule ids and 'pluginuid' is a string. The old
        // code int-cast EVERY leaf and zeroed any numeric equal to the
        // erased id, so erasing user 7 falsified a trail that had
        // merely relaxed rule 7, and erasing user 42 turned a '0042'
        // uid into the integer 0. Only integer-typed values in the
        // identity positions are replaced now; a colliding rule id or
        // digit-led string passes through untouched, and a log with
        // nothing to replace is not rewritten at all.
        foreach ($DB->get_records('selfselectadvanced_agrun', ['activityid' => $activityid]) as $agrun) {
            if (!$agrun->log) {
                continue;
            }
            $decoded = json_decode($agrun->log, true);
            if (!is_array($decoded)) {
                continue;
            }
            $changed = false;
            $zeroids = static function (array $ids) use ($userid, &$changed): array {
                foreach ($ids as $key => $value) {
                    if (is_int($value) && $value === $userid) {
                        $ids[$key] = 0;
                        $changed = true;
                    }
                }

                return $ids;
            };
            foreach (['pool', 'residue'] as $listkey) {
                if (isset($decoded[$listkey]) && is_array($decoded[$listkey])) {
                    $decoded[$listkey] = $zeroids($decoded[$listkey]);
                }
            }
            if (isset($decoded['groups']) && is_array($decoded['groups'])) {
                foreach ($decoded['groups'] as $index => $grouplog) {
                    if (!is_array($grouplog)) {
                        continue;
                    }
                    if (is_int($grouplog['leaderid'] ?? null) && $grouplog['leaderid'] === $userid) {
                        $decoded['groups'][$index]['leaderid'] = 0;
                        $changed = true;
                    }
                    if (isset($grouplog['members']) && is_array($grouplog['members'])) {
                        $decoded['groups'][$index]['members'] = $zeroids($grouplog['members']);
                    }
                }
            }
            if ($changed) {
                $DB->set_field('selfselectadvanced_agrun', 'log', json_encode($decoded), ['id' => $agrun->id]);
            }
        }

        // The erased person must leave the course group too, or the
        // mirror keeps a membership row for someone the plugin no
        // longer knows (D7-F1).
        //
        // CORRECTED 2026-08-02 (audit O-5). This used to say "where an
        // ambient transaction exists, the deferral guard hands the work
        // to the queued adhoc instead". There is no such guard and
        // there is no such queue on this path. The
        // is_transaction_started() deferral it referred to was REMOVED
        // in 1.20 (requirement 6), because that flag is unconditionally
        // true under PHPUnit on PostgreSQL and false on MariaDB, so
        // branching behaviour on it shipped one defect and would have
        // shipped more. Nothing here queues an adhoc task; the call
        // below runs inline, transaction or no transaction.
        //
        // What is actually true, and why that is safe. The deletion
        // paths run from core's privacy request task or a CLI/admin
        // request, neither of which wraps this class in a transaction
        // of its own, and this method opens none. If a caller did have
        // one open, core buffers the events and messages
        // sync_core_group() produces and discards the buffers on
        // database_transaction_rolledback, so nothing escapes a
        // rollback - the cost is buffering, not a leak, and
        // freeze::sync_core_group() carries the measurement for that
        // claim in its own comment. The ONLY adhoc on this path is the
        // retry freeze::request_sync() queues when a sync FAILS, queued
        // by freeze and never by this class, and deduped against an
        // identical pending row.
        if ($mirrored) {
            $activity = self::activity_or_null($activityid);
            if ($activity !== null) {
                foreach ($mirrored as $mirroredgroupid) {
                    \mod_selfselectadvanced\local\freeze::sync_core_group(
                        $activity,
                        $mirroredgroupid,
                        (int) get_admin()->id,
                        [$userid]
                    );
                }
            }
        }
    }

    /**
     * The activity wrapper for an instance id, or null when the
     * instance or its course module has gone.
     *
     * @param int $activityid the instance id
     * @return \mod_selfselectadvanced\activity|null
     */
    private static function activity_or_null(int $activityid): ?\mod_selfselectadvanced\activity {
        try {
            return \mod_selfselectadvanced\activity::from_instance($activityid);
        } catch (\dml_missing_record_exception | \moodle_exception $e) {
            return null;
        }
    }
}
