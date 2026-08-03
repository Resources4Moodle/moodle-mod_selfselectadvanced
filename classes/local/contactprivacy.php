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
 *   address to ANYBODY, :manage included (maintainer decision 24).
 *   Staff reach a participant with the Send a message action instead
 *   ({@see staffmessage}), which travels as a Moodle message and shows
 *   nobody an address;
 * - a mobile number renders only to a viewer CONNECTED to its owner -
 *   a confirmed teammate, the guide assigned to their team, or the
 *   claimant of that person's claimed ticket - or to a :manage holder,
 *   and in every case only when the owner set their own sharing
 *   consent. That fourth audience is a PROMISE, not an oversight; see
 *   can_see_map(), which explains what it costs and what it does not
 *   buy.
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
     * IT NO LONGER REOPENS AN ADDRESS ANYWHERE (1.20.1 wave 3D
     * completed what decision 24 began). candidates::search(),
     * external\search_participants, eoilist.php and - since this wave -
     * coordinatorcandidates_table's username column and username filter
     * all stopped asking it. What it still governs is the PHONE, and
     * only in the two places named on can_see_map() and
     * mobile_consent_bypass(). Before adding a third, read the note on
     * can_see_map() about the promise the plugin makes to the number's
     * owner: this predicate is the code half of a sentence the student
     * is shown, and the two move together or not at all.
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
     * THE :manage ARM IS A PROMISE, AND IT STAYS UNTIL THE MAINTAINER
     * MOVES IT (1.20.1 wave 3D, P-1 examined and deliberately not
     * changed). The wave-3C audit read this arm as decision 24's last
     * survivor and asked for its removal, on the argument that the
     * address surfaces lost their :manage exemption and the phone
     * surfaces did not. Removing it was implemented, tested and then
     * BACKED OUT, because three things say it is the specification:
     *
     * - lang/en's shareconsentgranted, which is what the plugin SHOWS
     *   THE NUMBER'S OWNER when they switch sharing on: "shared with
     *   your confirmed teammates, the guide assigned to your team, a
     *   staff member handling a request you raised, AND THE TEACHERS
     *   WHO MANAGE THIS ACTIVITY. Nobody else in this activity sees
     *   it." Four audiences, and this arm is the fourth. Deleting it
     *   turns the one sentence the plugin addresses to the data subject
     *   about their own data into a falsehood, and lang/ is not this
     *   wave's to correct;
     * - tests/behat/attributes_admin.feature drives the real roster and
     *   asserts that an EDITING TEACHER reads a CONSENTED number and
     *   does not read an unconsented one, with a comment saying that
     *   pairing is the point;
     * - the cardinal rule names the audiences it protects a student
     *   from - manager, non-editing teacher, student - and the editing
     *   teacher, who owns the switch, is not among them.
     *
     * WHAT THE ARM DOES NOT BUY, and this wave narrowed all of it: no
     * address, anywhere, for anybody (decision 24); no username-shaped
     * address on the coordinator screen, its filter or its download; no
     * mobile column in the site-wide attribute DOWNLOAD; no mobile in
     * the flagged-students export. On-screen, paged, one activity at a
     * time is the whole of what an exempt viewer gets.
     *
     * THE OPEN QUESTION, recorded so it is not rediscovered as new:
     * :manage is granted to the MANAGER archetype as well as the
     * editing teacher (db/access.php), and "manager" IS an audience the
     * cardinal rule names. The two cannot be told apart by the
     * capability this predicate asks. Separating them needs a
     * capability or archetype decision in db/access.php and a rewrite
     * of shareconsentgranted - both outside this wave - so it is
     * reported rather than guessed at.
     *
     * The three CONNECTIONS below are the other half of the design and
     * are not an exception to the rule but the point of it: a confirmed
     * teammate, a team's ASSIGNED guide and the claimant of a person's
     * ticket read a consented number, and no capability is involved.
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
     * CONSENT, NOT REACH. Every surface that asks this AND-s it onto
     * can_see_map(), so it answers "may I overrule what this person
     * chose about a number I am already allowed to reach", never "may I
     * reach it". The student is told as much in lang/en's
     * shareconsentwithheld: with sharing off, only a site administrator
     * or staff the site has deliberately granted
     * :viewparticipantidentity still reads the number.
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
