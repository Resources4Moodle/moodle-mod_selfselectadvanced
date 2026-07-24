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
     * @return stdClass report: ok, headererror, created, updated, warnings[], rejected[], total
     */
    public static function run(csv_import_reader $reader, int $actorid, bool $commit): stdClass {
        global $DB;

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
        $missing = array_diff(self::REQUIRED, array_keys($map));
        if ($missing) {
            $report->headererror = get_string('csvmissingcolumns', 'mod_selfselectadvanced', implode(', ', $missing));

            return $report;
        }

        $transaction = $commit ? $DB->start_delegated_transaction() : null;
        $deptsconfigured = depts::is_configured();

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
                $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
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

            // Pre-defined department vocabulary: once the tree is
            // configured, rows with values outside it are rejected —
            // free text invites typos (spec change 2026-07-24).
            if ($deptsconfigured) {
                $bad = depts::validate_pair($get('department'), $get('subdepartment'));
                if ($bad !== null) {
                    $report->rejected[] = get_string('csvrejectedbaddept', 'mod_selfselectadvanced', (object) [
                        'line' => $line,
                        'username' => $user->username,
                        'value' => $get($bad) !== '' ? $get($bad) : get_string('none'),
                    ]);
                    continue;
                }
            }

            $mobile = $get('mobile');
            if ($mobile !== '' && !preg_match('/^[0-9+\-\s()]{1,' . self::MOBILE_MAX . '}$/', $mobile)) {
                $report->warnings[] = get_string('csvbadmobile', 'mod_selfselectadvanced', (object) [
                    'line' => $line,
                    'username' => $user->username,
                ]);
                $mobile = '';
            }

            $exists = $DB->record_exists('selfselectadvanced_userattr', ['userid' => $user->id]);
            if ($commit) {
                $set = [
                    'gender' => $get('gender'),
                    'department' => $get('department'),
                    'subdepartment' => $get('subdepartment'),
                    'mobile' => $mobile,
                ];
                if (isset($map['seat'])) {
                    $set['seatlocation'] = $get('seat');
                }
                manager::set((int) $user->id, $set, $actorid);
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
