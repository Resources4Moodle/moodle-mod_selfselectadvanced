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
use stdClass;

/**
 * Per-activity notification template overrides.
 *
 * Anyone with mod/selfselectadvanced:manage (editing teachers by
 * default) can replace the subject and body of any notification kind
 * for their activity; unset kinds fall back to the site-wide language
 * strings (which site admins can customise via Language
 * customisation). Placeholders use the same {$a->name} syntax as the
 * language strings.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templates {
    /**
     * Every message kind: body lang key => subject lang key. The body
     * key is the stable identifier a stored override is keyed by.
     */
    public const CATALOG = [
        'msginvitationbody' => 'msginvitationsubject',
        'msgacceptedbody' => 'msgacceptedsubject',
        'msgdeclinedbody' => 'msgdeclinedsubject',
        'msgcascadebody' => 'msgcascadesubject',
        'msgwithdrawnbody' => 'msgwithdrawnsubject',
        'msgexpiredinviteebody' => 'msgexpiredinviteesubject',
        'msgexpiredleaderbody' => 'msgexpiredleadersubject',
        'msgnominationbodytransfer' => 'msgnominationsubject',
        'msgnominationbodystepout' => 'msgnominationsubject',
        'msgnominationconfirmedbody' => 'msgnominationconfirmedsubject',
        'msgnominationdeclinedbody' => 'msgnominationdeclinedsubject',
        'msgnominationcancelledbody' => 'msgnominationcancelledsubject',
        'msgsubmittedbody' => 'msgsubmittedsubject',
        'msgqueuedbody' => 'msgqueuedsubject',
        'msgreturnedbody' => 'msgreturnedsubject',
        'msgapprovedbody' => 'msgapprovedsubject',
        'msgfrozenbody' => 'msgfrozensubject',
        'msgunfrozenbody' => 'msgunfrozensubject',
        'msgmovedbody' => 'msgmovedsubject',
        'msgleaderpromotedbody' => 'msgleaderpromotedsubject',
        'msggroupdeletedbody' => 'msggroupdeletedsubject',
        'msgguidechangedbody' => 'msgguidechangedsubject',
        'msghandoverproposedbody' => 'msghandoverproposedsubject',
        'msghandoveracceptedbody' => 'msghandoveracceptedsubject',
        'msghandoverdeclinedbody' => 'msghandoverdeclinedsubject',
        'msgnowguidingbody' => 'msgnowguidingsubject',
        'msgleaderreplacedbody' => 'msgleaderreplacedsubject',
        'msgleaverequestbody' => 'msgleaverequestsubject',
        'msgleaveconfirmedbody' => 'msgleaveconfirmedsubject',
        'msgreminderbody' => 'msgremindersubject',
        'msgcapauditbody' => 'msgcapauditsubject',
        'msgticketfiledbody' => 'msgticketfiledsubject',
        'msgticketclaimedbody' => 'msgticketclaimedsubject',
        'msgticketclosedbody' => 'msgticketclosedsubject',
        'msgcoordinatorassignedbody' => 'msgcoordinatorassignedsubject',
        'msgcoordinatorremovedbody' => 'msgcoordinatorremovedsubject',
        'msgcontactsentbody' => 'msgcontactsentsubject',
        'msgcontactacceptedbody' => 'msgcontactacceptedsubject',
        'msgcontactdeclinedbody' => 'msgcontactdeclinedsubject',
    ];

    /**
     * Placeholders available to every template (see notifier::send).
     */
    public const COMMON_PLACEHOLDERS = ['firstname', 'lastname', 'fullname', 'url'];

    /**
     * All overrides of one activity.
     *
     * @param activity $activity the activity
     * @return stdClass[] msgkey-keyed records
     */
    public static function get_all(activity $activity): array {
        global $DB;

        $records = $DB->get_records('selfselectadvanced_template', ['activityid' => $activity->id()]);
        $bykey = [];
        foreach ($records as $record) {
            $bykey[$record->msgkey] = $record;
        }

        return $bykey;
    }

    /**
     * The stored override for one message kind, if any.
     *
     * @param activity $activity the activity
     * @param string $msgkey body lang key
     * @return stdClass|null
     */
    public static function get(activity $activity, string $msgkey): ?stdClass {
        global $DB;

        $record = $DB->get_record('selfselectadvanced_template', [
            'activityid' => $activity->id(),
            'msgkey' => $msgkey,
        ]);

        return $record ?: null;
    }

    /**
     * Create or update an override.
     *
     * The actor and their authority are asked INSIDE the service
     * (AUTH-001, 1.20.4): what a notification says to every recipient
     * of an activity is :manage authority, and until now only the page
     * asked, so any direct caller rewrote the words the plugin speaks
     * with. The actor is always stated by the caller - a service that
     * guesses its actor from $USER answers for the session, not for
     * the request.
     *
     * @param activity $activity the activity
     * @param string $msgkey body lang key (must be in the catalog)
     * @param string $subject custom subject
     * @param string $body custom body
     * @param int $actorid the acting user
     * @return stdClass the stored record
     * @throws \required_capability_exception when the actor lacks :manage here
     */
    public static function save(
        activity $activity,
        string $msgkey,
        string $subject,
        string $body,
        int $actorid
    ): stdClass {
        global $DB;

        if (!isset(self::CATALOG[$msgkey])) {
            throw new \moodle_exception('errtemplatekey', 'mod_selfselectadvanced');
        }
        require_capability('mod/selfselectadvanced:manage', $activity->context(), $actorid);
        $now = time();
        $record = self::get($activity, $msgkey);
        if ($record) {
            $record->subject = $subject;
            $record->body = $body;
            $record->timemodified = $now;
            $DB->update_record('selfselectadvanced_template', $record);
        } else {
            $record = (object) [
                'activityid' => $activity->id(),
                'msgkey' => $msgkey,
                'subject' => $subject,
                'body' => $body,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('selfselectadvanced_template', $record);
        }

        // A saved template is a state change an operator audits, so it
        // leaves an event (LOG-001, 1.20.4). No lock or transaction is
        // open here - a single-row upsert per (activity, msgkey)
        // carries no cross-row invariant - so triggering after the
        // write satisfies the after-commit-and-release rule trivially.
        \mod_selfselectadvanced\event\template_updated::create([
            'objectid' => (int) $record->id,
            'context' => $activity->context(),
            'userid' => $actorid,
            'other' => ['msgkey' => $msgkey],
        ])->trigger();

        return $record;
    }

    /**
     * Remove an override so the kind falls back to the language string.
     *
     * Same in-service authority as save(): removing the custom text
     * changes what every future notification of the kind says, and it
     * is audited the same way (AUTH-001/LOG-001, 1.20.4). A kind with
     * no stored override is a no-op and leaves no event.
     *
     * @param activity $activity the activity
     * @param string $msgkey body lang key
     * @param int $actorid the acting user
     * @throws \required_capability_exception when the actor lacks :manage here
     */
    public static function reset(activity $activity, string $msgkey, int $actorid): void {
        global $DB;

        require_capability('mod/selfselectadvanced:manage', $activity->context(), $actorid);
        $record = self::get($activity, $msgkey);
        if (!$record) {
            return;
        }
        $DB->delete_records('selfselectadvanced_template', ['id' => $record->id]);

        \mod_selfselectadvanced\event\template_deleted::create([
            'objectid' => (int) $record->id,
            'context' => $activity->context(),
            'userid' => $actorid,
            'other' => ['msgkey' => $msgkey],
        ])->trigger();
    }

    /**
     * Substitute {$a->name} (and plain {$a}) placeholders into a custom
     * template, exactly like get_string() does for language strings.
     *
     * @param string $text the template text
     * @param stdClass|null $a placeholder values
     * @return string the rendered text
     */
    public static function render(string $text, ?stdClass $a): string {
        if ($a === null) {
            return $text;
        }

        return preg_replace_callback(
            '/\{\$a(?:->([a-z0-9_]+))?\}/i',
            static function (array $match) use ($a): string {
                if (!isset($match[1])) {
                    return is_scalar($a) ? (string) $a : '';
                }

                return isset($a->{$match[1]}) && is_scalar($a->{$match[1]}) ? (string) $a->{$match[1]} : '';
            },
            $text
        );
    }
}
