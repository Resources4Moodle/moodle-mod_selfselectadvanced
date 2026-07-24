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

namespace mod_selfselectadvanced\local\attributes;

use stdClass;

/**
 * Participant attributes: site-wide, plugin-only records of gender,
 * department, sub-department and mobile (spec 8.1, D8, U4).
 *
 * Never reads from or writes to site profile fields, cohorts or any
 * external store, and never creates user accounts (C11). Activity
 * roles get read access where flows need values; only holders of the
 * system-context ingestattributes capability write.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string[] The quota dimensions (mobile is contact info, not a dimension). */
    public const DIMENSIONS = ['gender', 'department', 'subdepartment', 'program'];

    /**
     * Attribute records for a set of users, keyed by userid.
     *
     * Users without a record are absent from the result; callers treat
     * them as "attributes missing" and flag, never crash (spec 8.1).
     *
     * @param int[] $userids the users
     * @return stdClass[] userid-keyed records
     */
    public static function get_for_users(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $userids));

        $records = $DB->get_records_select('selfselectadvanced_userattr', "userid $insql", $params);
        $byuser = [];
        foreach ($records as $record) {
            $byuser[(int) $record->userid] = $record;
        }

        return $byuser;
    }

    /**
     * One user's attribute record, or null.
     *
     * @param int $userid the user
     * @return stdClass|null
     */
    public static function get(int $userid): ?stdClass {
        global $DB;

        return $DB->get_record('selfselectadvanced_userattr', ['userid' => $userid]) ?: null;
    }

    /**
     * Create or update a user's attributes (admin write path).
     *
     * @param int $userid the target user (must exist in Moodle)
     * @param array $values any of gender, department, subdepartment, mobile
     * @param int $actorid the acting administrator
     * @return stdClass the stored record
     */
    public static function set(int $userid, array $values, int $actorid): stdClass {
        global $DB;

        // The user must already exist: this plugin never creates accounts (C11).
        $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);

        $now = time();
        $record = $DB->get_record('selfselectadvanced_userattr', ['userid' => $userid]);
        $isnew = !$record;
        if ($isnew) {
            $record = (object) [
                'userid' => $userid,
                'gender' => null,
                'department' => null,
                'subdepartment' => null,
                'mobile' => null,
                'seatlocation' => null,
                'program' => null,
                'timecreated' => $now,
            ];
        }
        foreach (['gender', 'department', 'subdepartment', 'mobile', 'seatlocation', 'program'] as $field) {
            if (array_key_exists($field, $values)) {
                $value = trim((string) $values[$field]);
                $record->$field = $value === '' ? null : $value;
            }
        }
        $record->usermodified = $actorid;
        $record->timemodified = $now;
        if ($isnew) {
            $record->id = $DB->insert_record('selfselectadvanced_userattr', $record);
        } else {
            $DB->update_record('selfselectadvanced_userattr', $record);
        }

        \mod_selfselectadvanced\event\attributes_updated::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
        ])->trigger();

        self::purge_value_cache();

        return $record;
    }

    /**
     * Remove a user's attribute record (privacy delete and the
     * user_deleted observer, review item M3).
     *
     * @param int $userid the user
     */
    public static function delete_for_user(int $userid): void {
        global $DB;

        $DB->delete_records('selfselectadvanced_userattr', ['userid' => $userid]);
        self::purge_value_cache();
    }

    /**
     * Distinct non-empty values of a dimension, for the quota value
     * pickers (spec 4.7). Cached in MUC, invalidated on every write.
     *
     * @param string $dimension gender, department or subdepartment
     * @return string[] sorted distinct values
     */
    public static function distinct_values(string $dimension): array {
        global $DB;

        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new \coding_exception('Unknown attribute dimension: ' . $dimension);
        }

        $cache = \cache::make('mod_selfselectadvanced', 'attrvalues');
        $values = $cache->get($dimension);
        if ($values === false) {
            $values = $DB->get_fieldset_sql(
                "SELECT DISTINCT $dimension
                   FROM {selfselectadvanced_userattr}
                  WHERE $dimension IS NOT NULL AND $dimension <> ''
               ORDER BY $dimension"
            );
            $cache->set($dimension, $values);
        }

        return $values;
    }

    /**
     * Purge the distinct-value cache (every write path and the
     * user_deleted observer).
     */
    public static function purge_value_cache(): void {
        \cache::make('mod_selfselectadvanced', 'attrvalues')->purge();
    }

    /**
     * A compact display line of a user's attributes for staff rosters
     * (gender, department, sub-department; mobile only for viewall
     * holders per decision U4).
     *
     * @param stdClass|null $record the attribute record
     * @param bool $includemobile whether the viewer may see the mobile number
     * @return string localised summary, or the missing-attributes flag
     */
    public static function display_line(?stdClass $record, bool $includemobile): string {
        if (!$record) {
            return get_string('attrsmissing', 'mod_selfselectadvanced');
        }
        $parts = [];
        foreach (self::DIMENSIONS as $field) {
            if (!empty($record->$field)) {
                $parts[] = s($record->$field);
            }
        }
        if ($includemobile && !empty($record->mobile)) {
            $parts[] = s($record->mobile);
        }

        return $parts ? implode(' · ', $parts) : get_string('attrsmissing', 'mod_selfselectadvanced');
    }
}
