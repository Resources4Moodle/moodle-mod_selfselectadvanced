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

namespace mod_selfselectadvanced\local;

use mod_selfselectadvanced\activity;
use stdClass;

/**
 * The knowledgebank (1.20.45): FAQs the queue grows on its own, by the
 * maintainer's own model - "when staff resolve a ticket, they can tick
 * 'publish as FAQ' and edit the public wording (anonymised - the
 * requester and group stripped), with an interface for the addition of
 * articles to the KB that people have not asked. The LLM friendly model
 * should be used."
 *
 * Two ways a row is born: publish_from_ticket() (sourceticketid names
 * the origin) and create() (sourceticketid 0, "authored directly" -
 * the interface for articles nobody has asked). Both take a STAFF-
 * EDITED draft and never auto-copy a ticket's raw text - publishing is
 * a second, deliberate step, never a side effect of resolving.
 *
 * ANONYMISATION IS SERVICE-ENFORCED (guard_anonymisation()), not just
 * form guidance: crude by design (a case-insensitive substring test for
 * the requester's fullname and the group's name), because the human
 * edit is the real anonymiser and this only catches the lazy path -
 * pasting the resolution in unedited.
 *
 * export_entry() is THE single serialiser every public reader goes
 * through, in this release's own UI and in the 1.20.46 LLM API that
 * pins against its exact key set: sourceticketid and every author
 * userid are never in it, and the answer travels as both answerhtml
 * (format_text) and answertext (html_to_text) so a consumer never has
 * to parse HTML.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class kb {
    /**
     * @var string search()'s sentinel for "tickettype is exactly the
     *      empty string" (audit B5/M-4/M-18/M-23), as distinct from ''
     *      itself, which search() has always meant as "no type filter -
     *      every type, including general". A caller that wants the
     *      untyped GROUP alone (kb_page's student view, sectioning by
     *      type) had no way to ask for that until this constant existed;
     *      passing '' for that purpose silently asked for "everything"
     *      instead and duplicated every typed article under the "General"
     *      heading. Never a real tickettype value - tickets::known_types()
     *      cannot collide with it.
     */
    public const TYPE_GENERAL = '__general__';

    /** @var int deflect()'s top-N result cap (spec: "top N (5)"). */
    public const DEFLECT_LIMIT = 5;

    /**
     * @var int The shortest word deflect()'s keyword extraction keeps.
     *      Spec: "keywords extracted from $text (split, lowercase, drop
     *      stopwords < 4 chars)" - cheap by design, no full-text engine
     *      and no maintained stopword dictionary: a length floor alone
     *      already drops most English function words (a, is, the, and,
     *      for, not...) without a list a second language would need its
     *      own copy of.
     */
    private const KEYWORD_MINLEN = 4;

    /**
     * Publish a resolved ticket's staff-edited wording as an FAQ.
     *
     * Authority: queue authority (tickets::require_queue_authority()).
     * The ticket must be RESOLVED - a claimed, declined or still-open
     * ticket has no settled answer to publish. The draft is the STAFF-
     * EDITED wording, never the ticket's own raw request/resolution
     * text - publishing is a deliberate second step.
     *
     * Logs a ticketlog entry (tickets::ACTION_PUBLISHED_FAQ, 'no public
     * link back' - staff-internal, never narrated to the requester) and
     * fires kb_entry_created with other = [sourceticketid, tickettype].
     *
     * @param activity $activity the activity
     * @param int $ticketid the resolved ticket
     * @param int $userid the staff member publishing
     * @param array $draft title, question, answer (+ formats), tickettype,
     *        keywords, published - see normalise_draft()
     * @return stdClass the created kb row
     * @throws \moodle_exception when refused
     */
    public static function publish_from_ticket(activity $activity, int $ticketid, int $userid, array $draft): stdClass {
        global $DB;

        tickets::require_queue_authority($activity, $userid);
        $ticket = tickets::get($activity, $ticketid);
        if ($ticket->status !== tickets::STATUS_RESOLVED) {
            throw new workflow_refusal('refusalkbnotresolved', 'mod_selfselectadvanced');
        }

        $normalised = self::normalise_draft($draft, null, $ticket->type);
        // ANONYMISATION IS SERVICE-ENFORCED (class docblock): asked
        // against the STAFF-EDITED wording that is about to be stored,
        // not the ticket's own raw text.
        self::guard_anonymisation($activity, $ticket, $normalised['title'], $normalised['question'], $normalised['answer']);

        $lock = locks::acquire('ticket:' . $ticketid);
        try {
            $transaction = $DB->start_delegated_transaction();

            // Re-read INSIDE the lock (house rule A7), like every other
            // mutation in this codebase that acts on a ticket row - a
            // resolved ticket is terminal (close() opens no path back
            // out of it), so this is defence in depth rather than a
            // race this method could actually hit today.
            $fresh = $DB->get_record('selfselectadvanced_ticket', ['id' => $ticketid], '*', MUST_EXIST);
            if ((int) $fresh->activityid !== $activity->id()) {
                throw new \moodle_exception('errticketnotfound', 'mod_selfselectadvanced');
            }
            if ($fresh->status !== tickets::STATUS_RESOLVED) {
                throw new workflow_refusal('refusalkbnotresolved', 'mod_selfselectadvanced');
            }

            $now = time();
            $entry = (object) array_merge($normalised, [
                'activityid' => $activity->id(),
                'sourceticketid' => $ticketid,
                'usercreated' => $userid,
                'usermodified' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $entry->id = $DB->insert_record('selfselectadvanced_kb', $entry);

            // The trail row: staff-internal, no note, no public link
            // back (tickets::note_published_faq()'s own docblock).
            tickets::note_published_faq($ticketid, $userid);

            $event = \mod_selfselectadvanced\event\kb_entry_created::create([
                'objectid' => $entry->id,
                'context' => $activity->context(),
                'other' => [
                    'sourceticketid' => $ticketid,
                    'tickettype' => $entry->tickettype,
                ],
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            self::rollback($transaction ?? null, $e);
        } finally {
            $lock->release();
        }

        $event->trigger();

        return $entry;
    }

    /**
     * Add an article nobody has asked for - the direct-add interface
     * the maintainer's model names alongside publish_from_ticket().
     * sourceticketid 0 ("authored directly").
     *
     * @param activity $activity the activity
     * @param int $userid the staff member authoring it
     * @param array $draft see normalise_draft()
     * @return stdClass the created kb row
     * @throws \moodle_exception when refused
     */
    public static function create(activity $activity, int $userid, array $draft): stdClass {
        global $DB;

        tickets::require_queue_authority($activity, $userid);
        $normalised = self::normalise_draft($draft);

        $now = time();
        $entry = (object) array_merge($normalised, [
            'activityid' => $activity->id(),
            'sourceticketid' => 0,
            'usercreated' => $userid,
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $entry->id = $DB->insert_record('selfselectadvanced_kb', $entry);

        \mod_selfselectadvanced\event\kb_entry_created::create([
            'objectid' => $entry->id,
            'context' => $activity->context(),
            'other' => [
                'sourceticketid' => 0,
                'tickettype' => $entry->tickettype,
            ],
        ])->trigger();

        return $entry;
    }

    /**
     * Edit an existing entry. $draft is PARTIAL: any of the
     * normalise_draft() keys may be omitted, and an omitted key keeps
     * the row's current value - the one form kb.php uses for both a
     * full edit and a bare republish (['published' => 1]).
     *
     * ANONYMISATION IS SERVICE-ENFORCED HERE TOO (audit B6/M-22): when the
     * entry was published FROM a ticket (sourceticketid > 0),
     * publish_from_ticket()'s own guard_anonymisation() runs again against
     * the wording this call is ABOUT to store - editing a published FAQ is
     * exactly as capable of naming the requester as the original publish
     * was, and until this fix it was the one write path that never asked.
     *
     * @param activity $activity the activity
     * @param int $id the entry
     * @param array $draft see normalise_draft()
     * @param int $userid the staff member editing it
     * @return stdClass the updated kb row
     * @throws \moodle_exception when refused
     */
    public static function update(activity $activity, int $id, array $draft, int $userid): stdClass {
        global $DB;

        tickets::require_queue_authority($activity, $userid);
        $current = self::get($activity, $id);
        $normalised = self::normalise_draft($draft, $current);

        if ((int) $current->sourceticketid > 0) {
            $sourceticket = tickets::get($activity, (int) $current->sourceticketid);
            self::guard_anonymisation(
                $activity,
                $sourceticket,
                $normalised['title'],
                $normalised['question'],
                $normalised['answer']
            );
        }

        $entry = (object) array_merge((array) $current, $normalised, [
            'usermodified' => $userid,
            'timemodified' => time(),
        ]);
        $DB->update_record('selfselectadvanced_kb', $entry);

        \mod_selfselectadvanced\event\kb_entry_updated::create([
            'objectid' => $entry->id,
            'context' => $activity->context(),
            'other' => ['tickettype' => $entry->tickettype],
        ])->trigger();

        return $entry;
    }

    /**
     * Hide an entry from the public search/browse without deleting it -
     * still visible on the staff list.
     *
     * @param activity $activity the activity
     * @param int $id the entry
     * @param int $userid the staff member unpublishing it
     * @return stdClass the updated kb row
     * @throws \moodle_exception when refused
     */
    public static function unpublish(activity $activity, int $id, int $userid): stdClass {
        global $DB;

        tickets::require_queue_authority($activity, $userid);
        $entry = self::get($activity, $id);
        $entry->published = 0;
        $entry->usermodified = $userid;
        $entry->timemodified = time();
        $DB->update_record('selfselectadvanced_kb', $entry);

        \mod_selfselectadvanced\event\kb_entry_unpublished::create([
            'objectid' => $entry->id,
            'context' => $activity->context(),
            'other' => ['tickettype' => $entry->tickettype],
        ])->trigger();

        return $entry;
    }

    /**
     * Delete an entry outright.
     *
     * @param activity $activity the activity
     * @param int $id the entry
     * @param int $userid the staff member deleting it
     * @throws \moodle_exception when refused
     */
    public static function delete(activity $activity, int $id, int $userid): void {
        global $DB;

        tickets::require_queue_authority($activity, $userid);
        $entry = self::get($activity, $id);
        $DB->delete_records('selfselectadvanced_kb', ['id' => $id]);

        \mod_selfselectadvanced\event\kb_entry_deleted::create([
            'objectid' => $id,
            'context' => $activity->context(),
            'other' => ['tickettype' => $entry->tickettype],
        ])->trigger();
    }

    /**
     * One entry, owned by this activity.
     *
     * @param activity $activity the activity
     * @param int $id the entry
     * @return stdClass the row
     * @throws \moodle_exception if it belongs to another activity or does not exist
     */
    public static function get(activity $activity, int $id): stdClass {
        global $DB;

        $row = $DB->get_record('selfselectadvanced_kb', ['id' => $id], '*', MUST_EXIST);
        if ((int) $row->activityid !== $activity->id()) {
            throw new \moodle_exception('errkbnotfound', 'mod_selfselectadvanced');
        }

        return $row;
    }

    /**
     * Whether the activity has at least one published entry - the
     * landing page's "Common questions" link is drawn only then.
     *
     * @param activity $activity the activity
     * @return bool
     */
    public static function has_published(activity $activity): bool {
        global $DB;

        return $DB->record_exists('selfselectadvanced_kb', ['activityid' => $activity->id(), 'published' => 1]);
    }

    /**
     * Browse/search. Type match + LIKE on title/keywords, both engines
     * (sql_like with bound params). Public callers ALWAYS
     * $publishedonly - the staff list is the one caller that passes
     * false, to see unpublished entries too.
     *
     * @param activity $activity the activity
     * @param string $type tickets::TYPE_*, '' for every type (including
     *        general), or self::TYPE_GENERAL for "tickettype is exactly ''"
     * @param string $q free text, matched against title and keywords; '' matches everything
     * @param bool $publishedonly true for every public caller
     * @return stdClass[] id => row, newest-edited first
     * @throws \coding_exception if $type is not empty, not TYPE_GENERAL and not a known type
     */
    public static function search(activity $activity, string $type, string $q, bool $publishedonly = true): array {
        global $DB;

        if ($type !== '' && $type !== self::TYPE_GENERAL && !in_array($type, tickets::known_types(), true)) {
            throw new \coding_exception('Unknown knowledgebank type filter ' . $type);
        }

        $conditions = ['activityid = :activityid'];
        $params = ['activityid' => $activity->id()];
        if ($type === self::TYPE_GENERAL) {
            $conditions[] = "tickettype = ''";
        } else if ($type !== '') {
            $conditions[] = 'tickettype = :tickettype';
            $params['tickettype'] = $type;
        }
        if ($publishedonly) {
            $conditions[] = 'published = 1';
        }
        $q = trim($q);
        if ($q !== '') {
            $conditions[] = '(' . $DB->sql_like('title', ':qtitle', false) . ' OR '
                . $DB->sql_like('keywords', ':qkeywords', false) . ')';
            $like = '%' . $DB->sql_like_escape($q) . '%';
            $params['qtitle'] = $like;
            $params['qkeywords'] = $like;
        }

        return $DB->get_records_select(
            'selfselectadvanced_kb',
            implode(' AND ', $conditions),
            $params,
            'timemodified DESC'
        );
    }

    /**
     * Top matches for the filing screen (spec: "top N (5) matches...
     * cheap by design; no full-text engine"). Type filter + keywords
     * extracted from $text; ALWAYS published-only (this is a public
     * filing surface). An empty $text (or one with no keyword-length
     * word) still returns the type's most recently edited entries -
     * useful the moment the filing screen renders, before anything has
     * been typed.
     *
     * @param activity $activity the activity
     * @param string $type tickets::TYPE_*, or '' for general/unscoped filing
     * @param string $text the requester's own words so far
     * @return stdClass[] id => row, at most self::DEFLECT_LIMIT
     * @throws \coding_exception if $type is not empty and not a known type
     */
    public static function deflect(activity $activity, string $type, string $text): array {
        global $DB;

        if ($type !== '' && !in_array($type, tickets::known_types(), true)) {
            throw new \coding_exception('Unknown knowledgebank type filter ' . $type);
        }

        $conditions = ['activityid = :activityid', 'published = 1'];
        $params = ['activityid' => $activity->id()];
        if ($type !== '') {
            $conditions[] = 'tickettype = :tickettype';
            $params['tickettype'] = $type;
        }

        $keywords = self::extract_keywords($text);
        if ($keywords) {
            $ors = [];
            foreach (array_values($keywords) as $i => $word) {
                $ors[] = $DB->sql_like('title', ":kwt{$i}", false) . ' OR ' . $DB->sql_like('keywords', ":kwk{$i}", false);
                $like = '%' . $DB->sql_like_escape($word) . '%';
                $params["kwt{$i}"] = $like;
                $params["kwk{$i}"] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $ors) . ')';
        }

        return $DB->get_records_select(
            'selfselectadvanced_kb',
            implode(' AND ', $conditions),
            $params,
            'timemodified DESC',
            '*',
            0,
            self::DEFLECT_LIMIT
        );
    }

    /**
     * THE single serialiser every public reader of a kb row goes
     * through (class docblock) - this release's own UI, and the
     * 1.20.46 LLM API that pins against this exact key set.
     *
     * NEVER in the return value: sourceticketid, usercreated,
     * usermodified, activityid, published, id of anything but the entry
     * itself - no provenance, no author, nothing that could let a
     * consumer trace an anonymised article back to who asked or who
     * answered.
     *
     * @param stdClass $row a selfselectadvanced_kb row
     * @return array{
     *     id: int, title: string, question: string, answerhtml: string,
     *     answertext: string, type: string, keywords: string[], timemodified: int
     * }
     */
    public static function export_entry(stdClass $row): array {
        $rawkeywords = explode(',', (string) $row->keywords);
        $keywords = array_values(array_filter(array_map('trim', $rawkeywords), static fn($w) => $w !== ''));

        return [
            'id' => (int) $row->id,
            'title' => format_string((string) $row->title),
            'question' => trim(html_to_text((string) $row->question)),
            // Deliberately no context option on format_text() below:
            // export_entry() takes only the row (class docblock, and the
            // spec's own signature), so this can be called from outside
            // any page's context - the 1.20.46 API's whole point. Content
            // is still purified through clean_text(); only context-bound
            // FILTERS (multilang and friends) are skipped.
            'answerhtml' => format_text((string) $row->answer, (int) $row->answerformat),
            'answertext' => trim(html_to_text((string) $row->answer)),
            'type' => (string) $row->tickettype,
            'keywords' => $keywords,
            'timemodified' => (int) $row->timemodified,
        ];
    }

    /**
     * Validate and normalise a caller's draft into every column
     * normalise_draft() itself is responsible for. $current, when
     * given, supplies the fallback for any key $draft omits (update()'s
     * partial-edit contract); $fallbacktype supplies the tickettype
     * default when there is no $current either (publish_from_ticket()'s
     * "type label" default, per the resolve form's pre-fill).
     *
     * @param array $draft title, question, questionformat, answer,
     *        answerformat, tickettype, keywords, published - any subset
     * @param stdClass|null $current the existing row, for update()'s
     *        partial-edit fallback; null for a brand new entry
     * @param string $fallbacktype tickettype default when neither
     *        $draft nor $current supplies one
     * @return array title, question, questionformat, answer,
     *         answerformat, tickettype, keywords, published
     * @throws \moodle_exception refusalkbincomplete when title,
     *         question or answer is empty
     * @throws \coding_exception if tickettype is set and not one
     *         tickets::known_types() recognises
     */
    private static function normalise_draft(array $draft, ?stdClass $current = null, string $fallbacktype = ''): array {
        $title = array_key_exists('title', $draft) ? trim((string) $draft['title']) : trim((string) ($current->title ?? ''));
        $title = \core_text::substr($title, 0, 255);
        $question = array_key_exists('question', $draft) ? (string) $draft['question'] : (string) ($current->question ?? '');
        $questionformat = (int) ($draft['questionformat'] ?? ($current->questionformat ?? FORMAT_MOODLE));
        $answer = array_key_exists('answer', $draft) ? (string) $draft['answer'] : (string) ($current->answer ?? '');
        $answerformat = (int) ($draft['answerformat'] ?? ($current->answerformat ?? FORMAT_MOODLE));
        $tickettype = array_key_exists('tickettype', $draft)
            ? (string) $draft['tickettype']
            : (string) ($current->tickettype ?? $fallbacktype);
        $keywords = array_key_exists('keywords', $draft)
            ? self::normalise_keywords((string) $draft['keywords'])
            : (string) ($current->keywords ?? '');
        $published = array_key_exists('published', $draft)
            ? (int) (bool) $draft['published']
            : (int) ($current->published ?? 1);

        if ($title === '' || trim(html_to_text($question)) === '' || trim(html_to_text($answer)) === '') {
            throw new workflow_refusal('refusalkbincomplete', 'mod_selfselectadvanced');
        }
        if ($tickettype !== '' && !in_array($tickettype, tickets::known_types(), true)) {
            throw new \coding_exception('Unknown knowledgebank ticket type ' . $tickettype);
        }

        return [
            'title' => $title,
            'question' => $question,
            'questionformat' => $questionformat,
            'answer' => $answer,
            'answerformat' => $answerformat,
            'tickettype' => $tickettype,
            'keywords' => $keywords,
            'published' => $published,
        ];
    }

    /**
     * Comma-separated keywords, normalised to the schema's own contract
     * (char 255, comma-separated, lowercase): trimmed, lowercased,
     * de-duplicated, empty tokens dropped, truncated defensively to fit
     * the column (a PostgreSQL overlong-insert error is loud here,
     * unlike upgrade_log()'s own gotcha, but truncating client-side is
     * cheaper than a round trip that fails).
     *
     * @param string $raw the caller's raw keywords string
     * @return string
     */
    private static function normalise_keywords(string $raw): string {
        $parts = array_values(array_unique(array_filter(
            array_map('trim', explode(',', strtolower($raw))),
            static fn($part) => $part !== ''
        )));

        return \core_text::substr(implode(', ', $parts), 0, 255);
    }

    /**
     * Split $text into deflect()'s keyword set - split, lowercase, drop
     * anything shorter than KEYWORD_MINLEN (this method's own docblock
     * reasoning). $text may be any format; html_to_text() cleans it the
     * same way the emptiness checks elsewhere in this plugin do.
     *
     * @param string $text raw text, any format
     * @return string[] distinct lowercase words, KEYWORD_MINLEN or longer
     */
    private static function extract_keywords(string $text): array {
        $plain = strtolower(trim(html_to_text($text)));
        if ($plain === '') {
            return [];
        }

        $words = preg_split('/[^a-z0-9]+/', $plain, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = [];
        foreach ($words as $word) {
            if (\core_text::strlen($word) < self::KEYWORD_MINLEN) {
                continue;
            }
            $keywords[$word] = $word;
        }

        return array_values($keywords);
    }

    /**
     * Refuse to publish wording that still names the requester or the
     * group verbatim (case-insensitive) - crude by design (class
     * docblock): the human edit is the real anonymiser, this only
     * catches the lazy paste-it-in-unedited path.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the source ticket
     * @param string $title the staff-edited title
     * @param string $question the staff-edited question
     * @param string $answer the staff-edited answer
     * @throws \moodle_exception refusalkbanonymisation
     */
    private static function guard_anonymisation(
        activity $activity,
        stdClass $ticket,
        string $title,
        string $question,
        string $answer
    ): void {
        $haystack = strtolower(trim(html_to_text($title . ' ' . $question . ' ' . $answer)));
        if ($haystack === '') {
            return;
        }

        $requester = \core_user::get_user((int) $ticket->requestedby);
        if ($requester) {
            $fullname = strtolower(trim(fullname($requester)));
            if ($fullname !== '' && str_contains($haystack, $fullname)) {
                throw new workflow_refusal('refusalkbanonymisation', 'mod_selfselectadvanced');
            }
        }

        $group = tickets::group_of($activity, $ticket);
        if ($group !== null) {
            $groupname = strtolower(trim((string) $group->name));
            if ($groupname !== '' && str_contains($haystack, $groupname)) {
                throw new workflow_refusal('refusalkbanonymisation', 'mod_selfselectadvanced');
            }
        }
    }

    /**
     * Roll back a transaction and re-throw - the same shape tickets.php
     * keeps for its own mutations, duplicated here rather than shared
     * because tickets::rollback() is private to that class.
     *
     * @param \moodle_transaction|null $transaction the open transaction, if any
     * @param \Throwable $e the exception that triggered the rollback
     * @throws \Throwable always - $e itself
     */
    private static function rollback(?\moodle_transaction $transaction, \Throwable $e): void {
        if ($transaction !== null && !$transaction->is_disposed()) {
            $transaction->rollback($e);
        }

        throw $e;
    }
}
