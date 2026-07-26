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

namespace mod_selfselectadvanced\table;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

use mod_selfselectadvanced\activity;

/**
 * Manager-facing auto-grouping run history (1.8.0): every run for the
 * activity, newest first, with a link to expand that run's decision
 * log. This table's own download stays off; multi-format export goes
 * through the exporter fed by export_rows() and export_log_rows(),
 * built from raw values independently of the paginated display
 * (audit round 6 item 1: dataformat writers escape themselves).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agrun_history_table extends \table_sql {
    /** @var activity The activity. */
    private activity $activity;

    /**
     * Constructor.
     *
     * @param string $uniqueid table id
     * @param activity $activity the activity
     * @param \moodle_url $baseurl page url
     */
    public function __construct(string $uniqueid, activity $activity, \moodle_url $baseurl) {
        parent::__construct($uniqueid);
        $this->activity = $activity;

        $this->define_columns([
            'timestarted', 'timefinished', 'triggeredby', 'groupsformed', 'placed', 'unplaced', 'actions',
        ]);
        $this->define_headers([
            get_string('agrunstarted', 'mod_selfselectadvanced'),
            get_string('agrunfinished', 'mod_selfselectadvanced'),
            get_string('agruntriggeredby', 'mod_selfselectadvanced'),
            get_string('agrungroupsformed', 'mod_selfselectadvanced'),
            get_string('agrunplaced', 'mod_selfselectadvanced'),
            get_string('agrununplaced', 'mod_selfselectadvanced'),
            get_string('actions'),
        ]);
        $this->define_baseurl($baseurl);
        $this->sortable(true, 'timestarted', SORT_DESC);
        $this->no_sorting('actions');
        $this->is_downloadable(false);

        $userfields = implode(', ', self::triggeredby_select());
        $this->set_sql(
            "ar.id, ar.timestarted, ar.timefinished, ar.triggeredby, ar.groupsformed, ar.placed, ar.unplaced,
             $userfields",
            '{selfselectadvanced_agrun} ar LEFT JOIN {user} u ON u.id = ar.triggeredby',
            'ar.activityid = :activityid',
            ['activityid' => $activity->id()]
        );
    }

    /**
     * Run start time.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_timestarted($row) {
        return userdate((int) $row->timestarted);
    }

    /**
     * Run finish time, blank while a run has no recorded finish.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_timefinished($row) {
        return $row->timefinished ? userdate((int) $row->timefinished) : '-';
    }

    /**
     * Who triggered the run: a manager's name, or the scheduled task
     * when triggeredby is 0.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_triggeredby($row) {
        if (empty($row->triggeredby)) {
            return get_string('agrunscheduled', 'mod_selfselectadvanced');
        }

        return fullname($row);
    }

    /**
     * Link to expand this run's decision log.
     *
     * @param \stdClass $row table row
     * @return string
     */
    public function col_actions($row) {
        return \html_writer::link(
            new \moodle_url('/mod/selfselectadvanced/autogrouphistory.php', [
                'id' => $this->activity->cm()->id,
                'run' => $row->id,
            ]),
            get_string('agrunviewlog', 'mod_selfselectadvanced'),
            ['class' => 'btn btn-secondary btn-sm']
        );
    }

    /**
     * The full (unpaginated) raw-value run-summary export dataset.
     *
     * @param activity $activity the activity
     * @return \stdClass[] rows with id, timestarted, timefinished, triggeredby, groupsformed, placed, unplaced
     */
    public static function export_rows(activity $activity): array {
        global $DB;

        $userfields = implode(', ', self::triggeredby_select());
        $records = $DB->get_records_sql(
            "SELECT ar.id, ar.timestarted, ar.timefinished, ar.triggeredby, ar.groupsformed, ar.placed, ar.unplaced,
                    $userfields
               FROM {selfselectadvanced_agrun} ar
          LEFT JOIN {user} u ON u.id = ar.triggeredby
              WHERE ar.activityid = :activityid
           ORDER BY ar.timestarted DESC",
            ['activityid' => $activity->id()]
        );

        $rows = [];
        foreach ($records as $record) {
            $rows[] = (object) [
                'id' => (int) $record->id,
                'timestarted' => userdate((int) $record->timestarted),
                'timefinished' => $record->timefinished ? userdate((int) $record->timefinished) : '-',
                'triggeredby' => empty($record->triggeredby)
                    ? get_string('agrunscheduled', 'mod_selfselectadvanced')
                    : fullname($record),
                'groupsformed' => (int) $record->groupsformed,
                'placed' => (int) $record->placed,
                'unplaced' => (int) $record->unplaced,
            ];
        }

        return $rows;
    }

    /**
     * The flattened decision-log export: one row per formed group per
     * run. The run-level bypassed-rule and residue summary is repeated
     * on every row belonging to that run (the flattening denormalises
     * run-level facts down to group level).
     *
     * @param activity $activity the activity
     * @return \stdClass[] rows with runid, timestarted, pluginuid, leader, membercount, bypassed, residue
     */
    public static function export_log_rows(activity $activity): array {
        global $DB;

        // The outer fetch streams (SCALE): a recordset instead of
        // get_records() so an activity's whole run history is never
        // all materialised in memory at once. It can only be read
        // once, so each run's small, already-decoded essentials are
        // buffered here rather than re-reading the recordset for the
        // second pass a per-group row build needs.
        $runs = $DB->get_recordset(
            'selfselectadvanced_agrun',
            ['activityid' => $activity->id()],
            'timestarted DESC',
            'id, timestarted, unplaced, log'
        );

        // One batched lookup for every leader named across every log,
        // instead of a get_user() call per formed group.
        $rundata = [];
        $leaderids = [];
        foreach ($runs as $record) {
            $log = json_decode((string) $record->log, true) ?: [];
            $rundata[] = (object) [
                'id' => (int) $record->id,
                'timestarted' => (int) $record->timestarted,
                'unplaced' => (int) $record->unplaced,
                'log' => $log,
            ];
            foreach ($log['groups'] ?? [] as $formed) {
                if (!empty($formed['leaderid'])) {
                    $leaderids[(int) $formed['leaderid']] = true;
                }
            }
        }
        $runs->close();

        // Chunked defensively (SCALE): the leader set accumulates
        // across every historical run of the activity.
        $leaders = [];
        foreach (array_chunk(array_keys($leaderids), 1000) as $leaderidchunk) {
            $leaders += $DB->get_records_list('user', 'id', $leaderidchunk);
        }

        $rows = [];
        foreach ($rundata as $run) {
            $log = $run->log;
            // Raw values only (audit round 6 item 1): a comma list of
            // bypassed rule ids, or a dash placeholder (the same
            // convention as every other blank cell in this plugin's
            // tables), never a pre-formatted sentence a dataformat
            // writer would then escape a second time.
            $bypassed = $log['bypassedrules'] ?? [];
            $bypassedsummary = $bypassed ? implode(', ', $bypassed) : '-';
            foreach ($log['groups'] ?? [] as $formed) {
                $leaderid = (int) ($formed['leaderid'] ?? 0);
                $rows[] = (object) [
                    'runid' => $run->id,
                    'timestarted' => userdate($run->timestarted),
                    'pluginuid' => $formed['pluginuid'] ?? '',
                    'leader' => ($leaderid && isset($leaders[$leaderid])) ? fullname($leaders[$leaderid]) : '-',
                    'membercount' => count($formed['members'] ?? []),
                    'bypassed' => $bypassedsummary,
                    'residue' => $run->unplaced,
                ];
            }
        }

        return $rows;
    }

    /**
     * The SELECT list entries for the triggering user's name fields.
     *
     * @return string[] select expressions, e.g. "u.firstname"
     */
    private static function triggeredby_select(): array {
        return array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        );
    }
}
