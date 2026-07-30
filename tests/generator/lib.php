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
 * Data generator for mod_selfselectadvanced fixtures.
 *
 * The group and member creators write directly, bypassing the
 * gatekeeper on purpose: fixtures must be able to arrange states the
 * rules would refuse (grandfathering tests, boundary tests).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_selfselectadvanced_generator extends testing_module_generator {
    /** @var int Sequence for default group names. */
    private static int $groupseq = 0;

    /**
     * Create an activity instance with sane defaults for every setting.
     *
     * @param array|stdClass|null $record settings overrides
     * @param array|null $options course module options
     * @return stdClass the instance record
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;
        $defaults = [
            'grade' => 100,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 1,
            'maxguided' => 10,
            'timeopen' => 0,
            'timedue' => 0,
            'timecutoff' => 0,
            'penaltytype' => 0,
            'penaltyperday' => 0,
            'guidemode' => 0,
            'inviteexpiry' => 0,
            'autogroup' => 0,
            // The product defaults students-approach ON for a new
            // activity (1.17.0). A fixture, though, is usually standing
            // in for an activity configured the older way - most of the
            // suite exercises guide volunteering and guide interest,
            // which that mode closes. Tests about the new mode ask for
            // it explicitly, and studentapproach_test pins the product
            // default itself so this convenience cannot hide it.
            'studentapproach' => 0,
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }

        return parent::create_instance($record, (array) $options);
    }

    /**
     * Create a plugin group.
     *
     * Required: activityid (or selfselectadvanced instance record via
     * 'activity'), leaderid. The leader gets a confirmed member row
     * unless 'skipleaderrow' is set.
     *
     * @param array|stdClass $record group fields
     * @return stdClass the group row
     */
    public function create_group($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (!isset($record->activityid)) {
            throw new coding_exception('create_group requires activityid');
        }
        if (!isset($record->leaderid)) {
            throw new coding_exception('create_group requires leaderid');
        }

        self::$groupseq++;
        $now = time();
        $group = (object) [
            'activityid' => (int) $record->activityid,
            'pluginuid' => '',
            'name' => $record->name ?? 'Group ' . self::$groupseq,
            'title' => $record->title ?? 'Work title ' . self::$groupseq,
            'brief' => $record->brief ?? '<p>Brief</p>',
            'briefformat' => $record->briefformat ?? FORMAT_HTML,
            'leaderid' => (int) $record->leaderid,
            'guideid' => $record->guideid ?? null,
            'state' => $record->state ?? \mod_selfselectadvanced\local\state::FORMING,
            'autoformed' => $record->autoformed ?? 0,
            'successorid' => $record->successorid ?? null,
            'successortype' => $record->successortype ?? null,
            'timenominated' => isset($record->successorid) ? $now : null,
            'timesubmitted' => $record->timesubmitted ?? null,
            'timeapproved' => isset($record->timeapproved) && !is_numeric($record->timeapproved)
                ? strtotime((string) $record->timeapproved)
                : ($record->timeapproved ?? null),
            'timefrozen' => $record->timefrozen ?? null,
            'coregroupid' => $record->coregroupid ?? null,
            'usermodified' => (int) $record->leaderid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $group->id = $DB->insert_record('selfselectadvanced_group', $group);

        $activity = \mod_selfselectadvanced\activity::from_instance((int) $record->activityid);
        $group->pluginuid = $record->pluginuid
            ?? \mod_selfselectadvanced\local\groups::build_pluginuid($activity, (int) $group->id);
        $DB->set_field('selfselectadvanced_group', 'pluginuid', $group->pluginuid, ['id' => $group->id]);

        if (empty($record->skipleaderrow)) {
            $this->create_member([
                'groupid' => $group->id,
                'userid' => $record->leaderid,
                'status' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
                'isleader' => 1,
            ]);
        }

        return $group;
    }

    /**
     * Create a site-wide participant attribute record.
     *
     * Required: userid. Optional: gender, department, subdepartment, mobile.
     *
     * @param array|stdClass $record attribute fields
     * @return stdClass the stored record
     */
    public function create_userattr($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (!isset($record->userid)) {
            throw new coding_exception('create_userattr requires userid');
        }
        $now = time();
        $attr = (object) [
            'userid' => (int) $record->userid,
            'gender' => $record->gender ?? null,
            'department' => $record->department ?? null,
            'subdepartment' => $record->subdepartment ?? null,
            'mobile' => $record->mobile ?? null,
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $attr->id = $DB->insert_record('selfselectadvanced_userattr', $attr);
        \mod_selfselectadvanced\local\attributes\manager::purge_value_cache();

        return $attr;
    }

    /**
     * Create a department category.
     *
     * Required: name. Optional: parent (name of an existing category).
     *
     * @param array|stdClass $record fields
     * @return stdClass the category row
     */
    public function create_department($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (empty($record->name)) {
            throw new coding_exception('create_department requires name');
        }
        $parentid = 0;
        if (!empty($record->parent)) {
            $parentid = (int) $DB->get_field('selfselectadvanced_dept', 'id', ['name' => $record->parent], MUST_EXIST);
        }

        return \mod_selfselectadvanced\local\attributes\depts::create($record->name, $parentid);
    }

    /**
     * Create a quota rule.
     *
     * Required: activityid, dimension. Optional: rtype (default value),
     * value, mincount, maxcount, priority (default appends).
     *
     * @param array|stdClass $record rule fields
     * @return stdClass the rule row
     */
    public function create_quota($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (!isset($record->activityid) || !isset($record->dimension)) {
            throw new coding_exception('create_quota requires activityid and dimension');
        }
        $now = time();
        $rule = (object) [
            'activityid' => (int) $record->activityid,
            'dimension' => $record->dimension,
            'rtype' => $record->rtype ?? 'value',
            'value' => $record->value ?? null,
            'mincount' => $record->mincount ?? null,
            'maxcount' => $record->maxcount ?? null,
            'priority' => $record->priority ?? (1 + (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(priority), 0) FROM {selfselectadvanced_quota} WHERE activityid = ?',
                [(int) $record->activityid]
            )),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $rule->id = $DB->insert_record('selfselectadvanced_quota', $rule);

        return $rule;
    }

    /**
     * Create an override row through the store (fires events).
     *
     * Required: activityid, scope, targetid. Values per B5 field set.
     *
     * @param array|stdClass $record override fields
     * @return stdClass the stored row
     */
    public function create_override($record): stdClass {
        $record = (array) $record;
        foreach (['activityid', 'scope', 'targetid'] as $required) {
            if (!isset($record[$required])) {
                throw new coding_exception('create_override requires ' . $required);
            }
        }
        $activity = \mod_selfselectadvanced\activity::from_instance((int) $record['activityid']);
        $values = array_intersect_key(
            $record,
            array_flip(\mod_selfselectadvanced\local\override\store::FIELDS[$record['scope']])
        );

        return \mod_selfselectadvanced\local\override\store::save(
            $activity,
            $record['scope'],
            (int) $record['targetid'],
            $values,
            (int) ($record['actorid'] ?? 0)
        );
    }

    /**
     * Stage a move through the service (validates and events).
     *
     * Required: activityid, userid, targetgroupid.
     *
     * @param array|stdClass $record move fields
     * @return stdClass the pending move row
     */
    public function create_move($record): stdClass {
        $record = (object) (array) $record;
        foreach (['activityid', 'userid', 'targetgroupid'] as $required) {
            if (!isset($record->$required)) {
                throw new coding_exception('create_move requires ' . $required);
            }
        }
        $activity = \mod_selfselectadvanced\activity::from_instance((int) $record->activityid);
        $api = new \mod_selfselectadvanced\local\api($activity);

        return $api->moves()->stage(
            (int) $record->userid,
            isset($record->sourcegroupid) ? (int) $record->sourcegroupid : null,
            (int) $record->targetgroupid,
            !empty($record->makeleader),
            isset($record->successorid) ? (int) $record->successorid : null,
            (int) ($record->actorid ?? 0)
        );
    }

    /**
     * Create a membership/invitation row.
     *
     * Required: groupid, userid. Status defaults to confirmed.
     *
     * @param array|stdClass $record member fields
     * @return stdClass the member row
     */
    public function create_member($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (!isset($record->groupid) || !isset($record->userid)) {
            throw new coding_exception('create_member requires groupid and userid');
        }

        $now = time();
        $member = (object) [
            'groupid' => (int) $record->groupid,
            'userid' => (int) $record->userid,
            'status' => $record->status ?? \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
            'isleader' => $record->isleader ?? 0,
            'invitedby' => $record->invitedby ?? null,
            'timeinvited' => $record->timeinvited ?? null,
            'timeresponded' => $record->timeresponded ?? null,
            'leaverequested' => $record->leaverequested ?? null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $member->id = $DB->insert_record('selfselectadvanced_member', $member);

        return $member;
    }

    /**
     * Create an approach from a team to a guide (strategy 1.17 E).
     *
     * Required: activityid, groupid, guideid, sentby. Status defaults
     * to sent, which is the state a guide has to answer.
     *
     * @param array|stdClass $record contact fields
     * @return stdClass the contact row
     */
    public function create_contact($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        foreach (['activityid', 'groupid', 'guideid', 'sentby'] as $field) {
            if (!isset($record->$field)) {
                throw new coding_exception('create_contact requires ' . $field);
            }
        }

        $contact = (object) [
            'activityid' => (int) $record->activityid,
            'groupid' => (int) $record->groupid,
            'guideid' => (int) $record->guideid,
            'status' => $record->status ?? \mod_selfselectadvanced\local\contacts::STATUS_SENT,
            'sentby' => (int) $record->sentby,
            'message' => $record->message ?? '',
            'messageformat' => $record->messageformat ?? FORMAT_PLAIN,
            'reason' => $record->reason ?? null,
            'reasonformat' => $record->reasonformat ?? FORMAT_PLAIN,
            'timecreated' => $record->timecreated ?? time(),
            'timeresponded' => $record->timeresponded ?? null,
        ];
        $contact->id = $DB->insert_record('selfselectadvanced_contact', $contact);

        return $contact;
    }
}
