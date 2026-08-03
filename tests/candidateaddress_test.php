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
use mod_selfselectadvanced\local\candidates;

/**
 * The candidate picker's address surface, top of the role ladder
 * downwards (contact-privacy cardinal rule, maintainer decision 24).
 *
 * WHY A SECOND FILE. tests/external_search_test.php already pins the
 * student leader, the editing teacher holding :manage, a role granted
 * mod/selfselectadvanced:viewparticipantidentity and a CONNECTED guide.
 * It does not pin the SITE ADMINISTRATOR, and the administrator is the
 * viewer every "no exemptions" claim is weakest about: doanything
 * satisfies both core identity capabilities and
 * contactprivacy::is_unrestricted() at once, so if any arm of the
 * composition is ever ORed back in, the administrator is the first
 * viewer it shows an address to and the last one anybody tests.
 *
 * This file also pins the SHAPE of the switch-off case (wave-3B audit,
 * G-2). Until this cycle candidates::search() computed a connection map
 * and consulted it per row, under a condition - the switch on AND the
 * switch off - that no row could satisfy. Deleting unsatisfiable code
 * changes no behaviour, which is exactly why it needs a test: what must
 * not drift is that contact privacy here is per activity and BINARY for
 * the LABEL. On, and nobody sees an address. Off, and the two core
 * identity capabilities decide alone - not the connection map, which
 * returns all-true for every subject when the switch is off anyway.
 *
 * THE MATCH IS NOT BINARY, IT IS SIMPLY GONE (wave 3D, P-5). Until this
 * wave the assertions below said "legacy mode is unchanged" and proved
 * it by submitting a whole email address and getting a row back. That
 * IS the oracle, and the switch does not license it: the switch decides
 * what an activity DISPLAYS, never whether a probe is possible, and a
 * student leader with the picker open could hand it any address in the
 * world and be told, one query at a time, which named account owns it.
 * Every test here that used to assert an address MATCH now asserts its
 * absence, and the label assertions - which are about disclosing the
 * address of somebody found BY NAME - are unchanged.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\candidates
 */
final class candidateaddress_test extends \advanced_testcase {
    /**
     * One course, one protected activity, one legacy activity, and a
     * student whose address is the needle.
     *
     * @return array [protected activity, legacy activity, protected group,
     *                legacy group, the subject, the course]
     */
    private function world(): array {
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
        $subject = $generator->create_user([
            'firstname' => 'Tara',
            'lastname' => 'Gett',
            'email' => 'target@example.com',
        ]);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($subject->id, $course->id, 'student');

        $onactivity = activity::from_instance((int) $on->id);
        $offactivity = activity::from_instance((int) $off->id);
        $this->setUser($leader);
        $ongroup = (new api($onactivity))->create_group((int) $leader->id, 'Protected', 'T', '<p>b</p>', FORMAT_HTML);
        $offgroup = (new api($offactivity))->create_group((int) $leader->id, 'Legacy', 'T', '<p>b</p>', FORMAT_HTML);

        return [$onactivity, $offactivity, $ongroup, $offgroup, $subject, $course];
    }

    /**
     * The labels a viewer gets back for one query.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group being invited into
     * @param string $query the search text
     * @param int $viewerid the searching user
     * @return string[] the labels
     */
    private function labels(activity $activity, \stdClass $group, string $query, int $viewerid): array {
        return array_column(
            candidates::search($activity, $group, (new api($activity))->gatekeeper(), $query, $viewerid),
            'label'
        );
    }

    /**
     * The site administrator: no address match in EITHER mode, and no
     * address label while the switch is on.
     *
     * An administrator passes every gate this class composes -
     * moodle/site:viewuseridentity, moodle/course:viewhiddenuserfields,
     * mod/selfselectadvanced:manage and therefore
     * contactprivacy::is_unrestricted() - so while the switch is on the
     * ONLY thing withholding the address is the switch itself. The
     * legacy assertions at the end are what prove the query is not
     * simply broken: the same viewer, the same activity and the same
     * needle still find the person BY NAME and still print the address
     * on the label, and still refuse to confirm the address itself.
     */
    public function test_the_administrator_is_not_an_exemption(): void {
        $this->resetAfterTest();
        [$on, $off, $ongroup, $offgroup, $subject] = $this->world();

        $this->setAdminUser();
        global $USER;
        $adminid = (int) $USER->id;

        $this->assertSame(
            [],
            $this->labels($on, $ongroup, 'target@example.com', $adminid),
            'the administrator used the candidate picker as an address oracle'
        );

        $labels = $this->labels($on, $ongroup, 'Gett', $adminid);
        $this->assertCount(1, $labels, 'the administrator still finds people by name');
        $this->assertStringNotContainsString('target@example.com', $labels[0]);

        // Switch off, same viewer, same needle. The LABEL comes back,
        // because that is what the setting is for; the MATCH does not,
        // because the oracle was never the setting's to license. The
        // name search is what proves the picker still works at all.
        $this->assertSame(
            [],
            $this->labels($off, $offgroup, 'target@example.com', $adminid),
            'the oracle survived the switch being turned off'
        );
        $labels = $this->labels($off, $offgroup, 'Gett', $adminid);
        $this->assertCount(1, $labels, 'legacy mode still finds people by name');
        $this->assertStringContainsString('(target@example.com)', $labels[0]);
        unset($subject);
    }

    /**
     * A manager, and a role granted the plugin's identity capability:
     * neither reopens an address while the switch is on.
     *
     * The manager is the role the cardinal rule names first, and
     * :viewparticipantidentity is the capability that used to be an
     * alternative to the switch. Both are asserted on the MATCH and on
     * the LABEL, because gating one and not the other is what left an
     * oracle open the first time.
     */
    public function test_manager_and_identity_capability_are_not_exemptions(): void {
        $this->resetAfterTest();
        [$on, $off, $ongroup, $offgroup, $subject, $course] = $this->world();
        $generator = $this->getDataGenerator();

        $manager = $generator->create_user(['firstname' => 'Mo', 'lastname' => 'Anager']);
        $generator->enrol_user($manager->id, $course->id, 'manager');

        $granted = $generator->create_user(['firstname' => 'Gina', 'lastname' => 'Granted']);
        $generator->enrol_user($granted->id, $course->id, 'teacher');
        $role = $generator->create_role();
        assign_capability(
            'mod/selfselectadvanced:viewparticipantidentity',
            CAP_ALLOW,
            $role,
            \context_module::instance($on->cm()->id)
        );
        role_assign($role, $granted->id, \context_module::instance($on->cm()->id));
        accesslib_clear_all_caches_for_unit_testing();

        foreach ([$manager, $granted] as $viewer) {
            $who = $viewer->lastname;
            $this->setUser($viewer);
            $this->assertSame(
                [],
                $this->labels($on, $ongroup, 'target@example.com', (int) $viewer->id),
                "$who used the candidate picker as an address oracle"
            );
            $labels = $this->labels($on, $ongroup, 'Gett', (int) $viewer->id);
            $this->assertCount(1, $labels, "$who still finds people by name");
            $this->assertStringNotContainsString('target@example.com', $labels[0], "$who was labelled an address");
        }

        // The manager holds a core identity capability, so legacy mode
        // proves the switch rather than a missing capability is what
        // withholds the address above - by NAME, which is the only way
        // anybody reaches a row now.
        $this->setUser($manager);
        $this->assertSame(
            [],
            $this->labels($off, $offgroup, 'target@example.com', (int) $manager->id),
            'the oracle survived the switch being turned off'
        );
        $labels = $this->labels($off, $offgroup, 'Gett', (int) $manager->id);
        $this->assertCount(1, $labels, 'legacy mode still finds people by name');
        $this->assertStringContainsString('(target@example.com)', $labels[0]);
        unset($subject);
    }

    /**
     * Switch OFF is NOT connection-scoped, and that is a decision.
     *
     * This viewer is connected to nobody in the activity - no team, no
     * guide assignment, no claimed ticket - and holds only what core
     * gives a teacher-archetype role. With the switch off they see the
     * address, because the switch is the whole of this plugin's part in
     * the question and the core identity capabilities are the rest of
     * it. Restoring a connection factor here would break this
     * assertion, which is the point of having it: the unsatisfiable
     * `$showemail && $protect` this replaced could not have been caught
     * by any test, because no row could reach it.
     */
    public function test_the_switch_off_case_is_not_connection_scoped(): void {
        $this->resetAfterTest();
        [$on, $off, $ongroup, $offgroup, $subject, $course] = $this->world();
        $generator = $this->getDataGenerator();

        $stranger = $generator->create_user(['firstname' => 'Sam', 'lastname' => 'Tranger']);
        $generator->enrol_user($stranger->id, $course->id, 'teacher');
        accesslib_clear_all_caches_for_unit_testing();

        // A teacher archetype holds moodle/course:viewhiddenuserfields
        // in core, which is the arm this class AND-s onto, and holds
        // neither :manage nor :viewparticipantidentity.
        $this->assertTrue(has_capability('moodle/course:viewhiddenuserfields', $off->context(), $stranger->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:manage', $off->context(), $stranger->id));
        $this->assertFalse(
            \mod_selfselectadvanced\local\contactprivacy::can_see($on, (int) $stranger->id, (int) $subject->id),
            'the fixture is only meaningful while this viewer is connected to nobody'
        );

        $this->setUser($stranger);
        $labels = $this->labels($off, $offgroup, 'Gett', (int) $stranger->id);
        $this->assertCount(1, $labels);
        $this->assertStringContainsString(
            '(target@example.com)',
            $labels[0],
            'with the switch off the two core capabilities decide alone'
        );
        $this->assertSame(
            [],
            $this->labels($off, $offgroup, 'target@example.com', (int) $stranger->id),
            'and the MATCH is not on that gate, or on any other: it is gone'
        );

        // Same viewer, same lack of connection, switch on: nothing.
        $labels = $this->labels($on, $ongroup, 'Gett', (int) $stranger->id);
        $this->assertCount(1, $labels);
        $this->assertStringNotContainsString('target@example.com', $labels[0]);
        $this->assertSame([], $this->labels($on, $ongroup, 'target@example.com', (int) $stranger->id));
    }
}
