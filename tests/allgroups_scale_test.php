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

use mod_selfselectadvanced\local\api;
use mod_selfselectadvanced\local\coordinatorimport;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\freeze;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\groups_table;

/**
 * The "All groups" listing (1.20.47/1.20.48) exercised AT SCALE and across
 * EVERY lifecycle state, so a query-shape or authority defect surfaces here
 * rather than in a maintainer's manual GUI pass. tests/allgroups_native_test.php
 * already proves the :viewall/coordinator REACH and a couple of one- or
 * two-row action offers; this file does not repeat that ground. It proves
 * five different things instead:
 *
 *  1. The query pages IN THE DATABASE - bounded pages, no overlap, nothing
 *     missing across a full two-page sweep (the off-by-one that bites at
 *     scale), and survives real multi-member rosters built through
 *     invitations()->send()/accept() without the RCA-1 aggregate join
 *     fanning a group out into more than one row.
 *  2. Sorting is real and total: alphabetical order independent of creation
 *     order, ties on a repeated value broken deterministically by the
 *     table's own default column (proven twice, same order both times -
 *     see tests/ordering_determinism_test.php and ledger row 114 for the
 *     class of bug this guards against), every sortable column returns the
 *     full row count with a stable repeat order - AND a genuine, currently-
 *     shipping defect this file found along the way: sorting by the
 *     DISPLAY-only leadername/guidename columns issued `ORDER BY leadername`
 *     against SQL that has no such column and crashed the whole listing on
 *     both engines. Fixed in classes/table/groups_table.php (see the report
 *     for the RED-first proof); pinned here so it cannot come back silently.
 *  3. The state filter is exact: inclusion AND exclusion, built through
 *     real transitions only.
 *  4. The reported total matches a full unpaged fetch, filtered and not.
 *  5. Authority at scale: a bare :viewall holder and a real, appointed
 *     coordinator (ledger row 112) both reach the listing, and neither is
 *     offered an action it cannot perform - checked across every row on the
 *     page, not a single spot check.
 *
 * Every group below is built through the plugin's own transitions -
 * api::create_group(), invitations()->send()/accept(),
 * lifecycle()->submit()/approve(), freeze::freeze_group() - never by
 * writing the state column directly, so each fixture carries the
 * bookkeeping (timestamps, mirror requests, ledger rows, notifications)
 * a real group of that state actually has.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\groups_table
 */
final class allgroups_scale_test extends \advanced_testcase {
    /**
     * A fresh course and activity, generous on guide capacity so a single
     * guide can carry every group a scale test throws at it without
     * tripping the L5 cap the fixtures are not trying to test.
     *
     * @param array $overrides instance setting overrides
     * @return array [activity, api, course]
     */
    private function setup_activity(array $overrides = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', array_merge([
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 1,
            'maxguided' => 500,
        ], $overrides));
        $activity = activity::from_instance((int) $instance->id);

        return [$activity, new api($activity), $course];
    }

    /**
     * A fresh, enrolled student.
     *
     * @param \stdClass $course the course
     * @return \stdClass the user
     */
    private function make_student(\stdClass $course): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        return $user;
    }

    /**
     * A fresh, enrolled non-editing teacher - holds :guide and :freeze by
     * archetype (db/access.php), so this same user can be both a group's
     * assigned guide and the actor who freezes it on the own-guide branch.
     *
     * @param \stdClass $course the course
     * @return \stdClass the user
     */
    private function make_teacher(\stdClass $course): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'teacher');

        return $user;
    }

    /**
     * Build one group and drive it to $targetstate through the plugin's
     * own transitions - never a hand-set state column. FORMING needs only
     * create_group(); PENDING_GUIDE/FIRM/FROZEN chain submit()/approve()/
     * freeze_group() on top, in order, so a FROZEN fixture really has been
     * submitted and approved first, exactly like a real group.
     *
     * @param activity $activity the activity
     * @param api $api the api
     * @param int $leaderid the leader, becomes the group's confirmed leader
     * @param string $name unique group name
     * @param string $targetstate a state::* constant
     * @param int|null $guideid required for anything past FORMING
     *        (guidemode 0, leader-selects, is this fixture's default)
     * @return \stdClass the group row in $targetstate
     */
    private function build_group(
        activity $activity,
        api $api,
        int $leaderid,
        string $name,
        string $targetstate,
        ?int $guideid = null
    ): \stdClass {
        $group = $api->create_group($leaderid, $name, substr($name, 0, 20), '<p>Brief.</p>', FORMAT_HTML);
        if ($targetstate === state::FORMING) {
            return groups::get($activity, (int) $group->id);
        }

        $submitted = $api->lifecycle()->submit(groups::get($activity, (int) $group->id), $guideid, $leaderid);
        if ($targetstate === state::PENDING_GUIDE) {
            return $submitted;
        }

        $approved = $api->lifecycle()->approve($submitted, $guideid);
        if ($targetstate === state::FIRM) {
            return $approved;
        }

        // FROZEN: the assigned guide freezes their own team - the
        // own-guide branch of require_freeze_team(), which asks for
        // nothing but the bare :freeze capability the teacher archetype
        // already holds, so no conflict-of-interest machinery is in play.
        return freeze::freeze_group($activity, $approved, $guideid);
    }

    /**
     * The view.php route a groups_table is built against.
     *
     * @param activity $activity the activity
     * @return \moodle_url
     */
    private function table_url(activity $activity): \moodle_url {
        return new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]);
    }

    /**
     * A user holding ONLY mod/selfselectadvanced:viewall at the module
     * context - the same fixture shape as allgroups_native_test.php's, so
     * this file's scale assertions are about the same population that
     * file already proved reaches the table.
     *
     * @param activity $activity the activity
     * @return \stdClass the user
     */
    private function viewall_only_user(activity $activity): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $activity->cm()->course, 'student');
        $roleid = $generator->create_role();
        assign_capability('mod/selfselectadvanced:viewall', CAP_ALLOW, $roleid, $activity->context()->id, true);
        role_assign($roleid, (int) $user->id, $activity->context()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * A REAL Group Coordinator, appointed through coordinatorimport::appoint()
     * exactly like coordinatorappoint_test.php and allgroups_native_test.php -
     * never a hand-built stand-in - so the :viewall grant under test is the one
     * coordinatorrole::capabilities() actually ships (ledger row 112).
     *
     * @param activity $activity the activity
     * @return \stdClass the user
     */
    private function real_coordinator(activity $activity): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $activity->cm()->course, 'teacher');
        coordinatorrole::ensure();
        coordinatorimport::appoint($activity, (int) $user->id);

        return $user;
    }

    /**
     * Render a groups_table and return the captured HTML.
     *
     * @param groups_table $table the table
     * @param int $perpage page size
     * @return string
     */
    private function render(groups_table $table, int $perpage): string {
        ob_start();
        $table->out($perpage, true);

        return ob_get_clean();
    }

    /**
     * Render with a specific sort column forced through the same GET
     * parameter (tsort) the real header links use, restoring whatever was
     * there before (or absent) so one test's sort request can never leak
     * into the next.
     *
     * @param groups_table $table the table
     * @param int $perpage page size
     * @param string $column the column to sort by
     * @return string the rendered HTML
     */
    private function render_sorted(groups_table $table, int $perpage, string $column): string {
        $previous = $_GET['tsort'] ?? null;
        $_GET['tsort'] = $column;
        try {
            return $this->render($table, $perpage);
        } finally {
            if ($previous === null) {
                unset($_GET['tsort']);
            } else {
                $_GET['tsort'] = $previous;
            }
        }
    }

    // ------------------------------------------------------------------
    // 1. Paging.

    /**
     * With 20 groups and perpage=10, page 1 and page 2 partition the whole
     * set exactly: bounded, disjoint, and nothing missing across the two -
     * the property that catches an off-by-one in the paging window.
     *
     * Five of the twenty groups are given a real SECOND confirmed member
     * (invitations()->send() + accept()), and two of those a THIRD member
     * left merely invited, so the derived member-count join in
     * groups_table.php's SQL (the RCA-1 aggregate, GROUP BY m.groupid) is
     * exercised at more than one member row per group. A join that fanned
     * out per member instead of aggregating would show up here as a page
     * with fewer than 10 DISTINCT groups, or as a group appearing more than
     * once - either way the exhaustiveness assertion below would catch it.
     */
    public function test_query_pages_in_the_database_no_overlap_and_nothing_missing(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity();
        $viewer = $this->make_teacher($course);

        $total = 20;
        $perpage = 10;
        $expectedids = [];
        $leaders = [];
        for ($i = 1; $i <= $total; $i++) {
            $leader = $this->make_student($course);
            $leaders[$i] = $leader;
            $group = $api->create_group((int) $leader->id, sprintf('Page %02d', $i), 'T', '<p>Brief</p>', FORMAT_HTML);
            $expectedids[] = (int) $group->id;
        }

        foreach ([1, 5, 9, 13, 17] as $i) {
            $group = groups::get($activity, $expectedids[$i - 1]);
            $second = $this->make_student($course);
            $api->invitations()->send($group, (int) $second->id, (int) $leaders[$i]->id);
            $group = groups::get($activity, $expectedids[$i - 1]);
            $api->invitations()->accept($group, (int) $second->id);
        }
        foreach ([1, 9] as $i) {
            $group = groups::get($activity, $expectedids[$i - 1]);
            $third = $this->make_student($course);
            // Left at STATUS_INVITED deliberately - a pending invitation
            // must not fan the row out either.
            $api->invitations()->send($group, (int) $third->id, (int) $leaders[$i]->id);
        }

        $page1 = new groups_table(
            'ssascalepage1',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $viewer->id
        );
        $this->render($page1, $perpage);
        $ids1 = array_keys($page1->rawdata);

        $page2 = new groups_table(
            'ssascalepage2',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $viewer->id
        );
        $page2->currpage = 1;
        $this->render($page2, $perpage);
        $ids2 = array_keys($page2->rawdata);

        $this->assertCount($perpage, $ids1, 'page 1 must return exactly perpage rows - not fewer, not the whole 20');
        $this->assertCount($perpage, $ids2, 'page 2 must also return exactly perpage rows');
        $this->assertSame(
            $total,
            (int) $page1->totalrows,
            'the table must report the TRUE total, distinct from the page size it fetched'
        );
        $this->assertSame($total, (int) $page2->totalrows);
        $this->assertEmpty(array_intersect($ids1, $ids2), 'page 1 and page 2 must not repeat a single row');

        $union = array_merge($ids1, $ids2);
        sort($union);
        $expectedsorted = $expectedids;
        sort($expectedsorted);
        $this->assertSame(
            $expectedsorted,
            $union,
            'the two pages together must cover every one of the 20 groups exactly once - an off-by-one in the '
                . 'paging window would either duplicate a row across both pages or drop one from both'
        );
    }

    // ------------------------------------------------------------------
    // 2. Sorting.

    /**
     * Groups are created in an order that does NOT match alphabetical
     * name order, so a listing that happened to return rows in creation/id
     * order (rather than truly sorting on name) would fail this - proven
     * below by asserting against creation order first and watching it fail
     * for real (see the report).
     */
    public function test_sorting_by_name_is_real_not_incidental_to_creation_order(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity();
        $viewer = $this->make_teacher($course);

        $shuffled = ['Group Echo', 'Group Alpha', 'Group Delta', 'Group Bravo', 'Group Charlie'];
        $idsbyname = [];
        foreach ($shuffled as $name) {
            $leader = $this->make_student($course);
            $group = $api->create_group((int) $leader->id, $name, 'T', '<p>Brief</p>', FORMAT_HTML);
            $idsbyname[$name] = (int) $group->id;
        }

        $table = new groups_table(
            'ssascalesortname',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $viewer->id
        );
        $this->render($table, 50);

        $expected = array_map(
            static fn(string $n) => $idsbyname[$n],
            ['Group Alpha', 'Group Bravo', 'Group Charlie', 'Group Delta', 'Group Echo']
        );
        $this->assertSame(
            $expected,
            array_keys($table->rawdata),
            'sorting by name must be alphabetical, not the order the groups happened to be created in'
        );
    }

    /**
     * Six groups, all FIRM (every row ties on state), created in shuffled
     * name order. table_sql's own mechanism appends the table's default
     * sort column (name, set via groups_table's sortable(true, 'name'))
     * whenever the requested sort is not already in the list - so sorting
     * by state must resolve every tie by name, deterministically, and the
     * identical query run again must return the identical order.
     */
    public function test_sorting_by_state_ties_are_broken_deterministically_and_repeat_identically(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity(['maxguided' => 500]);
        $guide = $this->make_teacher($course);

        $shuffled = ['Firm Echo', 'Firm Charlie', 'Firm Alpha', 'Firm Delta', 'Firm Bravo', 'Firm Foxtrot'];
        $idsbyname = [];
        foreach ($shuffled as $name) {
            $leader = $this->make_student($course);
            $group = $this->build_group($activity, $api, (int) $leader->id, $name, state::FIRM, (int) $guide->id);
            $idsbyname[$name] = (int) $group->id;
        }

        $table1 = new groups_table(
            'ssascalesortstate1',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $guide->id
        );
        $this->render_sorted($table1, 50, 'state');

        $expected = array_map(
            static fn(string $n) => $idsbyname[$n],
            ['Firm Alpha', 'Firm Bravo', 'Firm Charlie', 'Firm Delta', 'Firm Echo', 'Firm Foxtrot']
        );
        $this->assertSame(
            $expected,
            array_keys($table1->rawdata),
            'every row ties on state (all FIRM); the order must fall back to the default sort column, name'
        );

        $table2 = new groups_table(
            'ssascalesortstate2',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $guide->id
        );
        $this->render_sorted($table2, 50, 'state');
        $this->assertSame(
            array_keys($table1->rawdata),
            array_keys($table2->rawdata),
            'the identical sorted query, run again in a fresh table object, must return rows in the same order'
        );
    }

    /**
     * Every genuinely sortable column - name, pluginuid, state, penaltyvalue
     * (size and actions are computed and correctly excluded; leadername and
     * guidename are covered, and were found broken, by the test below) -
     * returns the full row count with no row dropped or duplicated, and the
     * identical sorted query run twice returns rows in the identical order.
     */
    public function test_every_sortable_column_returns_the_full_row_count_with_a_stable_repeat_order(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity(['maxguided' => 500]);
        $guideone = $this->make_teacher($course);
        $guidetwo = $this->make_teacher($course);

        $states = [
            state::FORMING, state::FORMING,
            state::PENDING_GUIDE, state::PENDING_GUIDE,
            state::FIRM, state::FIRM, state::FIRM,
            state::FROZEN, state::FROZEN, state::FROZEN,
        ];
        $total = count($states);
        $ids = [];
        foreach ($states as $i => $st) {
            $leader = $this->make_student($course);
            $guide = $i % 2 === 0 ? $guideone : $guidetwo;
            $group = $this->build_group($activity, $api, (int) $leader->id, 'Col group ' . $i, $st, (int) $guide->id);
            $ids[] = (int) $group->id;
        }
        // N>0: the fixture itself must have produced a distinct row per group.
        $this->assertCount($total, array_unique($ids), 'fixture sanity: every built group must be distinct');

        $sortablecolumns = ['name', 'pluginuid', 'state', 'penaltyvalue'];
        $this->assertGreaterThan(
            0,
            count($sortablecolumns),
            'the column list itself must not be empty, or the loop below examines nothing'
        );

        foreach ($sortablecolumns as $column) {
            $first = new groups_table(
                'ssascalecolA_' . $column,
                $activity,
                $api->gatekeeper(),
                $this->table_url($activity),
                '',
                false,
                (int) $guideone->id
            );
            $this->render_sorted($first, 50, $column);
            $this->assertCount($total, $first->rawdata, "sorting by $column must not drop or duplicate a single row");

            $second = new groups_table(
                'ssascalecolB_' . $column,
                $activity,
                $api->gatekeeper(),
                $this->table_url($activity),
                '',
                false,
                (int) $guideone->id
            );
            $this->render_sorted($second, 50, $column);
            $this->assertSame(
                array_keys($first->rawdata),
                array_keys($second->rawdata),
                "sorting by $column twice must return the identical order both times"
            );
        }
    }

    /**
     * THE DEFECT THIS FILE FOUND. leadername and guidename are DISPLAY
     * columns col_leadername()/col_guidename() build from several aliased
     * name-format fields (leaderfirstname, leaderlastname, ...) - there is
     * no single SQL column called "leadername" or "guidename". Before this
     * wave they were left sortable anyway, so a viewer sorting the Leader
     * or Guide column sent `ORDER BY leadername`/`ORDER BY guidename`,
     * which both engines refuse - PostgreSQL: `column "leadername" does
     * not exist` - and the WHOLE LISTING crashed with an uncaught
     * dml_read_exception. RED-FIRST PROOF (quoted verbatim in the report):
     * with classes/table/groups_table.php's no_sorting('leadername') /
     * no_sorting('guidename') calls removed, this test's render() throws
     * exactly that exception instead of returning. Fixed by excluding both
     * columns from sorting, the same way size and actions already are.
     */
    public function test_sorting_by_a_display_only_column_does_not_crash_the_listing(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity();
        $leader = $this->make_student($course);
        $guide = $this->make_teacher($course);
        $api->create_group((int) $leader->id, 'Display sort group', 'T', '<p>Brief</p>', FORMAT_HTML);

        foreach (['leadername', 'guidename'] as $column) {
            $table = new groups_table(
                'ssascaledisplaysort_' . $column,
                $activity,
                $api->gatekeeper(),
                $this->table_url($activity),
                '',
                false,
                (int) $guide->id
            );
            $html = $this->render_sorted($table, 50, $column);

            $this->assertNotEmpty(
                $table->rawdata,
                "requesting a sort by the DISPLAY-only column $column must still return the listing, not an "
                    . 'empty page or a thrown query error'
            );
            $this->assertStringContainsString(
                'Display sort group',
                $html,
                "the row must actually render when sorted by $column, not be lost to a query error"
            );
        }
    }

    // ------------------------------------------------------------------
    // 3. The state filter.

    /**
     * 28 groups, 7 in each of the four states, built through real
     * transitions. Filtering to a state returns EXACTLY that state's 7
     * groups - inclusion (all 7 present) and exclusion (none of the other
     * 21) both asserted by the one set-equality comparison.
     */
    public function test_state_filter_includes_exactly_and_excludes_exactly(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity(['maxguided' => 500]);
        $guide = $this->make_teacher($course);

        $perstate = 7;
        $idsbystate = [];
        foreach (state::all() as $st) {
            $idsbystate[$st] = [];
            for ($i = 1; $i <= $perstate; $i++) {
                $leader = $this->make_student($course);
                $group = $this->build_group($activity, $api, (int) $leader->id, "Filter $st $i", $st, (int) $guide->id);
                $idsbystate[$st][] = (int) $group->id;
            }
        }
        $totalgroups = array_sum(array_map('count', $idsbystate));
        $this->assertSame(
            4 * $perstate,
            $totalgroups,
            'fixture sanity: the right number of groups (N>0) were actually built before filtering any of them'
        );

        foreach (state::all() as $st) {
            $table = new groups_table(
                'ssascalefilter_' . $st,
                $activity,
                $api->gatekeeper(),
                $this->table_url($activity),
                $st,
                false,
                (int) $guide->id
            );
            $this->render($table, 50);
            $got = array_keys($table->rawdata);
            sort($got);
            $expected = $idsbystate[$st];
            sort($expected);
            $this->assertSame(
                $expected,
                $got,
                "filtering to $st must return EXACTLY its own 7 groups: none missing (inclusion), none from "
                    . 'another state (exclusion)'
            );
        }
    }

    // ------------------------------------------------------------------
    // 4. The count matches the content.

    /**
     * The table's reported total equals a full unpaged fetch of the same
     * query - unfiltered (16 groups) and filtered to FIRM (4 groups) alike.
     */
    public function test_totalrows_matches_a_full_unpaged_fetch_with_and_without_a_filter(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity(['maxguided' => 500]);
        $guide = $this->make_teacher($course);

        $perstate = 4;
        foreach (state::all() as $st) {
            for ($i = 1; $i <= $perstate; $i++) {
                $leader = $this->make_student($course);
                $this->build_group($activity, $api, (int) $leader->id, "Count $st $i", $st, (int) $guide->id);
            }
        }
        $total = 4 * $perstate;

        $paged = new groups_table(
            'ssascalecounta',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $guide->id
        );
        $this->render($paged, 3);
        $full = new groups_table(
            'ssascalecountb',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $guide->id
        );
        $this->render($full, 200);

        $this->assertGreaterThan(0, count($full->rawdata), 'the unpaged fetch must not have examined nothing');
        $this->assertCount($total, $full->rawdata, 'fixture sanity: the unpaged fetch must see every group');
        $this->assertSame(
            count($full->rawdata),
            (int) $paged->totalrows,
            'the reported total must equal a full unpaged fetch of the same query, unfiltered'
        );

        $pagedfiltered = new groups_table(
            'ssascalecountc',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            state::FIRM,
            false,
            (int) $guide->id
        );
        $this->render($pagedfiltered, 2);
        $fullfiltered = new groups_table(
            'ssascalecountd',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            state::FIRM,
            false,
            (int) $guide->id
        );
        $this->render($fullfiltered, 200);

        $this->assertCount(
            $perstate,
            $fullfiltered->rawdata,
            'fixture sanity: the unpaged filtered fetch must see only the FIRM groups'
        );
        $this->assertSame(
            count($fullfiltered->rawdata),
            (int) $pagedfiltered->totalrows,
            'the reported total must equal a full unpaged fetch of the same query, filtered to FIRM'
        );
    }

    // ------------------------------------------------------------------
    // 5. Authority at scale.

    /**
     * A bare :viewall holder (no :freeze, no :unfreeze, no :manage, no
     * :coordinate) reaches a real page of 12 rows (6 FIRM, 6 FROZEN) and is
     * offered View on every single one, but Freeze or Unfreeze on NONE of
     * them - checked by counting occurrences across the whole rendered
     * page, so a bug that hid the action on only the first row (and leaked
     * it on the rest) would not slip past a single spot check the way it
     * would in allgroups_native_test.php's one- and two-row fixtures.
     */
    public function test_viewall_only_holder_at_scale_is_never_offered_freeze_or_unfreeze(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity(['maxguided' => 500]);
        $guide = $this->make_teacher($course);
        $viewer = $this->viewall_only_user($activity);

        $n = 6;
        for ($i = 1; $i <= $n; $i++) {
            $leader = $this->make_student($course);
            $this->build_group($activity, $api, (int) $leader->id, "Firm scale $i", state::FIRM, (int) $guide->id);
        }
        for ($i = 1; $i <= $n; $i++) {
            $leader = $this->make_student($course);
            $this->build_group($activity, $api, (int) $leader->id, "Frozen scale $i", state::FROZEN, (int) $guide->id);
        }

        $this->assertTrue(
            has_capability('mod/selfselectadvanced:viewall', $activity->context(), (int) $viewer->id),
            'the fixture must actually hold :viewall or the test proves nothing'
        );
        $this->assertFalse(has_capability('mod/selfselectadvanced:freeze', $activity->context(), (int) $viewer->id));
        $this->assertFalse(has_capability('mod/selfselectadvanced:unfreeze', $activity->context(), (int) $viewer->id));

        $table = new groups_table(
            'ssascaleviewall',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $viewer->id
        );
        $html = $this->render($table, 50);

        $this->assertCount(2 * $n, $table->rawdata, 'fixture sanity: every built row must be on the one page');
        $viewneedle = '>' . get_string('view') . '<';
        $this->assertSame(
            2 * $n,
            substr_count($html, $viewneedle),
            'View must be offered on every one of the ' . (2 * $n) . ' rows, at scale'
        );
        $freezeneedle = '>' . get_string('freeze', 'mod_selfselectadvanced') . '<';
        $unfreezeneedle = '>' . get_string('unfreeze', 'mod_selfselectadvanced') . '<';
        $this->assertSame(
            0,
            substr_count($html, $freezeneedle),
            "a bare viewall holder must not be offered Freeze on ANY of the $n FIRM rows"
        );
        $this->assertSame(
            0,
            substr_count($html, $unfreezeneedle),
            "a bare viewall holder must not be offered Unfreeze on ANY of the $n FROZEN rows"
        );
    }

    /**
     * A real, appointed Group Coordinator (ledger row 112) reaches a page
     * of 10 FIRM rows, 7 of which the coordinator is uninvolved in and 3 of
     * which the coordinator is a confirmed MEMBER of. Freeze must appear on
     * exactly the 7 uninvolved rows - counted across the whole page, so a
     * conflict-of-interest guard that only worked on the first involved row
     * it happened to meet cannot pass this the way it could pass a
     * one-row check.
     */
    public function test_coordinator_at_scale_offered_freeze_only_on_uninvolved_firm_rows(): void {
        $this->resetAfterTest();
        [$activity, $api, $course] = $this->setup_activity(['maxguided' => 500]);
        $guide = $this->make_teacher($course);
        $coordinator = $this->real_coordinator($activity);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $uninvolved = 7;
        $involved = 3;
        for ($i = 1; $i <= $uninvolved; $i++) {
            $leader = $this->make_student($course);
            $this->build_group($activity, $api, (int) $leader->id, "Uninvolved firm $i", state::FIRM, (int) $guide->id);
        }
        for ($i = 1; $i <= $involved; $i++) {
            $leader = $this->make_student($course);
            $group = $this->build_group($activity, $api, (int) $leader->id, "Involved firm $i", state::FIRM, (int) $guide->id);
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $coordinator->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
        }

        $table = new groups_table(
            'ssascalecoord',
            $activity,
            $api->gatekeeper(),
            $this->table_url($activity),
            '',
            false,
            (int) $coordinator->id
        );
        $html = $this->render($table, 50);

        $this->assertCount($uninvolved + $involved, $table->rawdata, 'fixture sanity: every built row must be on the page');
        $freezeneedle = '>' . get_string('freeze', 'mod_selfselectadvanced') . '<';
        $this->assertSame(
            $uninvolved,
            substr_count($html, $freezeneedle),
            'Freeze must appear on exactly the ' . $uninvolved . ' uninvolved FIRM rows and never the '
                . $involved . ' the coordinator is a confirmed member of - checked at scale across all '
                . ($uninvolved + $involved) . ' rows, not a single spot check'
        );
    }
}
