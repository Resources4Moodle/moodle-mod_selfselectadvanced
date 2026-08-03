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

use mod_selfselectadvanced\local\rules\settings_validator;

/**
 * The how-to seeder must build an activity the mod form would accept.
 *
 * WHY THIS EXISTS (1.20.1 wave 3F). docs/tools/seed_howto.php calls
 * add_moduleinfo() directly, which bypasses mod_form and therefore
 * bypasses settings_validator. It asked for the whole guide-advertises
 * world - guidevolunteer=1, eoienabled=1, guidemode=0 - but never
 * mentioned studentapproach, whose DB column DEFAULT IS 1
 * (db/install.xml). So the activity it built was the one combination
 * the validator rejects outright, and the run died at the first
 * volunteering::set() with 'refusalstudentapproach'. The five named
 * demonstration groups were never created, capture_howto.php cli_errors
 * when it cannot resolve them by name, and the deck that needs 21
 * specific frames could not be built. One unpassed key, three tools
 * down.
 *
 * The check that catches that is NOT "validate() returns no errors".
 * An OMITTED studentapproach is falsy to validate() and passes, which
 * is exactly how the defect hid: silence is not zero when the column
 * defaults to one. So the key's PRESENCE is asserted separately from
 * its value.
 *
 * COMMENTS ARE STRIPPED FIRST. seed_howto.php now carries a paragraph
 * that explains this rule and quotes the setting while doing so, so a
 * search over raw text could not tell the explanation from the payload
 * - and a commented-out key is precisely the edit that would
 * reintroduce the defect.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class seedhowto_test extends \basic_testcase {
    /** @var string The seeder, relative to this directory. */
    private const TOOL = '/../docs/tools/seed_howto.php';

    /**
     * The seeded activity's settings pass the plugin's own validator,
     * and the two keys whose absence broke the deliverable are present.
     */
    public function test_the_seeded_activity_is_one_the_form_would_accept(): void {
        $payload = self::seeder_payload();

        // POSITIVE CONTROL. The extractor reads a literal array out of a
        // CLI script; if it ever returned an empty or partial map, the
        // validator would be handed nothing and several assertions below
        // would pass for the wrong reason. Name the keys it must have
        // found, so a broken extractor fails here and says so.
        foreach (
            [
                'minsize', 'maxsize', 'maxlead', 'maxmembership', 'maxguided',
                'eoienabled', 'guidevolunteer', 'guidemode', 'grade',
            ] as $expected
        ) {
            $this->assertArrayHasKey(
                $expected,
                $payload,
                "the payload extractor did not find '$expected' - it is reading the wrong thing"
            );
        }

        // The activity the seeder builds is one mod_form would accept.
        $this->assertSame(
            [],
            settings_validator::validate($payload),
            'seed_howto.php builds an activity the plugin\'s own settings validator rejects, so the tool '
                . 'dies part-way and the how-to course never reaches its five named groups'
        );

        // PRESENCE, then value. Absent is not the same as zero here: the
        // studentapproach column defaults to 1, so a payload that simply
        // does not mention it produces students-approach mode, which
        // refuses volunteering::set() and eoi::express() - and validate()
        // above cannot see that, because to it an absent key is falsy.
        $this->assertArrayHasKey(
            'studentapproach',
            $payload,
            'seed_howto.php must pass studentapproach EXPLICITLY: the column defaults to 1, and the deck '
                . 'it seeds shows guides volunteering and expressing interest, which that mode refuses'
        );
        $this->assertSame(
            0,
            $payload['studentapproach'],
            'the how-to activity must not be in students-approach mode: volunteering::set() and '
                . 'eoi::express() both throw refusalstudentapproach there'
        );

        // Core's add_moduleinfo() -> edit_module_post_actions() reads
        // $moduleinfo->cmidnumber unconditionally once the module has a
        // grade item, so omitting it emits "Undefined property:
        // stdClass::$cmidnumber" from course/modlib.php on every run.
        $this->assertArrayHasKey(
            'cmidnumber',
            $payload,
            'seed_howto.php must supply cmidnumber, as a real form submission does, or course/modlib.php '
                . 'warns on every run'
        );
    }

    /**
     * The literal settings the seeder hands to add_moduleinfo().
     *
     * Comments are removed by token before anything is matched, then
     * the payload array is isolated and every scalar-literal pair read
     * out of it. Keys whose value is an expression rather than a
     * literal - the course and module ids, the intro text, the windows
     * written as `3 * DAYSECS` - are deliberately skipped: the
     * validator does not consult them, and guessing at them would make
     * this helper the thing most likely to be wrong.
     *
     * @return array<string, int|string> setting => literal value
     */
    private static function seeder_payload(): array {
        $path = __DIR__ . self::TOOL;
        if (!file_exists($path)) {
            // The docs/ directory is excluded from the release zip;
            // there is nothing to guard when the tool is not shipped.
            // Skipped, never silently passed.
            self::markTestSkipped('seed_howto.php is not present in this tree');
        }

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

        $start = strpos($code, 'add_moduleinfo((object) [');
        $end = $start === false ? false : strpos($code, '], $course);', $start);
        if ($start === false || $end === false) {
            throw new \coding_exception('the add_moduleinfo payload could not be located in ' . $path);
        }
        $literal = substr($code, $start, $end - $start);

        $payload = [];
        preg_match_all("/'([a-z]+)'\s*=>\s*(-?\d+|'')\s*,/", $literal, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $payload[$match[1]] = $match[2] === "''" ? '' : (int) $match[2];
        }

        return $payload;
    }
}
