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

use backup;
use backup_controller;
use core_privacy\local\request\approved_contextlist;
use mod_selfselectadvanced\local\kb;
use mod_selfselectadvanced\local\state;
use mod_selfselectadvanced\local\tickets;
use mod_selfselectadvanced\privacy\provider;
use restore_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * The knowledgebank's two unproven contracts, closed in 1.20.60 (audit
 * L-25 and L-26).
 *
 * L-25, BACKUP AND RESTORE. The kb travels in the backup (1.20.45) and
 * its restore step does three separate remappings - activityid from the
 * parent, usercreated/usermodified through the 'user' mapping, and
 * sourceticketid through 'ssaticket', degrading to 0 when the source
 * ticket was not restored. Nothing exercised any of it. A remapping that
 * silently fails leaves an article pointing at a ticket in a DIFFERENT
 * course, or authored by a user id that means somebody else on the
 * destination site - and a backup defect is invisible until the day
 * somebody actually restores.
 *
 * L-26, PRIVACY. The provider declares kb.usercreated and
 * kb.usermodified and nothing tested either direction: that the author
 * finds the article in their own export, and that erasing them de-links
 * their id WITHOUT deleting an article the course still needs. The two
 * halves pull against each other, which is exactly why both are here.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\privacy\provider
 * @covers     \restore_selfselectadvanced_activity_structure_step
 */
final class kb_backup_privacy_test extends \advanced_testcase {
    /**
     * A course, an activity, a requester with a firm group, and a staff
     * member holding queue authority.
     *
     * @return array [activity, course, leader, staff, group]
     */
    private function scene(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->create_module('selfselectadvanced', ['course' => $course->id]);
        $activity = activity::from_instance((int) $instance->id);

        $leader = $generator->create_user(['firstname' => 'Rina', 'lastname' => 'Requester']);
        $generator->enrol_user($leader->id, $course->id, 'student');
        $staff = $generator->create_user(['firstname' => 'Cora', 'lastname' => 'Coordinator']);
        $generator->enrol_user($staff->id, $course->id, 'editingteacher');

        $plugingen = $generator->get_plugin_generator('mod_selfselectadvanced');
        $group = $plugingen->create_group([
            'activityid' => $activity->id(),
            'leaderid' => (int) $leader->id,
            'name' => 'Team Blue',
            'state' => state::FIRM,
        ]);

        return [$activity, $course, $leader, $staff, $group];
    }

    /**
     * A RESOLVED ticket, so an article can be published FROM it.
     *
     * @param activity $activity the activity
     * @param \stdClass $group the group
     * @param \stdClass $leader the requester
     * @param \stdClass $staff the resolver
     * @return \stdClass the resolved ticket
     */
    private function resolved_ticket(activity $activity, \stdClass $group, \stdClass $leader, \stdClass $staff): \stdClass {
        $ticket = tickets::file_help($activity, $group, 'How do I swap a member?', FORMAT_PLAIN, (int) $leader->id);
        tickets::claim($activity, (int) $ticket->id, (int) $staff->id);
        tickets::close(
            $activity,
            (int) $ticket->id,
            tickets::STATUS_RESOLVED,
            'Ask your guide.',
            FORMAT_PLAIN,
            (int) $staff->id
        );

        return tickets::get($activity, (int) $ticket->id);
    }

    /**
     * Back up an activity with user data and restore it into a fresh
     * course, returning the restored instance.
     *
     * @param activity $activity the source activity
     * @return \stdClass the restored selfselectadvanced row
     */
    private function round_trip(activity $activity): \stdClass {
        global $DB;

        $admin = get_admin();
        $cm = $activity->cm();

        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cm->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int) $admin->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $results = $bc->get_results();
        $this->assertArrayHasKey('backup_destination', $results, 'the backup produced no archive');
        $file = $results['backup_destination'];
        $dir = make_backup_temp_directory($backupid);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $dir);
        $bc->destroy();

        $target = $this->getDataGenerator()->create_course();
        $rc = new restore_controller(
            $backupid,
            $target->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int) $admin->id,
            backup::TARGET_CURRENT_ADDING
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $restored = $DB->get_records('selfselectadvanced', ['course' => $target->id], 'id DESC');
        $this->assertNotEmpty($restored, 'the activity did not restore at all');

        return reset($restored);
    }

    // L-25: backup and restore.

    /**
     * An article published from a ticket survives the round trip with
     * every one of its remappings done: it belongs to the RESTORED
     * activity, its author is the RESTORED user, and its sourceticketid
     * points at the RESTORED ticket - never at the original rows, which
     * are still sitting in the source course.
     */
    public function test_a_published_article_survives_the_round_trip_with_its_links_remapped(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->redirectMessages();
        [$activity, , $leader, $staff, $group] = $this->scene();

        $ticket = $this->resolved_ticket($activity, $group, $leader, $staff);
        $entry = kb::publish_from_ticket($activity, (int) $ticket->id, (int) $staff->id, [
            'title' => 'Swapping a member',
            'question' => 'How does a group swap a member?',
            'answer' => 'The group asks its guide, who files a composition change.',
            'keywords' => 'Swap, Member, composition',
            'published' => 1,
        ]);
        $this->assertGreaterThan(0, (int) $entry->sourceticketid, 'fixture: the article must know its source ticket');

        $restoredinstance = $this->round_trip($activity);

        $restoredentries = $DB->get_records('selfselectadvanced_kb', ['activityid' => (int) $restoredinstance->id]);
        $this->assertCount(1, $restoredentries, 'the article must restore exactly once');
        $restoredentry = reset($restoredentries);

        $this->assertNotSame((int) $entry->id, (int) $restoredentry->id, 'the restore mints a new row');
        $this->assertSame('Swapping a member', $restoredentry->title);
        $this->assertSame(1, (int) $restoredentry->published, 'a published article must not come back unpublished');
        $this->assertSame('swap, member, composition', $restoredentry->keywords);

        // The author: the SAME person, on the destination site.
        $this->assertSame((int) $staff->id, (int) $restoredentry->usercreated);
        $this->assertSame((int) $staff->id, (int) $restoredentry->usermodified);

        // The source ticket: the RESTORED one, never the original.
        $restoredticket = $DB->get_record(
            'selfselectadvanced_ticket',
            ['activityid' => (int) $restoredinstance->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(
            (int) $restoredticket->id,
            (int) $restoredentry->sourceticketid,
            'the article must point at the ticket in its OWN course, not the one it was published from'
        );
        $this->assertNotSame((int) $ticket->id, (int) $restoredentry->sourceticketid);
    }

    /**
     * The other arm of the same step: an article AUTHORED DIRECTLY
     * (sourceticketid 0) restores as one, and an UNPUBLISHED article
     * comes back unpublished rather than being quietly republished into
     * a course whose staff never approved it.
     */
    public function test_a_direct_and_an_unpublished_article_restore_as_themselves(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->redirectMessages();
        [$activity, , , $staff] = $this->scene();

        kb::create($activity, (int) $staff->id, [
            'title' => 'Direct article',
            'question' => 'Something nobody asked?',
            'answer' => 'Answered anyway.',
            'tickettype' => tickets::TYPE_HELP,
            'published' => 1,
        ]);
        kb::create($activity, (int) $staff->id, [
            'title' => 'Draft article',
            'question' => 'Not ready?',
            'answer' => 'Not yet.',
            'tickettype' => tickets::TYPE_HELP,
            'published' => 0,
        ]);

        $restoredinstance = $this->round_trip($activity);

        $restored = $DB->get_records('selfselectadvanced_kb', ['activityid' => (int) $restoredinstance->id], 'title ASC');
        $this->assertCount(2, $restored);
        $bytitle = [];
        foreach ($restored as $row) {
            $bytitle[$row->title] = $row;
        }
        $this->assertArrayHasKey('Direct article', $bytitle);
        $this->assertArrayHasKey('Draft article', $bytitle);
        $this->assertSame(0, (int) $bytitle['Direct article']->sourceticketid, 'a direct article has no source ticket');
        $this->assertSame(1, (int) $bytitle['Direct article']->published);
        $this->assertSame(0, (int) $bytitle['Draft article']->published, 'a draft must not be republished by a restore');
    }

    // L-26: privacy.

    /**
     * The author finds the article in their own export - by TITLE and
     * nothing more, the deliberate narrowing the provider's own comment
     * describes: the article's body is staff-authored public-facing
     * wording, not the author's personal narrative.
     */
    public function test_the_author_finds_their_article_in_their_own_export(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $staff] = $this->scene();
        $cm = $activity->cm();

        kb::create($activity, (int) $staff->id, [
            'title' => 'An article this person wrote',
            'question' => 'Q?',
            'answer' => 'A.',
            'tickettype' => tickets::TYPE_HELP,
            'published' => 1,
        ]);

        $context = \context_module::instance($cm->id);
        $contextlist = new approved_contextlist(
            \core_user::get_user((int) $staff->id),
            'mod_selfselectadvanced',
            [$context->id]
        );

        \core_privacy\local\request\writer::reset();
        provider::export_user_data($contextlist);
        $exported = \core_privacy\local\request\writer::with_context($context)
            ->get_data([get_string('pluginname', 'mod_selfselectadvanced')]);

        $this->assertNotEmpty($exported->kbarticlesauthored ?? [], 'the author must find their article in their export');
        $titles = array_map(static fn($row) => $row->title, (array) $exported->kbarticlesauthored);
        $this->assertContains('An article this person wrote', $titles);
    }

    /**
     * Somebody who never touched the knowledgebank finds no article in
     * their export - the absence half, without which the test above
     * would pass on a provider that exported every article to everybody.
     */
    public function test_a_stranger_finds_no_article_in_their_export(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , $leader, $staff] = $this->scene();
        $cm = $activity->cm();

        kb::create($activity, (int) $staff->id, [
            'title' => 'Somebody elses article',
            'question' => 'Q?',
            'answer' => 'A.',
            'tickettype' => tickets::TYPE_HELP,
            'published' => 1,
        ]);

        $context = \context_module::instance($cm->id);
        $contextlist = new approved_contextlist(
            \core_user::get_user((int) $leader->id),
            'mod_selfselectadvanced',
            [$context->id]
        );

        \core_privacy\local\request\writer::reset();
        provider::export_user_data($contextlist);
        $exported = \core_privacy\local\request\writer::with_context($context)
            ->get_data([get_string('pluginname', 'mod_selfselectadvanced')]);

        $titles = array_map(static fn($row) => $row->title, (array) ($exported->kbarticlesauthored ?? []));
        $this->assertNotContains('Somebody elses article', $titles);
    }

    /**
     * ERASING THE AUTHOR DE-LINKS THEM AND KEEPS THE ARTICLE. The
     * article is the course's answer to a question the course keeps
     * being asked; deleting it because the person who typed it left
     * would take a resource away from every student who never met them.
     */
    public function test_erasing_the_author_delinks_them_but_keeps_the_article(): void {
        global $DB;

        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $staff] = $this->scene();
        $cm = $activity->cm();

        $entry = kb::create($activity, (int) $staff->id, [
            'title' => 'The article that outlives its author',
            'question' => 'Q?',
            'answer' => 'A.',
            'tickettype' => tickets::TYPE_HELP,
            'published' => 1,
        ]);

        $context = \context_module::instance($cm->id);
        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user((int) $staff->id),
            'mod_selfselectadvanced',
            [$context->id]
        ));

        $after = $DB->get_record('selfselectadvanced_kb', ['id' => (int) $entry->id], '*', MUST_EXIST);
        $this->assertSame('The article that outlives its author', $after->title, 'the article itself must survive');
        $this->assertSame(1, (int) $after->published, 'and must not be quietly unpublished either');
        $this->assertSame(0, (int) $after->usercreated, 'the author id must be de-linked to the 0 sentinel');
        $this->assertSame(0, (int) $after->usermodified);
    }

    /**
     * The author's own context is DISCOVERED by the provider - both
     * through their own request and through an administrator's userlist
     * - so an erasure that never visits this context cannot happen. The
     * two APIs have to agree, which is why both are asked here.
     */
    public function test_the_authors_context_is_found_by_both_privacy_apis(): void {
        $this->resetAfterTest();
        $this->redirectMessages();
        [$activity, , , $staff] = $this->scene();
        $cm = $activity->cm();
        $context = \context_module::instance($cm->id);

        kb::create($activity, (int) $staff->id, [
            'title' => 'Findable',
            'question' => 'Q?',
            'answer' => 'A.',
            'tickettype' => tickets::TYPE_HELP,
            'published' => 1,
        ]);

        // Both id lists are cast: they come back from the database, so
        // a context id can arrive as the string '191001' while
        // $context->id is the integer 191001, and assertContains is
        // strict. The subject here is WHICH contexts are found, not what
        // type the DML layer chose to hand them back as.
        $contextlist = provider::get_contexts_for_userid((int) $staff->id);
        $this->assertContains(
            (int) $context->id,
            array_map('intval', $contextlist->get_contextids()),
            'the author must find the context their article lives in'
        );

        $userlist = new \core_privacy\local\request\userlist($context, 'mod_selfselectadvanced');
        provider::get_users_in_context($userlist);
        $this->assertContains(
            (int) $staff->id,
            array_map('intval', $userlist->get_userids()),
            'an administrator deleting everybody in this context must reach the author too'
        );
    }
}
