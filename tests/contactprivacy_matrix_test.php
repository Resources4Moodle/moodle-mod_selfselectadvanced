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
use mod_selfselectadvanced\local\attributes\manager as attributes_manager;
use mod_selfselectadvanced\local\contactprivacy;
use mod_selfselectadvanced\local\coordinatorrole;
use mod_selfselectadvanced\local\groups;
use mod_selfselectadvanced\local\staffmessage;
use mod_selfselectadvanced\local\teamaccess;
use mod_selfselectadvanced\local\tickets;

/**
 * Contact privacy as values, not as the presence or absence of columns.
 *
 * The distinctive address and number below are seeded onto one participant and
 * then searched for in the actual renderable output or production payload handed
 * to each surface. A privacy regression is therefore caught even if the field
 * moves into an attribute, an unexpected table cell or a JSON key without
 * changing a column heading.
 *
 * Two root scripts cannot be executed safely from PHPUnit without turning a page
 * script into a callable production seam: flagged.php and tickets.php. For those
 * two, this file drives the exact production value producers and separately pins
 * the scripts to those producers. The build notes identify that boundary rather
 * than pretending that the root scripts themselves ran here.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\contactprivacy
 */
final class contactprivacy_matrix_test extends \advanced_testcase {
    /** @var string Address that cannot occur accidentally in plugin copy. */
    private const SUBJECT_EMAIL = 'ssa-matrix-subject-7f93@example.invalid';

    /** @var string Number that cannot occur accidentally in plugin copy. */
    private const SUBJECT_MOBILE = '919876540793';

    /** @var string Address used to prove the guide-search response does not echo its lookup key. */
    private const GUIDE_EMAIL = 'ssa-matrix-guide-2c61@example.invalid';

    /** @var string Guide number used as a second payload sentinel. */
    private const GUIDE_MOBILE = '919876542611';

    /** @var \stdClass Course fixture. */
    private \stdClass $course;

    /** @var activity[] Activity fixtures keyed by on/off. */
    private array $activities = [];

    /** @var \stdClass[] Participant/staff fixtures keyed by role in the matrix. */
    private array $users = [];

    /** @var \stdClass[] Subject groups keyed by on/off. */
    private array $groups = [];

    /**
     * Build both switch states and every viewer named by the work order.
     */
    private function build_world(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $this->course = $generator->create_course(['shortname' => 'CPMATRIX']);

        $oninstance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'maxmembership' => 2,
        ]);
        $offinstance = $generator->create_module('selfselectadvanced', [
            'course' => $this->course->id,
            'maxmembership' => 2,
            'contactprivacy' => 0,
        ]);
        $this->activities = [
            'on' => activity::from_instance((int) $oninstance->id),
            'off' => activity::from_instance((int) $offinstance->id),
        ];
        $this->assertTrue(contactprivacy::enabled($this->activities['on']), 'the default privacy state must be ON');
        $this->assertFalse(contactprivacy::enabled($this->activities['off']), 'the explicit legacy state must be OFF');

        $this->users['subject'] = $generator->create_user([
            'username' => 'ssamatrixsubject',
            'firstname' => 'Matrix',
            'lastname' => 'Subject',
            'email' => self::SUBJECT_EMAIL,
        ]);
        $this->users['teammate'] = $generator->create_user([
            'username' => 'ssamatrixmate',
            'firstname' => 'Matrix',
            'lastname' => 'Member',
        ]);
        $this->users['outsider'] = $generator->create_user([
            'username' => 'ssamatrixoutside',
            'firstname' => 'Matrix',
            'lastname' => 'Outsider',
        ]);
        foreach (['subject', 'teammate', 'outsider'] as $who) {
            $generator->enrol_user($this->users[$who]->id, $this->course->id, 'student');
        }

        $this->users['assignedguide'] = $generator->create_user([
            'username' => 'ssamatrixguide',
            'firstname' => 'Matrix',
            'lastname' => 'Guide',
            'email' => self::GUIDE_EMAIL,
        ]);
        $this->users['otherguide'] = $generator->create_user([
            'username' => 'ssamatrixotherguide',
            'firstname' => 'Other',
            'lastname' => 'Guide',
        ]);
        $this->users['claimedcoordinator'] = $generator->create_user([
            'username' => 'ssamatrixclaimed',
            'firstname' => 'Claimed',
            'lastname' => 'Coordinator',
        ]);
        $this->users['unclaimedcoordinator'] = $generator->create_user([
            'username' => 'ssamatrixunclaimed',
            'firstname' => 'Unclaimed',
            'lastname' => 'Coordinator',
        ]);
        foreach (['assignedguide', 'otherguide', 'claimedcoordinator', 'unclaimedcoordinator'] as $who) {
            $generator->enrol_user($this->users[$who]->id, $this->course->id, 'teacher');
        }

        $this->users['editingteacher'] = $generator->create_user([
            'username' => 'ssamatrixediting',
            'firstname' => 'Editing',
            'lastname' => 'Teacher',
        ]);
        $generator->enrol_user($this->users['editingteacher']->id, $this->course->id, 'editingteacher');

        $this->users['manager'] = $generator->create_user([
            'username' => 'ssamatrixmanager',
            'firstname' => 'Course',
            'lastname' => 'Manager',
        ]);
        $generator->enrol_user($this->users['manager']->id, $this->course->id, 'manager');

        $coordinatorrole = coordinatorrole::ensure();
        $this->assertGreaterThan(0, $coordinatorrole, 'the coordinator role is required by this matrix');
        foreach ($this->activities as $activity) {
            foreach (['claimedcoordinator', 'unclaimedcoordinator'] as $who) {
                role_assign($coordinatorrole, $this->users[$who]->id, $activity->context()->id);
            }
        }
        accesslib_clear_all_caches_for_unit_testing();

        foreach ($this->activities as $key => $activity) {
            $group = $plugingen->create_group([
                'activityid' => $activity->id(),
                'leaderid' => (int) $this->users['subject']->id,
                'name' => 'Matrix ' . strtoupper($key),
                'guideid' => (int) $this->users['assignedguide']->id,
            ]);
            $plugingen->create_member([
                'groupid' => $group->id,
                'userid' => (int) $this->users['teammate']->id,
                'status' => groups::STATUS_CONFIRMED,
            ]);
            $this->groups[$key] = groups::get($activity, (int) $group->id);

            // The queue page treats a claimed row as the connection. Direct fixture
            // insertion is deliberate: this test is about read-side disclosure, not
            // ticket lifecycle or notification delivery from filing the request.
            $DB->insert_record('selfselectadvanced_ticket', (object) [
                'activityid' => $activity->id(),
                'groupid' => (int) $group->id,
                'type' => tickets::TYPE_UNFREEZE,
                'status' => tickets::STATUS_CLAIMED,
                'requestedby' => (int) $this->users['subject']->id,
                'request' => 'Matrix privacy request',
                'requestformat' => FORMAT_PLAIN,
                'claimedby' => (int) $this->users['claimedcoordinator']->id,
                'timeclaimed' => time(),
                'resolutionformat' => FORMAT_PLAIN,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        attributes_manager::set(
            (int) $this->users['subject']->id,
            [
                'department' => 'Privacy',
                'subdepartment' => 'Matrix',
                'mobile' => self::SUBJECT_MOBILE,
            ],
            (int) get_admin()->id
        );
        attributes_manager::set_consent(
            (int) $this->users['subject']->id,
            true,
            (int) $this->users['subject']->id
        );
        attributes_manager::set(
            (int) $this->users['assignedguide']->id,
            ['mobile' => self::GUIDE_MOBILE],
            (int) get_admin()->id
        );
    }

    /**
     * Viewer keys in the work order, excluding the subject whose own details are seeded.
     *
     * @return string[]
     */
    private function viewer_keys(): array {
        return [
            'teammate',
            'outsider',
            'assignedguide',
            'otherguide',
            'claimedcoordinator',
            'unclaimedcoordinator',
            'editingteacher',
            'manager',
        ];
    }

    /**
     * The connection result promised by the current source for a consented number.
     *
     * OFF short-circuits the connection map to true. ON admits the three named
     * connections plus :manage. This is intentionally independent of the method
     * under test, so deleting one of its SQL arms changes the output but not the
     * expectation here.
     *
     * @param string $setting on|off
     * @param string $viewer viewer key
     * @return bool
     */
    private function expected_mobile_reach(string $setting, string $viewer): bool {
        if ($setting === 'off') {
            return true;
        }

        return in_array($viewer, [
            'teammate',
            'assignedguide',
            'claimedcoordinator',
            'editingteacher',
            'manager',
        ], true);
    }

    /**
     * Render the group template exactly as the page renderer does after its entry gate.
     *
     * @param activity $activity activity
     * @param \stdClass $group group
     * @param int $viewerid viewer
     * @return string template HTML
     */
    private function group_html(activity $activity, \stdClass $group, int $viewerid): string {
        // A FRESH page per render, not the shared global. Both helpers
        // below set a context, and setting it twice on one $PAGE - even
        // to the same value - is a coding problem Moodle reports through
        // debugging(), which the gate turns into a failure because it
        // runs PHPUnit with --fail-on-notice. A renderable does not need
        // the global page; it needs a renderer.
        $page = new \moodle_page();
        $page->set_context($activity->context());
        $page->set_url('/mod/selfselectadvanced/group.php', ['id' => $activity->cm()->id, 'g' => $group->id]);
        $renderer = $page->get_renderer('core');
        $data = (new \mod_selfselectadvanced\output\group_page(
            new api($activity),
            $group,
            $viewerid
        ))->export_for_template($renderer);

        return $renderer->render_from_template('mod_selfselectadvanced/group_page', $data);
    }

    /**
     * Render the guide-review template exactly as review.php does after its entry gate.
     *
     * @param activity $activity activity
     * @param \stdClass $group group
     * @param int $viewerid viewer
     * @return string template HTML
     */
    private function review_html(activity $activity, \stdClass $group, int $viewerid): string {
        $page = new \moodle_page();
        $page->set_context($activity->context());
        $page->set_url('/mod/selfselectadvanced/review.php', ['id' => $activity->cm()->id, 'g' => $group->id]);
        $renderer = $page->get_renderer('core');
        $data = (new \mod_selfselectadvanced\output\review_page(
            new api($activity),
            $group,
            $viewerid
        ))->export_for_template($renderer);

        return $renderer->render_from_template('mod_selfselectadvanced/review_page', $data);
    }

    /**
     * The value producer flagged.php uses before handing a row to its HTML table.
     *
     * The companion source assertion below binds the page to this producer. Keeping
     * the behaviour and the binding separate means a hard-coded true on the page and
     * a still-correct helper calculation cannot leave the check green.
     *
     * @param activity $activity activity
     * @param int $viewerid viewer
     * @return string rendered attribute line
     */
    private function flagged_attribute_line(activity $activity, int $viewerid): string {
        $subjectid = (int) $this->users['subject']->id;
        $records = attributes_manager::get_for_users([$subjectid]);
        $record = $records[$subjectid] ?? null;
        $map = contactprivacy::can_see_map($activity, $viewerid, [$subjectid]);
        $bypass = contactprivacy::mobile_consent_bypass(
            $activity,
            $viewerid,
            has_capability('mod/selfselectadvanced:viewparticipantidentity', $activity->context(), $viewerid)
        );
        $showmobile = !empty($map[$subjectid]) && attributes_manager::mobile_visible($record, $bypass);

        return attributes_manager::display_line($record, $showmobile);
    }

    /**
     * Contact values across the two renderable roster surfaces.
     */
    public function test_group_and_review_rendered_value_matrix(): void {
        $this->resetAfterTest();
        $this->build_world();

        foreach ($this->activities as $setting => $activity) {
            $group = $this->groups[$setting];
            foreach ($this->viewer_keys() as $viewer) {
                $userid = (int) $this->users[$viewer]->id;
                $this->setUser($this->users[$viewer]);
                $expectmobile = $this->expected_mobile_reach($setting, $viewer);

                if (teamaccess::may_open_team($activity, $group, $userid)) {
                    $html = $this->group_html($activity, $group, $userid);
                    $this->assertSame(
                        $expectmobile,
                        str_contains($html, self::SUBJECT_MOBILE),
                        $setting . ' / ' . $viewer . ' / group roster mobile'
                    );
                    $this->assertStringNotContainsString(
                        self::SUBJECT_EMAIL,
                        $html,
                        $setting . ' / ' . $viewer . ' / group roster address'
                    );
                } else {
                    $this->assertFalse(
                        teamaccess::may_open_team($activity, $group, $userid),
                        $setting . ' / ' . $viewer . ' / group page is not a reachable surface'
                    );
                }

                if (teamaccess::may_review_team($activity, $group, $userid)) {
                    $html = $this->review_html($activity, $group, $userid);
                    $this->assertSame(
                        $expectmobile,
                        str_contains($html, self::SUBJECT_MOBILE),
                        $setting . ' / ' . $viewer . ' / review roster mobile'
                    );
                    $this->assertStringNotContainsString(
                        self::SUBJECT_EMAIL,
                        $html,
                        $setting . ' / ' . $viewer . ' / review roster address'
                    );
                } else {
                    $this->assertFalse(
                        teamaccess::may_review_team($activity, $group, $userid),
                        $setting . ' / ' . $viewer . ' / review page is not a reachable surface'
                    );
                }
            }
        }
    }

    /**
     * Flagged-report and ticket-queue value producers across the viewer matrix.
     */
    public function test_flagged_and_ticket_value_matrix(): void {
        $this->resetAfterTest();
        $this->build_world();
        $subjectid = (int) $this->users['subject']->id;

        foreach ($this->activities as $setting => $activity) {
            foreach ($this->viewer_keys() as $viewer) {
                $userid = (int) $this->users[$viewer]->id;
                $this->setUser($this->users[$viewer]);

                if (has_capability('mod/selfselectadvanced:viewall', $activity->context(), $userid)) {
                    $line = $this->flagged_attribute_line($activity, $userid);
                    $this->assertSame(
                        $this->expected_mobile_reach($setting, $viewer),
                        str_contains($line, self::SUBJECT_MOBILE),
                        $setting . ' / ' . $viewer . ' / flagged screen mobile'
                    );
                    $this->assertStringNotContainsString(
                        self::SUBJECT_EMAIL,
                        $line,
                        $setting . ' / ' . $viewer . ' / flagged screen address'
                    );
                }

                if (
                    has_capability('mod/selfselectadvanced:coordinate', $activity->context(), $userid)
                    || has_capability('mod/selfselectadvanced:manage', $activity->context(), $userid)
                ) {
                    // The ticket queue passes only requesters of rows claimed by THIS viewer.
                    $claimedmine = $viewer === 'claimedcoordinator' ? [$subjectid] : [];
                    $payload = tickets::requester_contact_map($activity, $userid, $claimedmine);
                    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
                    $expectticketmobile = $setting === 'on' && $viewer === 'claimedcoordinator';
                    $this->assertSame(
                        $expectticketmobile,
                        str_contains($encoded, self::SUBJECT_MOBILE),
                        $setting . ' / ' . $viewer . ' / ticket queue mobile'
                    );
                    $this->assertStringNotContainsString(
                        self::SUBJECT_EMAIL,
                        $encoded,
                        $setting . ' / ' . $viewer . ' / ticket queue address'
                    );
                }
            }
        }

        $flagged = self::normalised_executable_source(__DIR__ . '/../flagged.php');
        $this->assertStringContainsString(
            'contactprivacy::can_see_map(',
            $flagged,
            'flagged.php must still obtain the per-subject connection map'
        );
        $this->assertStringContainsString(
            'attributes\\manager::mobile_visible(',
            $flagged,
            'flagged.php must still compose consent onto the connection verdict'
        );
        $this->assertStringContainsString(
            'attributes\\manager::display_line(',
            $flagged,
            'flagged.php must still render the value through the gated display producer'
        );
        $this->assertStringContainsString(
            'attributes\\manager::plain_line(',
            $flagged,
            'the flagged download path must remain explicit in the source inventory'
        );

        $ticketsource = self::normalised_executable_source(__DIR__ . '/../tickets.php');
        $this->assertStringContainsString(
            'tickets::requester_contact_map(',
            $ticketsource,
            'tickets.php must still source claimant contact from the gated production map'
        );
    }

    /**
     * Every current AJAX endpoint that returns user data is checked as a payload.
     */
    public function test_user_ajax_payloads_do_not_smuggle_contact_values(): void {
        $this->resetAfterTest();
        $this->build_world();

        foreach ($this->activities as $setting => $activity) {
            // The invitation candidate endpoint can return the participant and is
            // available to :manage even though the subject already belongs to the group.
            foreach (['editingteacher', 'manager'] as $viewer) {
                $this->setUser($this->users[$viewer]);
                $rows = \mod_selfselectadvanced\external\search_candidates::execute(
                    $activity->cm()->id,
                    (int) $this->groups[$setting]->id,
                    'Matrix Subject'
                );
                $row = $this->row_by_id($rows, (int) $this->users['subject']->id);
                $payload = json_encode($row, JSON_THROW_ON_ERROR);
                $expectemail = $setting === 'off'
                    && (has_capability('moodle/site:viewuseridentity', $activity->context())
                        || has_capability('moodle/course:viewhiddenuserfields', $activity->context()));
                $this->assertSame(
                    $expectemail,
                    str_contains($payload, self::SUBJECT_EMAIL),
                    $setting . ' / ' . $viewer . ' / search_candidates address'
                );
                $this->assertStringNotContainsString(
                    self::SUBJECT_MOBILE,
                    $payload,
                    $setting . ' / ' . $viewer . ' / search_candidates mobile'
                );
            }

            // The participant search is a staff move picker. It returns names and group
            // context only, so every authorised viewer gets the same contact-free shape.
            foreach (['claimedcoordinator', 'unclaimedcoordinator', 'editingteacher', 'manager'] as $viewer) {
                $this->setUser($this->users[$viewer]);
                if (
                    !has_any_capability([
                    'mod/selfselectadvanced:manage',
                    'mod/selfselectadvanced:managecomposition',
                    ], $activity->context())
                ) {
                    continue;
                }
                $rows = \mod_selfselectadvanced\external\search_participants::execute(
                    $activity->cm()->id,
                    'Matrix Subject'
                );
                $payload = json_encode($rows, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString(self::SUBJECT_EMAIL, $payload, $setting . ' / ' . $viewer);
                $this->assertStringNotContainsString(self::SUBJECT_MOBILE, $payload, $setting . ' / ' . $viewer);
            }

            // Guide lookup deliberately accepts a complete address as INPUT, but the
            // result must never echo the address or a phone number to any picker audience.
            foreach ($this->viewer_keys() as $viewer) {
                $this->setUser($this->users[$viewer]);
                $rows = \mod_selfselectadvanced\external\search_guides::execute(
                    $activity->cm()->id,
                    self::GUIDE_EMAIL,
                    false
                );
                $payload = json_encode($rows, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString(self::GUIDE_EMAIL, $payload, $setting . ' / ' . $viewer);
                $this->assertStringNotContainsString(self::GUIDE_MOBILE, $payload, $setting . ' / ' . $viewer);
            }
        }
    }

    /**
     * A plugin-generated Moodle message does not acquire either contact sentinel.
     */
    public function test_notification_body_contains_no_contact_value(): void {
        $this->resetAfterTest();
        $this->build_world();

        foreach ($this->activities as $setting => $activity) {
            $this->setUser($this->users['assignedguide']);
            $sink = $this->redirectMessages();
            staffmessage::send(
                $activity,
                (int) $this->users['assignedguide']->id,
                (int) $this->users['subject']->id,
                'Matrix privacy check',
                'Please review the group page.'
            );
            $messages = $sink->get_messages();
            $sink->close();
            $this->assertCount(1, $messages, $setting . ' / one Moodle message');
            $payload = implode(' ', [
                (string) ($messages[0]->subject ?? ''),
                (string) ($messages[0]->fullmessage ?? ''),
                (string) ($messages[0]->fullmessagehtml ?? ''),
                (string) ($messages[0]->smallmessage ?? ''),
            ]);
            $this->assertStringNotContainsString(self::SUBJECT_EMAIL, $payload, $setting . ' / recipient address');
            $this->assertStringNotContainsString(self::SUBJECT_MOBILE, $payload, $setting . ' / recipient mobile');
            $this->assertStringNotContainsString(self::GUIDE_EMAIL, $payload, $setting . ' / sender address');
            $this->assertStringNotContainsString(self::GUIDE_MOBILE, $payload, $setting . ' / sender mobile');
        }
    }

    /**
     * COMPLETE inventory of root download entry points and value checks for the
     * contact-bearing producers that can be driven without executing a page script.
     */
    public function test_download_surface_inventory_and_contact_value_producers(): void {
        $this->resetAfterTest();
        $this->build_world();

        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root);
        $found = [];
        foreach (glob($root . '/*.php') as $path) {
            $code = self::normalised_executable_source($path);
            if (preg_match('/optional_param\s*\(\s*[\"\']download[\"\']/', $code)) {
                $found[] = basename($path);
            }
        }
        sort($found);
        $expected = [
            'analytics.php',
            'attributes.php',
            'autogrouphistory.php',
            'coordinators.php',
            'eoilist.php',
            'flagged.php',
            'gridreport.php',
            'guide.php',
            'guidelist.php',
            'guideload.php',
            'guidequeue.php',
            'ledger.php',
            'manage.php',
            'roster.php',
        ];
        sort($expected);
        $this->assertSame(
            $expected,
            $found,
            'a root download surface was added or removed; classify its contact values in this matrix'
        );

        // Two download paths do not use a `download` parameter: the blank
        // participant-attribute template and the coordinator sample. Pin the
        // production PHP files that construct a CSV or Excel writer directly so
        // a third path cannot be added outside the inventory above without this
        // test asking what it contains. Their rows are fixed scaffolds, not
        // participant records.
        $directstreamers = [];
        foreach (self::production_php_files($root) as $path) {
            $source = self::normalised_executable_source($path);
            if (str_contains($source, 'csv_export_writer') || str_contains($source, 'MoodleExcelWorkbook')) {
                $directstreamers[] = self::relative_path($root, $path);
            }
        }
        sort($directstreamers);
        $this->assertSame([
            'attributes.php',
            'classes/local/coordinatorimport.php',
        ], $directstreamers, 'a direct CSV/Excel streamer was added or removed; classify its contact values in this matrix');

        $record = attributes_manager::get_for_users([(int) $this->users['subject']->id]);
        $record = $record[(int) $this->users['subject']->id] ?? null;
        $this->assertNotNull($record);
        $this->assertStringNotContainsString(
            self::SUBJECT_MOBILE,
            attributes_manager::plain_line($record, false),
            'the bulk-safe attribute line must not carry the mobile sentinel'
        );
        $this->assertStringContainsString(
            self::SUBJECT_MOBILE,
            attributes_manager::plain_line($record, true),
            'negative control: the sentinel is real and the formatter can carry it when explicitly authorised'
        );

        $flagged = self::normalised_executable_source($root . '/flagged.php');
        $this->assertMatchesRegularExpression(
            '/attributes\\\\manager::plain_line\([^;]+false\)/',
            $flagged,
            'the flagged bulk export must keep the literal false contact flag'
        );

        $attributes = self::normalised_executable_source($root . '/classes/table/attributes_table.php');
        $this->assertStringContainsString(
            "\$mobilefield = \$download ? '' : 'a.mobile, ';",
            $attributes,
            'the site-wide attribute download must not select the mobile column'
        );

        $roster = self::normalised_executable_source($root . '/classes/table/roster_table.php');
        $this->assertStringNotContainsString(
            'a.mobile',
            $roster,
            'the roster download query must not acquire a mobile value'
        );
        $this->assertStringNotContainsString(
            'u.email',
            $roster,
            'the roster download query must not acquire an address'
        );

        $eoi = self::normalised_executable_source($root . '/eoilist.php');
        $this->assertStringContainsString(
            "\$exportrow = [\$member->firstname, \$member->lastname];",
            $eoi,
            'the group-member EOI download must remain a names/composition export'
        );
        $this->assertStringNotContainsString(
            '$exportrow[] = $member->mobile',
            $eoi,
            'the mobile value shown on screen must never be copied into the EOI download row'
        );
    }

    /**
     * Find a result row by its id.
     *
     * @param array $rows external result rows
     * @param int $userid wanted id
     * @return array row
     */
    private function row_by_id(array $rows, int $userid): array {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $userid) {
                return $row;
            }
        }
        $this->fail('Expected user ' . $userid . ' in the external result');
    }

    /**
     * Production PHP files in the root and classes tree.
     *
     * @param string $root plugin root
     * @return string[] absolute paths
     */
    private static function production_php_files(string $root): array {
        $paths = glob($root . '/*.php') ?: [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/classes'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * Path relative to the plugin root.
     *
     * @param string $root plugin root
     * @param string $path absolute path
     * @return string relative path
     */
    private static function relative_path(string $root, string $path): string {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * PHP source with comments removed and whitespace collapsed.
     *
     * @param string $path PHP file
     * @return string executable source
     */
    private static function normalised_executable_source(string $path): string {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \coding_exception('Unreadable source file: ' . $path);
        }
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return preg_replace('/\s+/', ' ', $code);
    }
}
