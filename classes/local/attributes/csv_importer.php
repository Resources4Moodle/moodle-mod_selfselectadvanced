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

use csv_import_reader;
use stdClass;

/**
 * Participant-attribute CSV ingest (spec 8.1, U4, decision A9).
 *
 * Input columns: username, firstname, lastname, gender, department,
 * subdepartment, mobile (header matching is case- and
 * space-insensitive; an optional email column acts as the fallback
 * match key when a row's username is blank).
 *
 * A "Share consent" column is ACCEPTED AND IGNORED (decision 85). The
 * header map is built from whatever columns the file carries, so an
 * unused column costs nothing and fails nothing - but no CSV can set a
 * student's mobile-sharing consent, because that flag has one owner and
 * it is the student. Before 1.20.30 an upload could revoke it silently.
 *
 * Rules: rows are matched to EXISTING users by username (fallback
 * email). Unknown users are rejected and reported - creating accounts
 * is the site administrator's job through standard Moodle
 * administration (C11). First/last name are cross-check columns: a
 * mismatch against the matched account is warned but the row is still
 * ingested, username being authoritative (A9).
 *
 * CONTACT PRIVACY, DECISION 24 - and why the email fallback SURVIVES it
 * (2026-08-02, stated so it is a decision and not an oversight).
 * Decision 24 forbids every surface of this plugin from matching,
 * rendering, exporting or labelling an address for any role while the
 * per-activity switch is on, and it cost the move-form picker and the
 * invitation candidate search their address match. This importer keeps
 * its fallback key for three reasons, all of which are about where the
 * address comes from and what leaves again:
 *
 *  - the address is SUPPLIED, not discovered. It is a cell in a file
 *    the operator wrote, used once, as an exact lower-cased equality
 *    lookup - never a LIKE, so it cannot be walked with a prefix - to
 *    name a row's subject. It is not a repeatable probe over the
 *    enrolled population;
 *  - this ingest runs at SITE ADMINISTRATION level
 *    (admin_externalpage_setup, see attributes.php), the authority that
 *    can already read every account's address through core's own user
 *    administration. Closing this door leaves that one open, so
 *    closing it buys nothing and breaks a documented input format;
 *  - nothing goes back out. A matched row is reported by USERNAME
 *    ({@see csvnamemismatch}); a rejected row echoes only the key the
 *    operator typed - their own input, never a second account's
 *    address. That is the property Decision 24 actually protects here,
 *    and it is pinned by tests/importaddress_test.php rather than left
 *    to inspection.
 *
 * ACCEPTED RESIDUAL, stated rather than hidden: an administrator who
 * pastes addresses obtained elsewhere learns which of them belong to
 * accounts on this site, though not - through this class - whose.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_importer {
    /** @var string[] Required header columns (canonical form). */
    public const REQUIRED = ['username', 'firstname', 'lastname', 'gender', 'department', 'subdepartment', 'mobile'];

    /** @var string Optional fallback-key column. */
    public const OPTIONAL = 'email';

    /** @var int Maximum accepted mobile length (schema char 32). */
    public const MOBILE_MAX = 32;

    /**
     * Validate and optionally commit a parsed CSV.
     *
     * @param csv_import_reader $reader an initialised reader (load_csv_content done)
     * @param int $actorid the acting administrator
     * @param bool $commit false = dry-run report only, true = write inside a transaction
     * @param \stdClass|null $options mode ('override'|'fillmissing') and defaults array
     * @return stdClass report: ok, headererror, created, updated, warnings[], rejected[], total
     * @throws \required_capability_exception when the actor may not ingest attributes
     */
    public static function run(csv_import_reader $reader, int $actorid, bool $commit, ?\stdClass $options = null): stdClass {
        global $DB;

        // AUTHORISED HERE (audit A-6), and asked of the DRY RUN too.
        //
        // attributes.php reaches this method twice - once with
        // $commit=false to render the preview and once with true to
        // write - and both were gated by admin_externalpage_setup()
        // alone. The dry run is not "the harmless half": it resolves
        // every row's username or email against the user table and
        // reports which ones matched, which is an existence oracle over
        // the whole site's accounts and it needs the same authority as
        // the write.
        //
        // manager::set() below asks this again per row and that is not
        // redundant: this is the seam a caller entering the IMPORT
        // crosses, and the per-row check is the seam a caller entering
        // the WRITE crosses. Each is the gate for its own door.
        require_capability(manager::INGEST, \context_system::instance(), $actorid);

        // 1.5.0 modes, mirroring core user upload: 'override' (file
        // wins; admin defaults fill EMPTY cells) or 'fillmissing'
        // (only attributes currently empty are written; existing
        // values are never touched).
        $mode = $options->mode ?? 'override';
        $defaults = (array) ($options->defaults ?? []);

        $report = (object) [
            'ok' => false,
            'headererror' => null,
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'warnings' => [],
            'rejected' => [],
        ];

        // Header validation: case- and space-insensitive (A9).
        $columns = $reader->get_columns();
        if (!$columns) {
            $report->headererror = get_string('csvnoheader', 'mod_selfselectadvanced');

            return $report;
        }
        $map = [];
        foreach ($columns as $index => $name) {
            $canonical = strtolower(preg_replace('/[^a-z]/i', '', (string) $name));
            $map[$canonical] = $index;
        }
        // The U4 header says "Mobile Number"; accept both spellings.
        if (isset($map['mobilenumber']) && !isset($map['mobile'])) {
            $map['mobile'] = $map['mobilenumber'];
        }
        if (isset($map['emailaddress']) && !isset($map['email'])) {
            $map['email'] = $map['emailaddress'];
        }
        if (isset($map['seatlocation']) && !isset($map['seat'])) {
            $map['seat'] = $map['seatlocation'];
        }
        if (isset($map['typeofprogram']) && !isset($map['program'])) {
            $map['program'] = $map['typeofprogram'];
        }
        $missing = array_diff(self::REQUIRED, array_keys($map));
        if ($missing) {
            $report->headererror = get_string('csvmissingcolumns', 'mod_selfselectadvanced', implode(', ', $missing));

            return $report;
        }

        $transaction = $commit ? $DB->start_delegated_transaction() : null;

        $reader->init();
        $line = 1;
        while ($row = $reader->next()) {
            $line++;
            $report->total++;
            $get = static fn(string $col) => isset($map[$col], $row[$map[$col]]) ? trim((string) $row[$map[$col]]) : '';

            $username = \core_text::strtolower($get('username'));
            $email = \core_text::strtolower($get(self::OPTIONAL));

            // Match by username; fallback to email when username is blank.
            $user = null;
            if ($username !== '') {
                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
            } else if ($email !== '') {
                $matches = $DB->get_records('user', ['email' => $email, 'deleted' => 0], 'id', 'id', 0, 2);
                if (count($matches) > 1) {
                    $report->rejected[] = get_string('csvrejectedambiguous', 'mod_selfselectadvanced', (object) [
                        'line' => $line,
                        'key' => $email,
                    ]);
                    continue;
                }
                $user = $matches ? \core_user::get_user((int) array_key_first($matches)) : null;
            }
            if (!$user) {
                $report->rejected[] = get_string('csvrejectednouser', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'key' => $username !== '' ? $username : $email,
                ]);
                continue;
            }

            // A9: name cross-check warns; username stays authoritative.
            $first = $get('firstname');
            $last = $get('lastname');
            if (
                ($first !== '' && \core_text::strtolower($first) !== \core_text::strtolower($user->firstname))
                || ($last !== '' && \core_text::strtolower($last) !== \core_text::strtolower($user->lastname))
            ) {
                $report->warnings[] = get_string('csvnamemismatch', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'username' => $user->username,
                    'csvname' => trim($first . ' ' . $last),
                    'accountname' => $user->firstname . ' ' . $user->lastname,
                ]);
            }

            // Vocabulary handling (2026-07-24 policy change): this
            // importer runs at admin level, so unknown departments,
            // sub-departments and programmes are CREATED, not
            // rejected — the ingest is how admins drill the tree.
            // Each auto-creation is reported as a warning.
            $dept = $get('department') !== '' ? $get('department') : trim((string) ($defaults['department'] ?? ''));
            $sub = $get('subdepartment') !== '' ? $get('subdepartment') : trim((string) ($defaults['subdepartment'] ?? ''));
            if ($dept !== '' && depts::validate_pair($dept, $sub) !== null) {
                $report->warnings[] = get_string('csvvocabcreated', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'value' => $sub !== '' ? $dept . ' / ' . $sub : $dept,
                ]);
                if ($commit) {
                    depts::ensure($dept, $sub, $actorid);
                }
            }
            $program = isset($map['program']) ? $get('program') : '';
            if ($program !== '' && !array_key_exists($program, depts::programs_menu())) {
                $report->warnings[] = get_string('csvvocabcreated', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'value' => $program,
                ]);
                if ($commit) {
                    depts::ensure_program($program, $actorid);
                }
            }

            // Per-field length caps: one oversized cell rejects the
            // row, never aborts the whole commit (audit item 22).
            $overlong = false;
            foreach (
                ['gender' => 50, 'department' => 100, 'subdepartment' => 100,
                    'seat' => 100, 'program' => 100] as $col => $cap
            ) {
                if (isset($map[$col]) && \core_text::strlen($get($col)) > $cap) {
                    $overlong = true;
                }
            }
            if ($overlong) {
                $report->rejected[] = get_string('csvrejectedtoolong', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'username' => $user->username,
                ]);
                continue;
            }

            $mobile = $get('mobile');
            if ($mobile !== '' && !preg_match('/^[0-9+\-\s()]{1,' . self::MOBILE_MAX . '}$/', $mobile)) {
                $report->warnings[] = get_string('csvbadmobile', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'username' => $user->username,
                ]);
                $mobile = '';
            }

            // A "Share consent" column is READ AND IGNORED (maintainer
            // decision 85, 2026-08-09). The importer used to write it, which
            // meant a spreadsheet could revoke a consent the student had been
            // told was their own - silently, with nothing recording who did it
            // or on what basis.
            //
            // Grant-only was considered and rejected. It makes the destructive
            // direction impossible but still leaves two actors controlling one
            // flag that the interface describes as the student's choice, and a
            // single boolean cannot then answer who granted it, when, on what
            // basis, whether it was later withdrawn, or whether a re-import
            // should re-grant it. Consent has ONE owner.
            //
            // If offline consent must be ingested one day it needs its own
            // model carrying provenance, not this column. A shareconsent
            // column in the file is simply never looked up - the header map is
            // built from whatever columns exist - so an old file still imports
            // cleanly, and csvformathelp says the column has no effect.

            $exists = $DB->record_exists('selfselectadvanced_userattr', ['userid' => $user->id]);
            if ($commit) {
                $current = manager::get((int) $user->id);
                $cells = [
                    'gender' => $get('gender'),
                    'department' => $get('department'),
                    'subdepartment' => $get('subdepartment'),
                    'mobile' => $mobile,
                ];
                if (isset($map['seat'])) {
                    $cells['seatlocation'] = $get('seat');
                }
                if (isset($map['program'])) {
                    $cells['program'] = $get('program');
                }
                $set = [];
                foreach ($cells as $field => $cell) {
                    $value = $cell !== '' ? $cell : trim((string) ($defaults[$field] ?? ''));
                    if ($mode === 'fillmissing') {
                        $existingvalue = trim((string) ($current->$field ?? ''));
                        if ($existingvalue !== '') {
                            continue;
                        }
                        if ($value === '') {
                            continue;
                        }
                    }
                    $set[$field] = $value;
                }
                if ($set) {
                    manager::set((int) $user->id, $set, $actorid);
                }
            }
            if ($exists) {
                $report->updated++;
            } else {
                $report->created++;
            }
        }
        $reader->close();

        if ($commit) {
            \mod_selfselectadvanced\event\attributes_imported::create([
                'context' => \context_system::instance(),
                'other' => [
                    'total' => $report->total,
                    'created' => $report->created,
                    'updated' => $report->updated,
                    'warnings' => count($report->warnings),
                    'rejected' => count($report->rejected),
                ],
            ])->trigger();
            $transaction->allow_commit();
            manager::purge_value_cache();
        }

        $report->ok = true;

        return $report;
    }
}
