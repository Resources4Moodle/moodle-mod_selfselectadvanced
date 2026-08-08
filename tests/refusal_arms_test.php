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
 * The failure-transport contract, pinned where every CI runs it
 * (decision 68, wave 1.5): expected workflow refusals travel as
 * workflow_refusal, and no human-action controller swallows the rest
 * of moodle_exception's family.
 *
 * This is the PHPUnit mirror of the local gate's refusal-typing static
 * guard - mirrored deliberately, because GitHub Actions runs the
 * standard moodle-plugin-ci set and would otherwise never ask these two
 * questions. Until 1.20.22 this file was a self-declared weak canary
 * that counted NOTIFY_ERROR strings; the consolidated master audit
 * (§4.4) showed a particular arm could lose its catch while the count
 * stayed green, so the counting is gone and the contract itself is
 * asserted. stale_matrix_test and stale_action_test drive the seams
 * behaviourally; this file keeps the source contract from drifting.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class refusal_arms_test extends \advanced_testcase {
    /**
     * PHP files under a directory, recursively.
     *
     * @param string $dir absolute directory
     * @return string[] absolute paths
     */
    private function phpfiles(string $dir): array {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * No expected refusal travels untyped: a throw whose key literal
     * starts with "refusal", or whose first argument is a gatekeeper
     * refusal transport, must construct workflow_refusal.
     */
    public function test_no_untyped_refusal_throw_in_services(): void {
        $offenders = [];
        $throws = 0;
        $files = $this->phpfiles(__DIR__ . '/../classes');
        foreach ($files as $f) {
            $source = file_get_contents($f);
            $throws += preg_match_all('/throw new \\\\?moodle_exception\s*\(/', $source);
            if (
                preg_match_all(
                    '/throw new \\\\?moodle_exception\s*\(\s*[\'"]refusal/m',
                    $source,
                    $m
                )
            ) {
                $offenders[] = basename($f) . ': ' . count($m[0]) . ' untyped refusal key(s)';
            }
            if (
                preg_match_all(
                    '/throw new \\\\?moodle_exception\s*\(\s*\$(refusal|plan->refusal)->stringkey/m',
                    $source,
                    $m
                )
            ) {
                $offenders[] = basename($f) . ': ' . count($m[0]) . ' untyped gatekeeper transport(s)';
            }
        }
        // A guard that examined nothing must never pass: the tree
        // always has far more class files and throws than this.
        $this->assertGreaterThan(10, count($files), 'scanned the wrong tree');
        $this->assertGreaterThan(5, $throws, 'scanned the wrong tree');
        $this->assertSame(
            [],
            $offenders,
            "expected refusals must construct workflow_refusal:\n" . implode("\n", $offenders)
        );
    }

    /**
     * No human-action controller swallows the moodle_exception family:
     * every broad catch in a root page rethrows what it does not
     * explicitly map (the allowlist-and-rethrow shape); everything else
     * catches the workflow_refusal type exactly.
     */
    public function test_no_swallowing_broad_catch_in_root_pages(): void {
        $offenders = [];
        $catches = 0;
        $typed = 0;
        $files = glob(dirname(__DIR__) . '/*.php');
        foreach ($files as $f) {
            $source = file_get_contents($f);
            $typed += substr_count($source, 'catch (\\mod_selfselectadvanced\\local\\workflow_refusal');
            $offset = 0;
            while (
                preg_match(
                    '/catch \(\\\\?moodle_exception[^)]*\)\s*\{/',
                    $source,
                    $m,
                    PREG_OFFSET_CAPTURE,
                    $offset
                )
            ) {
                $catches++;
                $open = strpos($source, '{', $m[0][1]);
                $depth = 0;
                for ($i = $open, $len = strlen($source); $i < $len; $i++) {
                    if ($source[$i] === '{') {
                        $depth++;
                    } else if ($source[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $body = substr($source, $open, $i - $open + 1);
                if (strpos($body, 'throw $e;') === false) {
                    $line = substr_count($source, "\n", 0, $m[0][1]) + 1;
                    $offenders[] = basename($f) . ':' . $line . ' broad catch with no rethrow';
                }
                $offset = $i;
            }
        }
        $this->assertGreaterThan(10, count($files), 'scanned the wrong tree');
        $this->assertGreaterThan(
            20,
            $typed,
            'the typed catches are the arms themselves - losing them all means the wrong tree or a regression'
        );
        $this->assertSame(
            [],
            $offenders,
            "a broad catch must rethrow what it does not map:\n" . implode("\n", $offenders)
        );
    }
}
