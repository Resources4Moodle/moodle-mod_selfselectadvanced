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

use mod_selfselectadvanced\local\attributes\csv_importer;
use mod_selfselectadvanced\local\coordinatorimport;
use mod_selfselectadvanced\local\coordinatorrole;

/**
 * What the staff IMPORT paths may do with an address (decision 24).
 *
 * Maintainer decision 24 removed the last address match from the two
 * DISCOVERY surfaces - the move form's participant picker and the
 * invitation candidate search - because a search that accepts an
 * address and answers with a name is an inverse contact lookup however
 * little it prints.
 *
 * The two IMPORT paths kept theirs, and the reasoning is recorded in
 * each class: the address there is SUPPLIED by the operator in a file
 * they wrote, resolved once by exact equality, in a bounded action
 * behind :manage or site administration. A decision is only worth the
 * test that holds it, so this file pins BOTH halves of it:
 *
 *  - the match still works, so the documented "username or email" input
 *    format is not quietly broken by a later tightening;
 *  - and no report either importer produces carries an address that the
 *    operator did not type into the file themselves. That is the
 *    property decision 24 actually protects here - the importers must
 *    not become the inverse lookup by the back door, answering "which
 *    account owns this address" with a name AND an address.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\coordinatorimport
 * @covers     \mod_selfselectadvanced\local\attributes\csv_importer
 */
final class importaddress_test extends \advanced_testcase {
    /**
     * A csv_import_reader over a string, as the upload pages build one.
     *
     * @param string $content the file
     * @param string $type the reader type key
     * @return \csv_import_reader the initialised reader
     */
    private function reader(string $content, string $type): \csv_import_reader {
        global $CFG;

        require_once($CFG->libdir . '/csvlib.class.php');
        $iid = \csv_import_reader::get_new_iid($type);
        $reader = new \csv_import_reader($iid, $type);
        $this->assertNotFalse($reader->load_csv_content($content, 'UTF-8', 'comma'));

        return $reader;
    }

    /**
     * Every string a coordinator-import report hands back.
     *
     * @param \stdClass $report what run() gave back
     * @return string the whole report, flattened
     */
    private function flatten_coordinator_report(\stdClass $report): string {
        $parts = [];
        foreach ($report->lines as $line) {
            $parts[] = (string) $line->line;
            $parts[] = (string) $line->who;
            $parts[] = (string) $line->outcome;
        }

        return implode("\n", $parts);
    }

    /**
     * The coordinator upload resolves an address and reports a NAME.
     *
     * The file names one person by address and one by username. Both
     * are appointed - the documented format still works - and the
     * report that comes back names them by full name and username,
     * carrying neither person's address: not the one the operator
     * supplied paired with an identity, and above all not the address
     * of the person they named by username, which the operator never
     * held.
     */
    public function test_the_coordinator_upload_reports_names_not_addresses(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'IMPADDR']);
        $activity = activity::from_instance(
            (int) $generator->create_module('selfselectadvanced', ['course' => $course->id])->id
        );
        coordinatorrole::ensure();

        $byaddress = $generator->create_user([
            'username' => 'nadia.named',
            'email' => 'nadia@example.com',
            'firstname' => 'Nadia',
            'lastname' => 'Named',
        ]);
        $byusername = $generator->create_user([
            'username' => 'ursula.user',
            'email' => 'ursula@example.com',
            'firstname' => 'Ursula',
            'lastname' => 'User',
        ]);
        $generator->enrol_user($byaddress->id, $course->id, 'teacher');
        $generator->enrol_user($byusername->id, $course->id, 'teacher');

        $report = coordinatorimport::run(
            $activity,
            $this->reader("# people\nnadia@example.com\nursula.user\n", 'mod_selfselectadvanced_addr'),
            coordinatorimport::MODE_ADD_REMOVE,
            true,
            (int) get_admin()->id
        );

        // The documented input format still works, both ways.
        $this->assertSame(2, $report->added, 'the email fallback key stopped resolving');
        $flat = $this->flatten_coordinator_report($report);
        $this->assertStringContainsString('Nadia Named (nadia.named)', $flat);
        $this->assertStringContainsString('Ursula User (ursula.user)', $flat);

        // And the report carries no address at all.
        $this->assertStringNotContainsString(
            'ursula@example.com',
            $flat,
            'the report disclosed the address of somebody the operator named by username'
        );
        $this->assertStringNotContainsString(
            'nadia@example.com',
            $flat,
            'a matched line must be reported by name and username, never by the key'
        );
    }

    /**
     * An address that names nobody is echoed back, and nothing else is.
     *
     * The operator typed it, so returning it tells them which line of
     * their own file failed; what must never happen is a NEAR match
     * turning into somebody else's identity.
     */
    public function test_an_unmatched_address_is_echoed_and_resolves_nobody(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['shortname' => 'IMPADDR2']);
        $activity = activity::from_instance(
            (int) $generator->create_module('selfselectadvanced', ['course' => $course->id])->id
        );
        coordinatorrole::ensure();

        $real = $generator->create_user([
            'username' => 'rita.real',
            'email' => 'rita@example.com',
            'firstname' => 'Rita',
            'lastname' => 'Real',
        ]);
        $generator->enrol_user($real->id, $course->id, 'teacher');

        // A PREFIX of a real address. The lookup is exact equality, not
        // a LIKE, so this must name nobody - otherwise the file becomes
        // a walkable oracle one character at a time.
        $report = coordinatorimport::run(
            $activity,
            $this->reader("# people\nrita@\n", 'mod_selfselectadvanced_addr2'),
            coordinatorimport::MODE_ADD_REMOVE,
            true,
            (int) get_admin()->id
        );

        $this->assertSame(0, $report->added);
        $this->assertSame(1, $report->skipped);
        $flat = $this->flatten_coordinator_report($report);
        $this->assertStringContainsString('rita@', $flat, 'the operator gets their own line back');
        $this->assertStringNotContainsString('Rita Real', $flat, 'a prefix resolved to a person');
        $this->assertStringNotContainsString('rita@example.com', $flat);
    }

    /**
     * The attribute ingest resolves an address and reports a USERNAME.
     *
     * Three rows: one keyed by username whose name columns disagree
     * with the account (the path that produces a warning ABOUT a
     * matched person), one keyed by address, and one keyed by an
     * address nobody owns. The warnings and rejections that come back
     * must name people by username and echo only what the file said.
     */
    public function test_the_attribute_ingest_reports_usernames_not_addresses(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $mismatched = $generator->create_user([
            'username' => 'alpha',
            'email' => 'alpha@example.com',
            'firstname' => 'Al',
            'lastname' => 'Pha',
        ]);
        $byaddress = $generator->create_user([
            'username' => 'beta',
            'email' => 'beta@example.com',
            'firstname' => 'Bea',
            'lastname' => 'Ta',
        ]);

        $csv = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number,Email\n"
            . "alpha,WRONG,NAME,Female,Civil,Structures,+91 11111 22222,\n"
            . ",,,Male,Mech,Design,9999988888,beta@example.com\n"
            . ",,,Male,Mech,Design,9999977777,ghost@example.com\n";

        $report = csv_importer::run(
            $this->reader($csv, 'mod_selfselectadvanced_attraddr'),
            (int) get_admin()->id,
            false
        );

        // The documented fallback key still resolves.
        $this->assertSame(3, $report->total);
        $this->assertCount(1, $report->rejected, 'only the address nobody owns should be rejected');
        $this->assertStringContainsString(
            'ghost@example.com',
            $report->rejected[0],
            'the operator gets their own key back'
        );

        $flat = implode("\n", array_merge($report->rejected, $report->warnings));
        $this->assertStringContainsString('alpha', $flat, 'the mismatch warning names the account by username');
        $this->assertStringNotContainsString(
            'alpha@example.com',
            $flat,
            'a row keyed by USERNAME must never draw the account address into the report'
        );
        $this->assertStringNotContainsString(
            'beta@example.com',
            $flat,
            'a row keyed by address is reported by username, never by the key'
        );
        unset($mismatched, $byaddress);
    }

    /**
     * Ambiguity names nobody.
     *
     * Two accounts sharing an address make that address a useless key,
     * and the importers say so instead of picking one - which would be
     * the worst possible answer to "who owns this address".
     */
    public function test_an_address_two_accounts_share_names_nobody(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $first = $generator->create_user(['username' => 'twin.one', 'email' => 'twins@example.com']);
        $second = $generator->create_user(['username' => 'twin.two']);
        // Core refuses a duplicate address through the generator on
        // some configurations, so it is written straight to the column
        // the lookup reads - which is the state a site with
        // allowaccountssameemail on actually has.
        $DB->set_field('user', 'email', 'twins@example.com', ['id' => $second->id]);

        $csv = "Username,First name,Last Name,Gender,Department,Sub-Department,Mobile Number,Email\n"
            . ",,,Male,Mech,Design,9999966666,twins@example.com\n";
        $report = csv_importer::run(
            $this->reader($csv, 'mod_selfselectadvanced_attrtwin'),
            (int) get_admin()->id,
            false
        );

        $this->assertCount(1, $report->rejected);
        $this->assertSame(0, $report->created + $report->updated);
        $this->assertStringNotContainsString('twin.one', $report->rejected[0]);
        $this->assertStringNotContainsString('twin.two', $report->rejected[0]);
        unset($first);
    }
}
