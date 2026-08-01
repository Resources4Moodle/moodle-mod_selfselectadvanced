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

use mod_selfselectadvanced\activity;

/**
 * The single authority on who may see a participant's contact details
 * inside one activity (contact-privacy cardinal rule; maintainer
 * decisions 17 and 18, 2026-08-01).
 *
 * The per-activity `contactprivacy` setting defaults ON, for new and
 * for every existing instance, and is switched by a
 * mod/selfselectadvanced:manage holder - the editing teacher or an
 * administrator. While it is ON:
 *
 * - no surface of this plugin renders, links or exports an email
 *   address to anybody below :manage. Staff reach a participant with
 *   the Send a message action instead ({@see staffmessage}), which
 *   travels as a Moodle message and shows nobody an address;
 * - a mobile number renders only to a viewer CONNECTED to its owner -
 *   a confirmed teammate, the guide assigned to their team, or the
 *   claimant of that person's claimed ticket - AND only when the owner
 *   set their own sharing consent.
 *
 * GOOD-NEIGHBOUR PRINCIPLE. This class can only ever NARROW what a
 * viewer sees. It adds no core setting, overrides no core capability
 * and restores nothing a site withdrew: every consumer that also
 * consults a core identity capability AND-composes the two, never ORs
 * them. mod/selfselectadvanced:viewparticipantidentity is a permission
 * to see FIELDS, never a permission to bypass the connection map, and
 * it is deliberately NOT part of is_unrestricted().
 *
 * Read-time display gating only: nothing here writes, locks, opens a
 * transaction or fires an event. Never call it from inside
 * locks::acquire() or an open transaction.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contactprivacy {
    /** @var int Subjects resolved per query round trip. */
    private const CHUNK = 1000;

    /**
     * Whether this activity protects participant contact details.
     *
     * @param activity $activity the activity
     * @return bool whether the per-activity switch is on
     */
    public static function enabled(activity $activity): bool {
        return !empty($activity->settings()->contactprivacy);
    }

    /**
     * Whether this viewer is exempt from the switch altogether.
     *
     * Keyed on mod/selfselectadvanced:manage and on nothing else:
     * :addinstance would exempt managers and :viewall would exempt
     * every non-editing teacher (db/access.php grants it the teacher
     * archetype), and both are exactly the audience the cardinal rule
     * restricts. Administrators pass through doanything.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer
     * @return bool whether the viewer holds the manage capability
     */
    public static function is_unrestricted(activity $activity, int $viewerid): bool {
        return has_capability('mod/selfselectadvanced:manage', $activity->context(), $viewerid);
    }

    /**
     * Bulk connection map: subjectid => bool for EVERY requested
     * subject, all true when the switch is off or the viewer is
     * unrestricted.
     *
     * One call per page or export, never one per row: at ten thousand
     * students a per-subject check is ten thousand queries. Three
     * queries per thousand subjects plus one capability check,
     * whatever the subject count.
     *
     * Never call inside locks::acquire() or an open transaction.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer
     * @param int[] $subjectids the people whose details are wanted
     * @return bool[] subjectid => whether the viewer may see their contact details
     */
    public static function can_see_map(activity $activity, int $viewerid, array $subjectids): array {
        global $DB;

        $subjectids = array_values(array_unique(array_map('intval', $subjectids)));
        if (!$subjectids) {
            return [];
        }
        if (!self::enabled($activity) || self::is_unrestricted($activity, $viewerid)) {
            return array_fill_keys($subjectids, true);
        }

        $map = array_fill_keys($subjectids, false);
        if (isset($map[$viewerid])) {
            // Always your own details.
            $map[$viewerid] = true;
        }

        foreach (array_chunk($subjectids, self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cp');

            // Rule (a): confirmed teammates. CONFIRMED on both sides: an
            // invited, declined, expired or removed row is not a
            // connection.
            $rows = $DB->get_fieldset_sql(
                "SELECT DISTINCT m2.userid
                   FROM {selfselectadvanced_member} m1
                   JOIN {selfselectadvanced_member} m2 ON m2.groupid = m1.groupid
                   JOIN {selfselectadvanced_group} g ON g.id = m1.groupid
                  WHERE g.activityid = :aid AND m1.userid = :viewer
                    AND m1.status = :st1 AND m2.status = :st2 AND m2.userid $insql",
                [
                    'aid' => $activity->id(),
                    'viewer' => $viewerid,
                    'st1' => groups::STATUS_CONFIRMED,
                    'st2' => groups::STATUS_CONFIRMED,
                ] + $inparams
            );
            foreach ($rows as $userid) {
                $map[(int) $userid] = true;
            }

            // Rule (b): confirmed members of teams this viewer is the ASSIGNED
            // guide of. Assignment, not an expression of interest of any
            // status - that conflation is what let a guide with a
            // rejected interest read a team's contact details.
            $rows = $DB->get_fieldset_sql(
                "SELECT DISTINCT m.userid
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                  WHERE g.activityid = :aid AND g.guideid = :viewer
                    AND m.status = :st AND m.userid $insql",
                [
                    'aid' => $activity->id(),
                    'viewer' => $viewerid,
                    'st' => groups::STATUS_CONFIRMED,
                ] + $inparams
            );
            foreach ($rows as $userid) {
                $map[(int) $userid] = true;
            }

            // Rule (c): requesters of tickets this viewer currently holds
            // CLAIMED. An open ticket in the queue is not a connection,
            // and neither is being eligible to decide one: claiming is
            // the act that creates the link. Read after commit - the
            // same claimedby column tickets::claim() writes under lock -
            // so a released claim drops the row by itself.
            $rows = $DB->get_fieldset_sql(
                "SELECT DISTINCT t.requestedby
                   FROM {selfselectadvanced_ticket} t
                  WHERE t.activityid = :aid AND t.claimedby = :viewer
                    AND t.status = :st AND t.requestedby $insql",
                [
                    'aid' => $activity->id(),
                    'viewer' => $viewerid,
                    'st' => tickets::STATUS_CLAIMED,
                ] + $inparams
            );
            foreach ($rows as $userid) {
                $map[(int) $userid] = true;
            }
        }

        return $map;
    }

    /**
     * One subject's verdict, for the rare single-row caller.
     *
     * Never use this in a loop: can_see_map() exists so a page asks
     * once.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer
     * @param int $subjectid the person whose details are wanted
     * @return bool whether the viewer may see their contact details
     */
    public static function can_see(activity $activity, int $viewerid, int $subjectid): bool {
        return self::can_see_map($activity, $viewerid, [$subjectid])[$subjectid] ?? false;
    }

    /**
     * Whether a viewer may bypass the OWNER'S OWN mobile consent flag.
     *
     * RENAMED from the old viewall-shaped test on 2026-08-01 and the
     * rename is the fix: nothing may pass
     * mod/selfselectadvanced:viewall in here any more. The second
     * argument is the IDENTITY capability
     * (:viewparticipantidentity), never the breadth capability, and
     * even that only survives the AND when the switch is off or the
     * viewer holds :manage. Switch ON => only :manage bypasses
     * consent; a non-editing teacher or coordinator holding :viewall
     * does not.
     *
     * @param activity $activity the activity
     * @param int $viewerid the viewer
     * @param bool $hasidentitycap viewer holds :viewparticipantidentity
     * @return bool whether shareconsent may be bypassed
     */
    public static function mobile_consent_bypass(activity $activity, int $viewerid, bool $hasidentitycap): bool {
        return $hasidentitycap && (!self::enabled($activity) || self::is_unrestricted($activity, $viewerid));
    }

    /**
     * Rule (b) ALONE - the subjects this viewer guides - evaluated
     * regardless of the switch.
     *
     * can_see_map() short-circuits to all-true when the switch is off,
     * which is right for a DISPLAY gate and wrong for an ACTION gate:
     * "may I message this person" must not become "everyone may
     * message everyone" the moment an editing teacher turns protection
     * off. So the connection is available on its own here.
     *
     * @param activity $activity the activity
     * @param int $guideid the viewer
     * @param int[] $subjectids subjects to test
     * @return int[] the subset of $subjectids this viewer guides
     */
    public static function guided_subjects(activity $activity, int $guideid, array $subjectids): array {
        global $DB;

        $subjectids = array_values(array_unique(array_map('intval', $subjectids)));
        if (!$subjectids || $guideid <= 0) {
            return [];
        }

        $guided = [];
        foreach (array_chunk($subjectids, self::CHUNK) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'gs');
            $rows = $DB->get_fieldset_sql(
                "SELECT DISTINCT m.userid
                   FROM {selfselectadvanced_member} m
                   JOIN {selfselectadvanced_group} g ON g.id = m.groupid
                  WHERE g.activityid = :aid AND g.guideid = :guide
                    AND m.status = :st AND m.userid $insql",
                [
                    'aid' => $activity->id(),
                    'guide' => $guideid,
                    'st' => groups::STATUS_CONFIRMED,
                ] + $inparams
            );
            foreach ($rows as $userid) {
                $guided[] = (int) $userid;
            }
        }

        return $guided;
    }
}
