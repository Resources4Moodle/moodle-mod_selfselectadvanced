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

/**
 * An order somebody reads must not be left to the database.
 *
 * WHERE THIS CAME FROM. The 1.20.47 gate failed ONE Behat scenario on
 * PostgreSQL and passed the same scenario on MariaDB in the same run:
 * `groups::get_groups_of_user()` ordered by `timecreated ASC` alone, two
 * groups had been created inside the same second, and a tie leaves the order
 * to the engine. joinrequest.php renders that order straight into a sentence
 * a STUDENT reads - "You are in these groups at the moment: Team Blue, Team
 * Gold." - so the tie was not cosmetic. A re-run would have gone green and
 * left the defect in; the asymmetry between engines is what gave it away.
 *
 * Ten more untied orderings were then found by scanning, and all eleven now
 * break the tie on id. THIS TEST STOPS THE TWELFTH: it reads the source and
 * fails on any `timecreated` ordering that does not carry an id tiebreaker,
 * so a future author cannot reintroduce the class of defect quietly. It is a
 * source scan rather than a behavioural test on purpose - the behaviour only
 * misbehaves on a tie, on one engine, sometimes, which is precisely what a
 * test cannot reliably reproduce and a reader can check exactly.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class ordering_determinism_test extends \advanced_testcase {
    /**
     * Production PHP files: the plugin's own classes and its root pages.
     *
     * Tests are excluded - a fixture may order however it likes - and so is
     * anything under db/, which describes schema rather than reads rows.
     *
     * @return string[] absolute paths
     */
    private function production_files(): array {
        $root = realpath(__DIR__ . '/..');
        $files = [];
        foreach (glob($root . '/*.php') as $path) {
            $files[] = $path;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every `timecreated` ordering names a tiebreaker.
     *
     * The scan is deliberately generous about SHAPE - it catches both the SQL
     * `ORDER BY x.timecreated ASC` and the `get_records()` sort argument
     * `'timecreated ASC'` - and strict about the ANSWER: the same statement
     * must also mention an id column, in either direction.
     */
    public function test_every_timecreated_ordering_breaks_its_tie(): void {
        $offenders = [];
        $examined = 0;

        foreach ($this->production_files() as $path) {
            $lines = file($path);
            $count = count($lines);
            foreach ($lines as $number => $line) {
                // Prose is not an ordering. A docblock that happens to say
                // "keyed by group id, timecreated ASC" describes one; it does
                // not perform one, and flagging it would be a false alarm.
                $trimmed = ltrim($line);
                if (
                    $trimmed === ''
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '/*')
                ) {
                    continue;
                }
                // THE TRIGGER IS THE ORDERING ITSELF, wherever it sits.
                //
                // The first version of this scan demanded ORDER BY, or a
                // quote, on the SAME LINE - which a multi-line SQL string
                // never satisfies. A review of 1.20.53 found two orderings
                // hiding in exactly that blind spot, including one the
                // release had just added: group_live() puts
                // `t.timecreated DESC,` on its own continuation line, so the
                // scan never examined it and its id tiebreaker could have
                // been deleted without a single test going red. A checker
                // that cannot see the code it is meant to guard is worse
                // than no checker, because it reports a number.
                if (!preg_match('/\btimecreated\s+(ASC|DESC)/i', $line)) {
                    continue;
                }
                $examined++;

                // Judge the ORDERING CLAUSE, not the line: back to its
                // ORDER BY, and forward to whatever closes the statement.
                // A tiebreaker on a later line of the same clause counts;
                // one belonging to the NEXT statement must not.
                $start = $number;
                for ($i = $number; $i >= 0 && $i > $number - 12; $i--) {
                    if (preg_match('/ORDER BY/i', $lines[$i])) {
                        $start = $i;
                        break;
                    }
                }
                $end = $number;
                for ($i = $number; $i < $count && $i < $number + 12; $i++) {
                    $end = $i;
                    if (preg_match('/;|["\']\s*[,)]/', $lines[$i])) {
                        break;
                    }
                }
                $clause = implode('', array_slice($lines, $start, $end - $start + 1));
                if (preg_match('/\bid\s+(ASC|DESC)/i', $clause)) {
                    continue;
                }
                $offenders[] = str_replace(realpath(__DIR__ . '/..') . '/', '', $path)
                    . ':' . ($number + 1) . ' ' . trim($line);
            }
        }

        // THE N>0 RULE. A scan that examined nothing has proven nothing, and
        // this one would silently "pass" if the patterns above ever stopped
        // matching the codebase's style.
        $this->assertGreaterThan(
            0,
            $examined,
            'the scan found no timecreated ordering at all - it is measuring nothing'
        );

        $this->assertSame(
            [],
            $offenders,
            "a timecreated ordering with no id tiebreaker can be returned in either order by the "
                . "database, and this plugin renders such orders to users:\n  " . implode("\n  ", $offenders)
        );
    }
}
