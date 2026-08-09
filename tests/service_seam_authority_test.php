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
use mod_selfselectadvanced\local\attributes\csv_importer;
use mod_selfselectadvanced\local\attributes\manager as attrmanager;
use mod_selfselectadvanced\local\authority;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\override\store as overridestore;
use mod_selfselectadvanced\local\quota\slots;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Six service seams that authorised nobody, closed at the SERVICE.
 *
 * The shape of every test here is the one the 1.20 authorisation work
 * settled on, because it is the only shape that proves anything:
 *
 * 1. establish that the operation WORKS for the actor in question -
 *    a refusal test whose negative control was never green is a test
 *    that would pass against a service that refuses everybody;
 * 2. take the authority away - PROHIBIT the capability at the activity
 *    context, which is the override an administrator actually makes on
 *    the Permissions page, or use an actor who never held it;
 * 3. call the SAME function the page calls, never a copy of its gate;
 * 4. READ THE ROW BACK WITH $DB. A service that throws after writing
 *    has still written, and the object a service returned - or failed
 *    to return - cannot tell you which happened.
 *
 * The six (1.20.1 external audit, wave 3D):
 *
 * - A-1 external\search_candidates authorised a leader on RAW
 *   OWNERSHIP. The original defect used :creategroup's leader half;
 *   after the 1.20.26 split the same service invariant belongs to
 *   :lead. The capability in db/services.php is metadata, not
 *   enforcement.
 * - A-2 freeze::unfreeze() had NO POSITIVE AUTHORITY GATE at all -
 *   three conditional clauses and a fall-through into the roster
 *   restore.
 * - A-3 override\store::delete() applied neither of the checks its
 *   sibling save() applies, so an exception could be REVOKED by an
 *   actor who could not have GRANTED it.
 * - A-4 attributes\manager::set_consent() never asked whether the
 *   actor was the subject.
 * - A-5 tickets::close() never re-asked the queue-worker authority, so
 *   a claim outlived the capability that earned it.
 * - A-6 attributes\manager::set(), attributes\csv_importer::run() and
 *   quota\slots::create/update/delete() were gated by their pages and
 *   by nothing else.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\external\search_candidates
 * @covers     \mod_selfselectadvanced\local\freeze::unfreeze
 * @covers     \mod_selfselectadvanced\local\override\store::delete
 * @covers     \mod_selfselectadvanced\local\attributes\manager
 * @covers     \mod_selfselectadvanced\local\attributes\csv_importer
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \mod_selfselectadvanced\local\quota\slots
 * @covers     \mod_selfselectadvanced\local\authority
 */
final class service_seam_authority_test extends \externallib_advanced_testcase {
    /**
     * A course, an activity, students and the three staff shapes that
     * matter: a non-editing teacher (guide), an editing teacher, and a
     * second non-editing teacher who guides nothing.
     *
     * @param array $settings instance setting overrides
     * @return array{0: activity, 1: api, 2: \stdClass[], 3: \stdClass, 4: \stdClass, 5: \stdClass, 6: \stdClass}
     *         activity, api, students, guide, staff, bystander, course
     */
    private function world(array $settings = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 4,
            'maxlead' => 2,
            'maxmembership' => 2,
        ], $settings));
        $activity = activity::from_instance((int) $instance->id);

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $generator->create_user(['lastname' => 'Stud' . $i]);
            $generator->enrol_user($user->id, $course->id, 'student');
            $students[] = $user;
        }
        $guide = $generator->create_user(['lastname' => 'Guide']);
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $staff = $generator->create_user(['lastname' => 'Staff']);
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');
        $bystander = $generator->create_user(['lastname' => 'Bystander']);
        $generator->enrol_user($bystander->id, $course->id, 'teacher');

        return [$activity, new api($activity), $students, $guide, $staff, $bystander, $course];
    }

    /**
     * A firm team with an assigned guide, ready to be frozen.
     *
     * @param activity $activity the activity
     * @param \stdClass[] $students the students
     * @param \stdClass $guide the assigned guide
     * @return \stdClass the group row
     */
    private function firm_team(activity $activity, array $students, \stdClass $guide): \stdClass {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $students[0]->id,
            'name' => 'Seam',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $students[1]->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return groups::get($activity, (int) $group->id);
    }

    /**
     * Prohibit a capability for a role at a context - the override an
     * administrator makes on the activity's Permissions page.
     *
     * @param string $capability the capability
     * @param \context $context where to prohibit it
     * @param int $roleid the role
     */
    private function prohibit_role(string $capability, \context $context, int $roleid): void {
        role_change_permission($roleid, $context, $capability, CAP_PROHIBIT);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The same, by role shortname.
     *
     * @param string $capability the capability
     * @param \context $context where to prohibit it
     * @param string $shortname the role's shortname
     */
    private function prohibit(string $capability, \context $context, string $shortname): void {
        global $DB;

        $this->prohibit_role(
            $capability,
            $context,
            (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST)
        );
    }

    /**
     * An initialised CSV reader over literal content.
     *
     * @param string $content CSV text
     * @return \csv_import_reader the reader
     */
    private function reader(string $content): \csv_import_reader {
        $iid = \csv_import_reader::get_new_iid('mod_selfselectadvanced_attr');
        $reader = new \csv_import_reader($iid, 'mod_selfselectadvanced_attr');
        $reader->load_csv_content($content, 'UTF-8', 'comma');

        return $reader;
    }

    /**
     * A-1: the live AJAX endpoint refuses a leader whose :lead
     * has been prohibited, and hands back no part of the pool.
     *
     * The negative control above the prohibition is what makes this a
     * test rather than a tautology: the same call, by the same person,
     * on the same team, returns the candidate first.
     */
    public function test_search_candidates_refuses_a_prohibited_leader(): void {
        $this->resetAfterTest();
        [$activity, $api, $students] = $this->world(['maxmembership' => 1]);
        $leader = $students[0];
        $peer = $students[1];

        $this->setUser($leader);
        $group = $api->create_group((int) $leader->id, 'Searchers', 'T', '<p>b</p>', FORMAT_HTML);

        // NEGATIVE CONTROL: the endpoint works for this leader.
        $before = \mod_selfselectadvanced\external\search_candidates::execute(
            $activity->cm()->id,
            (int) $group->id,
            'Stud1'
        );
        $this->assertCount(1, $before, 'the fixture never produced a candidate to withhold');
        $this->assertSame((int) $peer->id, (int) $before[0]['id']);

        // The administrator takes the capability away. Ownership of the
        // group row is untouched - which is precisely the point.
        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->assertFalse(authority::may_lead($activity, (int) $leader->id));
        $this->assertSame(
            (int) $leader->id,
            (int) groups::get($activity, (int) $group->id)->leaderid,
            'the leader must still own the row, or this proves nothing'
        );

        try {
            \mod_selfselectadvanced\external\search_candidates::execute(
                $activity->cm()->id,
                (int) $group->id,
                'Stud1'
            );
            $this->fail('search_candidates::execute() served a prohibited leader');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::LEAD), $e->a);
        }
    }

    /**
     * A-1, the other half: the manager branch is untouched. An editing
     * teacher does not hold :lead at all, so a fix that reached
     * for it here would have broken the staff route into the picker.
     */
    public function test_search_candidates_still_serves_a_manager(): void {
        $this->resetAfterTest();
        [$activity, $api, $students, , $staff] = $this->world(['maxmembership' => 1]);
        $leader = $students[0];

        $this->setUser($leader);
        $group = $api->create_group((int) $leader->id, 'Searchers', 'T', '<p>b</p>', FORMAT_HTML);

        $this->prohibit(authority::LEAD, $activity->context(), 'student');
        $this->setUser($staff);
        $this->assertFalse(authority::may_lead($activity, (int) $staff->id));

        $result = \mod_selfselectadvanced\external\search_candidates::execute(
            $activity->cm()->id,
            (int) $group->id,
            'Stud1'
        );
        $this->assertCount(1, $result);
    }

    /**
     * A-2: an actor who is neither the team's assigned guide nor a
     * holder of :unfreeze cannot release a frozen team - and the row is
     * read back to prove the restore did not happen anyway.
     *
     * The witness is a NON-EDITING TEACHER who guides nothing. They
     * hold :freeze and :viewassignedteams by archetype and none of
     * :unfreeze, :manage or :coordinate, so they match no branch of the
     * three conditional checks the service used to consist of - which
     * is exactly the actor that fell straight through them.
     */
    public function test_unfreeze_refuses_an_actor_with_no_authority(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, , $bystander] = $this->world();
        $group = $this->firm_team($activity, $students, $guide);
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);
        $this->assertSame(state::FROZEN, $frozen->state);

        // The witness is a real, enrolled user holding no unfreeze
        // authority by any route.
        $context = $activity->context();
        $this->assertFalse(has_capability('mod/selfselectadvanced:unfreeze', $context, (int) $bystander->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:manage', $context, (int) $bystander->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:coordinate', $context, (int) $bystander->id));
        $this->assertNotSame((int) $frozen->guideid, (int) $bystander->id);

        try {
            freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $bystander->id);
            $this->fail('unfreeze() released a team for an actor holding no authority over it');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(authority::UNFREEZE), $e->a);
        }

        // READ THE ROW BACK: still frozen, still stamped, and the
        // membership rows are untouched.
        $row = $DB->get_record('selfselectadvanced_group', ['id' => $frozen->id], '*', MUST_EXIST);
        $this->assertSame(state::FROZEN, $row->state);
        $this->assertNotEmpty($row->timefrozen);
        $this->assertSame(
            2,
            $DB->count_records('selfselectadvanced_member', [
                'groupid' => $frozen->id,
                'status' => groups::STATUS_CONFIRMED,
            ])
        );
    }

    /**
     * A-2: a student is refused for the same reason, and a PROHIBIT on
     * the capability reaches the actor who does hold it.
     */
    public function test_unfreeze_refuses_a_student_and_a_prohibited_manager(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, $staff] = $this->world();
        $group = $this->firm_team($activity, $students, $guide);
        $frozen = freeze::freeze_group($activity, $group, (int) $guide->id);

        $this->assert_throws(
            \required_capability_exception::class,
            fn() => freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $students[0]->id)
        );

        // The editing teacher holds it - established before it is taken.
        $this->assertTrue(authority::may_unfreeze($activity, (int) $staff->id));
        $this->prohibit(authority::UNFREEZE, $activity->context(), 'editingteacher');
        $this->assertFalse(authority::may_unfreeze($activity, (int) $staff->id));

        $this->assert_throws(
            \required_capability_exception::class,
            fn() => freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $staff->id)
        );

        $this->assertSame(
            state::FROZEN,
            $DB->get_field('selfselectadvanced_group', 'state', ['id' => $frozen->id])
        );
    }

    /**
     * A-2 NEGATIVE CONTROLS: both actors the gate must keep admitting
     * still release the team, and the row records it.
     *
     * If either of these went red the gate would be a regression rather
     * than a fix - taking authority away from someone who had it is the
     * mistake this file's subject has already made twice.
     */
    public function test_unfreeze_still_admits_the_manager_and_the_guide(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, $staff] = $this->world();

        // The editing teacher, by capability.
        $first = $this->firm_team($activity, $students, $guide);
        $frozen = freeze::freeze_group($activity, $first, (int) $guide->id);
        $released = freeze::unfreeze($activity, groups::get($activity, (int) $frozen->id), (int) $staff->id);
        $this->assertSame(state::FIRM, $released->state);
        $this->assertSame(
            state::FIRM,
            $DB->get_field('selfselectadvanced_group', 'state', ['id' => $frozen->id])
        );

        // The assigned guide, by identity, on a freeze no member of
        // staff enforced (strategy 1.19 C).
        $refrozen = freeze::freeze_group($activity, groups::get($activity, (int) $frozen->id), (int) $guide->id);
        $this->assertSame(0, (int) $refrozen->frozenbystaff);
        $this->assertFalse(authority::may_unfreeze($activity, (int) $guide->id));
        $byguide = freeze::unfreeze($activity, groups::get($activity, (int) $refrozen->id), (int) $guide->id);
        $this->assertSame(state::FIRM, $byguide->state);
        $this->assertSame(
            state::FIRM,
            $DB->get_field('selfselectadvanced_group', 'state', ['id' => $frozen->id])
        );
    }

    /**
     * A-3: an override may not be REVOKED by an actor who could not
     * have GRANTED it - delete() now applies save()'s conflict rule.
     */
    public function test_override_delete_applies_the_conflict_rule(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, $staff, $bystander] = $this->world();
        $group = $this->firm_team($activity, $students, $guide);

        // A manager grants the exception.
        $row = overridestore::save(
            $activity,
            'group',
            (int) $group->id,
            ['maxsize' => 9],
            (int) $staff->id
        );
        $this->assertTrue($DB->record_exists('selfselectadvanced_override', ['id' => $row->id]));

        // The team's own guide is made a coordinator: coordinate-only
        // authority over a team they are involved in.
        $roleid = coordinatorrole::ensure();
        role_assign($roleid, $guide->id, $activity->context());
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(has_capability('mod/selfselectadvanced:coordinate', $activity->context(), (int) $guide->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:manage', $activity->context(), (int) $guide->id));

        // They cannot GRANT it - established, so the DELETE refusal
        // below is the same rule and not a coincidence.
        try {
            overridestore::save($activity, 'group', (int) $group->id, ['maxsize' => 8], (int) $guide->id);
            $this->fail('save() accepted an involved coordinator');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalcoiinvolved', $e->errorcode);
        }

        try {
            overridestore::delete($activity, (int) $row->id, (int) $guide->id);
            $this->fail('delete() let an involved coordinator revoke an override');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalcoiinvolved', $e->errorcode);
        }

        // READ THE ROW BACK: the exception is still there, unchanged.
        $stored = $DB->get_record('selfselectadvanced_override', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame(9, (int) $stored->maxsize);

        // NEGATIVE CONTROL: an UNINVOLVED coordinator deletes it, so
        // the guard restrains the conflict rather than the verb.
        role_assign($roleid, $bystander->id, $activity->context());
        accesslib_clear_all_caches_for_unit_testing();
        overridestore::delete($activity, (int) $row->id, (int) $bystander->id);
        $this->assertFalse($DB->record_exists('selfselectadvanced_override', ['id' => $row->id]));
    }

    /**
     * A-4: consent is the subject's to give. No other actor may set it,
     * a site administrator included.
     */
    public function test_set_consent_is_self_only(): void {
        global $DB;
        $this->resetAfterTest();
        [, , $students] = $this->world();
        $owner = $students[0];
        $other = $students[1];

        attrmanager::set((int) $owner->id, ['mobile' => '919800000001'], (int) get_admin()->id);
        $this->assertSame(
            0,
            (int) $DB->get_field('selfselectadvanced_userattr', 'shareconsent', ['userid' => $owner->id])
        );

        foreach ([(int) $other->id, (int) get_admin()->id] as $actorid) {
            try {
                attrmanager::set_consent((int) $owner->id, true, $actorid);
                $this->fail('set_consent() accepted actor ' . $actorid . ' for another person');
            } catch (\moodle_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
            // READ THE ROW BACK: the flag never moved.
            $this->assertSame(
                0,
                (int) $DB->get_field('selfselectadvanced_userattr', 'shareconsent', ['userid' => $owner->id])
            );
        }

        // NEGATIVE CONTROL: the owner sets their own, both ways.
        attrmanager::set_consent((int) $owner->id, true, (int) $owner->id);
        $this->assertSame(
            1,
            (int) $DB->get_field('selfselectadvanced_userattr', 'shareconsent', ['userid' => $owner->id])
        );
        attrmanager::set_consent((int) $owner->id, false, (int) $owner->id);
        $this->assertSame(
            0,
            (int) $DB->get_field('selfselectadvanced_userattr', 'shareconsent', ['userid' => $owner->id])
        );
    }

    /**
     * A-4 completed by decision 85: there is NO staff path to this flag.
     *
     * This test used to assert the opposite - that closing set_consent() to
     * staff stranded nothing, because the CSV import's Share Consent column
     * wrote the same flag under :ingestattributes. That WAS true, and it was
     * the hole: one flag described to the student as their own choice, with a
     * second owner holding a spreadsheet. Decision 85 closed it, so the
     * assertion inverts rather than disappears - what mattered then was that
     * the two paths agreed, and what matters now is that only one exists.
     *
     * The row's OTHER columns must still import, or the ruling would have cost
     * the site its attribute ingest.
     */
    public function test_no_staff_path_writes_a_participant_s_consent(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'consenta']);

        $header = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number,Share Consent\n";
        $report = csv_importer::run(
            $this->reader($header . "consenta,,,,,,919800000009,1\n"),
            (int) get_admin()->id,
            true
        );
        $this->assertTrue($report->ok, 'a file carrying the retired column must still import');
        $this->assertSame(
            0,
            (int) $DB->get_field('selfselectadvanced_userattr', 'shareconsent', ['userid' => $user->id]),
            'an import may not grant consent on a participant\'s behalf (decision 85)'
        );
        $this->assertSame(
            '919800000009',
            $DB->get_field('selfselectadvanced_userattr', 'mobile', ['userid' => $user->id]),
            'the rest of the row must still land - the column is ignored, not the file'
        );
    }

    /**
     * A-5: a claim does not outlive the capability that earned it. The
     * claimant's :coordinate is prohibited and the ticket stops being
     * theirs to resolve, decline or release.
     */
    public function test_ticket_close_rechecks_the_queue_authority(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $students, $guide] = $this->world();
        $group = $this->firm_team($activity, $students, $guide);

        $coordinator = $this->getDataGenerator()->create_user(['lastname' => 'Coord']);
        $this->getDataGenerator()->enrol_user($coordinator->id, $activity->courseid(), 'teacher');
        $roleid = coordinatorrole::ensure();
        role_assign($roleid, $coordinator->id, $activity->context());
        accesslib_clear_all_caches_for_unit_testing();

        $first = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap a member',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        // NEGATIVE CONTROL: while the capability holds, claim and close
        // both work and the row records the outcome.
        tickets::claim($activity, (int) $first->id, (int) $coordinator->id);
        tickets::close(
            $activity,
            (int) $first->id,
            tickets::STATUS_RESOLVED,
            'Done',
            FORMAT_PLAIN,
            (int) $coordinator->id
        );
        $this->assertSame(
            tickets::STATUS_RESOLVED,
            $DB->get_field('selfselectadvanced_ticket', 'status', ['id' => $first->id])
        );

        // A second request, filed once the first is off the queue (the
        // duplicate guard counts only open and claimed rows). It is
        // claimed, and THEN the capability goes.
        $second = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'And another swap',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $second->id, (int) $coordinator->id);
        $this->prohibit_role('mod/selfselectadvanced:coordinate', $activity->context(), $roleid);
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:coordinate', $activity->context(), (int) $coordinator->id)
        );
        $this->assertSame(
            (int) $coordinator->id,
            (int) $DB->get_field('selfselectadvanced_ticket', 'claimedby', ['id' => $second->id]),
            'the claim must still be theirs on the row, or this proves nothing'
        );

        foreach ([tickets::STATUS_RESOLVED, tickets::STATUS_DECLINED, tickets::STATUS_OPEN] as $outcome) {
            try {
                tickets::close(
                    $activity,
                    (int) $second->id,
                    $outcome,
                    'Still mine, surely',
                    FORMAT_PLAIN,
                    (int) $coordinator->id
                );
                $this->fail('close() honoured a claim after the capability was prohibited: ' . $outcome);
            } catch (\required_capability_exception $e) {
                $this->assertSame(
                    get_capability_string('mod/selfselectadvanced:coordinate'),
                    $e->a
                );
            }
        }

        // READ THE ROW BACK: still claimed, still unresolved.
        $row = $DB->get_record('selfselectadvanced_ticket', ['id' => $second->id], '*', MUST_EXIST);
        $this->assertSame(tickets::STATUS_CLAIMED, $row->status);
        $this->assertSame((int) $coordinator->id, (int) $row->claimedby);
        $this->assertNull($row->resolvedby);
    }

    /**
     * A-5, the same authority at the other door: claiming a ticket is
     * working the queue too, and a student was never refused by
     * anything but the conflict guard - which does not name them.
     */
    public function test_ticket_claim_requires_the_queue_authority(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $students, $guide] = $this->world();
        $group = $this->firm_team($activity, $students, $guide);

        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap a member',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        // The third student is in no team, so the conflict guard has
        // nothing to say about them: authority is the only thing that
        // can refuse this claim.
        $outsider = (int) $students[2]->id;
        $this->assertFalse($DB->record_exists('selfselectadvanced_member', [
            'groupid' => $group->id,
            'userid' => $outsider,
        ]));

        try {
            tickets::claim($activity, (int) $ticket->id, $outsider);
            $this->fail('claim() gave a ticket to an actor with no queue authority');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('mod/selfselectadvanced:coordinate'), $e->a);
        }

        $row = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticket->id], '*', MUST_EXIST);
        $this->assertSame(tickets::STATUS_OPEN, $row->status);
        $this->assertNull($row->claimedby);
    }

    /**
     * A-6: the attribute write path authorises its actor. An editing
     * teacher - as much staff as this plugin has inside a course - does
     * not hold the system-context capability, and the row is not
     * written.
     */
    public function test_attribute_set_requires_the_ingest_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [, , $students, , $staff] = $this->world();
        $subject = (int) $students[0]->id;

        try {
            attrmanager::set($subject, ['mobile' => '919800000002'], (int) $staff->id);
            $this->fail('manager::set() wrote an attribute row for an unauthorised actor');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(attrmanager::INGEST), $e->a);
        }
        $this->assertFalse($DB->record_exists('selfselectadvanced_userattr', ['userid' => $subject]));

        // NEGATIVE CONTROL: the administrator writes it.
        attrmanager::set($subject, ['mobile' => '919800000002'], (int) get_admin()->id);
        $this->assertSame(
            '919800000002',
            $DB->get_field('selfselectadvanced_userattr', 'mobile', ['userid' => $subject])
        );
    }

    /**
     * A-6: the CSV import authorises its actor on BOTH halves. The dry
     * run is not the harmless one - it resolves every row against the
     * user table and reports which names exist.
     */
    public function test_csv_import_requires_the_ingest_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [, , , , $staff] = $this->world();
        $this->getDataGenerator()->create_user(['username' => 'seamimport']);

        $header = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number\n";
        foreach ([false, true] as $commit) {
            try {
                csv_importer::run(
                    $this->reader($header . "seamimport,,,F,Science,Physics,919800000003\n"),
                    (int) $staff->id,
                    $commit
                );
                $this->fail('csv_importer::run() served an unauthorised actor (commit=' . var_export($commit, true) . ')');
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string(attrmanager::INGEST), $e->a);
            }
        }
        $this->assertSame(0, $DB->count_records('selfselectadvanced_userattr', []));

        // NEGATIVE CONTROL: the administrator's import lands.
        $report = csv_importer::run(
            $this->reader($header . "seamimport,,,F,Science,Physics,919800000003\n"),
            (int) get_admin()->id,
            true
        );
        $this->assertTrue($report->ok);
        $this->assertSame(1, $DB->count_records('selfselectadvanced_userattr', []));
    }

    /**
     * A-6: the composition template is not editable by whoever happens
     * to reach the service. All three writes ask :manage of the actor.
     */
    public function test_slot_writes_require_manage(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, , $students, $guide, $staff] = $this->world();

        $payload = (object) [
            'mincount' => 2,
            'dimension' => 'department',
            'matchtype' => 'value',
            'value' => 'Computer',
            'allowoverlap' => 0,
        ];

        foreach ([(int) $students[0]->id, (int) $guide->id] as $actorid) {
            try {
                slots::create($activity, $payload, $actorid);
                $this->fail('slots::create() accepted actor ' . $actorid);
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string(slots::MANAGE), $e->a);
            }
        }
        $this->assertSame(0, $DB->count_records('selfselectadvanced_qslot', ['activityid' => $activity->id()]));

        // NEGATIVE CONTROL: the editing teacher writes the template.
        $slot = slots::create($activity, $payload, (int) $staff->id);
        $this->assertTrue($DB->record_exists('selfselectadvanced_qslot', ['id' => $slot->id]));

        // Update and delete ask the same question of the same actor.
        $payload->mincount = 3;
        foreach ([(int) $students[0]->id, (int) $guide->id] as $actorid) {
            try {
                slots::update($activity, (int) $slot->id, $payload, $actorid);
                $this->fail('slots::update() accepted actor ' . $actorid);
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string(slots::MANAGE), $e->a);
            }
            try {
                slots::delete($activity, (int) $slot->id, $actorid);
                $this->fail('slots::delete() accepted actor ' . $actorid);
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string(slots::MANAGE), $e->a);
            }
        }
        // READ THE ROW BACK: unchanged and still there.
        $row = $DB->get_record('selfselectadvanced_qslot', ['id' => $slot->id], '*', MUST_EXIST);
        $this->assertSame(2, (int) $row->mincount);

        // And a PROHIBIT reaches the actor who does hold it.
        $this->prohibit(slots::MANAGE, $activity->context(), 'editingteacher');
        try {
            slots::update($activity, (int) $slot->id, $payload, (int) $staff->id);
            $this->fail('slots::update() ignored a prohibited :manage');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string(slots::MANAGE), $e->a);
        }
        $this->assertSame(
            2,
            (int) $DB->get_field('selfselectadvanced_qslot', 'mincount', ['id' => $slot->id])
        );
    }

    /**
     * Assert a callable throws a given exception class.
     *
     * @param string $class the expected exception class
     * @param callable $fn the call under test
     */
    private function assert_throws(string $class, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected ' . $class);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }
}
