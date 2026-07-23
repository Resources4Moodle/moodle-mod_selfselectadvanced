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
 * Library of interface functions for mod_selfselectadvanced.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare the features this activity module supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed true if supported, null if unknown
 */
function selfselectadvanced_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_COLLABORATION;
        default:
            return null;
    }
}

/**
 * Add a new instance of the activity.
 *
 * @param stdClass $data form data
 * @param mod_selfselectadvanced_mod_form|null $mform the form
 * @return int the new instance id
 */
function selfselectadvanced_add_instance(stdClass $data, $mform = null): int {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('selfselectadvanced', $data);

    selfselectadvanced_grade_item_update($data);

    return $data->id;
}

/**
 * Update an existing instance of the activity.
 *
 * Recomputation of the penalty ledger and the grandfathering compliance
 * pass (spec section 4A.8) are wired in here by the rules engine once a
 * gatekeeper exists for this instance's groups.
 *
 * @param stdClass $data form data
 * @param mod_selfselectadvanced_mod_form|null $mform the form
 * @return bool success
 */
function selfselectadvanced_update_instance(stdClass $data, $mform = null): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $result = $DB->update_record('selfselectadvanced', $data);

    $instance = $DB->get_record('selfselectadvanced', ['id' => $data->id], '*', MUST_EXIST);
    selfselectadvanced_grade_item_update($instance);

    return $result;
}

/**
 * Delete an instance and all its plugin-side data.
 *
 * Core course groups created by freezing remain in place: by then they
 * are course data (good-neighbour rule, spec section 14.5).
 *
 * @param int $id instance id
 * @return bool success
 */
function selfselectadvanced_delete_instance($id): bool {
    global $DB;

    $instance = $DB->get_record('selfselectadvanced', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    $groupids = $DB->get_fieldset_select('selfselectadvanced_group', 'id', 'activityid = ?', [$id]);
    if ($groupids) {
        [$insql, $inparams] = $DB->get_in_or_equal($groupids);
        $DB->delete_records_select('selfselectadvanced_member', "groupid $insql", $inparams);
        $DB->delete_records_select('selfselectadvanced_snapshot', "groupid $insql", $inparams);
    }
    $DB->delete_records('selfselectadvanced_penalty', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_override', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_move', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_quota', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_agrun', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_group', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced', ['id' => $id]);

    selfselectadvanced_grade_item_delete($instance);

    return true;
}

/**
 * Create or update the grade item for an instance.
 *
 * @param stdClass $instance instance record with at least id, course, name, grade
 * @param array|object|null $grades grades to set, or 'reset'
 * @return int GRADE_UPDATE_OK or failure code
 */
function selfselectadvanced_grade_item_update(stdClass $instance, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $instance->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => (float) $instance->grade,
        'grademin' => 0,
    ];
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/selfselectadvanced', $instance->course, 'mod', 'selfselectadvanced',
        $instance->id, 0, $grades, $params);
}

/**
 * Delete the grade item for an instance.
 *
 * @param stdClass $instance instance record
 * @return int GRADE_UPDATE_OK or failure code
 */
function selfselectadvanced_grade_item_delete(stdClass $instance): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/selfselectadvanced', $instance->course, 'mod', 'selfselectadvanced',
        $instance->id, 0, null, ['deleted' => 1]);
}

/**
 * Push current grades into the gradebook.
 *
 * A student's grade is the activity point value minus the penalty of each
 * group they are a confirmed member of, floored at zero. Students in no
 * firm or frozen group keep a null grade until placed. The penalty ledger
 * (slice 9) populates this; until then the grade item exists with no
 * user grades.
 *
 * @param stdClass $instance instance record
 * @param int $userid a single user to update, 0 for all
 * @param bool $nullifnone insert null grade when the user has none
 */
function selfselectadvanced_update_grades(stdClass $instance, int $userid = 0, bool $nullifnone = true): void {
    selfselectadvanced_grade_item_update($instance);
}
