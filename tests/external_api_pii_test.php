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

use mod_selfselectadvanced\external\api_get_ticket;
use mod_selfselectadvanced\external\api_list_kb;
use mod_selfselectadvanced\external\api_list_tickets;
use mod_selfselectadvanced\external\api_search_kb;
use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * NON-NEGOTIABLE 2, in its own file (not tests/external_api_test.php):
 * fixture users with KNOWN email and phone values, driving every read
 * endpoint the LLM API exposes, asserting the serialised JSON contains
 * NEITHER string - exact-match on the fixture values, never a regex
 * guess at what an address or a number might look like. A dedicated
 * file, not a method alongside the write-endpoint tests, so a failure
 * here is never entangled with - or masked by - the PostgreSQL
 * transaction state a refusal-path test in that file can leave behind
 * (house rule: split negative/positive controls apart).
 *
 * Every plausible "phone" surface this plugin has is covered: the CORE
 * user table's phone1 column (queried nowhere in this API, but
 * fixture-pinned rather than assumed) AND the plugin's OWN mobile
 * attribute (selfselectadvanced_userattr.mobile, the field the cardinal
 * contact-privacy rule actually governs elsewhere in this plugin).
 *
 * RED-FIRST EVIDENCE (captured 2026-08-15, PHPUnit run on m5pg against
 * this same tree with classes/local/llmapi.php's requester_identity()
 * temporarily widened BY HAND to add `'email' =>
 * \core_user::get_user((int) $ticket->requestedby)->email,` to its
 * returned array - the one line - synced and run there; the change
 * touched nothing else and was undone immediately after capturing the
 * failure, then re-applied and re-verified green:
 *
 * 1) mod_selfselectadvanced\external_api_pii_test::test_pii_absent_from_every_read_payload
 * list_tickets leaked the fixture email
 * Failed asserting that '{"tickets":[{"id":595000,"type":"compchange","status":"open",
 * "escalated":false,"groupname":"PII Group","requester":{"fullname":"Priya Guide",
 * "role":"guide","email":"pii-leak-check.guide(at)example.com"},"timerequested":1786784202,
 * "position":1}],"total":1}' [ASCII](length: 252) does not contain
 * "pii-leak-check.guide(at)example.com" [ASCII](length: 32).
 * FAILURES!
 * Tests: 1, Assertions: 2, Failures: 1, PHPUnit Deprecations: 1.
 * ("(at)" above stands in for the literal at-sign the real terminal
 * output used - written that way only because phpDocumentor's
 * inline-tag parser misreads an at-sign directly followed by the word
 * "example" as a tag, even with no braces around it, which
 * local_moodlecheck's phpdoc check then flags; the actual assertion in
 * test_pii_absent_from_every_read_payload() below uses the real
 * character throughout.)
 *
 * Green again only after requester_identity() returned to exactly
 * {fullname, role} - no email key at all.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\llmapi
 * @covers     \mod_selfselectadvanced\external\api_list_tickets
 * @covers     \mod_selfselectadvanced\external\api_get_ticket
 * @covers     \mod_selfselectadvanced\external\api_list_kb
 * @covers     \mod_selfselectadvanced\external\api_search_kb
 */
final class external_api_pii_test extends \externallib_advanced_testcase {
    /**
     * Every read endpoint carries the requester's fullname (D-104) but
     * never their email or phone (core user.phone1, or this plugin's
     * own mobile attribute) - proven with fixture values distinctive
     * enough that a stray substring match cannot be mistaken for a false
     * positive.
     */
    public function test_pii_absent_from_every_read_payload(): void {
        $this->resetAfterTest();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'PIIAPI1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $context = $activity->context();

        $knownemail = 'pii-leak-check.guide@example.com';
        $knownphone = '+19995550001';
        $knownmobile = '+19995550002';

        $guide = $generator->create_user([
            'firstname' => 'Priya',
            'lastname' => 'Guide',
            'email' => $knownemail,
            'phone1' => $knownphone,
        ]);
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $plugingen->create_userattr(['userid' => (int) $guide->id, 'mobile' => $knownmobile, 'shareconsent' => 1]);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'PII Group',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);

        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        $service = $generator->create_user();
        $generator->enrol_user($service->id, $course->id, 'student');
        $apirole = $generator->create_role();
        assign_capability('mod/selfselectadvanced:api', CAP_ALLOW, $apirole, $context->id, true);
        assign_capability('mod/selfselectadvanced:coordinate', CAP_ALLOW, $apirole, $context->id, true);
        role_assign($apirole, $service->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->setUser($manager);
        kb::create($activity, (int) $manager->id, [
            'title' => 'Composition change FAQ',
            'question' => 'How do I ask for a specialist?',
            'answer' => 'File a composition-change request from the group page.',
            'tickettype' => tickets::TYPE_COMPCHANGE,
            'keywords' => 'faq, specialist',
            'published' => 1,
        ]);

        $this->setUser($service);
        $cmid = $activity->cm()->id;

        $payloads = [
            'list_tickets' => api_list_tickets::execute($cmid),
            'get_ticket' => api_get_ticket::execute((int) $ticket->id),
            'list_kb' => api_list_kb::execute($cmid),
            'search_kb' => api_search_kb::execute($cmid, 'specialist'),
        ];

        foreach ($payloads as $endpoint => $payload) {
            $json = json_encode($payload);
            $this->assertIsString($json);
            $this->assertStringNotContainsString($knownemail, $json, "$endpoint leaked the fixture email");
            $this->assertStringNotContainsString($knownphone, $json, "$endpoint leaked the fixture phone1");
            $this->assertStringNotContainsString($knownmobile, $json, "$endpoint leaked the fixture mobile attribute");
        }

        // NOT A VACUOUS PASS: the requester's identity IS present
        // (D-104), which proves the absence above is not merely an
        // artefact of the payload carrying no identity at all.
        $listedjson = json_encode($payloads['list_tickets']);
        $this->assertIsString($listedjson);
        $this->assertStringContainsString(fullname($guide), $listedjson);
        $gotjson = json_encode($payloads['get_ticket']);
        $this->assertIsString($gotjson);
        $this->assertStringContainsString(fullname($guide), $gotjson);
    }
}
