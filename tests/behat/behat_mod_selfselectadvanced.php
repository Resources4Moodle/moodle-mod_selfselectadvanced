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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat page resolvers for mod_selfselectadvanced.
 *
 * Enables steps like:
 *   When I am on the "Lab groups" "mod_selfselectadvanced > quotas" page
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_selfselectadvanced extends behat_base {
    /**
     * Resolve plugin page types to URLs.
     *
     * Recognised types (identifier = the activity name): quotas,
     * manage, guide, tickets and friends below.
     *
     * Four types address ONE team or ONE guide rather than the whole
     * activity, so their identifier is "Activity name > thing":
     *   group        - "Lab groups > Alpha", the team page
     *   review       - "Lab groups > Alpha", the guide's review page
     *   eoi members  - "Lab groups > Alpha", the interest drill-down
     *   guide load   - "Lab groups > guide1", one guide's workload
     * and one addresses a COURSE rather than an activity:
     *   course role assign - "C1", the course's Assign roles screen
     * Behat's page-resolver contract passes a single identifier, and
     * every one of these pages needs a second key in its URL; a
     * scenario that has to guess a database id instead is a scenario
     * that stops working the moment a fixture is reordered.
     *
     * @param string $type page type
     * @param string $identifier activity name, or "activity > team|guide"
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        $type = strtolower($type);
        if ($type === 'course role assign') {
            // Core resolves /admin/roles/assign.php for an ACTIVITY
            // context ("... selfselectadvanced activity roles" page) but
            // has no course-level equivalent, and the assignability of
            // the Group Coordinator role is exactly a course-versus-
            // activity question. The identifier is the course shortname.
            return new moodle_url('/admin/roles/assign.php', [
                'contextid' => \context_course::instance($this->course_id_by_shortname($identifier))->id,
            ]);
        }
        $subpages = [
            'group' => '/mod/selfselectadvanced/group.php',
            'review' => '/mod/selfselectadvanced/review.php',
            'eoi members' => '/mod/selfselectadvanced/eoilist.php',
        ];
        if (isset($subpages[$type]) || $type === 'guide load') {
            [$activityname, $reference] = $this->split_reference($type, $identifier);
            $cm = $this->get_cm_by_activity_name('selfselectadvanced', $activityname);
            if ($type === 'guide load') {
                return new moodle_url('/mod/selfselectadvanced/guideload.php', [
                    'id' => $cm->id,
                    'guide' => $this->user_id_by_username($reference),
                ]);
            }
            $groupid = $this->group_id_by_name((int) $cm->instance, $reference);
            // The drill-down on eoilist.php is addressed with viewgroup;
            // group.php and review.php both use g.
            $key = $type === 'eoi members' ? 'viewgroup' : 'g';

            return new moodle_url($subpages[$type], ['id' => $cm->id, $key => $groupid]);
        }

        $pages = [
            'quotas' => '/mod/selfselectadvanced/quotas.php',
            'manage' => '/mod/selfselectadvanced/manage.php',
            'guide' => '/mod/selfselectadvanced/guide.php',
            'moves' => '/mod/selfselectadvanced/moves.php',
            'stage move' => '/mod/selfselectadvanced/moveedit.php',
            'new team' => '/mod/selfselectadvanced/groupedit.php',
            'overrides' => '/mod/selfselectadvanced/overrides.php',
            'ledger' => '/mod/selfselectadvanced/ledger.php',
            'flagged' => '/mod/selfselectadvanced/flagged.php',
            'templates' => '/mod/selfselectadvanced/templates.php',
            'tickets' => '/mod/selfselectadvanced/tickets.php',
            'coordinator' => '/mod/selfselectadvanced/coordinator.php',
            'coordinators' => '/mod/selfselectadvanced/coordinators.php',
            'core sync' => '/mod/selfselectadvanced/coresync.php',
            'guide queue' => '/mod/selfselectadvanced/guidequeue.php',
            'my requests' => '/mod/selfselectadvanced/myrequests.php',
            'join' => '/mod/selfselectadvanced/joinrequest.php',
            'eoi list' => '/mod/selfselectadvanced/eoilist.php',
        ];
        if (!isset($pages[$type])) {
            throw new Exception('Unrecognised mod_selfselectadvanced page type "' . $type . '"');
        }
        $cm = $this->get_cm_by_activity_name('selfselectadvanced', $identifier);

        return new moodle_url($pages[$type], ['id' => $cm->id]);
    }

    /**
     * Split "Activity name > thing" into its two halves.
     *
     * @param string $type the page type, for the error message
     * @param string $identifier the raw identifier
     * @return string[] activity name, then the reference
     */
    protected function split_reference(string $type, string $identifier): array {
        $parts = array_map('trim', explode('>', $identifier, 2));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new Exception(
                'The "' . $type . '" page needs an identifier of the form "Activity name > reference", got "'
                    . $identifier . '"'
            );
        }

        return $parts;
    }

    /**
     * A plugin group id from its name inside one activity.
     *
     * @param int $instanceid the selfselectadvanced instance id
     * @param string $name the team name
     * @return int the group id
     */
    protected function group_id_by_name(int $instanceid, string $name): int {
        global $DB;

        $id = $DB->get_field('selfselectadvanced_group', 'id', [
            'activityid' => $instanceid,
            'name' => $name,
        ]);
        if (!$id) {
            throw new Exception('No mod_selfselectadvanced team named "' . $name . '" in that activity');
        }

        return (int) $id;
    }

    /**
     * A user id from a username.
     *
     * @param string $username the username
     * @return int the user id
     */
    protected function user_id_by_username(string $username): int {
        global $DB;

        return (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
    }

    /**
     * A course id from its shortname.
     *
     * @param string $shortname the course shortname
     * @return int the course id
     */
    protected function course_id_by_shortname(string $shortname): int {
        global $DB;

        return (int) $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Delete the account of a group's leader, producing a real vacancy.
     *
     * The vacancy this asserts is the OUTCOME of core deleting a user, not a
     * row poked into the database - writing leaderid = NULL directly would
     * test the template against a state the plugin might never actually
     * reach. delete_user() fires the events the observers listen to, so the
     * group arrives at the page the same way it would in production.
     *
     * @Given the leader of the :team group has been removed
     *
     * @param string $team plugin team name
     */
    public function the_leader_of_the_group_has_been_removed(string $team): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $group = $DB->get_record('selfselectadvanced_group', ['name' => $team], '*', MUST_EXIST);
        $leader = $DB->get_record('user', ['id' => (int) $group->leaderid], '*', MUST_EXIST);
        delete_user($leader);

        $after = $DB->get_field('selfselectadvanced_group', 'leaderid', ['id' => (int) $group->id]);
        if ($after !== null) {
            throw new ExpectationException(
                'deleting the leader did not vacate the leadership of "' . $team . '"',
                $this->getSession()
            );
        }
    }

    /**
     * Assert the approved team's Moodle course-group mirror and members.
     *
     * @Then the Moodle group mirror for :team in :activityname should contain :usernames
     *
     * @param string $team plugin team name
     * @param string $activityname activity name
     * @param string $usernames comma-separated usernames
     */
    public function the_moodle_group_mirror_should_contain(
        string $team,
        string $activityname,
        string $usernames
    ): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        $cm = $this->get_cm_by_activity_name('selfselectadvanced', $activityname);
        $group = $DB->get_record('selfselectadvanced_group', [
            'activityid' => $cm->instance,
            'name' => $team,
        ], '*', MUST_EXIST);
        if (empty($group->coregroupid) || !groups_group_exists((int) $group->coregroupid)) {
            throw new ExpectationException(
                'No live Moodle group mirror exists for "' . $team . '".',
                $this->getSession()
            );
        }

        $expected = array_values(array_filter(array_map('trim', explode(',', $usernames))));
        sort($expected);
        $actual = array_map(
            static fn($user): string => (string) $user->username,
            groups_get_members((int) $group->coregroupid, 'u.id, u.username')
        );
        sort($actual);
        if ($actual !== $expected) {
            throw new ExpectationException(
                'Moodle group mirror for "' . $team . '" contains '
                    . implode(', ', $actual) . '; expected ' . implode(', ', $expected) . '.',
                $this->getSession()
            );
        }
    }

    /**
     * A plugin page must refuse the user who is already logged in.
     *
     * A refusal is the ASSERTION in half of viewassignedteams.feature,
     * and a plain "I am on the ... page" step can never express one:
     * Behat appends its own exception sniffer after every step, that
     * sniffer treats Moodle's error page as a crashed step, and it does
     * so whatever the debug settings are (the page carries
     * data-rel="fatalerror"). So this step visits the page, asserts the
     * refusal ITSELF - by its exact text, so a DIFFERENT error still
     * fails - and then leaves the browser on the dashboard, which is
     * what the sniffer sees.
     *
     * @Then the :identifier :pagetype page refuses me
     *
     * @param string $identifier the page identifier, as for the resolver
     * @param string $pagetype the plugin page type, without the component prefix
     */
    public function the_page_refuses_me(string $identifier, string $pagetype): void {
        $this->assert_page_refuses($identifier, $pagetype, null);
    }

    /**
     * A plugin page must refuse the logged-in user AND show none of the
     * data the page would have carried.
     *
     * A refusal that still printed the roster would pass the step above.
     *
     * @Then the :identifier :pagetype page refuses me and discloses nothing of :needle
     *
     * @param string $identifier the page identifier, as for the resolver
     * @param string $pagetype the plugin page type, without the component prefix
     * @param string $needle text that must NOT appear on the refusal page
     */
    public function the_page_refuses_me_and_discloses_nothing(
        string $identifier,
        string $pagetype,
        string $needle
    ): void {
        $this->assert_page_refuses($identifier, $pagetype, $needle);
    }

    /**
     * Visit a page, assert it refuses, then move somewhere harmless.
     *
     * @param string $identifier the page identifier
     * @param string $pagetype the plugin page type
     * @param string|null $needle text that must not appear, or null
     */
    protected function assert_page_refuses(string $identifier, string $pagetype, ?string $needle): void {
        // Driven straight through the session rather than through
        // $this->execute(), which calls look_for_exceptions() itself
        // and would report the refusal this step exists to assert.
        $url = $this->resolve_page_instance_url($pagetype, $identifier);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        $text = $this->getSession()->getPage()->getText();
        // The refusal core renders for require_capability() and for
        // moodle_exception('nopermissions'), without the capability
        // name that follows it in brackets.
        $refusal = 'Sorry, but you do not currently have permissions to do that';
        if (!str_contains($text, $refusal)) {
            throw new ExpectationException(
                'The "' . $pagetype . '" page for "' . $identifier . '" did not refuse: it said "'
                    . substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 400) . '"',
                $this->getSession()
            );
        }
        if ($needle !== null && str_contains($text, $needle)) {
            throw new ExpectationException(
                'The refusal page still disclosed "' . $needle . '"',
                $this->getSession()
            );
        }

        // Leave the browser on a page the framework's own after-step
        // exception sniffer is happy with. Without this the scenario
        // fails on the very page it exists to assert.
        $this->execute('behat_general::i_visit', [new moodle_url('/my/')]);
    }
}
