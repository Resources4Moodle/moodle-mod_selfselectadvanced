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

use core_external\external_api;
use mod_selfselectadvanced\local\api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * The candidate-search external function: the AJAX layer behind the
 * native selector (C10, U3), including its capability and IDOR guards.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\external\search_candidates
 */
final class external_search_test extends \externallib_advanced_testcase {
    /**
     * The leader can search by last name through the full external
     * wrapper; results carry eligibility.
     *
     * THE ADDRESS HALF OF THIS TEST IS GONE (1.20.1 wave 3D, P-5) and
     * its inverse is asserted instead. The activity is still created
     * with contact privacy OFF, and that is now the interesting part:
     * even in legacy mode this wrapper will not confirm that an address
     * belongs to a named account. The switch decides what an activity
     * DISPLAYS; it never decided what may be PROBED, and A14/S7's
     * address matching is withdrawn in both states.
     * test_email_match_gated_when_private pins the same pair.
     */
    public function test_execute_as_leader(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxsize' => 3,
            'maxlead' => 1,
            'maxmembership' => 1,
            'contactprivacy' => 0,
        ]);
        $leader = $generator->create_user(['firstname' => 'Lea', 'lastname' => 'Der']);
        $peer = $generator->create_user([
            'firstname' => 'Uma',
            'lastname' => 'Three',
            'email' => 'uma3@example.com',
        ]);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($peer->id, $course->id, 'student');

        $activity = activity::from_instance((int) $instance->id);
        $api = new api($activity);
        $this->setUser($leader);
        $group = $api->create_group((int) $leader->id, 'Searchers', 'T', '<p>b</p>', FORMAT_HTML);

        $result = \mod_selfselectadvanced\external\search_candidates::execute(
            $activity->cm()->id,
            (int) $group->id,
            'Three'
        );
        $result = external_api::clean_returnvalue(
            \mod_selfselectadvanced\external\search_candidates::execute_returns(),
            $result
        );

        $this->assertCount(1, $result);
        $this->assertSame((int) $peer->id, $result[0]['id']);
        $this->assertTrue($result[0]['eligible']);

        // The same person, by their whole address, in the switch state
        // that used to license it: nobody.
        $this->assertCount(
            0,
            \mod_selfselectadvanced\external\search_candidates::execute(
                $activity->cm()->id,
                (int) $group->id,
                'uma3@example.com'
            ),
            'legacy mode is not a licence to run an address oracle'
        );
    }

    /**
     * A non-leader without manage is refused; a foreign group id is
     * refused (IDOR).
     */
    public function test_execute_guards(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $other = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $leader = $generator->create_user();
        $stranger = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($stranger->id, $course->id, 'student');

        $activity = activity::from_instance((int) $instance->id);
        $this->setUser($leader);
        $group = (new api($activity))->create_group((int) $leader->id, 'Mine', 'T', '<p>b</p>', FORMAT_HTML);

        // A stranger may not search candidates for someone else's group.
        $this->setUser($stranger);
        try {
            \mod_selfselectadvanced\external\search_candidates::execute($activity->cm()->id, (int) $group->id, 'x');
            $this->fail('Expected a capability exception');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('permissions', $e->getMessage());
        }

        // The group id must belong to the given activity (IDOR).
        $this->setUser($leader);
        $otheractivity = activity::from_instance((int) $other->id);
        $this->expectException(\dml_missing_record_exception::class);
        \mod_selfselectadvanced\external\search_candidates::execute(
            $otheractivity->cm()->id,
            (int) $group->id,
            'x'
        );
    }

    /**
     * A course, two activities (protected and legacy), a leader with a
     * team in each, and a target whose address is the search term.
     *
     * @return array [protected activity, legacy activity, leader, target, teacher, group ids]
     */
    private function privacy_world(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $on = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
        ]);
        $off = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
            'contactprivacy' => 0,
        ]);
        $leader = $generator->create_user(['firstname' => 'Lea', 'lastname' => 'Der']);
        $target = $generator->create_user([
            'firstname' => 'Tara',
            'lastname' => 'Gett',
            'email' => 'target@example.com',
        ]);
        $teacher = $generator->create_user(['firstname' => 'Ed', 'lastname' => 'Iting']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($target->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $onactivity = activity::from_instance((int) $on->id);
        $offactivity = activity::from_instance((int) $off->id);
        $this->setUser($leader);
        $ongroup = (new api($onactivity))->create_group((int) $leader->id, 'Protected', 'T', '<p>b</p>', FORMAT_HTML);
        $offgroup = (new api($offactivity))->create_group((int) $leader->id, 'Legacy', 'T', '<p>b</p>', FORMAT_HTML);

        return [$onactivity, $offactivity, $leader, $target, $teacher, $ongroup, $offgroup, $course];
    }

    /**
     * 11. THE ORACLE. Matching by address is gated by the same rule that
     * gates showing it: with the switch on, searching a full address
     * gets NOTHING back, so nobody can confirm that the address belongs
     * to anybody here. MAINTAINER DECISION 24 (2026-08-02) extended
     * that to the last exempt viewer: the editing teacher, who holds
     * :manage and used to see through the switch, is refused the match
     * too. Legacy mode - the switch off - is unchanged for everyone.
     *
     * The web service is asserted too, so it is pinned to INHERIT the
     * fix rather than to be hardened a second time of its own.
     */
    public function test_email_match_gated_when_private(): void {
        $this->resetAfterTest();
        [$on, $off, $leader, $target, $teacher, $ongroup, $offgroup] = $this->privacy_world();

        $ongate = (new api($on))->gatekeeper();
        $offgate = (new api($off))->gatekeeper();

        $this->setUser($leader);
        $this->assertCount(
            0,
            \mod_selfselectadvanced\local\candidates::search(
                $on,
                $ongroup,
                $ongate,
                'target@example.com',
                (int) $leader->id
            ),
            'a student leader cannot use the picker as an address oracle'
        );
        // ... and neither can the web service they reach it through.
        $this->assertCount(
            0,
            \mod_selfselectadvanced\external\search_candidates::execute(
                $on->cm()->id,
                (int) $ongroup->id,
                'target@example.com'
            ),
            'the web service inherits the fix'
        );
        // The name search is untouched.
        $this->assertCount(
            1,
            \mod_selfselectadvanced\local\candidates::search($on, $ongroup, $ongate, 'Gett', (int) $leader->id)
        );

        // Legacy mode: A14/S7 address MATCHING is withdrawn there too
        // (wave 3D, P-5). This assertion used to read "1", on the
        // argument that an activity not protecting contact details had
        // nothing to protect. It has: the oracle answers for a person
        // whose address the searcher typed from somewhere else
        // entirely, and no per-activity setting was ever a decision
        // about that. The LABEL still follows the switch - test 12
        // pins it - because showing the address of somebody found BY
        // NAME discloses without confirming a guess.
        $this->assertCount(
            0,
            \mod_selfselectadvanced\local\candidates::search(
                $off,
                $offgroup,
                $offgate,
                'target@example.com',
                (int) $leader->id
            ),
            'the switch being off reopened the oracle'
        );
        $this->assertCount(
            1,
            \mod_selfselectadvanced\local\candidates::search($off, $offgroup, $offgate, 'Gett', (int) $leader->id),
            'and legacy mode still finds people by name'
        );

        // Decision 24: an editing teacher holds :manage, and that is no
        // longer an exemption. The switch is the whole test.
        $this->setUser($teacher);
        $this->assertCount(
            0,
            \mod_selfselectadvanced\local\candidates::search(
                $on,
                $ongroup,
                $ongate,
                'target@example.com',
                (int) $teacher->id
            ),
            'the manage holder used the picker as an address oracle'
        );
        // ... and the picker still works for them by name, so this is
        // a narrowing and not a breakage.
        $this->assertCount(
            1,
            \mod_selfselectadvanced\local\candidates::search($on, $ongroup, $ongate, 'Gett', (int) $teacher->id)
        );
        unset($target);
    }

    /**
     * 12. The LABEL. A teacher-archetype viewer with a core identity
     * capability sees no address while the switch is on; legacy mode and
     * the :manage holder do. And the plugin's own identity capability is
     * AND-ed onto core, never OR-ed - so granting it while core forbids
     * the field restores nothing.
     */
    public function test_label_email_hidden_when_private(): void {
        $this->resetAfterTest();
        [$on, $off, $leader, $target, $teacher, $ongroup, $offgroup, $course] = $this->privacy_world();

        $generator = $this->getDataGenerator();
        $ongate = (new api($on))->gatekeeper();
        $offgate = (new api($off))->gatekeeper();

        // A non-editing teacher with the core identity capability
        // explicitly allowed in the module context.
        $watcher = $generator->create_user(['firstname' => 'Wanda', 'lastname' => 'Watcher']);
        $generator->enrol_user($watcher->id, $course->id, 'teacher');
        $identityrole = $generator->create_role();
        assign_capability(
            'moodle/site:viewuseridentity',
            CAP_ALLOW,
            $identityrole,
            \context_module::instance($on->cm()->id)
        );
        role_assign($identityrole, $watcher->id, \context_module::instance($on->cm()->id));
        accesslib_clear_all_caches_for_unit_testing();

        $labels = array_column(
            \mod_selfselectadvanced\local\candidates::search($on, $ongroup, $ongate, 'Gett', (int) $watcher->id),
            'label'
        );
        $this->assertCount(1, $labels);
        $this->assertStringNotContainsString('(target@example.com', $labels[0]);

        $labels = array_column(
            \mod_selfselectadvanced\local\candidates::search($off, $offgroup, $offgate, 'Gett', (int) $watcher->id),
            'label'
        );
        $this->assertStringContainsString('(target@example.com', $labels[0], 'legacy mode is unchanged');

        // Decision 24: the manage holder no longer owns the switch. The
        // label carries a name and nothing else for them either, and
        // the legacy assertion just above is what proves the switch -
        // rather than a broken query - is doing the withholding.
        $labels = array_column(
            \mod_selfselectadvanced\local\candidates::search($on, $ongroup, $ongate, 'Gett', (int) $teacher->id),
            'label'
        );
        $this->assertCount(1, $labels, 'the manage holder still finds people by name');
        $this->assertStringNotContainsString(
            '(target@example.com',
            $labels[0],
            'no surface labels an address while the switch is on, managers included'
        );

        // THE CONNECTION MAP factor, isolated. This viewer clears both
        // the plugin arm (:viewparticipantidentity) AND the core arm
        // (moodle/course:viewhiddenuserfields, by teacher archetype),
        // so nothing but the map stands between them and the address -
        // and they are connected to nobody, so the label carries none.
        $mapped = $generator->create_user(['firstname' => 'Mona', 'lastname' => 'Mapped']);
        $generator->enrol_user($mapped->id, $course->id, 'teacher');
        $mappedrole = $generator->create_role();
        assign_capability(
            'mod/selfselectadvanced:viewparticipantidentity',
            CAP_ALLOW,
            $mappedrole,
            \context_module::instance($on->cm()->id)
        );
        role_assign($mappedrole, $mapped->id, \context_module::instance($on->cm()->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(has_capability('moodle/course:viewhiddenuserfields', $on->context(), $mapped->id));
        $labels = array_column(
            \mod_selfselectadvanced\local\candidates::search($on, $ongroup, $ongate, 'Gett', (int) $mapped->id),
            'label'
        );
        $this->assertCount(1, $labels, 'the NAME match admits them; decision 24 leaves no address match at all');
        $this->assertStringNotContainsString(
            '(target@example.com',
            $labels[0],
            'but the connection map still decides whose address may be shown'
        );

        // AND-order, good-neighbour principle: a viewer granted the
        // PLUGIN identity capability, CONNECTED to the subject as the
        // assigned guide of their team, and with both core capabilities
        // withdrawn, still sees no address. Everything except the core
        // arm says yes here, so this assertion isolates it: inverting
        // the composition to an OR is exactly what it catches.
        $granted = $generator->create_user(['firstname' => 'Gina', 'lastname' => 'Granted']);
        $generator->enrol_user($granted->id, $course->id, 'teacher');
        $this->connect_as_guide($on, $ongroup, $target, $granted);
        $pluginrole = $generator->create_role();
        assign_capability(
            'mod/selfselectadvanced:viewparticipantidentity',
            CAP_ALLOW,
            $pluginrole,
            \context_module::instance($on->cm()->id)
        );
        // PROHIBIT, not PREVENT: has_capability() resolves each role
        // down its own path and then allows if ANY role says so, so a
        // PREVENT on this extra role would leave the teacher role's own
        // ALLOW standing and the fixture would not model a site that
        // has actually withdrawn the field.
        assign_capability(
            'moodle/site:viewuseridentity',
            CAP_PROHIBIT,
            $pluginrole,
            \context_module::instance($on->cm()->id)
        );
        assign_capability(
            'moodle/course:viewhiddenuserfields',
            CAP_PROHIBIT,
            $pluginrole,
            \context_module::instance($on->cm()->id)
        );
        role_assign($pluginrole, $granted->id, \context_module::instance($on->cm()->id));
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(
            has_capability('mod/selfselectadvanced:viewparticipantidentity', $on->context(), $granted->id)
        );
        $this->assertFalse(has_capability('moodle/site:viewuseridentity', $on->context(), $granted->id));
        $this->assertFalse(has_capability('moodle/course:viewhiddenuserfields', $on->context(), $granted->id));

        $labels = array_column(
            \mod_selfselectadvanced\local\candidates::search($on, $ongroup, $ongate, 'Gett', (int) $granted->id),
            'label'
        );
        $this->assertTrue(
            \mod_selfselectadvanced\local\contactprivacy::can_see($on, (int) $granted->id, (int) $target->id),
            'the connection is real, so only the core arm can be refusing'
        );
        $this->assertCount(1, $labels, 'the NAME match admits them; decision 24 leaves no address match at all');
        $this->assertStringNotContainsString(
            '(target@example.com',
            $labels[0],
            'but it must never restore a field the SITE withheld'
        );
        unset($leader);
    }

    /**
     * Make one user the assigned guide of a team the other is a
     * confirmed member of, so the connection map answers true for the
     * pair.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the team
     * @param \stdClass $member the subject to add as a confirmed member
     * @param \stdClass $guide the viewer to make the assigned guide
     */
    private function connect_as_guide(
        activity $activity,
        \stdClass $group,
        \stdClass $member,
        \stdClass $guide
    ): void {
        global $DB;

        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => (int) $group->id,
            'userid' => (int) $member->id,
            'status' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
        ]);
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $guide->id, ['id' => (int) $group->id]);
        unset($activity);
    }
}
