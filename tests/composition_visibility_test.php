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
use mod_selfselectadvanced\local\groups;

/**
 * Team composition is visible to people deciding about that team.
 *
 * Department and sub-department are composition data. They are not
 * contact data, so a pending invitee sees them before answering; mobile
 * remains limited to the existing contact audience.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\output\group_page
 */
final class composition_visibility_test extends \advanced_testcase {
    /**
     * Build a team whose activity has only a department quota rule.
     *
     * @return array<string, mixed> Fixture objects.
     */
    private function fixture(): array {
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', [
            'course' => $course->id,
            'minsize' => 1,
            'maxsize' => 6,
            'maxlead' => 1,
            'maxmembership' => 2,
        ]);
        $activity = activity::from_instance((int) $instance->id);
        $plugingen->create_quota([
            'activityid' => $activity->id(),
            'dimension' => 'department',
            'value' => 'Civil',
            'mincount' => 1,
        ]);

        $users = [];
        foreach (['leader', 'member', 'invitee', 'declined', 'outsider', 'noattr'] as $handle) {
            $users[$handle] = $generator->create_user([
                'firstname' => ucfirst($handle),
                'lastname' => $handle,
            ]);
            $generator->enrol_user($users[$handle]->id, $course->id, 'student');
        }

        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $users['leader']->id,
            'name' => 'Composition Team',
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users['member']->id,
            'status' => groups::STATUS_CONFIRMED,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users['invitee']->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time() - 60,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users['declined']->id,
            'status' => groups::STATUS_DECLINED,
            'timeresponded' => time() - 30,
        ]);
        $plugingen->create_member([
            'groupid' => $group->id,
            'userid' => (int) $users['noattr']->id,
            'status' => groups::STATUS_INVITED,
            'timeinvited' => time() - 10,
        ]);

        $plugingen->create_userattr([
            'userid' => (int) $users['leader']->id,
            'department' => 'Civil',
            'subdepartment' => 'Structures',
            'mobile' => '919111111111',
            'shareconsent' => 1,
        ]);
        $plugingen->create_userattr([
            'userid' => (int) $users['member']->id,
            'department' => 'Mechanical',
            'subdepartment' => 'Design',
            'mobile' => '919222222222',
            'shareconsent' => 1,
        ]);
        $plugingen->create_userattr([
            'userid' => (int) $users['invitee']->id,
            'department' => 'Electrical',
            'subdepartment' => 'Signals',
        ]);

        return [
            'activity' => $activity,
            'api' => new api($activity),
            'group' => groups::get($activity, (int) $group->id),
            'users' => $users,
        ];
    }

    /**
     * Export the group page for one viewer.
     *
     * @param activity $activity the activity
     * @param api $api the facade
     * @param \stdClass $group the group row
     * @param int $userid the viewing user
     * @return \stdClass
     */
    private function grouppage(activity $activity, api $api, \stdClass $group, int $userid): \stdClass {
        global $PAGE;

        $PAGE->set_url('/mod/selfselectadvanced/group.php', [
            'id' => $activity->cm()->id,
            'g' => (int) $group->id,
        ]);

        return (new \mod_selfselectadvanced\output\group_page($api, $group, $userid))
            ->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Roster column labels exported for the table header.
     *
     * @param \stdClass $page the exported page
     * @return string[]
     */
    private function roster_head_labels(\stdClass $page): array {
        return array_map(static fn($head) => (string) $head['label'], $page->rosterhead);
    }

    /**
     * Return a roster row by lastname.
     *
     * @param array $rows exported roster rows
     * @param string $lastname the lastname to find
     * @return \stdClass
     */
    private function roster_row(array $rows, string $lastname): \stdClass {
        foreach ($rows as $row) {
            if ((string) $row->lastname === $lastname) {
                return $row;
            }
        }
        $this->fail('Missing roster row for ' . $lastname);
    }

    /**
     * The text values carried by one roster row's data columns.
     *
     * @param \stdClass $row exported roster row
     * @return string[]
     */
    private function dim_values(\stdClass $row): array {
        return array_map(static fn($cell) => (string) $cell['value'], $row->dims);
    }

    /**
     * MUTATION CAUGHT (run): making composition use the mobile predicate hid
     * these columns from an invitee.
     *
     * MUTATION CAUGHT (run): widening the mobile predicate to invitees
     * exposed the mobile column.
     */
    public function test_pending_invitee_sees_composition_but_not_mobile_or_caution(): void {
        $this->resetAfterTest();
        $fixture = $this->fixture();

        $page = $this->grouppage(
            $fixture['activity'],
            $fixture['api'],
            $fixture['group'],
            (int) $fixture['users']['invitee']->id
        );

        $this->assertSame([
            get_string('firstname'),
            get_string('lastname'),
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
        ], $this->roster_head_labels($page));
        $this->assertSame(['Civil', 'Structures'], $this->dim_values($this->roster_row($page->roster, 'leader')));
        $this->assertSame(['Mechanical', 'Design'], $this->dim_values($this->roster_row($page->roster, 'member')));
        $this->assertFalse($page->showmobilecaution);
        $this->assertStringNotContainsString('919111111111', json_encode($page->roster));
        $this->assertStringNotContainsString('919222222222', json_encode($page->roster));
    }

    /**
     * MUTATION CAUGHT (run): treating any historical invitation as a
     * decision point gave declined invitees the composition columns.
     */
    public function test_declined_invitee_does_not_gain_composition_view(): void {
        $this->resetAfterTest();
        $fixture = $this->fixture();

        $page = $this->grouppage(
            $fixture['activity'],
            $fixture['api'],
            $fixture['group'],
            (int) $fixture['users']['declined']->id
        );

        $this->assertSame([get_string('firstname'), get_string('lastname')], $this->roster_head_labels($page));
        $this->assertSame([], $this->dim_values($this->roster_row($page->roster, 'leader')));
        $this->assertFalse($page->showmobilecaution);
    }

    /**
     * MUTATION CAUGHT (run): dropping the original mobile predicate
     * removed the contact column from confirmed team members.
     */
    public function test_confirmed_member_still_sees_dimensions_and_mobile(): void {
        $this->resetAfterTest();
        $fixture = $this->fixture();

        $page = $this->grouppage(
            $fixture['activity'],
            $fixture['api'],
            $fixture['group'],
            (int) $fixture['users']['member']->id
        );

        $this->assertSame([
            get_string('firstname'),
            get_string('lastname'),
            get_string('attrdepartment', 'mod_selfselectadvanced'),
            get_string('attrsubdepartment', 'mod_selfselectadvanced'),
            get_string('attrmobile', 'mod_selfselectadvanced'),
        ], $this->roster_head_labels($page));
        $this->assertSame(
            ['Civil', 'Structures', '919111111111'],
            $this->dim_values($this->roster_row($page->roster, 'leader'))
        );
        $this->assertTrue($page->showmobilecaution);
    }

    /**
     * MUTATION CAUGHT (run): treating every enrolled student as a
     * composition viewer exposed dimensions to outsiders.
     */
    public function test_outsider_student_still_sees_neither(): void {
        $this->resetAfterTest();
        $fixture = $this->fixture();

        $page = $this->grouppage(
            $fixture['activity'],
            $fixture['api'],
            $fixture['group'],
            (int) $fixture['users']['outsider']->id
        );

        $this->assertSame([get_string('firstname'), get_string('lastname')], $this->roster_head_labels($page));
        $this->assertSame([], $this->dim_values($this->roster_row($page->roster, 'leader')));
        $this->assertFalse($page->showmobilecaution);
    }

    /**
     * MUTATION CAUGHT (run): leaving pending invitations as names only
     * meant the leader could not see invitee composition, and users
     * without an attribute record rendered no sensible fallback.
     */
    public function test_pending_invitation_rows_carry_dimensions_and_missing_attributes(): void {
        global $PAGE;

        $this->resetAfterTest();
        $fixture = $this->fixture();

        $page = $this->grouppage(
            $fixture['activity'],
            $fixture['api'],
            $fixture['group'],
            (int) $fixture['users']['leader']->id
        );

        $byname = [];
        foreach ($page->pendinginvites as $invite) {
            $byname[(string) $invite->fullname] = $invite;
        }

        $invitee = $byname[fullname($fixture['users']['invitee'])];
        $this->assertSame('Electrical', $invitee->department);
        $this->assertSame('Signals', $invitee->subdepartment);
        $this->assertTrue($invitee->hasdepartment);
        $this->assertTrue($invitee->hassubdepartment);
        $this->assertFalse($invitee->noattributes);

        $noattr = $byname[fullname($fixture['users']['noattr'])];
        $this->assertSame('', $noattr->department);
        $this->assertSame('', $noattr->subdepartment);
        $this->assertFalse($noattr->hasdepartment);
        $this->assertFalse($noattr->hassubdepartment);
        $this->assertTrue($noattr->noattributes);

        $html = $PAGE->get_renderer('core')->render_from_template('mod_selfselectadvanced/group_page', $page);
        $this->assertStringContainsString(get_string('attrsmissing', 'mod_selfselectadvanced'), $html);
    }
}
