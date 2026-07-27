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
    $before = $DB->get_record('selfselectadvanced', ['id' => $data->id], '*', MUST_EXIST);
    $result = $DB->update_record('selfselectadvanced', $data);

    $instance = $DB->get_record('selfselectadvanced', ['id' => $data->id], '*', MUST_EXIST);
    selfselectadvanced_grade_item_update($instance);

    // Spec 4A.8 / 14.7: record limit changes with old and new values.
    $limits = ['minsize', 'maxsize', 'maxlead', 'maxmembership', 'maxguided'];
    $old = [];
    $new = [];
    foreach ($limits as $limit) {
        if ((int) $before->$limit !== (int) $instance->$limit) {
            $old[$limit] = (int) $before->$limit;
            $new[$limit] = (int) $instance->$limit;
        }
    }
    if ($new) {
        [, $cm] = get_course_and_cm_from_instance($instance->id, 'selfselectadvanced', $instance->course);
        \mod_selfselectadvanced\event\limits_changed::create([
            'objectid' => $instance->id,
            'context' => context_module::instance($cm->id),
            'other' => ['oldvalues' => $old, 'newvalues' => $new],
        ])->trigger();
    }

    // Spec 11: date or penalty edits recompute the full ledger
    // immediately (the nightly task reconciles as defence in depth).
    \mod_selfselectadvanced\local\penalty\ledger::recompute_all(
        \mod_selfselectadvanced\activity::from_instance((int) $instance->id)
    );

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
    $DB->delete_records('selfselectadvanced_volunteer', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_move', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_quota', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_qslot', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_template', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_agrun', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_group', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced', ['id' => $id]);
    // Reminder markers die with the instance (audit item 23).
    $DB->delete_records('user_preferences', ['name' => 'mod_selfselectadvanced_reminded_' . $id]);
    if ($groupids) {
        [$gsql, $gparams] = $DB->get_in_or_equal(
            array_map(static fn($gid) => 'mod_selfselectadvanced_gremind_' . $gid, $groupids)
        );
        $DB->delete_records_select('user_preferences', "name $gsql", $gparams);
    }

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

    return grade_update(
        'mod/selfselectadvanced',
        $instance->course,
        'mod',
        'selfselectadvanced',
        $instance->id,
        0,
        $grades,
        $params
    );
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

    return grade_update(
        'mod/selfselectadvanced',
        $instance->course,
        'mod',
        'selfselectadvanced',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
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
    // Rebuild from activity truth (audit item 8): the ledger recomputes
    // the sequence-of-joining decomposition and republishes.
    \mod_selfselectadvanced\local\penalty\ledger::push_grades(
        \mod_selfselectadvanced\activity::from_instance((int) $instance->id),
        $userid
    );
}


/**
 * Add plugin tools to the activity's secondary/settings navigation.
 *
 * @param settings_navigation $settingsnav the settings navigation
 * @param navigation_node $node this activity's node
 */
/**
 * Course-reset form fragment (audit item 9).
 *
 * @param MoodleQuickForm $mform the reset form
 */
function selfselectadvanced_reset_course_form_definition($mform): void {
    $mform->addElement('header', 'selfselectadvancedheader', get_string('modulenameplural', 'mod_selfselectadvanced'));
    $mform->addElement(
        'advcheckbox',
        'reset_selfselectadvanced_groups',
        get_string('resetgroups', 'mod_selfselectadvanced')
    );
}

/**
 * Course-reset defaults.
 *
 * @param stdClass $course the course
 * @return array default values
 */
function selfselectadvanced_reset_course_form_defaults($course): array {
    return ['reset_selfselectadvanced_groups' => 1];
}

/**
 * Purge per-user data on course reset: groups, memberships, moves,
 * snapshots, penalties, overrides, auto-group runs and grades. The
 * configuration (settings, quota rules, slots, templates) and the
 * site-wide participant attributes remain.
 *
 * @param stdClass $data reset form data
 * @return array status rows
 */
function selfselectadvanced_reset_userdata($data): array {
    global $DB;

    $status = [];
    if (empty($data->reset_selfselectadvanced_groups)) {
        return $status;
    }
    $component = get_string('modulenameplural', 'mod_selfselectadvanced');
    foreach ($DB->get_records('selfselectadvanced', ['course' => $data->courseid]) as $instance) {
        $groupids = $DB->get_fieldset_select('selfselectadvanced_group', 'id', 'activityid = ?', [$instance->id]);
        if ($groupids) {
            [$insql, $inparams] = $DB->get_in_or_equal($groupids);
            $DB->delete_records_select('selfselectadvanced_member', "groupid $insql", $inparams);
            $DB->delete_records_select('selfselectadvanced_snapshot', "groupid $insql", $inparams);
        }
        $DB->delete_records('selfselectadvanced_penalty', ['activityid' => $instance->id]);
        $DB->delete_records('selfselectadvanced_move', ['activityid' => $instance->id]);
        $DB->delete_records('selfselectadvanced_agrun', ['activityid' => $instance->id]);
        $DB->delete_records('selfselectadvanced_override', ['activityid' => $instance->id]);
        $DB->delete_records('selfselectadvanced_group', ['activityid' => $instance->id]);
        $DB->delete_records('user_preferences', ['name' => 'mod_selfselectadvanced_reminded_' . $instance->id]);
        if (empty($data->reset_gradebook_grades)) {
            selfselectadvanced_grade_item_update($instance, 'reset');
        }
        $status[] = [
            'component' => $component,
            'item' => get_string('resetgroupsdone', 'mod_selfselectadvanced', format_string($instance->name)),
            'error' => false,
        ];
    }

    return $status;
}

/**
 * Serve files from the proposal filearea (itemid = plugin group id).
 * Visible to confirmed members of that group and to staff with
 * viewall.
 *
 * @param stdClass $course the course
 * @param stdClass $cm the course module
 * @param context $context module context
 * @param string $filearea file area name
 * @param array $args itemid + path
 * @param bool $forcedownload force download flag
 * @param array $options stream options
 * @return bool false when not found
 */
function selfselectadvanced_pluginfile(
    $course,
    $cm,
    $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    global $DB, $USER;

    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== 'proposal') {
        return false;
    }
    require_login($course, false, $cm);
    $groupid = (int) array_shift($args);
    $group = $DB->get_record('selfselectadvanced_group', ['id' => $groupid], '*', MUST_EXIST);
    if ((int) $group->activityid !== (int) $cm->instance) {
        return false;
    }
    if (!has_capability('mod/selfselectadvanced:viewall', $context)) {
        $ismember = $DB->record_exists('selfselectadvanced_member', [
            'groupid' => $groupid,
            'userid' => $USER->id,
            'status' => 'confirmed',
        ]);
        if (!$ismember) {
            return false;
        }
    }
    $fs = get_file_storage();
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = $fs->get_file($context->id, 'mod_selfselectadvanced', 'proposal', $groupid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);

    return true;
}

/**
 * Add the manager/guide tool pages to the activity settings menu.
 *
 * @param settings_navigation $settingsnav the settings navigation
 * @param navigation_node $node this activity's node
 */
function selfselectadvanced_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node): void {
    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = $cm->context;
    if (has_capability('mod/selfselectadvanced:manage', $context)) {
        $node->add(
            get_string('composition', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/quotas.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
        $node->add(
            get_string('managerdashboard', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/manage.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
        $node->add(
            get_string('pendingmoves', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/moves.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
        $node->add(
            get_string('notificationtemplates', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/templates.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
        $node->add(
            get_string('analyticspage', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/analytics.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
    }
    if (has_capability('mod/selfselectadvanced:override', $context)) {
        $node->add(
            get_string('overrides', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/overrides.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
    }
    if (has_capability('mod/selfselectadvanced:viewall', $context)) {
        $node->add(
            get_string('penaltyledger', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/ledger.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
    }
    if (has_capability('mod/selfselectadvanced:guide', $context)) {
        $node->add(
            get_string('guidedashboard', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/guide.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
    }
}
