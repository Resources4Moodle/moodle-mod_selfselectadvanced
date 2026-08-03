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
 * HOW FAR THE :manage EXEMPTION REACHES - pinned, so the answer stops
 * having to be re-derived by hand every wave (audit D7-a).
 *
 * contactprivacy::is_unrestricted() is the plugin's one "this viewer is
 * outside the contact-privacy switch" predicate. Waves 3A to 3D spent
 * most of their effort on the same question - WHICH surfaces still ask
 * it - and each answered it by reading the tree again. The answer today
 * is TWO CALL SITES, both inside contactprivacy.php itself:
 *
 *  - can_see_map(), the connection map, where it governs the PHONE;
 *  - mobile_consent_bypass(), where it is AND-ed onto the identity
 *    capability before the owner's own consent flag may be overruled.
 *
 * NOTHING ELSE ASKS IT. candidates::search(),
 * external\search_participants, eoilist.php and
 * coordinatorcandidates_table each used to, and each stopped when
 * maintainer decision 24 removed the address from every surface for
 * everybody; db/access.php, candidates.php, search_participants.php and
 * coordinatorcandidates_table.php still MENTION the predicate, in
 * comments, explaining why they no longer call it - which is precisely
 * why this file strips comments by token before it looks.
 *
 * THIS TEST TAKES NO POSITION ON WHETHER THE EXEMPTION SHOULD EXIST.
 * That argument is recorded on can_see_map() itself, together with the
 * three things that say it is the specification (lang/en's
 * shareconsentgranted, which promises the number's OWNER that sharing
 * reaches "the teachers who manage this activity";
 * tests/behat/attributes_admin.feature, which drives a real roster and
 * asserts an editing teacher reading a CONSENTED number and not reading
 * an unconsented one; and the cardinal rule's own list of audiences,
 * which does not include the editing teacher who owns the switch) and
 * with the open question about the MANAGER archetype, which shares
 * :manage with the editing teacher and which the cardinal rule DOES
 * name. What this file guarantees is narrower and is the part that can
 * be guaranteed without a maintainer ruling: whatever the exemption is
 * worth, it cannot quietly grow a third consumer.
 *
 * The other direction - that a confirmed teammate, an assigned guide
 * and a claiming coordinator still read a consenting subject's number
 * on the surfaces they actually use - is proved by
 * contactreach_test::test_the_three_connections_still_reach_a_consenting_number(),
 * which drives the group-page roster, the review page and the ticket
 * queue. It is not duplicated here; it is named here so a reader of
 * this file knows where the balancing proof lives.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\contactprivacy
 */
final class manage_exemption_scope_test extends \advanced_testcase {
    /**
     * No shipped file outside contactprivacy.php calls the predicate.
     *
     * The walk is over the whole plugin, tests/ and docs/ excluded, so a
     * new page or service that reaches for the exemption fails here on
     * the day it is written rather than in the next audit.
     */
    public function test_only_contactprivacy_asks_the_predicate(): void {
        $root = realpath(__DIR__ . '/..');
        $owner = $root . '/classes/local/contactprivacy.php';

        $examined = 0;
        $callers = [];
        foreach ($this->plugin_php_files($root) as $path) {
            $examined++;
            if ($path === $owner) {
                continue;
            }
            if (str_contains(self::executable_source($path), 'is_unrestricted')) {
                $callers[] = substr($path, strlen($root) + 1);
            }
        }

        // A check that examined nothing is worse than one that failed.
        $this->assertGreaterThan(
            100,
            $examined,
            'the file walk found almost nothing, so the assertion below is vacuous'
        );
        $this->assertSame(
            [],
            $callers,
            'a new surface asks contactprivacy::is_unrestricted(); read the note on can_see_map() before adding a third'
        );
    }

    /**
     * Inside its own class the predicate is asked exactly twice, by the
     * two methods the docblocks name.
     *
     * Counted on the comment-free source: contactprivacy.php's own
     * paragraphs discuss the predicate at length, and a raw count would
     * be measuring the prose.
     */
    public function test_the_predicate_has_exactly_two_call_sites(): void {
        $source = self::executable_source(__DIR__ . '/../classes/local/contactprivacy.php');

        $this->assertSame(
            2,
            substr_count($source, 'self::is_unrestricted('),
            'the number of call sites inside contactprivacy changed; can_see_map() and mobile_consent_bypass() '
                . 'are the two the class documents, and a third needs the maintainer ruling recorded there'
        );
        $this->assertTrue(
            str_contains($source, 'if (!self::enabled($activity) || self::is_unrestricted($activity, $viewerid)) {'),
            'can_see_map() no longer short-circuits on the switch and the exemption in one place'
        );
        $this->assertTrue(
            str_contains(
                $source,
                'return $hasidentitycap && (!self::enabled($activity) || self::is_unrestricted($activity, $viewerid));'
            ),
            'mobile_consent_bypass() must keep AND-ing the identity capability onto the exemption, never OR-ing it'
        );
    }

    /**
     * Every .php file the plugin ships, excluding tests and dev tools.
     *
     * @param string $root the plugin root
     * @return string[] absolute paths
     */
    private function plugin_php_files(string $root): array {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $item): bool {
                    $name = $item->getFilename();
                    if ($item->isDir()) {
                        return !in_array($name, ['tests', 'docs', '.git', '.github', 'node_modules'], true);
                    }

                    return str_ends_with($name, '.php');
                }
            )
        );

        $paths = [];
        foreach ($iterator as $file) {
            $paths[] = $file->getPathname();
        }
        sort($paths);

        return $paths;
    }

    /**
     * A PHP file's EXECUTABLE source, comments removed by token and
     * whitespace collapsed to single spaces.
     *
     * @param string $path absolute path to the file
     * @return string the code, comment-free and whitespace-collapsed
     */
    private static function executable_source(string $path): string {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new \coding_exception('unreadable: ' . $path);
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return preg_replace('/\s+/', ' ', $code);
    }
}
