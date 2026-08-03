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
 * Compare one scale-harness run against an accepted baseline.
 *
 * The harness writes a run record with --record=<path>; this script
 * judges any such record against the accepted baseline and prints one
 * line per probe. The ONLY success token is:
 *
 *   ### SCALECOMPARE fail=0
 *
 * and the exit code is 0 only when that is what was printed. A probe
 * that regressed beyond the thresholds, or that the baseline knows and
 * the run does not, is a failure; a probe only the run knows is
 * informational.
 *
 * Usage (maintainer testbed):
 *   php docs/tools/scale_compare.php \
 *       --baseline=/var/www/html/audit_state/perf/baseline-m5pg.json \
 *       --run=/srv/ci/perf/run-<stamp>-SCALE10KF.json \
 *       [--readgrowth=0.10] [--readmargin=25] \
 *       [--timefactor=2.0] [--timemargin=0.25]
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use mod_selfselectadvanced\local\perf\compare;

[$options] = cli_get_params([
    'help' => false,
    'baseline' => '',
    'run' => '',
    'readgrowth' => compare::READGROWTH,
    'readmargin' => compare::READMARGIN,
    'timefactor' => compare::TIMEFACTOR,
    'timemargin' => compare::TIMEMARGIN,
], ['h' => 'help']);

if ($options['help'] || $options['baseline'] === '' || $options['run'] === '') {
    cli_writeln("Compare a scale-harness run against an accepted baseline.

Options:
  --baseline=<path>   accepted baseline JSON (required)
  --run=<path>        run JSON to judge (required)
  --readgrowth=<f>    relative read tolerance (default " . compare::READGROWTH . ")
  --readmargin=<n>    absolute read tolerance (default " . compare::READMARGIN . ")
  --timefactor=<f>    wall-time factor tolerance (default " . compare::TIMEFACTOR . ")
  --timemargin=<f>    absolute wall-time tolerance in seconds (default " . compare::TIMEMARGIN . ")
  -h, --help          this help");
    exit($options['help'] ? 0 : 2);
}

/**
 * Read a run record and index its probes by label.
 *
 * Duplicate labels are refused rather than silently collapsed: the
 * label IS the comparator's key, so two probes sharing one would hide
 * whichever came first and quietly shrink the instrument.
 *
 * @param string $what which file this is, for the error message
 * @param string $path the JSON file
 * @return array label => stdClass probe record
 */
function selfselectadvanced_scale_compare_load(string $what, string $path): array {
    if (!is_readable($path)) {
        cli_error("{$what} file is not readable: {$path}");
    }
    $decoded = json_decode((string) file_get_contents($path));
    if (!is_object($decoded) || !isset($decoded->probes) || !is_array($decoded->probes)) {
        cli_error("{$what} file is not a scale-harness run record: {$path}");
    }
    $map = [];
    foreach ($decoded->probes as $probe) {
        if (!is_object($probe) || !isset($probe->label)) {
            cli_error("{$what} file contains a probe without a label: {$path}");
        }
        $label = (string) $probe->label;
        if (array_key_exists($label, $map)) {
            cli_error("{$what} file contains a duplicate probe label: {$label}");
        }
        $map[$label] = $probe;
    }
    if (!$map) {
        cli_error("{$what} file contains no probes: {$path}");
    }
    if (isset($decoded->meta)) {
        $meta = $decoded->meta;
        cli_writeln(sprintf(
            '%-8s %s  shortname=%s  plugin=%s/%s  db=%s  probes=%d',
            $what . ':',
            $meta->timeutc ?? '?',
            $meta->shortname ?? '?',
            $meta->pluginversion ?? '?',
            $meta->release ?? '?',
            $meta->dbfamily ?? '?',
            count($map)
        ));
    }

    return $map;
}

$baseline = selfselectadvanced_scale_compare_load('baseline', (string) $options['baseline']);
$run = selfselectadvanced_scale_compare_load('run', (string) $options['run']);

$verdict = compare::verdicts(
    $baseline,
    $run,
    (float) $options['readgrowth'],
    (int) $options['readmargin'],
    (float) $options['timefactor'],
    (float) $options['timemargin']
);

cli_writeln('');
foreach ($verdict->lines as $line) {
    cli_writeln($line);
}
cli_writeln('');
cli_writeln('### SCALECOMPARE fail=' . $verdict->fail);

exit($verdict->fail === 0 ? 0 : 1);
