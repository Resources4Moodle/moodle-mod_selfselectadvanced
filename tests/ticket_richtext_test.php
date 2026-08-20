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
 * Slice A: the ticket request/resolution fields carry FORMAT_MOODLE
 * multi-line rich-ish text (textarea + nl2br + auto-links + filters at
 * render) instead of single-line PARAM_TEXT plain text. No schema
 * change - requestformat/resolutionformat already existed and were
 * already threaded through the service layer; the defect was five
 * page-level widgets stuck at 'type' => 'text' and five call sites
 * hardcoding FORMAT_PLAIN (three of them also reading PARAM_TEXT, which
 * strips tags before the service ever sees them).
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DELIBERATELY DOES NOT. The five
 * widgets this slice touches live inside group.php, guidequeue.php and
 * tickets.php - page scripts that call require_login(), redirect() and
 * echo $OUTPUT->header(). PHPUnit cannot execute a Moodle page script
 * end-to-end (nothing in this suite does; toolcallsites_test.php's
 * docblock states the same limitation for docs/tools/), so this file
 * cannot drive the five widgets through a real HTTP round trip - Behat
 * carries that coverage (tests/behat/tickets.feature,
 * guide_tickets.feature, guidequeue.feature, myrequests.feature). What
 * IS unit-testable, and is tested here:
 *  - the SERVICE contract: tickets::file()/close() already accepted and
 *    stored $requestformat/$resolutionformat verbatim before this slice
 *    (classes/local/tickets.php needed no change) - storing FORMAT_MOODLE
 *    and a safe inline tag round-trips exactly;
 *  - the RENDER contract tickets.php:214 now relies on: format_text()
 *    honours the STORED format. FORMAT_MOODLE nl2br's the newline and
 *    keeps a safe tag; FORMAT_PLAIN - what every one of the five call
 *    sites hardcoded before this slice - nl2br's the newline too but
 *    ESCAPES the tag via s(), which is the concrete loss the slice
 *    fixes, not a paperwork difference;
 *  - the NOTIFIER contract (unchanged by this slice, re-checked here):
 *    the message actually delivered to the requester flattens the
 *    resolution through html_to_text(), so no raw tag reaches an inbox
 *    that may render no HTML at all;
 *  - a SOURCE PIN on the five call sites themselves, in the style of
 *    guard_callers_test.php and contactprivacy_matrix_test.php: each of
 *    PARAM_RAW (was PARAM_TEXT at three of the five) and FORMAT_MOODLE
 *    (was FORMAT_PLAIN at all five) is confirmed present in the actual
 *    page source, so a later revert of one line - the textarea kept,
 *    the format constant quietly put back - is caught here even without
 *    a browser.
 *
 * MUTATION-STYLE EVIDENCE (run 2026-08-14, PHPUnit against the unfixed
 * tree, before any production edit in this slice):
 *  - test_the_five_call_sites_read_raw_and_pass_format_moodle() failed
 *    all of: group.php still read PARAM_TEXT for 'reason', and
 *    group.php, guidequeue.php (both call sites) and tickets.php (both
 *    call sites) all still passed FORMAT_PLAIN; tickets.php:214 still
 *    rendered with s(), not format_text(). Green only after the edits.
 *  - test_format_plain_would_have_escaped_the_tag_format_moodle_does_not()
 *    is the behavioural half of the same point: FORMAT_PLAIN really does
 *    escape '<b>bold</b>' to '&lt;b&gt;bold&lt;/b&gt;' via format_text(),
 *    which is what every hardcoded call site was condemning the stored
 *    text to before this slice, regardless of what the textarea widget
 *    let the user type.
 *
 * UPDATED, SLICE B2 (2026-08-15): tickets.php's own two FORMAT_MOODLE
 * call sites (grant_guidecap()/close()) are GONE from that file, not
 * reverted - the forms that fed them (resolve, decline, the guidecap
 * grant, request-info) moved to the ticket's own thread (ticket.php),
 * which now holds FOUR FORMAT_MOODLE call sites of its own
 * (request_info(), grant_guidecap(), the shared close() resolve/decline
 * call, provide_info()). tickets.php keeps exactly ONE format constant -
 * FORMAT_PLAIN, on the bare one-click force-release action, which sends
 * no user text at all (close() only stores resolution/resolutionformat
 * for the resolved/declined outcomes). The render contract - tickets.php
 * still shows a closed ticket's resolution via format_text(), not s() -
 * is unchanged and re-pinned below; ticket.php gets its own source pin
 * for the forms that now write that text.
 *
 * UPDATED, 1.20.44 (the handling ladder): ticket.php gains TWO more
 * FORMAT_MOODLE call sites of its own - refer()'s and escalate()'s note
 * textarea, both read as the 'note' field - bringing the page's own
 * total from four to SIX. Neither is a resolution-shaped field (the
 * three-way close() dispatch doesn't touch them), so they are counted
 * separately below rather than folded into the loop over
 * question/resolution/declinereason/reply.
 *
 * UPDATED, 1.20.52 (a real editor): ticketfile_form.php's 'reason' and
 * ticketpost_form.php's question/reply/resolution fields became editor
 * elements, which POST an ARRAY (['text' => ..., 'format' => ...]), not
 * a scalar. group.php, filehelp.php and ticket.php now read that array
 * with optional_param_array() and pass the FORMAT THE EDITOR RETURNED
 * on to the service layer, rather than the FORMAT_MOODLE literal every
 * one of those call sites used to hardcode - the pin below is rewritten
 * to match, and a new test proves a row already stored at FORMAT_MOODLE
 * or FORMAT_PLAIN (every ticket filed before this slice) still renders
 * exactly as it did before. Untouched by this slice, and so still
 * pinned exactly as before: guidequeue.php and tickets.php (neither
 * uses either converted form), and ticket.php's refer()/escalate()
 * (hand-rolled textarea in the template, spec: out of scope) and the
 * decline arm's declinereason (same reason).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\tickets
 */
final class ticket_richtext_test extends \advanced_testcase {
    /**
     * An activity with a firm group: leader, confirmed member, guide,
     * manager, coordinator. Shaped exactly like
     * tickets_test.php::setup_world() and myrequests_test.php::setup_world()
     * - the same fixture the rest of the ticket queue is tested against.
     *
     * @return array [activity, group, leader, member, guide, manager, coordinator]
     */
    private function setup_world(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course(['shortname' => 'RICH1']);
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
            'name' => 'Richtexted',
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
     * The messages a sink captured, indexed by recipient. Shaped like
     * message_placeholder_contract_test.php::by_recipient().
     *
     * @param \phpunit_message_sink $sink the sink
     * @return array<int, array<int, \stdClass>> userid => messages
     */
    private function by_recipient(\phpunit_message_sink $sink): array {
        $byuser = [];
        foreach ($sink->get_messages() as $message) {
            $byuser[(int) $message->useridto][] = $message;
        }
        return $byuser;
    }

    /**
     * File a rich-ish request, resolve it with a rich-ish resolution:
     * both store FORMAT_MOODLE verbatim (the service layer needed no
     * change - fact 6 of the slice A spec), format_text() on the stored
     * resolution nl2br's the newline AND keeps the safe inline tag (the
     * render contract tickets.php:214 now relies on), and the closing
     * notice actually delivered to the requester carries the flattened
     * words with no raw tag (the notifier contract, unchanged by this
     * slice - fact 5).
     */
    public function test_file_and_close_store_and_render_format_moodle_richtext(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [$activity, $group, , $member, , $manager] = $this->setup_world();
        $context = $activity->context();

        $sink = $this->redirectMessages();
        $request = "Our leader has gone quiet.\nWe need someone to run the standups. <b>Please act soon.</b>";
        $ticket = tickets::file(
            $activity,
            $group,
            tickets::TYPE_LEADERCHANGE,
            $request,
            FORMAT_MOODLE,
            (int) $member->id
        );

        $this->assertSame((int) FORMAT_MOODLE, (int) $ticket->requestformat, 'the stored format is what file() was given');
        $this->assertStringContainsString(
            '<b>Please act soon.</b>',
            $ticket->request,
            'the request is stored verbatim, tag and all - the service layer never ran PARAM_TEXT on it'
        );

        tickets::claim($activity, (int) $ticket->id, (int) $manager->id);
        $resolution = "Spoken to the leader.\nThey are back and running standups <b>from tomorrow</b>.";
        $closed = tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            $resolution,
            FORMAT_MOODLE,
            (int) $manager->id
        );

        $this->assertSame((int) FORMAT_MOODLE, (int) $closed->resolutionformat);
        $this->assertSame($resolution, $closed->resolution);

        // The render contract tickets.php:214 now uses: format_text()
        // with the STORED format, not s(). FORMAT_MOODLE nl2br's the
        // newline and keeps the safe tag; s() alone would have escaped
        // the tag and done nothing with the newline.
        $rendered = format_text((string) $closed->resolution, (int) $closed->resolutionformat, ['context' => $context]);
        $this->assertStringContainsString('<br', $rendered, 'the newline became a line break, as FORMAT_MOODLE promises');
        $this->assertStringContainsString('<b>from tomorrow</b>', $rendered, 'the safe tag survived, unescaped');

        // The notifier flattens what it sends (fact 5, unchanged by this
        // slice): the message the requester actually receives must not
        // carry a raw tag into an inbox that may render no HTML at all.
        $byuser = $this->by_recipient($sink);
        $this->assertArrayHasKey((int) $member->id, $byuser, 'the requester is told their ticket closed');
        $notices = array_values(array_filter(
            $byuser[(int) $member->id],
            static fn($m) => str_contains((string) $m->fullmessage, 'What they said')
        ));
        $this->assertNotEmpty($notices, 'the closing notice (msgticketclosedbody) reached the requester');
        $notice = $notices[0];
        $this->assertStringNotContainsString('<b>', $notice->fullmessage, 'no raw tag reaches the plain-text body');
        // Moodle's html_to_text() (core_html2text) renders <b>/<strong> as
        // upper case rather than keeping the tag - a plain-text emphasis
        // convention, not a defect - so the match here is case-insensitive.
        $this->assertStringContainsStringIgnoringCase(
            'from tomorrow',
            $notice->fullmessage,
            'the flattened words are still there'
        );
    }

    /**
     * FORMAT_MOODLE is not a cosmetic choice: it is what makes a safe
     * inline tag survive rendering. The same source text stored as
     * FORMAT_PLAIN - what every one of the five call sites hardcoded
     * before this slice (group.php, guidequeue.php x2, tickets.php x2) -
     * renders with the tag escaped, not kept, so switching those five
     * constants to FORMAT_MOODLE is a behaviour change, not paperwork.
     *
     * Both formats nl2br the newline (Moodle 5.2's formatting.php runs
     * nl2br() on the FORMAT_PLAIN branch directly and via text_to_html()
     * on the FORMAT_MOODLE branch) - that is asserted here too, so the
     * negative control is honest about which axis actually distinguishes
     * the two formats.
     */
    public function test_format_plain_would_have_escaped_the_tag_format_moodle_does_not(): void {
        $this->resetAfterTest();
        [$activity] = $this->setup_world();
        $context = $activity->context();
        $text = "line1\nline2 <b>bold</b>";

        $plain = format_text($text, FORMAT_PLAIN, ['context' => $context]);
        $moodle = format_text($text, FORMAT_MOODLE, ['context' => $context]);

        $this->assertStringNotContainsString(
            '<b>bold</b>',
            $plain,
            'FORMAT_PLAIN escapes the tag - the loss every hardcoded call site caused before this slice'
        );
        $this->assertStringContainsString('&lt;b&gt;', $plain, 'escaped, not dropped: format_text() still ran s() on it');
        $this->assertStringContainsString('<b>bold</b>', $moodle, 'FORMAT_MOODLE keeps the safe tag - the fix');
        $this->assertStringContainsString('<br', $plain, 'both formats nl2br the newline - not the axis that distinguishes them');
        $this->assertStringContainsString('<br', $moodle, 'both formats nl2br the newline - not the axis that distinguishes them');
    }

    /**
     * 1.20.52 binding constraint 3: EXISTING ROWS MUST STILL RENDER. A
     * ticket already sitting in the database at FORMAT_MOODLE (every
     * ticket filed since 1.20.41) or FORMAT_PLAIN (every ticket filed
     * before that) keeps its stored format and must display unchanged
     * now that the reason/resolution fields are editor elements. The two
     * rows here are inserted DIRECTLY, never through tickets::file()
     * (which this slice does not touch and always stores whatever
     * format it is given) - the point under test is what a row already
     * on disk does, not what a fresh filing produces.
     *
     * This drives the actual render path ticket.php's GET arm uses -
     * ticket_page::export_for_template()'s 'openingpost' - rather than a
     * bare format_text() call in isolation, so a mistake INSIDE that
     * class (for instance rendering every opening post at a hardcoded
     * format regardless of what the row says) is what this test would
     * catch.
     *
     * RED-FIRST (run 2026-08-18): with classes/output/ticket_page.php's
     * 'openingpost' line temporarily mutated to
     * format_text((string) $ticket->request, FORMAT_HTML, ['context' => $context])
     * - a hardcoded format standing in for "every opening post now
     * renders as if freshly typed into the editor", exactly the mistake
     * this constraint forbids - the test failed on its very first
     * iteration (the FORMAT_MOODLE row) with:
     * "Failed asserting that 'line1\nline2 <b>bold</b>' contains '<br'."
     * FORMAT_HTML never runs text_to_html()/nl2br() the way FORMAT_MOODLE
     * does, so the newline survived raw instead of becoming a line break -
     * proof the mutation was caught, even though the FORMAT_PLAIN
     * escaping half of the proof never got the chance to run in the same
     * pass. Reverting the mutation (restoring the
     * (int) $ticket->requestformat read) turned the test green again with
     * no other change - see the report for the actual PHPUnit output of
     * both runs.
     */
    public function test_pre_existing_older_format_rows_still_render_unchanged(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        [$activity, $group, , $member] = $this->setup_world();
        $text = "line1\nline2 <b>bold</b>";
        $now = time();
        $base = [
            'activityid' => $activity->id(),
            'groupid' => (int) $group->id,
            'type' => tickets::TYPE_COMPCHANGE,
            'status' => tickets::STATUS_OPEN,
            'requestedby' => (int) $member->id,
            'request' => $text,
            'disclaimerack' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        // 1.20.56: pluginuid is NOT NULL UNIQUE now, so a row inserted
        // directly (as any pre-1.20.56 row this test simulates would
        // have been, before a real upgrade backfilled it) needs its own
        // distinct value - the point under test is the STORED FORMAT,
        // not the reference, so these are synthetic rather than minted.
        $moodleticketid = $DB->insert_record(
            'selfselectadvanced_ticket',
            (object) (['requestformat' => FORMAT_MOODLE, 'pluginuid' => 'RICH-LEGACY-T0001'] + $base)
        );
        $plainticketid = $DB->insert_record(
            'selfselectadvanced_ticket',
            (object) (['requestformat' => FORMAT_PLAIN, 'pluginuid' => 'RICH-LEGACY-T0002'] + $base)
        );

        $output = $PAGE->get_renderer('core');
        foreach ([[$moodleticketid, true], [$plainticketid, false]] as [$ticketid, $expecttag]) {
            $ticket = tickets::get($activity, $ticketid);
            $page = new \mod_selfselectadvanced\output\ticket_page(
                $activity,
                $ticket,
                $group,
                (int) $member->id,
                true,
                false
            );
            $exported = $page->export_for_template($output);
            if ($expecttag) {
                $this->assertStringContainsString(
                    '<b>bold</b>',
                    $exported->openingpost,
                    'a pre-existing FORMAT_MOODLE row must still keep the safe tag unescaped, unaffected by the editor conversion'
                );
            } else {
                $this->assertStringNotContainsString(
                    '<b>bold</b>',
                    $exported->openingpost,
                    'a pre-existing FORMAT_PLAIN row must still have the tag escaped, exactly as before this slice'
                );
                $this->assertStringContainsString(
                    '&lt;b&gt;',
                    $exported->openingpost,
                    'escaped, not dropped - FORMAT_PLAIN rendering is unchanged by the editor conversion'
                );
            }
            $this->assertStringContainsString(
                '<br',
                $exported->openingpost,
                'both formats still nl2br the newline, unchanged by the editor conversion'
            );
        }
    }

    /**
     * A source pin on the five call sites this slice edits (group.php:815,
     * guidequeue.php:68 and :90, tickets.php:73 and :94), the three
     * PARAM_TEXT reads this slice widens to PARAM_RAW (group.php:808,
     * guidequeue.php:66 and :88), and the renderer tickets.php:214 - so a
     * later revert of any one of them, textarea kept but the format
     * constant or the param type quietly put back, is caught here even
     * though PHPUnit cannot drive group.php/guidequeue.php/tickets.php
     * end-to-end (they require_login()/redirect()/echo $OUTPUT->header(),
     * none of which runs under PHPUnit - see toolcallsites_test.php for
     * the same limitation stated about docs/tools/). Behat carries the
     * end-to-end coverage this cannot: tests/behat/tickets.feature,
     * guide_tickets.feature, guidequeue.feature, myrequests.feature.
     *
     * MUTATION CAUGHT (run 2026-08-15, slice A): written and run against
     * the unfixed tree, every assertion below failed - group.php:808
     * still read PARAM_TEXT, and group.php:815, guidequeue.php:68,
     * guidequeue.php:90, tickets.php:73 and tickets.php:94 all still
     * passed FORMAT_PLAIN; tickets.php:214 still rendered the resolution
     * with s(), not format_text(). Green only after the edits landed.
     *
     * RE-PINNED, SLICE B2 (2026-08-15): the group.php and guidequeue.php
     * arms are untouched by B2 and still hold. The tickets.php arm is
     * rewritten for the new file - it keeps exactly one format constant
     * (FORMAT_PLAIN, on the bare force-release action, which carries no
     * user text) and still renders a closed ticket's resolution via
     * format_text(). A new ticket.php arm pins the four call sites the
     * queue's forms moved to.
     *
     * RE-PINNED, 1.20.52 (a real editor): group.php's reason field and
     * ticket.php's question/resolution/reply fields are no longer read
     * as a scalar - the editor element posts an ARRAY, so this pin now
     * requires optional_param_array() and a variable format (whatever
     * the editor returned), NOT the FORMAT_MOODLE literal a revert to
     * the old textarea would quietly restore. filehelp.php - never
     * pinned before this slice despite being a listed consumer - and
     * both form classes' addElement('editor', ..., ['maxfiles' => 0])
     * shape are pinned here too. guidequeue.php and tickets.php use
     * neither converted form and are untouched. ticket.php's
     * refer()/escalate() note and the decline arm's declinereason stay
     * hand-rolled textareas (spec: out of scope) and so keep their
     * scalar optional_param()/hardcoded FORMAT_MOODLE shape exactly as
     * pinned before.
     *
     * RED-FIRST for the array-read shape (run 2026-08-18): group.php,
     * filehelp.php and ticket.php were temporarily reverted to their OLD
     * scalar reads/hardcoded FORMAT_MOODLE (forms left converted to
     * 'editor') and this rewritten test run in isolation - it failed
     * immediately on the FIRST assertion below ("group.php: the
     * ticket-filing reason must be read as the array an editor element
     * posts"), which is as far as PHPUnit gets before a test method
     * stops at its first failed assertion; the old
     * optional_param('reason_' . $tickettype, ...) literal was still
     * present, the new optional_param_array(...) literal was not.
     * Reverting the revert (restoring the real consumer edits) turned
     * the test green again with no other change - see the report for
     * the actual command output of both runs.
     */
    public function test_the_call_sites_read_editor_arrays_and_store_the_returned_format(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);

        // Comments stripped, whitespace collapsed - so this file's own
        // WHY-comments (which say "FORMAT_MOODLE" and "PARAM_RAW" in
        // plain English right next to the code they explain) cannot
        // inflate the counts below. Same idiom as
        // contactprivacy_matrix_test.php::normalised_executable_source().
        $group = self::normalised_executable_source($root . '/group.php');
        // 1.20.44 part 2: the field name gained a per-type suffix
        // (classes/form/ticketfile_form.php's docblock explains why -
        // up to six of these forms render on one page, and
        // MoodleQuickForm derives DOM ids from element names alone).
        // 1.20.52: the element itself is now an editor, so the value
        // POSTed is an ARRAY - optional_param_array(), never the old
        // scalar optional_param() - and the format passed on to
        // file()/file_help() is the variable the array unpacked into,
        // never a hardcoded constant.
        $this->assertStringContainsString(
            "\$reasoneditor = optional_param_array('reason_' . \$tickettype, [], PARAM_RAW);",
            $group,
            'group.php: the ticket-filing reason must be read as the array an editor element posts'
        );
        $this->assertStringNotContainsString(
            "optional_param('reason_' . \$tickettype",
            $group,
            'group.php: the old scalar read of reason_$tickettype must be gone, not left alongside the array read'
        );
        $this->assertStringContainsString(
            'tickets::file( $activity, $group, $tickettype, $reason, $reasonformat,',
            $group,
            'group.php: file() must be called with the format the editor returned, never a hardcoded constant'
        );
        $this->assertStringContainsString(
            'tickets::file_help( $activity, $group, $reason, $reasonformat,',
            $group,
            'group.php: file_help() must be called with the format the editor returned, never a hardcoded constant'
        );
        $this->assertSame(
            1,
            substr_count($group, "?? FORMAT_MOODLE)"),
            'group.php: FORMAT_MOODLE may only appear as the ??-fallback default, never a hardcoded call-site argument'
        );

        $filehelp = self::normalised_executable_source($root . '/filehelp.php');
        $this->assertStringContainsString(
            'optional_param_array( \mod_selfselectadvanced\form\ticketfile_form::reason_field(tickets::TYPE_HELP),',
            $filehelp,
            'filehelp.php: the reason field must be read as the array an editor element posts'
        );
        $this->assertStringContainsString(
            'tickets::file_help($activity, $group, $reason, $reasonformat,',
            $filehelp,
            'filehelp.php: file_help() must be called with the format the editor returned, never a hardcoded constant'
        );
        $this->assertSame(
            1,
            substr_count($filehelp, "?? FORMAT_MOODLE)"),
            'filehelp.php: FORMAT_MOODLE may only appear as the ??-fallback default, never a hardcoded call-site argument'
        );

        // Binding constraint 1: both converted forms pass maxfiles => 0,
        // with the decision recorded in a comment (checked separately,
        // by grep, further down) rather than left implicit.
        $ticketfileform = self::normalised_executable_source($root . '/classes/form/ticketfile_form.php');
        $this->assertStringContainsString(
            "\$mform->addElement('editor', \$reasonfield, \$reasonlabel, null, ['maxfiles' => 0]);",
            $ticketfileform,
            "ticketfile_form.php: 'reason' must be a real editor with maxfiles explicitly 0"
        );
        $ticketpostform = self::normalised_executable_source($root . '/classes/form/ticketpost_form.php');
        $this->assertStringContainsString(
            "\$mform->addElement('editor', \$field, \$this->_customdata['label'], null, ['maxfiles' => 0]);",
            $ticketpostform,
            "ticketpost_form.php: question/reply/resolution must be a real editor with maxfiles explicitly 0"
        );

        $guidequeue = self::normalised_executable_source($root . '/guidequeue.php');
        $this->assertSame(
            0,
            substr_count($guidequeue, 'PARAM_TEXT'),
            'guidequeue.php: both reason reads must be PARAM_RAW now, not PARAM_TEXT'
        );
        $this->assertSame(
            2,
            substr_count($guidequeue, 'FORMAT_MOODLE'),
            'guidequeue.php: file_guidereduce() and file_guidecap() must both pass FORMAT_MOODLE'
        );
        $this->assertStringNotContainsString(
            'FORMAT_PLAIN',
            $guidequeue,
            'guidequeue.php: no call site should still hardcode FORMAT_PLAIN'
        );

        // B2: the queue keeps ONE format constant - FORMAT_PLAIN, on the
        // bare force-release action (no textarea feeds it: close() only
        // stores resolution/resolutionformat for the resolved/declined
        // outcomes, never for a release). Resolve, decline and the
        // guidecap grant moved to ticket.php, taking their two
        // FORMAT_MOODLE call sites with them.
        $tickets = self::normalised_executable_source($root . '/tickets.php');
        $this->assertSame(
            0,
            substr_count($tickets, 'FORMAT_MOODLE'),
            'tickets.php: the resolve/decline/grant forms moved to ticket.php, so no FORMAT_MOODLE call site should remain'
        );
        $this->assertSame(
            1,
            substr_count($tickets, 'FORMAT_PLAIN'),
            'tickets.php: exactly the bare force-release call site should hardcode FORMAT_PLAIN'
        );
        $this->assertStringContainsString(
            'format_text((string) $ticket->resolution, (int) $ticket->resolutionformat,',
            $tickets,
            'tickets.php must still render a closed ticket\'s resolution through format_text(), not s()'
        );

        // 1.20.52: ticket.php's question/resolution/reply fields are now
        // editor elements (ticketpost_form.php) and POST an ARRAY -
        // 'resolution' feeds BOTH the grant arm and the resolve arm
        // (ticket_page.php's own render_ticketpost_form() reuses the one
        // field name for both), so its array-read line appears TWICE.
        // declinereason and note (refer/escalate) are UNCHANGED - still
        // the hand-rolled textareas the spec named out of scope - so
        // they keep the old scalar optional_param()/FORMAT_MOODLE shape
        // exactly as pinned before 1.20.52.
        $ticketpage = self::normalised_executable_source($root . '/ticket.php');
        $this->assertStringNotContainsString(
            'PARAM_TEXT',
            $ticketpage,
            'ticket.php: every thread textarea must be read PARAM_RAW, never PARAM_TEXT'
        );
        foreach (['question', 'reply'] as $field) {
            $this->assertStringContainsString(
                '$' . $field . "editor = optional_param_array('$field', [], PARAM_RAW);",
                $ticketpage,
                "ticket.php: the '$field' field must be read as the array an editor element posts"
            );
            $this->assertStringNotContainsString(
                "optional_param('$field', '', PARAM_RAW)",
                $ticketpage,
                "ticket.php: the '$field' field must not still be read as a scalar"
            );
        }
        $this->assertSame(
            2,
            substr_count($ticketpage, "\$noteeditor = optional_param_array('resolution', [], PARAM_RAW);"),
            "ticket.php: 'resolution' feeds both the grant arm and the resolve arm, each reading the editor's array"
        );
        $this->assertStringNotContainsString(
            "optional_param('resolution', '', PARAM_RAW)",
            $ticketpage,
            "ticket.php: 'resolution' must not still be read as a scalar anywhere"
        );
        // Declinereason: unchanged, still the hand-rolled textarea and
        // still a scalar PARAM_RAW read.
        $this->assertStringContainsString(
            "optional_param('declinereason', '', PARAM_RAW)",
            $ticketpage,
            "ticket.php: 'declinereason' stays a scalar PARAM_RAW read - it is out of scope for the editor conversion"
        );
        $this->assertStringContainsString(
            '$noteformat = FORMAT_MOODLE;',
            $ticketpage,
            "ticket.php: decline's format stays the hardcoded constant it always was"
        );
        // Note (refer/escalate): unchanged, still the hand-rolled
        // textarea and still a scalar PARAM_RAW read, twice over (1.20.44
        // gave refer() and escalate() each their own note field).
        $this->assertSame(
            2,
            substr_count($ticketpage, "optional_param('note', '', PARAM_RAW)"),
            'ticket.php: refer() and escalate() must each still read their own note field as a scalar PARAM_RAW'
        );
        $this->assertStringContainsString(
            'tickets::refer($activity, $t, $targetid, $note, FORMAT_MOODLE,',
            $ticketpage,
            'ticket.php: refer() stays hardcoded FORMAT_MOODLE - out of scope for the editor conversion'
        );
        $this->assertStringContainsString(
            'tickets::escalate($activity, $t, $note, FORMAT_MOODLE,',
            $ticketpage,
            'ticket.php: escalate() stays hardcoded FORMAT_MOODLE - out of scope for the editor conversion'
        );
        // The four call sites whose format now comes from the editor:
        // request_info(), grant_guidecap(), the resolve arm of close(),
        // provide_info() - each must pass its OWN derived-format
        // variable, never a hardcoded constant.
        $this->assertStringContainsString(
            'tickets::request_info($activity, $t, $question, $questionformat,',
            $ticketpage,
            'ticket.php: request_info() must be called with the format the editor returned'
        );
        $this->assertStringContainsString(
            'tickets::grant_guidecap($activity, $t, $note, $noteformat,',
            $ticketpage,
            'ticket.php: grant_guidecap() must be called with the format the editor returned'
        );
        $this->assertStringContainsString(
            'tickets::close($activity, $t, $outcome, $note, $noteformat,',
            $ticketpage,
            'ticket.php: close() must be called with the resolve arm\'s derived format, never a hardcoded constant'
        );
        $this->assertStringContainsString(
            'tickets::provide_info($activity, $t, $reply, $replyformat,',
            $ticketpage,
            'ticket.php: provide_info() must be called with the format the editor returned'
        );
        // FORMAT_MOODLE may still appear, but ONLY in the four places
        // that are genuinely unaffected by this slice (refer, escalate,
        // decline's hardcoded constant) or as a ??-fallback default on
        // the four converted fields (question, resolution x2, reply) -
        // never as a hardcoded call-site argument on a converted field.
        $this->assertSame(
            7,
            substr_count($ticketpage, 'FORMAT_MOODLE'),
            'ticket.php: refer(1) + escalate(1) + decline(1) + four ??-fallback defaults(4) = 7, no more, no less'
        );
    }

    /**
     * A file's source with comments stripped and whitespace collapsed to
     * single spaces - so a source pin cannot be defeated, or inflated,
     * by the comment prose sitting next to the code it explains. Same
     * idiom as contactprivacy_matrix_test.php's private helper of the
     * same name; duplicated rather than shared because that class does
     * not expose it and the two suites otherwise stay decoupled.
     *
     * @param string $path absolute path to a PHP source file
     * @return string normalised source
     */
    private static function normalised_executable_source(string $path): string {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \coding_exception('Unreadable source file: ' . $path);
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
}
