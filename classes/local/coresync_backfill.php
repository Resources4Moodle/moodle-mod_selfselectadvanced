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

use mod_selfselectadvanced\activity;
use stdClass;

/**
 * Permanent convergence sweep for approved-team Moodle group mirrors.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class coresync_backfill {
    /**
     * Run the mirror sweep.
     *
     * @param array $options dryrun, courseid, activityid, actorid
     * @param callable|null $output receives progress lines
     * @return stdClass summary counters
     */
    public static function run(array $options = [], ?callable $output = null): stdClass {
        global $DB;

        $output ??= static function (string $line): void {
        };
        $dryrun = !empty($options['dryrun']);
        $actorid = (int) ($options['actorid'] ?? get_admin()->id);
        $activityid = (int) ($options['activityid'] ?? 0);
        $courseid = (int) ($options['courseid'] ?? 0);

        $params = [
            'firm' => state::FIRM,
            'frozen' => state::FROZEN,
        ];
        $where = 'g.state IN (:firm, :frozen)';
        if ($activityid > 0) {
            $where .= ' AND g.activityid = :activityid';
            $params['activityid'] = $activityid;
        }
        if ($courseid > 0) {
            $where .= ' AND s.course = :courseid';
            $params['courseid'] = $courseid;
        }

        $records = $DB->get_recordset_sql(
            "SELECT g.*
               FROM {selfselectadvanced_group} g
               JOIN {selfselectadvanced} s ON s.id = g.activityid
              WHERE $where
           ORDER BY g.activityid, g.id",
            $params
        );

        $summary = (object) [
            'scanned' => 0,
            'synced' => 0,
            'changed' => 0,
            'failed' => 0,
            'dryrun' => $dryrun ? 1 : 0,
        ];
        $activities = [];

        try {
            foreach ($records as $row) {
                $summary->scanned++;
                $aid = (int) $row->activityid;
                if (!array_key_exists($aid, $activities)) {
                    try {
                        $activities[$aid] = activity::from_instance($aid);
                    } catch (\Throwable $e) {
                        $summary->failed++;
                        $output('FAILED activity=' . $aid . ' group=' . (int) $row->id . ' error=' . $e->getMessage());
                        continue;
                    }
                }
                $activity = $activities[$aid];
                $beforecoreid = (int) ($row->coregroupid ?? 0);
                if ($dryrun) {
                    $drift = freeze::drift($row);
                    $wouldchange = $beforecoreid === 0 || $drift['repairable'] || $drift['extra'];
                    if ($wouldchange) {
                        $summary->changed++;
                    }
                    $summary->synced++;
                    $output(
                        'DRY-RUN group=' . (int) $row->id . ' pluginuid=' . $row->pluginuid
                            . ' wouldchange=' . ($wouldchange ? '1' : '0')
                    );
                    continue;
                }

                $sync = freeze::sync_core_group($activity, (int) $row->id, $actorid);
                if ($sync->status === 'failed') {
                    $summary->failed++;
                    $output(
                        'FAILED group=' . (int) $row->id . ' pluginuid=' . $row->pluginuid
                            . ' error=' . $sync->error
                    );
                    continue;
                }

                $freshcoreid = (int) $DB->get_field('selfselectadvanced_group', 'coregroupid', ['id' => (int) $row->id]);
                $changed = $beforecoreid !== $freshcoreid
                    || $sync->added || $sync->removed || $sync->refused || $sync->extra;
                if ($changed) {
                    $summary->changed++;
                }
                $summary->synced++;
                $output(
                    'SYNCED group=' . (int) $row->id . ' pluginuid=' . $row->pluginuid
                        . ' changed=' . ($changed ? '1' : '0')
                        . ' added=' . count($sync->added)
                        . ' removed=' . count($sync->removed)
                        . ' refused=' . count($sync->refused)
                        . ' extra=' . count($sync->extra)
                );
            }
        } finally {
            $records->close();
        }

        return $summary;
    }
}
