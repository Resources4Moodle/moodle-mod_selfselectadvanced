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

/**
 * Which defaulters can actually be sent a deadline reminder, and which cannot.
 *
 * Extracted from flagged.php in 1.20.28. The bucketing lived inline in a root
 * script, which meant the only way to reach it was a browser, and so the
 * defect it carried went unnoticed: recipients whose effective due date is the
 * 0 "no deadline" sentinel are dropped from the send, but the success notice
 * reported the number LISTED rather than the number queued. On an activity
 * with no deadline the manager was told "N reminder(s) queued for defaulters."
 * when nothing had been queued at all.
 *
 * Keeping the arithmetic here rather than in the page is what lets a test
 * state the rule directly: skipped + queued always equals the input, and a
 * recipient with no resolvable deadline is always in the skipped half.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nudgeplan {
    /**
     * Bucket recipients by their effective due date, dropping those without one.
     *
     * @param int[] $recipients user ids listed as defaulters
     * @param object $resolver a date resolver answering effective_dates(int): object with ->timedue
     * @return object buckets (due => userids), queued (int), skipped (int)
     */
    public static function bucket(array $recipients, object $resolver): object {
        $buckets = [];
        $skipped = 0;
        foreach ($recipients as $userid) {
            $due = (int) $resolver->effective_dates((int) $userid)->timedue;
            // 0 is the plugin's "no deadline" sentinel. msgreminderbody reads
            // "The penalty-free deadline is {$a->due}", so a recipient with no
            // deadline was being told their deadline was 1 January 1970. You
            // cannot remind somebody of a date that does not exist: they stay
            // on the report - it is a worklist, not a penalty ledger - but they
            // are not nudged, and the page says how many were left out.
            if ($due <= 0) {
                $skipped++;
                continue;
            }
            $buckets[$due][] = (int) $userid;
        }

        return (object) [
            'buckets' => $buckets,
            'queued' => array_sum(array_map('count', $buckets)),
            'skipped' => $skipped,
        ];
    }
}
