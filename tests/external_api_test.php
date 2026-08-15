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
use mod_selfselectadvanced\external\api_claim;
use mod_selfselectadvanced\external\api_escalate;
use mod_selfselectadvanced\external\api_get_ticket;
use mod_selfselectadvanced\external\api_list_kb;
use mod_selfselectadvanced\external\api_list_tickets;
use mod_selfselectadvanced\external\api_request_info;
use mod_selfselectadvanced\external\api_respond;
use mod_selfselectadvanced\external\api_search_kb;
use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * The LLM API (1.20.46): every endpoint proven at the SERVICE (direct
 * external calls, never through HTTP - the house idiom
 * external_search_test.php already uses for this plugin's other
 * external classes).
 *
 * The six BUILD-spec non-negotiables, each with its named test:
 * 1. test_no_close_endpoints
 * 2. PII absence - tests/external_api_pii_test.php (its own file, so a
 *    failure there can never be masked by transaction state this file's
 *    write-endpoint tests leave behind on PostgreSQL)
 * 3. test_list_tickets_happy_path / test_get_ticket_shows_role_labels_and_requester_identity
 * 4. every write endpoint below calls the existing service method and
 *    nothing else (read the source: no $DB->update/insert anywhere in
 *    classes/external/api_*.php - grep-level pin, test_no_db_writes_in_external_classes)
 * 5. test_claim_on_escalated_ticket_is_refused
 * 6. test_kb_endpoints_pin_export_entry_key_set_verbatim
 *
 * RED-FIRST EVIDENCE for non-negotiable A's "both, explicitly" rule
 * (captured 2026-08-15, PHPUnit run on m5pg against this same tree with
 * classes/local/llmapi.php's require_api_authority() temporarily
 * reduced BY HAND to `require_capability('mod/selfselectadvanced:api',
 * $activity->context(), $userid);` alone - the queue-authority line
 * removed - synced and run there; the change touched nothing else and
 * was undone immediately after capturing the failure, then re-applied
 * and re-verified green:
 *
 * 1) mod_selfselectadvanced\external_api_test::test_both_capabilities_required
 * Failed asserting that exception of type "required_capability_exception" is thrown.
 * FAILURES!
 * Tests: 1, Assertions: 4, Failures: 1, PHPUnit Deprecations: 1.
 *
 * Green again only after require_api_authority() regained its
 * tickets::require_queue_authority() call.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\llmapi
 * @covers     \mod_selfselectadvanced\local\tickets
 * @covers     \mod_selfselectadvanced\external\api_list_tickets
 * @covers     \mod_selfselectadvanced\external\api_get_ticket
 * @covers     \mod_selfselectadvanced\external\api_list_kb
 * @covers     \mod_selfselectadvanced\external\api_search_kb
 * @covers     \mod_selfselectadvanced\external\api_claim
 * @covers     \mod_selfselectadvanced\external\api_request_info
 * @covers     \mod_selfselectadvanced\external\api_respond
 * @covers     \mod_selfselectadvanced\external\api_escalate
 * @covers     \mod_selfselectadvanced\event\ticket_commented
 */
final class external_api_test extends \externallib_advanced_testcase {
    /**
     * A firm group (leader + guide), a manager, and a dedicated service
     * account holding :api + :coordinate - the shape README's
     * "Connecting an LLM" section describes.
     *
     * @return array [activity, group, guide, leader, manager, service account, course]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'LLMAPI1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $context = $activity->context();

        $guide = $generator->create_user(['firstname' => 'Gil', 'lastname' => 'Guide']);
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $leader = $generator->create_user(['firstname' => 'Leo', 'lastname' => 'Leader']);
        $generator->enrol_user($leader->id, $course->id, 'student');

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Alpha',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);

        $manager = $generator->create_user(['firstname' => 'Mona', 'lastname' => 'Manager']);
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');

        $service = $generator->create_user(['firstname' => 'Automated', 'lastname' => 'Assistant']);
        $generator->enrol_user($service->id, $course->id, 'student');
        $apirole = $generator->create_role();
        assign_capability('mod/selfselectadvanced:api', CAP_ALLOW, $apirole, $context->id, true);
        assign_capability('mod/selfselectadvanced:coordinate', CAP_ALLOW, $apirole, $context->id, true);
        role_assign($apirole, $service->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        return [$activity, $group, $guide, $leader, $manager, $service, $course];
    }

    /**
     * Expect one refusal string key from a callable - the
     * ticket_thread_test.php idiom, reused here.
     *
     * @param string $stringkey the expected errorcode
     * @param callable $fn the action
     */
    private function assert_refused(string $stringkey, callable $fn): void {
        try {
            $fn();
            $this->fail('Expected refusal ' . $stringkey);
        } catch (\moodle_exception $e) {
            $this->assertSame($stringkey, $e->errorcode);
        }
    }

    /**
     * NON-NEGOTIABLE 1: no _resolve/_decline endpoints exist anywhere -
     * neither as a declared web service function name nor as an
     * external class file.
     */
    public function test_no_close_endpoints(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);

        $source = file_get_contents($root . '/db/services.php');
        $this->assertIsString($source);
        // Every array key in db/services.php whose value opens with '['
        // - a function name (-> its definition block) or a service name
        // (-> its own block, which itself nests a 'functions' key the
        // same pattern also matches harmlessly) - never a scalar value
        // like a description string, which is exactly why this cannot
        // be a blunt whole-file substring search: this file's own
        // api_respond description PROSE says "There is no resolve or
        // decline endpoint", which a naive search would misread as one.
        preg_match_all("/'([a-z_]+)'\\s*=>\\s*\\[/", $source, $matches);
        $this->assertNotEmpty($matches[1], 'the scan must find at least the declared function names');
        foreach ($matches[1] as $key) {
            $this->assertStringNotContainsString('resolve', $key, "declared key '$key' must not resolve");
            $this->assertStringNotContainsString('decline', $key, "declared key '$key' must not decline");
        }

        $this->assertFileDoesNotExist($root . '/classes/external/api_resolve.php');
        $this->assertFileDoesNotExist($root . '/classes/external/api_decline.php');
    }

    /**
     * A source pin (non-negotiable 4: "no new state logic in the API
     * layer, ever" / "external classes contain no $DB->update/insert on
     * ticket tables") for every classes/external/api_*.php file.
     */
    public function test_no_db_writes_in_external_classes(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);
        $files = glob($root . '/classes/external/api_*.php');
        $this->assertCount(8, $files, 'expected exactly the eight LLM API endpoints');
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression(
                '/\$DB\s*->\s*(update_record|insert_record|update_record_raw|execute)\s*\(/',
                $source,
                basename($file) . ' must contain no direct database write'
            );
        }
    }

    /**
     * NON-NEGOTIABLE A / RED-FIRST PROOF 1: a user holding :api but
     * lacking coordinate/manage authority must be refused - the api
     * capability alone must not suffice. See this file's docblock for
     * the captured RED run.
     */
    public function test_both_capabilities_required(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , , , , $course] = $this->setup_world();
        $context = $activity->context();

        $apionly = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($apionly->id, $course->id, 'student');
        $apionlyrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/selfselectadvanced:api', CAP_ALLOW, $apionlyrole, $context->id, true);
        role_assign($apionlyrole, $apionly->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability('mod/selfselectadvanced:api', $context, $apionly->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:coordinate', $context, $apionly->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:manage', $context, $apionly->id));

        $this->setUser($apionly);
        $this->expectException(\required_capability_exception::class);
        api_list_tickets::execute($activity->cm()->id);
    }

    /**
     * A guest / no-token context is refused (validate_context() calls
     * require_login() internally - core_external\external_api).
     */
    public function test_no_token_context_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity] = $this->setup_world();

        $this->setGuestUser();
        $this->expectException(\moodle_exception::class);
        api_list_tickets::execute($activity->cm()->id);
    }

    /**
     * NON-NEGOTIABLE 3: list_tickets carries the requester's identity
     * (fullname + role), and the standard fields the queue shows.
     */
    public function test_list_tickets_happy_path(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, , , $service] = $this->setup_world();

        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->setUser($service);
        $result = api_list_tickets::execute($activity->cm()->id);
        $result = external_api::clean_returnvalue(api_list_tickets::execute_returns(), $result);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['tickets']);
        $row = $result['tickets'][0];
        $this->assertSame((int) $ticket->id, $row['id']);
        $this->assertSame(tickets::TYPE_COMPCHANGE, $row['type']);
        $this->assertSame(tickets::STATUS_OPEN, $row['status']);
        $this->assertFalse($row['escalated']);
        $this->assertSame('Alpha', $row['groupname']);
        $this->assertSame(fullname($guide), $row['requester']['fullname']);
        $this->assertSame('guide', $row['requester']['role']);
        $this->assertSame((int) $ticket->timecreated, $row['timerequested']);
        $this->assertSame(1, $row['position'], 'the sole open ticket must be first in the queue');
    }

    /**
     * NON-NEGOTIABLE 3 + B: get_ticket replaces actor identities with
     * ROLE LABELS throughout the trail, except the requester's own
     * identity (D-104's exception), and carries the previous-tickets
     * count.
     */
    public function test_get_ticket_shows_role_labels_and_requester_identity(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, , $manager, $service] = $this->setup_world();

        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        // The service account claims it (a :coordinate-level actor) and
        // asks a question; a human MANAGE holder's action would read as
        // "editing teacher" if one appeared, but only the service's own
        // action is exercised here.
        tickets::claim($activity, (int) $ticket->id, (int) $service->id);
        tickets::request_info($activity, (int) $ticket->id, 'Which subject?', FORMAT_PLAIN, (int) $service->id);

        $this->setUser($service);
        $result = api_get_ticket::execute((int) $ticket->id);
        $result = external_api::clean_returnvalue(api_get_ticket::execute_returns(), $result);

        $this->assertSame((int) $ticket->id, $result['id']);
        $this->assertSame(tickets::STATUS_NEEDSINFO, $result['status']);
        $this->assertSame(fullname($guide), $result['requester']['fullname']);
        $this->assertSame('guide', $result['requester']['role']);
        $this->assertSame('Need a specialist', $result['requesttext']);
        $this->assertSame(0, $result['previoustickets']['count']);
        $this->assertSame([], $result['previoustickets']['ids']);

        $byaction = [];
        foreach ($result['entries'] as $entry) {
            $byaction[$entry['action']] = $entry;
        }
        $this->assertArrayHasKey('filed', $byaction);
        $this->assertSame('requester', $byaction['filed']['actorrole'], "the ticket's own filer must read as 'requester'");
        $this->assertArrayHasKey('claimed', $byaction);
        $this->assertSame(
            'coordinator',
            $byaction['claimed']['actorrole'],
            'the service account holds :coordinate, not :manage, so it must read as coordinator'
        );
        $this->assertArrayHasKey('needsinfo', $byaction);
        $this->assertSame('coordinator', $byaction['needsinfo']['actorrole']);
        $this->assertSame('Which subject?', $byaction['needsinfo']['note']);

        // No staff NAME anywhere in the trail - only role labels and the
        // requester's own identity.
        $json = json_encode($result);
        $this->assertIsString($json);
        $this->assertStringNotContainsString(fullname($service), $json, 'the service account\'s own name must not appear');
        $this->assertStringNotContainsString(fullname($manager), $json, 'an uninvolved manager\'s name must not appear');
    }

    /**
     * NON-NEGOTIABLE 5: an escalated ticket refuses the machine's claim
     * exactly as it refuses a human coordinator - the SAME typed refusal
     * surfaces as a web service error, because api_claim is a thin
     * wrapper over tickets::claim() and nothing else.
     */
    public function test_claim_on_escalated_ticket_is_refused(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, , $manager, $service] = $this->setup_world();

        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        // A :manage holder may escalate an unclaimed ticket outright.
        tickets::escalate($activity, (int) $ticket->id, 'Needs senior judgement', FORMAT_PLAIN, (int) $manager->id);

        $this->setUser($service);
        $this->assert_refused('refusalticketescalated', fn() => api_claim::execute((int) $ticket->id));
    }

    /**
     * The write endpoints are thin wrappers, proven end to end: claim,
     * respond (a requester-visible trail row, event attribution under
     * the SERVICE ACCOUNT's own userid) and escalate.
     */
    public function test_claim_respond_and_escalate_end_to_end(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, , , $service] = $this->setup_world();

        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->setUser($service);
        $claimed = api_claim::execute((int) $ticket->id);
        $this->assertSame(tickets::STATUS_CLAIMED, $claimed['status']);
        $this->assertSame((int) $service->id, $claimed['claimedby']);

        $events = $this->redirectEvents();
        $responded = api_respond::execute((int) $ticket->id, 'Working on it - checking with the department.');
        $this->assertSame(
            tickets::STATUS_CLAIMED,
            $responded['status'],
            'respond must NOT close the ticket - read+respond only'
        );

        // Event attribution: the ticket_commented event's userid is the
        // SERVICE ACCOUNT's own id - the existing event bar makes this
        // free, exactly as design doc item 3 states.
        $commented = array_values(array_filter(
            $events->get_events(),
            static fn($e) => $e instanceof \mod_selfselectadvanced\event\ticket_commented
        ));
        $this->assertCount(1, $commented);
        $this->assertSame((int) $service->id, (int) $commented[0]->userid);
        $this->assertSame((int) $guide->id, (int) $commented[0]->relateduserid);
        $this->assertSame('commented', $commented[0]->other['action']);

        // The reply is a requester-visible trail row: present on the
        // ANONYMISED (withactors=false) trail the requester's own thread
        // reads from, note text intact.
        $requesterview = array_values(tickets::trail($activity, (int) $ticket->id, false));
        $lastentry = end($requesterview);
        $this->assertSame('commented', $lastentry->action);
        $this->assertSame('Working on it - checking with the department.', $lastentry->note);
        $this->assertObjectNotHasProperty(
            'actorid',
            $lastentry,
            'the requester view must never carry actor identity, machine posts included'
        );

        $escalated = api_escalate::execute((int) $ticket->id, 'Needs a policy decision above me');
        $this->assertTrue($escalated['escalated']);
        // The service account holds :coordinate, not :manage, so
        // escalating its OWN claim releases it - the same rule that
        // applies to any mere coordinator (tickets::escalate() docblock).
        $this->assertSame(tickets::STATUS_OPEN, $escalated['status']);
        $this->assertSame(0, $escalated['claimedby']);
    }

    /**
     * api_request_info is a thin wrapper too: the ticket moves to
     * needsinfo and the question is on the row.
     */
    public function test_request_info_happy_path(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, , , $service] = $this->setup_world();

        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->setUser($service);
        api_claim::execute((int) $ticket->id);
        $result = api_request_info::execute((int) $ticket->id, 'Which subject area?');

        $this->assertSame(tickets::STATUS_NEEDSINFO, $result['status']);
        $fresh = tickets::get($activity, (int) $ticket->id);
        $this->assertSame(tickets::STATUS_NEEDSINFO, $fresh->status);
    }

    /**
     * A PARAM-type regression: tickets::ACTION_PUBLISHED_FAQ is
     * 'published_faq', the one tickets::ACTION_* value with an
     * underscore - PARAM_ALPHA on the entries[].action field would
     * silently STRIP it under external_api::clean_returnvalue(), which
     * a fixture using only alpha-only actions (claimed, commented,
     * needsinfo...) could never catch. Proven end to end: resolve, then
     * publish to the knowledgebank (1.20.45), then read the thread back
     * through the API exactly as a real caller would - via
     * clean_returnvalue(), not the raw execute() array.
     */
    public function test_get_ticket_returns_published_faq_action_intact_through_clean_returnvalue(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, $guide, , $manager, $service] = $this->setup_world();

        $this->setUser($guide);
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Need a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Added a specialist.',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        kb::publish_from_ticket($activity, (int) $ticket->id, (int) $manager->id, [
            'title' => 'Composition change help',
            'question' => 'Can we ask for a specialist?',
            'answer' => 'Yes - file a composition-change request.',
            'tickettype' => tickets::TYPE_COMPCHANGE,
            'keywords' => 'specialist',
            'published' => 1,
        ]);

        $this->setUser($service);
        $result = api_get_ticket::execute((int) $ticket->id);
        $result = external_api::clean_returnvalue(api_get_ticket::execute_returns(), $result);

        $actions = array_column($result['entries'], 'action');
        $this->assertContains(
            'published_faq',
            $actions,
            'PARAM_ALPHA on the action field would have silently stripped this value\'s underscore'
        );
    }

    /**
     * NON-NEGOTIABLE 6: _list_kb/_search_kb return kb::export_entry()'s
     * exact key set VERBATIM - pinned against the landed serialiser
     * itself, not a guess at its shape.
     */
    public function test_kb_endpoints_pin_export_entry_key_set_verbatim(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , , $manager, $service] = $this->setup_world();

        $this->setUser($manager);
        $row = kb::create($activity, (int) $manager->id, [
            'title' => 'How do I ask for a specialist?',
            'question' => 'Can our group ask for a subject specialist?',
            'answer' => '<p>Yes - file a composition-change request.</p>',
            'tickettype' => tickets::TYPE_COMPCHANGE,
            'keywords' => 'specialist, compchange',
            'published' => 1,
        ]);
        $expected = kb::export_entry($row);

        $this->setUser($service);

        $listed = api_list_kb::execute($activity->cm()->id);
        $listed = external_api::clean_returnvalue(api_list_kb::execute_returns(), $listed);
        $this->assertCount(1, $listed['entries']);
        $this->assertSame(
            array_keys($expected),
            array_keys($listed['entries'][0]),
            'api_list_kb must return export_entry()\'s exact key set, in the same order'
        );
        $this->assertSame($expected, $listed['entries'][0]);
        $this->assertArrayNotHasKey('sourceticketid', $listed['entries'][0]);

        $searched = api_search_kb::execute($activity->cm()->id, 'specialist');
        $searched = external_api::clean_returnvalue(api_search_kb::execute_returns(), $searched);
        $this->assertCount(1, $searched['entries']);
        $this->assertSame(array_keys($expected), array_keys($searched['entries'][0]));
        $this->assertSame($expected, $searched['entries'][0]);

        // An unpublished entry must never surface through either
        // endpoint - "published entries only" (BUILD spec B).
        $hidden = kb::create($activity, (int) $manager->id, [
            'title' => 'Draft, not ready',
            'question' => 'Q',
            'answer' => 'A',
            'tickettype' => tickets::TYPE_COMPCHANGE,
            'keywords' => 'draft',
            'published' => 0,
        ]);
        unset($hidden);
        $listedagain = api_list_kb::execute($activity->cm()->id);
        $this->assertCount(1, $listedagain['entries'], 'an unpublished entry must not appear in the machine\'s list');
    }
}
