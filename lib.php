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
 * Settle the guide-side switches against students-approach mode.
 *
 * The form disables guide volunteering and guide-first mode while the
 * switch is on, and a disabled control submits nothing - so an activity
 * that already had either of them turned on could never adopt the new
 * mode: the value the user cannot reach would come straight back and
 * the validator would refuse the save, with no way forward. Turning the
 * switch on therefore settles them here, which is what the setting
 * means anyway.
 *
 * @param stdClass $data form data, modified in place
 */
function selfselectadvanced_settle_studentapproach(stdClass $data): void {
    if (empty($data->studentapproach)) {
        return;
    }
    $data->guidevolunteer = 0;
    $data->guidemode = 0;
    $data->eoienabled = 0;
}

/**
 * Split the ticket disclaimer editor's ['text' => ..., 'format' => ...]
 * array back into the two scalar columns it is stored as (1.20.43).
 *
 * The standard editor save idiom for a rich-text field beyond intro's
 * own automatic one: mod_form.php names the element
 * ticketdisclaimer_editor precisely so it cannot collide with these two
 * real columns while the array is still on $data, and this is the other
 * half of that idiom, called from both add_instance() and
 * update_instance() before the row is written.
 *
 * @param stdClass $data form data, modified in place
 */
function selfselectadvanced_split_ticketdisclaimer_editor(stdClass $data): void {
    if (!isset($data->ticketdisclaimer_editor) || !is_array($data->ticketdisclaimer_editor)) {
        return;
    }
    $data->ticketdisclaimer = $data->ticketdisclaimer_editor['text'] ?? '';
    $data->ticketdisclaimerformat = $data->ticketdisclaimer_editor['format'] ?? FORMAT_HTML;
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

    selfselectadvanced_settle_studentapproach($data);
    selfselectadvanced_split_ticketdisclaimer_editor($data);
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
    global $DB, $USER;

    selfselectadvanced_settle_studentapproach($data);
    selfselectadvanced_split_ticketdisclaimer_editor($data);
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

    $activity = \mod_selfselectadvanced\activity::from_instance((int) $instance->id);

    // The settings-edit hole (finding-9): a per-field override falls
    // through to the activity for every field it does not set, so
    // editing a setting can invalidate the MERGED tuple of rows nobody
    // touched - an extension granted against the old cutoff, a group
    // whose merged minsize now exceeds its overridden maxsize. Those
    // rows are parked back to 'pending' BEFORE the ledger recompute
    // below, so the recompute never consumes a merge this very edit
    // invalidated. Newly CONSISTENT pending rows are deliberately not
    // activated here: store::recheck_pending() already runs on every
    // visit to the overrides page and heals them there.
    $tuplefields = ['timeopen', 'timedue', 'timecutoff', 'minsize', 'maxsize', 'maxlead', 'maxmembership'];
    foreach ($tuplefields as $tuplefield) {
        if ((int) $before->$tuplefield !== (int) $instance->$tuplefield) {
            // The id is ALWAYS set in Moodle - $USER->id is 0 for a
            // session with nobody in it - so `?? get_admin()` never
            // fired and an actorless edit stamped every parked row with
            // usermodified = 0. `?:` is the test that was meant.
            $admin = get_admin();
            \mod_selfselectadvanced\local\override\store::park_inconsistent(
                $activity,
                (int) ($USER->id ?? 0) ?: (int) ($admin->id ?? 0)
            );
            break;
        }
    }

    // Spec 11: date or penalty edits recompute the full ledger
    // immediately (the nightly task reconciles as defence in depth).
    \mod_selfselectadvanced\local\penalty\ledger::recompute_all($activity);

    return $result;
}

/**
 * Delete an instance and all its plugin-side data.
 *
 * Core course groups this activity created remain in place: by then they
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
    // Queue tickets, guide interests and queued digest items are keyed
    // to the activity too: nothing may outlive the activity it points
    // at, or the rows become unreachable orphans.
    $DB->delete_records('selfselectadvanced_ticket', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_contact', ['activityid' => $id]);
    $DB->delete_records('selfselectadvanced_eoi', ['activityid' => $id]);
    \mod_selfselectadvanced\local\notifier::purge_activity_digests((int) $id);
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
    // The auto-approval resume cursor dies with the instance.
    unset_config('autoapprovecursor_' . (int) $id, 'mod_selfselectadvanced');

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
    global $CFG, $DB;

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
        // The groups these point at are about to go: a ticket or an
        // interest left behind would name a team that no longer
        // exists, and the queue would list work nobody can do.
        $DB->delete_records('selfselectadvanced_ticket', ['activityid' => $instance->id]);
        $DB->delete_records('selfselectadvanced_contact', ['activityid' => $instance->id]);
        $DB->delete_records('selfselectadvanced_eoi', ['activityid' => $instance->id]);
        \mod_selfselectadvanced\local\notifier::purge_activity_digests((int) $instance->id);
        // Proposal attachments are keyed by plugin group id in the
        // module's own context, and a reset does NOT remove that
        // context - so without this the files outlive every group that
        // owned them, unreachable and uncounted against nobody's quota.
        // (Deleting the activity itself is different: core drops the
        // whole context, files included.)
        if ($groupids) {
            $resetcm = get_coursemodule_from_instance('selfselectadvanced', $instance->id, $data->courseid, false, IGNORE_MISSING);
            if ($resetcm) {
                $fs = get_file_storage();
                $resetcontext = context_module::instance($resetcm->id);
                foreach ($groupids as $groupid) {
                    $fs->delete_area_files(
                        $resetcontext->id,
                        'mod_selfselectadvanced',
                        'proposal',
                        (int) $groupid
                    );
                }
            }
        }
        // LIFE-002, maintainer ruling 2026-08-13, option B. The Moodle course
        // groups this plugin made SURVIVE a reset - they are course data, and
        // another activity's group forum or assignment may already depend on
        // them, which is the same good-neighbour reasoning as ruling 98. But
        // the plugin's claim on them does not survive, and that half was
        // missing: the group rows carrying coregroupid were deleted while the
        // course groups kept this plugin's idnumber (the group's pluginuid),
        // its description marker and its component-tagged memberships.
        //
        // WHY A DANGLING CLAIM IS WORSE THAN UNTIDY. build_pluginuid() derives
        // the uid from the plugin group's row id, and freeze::live_coregroupid()
        // finds a mirror by idnumber = pluginuid. After a reset the ids start
        // again, so a NEW group could be handed the abandoned course group of a
        // deleted one - inheriting its members. Stripping the idnumber breaks
        // that adoption path; clearing the component tag stops the plugin
        // claiming memberships it no longer manages.
        //
        // The group, its name and its members stay exactly where they are.
        if ($groupids) {
            require_once($CFG->dirroot . '/group/lib.php');
            [$mrsql, $mrparams] = $DB->get_in_or_equal($groupids);
            $mirrors = $DB->get_fieldset_select(
                'selfselectadvanced_group',
                'coregroupid',
                "id $mrsql AND coregroupid IS NOT NULL",
                $mrparams
            );
            foreach (array_filter(array_map('intval', $mirrors)) as $coregroupid) {
                if (!$DB->record_exists('groups', ['id' => $coregroupid])) {
                    continue;
                }
                // Not groups_update_group(): the name and description are the
                // course's now, and only the machine-readable claim is ours to
                // withdraw.
                $DB->set_field('groups', 'idnumber', '', ['id' => $coregroupid]);
                $DB->set_field_select(
                    'groups_members',
                    'component',
                    '',
                    'groupid = ? AND component = ?',
                    [$coregroupid, 'mod_selfselectadvanced']
                );
                $DB->set_field_select(
                    'groups_members',
                    'itemid',
                    0,
                    'groupid = ? AND itemid <> 0',
                    [$coregroupid]
                );
            }
        }
        $DB->delete_records('selfselectadvanced_group', ['activityid' => $instance->id]);
        $DB->delete_records('user_preferences', ['name' => 'mod_selfselectadvanced_reminded_' . $instance->id]);
        // The per-group reminder markers go with the groups (external audit
        // PRIV-002, 2026-08-13). Instance deletion has always done this; reset
        // deleted the activity-level marker and left the group-level ones
        // behind, pointing at group ids that no longer exist - and since the
        // privacy provider discovers them by walking CURRENT groups, an
        // orphan is not merely stale, it is unreachable by the export that
        // would otherwise disclose it.
        if ($groupids) {
            [$psql, $pparams] = $DB->get_in_or_equal(
                array_map(static fn($gid) => 'mod_selfselectadvanced_gremind_' . (int) $gid, $groupids)
            );
            $DB->delete_records_select('user_preferences', "name $psql", $pparams);
        }
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
 * Whether staff may delete one of THIS plugin's memberships from a
 * mirrored course group through a core UI.
 *
 * Core calls this from groups_remove_member_allowed(), and only for
 * rows whose `component` column is set - i.e. exactly the memberships
 * this plugin writes. While the team is FROZEN the answer is no: the
 * plugin roster is authoritative, and a removal made in the groups UI,
 * the participants page, the enrolment UI or the web service would be
 * re-added by the next sync anyway. Refusing it turns "missing" drift
 * from something merely reported into something that cannot happen.
 *
 * While the team is FIRM the edit is allowed - the mirror still
 * converges on the plugin roster at the next sync, so composition
 * stays plugin-authoritative either way, and the way out for staff is
 * the plugin's own tools. A stale itemid (the plugin group is gone)
 * yields false from get_field, which is not FROZEN, so removal is
 * allowed - the right answer for an orphan.
 *
 * @param int $itemid the plugin group id recorded on the membership
 * @param int $groupid the course group id
 * @param int $userid the user being removed
 * @return bool true when core may delete the row
 */
function selfselectadvanced_allow_group_member_remove($itemid, $groupid, $userid): bool {
    global $DB;

    $state = $DB->get_field('selfselectadvanced_group', 'state', ['id' => (int) $itemid]);

    return $state !== \mod_selfselectadvanced\local\state::FROZEN;
}

/**
 * The full names of the people a core-group sync could not add.
 *
 * Names ONLY. A refusal notice is read by guides and managers, and the
 * per-activity contact-privacy setting means neither an email address
 * nor a phone number may travel in one (cardinal rule).
 *
 * @param int[] $userids the refused userids from freeze::sync_core_group()
 * @return string comma-separated full names
 */
function selfselectadvanced_refused_names(array $userids): string {
    return implode(', ', selfselectadvanced_user_names($userids));
}

/**
 * Full names for a bounded list of userids, in ONE query.
 *
 * Names ONLY, never an email address or a phone number: the confirm
 * pages that use this (unfreeze restore preview, dissolve parking list)
 * are read by managers and non-editing teachers, the exact audience the
 * per-activity contact-privacy setting restricts (cardinal rule).
 *
 * The lists this serves are bounded by a team's roster, so one batched
 * read is the whole cost.
 *
 * @param int[] $userids the people to name
 * @return string[] userid => full name, in the order given
 */
function selfselectadvanced_user_names(array $userids): array {
    global $DB;

    $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
    if (!$userids) {
        return [];
    }
    [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'un');
    $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', true)->selects;
    $rows = $DB->get_records_sql("SELECT id{$namefields} FROM {user} WHERE id $insql", $params);

    $names = [];
    foreach ($userids as $userid) {
        $names[$userid] = isset($rows[$userid]) ? fullname($rows[$userid]) : (string) $userid;
    }

    return $names;
}

/**
 * The full name of a userid, but ONLY when this activity's own candidate
 * pool contains them.
 *
 * The invite arm of group.php names people back to the leader when a pick
 * is refused, and the id it names them from arrived in a form post. That
 * id is not trusted to name anybody. Until this function existed both
 * branches handed whatever id was submitted to core_user::get_user() and
 * printed the result, which turned a refusal notice into a site-wide
 * userid-to-name oracle: a student could post -1, -2, -3 and read back
 * the names of people in other courses, suspended accounts and staff, one
 * per submit. Names are blessed among PARTICIPANTS, so a caller prints a
 * name when this returns one and a sentence that identifies nobody when
 * it returns null.
 *
 * Active enrolment is part of the question, not a detail of it: the
 * candidate search never shows a suspended enrolment, so resolving a name
 * for one would disclose an account the leader was never offered.
 *
 * Only a name is ever returned. A refusal notice is read by whoever leads
 * a team, and the per-activity contact-privacy setting means no email
 * address and no phone number may travel in one (cardinal rule).
 *
 * @param context_module $context the activity context whose pool decides
 * @param int $userid the id being resolved, already made positive
 * @return string|null the full name, or null when they are not a candidate
 */
function selfselectadvanced_candidate_name(context_module $context, int $userid): ?string {
    if ($userid <= 0 || !is_enrolled($context, $userid, 'mod/selfselectadvanced:respond', true)) {
        return null;
    }

    $user = \core_user::get_user($userid);

    return $user ? fullname($user) : null;
}

/**
 * The sentence a refused human action should show.
 *
 * A page arm can be refused two ways that mean the same thing to the
 * person: the workflow decided no, or the authority it needed was taken
 * away between the page rendering and the click. The plugin has always
 * answered the first as a notice. The second escaped to Moodle's
 * permission screen, because it arrives as core's own exception rather
 * than as one of ours - so an administrator adjusting a role mid-session
 * turned an ordinary group action into what reads like a fault, for
 * somebody who had done nothing but press a button that was on the page
 * a moment earlier.
 *
 * Only the PAGE arms use this. The services keep throwing core's
 * exception, so web services, scheduled tasks and CLI still fail loudly
 * where a missing capability is a genuine programming or configuration
 * fault rather than a race (decision 72).
 *
 * @param \Throwable $e the refusal a page arm caught
 * @return string the notice to show
 */
function selfselectadvanced_refusal_notice(\Throwable $e): string {
    if ($e instanceof \required_capability_exception) {
        return get_string('refusalauthoritygone', 'mod_selfselectadvanced');
    }

    return $e->getMessage();
}

/**
 * Serve files from the proposal, ticketrequest or ticketpost fileareas.
 *
 * WHO may read a proposal is not decided here. It is
 * teamaccess::may_read_proposal(), the one policy the pages that render
 * the link also call, because until 1.20.1 this function carried its
 * own transcription of it and the copies had drifted: an assigned guide
 * on a site that withdrew :viewassignedteams passed HERE while every
 * other door on their own team refused them, and a :manage-only
 * reviewer was refused the file the review page had just embedded
 * (audit A-05). A file server is the last gate a direct URL meets, so
 * it must ask the same question as the screen that offered the URL.
 *
 * The two ticket fileareas (1.20.44 part 2) follow the identical
 * discipline, through tickets::may_access_ticket_file() - the ONE
 * shared implementation of the access rule, never transcribed here.
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

    $knownareas = [
        'proposal',
        \mod_selfselectadvanced\local\tickets::FILEAREA_REQUEST,
        \mod_selfselectadvanced\local\tickets::FILEAREA_POST,
    ];
    if ($context->contextlevel !== CONTEXT_MODULE || !in_array($filearea, $knownareas, true)) {
        return false;
    }
    require_login($course, false, $cm);
    $itemid = (int) array_shift($args);

    if ($filearea === 'proposal') {
        $group = $DB->get_record('selfselectadvanced_group', ['id' => $itemid], '*', MUST_EXIST);
        if ((int) $group->activityid !== (int) $cm->instance) {
            return false;
        }
        $activity = \mod_selfselectadvanced\activity::from_cmid((int) $cm->id);
        if (!\mod_selfselectadvanced\local\teamaccess::may_read_proposal($activity, $group, (int) $USER->id)) {
            return false;
        }
    } else {
        $activity = \mod_selfselectadvanced\activity::from_cmid((int) $cm->id);
        if (
            !\mod_selfselectadvanced\local\tickets::may_access_ticket_file(
                $activity,
                $filearea,
                $itemid,
                (int) $USER->id
            )
        ) {
            return false;
        }
    }

    $fs = get_file_storage();
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = $fs->get_file($context->id, 'mod_selfselectadvanced', $filearea, $itemid, $filepath, $filename);
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
    global $USER;

    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = $cm->context;
    $activity = \mod_selfselectadvanced\activity::from_cmid((int) $cm->id);
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
        // Item 5d: gridreport.php shares flagged.php's viewall gate.
        $node->add(
            get_string('gridreport', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/gridreport.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING
        );
    }
    if (\mod_selfselectadvanced\local\authority::may_core_sync_report($activity, (int) $USER->id)) {
        $node->add(
            get_string('coresyncreport', 'mod_selfselectadvanced'),
            new moodle_url('/mod/selfselectadvanced/coresync.php', ['id' => $cm->id]),
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
