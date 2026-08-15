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

use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\local\workflow_refusal;

/**
 * 1.20.45, the knowledgebank, proven at the SERVICE (direct calls, never
 * through a page - UI hiding is not enforcement):
 *
 * A. publish_from_ticket(): queue authority, resolved-only, and the
 *    service-enforced anonymisation guard.
 * B. export_entry(): the exact key set the 1.20.46 API pins against,
 *    and that it leaks no provenance (sourceticketid, author userids).
 * C. search(): type filter + LIKE on title/keywords, published-only.
 * D. deflect(): keyword extraction (stopwords < 4 chars dropped), type
 *    filter, the top-N cap.
 * E. create()/update()/unpublish()/delete(): the direct-add interface,
 *    the partial-update contract, and that unpublish hides an entry
 *    from public search without hiding it from the staff list.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\kb
 */
final class kb_test extends \advanced_testcase {
    /**
     * A course, an activity, a leader and a member ready for a group, a
     * guide, and a staff member holding queue authority (editingteacher
     * - mod/selfselectadvanced:manage's default archetype).
     *
     * @return array [activity, course, leader, member, guide, staff]
     */
    private function scene(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
            'maxguided' => 5,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user(['firstname' => 'Lena', 'lastname' => 'Leader']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $member = $generator->create_user(['firstname' => 'Mo', 'lastname' => 'Member']);
        $generator->enrol_user($member->id, $course->id, 'student');
        $guide = $generator->create_user(['firstname' => 'Gina', 'lastname' => 'Guide']);
        $generator->enrol_user($guide->id, $course->id, 'teacher');
        $staff = $generator->create_user(['firstname' => 'Tina', 'lastname' => 'Teach']);
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        return [$activity, $course, $leader, $member, $guide, $staff];
    }

    /**
     * The plugin's data generator.
     *
     * @return \mod_selfselectadvanced_generator
     */
    private function plugingen(): \mod_selfselectadvanced_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
    }

    /**
     * Build a group directly (fixtures bypass the gatekeeper on purpose)
     * with the leader confirmed.
     *
     * @param activity $activity the activity
     * @param int $leaderid the leader
     * @param string $groupstate a state::* constant
     * @param int|null $guideid the assigned guide, or null
     * @return \stdClass the group row
     */
    private function group(activity $activity, int $leaderid, string $groupstate, ?int $guideid = null): \stdClass {
        $group = $this->plugingen()->create_group([
            'activityid' => $activity->id(),
            'leaderid' => $leaderid,
            'guideid' => $guideid,
            'state' => $groupstate,
            'name' => 'Team Blue',
        ]);

        return \mod_selfselectadvanced\local\groups::get($activity, (int) $group->id);
    }

    /**
     * A resolved ticket, filed by the group's leader (the general
     * `help` type - file_help() - needs no guide relationship and no
     * extra membership setup, unlike compchange) and closed by staff -
     * the scene every publish_from_ticket() test starts from.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group
     * @param int $requesterid the requester (files it)
     * @param int $staffid the claimant/resolver
     * @param string $resolution the resolution note
     * @return \stdClass the resolved ticket row
     */
    private function resolved_ticket(
        activity $activity,
        \stdClass $group,
        int $requesterid,
        int $staffid,
        string $resolution = 'Handled: the roster has been corrected.'
    ): \stdClass {
        $ticket = tickets::file_help(
            $activity,
            $group,
            'Please swap one member for another.',
            FORMAT_PLAIN,
            $requesterid
        );
        tickets::claim($activity, (int) $ticket->id, $staffid);

        return tickets::close($activity, (int) $ticket->id, tickets::STATUS_RESOLVED, $resolution, FORMAT_PLAIN, $staffid);
    }

    /**
     * A clean draft with no identifying detail - the positive control
     * every anonymisation-guard test compares against.
     *
     * @return array
     */
    private function clean_draft(): array {
        return [
            'title' => 'How do I ask for a composition change?',
            'question' => 'What is the process for requesting a change to group composition?',
            'answer' => 'Raise a composition-change request from your group page; a coordinator reviews it.',
            'tickettype' => tickets::TYPE_COMPCHANGE,
            'keywords' => 'composition, change',
        ];
    }

    // ------------------------------------------------------------------
    // A. publish_from_ticket(): authority, resolved-only, anonymisation.

    /**
     * Without queue authority, publish_from_ticket() refuses - core's own
     * required_capability_exception, the same type require_queue_authority()
     * throws for the ticket queue itself (never a workflow_refusal: a
     * missing capability is a fault, not a race - workflow_refusal.php's
     * own docblock).
     */
    public function test_publish_from_ticket_requires_queue_authority(): void {
        $this->resetAfterTest();

        [$activity, , $leader, $member, , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);

        $this->expectException(\required_capability_exception::class);
        kb::publish_from_ticket($activity, (int) $ticket->id, (int) $member->id, $this->clean_draft());
    }

    /**
     * A claimed (not yet resolved) ticket refuses - the queue-authority
     * check passes, so this proves the SECOND gate independently.
     */
    public function test_publish_from_ticket_refuses_unresolved(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = tickets::file_help(
            $activity,
            $group,
            'Please swap one member.',
            FORMAT_PLAIN,
            (int) $leader->id
        );
        tickets::claim($activity, (int) $ticket->id, (int) $staff->id);

        try {
            kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $this->clean_draft());
            $this->fail('a claimed (unresolved) ticket must not be publishable');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalkbnotresolved', $e->errorcode);
        }
    }

    /**
     * A resolved ticket with a clean draft succeeds - the positive
     * control, its own method (PostgreSQL transaction-poisoning rule:
     * a refused service call rolls its delegated frame back, and a
     * later commit in the same test method is poisoned on PostgreSQL -
     * see tests/ticket_authority_test.php's own docblocks).
     */
    public function test_publish_from_ticket_succeeds_when_resolved(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);

        $entry = kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $this->clean_draft());

        $this->assertSame((int) $ticket->id, (int) $entry->sourceticketid);
        $this->assertSame(tickets::TYPE_COMPCHANGE, $entry->tickettype);
        $this->assertSame(1, (int) $entry->published);
    }

    /**
     * A resolved ticket's staff-internal trail gains exactly one
     * published_faq row, carrying no note ("no public link back" -
     * maintainer's own words), and it never reaches the requester's
     * anonymised trail.
     */
    public function test_publish_from_ticket_logs_staff_internal_trail_row(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);

        kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $this->clean_draft());

        $stafftrail = tickets::trail($activity, (int) $ticket->id, true);
        $published = array_values(array_filter(
            $stafftrail,
            static fn($row) => $row->action === tickets::ACTION_PUBLISHED_FAQ
        ));
        $this->assertCount(1, $published, 'staff must see exactly one published_faq row');
        $this->assertNull($published[0]->note, 'the row carries no note - no public link back');

        $requestertrail = tickets::trail($activity, (int) $ticket->id, false);
        foreach ($requestertrail as $row) {
            $this->assertNotSame(
                tickets::ACTION_PUBLISHED_FAQ,
                $row->action,
                'the requester\'s anonymised trail must never carry this row'
            );
        }
    }

    /**
     * Firing kb_entry_created with other.sourceticketid and other.tickettype.
     */
    public function test_publish_from_ticket_fires_kb_entry_created(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);
        kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $this->clean_draft());

        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \mod_selfselectadvanced\event\kb_entry_created
        ));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame((int) $ticket->id, (int) $events[0]->other['sourceticketid']);
        $this->assertSame(tickets::TYPE_COMPCHANGE, $events[0]->other['tickettype']);
    }

    /**
     * ANONYMISATION IS SERVICE-ENFORCED (spec): a draft whose wording
     * still names the REQUESTER's fullname verbatim is refused, even
     * though every other gate (authority, resolved status) passes.
     *
     * RED-FIRST PROOF (quoted in the report): with guard_anonymisation()
     * not yet called from publish_from_ticket(), this test's block fails
     * because publishing succeeds instead of throwing.
     */
    public function test_publish_from_ticket_anonymisation_guard_blocks_requester_fullname(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);

        $draft = $this->clean_draft();
        $draft['answer'] = 'We agreed this with Lena Leader directly and made the change.';

        try {
            kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $draft);
            $this->fail('wording naming the requester verbatim must be refused');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalkbanonymisation', $e->errorcode);
        }
    }

    /**
     * The same guard, case-insensitively, over the GROUP's name - its
     * own method (never combined with the fullname arm above: a refused
     * call poisons a later commit on PostgreSQL within one test method).
     */
    public function test_publish_from_ticket_anonymisation_guard_blocks_group_name_case_insensitive(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);

        $draft = $this->clean_draft();
        $draft['question'] = 'A member of TEAM BLUE asked about swapping a teammate.';

        try {
            kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $draft);
            $this->fail('wording naming the group verbatim (any case) must be refused');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalkbanonymisation', $e->errorcode);
        }
    }

    /**
     * The guard is crude (a substring test), not a rewrite: a clean
     * draft that merely mentions the general SUBJECT (composition
     * change) publishes fine - the positive control for both arms
     * above, its own method.
     */
    public function test_publish_from_ticket_anonymisation_guard_allows_clean_wording(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);

        $entry = kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $this->clean_draft());
        $this->assertGreaterThan(0, (int) $entry->id);
    }

    // ------------------------------------------------------------------
    // B. export_entry(): exact key set, no provenance leak.

    /**
     * The exact key set the 1.20.46 API pins against.
     *
     * RED-FIRST PROOF (quoted in the report): run against a build with
     * 'sourceticketid' added back into export_entry()'s return, this
     * assertion fails on the extra key.
     */
    public function test_export_entry_exact_key_set(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $entry = kb::create($activity, (int) $staff->id, $this->clean_draft());
        global $DB;
        $row = $DB->get_record('selfselectadvanced_kb', ['id' => $entry->id], '*', MUST_EXIST);

        $exported = kb::export_entry($row);

        $expectedkeys = ['id', 'title', 'question', 'answerhtml', 'answertext', 'type', 'keywords', 'timemodified'];
        sort($expectedkeys);
        $actualkeys = array_keys($exported);
        sort($actualkeys);
        $this->assertSame($expectedkeys, $actualkeys);
    }

    /**
     * NO PROVENANCE LEAK (spec, in so many words): sourceticketid and
     * every author userid column stay out of the export, whatever else
     * changes about its shape.
     */
    public function test_export_entry_never_leaks_provenance(): void {
        $this->resetAfterTest();

        [$activity, , $leader, , , $staff] = $this->scene();
        $group = $this->group($activity, (int) $leader->id, state::FIRM);
        $ticket = $this->resolved_ticket($activity, $group, (int) $leader->id, (int) $staff->id);
        $entry = kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, $this->clean_draft());
        global $DB;
        $row = $DB->get_record('selfselectadvanced_kb', ['id' => $entry->id], '*', MUST_EXIST);
        // Fixture sanity: the row really does carry provenance to leak.
        $this->assertGreaterThan(0, (int) $row->sourceticketid);

        $exported = kb::export_entry($row);

        foreach (['sourceticketid', 'usercreated', 'usermodified', 'activityid', 'published'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $exported, "export_entry() leaked '$forbidden'");
        }
    }

    /**
     * answerhtml is format_text() output, answertext is html_to_text()
     * output - an LLM consumer never has to parse HTML (spec's own
     * words) out of either.
     */
    public function test_export_entry_answer_variants(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $draft = $this->clean_draft();
        $draft['answer'] = "Line one.\n\nLine <b>two</b> is bold.";
        $entry = kb::create($activity, (int) $staff->id, $draft);
        global $DB;
        $row = $DB->get_record('selfselectadvanced_kb', ['id' => $entry->id], '*', MUST_EXIST);

        $exported = kb::export_entry($row);
        $this->assertStringNotContainsString('<b>', $exported['answertext']);
        // Html_to_text() upper-cases <b>/<strong> content (its own
        // plain-text convention for emphasis) - checked case-
        // insensitively so this pins the CONTENT survived, not the tag.
        $this->assertStringContainsStringIgnoringCase('two', $exported['answertext']);
        $this->assertStringContainsString('<b>', $exported['answerhtml']);
    }

    // ------------------------------------------------------------------
    // C. search(): type filter, LIKE, published-only.

    /**
     * search() matches on title.
     */
    public function test_search_matches_title(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        kb::create($activity, (int) $staff->id, $this->clean_draft());

        $found = kb::search($activity, '', 'composition change', true);
        $this->assertCount(1, $found);
    }

    /**
     * search() matches on keywords, independent of the title.
     */
    public function test_search_matches_keywords(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $draft = $this->clean_draft();
        $draft['title'] = 'Something else entirely';
        $draft['keywords'] = 'swapping, roster';
        kb::create($activity, (int) $staff->id, $draft);

        $found = kb::search($activity, '', 'roster', true);
        $this->assertCount(1, $found);
    }

    /**
     * The type filter narrows to that type alone.
     */
    public function test_search_type_filter(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        kb::create($activity, (int) $staff->id, $this->clean_draft());
        $help = $this->clean_draft();
        $help['title'] = 'A general question';
        $help['tickettype'] = tickets::TYPE_HELP;
        kb::create($activity, (int) $staff->id, $help);

        $compchange = kb::search($activity, tickets::TYPE_COMPCHANGE, '', true);
        $this->assertCount(1, $compchange);
        foreach ($compchange as $row) {
            $this->assertSame(tickets::TYPE_COMPCHANGE, $row->tickettype);
        }
    }

    /**
     * publishedonly=true (every public caller) hides an unpublished
     * entry; publishedonly=false (the staff list) still shows it - the
     * spec's own words, proven directly rather than only through the
     * unpublish() lifecycle test below.
     */
    public function test_search_publishedonly_filters_unpublished(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $draft = $this->clean_draft();
        $draft['published'] = 0;
        kb::create($activity, (int) $staff->id, $draft);

        $this->assertCount(0, kb::search($activity, '', '', true));
        $this->assertCount(1, kb::search($activity, '', '', false));
    }

    // ------------------------------------------------------------------
    // D. deflect(): keyword extraction, type filter, top-N cap.

    /**
     * deflect() matches on a keyword extracted from free text.
     */
    public function test_deflect_matches_extracted_keyword(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        kb::create($activity, (int) $staff->id, $this->clean_draft());

        $matches = kb::deflect($activity, '', 'I need help with a composition problem in my group');
        $this->assertNotEmpty($matches);
    }

    /**
     * "drop stopwords < 4 chars" (spec): a query built ONLY from words
     * shorter than the floor extracts no keyword at all, so it degrades
     * to the type's most-recently-edited entries rather than matching
     * nothing - proven here by type '' still returning the fixture.
     */
    public function test_deflect_drops_short_words(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        kb::create($activity, (int) $staff->id, $this->clean_draft());

        // Every word here is under 4 characters.
        $matches = kb::deflect($activity, '', 'is it ok to do it now');
        $this->assertNotEmpty($matches, 'no keyword survives extraction, so this must fall back to recent entries');
    }

    /**
     * The type filter narrows deflect() the same way it narrows search().
     */
    public function test_deflect_type_filter(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        kb::create($activity, (int) $staff->id, $this->clean_draft());
        $help = $this->clean_draft();
        $help['title'] = 'Something about composition too';
        $help['tickettype'] = tickets::TYPE_HELP;
        kb::create($activity, (int) $staff->id, $help);

        $matches = kb::deflect($activity, tickets::TYPE_HELP, 'composition');
        $this->assertCount(1, $matches);
        foreach ($matches as $row) {
            $this->assertSame(tickets::TYPE_HELP, $row->tickettype);
        }
    }

    /**
     * deflect() never returns more than DEFLECT_LIMIT (5) rows.
     */
    public function test_deflect_caps_at_limit(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        for ($i = 1; $i <= 7; $i++) {
            $draft = $this->clean_draft();
            $draft['title'] = 'Composition question ' . $i;
            kb::create($activity, (int) $staff->id, $draft);
        }

        $matches = kb::deflect($activity, '', 'composition');
        $this->assertLessThanOrEqual(kb::DEFLECT_LIMIT, count($matches));
        $this->assertCount(kb::DEFLECT_LIMIT, $matches);
    }

    /**
     * deflect() only ever returns published entries - it is a PUBLIC
     * filing surface, so an unpublished draft must never appear there
     * even though the staff list can still see it.
     */
    public function test_deflect_never_returns_unpublished(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $draft = $this->clean_draft();
        $draft['published'] = 0;
        kb::create($activity, (int) $staff->id, $draft);

        $matches = kb::deflect($activity, '', 'composition');
        $this->assertCount(0, $matches);
    }

    // ------------------------------------------------------------------
    // E. create()/update()/unpublish()/delete() lifecycle.

    /**
     * create() is the direct-add interface: sourceticketid 0.
     */
    public function test_create_direct_entry_has_no_source_ticket(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $entry = kb::create($activity, (int) $staff->id, $this->clean_draft());

        $this->assertSame(0, (int) $entry->sourceticketid);
    }

    /**
     * update()'s PARTIAL-draft contract: a key not present in $draft
     * keeps the row's current value.
     */
    public function test_update_is_partial(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $entry = kb::create($activity, (int) $staff->id, $this->clean_draft());

        $updated = kb::update($activity, (int) $entry->id, ['title' => 'A better title'], (int) $staff->id);

        $this->assertSame('A better title', $updated->title);
        $this->assertSame($entry->question, $updated->question);
        $this->assertSame($entry->answer, $updated->answer);
    }

    /**
     * unpublish() hides an entry from public search but NOT from the
     * staff list - the spec's own words, proven through the full
     * lifecycle (create, then unpublish) rather than a row poked
     * straight into the database.
     */
    public function test_unpublish_hides_from_public_search_but_not_staff_list(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $entry = kb::create($activity, (int) $staff->id, $this->clean_draft());
        $this->assertCount(1, kb::search($activity, '', '', true), 'fixture: must start published and visible');

        $unpublished = kb::unpublish($activity, (int) $entry->id, (int) $staff->id);

        $this->assertSame(0, (int) $unpublished->published);
        $this->assertCount(0, kb::search($activity, '', '', true), 'unpublish must hide it from public search');
        $this->assertCount(1, kb::search($activity, '', '', false), 'unpublish must NOT hide it from the staff list');
    }

    /**
     * delete() removes the row outright - gone from both the public
     * search and the staff list.
     */
    public function test_delete_removes_entry_from_every_list(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $entry = kb::create($activity, (int) $staff->id, $this->clean_draft());

        kb::delete($activity, (int) $entry->id, (int) $staff->id);

        $this->assertCount(0, kb::search($activity, '', '', false));
    }

    /**
     * create()/update() refuse an empty title, question or answer -
     * the same emptiness idiom tickets.php uses throughout.
     */
    public function test_create_refuses_incomplete_draft(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $draft = $this->clean_draft();
        $draft['answer'] = '   ';

        try {
            kb::create($activity, (int) $staff->id, $draft);
            $this->fail('an empty answer must be refused');
        } catch (workflow_refusal $e) {
            $this->assertSame('refusalkbincomplete', $e->errorcode);
        }
    }

    /**
     * has_published(): false with nothing published, true once an
     * entry is - the landing page's own "Common questions" gate.
     */
    public function test_has_published_reflects_publication_state(): void {
        $this->resetAfterTest();

        [$activity, , , , , $staff] = $this->scene();
        $this->assertFalse(kb::has_published($activity));

        kb::create($activity, (int) $staff->id, $this->clean_draft());
        $this->assertTrue(kb::has_published($activity));
    }
}
