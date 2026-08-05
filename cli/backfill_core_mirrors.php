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
 * Create the missing Moodle group mirrors for teams that are already approved.
 *
 * Until 1.20.7 a team's mirrored course group was minted only when the team was
 * FROZEN, so every team sitting at FIRM had no Moodle group and was invisible
 * to group forums, group assignments, quiz and workshop. Mirroring now happens
 * at APPROVAL, which leaves every previously-approved team behind: this script
 * is what brings them forward.
 *
 * It is IDEMPOTENT and safe to re-run, which is deliberate - it doubles as the
 * permanent convergence sweep and as the operator's tool when a mirror drifts,
 * rather than being a one-shot migration nobody dares run twice.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'dry-run' => false,
        'courseid' => 0,
        'activityid' => 0,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unknown option(s):\n  " . $unrecognized, 2);
}

if (!empty($options['help'])) {
    $help = <<<EOF
Backfill Moodle course-group mirrors for approved Group self-selection (Advanced) teams.

Options:
--dry-run       Report what would be synchronised without writing core groups.
--courseid=N    Limit to one Moodle course id.
--activityid=N  Limit to one selfselectadvanced instance id.
-h, --help      Print this help.

Example:
\$ php mod/selfselectadvanced/cli/backfill_core_mirrors.php --courseid=42

EOF;
    cli_writeln($help);
    exit(0);
}

$summary = \mod_selfselectadvanced\local\coresync_backfill::run(
    [
        'dryrun' => !empty($options['dry-run']),
        'courseid' => (int) $options['courseid'],
        'activityid' => (int) $options['activityid'],
    ],
    static function (string $line): void {
        cli_writeln($line);
    }
);

cli_writeln(
    'SUMMARY scanned=' . (int) $summary->scanned
        . ' synced=' . (int) $summary->synced
        . ' changed=' . (int) $summary->changed
        . ' failed=' . (int) $summary->failed
        . ' dryrun=' . (int) $summary->dryrun
);

exit($summary->failed ? 1 : 0);
