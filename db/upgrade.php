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
 * Execute an upgrade from the given old version.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_selfselectadvanced_upgrade($oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

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
        // two, but it is not about a team - its groupid is 0 - and it
        // carries the number asked for, so a coordinator can grant it
        // in one action instead of copying the figure into an override
        // by hand.
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
        $duplicates = $DB->get_records_sql(
            "SELECT MIN(id) AS keepid, COUNT(id) AS dupcount, activityid, scope,
                    COALESCE(userid, 0) AS uid, COALESCE(groupid, 0) AS gid, COALESCE(moveid, 0) AS mid
               FROM {selfselectadvanced_override}
           GROUP BY activityid, scope, COALESCE(userid, 0), COALESCE(groupid, 0), COALESCE(moveid, 0)
             HAVING COUNT(id) > 1"
        );
        foreach ($duplicates as $dup) {
            // The oldest row survives - the same row store::get() now
            // returns - so the effective limits do not move under a
            // site at upgrade time.
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

        upgrade_mod_savepoint(true, 2026073110, 'selfselectadvanced');
    }

    return true;
}
