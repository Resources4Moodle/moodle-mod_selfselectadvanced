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

namespace mod_selfselectadvanced\local\attributes;

use stdClass;

/**
 * Participant attributes: site-wide, plugin-only records of gender,
 * department, sub-department and mobile (spec 8.1, D8, U4).
 *
 * Never reads from or writes to site profile fields, cohorts or any
 * external store, and never creates user accounts (C11). Activity
 * roles get read access where flows need values; only holders of the
 * system-context ingestattributes capability write.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string[] The quota dimensions (mobile is contact info, not a dimension). */
    public const DIMENSIONS = ['gender', 'department', 'subdepartment', 'program'];

    /**
     * @var string Ingest and edit participant attributes. Declared at
     * CONTEXT_SYSTEM with no archetype, so in practice only site
     * administrators hold it - and it is the ONE authority behind every
     * write in this file and in csv_importer.
     */
    public const INGEST = 'mod/selfselectadvanced:ingestattributes';

    /**
     * Attribute records for a set of users, keyed by userid.
     *
     * Users without a record are absent from the result; callers treat
     * them as "attributes missing" and flag, never crash (spec 8.1).
     *
     * @param int[] $userids the users
     * @return stdClass[] userid-keyed records
     */
    public static function get_for_users(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        // Chunked so an activity-wide caller can never approach the
        // bind-parameter ceiling (same discipline as the evaluator's
        // batch path).
        $byuser = [];
        foreach (array_chunk(array_unique(array_map('intval', $userids)), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $records = $DB->get_records_select('selfselectadvanced_userattr', "userid $insql", $params);
            foreach ($records as $record) {
                $byuser[(int) $record->userid] = $record;
            }
        }

        return $byuser;
    }

    /**
     * One user's attribute record, or null.
     *
     * @param int $userid the user
     * @return stdClass|null
     */
    public static function get(int $userid): ?stdClass {
        global $DB;

        return $DB->get_record('selfselectadvanced_userattr', ['userid' => $userid]) ?: null;
    }

    /**
     * Create or update a user's attributes (admin write path).
     *
     * AUTHORISED HERE (audit A-6), not by the page. The class docblock
     * has said since 1.0 that "only holders of the system-context
     * ingestattributes capability write", and until 1.20.1 the only
     * thing enforcing that sentence was admin_externalpage_setup() in
     * attributes.php. That is a door, not a gate: this method writes
     * gender, department, sub-department, MOBILE NUMBER and the sharing
     * CONSENT FLAG for any user id it is handed, and it is reachable
     * from the CSV importer, from any future service, task or web
     * service, and from anything a site adds - none of which pass
     * through that page.
     *
     * The capability is asked of the ACTOR at system context, which is
     * the context db/access.php declares it in. A site administrator
     * passes by is_siteadmin(), which is who holds it in practice.
     *
     * @param int $userid the target user (must exist in Moodle)
     * @param array $values any of gender, department, subdepartment, mobile
     * @param int $actorid the acting administrator
     * @return stdClass the stored record
     * @throws \required_capability_exception when the actor may not ingest attributes
     */
    public static function set(int $userid, array $values, int $actorid): stdClass {
        global $DB;

        require_capability(self::INGEST, \context_system::instance(), $actorid);

        // The user must already exist: this plugin never creates accounts (C11).
        $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id', MUST_EXIST);

        $now = time();
        $record = $DB->get_record('selfselectadvanced_userattr', ['userid' => $userid]);
        $isnew = !$record;
        if ($isnew) {
            $record = (object) [
                'userid' => $userid,
                'gender' => null,
                'department' => null,
                'subdepartment' => null,
                'mobile' => null,
                'seatlocation' => null,
                'program' => null,
                'shareconsent' => 0,
                'timecreated' => $now,
            ];
        }
        foreach (['gender', 'department', 'subdepartment', 'mobile', 'seatlocation', 'program'] as $field) {
            if (array_key_exists($field, $values)) {
                $value = trim((string) $values[$field]);
                $record->$field = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('shareconsent', $values)) {
            $record->shareconsent = empty($values['shareconsent']) ? 0 : 1;
        }
        $record->usermodified = $actorid;
        $record->timemodified = $now;
        if ($isnew) {
            $record->id = $DB->insert_record('selfselectadvanced_userattr', $record);
        } else {
            $DB->update_record('selfselectadvanced_userattr', $record);
        }

        \mod_selfselectadvanced\event\attributes_updated::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
        ])->trigger();

        self::purge_value_cache();

        return $record;
    }

    /**
     * Remove a user's attribute record (privacy delete and the
     * user_deleted observer, review item M3).
     *
     * @param int $userid the user
     */
    public static function delete_for_user(int $userid): void {
        global $DB;

        $DB->delete_records('selfselectadvanced_userattr', ['userid' => $userid]);
        self::purge_value_cache();
    }

    /**
     * Distinct non-empty values of a dimension, for the quota value
     * pickers (spec 4.7). Cached in MUC, invalidated on every write.
     *
     * @param string $dimension gender, department or subdepartment
     * @return string[] sorted distinct values
     */
    public static function distinct_values(string $dimension): array {
        global $DB;

        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new \coding_exception('Unknown attribute dimension: ' . $dimension);
        }

        $cache = \cache::make('mod_selfselectadvanced', 'attrvalues');
        $values = $cache->get($dimension);
        if ($values === false) {
            $values = $DB->get_fieldset_sql(
                "SELECT DISTINCT $dimension
                   FROM {selfselectadvanced_userattr}
                  WHERE $dimension IS NOT NULL AND $dimension <> ''
               ORDER BY $dimension"
            );
            $cache->set($dimension, $values);
        }

        return $values;
    }

    /**
     * Purge the distinct-value cache (every write path and the
     * user_deleted observer).
     */
    public static function purge_value_cache(): void {
        \cache::make('mod_selfselectadvanced', 'attrvalues')->purge();
    }

    /**
     * A compact display line of a user's attributes for staff rosters
     * (gender, department, sub-department, and the mobile number only
     * when the caller has already decided the viewer may have it).
     *
     * $includemobile is the CALLER'S verdict, not a capability: since
     * 1.20 every call site composes it from
     * {@see \mod_selfselectadvanced\local\contactprivacy::can_see_map()}
     * AND {@see self::mobile_visible()}. Do NOT pass a literal true and
     * do NOT pass has_capability(':viewall', ...) - the pre-1.20
     * decision-U4 wording said viewall, and that is exactly the defect
     * flagged.php shipped.
     *
     * @param stdClass|null $record the attribute record
     * @param bool $includemobile whether the viewer may see the mobile number
     * @return string localised summary, or the missing-attributes flag
     */
    public static function display_line(?stdClass $record, bool $includemobile): string {
        if (!$record) {
            return get_string('attrsmissing', 'mod_selfselectadvanced');
        }
        $parts = [];
        foreach (self::DIMENSIONS as $field) {
            if (!empty($record->$field)) {
                $parts[] = s($record->$field);
            }
        }
        if ($includemobile && !empty($record->mobile)) {
            $parts[] = s($record->mobile);
        }

        return $parts ? implode(' · ', $parts) : get_string('attrsmissing', 'mod_selfselectadvanced');
    }

    /**
     * The same summary with raw values for exports: dataformat
     * writers escape per format themselves, so feeding them the
     * s()-wrapped display line double-encodes entities.
     *
     * @param stdClass|null $record the attribute record
     * @param bool $includemobile whether the viewer may see the mobile number
     * @return string plain-text summary, or the missing-attributes flag
     */
    public static function plain_line(?stdClass $record, bool $includemobile): string {
        if (!$record) {
            return get_string('attrsmissing', 'mod_selfselectadvanced');
        }
        $parts = [];
        foreach (self::DIMENSIONS as $field) {
            if (!empty($record->$field)) {
                $parts[] = (string) $record->$field;
            }
        }
        if ($includemobile && !empty($record->mobile)) {
            $parts[] = (string) $record->mobile;
        }

        return $parts ? implode(' | ', $parts) : get_string('attrsmissing', 'mod_selfselectadvanced');
    }

    /**
     * The composition dimensions this activity actually uses in its
     * counting rules or its seat plan, in canonical order; the two
     * department levels when nothing is configured yet.
     *
     * @param \mod_selfselectadvanced\activity $activity the activity
     * @return string[]
     */
    public static function used_dimensions(\mod_selfselectadvanced\activity $activity): array {
        global $DB;

        $dims = $DB->get_fieldset_sql(
            "SELECT DISTINCT dimension FROM (
                SELECT dimension FROM {selfselectadvanced_quota} WHERE activityid = :qa
                UNION
                SELECT dimension FROM {selfselectadvanced_qslot} WHERE activityid = :sa
             ) dims",
            ['qa' => $activity->id(), 'sa' => $activity->id()]
        );
        $dims = array_values(array_intersect(self::DIMENSIONS, $dims ?: []));

        return $dims ?: ['department', 'subdepartment'];
    }

    /**
     * Self-service mobile-sharing consent (privacy feature): flips
     * only the consent flag, never any attribute value, and only for
     * a user who already holds an attribute record.
     *
     * SELF ONLY, and the check is the point of the method (audit A-4).
     * The parameter has always been documented as "the student
     * themself" and the method never once asked whether it was: any
     * caller could set any user's consent to anything. That is not one
     * more missing capability check - consent is the gate the entire
     * mobile-disclosure design rests on. contactprivacy decides who may
     * BYPASS a person's consent; mobile_visible() decides what a viewer
     * sees given it; every one of those answers is only worth what the
     * flag underneath is worth, and a service that will set the flag
     * for anybody is a forgeable key to all of them.
     *
     * Not live-reachable today - view.php passes $USER twice - and
     * that is exactly why it is written down here rather than left to
     * the caller: the invariant is a property of the operation, and the
     * next caller has not been written yet.
     *
     * THERE IS A STAFF PATH AND IT IS NOT THIS ONE. The CSV import
     * carries a "Share Consent" column, and it writes the flag through
     * {@see self::set()} - see csv_importer::run(), which passes
     * shareconsent in the $set array - under
     * mod/selfselectadvanced:ingestattributes, a system-context
     * capability held in practice only by site administrators. So no
     * legitimate staff operation needs this method, and it takes no
     * capability argument to widen: an office correcting a consent
     * value does it on the audited bulk path, with an actor recorded in
     * usermodified and an attributes_updated event, and this one stays
     * what its name says.
     *
     * @param int $userid the consenting user
     * @param bool $consent share (true) or withhold (false)
     * Refused with core's own nopermissions string rather than a new
     * plugin one, so the refusal is a clean permission message on every
     * language pack this plugin has ever shipped in.
     *
     * @param int $actorid the acting user; MUST be the consenting user
     * @throws \moodle_exception when the actor is not the subject
     */
    public static function set_consent(int $userid, bool $consent, int $actorid): void {
        global $DB;

        if ($actorid !== $userid) {
            throw new \moodle_exception(
                'nopermissions',
                'error',
                '',
                get_string('shareconsent', 'mod_selfselectadvanced')
            );
        }

        $record = $DB->get_record('selfselectadvanced_userattr', ['userid' => $userid], '*', MUST_EXIST);
        $record->shareconsent = $consent ? 1 : 0;
        $record->usermodified = $actorid;
        $record->timemodified = time();
        $DB->update_record('selfselectadvanced_userattr', $record);
    }

    /**
     * Whether a viewer may see this record's mobile number: the owner's
     * own sharing consent, or a viewer entitled to bypass it.
     *
     * The second argument is "may this viewer overrule the owner's
     * consent", decided by
     * {@see \mod_selfselectadvanced\local\contactprivacy::mobile_consent_bypass()}
     * and by nothing else. Do NOT pass
     * has_capability('mod/selfselectadvanced:viewall', ...) and do NOT
     * pass a literal true: seeing every team is not permission to
     * overrule a person's consent, and "the page gate already required
     * something" is not either. Both were live defects in 1.19 - a
     * non-editing teacher who guided nothing read unconsented numbers,
     * and flagged.php printed them to everyone with no gate at all.
     *
     * The body is deliberately unchanged from the version that carried
     * those defects: behaviour moves through the ARGUMENTS, so the diff
     * stays reviewable and every call site had to be re-read.
     *
     * @param stdClass|null $record the attribute record
     * @param bool $consentbypass whether the viewer may bypass the owner's consent
     * @return bool whether the number may be shown
     */
    public static function mobile_visible(?stdClass $record, bool $consentbypass): bool {
        if (!$record || empty($record->mobile)) {
            return false;
        }

        return $consentbypass || !empty($record->shareconsent);
    }
}
