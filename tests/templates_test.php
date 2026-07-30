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
use mod_selfselectadvanced\local\templates;

/**
 * Per-activity notification template overrides (2026-07-24 change).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_selfselectadvanced\local\templates
 * @covers     \mod_selfselectadvanced\local\notifier
 */
final class templates_test extends \advanced_testcase {
    /**
     * Placeholder substitution mirrors get_string semantics.
     */
    public function test_render(): void {
        $a = (object) ['firstname' => 'Bina', 'group' => 'Team Mercury', 'url' => 'https://x.example/y'];
        $this->assertSame(
            'Dear Bina, join Team Mercury via https://x.example/y today.',
            templates::render('Dear {$a->firstname}, join {$a->group} via {$a->url} today.', $a)
        );
        // Unknown placeholders blank out rather than leak syntax.
        $this->assertSame('Hello  and Bina', templates::render('Hello {$a->nosuch} and {$a->firstname}', $a));
        $this->assertSame('No params at all', templates::render('No params at all', null));
    }

    /**
     * Store lifecycle and the notifier override path: a custom
     * invitation template replaces subject and body for this activity
     * only, and reset returns to the language string.
     */
    public function test_notifier_uses_override(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $leader = $gen->create_user();
        $invitee = $gen->create_user(['firstname' => 'Bina', 'lastname' => 'Patel']);
        $invitee2 = $gen->create_user(['firstname' => 'Tara', 'lastname' => 'Iyer']);
        foreach ([$leader, $invitee, $invitee2] as $user) {
            $gen->enrol_user($user->id, $course->id, 'student');
        }
        $instance = $gen->create_module('selfselectadvanced', [
            'course' => $course->id,
            'maxsize' => 4,
            'minsize' => 2,
            'maxlead' => 1,
            'maxmembership' => 1,
            'maxguided' => 5,
            'timeopen' => time() - HOURSECS,
            'timedue' => time() + WEEKSECS,
            'timecutoff' => time() + (2 * WEEKSECS),
        ]);
        $activity = activity::from_cmid((int) $instance->cmid);
        $api = new api($activity);

        $this->assertNull(templates::get($activity, 'msginvitationbody'));
        templates::save(
            $activity,
            'msginvitationbody',
            'Namaste {$a->firstname}!',
            'Group {$a->group} calls you, {$a->fullname}. Go to {$a->url} now.'
        );

        $group = $api->create_group((int) $leader->id, 'Alpha', 'T', '<p>b</p>', FORMAT_HTML);
        $group = groups::get($activity, (int) $group->id);
        $sink = $this->redirectMessages();
        $api->invitations()->send($group, (int) $invitee->id, (int) $leader->id);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertSame('Namaste Bina!', $messages[0]->subject);
        $this->assertStringContainsString('Group Alpha calls you, Bina Patel.', $messages[0]->fullmessage);
        $this->assertStringContainsString('/mod/selfselectadvanced/', $messages[0]->fullmessage);

        // Catalog guard.
        try {
            templates::save($activity, 'nosuchkey', 's', 'b');
            $this->fail('catalog guard expected');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Unknown message template', $e->getMessage());
        }

        // Reset: back to the default language string.
        templates::reset($activity, 'msginvitationbody');
        $sink = $this->redirectMessages();
        $api->invitations()->send($group, (int) $invitee2->id, (int) $leader->id);
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        // Back to the shipped wording, inside the 1.17.0 message shape.
        $this->assertStringContainsString('Hello Tara', $messages[0]->fullmessage);
        $this->assertStringContainsString('You have been invited to join the team', $messages[0]->fullmessage);
    }
}
