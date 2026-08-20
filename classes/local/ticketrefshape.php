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
 * The 1.20.56 deliverable A ticket-reference shape: prefix-course-T-
 * number, e.g. SSA-PHYS101-T0042 - tickets::build_pluginuid()'s own
 * shape, taken as plain scalars rather than an activity object.
 *
 * PURE STRING LOGIC ONLY, deliberately - no database read, no plugin
 * table reference of any kind, in this file or reachable from it. That
 * is what lets BOTH of this shape's other two callers use it safely:
 *
 *  - db/upgrade.php's selfselectadvanced_upgrade_ticket_pluginuid()
 *    (docs/tools/upgrade-safety.sh, the gate's static check, refuses any
 *    db/upgrade.php call into a class whose FILE references a plugin
 *    subtable ANYWHERE in it - not merely in the method actually called -
 *    which is why that step cannot call tickets::build_pluginuid()
 *    itself: classes/local/tickets.php is full of such references);
 *  - restore_selfselectadvanced_stepslib.php's process_ssaticket(), whose
 *    OWN reason is different but the same shape of problem: resolving an
 *    activity object via activity::from_instance() depends on the
 *    course's modinfo cache recognising a course_module its own restore
 *    step may only just have created, which a live test
 *    (ticket_ladder_test.php::test_a_colliding_reference_is_regenerated_not_left_blank_on_restore)
 *    caught throwing "Invalid module ID" on a same-course restore.
 *
 * tickets::build_pluginuid() itself is NOT rewritten to call this - it
 * already has a real activity object in hand (the ordinary, most-called
 * path this shape exists for at all) and reads groups::UID_DIGITS_DEFAULT
 * for its digit width; duplicating that one call here just to save it
 * from writing four lines of sprintf() would not be reuse, it would be an
 * extra hop for no reader's benefit. What this class exists to stop is
 * the SAME logic drifting apart in the two places that cannot reach the
 * ordinary path at all.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ticketrefshape {
    /** @var int Digits in the number part - matches groups::UID_DIGITS_DEFAULT. */
    private const DIGITS = 4;

    /**
     * Build the reference from plain scalars.
     *
     * @param string $uidprefix the activity's uidprefix setting, raw
     * @param string $shortname the course shortname, raw
     * @param string $fullname the course fullname, raw (used only when
     *        the shortname sanitises to nothing)
     * @param int $courseid the course id (last-resort fallback)
     * @param int $ticketid the ticket's own DB id - the part that
     *        actually carries the uniqueness
     * @return string
     */
    public static function build(
        string $uidprefix,
        string $shortname,
        string $fullname,
        int $courseid,
        int $ticketid
    ): string {
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($uidprefix));
        if ($prefix === '') {
            $prefix = 'SSA';
        }
        $short = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shortname));
        if ($short === '') {
            $short = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $fullname));
        }
        if ($short === '') {
            $short = 'C' . $courseid;
        }
        $short = substr($short, 0, 12);

        // A ticket id longer than the fixed width keeps all its digits:
        // the number is an identity, never truncated to fit a format.
        return substr($prefix, 0, 8) . '-' . $short . '-T' . sprintf('%0' . self::DIGITS . 'd', $ticketid);
    }
}
