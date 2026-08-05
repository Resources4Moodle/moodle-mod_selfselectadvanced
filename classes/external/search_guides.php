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

namespace mod_selfselectadvanced\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_selfselectadvanced\activity;

/**
 * AJAX provider for every guide picker in the plugin (strategy 1.18 B).
 *
 * A dropdown listing 1500 guides is not a control, and there is one per
 * row on the assignment queue. Each picker now ships with no options at
 * all and calls this, which matches the typed text against guide names
 * BEFORE any per-guide override work is done and returns at most
 * self::LIMIT of them.
 *
 * Who sees what is decided by capability rather than by a parameter the
 * caller could choose for themselves. Staff assigning work, and a guide
 * nominating a successor, see how much each guide is carrying. A team
 * choosing for itself sees name and department - and the load too,
 * unless the activity is in students-approach mode, where the load is
 * exactly what must not be advertised (strategy 1.16 A).
 *
 * No address is RETURNED at any point, for anybody: the 1.17 rule for
 * approaches holds here too. Since maintainer decision 32 the typed
 * text can be MATCHED against a guide's address as well as their name -
 * and since decision 52 superseded decision 41 on 2026-08-04 only when that
 * text is a complete email address, compared by case-insensitive equality. Matching
 * and returning are two different questions; see the matrix note below.
 *
 * FIELD-VISIBILITY MATRIX (contact-privacy audit, 2026-08-01; amended
 * for decisions 32, 41 and 52, 2026-08-03/04): this endpoint admits any holder of
 * mod/selfselectadvanced:respond - which is every student - and
 * discloses to them a GUIDE's name, department and (outside
 * students-approach mode) current load. It returns no student data and
 * no contact data of any kind, so it is not a cardinal-rule surface and
 * needs no gate from the contact-privacy work.
 *
 * What decision 32 changed is the MATCH and not the RETURN. A typed
 * query that is a complete email address is tested against the guide's
 * email address as well as their name
 * ({@see \mod_selfselectadvanced\local\guides::with_load()}), because at
 * VIT a student approaches a faculty member in person and comes away
 * with an address or an employee id, and the id is already the surname.
 * A query that is not a complete email address matches names only. Nothing
 * here learns the address: label() below composes name, department,
 * sub-department and load, execute_returns() declares those five keys and no
 * more, and the row guides::with_load() hands over carries no address field
 * at all.
 *
 * WHY EQUALITY IS REQUIRED (decision 52 superseding decision 41, 2026-08-04). A blind audit
 * measured this endpoint with substring matching: a plain enrolled student
 * holding only :respond reconstructed a whole guide address in 453 calls to
 * execute(), extending a matched substring one character at a time.
 * Substring matching leaks the string it matches, so local-part-only,
 * domain-only, prefix and suffix probes must not engage the address arm.
 *
 * THE OPPOSITE RULE STILL HOLDS ON THE OPPOSITE POOL, and the two files
 * do not disagree by accident.
 * {@see \mod_selfselectadvanced\local\candidates} and
 * {@see \mod_selfselectadvanced\external\search_participants} match
 * NAMES ONLY, for every viewer, in both states of the contact-privacy
 * switch: an address probe against a pool of STUDENTS is an oracle over
 * protected people. This pool is the holders of
 * mod/selfselectadvanced:guide in this module context - staff, being
 * approached. "Guides are not a protected class" is the maintainer's
 * ruling, not this class's opinion.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_guides extends external_api {
    /** @var int Most rows a single search returns. */
    private const LIMIT = 50;

    /** @var int Longest query accepted from the searchable picker. */
    private const QUERY_LIMIT = 128;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'query' => new external_value(PARAM_RAW_TRIMMED, 'Search text'),
            'withroom' => new external_value(
                PARAM_BOOL,
                'Only guides with capacity left',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Search the activity's guides by name, or by exact email address when the
     * query is a complete address.
     *
     * @param int $cmid course module id
     * @param string $query search text
     * @param bool $withroom drop guides who are already full
     * @return array[] matching guides
     */
    public static function execute(int $cmid, string $query, bool $withroom = true): array {
        [
            'cmid' => $cmid,
            'query' => $query,
            'withroom' => $withroom,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'query' => $query,
            'withroom' => $withroom,
        ]);

        $activity = activity::from_cmid($cmid);
        $context = $activity->context();
        self::validate_context($context);

        // Four audiences may look a guide up, and each is identified by
        // a capability it already holds rather than by a parameter the
        // caller could choose: a student submitting to or approaching
        // one, a guide nominating a successor, and the staff assigning
        // work. Somebody with none of these has no picker to fill.
        //
        // Nothing returned here is private - name, department and load
        // are what every guide list in the plugin already shows, and no
        // address is RETURNED at any point (the 1.17 rule for approaches
        // holds here too). Decision 32 lets a complete email query be matched
        // against a guide's address by equality (decision 52 superseded
        // decision 41's substring rule); neither added an address to any answer.
        // :assignguide joined the list in 1.20.0. It is the capability
        // that reaches manage.php's assign/reassign tabs, and the
        // control there is guidepicker::render() - a select that starts
        // EMPTY and is filled entirely by this endpoint. Without this
        // name a holder of the new narrow capability opens the page it
        // was created for and finds a picker that answers nothing,
        // which would leave "a holder of :assignguide can assign a
        // team's guide" true only for somebody who also holds
        // :coordinate or :manage.
        $allowed = false;
        foreach (['respond', 'creategroup', 'guide', 'manage', 'coordinate', 'assignguide'] as $capability) {
            if (has_capability('mod/selfselectadvanced:' . $capability, $context)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \required_capability_exception($context, 'mod/selfselectadvanced:respond', 'nopermissions', '');
        }

        $query = trim($query);
        if (\core_text::strlen($query) > self::QUERY_LIMIT) {
            return [];
        }
        if ($query === '') {
            return [];
        }

        $api = new \mod_selfselectadvanced\local\api($activity);

        // Who may be OFFERED a guide who is full or has not volunteered
        // is a question of authority, not a parameter the caller could
        // choose for itself - the rule this whole class is built on (see
        // the docblock). The only picker that needs them is the override
        // target picker, and the page carrying it is gated on exactly
        // this capability (overrides.php). A student's picker cannot
        // reach an unavailable guide by asking, however it sets
        // withroom. It is also a no-op for the assign queue even for an
        // :override holder, because that picker sends withroom = true
        // and the search then drops everyone with no room left anyway.
        $includeunavailable = has_capability('mod/selfselectadvanced:override', $context);

        $matches = \mod_selfselectadvanced\local\guides::search(
            $activity,
            $api->gatekeeper()->resolver(),
            $query,
            self::LIMIT,
            $withroom,
            $includeunavailable
        );

        // Students-approach mode hides how much each guide is carrying,
        // because "Guiding 2 of 3" IS advertised availability and that
        // mode exists to stop teams shopping by it (strategy 1.16 A).
        // The rule belongs here, at the one place every picker is fed
        // from: staff assigning work still need the figure, and a guide
        // nominating a successor still needs it, but the teams choosing
        // do not see it.
        $showload = empty($activity->settings()->studentapproach);
        foreach (['manage', 'coordinate', 'guide', 'assignguide'] as $staffcapability) {
            if (has_capability('mod/selfselectadvanced:' . $staffcapability, $context)) {
                $showload = true;
                break;
            }
        }

        $results = [];
        foreach ($matches as $guide) {
            $results[] = [
                'id' => (int) $guide->id,
                'fullname' => $guide->fullname,
                'department' => $guide->department,
                'subdepartment' => $guide->subdepartment,
                'label' => self::label($guide, $showload),
            ];
        }

        return $results;
    }

    /**
     * The one-line label a picker shows for a guide.
     *
     * @param \stdClass $guide a row from guides::search()
     * @param bool $showload whether this viewer may see how much the guide is carrying
     * @return string name, department and - for those entitled to it - load
     */
    private static function label(\stdClass $guide, bool $showload): string {
        $parts = array_filter([$guide->department, $guide->subdepartment]);
        if ($showload) {
            $parts[] = $guide->label;
        }
        if (!$parts) {
            return $guide->fullname;
        }

        return get_string('guidepickerlabel', 'mod_selfselectadvanced', (object) [
            'fullname' => $guide->fullname,
            'label' => implode(' / ', $parts),
        ]);
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Guide user id'),
                'fullname' => new external_value(PARAM_TEXT, 'Guide name'),
                'department' => new external_value(PARAM_TEXT, 'Department, or empty when not recorded'),
                'subdepartment' => new external_value(PARAM_TEXT, 'Sub-department, or empty when not recorded'),
                'label' => new external_value(PARAM_TEXT, 'Display label with department and load'),
            ])
        );
    }
}
