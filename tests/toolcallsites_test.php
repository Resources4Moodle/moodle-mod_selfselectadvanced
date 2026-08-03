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
 * The maintainer tools in docs/tools/ must still be CALLABLE.
 *
 * WHY THIS EXISTS. 1.20.1 wave 3F gave quota\store::save() a required
 * third argument (the acting user, audit D7-b) and did not update
 * docs/tools/seed_howto.php, the only caller of that service outside the
 * plugin's own pages. The result was measured on the dev site: the
 * seeder died at "Too few arguments to function
 * mod_selfselectadvanced\local\quota\store::save(), 2 passed ... and
 * exactly 3 expected", thrown from store.php:100, immediately after the
 * activity was created and before a single demonstration team existed -
 * so the how-to deck could not be rebuilt at all.
 *
 * NOTHING IN THE GATE SAW IT, and each miss is for its own reason:
 *  - phplint is a syntax check, and the call site is syntactically
 *    perfect with two arguments or with three;
 *  - phpcs and phpdoc do not resolve a static call to its target;
 *  - PHPUnit never executes a page script or a CLI tool, and docs/ is
 *    excluded from the release zip so nothing else looks at it;
 *  - seedhowto_test pins the seeder's SETTINGS as text, which is a
 *    different question and stayed green throughout.
 * A tool nobody executes is a tool nobody notices breaking, and the two
 * tools here are how the maintainer's documentation and the performance
 * numbers are produced.
 *
 * WHAT IT CHECKS, and what it deliberately does not. Every statically
 * written call to one of THIS PLUGIN'S OWN classes in docs/tools/ is
 * resolved against the real class by reflection, and its argument count
 * is compared with the signature. It does not type-check the arguments
 * and it does not follow variables, so it is a signature check and not
 * a substitute for running the tool. It catches exactly the failure
 * above - a service that grew or lost a parameter while a caller
 * outside the plugin's pages stayed behind - which is the failure a
 * whole-suite green cannot see.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class toolcallsites_test extends \basic_testcase {
    /**
     * Every plugin service the maintainer tools call must be called with
     * a number of arguments its signature accepts.
     */
    public function test_the_maintainer_tools_call_this_plugins_services_correctly(): void {
        $dir = __DIR__ . '/../docs/tools';
        if (!is_dir($dir)) {
            // The docs directory is excluded from the release zip, so a
            // zip-built tree legitimately has nothing here. SKIP, never
            // silently pass.
            $this->markTestSkipped('docs/tools is absent from this tree');
        }

        $files = glob($dir . '/*.php');
        sort($files);
        $this->assertGreaterThanOrEqual(
            3,
            count($files),
            'the tool directory looks empty - this check would have examined nothing'
        );

        $checked = 0;
        foreach ($files as $file) {
            foreach (self::static_calls($file) as $call) {
                [$class, $method, $args, $line] = $call;
                $where = basename($file) . ':' . $line . ' ' . $class . '::' . $method . '()';

                $this->assertTrue(class_exists($class), $where . ' - no such class');
                $this->assertTrue(method_exists($class, $method), $where . ' - no such method');

                $rm = new \ReflectionMethod($class, $method);
                $min = $rm->getNumberOfRequiredParameters();
                $max = $rm->isVariadic() ? PHP_INT_MAX : $rm->getNumberOfParameters();

                $this->assertGreaterThanOrEqual(
                    $min,
                    $args,
                    $where . ' is called with ' . $args . ' argument(s) and needs at least ' . $min
                        . '. This tool is not run by any test or by the gate, so a signature change '
                        . 'that leaves it behind is invisible until somebody runs it.'
                );
                $this->assertLessThanOrEqual(
                    $max,
                    $args,
                    $where . ' is called with ' . $args . ' argument(s) and accepts at most ' . $max
                );
                $checked++;
            }
        }

        // A check that examined nothing is worse than a red one.
        $this->assertGreaterThan(
            20,
            $checked,
            'only ' . $checked . ' call sites were found - the scanner is broken, not the tools'
        );
    }

    /**
     * Statically written calls to this plugin's own classes in one file.
     *
     * Resolves short names through the file's `use` statements, counts
     * top-level arguments by bracket depth, and skips anything it cannot
     * read literally (spread arguments, a variable class name) rather
     * than guessing about it.
     *
     * @param string $path absolute path to a PHP file
     * @return array[] list of [class, method, argcount, line]
     */
    private static function static_calls(string $path): array {
        $tokens = token_get_all(file_get_contents($path));
        // Drop comments and whitespace: a commented-out call is not a call,
        // and a call split over five lines is still one call.
        $t = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $t[] = $token;
        }

        $aliases = self::aliases($t);
        $calls = [];
        $count = count($t);
        for ($i = 0; $i < $count - 3; $i++) {
            if (!is_array($t[$i]) || !self::is_name($t[$i][0])) {
                continue;
            }
            if (!is_array($t[$i + 1]) || $t[$i + 1][0] !== T_DOUBLE_COLON) {
                continue;
            }
            if (!is_array($t[$i + 2]) || $t[$i + 2][0] !== T_STRING || $t[$i + 3] !== '(') {
                continue;
            }
            $class = self::resolve($t[$i][1], $aliases);
            if (!str_starts_with($class, 'mod_selfselectadvanced\\')) {
                continue;
            }
            $args = self::count_args($t, $i + 3);
            if ($args === null) {
                continue;
            }
            $calls[] = [$class, $t[$i + 2][1], $args, $t[$i][2]];
        }

        return $calls;
    }

    /**
     * Whether a token id is a class-name token.
     *
     * @param int $id the token id
     * @return bool
     */
    private static function is_name(int $id): bool {
        return $id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED;
    }

    /**
     * The file's `use X\Y\Z;` and `use X\Y\Z as W;` map.
     *
     * @param array $t comment-free, whitespace-free token list
     * @return string[] short name => fully qualified name
     */
    private static function aliases(array $t): array {
        $map = [];
        $count = count($t);
        for ($i = 0; $i < $count - 1; $i++) {
            if (!is_array($t[$i]) || $t[$i][0] !== T_USE) {
                continue;
            }
            if (!is_array($t[$i + 1]) || !self::is_name($t[$i + 1][0])) {
                continue;
            }
            $fqn = ltrim($t[$i + 1][1], '\\');
            $short = substr($fqn, (int) strrpos($fqn, '\\') + 1);
            if (
                isset($t[$i + 2], $t[$i + 3]) && is_array($t[$i + 2]) && $t[$i + 2][0] === T_AS
                && is_array($t[$i + 3]) && $t[$i + 3][0] === T_STRING
            ) {
                $short = $t[$i + 3][1];
            }
            $map[$short] = $fqn;
        }

        return $map;
    }

    /**
     * A written class name resolved to a fully qualified one.
     *
     * @param string $written the name as it appears in the source
     * @param string[] $aliases the file's use map
     * @return string
     */
    private static function resolve(string $written, array $aliases): string {
        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }
        $head = strtok($written, '\\');
        if (isset($aliases[$head])) {
            return $aliases[$head] . substr($written, strlen($head));
        }

        return $written;
    }

    /**
     * Top-level argument count of the call whose '(' is at $open.
     *
     * @param array $t comment-free, whitespace-free token list
     * @param int $open index of the opening parenthesis
     * @return int|null the count, or null when the call cannot be read literally
     */
    private static function count_args(array $t, int $open): ?int {
        $depth = 0;
        $args = 0;
        $seen = false;
        $count = count($t);
        for ($i = $open; $i < $count; $i++) {
            $tok = $t[$i];
            if (is_array($tok)) {
                if ($tok[0] === T_ELLIPSIS) {
                    // A spread turns the count into a runtime question.
                    return null;
                }
                if ($tok[0] === T_ATTRIBUTE || $tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                $seen = true;
                continue;
            }
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
                if ($depth > 1) {
                    $seen = true;
                }
                continue;
            }
            if ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
                if ($depth === 0) {
                    return $seen ? $args + 1 : 0;
                }
                $seen = true;
                continue;
            }
            if ($tok === ',' && $depth === 1) {
                // A trailing comma before ')' is not another argument; the
                // $seen flag reset below makes the closing branch above
                // notice an empty tail.
                $args++;
                $seen = false;
                continue;
            }
            $seen = true;
        }

        return null;
    }
}
