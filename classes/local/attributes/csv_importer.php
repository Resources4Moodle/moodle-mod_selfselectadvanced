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
 * match key when a row's username is blank). An optional "Share
 * consent" column (1/0/yes/no, case-insensitive) sets the student's
 * mobile-sharing consent through the ordinary attribute write; a
 * column absent from the file, a blank/unrecognised cell, or
 * fillmissing mode (consent has no missing state) leaves existing
 * consent untouched.
 *
 * Rules: rows are matched to EXISTING users by username (fallback
 * email). Unknown users are rejected and reported - creating accounts
 * is the site administrator's job through standard Moodle
 * administration (C11). First/last name are cross-check columns: a
 * mismatch against the matched account is warned but the row is still
 * ingested, username being authoritative (A9).
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
     */
    public static function run(csv_import_reader $reader, int $actorid, bool $commit, ?\stdClass $options = null): stdClass {
        global $DB;

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
                    depts::ensure($dept, $sub);
                }
            }
            $program = isset($map['program']) ? $get('program') : '';
            if ($program !== '' && !array_key_exists($program, depts::programs_menu())) {
                $report->warnings[] = get_string('csvvocabcreated', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'value' => $program,
                ]);
                if ($commit) {
                    depts::ensure_program($program);
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

            // Optional "Share consent" column (1/0/yes/no, case
            // insensitive): a column absent from the file, or a blank
            // or unrecognised cell, leaves the user's existing consent
            // untouched, and fillmissing mode ignores the column entirely
            // (consent has no missing state; the toggle is self-service).
            $consentvalue = null;
            if (isset($map['shareconsent'])) {
                $rawconsent = \core_text::strtolower($get('shareconsent'));
                if (in_array($rawconsent, ['1', 'yes'], true)) {
                    $consentvalue = true;
                } else if (in_array($rawconsent, ['0', 'no'], true)) {
                    $consentvalue = false;
                }
            }

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
                if ($consentvalue !== null && $mode !== 'fillmissing') {
                    // Routed through set() so a consent-only row still
                    // creates the attribute record instead of crashing
                    // on a missing one. Fillmissing mode never touches
                    // the flag: consent is binary with a meaningful
                    // default, so there is no "missing" state to fill.
                    $set['shareconsent'] = $consentvalue ? 1 : 0;
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
