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

namespace mod_selfselectadvanced\local;

use csv_import_reader;
use mod_selfselectadvanced\activity;
use stdClass;

/**
 * Bulk appointment of Group Coordinators from a file (strategy 1.17 B3).
 *
 * The file names people, one per line, by username or email. What
 * happens to them depends on the mode:
 *
 * - ADD_REMOVE takes the file as a list of changes: everyone named is
 *   made a coordinator, and anyone named with a leading minus is
 *   removed. Coordinators the file does not mention are left alone.
 * - OVERWRITE takes the file as the complete list: everyone named is a
 *   coordinator afterwards, and every current coordinator the file does
 *   not name is removed.
 *
 * A person must already be enrolled in the course to hold the role -
 * appointing somebody who is not there would be an appointment in name
 * only. Enrolling them, and unenrolling them on removal, are offered as
 * options rather than assumed: whether a coordinator should also be a
 * participant is the school's decision, not the plugin's.
 *
 * Every appointment this class makes is a role assignment at the
 * ACTIVITY's context, carrying component 'mod_selfselectadvanced' so the
 * row says which instance made it. The role's capabilities are declared
 * at CONTEXT_MODULE and every consumer of them asks per activity, so a
 * course-context grant was silently course-wide across every instance.
 *
 * Only a non-editing teacher may be appointed, decided by role
 * ARCHETYPE rather than shortname - see eligible_userids(). Both writers
 * here and the candidates table consult that one predicate, so what the
 * screen offers and what the service accepts cannot drift apart.
 *
 * Every run reports before it commits, so the person uploading sees
 * exactly what will happen first.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coordinatorimport {
    /** @var string The file lists changes; unnamed coordinators are untouched. */
    public const MODE_ADD_REMOVE = 'addremove';

    /** @var string The file is the complete list of coordinators. */
    public const MODE_OVERWRITE = 'overwrite';

    /**
     * Read a file and either report on it or carry it out.
     *
     * @param activity $activity the activity
     * @param csv_import_reader $reader an initialised reader (load_csv_content done)
     * @param string $mode self::MODE_*
     * @param bool $commit false to report only
     * @param int $actorid the acting user
     * @param stdClass|null $options enrol and unenrol switches
     * @return stdClass counts, and a line-by-line account
     */
    public static function run(
        activity $activity,
        csv_import_reader $reader,
        string $mode,
        bool $commit,
        int $actorid,
        ?stdClass $options = null
    ): stdClass {
        global $DB;

        if (!in_array($mode, [self::MODE_ADD_REMOVE, self::MODE_OVERWRITE], true)) {
            throw new \coding_exception('Unknown coordinator import mode ' . $mode);
        }
        $options = $options ?? new stdClass();
        $doenrol = !empty($options->enrol);
        $dounenrol = !empty($options->unenrol);

        $courseid = $activity->courseid();
        $coursecontext = \context_course::instance($courseid);
        $roleid = coordinatorrole::roleid();

        $report = (object) [
            'added' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'enrolled' => 0,
            'unenrolled' => 0,
            'skipped' => 0,
            'lines' => [],
        ];

        // Who holds the role for this activity right now. BOTH contexts:
        // the module rows this plugin writes, and any course row left by
        // a site that predates 1.20.0 or assigned the role by hand. A
        // holder invisible here would be appointed a second time by
        // OVERWRITE, and never stood down by it.
        $modcontextid = $activity->context()->id;
        $current = array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
              WHERE ra.roleid = :roleid AND ra.contextid IN (:ctxa, :ctxb)",
            ['roleid' => $roleid, 'ctxa' => $modcontextid, 'ctxb' => $coursecontext->id]
        ));

        $wanted = [];
        $unwanted = [];

        $reader->init();
        $line = 0;
        while ($row = $reader->next()) {
            $line++;
            $raw = trim((string) ($row[0] ?? ''));
            if ($raw === '' || str_starts_with($raw, '#')) {
                continue;
            }
            $remove = str_starts_with($raw, '-');
            $needle = trim(ltrim($raw, '-'));
            if ($needle === '') {
                continue;
            }

            $user = self::find_user($needle);
            if (!$user) {
                $report->skipped++;
                $report->lines[] = self::line($line, $needle, 'coordinatorimportnouser');
                continue;
            }
            if ($remove) {
                $unwanted[(int) $user->id] = $user;
            } else {
                $wanted[(int) $user->id] = $user;
            }
        }
        $reader->close();

        // Overwrite: everyone holding the role who is not in the file
        // loses it. Add-and-remove leaves them alone.
        if ($mode === self::MODE_OVERWRITE) {
            foreach (array_diff($current, array_keys($wanted)) as $goneid) {
                if (!isset($unwanted[$goneid])) {
                    $unwanted[$goneid] = \core_user::get_user($goneid);
                }
            }
        }

        // Two set questions asked once for the whole file, not once per
        // line: a 10,000-student course uploaded as a cohort used to pay
        // one is_enrolled() query per name.
        $eligible = [];
        $enrolledids = [];
        if ($wanted) {
            $eligible = array_flip(self::eligible_userids($activity));
            [$esql, $eparams] = get_enrolled_sql($coursecontext);
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($wanted), SQL_PARAMS_NAMED, 'imp');
            $enrolledids = array_flip(array_map('intval', $DB->get_fieldset_sql(
                "SELECT eu.id FROM ($esql) eu WHERE eu.id $insql",
                $eparams + $inparams
            )));
        }

        foreach ($wanted as $userid => $user) {
            if (in_array($userid, $current, true)) {
                // Asked first, so somebody who already holds the role is
                // reported as unchanged even when today's eligibility
                // rule would refuse them. A migrated holder named in the
                // file must not turn into a refusal.
                $report->unchanged++;
                $report->lines[] = self::line(0, self::label($user), 'coordinatorimportalready');
                continue;
            }
            if (!isset($eligible[$userid])) {
                // Before the enrolment question on purpose: somebody who
                // could never hold the role must be reported as such,
                // not quietly enrolled in the course first.
                $report->skipped++;
                $report->lines[] = self::line(0, self::label($user), 'coordinatorimportineligible');
                continue;
            }
            $enrolled = isset($enrolledids[$userid]);
            if (!$enrolled && !$doenrol) {
                // The rule from the order: a coordinator must already be
                // in the course. Enrolling them is an option the person
                // uploading has chosen not to take.
                $report->skipped++;
                $report->lines[] = self::line(0, self::label($user), 'coordinatorimportnotenrolled');
                continue;
            }
            if ($commit) {
                if (!$enrolled && $doenrol) {
                    self::enrol($courseid, $userid);
                    $report->enrolled++;
                }
                role_assign($roleid, $userid, $modcontextid, 'mod_selfselectadvanced', 0);
                \mod_selfselectadvanced\event\coordinator_assigned::create([
                    'objectid' => $activity->id(),
                    'context' => $activity->context(),
                    'relateduserid' => $userid,
                ])->trigger();
                self::tell($activity, $userid, 'assigned');
            } else if (!$enrolled && $doenrol) {
                $report->enrolled++;
            }
            $report->added++;
            $report->lines[] = self::line(0, self::label($user), 'coordinatorimportadded');
        }

        foreach ($unwanted as $userid => $user) {
            if (!$user || !in_array((int) $userid, $current, true)) {
                $report->unchanged++;
                continue;
            }
            if ($commit) {
                self::strip($roleid, (int) $userid, $modcontextid, $coursecontext->id);
                \mod_selfselectadvanced\event\coordinator_removed::create([
                    'objectid' => $activity->id(),
                    'context' => $activity->context(),
                    'relateduserid' => (int) $userid,
                ])->trigger();
                self::tell($activity, (int) $userid, 'removed');
                if ($dounenrol) {
                    self::unenrol($courseid, (int) $userid);
                    $report->unenrolled++;
                }
            } else if ($dounenrol) {
                $report->unenrolled++;
            }
            $report->removed++;
            $report->lines[] = self::line(0, self::label($user), 'coordinatorimportremoved');
        }

        return $report;
    }

    /**
     * Send a sample file for download (strategy 1.18 D).
     *
     * The format was previously described in prose on the form, which
     * is exactly the kind of thing people get wrong once and then blame
     * the upload for. Both files carry the same four illustrative rows,
     * including a comment and a removal, so the two conventions that
     * are not obvious are shown rather than explained.
     *
     * @param string $format csv or xlsx
     */
    public static function send_sample(string $format): void {
        global $CFG;

        $rows = [
            ['# ' . get_string('coordinatorsampleheader', 'mod_selfselectadvanced')],
            ['# ' . get_string('coordinatorsampleremovehint', 'mod_selfselectadvanced')],
            ['teacher1'],
            ['a.lecturer@example.edu'],
            ['-teacher2'],
        ];

        if ($format === 'xlsx') {
            require_once($CFG->libdir . '/excellib.class.php');
            $workbook = new \MoodleExcelWorkbook('-');
            $workbook->send('coordinators-sample.xlsx');
            $sheet = $workbook->add_worksheet(get_string('coordinators', 'mod_selfselectadvanced'));
            foreach ($rows as $index => $row) {
                $sheet->write_string($index, 0, $row[0]);
            }
            $workbook->close();

            return;
        }

        require_once($CFG->libdir . '/csvlib.class.php');
        $writer = new \csv_export_writer();
        $writer->set_filename('coordinators-sample');
        foreach ($rows as $row) {
            $writer->add_data($row);
        }
        $writer->download_file();
    }

    /**
     * Every user eligible to be appointed coordinator in this activity.
     *
     * Policy: only non-editing teachers, keyed on role ARCHETYPE
     * 'teacher' and never on the shortname, which institutions rename
     * ("Tutor", "Demonstrator", "Facilitator") and which a site can
     * hand to a role that is nothing of the sort. Eligibility decides
     * who may hold :viewall and :override, so it cannot rest on a label.
     *
     * The coordinator role itself is archetype 'teacher' too
     * (coordinatorrole::ensure() creates it that way), so it is
     * excluded: holding the role must not be what makes somebody
     * eligible for it.
     *
     * The search runs over the ACTIVITY's whole context path - module,
     * course, category(s), system - not from the course down. A
     * non-editing teacher added through core's "Assign roles in this
     * activity" holds their row at CONTEXT_MODULE alone, and they are
     * exactly the people this activity would draw a coordinator from.
     *
     * One query, ids only: callers intersect, never loop per user.
     *
     * @param activity $activity the activity
     * @return int[] user ids, unordered
     */
    public static function eligible_userids(activity $activity): array {
        global $DB;

        // Activity + course + category(s) + system.
        $contextids = $activity->context()->get_parent_context_ids(true);
        [$ctxsql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ectx');
        $params['archetype'] = 'teacher';
        $sql = "SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.contextid $ctxsql AND r.archetype = :archetype";
        $coordroleid = coordinatorrole::roleid();
        if ($coordroleid > 0) {
            $sql .= ' AND r.id <> :coordroleid';
            $params['coordroleid'] = $coordroleid;
        }

        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Appoint one person, from the participants table (strategy 1.18 D).
     *
     * The same rules the upload enforces: they must already be in the
     * course, and they must be a non-editing teacher. The event and the
     * message are the same ones the upload emits, so the audit trail
     * does not record two kinds of appointment depending on which
     * control was used.
     *
     * The row is written at the ACTIVITY's context with this plugin's
     * component, so the appointment reaches exactly the instance it was
     * made in and says who made it. THIS is what keeps the coordinator
     * role - and anything later granted to it - confined to activities
     * where a coordinator has actually been appointed: the plugin never
     * assigns the role anywhere but CONTEXT_MODULE.
     *
     * @param activity $activity the activity
     * @param int $userid the person
     * @throws \moodle_exception when they are not enrolled, or are not a non-editing teacher
     */
    public static function appoint(activity $activity, int $userid): void {
        $coursecontext = \context_course::instance($activity->cm()->course);
        if (!is_enrolled($coursecontext, $userid)) {
            throw new \moodle_exception('coordinatorimportnotenrolled', 'mod_selfselectadvanced');
        }
        $roleid = coordinatorrole::roleid();
        $modcontext = $activity->context();

        // Asked before eligibility, and deliberately: core resolves this
        // over the whole context path, so it covers the module row this
        // plugin writes and any course-context row migrated from an
        // older release or assigned by hand. Somebody who already holds
        // the role is a no-op, never a refusal.
        if (user_has_role_assignment($userid, $roleid, $modcontext->id)) {
            return;
        }
        if (!in_array($userid, self::eligible_userids($activity), true)) {
            throw new \moodle_exception('coordinatorineligible', 'mod_selfselectadvanced');
        }

        role_assign($roleid, $userid, $modcontext->id, 'mod_selfselectadvanced', 0);
        \mod_selfselectadvanced\event\coordinator_assigned::create([
            'objectid' => $activity->id(),
            'context' => $activity->context(),
            'relateduserid' => $userid,
        ])->trigger();
        self::tell($activity, $userid, 'assigned');
    }

    /**
     * Stand one person down, from the participants table.
     *
     * Enrolment is never touched here. The upload offers unenrolling as
     * an explicit option on a form; a single button in a table is not
     * the place to remove somebody from a course as a side effect.
     *
     * @param activity $activity the activity
     * @param int $userid the person
     */
    public static function remove(activity $activity, int $userid): void {
        global $DB;

        $coursecontext = \context_course::instance($activity->cm()->course);
        $roleid = coordinatorrole::roleid();
        $modcontext = $activity->context();

        // Only a row this activity can actually take away counts as
        // holding it. user_has_role_assignment() walks the whole parent
        // path, so it answers true for a category- or system-level
        // grant that strip() deliberately leaves alone - and the event
        // and the message would then record a removal that never
        // happened. Since 1.20.0 the coordinators screen lists holders
        // with parent = true, so that POST is one button press away.
        $holds = $DB->record_exists_select(
            'role_assignments',
            'roleid = :roleid AND userid = :userid AND contextid IN (:ctxa, :ctxb)',
            ['roleid' => $roleid, 'userid' => $userid, 'ctxa' => $modcontext->id, 'ctxb' => $coursecontext->id]
        );
        if (!$holds) {
            return;
        }

        self::strip($roleid, $userid, $modcontext->id, $coursecontext->id);
        \mod_selfselectadvanced\event\coordinator_removed::create([
            'objectid' => $activity->id(),
            'context' => $activity->context(),
            'relateduserid' => $userid,
        ])->trigger();
        self::tell($activity, $userid, 'removed');
    }

    /**
     * Take the role away wherever this activity can see it.
     *
     * Three targeted deletes rather than one: role_unassign() matches
     * the component it is given, so the row this plugin wrote, a row an
     * administrator assigned by hand at the activity, and a legacy or
     * manual course-context row are three different rows. Removing one
     * and leaving the others would leave the person still holding the
     * role while the screen reported them stood down. Each call is a
     * no-op when nothing matches.
     *
     * Category- and system-level assignments are deliberately left
     * alone: they were not made for this activity and one activity's
     * screen has no business revoking them.
     *
     * @param int $roleid the coordinator role
     * @param int $userid the person
     * @param int $modcontextid the activity's context id
     * @param int $coursecontextid the course's context id
     */
    private static function strip(int $roleid, int $userid, int $modcontextid, int $coursecontextid): void {
        role_unassign($roleid, $userid, $modcontextid, 'mod_selfselectadvanced', 0);
        role_unassign($roleid, $userid, $modcontextid);
        role_unassign($roleid, $userid, $coursecontextid);
    }

    /**
     * Tell somebody they have been appointed or stood down.
     *
     * @param activity $activity the activity
     * @param int $userid the person
     * @param string $what assigned or removed
     */
    private static function tell(activity $activity, int $userid, string $what): void {
        notifier::send(
            $activity,
            'coordinator',
            $userid,
            'msgcoordinator' . $what . 'subject',
            'msgcoordinator' . $what . 'body',
            (object) ['activity' => format_string($activity->name())],
            new \moodle_url('/mod/selfselectadvanced/coordinator.php', ['id' => $activity->cm()->id]),
            get_string('coordinatordashboard', 'mod_selfselectadvanced')
        );
    }

    /**
     * Find a person by username, then by email.
     *
     * @param string $needle username or email from the file
     * @return stdClass|null the user, or null when nobody matches
     */
    private static function find_user(string $needle): ?stdClass {
        global $DB, $CFG;

        $user = $DB->get_record('user', [
            'username' => \core_text::strtolower($needle),
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        if ($user) {
            return $user;
        }
        $matches = $DB->get_records('user', ['email' => \core_text::strtolower($needle), 'deleted' => 0]);

        // An email shared by two accounts names nobody in particular.
        return count($matches) === 1 ? reset($matches) : null;
    }

    /**
     * Enrol somebody through the course's manual enrolment.
     *
     * @param int $courseid the course
     * @param int $userid the user
     */
    private static function enrol(int $courseid, int $userid): void {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MISSING);
        if (!$plugin || !$instance) {
            throw new \moodle_exception('coordinatorimportnomanual', 'mod_selfselectadvanced');
        }
        $plugin->enrol_user($instance, $userid);
    }

    /**
     * Remove a manual enrolment, leaving any other enrolment method
     * alone - this plugin did not create those and must not undo them.
     *
     * @param int $courseid the course
     * @param int $userid the user
     */
    private static function unenrol(int $courseid, int $userid): void {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MISSING);
        if ($plugin && $instance) {
            $plugin->unenrol_user($instance, $userid);
        }
    }

    /**
     * A person's label for the report.
     *
     * @param stdClass|null $user the user
     * @return string
     */
    private static function label(?stdClass $user): string {
        return $user ? fullname($user) . ' (' . $user->username . ')' : '?';
    }

    /**
     * One line of the account the report gives back.
     *
     * @param int $line the file line, or 0 when the entry is not from one
     * @param string $who the person
     * @param string $outcomekey lang key describing what happened
     * @return stdClass
     */
    private static function line(int $line, string $who, string $outcomekey): stdClass {
        return (object) [
            'line' => $line,
            'who' => $who,
            'outcome' => get_string($outcomekey, 'mod_selfselectadvanced'),
        ];
    }
}
