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
 * Public static guards and refusal producers must have an executable caller.
 *
 * A dead guard is worse than no guard when its docblock makes reviewers believe
 * a stale or authority path is protected. This check is intentionally narrow:
 * public static methods under classes/local whose name starts require_, can_ or
 * may_, whose name ends _refusal, _decision or _verdict, or whose declared return
 * type names refusal. The
 * caller scan is class-aware and strips comments and string literals, so a test
 * that merely quotes "Class::method(" cannot keep a dead method green.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class guard_callers_test extends \advanced_testcase {
    /**
     * Intentionally uncalled public guards/producers.
     *
     * Empty on the 1.20.26 baseline. Any future entry must carry the concrete reason why a
     * public guard is deliberately shipped without an executable caller.
     */
    private const ALLOWLIST = [];

    /**
     * Every discovered guard/producer has at least one executable caller.
     */
    public function test_public_static_guards_and_decision_producers_have_callers(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);

        $candidates = self::discover_candidates($root);
        $this->assertNotEmpty($candidates, 'the definition found no public guards/producers and is therefore vacuous');

        foreach (self::ALLOWLIST as $method => $reason) {
            $this->assertArrayHasKey($method, $candidates, 'allowlist entry is not a discovered candidate: ' . $method);
            $this->assertNotSame('', trim($reason), 'an allowlist entry must explain why it is intentionally uncalled');
        }

        $dead = [];
        foreach ($candidates as $id => $candidate) {
            $count = self::caller_count($root, $candidate);
            if ($count === 0 && !array_key_exists($id, self::ALLOWLIST)) {
                $dead[] = $id . ' declared in ' . self::relative_path($root, $candidate['path']);
            }
        }
        $this->assertSame([], $dead, "Public guards/producers with no executable caller:\n" . implode("\n", $dead));

        // Non-vacuity pin. This public helper is intentionally reached only through
        // guidepicker::render(); removing that one self-call must make the scanner
        // report it as dead rather than leaving an empty-candidate test green.
        $sentinel = 'mod_selfselectadvanced\\local\\guidepicker::require_js';
        $this->assertArrayHasKey($sentinel, $candidates);
        $this->assertGreaterThan(0, self::caller_count($root, $candidates[$sentinel]));
    }

    /**
     * Discover the deliberately narrow guard/producer definition.
     *
     * @param string $root plugin root
     * @return array candidates keyed by fqn; each has path, fqn, short, namespace, method
     */
    private static function discover_candidates(string $root): array {
        $localroot = $root . '/classes/local';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($localroot));
        $candidates = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $code = self::executable_code($path, false);
            if (!preg_match('/\bnamespace\s+([^;]+);/', $code, $namespacematch)) {
                continue;
            }
            if (!preg_match('/\b(?:final\s+|abstract\s+)?class\s+(\w+)/', $code, $classmatch)) {
                continue;
            }
            $namespace = trim($namespacematch[1]);
            $short = $classmatch[1];
            $fqn = $namespace . '\\' . $short;

            preg_match_all(
                '/public\s+static\s+function\s+(\w+)\s*\((.*?)\)\s*(?::\s*([^\{]+))?\s*\{/s',
                $code,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                $method = $match[1];
                $returntype = strtolower(trim($match[3] ?? ''));
                if (
                    !str_starts_with($method, 'require_')
                    && !str_starts_with($method, 'can_')
                    && !str_starts_with($method, 'may_')
                    && !str_ends_with($method, '_refusal')
                    && !str_ends_with($method, '_decision')
                    && !str_ends_with($method, '_verdict')
                    && !str_contains($returntype, 'refusal')
                ) {
                    continue;
                }
                $id = $fqn . '::' . $method;
                $candidates[$id] = [
                    'path' => $path,
                    'fqn' => $fqn,
                    'short' => $short,
                    'namespace' => $namespace,
                    'method' => $method,
                ];
            }
        }
        ksort($candidates);

        return $candidates;
    }

    /**
     * Count class-resolved executable calls to one static method.
     *
     * Search scope is COMPLETE for executable PHP in the root pages, classes/ and
     * tests/ (including PHP Behat contexts). Feature prose is deliberately not a
     * caller: naming a method in Gherkin cannot execute it.
     *
     * @param string $root plugin root
     * @param array $candidate the candidate: path, fqn, short, namespace, method
     * @return int caller count
     */
    private static function caller_count(string $root, array $candidate): int {
        $count = 0;
        foreach (self::caller_files($root) as $path) {
            $code = self::executable_code($path, true);
            $patterns = [];
            $patterns[] = '/(?<![A-Za-z0-9_\\\\])\\\\?' . preg_quote($candidate['fqn'], '/')
                . '\s*::\s*' . preg_quote($candidate['method'], '/') . '\s*\(/';

            $namespace = self::namespace_of($code);
            if ($namespace === $candidate['namespace']) {
                $patterns[] = '/\b' . preg_quote($candidate['short'], '/') . '\s*::\s*'
                    . preg_quote($candidate['method'], '/') . '\s*\(/';
            }
            foreach (self::aliases_for($code, $candidate['fqn'], $candidate['short']) as $alias) {
                $patterns[] = '/\b' . preg_quote($alias, '/') . '\s*::\s*'
                    . preg_quote($candidate['method'], '/') . '\s*\(/';
            }
            if (realpath($path) === realpath($candidate['path'])) {
                $patterns[] = '/\b(?:self|static)\s*::\s*' . preg_quote($candidate['method'], '/') . '\s*\(/';
            }

            $spans = [];
            foreach (array_unique($patterns) as $pattern) {
                if (!preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($matches[0] as [$text, $offset]) {
                    $spans[$offset . ':' . strlen($text)] = true;
                }
            }
            $count += count($spans);
        }

        return $count;
    }

    /**
     * PHP files that can execute a local guard in the shipped plugin or its tests.
     *
     * @param string $root plugin root
     * @return string[] absolute paths
     */
    private static function caller_files(string $root): array {
        $paths = glob($root . '/*.php') ?: [];
        foreach ([$root . '/classes', $root . '/tests'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * Aliases in one file which resolve to the candidate class.
     *
     * @param string $code executable source
     * @param string $fqn fully qualified class name
     * @param string $short unaliased short class name
     * @return string[] aliases usable in static calls
     */
    private static function aliases_for(string $code, string $fqn, string $short): array {
        $aliases = [];
        $pattern = '/\buse\s+\\\\?' . preg_quote($fqn, '/') . '(?:\s+as\s+(\w+))?\s*;/i';
        if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $aliases[] = $match[1] ?? $short;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * Namespace of a PHP file, or an empty string for root scripts.
     *
     * @param string $code executable source
     * @return string namespace
     */
    private static function namespace_of(string $code): string {
        return preg_match('/\bnamespace\s+([^;]+);/', $code, $match) ? trim($match[1]) : '';
    }

    /**
     * Executable PHP with comments removed and, for caller scans, string literals removed too.
     *
     * A source-test assertion containing 'Class::method(' is evidence about source,
     * not an invocation. Removing string tokens is what prevents that assertion from
     * being mistaken for a caller.
     *
     * @param string $path PHP file
     * @param bool $stripstrings whether quoted strings should be removed
     * @return string executable source
     */
    private static function executable_code(string $path, bool $stripstrings): string {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \coding_exception('Unreadable source file: ' . $path);
        }
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $code .= $token;
                continue;
            }
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            if ($stripstrings && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $code .= ' ';
                continue;
            }
            $code .= $token[1];
        }

        return $code;
    }

    /**
     * Path relative to the plugin root for failure output.
     *
     * @param string $root plugin root
     * @param string $path absolute path
     * @return string relative path
     */
    private static function relative_path(string $root, string $path): string {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
