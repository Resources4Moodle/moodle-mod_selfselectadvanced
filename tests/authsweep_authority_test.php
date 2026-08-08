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

use mod_selfselectadvanced\local\attributes\depts;
use mod_selfselectadvanced\local\attributes\manager as attrmanager;
use mod_selfselectadvanced\local\contacts;
use mod_selfselectadvanced\local\eoi;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\templates;
use mod_selfselectadvanced\local\volunteering;

/**
 * The 1.20.4 caller-trusting services sweep (AUTH-001/AUTH-003), proven
 * at the SERVICE: contacts::send's missing ACTOR capability,
 * volunteering::set, templates::save/reset, every depts:: write
 * including the programme delete that used to live inline on
 * departments.php, and eoi::transition's recorded actor.
 *
 * Every test follows the seam discipline the 1.20 authorisation work
 * settled on: call the SAME function the page calls, prohibit at the
 * context an administrator actually uses, and read the row back with
 * $DB - a service that throws after writing has still written. Negative
 * and positive controls never share a method: a refused service call
 * rolls its delegated frame back, and on PostgreSQL that poisons any
 * later commit in the same method.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\contacts::send
 * @covers     \mod_selfselectadvanced\local\volunteering::set
 * @covers     \mod_selfselectadvanced\local\templates
 * @covers     \mod_selfselectadvanced\local\attributes\depts
 * @covers     \mod_selfselectadvanced\local\eoi::withdraw
 */
final class authsweep_authority_test extends \advanced_testcase {
    /**
     * A course, an activity, a student leader with a forming team, a
     * guide and an editing teacher.
     *
     * @param array $settings instance setting overrides
     * @return array [activity, group row, leader, guide, staff, course]
     */
    private function world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 3,
            'contactmax' => 2,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $staff = $generator->create_user();
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Sweep',
            'state' => state::FORMING,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $guide, $staff, $course];
    }

    /**
     * Prohibit a capability for a role at a context - the override an
     * administrator makes on the Permissions page.
     *
     * @param string $capability the capability
     * @param \context $context where to prohibit it
     * @param string $roleshortname the archetype role to prohibit it for
     */
    private function prohibit(string $capability, \context $context, string $roleshortname): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $roleshortname], MUST_EXIST);
        role_change_permission($roleid, $context, $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Expect a capability refusal from a callable.
     *
     * @param callable $fn the direct service call
     */
    private function assert_capability_refused(callable $fn): void {
        try {
            $fn();
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * The plugin's own events out of a sink, other noise dropped.
     *
     * @param \core\event\base[] $events everything the sink caught
     * @return \core\event\base[] only mod_selfselectadvanced events
     */
    private function plugin_events(array $events): array {
        return array_values(array_filter(
            $events,
            static fn($event) => str_starts_with(get_class($event), 'mod_selfselectadvanced\\')
        ));
    }

    /**
     * AUTH-001 negative: the leader of record whose :lead was
     * prohibited can no longer approach a guide by calling the service
     * directly - ownership of the row is not authority (decision 38).
     */
    public function test_contacts_send_refused_for_leader_without_lead(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, $guide] = $this->world();

        $this->prohibit('mod/selfselectadvanced:lead', $activity->context(), 'student');

        $sink = $this->redirectEvents();
        $this->assert_capability_refused(fn() => contacts::send(
            $activity,
            $group,
            (int) $guide->id,
            'Would you take us on?',
            FORMAT_PLAIN,
            (int) $leader->id
        ));

        $this->assertSame(0, $DB->count_records('selfselectadvanced_contact'), 'a refused approach must not write');
        $this->assertSame([], $this->plugin_events($sink->get_events()), 'a refused approach must not leave events');
        $sink->close();
    }

    /**
     * Positive control for the approach: with the capability intact the
     * same call writes the row and leaves the contact_sent event.
     */
    public function test_contacts_send_positive_control(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $leader, $guide] = $this->world();

        $sink = $this->redirectEvents();
        $contact = contacts::send(
            $activity,
            $group,
            (int) $guide->id,
            'Would you take us on?',
            FORMAT_PLAIN,
            (int) $leader->id
        );
        $events = $this->plugin_events($sink->get_events());
        $sink->close();

        $row = $DB->get_record('selfselectadvanced_contact', ['id' => $contact->id], '*', MUST_EXIST);
        $this->assertSame(contacts::STATUS_SENT, $row->status);
        $this->assertSame((int) $leader->id, (int) $row->sentby);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\contact_sent::class, $events[0]);
    }

    /**
     * AUTH-001 negative: volunteering::set refuses an actor without
     * :guide - a student, and a guide the administrator prohibited -
     * and writes nothing for either.
     */
    public function test_volunteering_set_refused_without_guide_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $leader, $guide] = $this->world();

        // A student was never a guide here.
        try {
            volunteering::set($activity, (int) $leader->id, 2);
            $this->fail('Expected refusalnotaguide');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotaguide', $e->errorcode);
        }

        // A guide whose capability was prohibited afterwards is no
        // different: owning the (future) row is not authority.
        $this->prohibit('mod/selfselectadvanced:guide', $activity->context(), 'teacher');
        try {
            volunteering::set($activity, (int) $guide->id, 2);
            $this->fail('Expected refusalnotaguide');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalnotaguide', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('selfselectadvanced_volunteer'), 'a refused declaration must not write');
    }

    /**
     * Positive control for volunteering: the guide's own declaration
     * lands and the volunteer_updated event goes out.
     */
    public function test_volunteering_set_positive_control(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , , $guide] = $this->world();

        $sink = $this->redirectEvents();
        volunteering::set($activity, (int) $guide->id, 2);
        $events = $this->plugin_events($sink->get_events());
        $sink->close();

        $row = $DB->get_record('selfselectadvanced_volunteer', [
            'activityid' => $activity->id(),
            'userid' => (int) $guide->id,
        ], '*', MUST_EXIST);
        $this->assertSame(2, (int) $row->capacity);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\volunteer_updated::class, $events[0]);
    }

    /**
     * AUTH-001 negative: templates::save and templates::reset refuse an
     * actor without :manage - here the guide, whose page access never
     * included the template editor - and neither write lands.
     */
    public function test_templates_refused_without_manage(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , , $guide] = $this->world();

        $this->assert_capability_refused(fn() => templates::save(
            $activity,
            'msginvitationbody',
            'S',
            'B',
            (int) $guide->id
        ));
        $this->assertSame(0, $DB->count_records('selfselectadvanced_template'), 'a refused save must not write');

        // Seeded straight into the table, not through the service: this
        // method holds the negatives only.
        $now = time();
        $seeded = $DB->insert_record('selfselectadvanced_template', (object) [
            'activityid' => $activity->id(),
            'msgkey' => 'msginvitationbody',
            'subject' => 'Kept',
            'body' => 'Kept',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->assert_capability_refused(fn() => templates::reset($activity, 'msginvitationbody', (int) $guide->id));
        $this->assertSame(
            'Kept',
            $DB->get_field('selfselectadvanced_template', 'subject', ['id' => $seeded]),
            'a refused reset must not delete'
        );
    }

    /**
     * Positive control for the templates: the editing teacher saves and
     * resets, each write leaves its audit event carrying the actor, and
     * resetting a kind with no override leaves neither row nor event.
     */
    public function test_templates_positive_control_and_events(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , , , $staff] = $this->world();

        $sink = $this->redirectEvents();
        $record = templates::save($activity, 'msginvitationbody', 'Custom subject', 'Custom body', (int) $staff->id);
        templates::reset($activity, 'msginvitationbody', (int) $staff->id);
        // No override left: the second reset is a no-op and must not
        // manufacture an audit trail.
        templates::reset($activity, 'msginvitationbody', (int) $staff->id);
        $events = $this->plugin_events($sink->get_events());
        $sink->close();

        $this->assertFalse($DB->record_exists('selfselectadvanced_template', ['id' => $record->id]));
        $this->assertCount(2, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\template_updated::class, $events[0]);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\template_deleted::class, $events[1]);
        foreach ($events as $event) {
            $this->assertSame((int) $staff->id, (int) $event->userid, 'the audit event must carry the ACTOR');
            $this->assertSame('msginvitationbody', $event->other['msgkey']);
        }
    }

    /**
     * AUTH-001/AUTH-003 negative: every depts:: write - including the
     * programme delete that used to run inline on departments.php -
     * refuses an actor without the ingest authority before touching the
     * vocabulary. The capability is asked first, so not even the
     * MUST_EXIST reads run for the refused caller.
     */
    public function test_depts_writes_refused_without_ingest(): void {
        global $DB;
        $this->resetAfterTest();
        $outsider = $this->getDataGenerator()->create_user();
        $actorid = (int) $outsider->id;

        $sink = $this->redirectEvents();
        $this->assert_capability_refused(fn() => depts::create('Engineering', 0, $actorid));
        $this->assert_capability_refused(fn() => depts::ensure('Engineering', 'Mechanical', $actorid));
        $this->assert_capability_refused(fn() => depts::ensure_program('MSc', $actorid));
        $this->assert_capability_refused(fn() => depts::create_program('MSc', $actorid));
        $this->assert_capability_refused(fn() => depts::rename(999999, 'Engg', $actorid));
        $this->assert_capability_refused(fn() => depts::move(999999, -1, $actorid));
        $this->assert_capability_refused(fn() => depts::delete(999999, $actorid));
        $this->assert_capability_refused(fn() => depts::delete_program(999999, $actorid));
        $this->assert_capability_refused(fn() => depts::bulk_add("Engineering / Mechanical\n", $actorid));

        $this->assertSame(0, $DB->count_records('selfselectadvanced_dept'), 'a refused vocabulary write must not write');
        $this->assertSame([], $this->plugin_events($sink->get_events()), 'a refused vocabulary write must not leave events');
        $sink->close();
    }

    /**
     * Positive control for the vocabulary: an administrator drives the
     * full lifecycle; every UI-facing write leaves its audit event with
     * the actor at the system context, bulk_add dispatches AFTER its
     * commit, and the importer-facing ensure()/ensure_program() write
     * without events by design - their audit trail is the import's.
     */
    public function test_depts_writes_positive_control_and_events(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;
        $systemcontext = \context_system::instance();

        $sink = $this->redirectEvents();
        $eng = depts::create('Engineering', 0, $admin);
        $events = $this->plugin_events($sink->get_events());
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\dept_created::class, $events[0]);
        $this->assertSame($admin, (int) $events[0]->userid);
        $this->assertSame($systemcontext->id, (int) $events[0]->get_context()->id);
        $this->assertSame('Engineering', $events[0]->other['name']);
        $this->assertSame('dept', $events[0]->other['kind']);
        $sink->clear();

        depts::rename((int) $eng->id, 'Engg', $admin);
        $events = $this->plugin_events($sink->get_events());
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\dept_updated::class, $events[0]);
        $this->assertSame('Engg', $events[0]->other['name']);
        $sink->clear();

        $sci = depts::create('Science', 0, $admin);
        $sink->clear();
        depts::move((int) $sci->id, -1, $admin);
        $events = $this->plugin_events($sink->get_events());
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\dept_updated::class, $events[0]);
        $sink->clear();

        $report = depts::bulk_add("Engg / Mechanical / Thermo\nScience / Physics\n", $admin);
        $this->assertSame(3, $report->created);
        $this->assertSame(2, $report->existing);
        $events = $this->plugin_events($sink->get_events());
        $this->assertCount(3, $events, 'bulk_add fires one dept_created per node, after its commit');
        foreach ($events as $event) {
            $this->assertInstanceOf(\mod_selfselectadvanced\event\dept_created::class, $event);
            $this->assertSame($admin, (int) $event->userid);
        }
        $sink->clear();

        $prog = depts::create_program('MSc', $admin);
        $events = $this->plugin_events($sink->get_events());
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\dept_created::class, $events[0]);
        $this->assertSame('program', $events[0]->other['kind']);
        $sink->clear();

        depts::delete_program((int) $prog->id, $admin);
        $events = $this->plugin_events($sink->get_events());
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\dept_deleted::class, $events[0]);
        $this->assertSame('MSc', $events[0]->other['name']);
        $this->assertFalse($DB->record_exists('selfselectadvanced_dept', ['id' => $prog->id]));
        $sink->clear();

        // The importer-facing pair: rows land, no vocabulary events -
        // the ingest's own report and event are the audit trail there.
        depts::ensure('Humanities', 'History', $admin);
        depts::ensure_program('BSc', $admin);
        $this->assertNull(depts::validate_pair('Humanities', 'History'));
        $this->assertArrayHasKey('BSc', depts::programs_menu());
        $this->assertSame([], $this->plugin_events($sink->get_events()), 'ensure()/ensure_program() are event-free by design');
        $sink->close();
    }

    /**
     * AUTH-003: the in-use guard travelled with the programme delete
     * into the service - a programme carried by an ingested attribute
     * row cannot be deleted, by any caller, and the row survives.
     */
    public function test_delete_program_in_use_guard(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;

        $prog = depts::create_program('MSc', $admin);
        $user = $this->getDataGenerator()->create_user();
        attrmanager::set((int) $user->id, ['program' => 'MSc'], $admin);

        try {
            depts::delete_program((int) $prog->id, $admin);
            $this->fail('Expected errdeptinuse');
        } catch (\moodle_exception $e) {
            $this->assertSame('errdeptinuse', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('selfselectadvanced_dept', ['id' => $prog->id]));
    }

    /**
     * The eoi 900-970 residual (AUTH-001): transition() no longer
     * defaults its actor, and the eoi_updated event records who acted -
     * here a guide withdrawing while a DIFFERENT user holds the
     * session, which is exactly the case the old $USER default booked
     * to the wrong person.
     */
    public function test_eoi_withdraw_event_records_the_acting_guide(): void {
        $this->resetAfterTest();
        [$activity, $group, , $guide] = $this->world();

        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $row = $plugingen->create_eoi([
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'guideid' => (int) $guide->id,
        ]);

        // The session belongs to somebody else entirely.
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        eoi::withdraw($activity, (int) $row->id, (int) $guide->id);
        $events = $this->plugin_events($sink->get_events());
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(\mod_selfselectadvanced\event\eoi_updated::class, $events[0]);
        $this->assertSame(eoi::STATUS_WITHDRAWN, $events[0]->other['status']);
        $this->assertSame((int) $guide->id, (int) $events[0]->userid, 'the event must record the ACTOR, not the session');
        $this->assertSame((int) $guide->id, (int) $events[0]->relateduserid);
    }
}
