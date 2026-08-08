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
    private const CURRENT = 2026080805;

    /** @var int The previous release serial that must remain in the savepoint ladder. */
    private const PREVIOUS = 2026080804;

    /** @var string $plugin->release, set once and never lowered or churned. */
    private const RELEASE = '1.20.26';

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
     * The new step touches no plugin table, so it cannot depend on the schema
     * of the version it is upgrading FROM.
     *
     * upgrade-safety.sh reads the CLASSES db/upgrade.php calls. Nothing reads
     * the step's own body, and the step's own body is where a raw
     * $DB->get_records('selfselectadvanced_...') would sit.
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

        foreach (['{selfselectadvanced_', '\'selfselectadvanced_'] as $tablereference) {
            $this->assertStringNotContainsString(
                $tablereference,
                $block,
                'the ' . self::CURRENT . ' step queries a plugin table; during an upgrade the PHP is '
                    . 'the new code while the schema is still whatever the site is upgrading FROM'
            );
        }
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
}
