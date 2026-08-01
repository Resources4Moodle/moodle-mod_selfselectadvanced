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

/**
 * The Group Coordinator role (strategy 1.16 D): non-editing teachers
 * who work the ticket queue and handle freeze/unfreeze, and may also
 * serve as guides - with the conflict-of-interest guard keeping them
 * out of groups they are involved in.
 *
 * Plugins cannot declare roles in db/access.php, so install and
 * upgrade both call ensure().
 *
 * WHERE THIS ROLE IS EVER HELD (1.20.0). Every assignment this plugin
 * makes is at CONTEXT_MODULE, carrying component
 * 'mod_selfselectadvanced'. There are exactly three writers and no
 * fourth: coordinatorimport::appoint(), coordinatorimport::run() and
 * migrate_to_module_context() below. That is the guarantee any
 * capability granted to this role rests on - it can only ever take
 * effect in an activity where somebody was actually appointed, never
 * across a course and never site-wide.
 *
 * The role is assignable at CONTEXT_MODULE ONLY (maintainer decision,
 * 1.20.1: it "does work within our plugin only"). Course context was
 * withdrawn because every capability the role carries is declared at
 * CONTEXT_MODULE and asked for per activity, so a course-level
 * assignment was never anything but a silent widening across every
 * instance in the course.
 *
 * Legacy course-context rows keep working and stay manageable, and
 * that does NOT depend on assignability: the coordinators screen lists
 * holders with get_role_users($roleid, $context, true) and
 * coordinatorimport reads {role_assignments} directly at BOTH contexts
 * (run() and remove()), while remove() -> strip() calls role_unassign()
 * at the module AND the course context. Core consults
 * get_role_contextlevels() only for the role-assign screens and
 * get_assignable_roles(), never for role_assign(), role_unassign() or
 * any read of {role_assignments}. migrate_to_module_context() still
 * retires those rows where a course actually holds an instance.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coordinatorrole {
    /**
     * INVARIANT: nothing in this class may touch a plugin TABLE.
     *
     * db/upgrade.php calls ensure() from inside upgrade steps, where
     * the PHP is already the new code but the schema is still at
     * whatever savepoint that step begins from. Everything here works
     * against core only - the role table, plugin config and the
     * capability API - so it behaves identically at every savepoint.
     *
     * Add a query against a selfselectadvanced_* table and you break
     * every historical upgrade path that runs this, for sites coming
     * from an old version. Which is the one case nobody tests, because
     * the current-version upgrade would keep passing.
     *
     * @var string The role shortname.
     */
    public const SHORTNAME = 'groupcoordinator';

    /** @var string Config holding the id of the role WE created. */
    public const CONFIG_ROLEID = 'coordinatorroleid';

    /** @var string Config naming a foreign role that blocked us. */
    public const CONFIG_COLLISION = 'coordinatorrolecollision';

    /**
     * Create the Group Coordinator role if the site does not have it.
     *
     * Runs on install and on every upgrade. The plugin grants its
     * capabilities ONLY to a role it created itself, whose id it
     * recorded at the time.
     *
     * Until 1.19.1 this adopted any role that happened to carry the
     * shortname "groupcoordinator" and granted it six capabilities at
     * SYSTEM level - including :override and :viewall. On a site that
     * already used that shortname for something of its own, installing
     * this plugin silently handed every holder of that role powers
     * across the whole site. Sharing a shortname is not consent, and an
     * installer must never widen a role it did not create.
     *
     * A collision is recorded rather than worked around, because the
     * resolution is an administrator's to choose: rename their role, or
     * grant this plugin's capabilities to it deliberately.
     *
     * @return int the role id, or 0 when a foreign role blocks us
     */
    public static function ensure(): int {
        global $DB;

        $roleid = 0;
        $recorded = (int) get_config('mod_selfselectadvanced', self::CONFIG_ROLEID);
        if ($recorded > 0 && $DB->record_exists('role', ['id' => $recorded])) {
            // Ours, from an earlier run. The shortname may since have
            // been changed by an administrator; the id is what binds.
            $roleid = $recorded;
        } else {
            $existing = $DB->get_record('role', ['shortname' => self::SHORTNAME]);
            if ($existing && !self::looks_like_ours($existing)) {
                // Somebody else's role. Leave it completely alone.
                set_config(self::CONFIG_COLLISION, self::SHORTNAME, 'mod_selfselectadvanced');

                return 0;
            }
            if ($existing) {
                // Created by this plugin before it recorded ids: adopt
                // it once, so later releases can still top it up.
                $roleid = (int) $existing->id;
            } else {
                $roleid = create_role(
                    get_string('coordinatorrole', 'mod_selfselectadvanced'),
                    self::SHORTNAME,
                    get_string('coordinatorrole_desc', 'mod_selfselectadvanced'),
                    'teacher'
                );
                set_role_contextlevels($roleid, [CONTEXT_MODULE]);
            }
            set_config(self::CONFIG_ROLEID, $roleid, 'mod_selfselectadvanced');
        }
        unset_config(self::CONFIG_COLLISION, 'mod_selfselectadvanced');

        // ACTIVITY ONLY (maintainer decision, 1.20.1). Every capability
        // this role carries is declared at CONTEXT_MODULE and asked for
        // per activity, and the role exists to do work inside ONE
        // activity, so a course-level assignment was never anything but
        // a silent widening: it carried :viewall, :overriderules,
        // :managecomposition and :assignguide into every instance in
        // the course at once.
        //
        // This edits ASSIGNABILITY, not assignments.
        // get_role_contextlevels() is consulted by core's role-assign
        // screens and by get_assignable_roles(); it is NOT consulted by
        // role_assign(), role_unassign() or by any read of
        // {role_assignments}. So an existing course-level row keeps
        // granting exactly what it granted yesterday, stays listed on
        // the coordinators screen and stays removable from it (see the
        // class docblock). Nobody is stripped of a job they were doing;
        // the plugin simply stops offering a shape it does not support.
        //
        // This runs on EVERY branch - recorded, adopted and created -
        // because before 1.20.0 only the create branch ever set levels
        // at all, so a site that installed before ids were recorded, or
        // that lost the row, had a role it could not assign at an
        // activity. Values are cast because the levels come back from
        // the database as strings on both engines, and an unnormalised
        // strict comparison would rewrite the rows on every call.
        $levels = array_map('intval', array_values(get_role_contextlevels($roleid)));
        $wanted = [CONTEXT_MODULE];
        if ($levels !== $wanted) {
            set_role_contextlevels($roleid, $wanted);
        }

        $systemcontext = \context_system::instance();
        foreach (self::capabilities() as $capability) {
            // With overwrite off this fills in what is missing and
            // never overrules a prevent or prohibit already recorded.
            // A site that has tuned the role keeps its decisions; an
            // upgrade must never quietly restore a permission an
            // administrator chose to take away.
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, false);
        }
        $systemcontext->mark_dirty();

        return $roleid;
    }

    /**
     * Move every course-context appointment to the activities it meant.
     *
     * Until 1.20.0 an appointment was a role assignment at the COURSE,
     * although it was made from one activity and every capability the
     * role carries is declared at CONTEXT_MODULE and asked for per
     * activity. A course grant was therefore silently course-wide: a
     * coordinator appointed in one selfselectadvanced instance could
     * freeze, override and see everybody in every other instance of the
     * same course.
     *
     * Each course row is fanned out to EVERY selfselectadvanced
     * instance in that course and then retired. Fanning out rather than
     * picking one instance is the only choice that takes nothing away:
     * the site never recorded which instance an appointment came from,
     * so any narrower guess would silently strip somebody of a job they
     * were doing. A course with no instance to migrate into keeps its
     * row untouched - deleting it would erase the appointment with no
     * successor.
     *
     * Nobody is filtered out by today's eligibility rule. Existing
     * appointments are decisions a site already made; the rule applies
     * to new ones.
     *
     * Direct $DB writes rather than role_assign(): an upgrade step must
     * not fire role_assigned events into observers mid-upgrade, and the
     * upgrade pipeline purges caches on completion. Core tables only,
     * per this class's invariant. No transaction: every write is a
     * single row and the record_exists() guard makes a partial run
     * complete cleanly on the next attempt.
     */
    public static function migrate_to_module_context(): void {
        global $DB;

        $roleid = (int) get_config('mod_selfselectadvanced', self::CONFIG_ROLEID);
        if ($roleid <= 0 || !$DB->record_exists('role', ['id' => $roleid])) {
            // A collision site never created a role of ours, and a site
            // whose role was deleted has nothing left to move.
            return;
        }

        // Every course-context assignment of our role, streamed.
        $rs = $DB->get_recordset_sql(
            "SELECT ra.id, ra.userid, ctx.instanceid AS courseid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :courselevel
              WHERE ra.roleid = :roleid",
            ['courselevel' => CONTEXT_COURSE, 'roleid' => $roleid]
        );
        $bycourse = [];
        foreach ($rs as $ra) {
            $bycourse[(int) $ra->courseid][(int) $ra->userid][] = (int) $ra->id;
        }
        $rs->close();
        if (!$bycourse) {
            return;
        }

        // Every selfselectadvanced course_module in those courses, in
        // one query rather than one per course.
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($bycourse), SQL_PARAMS_NAMED, 'crs');
        $cms = $DB->get_records_sql(
            "SELECT cm.id, cm.course
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE cm.course $insql",
            ['modname' => 'selfselectadvanced'] + $inparams
        );
        $cmsbycourse = [];
        foreach ($cms as $cm) {
            $cmsbycourse[(int) $cm->course][] = (int) $cm->id;
        }

        foreach ($bycourse as $courseid => $holders) {
            if (empty($cmsbycourse[$courseid])) {
                continue;
            }
            foreach ($cmsbycourse[$courseid] as $cmid) {
                $modcontext = \context_module::instance($cmid);
                foreach ($holders as $userid => $unusedraids) {
                    $params = [
                        'roleid' => $roleid,
                        'contextid' => $modcontext->id,
                        'userid' => $userid,
                        'component' => 'mod_selfselectadvanced',
                        'itemid' => 0,
                    ];
                    if ($DB->record_exists('role_assignments', $params)) {
                        continue;
                    }
                    $DB->insert_record('role_assignments', (object) ($params + [
                        'timemodified' => time(),
                        'modifierid' => 0,
                        'sortorder' => 0,
                    ]));
                }
                $modcontext->mark_dirty();
            }
            foreach ($holders as $unuseduserid => $raids) {
                foreach ($raids as $raid) {
                    $DB->delete_records('role_assignments', ['id' => $raid]);
                }
            }
            // IGNORE_MISSING returns FALSE rather than null, so a
            // nullsafe call here would fatal on an orphaned course.
            $coursectx = \context_course::instance($courseid, IGNORE_MISSING);
            if ($coursectx) {
                $coursectx->mark_dirty();
            }
        }
    }

    /**
     * The role id at RUNTIME, without granting anything.
     *
     * ensure() is a provisioning step: it writes capabilities. Calling
     * it from a page view - which coordinators.php and the appoint /
     * remove paths did - meant that a capability an administrator had
     * deliberately set back to "Not set" was re-granted the next time
     * anybody opened the page. `assign_capability()` with overwrite off
     * declines to change a setting that exists; "Not set" is the
     * ABSENCE of a setting, so it was refilled every time. An
     * administrator's decision has to survive somebody visiting a page.
     *
     * So runtime resolves, and only provisioning provisions. The
     * fallback to ensure() covers the one case where there is nothing
     * to resolve yet.
     *
     * @return int the role id, or 0 when a foreign role blocks us
     */
    public static function roleid(): int {
        global $DB;

        $recorded = (int) get_config('mod_selfselectadvanced', self::CONFIG_ROLEID);
        if ($recorded > 0 && $DB->record_exists('role', ['id' => $recorded])) {
            return $recorded;
        }

        return self::ensure();
    }

    /**
     * The capabilities the coordinator role carries.
     *
     * WHY THIS LIST IS SAFE TO WIDEN (1.20.0). Every one of these is
     * declared at CONTEXT_MODULE in db/access.php and every consumer
     * asks for it at $activity->context(). ensure() records them
     * against the ROLE, at system context, which is where Moodle keeps
     * every role definition - a role definition is not a grant. What
     * turns a definition into authority is an ASSIGNMENT, and the class
     * docblock above states the guarantee this rests on: the only
     * assignments this plugin makes are role_assignments rows at an
     * ACTIVITY's CONTEXT_MODULE carrying component
     * 'mod_selfselectadvanced' (coordinatorimport::appoint(),
     * coordinatorimport::run(), migrate_to_module_context() - three
     * writers, no fourth). So a capability listed here can only ever
     * take effect in an activity where somebody was actually appointed:
     * never across a course, never site-wide.
     *
     * :overriderules is on this list for exactly that reason
     * (maintainer decision 14). It is the staff hatch that bypasses a
     * composition rule, parks a student with no destination and
     * dissolves a dead-end team, so it is only defensible where the
     * appointment is: T-05 moved appointments to CONTEXT_MODULE FIRST,
     * and this release grants the capability SECOND, so it never
     * existed on the role while a course-wide row could carry it. Two
     * residues are worth naming rather than glossing:
     *  - A course-context row recorded BEFORE 1.20.1 still grants what
     *    it granted, in every instance in that course. The role is no
     *    longer OFFERED at course context (ensure() sets the levels to
     *    CONTEXT_MODULE alone), but assignability is not a grant: an
     *    existing assignment is an administrator's recorded decision
     *    and is never revoked here. migrate_to_module_context() retires
     *    those rows where a course actually holds an instance.
     *  - migrate_to_module_context() leaves a course-context row alone
     *    when its course holds no selfselectadvanced instance, because
     *    there is nowhere to move it to. Such a row grants nothing
     *    WHILE that stays true - with no instance there is no
     *    CONTEXT_MODULE below it for a CONTEXT_MODULE capability to be
     *    asked at. It is not inert forever: the migration runs once, at
     *    its savepoint, so if somebody later adds an instance to that
     *    course the surviving row reaches it, exactly as a course-wide
     *    appointment used to. Said plainly rather than glossed, because
     *    the alternative - deleting the row - erases an appointment
     *    with no successor, which is the reason it is kept.
     *
     * :managecomposition and :assignguide are the narrow slices of
     * :manage this release introduces, so a coordinator can carry out
     * the composition change they resolved a ticket about. Neither
     * brings settings, quotas, dates or auto-grouping with it, and the
     * conflict-of-interest guard refuses both on any team the holder is
     * involved in.
     *
     * @return string[]
     */
    public static function capabilities(): array {
        return [
            'mod/selfselectadvanced:coordinate',
            'mod/selfselectadvanced:guide',
            'mod/selfselectadvanced:viewall',
            'mod/selfselectadvanced:freeze',
            'mod/selfselectadvanced:unfreeze',
            'mod/selfselectadvanced:override',
            'mod/selfselectadvanced:overriderules',
            'mod/selfselectadvanced:managecomposition',
            'mod/selfselectadvanced:assignguide',
            'mod/selfselectadvanced:viewassignedteams',
        ];
    }

    /**
     * Whether an unrecorded role with our shortname is one we made.
     *
     * Checked only on the one upgrade that introduces the recorded id,
     * for sites where this plugin did create the role before it kept
     * track. It has to be conservative: a role we did not create must
     * fail this, even at the cost of failing one we did (in which case
     * the administrator is told, and can grant the capabilities
     * themselves).
     *
     * @param \stdClass $role the role row
     * @return bool
     */
    protected static function looks_like_ours(\stdClass $role): bool {
        if ($role->archetype !== 'teacher') {
            return false;
        }
        // Created with our name, and not renamed since. Comparing the
        // untranslated string as well covers a site whose language has
        // changed since the role was made.
        $ours = [get_string('coordinatorrole', 'mod_selfselectadvanced'), 'Group Coordinators'];

        return in_array((string) $role->name, $ours, true);
    }

    /**
     * The foreign role blocking us, if one is.
     *
     * @return string|null the shortname, or null when there is no clash
     */
    public static function collision(): ?string {
        $value = get_config('mod_selfselectadvanced', self::CONFIG_COLLISION);

        return $value === false || $value === '' ? null : (string) $value;
    }
}
