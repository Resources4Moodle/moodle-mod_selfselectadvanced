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

use mod_selfselectadvanced\local\quota\slots;
use mod_selfselectadvanced\local\quota\store;

/**
 * THE COUNTING-RULE TABLE HAS AN OWNER (audit D7-b).
 *
 * quota\slots - the seat plan - was authorised at the service in 1.20.1
 * (audit A-6): create(), update() and delete() each take the actor
 * explicitly and each call require_manage(). Its sibling quota\store -
 * the counting rules - was not. save(), delete() and move() took NO
 * ACTOR ARGUMENT AT ALL, and the only thing that had ever asked who was
 * acting was one require_capability at the top of quotas.php.
 *
 * That is the same defect A-6 closed next door, and it is not academic:
 * a counting rule decides whether a team is quota-compliant, compliance
 * decides whether gatekeeper::can_submit() lets a leader submit, and
 * conflicts::detect() reports the rules to every manager. Editing this
 * table moves who may proceed across the whole activity.
 *
 * A missing PARAMETER is worse than a missing check, because no test can
 * be written against it: there is nothing to pass, so "who was refused"
 * is not a question the suite can even ask. That is why the repair is a
 * parameter first and a require_capability second, and why this file
 * asserts the SHAPE of the three signatures as well as their behaviour.
 *
 * Every refusal below is followed by a read of the quota table, because
 * a guard that throws after writing is not a guard.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\quota\store
 */
final class quota_store_authority_test extends \advanced_testcase {
    /** @var activity The activity under test. */
    private activity $activity;

    /** @var \stdClass[] Fixture users keyed by role name. */
    private array $users = [];

    /**
     * One course, one activity, and one actor of each shape that could
     * plausibly reach the service: the editing teacher who owns the
     * settings, a non-editing teacher (who holds :guide, :freeze and
     * :viewall but never :manage), and a student.
     */
    private function build_world(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 5,
        ]);
        $this->activity = activity::from_instance((int) $instance->id);

        foreach (['manager' => 'editingteacher', 'teacher' => 'teacher', 'student' => 'student'] as $who => $role) {
            $this->users[$who] = $generator->create_user();
            $generator->enrol_user($this->users[$who]->id, $course->id, $role);
        }

        $context = $this->activity->context();
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:manage', $context, $this->users['manager']->id),
            'the fixture manager must really hold :manage or the positive control proves nothing'
        );
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:manage', $context, $this->users['teacher']->id),
            'a non-editing teacher must not hold :manage or the negative controls prove nothing'
        );
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:manage', $context, $this->users['student']->id)
        );
    }

    /**
     * A rule payload in the shape quotas.php composes.
     *
     * @param string $value the attribute value the rule pins
     * @param int $mincount the minimum
     * @return \stdClass
     */
    private function payload(string $value, int $mincount = 1): \stdClass {
        return (object) [
            'dimension' => 'gender',
            'rtype' => 'value',
            'value' => $value,
            'mincount' => $mincount,
            'maxcount' => null,
        ];
    }

    /**
     * Assert a call is refused by the capability system and wrote nothing.
     *
     * @param callable $callback the call expected to refuse
     */
    private function assert_denied(callable $callback): void {
        global $DB;

        $before = $DB->get_records('selfselectadvanced_quota', ['activityid' => $this->activity->id()], 'id');
        try {
            $callback();
            $this->fail('Expected a required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $after = $DB->get_records('selfselectadvanced_quota', ['activityid' => $this->activity->id()], 'id');
        $this->assertEquals($before, $after, 'the refusal wrote to the quota table before throwing');
    }

    /**
     * save() refuses everybody who does not hold :manage, on the create
     * path and on the update path, and leaves the table untouched.
     */
    public function test_save_requires_manage(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $manager = (int) $this->users['manager']->id;

        // Nobody without :manage can create a rule.
        foreach (['teacher', 'student'] as $who) {
            $actor = (int) $this->users[$who]->id;
            $this->assert_denied(fn() => store::save($this->activity, $this->payload('Female'), $actor));
        }
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_quota', ['activityid' => $this->activity->id()]),
            'a refused create must leave no rule behind'
        );

        // The manager creates one - the positive control, without which
        // every assertion above is satisfied by a service that refuses
        // everybody.
        $rule = store::save($this->activity, $this->payload('Female'), $manager);
        $this->assertSame(
            'Female',
            $DB->get_field('selfselectadvanced_quota', 'value', ['id' => $rule->id], MUST_EXIST)
        );

        // And nobody without :manage can edit the rule that now exists.
        $edit = $this->payload('Male', 4);
        $edit->id = (int) $rule->id;
        $this->assert_denied(fn() => store::save($this->activity, $edit, (int) $this->users['teacher']->id));
        $this->assertSame(
            'Female',
            $DB->get_field('selfselectadvanced_quota', 'value', ['id' => $rule->id], MUST_EXIST),
            'a refused update must not have rewritten the rule'
        );

        // The manager may.
        store::save($this->activity, $edit, $manager);
        $this->assertSame(
            'Male',
            $DB->get_field('selfselectadvanced_quota', 'value', ['id' => $rule->id], MUST_EXIST)
        );
    }

    /**
     * delete() refuses without :manage and the rule survives; with
     * :manage it goes and the priority gap closes, exactly as before.
     */
    public function test_delete_requires_manage(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $manager = (int) $this->users['manager']->id;
        $first = store::save($this->activity, $this->payload('Female'), $manager);
        $second = store::save($this->activity, $this->payload('Male'), $manager);

        foreach (['teacher', 'student'] as $who) {
            $actor = (int) $this->users[$who]->id;
            $this->assert_denied(fn() => store::delete($this->activity, (int) $first->id, $actor));
        }
        $this->assertTrue($DB->record_exists('selfselectadvanced_quota', ['id' => $first->id]));

        store::delete($this->activity, (int) $first->id, $manager);
        $this->assertFalse($DB->record_exists('selfselectadvanced_quota', ['id' => $first->id]));
        $this->assertSame(
            1,
            (int) $DB->get_field('selfselectadvanced_quota', 'priority', ['id' => $second->id], MUST_EXIST),
            'the surviving rule renumbered to 1, so the repair did not break the gap-closing'
        );
    }

    /**
     * move() refuses without :manage and the priority order is
     * unchanged; with :manage the two rules swap.
     */
    public function test_move_requires_manage(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $manager = (int) $this->users['manager']->id;
        $first = store::save($this->activity, $this->payload('Female'), $manager);
        $second = store::save($this->activity, $this->payload('Male'), $manager);
        $order = fn() => array_map(fn($r) => (int) $r->id, store::get_all($this->activity));
        $this->assertSame([(int) $first->id, (int) $second->id], $order());

        foreach (['teacher', 'student'] as $who) {
            $actor = (int) $this->users[$who]->id;
            $this->assert_denied(fn() => store::move($this->activity, (int) $second->id, -1, $actor));
        }
        $this->assertSame([(int) $first->id, (int) $second->id], $order(), 'a refused move reordered the rules');

        store::move($this->activity, (int) $second->id, -1, $manager);
        $this->assertSame([(int) $second->id, (int) $first->id], $order());
        $this->assertSame(
            [1, 2],
            array_map(fn($r) => (int) $r->priority, store::get_all($this->activity)),
            'and priorities are still a unique 1..n sequence'
        );
    }

    /**
     * A site administrator still passes, through doanything: the repair
     * asks a capability, it does not invent a role list.
     */
    public function test_the_site_administrator_still_manages_rules(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $rule = store::save($this->activity, $this->payload('Female'), (int) get_admin()->id);
        $this->assertTrue($DB->record_exists('selfselectadvanced_quota', ['id' => $rule->id]));
    }

    /**
     * THE SHAPE, not just the behaviour: the actor is a REQUIRED
     * parameter on all three mutators, in both quota services.
     *
     * A default - `int $actorid = 0`, or worse a fallback to $USER -
     * would let every existing call site keep compiling and turn the
     * repair into decoration. slots.php's own docblock spells out why
     * the parameter is required rather than defaulted: a default of
     * "the current user" is silently wrong in cron, in an adhoc task
     * and in a CLI seed. This pins both services to that rule so they
     * cannot drift apart again.
     */
    public function test_the_actor_is_a_required_parameter_in_both_services(): void {
        $mutators = [
            store::class => ['save' => 2, 'delete' => 2, 'move' => 3],
            slots::class => ['create' => 2, 'update' => 3, 'delete' => 2],
        ];
        foreach ($mutators as $class => $methods) {
            foreach ($methods as $method => $position) {
                $params = (new \ReflectionMethod($class, $method))->getParameters();
                $this->assertArrayHasKey($position, $params, $class . '::' . $method . ' lost its actor parameter');
                $actor = $params[$position];
                $this->assertSame('actorid', $actor->getName(), $class . '::' . $method);
                $this->assertSame('int', (string) $actor->getType(), $class . '::' . $method);
                $this->assertFalse(
                    $actor->isOptional(),
                    $class . '::' . $method . ' gave its actor a default, which makes the gate optional'
                );
            }
        }
    }

    /**
     * quotas.php hands the real user to all three, and no page-level
     * require_capability substitutes for that.
     *
     * A unit test cannot drive a page script, so this reads the page's
     * EXECUTABLE source - comments stripped by token, whitespace
     * collapsed - which is the idiom contactprivacy_test and
     * contactreach_test use. A raw-text search fails open on a comment,
     * and commenting the old call out is exactly how the reverting edit
     * is made.
     */
    public function test_the_page_passes_the_real_actor(): void {
        $page = self::executable_source(__DIR__ . '/../quotas.php');
        $required = [
            'the move buttons must name the acting user' =>
                'store::move($activity, $ruleid, $action === \'moveup\' ? -1 : 1, (int) $USER->id)',
            'the delete button must name the acting user' =>
                'store::delete($activity, $ruleid, (int) $USER->id)',
            'the add/edit form must name the acting user' =>
                'store::save($activity, $save, (int) $USER->id)',
        ];
        foreach ($required as $why => $fragment) {
            $this->assertTrue(str_contains($page, $fragment), $why . ' - missing: ' . $fragment);
        }
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
