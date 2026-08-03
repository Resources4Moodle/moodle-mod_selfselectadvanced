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

use mod_selfselectadvanced\local\attributes\manager;

/**
 * THE BULK-EXPORT PINS, ASSERTED ON EXECUTABLE SOURCE ONLY.
 *
 * The cardinal rule forbids BULK extraction of contact details without
 * qualification, and flagged.php's CSV is the plugin's largest bulk
 * surface: one download, the whole enrolled cohort, a file that outlives
 * the session and forwards in one click. Wave 3D made that download take
 * a literal false - no number, ever, for anybody, however well connected
 * and however freely consented - while leaving the SCREEN on the
 * connection-plus-consent verdict, which is the specification and must
 * survive every hardening pass. The two call sites deliberately
 * DISAGREE, and a disagreement is exactly the kind of thing a later
 * reader "tidies up".
 *
 * WHY THIS FILE EXISTS AT ALL. That control had exactly one pin,
 * contactprivacy_test's 9c, and it is a presence search over the page's
 * RAW text with comments intact. A presence search FAILS OPEN ON A
 * COMMENT: the edit a developer actually makes is to comment the old
 * call out and write the new one under it, and after that edit the
 * pinned fragment is still in the file - in a comment - while the CSV
 * carries numbers again. Measured 2026-08-03 (mutation M18): with the
 * literal-false call wrapped in a block comment and a $showmobile call
 * written under it, 9c reported "Tests: 1 ... OK" on m5pg AND m5my,
 * while the first test below failed on both.
 *
 * The block comment is not a quibble about which comment syntax is
 * "realistic". That call spans four lines, and 9c collapses whitespace
 * before searching, so commenting each line with a double slash happens
 * to break its fragment where wrapping the four lines in a C-style
 * block comment does not. Surviving one comment style by accident is
 * not a property worth relying on, and nothing tells the next editor
 * which style the guard rail can see. Stripping by token removes the
 * question.
 *
 * Every assertion below is made against source with the comments
 * removed by token_get_all(), the idiom
 * contactreach_test::code_without_comments() and staffmessage_test's
 * 10d already use. The page's own comments quote both call sites while
 * explaining why they differ, which is precisely what made the raw
 * search unable to tell an explanation from a rule.
 *
 * The BEHAVIOUR of the flag is asserted too, so that the source pins
 * cannot go on passing over a plain_line() whose second argument
 * stopped meaning "include the number".
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\attributes\manager
 */
final class exportpins_test extends \advanced_testcase {
    /** @var string The number no export may ever carry. */
    private const NUMBER = '919800000555';

    /**
     * The flagged-students CSV cell passes a literal false, and NO
     * plain_line() call on that page passes anything else.
     *
     * Two assertions, because they fail on different edits. The first
     * catches the literal being replaced; the second catches a SECOND
     * export cell being added beside it, which the first would never
     * see. Both read comment-free source, so neither can be satisfied
     * by the paragraph in flagged.php that explains the rule.
     */
    public function test_the_flagged_export_cell_takes_a_literal_false(): void {
        $code = $this->normalised_code(__DIR__ . '/../flagged.php');

        $this->assertStringContainsString(
            'manager::plain_line( $attrs[(int) $user->id] ?? null, false )',
            $code,
            'the flagged-students CSV must take the literal false: a page of individually-permitted '
                . 'rows is still a bulk download once it is a file'
        );

        // Every plain_line() call on the page, not just the pinned one.
        // Counted over comment-free source: a call written inside a
        // comment is not a call, and a call added BESIDE the pinned one
        // is exactly the drift a single-fragment pin cannot see. With
        // this count fixed at one, the assertion above is a statement
        // about the only export cell there is.
        $this->assertSame(
            1,
            preg_match_all('/plain_line\(/', $code),
            'flagged.php gained or lost a plain_line() call; the assertion above no longer '
                . 'describes the whole export path - pin the new one too'
        );
    }

    /**
     * THE COUNTERWEIGHT: the SCREEN keeps the connection verdict.
     *
     * The cheapest way to pass a privacy audit is to disclose nothing to
     * anybody, and it would make the plugin useless. Connection plus
     * consent - a confirmed teammate, the assigned guide, the
     * coordinator holding the claim - IS the specification, and the
     * flagged report's on-screen line is one of the surfaces that keeps
     * it. This test exists so that a later pass which hardens the CSV by
     * hardening everything cannot land quietly: display_line() must
     * still take the computed flag, and that flag must still be the
     * connection map AND the owner's own consent.
     */
    public function test_the_flagged_screen_still_asks_connection_and_consent(): void {
        $code = $this->normalised_code(__DIR__ . '/../flagged.php');

        $this->assertStringContainsString(
            'manager::display_line( $attrs[(int) $user->id] ?? null, $showmobile )',
            $code,
            'the flagged-students SCREEN must keep the per-row verdict: hardening the bulk path is '
                . 'not a licence to delete the disclosure the specification requires'
        );
        $this->assertStringContainsString(
            '$showmobile = !empty($privacymap[(int) $user->id]) '
                . '&& \\mod_selfselectadvanced\\local\\attributes\\manager::mobile_visible( '
                . '$attrs[(int) $user->id] ?? null, $mobilebypass )',
            $code,
            'and that flag must be the connection map AND the owner\'s own consent'
        );
    }

    /**
     * The literal means what the pins above assume it means.
     *
     * A source pin is a statement about an argument, and an argument is
     * only worth pinning while the callee still honours it. Both arms
     * are driven explicitly - false drops the number, true keeps it -
     * so that a plain_line() rewritten to ignore its flag, or to invert
     * it, cannot leave two green source checks standing over an export
     * that carries numbers again.
     */
    public function test_plain_line_honours_its_flag_in_both_directions(): void {
        $record = (object) [
            'gender' => 'F',
            'department' => 'Physics',
            'subdepartment' => 'Optics',
            'program' => 'MSc',
            'mobile' => self::NUMBER,
            'shareconsent' => 1,
        ];

        $this->assertStringNotContainsString(
            self::NUMBER,
            manager::plain_line($record, false),
            'the export flag is false and the number is still in the line'
        );
        $this->assertStringContainsString(
            self::NUMBER,
            manager::plain_line($record, true),
            'the flag no longer controls anything, so pinning the literal false pins nothing'
        );
    }

    /**
     * A PHP file's executable source, comments removed and whitespace
     * collapsed to single spaces.
     *
     * Comments go first, by token, because a guard rail a comment can
     * satisfy is not a guard rail - this plugin's page scripts quote the
     * exact lines these checks look for while explaining why they are
     * there. Whitespace is collapsed afterwards so that the fragments
     * above survive a reformat of the call they describe.
     *
     * @param string $path absolute path to the file
     * @return string the source, comment-free and whitespace-collapsed
     */
    private function normalised_code(string $path): string {
        $source = file_get_contents($path);
        $this->assertIsString($source, 'unreadable: ' . $path);

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
