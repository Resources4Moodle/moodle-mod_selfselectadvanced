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
        'msgleaderreplacedbody' => 'msgleaderreplacedsubject',
        'msgleaverequestbody' => 'msgleaverequestsubject',
        'msgleaveconfirmedbody' => 'msgleaveconfirmedsubject',
        'msgreminderbody' => 'msgremindersubject',
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
     * @param activity $activity the activity
     * @param string $msgkey body lang key (must be in the catalog)
     * @param string $subject custom subject
     * @param string $body custom body
     * @return stdClass the stored record
     */
    public static function save(activity $activity, string $msgkey, string $subject, string $body): stdClass {
        global $DB;

        if (!isset(self::CATALOG[$msgkey])) {
            throw new \moodle_exception('errtemplatekey', 'mod_selfselectadvanced');
        }
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

        return $record;
    }

    /**
     * Remove an override so the kind falls back to the language string.
     *
     * @param activity $activity the activity
     * @param string $msgkey body lang key
     */
    public static function reset(activity $activity, string $msgkey): void {
        global $DB;

        $DB->delete_records('selfselectadvanced_template', [
            'activityid' => $activity->id(),
            'msgkey' => $msgkey,
        ]);
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
