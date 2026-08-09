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
 * The privacy contracts settled by maintainer decisions 84, 91, 92 and 93.
 *
 * Three promises, each of which the plugin previously broke in a different
 * direction: it exported staff notes it had promised students would never see,
 * it let a spreadsheet overwrite a consent it called the student's own
 * (decision 85, pinned in attributes_test), and it kept its strongest
 * protection - no email address reaches anybody - entirely off screen.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\privacy\provider
 */
final class privacy_contracts_test extends \advanced_testcase {
    /**
     * Guide notes never travel in a student's automatic export (decision 84).
     *
     * The field hangs off the GROUP, so no membership filter can tell which
     * sentence is about the requester and which is about a teammate. Exporting
     * it hands one student the guide's prose about everybody else. This is a
     * source-level pin AND a metadata pin, deliberately: the export path is a
     * long DB-backed walk, and what actually matters is that the column stops
     * being selected and stops being written into the payload.
     *
     * MUTATION CAUGHT (run 2026-08-09): restoring the 'guidenotes' key in the
     * membership loop fails the payload assertion; restoring g.guidenotes to
     * the SELECT fails the fetch assertion.
     */
    public function test_guide_notes_are_not_exported_to_students(): void {
        $root = realpath(__DIR__ . '/..');
        $provider = file_get_contents($root . '/classes/privacy/provider.php');
        $this->assertNotFalse($provider);

        $this->assertStringNotContainsString(
            "'guidenotes' => \$row->guidenotes",
            $provider,
            'guide notes are back in the subject-access payload; the interface promises students never see them'
        );
        $this->assertStringNotContainsString(
            'g.guidenotes',
            $provider,
            'a column that is never selected cannot be leaked by a later edit - keep it out of the query too'
        );

        // The DECLARATION must stay. Metadata says what the plugin STORES, and
        // it does store these notes; deleting the declaration to match the
        // export would be the same dishonesty pointing the other way.
        $this->assertStringContainsString(
            "'guidenotes' => 'privacy:metadata:group:guidenotes'",
            $provider,
            'the plugin still stores guide notes and must still declare that it does'
        );

        // And deletion must still reach them.
        $this->assertStringContainsString(
            "set_field('selfselectadvanced_group', 'guidenotes', null",
            $provider,
            'notes must still be purged when a context is deleted'
        );
    }

    /**
     * Every student sees a privacy statement, whether or not they have a number.
     *
     * This is the defect the maintainer found from two screenshots: the panel
     * rendered only for a viewer who happened to hold a mobile, so the
     * better-protected student was told LESS than the exposed one and the
     * silence was indistinguishable from a broken feature.
     *
     * MUTATION CAUGHT (run 2026-08-09): wrapping the panel back inside
     * {{#showconsent}} fails the unconditional assertion below.
     */
    public function test_the_privacy_statement_is_unconditional(): void {
        $root = realpath(__DIR__ . '/..');
        $template = file_get_contents($root . '/templates/landing.mustache');
        $this->assertNotFalse($template);

        $this->assertSame(
            1,
            preg_match('/<div class="selfselectadvanced-privacy(.*?)<\/div>\s*\{\{#isstudent\}\}/s', $template, $panel),
            'the privacy panel must sit at the top level of the landing template'
        );
        // The consent control is INSIDE the panel; the panel is not inside it.
        $this->assertStringContainsString('{{#showconsent}}', $panel[1], 'the share control nests inside the panel');
        $this->assertStringContainsString('{{privacystatement}}', $panel[1]);

        // Nothing may gate the panel itself.
        $before = substr($template, 0, strpos($template, '<div class="selfselectadvanced-privacy'));
        $opensections = preg_match_all('/\{\{#(\w+)\}\}/', $before, $opens);
        $closesections = preg_match_all('/\{\{\/(\w+)\}\}/', $before, $closes);
        $this->assertSame(
            $opensections,
            $closesections,
            'the privacy panel is inside an unclosed template section, so it is conditional after all'
        );
    }

    /**
     * The statement follows the CURRENT setting and never promises anything permanent.
     *
     * The cardinal rule requires the student to be INFORMED rather than
     * promised, because an editing teacher can switch contact privacy off. Two
     * different sentences exist for exactly that reason.
     */
    public function test_the_statement_describes_the_setting_not_a_permanent_promise(): void {
        $this->resetAfterTest();

        $on = get_string('privacypanelon', 'mod_selfselectadvanced');
        $off = get_string('privacypaneloff', 'mod_selfselectadvanced');

        $this->assertNotSame($on, $off, 'the two modes must not read identically');
        $this->assertMatchesRegularExpression('/\bON\b|is on\b/i', $on);
        $this->assertMatchesRegularExpression('/\bOFF\b|is off\b/i', $off);

        // Decision 93: the email guarantee had no presence on any screen. It
        // must appear in the protecting mode.
        $this->assertMatchesRegularExpression(
            '/email address is not shown/i',
            $on,
            'the strongest protection in the feature must actually be stated somewhere'
        );
        // And the OFF wording must not repeat that guarantee, because it does
        // not hold in that mode.
        $this->assertDoesNotMatchRegularExpression(
            '/email address is not shown/i',
            $off,
            'the off-mode wording must not carry the on-mode guarantee'
        );

        // Decision 92: the student is told where the number itself is corrected.
        $note = get_string('privacypanelnumber', 'mod_selfselectadvanced');
        $this->assertMatchesRegularExpression('/profile|institution/i', $note);
    }
}
