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

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;

/**
 * WHO MAY BE INSTALLED AS A TEAM'S GUIDE, ASKED WHERE THE ROW IS
 * WRITTEN (audit C5).
 *
 * There are four seams that hand a team to a guide: state::submit() in
 * leader-selects mode, state::assign_guide(), handover::propose() and
 * handover::accept(), and contacts::respond(). Every one of them asks
 * rules\gatekeeper::can_take_guide(), whose first line is
 * has_capability('mod/selfselectadvanced:guide', ..., $guideid).
 *
 * The expression-of-interest flow was the fifth, and it asked
 * eoi::remaining_capacity() instead - an INTEGER. An integer answers
 * "how many more teams could this person hold". It has never answered
 * "may this person hold a team at all", and no arithmetic ever will.
 *
 * WHAT THAT COST, and why this is an availability defect rather than a
 * privilege escalation: an administrator's CAP_PROHIBIT on :guide was
 * honoured everywhere downstream - the leader could not submit to them,
 * the guide could not approve, the picker never offered them, stepout
 * was unreachable - but the EOI accept wrote their id into
 * selfselectadvanced_group.guideid anyway. The result was a row nothing
 * could use: a team wedged behind a guide who was not one, recoverable
 * only by staff. The refusal string the leader now sees,
 * refusalnotaguide, already existed and was already shown by the other
 * four seams.
 *
 * Every test here reads the GROUP ROW BACK with $DB after the action.
 * The bug was never about the exception; it was about the field.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\eoi
 */
final class eoi_guide_authority_test extends \advanced_testcase {
    /**
     * Course, activity with interest enabled, two students, two guides.
     *
     * @param array $overrides instance setting overrides
     * @return array{0: activity, 1: \stdClass[], 2: \stdClass[]} activity, students, guides
     */
    private function setup_activity(array $overrides = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'eoienabled' => 1,
        ], $overrides));
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 2; $i++) {
            $student = $generator->create_user();
            $generator->enrol_user($student->id, $course->id, 'student');
            $students[] = $student;
        }
        $guides = [];
        for ($i = 0; $i < 2; $i++) {
            $guide = $generator->create_user();
            $generator->enrol_user($guide->id, $course->id, 'teacher');
            $guides[] = $guide;
        }

        return [$activity, $students, $guides];
    }

    /**
     * A forming team, listed for guide interest.
     *
     * @param activity $activity the activity
     * @param int $leaderid the leader
     * @param string $name group name
     * @return \stdClass the fresh, listed group row
     */
    private function listed_group(activity $activity, int $leaderid, string $name): \stdClass {
        global $DB;

        $group = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderid,
            'name' => $name,
        ]);
        $DB->set_field('selfselectadvanced_group', 'listed', 1, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'timelisted', time(), ['id' => $group->id]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * Withdraw mod/selfselectadvanced:guide from ONE user, the way an
     * administrator does it: a role carrying CAP_PROHIBIT, assigned to
     * that user in the activity's context.
     *
     * PROHIBIT rather than PREVENT, and a private role rather than an
     * override on 'teacher', so the second guide of the fixture keeps
     * the capability and stays available as the positive control.
     *
     * @param activity $activity the activity
     * @param int $userid the user to prohibit
     */
    private function prohibit_guide(activity $activity, int $userid): void {
        static $seq = 0;
        $seq++;

        $roleid = create_role(
            'SSA prohibited guide ' . $seq,
            'ssaprohibitguide' . $seq,
            'Test role: CAP_PROHIBIT on mod/selfselectadvanced:guide'
        );
        assign_capability(
            'mod/selfselectadvanced:guide',
            CAP_PROHIBIT,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $userid, $activity->context()->id);
        accesslib_clear_all_caches_for_unit_testing();

        // The fixture is worthless unless the prohibition really bit.
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:guide', $activity->context(), $userid),
            'the fixture failed to withdraw :guide, so nothing below would be testing anything'
        );
    }

    /**
     * Assert that a callback throws a moodle_exception with the given
     * error code.
     *
     * @param string $errorcode expected error code
     * @param callable $callback the call expected to refuse
     */
    private function assert_refusal(string $errorcode, callable $callback): void {
        try {
            $callback();
            $this->fail('Expected a moodle_exception with error code ' . $errorcode);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * THE DEFECT. A guide expresses interest while they still hold
     * :guide; the administrator withdraws it; the leader then presses
     * Accept in the ordinary way.
     *
     * Before the fix, respond() consulted remaining_capacity() - which
     * still returned a free slot, because a capability is not a count -
     * and wrote the prohibited user into guideid. The assertion that
     * matters is the last one: the FIELD, read back from the database.
     */
    public function test_accept_refuses_a_prohibited_guide_and_writes_no_guideid(): void {
        global $DB;

        $this->resetAfterTest();
        // A refusal now rolls its own delegated transaction back, which
        // sets force_rollback until the stack empties; on PostgreSQL
        // advanced_testcase holds a frame underneath that never lets it
        // empty. Committing the harness frame is what makes the two
        // engines agree - the same line, for the same reason, as in
        // eoi_test and races_locking_test.
        $this->preventResetByRollback();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Wedged');

        $sink = $this->redirectMessages();
        $eoiid = eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML);
        $sink->close();

        $this->prohibit_guide($activity, $guide);

        // The authority the seam was not asking, asked here so the
        // expectation below is anchored to the plugin's own answer
        // rather than to a string this test invented.
        $refusal = (new api($activity))->gatekeeper()->can_take_guide($guide);
        $this->assertNotNull($refusal, 'can_take_guide must refuse a prohibited guide');
        $this->assertSame('refusalnotaguide', $refusal->stringkey);

        $this->assert_refusal(
            'refusalnotaguide',
            fn() => eoi::respond($activity, $eoiid, true, $leader)
        );

        $fresh = $DB->get_record('selfselectadvanced_group', ['id' => $group->id], '*', MUST_EXIST);
        $this->assertNull(
            $fresh->guideid,
            'the accept branch installed a guide the administrator had prohibited'
        );
        $this->assertSame(
            eoi::STATUS_PENDING,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $eoiid], MUST_EXIST),
            'and the interest row was not consumed by a refusal that wrote nothing'
        );
    }

    /**
     * The same authority at the other end of the flow: a user who does
     * not hold :guide cannot open an interest at all, so the pending row
     * that wedges a team is never created either.
     *
     * pickteam.php requires :guide at its door, which is why this is
     * belt and braces rather than the only guard - and why it has to
     * live in the service, where a PROHIBIT applied after the page was
     * loaded, an adhoc task or a CLI caller still reaches it.
     */
    public function test_express_refuses_a_prohibited_guide_and_writes_no_row(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Listed');

        $this->prohibit_guide($activity, $guide);

        $this->assert_refusal(
            'refusalnotaguide',
            fn() => eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML)
        );

        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_eoi', ['groupid' => (int) $group->id]),
            'a refused expression of interest must leave no row behind'
        );
    }

    /**
     * THE OTHER DIRECTION, and it is not optional. A gate that refuses
     * everybody passes every negative test in this file and destroys the
     * feature. An ordinary guide, in the same fixture, with nothing
     * withdrawn, is still installed by an ordinary accept.
     */
    public function test_an_ordinary_accept_still_installs_the_guide(): void {
        global $DB;

        $this->resetAfterTest();

        [$activity, $students, $guides] = $this->setup_activity();
        $leader = (int) $students[0]->id;
        $guide = (int) $guides[0]->id;
        $group = $this->listed_group($activity, $leader, 'Working');

        $sink = $this->redirectMessages();
        $eoiid = eoi::express($activity, (int) $group->id, $guide, '', FORMAT_HTML);
        eoi::respond($activity, $eoiid, true, $leader);
        $sink->close();

        $fresh = $DB->get_record('selfselectadvanced_group', ['id' => $group->id], '*', MUST_EXIST);
        $this->assertSame($guide, (int) $fresh->guideid, 'the connection design must survive the repair');
        $this->assertSame(
            eoi::STATUS_ACCEPTED,
            $DB->get_field('selfselectadvanced_eoi', 'status', ['id' => $eoiid], MUST_EXIST)
        );
    }

    /**
     * A guide prohibited in ONE activity is untouched in another: the
     * gate asks the capability in the activity's own context, so a
     * site-wide conclusion is never drawn from a local override.
     */
    public function test_the_prohibition_is_scoped_to_its_own_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();

        [$activity, $students, $guides] = $this->setup_activity();
        $guide = (int) $guides[0]->id;
        $blocked = $this->listed_group($activity, (int) $students[0]->id, 'Blocked');

        // A second activity in the same course, sharing the same users.
        $second = activity::from_instance((int) $this->getDataGenerator()->create_module(
            'selfselectadvanced',
            ['course' => $activity->cm()->course, 'eoienabled' => 1]
        )->id);
        $open = $this->listed_group($second, (int) $students[1]->id, 'Open');

        $this->prohibit_guide($activity, $guide);
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:guide', $second->context(), $guide),
            'the fixture only means anything while the second activity is unaffected'
        );

        $this->assert_refusal(
            'refusalnotaguide',
            fn() => eoi::express($activity, (int) $blocked->id, $guide, '', FORMAT_HTML)
        );

        $sink = $this->redirectMessages();
        $eoiid = eoi::express($second, (int) $open->id, $guide, '', FORMAT_HTML);
        eoi::respond($second, $eoiid, true, (int) $students[1]->id);
        $sink->close();

        $this->assertSame(
            $guide,
            (int) $DB->get_field('selfselectadvanced_group', 'guideid', ['id' => $open->id], MUST_EXIST)
        );
    }

    /**
     * The four "take a team" seams ask ONE authority, pinned in source.
     *
     * A behavioural test proves the gate is there today; it cannot stop
     * a later reader deciding the extra query is redundant and restoring
     * `remaining_capacity(...) < 1`, which would pass every capacity
     * assertion in eoi_test while reopening exactly this defect. The
     * pin names the call, so the revert has to be deliberate.
     *
     * COMMENTS ARE STRIPPED BY TOKEN FIRST: this file's own paragraphs
     * quote the fragments below, and eoi.php's do too. A raw-text search
     * would be satisfied by the explanation of the rule instead of the
     * rule, which is how a pin fails open.
     */
    public function test_the_four_seams_call_the_one_authority(): void {
        $eoisource = self::executable_source(__DIR__ . '/../classes/local/eoi.php');
        $required = [
            'eoi::express() must ask the gatekeeper, not an integer' =>
                '->gatekeeper()->can_take_guide($guideid)',
            'eoi::respond()\'s accept branch must ask it about the guide it is installing' =>
                '->gatekeeper()->can_take_guide((int) $row->guideid)',
        ];
        foreach ($required as $why => $fragment) {
            $this->assertTrue(str_contains($eoisource, $fragment), $why . ' - missing: ' . $fragment);
        }

        // And the three seams that always asked it still do, so "all
        // four are identical" is a claim this test can actually make.
        foreach (['state.php', 'handover.php', 'contacts.php'] as $file) {
            $this->assertTrue(
                str_contains(self::executable_source(__DIR__ . '/../classes/local/' . $file), 'can_take_guide('),
                $file . ' stopped asking can_take_guide()'
            );
        }
    }

    /**
     * A PHP file's EXECUTABLE source, comments removed by token and
     * whitespace collapsed to single spaces.
     *
     * The idiom contactprivacy_test, contactreach_test and
     * exportpins_test use, and for the same reason: a presence search
     * over raw text FAILS OPEN on a comment, and commenting the old call
     * out is how the edit this pin exists to catch is actually made.
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
