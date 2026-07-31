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
                set_role_contextlevels($roleid, [CONTEXT_COURSE, CONTEXT_MODULE]);
            }
            set_config(self::CONFIG_ROLEID, $roleid, 'mod_selfselectadvanced');
        }
        unset_config(self::CONFIG_COLLISION, 'mod_selfselectadvanced');

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
