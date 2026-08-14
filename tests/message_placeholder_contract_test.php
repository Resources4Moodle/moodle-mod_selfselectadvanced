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

use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\local\coordinatorrole;

/**
 * Every message this plugin sends renders, and every link in one opens.
 *
 * WHY THIS EXISTS. Two defects, both live on the dev site, neither of
 * them failing anything:
 *
 * 1. THE PLACEHOLDER. notifier::send() casts every payload to an object
 *    (`$a = (object) (array) $a`) so it can add firstname/lastname/url
 *    to it. Moodle core substitutes `{$a->key}` when the payload is an
 *    object and a bare `{$a}` ONLY in the scalar branch
 *    (string_manager_standard.php). So a bare `{$a}` in any string this
 *    plugin sends through the notifier can never resolve, whatever the
 *    call site passes. Five subject lines carried one. The group's
 *    leader on the dev site was sent, literally,
 *    `Leadership help requested for "{$a}"`. Nothing caught it because
 *    no test named any of the five keys and no test asserted a rendered
 *    subject for them.
 *
 * 2. THE LINK. A ticket notification carried the queue page as its
 *    contexturl for EVERY recipient, requester included - and the queue
 *    refuses the requester by design (tickets.php requires manage or
 *    coordinate). The message that exists precisely because the
 *    requester cannot open the queue linked them to the queue. That is
 *    the mechanism behind the maintainer's report that a response to a
 *    student's request "cannot be viewed".
 *
 * The static test below is the general guard; the two send tests pin the
 * concrete cases end to end, through the real notifier, so a future
 * refactor of either cannot quietly restore the defect.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\notifier
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class message_placeholder_contract_test extends \advanced_testcase {
    /**
     * Does this string body carry a placeholder that only resolves for a
     * scalar payload?
     *
     * @param string $value the string's PHP source, quotes and all
     * @return bool true when a bare {$a} is present
     */
    private function has_scalar_placeholder(string $value): bool {
        return (bool) preg_match('/\{\$a\}/', $value);
    }

    /**
     * Every string in the English lang file: key => [line, source].
     *
     * @return array<string, array>
     */
    private function lang_strings(): array {
        $path = __DIR__ . '/../lang/en/selfselectadvanced.php';
        $source = file_get_contents($path);
        $this->assertIsString($source, 'the lang file must be readable');

        $strings = [];
        $line = 0;
        foreach (explode("\n", $source) as $raw) {
            $line++;
            if (preg_match('/^\$string\[\'([^\']+)\'\]\s*=\s*(.*)$/', $raw, $m)) {
                $strings[$m[1]] = [$line, $m[2]];
            }
        }
        return $strings;
    }

    /**
     * The same source with its comments blanked, line numbers intact.
     *
     * This plugin comments heavily, and several comments quote the very
     * calls this file scans for - freeze.php discusses
     * `flag_sync_refusals() -> notifier::send()` in prose. A scanner
     * that reads prose reports defects that do not exist, so the
     * tokenizer decides what is code. Each comment becomes the newlines
     * it contained, which keeps every reported line number true.
     *
     * @param string $source the file
     * @return string the file, without comments
     */
    private function strip_comments(string $source): string {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * Every PHP source file that can name a string key, except the lang
     * files themselves and this test suite.
     *
     * @return array<string, string> relative path => contents
     */
    private function php_sources(): array {
        $root = realpath(__DIR__ . '/..');
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php') {
                continue;
            }
            $relative = ltrim(substr($path, strlen($root)), '/');
            if (
                strpos($relative, 'lang/') === 0
                || strpos($relative, 'tests/') === 0
                || strpos($relative, '.git/') === 0
            ) {
                continue;
            }
            $found[$relative] = $this->strip_comments(file_get_contents($path));
        }
        return $found;
    }

    /**
     * The matcher itself works.
     *
     * Without this the static test below turns vacuous the moment the
     * plugin is clean: "no key was flagged" would read the same whether
     * the scan examined 900 strings or none of them. Here it is asked
     * the question with a known answer, both ways round.
     */
    public function test_the_placeholder_matcher_can_tell_the_two_forms_apart(): void {
        $this->assertTrue(
            $this->has_scalar_placeholder('\'Leadership help requested for "{$a}"\';'),
            'a bare {$a} must be recognised - it is the defect this file exists to stop'
        );
        $this->assertFalse(
            $this->has_scalar_placeholder('\'Leadership help requested for "{$a->group}"\';'),
            'the object form is the correct one and must not be flagged'
        );
        $this->assertFalse(
            $this->has_scalar_placeholder('\'No placeholder at all\';'),
            'a string without a placeholder must not be flagged'
        );
    }

    /**
     * Methods that reach notifier::send() with a key it cannot read.
     *
     * The scan below reads the key straight out of a notifier::send()
     * call. Two shapes hide it, and each is answered differently:
     *
     * - FORWARD: a wrapper passes its own parameter through, so the
     *   entry gives the positions of the subject and body keys in the
     *   WRAPPER's argument list and the scan follows it to its call
     *   sites. tickets::notify() is one.
     * - DATA: the key arrives inside a queued payload, so there is
     *   nothing in the call's own file to read. send_nudges::execute()
     *   takes it from adhoc task data. These are covered instead by the
     *   'subjectkey'/'bodykey' array elements that BUILD that payload,
     *   which the scan reads separately.
     *
     * A method that is not named here fails the test rather than being
     * skipped. That is the point of the map: it is how this check
     * refuses to go quietly blind, and it has already caught three
     * indirections that a grep for message keys does not see.
     *
     * @var array<string, array{string, int, int}> method => [kind, subject pos, body pos]
     */
    private const INDIRECT = [
        'notify' => ['forward', 2, 3],
        'execute' => ['data', 0, 0],
    ];

    /**
     * The array keys that carry a message key into a queued payload.
     *
     * @var array<int, string>
     */
    private const PAYLOAD_KEYS = ['\'subjectkey\'', '\'bodykey\''];

    /**
     * The file as a list of code tokens: text and line, no comments, no
     * whitespace.
     *
     * Text scanning cannot do this job. This plugin quotes its own call
     * signatures both in comments (freeze.php discusses
     * `flag_sync_refusals() -> notifier::send()`) and inside string
     * literals (notifier.php's own debugging messages name
     * `notifier::send()`), and a scanner that counts those reports
     * defects that are not there. The tokenizer decides what is code.
     *
     * @param string $source the file
     * @return array<int, array> the code tokens
     */
    private function code_tokens(string $source): array {
        $entries = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    $line += substr_count($token[1], "\n");
                    continue;
                }
                $entries[] = ['text' => $token[1], 'line' => $token[2]];
                continue;
            }
            $entries[] = ['text' => $token, 'line' => $line];
        }
        return $entries;
    }

    /**
     * The arguments of the call whose opening parenthesis is at $from.
     *
     * @param array $tokens the file
     * @param int $from index of the opening parenthesis
     * @return array<int, string> the arguments, in order, as written
     */
    private function call_arguments(array $tokens, int $from): array {
        $depth = 0;
        $current = '';
        $args = [];
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $text = $tokens[$i]['text'];
            if ($text === '(' || $text === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } else if ($text === ')' || $text === ']') {
                $depth--;
                if ($depth === 0) {
                    $args[] = trim($current);
                    return $args;
                }
            } else if ($text === ',' && $depth === 1) {
                $args[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $text;
        }
        return $args;
    }

    /**
     * Where a static call to $class::$method opens its argument list.
     *
     * @param array $tokens the file
     * @param string|null $class the class, or null for any
     * @param string $method the method
     * @return array<int, int> token indexes of the opening parenthesis
     */
    private function call_sites(array $tokens, ?string $class, string $method): array {
        $sites = [];
        $count = count($tokens);
        for ($i = 0; $i < $count - 3; $i++) {
            $offset = $class === null ? 0 : 1;
            if ($class !== null && $tokens[$i]['text'] !== $class) {
                continue;
            }
            if (($tokens[$i + $offset]['text'] ?? '') !== '::') {
                continue;
            }
            if (($tokens[$i + $offset + 1]['text'] ?? '') !== $method) {
                continue;
            }
            if (($tokens[$i + $offset + 2]['text'] ?? '') !== '(') {
                continue;
            }
            $sites[] = $i + $offset + 2;
        }
        return $sites;
    }

    /**
     * The string literals each local variable is assigned before $index.
     *
     * One level, same file, literals only - enough for a stem picked a
     * line or two above the send, which is the only shape that occurs
     * here. Anything else falls through to the family pattern or to the
     * INDIRECT map, both of which fail loudly rather than guess.
     *
     * @param array $tokens the file
     * @param int $index the call site
     * @return array<string, array<int, string>> variable => literals
     */
    private function local_literals(array $tokens, int $index): array {
        $locals = [];
        for ($i = 0; $i < $index - 1; $i++) {
            if ($tokens[$i]['text'][0] !== '$' || $tokens[$i + 1]['text'] !== '=') {
                continue;
            }
            $literals = [];
            for ($j = $i + 2; $j < $index; $j++) {
                if ($tokens[$j]['text'] === ';') {
                    break;
                }
                if (preg_match('/^\'([a-z0-9_]+)\'$/', $tokens[$j]['text'], $m)) {
                    $literals[] = $m[1];
                }
            }
            if ($literals !== []) {
                $locals[$tokens[$i]['text']] = $literals;
            }
        }
        return $locals;
    }

    /**
     * The name of the function a token index sits inside.
     *
     * @param array $tokens the file
     * @param int $index the token
     * @return string the nearest preceding function name, or '' if none
     */
    private function enclosing_function(array $tokens, int $index): string {
        for ($i = $index; $i > 0; $i--) {
            if ($tokens[$i]['text'] === 'function' && isset($tokens[$i + 1])) {
                return $tokens[$i + 1]['text'];
            }
        }
        return '';
    }

    /**
     * The message keys an argument expression can name.
     *
     * Four shapes occur.
     *
     * - A plain literal names one key.
     * - A choice between literals - joinrequests writes
     *   `$accept ? 'msgjoinacceptedsubject' : 'msgjoindeclinedsubject'`
     *   - names each of them, recognised by every literal in the
     *   expression already being a string key.
     * - A concatenation of literals and a variable - coordinatorimport
     *   builds `'msgcoordinator' . $what . 'subject'` - names a FAMILY,
     *   and becomes a pattern so every member is checked rather than
     *   none of them.
     * - Anything with no fixed text to go on names nothing readable and
     *   returns null, which sends the caller to the INDIRECT map. A
     *   pattern of pure wildcard would "match" every key in the file
     *   and report the whole lang file as an offender, which is how
     *   this function first went wrong.
     *
     * @param string $expression the argument, as written
     * @param array $strings the lang file
     * @param array $locals literals held by local variables
     * @return array<int, string>|null the keys, or null when unreadable
     */
    private function keys_from_expression(string $expression, array $strings, array $locals = []): ?array {
        if (preg_match('/^\'([a-z0-9_]+)\'$/', $expression, $m)) {
            return [$m[1]];
        }

        // Literals joined end to end are one key, not several.
        if (preg_match('/^\'[a-z0-9_]+\'(\s*\.\s*\'[a-z0-9_]+\')+$/', $expression)) {
            preg_match_all('/\'([a-z0-9_]+)\'/', $expression, $parts);
            return [implode('', $parts[1])];
        }

        // A choice between two keys. Recognised before any variable in
        // the condition is looked at - deadline_reminder tests
        // `isset($confirmedset[$userid])`, and substituting into that
        // produces nonsense - so the test is: a conditional whose every
        // literal is already a string key names those keys.
        if (strpos($expression, '?') !== false) {
            preg_match_all('/\'([a-z0-9_]+)\'/', $expression, $choices);
            $keys = array_values(array_filter(
                $choices[1],
                static fn(string $literal): bool => array_key_exists($literal, $strings)
            ));
            if (count($choices[1]) >= 2 && count($keys) === count($choices[1])) {
                return $keys;
            }
        }

        // A local stem: state.php picks 'msgsubmitted' or 'msgnowguiding'
        // one line above and appends 'subject'/'body' at the call. Put
        // the stem back before anything else looks at the expression,
        // or the family pattern below widens to every key that ends in
        // the same word.
        if ($locals !== []) {
            $expanded = [];
            foreach (preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $expression, $vars) ? $vars[0] : [] as $var) {
                foreach ($locals[$var] ?? [] as $literal) {
                    $expanded[] = str_replace($var, '\'' . $literal . '\'', $expression);
                }
            }
            if ($expanded !== []) {
                $keys = [];
                foreach ($expanded as $one) {
                    // The stems are literals now, so no locals are needed.
                    foreach ($this->keys_from_expression($one, $strings) ?? [] as $key) {
                        $keys[$key] = true;
                    }
                }
                return array_keys($keys);
            }
        }
        if (!preg_match_all('/\'([a-z0-9_]+)\'/', $expression, $literals)) {
            return null;
        }

        // A choice between keys: every literal in it is one.
        $known = array_values(array_filter(
            $literals[1],
            static fn(string $literal): bool => array_key_exists($literal, $strings)
        ));
        if (count($known) === count($literals[1])) {
            return $known;
        }

        // Otherwise the literals are fragments of a built key.
        $pattern = '';
        $fixed = 0;
        foreach (explode('.', $expression) as $part) {
            $part = trim($part);
            if (preg_match('/^\'([a-z0-9_]*)\'$/', $part, $m)) {
                $pattern .= preg_quote($m[1], '/');
                $fixed += strlen($m[1]);
            } else {
                $pattern .= '[a-z0-9_]+';
            }
        }
        if ($fixed < 3) {
            return null;
        }

        $matched = [];
        foreach (array_keys($strings) as $key) {
            if (preg_match('/^' . $pattern . '$/', $key)) {
                $matched[] = $key;
            }
        }
        return $matched;
    }

    /**
     * Every message key handed to the notifier, with where it was read.
     *
     * @return array<string, string> key => "file:line"
     */
    private function notifier_keys(): array {
        $sources = $this->php_sources();
        $strings = $this->lang_strings();
        $keys = [];
        $forwarders = [];

        $streams = [];
        foreach ($sources as $relative => $contents) {
            $streams[$relative] = $this->code_tokens($contents);
        }

        foreach ($streams as $relative => $tokens) {
            foreach ($this->call_sites($tokens, 'notifier', 'send') as $at) {
                $args = $this->call_arguments($tokens, $at);
                $line = $tokens[$at]['line'];
                $locals = $this->local_literals($tokens, $at);
                foreach ([3, 4] as $position) {
                    $arg = $args[$position] ?? '';
                    $named = $this->keys_from_expression($arg, $strings, $locals);
                    if ($named !== null) {
                        $this->assertNotSame(
                            [],
                            $named,
                            $relative . ':' . $line . ' builds a message key from ' . $arg
                            . ', which matches no string in lang/en/selfselectadvanced.php'
                        );
                        foreach ($named as $key) {
                            $keys[$key] = $relative . ':' . $line;
                        }
                        continue;
                    }
                    // Unreadable here: the enclosing method must say why.
                    $enclosing = $this->enclosing_function($tokens, $at);
                    $this->assertArrayHasKey(
                        $enclosing,
                        self::INDIRECT,
                        $relative . ':' . $line . ' hands the notifier a message key this scan cannot '
                        . 'read, so ' . $enclosing . '() must be named in INDIRECT - as a forwarder, '
                        . 'with the positions of its own subject and body arguments, or as one that '
                        . 'takes the key from a queued payload'
                    );
                    if (self::INDIRECT[$enclosing][0] === 'forward') {
                        $forwarders[$enclosing] = true;
                    }
                }
            }
        }

        // Follow each forwarder to its own call sites.
        foreach (array_keys($forwarders) as $method) {
            [, $subjectpos, $bodypos] = self::INDIRECT[$method];
            foreach ($streams as $relative => $tokens) {
                foreach ($this->call_sites($tokens, null, $method) as $at) {
                    $args = $this->call_arguments($tokens, $at);
                    foreach ([$subjectpos, $bodypos] as $position) {
                        foreach ($this->keys_from_expression($args[$position] ?? '', $strings) ?? [] as $key) {
                            $keys[$key] = $relative . ':' . $tokens[$at]['line'];
                        }
                    }
                }
            }
        }

        // And the payloads that carry a key to a queued send.
        foreach ($streams as $relative => $tokens) {
            $count = count($tokens);
            for ($i = 0; $i < $count - 2; $i++) {
                if (!in_array($tokens[$i]['text'], self::PAYLOAD_KEYS, true)) {
                    continue;
                }
                if ($tokens[$i + 1]['text'] !== '=>') {
                    continue;
                }
                $expression = '';
                $depth = 0;
                for ($j = $i + 2; $j < $count; $j++) {
                    $text = $tokens[$j]['text'];
                    if ($text === '(' || $text === '[') {
                        $depth++;
                    } else if ($text === ')' || $text === ']') {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    } else if ($text === ',' && $depth === 0) {
                        break;
                    }
                    $expression .= $text;
                }
                foreach ($this->keys_from_expression($expression, $strings) ?? [] as $key) {
                    $keys[$key] = $relative . ':' . $tokens[$i]['line'];
                }
            }
        }

        return $keys;
    }

    /**
     * Every key the notifier is given lives in the msg namespace.
     *
     * The placeholder test below reads the msg namespace. That is only
     * a complete account of what the notifier renders while this holds,
     * so it is asserted rather than assumed.
     *
     * MUTATION CAUGHT (run 2026-08-14), both arms:
     * - pointing joinrequests.php:706 at 'tickethasnoteam' (a real
     *   string, outside the namespace) is reported as outside it;
     * - pointing tickets.php:275 at 'msgnosuchkeyatall' is reported as
     *   having no string in the lang file.
     * The INDIRECT map earned its keep while this was being written: it
     * refused to pass three indirections the author had not found -
     * coordinatorimport::tell()'s built key, send_nudges::execute()'s
     * key from task data, and joinrequests' ternary - each of which
     * would otherwise have gone unchecked in silence.
     */
    public function test_every_notifier_key_is_named_in_the_msg_namespace(): void {
        $keys = $this->notifier_keys();
        $strings = $this->lang_strings();

        // Vacuity guard: a scan that found no call sites must not pass.
        $this->assertGreaterThan(40, count($keys), 'no notifier::send() keys were read');

        $offenders = [];
        foreach ($keys as $key => $where) {
            if (strpos($key, 'msg') !== 0) {
                $offenders[] = $where . ' hands the notifier \'' . $key
                    . '\', which is outside the msg namespace that the placeholder check reads';
            }
            if (!array_key_exists($key, $strings)) {
                $offenders[] = $where . ' hands the notifier \'' . $key
                    . '\', which has no string in lang/en/selfselectadvanced.php';
            }
        }

        $this->assertSame([], $offenders, "\n" . implode("\n", $offenders) . "\n");
    }

    /**
     * No string sent through the notifier uses the scalar placeholder.
     *
     * A bare {$a} is legal in a string rendered by get_string() with a
     * scalar - msggreeting is one, and every refusal string is another,
     * because moodle_exception passes its $a straight through. So the
     * rule is not "never write {$a}". The rule is that a string the
     * NOTIFIER renders cannot use it, because the notifier objectifies
     * every payload before core sees it. This test therefore reads the
     * msg namespace (pinned by the test above) and exempts a key whose
     * every reference sits in a get_string() call.
     */
    public function test_no_string_the_notifier_sends_uses_the_scalar_placeholder(): void {
        $strings = $this->lang_strings();
        $sources = $this->php_sources();

        // Vacuity guards: a scan that examined nothing must not pass.
        $this->assertGreaterThan(500, count($strings), 'the lang file did not parse');
        $this->assertGreaterThan(50, count($sources), 'no PHP sources were scanned');

        $offenders = [];
        foreach ($strings as $key => [$line, $value]) {
            if (strpos($key, 'msg') !== 0 || !$this->has_scalar_placeholder($value)) {
                continue;
            }
            foreach ($sources as $relative => $contents) {
                foreach (explode("\n", $contents) as $number => $text) {
                    if (strpos($text, '\'' . $key . '\'') === false) {
                        continue;
                    }
                    // A get_string() on the same line is passing a
                    // scalar, which resolves the bare form correctly.
                    if (strpos($text, 'get_string(') !== false) {
                        continue;
                    }
                    $offenders[] = 'lang/en/selfselectadvanced.php:' . $line . ' $string[\'' . $key
                        . '\'] carries a bare {$a} and is named at ' . $relative . ':' . ($number + 1)
                        . ' outside a get_string() call, so the notifier will ship the placeholder verbatim';
                }
            }
        }

        $this->assertSame([], $offenders, "\n" . implode("\n", $offenders) . "\n");
    }

    /**
     * An activity with a firm group (leader + confirmed member, guide
     * assigned), a manager and a coordinator.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'MSG1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($member->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_module::instance((int) $instance->cmid));

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Ticketed',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $member, $guide, $manager, $coordinator];
    }

    /**
     * The messages a sink captured, indexed by recipient.
     *
     * @param \phpunit_message_sink $sink the sink
     * @return array<int, array<int, \stdClass>> userid => messages
     */
    private function by_recipient(\phpunit_message_sink $sink): array {
        $byuser = [];
        foreach ($sink->get_messages() as $message) {
            $byuser[(int) $message->useridto][] = $message;
        }
        return $byuser;
    }

    /**
     * Decision 71's notice to the leader names the group, not {$a}.
     *
     * This is the exact message the dev site delivered with an
     * unresolved placeholder in its subject line.
     *
     * MUTATION CAUGHT (run 2026-08-14): written against the unfixed
     * tree, this failed with the live text - `Leadership help requested
     * for "{$a}"` - which is what notification 106154 carried to the
     * leader of group Alpha on the dev site.
     */
    public function test_the_leader_notice_names_the_group_in_its_subject(): void {
        $this->resetAfterTest();
        [$activity, $group, $leader, $member] = $this->setup_world();

        $sink = $this->redirectMessages();
        tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        $byuser = $this->by_recipient($sink);

        $this->assertArrayHasKey((int) $leader->id, $byuser, 'decision 71: the leader is always told');
        $notice = $byuser[(int) $leader->id][0];
        $this->assertStringNotContainsString(
            '{$a',
            $notice->subject,
            'the subject shipped an unresolved placeholder'
        );
        $this->assertStringContainsString(
            'Ticketed',
            $notice->subject,
            'the subject must name the group it is about'
        );
        $this->assertStringNotContainsString(
            '{$a',
            $notice->fullmessage,
            'the body shipped an unresolved placeholder'
        );
    }

    /**
     * A requester is linked to a page they can open; staff are linked
     * to the queue.
     *
     * The queue page requires manage or coordinate. Sending its URL to a
     * student is handing them a control that throws when operated, and
     * it is why a claimed request looked to the maintainer like a
     * response that could not be viewed.
     *
     * MUTATION CAUGHT (run 2026-08-14): written against the unfixed
     * tree, this failed on the claim notification's contexturl -
     * `.../tickets.php?id=<cmid>` - matching what notification 106155
     * carried to the student who filed the dev site's only ticket.
     */
    public function test_ticket_notifications_link_each_recipient_to_a_page_they_can_open(): void {
        $this->resetAfterTest();
        [$activity, $group, , $member, , $manager, $coordinator] = $this->setup_world();
        $cmid = (int) $activity->cm()->id;

        $sink = $this->redirectMessages();
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        $filed = $this->by_recipient($sink);

        // The people who work the queue are sent to the queue.
        foreach ([(int) $manager->id, (int) $coordinator->id] as $workerid) {
            $this->assertArrayHasKey($workerid, $filed, 'the queue workers are told of new work');
            $this->assertStringContainsString(
                '/mod/selfselectadvanced/tickets.php?id=' . $cmid,
                $filed[$workerid][0]->contexturl,
                'a worker is linked to the queue they can open'
            );
        }

        // The requester is not, because the queue refuses them.
        $sink->clear();
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $claimed = $this->by_recipient($sink);

        $this->assertArrayHasKey((int) $member->id, $claimed, 'the requester is told their request was claimed');
        $told = $claimed[(int) $member->id][0];
        $this->assertStringNotContainsString(
            '/mod/selfselectadvanced/tickets.php',
            $told->contexturl,
            'the requester was linked to a page that requires manage or coordinate'
        );
        $this->assertStringContainsString(
            '/mod/selfselectadvanced/myrequests.php?id=' . $cmid,
            $told->contexturl,
            'the requester is linked to their own requests, which no capability gates'
        );

        // And the same holds for the message that carries the outcome.
        $sink->clear();
        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Spoken to, all settled',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $closed = $this->by_recipient($sink);

        $this->assertArrayHasKey((int) $member->id, $closed, 'the requester is told the outcome');
        $outcome = $closed[(int) $member->id][0];
        $this->assertStringNotContainsString(
            '/mod/selfselectadvanced/tickets.php',
            $outcome->contexturl,
            'the closing note linked the requester to a page that refuses them'
        );
        $this->assertStringContainsString(
            'Spoken to, all settled',
            $outcome->fullmessage,
            'the resolution travels in the body - that is the whole design'
        );
    }
}
