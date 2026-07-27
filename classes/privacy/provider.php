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
 * member rows and de-links ids; a deletion that blanks a leader routes
 * the group to the flagged report via its empty leaderid (review item
 * M1). Snapshots are scrubbed of the user; agrun logs are
 * pseudonymised. Queued digest rows are deleted outright: they hold no
 * audit value once the recipient is gone.
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
        ], 'privacy:metadata:member');
        $collection->add_database_table('selfselectadvanced_group', [
            'leaderid' => 'privacy:metadata:group:leaderid',
            'guideid' => 'privacy:metadata:group:guideid',
            'successorid' => 'privacy:metadata:group:successorid',
            'guidesuccessorid' => 'privacy:metadata:group:guidesuccessorid',
            'brief' => 'privacy:metadata:group:brief',
        ], 'privacy:metadata:group');
        $collection->add_database_table('selfselectadvanced_userattr', [
            'userid' => 'privacy:metadata:userattr:userid',
            'gender' => 'privacy:metadata:userattr:gender',
            'department' => 'privacy:metadata:userattr:department',
            'subdepartment' => 'privacy:metadata:userattr:subdepartment',
            'mobile' => 'privacy:metadata:userattr:mobile',
            'shareconsent' => 'privacy:metadata:userattr:shareconsent',
        ], 'privacy:metadata:userattr');
        $collection->add_database_table('selfselectadvanced_override', [
            'userid' => 'privacy:metadata:override:userid',
        ], 'privacy:metadata:override');
        $collection->add_database_table('selfselectadvanced_volunteer', [
            'userid' => 'privacy:metadata:volunteer:userid',
            'capacity' => 'privacy:metadata:volunteer:capacity',
        ], 'privacy:metadata:volunteer');
        $collection->add_database_table('selfselectadvanced_move', [
            'userid' => 'privacy:metadata:move:userid',
            'successorid' => 'privacy:metadata:move:successorid',
        ], 'privacy:metadata:move');
        $collection->add_database_table('selfselectadvanced_snapshot', [
            'roster' => 'privacy:metadata:snapshot:roster',
            'takenby' => 'privacy:metadata:snapshot:takenby',
        ], 'privacy:metadata:snapshot');
        $collection->add_database_table('selfselectadvanced_agrun', [
            'log' => 'privacy:metadata:agrun:log',
        ], 'privacy:metadata:agrun');
        $collection->add_database_table('selfselectadvanced_digestq', [
            'userid' => 'privacy:metadata:digestq:userid',
            'payload' => 'privacy:metadata:digestq:payload',
        ], 'privacy:metadata:digestq');
        $collection->add_database_table('selfselectadvanced_eoi', [
            'guideid' => 'privacy:metadata:eoi:guideid',
            'remarks' => 'privacy:metadata:eoi:remarks',
            'status' => 'privacy:metadata:eoi:status',
        ], 'privacy:metadata:eoi');
        $collection->add_user_preference(
            'mod_selfselectadvanced_reminded_',
            'privacy:metadata:preference:reminded'
        );
        $collection->add_user_preference(
            'mod_selfselectadvanced_digest',
            'privacy:metadata:preference:digest'
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
                        SELECT 1 FROM {selfselectadvanced_eoi} eo
                         WHERE eo.activityid = a.id AND eo.guideid = :userid10)";
        $contextlist->add_from_sql($sql, [
            'modlevel' => CONTEXT_MODULE,
            'userid1' => $userid, 'userid2' => $userid, 'userid3' => $userid, 'userid4' => $userid,
            'userid5' => $userid, 'userid6' => $userid, 'userid7' => $userid, 'userid8' => $userid,
            'userid9' => $userid, 'userid10' => $userid, 'userid11' => $userid,
        ]);

        global $DB;
        if ($DB->record_exists('selfselectadvanced_userattr', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Users present in one context.
     *
     * @param userlist $userlist the userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {selfselectadvanced_userattr}', []);

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
        $userlist->add_from_sql(
            'userid',
            "SELECT eo.guideid AS userid
               FROM {selfselectadvanced_eoi} eo
               JOIN {course_modules} cm ON cm.instance = eo.activityid AND cm.id = :cmid",
            $params
        );
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
                if ($attr) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'mod_selfselectadvanced'),
                            get_string('participantattributes', 'mod_selfselectadvanced')],
                        (object) [
                            'gender' => $attr->gender,
                            'department' => $attr->department,
                            'subdepartment' => $attr->subdepartment,
                            'mobile' => $attr->mobile,
                            'shareconsent' => (int) ($attr->shareconsent ?? 0),
                        ]
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
                "SELECT m.id, g.name, g.pluginuid, g.title, g.brief, g.state, m.status, m.isleader,
                        m.timeinvited, m.timeresponded, p.penaltyvalue
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
                    'grouppenalty' => $row->penaltyvalue,
                ];
            }
            $overrides = $DB->get_records('selfselectadvanced_override', [
                'activityid' => $cm->instance,
                'userid' => $userid,
            ]);
            $moves = $DB->get_records_select(
                'selfselectadvanced_move',
                'activityid = :activityid AND (userid = :u1 OR successorid = :u2)',
                ['activityid' => $cm->instance, 'u1' => $userid, 'u2' => $userid]
            );
            $volunteer = $DB->get_record('selfselectadvanced_volunteer', [
                'activityid' => $cm->instance,
                'userid' => $userid,
            ]);
            $digestqueue = $DB->get_records('selfselectadvanced_digestq', [
                'activityid' => $cm->instance,
                'userid' => $userid,
            ], 'timecreated ASC');
            $eois = $DB->get_records_sql(
                "SELECT eo.id, g.name, g.pluginuid, eo.status, eo.remarks, eo.remarksformat,
                        eo.timecreated, eo.timeresponded
                   FROM {selfselectadvanced_eoi} eo
                   JOIN {selfselectadvanced_group} g ON g.id = eo.groupid
                  WHERE eo.activityid = :activityid AND eo.guideid = :userid",
                ['activityid' => $cm->instance, 'userid' => $userid]
            );
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'mod_selfselectadvanced')],
                (object) [
                    'memberships' => $memberships,
                    'interests' => array_values(array_map(static fn($eo) => (object) [
                        'group' => format_string($eo->name),
                        'pluginuid' => $eo->pluginuid,
                        'status' => $eo->status,
                        'remarks' => format_text($eo->remarks, $eo->remarksformat, ['context' => $context]),
                        'timecreated' => transform::datetime($eo->timecreated),
                        'timeresponded' => $eo->timeresponded ? transform::datetime($eo->timeresponded) : null,
                    ], $eois)),
                    'overrides' => array_values(array_map(static fn($o) => (object) [
                        'scope' => $o->scope,
                        'timeopen' => $o->timeopen,
                        'timedue' => $o->timedue,
                        'timecutoff' => $o->timecutoff,
                        'maxlead' => $o->maxlead,
                        'maxmembership' => $o->maxmembership,
                        'maxguided' => $o->maxguided,
                    ], $overrides)),
                    'moves' => array_values(array_map(static fn($m) => (object) [
                        'status' => $m->status,
                        'timecreated' => transform::datetime($m->timecreated),
                    ], $moves)),
                    'volunteer' => $volunteer ? (object) [
                        'capacity' => $volunteer->capacity,
                        'timemodified' => transform::datetime($volunteer->timemodified),
                    ] : null,
                    'digestqueue' => array_values(array_map(static fn($dq) => (object) [
                        'provider' => $dq->provider,
                        'timecreated' => transform::datetime($dq->timecreated),
                    ], $digestqueue)),
                ]
            );
        }
    }

    /**
     * Export reminder preferences and the site-wide notification
     * digest preference.
     *
     * @param int $userid the user
     */
    public static function export_user_preferences(int $userid): void {
        global $DB;

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
        $DB->delete_records('selfselectadvanced_digestq', ['activityid' => $cm->instance]);
        $DB->delete_records('selfselectadvanced_eoi', ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'leaderid', 0, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'guideid', null, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'successorid', null, ['activityid' => $cm->instance]);
        $DB->set_field('selfselectadvanced_group', 'guidesuccessorid', null, ['activityid' => $cm->instance]);
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
        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            if ($context instanceof \context_system) {
                \mod_selfselectadvanced\local\attributes\manager::delete_for_user((int) $userid);
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

        $groupids = $DB->get_fieldset_select('selfselectadvanced_group', 'id', 'activityid = ?', [$activityid]);
        if ($groupids) {
            [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $DB->delete_records_select(
                'selfselectadvanced_member',
                "groupid $insql AND userid = :userid",
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
        // M1: a blanked leader leaves the group leaderless -> flagged report.
        $DB->set_field(
            'selfselectadvanced_group',
            'leaderid',
            0,
            ['activityid' => $activityid, 'leaderid' => $userid]
        );
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
        $DB->delete_records_select(
            'selfselectadvanced_override',
            "activityid = :activityid AND scope IN ('user', 'guide') AND userid = :userid",
            ['activityid' => $activityid, 'userid' => $userid]
        );
        $DB->delete_records('selfselectadvanced_volunteer', ['activityid' => $activityid, 'userid' => $userid]);
        $DB->delete_records('selfselectadvanced_digestq', ['activityid' => $activityid, 'userid' => $userid]);
        // The interest history is the guide's own personal content
        // (remarks) and identity; deleted outright, exactly like a
        // member row, rather than de-linked (nothing else references
        // an eoi row by id).
        $DB->delete_records('selfselectadvanced_eoi', ['activityid' => $activityid, 'guideid' => $userid]);

        // Pseudonymise agrun logs.
        foreach ($DB->get_records('selfselectadvanced_agrun', ['activityid' => $activityid]) as $agrun) {
            $log = $agrun->log;
            if ($log && strpos($log, (string) $userid) !== false) {
                $decoded = json_decode($log, true) ?: [];
                array_walk_recursive($decoded, static function (&$value) use ($userid) {
                    if ((int) $value === $userid) {
                        $value = 0;
                    }
                });
                $DB->set_field('selfselectadvanced_agrun', 'log', json_encode($decoded), ['id' => $agrun->id]);
            }
        }
    }
}
