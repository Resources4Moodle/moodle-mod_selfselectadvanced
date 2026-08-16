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
            foreach ($lines as $number => $line) {
                // The two shapes an ordering takes in this codebase.
                $issql = (bool) preg_match('/ORDER BY[^"\']*timecreated\s+(ASC|DESC)/i', $line);
                $isarg = (bool) preg_match('/[\'"]timecreated\s+(ASC|DESC)[\'"]/i', $line);
                if (!$issql && !$isarg) {
                    continue;
                }
                $examined++;
                if (preg_match('/\bid\s+(ASC|DESC)/i', $line)) {
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
