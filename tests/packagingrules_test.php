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
 * The packaging rules the plugins directory review asked for, pinned so they
 * cannot rot back.
 *
 * Filed 2026-07-30 against 1.18.3 by the directory reviewer:
 *   issue 1  BLOCKER  no package-level LICENSE file
 *   issue 2  HIGH     global functions not frankenstyle prefixed
 *   issue 3  HIGH     a message provider with no matching language string
 *
 * Issue 3 turned out to be fixed already, which is exactly why these are
 * tests and not a checklist: nobody could tell without looking, and the
 * looking has to happen again on every future release.
 *
 * Issue 2 was reported with ONE example, moveedit.php's clean_param_alphaext().
 * Auditing the whole plugin found eight more the reviewer's sampling missed,
 * including a bare probe() in docs/tools/ and a helper named
 * upgrade_selfselectadvanced_* sitting inside core's own upgrade_* space. So
 * the pin here is the RULE over the whole package, not the three names that
 * happened to be reported.
 *
 * Every check states how many things it examined and refuses to pass on an
 * empty subject set. A green check that examined nothing is worse than a red
 * one, and a packaging test is the easiest place in a codebase to write one by
 * accident - point it at the wrong directory and every rule holds vacuously.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class packagingrules_test extends \advanced_testcase {
    /** @var string The plugin root. */
    private const ROOT = __DIR__ . '/..';

    /**
     * The package ships a licence file reviewers and users can read.
     *
     * @return void
     */
    public function test_the_package_carries_a_licence_file(): void {
        $path = self::ROOT . '/LICENSE';
        $this->assertFileExists($path, 'the plugin root must carry a LICENSE file (directory review, issue 1)');
        $text = (string) file_get_contents($path);
        $this->assertGreaterThan(
            10000,
            strlen($text),
            'the LICENSE file must hold the licence text, not a stub or a pointer'
        );
        $this->assertStringContainsString(
            'GNU GENERAL PUBLIC LICENSE',
            $text,
            'the licence must be the GPL the plugin headers all claim'
        );
        $this->assertStringContainsString('Version 3', $text, 'GPL v3, matching every file header');
    }

    /**
     * Every global function in the package is frankenstyle prefixed.
     *
     * Tokenised, not grepped: a `function foo(` inside a comment or a string
     * would satisfy a substring search, and source assertions in this codebase
     * have been fooled by a comment three times.
     *
     * @return void
     */
    public function test_every_global_function_is_frankenstyle_prefixed(): void {
        $offenders = [];
        $scanned = 0;
        $declarations = 0;

        foreach ($this->php_files() as $file) {
            $src = (string) file_get_contents($file);
            $tokens = token_get_all($src);
            if ($this->declares_a_namespace($tokens)) {
                // Namespaced code cannot collide; the namespace is the prefix.
                continue;
            }
            $scanned++;
            foreach ($this->global_function_names($tokens) as $name) {
                $declarations++;
                if (
                    strpos($name, 'selfselectadvanced_') !== 0
                    && strpos($name, 'xmldb_selfselectadvanced_') !== 0
                ) {
                    $offenders[] = str_replace(realpath(self::ROOT) . '/', '', $file) . ': ' . $name . '()';
                }
            }
        }

        // Refuse to pass on an empty subject set. Point this test at the wrong
        // directory and every assertion below holds while proving nothing.
        $this->assertGreaterThan(20, $scanned, 'too few non-namespaced files scanned - the scan found nothing to check');
        $this->assertGreaterThan(20, $declarations, 'too few global functions found - the tokeniser is not seeing declarations');

        $this->assertSame(
            [],
            $offenders,
            'every global function must be frankenstyle prefixed (directory review, issue 2). Offenders: '
                . implode(', ', $offenders)
        );
    }

    /**
     * Every message provider has the language string core will ask for.
     *
     * @return void
     */
    public function test_every_message_provider_has_its_language_string(): void {
        $messageproviders = [];
        require(self::ROOT . '/db/messages.php');
        $string = [];
        require(self::ROOT . '/lang/en/selfselectadvanced.php');

        $this->assertNotEmpty($messageproviders, 'no message providers parsed - this check would be vacuous');
        $this->assertNotEmpty($string, 'no language strings parsed - this check would be vacuous');

        $missing = [];
        foreach (array_keys($messageproviders) as $provider) {
            if (!isset($string['messageprovider:' . $provider])) {
                $missing[] = 'messageprovider:' . $provider;
            }
        }
        $this->assertSame(
            [],
            $missing,
            'every provider in db/messages.php needs messageprovider:<name> in lang/en '
                . '(directory review, issue 3). Missing: ' . implode(', ', $missing)
        );
    }

    /**
     * The plugin claims exactly the Moodle branch it is tested on.
     *
     * Narrowed from "4.5 LTS to 5.2" on maintainer instruction 2026-08-03: the
     * gate that governs this codebase runs one branch, so promising four was a
     * claim nobody had verified.
     *
     * @return void
     */
    public function test_version_php_claims_only_the_branch_that_is_tested(): void {
        $plugin = new \stdClass();
        require(self::ROOT . '/version.php');

        $this->assertSame([502, 502], $plugin->supported, 'Moodle 5.2 only');
        $this->assertGreaterThanOrEqual(
            2026042001,
            $plugin->requires,
            'requires must name Moodle 5.2, so a 5.1 site is refused at install rather than at runtime'
        );
        $this->assertSame('mod_selfselectadvanced', $plugin->component);
    }

    /**
     * The PHP floor is asserted in BOTH entry points, and before any savepoint.
     *
     * Moodle has no version.php field for a PHP minimum - core parses version,
     * requires, supported, incompatible, release, maturity and dependencies and
     * nothing else - so the floor only exists if the plugin asserts it itself.
     * A floor declared in one entry point and not the other is a floor that a
     * fresh install or an upgrade walks straight through.
     *
     * @return void
     */
    public function test_the_php_floor_is_asserted_on_install_and_on_upgrade(): void {
        foreach (['db/install.php', 'db/upgrade.php'] as $rel) {
            $src = self::executable_source(self::ROOT . '/' . $rel);
            $this->assertStringContainsString(
                "version_compare(PHP_VERSION, '8.4.0', '<')",
                $src,
                $rel . ' must assert the PHP 8.4 floor in code, not in a comment'
            );
            $this->assertStringContainsString(
                'errorphptoolow',
                $src,
                $rel . ' must raise the plain-English refusal, not a bare exception'
            );
        }

        // The upgrade guard must come BEFORE the first savepoint, or a refusal
        // leaves the ladder half-applied.
        $upgrade = self::executable_source(self::ROOT . '/db/upgrade.php');
        $guard = strpos($upgrade, "version_compare(PHP_VERSION, '8.4.0', '<')");
        $savepoint = strpos($upgrade, 'upgrade_mod_savepoint');
        $this->assertNotFalse($guard);
        $this->assertNotFalse($savepoint);
        $this->assertLessThan(
            $savepoint,
            $guard,
            'the PHP floor must be checked before any savepoint runs, so a refusal applies nothing'
        );
    }

    /**
     * The refusal string exists and names the version it needs.
     *
     * @return void
     */
    public function test_the_php_refusal_string_is_defined(): void {
        $string = [];
        require(self::ROOT . '/lang/en/selfselectadvanced.php');
        $this->assertArrayHasKey('errorphptoolow', $string);
        $this->assertStringContainsString('8.4', $string['errorphptoolow'], 'the message must name the version required');
        $this->assertStringContainsString(
            '{$a}',
            $string['errorphptoolow'],
            'the message must report the version actually running, or the reader cannot act on it'
        );
    }

    /**
     * Source with comments and strings removed.
     *
     * @param string $path file to read
     * @return string executable source
     */
    private static function executable_source(string $path): string {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    /**
     * Whether this token stream declares a namespace.
     *
     * @param array $tokens token_get_all output
     * @return bool
     */
    private function declares_a_namespace(array $tokens): bool {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Names of functions declared at the top level of this token stream.
     *
     * Methods are skipped: a class method cannot collide globally. Depth is
     * tracked with braces because T_FUNCTION alone cannot tell a method from a
     * function, and a closure has no name to collide with.
     *
     * @param array $tokens token_get_all output
     * @return array function names
     */
    private function global_function_names(array $tokens): array {
        $names = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } else if ($token === '}') {
                    $depth--;
                }
                continue;
            }
            if ($token[0] !== T_FUNCTION || $depth !== 0) {
                continue;
            }
            // The next meaningful token is the name, or '(' for a closure.
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && ($next[0] === T_WHITESPACE || $next[0] === T_COMMENT || $next[0] === T_DOC_COMMENT)) {
                    continue;
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    $names[] = $next[1];
                }
                break;
            }
        }
        return $names;
    }

    /**
     * Every PHP file that ships in the package.
     *
     * @return array absolute paths
     */
    private function php_files(): array {
        $root = realpath(self::ROOT);
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php') {
                continue;
            }
            // Tests are not shipped behaviour and use namespaces anyway; node
            // and vendor trees are not ours.
            if (strpos($path, '/node_modules/') !== false || strpos($path, '/vendor/') !== false) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);
        return $files;
    }
}
