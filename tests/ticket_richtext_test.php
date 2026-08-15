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
     */
    public function test_the_five_call_sites_read_raw_and_pass_format_moodle(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);

        // Comments stripped, whitespace collapsed - so this file's own
        // WHY-comments (which say "FORMAT_MOODLE" and "PARAM_RAW" in
        // plain English right next to the code they explain) cannot
        // inflate the counts below. Same idiom as
        // contactprivacy_matrix_test.php::normalised_executable_source().
        $group = self::normalised_executable_source($root . '/group.php');
        $this->assertStringContainsString(
            "\$reason = optional_param('reason', '', PARAM_RAW);",
            $group,
            'group.php: the ticket-filing reason must be read PARAM_RAW, not PARAM_TEXT'
        );
        $this->assertStringContainsString(
            'tickets::file( $activity, $group, $tickettype, $reason, FORMAT_MOODLE,',
            $group,
            'group.php: file() must be called with FORMAT_MOODLE, not the hardcoded FORMAT_PLAIN'
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

        // B2: ticket.php holds the four FORMAT_MOODLE call sites the
        // queue's forms moved to - request_info(), grant_guidecap(), the
        // shared close() resolve/decline call, and provide_info() - and
        // none of the four textareas that feed them (question,
        // resolution, declinereason, reply) may fall back to PARAM_TEXT.
        $ticketpage = self::normalised_executable_source($root . '/ticket.php');
        $this->assertSame(
            4,
            substr_count($ticketpage, 'FORMAT_MOODLE'),
            'ticket.php: request_info(), grant_guidecap(), close() and provide_info() must all pass FORMAT_MOODLE'
        );
        $this->assertStringNotContainsString(
            'PARAM_TEXT',
            $ticketpage,
            'ticket.php: every thread textarea must be read PARAM_RAW, never PARAM_TEXT'
        );
        foreach (['question', 'resolution', 'declinereason', 'reply'] as $field) {
            $this->assertStringContainsString(
                "optional_param('$field', '', PARAM_RAW)",
                $ticketpage,
                "ticket.php: the '$field' textarea must be read PARAM_RAW"
            );
        }
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
