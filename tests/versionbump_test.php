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

/**
 * The 1.20.0 release serial has to REACH an existing site, and be seen to.
 *
 * A version bump is the one change in a plugin that no functional test can
 * notice: every suite here installs from db/install.xml, where not one line
 * of db/upgrade.php ever executes, so an upgrade step that is unreachable,
 * mis-numbered or absent leaves the whole gate printing RESULT fail=0.
 *
 * The two static guards this project already owns do not close that hole
 * either:
 *
 *  - /srv/ci/ops/savepoint-tip.sh compares version.php with the MAXIMUM
 *    savepoint in db/upgrade.php. It therefore catches a LOWERED number and
 *    is blind to a re-used one, to a second block carrying a number an
 *    earlier block already recorded, and to a block whose $oldversion guard
 *    can never be true.
 *  - /srv/ci/ops/upgrade-safety.sh reads the classes db/upgrade.php calls,
 *    not what the new step itself does.
 *
 * So this file measures the thing directly. It drives the composition
 * admin/cli/upgrade.php performs - ASK moodle_needs_upgrading() first, then
 * upgrade_noncore() - against a site whose recorded version has been put
 * back to the previous serial, and it reads the answer back out of
 * config_plugins with $DB.
 *
 * THE TRAP, and why one line of setup is load-bearing. moodle_needs_upgrading()
 * in Moodle 5.2 is a hash comparison and nothing else: if $CFG->allversionshash
 * still equals \core\component::get_all_versions_hash() for the code on disk,
 * it returns false and the site never even looks at config_plugins. A test that
 * edits config_plugins alone leaves the disk untouched, so the hash still
 * matches, so no upgrade runs - and if the test then asserts only on the
 * version number it can still pass, because core's own upgrade_plugins() writes
 * $plugin->version into config_plugins once xmldb_selfselectadvanced_upgrade()
 * returns, whether that function did anything or not. That is how a version
 * check has been fooled in this project before.
 *
 * Both halves of the trap are pinned below:
 *   - test_the_recorded_hash_short_circuits_the_whole_scan() asserts the
 *     short circuit is real, so the unset_config() call in its sibling is
 *     demonstrably doing work rather than decorating the setup;
 *   - the upgrade assertions never rely on the version number alone. The new
 *     step writes an {upgrade_log} row, and that row is the only artefact
 *     that can tell "this step executed" apart from "core bumped the number
 *     and the step was skipped".
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class versionbump_test extends \advanced_testcase {
    /** @var int The serial this release ships, in version.php and as the final savepoint. */
    private const CURRENT = 2026082005;

    /** @var int The previous release serial that must remain in the savepoint ladder. */
    private const PREVIOUS = 2026082004;

    /** @var string $plugin->release, set once and never lowered or churned. */
    private const RELEASE = '1.20.59';

    /**
     * The step's own text, plus the body of every db/upgrade.php helper it
     * calls - so DML moved into a helper is still DML the scan can see.
     *
     * @param string $block the text between the guard and the savepoint
     * @param string $code the whole of db/upgrade.php
     * @return string $block with each called helper's body appended
     */
    private static function with_called_helpers(string $block, string $code): string {
        preg_match_all('/\b(selfselectadvanced_[a-z_]+)\s*\(/', $block, $calls);
        foreach (array_unique($calls[1] ?? []) as $name) {
            $at = strpos($code, 'function ' . $name . '(');
            if ($at === false) {
                // Not declared here - a core function, or one this file only
                // calls. Nothing to append, and nothing hidden either.
                continue;
            }
            $open = strpos($code, '{', $at);
            if ($open === false) {
                continue;
            }
            $depth = 0;
            for ($i = $open, $n = strlen($code); $i < $n; $i++) {
                if ($code[$i] === '{') {
                    $depth++;
                } else if ($code[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $block .= "\n" . substr($code, $open, $i - $open + 1);
                        break;
                    }
                }
            }
        }

        return $block;
    }

    /**
     * Upgrade constants and functions are not loaded in a plain test run.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->libdir . '/upgradelib.php');
    }

    /**
     * A PHP file's executable source with every comment removed.
     *
     * Comments are stripped because the interesting numbers are DISCUSSED in
     * this plugin's upgrade file at length - "upgrade_mod_savepoint() calls
     * as the plugin evolves" appears in a comment on line 93 - and a check a
     * comment can satisfy is not a check.
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

    /**
     * The version metadata this release declares, read from the file itself.
     *
     * @return \stdClass the object version.php populates
     */
    private function version_php(): \stdClass {
        $plugin = new \stdClass();
        include(__DIR__ . '/../version.php');

        return $plugin;
    }

    /**
     * Every $oldversion guard in db/upgrade.php, in file order.
     *
     * @param string $code comment-stripped source
     * @return int[] the guard serials
     */
    private function guards(string $code): array {
        preg_match_all('/if\s*\(\s*\$oldversion\s*<\s*(\d+)\s*\)/', $code, $matches);

        return array_map('intval', $matches[1]);
    }

    /**
     * Every module savepoint in db/upgrade.php, in file order.
     *
     * @param string $code comment-stripped source
     * @return int[] the savepoint serials
     */
    private function savepoints(string $code): array {
        preg_match_all(
            '/upgrade_mod_savepoint\(\s*true\s*,\s*(\d+)\s*,\s*\'selfselectadvanced\'\s*\)/',
            $code,
            $matches
        );

        return array_map('intval', $matches[1]);
    }

    /**
     * How many times the new step's own upgrade_log() marker has been written.
     *
     * Read straight off {upgrade_log}, and narrowed by TYPE as well as by
     * text: upgrade_plugin_savepoint() logs 'Upgrade savepoint reached' as
     * UPGRADE_LOG_NORMAL for every step, and print_upgrade_part_end() logs
     * 'Plugin upgraded', so counting rows for this component alone would
     * count core's bookkeeping and prove nothing about the step.
     *
     * @return int the number of marker rows
     */
    private function marker_rows(): int {
        global $DB;

        return $DB->count_records_select(
            'upgrade_log',
            'plugin = :plugin AND type = :type AND ' . $DB->sql_like('info', ':info'),
            [
                'plugin' => 'mod_selfselectadvanced',
                'type' => UPGRADE_LOG_NOTICE,
                'info' => '%(' . self::CURRENT . ')%',
            ]
        );
    }

    /**
     * The version this site has RECORDED, read back from the table.
     *
     * get_config() is cached; the row is the artefact under test, so the row
     * is what is read.
     *
     * @return string|false the stored value, or false when there is no row
     */
    private function recorded_version() {
        global $DB;

        return $DB->get_field('config_plugins', 'value', [
            'plugin' => 'mod_selfselectadvanced',
            'name' => 'version',
        ]);
    }

    /**
     * Put the site back to the serial an upgrading site is sitting at.
     *
     * @param int $serial the version to record
     */
    private function pretend_the_site_installed(int $serial): void {
        set_config('version', $serial, 'mod_selfselectadvanced');
        $this->assertSame(
            (string) $serial,
            $this->recorded_version(),
            'the pre-upgrade state was not reached'
        );
    }

    /**
     * Run what a site runs, in the order a site runs it.
     *
     * admin/cli/upgrade.php asks moodle_needs_upgrading(false) and exits with
     * "no upgrade needed" when the answer is no; only then does it call
     * upgrade_noncore(). Keeping the question in front of the work is the
     * whole point - the question is what the recorded hash short-circuits.
     *
     * @return bool whether the upgrade was actually performed
     */
    private function upgrade_the_way_a_site_does(): bool {
        global $CFG;

        if (!moodle_needs_upgrading(false)) {
            return false;
        }

        // Core prints through $OUTPUT under developer debugging, which every
        // test run has on.
        ob_start();
        try {
            upgrade_noncore(false);
        } finally {
            ob_end_clean();
        }

        // A real upgrade's completion clears this flag; leaving it set makes
        // every later call in the process believe a site upgrade is running.
        upgrade_finished();
        unset_config('upgraderunning');
        unset($CFG->upgraderunning);
        accesslib_clear_all_caches_for_unit_testing();

        return true;
    }

    /**
     * version.php, the savepoint ladder and $plugin->release all agree.
     *
     * This is the assertion set savepoint-tip.sh cannot make. It reads the
     * ladder as a SEQUENCE: each block's guard must be its own savepoint,
     * the serials must be strictly ascending and unique, and the last one
     * must be the code version. A re-used number, a second block carrying an
     * earlier block's number, or a guard folded back onto the previous serial
     * all break one of those and none of them breaks the maximum.
     */
    public function test_the_savepoint_ladder_is_a_ladder_and_its_tip_is_the_code_version(): void {
        $version = $this->version_php();
        $this->assertSame(self::CURRENT, (int) $version->version, 'version.php is not at the release serial');
        $this->assertSame(
            self::RELEASE,
            $version->release,
            '$plugin->release was churned; it is set once per release and never lowered'
        );

        $code = $this->code_without_comments(__DIR__ . '/../db/upgrade.php');
        $guards = $this->guards($code);
        $savepoints = $this->savepoints($code);

        $this->assertNotEmpty($savepoints, 'db/upgrade.php records no savepoint at all');
        $this->assertSame(
            $guards,
            $savepoints,
            'a block is not guarded by its own savepoint: an upgrade step is unreachable, '
                . 'or records a serial that is not the one it is gated on'
        );

        $this->assertSame(
            array_values(array_unique($savepoints)),
            $savepoints,
            'a savepoint serial is used twice; savepoint-tip.sh cannot see this'
        );

        $ascending = $savepoints;
        sort($ascending, SORT_NUMERIC);
        $this->assertSame($ascending, $savepoints, 'the savepoints are not in ascending order');

        $this->assertSame(self::CURRENT, (int) end($savepoints), 'the final savepoint is not the code version');

        // EVERY NEW SERIAL IS A REAL CALENDAR DATE (maintainer,
        // 2026-08-06: "I am very surprised that ... July 32, 2026 had
        // been a valid solution"). Six serials 2026073200..2026073250
        // shipped encoding July 32nd - a date that does not exist -
        // and they are grandfathered here because a LANDED savepoint
        // must never be rewritten: renumbering one breaks the upgrade
        // ladder of every site that recorded it. Everything after the
        // grandfather line, and version.php itself, must parse as
        // YYYYMMDDXX with a date the calendar contains. Until this
        // assertion the rule lived only in session memory, which is
        // the drift class this project keeps meeting.
        foreach (array_merge($savepoints, [self::CURRENT]) as $serial) {
            if ((int) $serial <= 2026073250) {
                continue;
            }
            $y = (int) substr((string) $serial, 0, 4);
            $m = (int) substr((string) $serial, 4, 2);
            $d = (int) substr((string) $serial, 6, 2);
            $this->assertTrue(
                checkdate($m, $d, $y),
                "serial {$serial} encodes {$y}-{$m}-{$d}, a date the calendar does not contain"
            );
        }

        // A LANDED SAVEPOINT MUST NEVER BE REWRITTEN - that is the property,
        // and it is unchanged. What changed is the assumption underneath the
        // old assertion: it read the serial immediately BELOW the tip and
        // required it to equal PREVIOUS, which silently assumed every release
        // adds exactly ONE upgrade step. 1.20.6 adds two - the ticket groupid
        // migration and the releasedbyguide column - so the serial below its
        // tip is its own first step, and the old form failed on a release
        // that had done nothing wrong.
        //
        // Containment is the honest test of the property: the previous
        // release's serial must STILL BE THERE. Combined with the ascending
        // check and the unique check above, and the tip check on the line
        // above this, a rewritten or dropped landed savepoint still fails.
        $this->assertContains(
            self::PREVIOUS,
            array_map('intval', $savepoints),
            'the previous release\'s savepoint is missing; a landed savepoint must never be rewritten'
        );
        $this->assertGreaterThan(
            self::PREVIOUS,
            (int) end($savepoints),
            'the tip did not advance beyond the previous release'
        );
    }

    /**
     * The new step touches no plugin table it did not already have.
     *
     * upgrade-safety.sh reads the CLASSES db/upgrade.php calls. Nothing reads
     * the step's own body, and the step's own body is where a raw
     * $DB->get_records('selfselectadvanced_...') would sit.
     *
     * THE RULE WAS ABSOLUTE UNTIL 1.20.35 and had to stop being, because a
     * schema migration that normalises bad data cannot avoid writing rows.
     * The 1.20.6 ticket.groupid migration already did exactly that
     * (`UPDATE {selfselectadvanced_ticket} SET groupid = NULL`), and the
     * leadership-vacancy migration must do the same for leaderid. Banning it
     * outright would have meant either shipping the schema change without the
     * data fix - leaving rows that violate the new contract on day one - or
     * quietly deleting this test, which is worse.
     *
     * So the hazard is now stated precisely instead of approximately. The
     * danger is DML against schema the FROM version may not have; DML confined
     * to tables and columns that already existed is safe. An exempt step must
     * name itself, name the tables it writes, and give a reason - and the
     * exemption is checked, not trusted:
     *
     *  - it must be for the CURRENT serial, so an entry cannot outlive its
     *    step and silently license the next one;
     *  - every plugin table the step actually touches must be declared, so
     *    widening the DML later fails here;
     *  - the step must not CREATE those tables or their columns, which is what
     *    proves they predate the upgrade.
     */
    public function test_the_new_step_queries_no_plugin_table(): void {
        $code = $this->code_without_comments(__DIR__ . '/../db/upgrade.php');

        $guard = 'if ($oldversion < ' . self::CURRENT . ')';
        $savepoint = 'upgrade_mod_savepoint(true, ' . self::CURRENT . ', \'selfselectadvanced\');';
        $start = strpos($code, $guard);
        $end = strpos($code, $savepoint);
        $this->assertNotFalse($start, 'the new step has no $oldversion guard: ' . $guard);
        $this->assertNotFalse($end, 'the new step records no savepoint: ' . $savepoint);
        $this->assertLessThan($end, $start, 'the guard does not precede its savepoint');

        $block = substr($code, $start, $end - $start);

        // A HELPER IS STILL THE STEP. The scan used to read only the text
        // BETWEEN the guard and the savepoint, so a step whose body was one
        // call to a function declared earlier in this same file had all of
        // its DML invisible here - the register would read "no tables
        // touched" for a step that rewrites every row of one. That is the
        // ledger-127 failure mode exactly: a checker reporting a number
        // about code it cannot see. Every selfselectadvanced_* function the
        // step calls, and which db/upgrade.php itself declares, is appended
        // to the text under examination.
        $block = self::with_called_helpers($block, $code);
        // Kept unblanked: the creation check below has to see which table
        // each xmldb_table() declaration names.
        $original = $block;

        // XMLDB SCHEMA DECLARATIONS ARE NOT QUERIES, and excluding them is
        // narrowing what this test IGNORES, never what it checks. A step that
        // adds a column MUST name its table - `new xmldb_table('..._group')`
        // is the only way Moodle lets you do it, and the 1.20.6 step that adds
        // releasedbyguide does exactly that. Before this exclusion the test
        // reported that step as "querying a plugin table", which it does not:
        // $dbman->add_field() manipulates the SCHEMA, and the schema is the one
        // thing an upgrade step is entitled to touch.
        //
        // What the test still forbids, which is the actual hazard, is DML -
        // reading or writing ROWS of a plugin table while the PHP is the new
        // code and the schema is still whatever the site is upgrading FROM.
        // Any $DB-> call naming a plugin table, and any other mention outside
        // an xmldb constructor, still fails below.
        $block = preg_replace(
            '/new\s+xmldb_(?:table|field|key|index)\s*\(\s*\'[^\']*\'/',
            'new xmldb_declaration(\'\'',
            $block
        );

        // The exemption register. Keyed by serial so it cannot be inherited.
        //
        // 'tables' are PRE-EXISTING tables the step performs DML on; the
        // hazard there is schema the FROM version may not have, and the
        // exemption asserts these predate the step. 'creates' are tables the
        // step itself creates before touching them - a different and weaker
        // claim, because the step has just guaranteed their existence, but it
        // has to be declared separately so nobody can quietly move a table
        // between the two categories.
        // EMPTY for 2026082005. The 1.20.59 step adds three ticket columns
        // - an int verdict NOT NULL DEFAULT 0 and two nullable ones - and
        // reads or writes no row of any plugin table. Same reason as the
        // step before it: a default, or nullability, is what lets a column
        // land on a populated table without a backfill. The 2026082004
        // entry was empty and is gone.
        $exempt = [];
        $created = array_key_exists(self::CURRENT, $exempt) ? ($exempt[self::CURRENT]['creates'] ?? []) : [];
        $allowed = array_key_exists(self::CURRENT, $exempt)
            ? array_merge($exempt[self::CURRENT]['tables'], $created)
            : [];

        // Stale-entry detection: an exemption for anything other than the
        // current step is a leftover, and leftovers are how a one-off licence
        // becomes a standing one.
        foreach (array_keys($exempt) as $serial) {
            $this->assertSame(
                self::CURRENT,
                $serial,
                'the DML exemption for ' . $serial . ' outlived its step and must be removed'
            );
        }
        if ($allowed !== []) {
            $this->assertNotSame('', trim($exempt[self::CURRENT]['reason']), 'an exemption needs a reason');
        }

        // What does the step ACTUALLY touch? Read it out rather than trusting
        // the declaration.
        preg_match_all('/[{\'](selfselectadvanced_[a-z]+)/', $block, $found);
        $touched = array_values(array_unique($found[1] ?? []));

        foreach ($touched as $table) {
            $this->assertContains(
                $table,
                $allowed,
                'the ' . self::CURRENT . ' step queries ' . $table . '; during an upgrade the PHP is '
                    . 'the new code while the schema is still whatever the site is upgrading FROM. '
                    . 'If the write is a necessary part of a schema migration, declare it in this '
                    . 'test\'s exemption register with a reason.'
            );
        }

        // THE PROPERTY THE 'tables' EXEMPTION RESTS ON: those tables predate
        // the step, so it must not be creating them. Read out of the step
        // rather than asserted in the abstract - every table handed to
        // create_table()/add_table() is collected from the ORIGINAL text (the
        // xmldb constructors were blanked above) and must have been declared
        // as a creation, never as a pre-existing table.
        preg_match_all(
            '/new\s+xmldb_table\s*\(\s*\'(selfselectadvanced_[a-z]+)\'/',
            $original,
            $declared
        );
        $makes = preg_match('/(?:create_table|add_table)\s*\(/', $original) === 1
            ? array_values(array_unique($declared[1] ?? []))
            : [];
        foreach ($makes as $table) {
            $this->assertNotContains(
                $table,
                $exempt[self::CURRENT]['tables'] ?? [],
                'the ' . self::CURRENT . ' step CREATES ' . $table . ' and the register calls it '
                    . 'pre-existing; an exemption cannot cover a table this step invents'
            );
        }
        $this->assertSame(
            $created,
            array_values(array_intersect($makes, $created)),
            'the register declares a created table the step does not create'
        );
    }

    /**
     * THE MEASUREMENT: a site sitting at the previous serial really does run
     * the new step, and really does end up recording the new one.
     *
     * unset_config('allversionshash') is what makes this test capable of
     * failing at all - see the sibling test, which asserts what happens
     * without it. Everything else here is read back out of the database
     * after the act.
     */
    public function test_a_site_at_the_previous_serial_runs_the_new_step(): void {
        global $CFG;

        $this->resetAfterTest();

        // A freshly installed site is at the tip already; that is db/install.xml
        // doing its job and is precisely why no other test in this plugin can
        // notice an upgrade step.
        $this->assertSame((string) self::CURRENT, $this->recorded_version(), 'a fresh install is not at the tip');

        $this->pretend_the_site_installed(self::PREVIOUS);
        $before = $this->marker_rows();

        // Editing config_plugins leaves the DISK untouched, so the recorded
        // hash still matches and core would short-circuit. A real deployment
        // changes version.php and therefore the hash; clearing it is the
        // equivalent, and without it this test proves nothing.
        unset_config('allversionshash');
        unset($CFG->allversionshash);
        $this->assertTrue(moodle_needs_upgrading(false), 'core still refuses to look for an upgrade');

        $this->assertTrue($this->upgrade_the_way_a_site_does(), 'the upgrade did not run');

        // OBSERVED AFTER: the row, not the cache.
        $this->assertSame(
            (string) self::CURRENT,
            $this->recorded_version(),
            'the site did not end up at the release serial'
        );

        // And the part the version number cannot prove. Core's
        // upgrade_plugins() writes $plugin->version into config_plugins by
        // itself once xmldb_selfselectadvanced_upgrade() returns, so the
        // assertion above would hold even if every block in db/upgrade.php
        // had been skipped. This row exists only if the new step ran.
        $this->assertSame(
            $before + 1,
            $this->marker_rows(),
            'the ' . self::CURRENT . ' step did not execute: core bumped the recorded version on its own'
        );
    }

    /**
     * THE DIGEST PURGE. A site upgrading with pending digest items loses them
     * and gains no invented subject relation.
     *
     * The rows are dropped rather than migrated because their non-recipient
     * subjects exist only as rendered names, and mapping a name back to an
     * account is the ambiguity selfselectadvanced_dqsubject was created to
     * end - a wrong guess files one person's data under another's. This test
     * exists to stop a later "helpfully" reinstated migration: it fails both
     * if a queue row survives AND if a relation row appears for a legacy row.
     */
    public function test_the_upgrade_purges_the_legacy_digest_queue_without_guessing_subjects(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $recipient = $generator->create_user();
        $named = $generator->create_user(['firstname' => 'Legacy', 'lastname' => 'Subject']);
        // A legacy row: the recipient's id, and the other person present only
        // as text - exactly the shape the old model produced.
        $legacy = $DB->insert_record('selfselectadvanced_digestq', (object) [
            'activityid' => (int) $instance->id,
            'userid' => (int) $recipient->id,
            'groupid' => null,
            'provider' => 'guidequeue',
            'subjectkey' => 'msghandoverproposedsubject',
            'bodykey' => 'msghandoverproposedbody',
            'payload' => json_encode(['from' => fullname($named), 'group' => 'Team Alpha']),
            'contexturl' => 'https://example.invalid/mod/selfselectadvanced/guide.php?id=1',
            'timecreated' => time(),
        ]);
        $this->assertTrue($DB->record_exists('selfselectadvanced_digestq', ['id' => $legacy]));

        // Wound back to the serial BEFORE the digest step, not to PREVIOUS,
        // which has advanced past it. A test that starts after the step it
        // tests proves nothing; the same pin the sentinel-normalisation test
        // above uses, and for the same reason.
        $this->pretend_the_site_installed(2026081102);
        unset_config('allversionshash');
        unset($CFG->allversionshash);
        $this->assertTrue($this->upgrade_the_way_a_site_does(), 'the upgrade did not run');

        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_digestq'),
            'a pending digest item survived an upgrade that cannot migrate its subjects'
        );
        $this->assertSame(
            0,
            $DB->count_records('selfselectadvanced_dqsubject'),
            'the upgrade invented a subject relation for a legacy row, which it can only have '
                . 'done by guessing identity from the payload text'
        );
    }

    /**
     * The upgrade is idempotent: a second pass changes nothing.
     *
     * An upgrade that a site can be made to run twice - a failed run resumed,
     * a cluster node repeating it - must not double anything up.
     */
    public function test_running_the_upgrade_again_does_nothing(): void {
        global $CFG;

        $this->resetAfterTest();

        $this->pretend_the_site_installed(self::PREVIOUS);
        unset_config('allversionshash');
        unset($CFG->allversionshash);
        $this->assertTrue($this->upgrade_the_way_a_site_does(), 'the first upgrade did not run');
        $after = $this->marker_rows();

        // Second pass, hash cleared again so the question is asked honestly.
        unset_config('allversionshash');
        unset($CFG->allversionshash);
        $this->assertTrue($this->upgrade_the_way_a_site_does(), 'the second pass did not reach upgrade_noncore()');

        $this->assertSame((string) self::CURRENT, $this->recorded_version(), 'the recorded version moved on a re-run');
        $this->assertSame($after, $this->marker_rows(), 'the step ran a second time on an up-to-date site');
    }

    /**
     * THE TRAP, asserted rather than described.
     *
     * With the recorded hash still matching the code on disk - which is the
     * state any test that edits config_plugins alone leaves behind - core
     * never scans for a plugin upgrade at all, and the site stays exactly
     * where it was. This is what a version-faking test WITHOUT
     * unset_config('allversionshash') is actually measuring: nothing.
     */
    public function test_the_recorded_hash_short_circuits_the_whole_scan(): void {
        global $CFG;

        $this->resetAfterTest();

        $this->pretend_the_site_installed(self::PREVIOUS);
        $before = $this->marker_rows();

        $hash = \core\component::get_all_versions_hash();
        set_config('allversionshash', $hash);
        $CFG->allversionshash = $hash;

        $this->assertFalse(
            moodle_needs_upgrading(false),
            'the short circuit is gone; the sibling test\'s unset_config() no longer proves anything '
                . 'and this whole file needs rewriting against whatever replaced it'
        );
        $this->assertFalse($this->upgrade_the_way_a_site_does(), 'the upgrade ran even though core saw no need');

        $this->assertSame(
            (string) self::PREVIOUS,
            $this->recorded_version(),
            'the site moved without core ever deciding it needed to'
        );
        $this->assertSame($before, $this->marker_rows(), 'the new step ran without core ever deciding it needed to');
    }

    /**
     * LEGACY NEGATIVE SETTINGS BECOME ZERO, and nothing else moves.
     *
     * The five sentinel columns each treated a negative number as a silent
     * second spelling of their 0 sentinel. The form refuses negatives now, but
     * rows already holding one are only fixed by this upgrade step.
     *
     * BEHAVIOUR-PRESERVING BY CONSTRUCTION, which is why the assertions are
     * about the stored value rather than about any changed outcome: every row
     * this touches is one the runtime already treated exactly as it treats 0.
     *
     * SAFETY OF THE STEP ITSELF, stated because the suite's upgrade-safety
     * guard cannot see it: that guard looks for 'selfselectadvanced_' with a
     * trailing underscore, so it does not police writes to the main
     * 'selfselectadvanced' table. This step is nevertheless safe on its own
     * merits - all five columns were added by upgrade steps far older than
     * 1.20.34, so every site running this step already has them.
     *
     * MUTATION CAUGHT (run 2026-08-11): removing any field from the loop in
     * the 2026081101 step leaves that column negative and fails its assertion.
     */
    public function test_negative_sentinel_settings_are_normalised_by_the_upgrade(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('selfselectadvanced', ['course' => $course->id]);

        $fields = ['contactmax', 'joinexpiry', 'eoimax', 'eoigroupmax', 'minmembership'];

        // A legacy row holding a negative in every one of them, plus a
        // control row whose values are already lawful.
        foreach ($fields as $i => $field) {
            $DB->set_field('selfselectadvanced', $field, -($i + 1), ['id' => $instance->id]);
        }
        $control = $this->getDataGenerator()->create_module('selfselectadvanced', [
            'course' => $course->id,
            'contactmax' => 3,
            'minmembership' => 0,
        ]);

        foreach ($fields as $field) {
            $this->assertLessThan(
                0,
                (int) $DB->get_field('selfselectadvanced', $field, ['id' => $instance->id]),
                'fixture: ' . $field . ' must start negative or this test proves nothing'
            );
        }

        // Wound back to the release BEFORE the normalisation step, not to
        // PREVIOUS. PREVIOUS tracks the last release and moves every time a
        // serial is added; this test is about one specific step, so it names
        // the serial that step upgrades from and keeps meaning the same thing
        // after the next bump.
        $this->pretend_the_site_installed(2026081003);
        unset_config('allversionshash');
        $this->assertTrue($this->upgrade_the_way_a_site_does(), 'the upgrade did not run');

        foreach ($fields as $field) {
            $this->assertSame(
                0,
                (int) $DB->get_field('selfselectadvanced', $field, ['id' => $instance->id]),
                $field . ' kept a negative value through the upgrade'
            );
        }

        // THE CONTROL. A normalisation that set every row to 0 would satisfy
        // the loop above perfectly, so a lawful positive must survive.
        $this->assertSame(
            3,
            (int) $DB->get_field('selfselectadvanced', 'contactmax', ['id' => $control->id]),
            'the upgrade flattened a lawful positive value'
        );
        $this->assertSame(
            0,
            (int) $DB->get_field('selfselectadvanced', 'minmembership', ['id' => $control->id]),
            'the upgrade disturbed a lawful zero'
        );
    }
}
