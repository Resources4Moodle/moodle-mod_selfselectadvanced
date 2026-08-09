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

use mod_selfselectadvanced\local\nudgeplan;

/**
 * The plugin must not tell a user something its own code contradicts.
 *
 * The 2026-08-09 silent-state audit found 25 verified places where a screen
 * stated, promised or collected something the code did not honour. Thirteen
 * carried no design content and were corrected in 1.20.28; this file is what
 * stops them coming back, and - for the two structural cases - stops their
 * whole class coming back.
 *
 * A note on the string assertions. Pinning wording is normally brittle and
 * worth avoiding, but these are not style pins: each one names a specific
 * FALSE CLAIM that was shipped, and is paired wherever possible with the
 * behaviour that made it false. If the behaviour is deliberately changed one
 * day, the paired assertion fails too and whoever changes it is sent to the
 * sentence that needs rewriting - which is the outcome this file exists for.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\nudgeplan
 */
final class claim_honesty_test extends \advanced_testcase {
    /**
     * Plugin root.
     *
     * @return string absolute path
     */
    private static function root(): string {
        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            throw new \coding_exception('cannot resolve plugin root');
        }

        return $root;
    }

    /**
     * Read a plugin file.
     *
     * @param string $relative path below the plugin root
     * @return string contents
     */
    private static function slurp(string $relative): string {
        $text = file_get_contents(self::root() . '/' . $relative);
        if ($text === false) {
            throw new \coding_exception('unreadable: ' . $relative);
        }

        return $text;
    }

    /**
     * STRUCTURAL GUARD. Every field the attribute form collects must reach the writer.
     *
     * The defect: attredit_form.php grew seatlocation and program controls, the
     * manager::set() call in attributes.php was never widened, and manager::set
     * writes a field only when its key is present. So both values were typed in,
     * discarded, and "Participant attributes saved." was shown anyway.
     *
     * This does not test the two fields. It tests the RULE, so the next field
     * added to the form cannot be silently dropped the same way.
     *
     * MUTATION CAUGHT (run 2026-08-09): removing 'program' from the set() call
     * in attributes.php fails this test naming program; removing 'seatlocation'
     * fails naming seatlocation.
     */
    public function test_every_attribute_form_field_reaches_the_writer(): void {
        $form = self::slurp('classes/form/attredit_form.php');
        $page = self::slurp('attributes.php');
        $manager = self::slurp('classes/local/attributes/manager.php');

        // The canonical attribute set comes from manager::set()'s own write
        // loop, not from a list maintained here. Deriving it that way is what
        // keeps the form's hidden routing fields (action, u, targetuser) out
        // without an exclusion list that would silently swallow a real
        // attribute somebody adds later.
        $this->assertSame(
            1,
            preg_match('/foreach \(\[([^\]]*)\] as \$field\)/', $manager, $fieldlist),
            'could not find the canonical attribute list in manager::set()'
        );
        preg_match_all("/'([a-z]+)'/", $fieldlist[1], $canonical);
        $attributes = $canonical[1];

        // Fields the form declares a type for: that is the set it will hand back.
        preg_match_all("/setType\(\s*'([a-z]+)'/", $form, $matches);
        $collected = array_values(array_intersect(array_unique($matches[1]), $attributes));
        sort($collected);

        $this->assertNotEmpty($attributes, 'the canonical list scan found nothing and is therefore vacuous');
        $this->assertNotEmpty($collected, 'the form scan found no attribute fields and is therefore vacuous');
        $this->assertContains('mobile', $collected, 'non-vacuity pin: the form has always collected mobile');

        // Keys handed to manager::set() in the save path.
        $this->assertSame(
            1,
            preg_match('/manager::set\(\$target,\s*\[(.*?)\]/s', $page, $call),
            'could not find the manager::set() call in attributes.php'
        );
        preg_match_all("/'([a-z]+)'\s*=>/", $call[1], $passed);
        $written = array_values(array_unique($passed[1]));
        sort($written);

        $dropped = array_values(array_diff($collected, $written));
        $this->assertSame(
            [],
            $dropped,
            'attributes.php collects these fields and never passes them to manager::set(), '
                . 'so they are discarded while the page reports success: ' . implode(', ', $dropped)
        );
    }

    /**
     * The nudge notice must count what was queued, not what was listed.
     *
     * MUTATION CAUGHT (run 2026-08-09): making nudgeplan::bucket() count the
     * skipped recipients into queued fails both the queued and skipped asserts.
     */
    public function test_nudge_plan_counts_only_what_it_queues(): void {
        $this->resetAfterTest();

        // A resolver stub: three users with a deadline, two without.
        $resolver = new class {
            /**
             * Effective dates for one user.
             *
             * @param int $userid user id
             * @return object with a timedue property
             */
            public function effective_dates(int $userid): object {
                $due = [11 => 1700000000, 12 => 1700000000, 13 => 1800000000, 14 => 0, 15 => 0];

                return (object) ['timedue' => $due[$userid] ?? 0];
            }
        };

        $plan = nudgeplan::bucket([11, 12, 13, 14, 15], $resolver);

        $this->assertSame(3, $plan->queued, 'only the three with a deadline can be reminded');
        $this->assertSame(2, $plan->skipped, 'the two without a deadline must be reported, not absorbed');
        $this->assertSame(
            5,
            $plan->queued + $plan->skipped,
            'every recipient must be accounted for in exactly one half'
        );
        // Bucketed by distinct due date: two share one, the third has its own.
        $this->assertCount(2, $plan->buckets);
        $this->assertEqualsCanonicalizing([11, 12], $plan->buckets[1700000000]);
        $this->assertSame([13], $plan->buckets[1800000000]);
    }

    /**
     * An activity where nobody has a deadline queues nothing and says so.
     */
    public function test_nudge_plan_with_no_deadlines_queues_nothing(): void {
        $resolver = new class {
            /**
             * Effective dates for one user.
             *
             * @param int $userid user id
             * @return object with a timedue property
             */
            public function effective_dates(int $userid): object {
                return (object) ['timedue' => 0];
            }
        };

        $plan = nudgeplan::bucket([21, 22, 23], $resolver);

        $this->assertSame(0, $plan->queued);
        $this->assertSame(3, $plan->skipped);
        $this->assertSame([], $plan->buckets);
    }

    /**
     * Retired false claims must not return, and the behaviour that falsified them still holds.
     *
     * Each row is [string key, phrase that must be ABSENT, why it was false].
     * The paired behavioural checks live in the tests below this one.
     *
     * MUTATION CAUGHT (run 2026-08-09): restoring any one original sentence in
     * lang/en/selfselectadvanced.php fails exactly its own row.
     */
    public function test_retired_false_claims_do_not_return(): void {
        $this->resetAfterTest();

        $cases = [
            // The approveconfirm string called approval irreversible; decision 62 added a
            // coordinator/manager return-to-forming that clears timeapproved.
            ['approveconfirm', 'irreversible'],
            // The freezeconfirm string said only a manager can unfreeze; the guide who
            // froze the group can release it themselves.
            ['freezeconfirm', 'Only a manager can unfreeze'],
            // The guideautoapprove_help string claimed drift never stops auto-approval.
            ['guideautoapprove_help', 'literally and unconditionally'],
            ['guideautoapprove_help', 'does NOT stop the automatic approval'],
            // The returned-group message reaches the leader alone.
            ['tplmsgreturnedbody', '(to the members)'],
            // The coordinate capability does not carry unfreeze on its own.
            ['selfselectadvanced:coordinate', 'freeze and unfreeze them'],
        ];

        foreach ($cases as [$key, $phrase]) {
            $value = get_string($key, 'mod_selfselectadvanced');
            $this->assertStringNotContainsString(
                $phrase,
                $value,
                $key . ' has regained a claim the code contradicts: "' . $phrase . '"'
            );
        }
    }

    /**
     * Claims that must now be PRESENT, because their absence was the defect.
     */
    public function test_previously_missing_disclosures_are_stated(): void {
        $this->resetAfterTest();

        // The csvformathelp string presented a closed column list omitting three columns
        // the importer reads - one of which overwrites the student's consent.
        $csv = get_string('csvformathelp', 'mod_selfselectadvanced');
        // Assert against the COLUMN LIST sentence, not the whole string. The
        // first version of this test searched the whole value, and the mutation
        // sweep caught it: trimming shareconsent from the list still passed,
        // because the sentence warning about shareconsent also contains the
        // word. A test that a nearby sentence can satisfy is not testing the
        // list at all.
        $this->assertSame(
            1,
            preg_match('/Expected columns:(.*?)An optional email/s', $csv, $listmatch),
            'csvformathelp no longer opens with its column list; this test needs rewriting'
        );
        foreach (['seatlocation', 'program', 'shareconsent'] as $column) {
            $this->assertStringContainsString(
                $column,
                $listmatch[1],
                'the csvformathelp column list still omits ' . $column . ', which csv_importer reads'
            );
        }
        $this->assertMatchesRegularExpression(
            '/overwrite/i',
            $csv,
            'csvformathelp must say that a shareconsent cell overwrites the participant\'s own choice'
        );

        // The defaulter strings promised a per-missing-membership charge that
        // never reaches a student holding no confirmed membership at all.
        foreach (['defaulterpenalty_help', 'defaultersintro', 'minmembership_help'] as $key) {
            $this->assertMatchesRegularExpression(
                '/no group at all|joined nothing at all/i',
                get_string($key, 'mod_selfselectadvanced'),
                $key . ' must state that a student in no group has no grade to charge'
            );
        }

        // The three strings added in 1.20.28 for states that used to be silent.
        foreach (['rosternomatch', 'leavependingown', 'nudgedefaultersnodeadline'] as $key) {
            $this->assertNotSame(
                '',
                trim(get_string($key, 'mod_selfselectadvanced', (object) ['needle' => 'x', 'total' => 1])),
                $key . ' must carry text: it exists to replace an unexplained blank'
            );
        }
    }

    /**
     * contactchoose states the capacity rule the guide list silently applies, so it must be rendered.
     *
     * It lost its only echo in 7d35a40 and stayed defined-but-dead, while a
     * comment in contact.php asserted "the rule is stated in the intro".
     *
     * MUTATION CAUGHT (run 2026-08-09): removing the get_string('contactchoose')
     * call from contact.php fails this test.
     */
    public function test_the_guide_capacity_rule_is_actually_rendered(): void {
        $contact = self::slurp('contact.php');
        $this->assertStringContainsString(
            "get_string('contactchoose', 'mod_selfselectadvanced')",
            $contact,
            'contact.php filters full guides out of the list; the string that explains that '
                . 'filter must be rendered, not merely defined'
        );
    }

    /**
     * The roster filter form is gated on having members, not on the filter having matched.
     *
     * MUTATION CAUGHT (run 2026-08-09): changing hasanyroster back to hasroster
     * in templates/group_page.mustache fails this test.
     */
    public function test_roster_filter_survives_a_filter_that_matches_nobody(): void {
        $template = self::slurp('templates/group_page.mustache');

        $this->assertSame(
            1,
            preg_match('/\{\{#hasanyroster\}\}(.*?)\{\{\/hasanyroster\}\}/s', $template, $section),
            'the roster filter form must sit in a hasanyroster section'
        );
        $this->assertStringContainsString(
            'name="rq"',
            $section[1],
            'the filter input must be inside hasanyroster, so a filter matching nobody '
                . 'cannot remove the box that has to be cleared'
        );
        $this->assertStringContainsString(
            '{{#rosternomatch}}',
            $template,
            'a filter matching nobody must say so rather than render blank space'
        );

        // And the exporter must distinguish the two, or the template cannot.
        $exporter = self::slurp('classes/output/group_page.php');
        $this->assertStringContainsString("'hasanyroster' =>", $exporter);
        $this->assertStringContainsString("'rosternomatch' =>", $exporter);
        $this->assertStringContainsString(
            '$rostertotal = count($roster);',
            $exporter,
            'the pre-filter count must be taken BEFORE the filter runs, or hasanyroster '
                . 'is just hasroster under another name'
        );
    }

    /**
     * The leave control asks the gate instead of restating it, and a pending request is stated.
     *
     * MUTATION CAUGHT (run 2026-08-09): restoring the transcribed condition list
     * in group_page.php fails this test.
     */
    public function test_leave_control_defers_to_the_gatekeeper(): void {
        $exporter = self::slurp('classes/output/group_page.php');

        $this->assertStringContainsString(
            'can_request_leave($this->group, $ownrow, $this->userid)',
            $exporter,
            'the page must ASK gatekeeper::can_request_leave() rather than transcribe its conditions'
        );
        $this->assertStringContainsString(
            "'leavependingnotice' =>",
            $exporter,
            'a member who has already asked to leave must be told so, not merely lose the button'
        );
        // The transcription that used to stand in for the gate.
        $this->assertStringNotContainsString(
            '$canrequestleave = $isforming' . "\n" . '            && !$isleader',
            $exporter,
            'the hand-copied condition list has returned; it drifts from the service it copies'
        );
    }
}
