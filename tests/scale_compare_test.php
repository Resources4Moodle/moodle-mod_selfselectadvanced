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

namespace mod_selfselectadvanced;

use mod_selfselectadvanced\local\perf\compare;

/**
 * The threshold logic behind the scale-harness comparator.
 *
 * The comparator is the only thing standing between "no probe
 * regressed" and a human reading two logs, so its arithmetic is pinned
 * at the boundaries, not merely in the middle: the read limit is a
 * max() of a relative and an absolute allowance (a 10% rule alone would
 * make a three-read probe flap on cache warmth), the wall-time limit is
 * likewise a max(), a probe the baseline knows and the run does not is
 * a failure rather than a silence, and a fixture step is never judged
 * at all.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\perf\compare
 */
final class scale_compare_test extends \basic_testcase {
    /**
     * One probe map, as the comparator consumes it.
     *
     * @param string $label the probe label
     * @param float $seconds wall seconds
     * @param int $reads database reads
     * @param int $writes database writes
     * @return array label => stdClass
     */
    private function probes(string $label, float $seconds, int $reads, int $writes = 0): array {
        return [$label => (object) ['seconds' => $seconds, 'reads' => $reads, 'writes' => $writes]];
    }

    /**
     * Reads growing past max(baseline * 1.10, baseline + 25) is a regression.
     */
    public function test_read_regression_flagged(): void {
        $baseline = $this->probes('probe: x', 0.5, 1000);

        // Limit is max(ceil(1000 * 1.10), 1000 + 25) = max(1100, 1025) = 1100.
        $over = compare::verdicts($baseline, $this->probes('probe: x', 0.5, 1101));
        $this->assertSame(1, $over->fail);
        $this->assertStringContainsString('REGRESSED', implode("\n", $over->lines));
        // The line names the dimension that tripped, and only that one.
        $this->assertStringContainsString('over limit: reads |', implode("\n", $over->lines));

        // The boundary itself passes: the limit is the last good value.
        $at = compare::verdicts($baseline, $this->probes('probe: x', 0.5, 1100));
        $this->assertSame(0, $at->fail);

        // Both dimensions over the limit is still exactly one failure.
        $both = compare::verdicts($baseline, $this->probes('probe: x', 9.0, 99999));
        $this->assertSame(1, $both->fail);
        $this->assertCount(1, $both->lines);
    }

    /**
     * The absolute margin, not the percentage, governs a tiny baseline.
     */
    public function test_small_baseline_absolute_margin(): void {
        $baseline = $this->probes('probe: tiny', 0.01, 3);

        // A pure 10% rule would allow ceil(3 * 1.10) = 4 reads and make
        // a three-read probe fail on ordinary cache warmth. The limit
        // is max(4, 3 + 25) = 28.
        $at = compare::verdicts($baseline, $this->probes('probe: tiny', 0.01, 28));
        $this->assertSame(0, $at->fail);

        $over = compare::verdicts($baseline, $this->probes('probe: tiny', 0.01, 29));
        $this->assertSame(1, $over->fail);

        // Both dimensions over: one failure, one line.
        $both = compare::verdicts($baseline, $this->probes('probe: tiny', 5.0, 500));
        $this->assertSame(1, $both->fail);
        $this->assertCount(1, $both->lines);
    }

    /**
     * Wall time uses max(baseline * 2.0, baseline + 0.25s).
     */
    public function test_time_regression_flagged(): void {
        $baseline = $this->probes('probe: slow', 0.5, 100);

        // Limit is max(0.5 * 2.0, 0.5 + 0.25) = max(1.0, 0.75) = 1.0.
        $over = compare::verdicts($baseline, $this->probes('probe: slow', 1.01, 100));
        $this->assertSame(1, $over->fail);
        // Reads are unchanged here, so time must be the named dimension.
        $this->assertStringContainsString('over limit: time |', implode("\n", $over->lines));

        $at = compare::verdicts($baseline, $this->probes('probe: slow', 1.0, 100));
        $this->assertSame(0, $at->fail);

        // On a fast probe the absolute margin absorbs the noise a
        // factor alone would call a regression: limit is
        // max(0.01 * 2.0, 0.01 + 0.25) = max(0.02, 0.26) = 0.26.
        $fast = $this->probes('probe: fast', 0.01, 100);
        $noise = compare::verdicts($fast, $this->probes('probe: fast', 0.25, 100));
        $this->assertSame(0, $noise->fail);

        $realregression = compare::verdicts($fast, $this->probes('probe: fast', 0.27, 100));
        $this->assertSame(1, $realregression->fail);

        // Both dimensions over: one failure, one line.
        $both = compare::verdicts($baseline, $this->probes('probe: slow', 9.0, 9999));
        $this->assertSame(1, $both->fail);
        $this->assertCount(1, $both->lines);
    }

    /**
     * A probe the baseline knows and the run does not fails: the
     * instrument shrank, which is worse than a red result.
     */
    public function test_missing_probe_fails(): void {
        $baseline = $this->probes('probe: gone', 0.5, 100);
        $verdict = compare::verdicts($baseline, $this->probes('probe: still here', 0.5, 100));

        $this->assertSame(1, $verdict->fail);
        $this->assertStringContainsString('MISSING', implode("\n", $verdict->lines));
        $this->assertStringContainsString('probe: gone', implode("\n", $verdict->lines));
    }

    /**
     * A label only the run knows is informational: adding a probe must
     * never break the comparison that motivated adding it.
     */
    public function test_new_probe_informational(): void {
        $run = $this->probes('probe: kept', 0.5, 100) + $this->probes('probe: added', 0.2, 40);
        $verdict = compare::verdicts($this->probes('probe: kept', 0.5, 100), $run);

        $this->assertSame(0, $verdict->fail);
        $this->assertStringContainsString('NEW', implode("\n", $verdict->lines));
        $this->assertStringContainsString('probe: added', implode("\n", $verdict->lines));
    }

    /**
     * Fixture steps are reported but never thresholded.
     */
    public function test_seed_probes_excluded(): void {
        $verdict = compare::verdicts(
            $this->probes('seed: anything', 0.5, 10),
            $this->probes('seed: anything', 90.0, 99999)
        );

        $this->assertSame(0, $verdict->fail);
        $this->assertStringContainsString('seed', implode("\n", $verdict->lines));
        $this->assertStringNotContainsString('REGRESSED', implode("\n", $verdict->lines));
    }
}
