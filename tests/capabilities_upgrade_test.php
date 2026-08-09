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

use mod_selfselectadvanced\local\authority;

/**
 * The 1.20 Moodle Manager grants have to reach an UPGRADED site.
 *
 * db/access.php gained 'manager' => CAP_ALLOW on :unfreeze, :manage,
 * :override and :viewall in 1.20 (decision 6, D6-7), and
 * docs/architecture.md recorded them as landed. Editing an archetype
 * list only ever reaches a FRESH install: core's update_capabilities()
 * builds its "new capabilities" set from the file's capabilities that
 * are ABSENT from the capabilities table and only that set reaches
 * assign_legacy_capabilities(), so on a site that installed 1.19.x the
 * four names are already known and nothing is assigned. The repair is a
 * db/upgrade.php step at savepoint 2026073150 that asserts the four
 * grants explicitly - without overruling any permission an
 * administrator has already recorded.
 *
 * Every assertion below is on the role_capabilities ROWS, because that
 * is the artefact the whole question is about, plus one has_capability()
 * check so the row story is tied to the answer a page actually gets.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class capabilities_upgrade_test extends \advanced_testcase {
    /** @var string[] The four capabilities db/access.php grants the manager archetype in 1.20. */
    private const MANAGERCAPS = [
        'mod/selfselectadvanced:unfreeze',
        'mod/selfselectadvanced:manage',
        'mod/selfselectadvanced:override',
        'mod/selfselectadvanced:viewall',
    ];

    /**
     * The permission recorded for one role and capability at system context.
     *
     * @param int $roleid the role
     * @param string $capability the capability name
     * @return int|null the stored permission, or null when nothing is recorded
     */
    private function permission_of(int $roleid, string $capability): ?int {
        global $DB;

        $permission = $DB->get_field('role_capabilities', 'permission', [
            'contextid' => \context_system::instance()->id,
            'roleid' => $roleid,
            'capability' => $capability,
        ]);

        return $permission === false ? null : (int) $permission;
    }

    /**
     * Put the site into the state of every pre-1.20 upgrade path.
     *
     * A 1.19.x site never had these four rows for the manager role: the
     * archetype did not list the manager, so core never wrote them.
     *
     * @param array $roles the manager-archetype roles
     */
    private function make_it_a_pre_120_site(array $roles): void {
        global $DB;

        foreach ($roles as $role) {
            foreach (self::MANAGERCAPS as $capability) {
                $DB->delete_records('role_capabilities', [
                    'contextid' => \context_system::instance()->id,
                    'roleid' => $role->id,
                    'capability' => $capability,
                ]);
            }
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Run the upgrade chain from the savepoint below the new step.
     */
    private function run_the_upgrade(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');

        set_config('version', 2026073140, 'mod_selfselectadvanced');
        xmldb_selfselectadvanced_upgrade(2026073140);
        // The savepoint sets $CFG->upgraderunning, which is what a real
        // upgrade's own completion clears; leaving it set makes every
        // later call in this test think a site upgrade is in progress
        // ("Cannot be executed during upgrade").
        $CFG->upgraderunning = 0;
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Install grants them; a 1.19.x site loses them; update_capabilities()
     * alone does NOT bring them back; the 2026073150 step does.
     */
    public function test_the_manager_grants_reach_an_upgraded_site(): void {
        $this->resetAfterTest();

        $roles = get_archetype_roles('manager');
        $this->assertNotEmpty($roles, 'this site has no role of the manager archetype');

        // Fresh install: the archetype list did its work.
        foreach ($roles as $role) {
            foreach (self::MANAGERCAPS as $capability) {
                $this->assertSame(
                    CAP_ALLOW,
                    $this->permission_of((int) $role->id, $capability),
                    $capability . ' is not granted on a fresh install'
                );
            }
        }

        $this->make_it_a_pre_120_site($roles);
        foreach ($roles as $role) {
            foreach (self::MANAGERCAPS as $capability) {
                $this->assertNull(
                    $this->permission_of((int) $role->id, $capability),
                    'the pre-1.20 state was not reached'
                );
            }
        }

        // THE MEASUREMENT. This is exactly what core runs for a module
        // upgrade, and on its own it changes nothing at all: none of the
        // four is a new capability, so none of them reaches
        // assign_legacy_capabilities().
        update_capabilities('mod_selfselectadvanced');
        accesslib_clear_all_caches_for_unit_testing();
        foreach ($roles as $role) {
            foreach (self::MANAGERCAPS as $capability) {
                $this->assertNull(
                    $this->permission_of((int) $role->id, $capability),
                    'update_capabilities() re-granted ' . $capability . '; this test no longer proves anything'
                );
            }
        }

        $this->run_the_upgrade();
        // Every later block runs too, so this is the ladder TIP, not
        // this test's own savepoint: it moves with every version.php
        // serial (2026073160 -> 2026073170 narrow capabilities -> 2026073180 contact
        // privacy -> 2026073190 least-privilege capabilities -> 2026073200 the
        // 1.20.0 release serial -> 2026073210 the 1.20.1 release serial ->
        // 2026073220 the 1.20.2 release serial).
        $this->assertSame('2026080901', get_config('mod_selfselectadvanced', 'version'));

        foreach ($roles as $role) {
            foreach (self::MANAGERCAPS as $capability) {
                $this->assertSame(
                    CAP_ALLOW,
                    $this->permission_of((int) $role->id, $capability),
                    $capability . ' still does not reach an upgraded site'
                );
            }
        }

        // And the answer a page gets, not just the row: a manager in an
        // activity context can manage, unfreeze, override and view all.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $context = \context_module::instance(
            get_coursemodule_from_instance('selfselectadvanced', (int) $instance->id)->id
        );
        $manager = $generator->create_user();
        role_assign((int) reset($roles)->id, (int) $manager->id, \context_system::instance()->id);
        foreach (self::MANAGERCAPS as $capability) {
            $this->assertTrue(
                has_capability($capability, $context, $manager),
                'a Moodle Manager cannot ' . $capability . ' after the upgrade'
            );
        }
    }

    /**
     * The 1.20.26 capability split preserves a custom role's recorded
     * creation prohibition when :lead is introduced.
     */
    public function test_lead_clones_a_custom_creategroup_prohibition_on_upgrade(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('selfselectadvanced', (int) $instance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int) $cm->id);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $roleid = create_role('Creation paused', 'selfselectadvanced_creationpaused', 'Upgrade fixture');
        set_role_contextlevels($roleid, [CONTEXT_MODULE]);
        role_change_permission($roleid, $context, authority::CREATEGROUP, CAP_PROHIBIT);
        role_assign($roleid, (int) $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability(authority::CREATEGROUP, $context, (int) $user->id));

        // The test suite is installed from the current db/access.php, so
        // remove the new capability to recreate a real 1.20.25 site.
        $DB->delete_records('role_capabilities', ['capability' => authority::LEAD]);
        $DB->delete_records('capabilities', ['name' => authority::LEAD]);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse($DB->record_exists('capabilities', ['name' => authority::LEAD]));

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/selfselectadvanced/db/upgrade.php');
        set_config('version', 2026080804, 'mod_selfselectadvanced');
        xmldb_selfselectadvanced_upgrade(2026080804);
        $CFG->upgraderunning = 0;
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($DB->record_exists('capabilities', ['name' => authority::LEAD]));
        $permission = $DB->get_field('role_capabilities', 'permission', [
            'contextid' => $context->id,
            'roleid' => $roleid,
            'capability' => authority::LEAD,
        ]);
        $this->assertSame(CAP_PROHIBIT, (int) $permission);
        $this->assertFalse(has_capability(authority::LEAD, $context, (int) $user->id));
    }

    /**
     * The step MUST NOT overrule a permission an administrator recorded.
     *
     * A site that deliberately took :manage away from the manager role
     * has a CAP_PREVENT row saying so. assign_capability() is called
     * with $overwrite left at its default false, and core returns early
     * on any existing row, so the step writes only where nothing is
     * recorded - and it is idempotent, which the second run pins.
     */
    public function test_the_upgrade_step_does_not_overrule_an_administrator(): void {
        $this->resetAfterTest();

        $roles = get_archetype_roles('manager');
        $this->make_it_a_pre_120_site($roles);

        $syscontextid = \context_system::instance()->id;
        foreach ($roles as $role) {
            assign_capability('mod/selfselectadvanced:manage', CAP_PREVENT, (int) $role->id, $syscontextid, true);
        }
        accesslib_clear_all_caches_for_unit_testing();

        $this->run_the_upgrade();

        foreach ($roles as $role) {
            $this->assertSame(
                CAP_PREVENT,
                $this->permission_of((int) $role->id, 'mod/selfselectadvanced:manage'),
                'the upgrade step overruled an administrator'
            );
            foreach (['unfreeze', 'override', 'viewall'] as $short) {
                $this->assertSame(
                    CAP_ALLOW,
                    $this->permission_of((int) $role->id, 'mod/selfselectadvanced:' . $short),
                    ':' . $short . ' was not granted alongside the administrator\'s exception'
                );
            }
        }

        // Idempotent: running it again changes nothing.
        $this->run_the_upgrade();
        foreach ($roles as $role) {
            $this->assertSame(
                CAP_PREVENT,
                $this->permission_of((int) $role->id, 'mod/selfselectadvanced:manage'),
                'a second run of the step overruled the administrator'
            );
        }
    }
}
