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

use mod_selfselectadvanced\local\attributes\manager;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\staffmessage;

/**
 * Maintainer decisions 17 and 18: no surface emits an address under the
 * switch, and staff reach a participant with a Moodle message instead.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\staffmessage
 */
final class staffmessage_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var activity The protected activity. */
    private activity $on;

    /** @var activity The legacy activity. */
    private activity $off;

    /** @var \stdClass[] Fixture users keyed by role name. */
    private array $users = [];

    /** @var \stdClass The team the assigned guide guides, in each activity. */
    private array $teams = [];

    /**
     * A team in each activity: leader s1, confirmed member s2, guided by
     * $users['guide']; $users['otherguide'] guides nothing.
     */
    private function build_world(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->course = $generator->create_course(['shortname' => 'SM1']);

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

        $this->users['manager'] = $generator->create_user([
            'email' => 'manager@example.com', 'firstname' => 'Mary', 'lastname' => 'Manager',
        ]);
        $generator->enrol_user($this->users['manager']->id, $this->course->id, 'editingteacher');
        $this->users['guide'] = $generator->create_user([
            'email' => 'guide@example.com', 'firstname' => 'Gita', 'lastname' => 'Guide',
        ]);
        $generator->enrol_user($this->users['guide']->id, $this->course->id, 'teacher');
        $this->users['otherguide'] = $generator->create_user([
            'email' => 'other@example.com', 'firstname' => 'Omar', 'lastname' => 'Other',
        ]);
        $generator->enrol_user($this->users['otherguide']->id, $this->course->id, 'teacher');
        // A :guide holder who is NOT a :viewall holder: the only way to
        // tell "assigned guide" from "sees everything" apart.
        $narrowrole = $generator->create_role();
        assign_capability(
            'mod/selfselectadvanced:guide',
            CAP_ALLOW,
            $narrowrole,
            \context_course::instance($this->course->id)
        );
        $this->users['narrowguide'] = $generator->create_user([
            'email' => 'narrow@example.com', 'firstname' => 'Nina', 'lastname' => 'Narrow',
        ]);
        $generator->enrol_user($this->users['narrowguide']->id, $this->course->id, 'student');
        role_assign($narrowrole, $this->users['narrowguide']->id, \context_course::instance($this->course->id));

        foreach (['s1' => 'One', 's2' => 'Two'] as $who => $surname) {
            $this->users[$who] = $generator->create_user([
                'email' => $who . '@example.com',
                'firstname' => 'Student',
                'lastname' => $surname,
            ]);
            $generator->enrol_user($this->users[$who]->id, $this->course->id, 'student');
        }

        foreach (['on' => $this->on, 'off' => $this->off] as $key => $activity) {
            $team = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $this->users['s1']->id,
                'name' => 'Team ' . $key,
                'guideid' => (int) $this->users['guide']->id,
            ]);
            $plugingen->create_member([
                'groupid' => $team->id,
                'userid' => (int) $this->users['s2']->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            $this->teams[$key] = $team;
        }

        // T-19 removed :viewall from the non-editing teacher ARCHETYPE
        // (db/access.php), so this fixture grants it deliberately rather
        // than inheriting it: 'otherguide' is written to test what a
        // :viewall holder who guides nothing may do, and an implicit
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
     * 10d. The decision-17 pin: the eoilist drill-down's member array
     * carries no address key, its export has no contact column, and
     * neither does for an EDITING TEACHER - the removal is
     * unconditional.
     */
    public function test_no_surface_emits_an_address_under_the_switch(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        foreach (['manager', 'guide'] as $who) {
            $viewerid = (int) $this->users[$who]->id;
            $members = $this->eoilist_members($this->on, (int) $this->teams['on']->id, $viewerid);
            $this->assertCount(2, $members);
            foreach ($members as $member) {
                foreach (['email', 'emailraw', 'mailtourl'] as $forbidden) {
                    $this->assertObjectNotHasProperty($forbidden, $member, $who . ' / ' . $forbidden);
                }
                foreach ((array) $member as $cell) {
                    $this->assertStringNotContainsString('@', (string) $cell, $who);
                }
            }
        }

        // The member array above is a REPLICA of the page's composition,
        // and a replica cannot catch the page drifting away from it, so
        // the page's own source is asserted too. Comments are stripped
        // first: eoilist.php's docblock says the word "mailto" while
        // recording what was removed, and a check that the docblock
        // trips is a check nobody keeps.
        $code = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../eoilist.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $this->assertStringNotContainsString(
            'u.email',
            $code,
            'the drill-down must not fetch the address: what is never loaded cannot be printed'
        );
        $this->assertStringNotContainsString(
            'email',
            $code,
            'and must not name it at all - no column, no key, no export cell'
        );
        $this->assertStringNotContainsString(
            'mailto:',
            $code,
            'and must offer no off-platform contact route: Send a message replaced it'
        );
        unset($DB);
    }

    /**
     * 10e. Exactly three kinds of sender are admitted, and the verdicts
     * are IDENTICAL with the switch off - which is what asking the
     * connection directly, instead of through the display map, buys.
     */
    public function test_may_message_admits_exactly_three_kinds_of_sender(): void {
        $this->resetAfterTest();
        $this->build_world();

        $subject = (int) $this->users['s2']->id;
        foreach (['on' => $this->on, 'off' => $this->off] as $key => $activity) {
            $this->assertTrue(
                staffmessage::may_message($activity, (int) $this->users['manager']->id, $subject),
                $key . ': the manage holder'
            );
            $this->assertTrue(
                staffmessage::may_message($activity, (int) $this->users['otherguide']->id, $subject),
                $key . ': a viewall holder'
            );
            $this->assertTrue(
                staffmessage::may_message($activity, (int) $this->users['guide']->id, $subject),
                $key . ': the assigned guide'
            );
            $this->assertFalse(
                staffmessage::may_message($activity, (int) $this->users['narrowguide']->id, $subject),
                $key . ': a guide holder assigned to nothing'
            );
            $this->assertFalse(
                staffmessage::may_message($activity, (int) $this->users['s1']->id, $subject),
                $key . ': a student teammate'
            );
            $this->assertFalse(
                staffmessage::may_message($activity, $subject, $subject),
                $key . ': self'
            );
        }
    }

    /**
     * 10f. The message travels as a Moodle message, carries no address
     * in either direction, fires no event and writes no plugin row.
     */
    public function test_the_message_travels_as_a_moodle_message_and_carries_no_address(): void {
        global $DB;

        $this->resetAfterTest();
        $this->build_world();

        $before = $this->plugin_row_counts();
        $events = $this->redirectEvents();
        $sink = $this->redirectMessages();

        staffmessage::send(
            $this->on,
            (int) $this->users['guide']->id,
            (int) $this->users['s2']->id,
            'About your team',
            'Please come and see me on Thursday.'
        );

        $messages = $sink->get_messages();
        $sink->close();
        $fired = $events->get_events();
        $events->close();

        $this->assertCount(1, $messages);
        $this->assertSame('mod_selfselectadvanced', $messages[0]->component);
        $this->assertSame('staffmessage', $messages[0]->eventtype);
        $this->assertSame((int) $this->users['s2']->id, (int) $messages[0]->useridto);
        $this->assertEquals(1, (int) $messages[0]->notification);
        $this->assertStringNotContainsString(
            '@',
            $messages[0]->fullmessage . $messages[0]->subject,
            'no address travels in either direction'
        );
        $this->assertStringContainsString('Gita Guide', $messages[0]->fullmessage, 'the sender is named');
        // No PLUGIN event: a person-to-person message is not an audit
        // event, and adding one would put a new event inside a path the
        // house rules keep event-free. (Core fires its own messaging
        // events; those are core's business.)
        $pluginevents = array_values(array_filter(
            $fired,
            static fn($e) => str_starts_with($e->eventname, '\\mod_selfselectadvanced')
        ));
        $this->assertSame([], $pluginevents, 'a person-to-person message is not an audit event');
        $this->assertSame($before, $this->plugin_row_counts(), 'nothing was written');
        unset($DB);
    }

    /**
     * 10g. The SERVICE is the authority: send() refuses what
     * may_message() refuses, whatever a page believed.
     */
    public function test_send_refuses_what_may_message_refuses(): void {
        $this->resetAfterTest();
        $this->build_world();

        $sink = $this->redirectMessages();
        try {
            staffmessage::send(
                $this->on,
                (int) $this->users['narrowguide']->id,
                (int) $this->users['s2']->id,
                'Hello',
                'Body'
            );
            $this->fail('Expected refusalcannotmessage');
        } catch (\moodle_exception $e) {
            $this->assertSame('refusalcannotmessage', $e->errorcode);
        }
        $this->assertSame([], $sink->get_messages());
        $sink->close();
    }

    /**
     * 10h. The provider is actually registered - the assertion that
     * catches a forgotten version bump, which is how this plugin once
     * dropped every notification it sent through a green run.
     */
    public function test_the_provider_is_registered(): void {
        global $DB;

        $this->resetAfterTest();

        $this->assertTrue($DB->record_exists('message_providers', [
            'component' => 'mod_selfselectadvanced',
            'name' => 'staffmessage',
        ]));
    }

    /**
     * The roster's action column carries the Send-a-message link for a
     * viewall holder, on other people's rows and not on their own - and
     * it carries no address, because the roster never had one.
     */
    public function test_the_roster_action_column_offers_the_message_link(): void {
        $this->resetAfterTest();
        $this->build_world();

        $viewerid = (int) $this->users['otherguide']->id;
        $table = new \mod_selfselectadvanced\table\roster_table(
            'ssarostertest',
            $this->on,
            new \moodle_url('/mod/selfselectadvanced/roster.php', ['id' => $this->on->cm()->id]),
            '',
            '',
            false,
            false,
            true,
            $viewerid
        );

        $cell = $table->col_action((object) ['userid' => (int) $this->users['s2']->id]);
        $this->assertStringContainsString(get_string('messagesend', 'mod_selfselectadvanced'), $cell);
        $this->assertStringContainsString('message.php', $cell);
        $this->assertStringNotContainsString('mailto:', $cell);
        $this->assertStringNotContainsString('@example.com', $cell);
        $this->assertStringNotContainsString(get_string('move'), $cell, 'no move link without :manage');

        $this->assertSame(
            '',
            $table->col_action((object) ['userid' => $viewerid]),
            'nobody is offered a message to themself'
        );
    }

    /**
     * Row counts of every plugin table, for a before/after snapshot.
     *
     * @return int[] table name => rows
     */
    private function plugin_row_counts(): array {
        global $DB;

        $counts = [];
        foreach ($DB->get_tables(false) as $table) {
            if (str_starts_with($table, 'selfselectadvanced')) {
                $counts[$table] = $DB->count_records($table);
            }
        }

        return $counts;
    }

    /**
     * The eoilist drill-down's member array, composed exactly as the
     * page composes it.
     *
     * The page script builds this inline and then dies, so there is no
     * seam to call; the test therefore replicates the composition and
     * the negative control reverts BOTH.
     *
     * @param activity $activity the activity
     * @param int $groupid the team
     * @param int $viewerid the viewing guide
     * @return \stdClass[] the member objects the page would render
     */
    private function eoilist_members(activity $activity, int $groupid, int $viewerid): array {
        global $DB;

        $useddims = manager::used_dimensions($activity);
        $hasidentitycap = has_capability(
            'mod/selfselectadvanced:viewparticipantidentity',
            $activity->context(),
            $viewerid,
            false
        );
        $namefields = implode(', ', array_map(
            static fn(string $field) => 'u.' . $field,
            \core_user\fields::for_name()->get_required_fields()
        ));
        $dimselect = implode(', ', array_map(static fn(string $dim) => 'a.' . $dim, $useddims));
        $memberrecords = $DB->get_records_sql(
            "SELECT u.id AS userid, $namefields, a.mobile, a.shareconsent, $dimselect
               FROM {selfselectadvanced_member} m
               JOIN {user} u ON u.id = m.userid
          LEFT JOIN {selfselectadvanced_userattr} a ON a.userid = u.id
              WHERE m.groupid = :groupid AND m.status = :confirmed
           ORDER BY m.isleader DESC, u.lastname, u.firstname",
            ['groupid' => $groupid, 'confirmed' => groups::STATUS_CONFIRMED]
        );

        $memberids = array_map(static fn($r) => (int) $r->userid, $memberrecords);
        $privacymap = \mod_selfselectadvanced\local\contactprivacy::can_see_map($activity, $viewerid, $memberids);
        $mobilebypass = \mod_selfselectadvanced\local\contactprivacy::mobile_consent_bypass(
            $activity,
            $viewerid,
            $hasidentitycap
        );
        $protect = \mod_selfselectadvanced\local\contactprivacy::enabled($activity);
        $messagemap = staffmessage::may_message_map($activity, $viewerid, $memberids);

        $members = [];
        foreach ($memberrecords as $memberrecord) {
            $mobilevisible = !empty($privacymap[(int) $memberrecord->userid])
                && manager::mobile_visible($memberrecord, $mobilebypass);
            $rawmobile = (string) ($memberrecord->mobile ?? '');
            $digits = ($mobilevisible && !$protect) ? preg_replace('/\D+/', '', $rawmobile) : '';
            $member = (object) [
                'firstname' => $memberrecord->firstname,
                'lastname' => $memberrecord->lastname,
                'mobile' => $mobilevisible ? $rawmobile : get_string('mobilewithheld', 'mod_selfselectadvanced'),
                'haswhatsapp' => $digits !== '',
                'whatsappurl' => $digits !== '' ? 'https://wa.me/' . $digits : '',
                'messageurl' => !empty($messagemap[(int) $memberrecord->userid]) ? 'message.php' : '',
            ];
            foreach ($useddims as $dim) {
                $member->$dim = (string) ($memberrecord->$dim ?? '');
            }
            $members[] = $member;
        }

        return $members;
    }
}
