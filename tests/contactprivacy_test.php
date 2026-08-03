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
use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\candidates;
use mod_selfselectadvanced\local\contactprivacy;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * The contact-privacy cardinal rule: the per-activity switch, the one
 * connection map every surface asks, and the narrowing of the mobile
 * surfaces that used to let :viewall overrule a person's own consent.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\contactprivacy
 */
final class contactprivacy_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var activity The protected activity (switch ON). */
    private activity $on;

    /** @var activity The legacy activity (switch OFF). */
    private activity $off;

    /** @var \stdClass[] Fixture users keyed by role name. */
    private array $users = [];

    /** @var \stdClass The team s1 leads, guided by $users['guide']. */
    private \stdClass $alpha;

    /** @var \stdClass A second team, led by s4, guided by nobody. */
    private \stdClass $beta;

    /**
     * Course, two activities (one left at the schema default, one
     * explicitly switched off), staff of every relevant shape and four
     * students in two teams.
     */
    private function build_world(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->course = $generator->create_course(['shortname' => 'CP1']);

        // Deliberately NOT passing contactprivacy: the schema default is
        // what every existing instance comes up with, and that IS the
        // assertion in test 1.
        $oninstance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'maxmembership' => 2,
            'eoienabled' => 1,
        ]);
        $offinstance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'maxmembership' => 2,
            'eoienabled' => 1,
            'contactprivacy' => 0,
        ]);
        $this->on = activity::from_instance((int) $oninstance->id);
        $this->off = activity::from_instance((int) $offinstance->id);

        $this->users['manager'] = $generator->create_user(['email' => 'manager@example.com']);
        $generator->enrol_user($this->users['manager']->id, $this->course->id, 'editingteacher');
        // A non-editing teacher who is the ASSIGNED guide of Alpha.
        $this->users['guide'] = $generator->create_user(['email' => 'guide@example.com']);
        $generator->enrol_user($this->users['guide']->id, $this->course->id, 'teacher');
        // A non-editing teacher who guides nothing: holds :viewall by
        // archetype and must see nothing extra because of it.
        $this->users['otherteacher'] = $generator->create_user(['email' => 'other@example.com']);
        $generator->enrol_user($this->users['otherteacher']->id, $this->course->id, 'teacher');
        // A coordinator: a non-editing teacher carrying the plugin role.
        $this->users['coordinator'] = $generator->create_user(['email' => 'coord@example.com']);
        $generator->enrol_user($this->users['coordinator']->id, $this->course->id, 'teacher');
        role_assign(
            coordinatorrole::ensure(),
            $this->users['coordinator']->id,
            \context_module::instance($this->on->cm()->id)
        );

        $names = ['s1' => 'One', 's2' => 'Two', 's3' => 'Three', 's4' => 'Four'];
        foreach ($names as $who => $surname) {
            $this->users[$who] = $generator->create_user([
                'email' => $who . '@example.com',
                'firstname' => 'Student',
                'lastname' => $surname,
            ]);
            $generator->enrol_user($this->users[$who]->id, $this->course->id, 'student');
        }

        $this->alpha = $plugingen->create_group([
            'activityid' => $this->on->id(),
            'leaderid' => (int) $this->users['s1']->id,
            'name' => 'Alpha',
            'guideid' => (int) $this->users['guide']->id,
        ]);
        $plugingen->create_member([
            'groupid' => $this->alpha->id,
            'userid' => (int) $this->users['s2']->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        // Student s3 is INVITED, not confirmed: not a connection.
        $plugingen->create_member([
            'groupid' => $this->alpha->id,
            'userid' => (int) $this->users['s3']->id,
            'status' => groups::STATUS_INVITED,
        ]);
        $this->beta = $plugingen->create_group([
            'activityid' => $this->on->id(),
            'leaderid' => (int) $this->users['s4']->id,
            'name' => 'Beta',
        ]);

        // T-19 removed :viewall from the non-editing teacher ARCHETYPE
        // (db/access.php), so this fixture grants it deliberately rather
        // than inheriting it: the actors below are written to test what a
        // :viewall holder who guides nothing may see, and an implicit
        // archetype grant is exactly the conflation 1.20.1 undoes. The
        // grant is at system context, which is where core writes an
        // archetype grant, so the fixture is byte-equivalent to the one
        // the archetype produced.
        assign_capability(
            'mod/selfselectadvanced:viewall',
            CAP_ALLOW,
            (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST),
            \context_system::instance()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The actor an attribute WRITE has to be made as.
     *
     * attributes\manager::set() authorises the actor against
     * mod/selfselectadvanced:ingestattributes at system context, so a
     * fixture cannot write a student's own mobile number as that
     * student any more. Nothing in this file is about the write path -
     * every test here is a READ-side question - so the fixture uses the
     * one actor that legitimately holds the capability and leaves
     * $USER alone, because several of these tests turn on who the
     * viewer is.
     *
     * @return int the site administrator's user id
     */
    private function ingester(): int {
        return (int) get_admin()->id;
    }

    /**
     * All four students, as a subject list.
     *
     * @return int[]
     */
    private function studentids(): array {
        return array_map(
            fn(string $who) => (int) $this->users[$who]->id,
            ['s1', 's2', 's3', 's4']
        );
    }

    /**
     * 1. The switch defaults ON for an instance created without it, and
     * an activity with it OFF hands every subject to every viewer.
     */
    public function test_default_on_and_disabled_all_visible(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $this->assertSame(
            1,
            (int) $DB->get_field('selfselectadvanced', 'contactprivacy', ['id' => $this->on->id()])
        );
        $this->assertTrue(contactprivacy::enabled($this->on));
        $this->assertFalse(contactprivacy::enabled($this->off));

        $map = contactprivacy::can_see_map($this->off, (int) $this->users['s1']->id, $this->studentids());
        $this->assertSame([], array_filter($map, static fn($v) => $v === false));
        $this->assertCount(4, $map);

        // An empty subject list is an empty map, not an error.
        $this->assertSame([], contactprivacy::can_see_map($this->on, (int) $this->users['s1']->id, []));
    }

    /**
     * 2. The unrestricted key is :manage and nothing else: an editing
     * teacher and an admin see everybody, a teacher-archetype viewer who
     * holds :viewall does not.
     *
     * WAVE 3D EXAMINED THIS AND LEFT IT (P-1). The audit asked for the
     * :manage arm to go, so that the phone surfaces would answer the
     * way the address surfaces do after decision 24. It was implemented
     * and backed out: lang/en's shareconsentgranted tells the number's
     * OWNER that sharing reaches "your confirmed teammates, the guide
     * assigned to your team, a staff member handling a request you
     * raised, and the teachers who manage this activity", and
     * attributes_admin.feature drives the roster and asserts an editing
     * teacher reading a consented number. Removing the arm without
     * rewriting that sentence would leave the plugin telling students
     * something untrue about their own data. contactprivacy.php's own
     * comment carries the full argument and the open question about the
     * MANAGER archetype, which shares :manage with the editing teacher
     * and which the cardinal rule does name.
     */
    public function test_manage_unrestricted_and_archetype_matrix(): void {
        global $USER;

        $this->resetAfterTest();
        $this->build_world();

        $context = $this->on->context();
        $this->assertTrue(has_capability('mod/selfselectadvanced:viewall', $context, $this->users['otherteacher']->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:manage', $context, $this->users['otherteacher']->id));

        $this->assertTrue(contactprivacy::is_unrestricted($this->on, (int) $this->users['manager']->id));
        $this->assertFalse(contactprivacy::is_unrestricted($this->on, (int) $this->users['otherteacher']->id));
        $this->assertFalse(contactprivacy::is_unrestricted($this->on, (int) $this->users['coordinator']->id));

        $managermap = contactprivacy::can_see_map($this->on, (int) $this->users['manager']->id, $this->studentids());
        $this->assertSame([], array_filter($managermap, static fn($v) => $v === false));

        $teachermap = contactprivacy::can_see_map(
            $this->on,
            (int) $this->users['otherteacher']->id,
            $this->studentids()
        );
        $this->assertSame([], array_filter($teachermap));

        $this->setAdminUser();
        $adminmap = contactprivacy::can_see_map($this->on, (int) $USER->id, $this->studentids());
        $this->assertSame([], array_filter($adminmap, static fn($v) => $v === false));
    }

    /**
     * 3. Connection (a): confirmed teammates both ways, and nobody else.
     */
    public function test_teammate_connection(): void {
        $this->resetAfterTest();
        $this->build_world();

        $s1 = (int) $this->users['s1']->id;
        $s2 = (int) $this->users['s2']->id;
        $s3 = (int) $this->users['s3']->id;
        $s4 = (int) $this->users['s4']->id;

        $from1 = contactprivacy::can_see_map($this->on, $s1, $this->studentids());
        $this->assertTrue($from1[$s1], 'own details always');
        $this->assertTrue($from1[$s2], 'confirmed teammate');
        $this->assertFalse($from1[$s3], 'invited is not confirmed');
        $this->assertFalse($from1[$s4], 'another team');

        $from2 = contactprivacy::can_see_map($this->on, $s2, $this->studentids());
        $this->assertTrue($from2[$s1], 'the connection is symmetric');
        $this->assertFalse($from2[$s4]);

        $this->assertTrue(contactprivacy::can_see($this->on, $s1, $s2));
        $this->assertFalse(contactprivacy::can_see($this->on, $s1, $s4));
    }

    /**
     * 4. Connection (b) keys on the ASSIGNED guide, never on an EOI row
     * of any status - the conflation that let a guide with a rejected
     * interest read a team's contact details.
     */
    public function test_guide_assignment_not_eoi(): void {
        $this->resetAfterTest();
        $this->build_world();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $guide = (int) $this->users['guide']->id;
        $other = (int) $this->users['otherteacher']->id;

        $assigned = contactprivacy::can_see_map($this->on, $guide, $this->studentids());
        $this->assertTrue($assigned[(int) $this->users['s1']->id], 'leader of the team they guide');
        $this->assertTrue($assigned[(int) $this->users['s2']->id], 'confirmed member');
        $this->assertFalse($assigned[(int) $this->users['s3']->id], 'invited only');
        $this->assertFalse($assigned[(int) $this->users['s4']->id], 'a team they do not guide');

        // An expression of interest is NOT an assignment, whatever its
        // status - and a rejected one least of all.
        foreach ([eoi::STATUS_PENDING, eoi::STATUS_ACCEPTED, eoi::STATUS_REJECTED, eoi::STATUS_WITHDRAWN] as $status) {
            $plugingen->create_eoi([
                'activityid' => $this->on->id(),
                'groupid' => (int) $this->beta->id,
                'guideid' => $other,
                'status' => $status,
            ]);
        }
        $interested = contactprivacy::can_see_map($this->on, $other, $this->studentids());
        $this->assertSame([], array_filter($interested), 'interest of any status is not a connection');
    }

    /**
     * 5. Connection (c) is an ACTIVE CLAIM and nothing weaker: not an
     * open ticket the viewer could claim, not eligibility to decide, and
     * not a claim that has since been released.
     */
    public function test_claimed_ticket_connection(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $this->build_world();

        $leader = (int) $this->users['s1']->id;
        $coordinator = (int) $this->users['coordinator']->id;
        $otherteacher = (int) $this->users['otherteacher']->id;
        $subjects = $this->studentids();

        // A frozen team so its leader may file an unfreeze request.
        $this->set_group_state($this->alpha, state::FROZEN);
        $ticket = tickets::file(
            $this->on,
            groups::get($this->on, (int) $this->alpha->id),
            tickets::TYPE_UNFREEZE,
            'Please release us',
            FORMAT_HTML,
            $leader
        );

        // Open, unclaimed: NOT a connection, for anybody - the recorded
        // narrowing of "or are deciding". A coordinator who COULD claim
        // it, and one who is an eligible join-request decider, both see
        // nothing until they actually claim.
        $this->assertSame(
            [],
            array_filter(contactprivacy::can_see_map($this->on, $coordinator, $subjects)),
            'an open ticket in the queue is not a connection'
        );
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:coordinate', $this->on->context(), $coordinator),
            'the fixture coordinator really is an eligible decider'
        );

        tickets::claim($this->on, (int) $ticket->id, $coordinator);

        $claimed = contactprivacy::can_see_map($this->on, $coordinator, $subjects);
        $this->assertTrue($claimed[$leader], 'the claimant reaches the requester');
        $this->assertFalse($claimed[(int) $this->users['s2']->id], 'and nobody else');
        $this->assertSame(
            [],
            array_filter(contactprivacy::can_see_map($this->on, $otherteacher, $subjects)),
            'a different coordinator holds no claim'
        );

        tickets::close($this->on, (int) $ticket->id, tickets::STATUS_OPEN, '', FORMAT_HTML, $coordinator);
        $this->assertSame(
            [],
            array_filter(contactprivacy::can_see_map($this->on, $coordinator, $subjects)),
            'releasing the claim drops the connection'
        );
        $sink->close();
    }

    /**
     * Force a group into a state the fixture needs without going through
     * the lifecycle.
     *
     * @param \stdClass $group the group row
     * @param string $state the state to write
     */
    private function set_group_state(\stdClass $group, string $state): void {
        global $DB;

        $DB->set_field('selfselectadvanced_group', 'state', $state, ['id' => $group->id]);
        $DB->set_field('selfselectadvanced_group', 'timefrozen', time(), ['id' => $group->id]);
    }

    /**
     * 6. One map, whatever the subject count: three queries per chunk
     * plus one capability check, never one lookup per subject.
     */
    public function test_query_count_bulk(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $generator = $this->getDataGenerator();
        $subjects = $this->studentids();
        for ($i = 0; $i < 56; $i++) {
            $extra = $generator->create_user();
            $generator->enrol_user($extra->id, $this->course->id, 'student');
            $subjects[] = (int) $extra->id;
        }
        $this->assertCount(60, $subjects);

        $viewer = (int) $this->users['guide']->id;
        contactprivacy::can_see_map($this->on, $viewer, $subjects);
        $reads = $DB->perf_get_reads();
        contactprivacy::can_see_map($this->on, $viewer, $subjects);
        $this->assertLessThanOrEqual(4, $DB->perf_get_reads() - $reads);
    }

    /**
     * 7. The consent bypass takes the IDENTITY capability, never the
     * breadth one - and a :viewall holder gets nothing from it in any
     * mode, which is the assertion that names the original defect.
     */
    public function test_mobile_consent_bypass_composition(): void {
        $this->resetAfterTest();
        $this->build_world();

        $manager = (int) $this->users['manager']->id;
        $teacher = (int) $this->users['otherteacher']->id;

        // With the identity capability in hand.
        $this->assertFalse(contactprivacy::mobile_consent_bypass($this->on, $teacher, true));
        $this->assertTrue(contactprivacy::mobile_consent_bypass($this->on, $manager, true));
        $this->assertTrue(contactprivacy::mobile_consent_bypass($this->off, $teacher, true));

        // Without it, never - not even for the manager, and not in
        // legacy mode.
        $this->assertFalse(contactprivacy::mobile_consent_bypass($this->on, $manager, false));
        $this->assertFalse(contactprivacy::mobile_consent_bypass($this->off, $manager, false));

        // The defect, named: :viewall buys no bypass anywhere.
        $this->assertTrue(has_capability('mod/selfselectadvanced:viewall', $this->on->context(), $teacher));
        $viewallonly = has_capability('mod/selfselectadvanced:viewall', $this->on->context(), $teacher);
        $this->assertFalse(contactprivacy::mobile_consent_bypass($this->on, $teacher, false));
        $this->assertFalse(contactprivacy::mobile_consent_bypass($this->off, $teacher, false));
        $this->assertTrue($viewallonly, 'the capability really is held, and still buys nothing');
    }

    /**
     * 8. The claimant's view of a requester: a consent-gated mobile and
     * NO email key at all.
     */
    public function test_requester_contact_map(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $this->build_world();

        $leader = (int) $this->users['s1']->id;
        $coordinator = (int) $this->users['coordinator']->id;

        manager::set($leader, ['mobile' => '919800000123'], $this->ingester());

        $this->set_group_state($this->alpha, state::FROZEN);
        $ticket = tickets::file(
            $this->on,
            groups::get($this->on, (int) $this->alpha->id),
            tickets::TYPE_UNFREEZE,
            'Please release us',
            FORMAT_HTML,
            $leader
        );

        $this->assertSame(
            [],
            tickets::requester_contact_map($this->on, $coordinator, [$leader]),
            'no claim, no row'
        );

        tickets::claim($this->on, (int) $ticket->id, $coordinator);

        $map = tickets::requester_contact_map($this->on, $coordinator, [$leader]);
        $this->assertArrayHasKey($leader, $map);
        $this->assertObjectNotHasProperty('email', $map[$leader]);
        $this->assertSame('', $map[$leader]->mobile, 'consent has not been given');

        manager::set_consent($leader, true, $leader);
        $map = tickets::requester_contact_map($this->on, $coordinator, [$leader]);
        $this->assertSame('919800000123', $map[$leader]->mobile);
        $this->assertObjectNotHasProperty('email', $map[$leader]);

        // Not the claimant: no row at all.
        $this->assertSame(
            [],
            tickets::requester_contact_map($this->on, (int) $this->users['otherteacher']->id, [$leader])
        );
        // Legacy mode keeps this page's historical no-contact rendering.
        $this->assertSame([], tickets::requester_contact_map($this->off, $coordinator, [$leader]));
        $sink->close();
    }

    /**
     * 9. The renderables: a :viewall non-editing teacher who guides
     * nothing no longer reads an unconsented number on the group page or
     * the review page.
     */
    public function test_surfaces_mobile_narrowed(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->build_world();

        $s2 = (int) $this->users['s2']->id;
        manager::set($s2, ['mobile' => '919800000222'], $this->ingester());
        $outsider = (int) $this->users['otherteacher']->id;

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $this->on->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $onpage = new \mod_selfselectadvanced\output\group_page(
            new api($this->on),
            groups::get($this->on, (int) $this->alpha->id),
            $outsider
        );
        $rows = $onpage->export_for_template($output)->roster;
        $this->assertSame(get_string('mobilewithheld', 'mod_selfselectadvanced'), $this->row_mobile($rows, 'Two'));

        $reviewon = new \mod_selfselectadvanced\output\review_page(
            new api($this->on),
            groups::get($this->on, (int) $this->alpha->id),
            $outsider
        );
        $line = $this->attrline($reviewon->export_for_template($output)->roster, 'Two');
        $this->assertStringNotContainsString('919800000222', $line);

        // Legacy mode restores the number only for a viewer who actually
        // holds the identity capability: after this work nobody holds it
        // by default, not even the editing teacher.
        $offgroup = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $this->off->id(),
            'leaderid' => (int) $this->users['s1']->id,
            'name' => 'Legacy',
        ]);
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_member([
            'groupid' => $offgroup->id,
            'userid' => $s2,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        $offpage = new \mod_selfselectadvanced\output\group_page(
            new api($this->off),
            groups::get($this->off, (int) $offgroup->id),
            $outsider
        );
        $this->assertSame(
            get_string('mobilewithheld', 'mod_selfselectadvanced'),
            $this->row_mobile($offpage->export_for_template($output)->roster, 'Two'),
            'no identity capability, no bypass, even in legacy mode'
        );

        $identityrole = $this->getDataGenerator()->create_role();
        assign_capability(
            'mod/selfselectadvanced:viewparticipantidentity',
            CAP_ALLOW,
            $identityrole,
            \context_module::instance($this->off->cm()->id)
        );
        role_assign($identityrole, $outsider, \context_module::instance($this->off->cm()->id));
        accesslib_clear_all_caches_for_unit_testing();

        $offpage = new \mod_selfselectadvanced\output\group_page(
            new api($this->off),
            groups::get($this->off, (int) $offgroup->id),
            $outsider
        );
        $this->assertSame(
            '919800000222',
            $this->row_mobile($offpage->export_for_template($output)->roster, 'Two'),
            'legacy mode plus the identity capability restores the bypass'
        );
    }

    /**
     * 9b. The review page is PER MEMBER, not page-wide: the assigned
     * guide of a team still reads a CONSENTED number there.
     */
    public function test_review_page_keeps_the_assigned_guide_connection(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->build_world();

        $s2 = (int) $this->users['s2']->id;
        manager::set($s2, ['mobile' => '919800000333'], $this->ingester());
        manager::set_consent($s2, true, $s2);

        $PAGE->set_url('/mod/selfselectadvanced/review.php', ['id' => $this->on->cm()->id]);
        $output = $PAGE->get_renderer('core');

        $assigned = new \mod_selfselectadvanced\output\review_page(
            new api($this->on),
            groups::get($this->on, (int) $this->alpha->id),
            (int) $this->users['guide']->id
        );
        $this->assertStringContainsString(
            '919800000333',
            $this->attrline($assigned->export_for_template($output)->roster, 'Two'),
            'the assigned guide of the team is a connection, and the member consented'
        );

        $unassigned = new \mod_selfselectadvanced\output\review_page(
            new api($this->on),
            groups::get($this->on, (int) $this->alpha->id),
            (int) $this->users['otherteacher']->id
        );
        $this->assertStringNotContainsString(
            '919800000333',
            $this->attrline($unassigned->export_for_template($output)->roster, 'Two'),
            'a :guide holder who guides another team is not'
        );
    }

    /**
     * 9c. flagged.php's groupless list, whose hard-coded literal true
     * printed unconsented numbers to every :viewall holder and put them
     * in a CSV. The page builds its list inline, so the test asserts on
     * the exact composed expression the page now uses.
     *
     * A REPLICA CANNOT CATCH THE PAGE DRIFTING AWAY FROM IT, and the
     * defect being closed here WAS a hard-coded literal, so the replica
     * assertions below are followed by assertions on flagged.php's own
     * two call sites. Measured 2026-08-01: with only the replica in
     * place, restoring the literal true at both call sites left all 474
     * tests green. The rendered page is driven as well, by
     * tests/behat/contactprivacy.feature's "The flagged report never
     * prints an unconsented number".
     */
    public function test_flagged_groupless_mobile_is_gated(): void {
        $this->resetAfterTest();
        $this->build_world();

        // Student s4 leads Beta, so make a genuinely groupless student.
        $generator = $this->getDataGenerator();
        $lonely = $generator->create_user(['email' => 'lonely@example.com']);
        $generator->enrol_user($lonely->id, $this->course->id, 'student');
        manager::set((int) $lonely->id, ['mobile' => '919800000444'], $this->ingester());

        $viewer = (int) $this->users['otherteacher']->id;
        $attrs = manager::get_for_users([(int) $lonely->id]);
        $bypass = contactprivacy::mobile_consent_bypass(
            $this->on,
            $viewer,
            has_capability('mod/selfselectadvanced:viewparticipantidentity', $this->on->context(), $viewer)
        );
        $map = contactprivacy::can_see_map($this->on, $viewer, [(int) $lonely->id]);
        $show = !empty($map[(int) $lonely->id])
            && manager::mobile_visible($attrs[(int) $lonely->id] ?? null, $bypass);

        $this->assertFalse($show);
        $this->assertStringNotContainsString(
            '919800000444',
            manager::display_line($attrs[(int) $lonely->id] ?? null, $show)
        );
        $this->assertStringNotContainsString(
            '919800000444',
            manager::plain_line($attrs[(int) $lonely->id] ?? null, $show),
            'and not in the export string either'
        );

        // The page itself, so the replica above cannot go on passing
        // while flagged.php drifts back to a literal. assertTrue on
        // str_contains rather than assertStringContainsString: the
        // haystack is a whole page script, and a failure message that
        // prints it is unreadable in a gate log.
        //
        // COMMENTS ARE STRIPPED FIRST (1.20.1 wave 3E). Until then this
        // read the RAW page, and flagged.php's own paragraphs quote both
        // of the call sites below while explaining why they differ - so
        // the search could not tell an explanation of the rule from the
        // rule. Measured (mutation M18): with the literal-false export
        // cell wrapped in a block comment and a $showmobile call written
        // under it - the edit a developer actually makes - this test
        // reported "Tests: 1 ... OK" on m5pg and on m5my while the CSV
        // carried numbers again. exportpins_test pins the same control
        // and found it; this one now finds it too, because a guard rail
        // that only one of two tests can see is a guard rail one commit
        // away from being deleted as a duplicate.
        $page = self::executable_source(__DIR__ . '/../flagged.php');
        $required = [
            'flagged.php must pass the gated flag to display_line(), never a literal' =>
                'manager::display_line( $attrs[(int) $user->id] ?? null, $showmobile )',
            // WAVE 3D: the two call sites deliberately DISAGREE now. The
            // screen keeps the connection verdict; the export takes a
            // literal false, because a page of individually-permitted
            // rows is still a bulk download once it is a file. Pinning
            // the literal is the only way to stop a later reader
            // "restoring the symmetry".
            'and NEVER to plain_line(), which is what the flagged-students CSV carries' =>
                'manager::plain_line( $attrs[(int) $user->id] ?? null, false )',
            'and that flag must be the connection map AND the owner\'s own consent' =>
                '$showmobile = !empty($privacymap[(int) $user->id]) '
                    . '&& \\mod_selfselectadvanced\\local\\attributes\\manager::mobile_visible( '
                    . '$attrs[(int) $user->id] ?? null, $mobilebypass )',
            'and the bypass must read the identity capability, not the page gate' =>
                "mobile_consent_bypass( \$activity, (int) \$USER->id, "
                    . "has_capability('mod/selfselectadvanced:viewparticipantidentity', \$context) )",
        ];
        foreach ($required as $why => $fragment) {
            $this->assertTrue(str_contains($page, $fragment), $why . ' - missing: ' . $fragment);
        }
    }

    /**
     * A page script's EXECUTABLE source, comments removed by token and
     * whitespace collapsed to single spaces.
     *
     * The same idiom exportpins_test, contactreach_test,
     * staffmessage_test and narrowcaps_test use, and for the same
     * reason: a presence search over raw text FAILS OPEN on a comment,
     * and commenting the old call out is how the edit this pin exists to
     * catch is actually made.
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

    /**
     * 10. Guard rails on the surfaces the audit REFUTED, so a later edit
     * cannot quietly make them address surfaces.
     */
    public function test_f7_guardrails(): void {
        $this->resetAfterTest();
        $this->build_world();

        $sink = $this->redirectMessages();
        \mod_selfselectadvanced\local\notifier::send(
            $this->on,
            'invitation',
            (int) $this->users['s2']->id,
            'msgnudgeguidesubject',
            'msgnudgeguidebody',
            (object) ['count' => 1, 'activity' => $this->on->name()],
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $this->on->cm()->id]),
            $this->on->name()
        );
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            foreach ($this->users as $user) {
                $this->assertStringNotContainsString($user->email, (string) $message->fullmessage);
                $this->assertStringNotContainsString($user->email, (string) $message->subject);
            }
        }

        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_eoi([
            'activityid' => $this->on->id(),
            'groupid' => (int) $this->beta->id,
            'guideid' => (int) $this->users['guide']->id,
            'remarks' => 'Happy to take this one on.',
        ]);
        $rows = \mod_selfselectadvanced\table\eoilist_table::export_rows(
            $this->on,
            (int) $this->users['guide']->id,
            ''
        );
        $this->assertNotEmpty($rows, 'the guard rail must have something to examine');
        foreach ($rows as $row) {
            $this->assertSame(
                ['rawname', 'leader', 'status', 'timecreated', 'timeresponded', 'remarks'],
                array_keys((array) $row)
            );
        }
    }

    /**
     * 10a. The plugin's own gate does not lean on core: with every core
     * participant/identity capability withdrawn the map is unchanged,
     * and no picker label carries an address for ANY viewer.
     */
    public function test_the_gate_holds_with_the_core_capabilities_gone(): void {
        $this->resetAfterTest();
        $this->build_world();

        $viewer = (int) $this->users['guide']->id;
        $before = contactprivacy::can_see_map($this->on, $viewer, $this->studentids());

        $coursecontext = \context_course::instance($this->course->id);
        $teacherrole = $this->role_id('teacher');
        $editingrole = $this->role_id('editingteacher');
        $corecaps = [
            'moodle/course:viewparticipants',
            'moodle/site:viewuseridentity',
            'moodle/course:viewhiddenuserfields',
            'moodle/user:viewdetails',
        ];
        foreach ([$teacherrole, $editingrole] as $roleid) {
            foreach ($corecaps as $capability) {
                assign_capability($capability, CAP_PROHIBIT, $roleid, $coursecontext, true);
            }
        }
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame(
            $before,
            contactprivacy::can_see_map($this->on, $viewer, $this->studentids()),
            'the plugin gate does not depend on core participant capabilities'
        );

        $gatekeeper = (new api($this->on))->gatekeeper();
        foreach (['manager', 'guide', 's1'] as $who) {
            $results = candidates::search(
                $this->on,
                groups::get($this->on, (int) $this->alpha->id),
                $gatekeeper,
                's2@example.com',
                (int) $this->users[$who]->id
            );
            foreach ($results as $result) {
                $this->assertStringNotContainsString('@', $result['label'], 'viewer: ' . $who);
            }
        }
    }

    /**
     * 10b. The two core identity capabilities are ALTERNATIVES:
     * preventing one alone leaves addresses printing. Pins the trap so
     * a lockdown runbook cannot be written from one capability name.
     *
     * The needle became a NAME in wave 3D. It used to be the address
     * itself, which made this test depend on the picker matching on an
     * address - the oracle P-5 removed, in both switch states. What the
     * test is actually about is the LABEL, so it now finds the person
     * the only way anybody can and reads the label it gets back.
     */
    public function test_both_core_identity_capabilities_must_go_together(): void {
        $this->resetAfterTest();
        $this->build_world();

        $coursecontext = \context_course::instance($this->course->id);
        assign_capability(
            'moodle/site:viewuseridentity',
            CAP_PREVENT,
            $this->role_id('editingteacher'),
            $coursecontext,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $manager = (int) $this->users['manager']->id;
        $this->assertFalse(has_capability('moodle/site:viewuseridentity', $this->off->context(), $manager));
        $this->assertTrue(has_capability('moodle/course:viewhiddenuserfields', $this->off->context(), $manager));

        $offgroup = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $this->off->id(),
            'leaderid' => (int) $this->users['s1']->id,
            'name' => 'LegacyPick',
        ]);
        $gatekeeper = (new api($this->off))->gatekeeper();
        $group = groups::get($this->off, (int) $offgroup->id);
        $results = candidates::search($this->off, $group, $gatekeeper, 'Two', $manager);
        $this->assertCount(1, $results);
        $this->assertStringContainsString(
            's2@example.com',
            $results[0]['label'],
            'one core capability withdrawn is not a lockdown: the other still prints the address'
        );
        // And the needle it used to use finds nobody, in the switch
        // state that is most permissive about the label.
        $this->assertSame(
            [],
            candidates::search($this->off, $group, $gatekeeper, 's2@example.com', $manager),
            'the picker answered a probe for a whole address'
        );
    }

    /**
     * The coordinator-candidates table's USERNAME column is an identity
     * column, not a name: on a site with email usernames it IS an
     * address, and the table is downloadable, so it puts one in a
     * spreadsheet. Both the column and the filter that MATCHES on it sit
     * behind the same AND-composition step 8 uses.
     *
     * Added 2026-08-01 by the adversarial pass: the gate shipped with no
     * test of any kind, PHPUnit or Behat. Measured - replacing the whole
     * composition with a literal true left every test green.
     *
     * REWRITTEN IN WAVE 3D, and the rewrite is the fix. The version of
     * this test that shipped in wave 3B asserted that a :manage holder
     * SEES the column, because is_unrestricted() was an arm of the
     * composition. Every viewer this page admits holds :manage, so that
     * arm exempted the entire audience: while the switch was on, this
     * was the one surface where an address could still be rendered,
     * matched (type an address into "Name", get exactly one row - an
     * oracle) and downloaded in bulk. Decision 24 names no exempt role;
     * the arm is gone, and what is asserted below is its absence.
     */
    public function test_coordinator_candidates_username_is_identity_gated(): void {
        $this->resetAfterTest();
        $this->build_world();

        $this->setUser($this->users['manager']);
        $roleid = coordinatorrole::ensure();
        $eligible = \mod_selfselectadvanced\local\coordinatorimport::eligible_userids($this->on);
        $url = new \moodle_url('/mod/selfselectadvanced/coordinators.php', ['id' => $this->on->cm()->id]);
        // Any non-empty filter will do: what is asserted is the WHERE
        // clause the filter builds, not the rows it returns.
        $filter = 'teach';
        $build = function (string $id) use ($roleid, $eligible, $url, $filter) {
            return new \mod_selfselectadvanced\table\coordinatorcandidates_table(
                $id,
                $this->on,
                $roleid,
                [],
                $eligible,
                [],
                $url,
                $filter
            );
        };

        // The page's own audience, protection ON: :manage, both core
        // identity capabilities, and no column, no select, no match.
        $this->assertTrue(
            contactprivacy::is_unrestricted($this->on, (int) $this->users['manager']->id),
            'the viewer really does hold the capability that used to be an exemption'
        );
        $this->assertTrue(has_capability('moodle/site:viewuseridentity', $this->on->context()));
        $protected = $build('ssacandprotected');
        $this->assertArrayNotHasKey('username', $protected->columns);
        $this->assertStringNotContainsString('u.username', $protected->sql->fields);
        $this->assertStringNotContainsString('u.username', $protected->sql->where);

        // The SITE grants the plugin's identity capability: the column,
        // the select and the match come back together.
        $identityrole = $this->getDataGenerator()->create_role();
        assign_capability(
            'mod/selfselectadvanced:viewparticipantidentity',
            CAP_ALLOW,
            $identityrole,
            \context_module::instance($this->on->cm()->id)
        );
        role_assign($identityrole, $this->users['manager']->id, \context_module::instance($this->on->cm()->id));
        accesslib_clear_all_caches_for_unit_testing();

        $shown = $build('ssacandshown');
        $this->assertArrayHasKey('username', $shown->columns);
        $this->assertStringContainsString('u.username', $shown->sql->fields);
        $this->assertStringContainsString('u.username', $shown->sql->where);

        // Now the SITE withdraws its own identity capabilities. The
        // plugin arm is untouched - the viewer still holds :manage AND
        // :viewparticipantidentity - so an OR would leave the column
        // standing. AND takes it away, column and match together, and
        // the spreadsheet with them.
        $coursecontext = \context_course::instance($this->course->id);
        foreach (['moodle/site:viewuseridentity', 'moodle/course:viewhiddenuserfields'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $this->role_id('editingteacher'), $coursecontext, true);
        }
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:viewparticipantidentity', $this->on->context()),
            'the plugin arm is still satisfied, so only the AND can close the column'
        );

        $gated = $build('ssacandgated');
        $this->assertArrayNotHasKey('username', $gated->columns);
        $this->assertStringNotContainsString('u.username', $gated->sql->fields);
        $this->assertStringNotContainsString('u.username', $gated->sql->where);
    }

    /**
     * 10c. On a fresh install the new identity capability is held by
     * NOBODY - no archetype, no clone. Needs --reinit to be meaningful,
     * because it reads what db/access.php installed.
     */
    public function test_viewparticipantidentity_is_held_by_nobody_by_default(): void {
        $this->resetAfterTest();

        $this->assertSame(
            [],
            get_roles_with_capability('mod/selfselectadvanced:viewparticipantidentity', CAP_ALLOW)
        );
    }

    /**
     * A core role id by archetype-ish shortname.
     *
     * @param string $shortname the role shortname
     * @return int the role id
     */
    private function role_id(string $shortname): int {
        global $DB;

        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * The mobile cell of the roster row whose last name matches.
     *
     * @param array $roster exported roster rows
     * @param string $lastname the surname to find
     * @return string the mobile cell
     */
    private function row_mobile(array $roster, string $lastname): string {
        foreach ($roster as $row) {
            if ($row->lastname === $lastname) {
                return (string) $row->mobile;
            }
        }
        $this->fail('No roster row for ' . $lastname);
    }

    /**
     * The attribute line of the roster row whose full name contains the
     * given surname.
     *
     * @param array $roster exported roster rows
     * @param string $lastname the surname to find
     * @return string the attribute line
     */
    private function attrline(array $roster, string $lastname): string {
        foreach ($roster as $row) {
            if (str_contains($row->fullname, $lastname)) {
                return (string) $row->attrline;
            }
        }
        $this->fail('No roster row for ' . $lastname);
    }
}
