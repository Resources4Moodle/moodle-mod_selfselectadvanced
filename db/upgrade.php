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

    return true;
}
