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

use moodle_exception;
use stdClass;

/**
 * Pre-defined department/sub-department vocabulary.
 *
 * Site-wide tree in the course-categories format (parent, depth, path,
 * sortorder), so multiple levels are possible; the participant
 * attribute fields use level 1 (department) and level 2
 * (sub-department). Attribute rows keep storing the plain names, so
 * quota dimensions and existing data are unaffected — this table is
 * the allowed vocabulary, enforced at the edit form and the CSV
 * importer.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class depts {
    /**
     * All categories ordered as a depth-first tree.
     *
     * @return stdClass[] records ordered for display, id-keyed
     */
    public static function get_all(): array {
        global $DB;

        $records = $DB->get_records('selfselectadvanced_dept', null, 'sortorder, id');
        // Depth-first order: sort by path of sortorders.
        $bypath = [];
        foreach ($records as $record) {
            $key = '';
            foreach (explode('/', trim($record->path, '/')) as $ancestorid) {
                $key .= sprintf('%08d/', $records[$ancestorid]->sortorder ?? 0) . sprintf('%08d/', $ancestorid);
            }
            $bypath[$key] = $record;
        }
        ksort($bypath);
        $ordered = [];
        foreach ($bypath as $record) {
            $ordered[$record->id] = $record;
        }

        return $ordered;
    }

    /**
     * Create a category.
     *
     * @param string $name the name
     * @param int $parent parent category id, 0 for top level
     * @return stdClass the new record
     */
    public static function create(string $name, int $parent = 0): stdClass {
        global $DB;

        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 100) {
            throw new moodle_exception('errdeptname', 'mod_selfselectadvanced');
        }
        $parentrecord = null;
        if ($parent) {
            $parentrecord = $DB->get_record('selfselectadvanced_dept', ['id' => $parent], '*', MUST_EXIST);
        }
        if ($DB->record_exists('selfselectadvanced_dept', ['parent' => $parent, 'name' => $name])) {
            throw new moodle_exception('errdeptduplicate', 'mod_selfselectadvanced', '', $name);
        }
        $now = time();
        $record = (object) [
            'name' => $name,
            'parent' => $parent,
            'depth' => $parentrecord ? (int) $parentrecord->depth + 1 : 1,
            'path' => '',
            'sortorder' => 1 + (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(sortorder), 0) FROM {selfselectadvanced_dept} WHERE parent = ?',
                [$parent]
            ),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('selfselectadvanced_dept', $record);
        $record->path = ($parentrecord ? $parentrecord->path : '') . '/' . $record->id;
        $DB->set_field('selfselectadvanced_dept', 'path', $record->path, ['id' => $record->id]);

        return $record;
    }

    /**
     * Rename a category.
     *
     * @param int $id category id
     * @param string $name new name
     */
    public static function rename(int $id, string $name): void {
        global $DB;

        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 100) {
            throw new moodle_exception('errdeptname', 'mod_selfselectadvanced');
        }
        $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id], '*', MUST_EXIST);
        $clash = $DB->get_record('selfselectadvanced_dept', ['parent' => $record->parent, 'name' => $name]);
        if ($clash && (int) $clash->id !== $id) {
            throw new moodle_exception('errdeptduplicate', 'mod_selfselectadvanced', '', $name);
        }
        $DB->update_record('selfselectadvanced_dept', (object) [
            'id' => $id,
            'name' => $name,
            'timemodified' => time(),
        ]);
    }

    /**
     * Delete a category; refused while it has children or ingested
     * attribute rows use its name at its level.
     *
     * @param int $id category id
     */
    public static function delete(int $id): void {
        global $DB;

        $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id], '*', MUST_EXIST);
        if ($DB->record_exists('selfselectadvanced_dept', ['parent' => $id])) {
            throw new moodle_exception('errdeptchildren', 'mod_selfselectadvanced', '', $record->name);
        }
        $field = (int) $record->depth === 1 ? 'department' : 'subdepartment';
        $inuse = $DB->record_exists_select(
            'selfselectadvanced_userattr',
            $DB->sql_equal($field, ':name', false),
            ['name' => $record->name]
        );
        if ($inuse) {
            throw new moodle_exception('errdeptinuse', 'mod_selfselectadvanced', '', $record->name);
        }
        $DB->delete_records('selfselectadvanced_dept', ['id' => $id]);
    }

    /**
     * Swap a category with its previous/next sibling.
     *
     * @param int $id category id
     * @param int $direction -1 up, 1 down
     */
    public static function move(int $id, int $direction): void {
        global $DB;

        $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id], '*', MUST_EXIST);
        $siblings = $DB->get_records('selfselectadvanced_dept', ['parent' => $record->parent], 'sortorder, id');
        $ids = array_keys($siblings);
        $pos = array_search($id, $ids);
        $swap = $ids[$pos + $direction] ?? null;
        if ($swap === null) {
            return;
        }
        $DB->set_field('selfselectadvanced_dept', 'sortorder', $siblings[$swap]->sortorder, ['id' => $id]);
        $DB->set_field('selfselectadvanced_dept', 'sortorder', $record->sortorder, ['id' => $swap]);
    }

    /**
     * Menu of top-level department names.
     *
     * @return string[] name => name, display-ordered
     */
    public static function departments_menu(): array {
        $menu = [];
        foreach (self::get_all() as $record) {
            if ((int) $record->depth === 1) {
                $menu[$record->name] = $record->name;
            }
        }

        return $menu;
    }

    /**
     * Sub-department names grouped by their parent department.
     *
     * @return array department name => (name => name)
     */
    public static function subdepartments_grouped(): array {
        $all = self::get_all();
        $grouped = [];
        foreach ($all as $record) {
            if ((int) $record->depth === 2 && isset($all[$record->parent])) {
                $grouped[$all[$record->parent]->name][$record->name] = $record->name;
            }
        }

        return $grouped;
    }

    /**
     * Is this department / sub-department pair part of the vocabulary?
     * Empty values are always acceptable; a sub-department requires its
     * own department.
     *
     * @param string $department department name ('' = none)
     * @param string $subdepartment sub-department name ('' = none)
     * @return string|null null when valid, else the offending field name
     */
    public static function validate_pair(string $department, string $subdepartment): ?string {
        if ($department === '' && $subdepartment === '') {
            return null;
        }
        if ($department === '') {
            return 'subdepartment';
        }
        $departments = self::departments_menu();
        if (!isset($departments[$department])) {
            return 'department';
        }
        if ($subdepartment === '') {
            return null;
        }
        $grouped = self::subdepartments_grouped();
        if (!isset($grouped[$department][$subdepartment])) {
            return 'subdepartment';
        }

        return null;
    }

    /**
     * Does the vocabulary have any entries yet? While empty, free-text
     * input stays allowed so existing sites are not locked out before
     * an administrator defines the tree.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        global $DB;

        return $DB->record_exists('selfselectadvanced_dept', []);
    }
}
