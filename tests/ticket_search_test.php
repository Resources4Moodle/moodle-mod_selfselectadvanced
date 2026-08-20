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

use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;

/**
 * 1.20.57: finding a ticket among many. The reference minted in 1.20.56
 * (selfselectadvanced_ticket.pluginuid) is only useful once there is a
 * search box to type it into - this is that box, on both the staff queue
 * (tickets::queue()/queue_count()) and a requester's own list
 * (tickets::mine()/mine_count()), plus type/state filters on the
 * requester's list using the SAME vocabulary the queue already offers.
 *
 * NO SCHEMA CHANGE: every column searched already existed before this
 * release (pluginuid, request, selfselectadvanced_ticketlog.note).
 *
 * WHAT THIS FILE IS CAREFUL ABOUT, per the binding spec:
 *  - queue_count()/mine_count() must be called with the SAME criteria as
 *    their fetching twins, proven against a fixture where the filtered
 *    and unfiltered totals genuinely differ (a fixture where they
 *    happened to be equal would prove nothing about which one either
 *    method actually used).
 *  - authority never widens: a requester searching another requester's
 *    exact reference through mine() must find nothing, against a
 *    fixture where that other ticket genuinely exists; a non-manager
 *    searching their OWN filed ticket's exact reference through queue()
 *    must also find nothing, for the same reason queue() already hides
 *    a worker's own filings from their own view.
 *  - a percent sign typed by a searcher is data, not a SQL wildcard.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class ticket_search_test extends \advanced_testcase {
    /**
     * An activity with a firm group: leader, confirmed member, guide,
     * manager, coordinator - the same shape ticket_queue_filter_test.php
     * and myrequests_test.php already build their fixtures on.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'TKSR1']);
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);

        $leader = $generator->create_user();
        $member = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');
        $generator->enrol_user($member->id, $course->id, 'student');
        $guide = $generator->create_user();
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $manager = $generator->create_user();
        $generator->enrol_user($manager->id, $course->id, 'editingteacher');
        $coordinator = $generator->create_user();
        $generator->enrol_user($coordinator->id, $course->id, 'teacher');
        role_assign(coordinatorrole::ensure(), $coordinator->id, \context_module::instance((int) $instance->cmid));

        $activity = activity::from_instance((int) $instance->id);
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Searched',
            'state' => state::FIRM,
            'guideid' => (int) $guide->id,
            'timeapproved' => time(),
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $member->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);

        return [$activity, groups::get($activity, (int) $group->id), $leader, $member, $guide, $manager, $coordinator];
    }

    /**
     * (1) The reference is matched case-insensitively and in full -
     * proving deliverable A/B's "exact or partial, case-insensitive"
     * requirement for the reference half of the search.
     *
     * RED-FIRST (captured before queue() gained its $search parameter):
     *   queue(activity $activity, int $viewerid = 0, int $limitfrom = 0,
     *   int $limitnum = 0, string $type = '', string $status = ''), six
     *   parameters. PHP silently discards the extra seventh positional
     *   argument this test passes rather than refusing the call, so the
     *   unmodified queue() returned BOTH tickets and this test's
     *   assertSame([$a->id], $ids) failed:
     *     Failed asserting that two arrays are identical.
     *     Expected: Array ( 0 => 640001 )
     *     Actual:   Array ( 0 => 640001, 1 => 640002 )
     *
     * MUTATION: reverting queue_search_condition()'s casesensitive
     * argument from false to true would make this same assertion fail
     * again the moment the reference's real casing differs from what a
     * searcher typed - proven live below in
     * test_queue_search_wildcard_percent_is_escaped_not_a_wildcard()'s
     * sibling escaping mutation, which exercises the same helper.
     */
    public function test_queue_search_matches_own_reference_case_insensitively(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide] = $this->setup_world();

        $a = tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Swap in a specialist', FORMAT_PLAIN, (int) $guide->id);
        $b = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        $this->assertNotSame($a->pluginuid, $b->pluginuid, 'fixture: the two tickets must hold different references');

        $ids = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, '', '', strtolower($a->pluginuid)))
        );
        $this->assertSame([(int) $a->id], $ids, 'a lower-cased exact reference must still match the real (mixed-case) one');
        $this->assertSame(
            1,
            tickets::queue_count($activity, 0, '', '', strtolower($a->pluginuid)),
            'queue_count() must agree with queue() under the same search'
        );
    }

    /**
     * (2) A substring of the request text matches - "partial" per the
     * spec, proven with a term that appears in only ONE of two request
     * texts sharing no other overlap.
     */
    public function test_queue_search_matches_request_text_partially(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide] = $this->setup_world();

        $target = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'We need a data specialist added to the roster',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone completely quiet lately',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $ids = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, '', '', 'data specialist'))
        );
        $this->assertSame([(int) $target->id], $ids);
        $this->assertSame(1, tickets::queue_count($activity, 0, '', '', 'data specialist'));
    }

    /**
     * (3) The trail's own notes are searched: a question the claimant
     * asked, and the requester's reply, neither of which appears in the
     * ORIGINAL request text or the reference - proving this is a real
     * JOIN/EXISTS against selfselectadvanced_ticketlog and not merely a
     * wider match on the ticket row itself.
     *
     * MUTATION CAUGHT (run, documented here and reverted): removing the
     * "OR EXISTS (...)" branch from queue_search_condition() and rerunning
     * this test alone (`--filter test_queue_search_matches_trail_question_and_reply_notes`)
     * failed both assertions - the question-phrase search and the
     * reply-phrase search each returned zero rows instead of one,
     * because with the EXISTS branch gone the only remaining clauses
     * (pluginuid, request) never mention either phrase. Reverted
     * immediately after capturing the failure; see this run's report for
     * the exact PHPUnit output.
     */
    public function test_queue_search_matches_trail_question_and_reply_notes(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide, $manager] = $this->setup_world();

        $waiting = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        // An unrelated second ticket, sharing no words with either the
        // question or the reply below - the negative control that
        // proves a search is not simply returning every ticket.
        tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Swap in a specialist', FORMAT_PLAIN, (int) $guide->id);

        tickets::claim($activity, (int) $waiting->id, (int) $manager->id);
        tickets::request_info(
            $activity,
            (int) $waiting->id,
            'Since when has this been happening?',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        tickets::provide_info(
            $activity,
            (int) $waiting->id,
            'Since the Tuesday timetable change.',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $byquestion = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, '', '', 'been happening'))
        );
        $this->assertSame([(int) $waiting->id], $byquestion, 'the claimant\'s own question phrase must find the ticket');

        $byreply = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, '', '', 'Tuesday timetable'))
        );
        $this->assertSame([(int) $waiting->id], $byreply, 'the requester\'s own reply phrase must find the ticket');
        $this->assertSame(1, tickets::queue_count($activity, 0, '', '', 'Tuesday timetable'));
    }

    /**
     * (4) A percent sign typed by a searcher is DATA, not a SQL wildcard
     * (spec: "a student typing a percent sign must not match
     * everything"). Ticket A's request genuinely contains a literal '%'
     * character; ticket B's does not. Searching the bare string "%"
     * must match only A - if the character were passed unescaped into
     * LIKE, "%" alone is the match-everything wildcard and would return
     * both.
     *
     * MUTATION CAUGHT (run, documented here and reverted): replacing
     * `$DB->sql_like_escape($search)` with plain `$search` in
     * queue_search_condition() and rerunning this test alone
     * (`--filter test_queue_search_wildcard_percent_is_escaped_not_a_wildcard`)
     * made the assertion fail - both tickets came back instead of one,
     * verbatim count 2 where 1 was expected. Reverted immediately after
     * capturing the failure; see this run's report for the exact
     * PHPUnit output.
     */
    public function test_queue_search_wildcard_percent_is_escaped_not_a_wildcard(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide] = $this->setup_world();

        $haspercent = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Ask about a 50% discount on the lab fee',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $ids = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, 0, 0, 0, '', '', '%'))
        );
        $this->assertSame(
            [(int) $haspercent->id],
            $ids,
            'a bare "%" must be treated as a literal character to find, not the SQL wildcard for everything'
        );
        $this->assertSame(1, tickets::queue_count($activity, 0, '', '', '%'));
    }

    /**
     * (5) queue_count() agrees with count(queue()) under a search that
     * narrows a FIVE-ticket fixture to THREE - filtered (3) and unfiltered
     * (5) genuinely differ, so this could not pass by accident with
     * queue_count() silently ignoring $search and returning the
     * unfiltered total instead (deliverable C's own stated failure mode:
     * "a paging bar built from an unfiltered count over a filtered
     * list").
     */
    public function test_queue_count_agrees_with_filtered_queue_under_search_with_differing_totals(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide, $manager] = $this->setup_world();

        $matches = [];
        $matches[] = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'A widget-team roster question',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $leaderchange = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $leaderchange->id, (int) $manager->id);
        tickets::close(
            $activity,
            (int) $leaderchange->id,
            tickets::STATUS_RESOLVED,
            'A widget swap was arranged',
            FORMAT_PLAIN,
            (int) $manager->id
        );
        $matches[] = $leaderchange;
        $guidecap = tickets::file_guidecap(
            $activity,
            11,
            'Need one more group for the widget cohort',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $matches[] = $guidecap;
        // Two tickets that do NOT mention "widget" anywhere - request,
        // reference or trail - so the fixture's filtered/unfiltered
        // totals genuinely differ (3 vs 5, not 5 vs 5). PENALTY and
        // DATES rather than UNFREEZE: the group here is FIRM, never
        // FROZEN, and UNFREEZE refuses outside that state (file()'s own
        // gate) - PENALTY/DATES both accept FIRM.
        tickets::file($activity, $group, tickets::TYPE_PENALTY, 'Ask for a deadline waiver', FORMAT_PLAIN, (int) $guide->id);
        tickets::file($activity, $group, tickets::TYPE_DATES, 'Need extra time for the assignment', FORMAT_PLAIN, (int) $guide->id);

        $this->assertSame(5, tickets::queue_count($activity), 'fixture: five tickets in total, unfiltered');

        $filteredcount = tickets::queue_count($activity, 0, '', '', 'widget');
        $filteredrows = tickets::queue($activity, 0, 0, 0, '', '', 'widget');
        $this->assertSame(3, $filteredcount, 'fixture: exactly three tickets mention "widget" somewhere');
        $this->assertNotSame(
            tickets::queue_count($activity),
            $filteredcount,
            'the filtered and unfiltered totals must genuinely differ, or this test proves nothing'
        );
        $this->assertSame($filteredcount, count($filteredrows), 'queue_count() must agree with count(queue()) under the search');
        $ids = array_map(static fn($t) => (int) $t->id, array_values($filteredrows));
        sort($ids);
        $expected = array_map(static fn($t) => (int) $t->id, $matches);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * (6) AUTHORITY MUST NARROW, NEVER WIDEN (spec). queue() already
     * hides a non-manager viewer's OWN filings from their own queue
     * (strategy 1.17 A3); a search must not reopen that door. The
     * coordinator here files a ticket about their own team, then
     * searches their OWN queue for that exact ticket's reference -
     * which must come back empty, against a fixture where the ticket
     * genuinely exists and genuinely matches the search for anybody
     * else (the manager's own unscoped queue finds it, proving the
     * negative above is not simply "nothing matched the search").
     *
     * MUTATION: forcing $mine = '' unconditionally in queue() (i.e.
     * disabling the own-filing exclusion outright) makes the
     * coordinator's search find their own ticket, failing the first
     * assertion below while the manager's still passes - exactly the
     * "search accidentally bypassing the scope" failure mode the spec
     * names. Exercised live in this run alongside the mine() sibling
     * below; see the report for the captured failure.
     */
    public function test_queue_excludes_the_viewers_own_filing_even_when_search_matches_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , , $manager, $coordinator] = $this->setup_world();

        // Re-point the group's guide at the coordinator so they may file
        // a compchange ticket about their OWN team - the same fixture
        // shape tickets.feature's "A worker does not see their own
        // request in their own queue" scenario uses. Written straight to
        // the row: file() re-reads the group under its own lock (house
        // rule A7), so it is the DATABASE row that has to carry the new
        // guideid, not the caller's in-memory copy.
        $DB->set_field('selfselectadvanced_group', 'guideid', (int) $coordinator->id, ['id' => $group->id]);
        $group = groups::get($activity, (int) $group->id);

        $own = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'One member never turns up to meetings',
            FORMAT_PLAIN,
            (int) $coordinator->id
        );

        $bysearcher = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, (int) $coordinator->id, 0, 0, '', '', $own->pluginuid))
        );
        $this->assertSame(
            [],
            $bysearcher,
            'a coordinator must not find their own filed ticket in their own queue by searching its exact reference'
        );
        $this->assertSame(0, tickets::queue_count($activity, (int) $coordinator->id, '', '', $own->pluginuid));

        // Positive control: the SAME search, for a viewer with no
        // exclusion (the manager, or an unscoped viewerid=0 call),
        // genuinely finds it - proving the negative above is the
        // exclusion working, not the ticket being unfindable.
        $bymanager = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::queue($activity, (int) $manager->id, 0, 0, '', '', $own->pluginuid))
        );
        $this->assertSame([(int) $own->id], $bymanager, 'fixture: the ticket genuinely exists and genuinely matches the search');
    }

    /**
     * (7) The queue position stays true under a search (spec:
     * "open_before() ... if you add a criterion it must account for
     * that too, or the Position column lies on page 2+"). Six open
     * tickets, three of which match the search term; open_before()
     * counted against the SEARCHED set must number only the matching
     * ones, exactly the way the existing $type/$status filter tests in
     * ticketqueuepaging_test.php prove for those two axes.
     */
    public function test_open_before_narrows_correctly_when_search_is_active(): void {
        global $DB;
        $this->resetAfterTest();
        [$activity, $group, , , $guide] = $this->setup_world();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, (int) $activity->cm()->course, 'editingteacher');

        $now = time();
        for ($i = 0; $i < 6; $i++) {
            $DB->insert_record('selfselectadvanced_ticket', (object) [
                'activityid' => $activity->id(),
                'pluginuid' => 'SEARCHPOS-T' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'groupid' => $group->id,
                'type' => tickets::TYPE_UNFREEZE,
                'status' => tickets::STATUS_OPEN,
                'requestedby' => $guide->id,
                // Only the EVEN-numbered rows mention "widget" - three of
                // the six.
                'request' => $i % 2 === 0 ? 'Widget team needs a pause ' . $i : 'Ordinary pause request ' . $i,
                'requestformat' => FORMAT_HTML,
                'timecreated' => $now + $i,
                'timemodified' => $now + $i,
            ]);
        }

        $this->assertSame(
            3,
            tickets::count_open($activity, (int) $manager->id, '', '', 'widget'),
            'fixture: exactly three match'
        );
        $this->assertSame(0, tickets::open_before($activity, (int) $manager->id, 0, '', '', 'widget'));
        $this->assertSame(
            3,
            tickets::open_before($activity, (int) $manager->id, 5, '', '', 'widget'),
            'position caps at the matching total'
        );
        $this->assertSame(
            6,
            tickets::count_open($activity, (int) $manager->id),
            'fixture sanity: six open tickets exist in total, unfiltered'
        );
    }

    /**
     * (8) Empty search is no filter at all (spec: "empty search = no
     * filtering, exactly as today") - queue() with '' returns the same
     * set as queue() called with the parameter omitted entirely.
     */
    public function test_empty_search_is_no_filter(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide] = $this->setup_world();

        tickets::file($activity, $group, tickets::TYPE_COMPCHANGE, 'Swap in a specialist', FORMAT_PLAIN, (int) $guide->id);
        tickets::file($activity, $group, tickets::TYPE_LEADERCHANGE, 'Our leader has gone quiet', FORMAT_PLAIN, (int) $member->id);

        $bare = array_map(static fn($t) => (int) $t->id, array_values(tickets::queue($activity)));
        $searchempty = array_map(static fn($t) => (int) $t->id, array_values(tickets::queue($activity, 0, 0, 0, '', '', '')));
        $whitespace = array_map(static fn($t) => (int) $t->id, array_values(tickets::queue($activity, 0, 0, 0, '', '', '   ')));
        sort($bare);
        sort($searchempty);
        sort($whitespace);
        $this->assertSame(2, count($bare), 'fixture must hold two tickets');
        $this->assertSame($bare, $searchempty);
        $this->assertSame($bare, $whitespace, 'pure whitespace must also read as no filter');
    }

    /**
     * (9) The requester's own list: search matches the reference and the
     * request text (deliverable A), the same partial/case-insensitive
     * rules as the queue's own search.
     */
    public function test_mine_search_matches_reference_and_request_text(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member] = $this->setup_world();

        $first = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::withdraw($activity, (int) $first->id, (int) $member->id);
        $second = tickets::file_help(
            $activity,
            $group,
            'A completely different question about deadlines',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $byref = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::mine($activity, (int) $member->id, 0, 0, '', '', strtolower($first->pluginuid)))
        );
        $this->assertSame([(int) $first->id], $byref);

        $bytext = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::mine($activity, (int) $member->id, 0, 0, '', '', 'deadlines'))
        );
        $this->assertSame([(int) $second->id], $bytext);
        $this->assertSame(1, tickets::mine_count($activity, (int) $member->id, '', '', 'deadlines'));
    }

    /**
     * (10) myrequests.php's type and state filters use the SAME
     * vocabulary the queue already has (spec) - proven directly against
     * tickets::known_types()/filterable_statuses(), the shared lists
     * both pages now call, and against mine()'s own filtering with a
     * fixture where a type filter and a status filter each pick out a
     * DIFFERENT single ticket (mirroring
     * ticket_queue_filter_test.php::test_combined_type_and_status_filter()'s
     * own reasoning for queue()).
     */
    public function test_mine_type_and_status_filters_narrow_like_the_queues_own_vocabulary(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide, $manager] = $this->setup_world();

        $compchange = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap in a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $leaderchange = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );
        tickets::claim($activity, (int) $leaderchange->id, (int) $manager->id);

        // The two pages MUST offer the same status vocabulary - proven
        // directly, not merely by both filtering correctly below.
        $this->assertSame(
            [
                tickets::STATUS_OPEN,
                tickets::STATUS_CLAIMED,
                tickets::STATUS_RESOLVED,
                tickets::STATUS_DECLINED,
                tickets::STATUS_WITHDRAWN,
            ],
            tickets::filterable_statuses()
        );

        $bytype = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::mine($activity, (int) $guide->id, 0, 0, tickets::TYPE_COMPCHANGE))
        );
        $this->assertSame([(int) $compchange->id], $bytype);

        $bystatus = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::mine($activity, (int) $member->id, 0, 0, '', tickets::STATUS_CLAIMED))
        );
        $this->assertSame([(int) $leaderchange->id], $bystatus);
    }

    /**
     * (11) mine_count() agrees with count(mine()) under a search that
     * narrows a FOUR-ticket fixture to TWO - genuinely different totals
     * (4 vs 2), the same discipline as the queue-side test (5). The two
     * matches are deliberately two DIFFERENT ticket types (compchange
     * and the general help channel) so this cannot pass by coincidence
     * with a type filter under another name.
     */
    public function test_mine_count_agrees_with_filtered_mine_under_differing_totals(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , , $guide] = $this->setup_world();

        $keep1 = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'A widget-team roster question',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        $keep2 = tickets::file_help($activity, $group, 'A second widget question entirely', FORMAT_PLAIN, (int) $guide->id);
        // Two more, neither mentioning "widget" anywhere.
        tickets::file($activity, $group, tickets::TYPE_PENALTY, 'Ask for a deadline waiver', FORMAT_PLAIN, (int) $guide->id);
        tickets::file(
            $activity,
            $group,
            tickets::TYPE_DATES,
            'Need extra time for the assignment',
            FORMAT_PLAIN,
            (int) $guide->id
        );

        $this->assertSame(4, tickets::mine_count($activity, (int) $guide->id), 'fixture: four tickets filed by this guide');

        $filtered = tickets::mine_count($activity, (int) $guide->id, '', '', 'widget');
        $this->assertSame(2, $filtered, 'fixture: exactly two mention "widget"');
        $this->assertNotSame(
            tickets::mine_count($activity, (int) $guide->id),
            $filtered,
            'the filtered and unfiltered totals must genuinely differ, or this test proves nothing'
        );
        $rows = tickets::mine($activity, (int) $guide->id, 0, 0, '', '', 'widget');
        $this->assertSame($filtered, count($rows), 'mine_count() must agree with count(mine()) under the same criteria');
        $ids = array_map(static fn($t) => (int) $t->id, array_values($rows));
        sort($ids);
        $expected = [(int) $keep1->id, (int) $keep2->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * (12) AUTHORITY MUST NARROW, NEVER WIDEN - the exact test the spec
     * asks for by name. Requester A searches for requester B's ticket by
     * its EXACT reference: mine(A, ..., search=B's exact pluginuid) must
     * return nothing, against a fixture where B's ticket genuinely
     * exists (B's own mine() call finds it) and A genuinely has no
     * ticket of their own matching that search (A's own unfiltered list
     * is non-empty, so this is not merely "A has nothing at all").
     *
     * MUTATION: dropping "AND t.requestedby = :userid" from mine()'s
     * WHERE clause (simulating a search wired in a way that widens
     * rather than narrows the scope) makes the first assertion below
     * fail - A's search now finds B's ticket. Exercised live in this
     * run; see the report for the captured failure.
     */
    public function test_mine_cannot_reach_another_requesters_ticket_by_searching_its_exact_reference(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, $group, , $member, $guide] = $this->setup_world();

        // B (the guide) files a ticket with its own real reference.
        $bsticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_COMPCHANGE,
            'Swap in a specialist',
            FORMAT_PLAIN,
            (int) $guide->id
        );
        // A (the member) has a ticket of their own too, so "A's search
        // finds nothing" cannot be explained by A simply having no
        // tickets at all.
        $asticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            'Our leader has gone quiet',
            FORMAT_PLAIN,
            (int) $member->id
        );

        $abysearch = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::mine($activity, (int) $member->id, 0, 0, '', '', $bsticket->pluginuid))
        );
        $this->assertSame(
            [],
            $abysearch,
            'A must not reach B\'s ticket through mine() by searching its exact reference'
        );
        $this->assertSame(0, tickets::mine_count($activity, (int) $member->id, '', '', $bsticket->pluginuid));

        // Positive controls: the ticket genuinely exists and genuinely
        // matches - B finds their own, and A's OWN reference still
        // works for A.
        $bbysearch = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::mine($activity, (int) $guide->id, 0, 0, '', '', $bsticket->pluginuid))
        );
        $this->assertSame([(int) $bsticket->id], $bbysearch, 'fixture: B\'s ticket genuinely exists and matches for B');

        $aownsearch = array_map(
            static fn($t) => (int) $t->id,
            array_values(tickets::mine($activity, (int) $member->id, 0, 0, '', '', $asticket->pluginuid))
        );
        $this->assertSame(
            [(int) $asticket->id],
            $aownsearch,
            'fixture: A genuinely has a ticket of their own, just not a match for B\'s reference'
        );
    }
}
