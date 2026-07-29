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
    /** @var string The role shortname. */
    public const SHORTNAME = 'groupcoordinator';

    /**
     * Create the Group Coordinator role if the site does not have it,
     * and (re)assert its capability set and assignable contexts.
     * Idempotent; safe on every upgrade.
     *
     * @return int the role id
     */
    public static function ensure(): int {
        global $DB;

        $existing = $DB->get_record('role', ['shortname' => self::SHORTNAME]);
        if ($existing) {
            $roleid = (int) $existing->id;
        } else {
            $roleid = create_role(
                get_string('coordinatorrole', 'mod_selfselectadvanced'),
                self::SHORTNAME,
                get_string('coordinatorrole_desc', 'mod_selfselectadvanced'),
                'teacher'
            );
        }

        set_role_contextlevels($roleid, [CONTEXT_COURSE, CONTEXT_MODULE]);

        $systemcontext = \context_system::instance();
        foreach ([
            'mod/selfselectadvanced:coordinate',
            'mod/selfselectadvanced:guide',
            'mod/selfselectadvanced:viewall',
            'mod/selfselectadvanced:freeze',
            'mod/selfselectadvanced:unfreeze',
        ] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }
        $systemcontext->mark_dirty();

        return $roleid;
    }
}
