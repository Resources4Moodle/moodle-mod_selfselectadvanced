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

namespace mod_selfselectadvanced\local\perf;

/**
 * Threshold comparison between two runs of the 10k scale harness.
 *
 * The harness (docs/tools/scale_scenarios.php) records each probe's
 * wall time and database work into a JSON run file. This class turns a
 * baseline file and a run file into a verdict: which probes regressed
 * beyond the agreed tolerances, which vanished from the instrument, and
 * which are new. It is deliberately pure - no database, no plugin
 * tables, no globals - so it is unit-testable without a site and is
 * trivially safe to call from anywhere, including an upgrade step.
 *
 * Reads count QUERIES, not rows, which is what makes them the primary
 * signal for the N+1 findings this instrument exists to price. Wall
 * time is the secondary signal and is kept deliberately loose: it is
 * the only thing that moves when an index changes a query plan without
 * changing the query count.
 *
 * "seed:" labels are fixture steps, not the system under test. They are
 * reported so a run's shape stays visible, but never thresholded: their
 * cost is dominated by bulk inserts whose timing says nothing about the
 * product.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compare {
    /** @var float Default tolerated relative read growth. */
    public const READGROWTH = 0.10;

    /** @var int Default tolerated absolute read growth, in queries. */
    public const READMARGIN = 25;

    /** @var float Default tolerated wall-time factor. */
    public const TIMEFACTOR = 2.0;

    /** @var float Default tolerated absolute wall-time growth, in seconds. */
    public const TIMEMARGIN = 0.25;

    /** @var string Label prefix of the fixture steps, reported but never thresholded. */
    public const SEEDPREFIX = 'seed:';

    /**
     * Judge a run against a baseline.
     *
     * A probe fails if EITHER dimension trips, and it fails exactly
     * once however many trip. A probe present in the baseline but
     * absent from the run also fails: the instrument shrank, and a
     * green check that examined nothing is worse than a red one.
     * Labels only in the run are informational - adding a probe is
     * always allowed, renaming or dropping one is not.
     *
     * @param array $baseline label => object{seconds, reads, writes} from the accepted baseline
     * @param array $run label => object{seconds, reads, writes} from the run under judgement
     * @param float $readgrowth relative read tolerance
     * @param int $readmargin absolute read tolerance, in queries
     * @param float $timefactor wall-time factor tolerance
     * @param float $timemargin absolute wall-time tolerance, in seconds
     * @return \stdClass {fail: int, lines: string[]}
     */
    public static function verdicts(
        array $baseline,
        array $run,
        float $readgrowth = self::READGROWTH,
        int $readmargin = self::READMARGIN,
        float $timefactor = self::TIMEFACTOR,
        float $timemargin = self::TIMEMARGIN
    ): \stdClass {
        $fail = 0;
        $lines = [];

        foreach ($baseline as $label => $entry) {
            $label = (string) $label;
            $base = (object) (array) $entry;
            $basereads = (int) ($base->reads ?? 0);
            $baseseconds = (float) ($base->seconds ?? 0);

            // Fixture steps first: they are reported, never judged, and
            // their disappearance is not the instrument shrinking - the
            // measured probe that depends on the fixture carries its own
            // tripwire and fails the run outright before it gets here.
            if (str_starts_with($label, self::SEEDPREFIX)) {
                if (array_key_exists($label, $run)) {
                    $now = (object) (array) $run[$label];
                    $lines[] = sprintf(
                        'seed      %s | reads %d -> %d | time %.4fs -> %.4fs (not thresholded)',
                        $label,
                        $basereads,
                        (int) ($now->reads ?? 0),
                        $baseseconds,
                        (float) ($now->seconds ?? 0)
                    );
                } else {
                    $lines[] = sprintf(
                        'seed      %s | not present in this run (not thresholded)',
                        $label
                    );
                }
                continue;
            }

            if (!array_key_exists($label, $run)) {
                $fail++;
                $lines[] = sprintf(
                    'MISSING   %s | baseline reads %d, time %.4fs - the run does not contain this probe',
                    $label,
                    $basereads,
                    $baseseconds
                );
                continue;
            }

            $now = (object) (array) $run[$label];
            $runreads = (int) ($now->reads ?? 0);
            $runseconds = (float) ($now->seconds ?? 0);

            $readlimit = max((int) ceil($basereads * (1 + $readgrowth)), $basereads + $readmargin);
            $timelimit = max($baseseconds * $timefactor, $baseseconds + $timemargin);

            $tripped = [];
            if ($runreads > $readlimit) {
                $tripped[] = 'reads';
            }
            if ($runseconds > $timelimit) {
                $tripped[] = 'time';
            }

            $detail = sprintf(
                'reads %d (base %d, limit %d) | time %.4fs (base %.4fs, limit %.4fs)',
                $runreads,
                $basereads,
                $readlimit,
                $runseconds,
                $baseseconds,
                $timelimit
            );

            if ($tripped) {
                $fail++;
                $lines[] = sprintf('REGRESSED %s | over limit: %s | %s', $label, implode(' and ', $tripped), $detail);
            } else {
                $lines[] = sprintf('ok        %s | %s', $label, $detail);
            }
        }

        foreach ($run as $label => $entry) {
            $label = (string) $label;
            if (array_key_exists($label, $baseline)) {
                continue;
            }
            $now = (object) (array) $entry;
            $lines[] = sprintf(
                'NEW       %s | reads %d | time %.4fs (informational)',
                $label,
                (int) ($now->reads ?? 0),
                (float) ($now->seconds ?? 0)
            );
        }

        return (object) ['fail' => $fail, 'lines' => $lines];
    }
}
