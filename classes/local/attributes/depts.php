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

namespace mod_selfselectadvanced\local\attributes;

use mod_selfselectadvanced\local\locks;
use mod_selfselectadvanced\local\workflow_refusal;
use moodle_exception;
use stdClass;

/**
 * Pre-defined department/sub-department vocabulary.
 *
 * Site-wide tree in the course-categories format (parent, depth, path,
 * sortorder), so multiple levels are possible; the participant
 * attribute fields use level 1 (department) and level 2
 * (sub-department). Attribute rows keep storing the plain names, so
 * quota dimensions and existing data are unaffected — this table is
 * the allowed vocabulary, enforced at the edit form and the CSV
 * importer.
 *
 * Every write takes the actor explicitly and asks the ingest authority
 * in-service (AUTH-001, 1.20.4): the vocabulary is site-wide, so the
 * capability is asked at the system context, exactly the seam
 * csv_importer::run() crosses. Until now the admin page was the only
 * gate and every one of these writes landed for any direct caller.
 *
 * Every write is also SERIALISED and ATOMIC (M-06, 1.20.5). The
 * vocabulary has no unique index behind it, so uniqueness was decided
 * by a read that nothing stopped a second writer from overtaking: two
 * administrators adding "Engineering" at the same instant each found
 * no clash and each inserted one, and every subsequent lookup by name
 * met two rows. The same read-then-write shape produced duplicate
 * sortorder values (MAX + 1 computed twice) and let two move() calls
 * on one pair of siblings interleave into a swap that lost an order.
 * The house pattern applies unchanged: authority, then the lock, then
 * the re-read and every refusal, then the transaction, then the write,
 * with the audit event triggered after the commit AND the release.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class depts {
    /**
     * The one lock every vocabulary write takes.
     *
     * The vocabulary is site-wide and has no activity, so it takes the
     * activity-rank lock on the reserved instance id 0: no real
     * activity has id 0, so this key can never contend with a genuine
     * activity lock, and nothing on these paths holds a second plugin
     * lock, so it can never invert the global order either
     * (locks::ORDER). One key for the whole tree rather than one per
     * node, because the races are BETWEEN nodes - a duplicate name, a
     * shared sortorder, a sibling swap.
     */
    private const LOCK = 'activity:0';

    /**
     * Refuse unless this actor holds the ingest authority.
     *
     * One seam for every vocabulary write. The capability and context
     * are the ones the admin page is declared with (settings.php) and
     * the CSV importer asks for: mod/selfselectadvanced:ingestattributes
     * at the system context, because the tree is site-wide.
     *
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the authority is not effective
     */
    private static function require_ingest(int $actorid): void {
        require_capability(manager::INGEST, \context_system::instance(), $actorid);
    }
    /**
     * All categories ordered as a depth-first tree.
     *
     * @return stdClass[] records ordered for display, id-keyed
     */
    public static function get_all(): array {
        global $DB;

        $records = $DB->get_records('selfselectadvanced_dept', ['kind' => 'dept'], 'sortorder, id');
        // Depth-first order: sort by path of sortorders.
        $bypath = [];
        foreach ($records as $record) {
            $key = '';
            foreach (explode('/', trim($record->path, '/')) as $ancestorid) {
                $key .= sprintf('%08d/', $records[$ancestorid]->sortorder ?? 0) . sprintf('%08d/', $ancestorid);
            }
            $bypath[$key] = $record;
        }
        ksort($bypath);
        $ordered = [];
        foreach ($bypath as $record) {
            $ordered[$record->id] = $record;
        }

        return $ordered;
    }

    /**
     * Judge a proposed node: the name, the parent's existence and the
     * absence of a clash. Every refusal of the insert lives here so a
     * caller can ask it under the lock but BEFORE opening a
     * transaction, which is what keeps a refused create() from
     * disposing anybody's delegated frame.
     *
     * @param string $name the proposed name
     * @param int $parent parent category id, 0 for top level
     * @return array [trimmed name, parent record or null]
     * @throws moodle_exception on an unusable name or a duplicate
     */
    private static function validate_node(string $name, int $parent): array {
        global $DB;

        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 100) {
            throw new moodle_exception('errdeptname', 'mod_selfselectadvanced');
        }
        $parentrecord = null;
        if ($parent) {
            $parentrecord = $DB->get_record('selfselectadvanced_dept', ['id' => $parent], '*', MUST_EXIST);
        }
        if ($DB->record_exists('selfselectadvanced_dept', ['parent' => $parent, 'name' => $name])) {
            throw new workflow_refusal('errdeptduplicate', 'mod_selfselectadvanced', '', $name);
        }

        return [$name, $parentrecord];
    }

    /**
     * The raw category insert: tree placement and path, no judgement.
     *
     * Internal on purpose, and the WRITE half only - validate_node()
     * has already spoken. The caller runs this inside its transaction.
     *
     * @param string $name the validated, trimmed name
     * @param stdClass|null $parentrecord the parent row, null for top level
     * @param int $parent parent category id, 0 for top level
     * @return stdClass the new record
     */
    private static function write_node(string $name, ?stdClass $parentrecord, int $parent): stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'name' => $name,
            'parent' => $parent,
            'depth' => $parentrecord ? (int) $parentrecord->depth + 1 : 1,
            'path' => '',
            'sortorder' => 1 + (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(sortorder), 0) FROM {selfselectadvanced_dept} WHERE parent = ?',
                [$parent]
            ),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->kind = 'dept';
        $record->id = $DB->insert_record('selfselectadvanced_dept', $record);
        $record->path = ($parentrecord ? $parentrecord->path : '') . '/' . $record->id;
        $DB->set_field('selfselectadvanced_dept', 'path', $record->path, ['id' => $record->id]);

        return $record;
    }

    /**
     * Judge and insert in one step, for the callers that are already
     * inside a transaction of their own (bulk_add(), ensure()) and
     * report a refusal per line rather than by unwinding.
     *
     * @param string $name the name
     * @param int $parent parent category id, 0 for top level
     * @return stdClass the new record
     * @throws moodle_exception on an unusable name or a duplicate
     */
    private static function insert_node(string $name, int $parent): stdClass {
        [$name, $parentrecord] = self::validate_node($name, $parent);

        return self::write_node($name, $parentrecord, $parent);
    }

    /**
     * Create a category.
     *
     * @param string $name the name
     * @param int $parent parent category id, 0 for top level
     * @param int $actorid the acting user
     * @return stdClass the new record
     * @throws \required_capability_exception when the actor lacks the ingest authority
     * @throws moodle_exception on an unusable name or a duplicate
     */
    public static function create(string $name, int $parent, int $actorid): stdClass {
        global $DB;

        // Authority BEFORE the lock, refusals before the transaction:
        // a create nobody was entitled to make must not so much as
        // queue for the lock, and a duplicate name must not dispose a
        // delegated frame on its way out.
        self::require_ingest($actorid);
        $lock = locks::acquire(self::LOCK);
        try {
            [$name, $parentrecord] = self::validate_node($name, $parent);
            $transaction = $DB->start_delegated_transaction();
            // Insert and path fix-up are one write: a crash between
            // them left a node with an empty path, which get_all()
            // then sorted to the front of the whole tree.
            $record = self::write_node($name, $parentrecord, $parent);
            // Payload built inside, dispatched outside (the binding
            // rule for new code; override\store::save() is the worked
            // example).
            $event = self::vocabulary_event(
                \mod_selfselectadvanced\event\dept_created::class,
                $record,
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Unconditional, never gated on is_transaction_started():
            // under PHPUnit that predicate answers for
            // advanced_testcase (true on m5pg, false on m5my) rather
            // than for this method. See handover::propose().
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        // A vocabulary change is a state change an operator audits
        // (LOG-001, 1.20.4). After the commit AND the release.
        $event->trigger();

        return $record;
    }

    /**
     * Build one vocabulary audit event; the caller triggers it after
     * its own writes (and, for bulk_add(), after its commit).
     *
     * @param string $eventclass dept_created, dept_updated or dept_deleted
     * @param stdClass $record the vocabulary row the event is about
     * @param int $actorid the acting user
     * @return \core\event\base the initialised event
     */
    private static function vocabulary_event(string $eventclass, stdClass $record, int $actorid): \core\event\base {
        return $eventclass::create([
            'objectid' => (int) $record->id,
            'context' => \context_system::instance(),
            'userid' => $actorid,
            'other' => ['name' => $record->name, 'kind' => $record->kind],
        ]);
    }

    /**
     * Ensure a department (and optional sub-department) exist,
     * creating missing nodes — the admin-level auto-create used by the
     * CSV importer (2026-07-24 change: admins define by ingesting).
     *
     * Fires no vocabulary event, deliberately: this runs inside the
     * importer's open transaction, where the binding rule forbids a
     * trigger, and the ingest already has its own audit trail — a
     * per-line "created" warning in the report and the
     * attributes_imported event. The UI writes (create() and friends)
     * are the evented paths.
     *
     * Takes no lock either, and for the same reason: csv_importer::run()
     * has a delegated transaction open around every call to this
     * method, and acquiring a lock inside somebody else's transaction
     * is how a lock ends up held for the whole of a long import and
     * released only when that import commits. Stated rather than
     * hidden, because it is a real residue: this method's
     * find-then-insert can still lose a race with a concurrent
     * create(). Closing it properly means the IMPORTER taking
     * depts::LOCK before it opens its transaction, which is
     * csv_importer's change to make, not this class's.
     *
     * @param string $department department name
     * @param string $subdepartment sub-department name ('' = none)
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the actor lacks the ingest authority
     */
    public static function ensure(string $department, string $subdepartment, int $actorid): void {
        global $DB;

        self::require_ingest($actorid);
        $department = trim($department);
        if ($department === '') {
            return;
        }
        $parent = $DB->get_record('selfselectadvanced_dept', [
            'kind' => 'dept',
            'parent' => 0,
            'name' => $department,
        ]);
        if (!$parent) {
            $parent = self::insert_node($department, 0);
        }
        $subdepartment = trim($subdepartment);
        if ($subdepartment === '') {
            return;
        }
        $exists = $DB->record_exists('selfselectadvanced_dept', [
            'kind' => 'dept',
            'parent' => (int) $parent->id,
            'name' => $subdepartment,
        ]);
        if (!$exists) {
            self::insert_node($subdepartment, (int) $parent->id);
        }
    }

    /**
     * The programme vocabulary (flat, kind=program rows).
     *
     * @return string[] name => name
     */
    public static function programs_menu(): array {
        global $DB;

        $menu = [];
        foreach ($DB->get_records('selfselectadvanced_dept', ['kind' => 'program'], 'sortorder, name') as $row) {
            $menu[$row->name] = $row->name;
        }

        return $menu;
    }

    /**
     * The raw programme insert; null when the name already exists.
     *
     * @param string $program the trimmed programme name
     * @return stdClass|null the new row, or null when nothing was created
     */
    private static function insert_program(string $program): ?stdClass {
        global $DB;

        if ($DB->record_exists('selfselectadvanced_dept', ['kind' => 'program', 'parent' => 0, 'name' => $program])) {
            return null;
        }
        $now = time();
        $record = (object) [
            'name' => $program,
            'kind' => 'program',
            'parent' => 0,
            'depth' => 1,
            'path' => '',
            'sortorder' => 1 + (int) $DB->count_records('selfselectadvanced_dept', ['kind' => 'program']),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('selfselectadvanced_dept', $record);
        $record->path = '/' . $record->id;
        $DB->set_field('selfselectadvanced_dept', 'path', $record->path, ['id' => $record->id]);

        return $record;
    }

    /**
     * Ensure a programme exists in the vocabulary (admin-level
     * auto-create on ingest). Unusable names are silently ignored, the
     * lenient importer semantics; the UI add is create_program(), which
     * refuses them loudly and leaves the audit event.
     *
     * No vocabulary event here for the same reason as ensure(): the
     * importer's transaction is open around this call.
     *
     * @param string $program the programme name
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the actor lacks the ingest authority
     */
    public static function ensure_program(string $program, int $actorid): void {
        self::require_ingest($actorid);
        $program = trim($program);
        if ($program === '' || \core_text::strlen($program) > 100) {
            return;
        }
        self::insert_program($program);
    }

    /**
     * Add a programme from the admin UI: the loud sibling of
     * ensure_program(). An empty or oversized name is refused rather
     * than swallowed, and a genuine creation leaves the vocabulary
     * audit event; asking for an existing name is idempotent and
     * leaves none.
     *
     * @param string $program the programme name
     * @param int $actorid the acting user
     * @return stdClass the programme row, new or existing
     * @throws \required_capability_exception when the actor lacks the ingest authority
     * @throws moodle_exception when the name is empty or over 100 characters
     */
    public static function create_program(string $program, int $actorid): stdClass {
        global $DB;

        self::require_ingest($actorid);
        $program = trim($program);
        if ($program === '' || \core_text::strlen($program) > 100) {
            throw new moodle_exception('errdeptname', 'mod_selfselectadvanced');
        }
        $lock = locks::acquire(self::LOCK);
        try {
            // The existence test and the insert are ONE decision, so
            // they are one transaction under one lock: taken apart,
            // two administrators adding the same programme both read
            // "absent" and both inserted, and programs_menu() - which
            // keys by name - then silently hid one of the two rows
            // while delete_program() could only ever remove the other.
            $transaction = $DB->start_delegated_transaction();
            $record = self::insert_program($program);
            $event = $record === null
                ? null
                : self::vocabulary_event(\mod_selfselectadvanced\event\dept_created::class, $record, $actorid);
            $existing = $record === null
                ? $DB->get_record(
                    'selfselectadvanced_dept',
                    ['kind' => 'program', 'parent' => 0, 'name' => $program],
                    '*',
                    MUST_EXIST
                )
                : null;
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        if ($record === null) {
            // Idempotent: asking for a programme that is already there
            // is not a change, so it leaves no audit event.
            return $existing;
        }
        $event->trigger();

        return $record;
    }

    /**
     * Rename a category.
     *
     * @param int $id category id
     * @param string $name new name
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the actor lacks the ingest authority
     * @throws moodle_exception on an unusable name or a clash with a sibling
     */
    public static function rename(int $id, string $name, int $actorid): void {
        global $DB;

        self::require_ingest($actorid);
        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > 100) {
            throw new moodle_exception('errdeptname', 'mod_selfselectadvanced');
        }
        $lock = locks::acquire(self::LOCK);
        try {
            // Re-read under the lock: the row and its siblings are read
            // AFTER the lock is held, so the clash test judges the tree
            // as it is now and not as it was when the form was drawn.
            $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id], '*', MUST_EXIST);
            $clash = $DB->get_record('selfselectadvanced_dept', ['parent' => $record->parent, 'name' => $name]);
            if ($clash && (int) $clash->id !== $id) {
                throw new workflow_refusal('errdeptduplicate', 'mod_selfselectadvanced', '', $name);
            }
            $transaction = $DB->start_delegated_transaction();
            $DB->update_record('selfselectadvanced_dept', (object) [
                'id' => $id,
                'name' => $name,
                'timemodified' => time(),
            ]);
            $record->name = $name;
            $event = self::vocabulary_event(
                \mod_selfselectadvanced\event\dept_updated::class,
                $record,
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $event->trigger();
    }

    /**
     * Delete a category; refused while it has children or ingested
     * attribute rows use its name at its level.
     *
     * @param int $id category id
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the actor lacks the ingest authority
     * @throws moodle_exception when the node has children or is still in use
     */
    public static function delete(int $id, int $actorid): void {
        global $DB;

        self::require_ingest($actorid);
        $lock = locks::acquire(self::LOCK);
        try {
            // Under the lock, the two guards below are decisive: without
            // it a create() could add the first child, or an import the
            // first attribute row, between the check and the delete.
            $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id], '*', MUST_EXIST);
            if ($record->kind === 'program') {
                // A programme row carries the schema-default depth 1, so the
                // department/subdepartment field guess below would run the
                // in-use check against the WRONG column and could delete a
                // programme still referenced (wave-2 blind audit, low 3).
                // Programmes have their own verb with their own guard.
                throw new \coding_exception('depts::delete() refuses programme rows; use delete_program()');
            }
            if ($DB->record_exists('selfselectadvanced_dept', ['parent' => $id])) {
                throw new workflow_refusal('errdeptchildren', 'mod_selfselectadvanced', '', $record->name);
            }
            $field = (int) $record->depth === 1 ? 'department' : 'subdepartment';
            $inuse = $DB->record_exists_select(
                'selfselectadvanced_userattr',
                $DB->sql_equal($field, ':name', false),
                ['name' => $record->name]
            );
            if ($inuse) {
                throw new workflow_refusal('errdeptinuse', 'mod_selfselectadvanced', '', $record->name);
            }
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records('selfselectadvanced_dept', ['id' => $id]);
            $event = self::vocabulary_event(
                \mod_selfselectadvanced\event\dept_deleted::class,
                $record,
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $event->trigger();
    }

    /**
     * Delete a programme from the vocabulary; refused while ingested
     * attribute rows carry its name.
     *
     * Moved here from departments.php, which deleted the row inline on
     * the page (AUTH-003, 1.20.4): a write the admin screen performs is
     * a write any direct caller could perform, so the authority, the
     * in-use guard and the audit event all live in the service now.
     *
     * @param int $id programme row id
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the actor lacks the ingest authority
     * @throws moodle_exception when attribute rows still use the programme
     */
    public static function delete_program(int $id, int $actorid): void {
        global $DB;

        self::require_ingest($actorid);
        $lock = locks::acquire(self::LOCK);
        try {
            $record = $DB->get_record(
                'selfselectadvanced_dept',
                ['id' => $id, 'kind' => 'program'],
                '*',
                MUST_EXIST
            );
            $inuse = $DB->record_exists_select(
                'selfselectadvanced_userattr',
                $DB->sql_equal('program', ':name', false),
                ['name' => $record->name]
            );
            if ($inuse) {
                throw new workflow_refusal('errdeptinuse', 'mod_selfselectadvanced', '', $record->name);
            }
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records('selfselectadvanced_dept', ['id' => $id]);
            $event = self::vocabulary_event(
                \mod_selfselectadvanced\event\dept_deleted::class,
                $record,
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $event->trigger();
    }

    /**
     * Swap a category with its previous/next sibling.
     *
     * @param int $id category id
     * @param int $direction -1 up, 1 down
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the actor lacks the ingest authority
     */
    public static function move(int $id, int $direction, int $actorid): void {
        global $DB;

        self::require_ingest($actorid);
        $lock = locks::acquire(self::LOCK);
        try {
            // A swap is two writes that are only meaningful together.
            // Unlocked and untransacted, two administrators reordering
            // one sibling list read the same pair of sortorders and
            // wrote them back interleaved, ending with BOTH rows
            // carrying the same value - after which get_all()'s order
            // fell back to the id and the buttons appeared to do
            // nothing. The re-read is inside the lock for the same
            // reason: the neighbour a page offered may not be the
            // neighbour any more.
            $record = $DB->get_record('selfselectadvanced_dept', ['id' => $id], '*', MUST_EXIST);
            $siblings = $DB->get_records('selfselectadvanced_dept', ['parent' => $record->parent], 'sortorder, id');
            $ids = array_keys($siblings);
            $pos = array_search($id, $ids);
            $swap = $ids[$pos + $direction] ?? null;
            if ($swap === null) {
                // Already at the end it was asked to move towards.
                // Nothing to write, so no transaction is opened and no
                // event is raised.
                return;
            }
            $transaction = $DB->start_delegated_transaction();
            $DB->set_field('selfselectadvanced_dept', 'sortorder', $siblings[$swap]->sortorder, ['id' => $id]);
            $DB->set_field('selfselectadvanced_dept', 'sortorder', $record->sortorder, ['id' => $swap]);
            $event = self::vocabulary_event(
                \mod_selfselectadvanced\event\dept_updated::class,
                $record,
                $actorid
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $event->trigger();
    }

    /**
     * Bulk-add categories from pasted text: one path per line, levels
     * separated by "/" (e.g. "Engineering / Mechanical / Thermo").
     * Existing nodes are reused, missing ones created; nothing is
     * deleted. Returns counts and per-line errors.
     *
     * @param string $text the pasted tree
     * @param int $actorid the acting user
     * @return stdClass report {created, existing, errors, errorlines[]}
     * @throws \required_capability_exception when the actor lacks the ingest authority
     */
    public static function bulk_add(string $text, int $actorid): stdClass {
        global $DB;

        // Authority BEFORE the lock and the transaction: a refused bulk
        // add must never have queued for the lock or opened a delegated
        // frame at all.
        self::require_ingest($actorid);

        $report = (object) ['created' => 0, 'existing' => 0, 'errors' => 0, 'errorlines' => []];
        $events = [];
        // The lock wraps the whole paste: every line's find-or-create
        // is the same read-then-insert create() serialises, and a paste
        // that raced a single create() produced exactly the duplicate
        // pair described at the top of this class.
        $lock = locks::acquire(self::LOCK);
        try {
            $transaction = $DB->start_delegated_transaction();
            foreach (preg_split('/\R/', $text) as $lineno => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = array_map('trim', explode('/', $line));
                $parentid = 0;
                try {
                    foreach ($parts as $name) {
                        if ($name === '' || \core_text::strlen($name) > 100) {
                            throw new moodle_exception('errdeptname', 'mod_selfselectadvanced');
                        }
                        $existing = $DB->get_record('selfselectadvanced_dept', [
                            'parent' => $parentid,
                            'name' => $name,
                        ]);
                        if ($existing) {
                            $report->existing++;
                            $parentid = (int) $existing->id;
                        } else {
                            $created = self::insert_node($name, $parentid);
                            $report->created++;
                            $parentid = (int) $created->id;
                            // Payload built inside the transaction,
                            // dispatched after the commit below - the
                            // binding rule for new code.
                            $events[] = self::vocabulary_event(
                                \mod_selfselectadvanced\event\dept_created::class,
                                $created,
                                $actorid
                            );
                        }
                    }
                } catch (moodle_exception $e) {
                    // A bad line is REPORTED, not unwound: the paste is
                    // a best-effort ingest and the other lines stand.
                    // Caught here and never rolled back, so the frame
                    // stays whole and the commit below is reached.
                    $report->errors++;
                    $report->errorlines[] = ($lineno + 1) . ': ' . $line;
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
        $report->errordetail = implode('; ', $report->errorlines);

        foreach ($events as $event) {
            $event->trigger();
        }

        return $report;
    }

    /**
     * Menu of top-level department names.
     *
     * @return string[] name => name, display-ordered
     */
    public static function departments_menu(): array {
        $menu = [];
        foreach (self::get_all() as $record) {
            if ((int) $record->depth === 1) {
                $menu[$record->name] = $record->name;
            }
        }

        return $menu;
    }

    /**
     * Sub-department names grouped by their parent department.
     *
     * @return array department name => (name => name)
     */
    public static function subdepartments_grouped(): array {
        $all = self::get_all();
        $grouped = [];
        foreach ($all as $record) {
            if ((int) $record->depth === 2 && isset($all[$record->parent])) {
                $grouped[$all[$record->parent]->name][$record->name] = $record->name;
            }
        }

        return $grouped;
    }

    /**
     * Is this department / sub-department pair part of the vocabulary?
     * Empty values are always acceptable; a sub-department requires its
     * own department.
     *
     * @param string $department department name ('' = none)
     * @param string $subdepartment sub-department name ('' = none)
     * @return string|null null when valid, else the offending field name
     */
    public static function validate_pair(string $department, string $subdepartment): ?string {
        if ($department === '' && $subdepartment === '') {
            return null;
        }
        if ($department === '') {
            return 'subdepartment';
        }
        $departments = self::departments_menu();
        if (!isset($departments[$department])) {
            return 'department';
        }
        if ($subdepartment === '') {
            return null;
        }
        $grouped = self::subdepartments_grouped();
        if (!isset($grouped[$department][$subdepartment])) {
            return 'subdepartment';
        }

        return null;
    }

    /**
     * Does the vocabulary have any entries yet? While empty, free-text
     * input stays allowed so existing sites are not locked out before
     * an administrator defines the tree.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        global $DB;

        return $DB->record_exists('selfselectadvanced_dept', ['kind' => 'dept']);
    }
}
