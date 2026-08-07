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
 * Upgrade steps for mod_selfselectadvanced.
 *
 * Versioned from day one (spec section 15.4): the plugin must upgrade
 * cleanly from any released version as well as install cleanly.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Merge duplicate override rows, keeping the row the read path reads.
 *
 * Override rows are unique per (activity, scope, target) by convention
 * in store::save() alone, which before 1.19.2 was a read-then-insert
 * with neither a lock nor an index behind it, so concurrent saves
 * created twins. No schema change can express the invariant: the four
 * scopes target four NULLABLE columns and NULLs are distinct in a
 * unique index on both PostgreSQL and MariaDB.
 *
 * The keeper is the OLDEST ACTIVE row, falling back to the oldest row
 * when none is active - the same preference resolver::load_overrides()
 * and store::get() apply, so the merge can never delete the exception
 * a site is actually running on. COALESCE over a conditional MIN is
 * portable to both engines.
 *
 * Raw SQL only, by design: nothing here may call a plugin class that
 * queries a plugin table (upgrade-safety rule).
 *
 * @param moodle_database $DB the database
 * @return int how many rows were deleted
 */
function selfselectadvanced_upgrade_merge_override_twins(moodle_database $DB): int {
    $duplicates = $DB->get_records_sql(
        "SELECT COALESCE(MIN(CASE WHEN status = :active THEN id END), MIN(id)) AS keepid,
                COUNT(id) AS dupcount, activityid, scope,
                COALESCE(userid, 0) AS uid, COALESCE(groupid, 0) AS gid, COALESCE(moveid, 0) AS mid
           FROM {selfselectadvanced_override}
       GROUP BY activityid, scope, COALESCE(userid, 0), COALESCE(groupid, 0), COALESCE(moveid, 0)
         HAVING COUNT(id) > 1",
        ['active' => 'active']
    );
    $deleted = 0;
    foreach ($duplicates as $dup) {
        $deleted += (int) $dup->dupcount - 1;
        $DB->delete_records_select(
            'selfselectadvanced_override',
            'activityid = :activityid AND scope = :scope AND id <> :keepid
               AND COALESCE(userid, 0) = :uid AND COALESCE(groupid, 0) = :gid
               AND COALESCE(moveid, 0) = :mid',
            [
                'activityid' => $dup->activityid,
                'scope' => $dup->scope,
                'keepid' => $dup->keepid,
                'uid' => $dup->uid,
                'gid' => $dup->gid,
                'mid' => $dup->mid,
            ]
        );
    }

    return $deleted;
}

/**
 * Execute an upgrade from the given old version.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_selfselectadvanced_upgrade($oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    // PHP 8.4 is the declared minimum from 1.20.1 onward. Moodle has no
    // version.php field for a PHP floor - core parses version, requires,
    // supported, incompatible, release, maturity and dependencies, and
    // nothing else - so the floor has to be asserted where the plugin runs.
    // It is asserted HERE, before any savepoint, so a refusal leaves nothing
    // half-applied; db/install.php makes the same check for a fresh install.
    if (version_compare(PHP_VERSION, '8.4.0', '<')) {
        throw new moodle_exception('errorphptoolow', 'mod_selfselectadvanced', '', PHP_VERSION);
    }

    // First public schema is 2026072400; upgrade steps accumulate below
    // with upgrade_mod_savepoint() calls as the plugin evolves.

    if ($oldversion < 2026072401) {
        // Slice 2: external function, message providers and the
        // invitation expiry task registered from their db/ files; no
        // schema change.
        upgrade_mod_savepoint(true, 2026072401, 'selfselectadvanced');
    }

    if ($oldversion < 2026072402) {
        // Slice 3: nomination and nominationresult message providers.
        upgrade_mod_savepoint(true, 2026072402, 'selfselectadvanced');
    }

    if ($oldversion < 2026072403) {
        // Slice 4: guidequeue, groupreturned and groupapproved
        // message providers.
        upgrade_mod_savepoint(true, 2026072403, 'selfselectadvanced');
    }

    if ($oldversion < 2026072404) {
        // Slice 5: attribute value cache definition and the
        // user_deleted observer.
        upgrade_mod_savepoint(true, 2026072404, 'selfselectadvanced');
    }

    if ($oldversion < 2026072405) {
        // Slice 8: movecommitted message provider.
        upgrade_mod_savepoint(true, 2026072405, 'selfselectadvanced');
    }

    if ($oldversion < 2026072406) {
        // Slice 9: reconcile_penalties scheduled task.
        upgrade_mod_savepoint(true, 2026072406, 'selfselectadvanced');
    }

    if ($oldversion < 2026072407) {
        // Slice 10: groupfrozen and groupunfrozen message providers.
        upgrade_mod_savepoint(true, 2026072407, 'selfselectadvanced');
    }

    if ($oldversion < 2026072408) {
        // Slice 12: run_autogrouping scheduled task.
        upgrade_mod_savepoint(true, 2026072408, 'selfselectadvanced');
    }

    if ($oldversion < 2026072409) {
        // Slice 13: leaverequest/leaveresult/deadlinereminder message
        // providers and the deadline_reminder task.
        upgrade_mod_savepoint(true, 2026072409, 'selfselectadvanced');
    }

    if ($oldversion < 2026072410) {
        // 1.0.0 release: documentation and maturity only.
        upgrade_mod_savepoint(true, 2026072410, 'selfselectadvanced');
    }

    if ($oldversion < 2026072411) {
        // 1.0.1: recipient placeholders in every notification template.
        upgrade_mod_savepoint(true, 2026072411, 'selfselectadvanced');
    }

    if ($oldversion < 2026072412) {
        // 1.1.0: department vocabulary tree + per-activity templates.
        $table = new xmldb_table('selfselectadvanced_dept');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('parent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('depth', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('path', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('parent', XMLDB_INDEX_NOTUNIQUE, ['parent']);
        $table->add_index('parentname', XMLDB_INDEX_UNIQUE, ['parent', 'name']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('selfselectadvanced_template');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('msgkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('subject', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('activityid', XMLDB_KEY_FOREIGN, ['activityid'], 'selfselectadvanced', ['id']);
        $table->add_index('activitymsgkey', XMLDB_INDEX_UNIQUE, ['activityid', 'msgkey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Seed the vocabulary from values already ingested so existing
        // data stays valid: distinct departments become top-level
        // categories, distinct (department, sub-department) pairs
        // become their children.
        $now = time();
        $seen = [];
        // Recordset, NOT get_records_sql: records keyed by the first
        // column would collapse duplicate departments to one pair.
        $pairs = $DB->get_recordset_sql(
            "SELECT DISTINCT department, subdepartment
               FROM {selfselectadvanced_userattr}
              WHERE department IS NOT NULL AND department <> ''"
        );
        foreach ($pairs as $pair) {
            $dept = trim((string) $pair->department);
            if ($dept === '' || core_text::strlen($dept) > 100) {
                continue;
            }
            if (!isset($seen[$dept])) {
                $id = $DB->get_field('selfselectadvanced_dept', 'id', ['parent' => 0, 'name' => $dept]);
                if (!$id) {
                    $id = $DB->insert_record('selfselectadvanced_dept', (object) [
                        'name' => $dept,
                        'parent' => 0,
                        'depth' => 1,
                        'path' => '',
                        'sortorder' => 1 + (int) $DB->count_records('selfselectadvanced_dept', ['parent' => 0]),
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                    $DB->set_field('selfselectadvanced_dept', 'path', '/' . $id, ['id' => $id]);
                }
                $seen[$dept] = (int) $id;
            }
            $sub = trim((string) $pair->subdepartment);
            if ($sub === '' || core_text::strlen($sub) > 100) {
                continue;
            }
            $parentid = $seen[$dept];
            if (!$DB->record_exists('selfselectadvanced_dept', ['parent' => $parentid, 'name' => $sub])) {
                $id = $DB->insert_record('selfselectadvanced_dept', (object) [
                    'name' => $sub,
                    'parent' => $parentid,
                    'depth' => 2,
                    'path' => '',
                    'sortorder' => 1 + (int) $DB->count_records('selfselectadvanced_dept', ['parent' => $parentid]),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->set_field('selfselectadvanced_dept', 'path', '/' . $parentid . '/' . $id, ['id' => $id]);
            }
        }

        $pairs->close();

        upgrade_mod_savepoint(true, 2026072412, 'selfselectadvanced');
    }

    if ($oldversion < 2026072413) {
        // Repair pass: 2026072412 first shipped with get_records_sql,
        // whose first-column keying collapsed duplicate departments —
        // sites that ran it are missing sub-departments. Re-seed
        // idempotently with the corrected recordset query.
        $now = time();
        $pairs = $DB->get_recordset_sql(
            "SELECT DISTINCT department, subdepartment
               FROM {selfselectadvanced_userattr}
              WHERE department IS NOT NULL AND department <> ''"
        );
        foreach ($pairs as $pair) {
            $dept = trim((string) $pair->department);
            if ($dept === '' || core_text::strlen($dept) > 100) {
                continue;
            }
            $parentid = $DB->get_field('selfselectadvanced_dept', 'id', ['parent' => 0, 'name' => $dept]);
            if (!$parentid) {
                $parentid = $DB->insert_record('selfselectadvanced_dept', (object) [
                    'name' => $dept,
                    'parent' => 0,
                    'depth' => 1,
                    'path' => '',
                    'sortorder' => 1 + (int) $DB->count_records('selfselectadvanced_dept', ['parent' => 0]),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->set_field('selfselectadvanced_dept', 'path', '/' . $parentid, ['id' => $parentid]);
            }
            $sub = trim((string) $pair->subdepartment);
            if ($sub === '' || core_text::strlen($sub) > 100) {
                continue;
            }
            if (!$DB->record_exists('selfselectadvanced_dept', ['parent' => $parentid, 'name' => $sub])) {
                $id = $DB->insert_record('selfselectadvanced_dept', (object) [
                    'name' => $sub,
                    'parent' => (int) $parentid,
                    'depth' => 2,
                    'path' => '',
                    'sortorder' => 1 + (int) $DB->count_records('selfselectadvanced_dept', ['parent' => $parentid]),
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $DB->set_field('selfselectadvanced_dept', 'path', '/' . $parentid . '/' . $id, ['id' => $id]);
            }
        }
        $pairs->close();

        upgrade_mod_savepoint(true, 2026072413, 'selfselectadvanced');
    }

    if ($oldversion < 2026072414) {
        // 1.2.0: guarded overrides (status), deliberate leader
        // replacement on moves, faculty seat location attribute.
        $table = new xmldb_table('selfselectadvanced_override');
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'active', 'rulesbypassed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('selfselectadvanced_move');
        $field = new xmldb_field('replaceleader', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'makeleader');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('selfselectadvanced_userattr');
        $field = new xmldb_field('seatlocation', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'mobile');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072414, 'selfselectadvanced');
    }

    if ($oldversion < 2026072415) {
        // 1.3.0: programme attribute + vocabulary kind, guide notes,
        // proposal mandate, slot-based composition templates.
        $table = new xmldb_table('selfselectadvanced_userattr');
        $field = new xmldb_field('program', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'seatlocation');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('selfselectadvanced_dept');
        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'dept', 'name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('selfselectadvanced_group');
        $field = new xmldb_field('guidenotes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'returncomment');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('guidenotesformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'guidenotes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('selfselectadvanced');
        $field = new xmldb_field('proposalrequired', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'autogroup');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('selfselectadvanced_qslot');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('slotno', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('mincount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('dimension', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('matchtype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL);
        $table->add_field('value', XMLDB_TYPE_CHAR, '100');
        $table->add_field('allowoverlap', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('activityid', XMLDB_KEY_FOREIGN, ['activityid'], 'selfselectadvanced', ['id']);
        $table->add_index('activityslot', XMLDB_INDEX_UNIQUE, ['activityid', 'slotno']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072415, 'selfselectadvanced');
    }

    if ($oldversion < 2026072416) {
        // 1.4.0: guide decision window, defaulters, incomplete-group
        // penalty with leader share, per-group awards.
        $table = new xmldb_table('selfselectadvanced');
        $adds = [
            ['guidewindow', XMLDB_TYPE_INTEGER, '10', '0', 'proposalrequired'],
            ['guideautoapprove', XMLDB_TYPE_INTEGER, '1', '0', 'guidewindow'],
            ['minmembership', XMLDB_TYPE_INTEGER, '10', '0', 'guideautoapprove'],
            ['leadershare', XMLDB_TYPE_INTEGER, '3', '60', 'minmembership'],
        ];
        foreach ($adds as [$name, $type, $len, $default, $after]) {
            $field = new xmldb_field($name, $type, $len, null, XMLDB_NOTNULL, null, $default, $after);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $field = new xmldb_field('defaulterpenalty', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0', 'minmembership');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field(
            'incompletepenalty',
            XMLDB_TYPE_NUMBER,
            '10, 5',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'defaulterpenalty'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('selfselectadvanced_penalty');
        $field = new xmldb_field('award', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null, 'penaltyvalue');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072416, 'selfselectadvanced');
    }

    if ($oldversion < 2026072417) {
        // 1.4.1: audit-fix release, no schema change.
        upgrade_mod_savepoint(true, 2026072417, 'selfselectadvanced');
    }

    if ($oldversion < 2026072418) {
        // 1.4.2: audit items 3/21/26/27/28, code only.
        upgrade_mod_savepoint(true, 2026072418, 'selfselectadvanced');
    }

    if ($oldversion < 2026072419) {
        // 1.4.3: flagged tabs/CSVs, clash detector, code only.
        upgrade_mod_savepoint(true, 2026072419, 'selfselectadvanced');
    }

    if ($oldversion < 2026072420) {
        // 1.5.0: guide visibility override.
        $table = new xmldb_table('selfselectadvanced_override');
        $field = new xmldb_field('guidehidden', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'penaltywaived');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072420, 'selfselectadvanced');
    }

    if ($oldversion < 2026072421) {
        // 1.5.0 release marker: import modes, code only.
        upgrade_mod_savepoint(true, 2026072421, 'selfselectadvanced');
    }

    if ($oldversion < 2026072422) {
        // 1.5.1: three-state auto-grouping mode; the old enabled flag
        // meant manual + automatic, so 1 migrates to 2.
        $DB->set_field('selfselectadvanced', 'autogroup', 2, ['autogroup' => 1]);

        upgrade_mod_savepoint(true, 2026072422, 'selfselectadvanced');
    }

    if ($oldversion < 2026072423) {
        // 1.5.2: autogroupresult message provider (round-4 fixes).
        upgrade_mod_savepoint(true, 2026072423, 'selfselectadvanced');
    }

    if ($oldversion < 2026072424) {
        // 1.6.0: report export overhaul + slot editing, code only.
        upgrade_mod_savepoint(true, 2026072424, 'selfselectadvanced');
    }

    if ($oldversion < 2026072425) {
        // 1.6.1: audit round 6 fixes (raw export values, provider
        // string, post-commit notifications), code only.
        upgrade_mod_savepoint(true, 2026072425, 'selfselectadvanced');
    }

    if ($oldversion < 2026072426) {
        // 1.6.2: flagged tabs rebuilt on flexible_table, code only.
        upgrade_mod_savepoint(true, 2026072426, 'selfselectadvanced');
    }

    if ($oldversion < 2026072427) {
        // 1.7.0: guide volunteering (optional capacity declaration by guides).
        $table = new xmldb_table('selfselectadvanced');
        $field = new xmldb_field(
            'guidevolunteer',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'guideautoapprove'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('selfselectadvanced_volunteer');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('capacity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_activityid', XMLDB_KEY_FOREIGN, ['activityid'], 'selfselectadvanced', ['id']);
            $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            // The fk_userid foreign key already indexes userid; a
            // separate index on the same field collides with it.
            $table->add_index('activityid_userid', XMLDB_INDEX_UNIQUE, ['activityid', 'userid']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072427, 'selfselectadvanced');
    }

    if ($oldversion < 2026072428) {
        // 1.7.1: volunteer table schema alignment, superseded by the
        // repair step below.
        upgrade_mod_savepoint(true, 2026072428, 'selfselectadvanced');
    }

    if ($oldversion < 2026072429) {
        // 1.7.1: the userid foreign key of the volunteer table carries
        // its own index, exactly as every other table here. Restore it
        // where an interim step removed it; index_exists() matches on
        // FIELDS rather than name, so this is the only safe test.
        $table = new xmldb_table('selfselectadvanced_volunteer');
        $index = new xmldb_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $index)) {
            $key = new xmldb_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $dbman->add_key($table, $key);
        }

        upgrade_mod_savepoint(true, 2026072429, 'selfselectadvanced');
    }

    if ($oldversion < 2026072430) {
        // 1.8.0: opt-in daily or weekly digest for guide-facing
        // notifications, queued here and flushed by the send_digests
        // scheduled task.
        $table = new xmldb_table('selfselectadvanced_digestq');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10');
            $table->add_field('provider', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('subjectkey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('bodykey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('payload', XMLDB_TYPE_TEXT);
            $table->add_field('contexturl', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('fk_activityid', XMLDB_KEY_FOREIGN, ['activityid'], 'selfselectadvanced', ['id']);
            // The fk_userid foreign key already indexes userid on its
            // own (the volunteer table's lesson, 2026072429): only the
            // composite (userid, timecreated) index is added here.
            $table->add_index('userid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072430, 'selfselectadvanced');
    }

    if ($oldversion < 2026072431) {
        // 1.8.1: report wording only, no schema change.
        upgrade_mod_savepoint(true, 2026072431, 'selfselectadvanced');
    }

    if ($oldversion < 2026072432) {
        // 1.8.2: unique action button ids on the group page, code only.
        upgrade_mod_savepoint(true, 2026072432, 'selfselectadvanced');
    }

    if ($oldversion < 2026072433) {
        // 1.8.3: stable action button ids on the group page, code only.
        upgrade_mod_savepoint(true, 2026072433, 'selfselectadvanced');
    }

    if ($oldversion < 2026072434) {
        // 1.9.0: audit round 7 fixes and the records-per-page control,
        // plus the send_nudges adhoc task, no schema change.
        upgrade_mod_savepoint(true, 2026072434, 'selfselectadvanced');
    }

    if ($oldversion < 2026072435) {
        // 1.10.0: performance batching, internationalisation fixes and
        // report usability, no schema change.
        upgrade_mod_savepoint(true, 2026072435, 'selfselectadvanced');
    }

    if ($oldversion < 2026072436) {
        // 1.11.0: expressions of interest. Guides pick listed teams,
        // leaders accept or reject, full history kept for analytics.
        $table = new xmldb_table('selfselectadvanced');
        $newsettings = [
            ['eoienabled', '1', '0', 'guidevolunteer'],
            ['eoiwindow', '10', '0', 'eoienabled'],
            ['eoimax', '5', '3', 'eoiwindow'],
            ['eoisequential', '1', '0', 'eoimax'],
            ['eoipeers', '1', '0', 'eoisequential'],
        ];
        foreach ($newsettings as [$name, $length, $default, $previous]) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, $length, null, XMLDB_NOTNULL, null, $default, $previous);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('selfselectadvanced_group');
        $field = new xmldb_field('returncommentformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'returncomment');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('listed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'returncommentformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('timelisted', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'listed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('selfselectadvanced_eoi');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('guideid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('remarks', XMLDB_TYPE_TEXT);
            $table->add_field('remarksformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeresponded', XMLDB_TYPE_INTEGER, '10');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_activityid', XMLDB_KEY_FOREIGN, ['activityid'], 'selfselectadvanced', ['id']);
            $table->add_key('fk_groupid', XMLDB_KEY_FOREIGN, ['groupid'], 'selfselectadvanced_group', ['id']);
            $table->add_key('fk_guideid', XMLDB_KEY_FOREIGN, ['guideid'], 'user', ['id']);
            $table->add_index('groupid_status', XMLDB_INDEX_NOTUNIQUE, ['groupid', 'status']);
            $table->add_index('guideid_status', XMLDB_INDEX_NOTUNIQUE, ['guideid', 'status']);
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072436, 'selfselectadvanced');
    }

    if ($oldversion < 2026072437) {
        // 1.11.1: member drill-down composition columns, inline
        // proposal preview, code only.
        upgrade_mod_savepoint(true, 2026072437, 'selfselectadvanced');
    }

    if ($oldversion < 2026072440) {
        // 1.14.0: per-group waitlist cap, guide handover nominee,
        // mobile-sharing consent.
        $table = new xmldb_table('selfselectadvanced');
        $field = new xmldb_field('eoigroupmax', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'eoipeers');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('selfselectadvanced_group');
        $field = new xmldb_field('guidesuccessorid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'successortype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('timeguidenominated', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'guidesuccessorid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('selfselectadvanced_userattr');
        $field = new xmldb_field('shareconsent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'program');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072440, 'selfselectadvanced');
    }

    if ($oldversion < 2026072450) {
        $table = new xmldb_table('selfselectadvanced');
        $field = new xmldb_field('uidprefix', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, 'SSA', 'maxguided');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072450, 'selfselectadvanced');
    }

    if ($oldversion < 2026072451) {
        $table = new xmldb_table('selfselectadvanced');
        $field = new xmldb_field('uiddigits', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '4', 'uidprefix');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072451, 'selfselectadvanced');
    }

    if ($oldversion < 2026072460) {
        $table = new xmldb_table('selfselectadvanced');
        $field = new xmldb_field('studentapproach', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'uiddigits');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('nameformat', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'studentapproach');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('nameformatexample', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'nameformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('selfselectadvanced_ticket');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '12', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'open');
        $table->add_field('requestedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('request', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('requestformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('claimedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timeclaimed', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('resolvedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timeresolved', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('resolution', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('resolutionformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_activityid', XMLDB_KEY_FOREIGN, ['activityid'], 'selfselectadvanced', ['id']);
        $table->add_key('fk_groupid', XMLDB_KEY_FOREIGN, ['groupid'], 'selfselectadvanced_group', ['id']);
        $table->add_key('fk_requestedby', XMLDB_KEY_FOREIGN, ['requestedby'], 'user', ['id']);
        $table->add_index('activityid_status', XMLDB_INDEX_NOTUNIQUE, ['activityid', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // The new capability must exist before the role can carry it;
        // core refreshes access.php only after upgrade.php finishes.
        update_capabilities('mod_selfselectadvanced');
        \mod_selfselectadvanced\local\coordinatorrole::ensure();

        upgrade_mod_savepoint(true, 2026072460, 'selfselectadvanced');
    }

    if ($oldversion < 2026073070) {
        $table = new xmldb_table('selfselectadvanced');

        // 1.16.0 constrained the group NAME; the intent was always the
        // project ID. The name fields go, and a template takes their
        // place. An activity that says nothing keeps the id shape the
        // plugin has always issued, so no site's ids change under it.
        $field = new xmldb_field('uidformat', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'studentapproach');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        foreach (['nameformat', 'nameformatexample'] as $gone) {
            $field = new xmldb_field($gone);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        // Students-approach becomes the default for activities created
        // from here on. Existing activities keep whatever they have:
        // changing a column default never rewrites rows.
        $field = new xmldb_field('studentapproach', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'uiddigits');
        $dbman->change_field_default($table, $field);

        upgrade_mod_savepoint(true, 2026073070, 'selfselectadvanced');
    }

    if ($oldversion < 2026073072) {
        $table = new xmldb_table('selfselectadvanced');

        // A team approaching a guide (strategy 1.17 E): the approaches
        // themselves, and how many guides one team may approach.
        $field = new xmldb_field('contactmax', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3', 'uiddigits');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $contacts = new xmldb_table('selfselectadvanced_contact');
        $contacts->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $contacts->add_field('activityid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $contacts->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $contacts->add_field('guideid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $contacts->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'sent');
        $contacts->add_field('sentby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $contacts->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $contacts->add_field('messageformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $contacts->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $contacts->add_field('reasonformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $contacts->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $contacts->add_field('timeresponded', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $contacts->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $contacts->add_key('fk_activityid', XMLDB_KEY_FOREIGN, ['activityid'], 'selfselectadvanced', ['id']);
        $contacts->add_key('fk_groupid', XMLDB_KEY_FOREIGN, ['groupid'], 'selfselectadvanced_group', ['id']);
        $contacts->add_key('fk_guideid', XMLDB_KEY_FOREIGN, ['guideid'], 'user', ['id']);
        $contacts->add_index('groupid_status', XMLDB_INDEX_NOTUNIQUE, ['groupid', 'status']);
        $contacts->add_index('guideid_status', XMLDB_INDEX_NOTUNIQUE, ['guideid', 'status']);
        if (!$dbman->table_exists($contacts)) {
            $dbman->create_table($contacts);
        }

        // The coordinator role gained the override capability this
        // release. Adding it to the role's capability list is not
        // enough on its own: nothing re-runs that list, so a site that
        // already has the role would never receive it. Registering the
        // capabilities first, as at install, then re-asserting the
        // role, gives it to them - and because ensure() never overrules
        // a setting already recorded, an administrator's own decisions
        // about this role survive.
        update_capabilities('mod_selfselectadvanced');
        \mod_selfselectadvanced\local\coordinatorrole::ensure();

        upgrade_mod_savepoint(true, 2026073072, 'selfselectadvanced');
    }

    if ($oldversion < 2026073080) {
        // A guide may now ask the coordinators for a higher team limit
        // (strategy 1.18 C). That request is a ticket like the other
        // two, but it is not about a team and it carries the number
        // asked for, so a coordinator can grant it in one action
        // instead of copying the figure into an override by hand.
        $ticket = new xmldb_table('selfselectadvanced_ticket');
        $requested = new xmldb_field('requested', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'resolutionformat');
        if (!$dbman->field_exists($ticket, $requested)) {
            $dbman->add_field($ticket, $requested);
        }

        upgrade_mod_savepoint(true, 2026073080, 'selfselectadvanced');
    }

    if ($oldversion < 2026073090) {
        // A guide may now release a team they guide - but only while no
        // editing teacher or coordinator has enforced the freeze
        // (strategy 1.19 C). Whether staff enforced it is recorded when
        // the freeze happens, so the question is answered by what was
        // true then rather than by who holds what today.
        $group = new xmldb_table('selfselectadvanced_group');
        $staff = new xmldb_field('frozenbystaff', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'timefrozen');
        if (!$dbman->field_exists($group, $staff)) {
            $dbman->add_field($group, $staff);
        }
        // Teams already frozen when this arrives were frozen under the
        // old rule, where only staff could unfreeze at all. Treating
        // them as staff-enforced keeps that promise rather than handing
        // guides a release nobody granted them.
        $DB->set_field_select(
            'selfselectadvanced_group',
            'frozenbystaff',
            1,
            'state = :frozen',
            ['frozen' => \mod_selfselectadvanced\local\state::FROZEN]
        );

        // A student's request to join a team is a move in a new status,
        // so committing one stays the engine already in place
        // (strategy 1.19 B).
        $move = new xmldb_table('selfselectadvanced_move');
        foreach (['reason', 'responsenote'] as $name) {
            $field = new xmldb_field($name, XMLDB_TYPE_TEXT, null, null, null, null, null);
            if (!$dbman->field_exists($move, $field)) {
                $dbman->add_field($move, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026073090, 'selfselectadvanced');
    }

    if ($oldversion < 2026073100) {
        // No schema change. This step exists so that the LAST savepoint
        // in this file equals the version in version.php.
        //
        // 1.19.0 raised the version to 2026073091 purely to make Moodle
        // re-read db/messages.php, and added no savepoint with it. Every
        // site that upgraded then finished holding db = 2026073091 with
        // the chain here terminating at 2026073090, so the plugin's own
        // record of how far it had got was behind the code. The same
        // applies to 1.19.1, which changes an external function's
        // signature and so needs db/services.php re-read.
        //
        // The invariant is now enforced by the build (savepoint-tip),
        // because a static checker that only reads this file cannot see
        // version.php and did not catch it.
        upgrade_mod_savepoint(true, 2026073100, 'selfselectadvanced');
    }

    if ($oldversion < 2026073110) {
        // Override rows were kept unique per (activity, scope, target)
        // by convention in store::save() alone - a read-then-insert
        // with neither a lock nor an index behind it - so concurrent
        // saves created twins, after which the resolver read an
        // arbitrary one. save()/delete() now serialise on
        // override:{scope}:{targetid}; this merges the twins that
        // already exist. No schema change: a unique index cannot
        // express the invariant, because the four scopes target four
        // NULLABLE columns and NULLs are distinct in a unique index on
        // both PostgreSQL and MariaDB.
        //
        // Raw SQL only, by design: nothing here may call a plugin class
        // that queries a plugin table (upgrade-safety rule).
        //
        // CORRECTED 2026-07-31 (1.20 audit repair). This block first
        // shipped keeping MIN(id) with no reference to status, which is
        // NOT the row the read path reads: resolver::load_overrides()
        // selects status='active' only, so for a twin pair whose older
        // row is 'pending' and whose newer row is 'active' the merge
        // deleted the exception actually in force and kept an invisible
        // parked one. Measured end to end on both engines: a group with
        // an active maxsize 9 twin and an older pending maxsize 2 twin
        // resolved to 9 before the merge and to the activity default
        // after it, with nothing logged and no way back. The keeper is
        // now "oldest ACTIVE, else oldest", which is exactly what
        // resolver::load_overrides() and store::get() both mean by
        // "the row that wins" (precedence P14).
        //
        // See the 2026073140 block below for what this can and cannot
        // do for a site that already ran the flawed version.
        selfselectadvanced_upgrade_merge_override_twins($DB);

        upgrade_mod_savepoint(true, 2026073110, 'selfselectadvanced');
    }

    if ($oldversion < 2026073120) {
        // No schema change and no data step. This savepoint exists
        // solely so the site re-reads db/events.php, which now
        // registers an observer for \core\event\user_enrolment_deleted:
        // observer registration is only refreshed on upgrade, so
        // without a version bump the observer would never fire on an
        // upgraded site (T-16).
        upgrade_mod_savepoint(true, 2026073120, 'selfselectadvanced');
    }

    if ($oldversion < 2026073130) {
        // Decision 6: a staff removal no longer needs a destination team.
        // A park is a move to nowhere, so targetgroupid relaxes to
        // nullable. $dbman only - no plugin class is loaded and no
        // plugin table is queried, which is what keeps this block safe
        // to run against a half-upgraded codebase.
        //
        // The foreign key on this column is implemented as an index,
        // and database_manager::check_field_dependencies() refuses to
        // modify ANY column an index references. So the key is dropped
        // and put back around the change - which is also why this is
        // three calls rather than the one the ticket drafted.
        $table = new xmldb_table('selfselectadvanced_move');
        $key = new xmldb_key(
            'fk_targetgroupid',
            XMLDB_KEY_FOREIGN,
            ['targetgroupid'],
            'selfselectadvanced_group',
            ['id']
        );
        $field = new xmldb_field('targetgroupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'sourcegroupid');
        $dbman->drop_key($table, $key);
        $dbman->change_field_notnull($table, $field);
        $dbman->add_key($table, $key);

        upgrade_mod_savepoint(true, 2026073130, 'selfselectadvanced');
    }

    if ($oldversion < 2026073140) {
        // Re-run the override twin merge with the corrected keeper.
        //
        // WHY A SECOND STEP AND NOT JUST THE EDIT ABOVE. The 2026073110
        // block already ran on every site that reached 1.19.2, and an
        // edited step does not re-run: `$oldversion < 2026073110` is
        // false for them forever. Correcting that block is still worth
        // doing - it protects every site that has NOT yet passed it,
        // which is the only upgrade path an external site can take,
        // since 1.19.2 and 1.20.0 were never released separately - but
        // it does nothing for a site already past it.
        //
        // WHAT THIS STEP CAN DO: whatever twins remain are merged the
        // way the read path reads them, so after this upgrade the
        // surviving row is provably the oldest ACTIVE row on every
        // site, whichever version it came from. It is idempotent and a
        // no-op on a site with no duplicates.
        //
        // WHAT IT CANNOT DO, said plainly rather than implied: a site
        // that ran the flawed merge has already had the active twin
        // DELETED. The row is gone, nothing recorded its values, and no
        // upgrade step can bring it back. The visible symptom is a
        // group, user or guide whose effective limits silently reverted
        // to the activity defaults at the 1.19.2 upgrade while a
        // 'pending' override row for the same target survives. Such a
        // target has to be re-granted by hand; CHANGELOG.md says so in
        // the release notes.
        $deleted = selfselectadvanced_upgrade_merge_override_twins($DB);
        if ($deleted) {
            upgrade_log(
                UPGRADE_LOG_NOTICE,
                'mod_selfselectadvanced',
                'Merged ' . $deleted . ' duplicate override row(s), keeping the oldest active row of each set'
            );
        }

        upgrade_mod_savepoint(true, 2026073140, 'selfselectadvanced');
    }

    if ($oldversion < 2026073150) {
        // The 1.20 Moodle Manager grants reach an UPGRADED site.
        //
        // db/access.php gives 'manager' => CAP_ALLOW on :unfreeze,
        // :manage, :override and :viewall (decision 6, D6-7). Editing
        // the archetype list is enough for a FRESH install and does
        // nothing at all for an upgrade: update_capabilities() builds
        // its "new capabilities" list from the file's capabilities that
        // are ABSENT from the capabilities table, and only that list
        // reaches assign_legacy_capabilities() (lib/accesslib.php - the
        // $newcaps loop). All four names have existed since 1.0, so on
        // every site that installed 1.19.x or earlier core sees nothing
        // new and assigns nothing. The last update_capabilities() call
        // in this file is at savepoint 2026073072, below 1.19.2's tip,
        // so no later block re-asserts them either. MEASURED on both
        // engines: delete those four role_capabilities rows - the state
        // of every pre-1.20 site - and run update_capabilities(); the
        // manager holds none of the four afterwards.
        //
        // So they are asserted here, explicitly, which is the pattern
        // the 2026073072 block already uses for the coordinator role.
        // update_capabilities() still runs first: it registers anything
        // genuinely new and refreshes riskbitmask/contextlevel, and the
        // assertion below is about the four names only.
        update_capabilities('mod_selfselectadvanced');

        // NEVER OVERRULE AN ADMINISTRATOR. A site that deliberately
        // took one of these away from the manager role has a row in
        // role_capabilities recording that decision (CAP_PREVENT or
        // CAP_PROHIBIT), and a site that deliberately granted it has
        // one too. assign_capability()'s $overwrite argument is left at
        // its default false, and core returns early on any existing row
        // (accesslib.php: "We want to keep whatever is there already"),
        // so this writes ONLY where the role has no recorded permission
        // for the capability at all. It is idempotent: running it twice
        // changes nothing the second time.
        //
        // System context and get_archetype_roles() rather than a
        // hardcoded role id, because that is where core itself stores a
        // role definition and because a site may have several roles of
        // the manager archetype (or have renamed the shipped one - the
        // shortname is never the key).
        $syscontext = context_system::instance();
        $managerroles = get_archetype_roles('manager');
        $managercaps = [
            'mod/selfselectadvanced:unfreeze',
            'mod/selfselectadvanced:manage',
            'mod/selfselectadvanced:override',
            'mod/selfselectadvanced:viewall',
        ];
        $granted = 0;
        foreach ($managerroles as $role) {
            foreach ($managercaps as $capability) {
                $recorded = $DB->record_exists('role_capabilities', [
                    'contextid' => $syscontext->id,
                    'roleid' => $role->id,
                    'capability' => $capability,
                ]);
                if ($recorded) {
                    continue;
                }
                assign_capability($capability, CAP_ALLOW, $role->id, $syscontext->id);
                $granted++;
            }
        }
        if ($granted) {
            // Role definitions changed outside update_capabilities()'s
            // own reset, so the static role cache has to be dropped or
            // the rest of this request answers from the old picture.
            accesslib_reset_role_cache();
            upgrade_log(
                UPGRADE_LOG_NOTICE,
                'mod_selfselectadvanced',
                'Granted ' . $granted . ' manager capability/capabilities that the archetype could not reach on upgrade'
            );
        }

        upgrade_mod_savepoint(true, 2026073150, 'selfselectadvanced');
    }

    if ($oldversion < 2026073160) {
        // 1.20.0: a coordinator appointment belongs to ONE activity.
        //
        // Until now it was a role assignment at the COURSE, made from
        // an activity's screen and consumed per activity: every
        // capability the role carries is declared at CONTEXT_MODULE and
        // every consumer asks for it at $activity->context(). So one
        // appointment quietly reached every selfselectadvanced instance
        // in the course.
        //
        // ensure() first, because it is what makes the role assignable
        // at CONTEXT_MODULE - on the recorded and adopted branches too,
        // not just on the branch that creates the role. Rows must not
        // appear at a level the role is not declared for.
        //
        // Then each course-context appointment is fanned out to every
        // selfselectadvanced instance in its course and the course row
        // retired; a course with no instance keeps its row, because
        // there is nowhere to move it to and dropping it would revoke
        // somebody's job. Both calls touch core tables only, so this
        // step queries no plugin table from any starting savepoint
        // (upgrade-safety).
        //
        // update_capabilities() FIRST, for the same reason the
        // 2026072460, 2026073072, 2026073150 and 2026073170 blocks give
        // it: ensure() calls assign_capability() for every name in
        // coordinatorrole::capabilities(), and assign_capability()
        // raises a coding_exception for a capability core has not
        // registered yet. Core registers db/access.php only in
        // upgrade_component_updated(), AFTER this function returns. The
        // list gained :managecomposition and :assignguide at 2026073170,
        // which made THIS block - written when the list held nothing
        // new - fail for a site starting at exactly 2026073150: the
        // 2026073150 block that would have registered them is skipped,
        // and the whole site upgrade dies with "Capability
        // 'mod/selfselectadvanced:managecomposition' was not found".
        // Measured before this line was added; pinned by
        // narrowcaps_test::test_upgrade_from_the_previous_serial_survives().
        update_capabilities('mod_selfselectadvanced');
        \mod_selfselectadvanced\local\coordinatorrole::ensure();
        \mod_selfselectadvanced\local\coordinatorrole::migrate_to_module_context();

        upgrade_mod_savepoint(true, 2026073160, 'selfselectadvanced');
    }

    if ($oldversion < 2026073170) {
        // 1.20.0: the Group Coordinator role gains the two narrow
        // powers this release introduces - :managecomposition (stage,
        // commit and cancel student moves) and :assignguide (assign or
        // reassign a team's guide, decide expressions of interest) -
        // plus :overriderules, the staff hatch T-15 introduced.
        //
        // A version bump alone only makes Moodle re-read
        // db/access.php's DEFINITIONS; it does not grant anything to a
        // role the plugin created. ensure() is what tops the role up,
        // and it must run here or an upgraded site's coordinators keep
        // the 1.19 power set while a freshly installed site's do not
        // (db/install.php calls ensure() too, which is why the two
        // paths have to agree).
        //
        // assign_capability() runs with overwrite OFF inside ensure(),
        // so a CAP_PREVENT or CAP_PROHIBIT an administrator recorded
        // against this role survives untouched. Restoring a permission
        // somebody deliberately took away is not an upgrade's job.
        //
        // :overriderules is granted only now, and deliberately only
        // now: it is defensible on this role because 2026073160 moved
        // every appointment to CONTEXT_MODULE first (maintainer
        // decision 14). Read capabilities()'s docblock for the
        // guarantee that carries. Core tables only - no plugin table is
        // touched, so this step behaves identically from any starting
        // savepoint (upgrade-safety).
        //
        // update_capabilities() FIRST, and it is not optional here.
        // Core refreshes db/access.php only in
        // upgrade_component_updated(), which runs AFTER this function
        // returns, and assign_capability() raises a coding_exception
        // for a capability that has no {capabilities} row. Without this
        // line ensure() kills the whole site upgrade with "Capability
        // 'mod/selfselectadvanced:managecomposition' was not found"
        // - measured on both engines, from a site at 2026073160. No
        // test on a rebuilt site can see it: --reinit installs through
        // db/install.php, which calls update_capabilities() for exactly
        // this reason, so PHPUnit, Behat and savepoint-tip all stay
        // green. The 2026072460 and 2026073150 blocks take the same
        // precaution; this is the first step since to introduce a
        // capability the role must carry, so it is the first that could
        // fail on it.
        update_capabilities('mod_selfselectadvanced');
        \mod_selfselectadvanced\local\coordinatorrole::ensure();

        upgrade_mod_savepoint(true, 2026073170, 'selfselectadvanced');
    }

    if ($oldversion < 2026073180) {
        // Contact privacy (cardinal rule; maintainer decisions 17 and 18,
        // 2026-08-01): per-activity switch hiding participant email and
        // mobile from everyone without the manage capability, except real
        // connections - and, for email, from everyone below manage full
        // stop, because staff now reach a student through a Moodle message
        // instead of an address. Existing instances come up protected: the
        // maintainer chose default ON for them too, which the NOTNULL
        // DEFAULT '1' applies to every existing row.
        $table = new xmldb_table('selfselectadvanced');
        $field = new xmldb_field('contactprivacy', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'leadershare');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Registers :viewparticipantidentity. It has no archetypes and no
        // clonepermissionsfrom, so this GRANTS IT TO NOBODY - it only puts
        // the row in {capabilities} so an administrator can find it. Core
        // runs update_capabilities() itself later in the plugin upgrade;
        // calling it here follows the precedent already set inside this
        // file and makes the point of the block legible in the upgrade
        // log. Core tables and xmldb only - no plugin class is loaded and
        // no plugin table is queried (upgrade-safety).
        update_capabilities('mod_selfselectadvanced');

        upgrade_mod_savepoint(true, 2026073180, 'selfselectadvanced');
    }

    if ($oldversion < 2026073190) {
        // 1.20.1: :viewassignedteams splits "the team I am assigned to
        // guide" out of :viewall, so a site can withdraw the broad
        // capability without locking every guide out of the page that
        // carries Freeze, Release, the roster and the proposal.
        //
        // update_capabilities() is what registers the new capability and
        // fires its clonepermissionsfrom pass, which copies each role's
        // recorded :guide permission - ALLOW, PREVENT or PROHIBIT -
        // onto the new name. Core runs this itself later in the plugin
        // upgrade; it is called here explicitly so the ensure() below
        // tops up a role whose capability already exists, and because
        // the 2026073150 block set that precedent.
        update_capabilities('mod_selfselectadvanced');

        // The Group Coordinator role is created by this plugin, so the
        // archetype/clone machinery above does not reach it: ensure()
        // is what writes its capability list. assign_capability's
        // $overwrite stays false (coordinatorrole.php), so a recorded
        // CAP_PREVENT or CAP_PROHIBIT survives untouched.
        //
        // ensure() is ALSO what applies the activity-context-only rule
        // on an upgraded site: the role becomes assignable at
        // CONTEXT_MODULE only. Read the levels BEFORE the call so the
        // log below can name what changed - that edit narrows
        // assignability, which is the one narrowing in this release an
        // administrator could notice, and it must be visible in the log
        // rather than inferred.
        //
        // The role id can legitimately be absent: a site where a
        // foreign role blocked us records a collision and ensure()
        // returns 0, and a site whose role row was deleted has nothing
        // to read. Guard it - a log line must never be the thing that
        // breaks an upgrade.
        $coordinatorroleid = (int) get_config(
            'mod_selfselectadvanced',
            \mod_selfselectadvanced\local\coordinatorrole::CONFIG_ROLEID
        );
        $levelsbefore = [];
        if ($coordinatorroleid > 0 && $DB->record_exists('role', ['id' => $coordinatorroleid])) {
            $levelsbefore = array_map('intval', array_values(get_role_contextlevels($coordinatorroleid)));
        }
        \mod_selfselectadvanced\local\coordinatorrole::ensure();

        // NEVER OVERRULE AN ADMINISTRATOR. db/access.php no longer
        // grants :viewall to the non-editing teacher archetype, and
        // that edit is deliberately INERT here: core applies archetypes
        // only to capabilities new to the capabilities table, so every
        // existing role_capabilities row stands. This block does NOT
        // call unassign_capability() and does NOT pass $overwrite=true
        // anywhere - role_capabilities carries no provenance, so the
        // plugin cannot distinguish its own install-time grant from a
        // permission an administrator chose to record, and guessing
        // would be exactly the overrule the rule forbids. Withdrawing
        // it is the administrator's act; all this step does is say so,
        // once, in the upgrade log, so the decision point is visible.
        $stillbroad = get_roles_with_capability(
            'mod/selfselectadvanced:viewall',
            CAP_ALLOW,
            context_system::instance()
        );
        $names = [];
        foreach ($stillbroad as $role) {
            $names[] = $role->shortname;
        }
        // The {upgrade_log} info column is char(255) and upgrade_log()
        // swallows the insert exception, so a long message logs
        // NOTHING AT ALL -
        // measured on PostgreSQL 18 with the whole text in $info, which
        // produced no row while the block reported success. The headline
        // goes in $info and the roll-call in $details, which is TEXT.
        $details = 'Roles still holding the broad mod/selfselectadvanced:viewall '
            . '(unchanged by this upgrade - withdraw it yourself if your site restricts '
            . 'participant visibility): '
            . ($names ? implode(', ', $names) : 'none')
            . '. The Group Coordinator role is now assignable at activity context only';
        if ($levelsbefore) {
            $details .= ' (was: ' . implode(', ', $levelsbefore) . ')';
        }
        $details .= '; existing role assignments, including any made at course level, '
            . 'are untouched and still work.';
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'mod/selfselectadvanced:viewassignedteams added; Group Coordinator role narrowed '
                . 'to activity context. No recorded permission was withdrawn.',
            $details
        );

        upgrade_mod_savepoint(true, 2026073190, 'selfselectadvanced');
    }

    if ($oldversion < 2026073200) {
        // 1.20.0 release serial.
        //
        // THERE IS GENUINELY NOTHING TO MIGRATE. Everything that landed
        // after savepoint 2026073190 changed BEHAVIOUR only: db/install.xml,
        // db/access.php, db/messages.php and db/tasks.php are byte-identical
        // to the tree that shipped that savepoint, so this step performs no
        // schema, capability, message-provider or data work of any kind.
        //
        // Why the serial exists at all: without a version.php number above
        // the one an existing site has recorded, Moodle never detects the
        // update, so none of that behaviour ever reaches the site.
        //
        // Why the block is NOT merely a savepoint. Core's upgrade_plugins()
        // writes $plugin->version into config_plugins itself once this
        // function returns, so an installed version of 2026073200 proves
        // only that version.php moved - it does NOT prove that any step in
        // THIS file ran. /srv/ci/ops/savepoint-tip.sh compares version.php
        // against the MAXIMUM savepoint here, which catches a lowered number
        // and is blind to a re-used or unreachable one, and --reinit builds
        // the test sites from db/install.xml, where no upgrade step runs at
        // all. The upgrade_log() row below is therefore the only artefact
        // that can distinguish "this step executed" from "this step was
        // skipped and nobody noticed"; versionbump_test asserts on it after
        // driving a real upgrade from 2026073190.
        //
        // {upgrade_log} is a CORE table and no plugin class is called here,
        // so the upgrade-safety rule holds trivially.
        //
        // The info column is char(255) and upgrade_log() swallows the insert
        // exception, so a long message logs NOTHING AT ALL (measured on
        // PostgreSQL 18 while writing the 2026073190 block). Headline in
        // $info, everything else in $details, which is TEXT.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.0 (2026073200). Behaviour changes only: no schema, capability '
                . 'or message-provider change in this step.',
            'This step migrates nothing; it carries the release serial so that an existing '
                . 'site detects 1.20.0 at all. It is deliberately observable: this row is '
                . 'written if and only if the step actually executed, which is what '
                . 'distinguishes a reachable upgrade path from a savepoint number that was '
                . 'merely written down. Everything 1.20.0 changes lives in the plugin code, '
                . 'not in its tables.'
        );

        upgrade_mod_savepoint(true, 2026073200, 'selfselectadvanced');
    }

    if ($oldversion < 2026073210) {
        // 1.20.1 - the plugins directory review, plus a narrowing of what this
        // plugin claims to support. No schema, capability, message-provider or
        // scheduled-task change: db/install.xml, db/access.php, db/messages.php
        // and db/tasks.php are untouched by this release.
        //
        // What changed is metadata and naming: a package LICENSE file, every
        // global function frankenstyle-prefixed, and version.php narrowed from
        // "Moodle 4.5 LTS to 5.2" to Moodle 5.2 only with PHP 8.4 as the floor.
        //
        // The marker row is written for the same reason the 2026073200 one is:
        // core writes $plugin->version into config_plugins by itself once this
        // function returns, so the recorded version cannot distinguish a step
        // that RAN from a step that was skipped. This row can.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.1 (2026073210). Packaging and metadata only: no schema, capability '
                . 'or message-provider change in this step.',
            'This step migrates nothing. It carries the release serial so that an existing '
                . 'site detects 1.20.1, and it is deliberately observable: this row is '
                . 'written if and only if the step actually executed. Everything 1.20.1 '
                . 'changes lives in the plugin package and its declared metadata, not in '
                . 'its tables.'
        );

        upgrade_mod_savepoint(true, 2026073210, 'selfselectadvanced');
    }

    if ($oldversion < 2026073220) {
        // 1.20.2 - finding a guide by the detail the student actually has. No
        // schema, capability, message-provider or scheduled-task change:
        // db/install.xml, db/access.php, db/messages.php and db/tasks.php are
        // untouched by this release.
        //
        // WHY THIS RELEASE NEEDS A SERIAL AT ALL, since it changes no table.
        // It adds LANGUAGE STRINGS (guidepickerplaceholderany,
        // participantpickerplaceholder), and Moodle's string cache key includes
        // $CFG->langrev, which only reset_caches() bumps - and reset_caches()
        // is reached by an upgrade. Ship the new keys without a version bump
        // and an installed site keeps serving the cached string file, so the
        // override form's picker renders the literal
        // [[guidepickerplaceholderany]] until somebody purges caches by hand.
        //
        // The marker row is written for the same reason the 2026073210 one is:
        // core writes $plugin->version into config_plugins by itself once this
        // function returns, so the recorded version cannot distinguish a step
        // that RAN from a step that was skipped. This row can - and
        // versionbump_test counts it by matching '%(2026073220)%', so the
        // serial must stay inside the parentheses in the headline below.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.2 (2026073220). Behaviour and language strings only: no schema, '
                . 'capability or message-provider change in this step.',
            'This step migrates nothing. It carries the release serial so that an existing '
                . 'site detects 1.20.2 and rebuilds its string cache, without which the new '
                . 'picker placeholders would render as [[...]] on an installed site. It is '
                . 'deliberately observable: this row is written if and only if the step '
                . 'actually executed. Everything 1.20.2 changes lives in the plugin code and '
                . 'its language file, not in its tables.'
        );

        upgrade_mod_savepoint(true, 2026073220, 'selfselectadvanced');
    }

    if ($oldversion < 2026073230) {
        // 1.20.3. Authority follows the service everywhere the external
        // evaluation showed it did not (AUTH-001..004, ACT-001..004,
        // UX-001, PERF-001), and every render predicate now consumes
        // the same ladder its service enforces. Behaviour, five new
        // language strings, three event classes and two service
        // classes - no schema, capability or message-provider change
        // in this step.
        //
        // The marker row is written for the same reason the 2026073220
        // one is: core writes $plugin->version into config_plugins by
        // itself once this function returns, so the recorded version
        // cannot distinguish a step that RAN from a step that was
        // skipped. This row can - and versionbump_test counts it by
        // matching '%(2026073230)%', so the serial must stay inside
        // the parentheses in the headline below.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.3 (2026073230). Behaviour, language strings and new event and '
                . 'service classes only: no schema, capability or message-provider change in '
                . 'this step.',
            'This step migrates nothing. It carries the release serial so that an existing '
                . 'site detects 1.20.3 and rebuilds its caches, without which the five new '
                . 'language strings would render as [[...]] on an installed site. It is '
                . 'deliberately observable: this row is written if and only if the step '
                . 'actually executed.'
        );

        upgrade_mod_savepoint(true, 2026073230, 'selfselectadvanced');
    }

    if ($oldversion < 2026073240) {
        // 1.20.4 wave 1 - messaging reliability. A refused message_send()
        // is now a fact the plugin records and acts on: notifier::send()
        // returns the outcome and writes a notification_refused event
        // (one new event class, one new language string), the reminder
        // flag and the auto-approve escalation marker are written only
        // after a send that reported true, and the digest task counts
        // submissions, stale cleanup and failures as three different
        // things. No schema, capability or message-provider change.
        //
        // Same marker discipline as every step above: core writes
        // $plugin->version into config_plugins by itself once this
        // function returns, so only this row can tell "ran" from
        // "skipped" - versionbump_test matches '%(2026073240)%', so the
        // serial stays inside the parentheses in the headline below.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.4 (2026073240). Messaging reliability: refused submissions '
                . 'are recorded and retried; one new event class and language string.',
            'This step migrates nothing. It carries the release serial so an installed '
                . 'site rebuilds its caches and the new event name renders. It is '
                . 'deliberately observable: this row is written if and only if the step '
                . 'actually executed.'
        );

        upgrade_mod_savepoint(true, 2026073240, 'selfselectadvanced');
    }

    if ($oldversion < 2026073250) {
        // 1.20.4 wave 2 - authority and atomicity. Every public write
        // service asks its actor's authority itself; the guide-notes
        // write and the return-comment format joined their services'
        // transactions; coordinators gained workbench cards; a departed
        // guide's teams are released or ticketed and a departed
        // nominee's handover lapses with notice; the privacy provider's
        // export gaps closed; the digest queue left backup. Seven new
        // event classes and their strings, two new privacy strings -
        // no schema, capability or message-provider change.
        //
        // Marker discipline as above: versionbump_test matches
        // '%(2026073250)%', serial inside the parentheses.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.4 (2026073250). Authority into every write service, atomic '
                . 'review writes, guide-departure handling, privacy-export completeness; new '
                . 'event classes and language strings.',
            'This step migrates nothing. It carries the release serial so an installed '
                . 'site rebuilds its caches and the new event and notification strings '
                . 'render. It is deliberately observable: this row is written if and only '
                . 'if the step actually executed.'
        );

        upgrade_mod_savepoint(true, 2026073250, 'selfselectadvanced');
    }

    if ($oldversion < 2026080100) {
        // 1.20.5. The serial is also a CORRECTION: every serial from
        // 2026073200 to 2026073250 encodes 2026-07-32, a day that does
        // not exist - the scheme added its increment to 20260731 and
        // carried into the day field instead of the month. Moodle only
        // needs the number to rise, so nothing broke and no gate could
        // see it; a human reading the version, or the plugins
        // directory's validator, would. Fixed forward from here:
        // 2026-08-01, increment 00. The published serials stay as they
        // are - a landed savepoint is never rewritten.
        //
        // What the release itself carries: a pending invitation no
        // longer HARD-refuses a join request (only confirmed members
        // can put a maximum beyond reach); the fit verdict a leader
        // reads and the acceptance the button performs now come from
        // one predicate, so the column can no longer promise what the
        // button refuses; requesters show their department and
        // sub-department; a departed nominee's handover lapses on the
        // unenrolment path as well as on deletion; the privacy provider
        // discovers AND erases names held inside queued digests, with
        // one predicate for both halves; and the vocabulary writes are
        // serialised. No schema, capability or message-provider change.
        //
        // Marker discipline unchanged: versionbump_test matches
        // '%(2026080100)%', so the serial stays inside the parentheses.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.5 (2026080100). Join-request composition honesty, guide-departure '
                . 'completeness, privacy erasure parity; the serial scheme is corrected to a real '
                . 'calendar date.',
            'This step migrates nothing. It carries the release serial so an installed site '
                . 'rebuilds its caches and the new language strings render. It is deliberately '
                . 'observable: this row is written if and only if the step actually executed.'
        );

        upgrade_mod_savepoint(true, 2026080100, 'selfselectadvanced');
    }

    if ($oldversion < 2026080500) {
        // 1.20.6 schema truth: a guide-capacity ticket is not about a
        // team. The schema used to advertise groupid as a NOT NULL
        // foreign key while the writer stored 0 for "no team", which
        // made XMLDB's foreign-key checker report violations and made
        // the metadata lie to readers. NULL is the relational spelling
        // of "there is no referenced row"; real team tickets keep the
        // foreign key.
        //
        // The foreign key is dropped and re-added around the nullable
        // change for the same reason as the 2026073130 move change
        // above: XMLDB will not modify a column while a key references
        // it.
        $table = new xmldb_table('selfselectadvanced_ticket');
        $key = new xmldb_key(
            'fk_groupid',
            XMLDB_KEY_FOREIGN,
            ['groupid'],
            'selfselectadvanced_group',
            ['id']
        );
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'activityid');
        $dbman->drop_key($table, $key);
        $dbman->change_field_notnull($table, $field);
        $DB->execute("UPDATE {selfselectadvanced_ticket} SET groupid = NULL WHERE groupid = :zero", ['zero' => 0]);
        $dbman->add_key($table, $key);

        upgrade_mod_savepoint(true, 2026080500, 'selfselectadvanced');
    }

    if ($oldversion < 2026080501) {
        // 1.20.6 late-join guard: only a team released by its assigned
        // guide may change after approval. The flag is false for every
        // existing row, so approved teams keep the newly-closed default
        // until their guide explicitly releases them.
        $table = new xmldb_table('selfselectadvanced_group');
        $field = new xmldb_field(
            'releasedbyguide',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'frozenbystaff'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Marker discipline unchanged: versionbump_test matches
        // '%(2026080501)%', so the serial stays inside the parentheses. Both
        // 1.20.6 steps landed without one, which is why
        // test_a_site_at_the_previous_serial_runs_the_new_step failed: a
        // version number moving proves only that CORE bumped it, not that this
        // step ran. This row is the difference between the two, and the test
        // exists precisely to refuse a release that cannot tell them apart.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.6 (2026080501). Late-join guard: a team settled with its guide, or '
                . 'approved and never released, no longer changes underneath that guide.',
            'Adds selfselectadvanced_group.releasedbyguide, defaulting to 0 so every existing '
                . 'approved team starts closed until its own guide releases it. This row is '
                . 'written if and only if the step actually executed.'
        );

        upgrade_mod_savepoint(true, 2026080501, 'selfselectadvanced');
    }

    if ($oldversion < 2026080502) {
        // 1.20.7: a team NAME may now repeat, in this activity or any other in
        // the course. Maintainer ruling of 2026-08-05: a team's identity is its
        // generated project id - built from the team's own database key and
        // unique plugin-wide forever - not the label a student typed. Refusing
        // a student's chosen name to protect a display convention was the wrong
        // trade; the pickers now lead with the project id instead, so two teams
        // called "Alpha" cannot be confused for one another.
        //
        // The index is made NON-UNIQUE rather than dropped: it still serves the
        // activity+name lookups that read it. Only the constraint goes.
        //
        // ONE-WAY IN PRACTICE. Once duplicate names exist, restoring the unique
        // index would fail on them, and the repair would mean renaming teams
        // out from under their members. Anyone reversing this must plan that
        // migration deliberately rather than editing install.xml back.
        $table = new xmldb_table('selfselectadvanced_group');
        $index = new xmldb_index('activityid_name', XMLDB_INDEX_UNIQUE, ['activityid', 'name']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('activityid_name', XMLDB_INDEX_NOTUNIQUE, ['activityid', 'name']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Marker discipline unchanged: versionbump_test matches
        // '%(2026080502)%', so the serial stays inside the parentheses.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.7 (2026080502). Team names may repeat; the project id carries identity.',
            'Drops the UNIQUE constraint on (activityid, name), keeping the index itself for lookups. '
                . 'This row is written if and only if the step actually executed.'
        );

        upgrade_mod_savepoint(true, 2026080502, 'selfselectadvanced');
    }

    if ($oldversion < 2026080503) {
        // Marker-only release step: acceptance of a forming-team join
        // request now shares the reachability predicate used by the
        // move engine's QUOTA verdict. There is no schema or data
        // migration; the marker proves this code release executed.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.8 (2026080503). Forming-team acceptance checks composition reachability.',
            'No schema change. Records that the accept surface and move engine now share the '
                . 'state-dependent QUOTA predicate for join acceptance.'
        );

        upgrade_mod_savepoint(true, 2026080503, 'selfselectadvanced');
    }

    if ($oldversion < 2026080601) {
        // Marker-only release step: decisions 60 and 61. Decision 60: a
        // composition maximum measured on confirmed members plus the
        // person entering is a hard refusal at every door (walk-up
        // accept and invitation accept), passable only by the logged
        // staff override; a maximum exceeded only when pending
        // invitations are counted blocks nothing, bypasses nothing and
        // writes no override row. Decision 61: a course-level
        // suspension of a confirmed member of a guide-approved or
        // frozen team makes the engine write that group's quotaexempt
        // override itself, so an institutional fact never becomes a
        // rules violation the team cannot repair. There is no schema or
        // data migration; the marker proves this code release executed.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.9 (2026080601). Composition maxima: one verdict at every door; '
                . 'suspension of a settled team\'s member auto-exempts the group.',
            'No schema change. Records that fit::door_verdict() now decides composition for the '
                . 'join-accept and invitation-accept doors, and that user_enrolment_updated is '
                . 'observed to grant quota exemption to firm and frozen teams on suspension.'
        );

        upgrade_mod_savepoint(true, 2026080601, 'selfselectadvanced');
    }

    if ($oldversion < 2026080602) {
        // Marker-only release step: the guide request set completed
        // (maintainer flows d and e, 2026-08-06). Three new ticket
        // types - guidereduce (a guide asks their team limit DOWN, 0
        // meaning relieve-me, suggested successors in the request
        // text), dates (a window extension for the guide's team) and
        // penalty (plugin-level lateness relief; the gradebook
        // remains the editing teacher's). The queue's contract is
        // unchanged: resolving never mutates a team - the claimant
        // acts with the existing override, handover and assignment
        // tools. No schema change: the ticket table already carried
        // every column these types need. The marker proves this code
        // release executed.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.10 (2026080602). Guide requests: limit reduction/relief, '
                . 'date extension, penalty waiver.',
            'No schema change. Records that tickets::file() accepts dates and penalty '
                . 'for the assigned guide of a submitted, firm or frozen team, and that '
                . 'file_guidereduce() files the downward capacity ask sharing one live slot '
                . 'with the raise.'
        );

        upgrade_mod_savepoint(true, 2026080602, 'selfselectadvanced');
    }

    if ($oldversion < 2026080603) {
        // Marker-only release step: the group page's three leader action
        // clusters - invite members, leadership succession, submit to
        // guide - become Bootstrap tabs (maintainer, 2026-08-06), with
        // a pending nomination badging the succession tab and every
        // pane rendering stacked when JavaScript is off. No schema
        // change; the marker proves this code release executed, and it
        // carries the serial so installed sites rebuild their template
        // and string caches.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.11 (2026080603). Tabbed leader panel on the group page.',
            'No schema change. Records that the invite/succession/submit clusters render as '
                . 'tabs with a no-JavaScript stacked fallback.'
        );

        upgrade_mod_savepoint(true, 2026080603, 'selfselectadvanced');
    }

    if ($oldversion < 2026080604) {
        // Marker-only release step: an ineligible invite pick is refused
        // BY NAME with the candidate's current refusal sentence
        // (maintainer's live report, 2026-08-06). The selector keeps an
        // ineligible candidate's identity as a negated id instead of
        // collapsing every such pick into the same anonymous zero, and
        // the batch loop prefixes combination refusals - eligible alone,
        // refused once an earlier pick consumed the capacity - with the
        // candidate's name. No schema change; the marker proves this
        // code release executed and rebuilds the string and AMD caches.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.12 (2026080604). Invite refusals name the candidate and the reason.',
            'No schema change. Records the negated-id selector mapping and the named batch refusals.'
        );

        upgrade_mod_savepoint(true, 2026080604, 'selfselectadvanced');
    }

    if ($oldversion < 2026080605) {
        // Marker-only release step: the feasibility bound now counts the
        // INTERACTION of a value-minimum and a distinct-minimum on one
        // dimension (maintainer's live find, 2026-08-06): fills that
        // repeat a required value introduce at most one new distinct
        // value each, so the bound is the shortfall sum plus whatever
        // the fills cannot supply of the distinct shortfall, not their
        // max. Under-counting had admitted a second same-department
        // member into a team whose completion thereby became a dead
        // end. No schema change; the marker proves this code release
        // executed.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.13 (2026080605). Feasibility counts the value-distinct rule interaction.',
            'No schema change. Records the corrected per-dimension bound in the quota evaluator.'
        );

        upgrade_mod_savepoint(true, 2026080605, 'selfselectadvanced');
    }

    if ($oldversion < 2026080606) {
        // Marker-only release step: decision 62 and the honest end of a
        // deleted team. A queue worker (coordinator or manager, never
        // involved with the team) may return a FIRM team to FORMING
        // with a reason - the missing half of ruling 51-A2: approval
        // undone, guide relieved and notified, pending handover lapsed.
        // And delete_group() now runs dissolve_group()'s orphan sweep,
        // so a live join request can no longer outlive the team it
        // targets, and the request history reads "Accepted - the team
        // was later disbanded" instead of a bare Accepted beside a team
        // that no longer exists. No schema change; the marker proves
        // this code release executed.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.14 (2026080606). Return-to-forming verb; deleted teams end honestly.',
            'No schema change. Records the FIRM-to-FORMING coordinator arm of return_group() and '
                . 'the orphan sweep in delete_group().'
        );

        upgrade_mod_savepoint(true, 2026080606, 'selfselectadvanced');
    }

    if ($oldversion < 2026080607) {
        // Decision 63: consent-first disband (the maintainer's flow 1).
        // A leader no longer deletes a peopled forming team; they
        // REQUEST the wind-up with a composed reason, every confirmed
        // member is messaged, members leave one-click while the request
        // stands, and only a leader-alone team can be deleted. Three
        // columns carry the request; null timedisbandrequested means
        // none stands, so every existing team upgrades to "no request".
        $table = new xmldb_table('selfselectadvanced_group');
        $field = new xmldb_field(
            'timedisbandrequested',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'coregroupid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field(
            'disbandreason',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'timedisbandrequested'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field(
            'disbandreasonformat',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            null,
            null,
            null,
            'disbandreason'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.15 (2026080607). Consent-first disband: request, broadcast, '
                . 'one-click leave, leader-alone delete.',
            'Adds timedisbandrequested, disbandreason and disbandreasonformat to the group '
                . 'table. Null timedisbandrequested is "no request stands", which is what every '
                . 'pre-existing row means.'
        );

        upgrade_mod_savepoint(true, 2026080607, 'selfselectadvanced');
    }

    if ($oldversion < 2026080701) {
        // 1.20.16 is a JavaScript-only release: the invitation
        // candidate autocomplete no longer rejects its transport on a
        // failed search call. Core's form-autocomplete cannot recover
        // from a rejected transport (its in-progress latch resets only
        // on success and the loading icon's removal is chained off the
        // resolved promise), so one transient server refusal froze the
        // picker for the life of the page, silently. The transport now
        // surfaces the exception and answers the widget with an empty
        // result set, so the spinner clears and the next keystroke
        // retries. No schema change; the savepoint exists so the
        // installed version reaches the code version and site caches
        // (including the JS revision) rebuild.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.16 (2026080701). The candidate picker survives a failed '
                . 'search call: the failure is named in a dialog and typing retries.',
            'JavaScript-only change to the autocomplete transport; no schema change.'
        );

        upgrade_mod_savepoint(true, 2026080701, 'selfselectadvanced');
    }

    if ($oldversion < 2026080702) {
        // 1.20.17, decision 64: rules are the staff's to declare
        // breakable, never the accepting leader's. The join-accept
        // door's engine-tier refusals (and the source-minimum L1) were
        // a confirmable warning whose OK click wrote a QUOTA/L1
        // override in the LEADER'S name - observed live on 2026-08-07,
        // when a student leader admitted a second same-department
        // member under "SCOPE exactly 2" + "at least 4 distinct
        // departments" on five seats. Every rule refusal on that door
        // is now a hard stop for the ordinary decider (accept control
        // disabled, reason in plain words); bypass exists only through
        // :overriderules with a written reason. Refusal strings on the
        // join and leave doors were reworded into natural language.
        // No schema change.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.17 (2026080702). Rule refusals on the join-accept door are '
                . 'staff-only bypasses; the accepting leader sees a disabled control with the '
                . 'reason in plain words.',
            'Behavioural change only; no schema change.'
        );

        upgrade_mod_savepoint(true, 2026080702, 'selfselectadvanced');
    }

    if ($oldversion < 2026080703) {
        // 1.20.18, the maintainer's three UX rulings of 2026-08-07:
        // unreachable-composition refusals name the CONCRETE unmet
        // needs in the panel's vocabulary instead of an aggregate
        // count; a refused invitation acceptance answers with a notice
        // and a redirect, never the raw error page; and an invitation
        // whose acceptance the gate refuses renders with Accept
        // disabled (the gate CALLED, not transcribed - every refusal
        // tier, not just the hard maximum) while Decline stays live.
        // No schema change.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.18 (2026080703). Refusals name the concrete unmet needs; a '
                . 'refused acceptance is a notice, not an error page; an unacceptable '
                . 'invitation disables Accept.',
            'Behavioural change only; no schema change.'
        );

        upgrade_mod_savepoint(true, 2026080703, 'selfselectadvanced');
    }

    if ($oldversion < 2026080704) {
        // 1.20.19: the seam-audit batch (SEAM-AUDIT-20260807, the 5
        // HIGH findings + the failure-honesty family + the critic's
        // three). The move engine gains the decision-63 DISB verdict
        // (default refusal, pierceable only by the move-scope override
        // - maintainer ruling 2026-08-07: staff decisions always
        // honoured); the override guard measures the same commitments
        // basis as the gate; thirteen POST arms answer refusals with
        // notices instead of raw error pages; notification bodies
        // receive every placeholder they promise; the join-decide door
        // asks the standing conflict-of-interest rule; restores shift
        // the schedule dates; the guide-reminder markers join the
        // privacy provider; the deadline reminder warns on the
        // penalty's own basis. No schema change.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.19 (2026080704). Seam-audit batch: engine disband seal, '
                . 'aligned cap arithmetic, refusals as notices everywhere, honest '
                . 'notifications, COI on join-decide, restore date-shift, privacy and '
                . 'reminder-basis completeness.',
            'Behavioural changes only; no schema change.'
        );

        upgrade_mod_savepoint(true, 2026080704, 'selfselectadvanced');
    }

    if ($oldversion < 2026080705) {
        // 1.20.20: seam-audit batch B. Every remaining transcribed
        // gate calls its producer (the Answer tab, the guide queue's
        // Return, the contact-a-guide link, the leader's pending-invite
        // markers, the delete control, the ticket Claim button, the
        // coordinator involvement card); the auto-grouping planner
        // honours distinct rules and logs seat-template deficits; and
        // seven refusal/notification sentences now say exactly what is
        // true for the reader who gets them. No schema change.
        upgrade_log(
            UPGRADE_LOG_NOTICE,
            'mod_selfselectadvanced',
            'Upgraded to 1.20.20 (2026080705). Seam-audit batch B: every control asks the '
                . 'gate it posts to; the auto-grouping planner honours distinct rules; '
                . 'refusals say what is true for their reader.',
            'Behavioural changes only; no schema change.'
        );

        upgrade_mod_savepoint(true, 2026080705, 'selfselectadvanced');
    }

    return true;
}
