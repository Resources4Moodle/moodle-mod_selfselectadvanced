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
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * WHO CAN REACH A PHONE NUMBER, AND BY WHAT ROUTE (1.20.1 wave 3D).
 *
 * The cardinal rule has two halves and this file is about keeping them
 * apart, because the previous three waves kept trading one for the
 * other.
 *
 * The half that FORBIDS: no bulk extraction of contact details, and no
 * address oracle. Bulk means a file - a downloadable table of the whole
 * site's mobile numbers, a CSV of the whole cohort's; an oracle means
 * any surface that will confirm "this address belongs to this named
 * account", whether or not it prints the address back. Neither is
 * licensed by a capability, and neither is licensed by the per-activity
 * switch being off: a setting about what an activity DISPLAYS was never
 * a statement about what may be PROBED or EXPORTED.
 *
 * The half that PERMITS, and that must not be deleted in the name of
 * the first: connection plus consent. A confirmed teammate, the guide
 * ASSIGNED to a team, and the coordinator holding a claim on a person's
 * ticket all see that person's mobile number when that person chose to
 * share it. That is the specification. Three of this file's tests exist
 * only to prove the wave did not quietly take it away, because the
 * cheapest way to pass a privacy audit is to disclose nothing to
 * anybody, and it would make the plugin useless.
 *
 * What moved in this wave, all of it read-time display gating:
 *  - contactprivacy::can_see_map() KEEPS its :manage arm, examined and
 *    deliberately not changed (P-1), because the plugin promises that
 *    audience to the number's owner in so many words - the reasoning,
 *    the evidence and the open question about the manager archetype are
 *    on can_see_map() itself and on the test below;
 *  - the site-wide attributes table drops the mobile column from its
 *    DOWNLOAD, and stops selecting it there at all (P-2);
 *  - the coordinator-candidates table's username column and username
 *    MATCH stop being reopened by :manage (P-3, asserted in
 *    contactprivacy_test);
 *  - the flagged-students export takes a literal false (P-4);
 *  - the invitation picker matches on names in BOTH switch states
 *    (P-5, asserted here and in candidateaddress_test);
 *  - the eoilist drill-down offers no off-platform deep link in either
 *    switch state (P-6).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\contactprivacy
 * @covers     \mod_selfselectadvanced\local\candidates
 * @covers     \mod_selfselectadvanced\table\attributes_table
 */
final class contactreach_test extends \advanced_testcase {
    /** @var string The number every subject in this file shares. */
    private const NUMBER = '919800000777';

    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var activity The protected activity (switch ON). */
    private activity $on;

    /** @var activity The legacy activity (switch OFF). */
    private activity $off;

    /** @var \stdClass[] Fixture users keyed by role name. */
    private array $users = [];

    /** @var \stdClass The team s1 leads and 'guide' is assigned to. */
    private \stdClass $alpha;

    /**
     * One course, one protected activity, one legacy activity, staff of
     * each relevant shape, a two-person team and one student in no team.
     *
     * s2's number is set and CONSENTED throughout: the whole file is
     * about who reaches a number its owner agreed to share, which is
     * the only interesting case. A number nobody consented to is
     * already answered by mobile_consent_bypass()'s own tests.
     */
    private function build_world(): void {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->course = $generator->create_course(['shortname' => 'CR1']);

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

        // The editing teacher: holds :manage, and holds :viewall by
        // archetype, so the roster's mobile COLUMN is drawn for them.
        // That is what makes them the discriminating viewer here - the
        // column exists and the cells must still be empty.
        $this->users['manager'] = $generator->create_user(['lastname' => 'Manager']);
        $generator->enrol_user($this->users['manager']->id, $this->course->id, 'editingteacher');
        $this->users['guide'] = $generator->create_user(['lastname' => 'Guide']);
        $generator->enrol_user($this->users['guide']->id, $this->course->id, 'teacher');
        $this->users['coordinator'] = $generator->create_user(['lastname' => 'Coord']);
        $generator->enrol_user($this->users['coordinator']->id, $this->course->id, 'teacher');
        role_assign(
            coordinatorrole::ensure(),
            $this->users['coordinator']->id,
            \context_module::instance($this->on->cm()->id)
        );

        foreach (['s1' => 'One', 's2' => 'Two', 's3' => 'Three'] as $who => $surname) {
            $this->users[$who] = $generator->create_user([
                'firstname' => 'Student',
                'lastname' => $surname,
                'email' => 'cr' . $who . '@example.com',
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

        // Student s2 shares; s3 is in no team and shares too, which is
        // the row the cohort-wide report and its export are built from.
        manager::set((int) $this->users['s2']->id, ['mobile' => self::NUMBER], $this->ingester());
        manager::set_consent((int) $this->users['s2']->id, true, (int) $this->users['s2']->id);
        manager::set((int) $this->users['s3']->id, ['mobile' => self::NUMBER], $this->ingester());
        manager::set_consent((int) $this->users['s3']->id, true, (int) $this->users['s3']->id);
    }

    /**
     * The actor an attribute WRITE has to be made as.
     *
     * attributes\manager::set() authorises the actor against
     * mod/selfselectadvanced:ingestattributes at system context. Every
     * question in this file is a READ-side one, so the fixture writes as
     * the one actor that legitimately holds that capability and leaves
     * $USER alone - which matters here, because half these tests turn
     * on who the viewer is.
     *
     * @return int the site administrator's user id
     */
    private function ingester(): int {
        return (int) get_admin()->id;
    }

    /**
     * The mobile cell of the group-page roster row for a surname.
     *
     * @param int $viewerid the viewing user
     * @param string $lastname the surname to find
     * @return string the mobile cell as the template would receive it
     */
    private function roster_mobile(int $viewerid, string $lastname): string {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/group.php', ['id' => $this->on->cm()->id]);
        $page = new \mod_selfselectadvanced\output\group_page(
            new api($this->on),
            groups::get($this->on, (int) $this->alpha->id),
            $viewerid
        );
        foreach ($page->export_for_template($PAGE->get_renderer('core'))->roster as $row) {
            if ($row->lastname === $lastname) {
                return (string) ($row->mobile ?? '');
            }
        }
        $this->fail('No roster row for ' . $lastname . ' (viewer ' . $viewerid . ')');
    }

    /**
     * The review page's attribute line for a surname.
     *
     * @param int $viewerid the viewing user
     * @param string $lastname the surname to find
     * @return string the attribute line
     */
    private function review_attrline(int $viewerid, string $lastname): string {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/review.php', ['id' => $this->on->cm()->id]);
        $page = new \mod_selfselectadvanced\output\review_page(
            new api($this->on),
            groups::get($this->on, (int) $this->alpha->id),
            $viewerid
        );
        foreach ($page->export_for_template($PAGE->get_renderer('core'))->roster as $row) {
            if (str_contains($row->fullname, $lastname)) {
                return (string) $row->attrline;
            }
        }
        $this->fail('No review row for ' . $lastname . ' (viewer ' . $viewerid . ')');
    }

    /**
     * P-1, EXAMINED AND DELIBERATELY NOT CHANGED: what a :manage holder
     * may see, and the four places this wave stopped it.
     *
     * The audit asked for the :manage arm of
     * contactprivacy::can_see_map() to be removed, so the phone
     * surfaces would answer the way the address surfaces do after
     * decision 24. It was implemented, and backed out, because
     * lang/en's shareconsentgranted tells the number's OWNER that
     * sharing reaches "your confirmed teammates, the guide assigned to
     * your team, a staff member handling a request you raised, AND THE
     * TEACHERS WHO MANAGE THIS ACTIVITY", and
     * tests/behat/attributes_admin.feature drives the real roster and
     * asserts an editing teacher reading a consented number. The
     * predicate is the code half of a sentence the plugin shows the
     * data subject; the two move together, and lang/ was not this
     * wave's to move.
     *
     * So this test pins the CONTRACT rather than the audit's verdict,
     * and pins BOTH halves of it, because either one alone rots:
     *  - the exempt viewer reads a CONSENTED number on the screen the
     *    promise is about;
     *  - the same viewer, in the same activity, at the same moment,
     *    gets no number out of the flagged-students EXPORT and no
     *    mobile column out of the site-wide DOWNLOAD, and reads no
     *    address anywhere. On-screen, paged, one activity at a time is
     *    the whole of the exemption.
     * A maintainer ruling on the manager archetype lands here.
     */
    public function test_what_the_manage_exemption_buys_and_what_it_does_not(): void {
        $this->resetAfterTest();
        $this->build_world();

        $managerid = (int) $this->users['manager']->id;
        $context = $this->on->context();

        // The fixture is only meaningful while all of these hold.
        $this->assertTrue(has_capability('mod/selfselectadvanced:manage', $context, $managerid));
        $this->assertTrue(
            has_capability('mod/selfselectadvanced:viewall', $context, $managerid),
            'without :viewall the roster would draw no mobile column and the assertion would be vacuous'
        );
        $this->assertTrue(contactprivacy::is_unrestricted($this->on, $managerid));
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:viewparticipantidentity', $context, $managerid),
            'no archetype holds the identity capability, so consent is the only thing in play'
        );

        // The promise: a CONSENTED number, on the roster.
        $this->assertSame(
            self::NUMBER,
            $this->roster_mobile($managerid, 'Two'),
            'shareconsentgranted names the managing teacher as an audience and the roster stopped honouring it'
        );

        // The boundary, part one: the flagged-students EXPORT. Same
        // viewer, same activity, a subject whose number they may read.
        $s3 = (int) $this->users['s3']->id;
        $attrs = manager::get_for_users([$s3]);
        $bypass = contactprivacy::mobile_consent_bypass(
            $this->on,
            $managerid,
            has_capability('mod/selfselectadvanced:viewparticipantidentity', $context, $managerid)
        );
        $privacymap = contactprivacy::can_see_map($this->on, $managerid, [$s3]);
        $showmobile = !empty($privacymap[$s3]) && manager::mobile_visible($attrs[$s3] ?? null, $bypass);
        $this->assertTrue($showmobile, 'the fixture is only meaningful while the SCREEN would show this number');
        $this->assertStringNotContainsString(
            self::NUMBER,
            manager::plain_line($attrs[$s3] ?? null, false),
            'the export is where an individually-permitted row becomes a bulk download'
        );

        // The boundary, part two: no address, and no address ORACLE,
        // for this viewer either - decision 24 has no exempt role.
        $gatekeeper = (new api($this->on))->gatekeeper();
        $group = groups::get($this->on, (int) $this->alpha->id);
        $this->assertSame(
            [],
            candidates::search($this->on, $group, $gatekeeper, $this->users['s3']->email, $managerid),
            'the exempt viewer used the picker as an address oracle'
        );
        foreach (candidates::search($this->on, $group, $gatekeeper, 'Three', $managerid) as $result) {
            $this->assertStringNotContainsString('@', $result['label']);
        }
    }

    /**
     * THE CONNECTION DESIGN IS INTACT: all three audiences, on the
     * surfaces they actually use, with the switch ON.
     *
     * This is the test that must fail if a later "hardening" pass
     * mistakes the cardinal rule for a role-level ban. Connection plus
     * consent IS the specification; what the rule forbids is bulk and
     * oracles, neither of which is any of the three reads below.
     */
    public function test_the_three_connections_still_reach_a_consenting_number(): void {
        global $DB;

        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $this->build_world();

        // Rule (a): a confirmed teammate, on the team roster.
        $this->assertSame(
            self::NUMBER,
            $this->roster_mobile((int) $this->users['s1']->id, 'Two'),
            'a confirmed teammate lost the number their teammate chose to share'
        );

        // Rule (b): the ASSIGNED guide, on the review page.
        $this->assertStringContainsString(
            self::NUMBER,
            $this->review_attrline((int) $this->users['guide']->id, 'Two'),
            "the team's own assigned guide lost a consented number"
        );

        // Rule (c): the coordinator holding the claim on that person's
        // ticket, on the ticket queue's requester line.
        $leader = (int) $this->users['s1']->id;
        $coordinator = (int) $this->users['coordinator']->id;
        manager::set($leader, ['mobile' => self::NUMBER], $this->ingester());
        manager::set_consent($leader, true, $leader);

        $DB->set_field('selfselectadvanced_group', 'state', state::FROZEN, ['id' => $this->alpha->id]);
        $DB->set_field('selfselectadvanced_group', 'timefrozen', time(), ['id' => $this->alpha->id]);
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
            'the fixture is only meaningful if an unclaimed ticket is not yet a connection'
        );

        tickets::claim($this->on, (int) $ticket->id, $coordinator);
        $claimed = tickets::requester_contact_map($this->on, $coordinator, [$leader]);
        $this->assertSame(
            self::NUMBER,
            $claimed[$leader]->mobile,
            'the claimant of a ticket lost the requester number the requester chose to share'
        );
        $this->assertObjectNotHasProperty('email', $claimed[$leader], 'and it is still never an address');
        $sink->close();
    }

    /**
     * P-2. The site-wide attributes listing does not put phone numbers
     * in a file.
     *
     * This table has no course and no activity scope: one download can
     * be every ingested row on the site. The column is built for the
     * screen and withheld from the download, and the download's SELECT
     * does not name a.mobile either - an unfetched column cannot be
     * printed by a later edit or iterated out of the record.
     */
    public function test_the_site_wide_attribute_download_carries_no_number(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $url = new \moodle_url('/mod/selfselectadvanced/attributes.php');

        $screen = new \mod_selfselectadvanced\table\attributes_table('ssaattrscreen', $url, false);
        $this->assertArrayHasKey('mobile', $screen->columns, 'the administrator still sees the row they are editing');
        $this->assertStringContainsString('a.mobile', $screen->sql->fields);
        $this->assertArrayHasKey('shareconsent', $screen->columns);

        $file = new \mod_selfselectadvanced\table\attributes_table('ssaattrfile', $url, true);
        $this->assertArrayNotHasKey('mobile', $file->columns, 'the site-wide download carried every mobile number');
        $this->assertStringNotContainsString(
            'a.mobile',
            $file->sql->fields,
            'the download still fetches the column it must not print'
        );
        // The consent STATE is a yes or a no, not a number, and a
        // consent audit is a legitimate site-wide question.
        $this->assertArrayHasKey('shareconsent', $file->columns);
        $this->assertSame(count($file->columns), count($file->headers), 'columns and headers must stay in step');
    }

    /**
     * P-4. The flagged-students export takes a literal false, so no
     * number can reach the spreadsheet even when the individual row
     * would have been permitted on screen.
     *
     * REACHABILITY, stated because it decides how serious this is. The
     * live path is the EXEMPT viewer: a :manage holder gets an all-true
     * map, so before this wave the flagged-students CSV handed them
     * every consenting groupless student's number in one file, for the
     * whole enrolled cohort - test
     * test_what_the_manage_exemption_buys_and_what_it_does_not drives
     * exactly that pair. For a viewer WITHOUT the exemption a groupless
     * student is nearly never a connection (rules (a) and (b) need a
     * confirmed membership, rule (c) needs a filed ticket, which needs
     * a team), and the one row that could carry a number is the
     * viewer's OWN, which is what this test drives. Both paths now end
     * at the same literal.
     *
     * The composition below is a replica - the page builds it inline
     * and then dies - so contactprivacy_test's 9c pins flagged.php's own
     * call sites as well. A negative control has to revert both.
     */
    public function test_the_flagged_export_never_carries_a_number(): void {
        $this->resetAfterTest();
        $this->build_world();

        $s3 = (int) $this->users['s3']->id;
        $attrs = manager::get_for_users([$s3]);
        $privacymap = contactprivacy::can_see_map($this->on, $s3, [$s3]);
        $showmobile = !empty($privacymap[$s3]) && manager::mobile_visible($attrs[$s3] ?? null, false);
        $this->assertTrue($showmobile, 'the fixture is only meaningful while the screen WOULD show this number');

        $this->assertStringContainsString(
            self::NUMBER,
            manager::display_line($attrs[$s3] ?? null, $showmobile),
            'the screen is unchanged: this row is permitted'
        );
        $this->assertStringNotContainsString(
            self::NUMBER,
            manager::plain_line($attrs[$s3] ?? null, false),
            'and the export of the same row carries no number'
        );
    }

    /**
     * P-5. The invitation picker is not an address oracle, and the
     * switch does not license one.
     *
     * The attacker named in the audit is the STUDENT LEADER: they hold
     * the picker, they can submit any string, and a "found / not found"
     * answer to a whole email address confirms which named account owns
     * that address. candidateaddress_test pins the staff ladder; this
     * pins the person the picker actually belongs to, in BOTH switch
     * states, and proves the picker still works by finding the same
     * person by name.
     */
    public function test_a_student_leader_cannot_probe_for_an_address(): void {
        $this->resetAfterTest();
        $this->build_world();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $leader = (int) $this->users['s1']->id;
        $needle = $this->users['s3']->email;

        $offgroup = $plugingen->create_group([
            'activityid' => $this->off->id(),
            'leaderid' => $leader,
            'name' => 'Legacy',
        ]);
        $ongroup = $plugingen->create_group([
            'activityid' => $this->on->id(),
            'leaderid' => (int) $this->users['s2']->id,
            'name' => 'Beta',
        ]);

        $cases = [
            'protection on' => [$this->on, $ongroup],
            'protection off' => [$this->off, $offgroup],
        ];
        foreach ($cases as $why => [$activity, $group]) {
            $gatekeeper = (new api($activity))->gatekeeper();
            $this->assertSame(
                [],
                candidates::search($activity, $group, $gatekeeper, $needle, $leader),
                "a student leader used the picker as an address oracle with $why"
            );
            $byname = candidates::search($activity, $group, $gatekeeper, 'Three', $leader);
            $this->assertCount(1, $byname, "the picker stopped finding people by name with $why");
            $this->assertStringNotContainsString(
                '@',
                $byname[0]['label'],
                "a student leader was labelled an address with $why"
            );
        }
    }

    /**
     * P-6. The eoilist drill-down offers no off-platform deep link, in
     * either switch state.
     *
     * The number itself still reaches a connected, consenting viewer -
     * asserted first, so this cannot pass on a page that stopped showing
     * anything. What is gone is the ACTION: a WhatsApp deep link hands
     * the digits to a third party the student never agreed to, off the
     * platform, where no consent flag and no connection map reaches, and
     * the maintainer's ruling was to regress to Moodle messaging only.
     * It used to be built whenever the number was visible and the
     * activity was NOT protecting contact details, which made a legacy
     * activity a licence to export a number off-site.
     *
     * The page script builds the row inline and then dies, so its source
     * is what is examined - the same idiom contactprivacy_test 9c uses,
     * and for the same reason. COMMENTS ARE STRIPPED FIRST: the reason a
     * deep link is forbidden is worth writing down in the file, and a
     * guard rail that fires on the explanation of its own rule would
     * quietly train the next reader to delete the explanation.
     */
    public function test_the_eoi_drill_down_offers_no_off_platform_link(): void {
        $this->resetAfterTest();
        $this->build_world();

        $this->assertStringContainsString(
            self::NUMBER,
            $this->review_attrline((int) $this->users['guide']->id, 'Two'),
            'the fixture is only meaningful while a connected, consenting read still works'
        );

        $code = $this->code_without_comments(__DIR__ . '/../eoilist.php');
        foreach (['wa.me', 'whatsapp', 'mailto:', 'tel:'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $code,
                'eoilist.php grew an off-platform contact affordance again: ' . $forbidden
            );
        }
        $this->assertStringContainsString(
            'staffmessage::url(',
            $code,
            'and the Moodle-messaging affordance that replaces it is still there'
        );
    }

    /**
     * A PHP file's executable source with every comment removed.
     *
     * @param string $path absolute path to the file
     * @return string the source, comments stripped
     */
    private function code_without_comments(string $path): string {
        $source = file_get_contents($path);
        $this->assertIsString($source, 'unreadable: ' . $path);

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

        return $code;
    }
}
