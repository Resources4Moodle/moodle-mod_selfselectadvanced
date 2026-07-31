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

        // Who holds the role in this course right now.
        $current = array_map('intval', $DB->get_fieldset_select(
            'role_assignments',
            'userid',
            'roleid = ? AND contextid = ?',
            [$roleid, $coursecontext->id]
        ));
        $current = array_values(array_unique($current));

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

        foreach ($wanted as $userid => $user) {
            $enrolled = is_enrolled($coursecontext, $userid);
            if (!$enrolled && !$doenrol) {
                // The rule from the order: a coordinator must already be
                // in the course. Enrolling them is an option the person
                // uploading has chosen not to take.
                $report->skipped++;
                $report->lines[] = self::line(0, self::label($user), 'coordinatorimportnotenrolled');
                continue;
            }
            if (in_array($userid, $current, true)) {
                $report->unchanged++;
                $report->lines[] = self::line(0, self::label($user), 'coordinatorimportalready');
                continue;
            }
            if ($commit) {
                if (!$enrolled && $doenrol) {
                    self::enrol($courseid, $userid);
                    $report->enrolled++;
                }
                role_assign($roleid, $userid, $coursecontext->id);
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
                role_unassign($roleid, (int) $userid, $coursecontext->id);
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
     * Appoint one person, from the participants table (strategy 1.18 D).
     *
     * The same rule the upload enforces: they must already be in the
     * course. The event and the message are the same ones the upload
     * emits, so the audit trail does not record two kinds of
     * appointment depending on which control was used.
     *
     * @param activity $activity the activity
     * @param int $userid the person
     * @throws \moodle_exception when they are not enrolled
     */
    public static function appoint(activity $activity, int $userid): void {
        $coursecontext = \context_course::instance($activity->cm()->course);
        if (!is_enrolled($coursecontext, $userid)) {
            throw new \moodle_exception('coordinatorimportnotenrolled', 'mod_selfselectadvanced');
        }
        $roleid = coordinatorrole::roleid();
        if (user_has_role_assignment($userid, $roleid, $coursecontext->id)) {
            return;
        }

        role_assign($roleid, $userid, $coursecontext->id);
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
        $coursecontext = \context_course::instance($activity->cm()->course);
        $roleid = coordinatorrole::roleid();
        if (!user_has_role_assignment($userid, $roleid, $coursecontext->id)) {
            return;
        }

        role_unassign($roleid, $userid, $coursecontext->id);
        \mod_selfselectadvanced\event\coordinator_removed::create([
            'objectid' => $activity->id(),
            'context' => $activity->context(),
            'relateduserid' => $userid,
        ])->trigger();
        self::tell($activity, $userid, 'removed');
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
