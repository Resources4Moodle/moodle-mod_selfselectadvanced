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
 * Shared helpers for the LLM API (1.20.46, classes/external/api_*.php).
 *
 * ONE HOME for the rule non-negotiable A states twice over ("the api
 * capability alone must not suffice") so the eight endpoints cannot
 * drift apart on it: require_api_authority() is the single place that
 * asks BOTH mod/selfselectadvanced:api and tickets::require_queue_
 * authority(). Kept here, in classes/local/, rather than duplicated in
 * classes/external/ - non-negotiable 4 pins the external classes as
 * thin wrappers with no state logic of their own, and a repeated
 * capability check across eight files is exactly the kind of logic that
 * belongs in one place instead.
 *
 * Role labels are deliberately plain, untranslated protocol strings
 * (DISCRETIONARY CALL, flagged for the orchestrator) rather than
 * get_string() lookups: this vocabulary is consumed by a machine, not
 * rendered on a page, and a label that shifted with the site's language
 * would break a caller parsing it - the same reasoning that already
 * keeps tickets::STATUS_* and tickets::TYPE_* themselves untranslated
 * wire values, with a human label layered on top only at render time.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class llmapi {
    /** @var string Requester role label: a plain confirmed member. */
    public const ROLE_STUDENT = 'student';

    /** @var string Requester role label: the group's leader. */
    public const ROLE_LEADER = 'leader';

    /** @var string Requester/actor role label: the group's assigned guide. */
    public const ROLE_GUIDE = 'guide';

    /** @var string Actor role label: this ticket's own requester. */
    public const ROLE_REQUESTER = 'requester';

    /** @var string Actor role label: a :coordinate holder. */
    public const ROLE_COORDINATOR = 'coordinator';

    /** @var string Actor role label: a :manage holder. */
    public const ROLE_EDITINGTEACHER = 'editing teacher';

    /** @var string Actor role label fallback: queue authority of an unrecognised shape. */
    public const ROLE_STAFF = 'staff';

    /**
     * The activity a ticket belongs to, from its id alone - every write
     * endpoint's signature is (ticketid, ...), carrying no cmid (BUILD
     * spec section C), so this is the one place that reads the row far
     * enough to resolve it. A read here, never a write - the grep-level
     * source pin (non-negotiable 4) is about classes/external/, and
     * keeping even this lookup out of those files means there is
     * nothing in them to grep for at all.
     *
     * @param int $ticketid the ticket
     * @return activity
     * @throws \dml_missing_record_exception if the ticket does not exist
     */
    public static function activity_for_ticket(int $ticketid): activity {
        global $DB;

        $activityid = $DB->get_field('selfselectadvanced_ticket', 'activityid', ['id' => $ticketid], MUST_EXIST);

        return activity::from_instance((int) $activityid);
    }

    /**
     * BOTH capabilities, explicitly (BUILD spec section A: "the api
     * capability alone must not suffice"). A user holding :api but
     * lacking coordinate-level authority is refused here by
     * tickets::require_queue_authority() with the SAME
     * required_capability_exception a human coordinator missing
     * :coordinate would get - the machine is inside the existing
     * authority model, not a parallel one.
     *
     * @param activity $activity the activity
     * @param int $userid the token user
     * @throws \required_capability_exception when either is missing
     */
    public static function require_api_authority(activity $activity, int $userid): void {
        require_capability('mod/selfselectadvanced:api', $activity->context(), $userid);
        tickets::require_queue_authority($activity, $userid);
    }

    /**
     * The requester's role label (BUILD spec B: "Role derives from the
     * plugin's relational predicates (leader of that group / guide of
     * that group / student)"), CALLED from tickets::raiser_role() - the
     * same predicate the filing gate itself uses - not transcribed, so
     * the two can never disagree about who somebody is.
     *
     * guidecap/guidereduce carry no group at all (groupid null,
     * tickets::group_of() answers null for them) despite always being
     * guide-filed (file_guidecap()/file_guidereduce() both require
     * mod/selfselectadvanced:guide) - raiser_role() falls through to its
     * groupless default of 'member' for them, which is wrong, so those
     * two types are special-cased to 'guide' first.
     *
     * DISCRETIONARY CALL (flagged for the orchestrator): a guidegone
     * ticket also carries a group, but requestedby names the SYSTEM
     * OBSERVER whose action triggered it (a deletion or unenrolment),
     * not a person with any raising relationship to that group at all -
     * raiser_role() has no concept for that and answers its groupless
     * default, 'student', which may be inaccurate for an admin actor.
     * Left as the general rule rather than special-cased: the identity
     * (fullname) is still accurate, only the role label may read oddly
     * on this one system-filed type, and the alternative is inventing a
     * fourth role this plugin's own filing model does not have.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @return string self::ROLE_STUDENT, self::ROLE_LEADER or self::ROLE_GUIDE
     */
    public static function requester_role(activity $activity, stdClass $ticket): string {
        if (in_array($ticket->type, [tickets::TYPE_GUIDECAP, tickets::TYPE_GUIDEREDUCE], true)) {
            return self::ROLE_GUIDE;
        }

        $group = tickets::group_of($activity, $ticket);
        $role = tickets::raiser_role($group, (int) $ticket->requestedby);

        return $role === 'member' ? self::ROLE_STUDENT : $role;
    }

    /**
     * The requester's identity payload (BUILD spec, D-104: "a definite
     * yes" - fullname + role, never email or phone).
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @return array{fullname: string, role: string}
     */
    public static function requester_identity(activity $activity, stdClass $ticket): array {
        $requester = \core_user::get_user((int) $ticket->requestedby);

        return [
            'fullname' => $requester ? fullname($requester) : '',
            'role' => self::requester_role($activity, $ticket),
        ];
    }

    /**
     * A trail row's actor, as a ROLE LABEL rather than a name (BUILD
     * spec: "actor names REPLACED BY ROLE LABELS ... the machine needs
     * the shape of the conversation, not staff identities; requester
     * identity is the exception D-104 grants").
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @param int $actorid the trail row's actorid
     * @return string one of the self::ROLE_* constants
     */
    public static function actor_role_label(activity $activity, stdClass $ticket, int $actorid): string {
        if ($actorid === (int) $ticket->requestedby) {
            return self::ROLE_REQUESTER;
        }

        $context = $activity->context();
        if (has_capability('mod/selfselectadvanced:manage', $context, $actorid)) {
            return self::ROLE_EDITINGTEACHER;
        }
        if (has_capability('mod/selfselectadvanced:coordinate', $context, $actorid)) {
            return self::ROLE_COORDINATOR;
        }

        return self::ROLE_STAFF;
    }

    /**
     * A ticket filter value the caller sent, or '' when it is not one of
     * tickets::known_types() - the same forgiving whitelist-and-reset
     * tickets.php's own UI applies to a querystring value (never a
     * coding_exception for a value that arrived over the wire, which is
     * exactly the "a person typed it" case that idiom exists for -
     * except here the "person" may be an LLM's own minor slip).
     *
     * @param string $type the caller's raw value
     * @return string $type, or '' if unrecognised
     */
    public static function known_type_or_blank(string $type): string {
        return $type !== '' && in_array($type, tickets::known_types(), true) ? $type : '';
    }

    /**
     * The status twin of known_type_or_blank().
     *
     * DISCRETIONARY CALL (flagged for the orchestrator): this list
     * includes tickets::STATUS_NEEDSINFO, which tickets.php's own UI
     * dropdown does not offer (its local $knownstatuses omits it) even
     * though the SERVICE layer's own validate_status_filter() accepts
     * it. The machine's filter is built against the service layer's
     * actual range, not the human page's narrower dropdown - a
     * needs-info ticket is exactly the kind of state a coordinating LLM
     * has reason to filter for, often because it asked the question
     * itself.
     *
     * @param string $status the caller's raw value
     * @return string $status, or '' if unrecognised
     */
    public static function known_status_or_blank(string $status): string {
        $known = [
            tickets::STATUS_OPEN,
            tickets::STATUS_CLAIMED,
            tickets::STATUS_NEEDSINFO,
            tickets::STATUS_RESOLVED,
            tickets::STATUS_DECLINED,
            tickets::STATUS_WITHDRAWN,
        ];

        return $status !== '' && in_array($status, $known, true) ? $status : '';
    }

    /**
     * The team name a ticket names, or the "no team" placeholder for a
     * guidecap/guidereduce request - the same idiom
     * tickets::subject_name() keeps privately for its own notifications.
     *
     * @param activity $activity the activity
     * @param stdClass $ticket the ticket row
     * @return string
     */
    public static function subject_name(activity $activity, stdClass $ticket): string {
        $group = tickets::group_of($activity, $ticket);

        return $group !== null ? format_string($group->name) : get_string('tickethasnoteam', 'mod_selfselectadvanced');
    }

    /**
     * A minimal post-write snapshot (BUILD spec section C's four write
     * endpoints), returned by claim/request_info/respond/escalate so a
     * caller can see the ticket's new state without a second read call.
     *
     * @param stdClass $ticket the updated ticket row
     * @return array{id: int, status: string, claimedby: int, escalated: bool}
     */
    public static function status_snapshot(stdClass $ticket): array {
        return [
            'id' => (int) $ticket->id,
            'status' => (string) $ticket->status,
            'claimedby' => (int) ($ticket->claimedby ?? 0),
            'escalated' => (int) ($ticket->escalated ?? 0) === 1,
        ];
    }

    /**
     * Attachment FILENAMES only for one ticket filearea/itemid - no
     * URLs, no bytes (BUILD spec B: "Attachment filenames listed per
     * post, no URLs/bytes"; downloadfiles is 0 on the service itself,
     * db/services.php).
     *
     * @param \context $context the activity's module context
     * @param string $filearea tickets::FILEAREA_*
     * @param int $itemid the ticket id (request) or ticketlog row id (post)
     * @return string[] filenames
     */
    public static function attachment_filenames(\context $context, string $filearea, int $itemid): array {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_selfselectadvanced',
            $filearea,
            $itemid,
            'filename',
            false
        );

        return array_values(array_map(static fn($file) => $file->get_filename(), $files));
    }
}
