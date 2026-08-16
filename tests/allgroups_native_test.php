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
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\table\groups_table;

/**
 * 1.20.47: the landing page's "All groups" listing becomes the real
 * groups_table manage.php's Teams tab already drives - native paging,
 * sorting and a state filter, reached by every mod/selfselectadvanced:viewall
 * holder rather than only a :manage holder.
 *
 * THE BUG THIS SLICE FIXES: before this release the export was
 * `$data->canseeallgroups = $data->ismanager`, so a :viewall holder who was
 * NOT a :manage holder saw a 20-row panel with no route onward - manage.php
 * would have refused them too. Gating the listing on :viewall directly
 * reaches that person, and (maintainer amendment, 2026-08-15) reaches every
 * Group Coordinator too, because coordinatorrole::capabilities() already
 * grants :viewall alongside :coordinate. That claim is PROVEN here against a
 * real appointed coordinator, not asserted from the capability list.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\table\groups_table
 * @covers     \mod_selfselectadvanced\output\landing
 */
final class allgroups_native_test extends \advanced_testcase {
    /**
     * One activity, one leader, and $groupcount groups (all FORMING,
     * distinct names so a per-page/per-sort assertion can name a row).
     *
     * @param int $groupcount how many groups to create
     * @return array [activity, api, leader]
     */
    private function setup_activity(int $groupcount): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
        ]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user();
        $generator->enrol_user($leader->id, $course->id, 'student');

        for ($i = 1; $i <= $groupcount; $i++) {
            $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $leader->id,
                'name' => sprintf('Group %02d', $i),
            ]);
        }

        return [$activity, new api($activity), $leader];
    }

    /**
     * A user holding ONLY mod/selfselectadvanced:viewall at the module
     * context - not :manage, not :coordinate, not :freeze, not :unfreeze,
     * and not the guide of anything. A fresh custom role granting exactly
     * that one capability, so this fixture is precisely "a bare :viewall
     * holder" and nothing more generous.
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
     * A REAL Group Coordinator, appointed exactly the way
     * coordinatorappoint_test.php does: coordinatorimport::appoint(), which
     * enforces the plugin's own eligibility rule (role ARCHETYPE 'teacher',
     * never a shortname a site may have renamed) and writes the role this
     * plugin actually ships - not a hand-built stand-in for it, so
     * coordinatorrole::capabilities()'s :viewall grant is the one under
     * test.
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
     * THE BUG, pinned directly: a :viewall holder without :manage reaches a
     * listing whose row count is bounded by the PAGE SIZE - not by the old
     * 20-row cap, and not by the full 60.
     */
    public function test_viewall_only_non_manager_reaches_a_listing_bounded_by_perpage(): void {
        $this->resetAfterTest();
        [$activity, $api] = $this->setup_activity(60);
        $viewer = $this->viewall_only_user($activity);

        $this->assertTrue(
            has_capability('mod/selfselectadvanced:viewall', $activity->context(), (int) $viewer->id),
            'the fixture must actually hold :viewall or the test proves nothing'
        );
        $this->assertFalse(
            has_capability('mod/selfselectadvanced:manage', $activity->context(), (int) $viewer->id),
            'this arm is specifically the :viewall-but-not-:manage person the old code stranded'
        );

        $table = new groups_table(
            'ssaallgroupstest1',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            '',
            false,
            (int) $viewer->id
        );
        $this->render($table, 10);

        $this->assertIsArray($table->rawdata);
        $this->assertCount(
            10,
            $table->rawdata,
            'a 60-group activity at perpage=10 must show exactly 10 rows on the page - '
                . 'neither the old 20-row cap nor the full 60'
        );
    }

    /**
     * Negative control: a viewall-only viewer - no :manage, no :coordinate,
     * not the guide of either team - is offered View on a FIRM and a
     * FROZEN team, but neither Freeze nor Unfreeze. col_actions() asks
     * freeze::may_freeze_team()/may_unfreeze_team() with the REAL actor id,
     * so an actor entitled to neither must be offered neither.
     */
    public function test_viewall_only_viewer_is_not_offered_freeze_or_unfreeze(): void {
        $this->resetAfterTest();
        [$activity, $api, $leader] = $this->setup_activity(0);
        $viewer = $this->viewall_only_user($activity);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Firm team',
            'state' => state::FIRM,
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Frozen team',
            'state' => state::FROZEN,
            'frozenbystaff' => 0,
        ]);

        $table = new groups_table(
            'ssaallgroupstest2',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            '',
            false,
            (int) $viewer->id
        );
        $html = $this->render($table, 10);

        $this->assertStringContainsString('>' . get_string('view') . '<', $html, 'View must still be offered');
        $this->assertStringNotContainsString(
            '>' . get_string('freeze', 'mod_selfselectadvanced') . '<',
            $html,
            'a bare :viewall holder must not be offered Freeze'
        );
        $this->assertStringNotContainsString(
            '>' . get_string('unfreeze', 'mod_selfselectadvanced') . '<',
            $html,
            'a bare :viewall holder must not be offered Unfreeze'
        );
    }

    /**
     * Positive control for the test above: without it, a col_actions()
     * that silently dropped Freeze/Unfreeze for EVERYONE would still pass
     * the negative control - this proves the assertions there are actually
     * measuring something. A :manage holder legitimately gets both, via
     * the SAME predicate the negative control just found refusing them.
     */
    public function test_manager_viewer_is_offered_freeze_and_unfreeze(): void {
        $this->resetAfterTest();
        [$activity, $api, $leader] = $this->setup_activity(0);
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manager->id, $activity->cm()->course, 'editingteacher');
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');

        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Firm team',
            'state' => state::FIRM,
        ]);
        $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Frozen team',
            'state' => state::FROZEN,
            'frozenbystaff' => 0,
        ]);

        $table = new groups_table(
            'ssaallgroupstest3',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            '',
            false,
            (int) $manager->id
        );
        $html = $this->render($table, 10);

        $this->assertStringContainsString('>' . get_string('freeze', 'mod_selfselectadvanced') . '<', $html);
        $this->assertStringContainsString('>' . get_string('unfreeze', 'mod_selfselectadvanced') . '<', $html);
    }

    /**
     * Source-pin: the dead hand-rolled panel is genuinely gone, not merely
     * unreachable. A source-level check is appropriate here (spirit of
     * control_state_test.php's own source-pins) because the point is that
     * the EXPORT no longer exists, which no fixture-driven assertion can
     * show as cleanly as reading the file.
     */
    public function test_the_removed_allgroups_exports_are_genuinely_gone(): void {
        $root = realpath(__DIR__ . '/..');
        $landingphp = file_get_contents($root . '/classes/output/landing.php');
        $landingmustache = file_get_contents($root . '/templates/landing.mustache');
        $this->assertNotFalse($landingphp);
        $this->assertNotFalse($landingmustache);

        $phplandingneedles = [
            'ALLGROUPS_LIMIT', "'allgroups'", "'hasallgroups'", "'canseeallgroups'", "'manageallurl'",
            "'allgroupstruncated'", "'allgroupsshowingtext'", "'isstaff'",
        ];
        foreach ($phplandingneedles as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $landingphp,
                $needle . ' should be gone from classes/output/landing.php (1.20.47)'
            );
        }
        foreach (['isstaff', 'hasallgroups', 'canseeallgroups', 'manageallurl', 'allgroupstruncated'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $landingmustache,
                $needle . ' should be gone from templates/landing.mustache (1.20.47)'
            );
        }
    }

    /**
     * AMENDMENT (maintainer, 2026-08-15): a real, appointed Group
     * Coordinator reaches the paged listing through the :viewall gate -
     * proven against coordinatorimport::appoint()'s actual role grant, not
     * asserted from coordinatorrole::capabilities()'s source list.
     */
    public function test_coordinator_reaches_the_paged_listing_via_the_viewall_gate(): void {
        $this->resetAfterTest();
        [$activity, $api] = $this->setup_activity(15);
        $coordinator = $this->real_coordinator($activity);

        $this->assertTrue(
            has_capability('mod/selfselectadvanced:viewall', $activity->context(), (int) $coordinator->id),
            'coordinatorrole::capabilities() is supposed to grant :viewall - proven on a real appointment, not assumed'
        );

        $table = new groups_table(
            'ssaallgroupstest4',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            '',
            false,
            (int) $coordinator->id
        );
        $this->render($table, 10);

        $this->assertIsArray($table->rawdata);
        $this->assertCount(10, $table->rawdata, 'the coordinator reaches a real paged listing, bounded by perpage');
    }

    /**
     * Positive control: an uninvolved coordinator legitimately sees Freeze
     * on a FIRM team - the ON-BEHALF branch strategy 1.16 D added
     * (require_freeze_team(): a :coordinate holder who is not conflicted).
     * Needed so the conflict-of-interest test below is not vacuously true
     * (a coordinator who never sees Freeze at all would also pass it).
     */
    public function test_coordinator_sees_freeze_on_an_uninvolved_firm_team(): void {
        $this->resetAfterTest();
        [$activity, $api, $leader] = $this->setup_activity(0);
        $coordinator = $this->real_coordinator($activity);
        $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced')->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Uninvolved firm team',
            'state' => state::FIRM,
        ]);

        $table = new groups_table(
            'ssaallgroupstest5',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            '',
            false,
            (int) $coordinator->id
        );
        $html = $this->render($table, 10);

        $this->assertStringContainsString('>' . get_string('freeze', 'mod_selfselectadvanced') . '<', $html);
    }

    /**
     * AMENDMENT requirement 2's other half: "sees no action they cannot
     * perform (same rule as a viewall-only viewer)". A coordinator holds
     * :coordinate everywhere, so a naive gate might offer Freeze on every
     * FIRM row - but freeze::require_freeze_team()'s conflict-of-interest
     * guard (tickets::require_uninvolved()) refuses it on a team this
     * coordinator is a CONFIRMED MEMBER of, and col_actions() asks that
     * same predicate, not a blanket "holds :coordinate" check. Freeze must
     * be absent from THIS team's row even though it was present on the
     * uninvolved team above.
     */
    public function test_coordinator_conflict_of_interest_still_hides_freeze(): void {
        $this->resetAfterTest();
        [$activity, $api, $leader] = $this->setup_activity(0);
        $coordinator = $this->real_coordinator($activity);
        $plugingen = $this->getDataGenerator()->get_plugin_generator('mod_selfselectadvanced');
        $conflicted = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team the coordinator is in',
            'state' => state::FIRM,
        ]);
        $plugingen->create_member([
            'groupid' => $conflicted->id,
            'userid' => (int) $coordinator->id,
            'status' => \mod_selfselectadvanced\local\groups::STATUS_CONFIRMED,
        ]);

        $table = new groups_table(
            'ssaallgroupstest6',
            $activity,
            $api->gatekeeper(),
            new \moodle_url('/mod/selfselectadvanced/view.php', ['id' => $activity->cm()->id]),
            '',
            false,
            (int) $coordinator->id
        );
        $html = $this->render($table, 10);

        $this->assertStringNotContainsString(
            '>' . get_string('freeze', 'mod_selfselectadvanced') . '<',
            $html,
            'a coordinator involved in the team must not be offered Freeze, despite holding :coordinate generally'
        );
    }
}
